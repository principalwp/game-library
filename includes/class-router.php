<?php
/**
 * Front-end routing: rewrite rules, template dispatch, and access gates.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the five plugin routes end to end (DD-006).
 *
 * `/my-library/`, `/my-library/imports/{id}/`, `/activity/`,
 * `/members/{nicename}/`, and `/join/{code}/` are rewrite rules pointing at
 * plugin-owned **full-document** templates: no theme `header.php`/`footer.php`
 * is ever loaded, because this repository ships no theme and the plugin must
 * render identically under whatever theme a site happens to run (D-REQ-41,
 * C-REQ-11). The templates call `wp_head()`, `wp_body_open()`, `body_class()`,
 * and `wp_footer()`, so themes and plugins still get their hooks — verified on
 * the shared Playground under Twenty Twenty-Five: core styles, the admin bar,
 * and the emoji/global-styles inline output all render from a
 * `template_include` document.
 *
 * Three gates live here, and nowhere else:
 *
 * - **AC-021** — a logged-out visitor at `/my-library/`, an import-review
 *   screen, or `/activity/` is 302'd to `wp_login_url()` carrying the full
 *   requested URL in `redirect_to`.
 * - **DD-014 / AC-028(c,e)** — a denied profile reuses WordPress's own 404
 *   handling (`WP_Query::set_404()` + a 404 status + the active theme's 404
 *   template), so a members-only profile viewed logged-out is byte-identical
 *   to a member who does not exist. There is deliberately no plugin-owned
 *   "members only" screen to compare against.
 * - **AC-030** — the owner-only `?gamelib_preview=visitor` mode drops the
 *   viewer to id 0 for the whole request, so the member sees exactly what a
 *   logged-out visitor sees: the public profile, or the same 404. It is always
 *   `noindex` and never cached.
 *
 * The class is also the plugin's template loader ({@see GameLib_Router::part()}
 * / {@see GameLib_Router::render_part()}): REST routes render list fragments
 * through the same parts the first paint used, which is what keeps PHP the
 * single markup-and-escaping path (ADR-002).
 */
final class GameLib_Router {

	/**
	 * Query var carrying the matched route slug.
	 *
	 * @var string
	 */
	const QV_ROUTE = 'gamelib_route';

	/**
	 * Query var carrying the profile owner's `user_nicename`.
	 *
	 * @var string
	 */
	const QV_MEMBER = 'gamelib_member';

	/**
	 * Query var carrying the import job id for the review screen.
	 *
	 * @var string
	 */
	const QV_IMPORT = 'gamelib_import_id';

	/**
	 * Query var carrying the invite code being redeemed.
	 *
	 * @var string
	 */
	const QV_INVITE = 'gamelib_invite_code';

	/**
	 * Query var switching a profile into preview-as-visitor mode (AC-030).
	 *
	 * @var string
	 */
	const QV_PREVIEW = 'gamelib_preview';

	/**
	 * The only accepted value of {@see GameLib_Router::QV_PREVIEW}.
	 *
	 * @var string
	 */
	const PREVIEW_VISITOR = 'visitor';

	/**
	 * Route slug: the member's own library (AC-014).
	 *
	 * @var string
	 */
	const ROUTE_MY_LIBRARY = 'my-library';

	/**
	 * Route slug: the two-phase import review screen (AC-042).
	 *
	 * @var string
	 */
	const ROUTE_IMPORT_REVIEW = 'import-review';

	/**
	 * Route slug: the followed-members activity feed (AC-024).
	 *
	 * @var string
	 */
	const ROUTE_ACTIVITY = 'activity';

	/**
	 * Route slug: a member profile (AC-031).
	 *
	 * @var string
	 */
	const ROUTE_PROFILE = 'profile';

	/**
	 * Route slug: invite redemption / registration (AC-003).
	 *
	 * @var string
	 */
	const ROUTE_JOIN = 'join';

	/**
	 * Route slug => template file under `templates/`.
	 *
	 * Doubles as the whitelist of routes this class will dispatch: a
	 * `gamelib_route` value absent from this map is not a route.
	 *
	 * @var array<string, string>
	 */
	const TEMPLATES = array(
		self::ROUTE_MY_LIBRARY    => 'my-library.php',
		self::ROUTE_IMPORT_REVIEW => 'import-review.php',
		self::ROUTE_ACTIVITY      => 'activity.php',
		self::ROUTE_PROFILE       => 'profile.php',
		self::ROUTE_JOIN          => 'join.php',
	);

