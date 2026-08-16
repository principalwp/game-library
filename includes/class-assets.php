<?php
/**
 * Conditional CSS/JS enqueue on plugin routes.
 *
 * @package Game_Library
 */

namespace Game_Library;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Assets.
 *
 * Enqueues `tokens.css` then `game-library.css` (DD-003/ADR-003, with
 * `tokens.css` as its dependency) plus the route-appropriate front-end
 * script, on the plugin's eight front-end routes only — never on a request
 * the plugin does not own.
 *
 * Query-var timing: this class hooks `wp_enqueue_scripts`, not `wp` or
 * `template_redirect`. WordPress resolves the main query — including every
 * custom query var a rewrite rule feeds through the `query_vars` filter,
 * such as `gl_route` — inside `WP::main()`'s call to `parse_request()`,
 * which core runs during the `wp()` bootstrap call in `wp-blog-header.php`,
 * long before `template-loader.php` fires `template_redirect`/
 * `template_include` or a located template calls `get_header()`.
 * `wp_enqueue_scripts` is core's own action, hooked onto `wp_head` at
 * priority 1 (`wp-includes/default-filters.php`: `add_action( 'wp_head',
 * 'wp_enqueue_scripts', 1 )`), which only fires once a template has been
 * selected and starts rendering — well after `parse_request()` has already
 * run. So `get_query_var( 'gl_route' )` is always already populated by the
 * time `enqueue()` below runs; no earlier hook or stashed value is needed.
 *
 * `gl_route` contract: `Router` owns the eight `Router::ROUTE_*` constants
 * (arch-pre-1 architecture review, finding AR-4 — `Router` is the single
 * writer of this query var and the class that owns the rewrite rules that
 * produce these values, so the constants live there even though `Assets`
 * defined this vocabulary first, back when `Router` did not exist yet) and
 * must set `gl_route` to one of them for the enqueue whitelist and the
 * per-route script map below to mean anything. `Router::ROUTE_LIBRARY` names
 * `/library/{nicename}/` (its template is `member-library.php`, distinct
 * from the slug, to avoid colliding with `Router::ROUTE_GAMES`) and
 * `Router::ROUTE_GAME` names `/games/{slug}/` (template `game-single.php`,
 * same reasoning against `Router::ROUTE_GAMES`).
 */
final class Assets {

	/**
	 * Every recognised value of the `gl_route` query var — the plugin's
	 * eight front-end routes (DD-008), keyed on `Router`'s own `ROUTE_*`
	 * constants (arch-pre-1 architecture review, finding AR-4). Any other
	 * value (including the unregistered empty string on a non-plugin page)
	 * enqueues nothing.
	 *
	 * @var string[]
	 */
	private const ROUTES = array(
		Router::ROUTE_MY_LIBRARY,
		Router::ROUTE_LIBRARY,
		Router::ROUTE_ACTIVITY,
		Router::ROUTE_MEMBERS,
		Router::ROUTE_INVITES,
		Router::ROUTE_JOIN,
		Router::ROUTE_GAMES,
		Router::ROUTE_GAME,
	);

	/**
	 * `gl_route` value => front-end script names required on that route.
	 * Each name resolves to the registered handle `game-library-{name}` and
	 * the file `assets/js/{name}.js` (Task 13/14). A route absent from this
	 * map (`Router::ROUTE_ACTIVITY`, `Router::ROUTE_JOIN`, `Router::ROUTE_GAME`)
	 * renders with no plugin script: `/activity/` and `/games/{slug}/` are
	 * read-only, and `/join/` is a plain form post-back.
	 *
	 * DES-50 / restyle §F.5 (human-approved): `game-detail.js` (the shared
	 * quick-look `<dialog>` behaviour, populated on a card-cover click) is
	 * added to `Router::ROUTE_MY_LIBRARY`, `Router::ROUTE_LIBRARY`, and —
	 * newly — `Router::ROUTE_GAMES`. Enabling it on `/games/` deliberately
	 * REVERSES this map's own prior "the catalog ships zero JS" choice, a
	 * documented departure flagged for and approved by the human, not a silent
	 * one. The cover-link in `game-card.php` is a real link to `/games/{slug}/`
	 * (progressive enhancement is structural), so the catalog still works with
	 * JS off — the script only upgrades the click to an in-place panel.
	 *
	 * PF-2 (cycle-3): `Router::ROUTE_LIBRARY` is the one route in this map
	 * that also serves logged-out visitors (AC-034) — every other mapped
	 * route is login-gated by `Router` and unreachable logged out.
	 * `social.js`'s only job on `/library/{nicename}/` is the
	 * `[data-gl-follow-toggle]` control, which the template renders only for
	 * a logged-in, non-self viewer; a logged-out visitor gets a plain "Log
	 * in to follow" link instead, so `social.js` binds a delegated listener
	 * that can never match anything. `enqueue()` drops just `social` for a
	 * logged-out `/library/` visitor (see below) — but KEEPS `game-detail`,
	 * whose quick-look works for anyone, matching `/games/`'s public-JS
	 * decision. A future route added to this map should get the same
	 * consideration if it also serves logged-out visitors.
	 *
	 * @var array<string,string[]>
	 */
	private const ROUTE_SCRIPTS = array(
		Router::ROUTE_MY_LIBRARY => array( 'search', 'library', 'game-detail' ),
		Router::ROUTE_LIBRARY    => array( 'social', 'game-detail' ),
		Router::ROUTE_MEMBERS    => array( 'social' ),
		Router::ROUTE_INVITES    => array( 'invites' ),
		Router::ROUTE_GAMES      => array( 'game-detail' ),
	);

