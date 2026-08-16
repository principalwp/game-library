<?php
/**
 * The hourly IGDB refresh job.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Revalidates the oldest rows of the shared game store, once an hour, in one
 * batched request (AC-036).
 *
 * The store treats a row past `GAMELIB_IGDB_CACHE_TTL` as *due for
 * revalidation*, never as unusable — nothing on a rendered page may make an
 * outbound request (Never Do #6), so freshness is entirely this job's
 * responsibility. One tick is one bounded slice of that work:
 *
 * 1. ask the store, right now, which rows are the most overdue (≤500, oldest
 *    first — the list is computed inside the tick and never cached, AC-036a);
 * 2. fetch exactly those ids in ONE batched IGDB request (AC-036b/e);
 * 3. write back what came home, and stamp every id in the batch as
 *    revalidated — including the ids IGDB left out, so a delisted game keeps
 *    the data it has instead of being re-requested every hour forever
 *    (AC-036c);
 * 4. re-sync the projection's post titles for the games that actually changed
 *    (ADR-011).
 *
 * The slice is bounded twice — by `BATCH_SIZE` rows and by
 * `GAMELIB_REFRESH_TICK_SECONDS` of wall clock (PB-2). The row cap says nothing
 * about how long the work takes: the same 500 ids are one cheap pass when
 * nothing upstream changed and 500 `wp_update_post()` calls when every title
 * drifted. Steps 3 and 4 split that one clock rather than racing for it — the
 * upsert loop stops {@see SYNC_RESERVE_SECONDS} early so the title sync behind
 * it always has a budget to spend (PB-1). What the upsert loop leaves unwritten
 * is simply left unstamped, so it is still the oldest thing in the table when
 * the next tick asks; a title the sync's clock could not reach is stamped with
 * its store row and re-mirrors within the TTL window on a later tick (ADR-036).
 *
 * **Failure is a scheduling problem, not a sleeping problem.** A 429, a 5xx,
 * or a dead socket ends the tick immediately; the retry is a *persisted
 * next-attempt timestamp* plus a chained single event, because VIP's Cron
 * Control kills a job that sleeps and `sleep()` in a callback is forbidden
 * outright (Never Do #7, ADR-004). The tick that fires before that timestamp
 * exits at its first line having done nothing.
 *
 * The chained retry event carries {@see RETRY_CONTEXT} as its argument, which
 * is load-bearing rather than decorative: `wp_schedule_single_event()` refuses
 * an event whose hook *and args* already have an occurrence due within ten
 * minutes, and the recurring hourly event (args `array()`) can easily sit
 * inside that window — measured on the shared Playground, the same-args call
 * returns `duplicate_event` while the distinct-args call succeeds. Without the
 * argument the whole retry chain would silently evaporate whenever the hourly
 * event happened to be due soon.
 */
final class GameLib_Refresh_Job {

	/**
	 * The recurring hook, owned by {@see GameLib_Plugin} so activation,
	 * deactivation, and this class all name it identically.
	 *
	 * @var string
	 */
	const HOOK = GameLib_Plugin::CRON_REFRESH_HOOK;

	/**
	 * Core's hourly recurrence — comfortably above VIP's 900-second floor for
	 * recurring events, and the interval AC-036 names.
	 *
	 * @var string
	 */
	const SCHEDULE = 'hourly';

	/**
	 * Argument the chained retry event carries (see the class docblock: it is
	 * what keeps `wp_schedule_single_event()`'s duplicate guard from mistaking
	 * a retry for the recurring hourly event).
	 *
	 * @var string
	 */
	const RETRY_CONTEXT = 'retry';

	/**
	 * Option holding this job's state — attempt counter, next-attempt
	 * timestamp, and the last outcome.
	 *
	 * Written exclusively through {@see save_state()} with `autoload => false`:
	 * it is read once an hour by one cron callback and never by a page load, so
	 * autoloading it would put a row on every request that needs it on none
	 * (WPP-04).
	 *
	 * @var string
	 */
	const STATE_OPTION = 'gamelib_refresh_state';

	/**
	 * Rows revalidated per tick, and therefore ids per request: IGDB's `limit`
	 * ceiling and the AC-036(a/b) cap are the same 500.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 500;

	/**
	 * Seconds of the tick's wall clock held back from the upsert loop so the
	 * projection sync behind it is never handed a spent deadline (PB-1).
	 *
	 * Without a reserve the two phases share one clock that the first of them
	 * spends: the loop stops only once the deadline has already passed, so the
	 * title sync starts expired and rewrites nothing — on every deadline-cut
	 * tick, with no error recorded anywhere.
	 *
	 * Five seconds is what a fully drifted batch measured. On the shared
	 * Playground a rewritten title costs about 10 ms — a primed post read plus
	 * one post update, which fires `save_post` and clears the post caches — so
	 * five seconds covers roughly 500 of them, which is the whole
	 * {@see BATCH_SIZE}; separate 100-row and 250-row passes agreed at 10.1 and
	 * 9.8 ms per row. Holding it back costs the upsert phase almost nothing: an
	 * upsert measured ~4.3 ms, so a full 500-row batch spends about two seconds
	 * in the loop and never reaches the earlier cut-off at all.
	 *
	 * @var int
	 */
	const SYNC_RESERVE_SECONDS = 5;

