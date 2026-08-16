<?php
/**
 * `wp_robots` noindex/nofollow policy and canonical link.
 *
 * @package Game_Library
 */

namespace Game_Library\Seo;

use Game_Library\Router;
use Game_Library\Settings;
use Game_Library\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Robots.
 *
 * Marks every private plugin route `noindex, nofollow` (AC-043 (a)-(g)) and
 * leaves the public catalog/game/opted-public-library routes indexable
 * unless the `catalog_indexable` kill switch is off, in which case those
 * routes fall back to `noindex, nofollow` too while continuing to serve
 * HTTP 200 (AC-045). Also emits a self-referencing `<link rel="canonical">`
 * for every recognised plugin route (AC-042).
 *
 * Verified at runtime against the shared Playground instance (port 9401,
 * this task's own "Verify before implementing" step): the default
 * `wp_robots` filter chain is never empty (core's own
 * `wp_robots_max_image_preview_large()` always adds
 * `max-image-preview:large` on an indexable request) — but on an *empty*
 * array `wp_robots()` (hooked `wp_head` priority 1 in core) prints no
 * `<meta name="robots">` tag at all rather than falling back to a safe
 * default. A private route must therefore actively add `noindex`/`nofollow`
 * keys itself; nothing else does it. Also confirmed: `wp_head()` (and
 * therefore the `wp_robots`/`wp_head` hooks) never fires on a `wp-admin`
 * screen — a default admin.php request for the plugin's own settings screen
 * carries no robots meta tag of any kind — so AC-043(g)'s admin-screen
 * requirement is satisfied via a separate `admin_head` hook below, not by
 * `wp_robots`/`wp_head`.
 *
 * `catalog_indexable` is read once per request (memoized via `settings()`)
 * — never a second `get_option()` call — and consulted by both
 * `route_requires_noindex()` (the robots policy) and `canonical_url()` (the
 * canonical emitter), which calls the same method rather than re-deriving
 * indexability independently.
 */
final class Robots {

	/**
	 * `gl_route` values that are always private, regardless of the
	 * `catalog_indexable` setting (AC-043 (a)-(e)), keyed on `Router`'s own
	 * `ROUTE_*` constants (arch-pre-1 architecture review, finding AR-4).
	 *
	 * @var string[]
	 */
	private const ALWAYS_NOINDEX_ROUTES = array( Router::ROUTE_MY_LIBRARY, Router::ROUTE_ACTIVITY, Router::ROUTE_MEMBERS, Router::ROUTE_INVITES, Router::ROUTE_JOIN );

	/**
	 * `gl_route` values whose indexability is gated only by
	 * `catalog_indexable` (AC-043 (h)-(i), AC-045 (a)-(b)) —
	 * `Router::ROUTE_LIBRARY` is handled separately because it also depends
	 * on the member's own `_gl_profile_public` opt-in.
	 *
	 * @var string[]
	 */
	private const CATALOG_GATED_ROUTES = array( Router::ROUTE_GAMES, Router::ROUTE_GAME );

	/**
	 * Prefix every one of this plugin's own admin page slugs starts with
	 * (`game-library`, `game-library-invites`, and any future submenu) —
	 * AC-043(g).
	 *
	 * @var string
	 */
	private const ADMIN_SLUG_PREFIX = 'game-library';

	/**
	 * Memoized `game_library_settings` option, read at most once per request.
	 *
	 * @var array<string,mixed>|null
	 */
	private $settings;

	/**
	 * Registers this class's hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'wp_robots', array( $this, 'filter_robots' ), 10 );
		// CF-PF-3 (cycle-5): priority 20, not 1 — same reasoning as
		// Schema_Org::register_hooks()'s identical change, applied here for
		// the canonical link tag; core's wp_resource_hints (2) and
		// wp_print_styles (8) both run on this same wp_head action first now.
		add_action( 'wp_head', array( $this, 'render_canonical' ), 20 );
		add_action( 'admin_head', array( $this, 'render_admin_noindex' ), 1 );
	}

	/**
	 * Adds `noindex`/`nofollow` to the `wp_robots` array for every private
	 * plugin route, and for a public/opted-public one when
	 * `catalog_indexable` is off (AC-043, AC-045 (a)-(c)).
	 *
	 * @param array<string,bool|string> $robots Robots directives collected so far.
	 * @return array<string,bool|string>
	 */
	public function filter_robots( array $robots ) {
		$route = $this->current_route();

		if ( null === $route || ! $this->route_requires_noindex( $route ) ) {
			return $robots;
		}

		$robots['noindex']  = true;
		$robots['nofollow'] = true;

		return $robots;
	}

	/**
	 * Emits `<link rel="canonical">` on `wp_head` for the current request's
	 * plugin route, pointing at its own absolute permalink — including the
	 * current page on a paginated view (AC-042).
	 *
	 * @return void
	 */
	public function render_canonical() {
		$canonical = $this->canonical_url();

		if ( null === $canonical ) {
			return;
		}

		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
	}

	/**
	 * Emits a `noindex, nofollow` robots meta tag on the plugin's own admin
	 * screens (AC-043(g)) — `wp_head`/`wp_robots` never fire in `wp-admin`
	 * (confirmed at runtime, see the class docblock), so this is a separate,
	 * always-on hook rather than a branch inside `filter_robots()`.
	 *
	 * @return void
	 */
	public function render_admin_noindex() {
		if ( ! $this->is_own_admin_screen() ) {
			return;
		}

		echo "<meta name='robots' content='noindex, nofollow' />\n";
	}

	/**
	 * Whether a `gl_route` value requires `noindex, nofollow` right now.
	 *
	 * @param string $route Recognised `gl_route` value.
	 * @return bool
	 */
	private function route_requires_noindex( $route ) {
		if ( in_array( $route, self::ALWAYS_NOINDEX_ROUTES, true ) ) {
			return true;
		}

		if ( in_array( $route, self::CATALOG_GATED_ROUTES, true ) ) {
			return ! $this->catalog_indexable();
		}

		if ( Router::ROUTE_LIBRARY === $route ) {
			return ! $this->catalog_indexable() || ! $this->is_opted_public_library();
		}

		return false;
	}

	/**
	 * The absolute canonical URL for the current request's recognised plugin
	 * route, or null on a non-plugin request, or on a route currently marked
	 * `noindex` (this shares `route_requires_noindex()`'s classification —
	 * and therefore the same memoized `catalog_indexable()` read — rather
	 * than a second, independent decision, so a page pulled from the index
	 * by the kill switch does not simultaneously declare itself canonical).
	 * Built from `$wp->request` (the matched rewrite path, never
	 * `$_SERVER['REQUEST_URI']`), trailing-slashed to match the site's own
	 * permalink structure — so a request to `/games/page/2/` canonicalizes to
	 * exactly that URL, not back to `/games/` (AC-042).
	 *
	 * @return string|null
	 */
	private function canonical_url() {
		global $wp;

		// CO-1: Router::serve_404() sets $wp_query->set_404() for an
		// out-of-range/unreferenced-game request but leaves gl_route set to
		// its original value, so a 404 response must not declare itself the
		// canonical URL for that path.
		if ( is_404() ) {
			return null;
		}

		$route = $this->current_route();

		if ( null === $route || $this->route_requires_noindex( $route ) ) {
			return null;
		}

		$canonical = home_url( user_trailingslashit( $wp->request ) );

		// CO-6: $wp->request is the matched path only, with no query
		// string. ROUTE_GAMES's own page 2+ is a path-based rewrite rule
		// (/games/page/2/), so its gl_page value is already part of
		// $wp->request above and appending it again here would produce a
		// wrong, doubled ?gl_page= on an already-correct canonical.
		// ROUTE_GAME's and ROUTE_LIBRARY's holder-list/library pagination
		// is query-string only (?gl_page=2 on /games/{slug}/ or an
		// opted-public /library/{nicename}/) and was not represented in
		// $wp->request at all — that canonical previously always pointed
		// at page 1 regardless of the requested page, so a crawler
		// consolidated page 2's list into page 1 and never indexed it.
		if ( Router::ROUTE_GAMES === $route ) {
			return $canonical;
		}

		$page = absint( get_query_var( 'gl_page' ) );

		return $page > 1 ? add_query_arg( 'gl_page', $page, $canonical ) : $canonical;
	}

	/**
	 * The current request's `gl_route` value, or null when this is not a
	 * recognised plugin route.
	 *
	 * @return string|null
	 */
	private function current_route() {
		$route = get_query_var( 'gl_route' );

		if ( ! is_string( $route ) || '' === $route ) {
			return null;
		}

		$recognised = array_merge( self::ALWAYS_NOINDEX_ROUTES, self::CATALOG_GATED_ROUTES, array( Router::ROUTE_LIBRARY ) );

		return in_array( $route, $recognised, true ) ? $route : null;
	}

	/**
	 * Whether the current `/library/{nicename}/` request resolves to a
	 * member who has opted their profile public.
	 *
	 * @return bool
	 */
	private function is_opted_public_library() {
		$nicename = sanitize_title( (string) get_query_var( 'gl_param' ) );
		$member   = '' !== $nicename ? get_user_by( 'slug', $nicename ) : false;

		if ( false === $member ) {
			return false;
		}

		return Visibility::is_public( $member->ID );
	}

	/**
	 * Whether the current admin request is for one of this plugin's own
	 * screens.
	 *
	 * @return bool
	 */
	private function is_own_admin_screen() {
		if ( ! is_admin() ) {
			return false;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen identification, not a state-changing action.

		return '' !== $page && 0 === strpos( $page, self::ADMIN_SLUG_PREFIX );
	}

	/**
	 * Whether the public catalog/opted-public-library surfaces are currently
	 * indexable (AC-045). Memoized so `game_library_settings` is read at
	 * most once per request regardless of how many times this or
	 * `canonical_url()`'s shared route classification is consulted.
	 *
	 * `Settings::all()` (arch-pre-1 architecture review, finding AR-1) always
	 * backfills `catalog_indexable` to its documented default of `true` when
	 * the stored option row omits it, so a plain boolean cast here preserves
	 * the exact "absent means indexable" semantics this method always had.
	 *
	 * @return bool
	 */
	private function catalog_indexable() {
		$settings = $this->settings();

		return (bool) $settings['catalog_indexable'];
	}

	/**
	 * Reads `game_library_settings`, memoized for the lifetime of this
	 * instance.
	 *
	 * @return array<string,mixed>
	 */
	private function settings() {
		if ( null === $this->settings ) {
			$this->settings = Settings::all();
		}

		return $this->settings;
	}
}
