<?php
/**
 * Object-cache helper.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one place in the plugin where `wp_cache_*` is called directly.
 *
 * Every repeated custom-table read goes through {@see GameLib_Cache::get()} /
 * {@see GameLib_Cache::set()}, and every write bumps the generation counter of
 * the scopes it touched with {@see GameLib_Cache::bump()}. Because the current
 * generation is baked into each entry key, a bump invalidates a whole scope in
 * one atomic increment: no deleted-key bookkeeping, no key enumeration, and no
 * `wp_cache_get`/`wp_cache_set` pair standing in for a lock (AC-NFR-010a,
 * Never Do #15). Keeping the calls in a single class is what makes that
 * discipline greppable.
 *
 * Writes are visible on the very next read (AC-NFR-010b): nothing is memoized
 * in a static property, so a bump performed earlier in the request is observed
 * by every later read in that same request.
 */
final class GameLib_Cache {

	/**
	 * Object-cache group for every plugin entry, counters included.
	 *
	 * @var string
	 */
	const GROUP = 'gamelib';

	/**
	 * Key prefix for the generation counters (`gamelib_gen_{scope}`).
	 *
	 * @var string
	 */
	const GENERATION_PREFIX = 'gamelib_gen_';

	/**
	 * Floor — and default — for every entry TTL (AC-NFR-010a: no sub-900s
	 * object-cache TTLs). {@see GameLib_Cache::set()} clamps to this, so a
	 * caller cannot introduce a shorter-lived entry by accident.
	 *
	 * @var int
	 */
	const MIN_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * TTL for the generation counters themselves: 0 = no expiry.
	 *
	 * A counter must outlive every entry derived from it, so it is never given
	 * a shorter lease than the data it invalidates.
	 *
	 * @var int
	 */
	const GENERATION_TTL = 0;

	/**
	 * Scope covering the shared IGDB game store's own entry keys — game rows and
	 * slug pointers.
	 *
	 * **Nothing bumps this scope, by design** (CO-2, PB-2). Every writer of
	 * `gamelib_games` re-primes the rows it touched from the values it just
	 * wrote, so there is never a stale entry to invalidate; a bump here would
	 * only discard rows that are already correct. `GameLib_Refresh_Job::run()`
	 * used to bump it *after* upserting 500 rows and priming their entries,
	 * which made every one of those writes unreachable in the request that
	 * wrote it.
	 *
	 * What a *content* change has to invalidate is the pages that render game
	 * content, and that is {@see SCOPE_GAME_CONTENT}'s job.
	 *
	 * @var string
	 */
	const SCOPE_GAMES = 'games';

	/**
	 * Scope standing for "the rendered content of the game store has changed".
	 *
	 * Its generation is folded into every cached library page (see
	 * {@see GameLib_Library::query()}), and it is bumped by the two deliberate
	 * acts that rewrite game content: the hourly refresh tick, and the single-row
	 * refresh behind the AC-035(f) admin row action and the importer's by-id
	 * hydration. One bump per act, never one per row.
	 *
	 * It exists because the store's own entries and the pages that render them
	 * need different invalidation (CO-2, PB-2): the store re-primes its rows, so
	 * discarding them is pure cost, while a renamed game has to reach every
	 * member's cards on the next read.
	 *
	 * @var string
	 */
	const SCOPE_GAME_CONTENT = 'game_content';

	/**
	 * Scope covering the Steam appid → IGDB id map.
	 *
	 * Split out of `SCOPE_GAMES` (PB-1): an import resolving thousands of
	 * appids must not be able to evict game rows and library pages, and nothing
	 * that renders a library page reads this map.
	 *
	 * @var string
	 */
	const SCOPE_STEAM_MAP = 'steam_map';

	/**
	 * Scope covering the shared activity *feed*: the head page of every member's
	 * followed-members query.
	 *
	 * Bumped by an event insert, by a follow-edge change, and by a member's
	 * events being purged — all three change what the newest page of somebody
	 * else's feed contains, and a new event is immediately visible to every
	 * follower because of it.
	 *
	 * Two things used to be keyed here and are not any more (PB-1): deep feed
	 * pages, which are {@see SCOPE_ACTIVITY_PAGES}, and a member's own event
	 * list, which is {@see activity_scope()}. Both were being discarded by every
	 * library write on the site while being unable to change.
	 *
	 * @var string
	 */
	const SCOPE_ACTIVITY = 'activity';

