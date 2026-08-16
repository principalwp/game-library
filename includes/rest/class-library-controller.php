<?php
/**
 * Library CRUD (`/library`, `/library/{id}`) and the manual IGDB refresh
 * action (`/games/{igdb_id}/refresh`).
 *
 * @package Game_Library
 */

namespace Game_Library\Rest;

use Game_Library\Data\Game_Repository;
use Game_Library\Data\Library_Repository;
use Game_Library\Igdb\Client;
use Game_Library\Igdb\Game_Mapper;
use Game_Library\Statuses;
use Game_Library\Visibility;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Library_Controller.
 *
 * Registers every member-facing library-curation route:
 *
 * - `POST /library` — add a game (fetching + caching its metadata through
 *   `Client`/`Game_Mapper` first when not already cached), or update the
 *   status of a game the member already holds (AC-010).
 * - `PATCH /library/{id}` and `DELETE /library/{id}` — change status
 *   (AC-011) or remove (AC-012) one of the *current member's own* entries,
 *   `{id}` read as an `igdb_id`. Ownership is structural: neither handler
 *   accepts a target user, so a write can only ever touch
 *   `get_current_user_id()`'s own entry.
 * - `GET /library/{id}` — one page of *any* member's library, `{id}` read as
 *   a `user_id`. The same URL shape as the two routes above, disambiguated
 *   purely by HTTP method, per this task's own route list.
 * - `POST /games/{igdb_id}/refresh` — re-fetch and overwrite one game's
 *   cached row, gated to a member who holds the game or can
 *   `gl_moderate_library`, with a 3600-second per-game cooldown (AC-008).
 *
 * No controller method issues its own SQL — every read/write goes through
 * `Game_Repository`/`Library_Repository` (Task 3), and every IGDB call goes
 * through `Client`/`Game_Mapper` (Task 5).
 *
 * Every arg below also sets `'validate_callback' => 'rest_validate_request_arg'`
 * — confirmed at runtime against the shared Playground instance (port 9401)
 * that `WP_REST_Request::has_valid_params()` only runs an arg's schema
 * validation (`type`/`enum`/`minimum`/`maximum`) when `validate_callback` is
 * present; declaring the schema keys alone (as `Search_Controller`'s `q` arg
 * first did) lets an out-of-range or wrong-type value straight through to the
 * callback with only its `sanitize_callback` applied.
 */
final class Library_Controller extends REST_Controller {

	/**
	 * REST base — `game-library/v1/library`.
	 *
	 * @var string
	 */
	protected $rest_base = 'library';

	/**
	 * Cached game metadata.
	 *
	 * @var Game_Repository
	 */
	private $games;

	/**
	 * Library entries.
	 *
	 * @var Library_Repository
	 */
	private $library;

	/**
	 * IGDB/Twitch client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Maps a raw IGDB payload onto a `Game_Repository::upsert()` row.
	 *
	 * @var Game_Mapper
	 */
	private $mapper;

	/**
	 * Constructor.
	 *
	 * @param Game_Repository|null    $games   Game repository. Defaults to a new instance.
	 * @param Library_Repository|null $library Library repository. Defaults to a new instance.
	 * @param Client|null             $client  IGDB client. Defaults to a new instance.
	 * @param Game_Mapper|null        $mapper  Payload mapper. Defaults to a new instance.
	 */
	public function __construct(
		?Game_Repository $games = null,
		?Library_Repository $library = null,
		?Client $client = null,
		?Game_Mapper $mapper = null
	) {
		$this->games   = $games ?: new Game_Repository();
		$this->library = $library ?: new Library_Repository();
		$this->client  = $client ?: new Client();
		$this->mapper  = $mapper ?: new Game_Mapper();
	}