	/**
	 * Routes that render at least one IGDB cover image (PF-4) — every one
	 * of these renders its LCP element from `https://images.igdb.com`, and
	 * the browser cannot start DNS+TCP+TLS for that origin until it parses
	 * the first `<img>` unless a `preconnect` hint gets a head start.
	 *
	 * PF-1 (cycle-3): the hint is emitted as a bare URL, giving the browser
	 * only DNS+TCP+TLS, not `crossorigin`. Every image on these routes is a
	 * plain `<img src>` — a no-CORS, credentialed fetch — but `crossorigin`
	 * puts the preconnected socket in the browser's ANONYMOUS
	 * (credentials-omitted) pool, which a credentialed image request can
	 * never reuse; the two pools are keyed separately. A `crossorigin` hint
	 * here was a net regression versus none at all (the browser opens the
	 * anonymous connection the hint warms AND a second credentialed one). If
	 * a future surface fetches an IGDB image in CORS mode, it needs its own
	 * second, `crossorigin` hint — never a replacement for this one.
	 *
	 * @var string[]
	 */
	private const COVER_ROUTES = array(
		Router::ROUTE_MY_LIBRARY,
		Router::ROUTE_LIBRARY,
		Router::ROUTE_ACTIVITY,
		Router::ROUTE_GAMES,
		Router::ROUTE_GAME,
	);

	/**
	 * Routes that render at least one `get_avatar()` image (PF-4) — same
	 * reasoning as `COVER_ROUTES`, including the PF-1 bare-URL correction
	 * above (Gravatar avatars are likewise plain `<img src>`, no-CORS,
	 * credentialed fetches).
	 *
	 * @var string[]
	 */
	private const AVATAR_ROUTES = array(
		Router::ROUTE_LIBRARY,
		Router::ROUTE_MEMBERS,
		Router::ROUTE_ACTIVITY,
		Router::ROUTE_GAME,
	);