	/**
	 * Scope covering the *deep* pages of the activity feed — everything below a
	 * keyset cursor (`before > 0`).
	 *
	 * A page of events strictly older than a fixed id cannot gain a member, so
	 * an event insert may not evict it: at one library write a minute site-wide,
	 * folding the shared feed generation into these keys meant no member ever
	 * read an already-paged "Load more" page from cache, and the plugin's most
	 * expensive query — the activity × follows join — ran on every one (PB-1).
	 *
	 * What can change such a page is a follow edge (a newly followed member's
	 * older events belong in it) and a purge (a deleted member's events must not
	 * survive in it), so those two bump this scope alongside
	 * {@see SCOPE_ACTIVITY}.
	 *
	 * @var string
	 */
	const SCOPE_ACTIVITY_PAGES = 'activity_pages';

	/**
	 * Scope covering invite aggregates (admin table counts, per-issuer
	 * counts). The redemption-path lookup is intentionally uncached
	 * (AC-NFR-010c) and must not read through this class.
	 *
	 * @var string
	 */
	const SCOPE_INVITES = 'invites';

	/**
	 * Scope covering member-visibility reads, including the public-profile
	 * sitemap provider query.
	 *
	 * @var string
	 */
	const SCOPE_VISIBILITY = 'visibility';

	/**
	 * Per-member scope for `gamelib_library` reads.
	 *
	 * @param int $user_id Library owner.
	 * @return string Scope name.
	 */
	public static function library_scope( $user_id ) {
		return 'lib_' . absint( $user_id );
	}

	/**
	 * Per-member scope for `gamelib_follows` reads (edges, counts, lists).
	 *
	 * @param int $user_id Member whose follow data is cached.
	 * @return string Scope name.
	 */
	public static function follows_scope( $user_id ) {
		return 'follows_' . absint( $user_id );
	}

	/**
	 * Per-member scope for one member's own activity list (AC-031d).
	 *
	 * A profile's activity list is a pure function of that member's own events,
	 * so nothing another member does may evict it (PB-1). Before this split, one
	 * status change by anybody discarded every public profile's cached activity
	 * list along with every cached feed page — and `GameLib_Library::add()`,
	 * `set_status()` and `bulk_set_status()`, the three commonest member
	 * actions, all reach `GameLib_Activity::record()`.
	 *
	 * AC-NFR-010(b) still holds for the actor: their own write bumps their own
	 * scope, so their next read is immediate.
	 *
	 * @param int $user_id Member whose events are cached.
	 * @return string Scope name.
	 */
	public static function activity_scope( $user_id ) {
		return 'act_' . absint( $user_id );
	}

	/**
	 * Current generation of a scope.
	 *
	 * A counter that is absent — first read of the scope, or an eviction — is
	 * seeded through `wp_cache_add()` so only the first writer wins and every
	 * caller in the request agrees on one value.
	 *
	 * @param string $scope Scope name.
	 * @return int Generation number.
	 */
	public static function generation( $scope ) {
		$key   = self::generation_key( $scope );
		$found = false;

		$generation = wp_cache_get( $key, self::GROUP, false, $found );

		if ( $found && is_numeric( $generation ) ) {
			return (int) $generation;
		}

		$seed = self::new_generation();

		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- GENERATION_TTL is 0 (no expiry): a counter must outlive every entry derived from it.
		if ( wp_cache_add( $key, $seed, self::GROUP, self::GENERATION_TTL ) ) {
			return $seed;
		}

		// Another process seeded first — adopt its value rather than ours.
		$generation = wp_cache_get( $key, self::GROUP, false, $found );

		return ( $found && is_numeric( $generation ) ) ? (int) $generation : $seed;
	}

	/**
	 * Invalidate a scope by incrementing its generation counter.
	 *
	 * `wp_cache_incr()` is atomic under Memcached and Redis, so concurrent
	 * writers cannot lose an invalidation the way a get-then-set pair would.
	 *
	 * @param string $scope Scope name.
	 * @return int The new generation.
	 */
	public static function bump( $scope ) {
		$key  = self::generation_key( $scope );
		$next = wp_cache_incr( $key, 1, self::GROUP );

		if ( false !== $next ) {
			return (int) $next;
		}

		/*
		 * No counter to increment. Seeding a fresh one is itself a valid
		 * invalidation: entries written under the previous counter can never
		 * be read again, because their keys are unreachable from the new
		 * generation.
		 */
		$seed = self::new_generation();

		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- GENERATION_TTL is 0 (no expiry): a counter must outlive every entry derived from it.
		if ( wp_cache_add( $key, $seed, self::GROUP, self::GENERATION_TTL ) ) {
			return $seed;
		}

		$next = wp_cache_incr( $key, 1, self::GROUP );

		return ( false !== $next ) ? (int) $next : $seed;
	}

