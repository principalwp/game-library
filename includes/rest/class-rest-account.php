<?php
/**
 * REST routes for a member's own account: visibility, Steam, and export.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The account half of the `gamelib/v1` REST surface.
 *
 * Five routes land here, and every one of them acts on the caller's own
 * account — the owner is the session, never a parameter, so "another member's
 * account" is not an expressible request (AC-NFR-001):
 *
 * - `POST   /account/visibility` — flip the opt-in public toggle (AC-027c) and
 *                                  run the AC-029 side effects in the same
 *                                  request.
 * - `POST   /invites`            — mint a single-use invite (AC-001).
 * - `GET    /invites`            — the caller's own invites and what is left of
 *                                  their allowance (AC-001, AC-002).
 * - `DELETE /account/steam`      — disconnect the stored SteamID (AC-046b).
 * - `GET    /export`             — download the caller's own library as CSV or
 *                                  JSON (AC-037).
 *
 * Invite creation is the one route here gated on something other than library
 * membership: it needs `gamelib_issue_invites`, which the AC-007(b) admin
 * toggle withdraws at the `user_has_cap` layer. Reading one's own invite list
 * stays open to any member, so a member whose issuance was switched off can
 * still see the invites they already sent.
 *
 * The export route is an authenticated account action with no public URL: it
 * answers 401 to a logged-out caller, is served as an attachment rather than a
 * page, and — like every `wp-json` response — carries core's `X-Robots-Tag:
 * noindex`, so it can appear in no sitemap and no index (AC-037, AC-048d).
 * Core's cookie authentication accepts the REST nonce as a `_wpnonce` query
 * argument when the `X-WP-Nonce` header is absent, which is what a download
 * started by navigating to an anchor has to rely on. Nothing in this plugin
 * renders such an anchor any more (SE-1, CWE-598): the account panel fetches
 * the file with the header and saves the response through an object URL, so the
 * member's `wp_rest` token — valid for every mutation route in this namespace
 * for ~12–24h — never lands in an access log, a proxy log, or a download
 * history. The query-argument path is core's and stays open; this plugin simply
 * stops putting a credential on it.
 *
 * Export *serialization* is not this class's business: the four-field row
 * shape, the CSV formula guard, the MIME types, and the filename all live in
 * {@see GameLib_Exporter}, which the import side reads back
 * (principal/adr/013-export-generation-ships-with-its-route.md). What stays
 * here is the route: authorization, headers, and the raw-body short-circuit.
 */
final class GameLib_REST_Account {

	/**
	 * REST namespace shared by every plugin route (§6 Integration Points); the
	 * same literal as {@see GameLib_REST_Library::REST_NAMESPACE}.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'gamelib/v1';

	/**
	 * Full REST route of the export download, as `WP_REST_Request::get_route()`
	 * reports it. {@see serve_export()} matches on this so the raw-body
	 * short-circuit can never touch another route's response.
	 *
	 * @var string
	 */
	const EXPORT_ROUTE = '/' . self::REST_NAMESPACE . '/export';

