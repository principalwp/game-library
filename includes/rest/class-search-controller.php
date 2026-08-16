<?php
/**
 * `GET /search` — free-text IGDB search for a logged-in member.
 *
 * @package Game_Library
 */

namespace Game_Library\Rest;

use Game_Library\Data\Library_Repository;
use Game_Library\Igdb\Client;
use Game_Library\Igdb\Game_Mapper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Search_Controller.
 *
 * Registers `GET /wp-json/game-library/v1/search` (AC-003). Every match
 * carries exactly the six fields AC-003 (a)-(f) specify, including a
 * `cover_url` of `null` (never an empty string or an absent key) for a game
 * with no cover, and `already_in_library` computed per result against the
 * current member's own library. No IGDB/Twitch call happens anywhere else in
 * this class — `Igdb\Client::search()` is the only outbound path, and its own
 * 900-second transient cache (C1 budget) means an identical repeated query
 * makes no additional request.
 */
final class Search_Controller extends REST_Controller {

	/**
	 * REST base — `game-library/v1/search`.
	 *
	 * @var string
	 */
	protected $rest_base = 'search';

	/**
	 * IGDB/Twitch client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Maps a raw IGDB payload onto the fields this route returns.
	 *
	 * @var Game_Mapper
	 */
	private $mapper;

	/**
	 * Used to compute `already_in_library` per result.
	 *
	 * @var Library_Repository
	 */
	private $library;

	/**
	 * Constructor.
	 *
	 * @param Client|null              $client  IGDB client. Defaults to a new instance.
	 * @param Game_Mapper|null         $mapper  Payload mapper. Defaults to a new instance.
	 * @param Library_Repository|null  $library Library repository. Defaults to a new instance.
	 */
	public function __construct( ?Client $client = null, ?Game_Mapper $mapper = null, ?Library_Repository $library = null ) {
		$this->client  = $client ?: new Client();
		$this->mapper  = $mapper ?: new Game_Mapper();
		$this->library = $library ?: new Library_Repository();
	}

	/**
	 * Registers this controller's one route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'search' ),
					'permission_callback' => array( $this, 'search_permissions_check' ),
					'args'                => array(
						'q' => array(
							'description'       => __( 'Free-text search query.', 'game-library' ),
							'type'               => 'string',
							'required'           => true,
							'sanitize_callback'  => 'sanitize_text_field',
							// WP_REST_Request::has_valid_params() only runs
							// schema (type/minLength/maxLength/enum/minimum/
							// maximum) validation for an arg that sets
							// validate_callback — declaring the schema keys
							// alone does not enforce them.
							'validate_callback' => 'rest_validate_request_arg',
							'minLength'          => 2,
							'maxLength'          => 100,
						),
					),
				),
			)
		);
	}

	/**
	 * Search is a member-only surface — matches `/my-library/`, the only
	 * front-end route this endpoint serves.
	 *
	 * @return true|WP_Error
	 */
	public function search_permissions_check() {
		return $this->require_login();
	}

	/**
	 * Handles `GET /search`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function search( WP_REST_Request $request ) {
		$query   = (string) $request->get_param( 'q' );
		$results = $this->client->search( $query );

		if ( is_wp_error( $results ) ) {
			return $this->map_error( $results );
		}

		$user_id = get_current_user_id();
		$page    = array_slice( $results, 0, 10 );

		// PB-2: one batched lookup for the whole page instead of has_game()
		// (one SELECT per result on a cache miss) inside the loop below —
		// see Library_Repository::held_igdb_ids()'s own docblock for why the
		// per-result cache this replaced missed so often in practice.
		$page_igdb_ids = array();

		foreach ( $page as $payload ) {
			if ( is_array( $payload ) && ! empty( $payload['id'] ) ) {
				$page_igdb_ids[] = absint( $payload['id'] );
			}
		}

		$held_igdb_ids = $this->library->held_igdb_ids( $user_id, $page_igdb_ids );

		$items = array();

		foreach ( $page as $payload ) {
			if ( ! is_array( $payload ) || empty( $payload['id'] ) ) {
				continue;
			}

			$igdb_id = absint( $payload['id'] );

			$items[] = array(
				'igdb_id'             => $igdb_id,
				'name'                => isset( $payload['name'] ) ? sanitize_text_field( $payload['name'] ) : '',
				// PF-3: 'thumb' — search.js renders this in
				// .gl-search-result__cover, a --gl-avatar-lg (64px) box, not
				// the 'big' size's card/hero dimensions.
				'cover_url'           => $this->mapper->cover_url( $this->mapper->extract_cover_image_id( $payload ), 'thumb' ),
				'first_release_year'  => $this->mapper->first_release_year( $payload ),
				'platforms'           => $this->mapper->extract_names( isset( $payload['platforms'] ) ? $payload['platforms'] : null ),
				'already_in_library'  => in_array( $igdb_id, $held_igdb_ids, true ),
			);
		}

		return rest_ensure_response( $items );
	}
}
