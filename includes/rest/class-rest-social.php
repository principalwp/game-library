<?php
/**
 * REST routes for the social graph: follows, the feed, and member libraries.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The social half of the `gamelib/v1` REST surface.
 *
 * Four routes:
 *
 * - `POST   /follow`               — follow a member (AC-022, AC-023).
 * - `DELETE /follow`               — unfollow a member (AC-023b).
 * - `GET    /feed`                 — one keyset page of the caller's feed
 *                                    (AC-024, AC-025).
 * - `GET    /members/{id}/library` — one page of a member's library, gated by
 *                                    the AC-028 matrix (AC-031c).
 *
 * Two 404 gates live in this class, they share one response shape
 * ({@see not_found_error()}), and they fire on entirely different conditions:
 *
 * 1. **Follow** answers 404 only when the target user id does not genuinely
 *    exist (AC-023c). Visibility is never consulted: under the Q-SPEC-1
 *    auth-only model any logged-in member may follow any other logged-in
 *    member, whatever the target's setting (DD-008). A logged-out requester
 *    never reaches the existence check — {@see check_follow()} answered 401
 *    first (AC-NFR-001g).
 * 2. **`/members/{id}/library`** answers 404 only for a viewer the AC-028
 *    matrix denies, which — the matrix being keyed on authentication state and
 *    the owner's own setting alone — is exactly one cell: a logged-out visitor
 *    at a non-public member's URL (AC-028c, DD-014). Every logged-in member is
 *    served every other member's library regardless of visibility or follow
 *    status (AC-028b).
 *
 * Because both gates return the same code, status, and message, no response
 * from either route lets a caller tell "that member does not exist" apart from
 * "that member exists but you are denied".
 *
 * List-returning routes answer `{ data, html, … }`: the fragment is rendered by
 * the same template parts the first paint uses, so markup and escaping exist
 * once, in PHP (ADR-002). The JSON beside it is transport and is deliberately
 * not HTML-escaped (Never Do #14).
 */
final class GameLib_REST_Social {

	/**
	 * REST namespace shared by every plugin route (§6 Integration Points); the
	 * same literal as {@see GameLib_REST_Library::REST_NAMESPACE}.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'gamelib/v1';

	/**
	 * State: the caller follows nobody, or nobody they follow has done
	 * anything yet (AC-026).
	 *
	 * @var string
	 */
	const STATE_FEED_EMPTY = 'feed_empty';

	/**
	 * State: a "Load more" request that reached the end of the feed. Distinct
	 * from {@see STATE_FEED_EMPTY} — the feed has items, this page has none.
	 *
	 * @var string
	 */
	const STATE_FEED_END = 'feed_end';

	/**
	 * State: this page of the feed carries items.
	 *
	 * @var string
	 */
	const STATE_FEED = 'feed';

	/**
	 * State: the member whose profile is being viewed has added no games.
	 *
	 * @var string
	 */
	const STATE_MEMBER_EMPTY = 'member_library_empty';

