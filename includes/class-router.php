<?php
/**
 * Rewrite rules, query vars, template resolution, and access gates.
 *
 * @package Game_Library
 */

namespace Game_Library;

use Game_Library\Data\Game_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Router.
 *
 * Registers the eight DD-008 front-end routes behind a single `gl_route`
 * query var (plus `gl_param` for the nicename/code/slug segment), resolves
 * each to a template in `templates/` (overridable by a theme via
 * `locate_template( 'game-library/{name}.php' )`), and enforces the access
 * gates every private route needs — a login redirect for a logged-out
 * visitor, and a 404 for an unknown member nicename or a game no active
 * member's library references.
 *
 * Query-var registration: rules below are added with `add_rewrite_rule()`,
 * not `add_rewrite_tag()`. `add_rewrite_tag()` auto-whitelists the tag it
 * creates as a public query var as a side effect; `add_rewrite_rule()` does
 * not. `WP::parse_request()` only copies a variable out of the rewrite
 * match's parsed query string into `$wp->query_vars` (what `get_query_var()`
 * ultimately reads) when that variable's name is present in
 * `$wp->public_query_vars` — the list core builds by running its own default
 * set through the `query_vars` filter. Without `register_query_vars()` below
 * adding `gl_route`/`gl_param` to that list, every one of these rules would
 * still match its URL pattern, but `get_query_var( 'gl_route' )` would return
 * an empty string on every request and every route below would silently
 * fall through to whatever the theme renders for an unresolved path — the
 * single most common cause of "the plugin activated but nothing resolves."
 *
 * Rewrite-rule/query-var registration timing: `Plugin::on_init()` (hooked to
 * `init` priority 10) calls `register_routes()` directly on every request,
 * rather than this class adding its own `init` hook — see that method's
 * docblock and `class-activator.php` for why: `Activator::activate()` calls
 * `Plugin::instance()->on_init()` synchronously, because by the time the
 * activation hook fires, WordPress's own `init` action has already completed
 * for that request and will not fire a second time. Registering rewrite
 * rules on every normal request (not only at activation) matters even though
 * request-time URL matching reads the persisted `rewrite_rules` option,
 * unchanged by a normal request: any later flush — an administrator resaving
 * Settings > Permalinks, another plugin calling `flush_rewrite_rules()` —
 * only re-captures rules that are registered on the `init` hook of the
 * specific request that triggers it, so these calls run every time.
 */
final class Router {

	/**
	 * `gl_route` value: the viewer's own library, `/my-library/`.
	 *
	 * @var string
	 */
	public const ROUTE_MY_LIBRARY = 'my-library';

	/**
	 * `gl_route` value: another member's library, `/library/{nicename}/` —
	 * deliberately distinct from the plural `ROUTE_MEMBERS` to avoid a
	 * collision.
	 *
	 * @var string
	 */
	public const ROUTE_LIBRARY = 'library';

	/**
	 * `gl_route` value: the viewer's activity feed, `/activity/`.
	 *
	 * @var string
	 */
	public const ROUTE_ACTIVITY = 'activity';

	/**
	 * `gl_route` value: the member directory, `/members/`.
	 *
	 * @var string
	 */
	public const ROUTE_MEMBERS = 'members';

	/**
	 * `gl_route` value: the viewer's own invites screen, `/invites/`.
	 *
	 * @var string
	 */
	public const ROUTE_INVITES = 'invites';

	/**
	 * `gl_route` value: invite redemption / registration, `/join/` and
	 * `/join/{code}/`.
	 *
	 * @var string
	 */
	public const ROUTE_JOIN = 'join';

	/**
	 * `gl_route` value: the public game catalog, `/games/` and
	 * `/games/page/{n}/`.
	 *
	 * @var string
	 */
	public const ROUTE_GAMES = 'games';

	/**
	 * `gl_route` value: one game's own public page, `/games/{slug}/` —
	 * deliberately distinct from the plural `ROUTE_GAMES` to avoid a
	 * collision.
	 *
	 * @var string
	 */
	public const ROUTE_GAME = 'game';

