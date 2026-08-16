<?php
/**
 * Activation and deactivation lifecycle.
 *
 * @package Game_Library
 */

namespace Game_Library;

use Game_Library\Data\Option_Lock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Activator.
 *
 * Runs install/upgrade on activation, creates the database schema, seeds
 * capabilities and the default settings option, registers rewrite rules then
 * flushes them, and schedules the daily maintenance event. Deactivation
 * unschedules the event, removes the plugin capabilities, and flushes
 * rewrite rules — it never touches a table, an option, a row, or user meta
 * (AC-001 (g)).
 */
final class Activator {

	/**
	 * The `wp_options` row `maybe_upgrade()`'s single-flight lock uses
	 * (VIP-2/PB-8). Not autoloaded — see that method's own docblock.
	 *
	 * @var string
	 */
	private const UPGRADE_LOCK_OPTION = 'gl_upgrade_lock';

	/**
	 * Age, in seconds, past which `maybe_upgrade()`'s lock is treated as
	 * abandoned by a request that crashed mid-routine rather than still
	 * legitimately held (PB-8/PB-4).
	 *
	 * @var int
	 */
	private const UPGRADE_LOCK_STALE_AFTER = 5 * MINUTE_IN_SECONDS;

	/**
	 * The schema-bookkeeping option this class owns — the installed schema
	 * version, compared against `GAME_LIBRARY_VERSION` by `maybe_upgrade()`.
	 * `uninstall.php` reads this constant too (arch-pre-4 architecture
	 * review, finding AR-2): unlike `game_library_settings`
	 * (`Settings::OPTION_KEY`), this option previously had no owning
	 * constant, only four bare-literal call sites, one of them in a file
	 * (`uninstall.php`) that structurally cannot see any constant added later
	 * without an explicit `require_once` — see uninstall.php's own docblock.
	 *
	 * @var string
	 */
	public const DB_VERSION_OPTION = 'game_library_db_version';

	/**
	 * Fires on `register_activation_hook()`.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install_or_upgrade();
		self::schedule_events();

		// `register_activation_hook()` fires only after `init` has already
		// run for this request: WordPress completes its normal init phase
		// (this plugin not yet in the active list) before
		// `wp-admin/plugins.php` includes the plugin file and fires the
		// activation hook. `Plugin`'s constructor (executed by that include)
		// registers an `init` callback, but `init` itself will not fire
		// again this request. Call the callback directly, synchronously,
		// before flushing — otherwise the flush writes an empty rule set and
		// every plugin route 404s until an administrator re-saves permalinks
		// (AC-001 (c)). This also keeps activation forward-compatible with
		// the rewrite rules a later task registers inside
		// `Plugin::on_init()` (Task 10's `Router`), with no further change
		// needed here.
		Plugin::instance()->on_init();

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- required by AC-001 (c): flushed only here, on activation, never on a normal request.
		flush_rewrite_rules();
	}

	/**
	 * Fires on `register_deactivation_hook()`.
	 *
	 * Removes only what the plugin added on activation — its rewrite rules
	 * and daily event and the capabilities it seeded. No table, option, row,
	 * or user meta is touched (AC-001 (e)-(g)).
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::unschedule_events();
		Roles::remove_capabilities();
		self::strip_own_rewrite_rules_added_this_request();

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- required by AC-001 (e): flushed only here, on deactivation, never on a normal request.
		flush_rewrite_rules();
	}

	/**
	 * Strips this plugin's own rewrite rules from the current request's
	 * in-memory `$wp_rewrite` before `flush_rewrite_rules()` regenerates and
	 * persists the `rewrite_rules` option (AC-001 (e)).
	 *
	 * Both deactivation paths — the classic wp-admin `plugins.php` flow and a
	 * REST `PUT /wp/v2/plugins/{plugin}` request — dispatch through
	 * `deactivate_plugins()` well after this same request's `init` action has
	 * already fired. `Plugin::on_init()` calls `Router::register_routes()`
	 * unconditionally on every request, which calls `add_rewrite_rule( …,
	 * 'top' )` for all ten of this plugin's routes, populating
	 * `$wp_rewrite->extra_rules_top` in memory before this deactivation
	 * method ever runs. `WP_Rewrite::rewrite_rules()`
	 * (`wp-includes/class-wp-rewrite.php`) merges `extra_rules_top` verbatim
	 * into the rule set it builds and persists — confirmed by reading
	 * WordPress core — so a flush at this point would otherwise re-persist
	 * exactly the routes this deactivation is meant to remove, leaving
	 * `/my-library/` (and every other plugin route) still resolving after
	 * deactivation. This is not REST-specific: the classic wp-admin flow also
	 * runs `init` before dispatching the deactivation hook, so it has the
	 * identical exposure.
	 *
	 * WordPress exposes no `remove_rewrite_rule()`, so these keys are
	 * stripped directly from the global. `Router::REWRITE_RULES` is this
	 * plugin's single source of truth for the ten patterns (arch-pre-2
	 * architecture review, finding AR-2) — this method reads
	 * `array_keys( Router::REWRITE_RULES )` rather than carrying its own
	 * second, hand-mirrored copy, so adding/removing/re-spelling a route in
	 * `Router` can no longer leave this list silently out of sync.
	 *
	 * @return void
	 */
	private static function strip_own_rewrite_rules_added_this_request() {
		global $wp_rewrite;

		if ( ! isset( $wp_rewrite ) || ! is_object( $wp_rewrite ) ) {
			return;
		}

		foreach ( array_keys( Router::REWRITE_RULES ) as $pattern ) {
			unset( $wp_rewrite->extra_rules_top[ $pattern ] );
		}
	}

