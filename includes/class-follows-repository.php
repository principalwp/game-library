<?php
/**
 * Follows persistence (gl_follows): one-directional follows and the followed-id set.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * Owns follow relationships.
 */
class Follows_Repository {

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'gl_follows';
	}

	/**
	 * Create a follow. Self-follows are refused; repeats are absorbed by the
	 * unique index (reported as already-following, no error).
	 *
	 * @param int $follower_id Follower user id.
	 * @param int $followed_id Followed user id.
	 * @return bool True once the follow exists.
	 */
	public function follow( $follower_id, $followed_id ) {
		global $wpdb;

		$follower_id = absint( $follower_id );
		$followed_id = absint( $followed_id );

		if ( $follower_id <= 0 || $followed_id <= 0 || $follower_id === $followed_id ) {
			return false;
		}

		$suppress = $wpdb->suppress_errors( true );
		$wpdb->insert(
			$this->table(),
			array(
				'follower_id' => $follower_id,
				'followed_id' => $followed_id,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s' )
		);
		$wpdb->suppress_errors( $suppress );

		return $this->is_following( $follower_id, $followed_id );
	}

	/**
	 * Remove a follow.
	 *
	 * @param int $follower_id Follower user id.
	 * @param int $followed_id Followed user id.
	 * @return bool
	 */
	public function unfollow( $follower_id, $followed_id ) {
		global $wpdb;
		$wpdb->delete(
			$this->table(),
			array(
				'follower_id' => absint( $follower_id ),
				'followed_id' => absint( $followed_id ),
			),
			array( '%d', '%d' )
		);
		return ! $this->is_following( $follower_id, $followed_id );
	}

	/**
	 * Whether follower currently follows followed.
	 *
	 * @param int $follower_id Follower user id.
	 * @param int $followed_id Followed user id.
	 * @return bool
	 */
	public function is_following( $follower_id, $followed_id ) {
		global $wpdb;
		$table = $this->table();
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE follower_id = %d AND followed_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $follower_id ),
				absint( $followed_id )
			)
		);
		return null !== $found;
	}

	/**
	 * The set of ids a user follows.
	 *
	 * @param int $follower_id Follower user id.
	 * @param int $limit       Safety cap.
	 * @return array<int, int>
	 */
	public function followed_ids( $follower_id, $limit = 5000 ) {
		global $wpdb;
		$table = $this->table();
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT followed_id FROM {$table} WHERE follower_id = %d ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $follower_id ),
				absint( $limit )
			)
		);
		return array_map( 'absint', (array) $rows );
	}

	/**
	 * Delete every follow a user owns or receives (OQ-7 cleanup).
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function delete_for_user( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$wpdb->delete( $this->table(), array( 'follower_id' => $user_id ), array( '%d' ) );
		$wpdb->delete( $this->table(), array( 'followed_id' => $user_id ), array( '%d' ) );
	}
}
