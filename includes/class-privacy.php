<?php
/**
 * Personal data: exporter, eraser, account deletion, and policy text.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything WordPress's privacy tools need to know about this plugin
 * (AC-052, AC-053).
 *
 * Three integration points, one shared routine:
 *
 * 1. `wp_privacy_personal_data_exporters` — Tools → Export Personal Data
 *    reports the member's library, their follow edges in both directions, the
 *    events they authored, the invites they issued, their stored SteamID
 *    (AC-046c), and their visibility setting (AC-052a).
 * 2. `wp_privacy_personal_data_erasers` — Tools → Erase Personal Data removes
 *    all of that except the invites somebody already redeemed, which are kept
 *    and reported through `items_retained` with the reason (AC-052 b,d).
 * 3. `deleted_user` — deleting the account runs the very same purge, so the two
 *    paths can never drift apart (AC-052c).
 *
 * The purge itself is {@see GameLib_Privacy::purge_user()}, and it does no SQL
 * of its own: each domain class owns its table and already carries the batched
 * `purge_user()` this calls, including the generation bumps that make a
 * follower's feed lose the erased member's events on the very next read
 * (AC-052e). Nothing here touches `glib_game` posts or `gamelib_games` rows —
 * the shared game store is not personal data, and no privacy path may delete
 * from it (AC-052f).
 *
 * Two things are deliberately left in place by the eraser:
 *
 * - **Redeemed invites** (AC-052d), because they are the registration record of
 *   an account that still exists.
 * - **`gamelib_invites_disabled`**, the AC-007(b) administrator override. It is
 *   a moderation decision *about* the account rather than data the member
 *   supplied, and erasing it would silently hand invite creation back to a
 *   member an administrator had switched it off for. AC-052(b) enumerates the
 *   meta in scope, and that key is not on the list.
 */
final class GameLib_Privacy {

	/**
	 * Key this plugin registers its exporter and eraser under.
	 *
	 * @var string
	 */
	const KEY = 'game-library';

	/**
	 * Export group: the account-level settings (visibility, SteamID).
	 *
	 * @var string
	 */
	const GROUP_ACCOUNT = 'gamelib-account';

	/**
	 * Export group: one item per library entry.
	 *
	 * @var string
	 */
	const GROUP_LIBRARY = 'gamelib-library';

	/**
	 * Export group: one item per follow edge, in both directions.
	 *
	 * @var string
	 */
	const GROUP_FOLLOWS = 'gamelib-follows';

	/**
	 * Export group: one item per authored activity event.
	 *
	 * @var string
	 */
	const GROUP_ACTIVITY = 'gamelib-activity';

	/**
	 * Export group: one item per invite the member issued.
	 *
	 * @var string
	 */
	const GROUP_INVITES = 'gamelib-invites';

	/**
	 * Follow edges read per statement, per direction. Matches
	 * {@see GameLib_Follows::MAX_LIST_LIMIT}, the ceiling that class clamps to.
	 *
	 * @var int
	 */
	const FOLLOWS_BATCH = 200;

	/**
	 * Activity events read per statement. Matches
	 * {@see GameLib_Activity::MAX_PAGE_SIZE}, the ceiling that class clamps to.
	 *
	 * @var int
	 */
	const ACTIVITY_BATCH = 50;

	/**
	 * Invites read per statement. Matches
	 * {@see GameLib_Invites::MAX_LIST_LIMIT}, the ceiling that class clamps to.
	 *
	 * @var int
	 */
	const INVITES_BATCH = 100;

	/**
	 * Rows the eraser removes per domain, per call (PB-3).
	 *
	 * Core re-invokes an eraser with an incrementing page number until it answers
	 * `done`, which is exactly the lever a bounded purge needs: each call removes
	 * at most this many library rows, follow edges, and activity events, and
	 * reports `done => false` while any domain still has rows. Before this, a
	 * member with a five-figure history was one unbounded request that reported
	 * `done => true` whether or not it had finished — a compliance problem, not
	 * only a performance one.
	 *
	 * @var int
	 */
	const PURGE_BATCH = 2000;

	/**
	 * Rows one *request* may remove before every further member is handed to
	 * cron (PB-2).
	 *
	 * {@see PURGE_BATCH} bounds one member's pass; nothing bounded how many
	 * passes ran in one request. `wp-admin/users.php`'s bulk delete loops
	 * `wp_delete_user()` over every selected id and fires `deleted_user` once
	 * per member, so fifty heavy members in one Delete was ~300,000 row deletes,
	 * ~1,200 statements and ~1,400 object-cache round trips in one synchronous
	 * admin POST — past VIP's 60s ceiling, where a timeout loses no committed
	 * work but leaves the un-reached members undeleted with no signal.
	 *
	 * One member's worth: three unbounded domains at {@see PURGE_BATCH} each. So
	 * the first member deleted still gets the whole synchronous
	 * detach-from-feeds pass {@see purge_user()}'s ordering is built around, and
	 * every member after that is scheduled instead.
	 *
	 * @var int
	 */
	const REQUEST_BUDGET = self::PURGE_BATCH * 3;

	/**
	 * Rows this request has already removed, against {@see REQUEST_BUDGET}.
	 *
	 * @var int
	 */
	private static $purged_this_request = 0;

	/**
	 * Seconds of wall clock one cron tick or one WP-CLI process spends purging
	 * before it starts deferring (PB-1).
	 *
	 * The row budget above exists to protect a synchronous `wp-admin` POST, and
	 * applying it to cron was the opposite of what it is for: `wp-cron.php`
	 * snapshots every due event and runs them all in one request, so once the
	 * first `gamelib_purge_user` event spent {@see REQUEST_BUDGET}, every other
	 * due purge event in that run fired, deleted nothing, and re-scheduled
	 * itself — where it was due again for the very next pass. Measured on the
	 * booted instance: two member ids reappeared at a fresh timestamp on each of
	 * three consecutive cron passes, having deleted nothing, every event firing
	 * inside the same request. Each of those deferrals rewrites the autoloaded
	 * `cron` option twice, and therefore the whole `alloptions` cache entry, so
	 * the cost lands on every concurrent front-end request rather than on the
	 * deleting administrator.
	 *
	 * The background contexts are bounded by time instead, which is the shape
	 * {@see GameLib_Library::run_bulk()} already carries: one tick drains as
	 * many members as it can inside its budget, and `wp user delete a b c …`
	 * does the work the operator asked the process to do — on an install with
	 * `DISABLE_WP_CRON` and no system cron, those punted events would never run
	 * at all.
	 *
	 * @var int
	 */
	const PURGE_TICK_SECONDS = 20;

	/**
	 * Ceiling, in seconds, a *recorded* member's in-request finish may spend
	 * (VIP-2; scoped down per tick since PB-1, cycle-7).
	 *
	 * {@see continue_purge()} reaches that walk only when scheduling was
	 * refused, and that is reachable from two different budgets running out,
	 * not one. On the `deleted_user` path, inside a `wp-admin` POST, it is
	 * usually the row budget ({@see REQUEST_BUDGET}) that is already spent;
	 * unbounded there, the walk is however many hundreds of thousands of rows
	 * the member has, and VIP kills the request at the platform ceiling — the
	 * administrator gets a 502 on `users.php` with no indication of what state
	 * the deletion is in. Ten seconds keeps it well inside the ceiling and
	 * loses nothing: the id is on {@see PURGE_PENDING_OPTION} before the walk
	 * starts, so the refresh tick finishes what this leaves.
	 *
	 * The same walk is reachable from cron and WP-CLI too, once a tick has
	 * already spent its own {@see PURGE_TICK_SECONDS} wall-clock budget and a
	 * *further* due `gamelib_purge_user` event in that run also gets refused
	 * — {@see budget_spent()} answers true for that event before it does any
	 * work, so this constant is this class's only fixed value here, not the
	 * ceiling actually applied. `continue_purge()` therefore passes
	 * {@see walk_bounded()} whatever the tick has left, clamped to this many
	 * seconds, rather than this constant outright — a tick with nothing left
	 * still runs one bounded batch and stops, instead of spending a fresh ten
	 * seconds per remaining member on top of a budget already exhausted.
	 *
	 * @var int
	 */
	const PURGE_FINISH_SECONDS = 10;