	/**
	 * Invalidate several scopes at once.
	 *
	 * A single write often spans scopes — adding a game touches the owner's
	 * library and the activity feed — and both must be bumped in the same
	 * request as the database write.
	 *
	 * @param string[] $scopes Scope names.
	 * @return void
	 */
	public static function bump_many( array $scopes ) {
		foreach ( array_unique( $scopes ) as $scope ) {
			self::bump( $scope );
		}
	}

	/**
	 * Read a cached value from a scope.
	 *
	 * Callers must use `$found` rather than testing the return value: `false`,
	 * `null`, and `array()` are all legitimate cached payloads (a negative
	 * Steam-map match, for one).
	 *
	 * @param string       $scope Scope name.
	 * @param string|array $key   Entry key; arrays are hashed via their JSON form.
	 * @param bool         $found Set by reference: true on a cache hit.
	 * @return mixed Cached value, or false on a miss.
	 */
	public static function get( $scope, $key, &$found = null ) {
		$found = false;

		return wp_cache_get( self::entry_key( $scope, $key ), self::GROUP, false, $found );
	}

	/**
	 * Write a value into a scope.
	 *
	 * @param string       $scope Scope name.
	 * @param string|array $key   Entry key; arrays are hashed via their JSON form.
	 * @param mixed        $value Value to cache.
	 * @param int          $ttl   Optional. Lifetime in seconds; clamped up to
	 *                            {@see GameLib_Cache::MIN_TTL}.
	 * @return bool True on success.
	 */
	public static function set( $scope, $key, $value, $ttl = self::MIN_TTL ) {
		$expiry = max( self::MIN_TTL, (int) $ttl );

		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- $expiry is clamped to MIN_TTL (15 * MINUTE_IN_SECONDS) on the line above.
		return wp_cache_set( self::entry_key( $scope, $key ), $value, self::GROUP, $expiry );
	}

	/**
	 * Remove one entry from a scope.
	 *
	 * The scalpel to {@see bump()}'s hammer: used where a single key is known to
	 * be wrong and the rest of the scope is fine — a game whose IGDB slug
	 * changed, leaving the previous slug pointer aimed at a row that no longer
	 * carries that slug (PB-1).
	 *
	 * @param string       $scope Scope name.
	 * @param string|array $key   Entry key.
	 * @return bool True when an entry was removed.
	 */
	public static function delete( $scope, $key ) {
		return (bool) wp_cache_delete( self::entry_key( $scope, $key ), self::GROUP );
	}

	/**
	 * Read many entries from one scope in a single round trip (PB-2).
	 *
	 * `get()` resolves the scope's generation on every call, so a loop of N
	 * single reads is 2N object-cache operations — network hops on VIP. This
	 * resolves the generation once and issues one `wp_cache_get_multiple()`.
	 *
	 * A value cached as literal `false` is indistinguishable from a miss through
	 * `wp_cache_get_multiple()`, which returns `false` for both — so this method
	 * reports a hit only for a value that is not `false`, and a caller that needs
	 * a "no such row" marker must store something else. `null` is not a safe
	 * choice either: core's in-process cache preserves it, but a persistent
	 * drop-in is free to normalise it, and a normalised `null` reads back here as
	 * a permanent miss (PB-5). Store a string sentinel and translate it on read —
	 * `GameLib_Game_Store::NO_ROW` and `STEAM_NO_ROW` are the two in the plugin.
	 *
	 * @param string                $scope Scope name.
	 * @param array<string|int,string> $keys Entry keys, keyed by whatever the
	 *                                       caller wants the results keyed by
	 *                                       (typically the row id).
	 * @return array<string|int,mixed> Hits only, under the caller's own keys.
	 */
	public static function get_many( $scope, array $keys ) {
		if ( empty( $keys ) ) {
			return array();
		}

		$generation = self::generation( $scope );
		$lookup     = array();

		foreach ( $keys as $caller_key => $key ) {
			$lookup[ self::compose_key( $scope, $generation, $key ) ] = $caller_key;
		}

		$values = wp_cache_get_multiple( array_keys( $lookup ), self::GROUP );

		if ( ! is_array( $values ) ) {
			return array();
		}

		$found = array();

		foreach ( $values as $cache_key => $value ) {
			if ( false === $value || ! isset( $lookup[ $cache_key ] ) ) {
				continue;
			}

			$found[ $lookup[ $cache_key ] ] = $value;
		}

		return $found;
	}