	/**
	 * Re-runs capability seeding and schema installation whenever the
	 * installed version differs from the running plugin version.
	 *
	 * Called unconditionally from `activate()`, and from `Plugin::on_init()`
	 * on `WP_CLI` and `wp_doing_cron()` requests ONLY (VIP-1, cycle-5) — a
	 * normal front-end/REST/admin page-load request never triggers a schema
	 * change (CO-5, cycle-7; this text used to say the opposite, which
	 * disagreed with `Plugin::on_init()`'s own docblock). The documented
	 * path after a release that bumps `GAME_LIBRARY_VERSION` is
	 * `wp game-library migrate` (`Cli\Migrate_Command`), run as a deploy
	 * step; the `wp_doing_cron()` call is a backstop that does not fire
	 * under `DISABLE_WP_CRON` — and, on VIP specifically, does not fire at
	 * `init` at all regardless of that constant (VIP-5, see
	 * `Plugin::on_init()`'s own docblock).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed_version = get_option( self::DB_VERSION_OPTION );

		if ( GAME_LIBRARY_VERSION === $installed_version ) {
			return;
		}

		// VIP-2/PB-8 (cycle-5): single-flight lock. install_or_upgrade()
		// runs dbDelta() over five CREATE TABLEs plus five add_cap() calls
		// (each an unconditional update_option( 'wp_user_roles' ),
		// invalidating alloptions site-wide) — with no guard, every request
		// arriving during a version-bumping deploy re-runs the whole
		// routine, because the version option is only written at the very
		// end of it. A version-bumping deploy on VIP lands on every
		// container at once while serving live traffic, so without this
		// lock the exposure is not theoretical.
		//
		// The previous lock used wp_cache_add(), documented at the time as
		// "atomic on a persistent object cache (memcached/Redis on VIP)" —
		// human ruling 1 (interrupts/review:conflict-pending-resolution.md)
		// fixes this project's deployment target as `portable`, running
		// core's stock, non-persistent object cache with no drop-in. On
		// that substrate the in-process cache array starts empty every
		// request, so wp_cache_add() succeeds for EVERY concurrent request
		// and none returned early — the lock prevented nothing.
		//
		// MR-3 (cycle-7): the lock that replaced it was itself broken —
		// get_option()-then-add_option() is a PHP-level check-then-act, and
		// the get_option() pre-read (needed for the staleness check below)
		// primes core's `notoptions` cache with the very key this method is
		// about to try to lock, which is exactly what makes core's
		// add_option() skip its only existence check: add_option() issues
		// `INSERT ... ON DUPLICATE KEY UPDATE`, which SUCCEEDS on a
		// collision and overwrites the incumbent's value once `notoptions`
		// is warm — it is not atomic via a DB constraint the way the
		// previous version of this comment claimed. acquire_upgrade_lock()
		// below instead issues a raw `INSERT IGNORE` and checks
		// `$wpdb->rows_affected`, which genuinely fails (0 rows affected) on
		// a collision regardless of any option cache state. Do not reuse
		// add_option() for a security-critical one-shot (e.g. a redemption
		// token) for the same reason.
		//
		// A lock with no TTL leaves a request that dies mid-routine
		// (dbDelta() failure, a PHP fatal, an execution-time kill) wedged
		// forever with nothing to expire it — CO-4 (cycle-3)'s own
		// finally-release fix only helps the paths that reach a finally at
		// all. The explicit staleness check below restores the same
		// 5-minute crash backstop: a lock older than
		// UPGRADE_LOCK_STALE_AFTER is treated as abandoned, cleared, and
		// re-acquired. dbDelta() only widens or adds columns/indexes and
		// install_or_upgrade() is otherwise idempotent (add_cap()/
		// add_option() are both no-ops when already applied), so the narrow
		// window where two requests could both observe the same stale lock
		// and both re-acquire it costs at most one duplicate, harmless run
		// — never an incompatible schema.
		$held = get_option( self::UPGRADE_LOCK_OPTION );

		if ( false !== $held && ( time() - (int) $held ) < self::UPGRADE_LOCK_STALE_AFTER ) {
			return;
		}

		if ( false !== $held ) {
			// Stale — the previous holder crashed mid-routine and never
			// released it. Clear it before this request can try to
			// re-acquire it.
			delete_option( self::UPGRADE_LOCK_OPTION );
		}

		if ( ! self::acquire_upgrade_lock() ) {
			// Lost the race — a concurrent request's INSERT IGNORE landed
			// first (either the lock was never absent, or another request
			// cleared the same stale lock and re-acquired it a moment
			// before this one could).
			return;
		}

		// CO-4 (cycle-3): releases the lock once install_or_upgrade()
		// returns, on both the success and failure path (PB-4) — a run that
		// dies mid-routine must be free to retry on the very next request
		// rather than wait out the full staleness window.
		try {
			self::install_or_upgrade();
		} finally {
			delete_option( self::UPGRADE_LOCK_OPTION );
		}
	}

	/**
	 * Acquires `maybe_upgrade()`'s single-flight lock (MR-3, cycle-7) — see
	 * `maybe_upgrade()`'s own docblock for why `add_option()` cannot be
	 * trusted as a real check-then-act primitive here. MR-2 (cycle-8):
	 * delegates to `Data\Option_Lock::acquire()`, the one shared home for
	 * this raw-`INSERT IGNORE`-plus-`rows_affected` primitive —
	 * `Data\Game_Repository::under_regen_lock()` now calls the same helper
	 * instead of carrying its own independent copy.
	 *
	 * @return bool True when this request acquired the lock.
	 */
	private static function acquire_upgrade_lock() {
		return Option_Lock::acquire( self::UPGRADE_LOCK_OPTION );
	}

