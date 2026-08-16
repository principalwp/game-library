<?php
/**
 * Plugin Name:       Game Library
 * Plugin URI:        https://principalwp.com
 * Description:       Invite-only community for video-game collectors: IGDB-backed personal libraries, follows, and an activity feed.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.4
 * Author:            Principal WP
 * Author URI:        https://principalwp.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       game-library
 * Domain Path:       /languages
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

// Load VIP polyfills — no-op on VIP where platform functions already exist.
require_once __DIR__ . '/vip-polyfill.php';


/**
 * Plugin version — also the cache-busting fallback for assets that ship
 * without a build/{entry}.asset.php version hash.
 */
define( 'GAMELIB_VERSION', '1.0.0' );

/**
 * Schema version. Bumped whenever the custom-table DDL changes so the
 * idempotent upgrade routine knows it has work to do (D-REQ-4). Monotonic, as
 * this docblock has always said it is — which is why the derivation is written
 * out rather than the number being wound back:
 *
 * - `1` — the release DDL.
 * - `2` — added `KEY name (name(191))` to `gamelib_games`. It cannot serve the
 *   query it was added for, for two independent reasons; see
 *   principal/adr/032-title-sort-filesort-is-a-known-limitation.md (PB-1).
 * - `3` — the DDL of version 1 again, plus the one-shot `DROP INDEX` that takes
 *   the version-2 index back out (CO-4/PB-1). Removing it from the declaration
 *   was not enough on its own: `dbDelta()` only ever *adds* an index the
 *   declaration names and has no mechanism for dropping one, so every install
 *   that ran at version 2 kept maintaining a fourth secondary index on the
 *   plugin's most-written table while the schema reported itself as not having
 *   it. {@see GameLib_Schema::install()} carries the drop.
 */
define( 'GAMELIB_DB_VERSION', '3' );

/** Absolute path to this bootstrap file (register_activation_hook, plugin_basename). */
define( 'GAMELIB_PLUGIN_FILE', __FILE__ );

/** Absolute filesystem path to the plugin directory, trailing slash included. */
define( 'GAMELIB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** Public URL of the plugin directory, trailing slash included. */
define( 'GAMELIB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Freshness window for the shared IGDB game store. 24 hours per D-REQ-18 —
 * this supersedes the earlier 7-day directive and must not be re-litigated.
 */
define( 'GAMELIB_IGDB_CACHE_TTL', DAY_IN_SECONDS );

/** Lifetime of a normalized IGDB search-result transient (DD-015). */
define( 'GAMELIB_SEARCH_CACHE_TTL', 15 * MINUTE_IN_SECONDS );

/** Hard row cap for every import source — file and Steam alike (DD-012). */
define( 'GAMELIB_IMPORT_MAX_ROWS', 10000 );

/** Hard byte cap for uploaded import payloads (DD-012). */
define( 'GAMELIB_IMPORT_MAX_BYTES', 2 * MB_IN_BYTES );

/**
 * Serial IGDB requests one import cron tick may spend before rescheduling
 * (ADR-004).
 *
 * Lowered from 30 to 15 so the worst case — every request sitting out the 10s
 * background timeout — fits inside `GAMELIB_IMPORT_TICK_SECONDS` rather than
 * running 300s past it (PB-3). The two budgets and the row cap are checked
 * independently; whichever is reached first ends the slice, and the next tick
 * is chained.
 */
define( 'GAMELIB_IMPORT_CALLS_PER_TICK', 15 );

/**
 * Rows one import cron tick may bucket before rescheduling (PB-3).
 *
 * The request budget alone does not bound a tick: a chunk resolvable entirely
 * from local state — a re-imported export, an all-mapped Steam library — spends
 * zero requests, so a 10,000-row job would run all 400 chunks (tens of
 * thousands of queries, thousands of `wp_insert_post()` calls through
 * `gamelib_first_add`) in one PHP process and be killed before it could chain
 * the next tick.
 */
define( 'GAMELIB_IMPORT_ROWS_PER_TICK', 250 );

/**
 * Wall-clock budget for one import cron tick, in seconds (PB-3).
 *
 * The backstop the other two budgets cannot provide: neither a request count
 * nor a row count knows how long the work actually took. Checked at the top of
 * every chunk, so a tick overshoots by at most one chunk.
 */
define( 'GAMELIB_IMPORT_TICK_SECONDS', 20 );

/**
 * Wall-clock budget for one hourly refresh tick, in seconds (PB-2).
 *
 * The same 20 seconds the other three background jobs take
 * (`GAMELIB_IMPORT_TICK_SECONDS`, `GameLib_Library::BULK_TICK_SECONDS`,
 * `GameLib_Privacy::PURGE_TICK_SECONDS`), and for the same reason: the row cap
 * `GameLib_Refresh_Job::BATCH_SIZE` is not a bound on how long the work takes.
 * A batch of 500 rows whose titles have all drifted upstream is 500
 * `wp_update_post()` calls behind the upserts, each firing `save_post` and
 * clearing post caches, and nothing else in that tick looks at a clock.
 */
define( 'GAMELIB_REFRESH_TICK_SECONDS', 20 );

/**
 * Read a third-party API secret at call time.
 *
 * Resolution order, per AC-NFR-003: a PHP constant (local `wp-config.php`),
 * then `vip_get_env_var()` where the VIP platform provides it, then the raw
 * environment. The value is returned to the caller and nowhere else — this
 * helper never writes it to an option, transient, table, log line, or
 * response body, and callers must keep it out of markup and REST payloads.
 *
 * Only the three known secret names resolve; anything else returns an empty
 * string so this cannot be turned into a general-purpose environment reader.
 *
 * @param string $name Secret name: IGDB_CLIENT_ID, IGDB_CLIENT_SECRET, or STEAM_WEB_API_KEY.
 * @return string The secret, or '' when it is unset or the name is unknown.
 */
function gamelib_get_secret( $name ) {
	$known = array( 'IGDB_CLIENT_ID', 'IGDB_CLIENT_SECRET', 'STEAM_WEB_API_KEY' );

	if ( ! in_array( $name, $known, true ) ) {
		return '';
	}

	if ( defined( $name ) ) {
		$value = constant( $name );
	} elseif ( function_exists( 'vip_get_env_var' ) ) {
		$value = vip_get_env_var( $name, '' );
	} else {
		$value = getenv( $name );
	}

	return is_scalar( $value ) ? trim( (string) $value ) : '';
}

require_once GAMELIB_PLUGIN_DIR . 'includes/class-cache.php';
require_once GAMELIB_PLUGIN_DIR . 'includes/class-schema.php';
require_once GAMELIB_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'GameLib_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GameLib_Plugin', 'deactivate' ) );

GameLib_Plugin::instance()->boot();