	/**
	 * Write many entries into one scope in a single round trip (PB-2).
	 *
	 * Unlike {@see get_many()} the array is keyed by the *entry key*, because a
	 * write has nothing to hand back and the entry key is what the caller
	 * already composed.
	 *
	 * @param string              $scope  Scope name.
	 * @param array<string,mixed> $values Entry key → value.
	 * @param int                 $ttl    Optional. Lifetime in seconds; clamped
	 *                                    up to {@see MIN_TTL}.
	 * @return bool True when every entry was written.
	 */
	public static function set_many( $scope, array $values, $ttl = self::MIN_TTL ) {
		if ( empty( $values ) ) {
			return true;
		}

		$expiry     = max( self::MIN_TTL, (int) $ttl );
		$generation = self::generation( $scope );
		$data       = array();

		foreach ( $values as $key => $value ) {
			$data[ self::compose_key( $scope, $generation, $key ) ] = $value;
		}

		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- $expiry is clamped to MIN_TTL (15 * MINUTE_IN_SECONDS) on the line above.
		$results = wp_cache_set_multiple( $data, self::GROUP, $expiry );

		if ( ! is_array( $results ) ) {
			return (bool) $results;
		}

		return ! in_array( false, $results, true );
	}

	/**
	 * Key of a scope's generation counter.
	 *
	 * @param string $scope Scope name.
	 * @return string Counter key.
	 */
	private static function generation_key( $scope ) {
		return self::GENERATION_PREFIX . (string) $scope;
	}

	/**
	 * Compose a full entry key: scope, current generation, caller fragment.
	 *
	 * @param string       $scope Scope name.
	 * @param string|array $key   Entry key.
	 * @return string Object-cache key.
	 */
	private static function entry_key( $scope, $key ) {
		return self::compose_key( $scope, self::generation( $scope ), $key );
	}

	/**
	 * Compose a full entry key against an already-resolved generation.
	 *
	 * Split out of {@see entry_key()} so a batch operation resolves the counter
	 * once instead of once per key (PB-2).
	 *
	 * @param string       $scope      Scope name.
	 * @param int          $generation Current generation of the scope.
	 * @param string|array $key        Entry key.
	 * @return string Object-cache key.
	 */
	private static function compose_key( $scope, $generation, $key ) {
		return (string) $scope . ':' . (int) $generation . ':' . self::fragment( $key );
	}

	/**
	 * Normalize a caller-supplied key into a short, cache-safe token.
	 *
	 * Query arguments and search terms carry spaces, punctuation, and
	 * arbitrary length — none of which memcached keys tolerate — so anything
	 * outside a conservative character set, or longer than 64 characters, is
	 * hashed. Hashing is for key hygiene only; the generation counter, not the
	 * hash, is what invalidates.
	 *
	 * @param string|array $key Entry key.
	 * @return string Key fragment.
	 */
	private static function fragment( $key ) {
		if ( is_array( $key ) ) {
			return md5( (string) wp_json_encode( $key ) );
		}

		$fragment = (string) $key;

		if ( strlen( $fragment ) > 64 || preg_match( '/[^A-Za-z0-9_.:-]/', $fragment ) ) {
			return md5( $fragment );
		}

		return $fragment;
	}

	/**
	 * Seed value for a counter that does not exist yet.
	 *
	 * Seeded from the clock rather than from 1 so that a counter lost to
	 * eviction restarts *above* every generation it has already issued. A
	 * fixed seed would rewind the scope and let live entries written under an
	 * earlier incarnation be read back as if current; a clock seed turns the
	 * same eviction into plain cache misses and fresh database reads, which is
	 * the behaviour the spec's Known Risks entry describes.
	 *
	 * See principal/adr/005-generation-counter-seed.md — the spec text says
	 * "a missing counter initializes to 1"; the seed is below the AC line and
	 * the stale-collision it would allow is not.
	 *
	 * @return int Seed generation.
	 */
	private static function new_generation() {
		return time();
	}
}
