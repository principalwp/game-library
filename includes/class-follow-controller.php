<?php
/**
 * Follow / unfollow service + REST controller (one-way follows).
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-way following with no approval step. Self-follows are rejected server-side;
 * repeat follows are a no-op via UNIQUE(follower_id,followee_id). Follow writes one
 * activity event; unfollow writes none.
 */
final class Game_Library_Follow_Controller {

	/**
	 * Register REST hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the follow / unfollow routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$args = array(
			'followee_id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			GAME_LIBRARY_REST_NAMESPACE,
			'/follow',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'follow' ),
				'permission_callback' => array( $this, 'require_member' ),
				'args'                => $args,
			)
		);

		register_rest_route(
			GAME_LIBRARY_REST_NAMESPACE,
			'/unfollow',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'unfollow' ),
				'permission_callback' => array( $this, 'require_member' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Permission callback: any logged-in member.
	 *
	 * @return true|WP_Error
	 */
	public function require_member() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new WP_Error(
			'game_library_not_logged_in',
			__( 'You must be logged in.', 'game-library' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Follow route.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function follow( WP_REST_Request $request ) {
		global $wpdb;

		$follower_id = get_current_user_id();
		$followee_id = absint( $request->get_param( 'followee_id' ) );

		if ( $followee_id <= 0 || ! get_user_by( 'id', $followee_id ) ) {
			return new WP_Error( 'game_library_bad_user', __( 'That member does not exist.', 'game-library' ), array( 'status' => 404 ) );
		}
		if ( $followee_id === $follower_id ) {
			return new WP_Error( 'game_library_self_follow', __( 'You cannot follow yourself.', 'game-library' ), array( 'status' => 400 ) );
		}

		if ( $this->is_following( $follower_id, $followee_id ) ) {
			// Repeat follow: no-op, no duplicate event.
			return new WP_REST_Response( array( 'following' => true ), 200 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; dedupe via UNIQUE constraint.
		$inserted = $wpdb->insert(
			Game_Library_Activator::table( 'follows' ),
			array(
				'follower_id' => $follower_id,
				'followee_id' => $followee_id,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s' )
		);

		if ( ! $inserted ) {
			// The UNIQUE constraint rejected a race duplicate — still a no-op success.
			return new WP_REST_Response( array( 'following' => true ), 200 );
		}

		Game_Library_Activity::record(
			$follower_id,
			'follow',
			array( 'target_user_id' => $followee_id )
		);

		return new WP_REST_Response( array( 'following' => true ), 201 );
	}

	/**
	 * Unfollow route.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function unfollow( WP_REST_Request $request ) {
		global $wpdb;

		$follower_id = get_current_user_id();
		$followee_id = absint( $request->get_param( 'followee_id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->delete(
			Game_Library_Activator::table( 'follows' ),
			array(
				'follower_id' => $follower_id,
				'followee_id' => $followee_id,
			),
			array( '%d', '%d' )
		);

		return new WP_REST_Response( array( 'following' => false ), 200 );
	}

	/**
	 * Whether $follower_id already follows $followee_id.
	 *
	 * @param int $follower_id Follower.
	 * @param int $followee_id Followee.
	 * @return bool
	 */
	public function is_following( $follower_id, $followee_id ) {
		global $wpdb;

		$table = Game_Library_Activator::table( 'follows' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, values via %d.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE follower_id = %d AND followee_id = %d',
				$table,
				absint( $follower_id ),
				absint( $followee_id )
			)
		);

		return ! empty( $found );
	}
}