	/**
	 * Registers this class's hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 10 );
		add_filter( 'wp_resource_hints', array( $this, 'resource_hints' ), 10, 2 );
	}

	/**
	 * Enqueues the token/component stylesheets and the route-appropriate
	 * script(s) when the current request resolves to one of the plugin's
	 * own routes.
	 *
	 * @return void
	 */
	public function enqueue() {
		$route = get_query_var( 'gl_route' );

		if ( ! is_string( $route ) || ! in_array( $route, self::ROUTES, true ) ) {
			return;
		}

		$base_url = trailingslashit( plugin_dir_url( GAME_LIBRARY_PLUGIN_FILE ) );

		// DES-50 (restyle §D): fonts.css declares the seven self-hosted
		// @font-face rules the --gl-font-family-* tokens name, so it heads the
		// sequential-dependency chain (fonts -> tokens -> component sheet) —
		// the faces must be declared before tokens.css references their family
		// names and before game-library.css consumes them.
		wp_enqueue_style( 'game-library-fonts', $base_url . 'assets/css/fonts.css', array(), GAME_LIBRARY_VERSION );
		wp_enqueue_style( 'game-library-tokens', $base_url . 'assets/css/tokens.css', array( 'game-library-fonts' ), GAME_LIBRARY_VERSION );
		wp_enqueue_style( 'game-library', $base_url . 'assets/css/game-library.css', array( 'game-library-tokens' ), GAME_LIBRARY_VERSION );

		// Header/footer redesign task: site-header.php's Menu toggle renders on
		// every one of these routes (it is part of the shared chrome, not a
		// route-specific feature), so it is enqueued here unconditionally
		// rather than through the per-route ROUTE_SCRIPTS map below. No REST
		// call, no wp-api-fetch dependency.
		wp_enqueue_script(
			'game-library-site-header',
			$base_url . 'assets/js/site-header.js',
			array(),
			GAME_LIBRARY_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		$scripts = isset( self::ROUTE_SCRIPTS[ $route ] ) ? self::ROUTE_SCRIPTS[ $route ] : array();

		// PF-2: /library/{nicename}/ is the one mapped route that also
		// serves logged-out visitors — see ROUTE_SCRIPTS's own docblock.
		// is_user_logged_in() is safe here: auth cookies are already parsed
		// by plugins_loaded, well before wp_enqueue_scripts. Drop only
		// `social` (its follow control never renders for a logged-out
		// viewer) — `game-detail`'s quick-look works for anyone, so it stays,
		// matching /games/'s own public-JS decision (restyle §F.5).
		if ( Router::ROUTE_LIBRARY === $route && ! is_user_logged_in() ) {
			$scripts = array_values( array_diff( $scripts, array( 'social' ) ) );
		}

		foreach ( $scripts as $script ) {
			$handle = 'game-library-' . $script;

			wp_enqueue_script(
				$handle,
				$base_url . 'assets/js/' . $script . '.js',
				array( 'wp-api-fetch' ),
				GAME_LIBRARY_VERSION,
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);

			// CO-2: every route script makes wp.i18n.__()/_n() calls with
			// this text domain, but without a registered JSON translation
			// source every one of those strings rendered in English on a
			// translated site regardless of the installed language pack —
			// unlike the PHP half, which already calls
			// load_plugin_textdomain(). wp_set_script_translations() adds
			// wp-i18n to the handle's dependencies itself.
			wp_set_script_translations( $handle, 'game-library', plugin_dir_path( GAME_LIBRARY_PLUGIN_FILE ) . 'languages' );
		}

		if ( ! empty( $scripts ) ) {
			$this->localize_scripts();
		}
	}

	/**
	 * Adds a `preconnect` resource hint for the third-party origin(s) the
	 * current route's covers/avatars come from (PF-4) — printed by
	 * `wp_head`, no manual markup. Returns `$urls` unchanged for every
	 * relation type but `preconnect` and every request that is not one of
	 * this plugin's own routes.
	 *
	 * @param string[]|array<int,array<string,mixed>> $urls          Existing hint URLs/specs.
	 * @param string                                    $relation_type The relation currently being filtered.
	 * @return string[]|array<int,array<string,mixed>>
	 */
	public function resource_hints( $urls, $relation_type ) {
		if ( 'preconnect' !== $relation_type ) {
			return $urls;
		}

		$route = get_query_var( 'gl_route' );

		if ( ! is_string( $route ) || ! in_array( $route, self::ROUTES, true ) ) {
			return $urls;
		}

		if ( in_array( $route, self::COVER_ROUTES, true ) ) {
			$urls[] = 'https://images.igdb.com';
		}

		if ( in_array( $route, self::AVATAR_ROUTES, true ) ) {
			$urls[] = 'https://secure.gravatar.com';
		}

		return $urls;
	}

	/**
	 * Exposes the plugin's REST namespace and translated status labels as
	 * `window.gameLibraryData`, via `wp_add_inline_script()` attached to the
	 * always-registered core `wp-api-fetch` handle. Every route script
	 * declares `wp-api-fetch` as a dependency, so this inline script always
	 * prints ahead of whichever route script(s) this request enqueued.
	 *
	 * PF-3 (cycle-3): this used to also attach its own
	 * `createRootURLMiddleware()`/`createNonceMiddleware()` pair to
	 * `wp-api-fetch` — core's own `wp_default_packages_inline_scripts()`
	 * already attaches both to that handle (verified at runtime: both
	 * blocks printed inside the same `wp-api-fetch-js-after` script, with an
	 * identical nonce value), so every `wp.apiFetch()` call this plugin made
	 * ran both middlewares twice. Removed rather than deliberately kept as
	 * belt-and-braces — a site that dequeues core's own inline script has
	 * bigger problems than a missing middleware, and this codebase does not
	 * do so.
	 *
	 * @return void
	 */
	private function localize_scripts() {
		wp_add_inline_script(
			'wp-api-fetch',
			'window.gameLibraryData = ' . wp_json_encode(
				array(
					'restNamespace' => 'game-library/v1',
					'statusLabels'  => $this->status_labels(),
				)
			) . ';'
		);
	}

	/**
	 * The translated label for every recognised status, keyed by status
	 * value, for the front-end status selector and search-result options
	 * (AC-015).
	 *
	 * @return array<string,string>
	 */
	private function status_labels() {
		$labels = array();

		foreach ( Statuses::all() as $status ) {
			$labels[ $status ] = Statuses::label( $status );
		}

		return $labels;
	}
}
