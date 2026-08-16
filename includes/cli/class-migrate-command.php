<?php
/**
 * `wp game-library migrate` — the out-of-band schema/capability deploy step.
 *
 * @package Game_Library
 */

namespace Game_Library\Cli;

use Game_Library\Activator;
use Game_Library\Roles;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Migrate_Command.
 *
 * VIP-1: `Plugin::on_init()` no longer calls `Activator::maybe_upgrade()`
 * unconditionally on every request — a live front-end/REST/admin request is
 * not where a `dbDelta()` schema change and a `wp_user_roles` capability
 * grant belong, and VIP requires schema changes to run out of band from live
 * traffic anyway. This command is that out-of-band step: run it once after
 * any deploy that bumps `GAME_LIBRARY_VERSION`, as part of the deploy
 * pipeline rather than left to whichever visitor's request happens to
 * notice the version drift first.
 *
 * Under stock WP-Cron, `wp_doing_cron()` requests still call
 * `maybe_upgrade()` directly (see `Plugin::on_init()`) as a self-healing
 * backstop for a version bump that ships without this command being run.
 * This plugin's ruled deployment target is `portable` (spec.md; human ruling
 * CONF-2(i)), where that backstop does work — but it does NOT work on VIP
 * (VIP-5, cycle-7), or on any host running under `DISABLE_WP_CRON` (which
 * this project's own E2E blueprints set): VIP disables `/wp-cron.php` and
 * Cron Control dispatches events through a REST route that defines
 * `DOING_CRON` after `init` has already fired, so `wp_doing_cron()` is
 * reliably false at `init` on every VIP request; `DISABLE_WP_CRON` simply
 * never fires the pseudo-cron request at all. On either of those, running
 * `wp game-library migrate` after a deploy that bumps `GAME_LIBRARY_VERSION`
 * is the only path guaranteed to apply the schema change. MR-4 (cycle-8):
 * an earlier version of this docblock claimed VIP as the ruled deployment
 * target and framed running this command as universally "required" —
 * `Plugin::on_init()`'s own docblock and `Data\Generations`'s already stated
 * the fact correctly (`portable`); this file was the one outlier.
 * `Plugin::on_init()` surfaces an `admin_notices` warning when the installed
 * schema version lags, in place of a working backstop.
 */
final class Migrate_Command {

	/**
	 * Registers the `wp game-library migrate` command. Guarded by the
	 * `WP_CLI` constant at every call site — this class is only autoloaded
	 * when something references it, but `WP_CLI::add_command()` itself is
	 * only defined when WP-CLI is the current runtime.
	 *
	 * @return void
	 */
	public static function register() {
		WP_CLI::add_command( 'game-library migrate', array( __CLASS__, 'migrate' ) );
	}

	/**
	 * Installs/upgrades the database schema and capabilities to the running
	 * plugin version (VIP-1) — the documented deploy step after any release
	 * that bumps `GAME_LIBRARY_VERSION`. A no-op, safe to run any time,
	 * when the installed version already matches.
	 *
	 * MR-3 (cycle-8): also drains any remaining member-marker backfill
	 * batches synchronously, in a loop, via
	 * `Roles::drain_member_marker_backfill()` — this command runs with no
	 * execution-time limit, unlike the single scheduled-event continuation
	 * `Roles::backfill_member_markers()` otherwise falls back to when a
	 * bounded batch doesn't finish the whole set inside a live request. A
	 * no-op when nothing is left to backfill.
	 *
	 * ## EXAMPLES
	 *
	 *     wp game-library migrate
	 *
	 * @return void
	 */
	public static function migrate() {
		Activator::maybe_upgrade();
		Roles::drain_member_marker_backfill();

		WP_CLI::success( 'Game Library schema and capabilities are up to date.' );
	}
}
