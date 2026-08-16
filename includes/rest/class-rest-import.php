<?php
/**
 * REST routes for import jobs.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The import half of the `gamelib/v1` REST surface.
 *
 * - `POST /imports`            — upload a CSV/JSON export and queue it
 *                                (AC-038), or start a Steam import from a
 *                                SteamID64/vanity name (AC-040). The `type`
 *                                body parameter picks between them and
 *                                defaults to `file`.
 * - `GET  /imports/{id}`       — the job's progress and buckets, as JSON and as
 *                                the rendered fragment the review screen swaps
 *                                in (AC-042, AC-043b).
 * - `POST /imports/{id}/retry` — pick a stalled job back up (AC-043d).
 * - `POST /imports/{id}/items/{item_id}/choose` — attach one reviewed row to a
 *                                game and add it as backlog (AC-042 b,c).
 * - `POST /imports/{id}/items/{item_id}/skip`   — dismiss one reviewed row
 *                                (AC-042 b,c).
 * - `POST /imports/{id}/items/{item_id}/search` — the not-found row's manual
 *                                IGDB search box (AC-042c). It only ever
 *                                *offers* candidates; attaching one is a
 *                                `choose`.
 * - `POST /imports/{id}/finish` — close a reviewed import, which is what fires
 *                                its single `games_imported` event (AC-044a).
 *
 * Two rules hold for all of them, and are what AC-NFR-001 (l)–(p) asserts:
 *
 * 1. **Authorization first.** No `permission_callback` here is
 *    `__return_true`: each requires `gamelib_manage_library`, so a logged-out
 *    caller gets 401 and a logged-in caller without the capability gets 403.
 *    A cookie-authenticated request that omits the `wp_rest` nonce is treated
 *    as anonymous by core and answers 401; a wrong nonce is core's own 403
 *    before any callback runs.
 * 2. **The owner is the session.** An id is a path parameter, so ownership is
 *    checked against the row: {@see GameLib_Importer::get_for_user()} answers
 *    404 for another member's import *and* for one that does not exist, which
 *    is what stops an id from being probed. A row id is scoped the same way —
 *    {@see GameLib_Importer::item()} is import-scoped, so a row belonging to
 *    somebody else's job reads as "no such row" even once the import id has
 *    been proven to be the caller's.
 *
 * The upload route performs no outbound request of any kind — it validates,
 * writes the job, and schedules a cron tick (AC-038e). Its Steam sibling talks
 * to Steam and to nothing else. Matching — every IGDB request an import makes —
 * happens in {@see GameLib_Importer::tick()}, off the request path (AC-040e).
 */
final class GameLib_REST_Import {

	/**
	 * REST namespace shared by every plugin route (§6 Integration Points).
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'gamelib/v1';

	/**
	 * Multipart field the upload arrives in.
	 *
	 * @var string
	 */
	const FILE_PARAM = 'file';

	/**
	 * Body parameter choosing which kind of import to start.
	 *
	 * @var string
	 */
	const TYPE_PARAM = 'type';

	/**
	 * Body parameter carrying the member's SteamID64 or vanity name.
	 *
	 * @var string
	 */
	const ACCOUNT_PARAM = 'steam_account';

	/**
	 * Path parameter naming one row of an import.
	 *
	 * @var string
	 */
	const ITEM_PARAM = 'item_id';

	/**
	 * Query parameter a poller round-trips so an unchanged job answers without
	 * the rendered fragment (PF-4).
	 *
	 * @var string
	 */
	const FINGERPRINT_PARAM = 'fingerprint';

	/**
	 * Body parameter carrying the game a member picked for a row (AC-042 b,c).
	 *
	 * @var string
	 */
	const IGDB_PARAM = 'igdb_id';

	/**
	 * Body parameter carrying the manual search box's query (AC-042c).
	 *
	 * @var string
	 */
	const QUERY_PARAM = 'q';

	/**
	 * Body parameter a decision sets when it needs the whole re-rendered job
	 * rather than the moved row (PB-2).
	 *
	 * The server works out every other reason a move cannot express a decision
	 * (see {@see needs_whole_job()}); this covers the one it cannot see — a
	 * sibling decision in flight in the same browser, whose response the client's
	 * sequence guard is about to discard (CO-1).
	 *
	 * @var string
	 */
	const FULL_PARAM = 'full';