	/**
	 * Creates/upgrades the database schema, grants the plugin's
	 * capabilities, seeds the default settings option, records the running
	 * version, then backfills the member-marker meta this version's
	 * capability grant needs.
	 *
	 * MR-3 (cycle-8): `update_option( self::DB_VERSION_OPTION, … )` runs
	 * BEFORE `Roles::backfill_member_markers()`, not after — the backfill is
	 * now a bounded batch that can legitimately take more than one request
	 * to finish (see that method's own docblock), and a request killed
	 * mid-batch must not also leave the schema/capability half of this
	 * routine unrecorded and re-attempted from the top on the next request.
	 *
	 * @return void
	 */
	private static function install_or_upgrade() {
		Schema::install();
		Roles::grant_capabilities();
		self::seed_settings();

		// PB-9: left autoloaded (no wp_set_option_autoload( ..., false )
		// call) — a non-autoloaded option is not in the alloptions blob, so
		// without a persistent object cache maybe_upgrade()'s own
		// get_option() read below issued its own extra SELECT on literally
		// every request (Plugin::on_init() calls maybe_upgrade()
		// unconditionally), including every logged-out hit on the public
		// /games/ catalog and every REST call. The same short-scalar-read
		// justification seed_settings() already records for leaving
		// game_library_settings autoloaded applies here too.
		update_option( self::DB_VERSION_OPTION, GAME_LIBRARY_VERSION );

		Roles::backfill_member_markers();
	}