	/**
	 * The export the current request is serving: `format` and `user_id`.
	 *
	 * `rest_pre_serve_request` hands its callback the response and the request
	 * and nothing else, and the response no longer carries the document (VIP-6),
	 * so the two values the stream needs travel here. Written by {@see export()}
	 * and cleared by {@see serve_export()} on the same request, which is also the
	 * only thing that ever reads it.
	 *
	 * @var array{format:string,user_id:int}|null
	 */
	private static $export_job = null;

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
			'/account/visibility',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'set_visibility' ),
					'permission_callback' => array( __CLASS__, 'check_member' ),
					'args'                => array(
						/*
						 * Deliberately neither `required` nor
						 * validate-callback-enforced: WordPress checks
						 * required-ness *before* `permission_callback` runs, so
						 * a required parameter would answer a logged-out
						 * request with 400 where AC-NFR-001(i) requires 401.
						 * The whitelist is enforced in the handler instead —
						 * which it has to be regardless, since a hand-written
						 * `args` schema carries no `validate_callback` of its
						 * own and `enum` alone enforces nothing (measured on
						 * WP 7.0.4).
						 */
						'visibility' => array(
							'type'              => 'string',
							'default'           => '',
							'enum'              => GameLib_Visibility::VALUES,
							'description'       => __( 'Members-only or public.', 'game-library' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		/*
		 * JSON only — no `html` companion, unlike the library and feed list
		 * routes. See principal/adr/014-invite-routes-return-json-only.md: an
		 * invite payload carries no member-supplied string (hex code, composed
		 * URL, whitelisted status, gmdate timestamps), so the DD-005 fragment
		 * buys no escaping guarantee here, and the account-panel markup it
		 * would have to match belongs to a later task.
		 */
		register_rest_route(
			self::REST_NAMESPACE,
			'/invites',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_invite' ),
					'permission_callback' => array( __CLASS__, 'check_issuer' ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_invites' ),
					'permission_callback' => array( __CLASS__, 'check_member' ),
					'args'                => array(
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'description'       => __( 'Page of the invite list.', 'game-library' ),
							'sanitize_callback' => 'absint',
						),
						/*
						 * Clamped rather than refused, per
						 * principal/adr/012-per-page-cap-clamps.md: reading
						 * fewer rows than asked for loses nothing, and the
						 * response echoes the size actually used.
						 */
						'per_page' => array(
							'type'              => 'integer',
							'default'           => GameLib_Invites::LIST_LIMIT,
							'description'       => __( 'Invites per page.', 'game-library' ),
							'sanitize_callback' => array( __CLASS__, 'sanitize_per_page' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/account/steam',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'disconnect_steam' ),
					'permission_callback' => array( __CLASS__, 'check_member' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/export',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'export' ),
					'permission_callback' => array( __CLASS__, 'check_member' ),
					'args'                => array(
						'format' => array(
							'type'              => 'string',
							'default'           => GameLib_Exporter::FORMAT_CSV,
							'enum'              => GameLib_Exporter::FORMATS,
							'description'       => __( 'Download format: CSV or JSON.', 'game-library' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * Gate for every route in this class: a member, with the library
	 * capability (Always Do #3).
	 *
	 * A logged-out caller is 401 and a logged-in caller without the capability
	 * is 403 — the distinction AC-NFR-001 (i),(q),(r) asserts. Both come from
	 * {@see GameLib_Capabilities::rest_gate()}, the single definition of that
	 * contract; this callback supplies only the capability and its two
	 * sentences. A cookie-authenticated request that omits or fails the
	 * `wp_rest` nonce never arrives here: core treats a nonce-less request as
	 * anonymous (so this callback answers 401) and rejects a wrong nonce with
	 * its own 403 before any callback runs.
	 *
	 * @return true|WP_Error True when the caller may act on their account.
	 */
	public static function check_member() {
		return GameLib_Capabilities::rest_gate(
			GameLib_Capabilities::CAP_MANAGE_LIBRARY,
			__( 'Sign in to manage your account.', 'game-library' ),
			__( 'Your account cannot manage a game library.', 'game-library' )
		);
	}

	/**
	 * Gate for invite creation: a member who may still issue invites (AC-001).
	 *
	 * Layered exactly as AC-007(b) describes. The binary disable toggle is
	 * consulted first, purely so a switched-off member reads why rather than a
	 * generic refusal — the capability check below would deny them anyway,
	 * because {@see GameLib_Capabilities::filter_user_has_cap()} withdraws
	 * `gamelib_issue_invites` from a disabled member at the `user_has_cap`
	 * layer. Neither check consults the site-wide quota: that is a domain rule
	 * enforced by {@see GameLib_Invites::create()}, and its refusal names the
	 * remaining allowance (AC-002c).
	 *
	 * This is the one gate that cannot delegate to
	 * {@see GameLib_Capabilities::rest_gate()} wholesale, because its middle
	 * step has to run *between* the two halves of that contract: after the
	 * capability test a switched-off member would read the generic
	 * `gamelib_rest_forbidden` instead of the code that says why. The 401 and
	 * the 403 themselves still come from the same two factories every other
	 * gate uses, so the codes, statuses, and `state` keys have one definition.
	 *
	 * @return true|WP_Error True when the caller may create an invite.
	 */
	public static function check_issuer() {
		if ( ! is_user_logged_in() ) {
			return GameLib_Capabilities::rest_signin_error(
				__( 'Sign in to create an invite.', 'game-library' )
			);
		}

		if ( GameLib_Capabilities::invites_disabled( get_current_user_id() ) ) {
			return new WP_Error(
				'gamelib_rest_invites_disabled',
				__( 'Invite creation is switched off for your account. Ask an administrator if you think that is a mistake.', 'game-library' ),
				array(
					'status' => 403,
					'state'  => 'invites_disabled',
				)
			);
		}

		if ( ! current_user_can( GameLib_Capabilities::CAP_ISSUE_INVITES ) ) {
			return GameLib_Capabilities::rest_forbidden_error(
				__( 'Your account cannot create invites.', 'game-library' )
			);
		}

		return true;
	}

	/**
	 * `POST /invites` — mint one single-use invite (AC-001).
	 *
	 * The response carries the code, its shareable `/join/` URL, and the
	 * caller's refreshed allowance, so the account panel can render the new
	 * invite and update "you have N left" from one round trip. There is no
	 * recipient parameter anywhere in the flow: the site never collects or
	 * emails invitee contact information (AC-001, D-REQ-9).
	 *
	 * @return WP_REST_Response|WP_Error The new invite, or the reason it was refused.
	 */
	public static function create_invite() {
		$user_id = get_current_user_id();
		$invite  = GameLib_Invites::create( $user_id );

		if ( is_wp_error( $invite ) ) {
			return $invite;
		}

		$response = rest_ensure_response(
			array(
				'invite'    => $invite,
				'allowance' => self::allowance_payload( $user_id ),
				'state'     => 'invite_created',
				'message'   => __( 'Your invite link is ready. It works once, for one person.', 'game-library' ),
			)
		);

		$response->set_status( 201 );

		return $response;
	}

	/**
	 * `GET /invites` — the caller's own invites and their remaining allowance.
	 *
	 * Open to any member, including one whose issuance an administrator has
	 * switched off: they can still see — and re-copy — the links they already
	 * sent. `allowance.can_create` is what the panel gates its create control
	 * on.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response One page of the caller's invites.
	 */
	public static function list_invites( $request ) {
		$user_id  = get_current_user_id();
		$per_page = self::sanitize_per_page( $request->get_param( 'per_page' ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$counts   = GameLib_Invites::counts_for_user( $user_id );
		$total    = (int) $counts['total'];
		$invites  = array();

		foreach ( GameLib_Invites::list_for_user( $user_id, $per_page, ( $page - 1 ) * $per_page ) as $row ) {
			$invites[] = GameLib_Invites::payload( $row );
		}

		return rest_ensure_response(
			array(
				'invites'   => $invites,
				'counts'    => $counts,
				'allowance' => self::allowance_payload( $user_id ),
				'total'     => $total,
				'pages'     => (int) ceil( $total / $per_page ),
				'page'      => $page,
				'per_page'  => $per_page,
			)
		);
	}

	/**
	 * A requested invite page size, clamped to the domain's ceiling.
	 *
	 * @param mixed $value Raw parameter.
	 * @return int Rows this request will actually read.
	 */
	public static function sanitize_per_page( $value ) {
		$per_page = absint( $value );

		if ( $per_page < 1 ) {
			return GameLib_Invites::LIST_LIMIT;
		}

		return min( GameLib_Invites::MAX_LIST_LIMIT, $per_page );
	}

	/**
	 * `POST /account/visibility` — flip the opt-in public toggle (AC-027c).
	 *
	 * The write and its two side effects happen in one request:
	 * {@see GameLib_Visibility::set()} bumps the visibility generation scope —
	 * which is what drops every cached visibility-derived read, the sitemap
	 * provider's query among them — and calls the page-cache purge helper for
	 * the member's profile URL. That bundling is what makes AC-029(a) true: the
	 * very next logged-out request already sees the new state.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error The new state, or the reason it failed.
	 */
	public static function set_visibility( $request ) {
		$user_id    = get_current_user_id();
		$visibility = sanitize_key( (string) $request->get_param( 'visibility' ) );

		if ( ! in_array( $visibility, GameLib_Visibility::VALUES, true ) ) {
			// A value outside the whitelist is a malformed request, not a
			// failed write — and "private" is not one of this plugin's two
			// states (AC-027b, R-REQ-1).
			return new WP_Error(
				'gamelib_rest_visibility_invalid',
				__( 'Choose Members-only or Public.', 'game-library' ),
				array(
					'status' => 400,
					'state'  => 'invalid_visibility',
				)
			);
		}

		if ( ! GameLib_Visibility::set( $user_id, $visibility ) ) {
			return new WP_Error(
				'gamelib_rest_visibility_failed',
				__( 'That setting could not be saved. Please try again.', 'game-library' ),
				array(
					'status' => 500,
					'state'  => 'failed',
				)
			);
		}

		$current   = GameLib_Visibility::get( $user_id );
		$is_public = GameLib_Visibility::is_public( $user_id );
		$url       = GameLib_Visibility::profile_url( $user_id );

		return rest_ensure_response(
			array(
				'visibility'  => $current,
				'is_public'   => $is_public,
				'label'       => GameLib_Visibility::visibility_label( $current ),
				'profile_url' => $url,
				// AC-030: the owner-only view of their own profile as a
				// logged-out visitor sees it.
				'preview_url' => ( '' === $url )
					? ''
					: add_query_arg( GameLib_Router::QV_PREVIEW, GameLib_Router::PREVIEW_VISITOR, $url ),
				'state'       => 'visibility_changed',
				'message'     => $is_public
					? __( 'Your profile is public. Anyone can see it, including signed-out visitors.', 'game-library' )
					: __( 'Your profile is members-only. Only signed-in members can see it.', 'game-library' ),
			)
		);
	}

	/**
	 * `DELETE /account/steam` — disconnect the stored SteamID (AC-046b).
	 *
	 * Deletes `gamelib_steamid` and drops the cached owned-games list read on
	 * behalf of that account, so nothing about the disconnected account
	 * outlives the disconnect. Idempotent: a member with no SteamID stored gets
	 * the same disconnected state back, with `removed` false.
	 *
	 * @return WP_REST_Response The disconnected state.
	 */
	public static function disconnect_steam() {
		$user_id = get_current_user_id();
		$steamid = GameLib_Steam_Client::sanitize_steamid64(
			get_user_meta( $user_id, GameLib_Steam_Client::STEAMID_META, true )
		);
		$removed = (bool) delete_user_meta( $user_id, GameLib_Steam_Client::STEAMID_META );

		if ( '' !== $steamid ) {
			GameLib_Steam_Client::delete_owned_games_cache( $steamid );
		}

		return rest_ensure_response(
			array(
				'connected' => false,
				'steamid'   => '',
				'removed'   => $removed,
				'state'     => 'steam_disconnected',
				'message'   => __( 'Your Steam profile is disconnected. Its ID is no longer stored.', 'game-library' ),
			)
		);
	}

	/**
	 * `GET /export` — download the caller's own library (AC-037).
	 *
	 * Exactly the four permitted fields per row, in both formats, served with a
	 * `Content-Disposition: attachment` header. The body itself is emitted
	 * verbatim by {@see serve_export()} — a CSV that went through the REST
	 * server's JSON encoder would arrive as a quoted string rather than a file.
	 *
	 * Nothing here holds the document (PB-8, VIP-6). The response body is empty:
	 * {@see serve_export()} owns the output and streams the file from
	 * {@see GameLib_Exporter::stream()} as the keyset walk produces it, so peak
	 * memory is one batch of serialized rows rather than a whole library —
	 * twice over, since the previous shape held the assembled string here *and*
	 * echoed it there.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response The file's headers; the body is streamed.
	 */
	public static function export( $request ) {
		$user_id = get_current_user_id();
		$format  = GameLib_Exporter::sanitize_format( $request->get_param( 'format' ) );

		// What the streaming callback needs, which the filter signature cannot
		// carry. Read once, by the filter this call registers, and cleared there.
		self::$export_job = array(
			'format'  => $format,
			'user_id' => $user_id,
		);

		$response = new WP_REST_Response( '' );

		$response->header( 'Content-Type', GameLib_Exporter::content_type( $format ) );
		$response->header(
			'Content-Disposition',
			'attachment; filename="' . GameLib_Exporter::filename( $user_id, $format ) . '"'
		);
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_export' ), 10, 3 );

		return $response;
	}

	/**
	 * Emit an export response as the file it is, rather than as JSON.
	 *
	 * The REST server serializes every response body through `wp_json_encode()`
	 * unless a `rest_pre_serve_request` filter claims the request; a download
	 * has to claim it, or the CSV arrives quoted and escaped. Registered by
	 * {@see export()} for the duration of one request and removed here, and
	 * scoped to the export route's own successful responses — an error is
	 * served as ordinary JSON, and a `HEAD` request is left to core, which
	 * sends the headers without a body.
	 *
	 * The bytes are written by {@see GameLib_Exporter::stream()} one batch at a
	 * time rather than echoed from a string the route assembled (VIP-6).
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_HTTP_Response $result  Response about to be served.
	 * @param WP_REST_Request  $request Request being served.
	 * @return bool True when this filter emitted the body itself.
	 */
	public static function serve_export( $served, $result, $request ) {
		if ( $served || ! $request instanceof WP_REST_Request || self::EXPORT_ROUTE !== $request->get_route() ) {
			return $served;
		}

		remove_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_export' ), 10 );

		$job              = self::$export_job;
		self::$export_job = null;

		if ( ! is_array( $job ) || ! $result instanceof WP_HTTP_Response || 200 !== $result->get_status() ) {
			return $served;
		}

		// Core answers a HEAD with headers only, and a streamed body would be
		// exactly the thing it is not supposed to send.
		if ( 'HEAD' === $request->get_method() ) {
			return $served;
		}

		GameLib_Exporter::stream( $job['format'], $job['user_id'] );

		return true;
	}

	/**
	 * The caller's invite allowance, shaped for transport.
	 *
	 * The domain reports an unlimited quota as
	 * {@see GameLib_Invites::UNLIMITED} (`-1`), which is unambiguous in PHP and
	 * a trap in JSON — a client that renders `remaining` would print "-1 left".
	 * Over the wire it becomes `null` beside the explicit `unlimited` flag.
	 *
	 * @param int $user_id Member whose allowance is being reported.
	 * @return array<string, int|bool|null> Allowance payload.
	 */
	private static function allowance_payload( $user_id ) {
		$allowance = GameLib_Invites::allowance( $user_id );

		return array(
			'quota'      => (int) $allowance['quota'],
			'issued'     => (int) $allowance['issued'],
			'remaining'  => $allowance['unlimited'] ? null : (int) $allowance['remaining'],
			'unlimited'  => (bool) $allowance['unlimited'],
			'disabled'   => (bool) $allowance['disabled'],
			'can_create' => (bool) $allowance['can_create'],
		);
	}
}
