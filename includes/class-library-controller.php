<?php
/**
 * REST controller: add / update-status / remove library entries.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * State-changing library routes. Each requires the REST cookie nonce (X-WP-Nonce)
 * plus a logged-in permission callback; ownership is verified inside the service.
 */
final class Game_Library_Library_Controller {

	/**
	 * Library service.
	 *
	 * @var Game_Library_Library_Service
	 */
	private $service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->service = new Game_Library_Library_Service();
	}

	/**
	 * Register REST hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the three routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$permission = array( $this, 'require_member' );

		register_rest_route(
			GAME_LIBRARY_REST_NAMESPACE,
			'/library',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add' ),
				'permission_callback' => $permission,
				'args'                => array(
					'igdb_id'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'status'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'game_name' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'cover_url' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		register_rest_route(
			GAME_LIBRARY_REST_NAMESPACE,
			'/library/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_status' ),
					'permission_callback' => $permission,
					'args'                => array(
						'id'     => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'status' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => $permission,
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
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
	 * Add route.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add( WP_REST_Request $request ) {
		$result = $this->service->add(
			get_current_user_id(),
			(int) $request->get_param( 'igdb_id' ),
			(string) $request->get_param( 'status' ),
			(string) $request->get_param( 'game_name' ),
			(string) $request->get_param( 'cover_url' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( array( 'entry' => $result ), 201 );
	}

	/**
	 * Update-status route.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_status( WP_REST_Request $request ) {
		$result = $this->service->update_status(
			get_current_user_id(),
			(int) $request->get_param( 'id' ),
			(string) $request->get_param( 'status' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( array( 'entry' => $result ), 200 );
	}

	/**
	 * Remove route.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove( WP_REST_Request $request ) {
		$result = $this->service->remove(
			get_current_user_id(),
			(int) $request->get_param( 'id' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( array( 'removed' => true ), 200 );
	}
}