	/**
	 * Seeds `game_library_settings` with its documented defaults.
	 *
	 * `add_option()` is a no-op when the option already exists, so a later
	 * version bump never overwrites settings an administrator has already
	 * changed. The option is left autoloaded (the default for `add_option()`)
	 * because it is under 1 KB and read on every plugin route.
	 *
	 * Reads `Settings::defaults()` (arch-pre-1 architecture review, finding
	 * AR-1) rather than its own literal defaults array, so this seed can
	 * never drift from every other reader's documented defaults.
	 *
	 * @return void
	 */
	private static function seed_settings() {
		add_option( Settings::OPTION_KEY, Settings::defaults() );
	}

	/**
	 * Schedules the daily invite-maintenance event if it is not already
	 * scheduled.
	 *
	 * @return void
	 */
	private static function schedule_events() {
		if ( false === wp_next_scheduled( 'game_library_invite_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'game_library_invite_maintenance' );
		}
	}

	/**
	 * Unschedules every occurrence of the daily invite-maintenance event
	 * (CO-9). `wp_clear_scheduled_hook()` — not `wp_next_scheduled()` +
	 * `wp_unschedule_event()` — because the latter pair only removes the
	 * one occurrence at exactly the timestamp `wp_next_scheduled()`
	 * returned; a duplicate entry for this hook at any other timestamp
	 * (e.g. from a schedule_events() race, or a stale entry left by an
	 * older code path) survived deactivation under the old pair, so
	 * AC-001(e) — "deactivation leaves no scheduled event behind" — could
	 * fail depending on which timestamp happened to still be scheduled.
	 * `uninstall.php`'s own cleanup already uses
	 * `wp_clear_scheduled_hook()` for the same hook; this brings both
	 * cleanup paths onto the identical, stronger call instead of disagreeing
	 * with each other.
	 *
	 * `Roles::BACKFILL_CONTINUE_HOOK` (MR-3, cycle-8) is cleared here too —
	 * `backfill_member_markers()` can leave a single scheduled continuation
	 * event queued when a bounded batch does not finish the whole set; the
	 * same AC-001(e) "no scheduled event survives deactivation" requirement
	 * applies to it as to the daily maintenance event.
	 *
	 * VIP-4 (cycle-8): `Page_Cache`'s three single-event hooks had no
	 * cleanup anywhere before this — a deactivation mid-flight left any
	 * already-scheduled edge-cache purge queued to fire against a plugin
	 * that may since have been reactivated with different data, or never
	 * reactivated at all.
	 *
	 * @return void
	 */
	private static function unschedule_events() {
		wp_clear_scheduled_hook( 'game_library_invite_maintenance' );
		wp_clear_scheduled_hook( Roles::BACKFILL_CONTINUE_HOOK );
		wp_clear_scheduled_hook( Page_Cache::PURGE_GAME_SLUGS_HOOK );
		wp_clear_scheduled_hook( Page_Cache::PURGE_FOR_USER_HOOK );
		wp_clear_scheduled_hook( Page_Cache::PURGE_CAPTURED_SLUGS_HOOK );
	}
}
