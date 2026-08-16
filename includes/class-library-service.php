<?php
/**
 * Library service: the data + business layer for library entries.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns all writes to the entries table. Every mutation validates server-side and
 * writes the matching activity event. Ownership is enforced here, not in the UI.
 */
final class Game_Library_Library_Service {

	/**
	 * The only four valid statuses (server-side whitelist).
	 */
	const STATUSES = array( 'playing', 'finished', 'backlog', 'wishlist' );

	/**
	 * The only cover host accepted (AC-002).
	 */
	const COVER_HOST = 'images.igdb.com';

	/**
	 * Whether a status is one of the four permitted values.
	 *
	 * @param string $status Candidate status.
	 * @return bool
	 */
	public static function is_valid_status( $status ) {
		return in_array( $status, self::STATUSES, true );
	}

	/**
	 * Add a game to a member's library. Dedupe is enforced by the
	 * UNIQUE(user_id,igdb_id) constraint, not an application lock.
	 *
	 * @param int    $user_id   Owning member.
	 * @param int    $igdb_id   IGDB game id.
	 * @param string $status    One of STATUSES.
	 * @param string $game_name Cached title.
	 * @param string $cover_url Cached cover URL (host-restricted).
	 * @return array|WP_Error The stored entry, or WP_Error on invalid input / duplicate.
	 */
	public function add( $user_id, $igdb_id, $status, $game_name, $cover_url ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$igdb_id = absint( $igdb_id );

		if ( $user_id <= 0 ) {
			return new WP_Error( 'game_library_no_user', __( 'You must be logged in.', 'game-library' ), array( 'status' => 401 ) );
		}
		if ( $igdb_id <= 0 ) {
			return new WP_Error( 'game_library_bad_igdb_id', __( 'A valid game reference is required.', 'game-library' ), array( 'status' => 400 ) );
		}
		if ( ! self::is_valid_status( $status ) ) {
			return new WP_Error( 'game_library_bad_status', __( 'That status is not allowed.', 'game-library' ), array( 'status' => 400 ) );
		}

		$game_name = sanitize_text_field( $game_name );
		$cover_url = $this->sanitize_cover_url( $cover_url );
		$now       = current_time( 'mysql' );

		// The authoritative dedupe is the UNIQUE(user_id,igdb_id) constraint below
		// (it is what holds under concurrency). This pre-check returns a clean 409
		// for the common sequential case and bridges DB drivers that do not enforce
		// a composite UNIQUE on INSERT.
		if ( $this->has_game( $user_id, $igdb_id ) ) {
			return new WP_Error(
				'game_library_duplicate',
				__( 'That game is already in your library.', 'game-library' ),
				array( 'status' => 409 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; dedupe via UNIQUE constraint.
		$inserted = $wpdb->insert(
			Game_Library_Activator::table( 'entries' ),
			array(
				'user_id'    => $user_id,
				'igdb_id'    => $igdb_id,
				'status'     => $status,
				'game_name'  => $game_name,
				'cover_url'  => $cover_url,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			// The UNIQUE(user_id,igdb_id) constraint rejected a duplicate.
			return new WP_Error(
				'game_library_duplicate',
				__( 'That game is already in your library.', 'game-library' ),
				array( 'status' => 409 )
			);
		}

		$entry_id = (int) $wpdb->insert_id;
		Game_Library_Activity::record(
			$user_id,
			'added',
			array(
				'igdb_id'   => $igdb_id,
				'status'    => $status,
				'game_name' => $game_name,
			)
		);

		return $this->get_entry( $entry_id );
	}

	/**
	 * Change a member's own entry to another of the four statuses.
	 *
	 * @param int    $user_id  Requesting member.
	 * @param int    $entry_id Target entry.
	 * @param string $status   New status.
	 * @return array|WP_Error Updated entry, or error (404 unknown / 403 not owner / 400 bad status).
	 */
	public function update_status( $user_id, $entry_id, $status ) {
		global $wpdb;

		$user_id  = absint( $user_id );
		$entry_id = absint( $entry_id );

		if ( ! self::is_valid_status( $status ) ) {
			return new WP_Error( 'game_library_bad_status', __( 'That status is not allowed.', 'game-library' ), array( 'status' => 400 ) );
		}

		$entry = $this->get_entry( $entry_id );
		if ( ! $entry ) {
			return new WP_Error( 'game_library_not_found', __( 'That library entry does not exist.', 'game-library' ), array( 'status' => 404 ) );
		}
		if ( (int) $entry['user_id'] !== $user_id ) {
			return new WP_Error( 'game_library_forbidden', __( 'You can only change your own library.', 'game-library' ), array( 'status' => 403 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->update(
			Game_Library_Activator::table( 'entries' ),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $entry_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		Game_Library_Activity::record(
			$user_id,
			'status_change',
			array(
				'igdb_id'   => (int) $entry['igdb_id'],
				'status'    => $status,
				'game_name' => $entry['game_name'],
			)
		);

		return $this->get_entry( $entry_id );
	}

	/**
	 * Remove a member's own entry (no activity event — remove is not an event type).
	 *
	 * @param int $user_id  Requesting member.
	 * @param int $entry_id Target entry.
	 * @return true|WP_Error
	 */
	public function remove( $user_id, $entry_id ) {
		global $wpdb;

		$user_id  = absint( $user_id );
		$entry_id = absint( $entry_id );

		$entry = $this->get_entry( $entry_id );
		if ( ! $entry ) {
			return new WP_Error( 'game_library_not_found', __( 'That library entry does not exist.', 'game-library' ), array( 'status' => 404 ) );
		}
		if ( (int) $entry['user_id'] !== $user_id ) {
			return new WP_Error( 'game_library_forbidden', __( 'You can only remove your own entries.', 'game-library' ), array( 'status' => 403 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->delete(
			Game_Library_Activator::table( 'entries' ),
			array( 'id' => $entry_id ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Fetch a single entry as an associative array.
	 *
	 * @param int $entry_id Entry id.
	 * @return array|null
	 */
	public function get_entry( $entry_id ) {
		global $wpdb;

		$entry_id = absint( $entry_id );
		$table    = Game_Library_Activator::table( 'entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, id via %d.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $entry_id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * A member's library, newest first, bounded to PAGE_SIZE (NFR-001).
	 *
	 * @param int $user_id Owning member.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_entries_for_user( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array();
		}
		$table = Game_Library_Activator::table( 'entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, user_id and LIMIT via %d.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d',
				$table,
				$user_id,
				Game_Library_Activity::PAGE_SIZE
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether a member already has a given IGDB game.
	 *
	 * @param int $user_id Member.
	 * @param int $igdb_id IGDB game id.
	 * @return bool
	 */
	public function has_game( $user_id, $igdb_id ) {
		global $wpdb;

		$table = Game_Library_Activator::table( 'entries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, values via %d.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE user_id = %d AND igdb_id = %d',
				$table,
				absint( $user_id ),
				absint( $igdb_id )
			)
		);

		return ! empty( $found );
	}

	/**
	 * Validate a cover URL and require the images.igdb.com host.
	 *
	 * @param string $cover_url Candidate URL.
	 * @return string Empty string when the URL is missing or off-host.
	 */
	private function sanitize_cover_url( $cover_url ) {
		$cover_url = esc_url_raw( trim( (string) $cover_url ) );
		if ( '' === $cover_url ) {
			return '';
		}
		$host = wp_parse_url( $cover_url, PHP_URL_HOST );
		if ( self::COVER_HOST !== $host ) {
			return '';
		}
		return $cover_url;
	}
}