	/**
	 * Attempts one tick chain may spend on the same failing upstream before it
	 * gives up until the next hourly tick (AC-036d).
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Seconds to wait after the 1st, 2nd, and 3rd failed attempt (AC-036d).
	 *
	 * The first two entries schedule the next attempt in the chain. The third
	 * is the cooldown persisted when the chain gives up: the attempt counter
	 * resets, but a tick arriving inside those 16 seconds still stands down
	 * rather than immediately re-hammering an upstream that has refused three
	 * times.
	 *
	 * @var int[]
	 */
	const BACKOFF = array( 1, 4, 16 );

	/**
	 * The two IGDB failure classes worth retrying: AC-036(d)'s
	 * "429/5xx/network error".
	 *
	 * `unconfigured` (no credentials, or credentials refused) and `malformed`
	 * (a rejected query) are deliberately absent — they are operator or code
	 * defects, and three more attempts twenty seconds apart would fail
	 * identically while spending the rate-limit budget.
	 *
	 * @var string[]
	 */
	const RETRYABLE = array(
		GameLib_IGDB_Client::ERROR_RATE_LIMITED,
		GameLib_IGDB_Client::ERROR_UNAVAILABLE,
	);

	/**
	 * Register the recurring event (activation, AC-051a).
	 *
	 * The first run is scheduled for *now* rather than an hour out: on a site
	 * with no games the tick is a single indexed query that finds nothing, and
	 * on a site being reactivated it closes the gap the deactivation opened.
	 *
	 * Idempotent — re-activating a plugin that already has the event scheduled
	 * leaves the existing schedule alone.
	 *
	 * @return void
	 */
	public static function activate() {
		self::ensure_scheduled();
	}

	/**
	 * Make sure the recurring event exists.
	 *
	 * Runs on activation and again on `init`, matching how the schema
	 * (`GameLib_Schema::maybe_upgrade()`) and the capability grants
	 * (`GameLib_Capabilities::sync()`, ADR-007) reconcile themselves: an
	 * activation hook fires once, and anything that fires once can be missed —
	 * by a site restored from a backup taken before the event existed, by a
	 * plugin that rewrote the `cron` option, or by an environment where the
	 * plugin was activated before this file shipped. A missing event means the
	 * store silently stops being revalidated, with nothing to notice it.
	 *
	 * The check reads the autoloaded `cron` option on stock WordPress, so a
	 * request there pays no query for it — but that reasoning does **not** hold
	 * on VIP (VIP-5). Cron Control routes `wp_next_scheduled()` through
	 * `pre_get_scheduled_event` into its own event store, which is an extra
	 * cache (or database) lookup on every request that reaches `init`, anonymous
	 * front-end hits and REST calls included. So the repair runs only in the
	 * contexts that can act on it: wp-admin, a cron tick, and WP-CLI. Activation
	 * still schedules the event; the next admin request or cron tick still
	 * repairs a missing one.
	 *
	 * Retry events are invisible here: they carry {@see RETRY_CONTEXT}, and
	 * `wp_next_scheduled()` matches on arguments.
	 *
	 * @return void
	 */
	public static function ensure_scheduled() {
		if ( ! self::repair_context() ) {
			return;
		}

		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
	}

