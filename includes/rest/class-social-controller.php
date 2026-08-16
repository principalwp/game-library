<?php
/**
 * Follow, activity feed, member directory, and profile-visibility routes.
 *
 * @package Game_Library
 */

namespace Game_Library\Rest;

use Game_Library\Data\Activity_Repository;
use Game_Library\Data\Follow_Repository;
use Game_Library\Data\Game_Repository;
use Game_Library\Data\Library_Repository;
use Game_Library\Data\Member_Directory;
use Game_Library\Igdb\Game_Mapper;
use Game_Library\Router;
use Game_Library\Visibility;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Social_Controller.
 *
 * Registers:
 *
 * - `POST /follow/{user_id}` / `DELETE /follow/{user_id}` — create/remove a
 *   follow edge, always scoped to `get_current_user_id()` as the follower
 *   (AC-019).
 * - `GET /activity` — the viewer's activity feed, paginated at 20 per page
 *   (AC-020, AC-021, AC-022, AC-023).
 * - `GET /members` — the paginated member directory (AC-018).
 * - `PATCH /profile/visibility` — the current member's own
 *   `_gl_profile_public` toggle only; never accepts a `user_id` (AC-034).
 *
 * `GET /members` and `GET /activity` batch every per-row lookup exactly the
 * way `Library_Controller::get_library()` batches game rows — one query (or
 * one cached call) for the whole page, never one per row:
 *
 * - `get_activity()`: `Follow_Repository::following_ids()` (0-1 query) feeds
 *   `Activity_Repository::get_feed()` (0-1 query, plus its own one-call actor
 *   priming), and `Game_Repository::get_many()` (0-1 query) batches every
 *   game row the page's entries reference.
 * - `get_members()`: `cache_users()` primes every member row on the page in
 *   one call, `Library_Repository::counts_for_users()` and
 *   `Follow_Repository::is_following_map()` each cost one query for the whole
 *   page, and the per-row loop below issues no query of its own.
 *
 * Every arg sets `'validate_callback' => 'rest_validate_request_arg'` — see
 * `Library_Controller`'s own docblock (Task 11) for why declaring the schema
 * keys alone does not enforce them.
 */
final class Social_Controller extends REST_Controller {

	/**
	 * REST base — nominal only; every route below is registered at its own
	 * literal path rather than under a shared `/social` prefix.
	 *
	 * @var string
	 */
	protected $rest_base = 'social';

	/**
	 * Member-directory page size, matching `Library_Repository`'s own
	 * `DEFAULT_PER_PAGE` for consistency across the plugin's paginated lists.
	 *
	 * @var int
	 */
	private const MEMBERS_PER_PAGE = 24;

	/**
	 * Activity-feed page size (AC-020 — "paginated at 20 per page").
	 *
	 * @var int
	 */
	private const ACTIVITY_PER_PAGE = 20;

	/**
	 * Follow edges.
	 *
	 * @var Follow_Repository
	 */
	private $follows;

	/**
	 * Activity feed reads/writes.
	 *
	 * @var Activity_Repository
	 */
	private $activity;

	/**
	 * Used to batch per-member library entry counts for `GET /members`.
	 *
	 * @var Library_Repository
	 */
	private $library;

	/**
	 * Used to batch game rows referenced by an activity feed page.
	 *
	 * @var Game_Repository
	 */
	private $games;

	/**
	 * Used to build cover-image URLs for game rows embedded in feed entries.
	 *
	 * @var Game_Mapper
	 */
	private $mapper;

	/**
	 * Cached, capability-filtered member directory reads for `GET /members`
	 * (MR-4).
	 *
	 * @var Member_Directory
	 */
	private $members;

	/**
	 * Constructor.
	 *
	 * @param Follow_Repository|null   $follows  Follow repository. Defaults to a new instance.
	 * @param Activity_Repository|null $activity Activity repository. Defaults to a new instance.
	 * @param Library_Repository|null  $library  Library repository. Defaults to a new instance.
	 * @param Game_Repository|null     $games    Game repository. Defaults to a new instance.
	 * @param Game_Mapper|null         $mapper   Payload mapper. Defaults to a new instance.
	 * @param Member_Directory|null    $members  Member directory. Defaults to a new instance.
	 */
	public function __construct(
		?Follow_Repository $follows = null,
		?Activity_Repository $activity = null,
		?Library_Repository $library = null,
		?Game_Repository $games = null,
		?Game_Mapper $mapper = null,
		?Member_Directory $members = null
	) {
		$this->follows  = $follows ?: new Follow_Repository();
		$this->activity = $activity ?: new Activity_Repository();
		$this->library  = $library ?: new Library_Repository();
		$this->games    = $games ?: new Game_Repository();
		$this->mapper   = $mapper ?: new Game_Mapper();
		$this->members  = $members ?: new Member_Directory();
	}