	/**
	 * `gl_route` value => template file name under `templates/`.
	 *
	 * `ROUTE_LIBRARY` names `/library/{nicename}/` (template
	 * `member-library.php`, distinct from the slug to avoid colliding with
	 * `ROUTE_GAMES`) and `ROUTE_GAME` names `/games/{slug}/` (template
	 * `game-single.php`, same reasoning against `ROUTE_GAMES`) — the same
	 * eight values `Assets::ROUTES` (Task 4) already establishes as the
	 * `gl_route` contract, now keyed on this class's own constants (arch-pre-1
	 * architecture review, finding AR-4) rather than bare string literals.
	 *
	 * @var array<string,string>
	 */
	private const TEMPLATES = array(
		self::ROUTE_MY_LIBRARY => 'my-library.php',
		self::ROUTE_LIBRARY    => 'member-library.php',
		self::ROUTE_ACTIVITY   => 'activity.php',
		self::ROUTE_MEMBERS    => 'members.php',
		self::ROUTE_INVITES    => 'invites.php',
		self::ROUTE_JOIN       => 'join.php',
		self::ROUTE_GAMES      => 'game-catalog.php',
		self::ROUTE_GAME       => 'game-single.php',
	);

	/**
	 * `gl_route` values that require a logged-in viewer outright (AC-033
	 * (a)-(d)). `ROUTE_LIBRARY` and `ROUTE_GAME` have their own conditional
	 * gates below — `ROUTE_LIBRARY` because a publicly-opted-in profile is
	 * visible logged-out (AC-034), `ROUTE_GAME` because it is a public route
	 * that 404s only when no active member references the game (AC-040).
	 *
	 * @var string[]
	 */
	private const LOGIN_REQUIRED_ROUTES = array( self::ROUTE_MY_LIBRARY, self::ROUTE_ACTIVITY, self::ROUTE_MEMBERS, self::ROUTE_INVITES );

	/**
	 * The ten physical rewrite-rule pattern => query-string pairs behind the
	 * eight DD-008 routes — `join` gets two (with and without a code
	 * segment), `games` gets two (plain and paginated), every other route
	 * gets one. All registered `top`, so priority is hardcoded in
	 * `add_rewrite_rules()`'s loop rather than stored per entry.
	 *
	 * This is the single source of truth for these ten patterns
	 * (arch-pre-2 architecture review, finding AR-2) —
	 * `Activator::strip_own_rewrite_rules_added_this_request()` reads
	 * `array_keys( self::REWRITE_RULES )` instead of carrying its own
	 * second, hand-mirrored copy of the pattern list. Order is load-bearing:
	 * `'^games/([^/]+)/?$'` must stay registered before
	 * `'^games/page/([0-9]{1,})/?$'` — PHP arrays preserve insertion order
	 * and `foreach` walks them in that order, so this list must never be
	 * sorted/alphabetized/regrouped. Every query string is single-quoted so
	 * `$matches[1]` stays a literal capture-group reference — double quotes
	 * would interpolate an undefined PHP variable and silently break the two
	 * capture-group routes.
	 *
	 * Every query string is now built by concatenating the literal
	 * `index.php?gl_route=`/`&gl_param=$matches[1]`/`&gl_page=$matches[1]`
	 * fragments with the matching `self::ROUTE_*` constant (arch-pre-3
	 * architecture review, finding AR-1), rather than re-spelling each
	 * route's value as a second, bare string literal — this class constant
	 * array is the one place PHP allows a constant expression to reference
	 * another constant on the same class, so the substitution costs nothing
	 * at runtime. Re-spelling a `ROUTE_*` constant no longer requires a
	 * matching hand-edit here to avoid a silent route mismatch.
	 *
	 * @var array<string,string>
	 */
	public const REWRITE_RULES = array(
		'^my-library/?$'                  => 'index.php?gl_route=' . self::ROUTE_MY_LIBRARY,
		'^library/([^/]+)/?$'             => 'index.php?gl_route=' . self::ROUTE_LIBRARY . '&gl_param=$matches[1]',
		'^activity/?$'                    => 'index.php?gl_route=' . self::ROUTE_ACTIVITY,
		'^members/?$'                     => 'index.php?gl_route=' . self::ROUTE_MEMBERS,
		'^invites/?$'                     => 'index.php?gl_route=' . self::ROUTE_INVITES,
		'^join/([^/]+)/?$'                => 'index.php?gl_route=' . self::ROUTE_JOIN . '&gl_param=$matches[1]',
		'^join/?$'                        => 'index.php?gl_route=' . self::ROUTE_JOIN,
		'^games/([^/]+)/?$'               => 'index.php?gl_route=' . self::ROUTE_GAME . '&gl_param=$matches[1]',
		'^games/page/([0-9]{1,})/?$'      => 'index.php?gl_route=' . self::ROUTE_GAMES . '&gl_page=$matches[1]',
		'^games/?$'                       => 'index.php?gl_route=' . self::ROUTE_GAMES,
	);

