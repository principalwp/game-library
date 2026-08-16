<?php
/**
 * The only read/write path to `gl_follows`.
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
 * Class Follow_Repository.
 *
 * Cached CRUD over the `gl_follows` table (DD-001/ADR-001) — one-directional
 * follow edges (D8). Every direct `$wpdb` read is wrapped in
 * `wp_cache_get()`/`wp_cache_set()` in the `game_library` object-cache group
 * at a 3600-second TTL; freshness comes from per-scope generation counters
 * folded into every cache key (DD-004/ADR-004). The key builders and the
 * read/bump pair itself live in `Generations` (arch-pre-1 architecture
 * review, finding AR-2) — see that class's docblock for the "seed the
 * cold-bump fallback to 2, not 1" reasoning every `Generations::bump()` call
 * below relies on.
 *
 * Two generation-counter scopes:
 *
 * - `follow_gen_{user_id}` — bumped for the *follower* on every follow/unfollow
 *   that member makes. Scopes `get_following_set()` (the private helper
 *   `is_following()`, `is_following_map()`, and `following_ids()` all read),
 *   and is also read (never bumped) by `Activity_Repository` (Task 12) to key
 *   its feed cache — the two classes share this counter's naming convention
 *   without either importing the other's code (per the Transients and object
 *   cache table in spec section 6: generation counters live in one shared
 *   `game_library`-group namespace, not owned exclusively by one class).
 * - `follower_gen_{user_id}` — bumped for the *followed* member on every
 *   follow/unfollow naming them, scoping `follower_count()`. This scope is
 *   not named in the spec's own cache-key table (which only lists
 *   `following_{user_id}_{gen}` for the follower's own "Follow-id set"); it is
 *   added here because `follower_count()` is a per-*followed*-member
 *   aggregate with no other correct invalidation signal — the follower's own
 *   `follow_gen` bump does not fire for the member being followed, and the
 *   shared global `activity_gen` counter is not bumped on `unfollow()` (no
 *   `gl_activity` row is written or removed for an unfollow), which would
 *   leave a cached follower count stale after an unfollow until an unrelated
 *   activity write happened to bump it. See the Task 12 coder decision log
 *   for the full reasoning.
 *
 * `is_following()`, `is_following_map()`, and `following_ids()` all read from
 * one shared private helper, `get_following_set()`, which caches a follower's
 * *entire* set of `following_id`s under the exact `following_{user_id}_{gen}`
 * key the spec's cache table names for the "Follow-id set". Deriving all
 * three public reads from this one cached set (rather than issuing a second,
 * differently-scoped query for `is_following_map()`) means a call to any of
 * the three costs at most one query — never one query per row, and often zero
 * once the set is warm for that viewer, satisfying `is_following_map()`'s
 * "one query returning the viewer's follow state for a whole page of member
 * ids" contract by construction.
 *
 * Custom tables outside `wp_posts`/`wp_postmeta` like this one are
 * recommended to go through VIP's database review process for
 * backup/restore compatibility.
 */
final class Follow_Repository {

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
	 * Freshness comes from the generation counters below, not a short TTL.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'game_library';

	/**
	 * Safety bound on how many `following_id`s a single `get_following_set()`
	 * query returns. Never realistically reached at this plugin's sizing
	 * assumption (~1,000 total members, D10), present only to satisfy the
	 * project's "paginate every list query with an explicit limit" rule.
	 *
	 * @var int
	 */
	private const MAX_FOLLOWING = 2000;

	/**
	 * Records `gl_activity`'s `member_followed` event and owns the global
	 * `activity_gen` bump on that write — see the class docblock for why this
	 * dependency runs one direction only (`Follow_Repository` ->
	 * `Activity_Repository`), never the reverse.
	 *
	 * @var Activity_Repository
	 */
	private $activity;

	/**
	 * Constructor.
	 *
	 * @param Activity_Repository|null $activity Activity repository. Defaults
	 *                                            to a new instance.
	 */
	public function __construct( ?Activity_Repository $activity = null ) {
		$this->activity = $activity ?: new Activity_Repository();
	}

