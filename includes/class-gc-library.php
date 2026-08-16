<?php
/**
 * User game libraries (backed by an IGDB games cache).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Library {

	const STATUSES = array( 'playing', 'finished', 'backlog', 'wishlist' );

	public static function status_labels() {
		return array(
			'playing'  => __( 'Now Playing', 'game-collector' ),
			'finished' => __( 'Finished', 'game-collector' ),
			'backlog'  => __( 'Backlog', 'game-collector' ),
			'wishlist' => __( 'Wishlist', 'game-collector' ),
		);
	}

	public static function is_valid_status( $status ) {
		return in_array( $status, self::STATUSES, true );
	}

	private static function games_table() {
		return GC_Install::table( 'games' );
	}

	private static function user_games_table() {
		return GC_Install::table( 'user_games' );
	}

	/**
	 * Get a cached game row by local id.
	 */
	public static function get_game( $game_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::games_table() . ' WHERE id = %d', $game_id )
		);
	}

	/**
	 * Ensure a game exists in the local cache; fetch from IGDB if missing.
	 *
	 * @return int|WP_Error Local game id.
	 */
	public static function ensure_game_cached( $igdb_id ) {
		global $wpdb;

		$igdb_id = (int) $igdb_id;

		$existing_id = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . self::games_table() . ' WHERE igdb_id = %d', $igdb_id )
		);

		if ( $existing_id ) {
			return (int) $existing_id;
		}

		$game = GC_IGDB::get_game( $igdb_id );

		if ( is_wp_error( $game ) ) {
			return $game;
		}

		if ( ! $game ) {
			return new WP_Error( 'gc_game_not_found', __( 'Game not found on IGDB.', 'game-collector' ) );
		}

		$wpdb->insert(
			self::games_table(),
			array(
				'igdb_id'        => $game['igdb_id'],
				'name'           => $game['name'],
				'slug'           => $game['slug'],
				'cover_image_id' => $game['cover_image_id'],
				'release_year'   => $game['release_year'],
				'platforms'      => $game['platforms'],
				'summary'        => $game['summary'],
				'created_at'     => current_time( 'mysql', true ),
			)
		);

		if ( ! $wpdb->insert_id ) {
			// Lost a race to a concurrent insert; the unique igdb_id key means the row now exists.
			$existing_id = $wpdb->get_var(
				$wpdb->prepare( 'SELECT id FROM ' . self::games_table() . ' WHERE igdb_id = %d', $igdb_id )
			);
			if ( $existing_id ) {
				return (int) $existing_id;
			}
			return new WP_Error( 'gc_db_error', __( 'Could not save the game.', 'game-collector' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Add a game to a user's library.
	 *
	 * @return array|WP_Error { game_id, status, already_in_library }
	 */
	public static function add( $user_id, $igdb_id, $status ) {
		global $wpdb;

		if ( ! self::is_valid_status( $status ) ) {
			return new WP_Error( 'gc_bad_status', __( 'Invalid status.', 'game-collector' ) );
		}

		$game_id = self::ensure_game_cached( $igdb_id );
		if ( is_wp_error( $game_id ) ) {
			return $game_id;
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::user_games_table() . ' WHERE user_id = %d AND game_id = %d',
				$user_id,
				$game_id
			)
		);

		if ( $existing ) {
			return array(
				'game_id'            => $game_id,
				'status'             => $existing->status,
				'already_in_library' => true,
			);
		}

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			self::user_games_table(),
			array(
				'user_id'    => (int) $user_id,
				'game_id'    => $game_id,
				'status'     => $status,
				'added_at'   => $now,
				'updated_at' => $now,
			)
		);

		if ( ! $inserted ) {
			return new WP_Error( 'gc_db_error', __( 'Could not add the game to your library.', 'game-collector' ) );
		}

		GC_Activity::log( $user_id, 'added_game', array( 'game_id' => $game_id, 'status' => $status ) );

		return array(
			'game_id'            => $game_id,
			'status'             => $status,
			'already_in_library' => false,
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function update_status( $user_id, $game_id, $status ) {
		global $wpdb;

		if ( ! self::is_valid_status( $status ) ) {
			return new WP_Error( 'gc_bad_status', __( 'Invalid status.', 'game-collector' ) );
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::user_games_table() . ' WHERE user_id = %d AND game_id = %d',
				$user_id,
				$game_id
			)
		);

		if ( ! $existing ) {
			return new WP_Error( 'gc_not_in_library', __( 'That game is not in the library.', 'game-collector' ) );
		}

		if ( $existing->status === $status ) {
			return true;
		}

		$wpdb->update(
			self::user_games_table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $existing->id )
		);

		GC_Activity::log( $user_id, 'status_change', array( 'game_id' => (int) $game_id, 'status' => $status ) );

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function remove( $user_id, $game_id ) {
		global $wpdb;

		$deleted = $wpdb->delete(
			self::user_games_table(),
			array(
				'user_id' => (int) $user_id,
				'game_id' => (int) $game_id,
			)
		);

		if ( ! $deleted ) {
			return new WP_Error( 'gc_not_in_library', __( 'That game is not in the library.', 'game-collector' ) );
		}

		return true;
	}

	/**
	 * A user's library entries joined with game data.
	 *
	 * @return object[] Rows: user_games columns + game name/cover/etc.
	 */
	public static function get_user_library( $user_id, $status = null ) {
		global $wpdb;

		$sql    = 'SELECT ug.game_id, ug.status, ug.added_at, ug.updated_at,
			g.igdb_id, g.name, g.slug, g.cover_image_id, g.release_year, g.platforms
			FROM ' . self::user_games_table() . ' ug
			INNER JOIN ' . self::games_table() . ' g ON g.id = ug.game_id
			WHERE ug.user_id = %d';
		$params = array( (int) $user_id );

		if ( $status && self::is_valid_status( $status ) ) {
			$sql     .= ' AND ug.status = %s';
			$params[] = $status;
		}

		$sql .= ' ORDER BY ug.updated_at DESC';

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * @return array status => count, plus 'all'.
	 */
	public static function get_counts( $user_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS n FROM ' . self::user_games_table() . ' WHERE user_id = %d GROUP BY status',
				$user_id
			)
		);

		$counts = array_fill_keys( self::STATUSES, 0 );
		$total  = 0;

		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->n;
				$total                 += (int) $row->n;
			}
		}

		$counts['all'] = $total;

		return $counts;
	}
}