	/**
	 * `type` value for the CSV/JSON upload — the default when none is sent.
	 *
	 * @var string
	 */
	const TYPE_FILE = 'file';

	/**
	 * `type` value for a public Steam profile (AC-040).
	 *
	 * @var string
	 */
	const TYPE_STEAM = 'steam';

	/**
	 * The two kinds of import a member may start.
	 *
	 * @var string[]
	 */
	const TYPES = array( self::TYPE_FILE, self::TYPE_STEAM );

	/**
	 * Register every route in this class.
	 *
	 * Hooked to `rest_api_init` from {@see GameLib_Plugin::boot()}.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/imports',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'start' ),
					'permission_callback' => array( __CLASS__, 'check_member' ),
					/*
					 * Neither parameter is `required`: an args schema is
					 * validated before the permission callback runs, so a
					 * logged-out caller must reach the 401 rather than a 400
					 * about a missing field (AC-NFR-001 l). The whitelist below
					 * is documentation — the callback enforces it.
					 */
					'args'                => array(
						self::TYPE_PARAM    => array(
							'type'              => 'string',
							'default'           => self::TYPE_FILE,
							'enum'              => self::TYPES,
							'description'       => __( 'Which kind of import to start.', 'game-library' ),
							'sanitize_callback' => 'sanitize_key',
						),
						self::ACCOUNT_PARAM => array(
							'type'              => 'string',
							'description'       => __( 'SteamID64 or Steam profile name, for a Steam import.', 'game-library' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/(?P<id>[0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'status' ),
					'permission_callback' => array( __CLASS__, 'check_member' ),
					'args'                => array(
						'id'                     => self::id_schema(),
						self::FINGERPRINT_PARAM  => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'description'       => __( 'Digest of the job state the caller already rendered; a match answers without the HTML fragment.', 'game-library' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/(?P<id>[0-9]+)/retry',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'retry' ),
					'permission_callback' => array( __CLASS__, 'check_member' ),
					'args'                => array(
						'id' => self::id_schema(),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/(?P<id>[0-9]+)/finish',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'finish' ),
					'permission_callback' => array( __CLASS__, 'check_member' ),
					'args'                => array(
						'id' => self::id_schema(),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/(?P<id>[0-9]+)/items/(?P<item_id>[0-9]+)/choose',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'choose' ),
					'permission_callback' => array( __CLASS__, 'check_member' ),
					'args'                => array(
						'id'             => self::id_schema(),
						self::ITEM_PARAM => self::item_id_schema(),
						/*
						 * Not `required`: a schema is validated before the
						 * permission callback runs, and a logged-out caller has
						 * to reach the 401 rather than a 400 about a missing
						 * field (AC-NFR-001 m). The domain refuses a choice
						 * without a game id on its own account (Never Do #10).
						 */
						self::IGDB_PARAM => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'description'       => __( 'IGDB id of the game this row is being attached to.', 'game-library' ),
							'sanitize_callback' => 'absint',
						),
						self::FULL_PARAM => self::full_schema(),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/(?P<id>[0-9]+)/items/(?P<item_id>[0-9]+)/skip',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'skip' ),
					'permission_callback' => array( __CLASS__, 'check_member' ),
					'args'                => array(
						'id'             => self::id_schema(),
						self::ITEM_PARAM => self::item_id_schema(),
						self::FULL_PARAM => self::full_schema(),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/(?P<id>[0-9]+)/items/(?P<item_id>[0-9]+)/search',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'search' ),
					'permission_callback' => array( __CLASS__, 'check_member' ),
					'args'                => array(
						'id'              => self::id_schema(),
						self::ITEM_PARAM  => self::item_id_schema(),
						// Not `required`, for the same reason `igdb_id` is not.
						self::QUERY_PARAM => array(
							'type'              => 'string',
							'description'       => __( 'What to search IGDB for.', 'game-library' ),
							'sanitize_callback' => array( 'GameLib_REST_Library', 'sanitize_query' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Gate for every route in this class: a member with the library capability
	 * (Always Do #3).
	 *
	 * The 401/403 contract itself lives in
	 * {@see GameLib_Capabilities::rest_gate()} — this callback supplies only the
	 * capability and the two sentences an import caller should read.
	 *
	 * @return true|WP_Error True when the caller may run imports.
	 */
	public static function check_member() {
		return GameLib_Capabilities::rest_gate(
			GameLib_Capabilities::CAP_MANAGE_LIBRARY,
			__( 'Sign in to import a library.', 'game-library' ),
			__( 'Your account cannot manage a game library.', 'game-library' )
		);
	}

	/**
	 * `POST /imports` — start an import (AC-038, AC-040).
	 *
	 * One route, two sources: `type=file` (the default) takes the multipart
	 * upload, `type=steam` takes a SteamID64 or vanity name. Both answer with
	 * the queued job exactly as the status route would report it, so the client
	 * that polls afterwards has nothing to learn about which one it started.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error The queued job, or why it was refused.
	 */
	public static function start( $request ) {
		$type = sanitize_key( (string) $request->get_param( self::TYPE_PARAM ) );

		if ( '' === $type ) {
			$type = self::TYPE_FILE;
		}

		// The route's `enum` is documentation; this is the enforcement.
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error(
				GameLib_Importer::ERROR_SOURCE,
				__( 'That is not an import this site can run.', 'game-library' ),
				array(
					'status' => 400,
					'state'  => 'invalid_type',
				)
			);
		}

		if ( self::TYPE_STEAM === $type ) {
			return self::start_steam( $request );
		}

		return self::start_file( $request );
	}

	/**
	 * `POST /imports` with `type=steam` — start a Steam import (AC-040).
	 *
	 * Everything the AC asks for — the id/vanity rule, the visibility
	 * pre-flight, the cached owned-games read, the stored SteamID — belongs to
	 * the domain and happens in {@see GameLib_Importer::start_steam_import()};
	 * this is authorization, the parameter, and the response shape.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error The queued job, or why it was refused.
	 */
	private static function start_steam( $request ) {
		$account = $request->get_param( self::ACCOUNT_PARAM );
		$started = GameLib_Importer::start_steam_import(
			get_current_user_id(),
			is_scalar( $account ) ? (string) $account : ''
		);

		if ( is_wp_error( $started ) ) {
			return $started;
		}

		return self::started_response( $started );
	}

	/**
	 * `POST /imports` with `type=file` — validate an uploaded export and queue
	 * it (AC-038).
	 *
	 * The response carries the rows validation rejected, each with the line
	 * number it came from (AC-038d), so the member sees what will not be
	 * imported without waiting for a single tick.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error The queued job, or why it was refused.
	 */
	private static function start_file( $request ) {
		$files = $request->get_file_params();
		$file  = isset( $files[ self::FILE_PARAM ] ) && is_array( $files[ self::FILE_PARAM ] )
			? $files[ self::FILE_PARAM ]
			: array();

		if ( empty( $file ) ) {
			/*
			 * PHP discards the whole body — `$_FILES` included — when it is
			 * larger than `post_max_size`, so "no file arrived at all" on a
			 * large request is the size refusal rather than a missing field.
			 */
			$length = absint( $request->get_header( 'content_length' ) );

			if ( $length > GAMELIB_IMPORT_MAX_BYTES ) {
				return GameLib_Importer::too_large_error();
			}

			return new WP_Error(
				GameLib_Importer::ERROR_UPLOAD,
				__( 'Choose the CSV or JSON file you exported from this site.', 'game-library' ),
				array(
					'status' => 400,
					'state'  => 'no_file',
				)
			);
		}

		$started = GameLib_Importer::start_file_import( get_current_user_id(), $file );

		if ( is_wp_error( $started ) ) {
			return $started;
		}

		return self::started_response( $started );
	}

	/**
	 * The 201 both start paths answer with.
	 *
	 * @param array $started Return value of a `GameLib_Importer::start_*()` call.
	 * @return WP_REST_Response|WP_Error The queued job, or a 500 if the row it
	 *         just wrote cannot be read back.
	 */
	private static function started_response( array $started ) {
		$import = GameLib_Importer::get( isset( $started['id'] ) ? $started['id'] : 0 );

		if ( ! is_array( $import ) ) {
			return new WP_Error(
				GameLib_Importer::ERROR_FAILED,
				__( 'That import could not be started. Please try again.', 'game-library' ),
				array(
					'status' => 500,
					'state'  => 'failed',
				)
			);
		}

		$payload            = self::import_payload( $import );
		$payload['invalid'] = isset( $started['invalid'] ) ? $started['invalid'] : array();
		$payload['rows']    = isset( $started['rows'] ) ? (int) $started['rows'] : 0;
		$payload['state']   = 'import_queued';

		if ( isset( $started['steamid'] ) ) {
			// AC-046(b): the account panel shows the SteamID this import just
			// stored, without a second round trip to find out what it is.
			$payload['steamid'] = (string) $started['steamid'];
		}

		$response = rest_ensure_response( $payload );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * `GET /imports/{id}` — the job's progress and buckets (AC-042, AC-043b).
	 *
	 * A caller that sends back the `fingerprint` it already holds gets the JSON
	 * without the `html` key when nothing the fragment renders has moved (PF-4).
	 * The poller's swap already no-ops on an absent `html`, so an unchanged tick
	 * costs a small JSON body instead of the whole re-rendered panel.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error The job, or 404 when the caller does
	 *                                   not own it.
	 */
	public static function status( $request ) {
		$import = GameLib_Importer::get_for_user(
			absint( $request->get_param( 'id' ) ),
			get_current_user_id()
		);

		if ( is_wp_error( $import ) ) {
			return $import;
		}

		$payload = self::import_payload( $import );
		$known   = (string) $request->get_param( self::FINGERPRINT_PARAM );

		if ( '' !== $known && isset( $payload['fingerprint'] ) && $known === $payload['fingerprint'] ) {
			unset( $payload['html'] );
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * `POST /imports/{id}/retry` — resume a stalled job (AC-043d).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error The resumed job, or why it was refused.
	 */
	public static function retry( $request ) {
		$import = GameLib_Importer::get_for_user(
			absint( $request->get_param( 'id' ) ),
			get_current_user_id()
		);

		if ( is_wp_error( $import ) ) {
			return $import;
		}

		$resumed = GameLib_Importer::retry( $import['id'] );

		if ( is_wp_error( $resumed ) ) {
			return $resumed;
		}

		$payload          = self::import_payload( $resumed );
		$payload['state'] = 'import_retried';

		return rest_ensure_response( $payload );
	}

	/**
	 * `POST /imports/{id}/items/{item_id}/choose` — attach one row to a game
	 * (AC-042 b,c).
	 *
	 * One route for both ways a member resolves a row: picking one of the
	 * candidates the cascade offered, and attaching a game they found with the
	 * not-found row's manual search box. They are the same act — *this appid is
	 * that game* — so they take the same path, and the Steam map records it
	 * either way (AC-045).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error The refreshed job and its fragment, or
	 *                                   why the decision was refused.
	 */
	public static function choose( $request ) {
		$import = self::owned_import( $request );

		if ( is_wp_error( $import ) ) {
			return $import;
		}

		$decided = GameLib_Importer::choose(
			$import['id'],
			absint( $request->get_param( self::ITEM_PARAM ) ),
			absint( $request->get_param( self::IGDB_PARAM ) )
		);

		if ( is_wp_error( $decided ) ) {
			return $decided;
		}

		return rest_ensure_response(
			self::decision_payload(
				$decided,
				empty( $decided['duplicate'] ) ? 'import_item_added' : 'import_item_duplicate',
				$import,
				(bool) $request->get_param( self::FULL_PARAM )
			)
		);
	}

	/**
	 * `POST /imports/{id}/items/{item_id}/skip` — dismiss one row (AC-042 b,c).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error The refreshed job and its fragment, or
	 *                                   why the decision was refused.
	 */
	public static function skip( $request ) {
		$import = self::owned_import( $request );

		if ( is_wp_error( $import ) ) {
			return $import;
		}

		$decided = GameLib_Importer::skip(
			$import['id'],
			absint( $request->get_param( self::ITEM_PARAM ) )
		);

		if ( is_wp_error( $decided ) ) {
			return $decided;
		}

		return rest_ensure_response(
			self::decision_payload(
				$decided,
				'import_item_skipped',
				$import,
				(bool) $request->get_param( self::FULL_PARAM )
			)
		);
	}

	/**
	 * `POST /imports/{id}/finish` — close a reviewed import (AC-044a).
	 *
	 * The event this fires is the import's only one, and its N is every game
	 * the import put in the library — auto-attached plus review picks
	 * (AC-044b). Both facts belong to {@see GameLib_Importer::finish()}; this
	 * route is authorization and the response shape.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error The completed job and its fragment, or
	 *                                   why it could not be closed.
	 */
	public static function finish( $request ) {
		$import = self::owned_import( $request );

		if ( is_wp_error( $import ) ) {
			return $import;
		}

		$closed = GameLib_Importer::finish( $import['id'] );

		if ( is_wp_error( $closed ) ) {
			return $closed;
		}

		$payload          = self::import_payload( $closed );
		$payload['state'] = 'import_finished';
		$payload['added'] = GameLib_Importer::added_total( $closed['counts'] );

		return rest_ensure_response( $payload );
	}

	/**
	 * `POST /imports/{id}/items/{item_id}/search` — the manual IGDB search box
	 * on a not-found row (AC-042c).
	 *
	 * Deliberately a separate route from `POST /search`: the result of this one
	 * is a candidate list bound to *this row*, whose controls attach a game to
	 * it, and the row it is bound to has to be one the caller owns and has not
	 * decided yet. The fragment is rendered by the same part the stored
	 * candidates use, so a searched candidate and a cascade candidate are the
	 * same markup (ADR-002).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Candidates and their fragment, or why
	 *                                   the search was refused.
	 */
	public static function search( $request ) {
		$item = self::undecided_item( $request );

		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$query = GameLib_REST_Library::sanitize_query( $request->get_param( self::QUERY_PARAM ) );

		if ( '' === $query ) {
			return new WP_Error(
				'gamelib_rest_empty_query',
				__( 'Type a game title to search for.', 'game-library' ),
				array(
					'status' => 400,
					'state'  => 'empty_query',
				)
			);
		}

		$results = GameLib_IGDB_Client::search(
			$query,
			array( 'limit' => GameLib_Importer::CANDIDATE_LIMIT )
		);

		if ( is_wp_error( $results ) ) {
			return self::search_error( $results );
		}

		$candidates = self::candidate_rows( $results );
		$empty      = empty( $candidates );

		return rest_ensure_response(
			array(
				'query'   => $query,
				// An empty result is a state, not a failure (AC-009's rule,
				// applied to the row-level search).
				'state'   => $empty ? GameLib_REST_Library::STATE_SEARCH_EMPTY : 'search_results',
				'item_id' => $item['id'],
				'total'   => count( $candidates ),
				'results' => $candidates,
				'html'    => GameLib_Router::render_part(
					'import-candidates',
					array(
						'item_id'    => $item['id'],
						'name'       => $item['name'],
						'candidates' => $candidates,
						'message'    => $empty
							? GameLib_REST_Library::state_message( GameLib_REST_Library::STATE_SEARCH_EMPTY, $query )
							: '',
					)
				),
			)
		);
	}

	/**
	 * One job as JSON plus the fragment that renders it.
	 *
	 * The fragment comes from the same template part the review screen paints
	 * with, so a polled refresh and a reload produce identical markup, escaped
	 * in exactly one place (ADR-002). The JSON beside it is transport and is
	 * deliberately not HTML-escaped (Never Do #14).
	 *
	 * @param array $import Job row.
	 * @return array<string, mixed> Response payload.
	 */
	private static function import_payload( array $import ) {
		$payload = GameLib_Importer::payload( $import );
		$items   = GameLib_Importer::report_items( $import );

		$payload['items'] = $items;
		$payload['html']  = GameLib_Router::render_part(
			'import-status',
			array(
				'import' => $payload,
				'items'  => $items,
			)
		);

		return $payload;
	}

	/**
	 * The job named by the request's `id`, if the caller owns it.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array|WP_Error The job row, or the shared not-found refusal.
	 */
	private static function owned_import( $request ) {
		return GameLib_Importer::get_for_user(
			absint( $request->get_param( 'id' ) ),
			get_current_user_id()
		);
	}

	/**
	 * The row named by the request, if the caller owns its import and has not
	 * decided it yet.
	 *
	 * The bucket check is the same one the decision methods apply, made here so
	 * the search box cannot be used to offer candidates for a row that is
	 * already in the library.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array|WP_Error The item row, or why it cannot be acted on.
	 */
	private static function undecided_item( $request ) {
		$import = self::owned_import( $request );

		if ( is_wp_error( $import ) ) {
			return $import;
		}

		$item = GameLib_Importer::item(
			$import['id'],
			absint( $request->get_param( self::ITEM_PARAM ) )
		);

		if ( ! is_array( $item ) ) {
			return new WP_Error(
				GameLib_Importer::ERROR_ITEM,
				__( 'That row is not part of this import.', 'game-library' ),
				array(
					'status' => 404,
					'state'  => 'item_not_found',
				)
			);
		}

		$open = array( GameLib_Importer::BUCKET_NEEDS_CHOICE, GameLib_Importer::BUCKET_NOT_FOUND );

		if ( ! in_array( $item['bucket'], $open, true ) ) {
			return new WP_Error(
				GameLib_Importer::ERROR_STATE,
				__( 'That row has already been decided.', 'game-library' ),
				array(
					'status' => 409,
					'state'  => 'already_decided',
				)
			);
		}

		return $item;
	}

	/**
	 * The response a pick or a skip answers with.
	 *
	 * Every ordinary decision is one row leaving one bucket for another, and
	 * rebuilding up to 700 rows and ~4,000 nodes to express that costs 40–90ms of
	 * main-thread parsing on a mid-tier phone, throws away every other row's
	 * half-typed manual search, and does it once per decision for a member
	 * working a 100-row bucket. So the payload carries the three fragments a move
	 * needs: the moved `row` rendered for its new bucket, the `counts` region,
	 * and the freshly formatted per-bucket totals. All three come from the same
	 * parts the whole job renders with, so a moved row and a reload are
	 * byte-identical (ADR-002, AC-042e) — the client moves DOM, never composes
	 * any.
	 *
	 * The whole job — `html` and `items` — rides along **only when a move cannot
	 * express the change** (PB-2). The server decides that, rather than shipping
	 * both renderings and letting the client discard one: building the whole job
	 * costs one uncached `LIMIT 100` SELECT per non-empty bucket (up to seven)
	 * plus a `json_decode` of every `candidates` column plus a ~700-`<li>` render,
	 * and AC-042 is a per-row workflow, so a 100-row bucket paid that a hundred
	 * times over for bytes nobody read. {@see needs_whole_job()} enumerates the
	 * cases; `GET /imports/{id}` is untouched and still always carries the
	 * fragment (ADR-002).
	 *
	 * @param array  $decided  Return value of a `GameLib_Importer` decision method.
	 * @param string $state    Machine-readable outcome for the client.
	 * @param array  $fallback The job as it was read before the decision, used
	 *                         only if the re-read after it came back empty.
	 * @param bool   $full     Client's own request for the whole job — it knows
	 *                         one thing the server cannot: whether a sibling
	 *                         decision was in flight (CO-1).
	 * @return array<string, mixed> Response payload.
	 */
	private static function decision_payload( array $decided, $state, array $fallback, $full = false ) {
		$import = ( isset( $decided['import'] ) && is_array( $decided['import'] ) && ! empty( $decided['import'] ) )
			? $decided['import']
			: $fallback;
		$item   = ( isset( $decided['item'] ) && is_array( $decided['item'] ) ) ? $decided['item'] : array();

		$payload           = GameLib_Importer::payload( $import );
		$payload['state']  = $state;
		$payload['item']   = $item;
		$payload['bucket'] = isset( $item['bucket'] ) ? (string) $item['bucket'] : '';
		// The row's own note — "Added as Backlog.", "You skipped this title." —
		// is the sentence the screen announces, authored once, in PHP.
		$payload['notice'] = isset( $item['note'] ) ? (string) $item['note'] : '';

		$counts = ( isset( $payload['counts'] ) && is_array( $payload['counts'] ) ) ? $payload['counts'] : array();
		$from   = isset( $decided['from'] ) ? (string) $decided['from'] : '';

		$payload['row']           = self::row_fragment( $payload['bucket'], $item );
		$payload['counts_html']   = GameLib_Router::render_part( 'import-counts', array( 'counts' => $counts ) );
		$payload['bucket_counts'] = self::bucket_counts( $counts );

		if ( $full || self::needs_whole_job( $import, $counts, $from, $payload['bucket'], $payload['row'] ) ) {
			$items            = GameLib_Importer::report_items( $import );
			$payload['items'] = $items;
			$payload['html']  = GameLib_Router::render_part(
				'import-status',
				array(
					'import' => $payload,
					'items'  => $items,
				)
			);
		}

		return $payload;
	}

	/**
	 * Can this decision be applied as a move of one row (PB-2)?
	 *
	 * Every case the client's own move refuses is decidable here, from the job
	 * that was just written:
	 *
	 * - the row could not be rendered for its new bucket at all;
	 * - the job is no longer in `review`, so the panel's chrome — the Finish
	 *   control, the progress region, the status sentence — has changed shape;
	 * - the bucket the row **left** is now empty, so its whole section, heading
	 *   included, has to go with it;
	 * - the bucket the row **landed in** now holds exactly this row, so it had no
	 *   section on screen for the row to be appended to.
	 *
	 * The one thing this cannot see is a sibling decision in flight in the same
	 * browser (CO-1); the client asks for the whole job itself in that case.
	 *
	 * @param array  $import Job row as re-read after the decision.
	 * @param array  $counts Bucket → row count, post-decision.
	 * @param string $from   Bucket the row left.
	 * @param string $to     Bucket the row landed in.
	 * @param string $row    Rendered row fragment, '' when it could not be built.
	 * @return bool True when the client has to be handed the whole fragment.
	 */
	private static function needs_whole_job( array $import, array $counts, $from, $to, $row ) {
		if ( '' === $row || '' === $to ) {
			return true;
		}

		if ( GameLib_Importer::STATUS_REVIEW !== $import['status'] ) {
			return true;
		}

		if ( '' !== $from && $from !== $to && empty( $counts[ $from ] ) ) {
			return true;
		}

		return ( isset( $counts[ $to ] ) ? (int) $counts[ $to ] : 0 ) < 2;
	}

	/**
	 * The moved row, rendered for the bucket it landed in (PF-3).
	 *
	 * Rendered straight from the decision's own re-read of the row, which is
	 * `GameLib_Importer::item()` — byte for byte the shape `items()` produces for
	 * `report_items()`, note and candidates included, because both go through
	 * `hydrate_item()`. Walking the whole job's rows to find this one again was
	 * what tied a single-row response to a seven-query hydration of every bucket
	 * (PB-2).
	 *
	 * @param string $bucket Bucket the row landed in.
	 * @param array  $item   The decided row, as `GameLib_Importer::item()`
	 *                       hydrated it.
	 * @return string Rendered `<li>`, or '' when the row cannot be placed and
	 *                the caller should fall back to the whole-job fragment.
	 */
	private static function row_fragment( $bucket, array $item ) {
		$item_id = isset( $item['id'] ) ? (int) $item['id'] : 0;
		$bucket  = (string) $bucket;

		if ( $item_id < 1 || '' === $bucket || $bucket !== ( isset( $item['bucket'] ) ? (string) $item['bucket'] : '' ) ) {
			return '';
		}

		return GameLib_Router::render_part(
			'import-row',
			array(
				'item'   => $item,
				'bucket' => $bucket,
			)
		);
	}

	/**
	 * Every reported bucket's count, formatted for display (PF-3).
	 *
	 * Formatted here rather than in the client: `number_format_i18n()` is the
	 * locale's own grouping and digits, and a number written client-side would
	 * lose both.
	 *
	 * @param array $counts Bucket → row count.
	 * @return array<string, string> Bucket → formatted count.
	 */
	private static function bucket_counts( array $counts ) {
		$formatted = array();

		foreach ( array_keys( GameLib_Importer::bucket_labels() ) as $bucket ) {
			$formatted[ $bucket ] = number_format_i18n( isset( $counts[ $bucket ] ) ? (int) $counts[ $bucket ] : 0 );
		}

		return $formatted;
	}

	/**
	 * Shape IGDB search rows into the candidate list a review row renders.
	 *
	 * The same `{igdb_id, name, year}` shape the cascade stores on an
	 * unresolved row, so one template part renders both (AC-041c, AC-042c).
	 *
	 * @param array $records Raw IGDB game records.
	 * @return array[] Candidate rows.
	 */
	private static function candidate_rows( array $records ) {
		$candidates = array();

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$igdb_id = ( isset( $record['id'] ) && is_scalar( $record['id'] ) ) ? (int) $record['id'] : 0;
			$name    = ( isset( $record['name'] ) && is_scalar( $record['name'] ) )
				? sanitize_text_field( (string) $record['name'] )
				: '';

			if ( $igdb_id < 1 || '' === $name ) {
				continue;
			}

			$released = ( isset( $record['first_release_date'] ) && is_scalar( $record['first_release_date'] ) )
				? (int) $record['first_release_date']
				: 0;

			$candidates[] = array(
				'igdb_id' => $igdb_id,
				'name'    => $name,
				'year'    => ( $released > 0 ) ? gmdate( 'Y', $released ) : '',
			);
		}

		return $candidates;
	}

	/**
	 * Turn an IGDB failure into the refusal the search box renders.
	 *
	 * The copy is {@see GameLib_REST_Library::state_message()}'s, so a manual
	 * search that cannot reach IGDB reads exactly like the library search that
	 * cannot (AC-010c: an unconfigured integration is indistinguishable from an
	 * unavailable one to a member).
	 *
	 * @param WP_Error $error Failure from the IGDB client.
	 * @return WP_Error Typed error carrying `status` and `state`.
	 */
	private static function search_error( WP_Error $error ) {
		$code    = (string) $error->get_error_code();
		$status  = 503;
		$message = GameLib_REST_Library::state_message( GameLib_REST_Library::STATE_SEARCH_UNAVAILABLE );

		if ( GameLib_IGDB_Client::ERROR_RATE_LIMITED === $code ) {
			$status  = 429;
			$message = GameLib_REST_Library::state_message( GameLib_REST_Library::STATE_SEARCH_BUSY );
		}

		return new WP_Error(
			$code,
			$message,
			array(
				'status' => $status,
				'state'  => $code,
			)
		);
	}

	/**
	 * The `id` path parameter.
	 *
	 * @return array<string, mixed> `args` schema fragment.
	 */
	private static function id_schema() {
		return array(
			'type'              => 'integer',
			'required'          => true,
			'minimum'           => 1,
			'description'       => __( 'Import job id.', 'game-library' ),
			'sanitize_callback' => 'absint',
		);
	}

	/**
	 * The `item_id` path parameter.
	 *
	 * @return array<string, mixed> `args` schema fragment.
	 */
	private static function item_id_schema() {
		return array(
			'type'              => 'integer',
			'required'          => true,
			'minimum'           => 1,
			'description'       => __( 'Import row id.', 'game-library' ),
			'sanitize_callback' => 'absint',
		);
	}

	/**
	 * The optional `full` body parameter (PB-2).
	 *
	 * Never `required`: a schema is validated before the permission callback
	 * runs, so a field a logged-out caller may omit has to stay optional or the
	 * 401 becomes a 400 (AC-NFR-001 m).
	 *
	 * @return array<string, mixed> `args` schema fragment.
	 */
	private static function full_schema() {
		return array(
			'type'        => 'boolean',
			'default'     => false,
			'description' => __( 'Return the whole re-rendered job rather than the moved row.', 'game-library' ),
		);
	}
}
