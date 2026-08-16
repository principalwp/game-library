<?php
/**
 * Route-gated enqueue of the compiled front-end bundles.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads exactly one compiled bundle per request, and only where its controls
 * exist (ADR-002).
 *
 * Four entries are built from `src/js/` into `build/` by `npm run build`: the
 * three behaviour bundles `my-library`, `import-review` and `public`, and
 * `style`, which is nothing but the `src/scss/main.scss` import. Registration
 * always reads the generated dependency array and content-hash version from the
 * entry's `{entry}.asset.php` and always points at `build/`, never `src/` — a
 * hand-written dependency list or a version pinned to `GAMELIB_VERSION` would go
 * stale the first time an entry gains an import.
 *
 * The base layer is **one** handle on every plugin surface (PF-2). It used to be
 * imported by each of the three bundles, which made wp-scripts emit three
 * byte-identical stylesheets under three URLs — so a member who visited a public
 * profile and then `/my-library/` paid a second render-blocking ~16.8 KB /
 * ~2.7 KB gz `<link>` in `<head>` for bytes the browser already had, and a third
 * on the import-review route. One handle means the second and third routes in a
 * session hit the HTTP cache.
 *
 * No request ever loads two behaviour bundles.
 *
 * The REST nonce and route context travel in one inline `before` script rather
 * than through `wp_localize_script()`, which HTML-escapes its values — the
 * payload is JSON transport, not markup, and must not be entity-encoded
 * (Never Do #14).
 */
final class GameLib_Assets {

	/**
	 * Prefix for every script and style handle this class registers.
	 *
	 * @var string
	 */
	const HANDLE_PREFIX = 'gamelib-';

	/**
	 * Global the inline bootstrap payload is published as.
	 *
	 * @var string
	 */
	const DATA_OBJECT = 'gameLibrarySettings';

	/**
	 * Compiled entry for the member's library surface.
	 *
	 * @var string
	 */
	const ENTRY_MY_LIBRARY = 'my-library';

	/**
	 * Compiled entry for the import review screen.
	 *
	 * @var string
	 */
	const ENTRY_IMPORT_REVIEW = 'import-review';

	/**
	 * Compiled entry for every publicly reachable surface: profiles, the
	 * activity feed, `/join/`, and game pages.
	 *
	 * @var string
	 */
	const ENTRY_PUBLIC = 'public';

	/**
	 * Compiled stylesheet-only entry: the whole base layer, shared by every
	 * plugin surface and registered once (PF-2).
	 *
	 * @var string
	 */
	const ENTRY_STYLE = 'style';

	/**
	 * Post type of the read-only game projection (ADR-003, registered in
	 * `GameLib_Game_CPT`). Named here as a literal so asset loading does not
	 * depend on that class having been loaded.
	 *
	 * @var string
	 */
	const GAME_POST_TYPE = 'glib_game';

	/**
	 * Enqueue the bundle this request needs, if any.
	 *
	 * @return void
	 */
	public static function enqueue() {
		$entry = self::entry_for_request();

		if ( '' === $entry ) {
			return;
		}

		self::enqueue_style_layer();
		self::enqueue_entry( $entry );
	}

	/**
	 * Which compiled entry belongs to this request.
	 *
	 * @return string Entry name, or '' when no plugin surface is being rendered.
	 */
	public static function entry_for_request() {
		switch ( GameLib_Router::route() ) {
			case GameLib_Router::ROUTE_MY_LIBRARY:
				return self::ENTRY_MY_LIBRARY;

			case GameLib_Router::ROUTE_IMPORT_REVIEW:
				return self::ENTRY_IMPORT_REVIEW;

			case GameLib_Router::ROUTE_PROFILE:
			case GameLib_Router::ROUTE_ACTIVITY:
			case GameLib_Router::ROUTE_JOIN:
				return self::ENTRY_PUBLIC;
		}

		return is_singular( self::GAME_POST_TYPE ) ? self::ENTRY_PUBLIC : '';
	}

	/**
	 * Enqueue the shared base stylesheet (PF-2).
	 *
	 * One handle and one URL for every plugin surface, so the second and third
	 * routes a member visits in a session reuse the bytes the first one fetched
	 * instead of blocking render on a fresh `<link>` carrying identical CSS.
	 *
	 * @return void
	 */
	private static function enqueue_style_layer() {
		$handle = self::HANDLE_PREFIX . self::ENTRY_STYLE;

		wp_enqueue_style(
			$handle,
			plugins_url( 'build/' . self::ENTRY_STYLE . '.css', GAMELIB_PLUGIN_FILE ),
			array(),
			self::asset_manifest( self::ENTRY_STYLE )['version']
		);

		// wp-scripts emits build/style-rtl.css alongside the LTR stylesheet.
		wp_style_add_data( $handle, 'rtl', 'replace' );
	}

	/**
	 * Register and enqueue one entry's script and bootstrap data.
	 *
	 * The stylesheet is not this method's business: the base layer is a separate
	 * entry with a handle of its own (PF-2), and the behaviour bundles emit no
	 * CSS at all.
	 *
	 * @param string $entry Entry name.
	 * @return void
	 */
	private static function enqueue_entry( $entry ) {
		$handle = self::HANDLE_PREFIX . $entry;
		$asset  = self::asset_manifest( $entry );

		wp_enqueue_script(
			$handle,
			plugins_url( 'build/' . $entry . '.js', GAMELIB_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			/*
			 * `strategy` travels in the same args array WP 6.3+ accepts
			 * (PF-6). Without it these are parser-blocking classic scripts
			 * sitting after ~650 elements of markup, delaying
			 * DOMContentLoaded — for bundles that are pure progressive
			 * enhancement by design (ADR-002).
			 *
			 * `defer` rather than `async`: the bundles must run after the
			 * document they bind to is parsed, and in the order they were
			 * enqueued. The `before` inline data below stays correct either
			 * way — core emits it as its own non-deferred tag, so
			 * `window.gameLibrarySettings` is defined before the bundle runs.
			 */
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			$handle,
			'window.' . self::DATA_OBJECT . ' = ' . wp_json_encode( self::bootstrap_data() ) . ';',
			'before'
		);
	}

	/**
	 * Dependency array and version for a compiled entry.
	 *
	 * @param string $entry Entry name.
	 * @return array{dependencies: string[], version: string} Manifest values.
	 */
	private static function asset_manifest( $entry ) {
		$file     = dirname( __DIR__ ) . '/build/' . $entry . '.asset.php';
		$manifest = file_exists( $file ) ? require $file : array();

		return array(
			'dependencies' => isset( $manifest['dependencies'] ) && is_array( $manifest['dependencies'] )
				? $manifest['dependencies']
				: array(),
			'version'      => isset( $manifest['version'] ) && is_string( $manifest['version'] )
				? $manifest['version']
				: GAMELIB_VERSION,
		);
	}

	/**
	 * The values every bundle needs before it can talk to the REST API.
	 *
	 * Nothing secret goes in here: it is inline markup on a public page
	 * (AC-NFR-003). The nonce is the standard cookie-auth `wp_rest` nonce; the
	 * separate `gamelib_bulk` nonce is rendered by the control that uses it.
	 *
	 * @return array<string, mixed> Bootstrap payload.
	 */
	private static function bootstrap_data() {
		return array(
			'restUrl' => esc_url_raw( rest_url( 'gamelib/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'route'   => GameLib_Router::route(),
		);
	}
}
