<?php
/**
 * REST API: gc/v1
 *
 * All routes are members-only (cookie auth + X-WP-Nonce from the front-end JS).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Rest {

	const NS = 'gc/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function require_login() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new WP_Error(
			'gc_forbidden',
			__( 'You must be logged in.', 'game-collector' ),
			array( 'status' => 401 )
		);
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'args'                => array(
					'q' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/library',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'add_to_library' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'args'                => array(
					'igdb_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
					'status'  => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => GC_Library::STATUSES,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/library/(?P<game_id>\d+)',
			array(
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( __CLASS__, 'update_status' ),
					'permission_callback' => array( __CLASS__, 'require_login' ),
					'args'                => array(
						'status' => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => GC_Library::STATUSES,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'remove_from_library' ),
					'permission_callback' => array( __CLASS__, 'require_login' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/follows/(?P<user_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'follow' ),
					'permission_callback' => array( __CLASS__, 'require_login' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'unfollow' ),
					'permission_callback' => array( __CLASS__, 'require_login' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/activity',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'activity' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'args'                => array(
					'page' => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);
	}

	private static function as_response( $result, $extra = array() ) {
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => self::error_status( $result ) ) );
			return $result;
		}
		return rest_ensure_response( array_merge( array( 'success' => true ), $extra ) );
	}

	private static function error_status( WP_Error $error ) {
		$map = array(
			'gc_bad_status'          => 400,
			'gc_not_in_library'      => 404,
			'gc_game_not_found'      => 404,
			'gc_no_user'             => 404,
			'gc_follow_self'         => 400,
			'gc_igdb_not_configured' => 503,
		);
		$code = $error->get_error_code();
		return isset( $map[ $code ] ) ? $map[ $code ] : 500;
	}

	/* ---- Handlers ---- */

	public static function search( WP_REST_Request $request ) {
		$term = trim( $request['q'] );

		if ( strlen( $term ) < 2 ) {
			return rest_ensure_response( array( 'results' => array() ) );
		}

		$results = GC_IGDB::search( $term );

		if ( is_wp_error( $results ) ) {
			$results->add_data( array( 'status' => self::error_status( $results ) ) );
			return $results;
		}

		// Flag games the current user already has, so the UI can say so.
		global $wpdb;
		$user_games   = GC_Install::table( 'user_games' );
		$games        = GC_Install::table( 'games' );
		$owned_igdb   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT g.igdb_id FROM {$user_games} ug INNER JOIN {$games} g ON g.id = ug.game_id WHERE ug.user_id = %d",
				get_current_user_id()
			)
		);
		$owned_lookup = array_fill_keys( array_map( 'intval', $owned_igdb ), true );

		foreach ( $results as &$game ) {
			$game['cover_url']  = GC_IGDB::cover_url( $game['cover_image_id'] );
			$game['in_library'] = isset( $owned_lookup[ $game['igdb_id'] ] );
			unset( $game['summary'] );
		}
		unset( $game );

		return rest_ensure_response( array( 'results' => $results ) );
	}

	public static function add_to_library( WP_REST_Request $request ) {
		$result = GC_Library::add( get_current_user_id(), (int) $request['igdb_id'], $request['status'] );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => self::error_status( $result ) ) );
			return $result;
		}

		return rest_ensure_response( array_merge( array( 'success' => true ), $result ) );
	}

	public static function update_status( WP_REST_Request $request ) {
		return self::as_response(
			GC_Library::update_status( get_current_user_id(), (int) $request['game_id'], $request['status'] ),
			array( 'status' => $request['status'] )
		);
	}

	public static function remove_from_library( WP_REST_Request $request ) {
		return self::as_response(
			GC_Library::remove( get_current_user_id(), (int) $request['game_id'] )
		);
	}

	public static function follow( WP_REST_Request $request ) {
		return self::as_response(
			GC_Follows::follow( get_current_user_id(), (int) $request['user_id'] ),
			array( 'following' => true )
		);
	}

	public static function unfollow( WP_REST_Request $request ) {
		return self::as_response(
			GC_Follows::unfollow( get_current_user_id(), (int) $request['user_id'] ),
			array( 'following' => false )
		);
	}

	public static function activity( WP_REST_Request $request ) {
		$items = GC_Activity::get_feed_for( get_current_user_id(), (int) $request['page'] );

		$out = array();
		foreach ( $items as $item ) {
			$out[] = array(
				'id'         => (int) $item->id,
				'html'       => GC_Activity::describe( $item ),
				'cover_url'  => GC_IGDB::cover_url( $item->cover_image_id, 'cover_small' ),
				'created_at' => mysql2date( 'c', $item->created_at, false ),
				'time_ago'   => human_time_diff( strtotime( $item->created_at . ' UTC' ) ) . ' ' . __( 'ago', 'game-collector' ),
			);
		}

		return rest_ensure_response( array( 'items' => $out ) );
	}
}
