<?php
/**
 * The only read/write path to `gl_invites`.
 *
 * @package Game_Library
 */

namespace Game_Library\Data;

use Game_Library\Schema;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Invite_Repository.
 *
 * Cached CRUD over the `gl_invites` table (DD-001/ADR-001) — the four-state
 * invite lifecycle (D40): `pending` → `redeemed`/`expired`/`revoked`.
 *
 * Two write paths need special care:
 *
 * - `insert_pending()` implements the DD-010 quota algorithm: insert the row,
 *   then count the inviter's non-revoked invites in the rolling 30-day window
 *   with `id <= $wpdb->insert_id`, deleting the just-inserted row and
 *   returning `gl_invite_quota_exceeded` when that count exceeds the quota.
 *   This is portable across MySQL and the SQLite integration WP Playground
 *   runs on, and correct under concurrency, because auto-increment ids are
 *   unique and totally ordered — a later concurrent insert can never be
 *   counted by an earlier one's quota check, and the deterministic ordering
 *   means exactly `invite_quota` rows survive regardless of how many of the
 *   12 concurrent requests DD-010's AC-024 scenario fires land in what order.
 * - `claim_by_code()` is the atomic redemption claim AC-027(g) depends on: a
 *   single conditional `UPDATE … WHERE code = %s AND status = 'pending' AND
 *   date_expires > %s`, decided purely by `$wpdb->rows_affected === 1`. This
 *   repository confirmed at runtime, against this project's shared Playground
 *   instance (SQLite-backed, matching the E2E environment), that this
 *   comparison reports 1 for the request whose UPDATE actually flips
 *   `pending` → `redeemed` and 0 for a second, later request racing the same
 *   code — the exact guarantee `Registration` (Task 8) needs to create
 *   exactly one account per code no matter how many concurrent submissions
 *   arrive.
 *
 * Every code-accepting public method re-uppercases its `$code` argument after
 * `sanitize_key()` (or applies the same normalisation directly). Codes are
 * always stored upper-cased (`wp_generate_password( 20, false, false )`
 * contains no characters `sanitize_key()` strips, so lower-casing is its only
 * effect on a valid code); `sanitize_key()` alone would otherwise pass a
 * lower-cased value into a lookup against an upper-cased stored value. This
 * repository confirmed at runtime that the shared Playground's SQLite backend
 * currently compares `VARCHAR` equality case-insensitively (matching MySQL's
 * default collation), so a case mismatch does not reproduce there today — the
 * explicit re-uppercase makes every lookup correct independent of that
 * collation-dependent behaviour rather than relying on it.
 *
 * A single generation counter (`Generations::invite_key()`, `invite_gen`)
 * scopes every list/aggregate read (`get_for_inviter()`, `quota_usage()`,
 * `get_page()`, `count_all()`), bumped on every write via `Generations::bump()`
 * (arch-pre-1 architecture review, finding AR-2). Unlike `Library_Repository`'s
 * per-user and global scopes, one shared counter is enough here: invite volume
 * per member is capped at `invite_quota` (1–100) and the admin list is the only
 * cross-member reader, so the extra invalidation an unrelated write causes is
 * negligible against the simplicity of one counter. Single-row lookups
 * (`get_by_id()`, `get_by_code()`) instead use an explicit `wp_cache_delete()`
 * on write, matching `Game_Repository`'s single-writer-path pattern.
 *
 * `expire_lapsed()`/`purge_expired_before()` (arch-pre-2 architecture review,
 * finding AR-1) are this table's third write path: the two passes the daily
 * `game_library_invite_maintenance` cron event needs (`Invite_Maintenance::
 * run()`), moved here from that class so its batched writes invalidate
 * through `invalidate_row()` like every other write in this file, instead of
 * the cron issuing raw `$wpdb` writes that skipped invalidation entirely.
 * `Invite_Maintenance` supplies the batch size/cutoff and no longer touches
 * `$wpdb` itself — see that class's own docblock for the reasoning behind
 * batching by `LIMIT`-then-`IN (…)` rather than `UPDATE … LIMIT`/
 * `DELETE … LIMIT`.
 *
 * `erase_for_user()` (arch-pre-4 architecture review, finding AR-1) is this
 * table's fourth write path: the two invite writes the GDPR erasure cascade
 * needs (`Erasure_Service::erase()`, AC-036 (f)/(g)), moved here from that
 * class for the same reason as the cron passes above — its writes now
 * invalidate the id/code-keyed row caches through `invalidate_row()` instead
 * of leaving them stale for up to an hour after an erasure.
 *
 * Custom tables outside `wp_posts`/`wp_postmeta` like this one are
 * recommended to go through VIP's database review process for
 * backup/restore compatibility.
 */
final class Invite_Repository {

	/**
	 * Object-cache group for every cache entry this repository reads/writes.
	 *
	 * Every `wp_cache_set()` call below uses the `HOUR_IN_SECONDS` WordPress
	 * time constant (never below the 900s floor) as its TTL — a literal WP
	 * time constant, not a class constant, because
	 * `WordPressVIPMinimum.Performance.LowExpiryCacheTime` can only
	 * statically evaluate a literal number, arithmetic on literals, or one
	 * of the named WP time constants; a `self::` class-constant reference is
	 * an unresolvable token to that sniff regardless of its actual value.
	 * Freshness comes from the `invite_gen` generation counter, not a short
	 * TTL.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'game_library';

	/**
	 * Default admin list-table page size (AC-031).
	 *
	 * @var int
	 */
	private const DEFAULT_PER_PAGE = 50;

