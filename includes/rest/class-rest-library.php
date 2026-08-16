<?php
/**
 * REST routes for search, the member's library, and bulk actions.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The library half of the `gamelib/v1` REST surface.
 *
 * Six routes, all of them owner-scoped:
 *
 * - `POST   /search`               — IGDB search (AC-008–AC-010).
 * - `GET    /library`              — one filtered/sorted page of the caller's
 *                                    own library (AC-017, AC-018).
 * - `POST   /library`              — add a game with a chosen status (AC-011).
 * - `POST   /library/{igdb_id}`    — move one entry to another status (AC-015).
 * - `DELETE /library/{igdb_id}`    — remove one entry (AC-016).
 * - `POST   /library/bulk`         — bulk status change / removal (AC-019).
 *
 * Three rules shape every one of them:
 *
 * 1. **Authorization first.** No `permission_callback` on this class is
 *    `__return_true`: each checks `gamelib_manage_library`, and the bulk route
 *    additionally verifies the dedicated `gamelib_bulk` nonce *inside* its
 *    permission callback, on top of the cookie-auth `wp_rest` nonce WordPress
 *    has already validated by then (AC-019d, AC-NFR-001). A logged-out caller
 *    gets 401, a logged-in caller without the capability gets 403.
 * 2. **The owner is the session, never a parameter.** Every handler reads
 *    `get_current_user_id()`; no route accepts a user id, so "another member's
 *    row" is not an expressible request — an id the caller does not own simply
 *    does not exist for them, and the domain answers 404 (AC-NFR-001).
 * 3. **One rendering path.** List-refreshing routes return `{ data, html, … }`
 *    where the HTML fragment is produced by the very template parts the first
 *    paint uses ({@see GameLib_Router::render_part()}), so markup and escaping
 *    exist once, in PHP (ADR-002, AC-NFR-002). The JSON fields beside it are
 *    transport, not markup, and are deliberately *not* HTML-escaped
 *    (Never Do #14).
 *
 * Failures are typed. Every `WP_Error` this class returns carries an HTTP
 * status and a `state` term in its data: the four IGDB taxonomy terms
 * (`rate_limited`, `unavailable`, `unconfigured`, `malformed`) map to 429/503
 * with the member-facing copy AC-010 prescribes, and the library domain's own
 * codes pass through with the status they already declare. Error responses for
 * the search route also carry a rendered `html` fragment, so a degraded state
 * is swapped in exactly like a successful one.
 */
final class GameLib_REST_Library {

	/**
	 * REST namespace shared by every plugin route (§6 Integration Points).
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'gamelib/v1';

	/**
	 * Nonce action the bulk route requires in addition to the REST cookie
	 * nonce (AC-019d).
	 *
	 * @var string
	 */
	const BULK_NONCE_ACTION = 'gamelib_bulk';

	/**
	 * Body parameter carrying the {@see BULK_NONCE_ACTION} nonce.
	 *
	 * @var string
	 */
	const BULK_NONCE_PARAM = 'nonce';

	/**
	 * Bulk action: move every selected entry to one status.
	 *
	 * @var string
	 */
	const BULK_STATUS = 'status';

	/**
	 * Bulk action: delete every selected entry.
	 *
	 * @var string
	 */
	const BULK_REMOVE = 'remove';

	/**
	 * The two bulk actions, the whitelist the route validates against.
	 *
	 * @var string[]
	 */
	const BULK_ACTIONS = array( self::BULK_STATUS, self::BULK_REMOVE );

	/**
	 * IGDB image size for a search-result thumbnail (AC-008b). Portrait, so the
	 * card's fixed-aspect media box is filled rather than letterboxed.
	 *
	 * @var string
	 */
	const SEARCH_COVER_SIZE = 'cover_small';

	/**
	 * Longest search query this route forwards. Longer input is truncated
	 * rather than refused — a member who pastes a paragraph gets a search, not
	 * an error.
	 *
	 * @var int
	 */
	const MAX_QUERY_LENGTH = 100;

	/**
	 * State: the search succeeded and matched nothing (AC-009). Not an error.
	 *
	 * @var string
	 */
	const STATE_SEARCH_EMPTY = 'search_empty';

	/**
	 * State: IGDB is rate limiting this site (AC-010a).
	 *
	 * @var string
	 */
	const STATE_SEARCH_BUSY = 'search_busy';

	/**
	 * State: IGDB is unreachable, misconfigured, or refused the query — one
	 * member-facing message for all three (AC-010 b,c).
	 *
	 * @var string
	 */
	const STATE_SEARCH_UNAVAILABLE = 'search_unavailable';

	/**
	 * State: the member has added nothing yet (AC-020).
	 *
	 * @var string
	 */
	const STATE_LIBRARY_EMPTY = 'library_empty';