	/**
	 * Is this request one that may repair the schedule?
	 *
	 * @return bool True in wp-admin, a cron run, or WP-CLI.
	 */
	private static function repair_context() {
		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * One tick: revalidate the most overdue slice of the game store (AC-036).
	 *
	 * Registered unconditionally — never behind `is_admin()` — because cron
	 * runs in neither context on VIP's Cron Control (ADR-004).
	 *
	 * Returns nothing — an action callback's return value goes nowhere. What
	 * the tick did is readable afterwards from {@see state()}: `last_batch`,
	 * `last_success_at`, `last_error`, and the attempt counter.
	 *
	 * @param string $context Event argument. {@see RETRY_CONTEXT} when this
	 *                        tick is a chained retry, '' when it is the hourly
	 *                        event. A retry re-derives its own stale-id list
	 *                        exactly like a fresh tick (AC-036a), so the value
	 *                        is not acted on.
	 * @return void
	 */
	public static function run( $context = '' ) {
		$now   = time();
		$state = self::state();

		/*
		 * The tick's wall clock (PB-2), taken before any work, in the shape the
		 * plugin's other three background jobs already use. `BATCH_SIZE` bounds
		 * the rows, not the seconds: the same 500 ids cost one cheap pass when
		 * nothing upstream changed and 500 `wp_update_post()` calls when every
		 * title drifted.
		 */
		$deadline = microtime( true ) + (float) GAMELIB_REFRESH_TICK_SECONDS;

		/*
		 * The backoff, in the only form a cron callback may express it: a
		 * timestamp checked at tick start. Nothing sleeps; the tick simply is
		 * not the one that does the work (AC-036d, Never Do #7).
		 */
		if ( $state['next_attempt_at'] > $now ) {
			return;
		}

		// AC-036(a): asked and answered inside the tick, every tick. A cached
		// or precomputed list would hand this job ids it refreshed an hour ago.
		$igdb_ids = GameLib_Game_Store::get_stale_ids( self::BATCH_SIZE );

		if ( empty( $igdb_ids ) ) {
			self::save_state(
				array(
					'attempts'        => 0,
					'next_attempt_at' => 0,
					'last_run_at'     => $now,
					'last_error'      => '',
					'last_batch'      => 0,
					'retry_scheduled' => false,
				)
			);

			return;
		}

		/*
		 * AC-036(b/e): one request for the whole batch, on the background
		 * timeout budget (10s, DD-017). The client chunks by 500 and issues its
		 * chunks serially, and this batch is at most 500 — so this is exactly
		 * one upstream call, never a concurrent fan-out.
		 */
		$records = GameLib_IGDB_Client::get_games_by_ids( $igdb_ids, array( 'background' => true ) );

		if ( is_wp_error( $records ) ) {
			self::record_failure( $records, $state, $now );

			return;
		}

		$refreshed = array();
		$processed = 0;
		$whole     = true;

		/*
		 * PB-1: what the loop below may spend, which is the tick's budget less
		 * the slice reserved for the title sync that follows it. Spending the
		 * whole clock here would leave that sync with an already-expired
		 * deadline, and a sync that rewrites nothing is how AC-034(c) stops
		 * working without anything noticing.
		 */
		$upsert_deadline = $deadline - (float) self::SYNC_RESERVE_SECONDS;

		foreach ( $records as $record ) {
			/*
			 * PB-2: the clock, checked at the head of the loop the way
			 * `GameLib_Library::drain_bulk()` checks its own — but only once a
			 * record has been through the upsert, so a tick whose IGDB call ate
			 * the whole budget still makes forward progress instead of
			 * re-requesting the same slice every hour forever.
			 */
			if ( $processed > 0 && microtime( true ) >= $upsert_deadline ) {
				$whole = false;

				break;
			}

			if ( ! is_array( $record ) ) {
				continue;
			}

			$stored = GameLib_Game_Store::upsert_from_igdb( $record );
			++$processed;

			if ( $stored > 0 && in_array( $stored, $igdb_ids, true ) ) {
				$refreshed[] = $stored;
			}
		}

		/*
		 * AC-036(c): every id in the batch is stamped, not just the ones IGDB
		 * answered for. A game that has been delisted upstream keeps its data
		 * and stops being the oldest row in the table — without this the same
		 * dead ids would fill every batch forever and no other row would ever
		 * be revalidated.
		 *
		 * A tick the deadline cut short stamps only what it wrote (PB-2). The
		 * ids it never reached keep their old `updated_at`, which is what makes
		 * them the oldest rows in the table — so the next tick's own
		 * `get_stale_ids()` hands them straight back and the work resumes there.
		 * Nothing is carried between ticks in an option or a cursor: the list is
		 * derived inside the tick, every tick (AC-036a).
		 */
		$stamped = $whole ? $igdb_ids : $refreshed;

		if ( ! empty( $refreshed ) ) {
			/*
			 * Read from the cache the upserts above re-primed, so the projection
			 * sync costs no query at all (PB-2).
			 */
			$rows = GameLib_Game_Store::get_many( $refreshed );

			/*
			 * One bump for the whole batch, and of `game_content` rather than of
			 * `games` (CO-2, PB-2). `game_content` is folded into every member's
			 * cached library page, so a rename or a cover change upstream reaches
			 * those cards on the next read; the store's own entries are left
			 * alone, because this tick has just re-primed every one of them and
			 * bumping their scope discarded 500 fresh writes in the request that
			 * made them — then re-read the rows it had just orphaned.
			 */
			GameLib_Cache::bump( GameLib_Cache::SCOPE_GAME_CONTENT );

			/*
			 * ADR-011: the projection's title mirrors the store row; the method
			 * skips every post whose title already matches, which is all of
			 * them on an ordinary batch. It takes this tick's deadline (PB-2)
			 * because the drifted batch — where it does not skip — is one
			 * `wp_update_post()` per row, each firing `save_post` and clearing
			 * post caches. The reserve deducted from the upsert loop above is
			 * what makes that deadline worth passing: the sync arrives with
			 * seconds left rather than with a clock the loop has just spent.
			 *
			 * A title the sync's own clock cuts short is not re-prioritised: its
			 * store row is stamped with the rest of the batch below, and the
			 * projection re-mirrors on an ordinary later tick. Under a sustained
			 * backlog that lag is bounded at the 24-hour TTL — the accepted
			 * freshness contract for this cache (ADR-036, D-REQ-18).
			 */
			GameLib_Game_CPT::sync_posts( $refreshed, $rows, $deadline );
		}

		if ( ! empty( $stamped ) ) {
			GameLib_Game_Store::touch( $stamped );
		}

		self::save_state(
			array(
				'attempts'        => 0,
				'next_attempt_at' => 0,
				'last_run_at'     => $now,
				'last_success_at' => $now,
				'last_error'      => '',
				// What the tick actually revalidated, which is the whole slice
				// on a tick that ran to the end of its batch (PB-2).
				'last_batch'      => count( $stamped ),
				'retry_scheduled' => false,
			)
		);
	}

	/**
	 * This job's persisted state, with every key present and typed.
	 *
	 * @return array{attempts:int,next_attempt_at:int,last_run_at:int,last_success_at:int,last_error:string,last_batch:int,retry_scheduled:bool} Job state.
	 */
	public static function state() {
		$stored = get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'attempts'        => isset( $stored['attempts'] ) ? absint( $stored['attempts'] ) : 0,
			'next_attempt_at' => isset( $stored['next_attempt_at'] ) ? absint( $stored['next_attempt_at'] ) : 0,
			'last_run_at'     => isset( $stored['last_run_at'] ) ? absint( $stored['last_run_at'] ) : 0,
			'last_success_at' => isset( $stored['last_success_at'] ) ? absint( $stored['last_success_at'] ) : 0,
			'last_error'      => isset( $stored['last_error'] ) ? sanitize_key( (string) $stored['last_error'] ) : '',
			'last_batch'      => isset( $stored['last_batch'] ) ? absint( $stored['last_batch'] ) : 0,
			'retry_scheduled' => ! empty( $stored['retry_scheduled'] ),
		);
	}