	/**
	 * Length, in seconds, of the rolling quota window (AC-024).
	 *
	 * @var int
	 */
	private const QUOTA_WINDOW = 30 * DAY_IN_SECONDS;

	/**
	 * The four-state invite status allowlist (D40). No other file may
	 * hardcode one of these strings.
	 *
	 * @var string[]
	 */
	private const STATUSES = array( 'pending', 'redeemed', 'expired', 'revoked' );

	/**
	 * The two invite channels.
	 *
	 * @var string[]
	 */
	private const CHANNELS = array( 'email', 'link' );

	/**
	 * Inserts a new `pending` invite, then enforces the DD-010 rolling-30-day
	 * quota. On a quota breach the inserted row is deleted and this method
	 * returns `gl_invite_quota_exceeded` naming the date the oldest counted
	 * invite ages out of the window; no invite row survives that call
	 * (AC-024).
	 *
	 * On a `code` unique-key collision the underlying insert fails and this
	 * method returns `gl_invite_code_collision` without attempting the quota
	 * check — the caller (`Invite_Service`) regenerates the code and retries.
	 *
	 * @param string      $code        20-character, upper-cased invite code.
	 * @param int         $inviter_id  Issuing member.
	 * @param string|null $email       Recipient email, or null for a link invite.
	 * @param string      $channel     One of `email`/`link`.
	 * @param int         $expiry_days Days until this invite expires (1–90).
	 * @param int         $quota       The inviter's invite quota (1–100).
	 * @return array<string,mixed>|WP_Error The created invite row, or a
	 *                                       WP_Error on collision, quota
	 *                                       breach, or a database failure.
	 */
	public function insert_pending( $code, $inviter_id, $email, $channel, $expiry_days, $quota ) {
		$code        = $this->normalize_code( $code );
		$inviter_id  = absint( $inviter_id );
		$email       = ( is_string( $email ) && '' !== $email ) ? sanitize_email( $email ) : null;
		$channel     = in_array( $channel, self::CHANNELS, true ) ? $channel : 'link';
		$expiry_days = max( 1, absint( $expiry_days ) );
		$quota       = max( 1, absint( $quota ) );

		if ( '' === $code || ! $inviter_id ) {
			return new WP_Error( 'gl_invalid_invite', __( 'An invite requires a code and an inviting member.', 'game-library' ) );
		}

		$now     = gmdate( 'Y-m-d H:i:s' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + ( $expiry_days * DAY_IN_SECONDS ) );

		global $wpdb;
		$table = Schema::invites_table();

		// A code collision is expected to hit the `code` unique key and fail
		// (Invite_Service::create_invite() retries up to three times on it) —
		// a normal, benign outcome, not a real database error. wpdb's default
		// error-display behaviour (active whenever WP_DEBUG is on, confirmed
		// at runtime against the shared Playground instance) prints the full
		// failed query and a stack trace directly into the HTTP response body
		// ahead of POST /invites' own JSON, disclosing internal paths/schema
		// and breaking response.json() in invites.js (MR-5). Suppressing
		// errors only around this one expected-to-sometimes-fail call, and
		// restoring the prior setting immediately after, avoids that leak
		// without hiding errors anywhere else in the request — mirrors
		// Follow_Repository::follow()'s identical fix for the same
		// insert-may-collide shape.
		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; invalidated via Generations::bump() below.
		$inserted = $wpdb->insert(
			$table,
			array(
				'code'         => $code,
				'inviter_id'   => $inviter_id,
				'email'        => $email,
				'channel'      => $channel,
				'status'       => 'pending',
				'date_created' => $now,
				'date_expires' => $expires,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$wpdb->suppress_errors( $suppress );

		if ( false === $inserted ) {
			// CO-5 (cycle-5): every false === $inserted used to map to
			// gl_invite_code_collision unconditionally, on the assumption
			// that the code unique key was the only realistic cause —
			// but a genuine database failure (connection drop, a locked
			// table, disk full) also returns false here, and
			// create_invite() retries a collision up to three times, so a
			// real failure surfaced as "That invite code is already in
			// use." at HTTP 500 after three wasted writes against an
			// already-failing database, with gl_invite_create_failed
			// (this method's own docblock promise) unreachable.
			// $wpdb->last_error is populated even under
			// suppress_errors() (confirmed by reading wpdb::insert()/
			// wpdb::query()'s error-capture path, which runs
			// independent of whether display is suppressed) — checking
			// its text distinguishes the two causes without needing a
			// second, unsuppressed query.
			// CO-3 (cycle-7): match both dialects' collision error text, not
			// only MySQL's. MySQL emits "Duplicate entry '...' for key
			// '...'"; the SQLite Database Integration drop-in WP Playground
			// installs — the substrate every E2E spec in this repo runs on
			// — emits "UNIQUE constraint failed: wp_gl_invites.code",
			// which contains no "duplicate" token at all. Without this,
			// a genuine code collision on SQLite/Playground was
			// misclassified as gl_invite_create_failed, and
			// Invite_Service::create_invite() returns immediately on any
			// non-gl_invite_code_collision code — making the documented
			// MAX_CODE_ATTEMPTS three-attempt retry unreachable on the only
			// environment that can exercise it. MySQL's error text is also
			// lc_messages-dependent, which "unique constraint" is not.
			$last_error = (string) $wpdb->last_error;

			if ( false !== stripos( $last_error, 'duplicate' ) || false !== stripos( $last_error, 'unique constraint' ) ) {
				return new WP_Error( 'gl_invite_code_collision', __( 'That invite code is already in use.', 'game-library' ) );
			}

			return new WP_Error( 'gl_invite_create_failed', __( 'The invite could not be created. Try again.', 'game-library' ) );
		}

		$inserted_id = (int) $wpdb->insert_id;
		$quota_error = $this->enforce_quota( $inviter_id, $inserted_id, $quota );

		if ( is_wp_error( $quota_error ) ) {
			return $quota_error;
		}

		Generations::bump( Generations::invite_key() );

		return $this->get_by_id( $inserted_id );
	}

	/**
	 * Fetches one invite by its surrogate id.
	 *
	 * @param int $id Invite id.
	 * @return array<string,mixed>|null
	 */
	public function get_by_id( $id ) {
		$id = absint( $id );

		if ( ! $id ) {
			return null;
		}

		$cache_key = $this->id_cache_key( $id );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return false === $cached ? null : $cached;
		}

		global $wpdb;
		$table = Schema::invites_table();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached (including negative results) via wp_cache_set() below.

		$invite = $row ? $this->hydrate_row( $row ) : null;

		wp_cache_set( $cache_key, null === $invite ? false : $invite, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $invite;
	}

	/**
	 * Fetches one invite by its bearer code.
	 *
	 * @param string $code Invite code (any case; sanitized and re-uppercased
	 *                     internally — see the class docblock).
	 * @return array<string,mixed>|null
	 */
	public function get_by_code( $code ) {
		$code = $this->normalize_code( $code );

		if ( '' === $code ) {
			return null;
		}

		$cache_key = $this->code_cache_key( $code );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return false === $cached ? null : $cached;
		}

		global $wpdb;
		$table = Schema::invites_table();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached (including negative results) via wp_cache_set() below.

		$invite = $row ? $this->hydrate_row( $row ) : null;

		wp_cache_set( $cache_key, null === $invite ? false : $invite, self::CACHE_GROUP, HOUR_IN_SECONDS );

		// Prime the id-keyed cache too so a later get_by_id() for the same
		// invite is also a hit.
		if ( $invite ) {
			wp_cache_set( $this->id_cache_key( $invite['id'] ), $invite, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		return $invite;
	}

	/**
	 * One page of invites issued by a single member, newest first (the
	 * `/invites/` member screen, Task 14).
	 *
	 * PB-3 (cycle-8): clamps `$offset` against `count_for_inviter()` before
	 * running the query — the previous version had no ceiling, so
	 * `templates/invites.php` reading `?gl_page=` unbounded reached this
	 * method with an ever-growing `$offset`, each one writing its own
	 * hour-TTL `invites_for_*` cache entry regardless of whether any row
	 * existed at that offset.
	 *
	 * @param int $inviter_id Inviting member.
	 * @param int $limit      Page size.
	 * @param int $offset     Row offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_for_inviter( $inviter_id, $limit, $offset ) {
		$inviter_id = absint( $inviter_id );
		$limit      = max( 1, absint( $limit ) );
		$offset     = max( 0, absint( $offset ) );

		if ( ! $inviter_id ) {
			return array();
		}

		if ( $offset > 0 && $offset >= $this->count_for_inviter( $inviter_id ) ) {
			return array();
		}

		$gen       = Generations::read( Generations::invite_key() );
		$cache_key = sprintf( 'invites_for_%d_%d_%d_%d', $inviter_id, $limit, $offset, $gen );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table = Schema::invites_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE inviter_id = %d ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d", $inviter_id, $limit, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input.
			ARRAY_A
		);

		$items = array_map( array( $this, 'hydrate_row' ), (array) $rows );

		wp_cache_set( $cache_key, $items, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $items;
	}

	/**
	 * The total invite-row count issued by a single member (PB-3, cycle-8)
	 * — pairs with `get_for_inviter()` for `templates/invites.php`'s own
	 * pagination/404. Every row regardless of status, matching
	 * `get_for_inviter()`'s own unfiltered `WHERE inviter_id = %d`.
	 *
	 * @param int $inviter_id Inviting member.
	 * @return int
	 */
	public function count_for_inviter( $inviter_id ) {
		$inviter_id = absint( $inviter_id );

		if ( ! $inviter_id ) {
			return 0;
		}

		$gen       = Generations::read( Generations::invite_key() );
		$cache_key = sprintf( 'invites_for_count_%d_%d', $inviter_id, $gen );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table = Schema::invites_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE inviter_id = %d", $inviter_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input.
		);

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * The raw quota-window figures for one inviter as of now — the count of
	 * their non-revoked invites created within the rolling 30-day window, and
	 * the oldest counted invite's creation timestamp (used to compute the
	 * quota reset date). Distinct from the `id <=`-bounded count
	 * `insert_pending()` runs at creation time; this reflects the current
	 * moment, for display on the `/invites/` screen (Task 14).
	 *
	 * @param int $inviter_id Inviting member.
	 * @return array{count:int,oldest_date_created:?string}
	 */
	public function quota_usage( $inviter_id ) {
		$inviter_id = absint( $inviter_id );

		if ( ! $inviter_id ) {
			return array(
				'count'               => 0,
				'oldest_date_created' => null,
			);
		}

		$gen       = Generations::read( Generations::invite_key() );
		$cache_key = sprintf( 'invite_quota_usage_%d_%d', $inviter_id, $gen );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table        = Schema::invites_table();
		$window_start = gmdate( 'Y-m-d H:i:s', time() - self::QUOTA_WINDOW );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
		$row = $wpdb->get_row(
			// status != 'revoked' is a hardcoded D40 allowlist literal, never user input.
			$wpdb->prepare( "SELECT COUNT(*) AS total, MIN(date_created) AS oldest FROM {$table} WHERE inviter_id = %d AND status != 'revoked' AND date_created >= %s", $inviter_id, $window_start ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input.
			ARRAY_A
		);

		$usage = array(
			'count'               => $row ? (int) $row['total'] : 0,
			'oldest_date_created' => ( $row && $row['oldest'] ) ? (string) $row['oldest'] : null,
		);

		wp_cache_set( $cache_key, $usage, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $usage;
	}

	/**
	 * The count of `channel = 'email'` invites this inviter created within
	 * the rolling `QUOTA_WINDOW`, INCLUDING `revoked` rows (SE-1, cycle-5) —
	 * the durable, DB-backed replacement for a cycle-3/cycle-4 object-cache
	 * mail-budget counter. `Invite_Service::mail_budget_available()` (MR-4,
	 * cycle-7 — renamed from `consume_mail_budget()`, which described a
	 * spend/increment step that never actually happened even under its old
	 * name) reads this count as a pure comparison; nothing increments it.
	 * `insert_pending()` commits the authorizing row before
	 * `Invite_Service::deliver()` ever calls `wp_mail()`, so counting
	 * straight off this table is correct on first read regardless of cache
	 * substrate, with no separate increment step and no window for two
	 * concurrent requests to race past each other.
	 *
	 * Deliberately does NOT exclude `revoked` rows the way `quota_usage()`
	 * does: a revoked invite already consumed one real `wp_mail()` send at
	 * creation time, and a revoke does not un-send it. Excluding revoked
	 * rows here would let a create -> revoke -> create loop replay past
	 * `mail_budget()` indefinitely (the original SE-1 vector) while
	 * `RESEND_LIMIT` alone caps only the resend path, not fresh creates.
	 * Human ruling 3 (`interrupts/review:conflict-pending-resolution.md`)
	 * keeps AC-024's literal revoke-refund wording for the invite *quota*
	 * unchanged — this method backs the separate mail *budget* only, and
	 * including revoked rows here is what dissolves the entanglement
	 * between the two instead of reopening it.
	 *
	 * Deliberately not cached: SE-1's ceiling needs the live count on every
	 * send attempt, and caching it (even behind `invite_gen`) would
	 * reintroduce a window between a write and the next read the whole
	 * point of this fix is to close.
	 *
	 * @param int $inviter_id Inviting member.
	 * @return int
	 */
	public function email_invite_count_in_window( $inviter_id ) {
		$inviter_id = absint( $inviter_id );

		if ( ! $inviter_id ) {
			return 0;
		}

		global $wpdb;
		$table        = Schema::invites_table();
		$window_start = gmdate( 'Y-m-d H:i:s', time() - self::QUOTA_WINDOW );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; SE-1's mail-budget ceiling needs the live, uncached count on every send attempt — caching would reopen the race this fix exists to close.
		$count = $wpdb->get_var(
			// 'email' is a hardcoded D40/DD-010 channel allowlist literal, never user input.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE inviter_id = %d AND channel = 'email' AND date_created >= %s", $inviter_id, $window_start ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input.
		);

		return (int) $count;
	}

	/**
	 * The count of EVERY invite this inviter created within the rolling
	 * `QUOTA_WINDOW`, INCLUDING `revoked` rows — deliberately unlike
	 * `quota_usage()`'s `status != 'revoked'` filter, matching
	 * `email_invite_count_in_window()`'s own inclusion of revoked rows for
	 * the identical reason (SE-3, cycle-7).
	 *
	 * This is a SEPARATE control from the invite quota, not a
	 * reinterpretation of it: `quota_usage()`/`insert_pending()`'s quota
	 * keeps refunding a slot on revoke (AC-024, human ruling 3, unchanged),
	 * while `Invite_Service::create_invite()` gates on THIS count using the
	 * same `invite_quota * (1 + RESEND_LIMIT)` shape `mail_budget()`
	 * already uses. Without a row ceiling that survives a revoke, a
	 * create→revoke→create loop refunds the quota indefinitely — link
	 * invites (no email) skip `mail_budget()`'s ceiling entirely, so
	 * nothing previously bounded that loop for a link-channel invite at
	 * all, and POST /invites carries no cooldown or counter of its own.
	 *
	 * Deliberately not cached, matching `email_invite_count_in_window()` —
	 * this gates a real row creation on every attempt and must see the live
	 * count, not a value that could still be stale from a request that
	 * just inserted or revoked a row a moment ago.
	 *
	 * @param int $inviter_id Inviting member.
	 * @return int
	 */
	public function invite_row_count_in_window( $inviter_id ) {
		$inviter_id = absint( $inviter_id );

		if ( ! $inviter_id ) {
			return 0;
		}

		global $wpdb;
		$table        = Schema::invites_table();
		$window_start = gmdate( 'Y-m-d H:i:s', time() - self::QUOTA_WINDOW );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; deliberately uncached, see method docblock.
		$count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE inviter_id = %d AND date_created >= %s", $inviter_id, $window_start ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input.
		);

		return (int) $count;
	}

	/**
	 * Atomically claims one resend slot for invite `$id` (MR-4/MR-6,
	 * cycle-7) — `resend_invite()`'s own durable ceiling. A resend inserts
	 * no `gl_invites` row, so nothing else in this table counts it;
	 * `resend_count + 1` always transitions to a genuinely different value
	 * (never a same-value `WHERE`/`SET` no-op), which this project's SQLite
	 * backend requires — it reports rows MATCHED, not rows CHANGED, for a
	 * same-value conditional `UPDATE` (confirmed at runtime; see the plugin
	 * CLAUDE.md). `1 === $wpdb->rows_affected` is therefore a reliable
	 * "did this request actually win the slot" signal, matching
	 * `claim_by_code()`'s own conditional-`UPDATE`-as-claim shape.
	 *
	 * @param int $id    Invite id.
	 * @param int $limit Max resends this invite may still consume.
	 * @return bool True when this call claimed the slot.
	 */
	public function consume_resend_slot( $id, $limit ) {
		$id    = absint( $id );
		$limit = max( 0, absint( $limit ) );

		if ( ! $id ) {
			return false;
		}

		global $wpdb;
		$table = Schema::invites_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; single-row conditional UPDATE, nothing to cache.
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET resend_count = resend_count + 1 WHERE id = %d AND resend_count < %d", $id, $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input.
		);

		$claimed = 1 === (int) $wpdb->rows_affected;

		if ( $claimed ) {
			$this->invalidate_row( $id, '' );
		}

		return $claimed;
	}

	/**
	 * One page of every invite across every inviter, optionally filtered to
	 * one status, newest first (the admin invite list, Task 9).
	 *
	 * @param string $status One of the D40 statuses, or '' for no filter.
	 *                       Any other non-empty value returns an empty
	 *                       result rather than silently ignoring the filter.
	 * @param int    $limit  Page size; defaults to the 50-per-page AC-031
	 *                       requires.
	 * @param int    $offset Row offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_page( $status, $limit = self::DEFAULT_PER_PAGE, $offset = 0 ) {
		$limit  = max( 1, absint( $limit ) );
		$offset = max( 0, absint( $offset ) );

		if ( '' !== $status && ! in_array( $status, self::STATUSES, true ) ) {
			return array();
		}

		$gen       = Generations::read( Generations::invite_key() );
		$cache_key = sprintf( 'invites_page_%s_%d_%d_%d', '' === $status ? 'all' : $status, $limit, $offset, $gen );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table = Schema::invites_table();

		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d", $status, $limit, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input.
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input.
				ARRAY_A
			);
		}

		$items = array_map( array( $this, 'hydrate_row' ), (array) $rows );

		wp_cache_set( $cache_key, $items, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $items;
	}

	/**
	 * The total invite count across every inviter, optionally filtered to one
	 * status — pairs with `get_page()` for the admin list table's pagination.
	 *
	 * @param string $status One of the D40 statuses, or '' for no filter.
	 * @return int
	 */
	public function count_all( $status ) {
		if ( '' !== $status && ! in_array( $status, self::STATUSES, true ) ) {
			return 0;
		}

		$gen       = Generations::read( Generations::invite_key() );
		$cache_key = sprintf( 'invites_count_%s_%d', '' === $status ? 'all' : $status, $gen );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table = Schema::invites_table();

		if ( '' !== $status ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
		} else {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; no variable to bind; cached via wp_cache_set() below.
		}

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * The atomic redemption claim (AC-027(g)): flips exactly one `pending`,
	 * unexpired invite to `redeemed` and stamps `date_redeemed`, decided
	 * solely by `$wpdb->rows_affected === 1` — see the class docblock for the
	 * runtime confirmation this relies on. `redeemed_user_id` is deliberately
	 * not set here: the new account does not exist yet at claim time, so
	 * `Registration` (Task 8) calls the claim first and `set_redeemed_user()`
	 * only after `wp_insert_user()` succeeds.
	 *
	 * @param string $code Invite code (any case; normalized internally).
	 * @return bool True when this call claimed the invite, false when it did
	 *              not (unknown code, already redeemed/expired/revoked, or
	 *              expired by timestamp with a stale `pending` status).
	 */
	public function claim_by_code( $code ) {
		$code = $this->normalize_code( $code );

		if ( '' === $code ) {
			return false;
		}

		global $wpdb;
		$table = Schema::invites_table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; invalidated via invalidate_row()'s wp_cache_delete()/Generations::bump() below when the claim succeeds — a conditional invalidation path this sniff cannot recognize.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'redeemed', date_redeemed = %s WHERE code = %s AND status = 'pending' AND date_expires > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input; 'redeemed'/'pending' are hardcoded D40 allowlist literals.
				$now,
				$code,
				$now
			)
		);

		$claimed = 1 === (int) $wpdb->rows_affected;

		if ( $claimed ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; a one-off lookup used only to invalidate the row via invalidate_row() below, never cached itself.

			$this->invalidate_row( $id, $code );
		}

		return $claimed;
	}

	/**
	 * Stamps the redeemed invite with the newly created account's id
	 * (AC-025(i), AC-030) — called only after `claim_by_code()` returned true
	 * and `wp_insert_user()` succeeded.
	 *
	 * @param int $id      Invite id (from the row read before claiming).
	 * @param int $user_id Newly created account id.
	 * @return bool True when the row was updated.
	 */
	public function set_redeemed_user( $id, $user_id ) {
		$id      = absint( $id );
		$user_id = absint( $user_id );

		if ( ! $id || ! $user_id ) {
			return false;
		}

		global $wpdb;
		$table = Schema::invites_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; invalidated via invalidate_row()'s wp_cache_delete()/Generations::bump() below.
		$updated = $wpdb->update(
			$table,
			array( 'redeemed_user_id' => $user_id ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		$invite = $this->get_by_id( $id );
		$this->invalidate_row( $id, $invite ? $invite['code'] : '' );

		return true;
	}

	/**
	 * Revokes a `pending` invite (AC-031(j)) — a no-op returning false for
	 * any invite not currently `pending`. Revoking never deletes the row.
	 *
	 * @param int $id Invite id.
	 * @return bool True when the invite was revoked.
	 */
	public function revoke( $id ) {
		$id = absint( $id );

		if ( ! $id ) {
			return false;
		}

		global $wpdb;
		$table = Schema::invites_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; invalidated via invalidate_row()'s wp_cache_delete()/Generations::bump() below.
		$updated = $wpdb->update(
			$table,
			array( 'status' => 'revoked' ),
			array(
				'id'     => $id,
				'status' => 'pending',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		if ( ! $updated ) {
			return false;
		}

		$invite = $this->get_by_id( $id );
		$this->invalidate_row( $id, $invite ? $invite['code'] : '' );

		return true;
	}

	/**
	 * Flips every `pending` invite whose `date_expires` has already passed to
	 * `expired` — the daily invite-maintenance cron's first pass (AC-032,
	 * first half; `Invite_Maintenance::run()`). Selects `id, code` (not just
	 * `id`) so each affected row can be invalidated through the existing
	 * `invalidate_row()`, clearing its `code`-keyed cache entry and bumping
	 * `invite_gen` the same way every other write in this class does. Before
	 * arch-pre-2 finding AR-1, `Invite_Maintenance` ran this pass directly
	 * against `$wpdb`, invalidating nothing — the admin invite list and
	 * `/invites/` kept serving pre-cron statuses for up to an hour.
	 *
	 * Batched: ids/codes are read first with an explicit `LIMIT`, then acted
	 * on via an `id IN (…)` clause — never `UPDATE … LIMIT`, which the SQLite
	 * backend this project's E2E environment runs on does not support. A pass
	 * that fills `$limit` deliberately leaves the remainder for the caller's
	 * next run rather than continuing in the same call — see
	 * `Invite_Maintenance`'s own class docblock for why that is correct at
	 * this plugin's sizing assumption.
	 *
	 * @param int $limit Max rows processed in one call.
	 * @return void
	 */
	public function expire_lapsed( $limit ) {
		$limit = max( 1, absint( $limit ) );

		global $wpdb;
		$table = Schema::invites_table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; a deliberately uncached read of current table state, see class docblock.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, code FROM {$table} WHERE status = 'pending' AND date_expires <= %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input; 'pending' is a hardcoded D40 allowlist literal.
				$now,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		$ids          = array_map( 'absint', wp_list_pluck( $rows, 'id' ) );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table/$placeholders are built from Schema::invites_table() and array_fill(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; invalidated per row via invalidate_row() below.
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET status = 'expired' WHERE id IN ({$placeholders})", $ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table/$placeholders are built from Schema::invites_table() and array_fill(), never user input; 'expired' is a hardcoded D40 allowlist literal; every id is bound through prepare() above.
		);

		foreach ( $rows as $row ) {
			$this->invalidate_row( (int) $row['id'], (string) $row['code'] );
		}
	}

	/**
	 * Permanently deletes every `expired`/`revoked` invite whose
	 * `date_expires` is on or before `$cutoff` — the daily invite-maintenance
	 * cron's second pass (AC-032, second half; `Invite_Maintenance::run()`).
	 * Same `id, code` selection and per-row `invalidate_row()` invalidation
	 * as `expire_lapsed()` above, for the identical reason (arch-pre-2
	 * finding AR-1).
	 *
	 * @param string $cutoff UTC `Y-m-d H:i:s` cutoff — rows with
	 *                       `date_expires` on or before this are purged.
	 * @param int    $limit  Max rows processed in one call.
	 * @return void
	 */
	public function purge_expired_before( $cutoff, $limit ) {
		$cutoff = (string) $cutoff;
		$limit  = max( 1, absint( $limit ) );

		global $wpdb;
		$table = Schema::invites_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; a deliberately uncached read of current table state, see class docblock.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, code FROM {$table} WHERE status IN ( 'expired', 'revoked' ) AND date_expires <= %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input; the status list is a hardcoded D40 allowlist literal.
				$cutoff,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		$ids          = array_map( 'absint', wp_list_pluck( $rows, 'id' ) );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table/$placeholders are built from Schema::invites_table() and array_fill(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; invalidated per row via invalidate_row() below.
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table/$placeholders are built from Schema::invites_table() and array_fill(), never user input; every id is bound through prepare() above.
		);

		foreach ( $rows as $row ) {
			$this->invalidate_row( (int) $row['id'], (string) $row['code'] );
		}
	}

	/**
	 * The GDPR erasure cascade's two invite writes (`Erasure_Service::erase()`,
	 * AC-036 (f)/(g)) — arch-pre-4 architecture review, finding AR-1. Before
	 * this method existed, `Erasure_Service` issued both writes directly
	 * against `$wpdb`, invalidating only the shared `invite_gen` counter and
	 * never the id/code-keyed row caches `get_by_id()`/`get_by_code()` set —
	 * leaving those two single-row lookups serving erased data for up to
	 * `HOUR_IN_SECONDS`. Same `id, code`-first shape as `expire_lapsed()`/
	 * `purge_expired_before()` above, for the identical reason: read the
	 * affected rows before writing so each one can be invalidated through
	 * `invalidate_row()`.
	 *
	 * The two writes themselves stay unbounded — AC-036 requires every
	 * matching row erased, not just the first `$limit` of them — only the
	 * `id, code` selects that drive invalidation are bounded. `$limit` is
	 * unreachable at this plugin's D10 sizing (an invite quota of 1-100 per
	 * member, ~1,000 members) in practice; a member whose matching row count
	 * did exceed it would keep a stale id/code-keyed cache entry for up to
	 * `HOUR_IN_SECONDS`, exactly as this class's other bounded lookups
	 * (`get_for_inviter()`, `get_page()`) already accept.
	 *
	 * The (g) half's `$wpdb->update()` null-email write is preserved verbatim
	 * — `wpdb::update()` emits a literal `= NULL` for a real PHP `null` in
	 * `$data` (see `Erasure_Service`'s own docblock and
	 * `wp-content/plugins/game-library/CLAUDE.md`'s Human Notes), so this does
	 * not need the manual-NULL `INSERT` handling the repositories'
	 * `build_insert_clause()` helpers use.
	 *
	 * @param int $user_id The member being erased.
	 * @param int $limit   Max rows selected (for invalidation purposes only)
	 *                     per half of the cascade.
	 * @return bool True when either write affected at least one row.
	 */
	public function erase_for_user( $user_id, $limit ) {
		$user_id = absint( $user_id );
		$limit   = max( 1, absint( $limit ) );

		if ( ! $user_id ) {
			return false;
		}

		global $wpdb;
		$table = Schema::invites_table();

		$affected = false;

		// (f) invites they issued, including the stored recipient emails —
		// the whole row is deleted, taking the email with it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; a deliberately uncached read of current table state, see class docblock.
		$issued_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, code FROM {$table} WHERE inviter_id = %d LIMIT %d", $user_id, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input.
			ARRAY_A
		);

		if ( ! empty( $issued_rows ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; invalidated per row via invalidate_row() below.
			$deleted = $wpdb->delete( $table, array( 'inviter_id' => $user_id ), array( '%d' ) );

			if ( false !== $deleted && $deleted > 0 ) {
				$affected = true;
			}

			foreach ( $issued_rows as $row ) {
				$this->invalidate_row( (int) $row['id'], (string) $row['code'] );
			}
		}

		// (g) the recipient email on the invite that created their own
		// account — null the email, keep the row for the inviter's audit
		// trail.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; a deliberately uncached read of current table state, see class docblock.
		$redeemed_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, code FROM {$table} WHERE redeemed_user_id = %d LIMIT %d", $user_id, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input.
			ARRAY_A
		);

		if ( ! empty( $redeemed_rows ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; invalidated per row via invalidate_row() below.
			$updated = $wpdb->update( $table, array( 'email' => null ), array( 'redeemed_user_id' => $user_id ), array( '%s' ), array( '%d' ) );

			if ( false !== $updated && $updated > 0 ) {
				$affected = true;
			}

			foreach ( $redeemed_rows as $row ) {
				$this->invalidate_row( (int) $row['id'], (string) $row['code'] );
			}
		}

		return $affected;
	}

	/**
	 * DD-010's quota check: counts this inviter's non-revoked invites created
	 * inside the rolling 30-day window with `id <= $inserted_id`; deletes the
	 * just-inserted row and returns `gl_invite_quota_exceeded` when that count
	 * exceeds `$quota`.
	 *
	 * @param int $inviter_id  Inviting member.
	 * @param int $inserted_id The id `insert_pending()` just created.
	 * @param int $quota       The inviter's invite quota.
	 * @return true|WP_Error
	 */
	private function enforce_quota( $inviter_id, $inserted_id, $quota ) {
		global $wpdb;
		$table        = Schema::invites_table();
		$window_start = gmdate( 'Y-m-d H:i:s', time() - self::QUOTA_WINDOW );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; DD-010's quota check needs the live, uncached count at insert time — caching would risk a stale quota decision.
		$row = $wpdb->get_row(
			// status != 'revoked' is a hardcoded D40 allowlist literal, never user input.
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, MIN(date_created) AS oldest FROM {$table} WHERE inviter_id = %d AND status != 'revoked' AND date_created >= %s AND id <= %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input.
				$inviter_id,
				$window_start,
				$inserted_id
			),
			ARRAY_A
		);

		$count = $row ? (int) $row['total'] : 0;

		if ( $count <= $quota ) {
			return true;
		}

		$oldest = $row && $row['oldest'] ? (string) $row['oldest'] : $window_start;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; rolls back a same-request insert that was never cached or exposed (invite_gen is only bumped after this method returns success), so there is nothing to invalidate.
		$wpdb->delete( $table, array( 'id' => $inserted_id ), array( '%d' ) );

		$reset_timestamp = strtotime( $oldest . ' UTC' ) + self::QUOTA_WINDOW;

		return new WP_Error(
			'gl_invite_quota_exceeded',
			sprintf(
				/* translators: %s: the date the inviter's invite quota next frees up. */
				__( 'You have reached your invite quota. You can send another invite starting %s.', 'game-library' ),
				// wp_date(), not date_i18n() (CO-5): date_i18n() reinterprets
				// its timestamp as an already-site-offset wall clock, so a
				// real UTC epoch like $reset_timestamp renders the UTC date
				// unconverted — one calendar day off near midnight on any
				// site not at UTC. wp_date() is core's function that
				// actually converts.
				wp_date( get_option( 'date_format' ), $reset_timestamp )
			)
		);
	}

	/**
	 * Deletes the explicit id/code single-row cache entries for one invite
	 * and bumps the shared `invite_gen` generation counter — called after
	 * every write that mutates an existing row.
	 *
	 * @param int    $id   Invite id.
	 * @param string $code Invite code (already normalized), or '' when
	 *                     unknown.
	 * @return void
	 */
	private function invalidate_row( $id, $code ) {
		if ( $id ) {
			wp_cache_delete( $this->id_cache_key( $id ), self::CACHE_GROUP );
		}

		if ( '' !== $code ) {
			wp_cache_delete( $this->code_cache_key( $code ), self::CACHE_GROUP );
		}

		Generations::bump( Generations::invite_key() );
	}

	/**
	 * Normalizes a code argument to the exact form it is stored in: sanitized
	 * with `sanitize_key()` (stripping anything outside `[a-z0-9_-]`) then
	 * re-uppercased — see the class docblock for why the re-uppercase matters.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	private function normalize_code( $code ) {
		return strtoupper( sanitize_key( (string) $code ) );
	}

	/**
	 * Normalises a raw `gl_invites` row into typed, nullable PHP values.
	 *
	 * @param array<string,mixed> $row Raw row from `$wpdb->get_row()`/`get_results()`.
	 * @return array<string,mixed>
	 */
	private function hydrate_row( array $row ) {
		return array(
			'id'               => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'code'             => isset( $row['code'] ) ? (string) $row['code'] : '',
			'inviter_id'       => isset( $row['inviter_id'] ) ? (int) $row['inviter_id'] : 0,
			'email'            => ! empty( $row['email'] ) ? (string) $row['email'] : null,
			'channel'          => isset( $row['channel'] ) ? (string) $row['channel'] : '',
			'status'           => isset( $row['status'] ) ? (string) $row['status'] : '',
			'date_created'     => isset( $row['date_created'] ) ? (string) $row['date_created'] : '',
			'date_expires'     => isset( $row['date_expires'] ) ? (string) $row['date_expires'] : '',
			'date_redeemed'    => ! empty( $row['date_redeemed'] ) ? (string) $row['date_redeemed'] : null,
			'redeemed_user_id' => ! empty( $row['redeemed_user_id'] ) ? (int) $row['redeemed_user_id'] : null,
		);
	}

	/**
	 * The object-cache key for one invite row by id.
	 *
	 * @param int $id Invite id.
	 * @return string
	 */
	private function id_cache_key( $id ) {
		return 'invite_id_' . absint( $id );
	}

	/**
	 * The object-cache key for one invite row by code.
	 *
	 * @param string $code Already-normalized invite code.
	 * @return string
	 */
	private function code_cache_key( $code ) {
		return 'invite_code_' . $code;
	}
}