	/**
	 * Registers this controller's routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_to_library' ),
					'permission_callback' => array( $this, 'manage_library_permissions_check' ),
					'args'                => array(
						'igdb_id' => array(
							'description'       => __( 'IGDB game id to add.', 'game-library' ),
							'type'               => 'integer',
							'required'           => true,
							'sanitize_callback'  => 'absint',
							'validate_callback'  => 'rest_validate_request_arg',
						),
						'status'  => array(
							'description'       => __( 'Library status for this entry.', 'game-library' ),
							'type'               => 'string',
							'required'           => true,
							'enum'               => Statuses::all(),
							'validate_callback'  => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_library' ),
					'permission_callback' => array( $this, 'get_permissions_check' ),
					'args'                => array(
						'id'       => array(
							'description'       => __( 'Member user id whose library to read.', 'game-library' ),
							'type'               => 'integer',
							'required'           => true,
							'sanitize_callback'  => 'absint',
							'validate_callback'  => 'rest_validate_request_arg',
						),
						'status'   => array(
							'description'       => __( 'Filter to one status.', 'game-library' ),
							'type'               => 'string',
							'enum'               => Statuses::all(),
							'validate_callback'  => 'rest_validate_request_arg',
						),
						'page'     => array(
							'description'       => __( 'Page number.', 'game-library' ),
							'type'               => 'integer',
							'sanitize_callback'  => 'absint',
							'default'            => 1,
							'minimum'            => 1,
							// MR-1: a sanity ceiling only — the real per-request
							// bound is however many pages the member's library
							// actually has, which this static schema cannot
							// express. Rejects a pathologically large page
							// number (400) before it ever reaches
							// Library_Repository::get_page(), where it would
							// otherwise be a guaranteed cache-miss OFFSET query.
							'maximum'            => 10000,
							'validate_callback'  => 'rest_validate_request_arg',
						),
						'per_page' => array(
							'description'       => __( 'Items per page (max 50).', 'game-library' ),
							'type'               => 'integer',
							'sanitize_callback'  => 'absint',
							'default'            => 24,
							'minimum'            => 1,
							'maximum'            => 50,
							'validate_callback'  => 'rest_validate_request_arg',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_status' ),
					'permission_callback' => array( $this, 'manage_library_permissions_check' ),
					'args'                => array(
						'id'     => array(
							'description'       => __( 'IGDB game id of the entry to update.', 'game-library' ),
							'type'               => 'integer',
							'required'           => true,
							'sanitize_callback'  => 'absint',
							'validate_callback'  => 'rest_validate_request_arg',
						),
						'status' => array(
							'description'       => __( 'New library status.', 'game-library' ),
							'type'               => 'string',
							'required'           => true,
							'enum'               => Statuses::all(),
							'validate_callback'  => 'rest_validate_request_arg',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => array( $this, 'manage_library_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'IGDB game id of the entry to remove.', 'game-library' ),
							'type'               => 'integer',
							'required'           => true,
							'sanitize_callback'  => 'absint',
							'validate_callback'  => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/games/(?P<igdb_id>[\d]+)/refresh',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh' ),
					'permission_callback' => array( $this, 'refresh_permissions_check' ),
					'args'                => array(
						'igdb_id' => array(
							'description'       => __( 'IGDB game id to refresh.', 'game-library' ),
							'type'               => 'integer',
							'required'           => true,
							'sanitize_callback'  => 'absint',
							'validate_callback'  => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		// DES-50 (restyle §C): the quick-look detail panel's data source. A
		// sibling GET on the same `/games/{igdb_id}` base the refresh route
		// already owns (disambiguated by method + the trailing `/refresh`
		// segment), returning the full cached metadata the shared `<dialog>`
		// renders — cover, release year, genres, platforms, summary, and the
		// IGDB attribution URL. Public (`__return_true`, restyle §F.2, human
		// ruling): this is the same global game metadata `/games/{slug}/`
		// already serves anyone with no auth gate, carrying no per-user library
		// state. Reads `Game_Repository::get()`/`Game_Mapper` verbatim — no new
		// query shape, no schema change.
		register_rest_route(
			$this->namespace,
			'/games/(?P<igdb_id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_game' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'igdb_id' => array(
							'description'       => __( 'IGDB game id to look up.', 'game-library' ),
							'type'               => 'integer',
							'required'           => true,
							'sanitize_callback'  => 'absint',
							'validate_callback'  => 'rest_validate_request_arg',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission callback for `POST /library`, `PATCH /library/{id}`, and
	 * `DELETE /library/{id}` — `gl_manage_library` is the only gate; every
	 * write always targets `get_current_user_id()`'s own entry, so no
	 * separate ownership check is possible or necessary here.
	 *
	 * @return true|WP_Error
	 */
	public function manage_library_permissions_check() {
		return $this->require_capability( 'gl_manage_library' );
	}

