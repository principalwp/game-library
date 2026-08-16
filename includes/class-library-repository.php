<?php
/**
 * Library persistence (gl_library): CRUD, per-status counts, paginated reads, ownership.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * Owns a member's game library rows.
 */
class Library_Repository {

	/**
	 * The four allowed statuses.
	 */
	const STATUSES = array( 'playing', 'finished', 'backlog', 'wishlist' );

	/**
	 * Whether a value is one of the four allowed statuses.
	 *
	 * @param mixed $status Candidate.
	 * @return bool
	 */
	public static function is_status( $status ) {
		return is_string( $status ) && in_array( $status, self::STATUSES, true );
	}

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'gl_library';
	}

	/**
	 * Insert one library row. Duplicate (user, game) is absorbed by the unique
	 * index and reported, never pre-checked.
	 *
	 * @param int    $user_id User id.
	 * @param int    $game_id Game id.
	 * @param string $status  Chosen status.
	 * @return array{added:bool, duplicate:bool}
	 */
	public function add( $user_id, $game_id, $status ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert(
			$this->table(),
			array(
				'user_id'    => absint( $user_id ),
				'game_id'    => absint( $game_id ),
				'status'     => $status,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $suppress );

		if ( $ok ) {
			return array(
				'added'     => true,
				'duplicate' => false,
			);
		}

		return array(
			'added'     => false,
			'duplicate' => true,
		);
	}

	/**
	 * Change an owned row's status. Ownership is enforced in the WHERE clause.
	 *
	 * @param int    $id         Library row id.
	 * @param int    $user_id    Acting user id.
	 * @param string $new_status New status.
	 * @return array{from:string, to:string, game_id:int}|null Null when not owned/found.
	 */
	public function change_status( $id, $user_id, $new_status ) {
		global $wpdb;

		$row = $this->get_owned_row( $id, $user_id );
		if ( ! $row ) {
			return null;
		}

		$updated = $wpdb->update(
			$this->table(),
			array(
				'status'     => $new_status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'id'      => absint( $id ),
				'user_id' => absint( $user_id ),
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);

		if ( false === $updated ) {
			return null;
		}

		return array(
			'from'    => (string) $row->status,
			'to'      => $new_status,
			'game_id' => (int) $row->game_id,
		);
	}

	/**
	 * Delete an owned row. Ownership enforced in the WHERE clause.
	 *
	 * @param int $id      Library row id.
	 * @param int $user_id Acting user id.
	 * @return bool
	 */
	public function remove( $id, $user_id ) {
		global $wpdb;
		$deleted = $wpdb->delete(
			$this->table(),
			array(
				'id'      => absint( $id ),
				'user_id' => absint( $user_id ),
			),
			array( '%d', '%d' )
		);
		return (bool) $deleted;
	}

	/**
	 * Fetch a row by id, regardless of owner (for ownership adjudication).
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public function get_row( $id ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Fetch a row by id, scoped to its owner.
	 *
	 * @param int $id      Row id.
	 * @param int $user_id Owner id.
	 * @return object|null
	 */
	public function get_owned_row( $id, $user_id ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $id ),
				absint( $user_id )
			)
		);
	}

	/**
	 * A single (user, game) row, joined to game data.
	 *
	 * @param int $user_id User id.
	 * @param int $game_id Game id.
	 * @return object|null
	 */
	public function get_entry( $user_id, $game_id ) {
		global $wpdb;
		$table = $this->table();
		$games = $wpdb->prefix . 'gl_games';
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT l.*, g.name, g.slug, g.cover_image_id FROM {$table} l INNER JOIN {$games} g ON g.id = l.game_id WHERE l.user_id = %d AND l.game_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id ),
				absint( $game_id )
			)
		);
	}

	/**
	 * Paginated library for a user, optionally filtered to one status.
	 *
	 * @param int         $user_id  User id.
	 * @param string|null $status   Status filter, or null for all.
	 * @param int         $per_page Rows per page.
	 * @param int         $offset   Offset.
	 * @return array<int, object>
	 */
	public function get_library( $user_id, $status, $per_page, $offset ) {
		global $wpdb;
		$table = $this->table();
		$games = $wpdb->prefix . 'gl_games';

		if ( self::is_status( $status ) ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.*, g.name, g.slug, g.cover_image_id, g.first_release_date FROM {$table} l INNER JOIN {$games} g ON g.id = l.game_id WHERE l.user_id = %d AND l.status = %s ORDER BY l.updated_at DESC, l.id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					absint( $user_id ),
					$status,
					absint( $per_page ),
					absint( $offset )
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, g.name, g.slug, g.cover_image_id, g.first_release_date FROM {$table} l INNER JOIN {$games} g ON g.id = l.game_id WHERE l.user_id = %d ORDER BY l.updated_at DESC, l.id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id ),
				absint( $per_page ),
				absint( $offset )
			)
		);
	}

	/**
	 * Count a user's library, optionally filtered to one status.
	 *
	 * @param int         $user_id User id.
	 * @param string|null $status  Status filter, or null for all.
	 * @return int
	 */
	public function count_library( $user_id, $status = null ) {
		global $wpdb;
		$table = $this->table();

		if ( self::is_status( $status ) ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					absint( $user_id ),
					$status
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id )
			)
		);
	}

	/**
	 * Per-status counts for a user's filter tabs (all four keys always present).
	 *
	 * @param int $user_id User id.
	 * @return array<string, int>
	 */
	public function status_counts( $user_id ) {
		global $wpdb;
		$table = $this->table();

		$counts = array(
			'playing'  => 0,
			'finished' => 0,
			'backlog'  => 0,
			'wishlist' => 0,
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS n FROM {$table} WHERE user_id = %d GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id )
			)
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->n;
			}
		}
		return $counts;
	}

	/**
	 * Total game count for one user.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public function game_count_for_user( $user_id ) {
		return $this->count_library( $user_id, null );
	}

	/**
	 * Grouped game counts for a set of users (one query, not one per member).
	 *
	 * @param array<int, int> $user_ids User ids.
	 * @return array<int, int> Map of user id => count.
	 */
	public function game_counts_for_users( array $user_ids ) {
		global $wpdb;
		$table = $this->table();

		$ids = array_values( array_unique( array_map( 'absint', $user_ids ) ) );
		$ids = array_filter( $ids );
		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COUNT(*) AS n FROM {$table} WHERE user_id IN ({$placeholders}) GROUP BY user_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->user_id ] = (int) $row->n;
		}
		return $map;
	}

	/**
	 * Delete every library row a user owns (OQ-7 cleanup).
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function delete_for_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'user_id' => absint( $user_id ) ), array( '%d' ) );
	}

	/**
	 * Holders of one game, with each holder's status. Optionally public-only.
	 *
	 * @param int  $game_id     Game id.
	 * @param bool $public_only Restrict to public-library holders.
	 * @param int  $limit       Max rows.
	 * @return array<int, object>
	 */
	public function holders_for_game( $game_id, $public_only, $limit = 200 ) {
		global $wpdb;
		$table    = $this->table();
		$usermeta = $wpdb->usermeta;

		if ( $public_only ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.user_id, l.status FROM {$table} l INNER JOIN {$usermeta} um ON um.user_id = l.user_id AND um.meta_key = 'gl_library_visibility' AND um.meta_value = 'public' WHERE l.game_id = %d ORDER BY l.status ASC, l.user_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					absint( $game_id ),
					absint( $limit )
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.user_id, l.status FROM {$table} l WHERE l.game_id = %d ORDER BY l.status ASC, l.user_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $game_id ),
				absint( $limit )
			)
		);
	}
}
