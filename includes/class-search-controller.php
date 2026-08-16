<?php
/**
 * REST controller: server-side IGDB search proxy.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes a single logged-in-only search route. IGDB credentials and the bearer
 * token are used entirely inside the IGDB client — the response carries only the
 * game title and cover thumbnail, never a credential.
 */
final class Game_Library_Search_Controller {

	/**
	 * Shared IGDB client.
	 *
	 * @var Game_Library_IGDB_Client
	 */
	private $igdb;

	/**
	 * Constructor.
	 *
	 * @param Game_Library_IGDB_Client $igdb Shared IGDB client.
	 */
	public function __construct( Game_Library_IGDB_Client $igdb ) {
		$this->igdb = $igdb;
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
	 * Register the search route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			GAME_LIBRARY_REST_NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'require_member' ),
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Permission callback: any logged-in member. Paired with the REST cookie nonce
	 * (X-WP-Nonce) this satisfies the nonce + capability requirement.
	 *
	 * @return true|WP_Error
	 */
	public function require_member() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new WP_Error(
			'game_library_not_logged_in',
			__( 'You must be logged in to search.', 'game-library' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Handle a search request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search( WP_REST_Request $request ) {
		$query = (string) $request->get_param( 'q' );

		$results = $this->igdb->search( $query );
		if ( is_wp_error( $results ) ) {
			// AC-017: an integration failure surfaces as "search unavailable", never
			// a silent empty list. Distinct HTTP status from the zero-match case.
			return new WP_Error(
				'game_library_search_unavailable',
				__( 'Search is unavailable right now. Please try again shortly.', 'game-library' ),
				array( 'status' => 503 )
			);
		}

		// AC-018: a successful zero-match search returns an empty list (HTTP 200),
		// which the UI renders as the distinct "no games found" empty state.
		return new WP_REST_Response(
			array(
				'results' => array_values( $results ),
			),
			200
		);
	}
}
