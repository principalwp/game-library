<?php
/**
 * The only read/write path to `gl_activity` beyond the two narrow,
 * directly-coupled writes `Library_Repository` owns for its own table
 * (see that class's docblock).
 *
 * @package Game_Library
 */

namespace Game_Library\Data;

use Game_Library\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Activity_Repository.
 *
 * Owns the `member_followed` activity write (AC-019) and the feed read that
 * joins a viewer's follow set to `gl_activity` in one bounded query (D37 — one
 * row per transition, no dedup). `game_added`/`status_changed` rows stay
 * owned by `Library_Repository::add_or_update()`/`remove()` (Task 3), which
 * already ties them directly to its own writes; this class is "every other
 * read and write against `gl_activity`" per that class's own docblock.
 *
 * `get_feed()` takes the viewer's follow-id list as a parameter rather than
 * fetching it itself via a `Follow_Repository` dependency. `Follow_Repository`
 * already depends on this class (to record the `member_followed` write) —
 * this class depending back on `Follow_Repository` would be a circular
 * constructor dependency between the two repositories. `Social_Controller`
 * (this same task) composes both: it calls
 * `Follow_Repository::following_ids()` first, then passes the result into
 * `get_feed()`. `get_feed()` still independently reads (never bumps) the
 * viewer's `follow_gen_{user_id}` counter to key its own cache — generation
 * counters live in one shared `game_library`-group namespace per the
 * Transients and object cache table in spec section 6, not owned exclusively
 * by whichever class first defined a given scope.
 *
 * One generation-counter scope: the global `activity_gen` counter, bumped on
 * this class's own `record_member_followed()` insert and (independently, by
 * `Library_Repository` on every library add/remove, and by
 * `Game_Repository::upsert()` on every cached-game write) elsewhere. The key
 * builders and the read/bump pair itself live in `Generations` (arch-pre-1
 * architecture review, finding AR-2) — see that class's docblock for the
 * "seed the cold-bump fallback to 2, not 1" reasoning.
 *
 * `get_recent()`/`count_recent()`/`delete()` were added in Task 18: not part
 * of Task 12's own Description, but this class's own opening docblock line
 * already designates it "the only read/write path to `gl_activity`" beyond
 * `Library_Repository`'s two coupled writes — the moderation screen's
 * "recent activity list with delete" (AC-048) is exactly that kind of
 * read/write, and Task 18's own constraint ("All reads go through the
 * repositories … deleting an activity row bumps the global activity
 * generation") rules out a direct `$wpdb` query or a private-method
 * reach-around from `Moderation_Page`. Same precedent as
 * `Game_Repository::get_referenced_games()` (Task 15) — see that method's
 * docblock and `principal/adr/008-catalog-listing-and-games-pagination-route.md`.
 * `get_recent()`/`count_recent()` reuse the existing `activity_gen` scope
 * rather than adding a new one — a delete already bumps that counter, so no
 * separate invalidation path is needed. Task 18 also added a public
 * `bump_activity_generation()` passthrough for `Moderation_Page`'s own
 * save/refresh handlers to call after a successful `Game_Repository::upsert()`
 * — removed in arch-pre-2 finding AR-1, which moved that bump into
 * `upsert()` itself so every write path gets it automatically, not just this
 * screen's.
 *
 * Custom tables outside `wp_posts`/`wp_postmeta` like this one are
 * recommended to go through VIP's database review process for
 * backup/restore compatibility.
 */
final class Activity_Repository {

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
	 * Freshness comes from the generation counters above, not a short TTL.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'game_library';

	/**
	 * Feed page size (AC-020 — "paginated at 20 per page").
	 *
	 * @var int
	 */
	private const DEFAULT_PER_PAGE = 20;