	/**
	 * The absolute URL for one game's public page, `/games/{slug}/` — the
	 * single owner of this plugin's URL *construction* for the route
	 * `REWRITE_RULES` above owns the *pattern* for (arch-pre-3 architecture
	 * review, finding AR-2). Output is byte-for-byte identical to what every
	 * call site built by hand before this method existed: no escaping and no
	 * sanitization of `$slug` happens here — every caller already holds an
	 * already-trusted value, and a caller that needs an escaped URL wraps
	 * this call in `esc_url()` itself, exactly as it did before. Every other
	 * builder below shares this same no-escaping contract.
	 *
	 * @param string $slug Game slug.
	 * @return string
	 */
	public static function game_url( $slug ) {
		return home_url( '/games/' . $slug . '/' );
	}

	/**
	 * One paginated variant of `/games/{slug}/`'s public-holder list
	 * (CF-VIP-1) — `game-single.php` reads its page number as the plain
	 * `?gl_page=N` query-string param (never a rewritten path segment, per
	 * that route's own docblock and `REWRITE_RULES` above, which has no
	 * `games/{slug}/page/{n}/` pattern), so it is appended with
	 * `add_query_arg()` rather than built into the path.
	 *
	 * @param string $slug Game slug.
	 * @param int    $page 1-based page number.
	 * @return string
	 */
	public static function game_page_url( $slug, $page ) {
		$url = self::game_url( $slug );

		return $page > 1 ? add_query_arg( 'gl_page', (int) $page, $url ) : $url;
	}

	/**
	 * The absolute URL for one member's public library page,
	 * `/library/{nicename}/`.
	 *
	 * @param string $nicename Member's `user_nicename`.
	 * @return string
	 */
	public static function member_library_url( $nicename ) {
		return home_url( '/library/' . $nicename . '/' );
	}

	/**
	 * One paginated/status-filtered variant of `/library/{nicename}/`
	 * (CF-VIP-1) — `member-library.php` reads both as plain query-string
	 * params (`?status=`, `?gl_page=`, per that route's own docblock), never
	 * a rewritten path segment, so both are appended with `add_query_arg()`
	 * rather than built into the path the way `catalog_url()`/`game_page_url()`
	 * build `/page/{n}/`.
	 *
	 * @param string $nicename Member's `user_nicename`.
	 * @param string $status   One of `Statuses::all()`, or '' for the
	 *                         unfiltered "All" view.
	 * @param int    $page     1-based page number.
	 * @return string
	 */
	public static function member_library_page_url( $nicename, $status, $page ) {
		$url = self::member_library_url( $nicename );

		if ( '' !== $status ) {
			$url = add_query_arg( 'status', $status, $url );
		}

		if ( $page > 1 ) {
			$url = add_query_arg( 'gl_page', (int) $page, $url );
		}

		return $url;
	}