	/**
	 * Registers this controller's routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/follow/(?P<user_id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'follow' ),
					'permission_callback' => array( $this, 'member_permissions_check' ),
					'args'                => array(
						'user_id' => array(
							'description'       => __( 'Member to follow.', 'game-library' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unfollow' ),
					'permission_callback' => array( $this, 'member_permissions_check' ),
					'args'                => array(
						'user_id' => array(
							'description'       => __( 'Member to unfollow.', 'game-library' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/activity',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_activity' ),
					'permission_callback' => array( $this, 'member_permissions_check' ),
					'args'                => array(
						'page' => array(
							'description'       => __( 'Page number.', 'game-library' ),
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 1,
							'minimum'           => 1,
							// MR-1: a sanity ceiling only, see Library_Controller's
							// identical `page` arg for the full reasoning — the
							// real per-request bound (however many pages the
							// viewer's feed actually has) cannot be expressed in
							// a static schema.
							'maximum'           => 10000,
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/members',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_members' ),
					'permission_callback' => array( $this, 'member_permissions_check' ),
					'args'                => array(
						'page' => array(
							'description'       => __( 'Page number.', 'game-library' ),
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 1,
							'minimum'           => 1,
							// MR-1: sanity ceiling, see Library_Controller's
							// identical `page` arg.
							'maximum'           => 10000,
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/profile/visibility',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_visibility' ),
					'permission_callback' => array( $this, 'member_permissions_check' ),
					'args'                => array(
						'public' => array(
							'description'       => __( 'Whether this member\'s library is visible to logged-out visitors.', 'game-library' ),
							'type'              => 'boolean',
							'required'          => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission callback for every route this controller registers —
	 * `is_user_logged_in()` is the only gate every one of them needs
	 * (AC-033 (f), the constraint list's "GET /members and GET /activity
	 * require is_user_logged_in()").
	 *
	 * @return true|WP_Error
	 */
	public function member_permissions_check() {
		return $this->require_login();
	}