	/**
	 * Route slug => the request path that route is allowed to serve.
	 *
	 * Rewrite targets have to be *public* query vars (WordPress drops anything
	 * else from a matched rule), which means `?gamelib_route=my-library` on any
	 * URL would otherwise dispatch a plugin template over, say, the front page.
	 * Re-checking the path closes that: a route serves its own URL only.
	 *
	 * @var array<string, string>
	 */
	const ROUTE_PATHS = array(
		self::ROUTE_MY_LIBRARY    => '#^my-library$#',
		self::ROUTE_IMPORT_REVIEW => '#^my-library/imports/[0-9]+$#',
		self::ROUTE_ACTIVITY      => '#^activity$#',
		self::ROUTE_PROFILE       => '#^members/[^/]+$#',
		self::ROUTE_JOIN          => '#^join/[^/]+$#',
	);

	/**
	 * Routes that require an authenticated member (AC-021).
	 *
	 * @var string[]
	 */
	const MEMBER_ROUTES = array(
		self::ROUTE_MY_LIBRARY,
		self::ROUTE_IMPORT_REVIEW,
		self::ROUTE_ACTIVITY,
	);

	/**
	 * The route being served, '' when this request is not a plugin route or
	 * was denied.
	 *
	 * @var string
	 */
	private static $route = '';

	/**
	 * Profile owner for {@see GameLib_Router::ROUTE_PROFILE}.
	 *
	 * @var WP_User|null
	 */
	private static $member = null;

	/**
	 * Import job id for {@see GameLib_Router::ROUTE_IMPORT_REVIEW}.
	 *
	 * @var int
	 */
	private static $import_id = 0;

	/**
	 * Invite code for {@see GameLib_Router::ROUTE_JOIN}, raw from the URL.
	 *
	 * @var string
	 */
	private static $invite_code = '';

	/**
	 * Is this request an owner's preview-as-visitor (AC-030)?
	 *
	 * @var bool
	 */
	private static $preview = false;

	/**
	 * Did a gate deny this request and hand it to WordPress's 404 handling?
	 *
	 * @var bool
	 */
	private static $denied = false;

	/**
	 * Activation work: register the rules, then write them to the DB.
	 *
	 * Rewrite rules only reach the `rewrite_rules` option through a flush, and
	 * a flush is far too expensive to run per request — so it happens here, on
	 * activation, and nowhere else. `register_rewrites()` has to run first:
	 * activation fires after this request's `init` has already passed, so the
	 * plugin's own rules are not registered yet and a bare flush would persist
	 * a rule set without them.
	 *
	 * @return void
	 */
	public static function activate() {
		self::register_rewrites();

		/*
		 * Soft flush: regenerate and store `rewrite_rules`, never touch
		 * .htaccess — the plugin performs no filesystem writes (Never Do #3).
		 */
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Activation only, once per activation: the five plugin routes cannot resolve without one persisted rule set. Never called on init or any front-end request.
		flush_rewrite_rules( false );
	}