	/**
	 * State: the applied status filter matched none of that member's games —
	 * a different thing from an empty library (AC-017 copy, reused).
	 *
	 * @var string
	 */
	const STATE_MEMBER_NO_MATCH = 'member_library_no_match';

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
			'/follow',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'follow' ),
					'permission_callback' => array( __CLASS__, 'check_follow' ),
					'args'                => array(
						'user_id' => self::user_id_schema(),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'unfollow' ),
					'permission_callback' => array( __CLASS__, 'check_follow' ),
					'args'                => array(
						'user_id' => self::user_id_schema(),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/feed',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'feed' ),
					'permission_callback' => array( __CLASS__, 'check_follow' ),
					'args'                => array(
						'before' => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'description'       => __( 'Keyset cursor: the id of the last item already shown.', 'game-library' ),
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/members/(?P<id>[0-9]+)/library',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'member_library' ),
					'permission_callback' => array( __CLASS__, 'check_member_library' ),
					'args'                => array(
						'id'     => array(
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'description'       => __( 'Member whose library is being read.', 'game-library' ),
							'sanitize_callback' => 'absint',
						),
						'status' => array(
							'type'              => 'string',
							'default'           => GameLib_Library::STATUS_ALL,
							'enum'              => array_merge( array( GameLib_Library::STATUS_ALL ), GameLib_Library::STATUSES ),
							'description'       => __( 'Status filter, or “all”.', 'game-library' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'page'   => array(
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'description'       => __( 'Page of results, 1-based.', 'game-library' ),
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Gate for the follow, unfollow, and feed routes (Always Do #3).
	 *
	 * A logged-out caller is 401 and a logged-in caller whose role does not
	 * carry `gamelib_follow` is 403 — the distinction AC-NFR-001 (g),(h)
	 * asserts. Both come from {@see GameLib_Capabilities::rest_gate()}, the
	 * single definition of that contract; this callback supplies only the
	 * capability and its two sentences. A cookie-authenticated request that
	 * omits or fails the `wp_rest` nonce never arrives here: core treats a
	 * nonce-less request as anonymous (so this callback answers 401) and rejects
	 * a wrong nonce with its own 403 before any callback runs.
	 *
	 * @return true|WP_Error True when the caller may use the social routes.
	 */
	public static function check_follow() {
		return GameLib_Capabilities::rest_gate(
			GameLib_Capabilities::CAP_FOLLOW,
			__( 'Sign in to follow other members.', 'game-library' ),
			__( 'Your account cannot follow other members.', 'game-library' )
		);
	}

	/**
	 * Gate for `GET /members/{id}/library`: the AC-028 matrix, nothing else.
	 *
	 * Deliberately *not* a "logged-in only" check. The route powers the
	 * read-only grid on `/members/{nicename}/`, and that page is served to
	 * logged-out visitors when the owner has opted their profile public
	 * (AC-028d) — so the permission model here is viewability, evaluated by the
	 * single authority every other surface uses
	 * ({@see GameLib_Visibility::can_view_member()}).
	 *
	 * The viewer comes from {@see GameLib_Router::viewer_id()} rather than
	 * `get_current_user_id()`, so profile surfaces read one identity everywhere.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error True when this viewer may see that member's library.
	 */
	public static function check_member_library( $request ) {
		$owner_id = absint( $request->get_param( 'id' ) );

		if ( ! GameLib_Visibility::can_view_member( GameLib_Router::viewer_id(), $owner_id ) ) {
			// A member who does not exist and a member this viewer is denied
			// produce the same refusal (AC-028e, DD-014).
			return self::not_found_error();
		}

		return true;
	}

	/**
	 * `POST /follow` — follow a member (AC-022 a,b, AC-023).
	 *
	 * Open follow: no approval, no notification, and no visibility check. The
	 * only refusals are following yourself (400) and a target user id that does
	 * not exist (404) — a repeat follow is a success with one edge (AC-023b).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error The toggle's new state, or the refusal.
	 */
	public static function follow( $request ) {
		$viewer_id = get_current_user_id();
		$target_id = absint( $request->get_param( 'user_id' ) );

		if ( $target_id < 1 ) {
			return self::invalid_member_error();
		}

		$result = GameLib_Follows::follow( $viewer_id, $target_id );

		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}

		return rest_ensure_response( self::follow_payload( $viewer_id, $target_id ) );
	}

	/**
	 * `DELETE /follow` — unfollow a member (AC-023b).
	 *
	 * Idempotent in the same way follow is: unfollowing somebody the caller
	 * does not follow is a success that changed nothing.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error The toggle's new state, or the refusal.
	 */
	public static function unfollow( $request ) {
		$viewer_id = get_current_user_id();
		$target_id = absint( $request->get_param( 'user_id' ) );

		if ( $target_id < 1 ) {
			return self::invalid_member_error();
		}

		$result = GameLib_Follows::unfollow( $viewer_id, $target_id );

		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}

		return rest_ensure_response( self::follow_payload( $viewer_id, $target_id ) );
	}

	/**
	 * `GET /feed` — one page of the caller's feed (AC-024, AC-025 b,c).
	 *
	 * Keyset only: `before` is the id of the last item already shown, page size
	 * is fixed at {@see GameLib_Activity::FEED_PAGE_SIZE}, and no offset
	 * parameter exists to send (AC-025c).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response The page, its fragment, and the next cursor.
	 */
	public static function feed( $request ) {
		$viewer_id = get_current_user_id();
		$before    = absint( $request->get_param( 'before' ) );

		$rows  = GameLib_Activity::feed(
			$viewer_id,
			array(
				'before' => $before,
				'limit'  => GameLib_Activity::FEED_PAGE_SIZE,
			)
		);
		$items = GameLib_Activity::render_items( $rows );

		if ( empty( $items ) ) {
			// "Nothing yet" and "no more" are different things to say, and only
			// the first one gets a message (AC-026).
			$state = ( $before > 0 ) ? self::STATE_FEED_END : self::STATE_FEED_EMPTY;

			return rest_ensure_response(
				array(
					'data'     => array(),
					'html'     => ( self::STATE_FEED_EMPTY === $state )
						? self::render_state( $state, 'li' )
						: '',
					'count'    => 0,
					'before'   => $before,
					'next'     => 0,
					'has_more' => false,
					'state'    => $state,
					'message'  => self::state_message( $state ),
				)
			);
		}

		return rest_ensure_response(
			array(
				'data'     => $items,
				'html'     => self::render_items( $items ),
				'count'    => count( $items ),
				'before'   => $before,
				// Derived from the rows, not the rendered items: an event a
				// renderer dropped must still advance the cursor (AC-025b).
				'next'     => GameLib_Activity::next_cursor( $rows ),
				'has_more' => count( $rows ) === GameLib_Activity::FEED_PAGE_SIZE,
				'state'    => self::STATE_FEED,
				'message'  => '',
			)
		);
	}

	/**
	 * `GET /members/{id}/library` — one page of a member's library (AC-031c).
	 *
	 * Read-only by construction: the cards carry no status control, no remove
	 * control, and no bulk checkbox, because none is passed to the part. Page
	 * size is the grid's own 24 and is not caller-tunable.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response The page, its fragment, and its filter state.
	 */
	public static function member_library( $request ) {
		$owner_id = absint( $request->get_param( 'id' ) );
		$owner    = get_userdata( $owner_id );
		$name     = ( $owner instanceof WP_User ) ? (string) $owner->display_name : '';

		$args = array(
			'status'   => GameLib_Library::sanitize_status( $request->get_param( 'status' ) ),
			'sort'     => GameLib_Library::SORT_DEFAULT,
			'page'     => max( 1, absint( $request->get_param( 'page' ) ) ),
			'per_page' => GameLib_Library::PAGE_SIZE,
		);

		$result = GameLib_Library::query( $owner_id, $args );

		if ( $result['pages'] > 0 && $result['page'] > $result['pages'] ) {
			$args['page'] = $result['pages'];
			$result       = GameLib_Library::query( $owner_id, $args );
		}

		$rows = array();

		foreach ( $result['rows'] as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = self::entry_payload( $row );
			}
		}

		return rest_ensure_response(
			array(
				'data'      => $rows,
				'html'      => self::render_entries( $result, $name ),
				'total'     => (int) $result['total'],
				'pages'     => (int) $result['pages'],
				'page'      => (int) $result['page'],
				'per_page'  => (int) $result['per_page'],
				'status'    => (string) $result['status'],
				'filtered'  => (bool) $result['filtered'],
				'counts'    => GameLib_Library::count_by_status( $owner_id ),
				'member'    => array(
					'id'   => $owner_id,
					'name' => $name,
					'url'  => GameLib_Visibility::profile_url( $owner_id ),
				),
				'is_public' => GameLib_Visibility::is_public( $owner_id ),
			)
		);
	}

	/**
	 * The shared copy for the list states this class can render.
	 *
	 * Published like {@see GameLib_REST_Library::state_message()} so the first
	 * paint of `/activity/` and a profile grid say exactly what a REST swap
	 * says, without either side re-authoring the strings.
	 *
	 * @param string $state One of the `STATE_*` constants.
	 * @param string $name  Member display name, for the states that name one.
	 * @return string Translated message, or '' for a state with nothing to say.
	 */
	public static function state_message( $state, $name = '' ) {
		switch ( $state ) {
			case self::STATE_FEED_EMPTY:
				// AC-026: explains what fills the feed, rather than "no results".
				return __( 'Your feed fills up as the members you follow add and update games.', 'game-library' );

			case self::STATE_MEMBER_EMPTY:
				return ( '' === $name )
					? __( 'This member has not added any games yet.', 'game-library' )
					: sprintf(
						/* translators: %s: member display name. */
						__( '%s has not added any games yet.', 'game-library' ),
						$name
					);

			case self::STATE_MEMBER_NO_MATCH:
				return GameLib_REST_Library::state_message( GameLib_REST_Library::STATE_LIBRARY_NO_MATCH );
		}

		return '';
	}

	/**
	 * The accessible label of a follow toggle (AC-022c).
	 *
	 * Names the profile owner in both states, because "Follow" alone is not an
	 * accessible name on a page that may carry several toggles.
	 *
	 * @param string $name      Owner's display name.
	 * @param bool   $following Whether the caller currently follows them.
	 * @return string Translated label.
	 */
	public static function follow_label( $name, $following ) {
		if ( $following ) {
			return sprintf(
				/* translators: %s: member display name. */
				__( 'Unfollow %s', 'game-library' ),
				$name
			);
		}

		return sprintf(
			/* translators: %s: member display name. */
			__( 'Follow %s', 'game-library' ),
			$name
		);
	}

	/**
	 * The `user_id` parameter of the follow routes.
	 *
	 * Deliberately not `required`: WordPress validates the args schema *before*
	 * running `permission_callback`, so a required parameter would turn a
	 * logged-out request that omits it into a 400 where AC-NFR-001(g) requires
	 * 401. The handler refuses a missing id with its own 400 instead.
	 *
	 * @return array<string, mixed> `args` schema fragment.
	 */
	private static function user_id_schema() {
		return array(
			'type'              => 'integer',
			'default'           => 0,
			'minimum'           => 0,
			'description'       => __( 'Member to follow or unfollow.', 'game-library' ),
			'sanitize_callback' => 'absint',
		);
	}

	/**
	 * The state a follow toggle renders after a successful write.
	 *
	 * Counts are read after the write and are current: an edge write bumps both
	 * endpoints' follow scopes, so the cached counts cannot survive their own
	 * edge (AC-NFR-010b).
	 *
	 * @param int $viewer_id Caller.
	 * @param int $target_id Member followed or unfollowed.
	 * @return array<string, mixed> Response payload.
	 */
	private static function follow_payload( $viewer_id, $target_id ) {
		$owner     = get_userdata( $target_id );
		$name      = ( $owner instanceof WP_User ) ? (string) $owner->display_name : '';
		$following = GameLib_Follows::is_following( $viewer_id, $target_id );

		return array(
			'user_id'         => $target_id,
			'following'       => $following,
			// What the control's `aria-pressed` should say (AC-022c).
			'pressed'         => $following,
			'label'           => self::follow_label( $name, $following ),
			'followers'       => GameLib_Follows::follower_count( $target_id ),
			'following_count' => GameLib_Follows::following_count( $target_id ),
			'state'           => $following ? 'following' : 'not_following',
			'message'         => $following
				? sprintf(
					/* translators: %s: member display name. */
					__( 'You are now following %s.', 'game-library' ),
					$name
				)
				: sprintf(
					/* translators: %s: member display name. */
					__( 'You are no longer following %s.', 'game-library' ),
					$name
				),
		);
	}

	/**
	 * The JSON shape of one library entry on a member's profile.
	 *
	 * Values travel raw — they are transport, and the markup that renders them
	 * escapes them where it renders them (Never Do #14, AC-NFR-002).
	 *
	 * @param array $row Row from {@see GameLib_Library::query()}.
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
	 * Render a page of a member's entries, or the state that explains why there
	 * are none.
	 *
	 * The cards are the same part `/my-library/` renders, minus every control:
	 * a read-only grid is the identical markup with nothing interactive passed
	 * to it (AC-031c).
	 *
	 * @param array  $result Return value of {@see GameLib_Library::query()}.
	 * @param string $name   Member display name, for the empty state.
	 * @return string Markup.
	 */
	private static function render_entries( array $result, $name ) {
		if ( empty( $result['rows'] ) ) {
			$state = $result['filtered'] ? self::STATE_MEMBER_NO_MATCH : self::STATE_MEMBER_EMPTY;

			return self::render_state( $state, 'li', $name );
		}

		$html = '';

		foreach ( $result['rows'] as $row ) {
			if ( is_array( $row ) ) {
				$html .= GameLib_Router::render_part( 'game-card', $row );
			}
		}

		return $html;
	}

	/**
	 * Render a page of feed items.
	 *
	 * The actor is deliberately not passed: the sentence
	 * {@see GameLib_Activity::render_items()} composes already opens with the
	 * actor's linked display name (AC-024a), so passing `actor_name` as well
	 * would print the member's name twice in every item.
	 *
	 * @param array[] $items Render-ready items.
	 * @return string Markup.
	 */
	private static function render_items( array $items ) {
		$html = '';

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$html .= GameLib_Router::render_part(
				'feed-item',
				array(
					'type'       => isset( $item['type'] ) ? (string) $item['type'] : '',
					'sentence'   => isset( $item['sentence'] ) ? (string) $item['sentence'] : '',
					'created_at' => isset( $item['created_at'] ) ? (string) $item['created_at'] : '',
				)
			);
		}

		return $html;
	}

	/**
	 * Render one list-state message through the shared part.
	 *
	 * @param string $state One of the `STATE_*` constants.
	 * @param string $tag   `li` inside a list, `p` anywhere else.
	 * @param string $name  Member display name, for the states that name one.
	 * @return string Markup.
	 */
	private static function render_state( $state, $tag, $name = '' ) {
		return GameLib_Router::render_part(
			'list-state',
			array(
				'state'   => $state,
				'tag'     => $tag,
				'tone'    => 'neutral',
				'message' => self::state_message( $state, $name ),
			)
		);
	}

	/**
	 * Turn a follow-domain failure into a typed REST error.
	 *
	 * The domain's "no such member" becomes the shared 404 — the same code,
	 * status, and message the visibility gate returns — so neither route
	 * discloses which of the two conditions was actually met.
	 *
	 * @param WP_Error $error Failure from {@see GameLib_Follows}.
	 * @return WP_Error Typed error carrying `status` and `state`.
	 */
	private static function error_response( WP_Error $error ) {
		$code = (string) $error->get_error_code();

		if ( GameLib_Follows::ERROR_NO_MEMBER === $code ) {
			return self::not_found_error();
		}

		$data   = $error->get_error_data();
		$status = ( is_array( $data ) && isset( $data['status'] ) ) ? absint( $data['status'] ) : 0;

		if ( $status < 400 ) {
			$status = 400;
		}

		return new WP_Error(
			$code,
			(string) $error->get_error_message(),
			array(
				'status' => $status,
				'state'  => self::error_state( $code ),
			)
		);
	}

	/**
	 * The `state` term a client keys on for a follow-domain failure.
	 *
	 * @param string $code Domain error code.
	 * @return string State term.
	 */
	private static function error_state( $code ) {
		switch ( $code ) {
			case GameLib_Follows::ERROR_SELF:
				return 'follow_self';

			case GameLib_Follows::ERROR_NO_ACTOR:
				return 'unauthorized';

			case GameLib_Follows::ERROR_FAILED:
				return 'failed';
		}

		return sanitize_key( $code );
	}

	/**
	 * The shared refusal of both 404 gates in this class.
	 *
	 * One shape, two conditions (see the class docblock): a target user id that
	 * does not exist, and a viewer the AC-028 matrix denies. Nothing in the
	 * response distinguishes them.
	 *
	 * @return WP_Error 404 refusal.
	 */
	private static function not_found_error() {
		return new WP_Error(
			'gamelib_rest_member_not_found',
			__( 'That member could not be found.', 'game-library' ),
			array(
				'status' => 404,
				'state'  => 'not_found',
			)
		);
	}

	/**
	 * A follow request that named no member at all.
	 *
	 * Distinct from {@see not_found_error()} on purpose: a missing parameter is
	 * a malformed request (400), not a statement about whether some member
	 * exists (AC-023c reserves 404 for a genuinely nonexistent target id).
	 *
	 * @return WP_Error 400 refusal.
	 */
	private static function invalid_member_error() {
		return new WP_Error(
			'gamelib_rest_member_invalid',
			__( 'That member could not be identified.', 'game-library' ),
			array(
				'status' => 400,
				'state'  => 'invalid_member',
			)
		);
	}
}