	/**
	 * The absolute URL for the viewer's own library, `/my-library/`.
	 *
	 * @return string
	 */
	public static function my_library_url() {
		return home_url( '/my-library/' );
	}

	/**
	 * The absolute URL for the member directory, `/members/`.
	 *
	 * @return string
	 */
	public static function members_url() {
		return home_url( '/members/' );
	}

	/**
	 * The absolute URL for the viewer's own activity feed, `/activity/`.
	 *
	 * @return string
	 */
	public static function activity_url() {
		return home_url( '/activity/' );
	}

	/**
	 * The absolute URL for the viewer's own invites screen, `/invites/`.
	 *
	 * @return string
	 */
	public static function invites_url() {
		return home_url( '/invites/' );
	}

	/**
	 * The absolute URL for invite redemption/registration — `/join/` for no
	 * code, `/join/{code}/` for a specific code.
	 *
	 * @param string $code Invite code, or '' for the bare `/join/` form.
	 * @return string
	 */
	public static function join_url( $code = '' ) {
		return '' === $code ? home_url( '/join/' ) : home_url( '/join/' . $code . '/' );
	}

	/**
	 * The absolute URL for one page of the public game catalog — `/games/`
	 * for page 1 (or lower), `/games/page/{n}/` for page 2+, matching the
	 * ternary every call site used to build by hand.
	 *
	 * @param int $page 1-based page number.
	 * @return string
	 */
	public static function catalog_url( $page = 1 ) {
		return $page < 2 ? home_url( '/games/' ) : home_url( '/games/page/' . (int) $page . '/' );
	}

	/**
	 * Lazily-instantiated game repository, memoized per request.
	 *
	 * @var Game_Repository|null
	 */
	private $game_repository;

	/**
	 * Registers this class's always-on hooks — template resolution and the
	 * access gates. Called unconditionally from `Plugin`'s constructor
	 * (ADR-007). Rewrite-rule and query-var registration is deliberately not
	 * added here — see `register_routes()` and this class's own docblock.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'template_include', array( $this, 'resolve_template' ), 20 );
		add_action( 'template_redirect', array( $this, 'enforce_access_gates' ), 10 );
	}

	/**
	 * Registers the plugin's rewrite rules and whitelists `gl_route`/
	 * `gl_param` as public query vars. Called directly from
	 * `Plugin::on_init()` on every request — see this class's docblock for
	 * why rewrite rules are registered every request while being flushed
	 * only from the activation and deactivation hooks.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->add_rewrite_rules();

		add_filter( 'query_vars', array( $this, 'register_query_vars' ), 10 );
	}

	/**
	 * Adds `gl_route`, `gl_param`, and `gl_page` to WordPress's public query
	 * var whitelist — see this class's docblock for why this is required for
	 * `get_query_var()` to ever return any of them.
	 *
	 * @param string[] $vars Existing public query vars.
	 * @return string[]
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'gl_route';
		$vars[] = 'gl_param';
		$vars[] = 'gl_page';

		return $vars;
	}

	/**
	 * Resolves the current request's `gl_route` to a template, preferring a
	 * theme override at `game-library/{name}.php` (`locate_template()`) over
	 * the plugin's own copy under `templates/`. Leaves `$template` untouched
	 * for a 404 (so the theme's own 404 template renders — `enforce_access_gates()`
	 * may have already called `set_404()`), for a route with no recognised
	 * `gl_route` value, or before a later task has created that route's
	 * template file.
	 *
	 * @param string $template Template path core/theme would otherwise use.
	 * @return string
	 */
	public function resolve_template( $template ) {
		$route = get_query_var( 'gl_route' );

		if ( ! is_string( $route ) || '' === $route || ! isset( self::TEMPLATES[ $route ] ) ) {
			return $template;
		}

		if ( is_404() ) {
			return $template;
		}

		$name = self::TEMPLATES[ $route ];

		$theme_template = locate_template( 'game-library/' . $name );

		if ( '' !== $theme_template ) {
			return $theme_template;
		}

		$plugin_template = plugin_dir_path( GAME_LIBRARY_PLUGIN_FILE ) . 'templates/' . $name;

		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return $template;
	}

