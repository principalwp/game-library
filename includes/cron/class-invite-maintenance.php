<?php
/**
 * Daily invite expiry and purge maintenance.
 *
 * @package Game_Library
 */

namespace Game_Library\Cron;

use Game_Library\Data\Invite_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Invite_Maintenance.
 *
 * Hooked to the daily `game_library_invite_maintenance` custom cron event —
 * scheduled on activation and unscheduled on deactivation by `Activator`
 * (Task 1). `run()` marks lapsed `pending` invites `expired` and permanently
 * purges `expired`/`revoked` invites more than 30 days past their expiry
 * (AC-032), by calling `Invite_Repository::expire_lapsed()`/
 * `::purge_expired_before()`.
 *
 * Both passes moved onto `Invite_Repository` in arch-pre-2 architecture
 * review, finding AR-1: this class previously issued the `UPDATE`/`DELETE`
 * writes directly against `$wpdb`, which never invalidated the per-row
 * `code`-keyed cache entries or bumped `invite_gen` — the admin invite list
 * (AC-031) and `/invites/` kept showing pre-cron statuses and rows the cron
 * had already deleted, for up to an hour after every daily run. This class
 * now only decides *what* to act on (the batch size, the purge cutoff) and
 * `Invite_Repository` owns the query, the batching, and the invalidation,
 * matching every other write against `gl_invites`.
 *
 * Both passes are still batched exactly as before: `Invite_Repository`'s two
 * methods read the ids to update/delete first with an explicit `LIMIT`, then
 * act on them via an `id IN (…)` clause — never `UPDATE … LIMIT`/
 * `DELETE … LIMIT`, which the SQLite backend this project's E2E environment
 * runs on does not support. A pass that fills `BATCH_SIZE` deliberately
 * leaves the remainder for the next daily run rather than scheduling a
 * same-run follow-up — at this plugin's sizing assumption (~1,000 members, a
 * 10-invite rolling quota) the invite table tops out near 10,000 rows and a
 * single daily run only ever touches the handful to few dozen rows that
 * lapsed or aged past the purge line since the previous run, so a 500-row
 * batch never fills in practice; this maintenance is not time-critical, so
 * waiting for the next scheduled run is correct (arch-pre-1 architecture
 * review, finding AR-3 — a later reviewer re-adding same-run continuation
 * here should re-check that reasoning first).
 */
final class Invite_Maintenance {

	/**
	 * The custom cron hook name, scheduled daily by `Activator`.
	 *
	 * @var string
	 */
	public const HOOK = 'game_library_invite_maintenance';

	/**
	 * Days past expiry before an `expired`/`revoked` invite is purged
	 * (AC-032).
	 *
	 * @var int
	 */
	private const PURGE_AFTER_DAYS = 30;

	/**
	 * Max rows processed by either pass in a single run.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 500;

	/**
	 * Invite storage.
	 *
	 * @var Invite_Repository
	 */
	private $invites;

	/**
	 * Constructor.
	 *
	 * @param Invite_Repository|null $invites Invite repository. Defaults to a
	 *                                        new instance.
	 */
	public function __construct( ?Invite_Repository $invites = null ) {
		$this->invites = $invites ?: new Invite_Repository();
	}

	/**
	 * Registers this service's hooks. No `is_admin()` guard — cron runs in a
	 * non-admin context and a guard here would mean this hook never fires.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Runs both maintenance passes.
	 *
	 * @return void
	 */
	public function run() {
		$this->invites->expire_lapsed( self::BATCH_SIZE );

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::PURGE_AFTER_DAYS * DAY_IN_SECONDS ) );
		$this->invites->purge_expired_before( $cutoff, self::BATCH_SIZE );
	}
}