	/**
	 * Register the five route rules.
	 *
	 * Runs on `init` so the rules exist for rule *matching* on every request;
	 * persisting them is the activation flush's job. Order matters: the
	 * import-review rule is registered before the `/my-library/` rule so the
	 * more specific pattern is tested first.
	 *
	 * @return void
	 */
	public static function register_rewrites() {
		add_rewrite_rule(
			'^my-library/imports/([0-9]+)/?$',
			'index.php?' . self::QV_ROUTE . '=' . self::ROUTE_IMPORT_REVIEW . '&' . self::QV_IMPORT . '=$matches[1]',
			'top'
		);

		add_rewrite_rule(
			'^my-library/?$',
			'index.php?' . self::QV_ROUTE . '=' . self::ROUTE_MY_LIBRARY,
			'top'
		);

		add_rewrite_rule(
			'^activity/?$',
			'index.php?' . self::QV_ROUTE . '=' . self::ROUTE_ACTIVITY,
			'top'
		);

		add_rewrite_rule(
			'^members/([^/]+)/?$',
			'index.php?' . self::QV_ROUTE . '=' . self::ROUTE_PROFILE . '&' . self::QV_MEMBER . '=$matches[1]',
			'top'
		);

		add_rewrite_rule(
			'^join/([^/]+)/?$',
			'index.php?' . self::QV_ROUTE . '=' . self::ROUTE_JOIN . '&' . self::QV_INVITE . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Declare the plugin's query vars.
	 *
	 * @param string[] $vars Registered public query vars.
	 * @return string[] Filtered query vars.
	 */
	public static function filter_query_vars( $vars ) {
		$vars[] = self::QV_ROUTE;
		$vars[] = self::QV_MEMBER;
		$vars[] = self::QV_IMPORT;
		$vars[] = self::QV_INVITE;
		$vars[] = self::QV_PREVIEW;

		return $vars;
	}

	/**
	 * Skip the posts query on a plugin route.
	 *
	 * A route resolves to `index.php` with no post-ish query var, so WordPress
	 * would run the blog-home query and hydrate ten posts nothing renders.
	 * Returning an array short-circuits `WP_Query::get_posts()` — the filter
	 * core provides for exactly this.
	 *
	 * @param WP_Post[]|int[]|null $posts Posts, or null to run the query.
	 * @param WP_Query             $query Query being executed.
	 * @return WP_Post[]|int[]|null Empty array on a plugin route, input otherwise.
	 */
	public static function filter_posts_pre_query( $posts, $query ) {
		if ( null !== $posts || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return $posts;
		}

		if ( '' === self::route_from_query( $query ) ) {
			return $posts;
		}

		$query->found_posts   = 0;
		$query->max_num_pages = 0;

		return array();
	}

	/**
	 * Claim the response status for a plugin route.
	 *
	 * `WP::handle_404()` decides 200-vs-404 from the main query's post count,
	 * and a plugin route has no posts by design — so without this the routes
	 * would 404 on a site with an empty blog. Short-circuiting also lets us
	 * clear the `is_home` flag WordPress inferred from the postless query
	 * before `redirect_canonical`, `body_class()`, or the SEO layer read it.
	 *
	 * Runs before the access gates in {@see GameLib_Router::dispatch()}; a
	 * denial re-sets the status to 404 there, on the same request.
	 *
	 * @param bool     $short_circuit Whether to skip core's 404 handling.
	 * @param WP_Query $query         Main query.
	 * @return bool True to skip core's handling on a plugin route.
	 */
	public static function filter_pre_handle_404( $short_circuit, $query ) {
		if ( ! $query instanceof WP_Query || '' === self::route_from_query( $query ) ) {
			return $short_circuit;
		}

		$query->is_home    = false;
		$query->is_archive = false;
		$query->is_404     = false;

		status_header( 200 );

		return true;
	}

	/**
	 * Resolve the route, enforce its gates, and stash what templates need.
	 *
	 * Hooked to `template_redirect` because that is the last point before any
	 * output where a redirect is still legal — and after `handle_404()`, so a
	 * denial's `set_404()` is the final word on the status.
	 *
	 * @return void
	 */
	public static function dispatch() {
		$route = self::route_from_query( $GLOBALS['wp_query'] );

		if ( '' === $route ) {
			return;
		}

		self::$route = $route;

		if ( in_array( $route, self::MEMBER_ROUTES, true ) ) {
			self::gate_member_route();
		}

		if ( self::ROUTE_PROFILE === self::$route ) {
			self::gate_profile();
		}

		if ( self::ROUTE_IMPORT_REVIEW === self::$route ) {
			self::$import_id = absint( get_query_var( self::QV_IMPORT ) );

			if ( self::$import_id <= 0 ) {
				self::deny();
			}
		}

		if ( self::ROUTE_JOIN === self::$route ) {
			// Unknown, redeemed, and revoked codes all reach the template and
			// get one identical invalid message (AC-005); only the shape is
			// normalized here.
			self::$invite_code = sanitize_key( (string) get_query_var( self::QV_INVITE ) );
		}
	}

	/**
	 * Serve the plugin's template for the resolved route.
	 *
	 * A denied request returns the incoming `$template` untouched: WordPress
	 * has already resolved the active theme's 404 template from the `set_404()`
	 * performed during `template_redirect`, and reusing it verbatim is what
	 * makes the denial indistinguishable from a genuine 404 (DD-014).
	 *
	 * @param string $template Template WordPress resolved.
	 * @return string Template to load.
	 */
	public static function filter_template_include( $template ) {
		if ( self::$denied || '' === self::$route || ! isset( self::TEMPLATES[ self::$route ] ) ) {
			return $template;
		}

		$file = self::templates_dir() . self::TEMPLATES[ self::$route ];

		return file_exists( $file ) ? $file : $template;
	}

	/**
	 * The route being served this request.
	 *
	 * @return string One of the `ROUTE_*` constants, or '' off-route.
	 */
	public static function route() {
		return self::$route;
	}

	/**
	 * The profile owner on {@see GameLib_Router::ROUTE_PROFILE}.
	 *
	 * @return WP_User|null Owner, or null off-route.
	 */
	public static function member() {
		return self::$member;
	}

	/**
	 * The import job id on {@see GameLib_Router::ROUTE_IMPORT_REVIEW}.
	 *
	 * Ownership of that job is the import screen's gate, not the router's.
	 *
	 * @return int Import id, 0 off-route.
	 */
	public static function import_id() {
		return self::$import_id;
	}

	/**
	 * The invite code on {@see GameLib_Router::ROUTE_JOIN}.
	 *
	 * @return string Normalized code, '' off-route.
	 */
	public static function invite_code() {
		return self::$invite_code;
	}

	/**
	 * Is the owner previewing their profile as a logged-out visitor (AC-030)?
	 *
	 * @return bool True only for the owner, on their own profile, with the flag set.
	 */
	public static function is_visitor_preview() {
		return self::$preview;
	}

	/**
	 * The viewer identity every plugin surface must render for.
	 *
	 * Identical to `get_current_user_id()` except under preview-as-visitor,
	 * where it is 0 — the one place that substitution is made, so no surface
	 * has to remember the preview exists (AC-030).
	 *
	 * @return int Viewer user id, 0 for a logged-out (or previewed-as-visitor) request.
	 */
	public static function viewer_id() {
		return self::$preview ? 0 : get_current_user_id();
	}

	/**
	 * Absolute URL of a plugin route.
	 *
	 * The single composer for every route URL except the profile, which
	 * {@see GameLib_Visibility::profile_url()} already owns because the AC-029
	 * cache purge needs it without a route lookup.
	 *
	 * @param string     $route One of the `ROUTE_*` constants.
	 * @param string|int $arg   Import id or invite code, where the route takes one.
	 * @return string Absolute URL, or '' for an unknown route.
	 */
	public static function route_url( $route, $arg = '' ) {
		switch ( $route ) {
			case self::ROUTE_MY_LIBRARY:
				$path = 'my-library';
				break;

			case self::ROUTE_IMPORT_REVIEW:
				$path = 'my-library/imports/' . absint( $arg );
				break;

			case self::ROUTE_ACTIVITY:
				$path = 'activity';
				break;

			case self::ROUTE_JOIN:
				$path = 'join/' . rawurlencode( (string) $arg );
				break;

			default:
				return '';
		}

		return home_url( user_trailingslashit( $path ) );
	}

	/**
	 * Render a template part and return its markup.
	 *
	 * The REST layer's HTML fragments and the first paint go through this same
	 * call, so a card is escaped once, in one file (ADR-002).
	 *
	 * @param string               $name Part name under `templates/parts/`.
	 * @param array<string, mixed> $args Values the part documents.
	 * @return string Markup, '' when the part does not exist.
	 */
	public static function render_part( $name, array $args = array() ) {
		ob_start();
		self::part( $name, $args );

		return (string) ob_get_clean();
	}

	/**
	 * Output a template part.
	 *
	 * @param string               $name Part name under `templates/parts/`.
	 * @param array<string, mixed> $args Values the part documents; in scope as `$args`.
	 * @return void
	 */
	public static function part( $name, array $args = array() ) {
		$file = self::templates_dir() . 'parts/' . sanitize_file_name( (string) $name ) . '.php';

		if ( ! file_exists( $file ) ) {
			return;
		}

		require $file;
	}

	/**
	 * Deny the current request and hand it to WordPress's 404 handling.
	 *
	 * The shared denial path (DD-014): every gate in the plugin — profile
	 * visibility, a malformed import id, a logged-in non-member — ends here, so
	 * every denial produces one response, the active theme's 404, with no
	 * plugin-owned body to fingerprint (AC-028e).
	 *
	 * @return void
	 */
	public static function deny() {
		self::$route  = '';
		self::$member = null;
		self::$denied = true;

		$GLOBALS['wp_query']->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Enforce AC-021 on the three member-only routes.
	 *
	 * @return void
	 */
	private static function gate_member_route() {
		if ( ! is_user_logged_in() ) {
			nocache_headers();
			wp_safe_redirect( wp_login_url( self::current_url() ), 302 );
			exit;
		}

		if ( ! GameLib_Capabilities::is_member( get_current_user_id() ) ) {
			// Logged in without the plugin's capabilities: not a member, and a
			// login redirect would only loop them back here. Same 404 as every
			// other denial.
			self::deny();
		}
	}

	/**
	 * Resolve and gate `/members/{nicename}/` through the AC-028 matrix.
	 *
	 * @return void
	 */
	private static function gate_profile() {
		$nicename = sanitize_title( (string) get_query_var( self::QV_MEMBER ) );
		$user     = ( '' === $nicename ) ? false : get_user_by( 'slug', $nicename );
		$owner_id = ( $user instanceof WP_User ) ? (int) $user->ID : 0;

		self::$preview = self::preview_requested( $owner_id );

		if ( self::$preview ) {
			// Exactly what a logged-out visitor gets, and never indexed or
			// cached on the way there (AC-030).
			nocache_headers();
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		}

		if ( ! GameLib_Visibility::can_view_member( self::viewer_id(), $owner_id ) ) {
			self::deny();

			return;
		}

		self::$member = $user instanceof WP_User ? $user : null;
	}

	/**
	 * Is a valid preview-as-visitor request being made by the profile's owner?
	 *
	 * @param int $owner_id Profile owner id; 0 when the nicename resolved to nobody.
	 * @return bool True only when the current user owns the profile and asked for the preview.
	 */
	private static function preview_requested( $owner_id ) {
		if ( self::PREVIEW_VISITOR !== (string) get_query_var( self::QV_PREVIEW ) ) {
			return false;
		}

		$viewer_id = get_current_user_id();

		return $viewer_id > 0 && $owner_id > 0 && $viewer_id === $owner_id;
	}

	/**
	 * The route slug this request resolves to, validated against its own path.
	 *
	 * @param WP_Query|null $query Query to read the route var from.
	 * @return string Route slug, or '' when this is not a plugin route.
	 */
	private static function route_from_query( $query ) {
		if ( ! $query instanceof WP_Query ) {
			return '';
		}

		$route = (string) $query->get( self::QV_ROUTE );

		if ( ! isset( self::ROUTE_PATHS[ $route ] ) ) {
			return '';
		}

		return preg_match( self::ROUTE_PATHS[ $route ], self::request_path() ) ? $route : '';
	}

	/**
	 * The requested path, relative to the site root and without slashes.
	 *
	 * @return string Path such as `my-library/imports/12`.
	 */
	private static function request_path() {
		$wp = isset( $GLOBALS['wp'] ) ? $GLOBALS['wp'] : null;

		if ( ! $wp instanceof WP || ! is_string( $wp->request ) ) {
			return '';
		}

		return trim( $wp->request, '/' );
	}

	/**
	 * The full URL of the current request, for `redirect_to`.
	 *
	 * @return string Absolute URL including the query string.
	 */
	private static function current_url() {
		$url = home_url( user_trailingslashit( self::request_path() ) );

		$query = isset( $_SERVER['QUERY_STRING'] )
			? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) )
			: '';

		if ( '' !== $query ) {
			$url .= '?' . $query;
		}

		return esc_url_raw( $url );
	}

	/**
	 * Absolute path of the plugin's `templates/` directory, trailing slash included.
	 *
	 * @return string Directory path.
	 */
	private static function templates_dir() {
		return dirname( __DIR__ ) . '/templates/';
	}
}
