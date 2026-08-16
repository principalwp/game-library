<?php
/**
 * Follow relationships between members.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Follows {

	private static function table() {
		return GC_Install::table( 'follows' );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function follow( $follower_id, $following_id ) {
		global $wpdb;

		$follower_id  = (int) $follower_id;
		$following_id = (int) $following_id;

		if ( $follower_id === $following_id ) {
			return new WP_Error( 'gc_follow_self', __( 'You cannot follow yourself.', 'game-collector' ) );
		}

		if ( ! get_userdata( $following_id ) ) {
			return new WP_Error( 'gc_no_user', __( 'User not found.', 'game-collector' ) );
		}

		if ( self::is_following( $follower_id, $following_id ) ) {
			return true;
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'follower_id'  => $follower_id,
				'following_id' => $following_id,
				'created_at'   => current_time( 'mysql', true ),
			)
		);

		if ( ! $inserted ) {
			return new WP_Error( 'gc_db_error', __( 'Could not follow that user.', 'game-collector' ) );
		}

		GC_Activity::log( $follower_id, 'followed_user', array( 'target_user_id' => $following_id ) );

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function unfollow( $follower_id, $following_id ) {
		global $wpdb;

		$wpdb->delete(
			self::table(),
			array(
				'follower_id'  => (int) $follower_id,
				'following_id' => (int) $following_id,
			)
		);

		return true;
	}

	public static function is_following( $follower_id, $following_id ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE follower_id = %d AND following_id = %d',
				$follower_id,
				$following_id
			)
		);
	}

	/**
	 * @return int[] User ids this user follows.
	 */
	public static function get_following_ids( $user_id ) {
		global $wpdb;

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare( 'SELECT following_id FROM ' . self::table() . ' WHERE follower_id = %d', $user_id )
			)
		);
	}

	/**
	 * @return int[] User ids following this user.
	 */
	public static function get_follower_ids( $user_id ) {
		global $wpdb;

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare( 'SELECT follower_id FROM ' . self::table() . ' WHERE following_id = %d', $user_id )
			)
		);
	}

	public static function count_following( $user_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE follower_id = %d', $user_id )
		);
	}

	public static function count_followers( $user_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE following_id = %d', $user_id )
		);
	}
}
