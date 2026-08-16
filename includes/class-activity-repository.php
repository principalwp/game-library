<?php
/**
 * Activity feed persistence (gl_activity): writes plus the two audience feed queries.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the activity feed. The "Everyone" audience enforces visibility in SQL.
 */
class Activity_Repository {

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'gl_activity';
	}

	/**
	 * Record one activity event.
	 *
	 * @param int         $actor_id Actor user id.
	 * @param string      $verb     'game-added' | 'status-changed'.
	 * @param int         $game_id  Game id.
	 * @param string|null $from     Previous status (null for adds).
	 * @param string      $to       New status.
	 * @return void
	 */
	public function log( $actor_id, $verb, $game_id, $from, $to ) {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'actor_id'    => absint( $actor_id ),
				'verb'        => $verb,
				'game_id'     => absint( $game_id ),
				'from_status' => ( null === $from ) ? null : (string) $from,
				'to_status'   => (string) $to,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * SELECT column list shared by both audiences (joins game data in).
	 *
	 * @return string
	 */
	private function columns() {
		return 'a.id, a.actor_id, a.verb, a.game_id, a.from_status, a.to_status, a.created_at, g.name AS game_name, g.slug AS game_slug, g.cover_image_id';
	}

	/**
	 * The "Following" audience: the viewer's followed set plus the viewer.
	 *
	 * @param int             $viewer_id    Viewer user id.
	 * @param array<int, int> $followed_ids Ids the viewer follows.
	 * @param int             $per_page     Rows per page.
	 * @param int             $offset       Offset.
	 * @return array<int, object>
	 */
	public function feed_following( $viewer_id, array $followed_ids, $per_page, $offset ) {
		global $wpdb;
		$table = $this->table();
		$games = $wpdb->prefix . 'gl_games';

		$ids          = $this->actor_set( $viewer_id, $followed_ids );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$args   = $ids;
		$args[] = absint( $per_page );
		$args[] = absint( $offset );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$this->columns()} FROM {$table} a INNER JOIN {$games} g ON g.id = a.game_id WHERE a.actor_id IN ({$placeholders}) ORDER BY a.created_at DESC, a.id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$args
			)
		);
	}

	/**
	 * Count for the "Following" audience.
	 *
	 * @param int             $viewer_id    Viewer user id.
	 * @param array<int, int> $followed_ids Ids the viewer follows.
	 * @return int
	 */
	public function count_following( $viewer_id, array $followed_ids ) {
		global $wpdb;
		$table = $this->table();

		$ids          = $this->actor_set( $viewer_id, $followed_ids );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} a WHERE a.actor_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids
			)
		);
	}

	/**
	 * The "Everyone" audience: only public-library actors, enforced in SQL.
	 *
	 * @param int $per_page Rows per page.
	 * @param int $offset   Offset.
	 * @return array<int, object>
	 */
	public function feed_everyone( $per_page, $offset ) {
		global $wpdb;
		$table    = $this->table();
		$games    = $wpdb->prefix . 'gl_games';
		$usermeta = $wpdb->usermeta;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$this->columns()} FROM {$table} a INNER JOIN {$games} g ON g.id = a.game_id INNER JOIN {$usermeta} um ON um.user_id = a.actor_id AND um.meta_key = 'gl_library_visibility' AND um.meta_value = 'public' ORDER BY a.created_at DESC, a.id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $per_page ),
				absint( $offset )
			)
		);
	}

	/**
	 * Count for the "Everyone" audience.
	 *
	 * @return int
	 */
	public function count_everyone() {
		global $wpdb;
		$table    = $this->table();
		$usermeta = $wpdb->usermeta;

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} a INNER JOIN {$usermeta} um ON um.user_id = a.actor_id AND um.meta_key = 'gl_library_visibility' AND um.meta_value = 'public'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * Delete every activity row authored by a user (OQ-7 cleanup).
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function delete_for_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'actor_id' => absint( $user_id ) ), array( '%d' ) );
	}

	/**
	 * The distinct actor id set for the following audience (followed + self).
	 *
	 * @param int             $viewer_id    Viewer user id.
	 * @param array<int, int> $followed_ids Followed ids.
	 * @return array<int, int>
	 */
	private function actor_set( $viewer_id, array $followed_ids ) {
		$ids   = array_map( 'absint', $followed_ids );
		$ids[] = absint( $viewer_id );
		$ids   = array_values( array_unique( array_filter( $ids ) ) );
		if ( empty( $ids ) ) {
			$ids = array( 0 );
		}
		return $ids;
	}
}