	/**
	 * Permission callback for `GET /library/{id}` — any logged-in member may
	 * read any other member's library; a logged-out visitor may read only a
	 * member who has opted `_gl_profile_public` (AC-033 (g)), matching
	 * `Router::gate_member_library()`'s front-end gate.
	 *
	 * SE-3 (CWE-204): for an anonymous caller, "no such member" (404) and
	 * "exists but private" (401) previously resolved to two distinguishable
	 * responses — walking `id` values against this route sizes the member
	 * roster and maps valid user ids across the whole `wp_users` table
	 * (including administrators and non-member accounts) on a site whose
	 * entire premise is that membership is private, and feeds both an
	 * account-exists oracle for `/join/` and a target list for credential
	 * stuffing. An anonymous caller now always gets the identical 401
	 * either way; only a genuinely public member's library resolves to
	 * `true`. An authenticated caller keeps the informative 404 — they can
	 * already read the full member roster via `/members/`, so it discloses
	 * nothing they cannot already see there.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_permissions_check( WP_REST_Request $request ) {
		$user_id = absint( $request->get_param( 'id' ) );

		if ( ! is_user_logged_in() ) {
			return ( Visibility::is_public( $user_id ) && get_user_by( 'id', $user_id ) ) ? true : $this->require_login();
		}

		if ( ! get_user_by( 'id', $user_id ) ) {
			return $this->not_found( __( 'That member could not be found.', 'game-library' ) );
		}

		return true;
	}

	/**
	 * Permission callback for `POST /games/{igdb_id}/refresh` — the current
	 * member must either already hold the game or be able to
	 * `gl_moderate_library` (AC-008).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function refresh_permissions_check( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return $this->require_login();
		}

		$igdb_id = absint( $request->get_param( 'igdb_id' ) );
		$user_id = get_current_user_id();

		if ( $this->library->has_game( $user_id, $igdb_id ) || current_user_can( 'gl_moderate_library' ) ) {
			return true;
		}

		return $this->forbidden( __( 'You must hold this game, or be able to moderate the library, to refresh it.', 'game-library' ) );
	}

	/**
	 * Handles `POST /library` (AC-010). Fetches and caches the game's
	 * metadata through `Client`/`Game_Mapper` first when it is not already
	 * cached — the only place in this class an outbound IGDB call happens
	 * outside `refresh()`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_to_library( WP_REST_Request $request ) {
		$igdb_id = absint( $request->get_param( 'igdb_id' ) );
		$status  = (string) $request->get_param( 'status' );
		$user_id = get_current_user_id();

		$game = $this->games->get( $igdb_id );

		if ( null === $game ) {
			$cached = $this->cache_game_from_igdb( $igdb_id );

			if ( is_wp_error( $cached ) ) {
				// map_error() is a no-op passthrough for any code outside its
				// own map (e.g. not_found()'s gl_rest_not_found, already
				// carrying status 404, or an upsert() validation/db error
				// that should fall through to WP_REST_Server's 500 default)
				// — safe to call unconditionally on every WP_Error here.
				return $this->map_error( $cached );
			}

			$game = $cached;
		}

		$entry = $this->library->add_or_update( $user_id, $igdb_id, $status );

		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		return rest_ensure_response( $this->format_entry( $entry, $game ) );
	}

	/**
	 * Handles `PATCH /library/{id}` (AC-011). 404s when the current member
	 * does not already hold this game — a status change presumes an existing
	 * entry; it never creates one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_status( WP_REST_Request $request ) {
		$igdb_id = absint( $request->get_param( 'id' ) );
		$status  = (string) $request->get_param( 'status' );
		$user_id = get_current_user_id();

		if ( ! $this->library->has_game( $user_id, $igdb_id ) ) {
			return $this->not_found( __( 'You do not have that game in your library.', 'game-library' ) );
		}

		$entry = $this->library->add_or_update( $user_id, $igdb_id, $status );

		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		return rest_ensure_response( $this->format_entry( $entry, $this->games->get( $igdb_id ) ) );
	}

	/**
	 * Handles `DELETE /library/{id}` (AC-012).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove( WP_REST_Request $request ) {
		$igdb_id = absint( $request->get_param( 'id' ) );
		$user_id = get_current_user_id();

		if ( ! $this->library->remove( $user_id, $igdb_id ) ) {
			return $this->not_found( __( 'You do not have that game in your library.', 'game-library' ) );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Handles `GET /library/{id}` — one page of a member's library, embedding
	 * each entry's game metadata via one batched `Game_Repository::get_many()`
	 * call rather than one lookup per entry (never N+1).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_library( WP_REST_Request $request ) {
		$user_id  = absint( $request->get_param( 'id' ) );
		$status   = $request->get_param( 'status' );
		$status   = is_string( $status ) ? $status : '';
		$page     = absint( $request->get_param( 'page' ) );
		$per_page = absint( $request->get_param( 'per_page' ) );

		$entries = $this->library->get_page( $user_id, $status, $page, $per_page );
		$counts  = $this->library->status_counts( $user_id );
		$total   = Statuses::is_valid( $status ) ? $counts[ $status ] : array_sum( $counts );

		$games = $this->games->get_many( wp_list_pluck( $entries, 'igdb_id' ) );

		$items = array();

		foreach ( $entries as $entry ) {
			$items[] = $this->format_entry( $entry, isset( $games[ $entry['igdb_id'] ] ) ? $games[ $entry['igdb_id'] ] : null );
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );

		return $response;
	}

	/**
	 * Handles `POST /games/{igdb_id}/refresh` (AC-008). Rejects a second
	 * refresh of the same game within `Game_Repository::REFRESH_COOLDOWN`
	 * seconds without issuing any outbound request — the cooldown transient
	 * is checked before `Client::fetch_games()` is ever called.
	 * `Game_Repository` owns the cooldown key/TTL contract shared with
	 * `Moderation_Page`'s own "Refresh from IGDB" action (AC-008, arch-pre-3
	 * architecture review, finding AR-3).
	 *
	 * SE-1 (cycle-3, same defect class as `Invite_Service`'s mail budget):
	 * the cooldown is armed immediately after the check passes, BEFORE
	 * `Client::fetch_games()` is called — not after the upsert. Arming after
	 * the outbound call left a window where every request arriving inside
	 * one IGDB round-trip read the cooldown as absent and all reached IGDB,
	 * and a consistently-failing game (whose upsert never runs) never armed
	 * the cooldown at all, so it could be retried without limit.
	 *
	 * MR-2 (cycle-5): deliberately does NOT clear the cooldown on a failure
	 * branch below (`is_wp_error( $fetched )`, an empty result, or a failed
	 * upsert) — the attempt already consumed the outbound IGDB budget this
	 * cooldown exists to bound, and a consistently-failing game must not be
	 * replayable just because it fails. `Moderation_Page::
	 * handle_refresh_game()` shares this same cooldown key/TTL and the same
	 * deliberate no-clear-on-failure behavior — the two handlers must not
	 * drift apart on this again.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refresh( WP_REST_Request $request ) {
		$igdb_id      = absint( $request->get_param( 'igdb_id' ) );
		$cooldown_key = Game_Repository::refresh_cooldown_key( $igdb_id );

		if ( false !== get_transient( $cooldown_key ) ) {
			return $this->map_error(
				new WP_Error( 'gl_refresh_cooldown', __( 'This game was recently refreshed. Try again shortly.', 'game-library' ) )
			);
		}

		set_transient( $cooldown_key, 1, Game_Repository::REFRESH_COOLDOWN );

		$fetched = $this->client->fetch_games( array( $igdb_id ) );

		if ( is_wp_error( $fetched ) ) {
			return $this->map_error( $fetched );
		}

		if ( empty( $fetched ) ) {
			return $this->not_found( __( 'That game could not be found on IGDB.', 'game-library' ) );
		}

		$upserted = $this->games->upsert( $this->mapper->to_row( $fetched[0] ) );

		if ( is_wp_error( $upserted ) ) {
			return $upserted;
		}

		$game = $this->games->get( $igdb_id );

		return rest_ensure_response(
			array(
				'igdb_id'   => $game['igdb_id'],
				'name'      => $game['name'],
				'slug'      => $game['slug'],
				'cover_url' => $this->mapper->cover_url( $game['cover_image_id'] ),
			)
		);
	}

	/**
	 * Handles `GET /games/{igdb_id}` (DES-50, restyle §C) — the read-only
	 * metadata payload the shared quick-look `<dialog>` renders. Serves only a
	 * game already cached in `gl_games`; a never-cached id 404s rather than
	 * triggering an outbound IGDB fetch (unlike `add_to_library()`), keeping
	 * this a pure, public, cache read. `first_release_year` and `cover_url`
	 * are derived through `Game_Mapper` exactly as `format_entry()` and
	 * `game-single.php` derive them, so the panel's year/cover match the card
	 * and the full page for the same game.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_game( WP_REST_Request $request ) {
		$igdb_id = absint( $request->get_param( 'igdb_id' ) );
		$game    = $this->games->get( $igdb_id );

		if ( null === $game ) {
			return $this->not_found( __( 'That game could not be found.', 'game-library' ) );
		}

		return rest_ensure_response(
			array(
				'igdb_id'            => $game['igdb_id'],
				'name'               => $game['name'],
				'slug'               => $game['slug'],
				'cover_url'          => $this->mapper->cover_url( $game['cover_image_id'], 'big' ),
				'first_release_year' => $this->mapper->first_release_year( $game ),
				'genres'             => $game['genres'],
				'platforms'          => $game['platforms'],
				'summary'            => $game['summary'],
				'igdb_url'           => $game['igdb_url'],
			)
		);
	}

	/**
	 * Fetches one game live from IGDB, maps it, and caches it — the shared
	 * path `add_to_library()` uses when a member adds a game not already in
	 * `gl_games`.
	 *
	 * @param int $igdb_id IGDB game id.
	 * @return array<string,mixed>|WP_Error The hydrated, freshly cached game
	 *                                       row, or a WP_Error on an IGDB or
	 *                                       database failure.
	 */
	private function cache_game_from_igdb( $igdb_id ) {
		$fetched = $this->client->fetch_games( array( $igdb_id ) );

		if ( is_wp_error( $fetched ) ) {
			return $fetched;
		}

		if ( empty( $fetched ) ) {
			return $this->not_found( __( 'That game could not be found on IGDB.', 'game-library' ) );
		}

		$upserted = $this->games->upsert( $this->mapper->to_row( $fetched[0] ) );

		if ( is_wp_error( $upserted ) ) {
			return $upserted;
		}

		return $this->games->get( $igdb_id );
	}

	/**
	 * Shapes one library entry plus its game metadata into the payload every
	 * write/read handler in this class returns.
	 *
	 * @param array<string,mixed>      $entry Hydrated `gl_library_entries` row.
	 * @param array<string,mixed>|null $game  Hydrated `gl_games` row, or null
	 *                                        when the game could not be
	 *                                        resolved (should not normally
	 *                                        happen once cached).
	 * @return array<string,mixed>
	 */
	private function format_entry( array $entry, $game ) {
		return array(
			'igdb_id'       => $entry['igdb_id'],
			'status'        => $entry['status'],
			'date_added'    => $entry['date_added'],
			'date_modified' => $entry['date_modified'],
			'game'          => null === $game ? null : array(
				'name'               => $game['name'],
				'slug'               => $game['slug'],
				'cover_url'          => $this->mapper->cover_url( $game['cover_image_id'] ),
				'first_release_year' => $this->mapper->first_release_year( $game ),
			),
		);
	}
}