	/**
	 * Creates a one-directional follow edge (AC-019). Rejects a self-follow
	 * with a 400 `WP_Error`. Relies on the `edge` unique key for idempotency —
	 * a repeated follow's `INSERT` fails silently on the constraint and this
	 * method returns success without writing a second activity row or bumping
	 * either generation counter a second time, exactly matching AC-019's "a
	 * second identical follow returns HTTP 200 and does not create a
	 * duplicate row."
	 *
	 * @param int $follower_id  The member doing the following.
	 * @param int $following_id The member being followed.
	 * @return true|WP_Error
	 */
	public function follow( $follower_id, $following_id ) {
		$follower_id  = absint( $follower_id );
		$following_id = absint( $following_id );

		if ( ! $follower_id || ! $following_id ) {
			return new WP_Error( 'gl_invalid_follow', __( 'A follow requires two members.', 'game-library' ), array( 'status' => 400 ) );
		}

		if ( $follower_id === $following_id ) {
			return new WP_Error( 'gl_cannot_follow_self', __( 'You cannot follow yourself.', 'game-library' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$table = Schema::follows_table();

		// A repeated follow is expected to hit the `edge` unique key and fail
		// (AC-019 idempotency, below) — that is a normal, benign outcome, not
		// a real database error. wpdb's default error-display behaviour
		// (active whenever WP_DEBUG is on, confirmed at runtime against the
		// shared Playground instance) prints the full failed query and a
		// stack trace directly into the HTTP response body ahead of this
		// method's own JSON, corrupting every idempotent-follow response.
		// Suppressing errors only around this one expected-to-sometimes-fail
		// call, and restoring the prior setting immediately after, avoids
		// that leak without hiding errors anywhere else in the request.
		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::follows_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; invalidated via the Generations::bump() calls below.
		$inserted = $wpdb->insert(
			$table,
			array(
				'follower_id'  => $follower_id,
				'following_id' => $following_id,
				'date_created' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s' )
		);

		$wpdb->suppress_errors( $suppress );

		if ( false === $inserted ) {
			// The `edge` unique key is the only realistic cause of an insert
			// failure here — every other value is already validated above. A
			// repeated follow is idempotent (AC-019): no new row, no error.
			return true;
		}

		$this->activity->record_member_followed( $follower_id, $following_id );

		Generations::bump( Generations::follow_key( $follower_id ) );
		Generations::bump( Generations::follower_key( $following_id ) );

		return true;
	}

	/**
	 * Removes a follow edge, if one exists.
	 *
	 * @param int $follower_id  The member doing the unfollowing.
	 * @param int $following_id The member being unfollowed.
	 * @return bool True when an edge was removed, false when none existed.
	 */
	public function unfollow( $follower_id, $following_id ) {
		$follower_id  = absint( $follower_id );
		$following_id = absint( $following_id );

		if ( ! $follower_id || ! $following_id ) {
			return false;
		}

		global $wpdb;
		$table = Schema::follows_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::follows_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; invalidated via the Generations::bump() calls below.
		$deleted = $wpdb->delete(
			$table,
			array(
				'follower_id'  => $follower_id,
				'following_id' => $following_id,
			),
			array( '%d', '%d' )
		);

		if ( ! $deleted ) {
			return false;
		}

		Generations::bump( Generations::follow_key( $follower_id ) );
		Generations::bump( Generations::follower_key( $following_id ) );

		return true;
	}

	/**
	 * Whether one member follows another.
	 *
	 * @param int $follower_id  Candidate follower.
	 * @param int $following_id Candidate followed member.
	 * @return bool
	 */
	public function is_following( $follower_id, $following_id ) {
		$follower_id  = absint( $follower_id );
		$following_id = absint( $following_id );

		if ( ! $follower_id || ! $following_id ) {
			return false;
		}

		return in_array( $following_id, $this->get_following_set( $follower_id ), true );
	}

	/**
	 * The viewer's follow state for a whole page of member ids in one query —
	 * never one query per row (used by the `/members/` directory and the
	 * `GET /members` route in Task 12's own `Social_Controller`).
	 *
	 * @param int   $viewer_id Viewer whose follow state to check.
	 * @param int[] $ids       Member ids to check.
	 * @return array<int,bool> id => true/false for every requested id. Every
	 *                         id in `$ids` is present in the result — a
	 *                         viewer who follows nobody still gets a full map
	 *                         of `false` values, since the caller needs a
	 *                         definite state per row to render a Follow
	 *                         control.
	 */
	public function is_following_map( $viewer_id, array $ids ) {
		$viewer_id = absint( $viewer_id );
		$ids       = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( ! $viewer_id || empty( $ids ) ) {
			return array();
		}

		$following = $this->get_following_set( $viewer_id );
		$map       = array();

		foreach ( $ids as $id ) {
			$map[ $id ] = in_array( $id, $following, true );
		}

		return $map;
	}

	/**
	 * The full set of member ids a viewer follows — used by
	 * `Social_Controller::get_activity()` to build the `IN (…)` clause
	 * `Activity_Repository::get_feed()` needs.
	 *
	 * @param int $follower_id Viewer.
	 * @return int[]
	 */
	public function following_ids( $follower_id ) {
		return $this->get_following_set( absint( $follower_id ) );
	}

	/**
	 * The number of members following one member — not consumed by any route
	 * in this task, built because the task's own Description names it as one
	 * of this repository's methods.
	 *
	 * @param int $user_id Member.
	 * @return int
	 */
	public function follower_count( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return 0;
		}

		$gen       = Generations::read( Generations::follower_key( $user_id ) );
		$cache_key = sprintf( 'follower_count_%d_%d', $user_id, $gen );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table = Schema::follows_table();

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE following_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::follows_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * Fetches (and caches) the complete set of `following_id`s for one
	 * follower — the single source `is_following()`, `is_following_map()`,
	 * and `following_ids()` all read from. See the class docblock for why
	 * sharing this one cached set across all three public reads is what makes
	 * each of them cost at most one query.
	 *
	 * @param int $follower_id Follower.
	 * @return int[]
	 */
	private function get_following_set( $follower_id ) {
		if ( ! $follower_id ) {
			return array();
		}

		$gen       = Generations::read( Generations::follow_key( $follower_id ) );
		$cache_key = sprintf( 'following_%d_%d', $follower_id, $gen );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return $cached;
		}

		global $wpdb;
		$table = Schema::follows_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::follows_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
		$rows = $wpdb->get_col(
			$wpdb->prepare( "SELECT following_id FROM {$table} WHERE follower_id = %d ORDER BY id ASC LIMIT %d", $follower_id, self::MAX_FOLLOWING ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::follows_table(), never user input.
		);

		$ids = array_values( array_map( 'absint', (array) $rows ) );

		wp_cache_set( $cache_key, $ids, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $ids;
	}
}