	/**
	 * Enforces the access gate for the current request's `gl_route`, if any.
	 *
	 * VIP-3 (cycle-5): `/join/` and `/join/{code}/` are the only plugin
	 * routes that return HTTP 200 to a logged-out visitor AND render
	 * request-specific state — a server-side `wp_nonce_field()` and a live
	 * `find_redeemable_invite()` lookup rendering the inviter's display
	 * name. Nothing in the plugin previously sent `nocache_headers()` or
	 * any `Cache-Control` override, so VIP's edge held the rendered HTML
	 * for up to 30 minutes: an invite revoked or redeemed by someone else
	 * kept serving a valid-looking form from the edge, the nonce baked into
	 * that cached HTML was stale, and per-invite HTML keyed by a secret
	 * code sat in a shared edge cache. `nocache_headers()` emits
	 * `Cache-Control: no-cache, must-revalidate, max-age=0`, which takes
	 * the route out of both VIP's edge and any browser cache — called here
	 * rather than moving the nonce to an AJAX fetch, since the stale
	 * invite-state problem is the larger of the two and that alternative
	 * would only have addressed the lesser half.
	 *
	 * @return void
	 */
	public function enforce_access_gates() {
		$route = get_query_var( 'gl_route' );

		if ( ! is_string( $route ) || '' === $route ) {
			return;
		}

		if ( self::ROUTE_JOIN === $route ) {
			nocache_headers();
		}

		if ( in_array( $route, self::LOGIN_REQUIRED_ROUTES, true ) ) {
			$this->require_login();

			return;
		}

		if ( self::ROUTE_LIBRARY === $route ) {
			$this->gate_member_library();

			return;
		}

		if ( self::ROUTE_GAME === $route ) {
			$this->gate_game_single();
		}
	}

	/**
	 * Registers the ten physical rewrite rules behind the eight DD-008
	 * routes — `join` gets two rules (with and without a code segment) for
	 * one `gl_route` value, `games` gets two (plain and paginated), every
	 * other route gets one. All registered `top` so they are tried before the
	 * site's own generated permalink rules (AC-033/AC-034/AC-040 depend on
	 * these paths never falling through to a same-named page or post).
	 *
	 * The `games/page/(\d+)/` rule is a Task 15 addition, not part of Task
	 * 10's own rule set: AC-042 requires `/games/page/2/` to resolve (its
	 * canonical must match that exact requested URL) and confirmed at
	 * runtime against the shared Playground instance (port 9401) that no
	 * rule here or in WordPress's own generated permalink structure matches
	 * that path otherwise — a bare `add_rewrite_rule( '^games/?$', … )` does
	 * not auto-generate a paginated companion the way a registered post
	 * type/taxonomy permastructure would. See
	 * `principal/adr/008-catalog-listing-and-games-pagination-route.md` and
	 * the Task 15 coder decision log.
	 *
	 * This rule feeds the page number through a plugin-owned `gl_page` query
	 * var, never WordPress's own built-in `paged` — also confirmed at
	 * runtime: `paged` is not just a free-standing value, it feeds WP's own
	 * main query (interpreted as "page N of the site's default archive/home
	 * query"), and that query's own `WP::handle_404()` logic marks the whole
	 * request 404 when the *site's* post count is too small to have a real
	 * page 2, even though `gl_route`/`gl_param` resolved correctly — the
	 * exact reason every other paginated template in this plugin
	 * (`my-library.php`, `member-library.php`, `members.php`, `activity.php`)
	 * already reads pagination from the raw `$_GET['paged']` superglobal
	 * instead of `get_query_var( 'paged' )`, bypassing the main query
	 * entirely. `gl_page` sidesteps the same conflict for a *path*-based
	 * (not query-string) pagination scheme.
	 *
	 * @return void
	 */
	private function add_rewrite_rules() {
		foreach ( self::REWRITE_RULES as $pattern => $query ) {
			add_rewrite_rule( $pattern, $query, 'top' );
		}
	}