	/**
	 * Persist a failed attempt and, while the chain has attempts left, schedule
	 * the next one (AC-036d).
	 *
	 * @param WP_Error $error IGDB taxonomy error that ended the tick.
	 * @param array    $state State this tick started from.
	 * @param int      $now   Tick timestamp (UTC).
	 * @return void
	 */
	private static function record_failure( WP_Error $error, array $state, $now ) {
		$code = (string) $error->get_error_code();

		if ( ! in_array( $code, self::RETRYABLE, true ) ) {
			/*
			 * Missing credentials or a rejected query: the next attempt fails
			 * the same way. The chain ends here and the hourly event tries
			 * again once the operator has fixed the cause.
			 */
			self::save_state(
				array(
					'attempts'        => 0,
					'next_attempt_at' => 0,
					'last_run_at'     => $now,
					'last_error'      => $code,
					'retry_scheduled' => false,
				)
			);

			return;
		}

		$attempts = min( self::MAX_ATTEMPTS, $state['attempts'] + 1 );
		$next     = $now + self::BACKOFF[ $attempts - 1 ];
		$chained  = false;

		if ( $attempts < self::MAX_ATTEMPTS ) {
			$scheduled = wp_schedule_single_event( $next, self::HOOK, array( self::RETRY_CONTEXT ), true );
			$chained   = ! is_wp_error( $scheduled ) && false !== $scheduled;
		}

		self::save_state(
			array(
				// Reaching the cap ends the chain: the counter resets, and the
				// persisted timestamp remains as a cooldown until the hourly
				// event comes back around.
				'attempts'        => ( $attempts < self::MAX_ATTEMPTS ) ? $attempts : 0,
				'next_attempt_at' => $next,
				'last_run_at'     => $now,
				'last_error'      => $code,
				'retry_scheduled' => $chained,
			)
		);
	}

	/**
	 * Merge changes into the state option.
	 *
	 * `autoload => false` is the whole point of routing every write through
	 * here: this row must never join the autoloaded set (WPP-04, and the
	 * task's explicit constraint — the third argument of `update_option()`,
	 * never a `register_setting()` autoload argument).
	 *
	 * @param array $changes Keys to overwrite.
	 * @return void
	 */
	private static function save_state( array $changes ) {
		$state = array_merge( self::state(), $changes );

		update_option( self::STATE_OPTION, $state, false );
	}
}