	/**
	 * Handles `POST /follow/{user_id}` (AC-019).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function follow( WP_REST_Request $request ) {
		$following_id = absint( $request->get_param( 'user_id' ) );
		$follower_id  = get_current_user_id();

		if ( ! get_user_by( 'id', $following_id ) ) {
			return $this->not_found( __( 'That member could not be found.', 'game-library' ) );
		}

		$result = $this->follows->follow( $follower_id, $following_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'following' => true ) );
	}

	/**
	 * Handles `DELETE /follow/{user_id}`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unfollow( WP_REST_Request $request ) {
		$following_id = absint( $request->get_param( 'user_id' ) );
		$follower_id  = get_current_user_id();

		if ( ! $this->follows->unfollow( $follower_id, $following_id ) ) {
			return $this->not_found( __( 'You are not following that member.', 'game-library' ) );
		}

		return rest_ensure_response( array( 'following' => false ) );
	}

	/**
	 * Handles `GET /activity` (AC-020, AC-021, AC-022, AC-023). Returns
	 * structured data only — no pre-rendered HTML sentence, so a JS consumer
	 * never has to (and must not) insert response data into the DOM via
	 * `innerHTML`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_activity( WP_REST_Request $request ) {
		$viewer_id = get_current_user_id();
		$page      = absint( $request->get_param( 'page' ) );

		$following_ids = $this->follows->following_ids( $viewer_id );
		$entries       = $this->activity->get_feed( $viewer_id, $following_ids, $page, self::ACTIVITY_PER_PAGE );

		$game_ids = array_values( array_unique( array_filter( wp_list_pluck( $entries, 'igdb_id' ) ) ) );
		$games    = ! empty( $game_ids ) ? $this->games->get_many( $game_ids ) : array();

		$items = array();

		foreach ( $entries as $entry ) {
			$items[] = $this->format_activity_entry( $entry, $games );
		}

		return rest_ensure_response( $items );
	}

	/**
	 * Handles `GET /members` (AC-018).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_members( WP_REST_Request $request ) {
		$viewer_id = get_current_user_id();
		$page      = absint( $request->get_param( 'page' ) );

		// MR-4: Member_Directory caches both the id list and the total —
		// this was previously an uncached WP_User_Query on every call, the
		// heaviest shape that API can produce (a leading-wildcard
		// capability meta_value LIKE plus SQL_CALC_FOUND_ROWS).
		$member_ids = $this->members->member_ids( $page, self::MEMBERS_PER_PAGE );

		if ( empty( $member_ids ) ) {
			return rest_ensure_response( array() );
		}

		// One call primes every member row on this page — never one
		// get_userdata() per row (this task's own batching constraint).
		cache_users( $member_ids );

		$counts    = $this->library->counts_for_users( $member_ids );
		$following = $this->follows->is_following_map( $viewer_id, $member_ids );

		$items = array();

		foreach ( $member_ids as $member_id ) {
			$user = get_userdata( $member_id );

			if ( ! $user ) {
				continue;
			}

			$items[] = array(
				'id'           => $member_id,
				'display_name' => $user->display_name,
				'avatar_url'   => get_avatar_url( $member_id ),
				'library_url'  => Router::member_library_url( $user->user_nicename ),
				'entry_count'  => isset( $counts[ $member_id ] ) ? $counts[ $member_id ] : 0,
				'is_following' => isset( $following[ $member_id ] ) ? $following[ $member_id ] : false,
				'is_public'    => Visibility::is_public( $member_id ),
			);
		}

		$total = $this->members->member_count();

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / self::MEMBERS_PER_PAGE ) ) );

		return $response;
	}

	/**
	 * Handles `PATCH /profile/visibility` (AC-034). Always writes the current
	 * user's own meta — the route declares no `user_id` arg, so there is no
	 * way for a caller to target another member.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_visibility( WP_REST_Request $request ) {
		$public  = (bool) $request->get_param( 'public' );
		$user_id = get_current_user_id();

		Visibility::set_public( $user_id, $public );

		return rest_ensure_response( array( 'public' => $public ) );
	}

	/**
	 * Shapes one activity row into structured response data.
	 *
	 * @param array<string,mixed>                    $entry Hydrated `gl_activity` row.
	 * @param array<int,array<string,mixed>>          $games Batched `Game_Repository::get_many()` result, keyed by igdb_id.
	 * @return array<string,mixed>
	 */
	private function format_activity_entry( array $entry, array $games ) {
		$actor       = get_userdata( $entry['user_id'] );
		$game        = ( null !== $entry['igdb_id'] && isset( $games[ $entry['igdb_id'] ] ) ) ? $games[ $entry['igdb_id'] ] : null;
		$object_user = null !== $entry['object_user_id'] ? get_userdata( $entry['object_user_id'] ) : false;

		return array(
			'id'           => $entry['id'],
			'event_type'   => $entry['event_type'],
			'status_from'  => $entry['status_from'],
			'status_to'    => $entry['status_to'],
			'date_created' => $entry['date_created'],
			'actor'        => $actor ? $this->format_member_summary( $actor ) : null,
			'game'         => $game ? array(
				'igdb_id'   => $game['igdb_id'],
				'name'      => $game['name'],
				'slug'      => $game['slug'],
				'cover_url' => $this->mapper->cover_url( $game['cover_image_id'] ),
				'url'       => Router::game_url( $game['slug'] ),
			) : null,
			'object_user'  => $object_user ? $this->format_member_summary( $object_user ) : null,
		);
	}

	/**
	 * Shapes one `WP_User` into the small summary shape both the activity
	 * feed's actor/object_user fields and the member directory reuse.
	 *
	 * @param WP_User $user User to summarise.
	 * @return array<string,mixed>
	 */
	private function format_member_summary( WP_User $user ) {
		return array(
			'id'           => $user->ID,
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user->ID ),
			'library_url'  => Router::member_library_url( $user->user_nicename ),
		);
	}
}