	/**
	 * Fetches one page of a viewer's activity feed — entries authored by the
	 * members in `$following_ids`, newest first — via a single prepared query
	 * with an `IN (…)` placeholder run plus an explicit `LIMIT`/`OFFSET`
	 * (never one query per followed member). When `$following_ids` is empty
	 * this returns an empty array without issuing any query against
	 * `gl_activity` at all (AC-022's empty state).
	 *
	 * Before returning, primes the WordPress user object cache in one call
	 * for every distinct actor (`user_id`) and, for `member_followed` rows,
	 * every distinct followed member (`object_user_id`) on this page —
	 * `cache_users()` — so a caller's subsequent `get_userdata()`/
	 * `get_avatar()` for any of those ids is a cache hit rather than one
	 * query per row. This priming runs on every call, including a cache hit
	 * on this class's own `game_library`-group cache: that cache only stores
	 * the raw activity rows, not WordPress core's separate user/usermeta
	 * object cache, which may still be cold for this request/process.
	 *
	 * Known limitation (PB-10): the `WHERE user_id IN (…) ORDER BY
	 * date_created DESC, id DESC LIMIT … OFFSET …` shape below, with up to
	 * `Follow_Repository::MAX_FOLLOWING` (2000) ids in the `IN (…)` list,
	 * gives `Schema`'s own `user_date` key one ordered range per followed
	 * member, not one ordered stream across all of them — MySQL materialises
	 * the union and filesorts it before applying `LIMIT`, and a deep
	 * `OFFSET` compounds it. Negligible at AC-NFR-005's stated scale (50
	 * followed, 500 rows) and invisible to that AC's own query-COUNT-only
	 * gate by construction; grows with total history. If this is ever
	 * measured hot, the fix is a keyset cursor (`WHERE user_id IN (…) AND (
	 * date_created, id ) < ( :d, :i ) ORDER BY date_created DESC, id DESC
	 * LIMIT 20`, letting the index ranges terminate early and removing the
	 * `OFFSET` scan) plus capping the `IN (…)` set well below 2000 for this
	 * query specifically. No code change for this cycle.
	 *
	 * PB-3 (cycle-8): an out-of-range `$page` used to run the full
	 * `IN (…)`/`ORDER BY`/`LIMIT …OFFSET …` query anyway — every other
	 * paginated read in this plugin (`Game_Repository`, `Library_Repository`,
	 * `Member_Directory`) already clamps the offset against the real total
	 * before running its own cache-miss query; this was the one remaining
	 * gap. `count_feed()` closes it, keyed to match this method's own cache
	 * key shape.
	 *
	 * @param int   $viewer_id     Feed owner.
	 * @param int[] $following_ids The viewer's current follow-id set (from
	 *                             `Follow_Repository::following_ids()`).
	 * @param int   $page          1-based page number.
	 * @param int   $per_page      Page size; defaults to the 20-per-page
	 *                             AC-020 requires.
	 * @return array<int,array<string,mixed>> Hydrated activity rows.
	 */
	public function get_feed( $viewer_id, array $following_ids, $page = 1, $per_page = self::DEFAULT_PER_PAGE ) {
		$viewer_id = absint( $viewer_id );
		$page      = max( 1, absint( $page ) );
		$per_page  = max( 1, absint( $per_page ) );

		$following_ids = array_values( array_unique( array_filter( array_map( 'absint', $following_ids ) ) ) );

		if ( empty( $following_ids ) ) {
			return array();
		}

		// PB-3 (cycle-8): defence-in-depth ceiling, matching every sibling
		// paginated read's own guard (see this method's own docblock) —
		// count_feed() is itself cached, so this costs nothing extra on the
		// common (in-range) case, and page 1 never pays for it at all.
		$ceiling_offset = ( $page - 1 ) * $per_page;

		if ( $ceiling_offset > 0 && $ceiling_offset >= $this->count_feed( $viewer_id, $following_ids ) ) {
			return array();
		}

		$follow_gen   = Generations::read( Generations::follow_key( $viewer_id ) );
		$activity_gen = Generations::read( Generations::activity_key() );
		// $per_page is folded into the key (CO-1 sibling) — latent today
		// (every caller passes the same 20), but Library_Repository::get_page()'s
		// identical key shape had this exact omission as a live bug, and the
		// two should not be allowed to diverge in correctness.
		$cache_key = sprintf( 'feed_%d_%d_%d_%d_%d', $viewer_id, $page, $per_page, $follow_gen, $activity_gen );

		$items = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false === $items ) {
			global $wpdb;
			$table        = Schema::activity_table();
			$offset       = ( $page - 1 ) * $per_page;
			$placeholders = implode( ', ', array_fill( 0, count( $following_ids ), '%d' ) );

			$args   = $following_ids;
			$args[] = $per_page;
			$args[] = $offset;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::activity_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, event_type, igdb_id, object_user_id, status_from, status_to, date_created FROM {$table} WHERE user_id IN ({$placeholders}) ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::activity_table() and $placeholders is a fixed run of %d tokens built by array_fill(), neither is user data; every value is bound through prepare() below.
					$args
				),
				ARRAY_A
			);

			$items = array_map( array( $this, 'hydrate_row' ), (array) $rows );

			// HOUR_IN_SECONDS +/- 10% jitter (PB-4): every per-viewer feed
			// page keyed off the same activity_gen otherwise expires in the
			// same instant it was written in, so a popular follow graph can
			// synchronise many members' cache-misses onto one moment.
			// Freshness still comes from the generation counter, not this
			// TTL — see Library_Repository::jittered_ttl()'s own docblock
			// for the identical reasoning applied to the other high-traffic
			// reads on this shared scope.
			wp_cache_set( $cache_key, $items, self::CACHE_GROUP, HOUR_IN_SECONDS + wp_rand( -360, 360 ) ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- the expression is HOUR_IN_SECONDS +/- a bounded 360s jitter (PB-4), always >= 3240s; the sniff cannot statically evaluate wp_rand()'s runtime result.
		}

		$this->prime_actors( $items );

		return $items;
	}

	/**
	 * The total row count of a viewer's feed (PB-3, cycle-8) — every row in
	 * `gl_activity` whose `user_id` is one of `$following_ids`, matching
	 * `get_feed()`'s own `WHERE user_id IN (…)` shape exactly so the two
	 * agree on what "in range" means. Cached on a key that folds in the same
	 * two generations `get_feed()`'s own key does (`follow_gen`/
	 * `activity_gen`), so a new follow/unfollow or a new activity row
	 * invalidates this count the same instant it invalidates a feed page.
	 *
	 * @param int   $viewer_id     Feed owner.
	 * @param int[] $following_ids The viewer's current follow-id set,
	 *                             already sanitised by the caller
	 *                             (`get_feed()`).
	 * @return int
	 */
	public function count_feed( $viewer_id, array $following_ids ) {
		$viewer_id = absint( $viewer_id );

		if ( empty( $following_ids ) ) {
			return 0;
		}

		$follow_gen   = Generations::read( Generations::follow_key( $viewer_id ) );
		$activity_gen = Generations::read( Generations::activity_key() );
		$cache_key    = sprintf( 'feed_count_%d_%d_%d', $viewer_id, $follow_gen, $activity_gen );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table        = Schema::activity_table();
		$placeholders = implode( ', ', array_fill( 0, count( $following_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::activity_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::activity_table() and $placeholders is a fixed run of %d tokens built by array_fill(), neither is user data; every value is bound through prepare() below.
				$following_ids
			)
		);

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, HOUR_IN_SECONDS + wp_rand( -360, 360 ) ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- the expression is HOUR_IN_SECONDS +/- a bounded 360s jitter (PB-4), always >= 3240s; the sniff cannot statically evaluate wp_rand()'s runtime result.

		return $count;
	}

	/**
	 * Inserts one `member_followed` `gl_activity` row (AC-019) and bumps the
	 * global `activity_gen` counter — the write path `Follow_Repository::follow()`
	 * calls after successfully creating a new edge (never on an idempotent
	 * repeated follow, which never reaches this method).
	 *
	 * @param int $follower_id  Actor — the member who followed someone.
	 * @param int $following_id Subject — the member who was followed.
	 * @return bool True on success.
	 */
	public function record_member_followed( $follower_id, $following_id ) {
		$follower_id  = absint( $follower_id );
		$following_id = absint( $following_id );

		if ( ! $follower_id || ! $following_id ) {
			return false;
		}

		global $wpdb;
		$table = Schema::activity_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::activity_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; invalidated via Generations::bump() below.
		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'        => $follower_id,
				'event_type'     => 'member_followed',
				'igdb_id'        => null,
				'object_user_id' => $following_id,
				'status_from'    => null,
				'status_to'      => null,
				'date_created'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		Generations::bump( Generations::activity_key() );

		return true;
	}

	/**
	 * One page of the most recent activity across every member, newest first —
	 * the moderation screen's "recent activity list" (AC-048). Unlike
	 * `get_feed()`, this is not scoped to any viewer's follow set; it is the
	 * unfiltered table, bounded by an explicit limit/offset. Primes the
	 * WordPress user object cache for every distinct actor/followed-member id
	 * on the page, matching `get_feed()`'s own priming.
	 *
	 * @param int $limit  Page size.
	 * @param int $offset Row offset.
	 * @return array<int,array<string,mixed>> Hydrated activity rows.
	 */
	public function get_recent( $limit, $offset = 0 ) {
		$limit  = max( 1, absint( $limit ) );
		$offset = max( 0, absint( $offset ) );

		$activity_gen = Generations::read( Generations::activity_key() );
		$cache_key    = sprintf( 'activity_recent_%d_%d_%d', $limit, $offset, $activity_gen );

		$items = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false === $items ) {
			global $wpdb;
			$table = Schema::activity_table();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::activity_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, user_id, event_type, igdb_id, object_user_id, status_from, status_to, date_created FROM {$table} ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::activity_table(), never user input.
				ARRAY_A
			);

			$items = array_map( array( $this, 'hydrate_row' ), (array) $rows );

			wp_cache_set( $cache_key, $items, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		$this->prime_actors( $items );

		return $items;
	}

	/**
	 * The total activity-row count across every member — pairs with
	 * `get_recent()` for the moderation screen's pagination.
	 *
	 * @return int
	 */
	public function count_recent() {
		$activity_gen = Generations::read( Generations::activity_key() );
		$cache_key    = sprintf( 'activity_recent_count_%d', $activity_gen );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table = Schema::activity_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::activity_table(), never user input; no variable to bind; cached via wp_cache_set() below.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * Deletes one activity row by id (AC-048) and bumps the global
	 * `activity_gen` counter — the same counter every feed/recent-activity
	 * cache key folds in, so the row is absent from every member's `/activity/`
	 * feed and from this screen's own list on the very next request.
	 *
	 * @param int $id Activity row id.
	 * @return bool True when a row was deleted.
	 */
	public function delete( $id ) {
		$id = absint( $id );

		if ( ! $id ) {
			return false;
		}

		global $wpdb;
		$table = Schema::activity_table();

		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::activity_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; invalidated via the DD-004/ADR-004 activity_gen bump below.

		if ( ! $deleted ) {
			return false;
		}

		Generations::bump( Generations::activity_key() );

		return true;
	}

	/**
	 * Primes the WordPress user object cache in one call for every distinct
	 * actor and followed-member id on a fetched feed page — see `get_feed()`'s
	 * docblock for why this runs unconditionally, including on a cache hit.
	 *
	 * @param array<int,array<string,mixed>> $items Hydrated feed rows.
	 * @return void
	 */
	private function prime_actors( array $items ) {
		if ( empty( $items ) ) {
			return;
		}

		$ids = array();

		foreach ( $items as $item ) {
			$ids[] = $item['user_id'];

			if ( null !== $item['object_user_id'] ) {
				$ids[] = $item['object_user_id'];
			}
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( ! empty( $ids ) ) {
			cache_users( $ids );
		}
	}

	/**
	 * Normalises a raw `gl_activity` row into typed, nullable PHP values.
	 *
	 * @param array<string,mixed> $row Raw row from `$wpdb->get_results()`.
	 * @return array<string,mixed>
	 */
	private function hydrate_row( array $row ) {
		return array(
			'id'             => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'user_id'        => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'event_type'     => isset( $row['event_type'] ) ? (string) $row['event_type'] : '',
			'igdb_id'        => ! empty( $row['igdb_id'] ) ? (int) $row['igdb_id'] : null,
			'object_user_id' => ! empty( $row['object_user_id'] ) ? (int) $row['object_user_id'] : null,
			'status_from'    => ! empty( $row['status_from'] ) ? (string) $row['status_from'] : null,
			'status_to'      => ! empty( $row['status_to'] ) ? (string) $row['status_to'] : null,
			'date_created'   => isset( $row['date_created'] ) ? (string) $row['date_created'] : '',
		);
	}
}