	/**
	 * Redirects a logged-out visitor to `wp-login.php` with a `redirect_to`
	 * pointing back at the requested URL (AC-033).
	 *
	 * @return void
	 */
	private function require_login() {
		if ( is_user_logged_in() ) {
			return;
		}

		$this->redirect_to_login();
	}

	/**
	 * Gates `/library/{nicename}/`: 404 when the nicename resolves to no
	 * member, otherwise a login redirect for a logged-out visitor unless the
	 * member has opted their profile public (AC-033 (e), AC-034). Any
	 * logged-in viewer may view any member's library regardless of that
	 * member's own visibility setting, per the Front-End Views table.
	 *
	 * @return void
	 */
	private function gate_member_library() {
		$nicename = sanitize_title( (string) get_query_var( 'gl_param' ) );
		$member   = '' !== $nicename ? get_user_by( 'slug', $nicename ) : false;

		if ( false === $member ) {
			$this->serve_404();

			return;
		}

		if ( is_user_logged_in() ) {
			return;
		}

		if ( Visibility::is_public( $member->ID ) ) {
			return;
		}

		$this->redirect_to_login();
	}

	/**
	 * Gates `/games/{slug}/`: 404 when the slug matches no cached game, or
	 * when it matches a game no active member's library currently references
	 * (AC-040).
	 *
	 * @return void
	 */
	private function gate_game_single() {
		$slug = sanitize_title( (string) get_query_var( 'gl_param' ) );
		$game = '' !== $slug ? $this->game_repository()->get_by_slug( $slug ) : null;

		if ( null === $game ) {
			$this->serve_404();

			return;
		}

		if ( 0 === $this->game_repository()->reference_count( $game['igdb_id'] ) ) {
			$this->serve_404();
		}
	}

	/**
	 * Builds the requested URL from `$wp->request` (never
	 * `$_SERVER['REQUEST_URI']`) and redirects to it via `wp_login_url()`.
	 *
	 * @return void
	 */
	private function redirect_to_login() {
		global $wp;

		// CO-7: add_query_arg( array(), $wp->request ) returns $wp->request
		// unchanged — the path with no query string — so a member following
		// a shared, filtered, or paginated link previously landed on page 1
		// unfiltered after logging in. Only this plugin's own already-
		// sanitized/validated params are re-attached (never
		// $_SERVER['REQUEST_URI'], which stays deliberately avoided) —
		// gl_page for every gl_page-paginated route, and status for the two
		// routes that carry one (/my-library/, /library/{nicename}/); on
		// every other route the raw $_GET['status'] read below is simply
		// absent, so this is a no-op there.
		$requested_url = home_url( user_trailingslashit( $wp->request ) );

		$page = absint( get_query_var( 'gl_page' ) );

		if ( $page > 1 ) {
			$requested_url = add_query_arg( 'gl_page', $page, $requested_url );
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter param, not a state-changing action; re-derives the same value templates/my-library.php's own $_GET['status'] read already validates.

		if ( Statuses::is_valid( $status ) ) {
			$requested_url = add_query_arg( 'status', $status, $requested_url );
		}

		wp_safe_redirect( wp_login_url( $requested_url ) );
		exit;
	}

	/**
	 * Marks the current request a 404 so the theme's own 404 template
	 * renders — never `wp_die()`, never a bare `exit`.
	 *
	 * @return void
	 */
	private function serve_404() {
		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
	}

	/**
	 * The memoized `Game_Repository` instance this class's game-route gate
	 * needs.
	 *
	 * @return Game_Repository
	 */
	private function game_repository() {
		if ( null === $this->game_repository ) {
			$this->game_repository = new Game_Repository();
		}

		return $this->game_repository;
	}
}