	/**
	 * When this request's background purge budget runs out, as a `microtime()`.
	 *
	 * Zero until the first {@see purge_pass()} of the request sets it.
	 *
	 * @var float
	 */
	private static $purge_deadline = 0.0;

	/**
	 * Option holding the members whose purge chain could not be scheduled
	 * (VIP-1/CO-5/SE-2).
	 *
	 * Ids only — never an email, a name, or anything else about the account,
	 * which is already gone by the time anything is written here — and
	 * `autoload => false`, because nothing on a page load reads it. Drained by
	 * {@see drain_pending()} on the refresh tick, which is already scheduled and
	 * already runs on VIP.
	 *
	 * @var string
	 */
	const PURGE_PENDING_OPTION = 'gamelib_purge_pending';

	/**
	 * Ids {@see PURGE_PENDING_OPTION} will hold before it stops growing.
	 *
	 * Sized so the in-request fallback is unreachable for any realistic bulk
	 * selection, rather than sized to the option's bytes (PB-2). The cap is not
	 * a bound, it is a cliff: {@see continue_purge()} finishes a *not-recorded*
	 * member's walk unbounded and in-request, deliberately, because nothing else
	 * is coming for an id that could not be recorded — so at 100, deleting 500
	 * accounts during a `pre_schedule_event` outage recorded the first hundred
	 * cheaply and then walked four hundred members to exhaustion in one
	 * synchronous admin POST, with neither the per-request budget nor the
	 * per-member batch applying. The option is `autoload => false` and holds
	 * bare integers: a hundred ids is ~2KB serialized and ten thousand ~200KB,
	 * in a row nothing on a page load reads. Two orders of magnitude of headroom
	 * costs nothing and takes the cliff out of reach.
	 *
	 * Sized in company with {@see PURGE_DRAIN_PER_TICK}, which is what keeps a
	 * list this long from becoming a cron-tick problem of its own.
	 *
	 * @var int
	 */
	const PURGE_PENDING_MAX = 10000;

	/**
	 * Ids one {@see drain_pending()} tick works through (PB-3).
	 *
	 * The drain rewrites the whole option once per id — {@see forget_pending()}
	 * re-reads and re-writes it, and a pass that still cannot schedule adds a
	 * second write through {@see remember_pending()} — so an unbounded loop is
	 * O(N^2) in serialization, on the one recurring event the whole plugin
	 * depends on and alongside {@see GameLib_Refresh_Job::run()}, which already
	 * has budgets of its own. Fifty caps the churn at ~100 option writes a tick
	 * and lets a long list drain over successive ones, which is the shape the
	 * rest of this class already uses.
	 *
	 * @var int
	 */
	const PURGE_DRAIN_PER_TICK = 50;

	/**
	 * Exporter page 1: the account settings and the whole library.
	 *
	 * @var int
	 */
	const PAGE_ACCOUNT = 1;

	/**
	 * Exporter page 2: the follow edges, both directions.
	 *
	 * @var int
	 */
	const PAGE_FOLLOWS = 2;

	/**
	 * Exporter page 3: the authored activity events.
	 *
	 * @var int
	 */
	const PAGE_ACTIVITY = 3;

	/**
	 * Exporter page 4: the issued invites. The last page — the one that answers
	 * `done`.
	 *
	 * @var int
	 */
	const PAGE_INVITES = 4;

