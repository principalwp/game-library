<?php
/**
 * Shared generation-counter cache-key builders and read/bump helpers.
 *
 * @package Game_Library
 */

namespace Game_Library\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Generations.
 *
 * The single owner of the DD-004/ADR-004 generation-counter contract:
 * freshness for every cached read in this plugin comes from folding one or
 * more generation counters into the cache key, bumped on write, rather than
 * from a short TTL (the Boundaries section requires content cache TTLs stay
 * at or above 900 seconds). Before this class existed, five key formats and
 * the read/bump pair were copy-pasted independently across
 * `Library_Repository`, `Activity_Repository`, `Follow_Repository`,
 * `Invite_Repository`, and `Erasure_Service` — a rename or a copy that
 * drifted in any one of them would silently break invalidation with no error
 * anywhere (arch-pre-1 architecture review, finding AR-2).
 *
 * Six shared scopes, plus one single-writer scope:
 *
 * - `library_key( $user_id )` (`lib_gen_{id}`) — one member's own library
 *   (`Library_Repository`).
 * - `follow_key( $user_id )` (`follow_gen_{id}`) — one member's own
 *   following set (`Follow_Repository`, also read by `Activity_Repository`
 *   to key its feed cache).
 * - `follower_key( $user_id )` (`follower_gen_{id}`) — one member's own
 *   follower count (`Follow_Repository`).
 * - `activity_key()` (`activity_gen`) — the global activity/feed/per-game
 *   holder-status scope, bumped by `Library_Repository`, `Follow_Repository`
 *   (via `Activity_Repository::record_member_followed()`), and
 *   `Activity_Repository` itself, and read by `Game_Repository::
 *   reference_count()`. As of PB-5 (cycle-5), the public catalog's own three
 *   reads moved off this scope onto `catalog_key()` below — see that
 *   method's own docblock.
 * - `catalog_key()` (`catalog_gen`, PB-5, cycle-5) — the public `/games/`
 *   catalog listing scope, split out of `activity_gen`'s coarser one. See
 *   that method's own docblock for the full reasoning and its exact bump
 *   sites.
 * - `invite_key()` (`invite_gen`) — the single shared invite-list scope
 *   (`Invite_Repository`).
 * - `games_key()` (`games_gen`) — not a seventh shared scope: only
 *   `Game_Repository::upsert()` ever writes it and only
 *   `Game_Repository::search_by_name()` ever reads it (arch-pre-2 finding
 *   AR-3). It lives here for the same `read()`/`bump()` protocol every other
 *   scope uses, not because a second class needs to reach it.
 *
 * `read()` and `bump()`'s cold-path seeds must always land on a value large
 * enough that it is very unlikely to collide with whatever generation was in
 * use immediately before — see each method's own docblock (VIP-1). A fixed
 * small integer (an earlier version of this class used a literal `1`/`2`
 * pair) is only safe against a *cold start*, where nothing has ever read or
 * written the scope before; it is wrong against a persistent object cache's
 * LRU eviction (VIP's memcached pool is exactly this), which can silently
 * drop a live, mid-use counter — including one already well past `2` — at
 * any time, independent of the `HOUR_IN_SECONDS`-TTL data blobs it keys
 * still being alive. Reseeding a fixed small integer after such an eviction
 * can exactly re-collide with the value the counter already held, silently
 * reverting every reader to a generation whose cached entries are stale.
 * `wp_cache_incr()` itself still returns `false` on an absent key and does
 * not implicitly seed it — confirmed at runtime against this project's
 * shared Playground instance; see
 * `principal/specs/2026-08-game-library/decisions/coder.md`
 * (`DEC-20260812T220544Z`) for the original cold-start incident this class's
 * seed-must-differ-from-the-reader-default rule was discovered from.
 *
 * PB-8 (cycle-2): a `time()`-based seed (one integer per second) is
 * monotonic only while a scope averages fewer than one bump per second over
 * the key's whole life — a counter seeded at `T0` and bumped `B` times over
 * `E` seconds holds `T0 + B`, and an eviction at `T0 + E` reseeds to
 * `T0 + E`; when `B > E` that reseed is *lower* than the value already in
 * use, so every generation between the two is re-derived and its
 * pre-eviction cache entries are served again as fresh. `activity_gen` —
 * bumped by every library/follow/activity/game write site-wide — is the one
 * scope where this is reachable. `seed()` below uses milliseconds instead
 * (`microtime( true ) * 1000`), leaving 1,000 units of headroom per second
 * of wall clock for bumps between an eviction and the next one, at the cost
 * of only being "very unlikely," not impossible, to collide under a
 * sufficiently extreme write burst — the read/bump protocol itself is
 * unchanged.
 */
final class Generations {

	/**
	 * Object-cache group every generation counter lives in.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'game_library';

	/**
	 * The per-member library generation-counter cache key.
	 *
	 * @param int $user_id Member.
	 * @return string
	 */
	public static function library_key( $user_id ) {
		return 'lib_gen_' . absint( $user_id );
	}

	/**
	 * The per-follower generation-counter cache key.
	 *
	 * @param int $user_id Follower.
	 * @return string
	 */
	public static function follow_key( $user_id ) {
		return 'follow_gen_' . absint( $user_id );
	}

	/**
	 * The per-followed-member generation-counter cache key.
	 *
	 * @param int $user_id Followed member.
	 * @return string
	 */
	public static function follower_key( $user_id ) {
		return 'follower_gen_' . absint( $user_id );
	}

	/**
	 * The global activity generation-counter cache key.
	 *
	 * @return string
	 */
	public static function activity_key() {
		return 'activity_gen';
	}

	/**
	 * The shared invite generation-counter cache key.
	 *
	 * @return string
	 */
	public static function invite_key() {
		return 'invite_gen';
	}

	/**
	 * The `catalog_gen` generation-counter cache key (PB-5, cycle-5) — scopes
	 * `Game_Repository`'s three catalog reads (`get_referenced_games()`,
	 * `referenced_game_slugs()`, `count_referenced_games()`), split out of
	 * the coarser `activity_gen` scope those reads used to share. `activity_gen`
	 * is bumped by every library add, status change, remove, follow, activity
	 * delete, game upsert, and erasure site-wide — but the public `/games/`
	 * catalog listing only actually changes when a game enters or leaves the
	 * referenced set (a game's first-ever library reference, or its last one
	 * being removed) or when a game's own metadata is corrected
	 * (`Game_Repository::upsert()`). A status change by one member on their
	 * own private library entry was invalidating the public catalog, every
	 * sitemap slug page, and every other member's own catalog-adjacent reads
	 * site-wide before this split — see ADR-004's own Decision/Negative
	 * sections for the accepted refinement this scope is part of. Bumped
	 * from `Library_Repository::add_or_update()`/`remove()` only at the
	 * reference-count 0<->1 transition (genuine membership change, not every
	 * write), `Game_Repository::upsert()` (a game's own data changing
	 * invalidates the catalog rows that embed it, even with no membership
	 * change), and `Erasure_Service` (an erasure can drop a game's last
	 * reference; the cascade does not track per-game transitions, so this
	 * bump is unconditional there, matching this class's existing
	 * over-invalidate-rather-than-under-invalidate posture for rare,
	 * account-level writes).
	 *
	 * @return string
	 */
	public static function catalog_key() {
		return 'catalog_gen';
	}

	/**
	 * The `games_gen` generation-counter cache key — see the class docblock
	 * for why this is a single-writer scope (`Game_Repository::upsert()`
	 * writes it, `Game_Repository::search_by_name()` reads it) rather than a
	 * sixth shared scope.
	 *
	 * @return string
	 */
	public static function games_key() {
		return 'games_gen';
	}

	/**
	 * The `members_gen` generation-counter cache key (MR-4/PB-6) — scopes
	 * `Member_Directory`'s cached `gl_manage_library` id lists/totals. Every
	 * bump site (PB-9, cycle-7, corrects an incomplete list that omitted two
	 * of these):
	 *
	 * - `user_register` (`Roles::sync_and_bump_member_directory()`) — a new
	 *   account's default role can hold the capability.
	 * - `deleted_user` (`Roles::bump_member_directory_generation()`) — an
	 *   account, and any capability it held, is gone.
	 * - `set_user_role`/`add_user_role`/`remove_user_role`
	 *   (`Roles::sync_and_bump_member_directory()`, PB-9) — an administrator
	 *   moving a user between roles adds or removes them from the
	 *   `gl_manage_library` set with no `user_register`/`deleted_user` event
	 *   of its own.
	 * - `Roles::grant_capabilities()` — the capability is added to a role
	 *   (activation/upgrade).
	 * - `Roles::remove_capabilities()` (PB-9) — the capability is removed
	 *   from a role (deactivation); object-cache only, so this does not
	 *   violate AC-001 (g) — see that method's own docblock.
	 * - `Visibility::set_public()` — membership in the public-only subset
	 *   changes even though the capability does not.
	 * - `Erasure_Service::bump_generations()` (PB-3, cycle-3) — the eraser
	 *   path deletes `_gl_profile_public` via `delete_gl_user_meta()`, which
	 *   is also membership in the public-only subset changing.
	 *
	 * @return string
	 */
	public static function members_key() {
		return 'members_gen';
	}

	/**
	 * Reads a generation counter, atomically seeding a cold/evicted key
	 * before returning it (VIP-1) — a miss is no longer treated as an
	 * implicit "generation 1" default with nothing actually written to the
	 * cache. `wp_cache_add()` is atomic on a persistent object cache, so
	 * concurrent readers hitting the same cold key all attempt the same
	 * add; exactly one write wins, and every caller (including the losers,
	 * whose own add() silently no-ops) then reads back whichever value won
	 * — they converge on one seed instead of each independently assuming an
	 * un-cached default. Seeded via `seed()` (PB-8, cycle-2) rather than a
	 * small fixed integer — see the class docblock for why.
	 *
	 * @param string $key Generation-counter cache key.
	 * @return int
	 */
	public static function read( $key ) {
		$value = wp_cache_get( $key, self::CACHE_GROUP );

		if ( false !== $value ) {
			return (int) $value;
		}

		wp_cache_add( $key, self::seed(), self::CACHE_GROUP );

		return (int) wp_cache_get( $key, self::CACHE_GROUP );
	}

	/**
	 * Bumps a generation counter, seeding a cold/evicted key to `seed() + 1`
	 * (VIP-1/PB-8) rather than a fixed literal — see the class docblock and
	 * `read()`'s own docblock for why a small fixed seed can silently
	 * re-collide with a generation value an LRU-evicting persistent object
	 * cache already dropped, and `seed()`'s own docblock for why the two use
	 * adjacent-but-distinguishable seed values rather than an identical one.
	 *
	 * @param string $key Generation-counter cache key.
	 * @return void
	 */
	public static function bump( $key ) {
		if ( false === wp_cache_incr( $key, 1, self::CACHE_GROUP ) ) {
			wp_cache_set( $key, self::seed() + 1, self::CACHE_GROUP );
		}
	}

	/**
	 * The cold/evicted-key seed value (PB-8, cycle-2) — current time in
	 * milliseconds, not seconds (`time()`, the pre-cycle-2 seed). A
	 * `time()`-based seed is monotonic only while a scope averages fewer
	 * than one bump per second over the key's whole life; milliseconds
	 * leave 1,000 units of headroom per second of wall clock for bumps
	 * between one eviction and the next, at the cost of only being very
	 * unlikely, not impossible, to collide under a sufficiently extreme
	 * write burst — see the class docblock for the full reasoning.
	 *
	 * CO-5 (cycle-3): assumes a 64-bit PHP build. The millisecond value here
	 * is roughly 1.79e12, about 832x `PHP_INT_MAX` on a 32-bit build, where a
	 * float-to-int cast beyond the integer range is undefined behaviour in
	 * PHP — the resulting seed could be arbitrary, possibly negative, on
	 * such a build, breaking the monotonicity this method exists to provide.
	 * Not worth an epoch offset or a `[seed, counter]` pair for this: the
	 * ruled deployment target is `portable` (spec.md; human ruling CONF-2(i))
	 * — not VIP, contrary to what this comment used to say (CO-12,
	 * cycle-7) — and 32-bit PHP is effectively extinct on any host this
	 * plugin would run on, including VIP, which is 64-bit.
	 *
	 * @return int
	 */
	private static function seed() {
		return (int) ( microtime( true ) * 1000 );
	}
}