	/**
	 * State: the applied filter matched nothing (AC-017), which is a different
	 * thing from an empty library.
	 *
	 * @var string
	 */
	const STATE_LIBRARY_NO_MATCH = 'library_no_match';

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
			'/search',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'search' ),
					'permission_callback' => array( __CLASS__, 'check_manage_library' ),
					'args'                => array(
						'q' => array(
							'type'              => 'string',
							'required'          => true,
							'description'       => __( 'What to search IGDB for.', 'game-library' ),
							'sanitize_callback' => array( __CLASS__, 'sanitize_query' ),
							'validate_callback' => array( __CLASS__, 'validate_query' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/library',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_library' ),
					'permission_callback' => array( __CLASS__, 'check_manage_library' ),
					'args'                => self::view_schema(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'add' ),
					'permission_callback' => array( __CLASS__, 'check_manage_library' ),
					'args'                => array(
						'igdb_id' => self::igdb_id_schema(),
						'status'  => array(
							'type'              => 'string',
							'required'          => true,
							'enum'              => GameLib_Library::STATUSES,
							'description'       => __( 'Library status to add the game with.', 'game-library' ),
							'sanitize_callback' => array( 'GameLib_Library', 'sanitize_status' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/library/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'bulk' ),
					// Capability *and* the dedicated bulk nonce (AC-019d).
					'permission_callback' => array( __CLASS__, 'check_bulk' ),
					'args'                => self::bulk_schema(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/library/(?P<igdb_id>[0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'set_status' ),
					'permission_callback' => array( __CLASS__, 'check_manage_library' ),
					'args'                => array(
						'igdb_id' => self::igdb_id_schema(),
						'status'  => array(
							'type'              => 'string',
							'required'          => true,
							'enum'              => GameLib_Library::STATUSES,
							'description'       => __( 'Library status to move the entry to.', 'game-library' ),
							'sanitize_callback' => array( 'GameLib_Library', 'sanitize_status' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'remove' ),
					'permission_callback' => array( __CLASS__, 'check_manage_library' ),
					'args'                => array(
						'igdb_id' => self::igdb_id_schema(),
					),
				),
			)
		);
	}

	/**
	 * Gate for every route in this class: a member, with the library
	 * capability (Always Do #3).
	 *
	 * A logged-out caller is 401 — "you have not identified yourself" — and a
	 * logged-in caller whose role carries no library capability is 403, which
	 * is the distinction AC-NFR-001's matrix asserts. Both come from
	 * {@see GameLib_Capabilities::rest_gate()}, the single definition of that
	 * contract; this callback supplies only the capability and its two
	 * sentences. A cookie-authenticated request that omits or fails the
	 * `wp_rest` nonce never reaches this callback: core rejects it at
	 * authentication with its own 403.
	 *
	 * @return true|WP_Error True when the caller may manage their library.
	 */
	public static function check_manage_library() {
		return GameLib_Capabilities::rest_gate(
			GameLib_Capabilities::CAP_MANAGE_LIBRARY,
			__( 'Sign in to manage your library.', 'game-library' ),
			__( 'Your account cannot manage a game library.', 'game-library' )
		);
	}

	/**
	 * The bulk route's gate: {@see check_manage_library()} plus the dedicated
	 * `gamelib_bulk` nonce (AC-019d).
	 *
	 * The second nonce is deliberately checked here rather than in the handler,
	 * so a bulk request that cannot prove intent is refused before a single row
	 * is read. It is read raw — permission callbacks run ahead of the args
	 * schema — and `wp_verify_nonce()` hashes whatever it is handed, so a
	 * non-scalar value simply fails.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error True when the caller may run a bulk action.
	 */
	public static function check_bulk( $request ) {
		$allowed = self::check_manage_library();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$nonce = $request->get_param( self::BULK_NONCE_PARAM );
		$nonce = is_scalar( $nonce ) ? (string) $nonce : '';

		if ( ! wp_verify_nonce( $nonce, self::BULK_NONCE_ACTION ) ) {
			return new WP_Error(
				'gamelib_rest_bulk_nonce',
				__( 'That bulk action could not be verified. Reload the page and try again.', 'game-library' ),
				array(
					'status' => 403,
					'state'  => 'invalid_nonce',
				)
			);
		}

		return true;
	}

	/**
	 * `POST /search` — search IGDB for games to add (AC-008–AC-010).
	 *
	 * The query itself is normalized, hashed, cached, and sent by
	 * {@see GameLib_IGDB_Client::search()}; this route's job is authorization,
	 * shaping the rows a card needs, and rendering them. Nothing about the
	 * query is logged, here or anywhere below (Never Do #13).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Results plus their rendered fragment.
	 */
	public static function search( $request ) {
		$query   = self::string_param( $request, 'q' );
		$results = GameLib_IGDB_Client::search( $query );

		if ( is_wp_error( $results ) ) {
			return self::error_response( $results, true );
		}

		$rows  = self::search_rows( $results, get_current_user_id() );
		$empty = empty( $rows );

		return rest_ensure_response(
			array(
				'query'   => $query,
				// AC-009: an empty result is a state, not an error — and it is
				// the IGDB taxonomy's own term for it.
				'state'   => GameLib_IGDB_Client::classify( $rows ),
				'total'   => count( $rows ),
				'results' => $rows,
				'html'    => $empty
					? self::render_state( self::STATE_SEARCH_EMPTY, 'p', false, $query )
					: self::render_results( $rows ),
			)
		);
	}

	/**
	 * `GET /library` — one page of the caller's own library (AC-017, AC-018).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response The page, its fragment, and its controls' state.
	 */
	public static function get_library( $request ) {
		return rest_ensure_response(
			self::list_payload( get_current_user_id(), self::view_args( $request ) )
		);
	}

	/**
	 * `POST /library` — add a game with a chosen status (AC-011).
	 *
	 * A duplicate is a success, not an error: the domain preserves the existing
	 * row untouched and reports it, and this route turns that into a 200 with
	 * the notice AC-011(e) asks for, against 201 for a row that was created.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error The resulting entry and its card.
	 */
	public static function add( $request ) {
		$user_id = get_current_user_id();
		$igdb_id = absint( $request->get_param( 'igdb_id' ) );
		$status  = self::string_param( $request, 'status' );

		$result = GameLib_Library::add( $user_id, $igdb_id, $status );

		if ( is_wp_error( $result ) ) {
			return self::error_response( $result, false );
		}

		$card      = self::card_args( $igdb_id, $result['status'], $result['added_at'] );
		$duplicate = (bool) $result['duplicate'];

		$response = rest_ensure_response(
			array(
				'igdb_id'   => (int) $result['igdb_id'],
				'status'    => (string) $result['status'],
				'added'     => (bool) $result['added'],
				'duplicate' => $duplicate,
				'added_at'  => (string) $result['added_at'],
				'state'     => $duplicate ? 'duplicate' : 'added',
				'message'   => $duplicate
					? sprintf(
						/* translators: %s: game name. */
						__( '“%s” is already in your library.', 'game-library' ),
						$card['name']
					)
					: sprintf(
						/* translators: %s: game name. */
						__( '“%s” was added to your library.', 'game-library' ),
						$card['name']
					),
				'entry'     => self::entry_payload( $card ),
				'html'      => GameLib_Router::render_part( 'game-card', $card ),
			)
		);

		$response->set_status( $duplicate ? 200 : 201 );

		return $response;
	}

	/**
	 * `POST /library/{igdb_id}` — move one entry to another status (AC-015).
	 *
	 * An entry the caller does not have — including one that belongs to another
	 * member — is a 404 from the domain, which is what makes ownership
	 * unforgeable here (AC-NFR-001).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error The transition and the re-rendered card.
	 */
	public static function set_status( $request ) {
		$user_id = get_current_user_id();
		$igdb_id = absint( $request->get_param( 'igdb_id' ) );
		$status  = self::string_param( $request, 'status' );

		$result = GameLib_Library::set_status( $user_id, $igdb_id, $status );

		if ( is_wp_error( $result ) ) {
			return self::error_response( $result, false );
		}

		$entry = GameLib_Library::entry( $user_id, $igdb_id );
		$card  = self::card_args(
			$igdb_id,
			(string) $result['to'],
			is_array( $entry ) ? (string) $entry['added_at'] : ''
		);

		return rest_ensure_response(
			array(
				'igdb_id' => (int) $result['igdb_id'],
				'from'    => (string) $result['from'],
				'to'      => (string) $result['to'],
				'changed' => (bool) $result['changed'],
				'state'   => 'status_changed',
				'entry'   => self::entry_payload( $card ),
				'html'    => GameLib_Router::render_part( 'game-card', $card ),
			)
		);
	}

	/**
	 * `DELETE /library/{igdb_id}` — remove one entry (AC-016).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Confirmation, or 404 when the caller
	 *                                   does not have that game.
	 */
	public static function remove( $request ) {
		$user_id = get_current_user_id();
		$igdb_id = absint( $request->get_param( 'igdb_id' ) );

		$removed = GameLib_Library::remove( $user_id, $igdb_id );

		if ( is_wp_error( $removed ) ) {
			return self::error_response( $removed, false );
		}

		if ( $removed < 1 ) {
			// Nothing was deleted: the caller either never had this game or it
			// belongs to somebody else. Both are "not found" from here.
			return new WP_Error(
				GameLib_Library::ERROR_MISSING,
				__( 'That game is not in your library.', 'game-library' ),
				array(
					'status' => 404,
					'state'  => 'missing',
				)
			);
		}

		return rest_ensure_response(
			array(
				'igdb_id' => $igdb_id,
				'removed' => true,
				'state'   => 'removed',
				'message' => __( 'That game was removed from your library.', 'game-library' ),
			)
		);
	}

	/**
	 * `POST /library/bulk` — bulk status change or removal (AC-019).
	 *
	 * Selection is either the ids the member ticked (capped at 200 by the args
	 * schema and again by the domain) or everything matching the applied
	 * filter, which is evaluated server-side from the same criteria the grid
	 * was rendered with — never from a client-side list of "all" ids
	 * (AC-019f).
	 *
	 * The response is the refreshed page of the same view, so one round trip
	 * both performs the action and repaints the grid.
	 *
	 * A filter-mode selection larger than one request may finish answers with
	 * `queued: true` and the rows applied so far (PB-3); the remainder drains
	 * from cron and the member's next read shows it. The sentence names what has
	 * happened rather than what was asked for, so it is honest in both cases.
	 *
	 * When the remainder could not even be scheduled the response says
	 * `abandoned: true` and asks the member to run the action again (CO-5) — a
	 * third outcome that used to be reported as a plain success.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error The outcome plus the refreshed page.
	 */
	public static function bulk( $request ) {
		$user_id = get_current_user_id();
		$action  = self::string_param( $request, 'bulk_action' );
		$view    = self::view_args( $request );

		$selection = array(
			'ids'    => self::int_list( $request->get_param( 'ids' ) ),
			'all'    => (bool) $request->get_param( 'all' ),
			'status' => $view['status'],
			'search' => $view['search'],
		);

		if ( self::BULK_REMOVE === $action ) {
			$result = GameLib_Library::bulk_remove( $user_id, $selection );
		} else {
			$target = GameLib_Library::sanitize_status( $request->get_param( 'target_status' ) );

			if ( '' === $target ) {
				return new WP_Error(
					GameLib_Library::ERROR_STATUS,
					__( 'Choose Playing, Finished, Backlog, or Wishlist.', 'game-library' ),
					array(
						'status' => 400,
						'state'  => 'invalid_status',
					)
				);
			}

			$result = GameLib_Library::bulk_set_status( $user_id, $target, $selection );
		}

		if ( is_wp_error( $result ) ) {
			return self::error_response( $result, false );
		}

		$removed = isset( $result['removed'] );
		$count   = $removed ? (int) $result['removed'] : (int) $result['updated'];

		$payload                = self::list_payload( $user_id, $view );
		$payload['bulk_action'] = $removed ? self::BULK_REMOVE : self::BULK_STATUS;
		$payload['count']       = $count;
		// True when the selection was larger than one request may apply and the
		// rest is draining from cron (PB-3).
		$payload['queued']      = ! empty( $result['queued'] );
		// True when the remainder could not be handed to cron at all (CO-5).
		$payload['abandoned']   = ! empty( $result['abandoned'] );
		$payload['state']       = $removed ? 'bulk_removed' : 'bulk_status_changed';
		$payload['message']     = $removed
			? sprintf(
				/* translators: %s: number of games removed. */
				_n( '%s game removed.', '%s games removed.', $count, 'game-library' ),
				number_format_i18n( $count )
			)
			: sprintf(
				/* translators: 1: number of games moved, 2: status they were moved to. */
				_n( '%1$s game moved to %2$s.', '%1$s games moved to %2$s.', $count, 'game-library' ),
				number_format_i18n( $count ),
				self::status_label( (string) $result['status'] )
			);

		/*
		 * The grid shipped with this response was rendered after the first pass
		 * only, so on a selection above BULK_SYNC_MAX the count sentence alone —
		 * "2,000 games moved to Playing." — reads as complete while the member is
		 * looking at rows that have not moved, and the natural response is to run
		 * the action again and start a second chained job. The continuation is
		 * appended here, in PHP, beside every other string this plugin authors
		 * (CO-4): nothing on the client composes a sentence, and `message` is
		 * already what the bundle announces.
		 */
		if ( $payload['queued'] ) {
			$payload['message'] .= ' ' . __( 'The rest is still being applied — reload in a moment to see it.', 'game-library' );
		}

		/*
		 * Rows were left matching the filter and nothing will come back for them
		 * (CO-5), so the member is told to run the action again rather than being
		 * handed a completed-sounding sentence beside a grid that still shows
		 * unmoved rows. The state changes with it, because "some of it worked"
		 * is a third outcome, not a variant of success.
		 */
		if ( $payload['abandoned'] ) {
			$payload['state']    = $removed ? 'bulk_removed_incomplete' : 'bulk_status_changed_incomplete';
			$payload['message'] .= ' ' . __( 'The rest could not be applied — run the action again to finish it.', 'game-library' );
		}

		if ( ! $removed ) {
			$payload['status_changed_to'] = (string) $result['status'];
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * The shared copy for the four list states a caller may have to render.
	 *
	 * Published as a static catalog so the REST fragments and the first paint
	 * of `/my-library/` say the same thing without either side re-authoring the
	 * strings: {@see render_state()} is this class's consumer, and a template
	 * rendering an empty grid server-side can call it directly.
	 *
	 * @param string $state One of the `STATE_*` constants.
	 * @param string $query Search query, for the states that name it.
	 * @return string Translated message, or '' for an unknown state.
	 */
	public static function state_message( $state, $query = '' ) {
		switch ( $state ) {
			case self::STATE_SEARCH_EMPTY:
				return sprintf(
					/* translators: %s: the search query a member typed. */
					__( 'No games found for “%s”.', 'game-library' ),
					$query
				);

			case self::STATE_SEARCH_BUSY:
				return __( 'Search is busy — try again in a moment.', 'game-library' );

			case self::STATE_SEARCH_UNAVAILABLE:
				return __( 'Search is temporarily unavailable.', 'game-library' );

			case self::STATE_LIBRARY_EMPTY:
				return __( 'Your library is empty. Use the search box above to add your first game.', 'game-library' );

			case self::STATE_LIBRARY_NO_MATCH:
				return __( 'No games match.', 'game-library' );
		}

		return '';
	}

	/**
	 * Trim a search query to something worth sending upstream.
	 *
	 * @param mixed $value Raw parameter.
	 * @return string Sanitized query.
	 */
	public static function sanitize_query( $value ) {
		$query = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $query, 0, self::MAX_QUERY_LENGTH );
		}

		return substr( $query, 0, self::MAX_QUERY_LENGTH );
	}

	/**
	 * Refuse a search that asks for nothing.
	 *
	 * @param mixed $value Raw parameter (validation runs before sanitization).
	 * @return true|WP_Error True when the query holds something searchable.
	 */
	public static function validate_query( $value ) {
		if ( '' === self::sanitize_query( $value ) ) {
			return new WP_Error(
				'gamelib_rest_empty_query',
				__( 'Type a game title to search for.', 'game-library' ),
				array(
					'status' => 400,
					'state'  => 'empty_query',
				)
			);
		}

		return true;
	}

	/**
	 * Clamp a requested page size to the grid's ceiling (AC-018a).
	 *
	 * A caller asking for more than {@see GameLib_Library::MAX_PAGE_SIZE} gets
	 * the ceiling rather than an error — it is a cap, not a contract breach —
	 * and the domain clamps again on its own account. See
	 * principal/adr/012-per-page-cap-clamps.md: the schema deliberately carries
	 * no `maximum`, unlike the explicit-id bulk cap, which *is* a refusal
	 * because truncating a selection would lose member intent.
	 *
	 * @param mixed $value Raw parameter.
	 * @return int Rows per page.
	 */
	public static function sanitize_per_page( $value ) {
		$per_page = absint( $value );

		if ( $per_page < 1 ) {
			return GameLib_Library::PAGE_SIZE;
		}

		return min( GameLib_Library::MAX_PAGE_SIZE, $per_page );
	}

	/**
	 * The filter/sort/pagination parameters every list-returning route accepts.
	 *
	 * @return array<string, array<string, mixed>> `args` schema fragment.
	 */
	private static function view_schema() {
		return array(
			'status'   => array(
				'type'              => 'string',
				'default'           => GameLib_Library::STATUS_ALL,
				'enum'              => array_merge( array( GameLib_Library::STATUS_ALL ), GameLib_Library::STATUSES ),
				'description'       => __( 'Status filter, or “all”.', 'game-library' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'search'   => array(
				'type'              => 'string',
				'default'           => '',
				'description'       => __( 'Title search within the library.', 'game-library' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'sort'     => array(
				'type'              => 'string',
				'default'           => GameLib_Library::SORT_DEFAULT,
				'enum'              => GameLib_Library::SORTS,
				'description'       => __( 'Sort order.', 'game-library' ),
				'sanitize_callback' => array( 'GameLib_Library', 'sanitize_sort' ),
			),
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'description'       => __( 'Page of results, 1-based.', 'game-library' ),
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => GameLib_Library::PAGE_SIZE,
				'minimum'           => 1,
				'description'       => __( 'Entries per page; capped server-side.', 'game-library' ),
				'sanitize_callback' => array( __CLASS__, 'sanitize_per_page' ),
			),
		);
	}

	/**
	 * The bulk route's parameters: the action, its selection, and the view to
	 * repaint afterwards.
	 *
	 * @return array<string, array<string, mixed>> `args` schema.
	 */
	private static function bulk_schema() {
		$schema = self::view_schema();

		$schema['bulk_action'] = array(
			'type'              => 'string',
			'required'          => true,
			'enum'              => self::BULK_ACTIONS,
			'description'       => __( 'Bulk action to perform.', 'game-library' ),
			'sanitize_callback' => 'sanitize_key',
		);

		$schema['target_status'] = array(
			'type'              => 'string',
			'enum'              => GameLib_Library::STATUSES,
			'description'       => __( 'Status a bulk status change moves entries to.', 'game-library' ),
			'sanitize_callback' => array( 'GameLib_Library', 'sanitize_status' ),
		);

		$schema['ids'] = array(
			'type'        => 'array',
			'default'     => array(),
			// AC-019f: an explicit-id request names at most 200 games; more than
			// that is what "select all matching this filter" is for.
			'maxItems'    => GameLib_Library::MAX_BULK_IDS,
			'items'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'description' => __( 'IGDB ids to act on.', 'game-library' ),
		);

		$schema['all'] = array(
			'type'        => 'boolean',
			'default'     => false,
			'description' => __( 'Act on every entry matching the applied filter.', 'game-library' ),
		);

		/*
		 * Deliberately not `required`: a missing bulk nonce and a wrong one are
		 * the same refusal, and the args schema would turn the first into a 400
		 * before {@see check_bulk()} could answer 403 (AC-019d).
		 */
		$schema[ self::BULK_NONCE_PARAM ] = array(
			'type'        => 'string',
			'default'     => '',
			'description' => __( 'Dedicated gamelib_bulk nonce.', 'game-library' ),
		);

		return $schema;
	}

	/**
	 * The `igdb_id` parameter, shared by the routes that name a game.
	 *
	 * @return array<string, mixed> `args` schema fragment.
	 */
	private static function igdb_id_schema() {
		return array(
			'type'              => 'integer',
			'required'          => true,
			'minimum'           => 1,
			'description'       => __( 'IGDB game id.', 'game-library' ),
			'sanitize_callback' => 'absint',
		);
	}

	/**
	 * Read the filter/sort/pagination criteria off a request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array{status:string,search:string,sort:string,page:int,per_page:int} View criteria.
	 */
	private static function view_args( $request ) {
		return array(
			// '' is how the domain spells "no status filter"; `all` normalizes
			// to exactly that inside GameLib_Library::criteria().
			'status'   => GameLib_Library::sanitize_status( $request->get_param( 'status' ) ),
			'search'   => self::string_param( $request, 'search' ),
			'sort'     => GameLib_Library::sanitize_sort( $request->get_param( 'sort' ) ),
			'page'     => max( 1, absint( $request->get_param( 'page' ) ) ),
			'per_page' => self::sanitize_per_page( $request->get_param( 'per_page' ) ),
		);
	}

	/**
	 * One page of the library as JSON plus its rendered grid fragment.
	 *
	 * A page beyond the end — the state a bulk removal leaves the grid in — is
	 * re-read at the last page that exists, so the response never hands back an
	 * empty grid for a library that has entries.
	 *
	 * **`data` has no consumer in this release, and that is deliberate** (PF-6).
	 * `src/js/my-library.js` reads `html`, `pages`, `page`, `total` and `counts`
	 * and never touches `data`, so a list refresh carries ~6KB of structured
	 * entries (~1.5KB gzipped) that nothing parses — on every keystroke past the
	 * 300ms filter debounce. The dual shape is DD-005's envelope contract: the
	 * plugin's own bundle renders from the fragment, and the JSON is what any
	 * other client reads. Gating `data` behind an `include_data` parameter would
	 * narrow a spec-level contract to suit one consumer, which is an
	 * architecture decision this cycle has no ruling on. Recorded here so the
	 * next reader knows the redundancy is known and intended.
	 *
	 * @param int   $user_id Library owner (always the caller).
	 * @param array $args    View criteria from {@see view_args()}.
	 * @return array<string, mixed> Response payload.
	 */
	private static function list_payload( $user_id, array $args ) {
		$result = GameLib_Library::query( $user_id, $args );

		if ( $result['pages'] > 0 && $result['page'] > $result['pages'] ) {
			$args['page'] = $result['pages'];
			$result       = GameLib_Library::query( $user_id, $args );
		}

		$rows = array();

		foreach ( $result['rows'] as $row ) {
			$rows[] = self::entry_payload( $row );
		}

		return array(
			'data'     => $rows,
			'html'     => self::render_entries( $result ),
			'total'    => (int) $result['total'],
			'pages'    => (int) $result['pages'],
			'page'     => (int) $result['page'],
			'per_page' => (int) $result['per_page'],
			'status'   => (string) $result['status'],
			'search'   => (string) $result['search'],
			'sort'     => (string) $result['sort'],
			'filtered' => (bool) $result['filtered'],
			'counts'   => GameLib_Library::count_by_status( $user_id ),
		);
	}

	/**
	 * Render a page of entries as grid items, or the state that explains why
	 * there are none.
	 *
	 * Both the cards and the empty state are `<li>` elements, because the grid
	 * they are swapped into is a `<ul>`.
	 *
	 * @param array $result Return value of {@see GameLib_Library::query()}.
	 * @return string Markup.
	 */
	private static function render_entries( array $result ) {
		if ( empty( $result['rows'] ) ) {
			// AC-017 vs AC-020: "nothing matched this filter" is a different
			// message from "you have not added anything yet".
			$state = $result['filtered'] ? self::STATE_LIBRARY_NO_MATCH : self::STATE_LIBRARY_EMPTY;

			return self::render_state( $state, 'li', false );
		}

		$html = '';

		foreach ( $result['rows'] as $row ) {
			if ( is_array( $row ) ) {
				/*
				 * Every route in this class is owner-scoped, so a card it
				 * renders is always the caller's own and always carries the
				 * AC-014(e,f) controls. `templates/parts/game-card.php` renders
				 * them opt-in, which is what keeps another member's read-only
				 * grid (AC-031c, GameLib_REST_Social) free of them.
				 */
				$row['controls'] = true;

				$html .= GameLib_Router::render_part( 'game-card', $row );
			}
		}

		return $html;
	}

	/**
	 * Render a list of search results.
	 *
	 * @param array[] $rows Prepared result rows.
	 * @return string Markup.
	 */
	private static function render_results( array $rows ) {
		$html = '<ul class="gamelib-results">';

		foreach ( $rows as $row ) {
			$html .= GameLib_Router::render_part( 'search-result', $row );
		}

		return $html . '</ul>';
	}

	/**
	 * Render one list-state message through the shared part.
	 *
	 * @param string $state One of the `STATE_*` constants.
	 * @param string $tag   `li` inside the grid, `p` anywhere else.
	 * @param bool   $error Whether the state is a failure (AC-010) rather than
	 *                      an ordinary empty result (AC-009).
	 * @param string $query Search query, for the states that name it.
	 * @return string Markup.
	 */
	private static function render_state( $state, $tag, $error, $query = '' ) {
		return GameLib_Router::render_part(
			'list-state',
			array(
				'state'   => $state,
				'tag'     => $tag,
				'tone'    => $error ? 'error' : 'neutral',
				'message' => self::state_message( $state, $query ),
			)
		);
	}

	/**
	 * Shape IGDB search rows into what a result card renders (AC-008b).
	 *
	 * IGDB omits null fields rather than nulling them, so every field but the
	 * id and the name is optional; an unusable row is dropped rather than
	 * rendered half-empty. The statuses the member already holds are looked up
	 * once for the whole page, so a result they have added shows it without a
	 * query per card.
	 *
	 * @param array $records Raw IGDB game records.
	 * @param int   $user_id Caller, for the already-in-library flag.
	 * @return array[] Result rows.
	 */
	private static function search_rows( array $records, $user_id ) {
		$rows = array();
		$ids  = array();

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$igdb_id = isset( $record['id'] ) && is_scalar( $record['id'] ) ? (int) $record['id'] : 0;
			$name    = isset( $record['name'] ) && is_scalar( $record['name'] )
				? sanitize_text_field( (string) $record['name'] )
				: '';

			if ( $igdb_id < 1 || '' === $name ) {
				continue;
			}

			$image_id = ( isset( $record['cover']['image_id'] ) && is_scalar( $record['cover']['image_id'] ) )
				? (string) $record['cover']['image_id']
				: '';

			$released = isset( $record['first_release_date'] ) && is_scalar( $record['first_release_date'] )
				? (int) $record['first_release_date']
				: 0;

			$ids[]  = $igdb_id;
			$rows[] = array(
				'igdb_id'   => $igdb_id,
				'name'      => $name,
				'cover_url' => GameLib_Game_Store::cover_url( $image_id, self::SEARCH_COVER_SIZE ),
				'year'      => ( 0 !== $released ) ? gmdate( 'Y', $released ) : '',
				'platforms' => self::names( isset( $record['platforms'] ) ? $record['platforms'] : null ),
				'status'    => '',
			);
		}

		$statuses = GameLib_Library::statuses_for( $user_id, $ids );

		if ( empty( $statuses ) ) {
			return $rows;
		}

		foreach ( $rows as $index => $row ) {
			if ( isset( $statuses[ $row['igdb_id'] ] ) ) {
				$rows[ $index ]['status'] = (string) $statuses[ $row['igdb_id'] ];
			}
		}

		return $rows;
	}

	/**
	 * Pull the `name` out of an IGDB expanded-field list (platforms, genres).
	 *
	 * @param mixed $values Raw field value.
	 * @return string[] Sanitized names.
	 */
	private static function names( $values ) {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$names = array();

		foreach ( $values as $value ) {
			$name = '';

			if ( is_array( $value ) && isset( $value['name'] ) && is_scalar( $value['name'] ) ) {
				$name = sanitize_text_field( (string) $value['name'] );
			} elseif ( is_scalar( $value ) ) {
				$name = sanitize_text_field( (string) $value );
			}

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Everything `templates/parts/game-card.php` needs for one entry.
	 *
	 * Read after the write, not from it: an add fires the first-add hook, which
	 * may have created the game's page a moment ago, and the permalink of that
	 * page is part of the card (AC-014b).
	 *
	 * @param int    $igdb_id  IGDB game id.
	 * @param string $status   Entry status.
	 * @param string $added_at UTC `Y-m-d H:i:s` the entry was added.
	 * @return array<string, mixed> Card arguments.
	 */
	private static function card_args( $igdb_id, $status, $added_at ) {
		$game      = GameLib_Game_Store::get( $igdb_id );
		$post_id   = GameLib_Game_Store::live_post_id( $igdb_id );
		$permalink = ( $post_id > 0 ) ? get_permalink( $post_id ) : false;

		return array(
			'igdb_id'      => (int) $igdb_id,
			'name'         => is_array( $game ) ? (string) $game['name'] : '',
			// Same grid, same renditions as the first paint (PF-1, CO-1).
			'cover_url'    => is_array( $game ) ? GameLib_Game_Store::cover_url( $game['cover_image_id'], GameLib_Game_Store::CARD_COVER_SIZE ) : '',
			'cover_srcset' => is_array( $game ) ? GameLib_Game_Store::card_cover_srcset( $game['cover_image_id'] ) : '',
			'permalink'    => is_string( $permalink ) ? $permalink : '',
			'status'       => (string) $status,
			'added_at'     => (string) $added_at,
			// The caller owns this entry — see render_entries().
			'controls'     => true,
		);
	}

	/**
	 * The JSON shape of one library entry.
	 *
	 * Values travel raw: they are transport, and the markup that renders them
	 * escapes them where it renders them (Never Do #14, AC-NFR-002).
	 *
	 * @param array $row Card arguments or a row from
	 *                   {@see GameLib_Library::query()}.
	 * @return array<string, mixed> Entry payload.
	 */
	private static function entry_payload( array $row ) {
		return array(
			'igdb_id'   => isset( $row['igdb_id'] ) ? (int) $row['igdb_id'] : 0,
			'name'      => isset( $row['name'] ) ? (string) $row['name'] : '',
			'status'    => isset( $row['status'] ) ? (string) $row['status'] : '',
			'added_at'  => isset( $row['added_at'] ) ? (string) $row['added_at'] : '',
			'cover_url' => isset( $row['cover_url'] ) ? (string) $row['cover_url'] : '',
			'permalink' => isset( $row['permalink'] ) ? (string) $row['permalink'] : '',
		);
	}

	/**
	 * Turn a domain or IGDB failure into a typed REST error.
	 *
	 * The four IGDB taxonomy terms carry no HTTP status of their own, so they
	 * are mapped here — 429 for rate limiting, 503 for everything else that
	 * means "IGDB did not answer usefully" — and given the member-facing copy
	 * AC-010 prescribes. Library-domain errors already declare their status and
	 * their message is already member-facing, so they pass through.
	 *
	 * @param WP_Error $error   Failure from the domain or the IGDB client.
	 * @param bool     $search  Whether this failure happened on the search
	 *                          route, which has its own copy and renders a
	 *                          fragment for it.
	 * @return WP_Error Typed error carrying `status`, `state`, and — on the
	 *                  search route — a rendered `html` fragment.
	 */
	private static function error_response( WP_Error $error, $search ) {
		$code = (string) $error->get_error_code();
		$data = $error->get_error_data();

		$status  = ( is_array( $data ) && isset( $data['status'] ) ) ? absint( $data['status'] ) : 0;
		$message = (string) $error->get_error_message();
		$state   = $code;

		switch ( $code ) {
			case GameLib_IGDB_Client::ERROR_RATE_LIMITED:
				$status  = 429;
				$message = $search
					? self::state_message( self::STATE_SEARCH_BUSY )
					: __( 'Game data is busy — try again in a moment.', 'game-library' );
				break;

			case GameLib_IGDB_Client::ERROR_UNAVAILABLE:
			case GameLib_IGDB_Client::ERROR_UNCONFIGURED:
			case GameLib_IGDB_Client::ERROR_MALFORMED:
				// AC-010(c): an unconfigured integration reads to a member
				// exactly like an unavailable one. The settings screen is where
				// the difference is visible.
				$status  = 503;
				$message = $search
					? self::state_message( self::STATE_SEARCH_UNAVAILABLE )
					: __( 'Game data is temporarily unavailable.', 'game-library' );
				break;

			case GameLib_IGDB_Client::RESULT_EMPTY:
				$status  = 404;
				$message = __( 'IGDB has no record of that game.', 'game-library' );
				break;
		}

		if ( $status < 400 ) {
			$status = 400;
		}

		$payload = array(
			'status' => $status,
			'state'  => $state,
		);

		if ( $search ) {
			$payload['html'] = self::render_state(
				429 === $status ? self::STATE_SEARCH_BUSY : self::STATE_SEARCH_UNAVAILABLE,
				'p',
				true
			);
		}

		/*
		 * The domain's own codes are already namespaced; the IGDB taxonomy's
		 * four bare terms are not, and a REST error code has to be unambiguous
		 * across every plugin on the site.
		 */
		$rest_code = sanitize_key( $code );

		if ( 0 !== strpos( $rest_code, 'gamelib_' ) ) {
			$rest_code = 'gamelib_' . $rest_code;
		}

		return new WP_Error( $rest_code, $message, $payload );
	}

	/**
	 * Display label for a library status.
	 *
	 * Presentation only — the whitelist itself is enforced on write by
	 * {@see GameLib_Library::sanitize_status()}.
	 *
	 * @param string $status One of {@see GameLib_Library::STATUSES}.
	 * @return string Translated label, or the raw value when unrecognized.
	 */
	private static function status_label( $status ) {
		switch ( $status ) {
			case 'playing':
				return _x( 'Playing', 'library status', 'game-library' );

			case 'finished':
				return _x( 'Finished', 'library status', 'game-library' );

			case 'backlog':
				return _x( 'Backlog', 'library status', 'game-library' );

			case 'wishlist':
				return _x( 'Wishlist', 'library status', 'game-library' );
		}

		return (string) $status;
	}

	/**
	 * One request parameter as a plain string.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $name    Parameter name.
	 * @return string Value, '' when absent or not scalar.
	 */
	private static function string_param( $request, $name ) {
		$value = $request->get_param( $name );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * A caller-supplied list of ids as positive integers.
	 *
	 * @param mixed $value Raw parameter.
	 * @return int[] Positive integers, in the order given.
	 */
	private static function int_list( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();

		foreach ( $value as $item ) {
			$id = is_scalar( $item ) ? (int) $item : 0;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}