	/**
	 * Register the personal-data exporter (AC-052a).
	 *
	 * @param array $exporters Exporters keyed by id.
	 * @return array Exporters with this plugin's added.
	 */
	public static function register_exporters( $exporters ) {
		if ( ! is_array( $exporters ) ) {
			$exporters = array();
		}

		$exporters[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'Game Library', 'game-library' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the personal-data eraser (AC-052 b,d).
	 *
	 * @param array $erasers Erasers keyed by id.
	 * @return array Erasers with this plugin's added.
	 */
	public static function register_erasers( $erasers ) {
		if ( ! is_array( $erasers ) ) {
			$erasers = array();
		}

		$erasers[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'Game Library', 'game-library' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export everything this plugin holds about one member (AC-052a).
	 *
	 * One group per page (PB-7): account + library, then follows, then activity,
	 * then invites — the contract `wp_privacy_personal_data_exporters` is built
	 * for, and what keeps any single admin-ajax request bounded to one domain's
	 * walk. Building all five groups in one request meant a member with a large
	 * history read their library, both follow directions, every event and every
	 * invite inside one PHP timeout, holding every rendered item in memory at
	 * once.
	 *
	 * Paging is by *group*, not by row, because core hands a callback nothing
	 * but the email address and the page number — a row cursor would have to
	 * survive between two admin-ajax requests, and the activity table is
	 * keyset-paginated, so page N's starting row is not derivable from N.
	 * Within a page each section is still read in bounded batches: the library
	 * through {@see GameLib_Library::export_rows()}, the edges through
	 * {@see GameLib_Follows::followers()}/{@see GameLib_Follows::following()},
	 * the events through {@see GameLib_Activity::for_member()}'s keyset cursor,
	 * and the invites through {@see GameLib_Invites::list_for_user()}. Each loop
	 * advances strictly and stops on the first short batch, so there is no
	 * unbounded read and no way to spin.
	 *
	 * Values are returned raw. Core escapes them when it writes the export
	 * document, and the JSON leg of the same response must not carry HTML
	 * entities (Never Do #14).
	 *
	 * @param string $email_address Member's email address.
	 * @param int    $page          1-based page number.
	 * @return array{data:array,done:bool} Export payload.
	 */
	public static function export( $email_address, $page = 1 ) {
		$page = max( 1, absint( $page ) );

		$response = array(
			'data' => array(),
			'done' => ( $page >= self::PAGE_INVITES ),
		);

		$user = self::user_for_email( $email_address );

		if ( null === $user ) {
			// Nothing to walk, so nothing to page: stop on this request rather
			// than making core ask three more times for the same empty answer.
			$response['done'] = true;

			return $response;
		}

		$user_id = (int) $user->ID;

		switch ( $page ) {
			case self::PAGE_ACCOUNT:
				$response['data'] = array_merge(
					self::account_items( $user_id ),
					self::library_items( $user_id )
				);
				break;

			case self::PAGE_FOLLOWS:
				$response['data'] = self::follows_items( $user_id );
				break;

			case self::PAGE_ACTIVITY:
				$response['data'] = self::activity_items( $user_id );
				break;

			case self::PAGE_INVITES:
				$response['data'] = self::invite_items( $user_id );
				break;
		}

		return $response;
	}

	/**
	 * Erase everything this plugin holds about one member (AC-052 b,d,e,f).
	 *
	 * The removal itself is {@see GameLib_Privacy::purge_user()}; what belongs
	 * here is the shape Tools → Erase Personal Data reads — `items_removed`,
	 * `items_retained`, `messages`, `done` — and the AC-052(d) report of the
	 * redeemed invites that survive the purge on purpose.
	 *
	 * Idempotent: a second run over the same member removes nothing and says so,
	 * while still reporting the retained invites, because they are still there.
	 *
	 * Paged, and every page does real work (PB-3). Each call removes up to
	 * {@see PURGE_BATCH} rows per domain and answers `done => false` while any
	 * domain still reports rows, so core calls again; the retained-invite message
	 * is composed only on the last page, where it is final. The previous shape —
	 * refusing every page above 1 and reporting `done => true` — meant a partial
	 * erasure reported itself complete.
	 *
	 * @param string $email_address Member's email address.
	 * @param int    $page          1-based page number.
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool} Eraser result.
	 */
	public static function erase( $email_address, $page = 1 ) {
		$response = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		$user = self::user_for_email( $email_address );

		if ( null === $user ) {
			return $response;
		}

		$user_id = (int) $user->ID;
		$purged  = self::purge_user( $user_id, array( 'limit' => self::PURGE_BATCH ) );

		$response['items_removed'] = ( $purged['total'] > 0 );

		if ( ! empty( $purged['remaining'] ) ) {
			// More rows than one request should delete. Core will call again
			// with the next page number; nothing is reported as final yet.
			$response['done'] = false;

			return $response;
		}

		// Read after the purge: it deletes outstanding and revoked rows only, so
		// what is left under this issuer is exactly what was retained.
		$counts   = GameLib_Invites::counts_for_user( $user_id );
		$retained = isset( $counts[ GameLib_Invites::STATUS_REDEEMED ] )
			? (int) $counts[ GameLib_Invites::STATUS_REDEEMED ]
			: 0;

		if ( $retained > 0 ) {
			$response['items_retained'] = true;
			$response['messages'][]     = sprintf(
				/* translators: %s: number of redeemed invites, already formatted. */
				_n(
					'%s invite issued by this member was redeemed and is kept as the record of how the account it created was registered.',
					'%s invites issued by this member were redeemed and are kept as the record of how the accounts they created were registered.',
					$retained,
					'game-library'
				),
				number_format_i18n( $retained )
			);
		}

		/*
		 * The second thing that survives, and until now the unreported one
		 * (SE-1). No privacy path touches `gamelib_imports` or
		 * `gamelib_import_items`: AC-052's enumeration does not name them, and
		 * `principal/adr/034-import-history-is-outside-the-erasure.md` records
		 * the decision and the open question behind it.
		 *
		 * Those rows hold the source name of every row a member imported —
		 * including rows that never entered their library — so an eraser that
		 * answered `done` without mentioning them let core mail the data subject
		 * a completion confirmation for data that is still there. What
		 * `items_retained` is for.
		 */
		$imports = GameLib_Importer::counts_for_user( $user_id );

		if ( $imports['imports'] > 0 ) {
			/*
			 * One count per string, two strings (CO-5, AC-NFR-008). `messages`
			 * is an array and core renders each entry on its own line, which is
			 * what makes this possible — the two counts vary independently, and
			 * a single sentence pluralized on the first while interpolating the
			 * second told a member who imported one row "1 import … together
			 * with the 1 rows it staged". No locale could repair that: the row
			 * count was not a plural argument at all, so a language whose plural
			 * rules differ between the two numbers could not express it.
			 */
			$response['items_retained'] = true;
			$response['messages'][]     = sprintf(
				/* translators: %s: number of imports, already formatted. */
				_n(
					'%s import run by this member is kept. Import history is not removed by an erasure.',
					'%s imports run by this member are kept. Import history is not removed by an erasure.',
					$imports['imports'],
					'game-library'
				),
				number_format_i18n( $imports['imports'] )
			);
			$response['messages'][]     = sprintf(
				/* translators: %s: number of staged import rows, already formatted. */
				_n(
					'%s row staged by that import is kept with it — a game title read from the file or Steam profile it was given, whether or not it entered the library.',
					'%s rows staged by those imports are kept with them — the game titles read from the files or Steam profiles they were given, including titles that never entered the library.',
					$imports['items'],
					'game-library'
				),
				number_format_i18n( $imports['items'] )
			);
		}

		return $response;
	}

	/**
	 * Purge a deleted account's data (AC-052c).
	 *
	 * `deleted_user` fires after the user row is gone, so this is the last stop
	 * for the rows that referenced it. It runs the same routine the eraser does
	 * — one purge, two entry points.
	 *
	 * Bounded and chained, exactly like the eraser pages (VIP-1). The hook has
	 * no caller that will come back for a second page, so it supplies its own:
	 * one {@see PURGE_BATCH} pass per request, and a
	 * {@see GameLib_Plugin::CRON_PURGE_HOOK} single event for the remainder,
	 * which repeats until nothing is left. An unbounded walk here was a
	 * data-retention hazard rather than a slow request — a request killed inside
	 * the first domain left every follow edge and activity row of an account
	 * that no longer exists, in a hook that can never fire again, so a deleted
	 * member's events stayed in their followers' feeds indefinitely (AC-052e).
	 *
	 * @param int $user_id Member whose account was deleted.
	 * @return void
	 */
	public static function on_deleted_user( $user_id ) {
		self::purge_pass( $user_id );
	}

	/**
	 * Continue a `deleted_user` purge that ran out of budget (VIP-1).
	 *
	 * The callback behind {@see GameLib_Plugin::CRON_PURGE_HOOK}, and its own
	 * caller: every pass that stops short schedules the next one, so the chain
	 * ends only when a pass reports nothing remaining.
	 *
	 * @param int $user_id Member whose account was deleted.
	 * @return void
	 */
	public static function run_purge( $user_id ) {
		self::purge_pass( $user_id );
	}

	/**
	 * Pick up the members whose chain could not be scheduled
	 * (VIP-1/CO-5/SE-2).
	 *
	 * Registered on {@see GameLib_Plugin::CRON_REFRESH_HOOK} — the plugin's one
	 * recurring event, which is already scheduled on every install and already
	 * runs on VIP, so a stranded purge needs no schedule of its own to be
	 * recovered by.
	 *
	 * An id stays on the list across its own pass, and comes off only once that
	 * pass reports something else is responsible for it (VIP-1). Taking it off
	 * first inverted the invariant {@see continue_purge()} is built on: between
	 * the {@see forget_pending()} write and the end of the pass the member
	 * existed in no durable store at all — the account row is gone, so
	 * `deleted_user` cannot fire again, no {@see GameLib_Plugin::CRON_PURGE_HOOK}
	 * event is scheduled yet, and the id is off the list — so a request that
	 * died in that window stranded the account permanently, with its follow
	 * edges and activity rows still live in other members' feeds (AC-052e) and
	 * no detection surface. Not theoretical on VIP: this rides a Cron Control
	 * event that has already spent time on an IGDB batch before the purge walk
	 * starts, and a long-running Cron Control callback is terminated.
	 *
	 * The order is safe both ways round: {@see remember_pending()} returns true
	 * for an id already present, so a pass that has to re-record is a no-op, and
	 * {@see continue_purge()} forgets a finished id itself, so the call below is
	 * idempotent.
	 *
	 * Bounded per tick twice (PB-3): by {@see PURGE_DRAIN_PER_TICK} ids, and by
	 * the same wall clock every other purge path answers to. The id cap alone
	 * does not bound the work — during a `pre_schedule_event` outage every id
	 * costs one full purge batch whether or not the tick has anything left, so
	 * one or two expensive members could hand the remaining forty-eight a batch
	 * each on top of an already-spent budget. A fresh tick still always
	 * processes at least one id: {@see budget_spent()}'s first call in a request
	 * *arms* the deadline and answers false, and the `$purged_this_request`
	 * half of the guard covers the only case where the first iteration could
	 * already be over budget — a `gamelib_purge_user` event that ran earlier in
	 * this same cron request.
	 *
	 * @return void
	 */
	public static function drain_pending() {
		foreach ( array_slice( self::pending_ids(), 0, self::PURGE_DRAIN_PER_TICK ) as $user_id ) {
			// PB-3: stop once this tick's budget is spent. Every id past that
			// point still costs a full purge batch — a refused schedule routes
			// it through continue_purge() to a walk_bounded() whose do/while
			// runs one batch before it looks at the clock.
			if ( self::budget_spent() && self::$purged_this_request > 0 ) {
				break;
			}

			if ( self::purge_pass( $user_id ) ) {
				self::forget_pending( $user_id );
			}
		}
	}

	/**
	 * One bounded purge pass, re-arming itself while rows remain (VIP-1).
	 *
	 * @param int $user_id Member whose account was deleted.
	 * @return bool True when this member is finished, or a successor pass is
	 *              scheduled for them. False while the id is still owed a pass,
	 *              which is what keeps it on {@see PURGE_PENDING_OPTION}.
	 */
	private static function purge_pass( $user_id ) {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			return true;
		}

		/*
		 * Before any work: this request has spent its budget, so this member is
		 * scheduled rather than walked (PB-2). Nothing else bounds a bulk delete
		 * — `deleted_user` fires once per selected member, with no shared budget
		 * between them.
		 */
		if ( self::budget_spent() ) {
			return self::continue_purge( $user_id, false );
		}

		$purged = self::purge_user( $user_id, array( 'limit' => self::PURGE_BATCH ) );

		self::$purged_this_request += (int) $purged['total'];

		if ( empty( $purged['remaining'] ) ) {
			return true;
		}

		return self::continue_purge( $user_id );
	}

	/**
	 * Has this request done as much purging as it should (PB-1)?
	 *
	 * Two budgets for two request types, because the thing worth bounding is
	 * different in each. A synchronous `wp-admin` POST is bounded by rows: the
	 * first member deleted gets the whole detach-from-feeds pass and every
	 * member after that is handed to cron, which is what keeps a fifty-member
	 * bulk delete inside the platform's request ceiling. Cron and WP-CLI are
	 * bounded by wall clock instead — they are precisely the contexts that
	 * *should* be allowed to work, and the row budget there throttled a whole
	 * cron run to one member's worth of rows while every other due event
	 * re-scheduled itself at `time()` and renewed the deferral instead of
	 * draining it.
	 *
	 * The deadline is taken on the first pass of the request, so it bounds the
	 * purging rather than the request that happens to contain it.
	 *
	 * @return bool True when further members should be deferred.
	 */
	private static function budget_spent() {
		if ( ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return self::$purged_this_request >= self::REQUEST_BUDGET;
		}

		if ( 0.0 === self::$purge_deadline ) {
			self::$purge_deadline = microtime( true ) + self::PURGE_TICK_SECONDS;
		}

		return microtime( true ) >= self::$purge_deadline;
	}

	/**
	 * Hand the rest of a member's purge to the next pass — or, when nothing will
	 * come, finish it here (VIP-1/CO-5/SE-2).
	 *
	 * `wp_schedule_single_event()` returns false whenever `pre_schedule_event`
	 * refuses, and on VIP that filter is owned by Cron Control, where it is also
	 * where the event is written into Cron Control's own store: a store-unavailable
	 * window, or any client mu-plugin filter, ends the chain. Discarding the
	 * return value is how a deleted member's follow edges and activity rows stay
	 * live in other members' feeds forever (AC-052e) — `deleted_user` cannot fire
	 * again, the account row is gone, and unlike the importer and the refresh job
	 * there is no job row or option cursor holding the member's id.
	 *
	 * So a refusal takes both recoveries, which are complementary rather than
	 * alternative:
	 *
	 * 1. The id is written to {@see PURGE_PENDING_OPTION} *first*, so a fatal in
	 *    the walk below is still recoverable — the refresh tick drains it.
	 * 2. Then the walk is finished in-request.
	 *
	 * **A refusal always walks** (SE-1). The early return this used to take when
	 * the id was recorded and the request was over budget made that record the
	 * member's *sole* custodian — and the record is maintained through a
	 * non-atomic read-modify-write, so a concurrent writer can clobber the id
	 * off it (CWE-362). A clobbered id on that path left the member neither
	 * scheduled, nor recorded, nor walked: the stranded state this whole
	 * mechanism exists to prevent. Walking regardless costs one bounded pass and
	 * makes the option a recovery net rather than a single point of failure —
	 * everywhere else the race is already harmless, because a stale id is
	 * idempotently re-purged to a no-op and a lost one was going to be walked
	 * in-request anyway.
	 *
	 * How far that walk goes depends on whether anything else is coming, which
	 * is exactly what `$recorded` answers:
	 *
	 * - **Recorded** — bounded by wall clock, and the id stays on the list
	 *   (VIP-2). The unbounded form here was the cycle-3 defect reintroduced on
	 *   the refusal branch: an admin bulk-delete POST walking a member's entire
	 *   remaining follows + activity + library, after already spending a full
	 *   {@see REQUEST_BUDGET}, is killed at VIP's request ceiling and the
	 *   administrator gets a 502 on `users.php` with no idea what state the
	 *   deletion is in. The trade-off argued above was written for the case
	 *   where nothing else is coming; once the id is on the list, the refresh
	 *   tick is.
	 * - **Not recorded** — unbounded, and that is load-bearing (PB-2). An id
	 *   that could not be recorded has no successor of any kind, so
	 *   unbounded-but-complete really does beat bounded-but-stranded. It is also
	 *   why {@see PURGE_PENDING_MAX} is sized to make this branch unreachable
	 *   rather than sized to the option's bytes.
	 *
	 * @param int  $user_id           Member whose account was deleted.
	 * @param bool $finish_in_request Optional. False where the request has
	 *                                already spent its budget (PB-1/PB-2), which
	 *                                schedules the continuation further out and
	 *                                is the only thing this now decides — both
	 *                                walks below run either way (SE-1).
	 * @return bool True when the member is finished or a successor pass is
	 *              scheduled. False while the id is still owed one.
	 */
	private static function continue_purge( $user_id, $finish_in_request = true ) {
		/*
		 * A pass deferred because a *budget* is spent goes a minute out, not to
		 * `time()` (PB-1). At `time()` it is due again on the very next cron
		 * pass — where the same budget is just as likely to be spent — so the
		 * deferral renews itself, rewriting the autoloaded `cron` option twice
		 * per member per pass and deleting nothing. The real-work continuation
		 * keeps `time()`: that member has rows waiting and the request that
		 * would have removed them has simply run out of room.
		 */
		$timestamp = $finish_in_request ? time() : time() + MINUTE_IN_SECONDS;

		if ( self::schedule_purge( $user_id, $timestamp ) ) {
			return true;
		}

		$recorded = self::remember_pending( $user_id );

		if ( ! $recorded ) {
			// Nothing else is coming for this id, so this walk is the only one
			// it will ever get (PB-2).
			$purged = self::purge_user( $user_id );

			self::$purged_this_request += (int) $purged['total'];

			return empty( $purged['remaining'] );
		}

		/*
		 * Scope the finish-walk to what THIS tick has left, not a flat
		 * constant, whenever a cron/CLI deadline is active (PB-1, cycle-7).
		 * `$purge_deadline` is non-zero only past the first
		 * {@see budget_spent()} call of a cron/CLI request; a wp-admin POST
		 * never sets it, so it keeps the full ten seconds VIP-2 measured and
		 * prescribed. A cron/CLI tick that already spent its whole
		 * {@see PURGE_TICK_SECONDS} clamps to (near) zero, which still runs
		 * {@see walk_bounded()}'s first batch — the do-while checks its
		 * budget after that batch, not before — and then stops instead of
		 * spending up to ten more seconds per remaining member.
		 */
		$finish_budget = ( 0.0 !== self::$purge_deadline )
			? max( 0.0, min( self::PURGE_FINISH_SECONDS, self::$purge_deadline - microtime( true ) ) )
			: self::PURGE_FINISH_SECONDS;

		$finished = self::walk_bounded( $user_id, $finish_budget );

		if ( $finished ) {
			self::forget_pending( $user_id );
		}

		return $finished;
	}

	/**
	 * Walk a recorded member's remaining rows under a wall-clock budget
	 * (VIP-2, SE-1, PB-1 cycle-7).
	 *
	 * The same shape the importer's tick uses (`GAMELIB_IMPORT_TICK_SECONDS`):
	 * batch, commit, ask the clock. Every domain walk commits in
	 * `PURGE_BATCH` increments and bumps its cache scopes per batch, so
	 * stopping short loses no work — and the id is on
	 * {@see PURGE_PENDING_OPTION}, so {@see drain_pending()} picks the
	 * remainder up on the next refresh tick. The detach-from-feeds pass
	 * AC-052(e) cares about is the first thing {@see purge_user()} does, so it
	 * stays as immediate as it was.
	 *
	 * `$budget` defaults to the full {@see PURGE_FINISH_SECONDS} — a
	 * wp-admin POST, which never sets {@see $purge_deadline}, always calls
	 * with the default. {@see continue_purge()} passes a smaller value once
	 * a cron/CLI tick's own budget is already spent, so this loop still runs
	 * its first batch (the check is after the first iteration, same as
	 * before) and then stops rather than spending a fresh, unrelated ten
	 * seconds on top of a budget the caller has already exhausted.
	 *
	 * @param int   $user_id Member whose account was deleted.
	 * @param float $budget  Optional. Wall-clock seconds this walk may run.
	 *                       Default {@see PURGE_FINISH_SECONDS}.
	 * @return bool True when nothing is left to purge.
	 */
	private static function walk_bounded( $user_id, $budget = self::PURGE_FINISH_SECONDS ) {
		$started   = microtime( true );
		$remaining = true;

		do {
			$purged = self::purge_user( $user_id, array( 'limit' => self::PURGE_BATCH ) );

			self::$purged_this_request += (int) $purged['total'];
			$remaining                  = ! empty( $purged['remaining'] );
		} while ( $remaining && ( microtime( true ) - $started ) < $budget );

		return ! $remaining;
	}

	/**
	 * Schedule the next pass of a purge, and say whether one is coming.
	 *
	 * Mirrors {@see GameLib_Library::schedule_bulk()}, the same shape the other
	 * three chained jobs in this plugin already use: `$wp_error => true`, a
	 * `duplicate_event` refusal treated as success, and anything else treated as
	 * a failure the caller has to handle.
	 *
	 * The caller chooses when (PB-1). `time()` for a continuation that has work
	 * waiting — the remainder is data about an account that no longer exists —
	 * and a minute out for one deferred because a budget is spent, which at
	 * `time()` would simply be due again on the next pass. Core's
	 * duplicate-event guard normally cannot refuse the chained call — the
	 * running event is unscheduled before its callback fires — but it can refuse
	 * the one {@see drain_pending()} makes for an id whose earlier event did
	 * land, and that refusal is a success: a pass is already due for this
	 * member.
	 *
	 * @param int $user_id   Member whose account was deleted.
	 * @param int $timestamp When the pass should run, as a Unix timestamp.
	 * @return bool True when a further pass is scheduled.
	 */
	private static function schedule_purge( $user_id, $timestamp ) {
		$scheduled = wp_schedule_single_event( $timestamp, GameLib_Plugin::CRON_PURGE_HOOK, array( $user_id ), true );

		if ( is_wp_error( $scheduled ) ) {
			return 'duplicate_event' === $scheduled->get_error_code();
		}

		return false !== $scheduled;
	}

	/**
	 * The member ids waiting for a pass nothing scheduled.
	 *
	 * @return int[] Positive ids, de-duplicated.
	 */
	private static function pending_ids() {
		$pending = get_option( self::PURGE_PENDING_OPTION, array() );

		if ( ! is_array( $pending ) ) {
			return array();
		}

		$ids = array();

		foreach ( $pending as $value ) {
			$id = absint( $value );

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Remember a member whose continuation could not be scheduled.
	 *
	 * @param int $user_id Member whose account was deleted.
	 * @return bool True when the id is on the list — including when it already
	 *              was. False only when the list is full, which tells the caller
	 *              it has to finish the walk itself.
	 */
	private static function remember_pending( $user_id ) {
		$ids = self::pending_ids();

		if ( in_array( $user_id, $ids, true ) ) {
			return true;
		}

		if ( count( $ids ) >= self::PURGE_PENDING_MAX ) {
			return false;
		}

		$ids[] = $user_id;

		update_option( self::PURGE_PENDING_OPTION, $ids, false );

		return true;
	}

	/**
	 * Drop a member from the pending list.
	 *
	 * @param int $user_id Member whose account was deleted.
	 * @return void
	 */
	private static function forget_pending( $user_id ) {
		$ids  = self::pending_ids();
		$left = array_values( array_diff( $ids, array( $user_id ) ) );

		if ( count( $left ) === count( $ids ) ) {
			return;
		}

		if ( empty( $left ) ) {
			delete_option( self::PURGE_PENDING_OPTION );

			return;
		}

		update_option( self::PURGE_PENDING_OPTION, $left, false );
	}

	/**
	 * The shared purge behind the eraser and `deleted_user` (AC-052 b,c,e,f).
	 *
	 * Every statement belongs to the class that owns its table, and each of
	 * those deletes in batches and bumps the generation scopes its rows feed:
	 * the owner's library scope, both endpoints' follow scopes, and the shared
	 * activity scope — which is what empties this member out of their followers'
	 * feeds on the very next read rather than in fifteen minutes (AC-052e).
	 *
	 * Not touched, in any privacy path, and the list is exhaustive:
	 *
	 * - `glib_game` posts and `gamelib_games` rows. The game store is shared,
	 *   IGDB-sourced, and holds nothing about any member (AC-052f).
	 * - `gamelib_imports` and `gamelib_import_items` (SE-1). These are the
	 *   member's own data — a source name per row, every title read from the
	 *   file or Steam profile, rows that never entered the library included —
	 *   and they are *not* purged: AC-052's enumeration does not name them.
	 *   {@see erase()} therefore reports them through `items_retained`, and
	 *   `principal/adr/034-import-history-is-outside-the-erasure.md` records the
	 *   decision, what it costs, and the ruling that would reverse it. A reader
	 *   who takes this list as "everything the plugin holds" is being misled
	 *   otherwise.
	 * - `gamelib_invites_disabled`, the AC-007(b) administrator override (see
	 *   the class docblock).
	 *
	 * The three unbounded domains take a per-call row limit and report whether
	 * they stopped short (PB-3); `remaining` is true when any of them did, which
	 * is what {@see erase()} pages on. The user meta and the invites are removed
	 * on every call: both are bounded — one row each for the meta, and an
	 * issuer's unredeemed invites are capped by their allowance — and the meta
	 * deletes are what make the member's visibility and Steam link stop applying
	 * immediately rather than after the last page.
	 *
	 * @param int   $user_id Member being purged.
	 * @param array $args    Optional. `limit` (int) — rows per unbounded domain;
	 *                       0, the default, purges each to exhaustion.
	 * @return array{library:int,follows:int,activity:int,invites:int,meta:int,total:int,remaining:bool}
	 *         What was removed, by area, and whether any domain has rows left.
	 */
	public static function purge_user( $user_id, array $args = array() ) {
		$user_id = absint( $user_id );
		$limit   = isset( $args['limit'] ) ? max( 0, absint( $args['limit'] ) ) : 0;

		$purged = array(
			'library'   => 0,
			'follows'   => 0,
			'activity'  => 0,
			'invites'   => 0,
			'meta'      => 0,
			'total'     => 0,
			'remaining' => false,
		);

		if ( $user_id <= 0 ) {
			return $purged;
		}

		$bounded = array( 'limit' => $limit );

		/*
		 * Follows first, then activity, then the library (VIP-1). The order is
		 * the compliance order rather than the schema order: detaching the member
		 * from every feed is what AC-052(e) is about, and those two domains are
		 * also the ones whose rows are visible to *other* members. A pass that
		 * never gets a successor — a `deleted_user` request killed mid-walk —
		 * therefore leaves behind the member's own library rows, which nothing
		 * renders once the account is gone, rather than follow edges and events
		 * that keep surfacing in followers' feeds.
		 */
		$follows  = GameLib_Follows::purge_user( $user_id, $bounded );
		$activity = GameLib_Activity::purge_user( $user_id, $bounded );
		$library  = GameLib_Library::purge_user( $user_id, $bounded );

		$purged['library']  = (int) $library['deleted'];
		$purged['follows']  = (int) $follows['deleted'];
		$purged['activity'] = (int) $activity['deleted'];
		$purged['invites']  = (int) GameLib_Invites::purge_user( $user_id );
		$purged['meta']     = self::purge_meta( $user_id );

		$purged['remaining'] = ! empty( $library['remaining'] )
			|| ! empty( $follows['remaining'] )
			|| ! empty( $activity['remaining'] );

		$purged['total'] = $purged['library'] + $purged['follows'] + $purged['activity']
			+ $purged['invites'] + $purged['meta'];

		return $purged;
	}

	/**
	 * Suggested privacy-policy text (AC-053).
	 *
	 * Runs on `admin_init`, which is the only context core accepts this call
	 * from. The `privacy-policy-tutorial` strings are instructions to the site
	 * owner — the Privacy Policy Guide shows them but never copies them into the
	 * policy — and one of them is the AC-053(b) placeholder the owner has to
	 * complete before publishing.
	 *
	 * @return void
	 */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p class="privacy-policy-tutorial">'
			. esc_html__( 'This site sends member data to two third parties — the IGDB game database and, for members who import from Steam, the Steam Web API. The suggested text below describes both. Complete the country placeholder before you publish your policy.', 'game-library' )
			. '</p>';

		$content .= '<p><strong class="privacy-policy-tutorial">'
			. esc_html__( 'Suggested text:', 'game-library' )
			. '</strong> '
			. esc_html__( 'Game information on this site comes from IGDB, a video game database operated by Twitch Interactive, Inc., an Amazon company. When you search for a game to add to your library, the words you type are sent to the IGDB API so that it can answer the search. Your searches are not stored next to your account: this site never logs game search queries against your username, your member ID, or any other account identifier.', 'game-library' )
			. '</p>';

		$content .= '<p><strong class="privacy-policy-tutorial">'
			. esc_html__( 'Suggested text:', 'game-library' )
			. '</strong> '
			. esc_html__( 'If you choose to import your games from your public Steam profile, this site sends your Steam account identifier (SteamID) to the Steam Web API, operated by Valve Corporation, and reads the list of games your Steam profile shows publicly. Your SteamID is stored with your account so that you can run the import again later. You can remove it yourself at any time with the Disconnect action on your library page, and it is removed when you ask for your personal data to be erased. Steam data is used only to build your library on this site — never for marketing, advertising, or profiling.', 'game-library' )
			. '</p>';

		$content .= '<p><strong class="privacy-policy-tutorial">'
			. esc_html__( 'Replace [COUNTRY WHERE STEAM DATA IS STORED] below with the country in which this site stores Steam Data, then delete this instruction.', 'game-library' )
			. '</strong> '
			. esc_html__( 'Steam Data — your SteamID and the game list imported from your Steam profile — is stored in [COUNTRY WHERE STEAM DATA IS STORED].', 'game-library' )
			. '</p>';

		$content .= '<p><strong class="privacy-policy-tutorial">'
			. esc_html__( 'Suggested text:', 'game-library' )
			. '</strong> '
			. esc_html__( 'Your library, the members you follow and the members who follow you, your activity history, the invites you issued, your SteamID, and your profile visibility setting are all included when you request an export of your personal data, and all removed when you request an erasure. Two things are kept. An invite that somebody has already redeemed is kept, because it is the record of how that member’s account was created. The record of each import you have run is also kept — the list of game titles read from the file or Steam profile it was given, including titles that were never added to your library — as the record of how this site built your library; it is not included in an export and it is not removed by an erasure.', 'game-library' )
			. '</p>';

		wp_add_privacy_policy_content(
			__( 'Game Library', 'game-library' ),
			wp_kses_post( $content )
		);
	}

	/**
	 * Delete the plugin's user meta and everything cached behind it.
	 *
	 * The SteamID goes first, together with the owned-games list read on behalf
	 * of that Steam account, so nothing about it outlives the erasure by the
	 * half hour that cache would otherwise live. Removing the visibility value
	 * returns the member to the members-only default (AC-027a), which is a
	 * change of access: the visibility generation scope is bumped so the sitemap
	 * provider drops the profile on its next render, and the profile URL goes
	 * through the best-effort edge purge (AC-029c).
	 *
	 * @param int $user_id Member being purged.
	 * @return int Meta values deleted.
	 */
	private static function purge_meta( $user_id ) {
		$removed = 0;

		$steamid = GameLib_Steam_Client::sanitize_steamid64(
			get_user_meta( $user_id, GameLib_Steam_Client::STEAMID_META, true )
		);

		if ( delete_user_meta( $user_id, GameLib_Steam_Client::STEAMID_META ) ) {
			++$removed;
		}

		if ( '' !== $steamid ) {
			GameLib_Steam_Client::delete_owned_games_cache( $steamid );
		}

		/*
		 * Read both before the delete: after it the member is members-only by
		 * default, and after `deleted_user` the profile URL cannot be composed
		 * at all (the user row is already gone, which is why the purge is
		 * best-effort there and exact in the eraser).
		 */
		$was_public  = GameLib_Visibility::is_public( $user_id );
		$profile_url = GameLib_Visibility::profile_url( $user_id );

		if ( delete_user_meta( $user_id, GameLib_Visibility::META_KEY ) ) {
			++$removed;

			GameLib_Cache::bump( GameLib_Cache::SCOPE_VISIBILITY );

			if ( $was_public && '' !== $profile_url ) {
				GameLib_Visibility::purge_url( $profile_url );
			}
		}

		return $removed;
	}

	/**
	 * The account-level export item: visibility, and the SteamID if one is
	 * stored (AC-046c, AC-052a).
	 *
	 * @param int $user_id Member being exported.
	 * @return array[] Zero or one export item.
	 */
	private static function account_items( $user_id ) {
		$item = self::item(
			self::GROUP_ACCOUNT,
			'gamelib-account-' . $user_id,
			array(
				__( 'Profile visibility', 'game-library' ) => GameLib_Visibility::visibility_label( GameLib_Visibility::get( $user_id ) ),
				__( 'Profile URL', 'game-library' )        => GameLib_Visibility::profile_url( $user_id ),
				__( 'Steam ID', 'game-library' )           => GameLib_Steam_Client::sanitize_steamid64(
					get_user_meta( $user_id, GameLib_Steam_Client::STEAMID_META, true )
				),
			)
		);

		return ( null === $item ) ? array() : array( $item );
	}

	/**
	 * One export item per library entry (AC-052a).
	 *
	 * The walk itself belongs to {@see GameLib_Library::export_rows()}, which
	 * hands over one keyset-paged batch at a time and drops it (PB-8); what
	 * survives here is one export item per row, which is the answer being built.
	 *
	 * @param int $user_id Member being exported.
	 * @return array[] Export items.
	 */
	private static function library_items( $user_id ) {
		$items = array();

		GameLib_Library::export_rows(
			$user_id,
			static function ( array $rows ) use ( &$items ) {
				foreach ( $rows as $row ) {
					$igdb_id = isset( $row['igdb_id'] ) ? (int) $row['igdb_id'] : 0;

					$item = self::item(
						self::GROUP_LIBRARY,
						'gamelib-library-' . $igdb_id,
						array(
							__( 'Game', 'game-library' )    => isset( $row['title'] ) ? $row['title'] : '',
							__( 'IGDB ID', 'game-library' ) => ( $igdb_id > 0 ) ? (string) $igdb_id : '',
							__( 'Status', 'game-library' )  => self::status_label( isset( $row['status'] ) ? $row['status'] : '' ),
							__( 'Date added', 'game-library' ) => self::format_datetime( isset( $row['date_added'] ) ? $row['date_added'] : '', false ),
						)
					);

					if ( null !== $item ) {
						$items[] = $item;
					}
				}
			}
		);

		return $items;
	}

	/**
	 * One export item per follow edge, in both directions (AC-052a).
	 *
	 * Each counterpart is named, because who a member follows — and who follows
	 * them — is the edge, and an id alone would not be a readable answer.
	 *
	 * The only walk of a whole follow graph in the plugin, and the two options it
	 * passes are both there for that reason. `after_id` takes the keyset form
	 * (PB-11): the offset form re-scans everything it skips, so a member with
	 * 100,000 followers cost 500 statements with offsets climbing to 99,800.
	 * `cache => false` keeps the pages it loads out of the shared object cache,
	 * which would otherwise hold a 15-minute entry per page that nothing will
	 * ever read from (PB-7).
	 *
	 * @param int $user_id Member being exported.
	 * @return array[] Export items.
	 */
	private static function follows_items( $user_id ) {
		$items = array();

		$directions = array(
			'following' => __( 'You follow this member', 'game-library' ),
			'follower'  => __( 'This member follows you', 'game-library' ),
		);

		foreach ( $directions as $direction => $relationship ) {
			$after = 0;

			do {
				$args = array(
					'after_id' => $after,
					'cache'    => false,
				);

				$ids = ( 'following' === $direction )
					? GameLib_Follows::following( $user_id, self::FOLLOWS_BATCH, $args )
					: GameLib_Follows::followers( $user_id, self::FOLLOWS_BATCH, $args );

				$read = count( $ids );

				if ( $read > 0 ) {
					// The ids are the keyset column itself, ascending, so the
					// page's own contents carry the cursor for the next one.
					$after = max( $ids );

					// One prime for the batch: a per-edge get_userdata() on a
					// cold cache would be an N+1 (WPP-05).
					cache_users( $ids );
				}

				foreach ( $ids as $id ) {
					$member = get_userdata( $id );

					if ( ! $member instanceof WP_User ) {
						continue;
					}

					$item = self::item(
						self::GROUP_FOLLOWS,
						'gamelib-' . $direction . '-' . (int) $id,
						array(
							__( 'Relationship', 'game-library' ) => $relationship,
							__( 'Member', 'game-library' )       => $member->display_name,
							__( 'Profile', 'game-library' )      => GameLib_Visibility::profile_url( $id ),
						)
					);

					if ( null !== $item ) {
						$items[] = $item;
					}
				}
			} while ( self::FOLLOWS_BATCH === $read );
		}

		return $items;
	}

	/**
	 * One export item per activity event the member authored (AC-052a).
	 *
	 * Read through the same keyset cursor the feed uses, so the walk is a series
	 * of indexed reads down the id order rather than a deepening OFFSET — and
	 * with `cache => false`, because a member with 50,000 events would otherwise
	 * write ~1,000 pages nothing will read into the shared object cache,
	 * evicting live feed and library entries under LRU (PB-7).
	 *
	 * @param int $user_id Member being exported.
	 * @return array[] Export items.
	 */
	private static function activity_items( $user_id ) {
		$items  = array();
		$cursor = 0;

		do {
			$rows = GameLib_Activity::for_member(
				$user_id,
				array(
					'before' => $cursor,
					'limit'  => self::ACTIVITY_BATCH,
					'cache'  => false,
				)
			);

			foreach ( $rows as $row ) {
				$igdb_id = isset( $row['igdb_id'] ) ? (int) $row['igdb_id'] : 0;

				$item = self::item(
					self::GROUP_ACTIVITY,
					'gamelib-activity-' . ( isset( $row['id'] ) ? (int) $row['id'] : 0 ),
					array(
						__( 'Event', 'game-library' )   => self::event_label( isset( $row['type'] ) ? (string) $row['type'] : '' ),
						__( 'IGDB ID', 'game-library' ) => ( $igdb_id > 0 ) ? (string) $igdb_id : '',
						__( 'Details', 'game-library' ) => self::event_details(
							isset( $row['type'] ) ? (string) $row['type'] : '',
							isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : array()
						),
						__( 'Date', 'game-library' )    => self::format_datetime( isset( $row['created_at'] ) ? $row['created_at'] : '' ),
					)
				);

				if ( null !== $item ) {
					$items[] = $item;
				}
			}

			$read   = count( $rows );
			$cursor = GameLib_Activity::next_cursor( $rows );
		} while ( self::ACTIVITY_BATCH === $read && $cursor > 0 );

		return $items;
	}

	/**
	 * One export item per invite the member issued (AC-052a).
	 *
	 * The redeemer is deliberately not named: whose account an invite created is
	 * that member's data, not this one's.
	 *
	 * @param int $user_id Member being exported.
	 * @return array[] Export items.
	 */
	private static function invite_items( $user_id ) {
		$items  = array();
		$offset = 0;

		do {
			$rows = GameLib_Invites::list_for_user( $user_id, self::INVITES_BATCH, $offset );

			foreach ( $rows as $row ) {
				$invite = GameLib_Invites::payload( $row );

				$item = self::item(
					self::GROUP_INVITES,
					'gamelib-invite-' . $invite['id'],
					array(
						__( 'Invite code', 'game-library' ) => $invite['code'],
						__( 'Status', 'game-library' )      => self::invite_status_label( $invite['status'] ),
						__( 'Created', 'game-library' )     => self::format_datetime( $invite['created_at'] ),
						__( 'Redeemed', 'game-library' )    => self::format_datetime( $invite['redeemed_at'] ),
					)
				);

				if ( null !== $item ) {
					$items[] = $item;
				}
			}

			$read    = count( $rows );
			$offset += $read;
		} while ( self::INVITES_BATCH === $read );

		return $items;
	}

	/**
	 * Compose one export item, dropping the fields that have no value.
	 *
	 * An absent field is an absent line rather than an empty one — the same rule
	 * the head output follows for the fields IGDB did not supply (AC-047f).
	 *
	 * @param string $group_id Group the item belongs to.
	 * @param string $item_id  Unique item id within the export.
	 * @param array  $fields   Field label => value.
	 * @return array{group_id:string,group_label:string,group_description:string,item_id:string,data:array}|null
	 *         The item, or null when nothing was left to report.
	 */
	private static function item( $group_id, $item_id, array $fields ) {
		$data = array();

		foreach ( $fields as $name => $value ) {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';

			if ( '' === $value ) {
				continue;
			}

			$data[] = array(
				'name'  => (string) $name,
				'value' => $value,
			);
		}

		if ( empty( $data ) ) {
			return null;
		}

		$group = self::group( $group_id );

		return array(
			'group_id'          => $group_id,
			'group_label'       => $group['label'],
			'group_description' => $group['description'],
			'item_id'           => $item_id,
			'data'              => $data,
		);
	}

	/**
	 * Label and description of an export group.
	 *
	 * @param string $group_id Group id.
	 * @return array{label:string,description:string} Group heading strings.
	 */
	private static function group( $group_id ) {
		switch ( $group_id ) {
			case self::GROUP_LIBRARY:
				return array(
					'label'       => __( 'Game library', 'game-library' ),
					'description' => __( 'The games in this member’s library, with the status and the date each one was added.', 'game-library' ),
				);

			case self::GROUP_FOLLOWS:
				return array(
					'label'       => __( 'Game library follows', 'game-library' ),
					'description' => __( 'The members this member follows, and the members who follow them.', 'game-library' ),
				);

			case self::GROUP_ACTIVITY:
				return array(
					'label'       => __( 'Game library activity', 'game-library' ),
					'description' => __( 'The activity events this member’s own actions recorded.', 'game-library' ),
				);

			case self::GROUP_INVITES:
				return array(
					'label'       => __( 'Game library invites', 'game-library' ),
					'description' => __( 'The invites this member issued.', 'game-library' ),
				);
		}

		return array(
			'label'       => __( 'Game library account', 'game-library' ),
			'description' => __( 'This member’s Game Library settings, including the Steam account they connected.', 'game-library' ),
		);
	}

	/**
	 * The member whose email address this is.
	 *
	 * @param mixed $email_address Address core passed the callback.
	 * @return WP_User|null The member, or null when no account has that address.
	 */
	private static function user_for_email( $email_address ) {
		$email = sanitize_email( is_scalar( $email_address ) ? (string) $email_address : '' );

		if ( '' === $email ) {
			return null;
		}

		$user = get_user_by( 'email', $email );

		return ( $user instanceof WP_User ) ? $user : null;
	}

	/**
	 * Render a stored UTC datetime in the site's own format.
	 *
	 * Everything this plugin stores is UTC (`gmdate()`); everything it shows a
	 * human goes through `wp_date()` (Always Do #10).
	 *
	 * @param mixed $value Stored `Y-m-d H:i:s` or `Y-m-d` value.
	 * @param bool  $time  Optional. Include the time of day. Default true.
	 * @return string Formatted date, or '' when there is nothing to format.
	 */
	private static function format_datetime( $value, $time = true ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		$format = (string) get_option( 'date_format' );

		if ( $time ) {
			$format .= ' ' . (string) get_option( 'time_format' );
		}

		return wp_date( $format, $timestamp );
	}

	/**
	 * Display label for a library status.
	 *
	 * @param mixed $status Stored status key.
	 * @return string Translated label, or the key itself when it is not one of
	 *                the four.
	 */
	private static function status_label( $status ) {
		$status = sanitize_key( is_scalar( $status ) ? (string) $status : '' );

		$labels = array(
			'playing'  => _x( 'Playing', 'library status', 'game-library' ),
			'finished' => _x( 'Finished', 'library status', 'game-library' ),
			'backlog'  => _x( 'Backlog', 'library status', 'game-library' ),
			'wishlist' => _x( 'Wishlist', 'library status', 'game-library' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Display label for an activity event type.
	 *
	 * @param string $type Stored event type.
	 * @return string Translated label, or the type itself when it is not one of
	 *                the four.
	 */
	private static function event_label( $type ) {
		$labels = array(
			GameLib_Activity::TYPE_GAME_ADDED          => __( 'Added a game', 'game-library' ),
			GameLib_Activity::TYPE_STATUS_CHANGED      => __( 'Changed a game’s status', 'game-library' ),
			GameLib_Activity::TYPE_GAMES_IMPORTED      => __( 'Imported games', 'game-library' ),
			GameLib_Activity::TYPE_BULK_STATUS_CHANGED => __( 'Changed the status of several games', 'game-library' ),
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : (string) $type;
	}

	/**
	 * The stored meta of one event, as a sentence a member can read.
	 *
	 * @param string $type Event type.
	 * @param array  $meta Stored payload.
	 * @return string Details, or '' when the type carries none.
	 */
	private static function event_details( $type, array $meta ) {
		$count = isset( $meta['count'] ) ? absint( $meta['count'] ) : 0;
		$to    = isset( $meta['to'] ) ? self::status_label( $meta['to'] ) : '';
		$from  = isset( $meta['from'] ) ? self::status_label( $meta['from'] ) : '';

		switch ( $type ) {
			case GameLib_Activity::TYPE_GAME_ADDED:
				return ( '' === $to )
					? ''
					: sprintf(
						/* translators: %s: library status such as Playing. */
						__( 'Added as %s', 'game-library' ),
						$to
					);

			case GameLib_Activity::TYPE_STATUS_CHANGED:
				return ( '' === $from || '' === $to )
					? ''
					: sprintf(
						/* translators: 1: previous library status, 2: new library status. */
						__( 'Moved from %1$s to %2$s', 'game-library' ),
						$from,
						$to
					);

			case GameLib_Activity::TYPE_GAMES_IMPORTED:
				return ( $count < 1 )
					? ''
					: sprintf(
						/* translators: %s: number of games imported, already formatted. */
						_n( '%s game imported', '%s games imported', $count, 'game-library' ),
						number_format_i18n( $count )
					);

			case GameLib_Activity::TYPE_BULK_STATUS_CHANGED:
				return ( $count < 1 || '' === $to )
					? ''
					: sprintf(
						/* translators: 1: number of games moved, already formatted, 2: library status such as Playing. */
						_n( '%1$s game moved to %2$s', '%1$s games moved to %2$s', $count, 'game-library' ),
						number_format_i18n( $count ),
						$to
					);
		}

		return '';
	}

	/**
	 * Display label for an invite status.
	 *
	 * @param string $status Stored invite status.
	 * @return string Translated label, or the status itself when it is not one
	 *                of the three.
	 */
	private static function invite_status_label( $status ) {
		$labels = array(
			GameLib_Invites::STATUS_OUTSTANDING => _x( 'Outstanding', 'invite status', 'game-library' ),
			GameLib_Invites::STATUS_REDEEMED    => _x( 'Redeemed', 'invite status', 'game-library' ),
			GameLib_Invites::STATUS_REVOKED     => _x( 'Revoked', 'invite status', 'game-library' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}
}
