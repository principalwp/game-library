<?php
/**
 * The only read/write path to `gl_games`.
 *
 * @package Game_Library
 */

namespace Game_Library\Data;

use Game_Library\Page_Cache;
use Game_Library\Router;
use Game_Library\Schema;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Game_Repository.
 *
 * Cached CRUD over the `gl_games` table (DD-001/ADR-001) — cached IGDB game
 * metadata keyed by IGDB id (D26, D34). Every direct `$wpdb` read is wrapped
 * in `wp_cache_get()`/`wp_cache_set()` in the `game_library` object-cache
 * group at a 3600-second TTL (AC-NFR-004). A single game row is invalidated
 * by an explicit `wp_cache_delete()` on write, not a generation counter —
 * unlike the per-user/global scopes on `Library_Repository`, a game row has
 * exactly one writer path (`upsert()`) and no cross-request enumeration
 * problem to solve.
 *
 * `search_by_name()` was added in Task 18: not part of Task 3's own
 * Description, but this class's own opening docblock line already
 * designates it "the only read/write path to `gl_games`" — the moderation
 * screen's "find a cached game" (AC-047) is exactly that kind of read, and
 * Task 18's own constraint ("All reads go through the repositories … no
 * unbounded SELECT") rules out `Moderation_Page` issuing its own `$wpdb`
 * query. Same precedent as `Activity_Repository::get_recent()` (also Task
 * 18) and `get_referenced_games()` (Task 15) — see those methods' docblocks
 * and `principal/adr/008-catalog-listing-and-games-pagination-route.md`.
 * Scoped by its own `games_gen` counter (bumped inside `upsert()`) rather
 * than the shared `activity_gen` — a game's cached name/summary/etc. changes
 * on every `upsert()` call, independent of any library add/remove, so tying
 * search-result freshness to `activity_gen` would neither invalidate
 * correctly on every edit nor avoid the unrelated invalidation `activity_gen`
 * churns through on every member's library write.
 *
 * `upsert()` bumps *both* counters on every successful write — `games_gen`
 * (its own single-writer scope, scoping `search_by_name()`) and, as of PB-5
 * (cycle-5), the shared `catalog_gen` (scoping the public catalog
 * listing/count, which hydrate a game's cached name/summary/cover; split
 * out of the coarser `activity_gen` those reads used to share — see
 * `Generations::catalog_key()`'s own docblock). Before arch-pre-2 finding
 * AR-1, only the caller-side `Moderation_Page` remembered to bump the
 * catalog's generation counter after calling `upsert()`; `Library_Controller`'s
 * own two `upsert()` call sites (add-from-IGDB, manual refresh) did not,
 * leaving the public catalog serving stale name/cover data for up to an
 * hour after either action. This class now owns both bumps itself so every
 * current and future write path through `upsert()` invalidates correctly
 * with nothing for the caller to remember. Both the read and bump protocol
 * (cache-miss default of
 * generation 1, cold-bump seed of 2) go through `Generations::read()`/
 * `Generations::bump()` (arch-pre-1 finding AR-2, extended to `games_gen`
 * by arch-pre-2 finding AR-3) — `games_gen` itself stays a single-writer
 * scope local in *meaning* to this class (only `upsert()` writes it, only
 * `search_by_name()` reads it), even though the read/bump *mechanism* is
 * now shared with every other generation-counter scope in the plugin.
 *
 * Custom tables outside `wp_posts`/`wp_postmeta` like this one are
 * recommended to go through VIP's database review process for
 * backup/restore compatibility.
 */
final class Game_Repository {

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
	 * Freshness for the reference-count lookup comes from the shared
	 * `activity_gen` generation counter folded into its cache key, not from
	 * a short TTL.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'game_library';

	/**
	 * Age, in seconds, past which one of the three catalog reads' own
	 * regeneration lock (PB-6, cycle-5) is treated as abandoned by a
	 * request that died mid-query rather than still legitimately in
	 * progress — see `under_regen_lock()`'s own docblock.
	 *
	 * @var int
	 */
	private const REGEN_LOCK_STALE_AFTER = 30;

	/**
	 * Manual per-game IGDB refresh cooldown, in seconds (AC-008). The single
	 * owner of the cooldown key/TTL contract shared by
	 * `Library_Controller::refresh()` (the member-facing REST route) and
	 * `Moderation_Page::handle_refresh_game()` (the admin "Refresh from
	 * IGDB" action) — before this constant and `refresh_cooldown_key()`
	 * existed, both consumers carried byte-identical copies of the constant
	 * and the key builder with a docblock in each asking the reader to keep
	 * them in sync (arch-pre-3 architecture review, finding AR-3). This class
	 * already owns every other read/write path to `gl_games`, so it owns
	 * this contract too — do not change the key string, the prefix, the
	 * `absint()` call, or the `3600` value; a changed key silently resets
	 * every live cooldown.
	 *
	 * @var int
	 */
	public const REFRESH_COOLDOWN = 3600;

	/**
	 * The per-game manual-refresh cooldown transient key — see
	 * `REFRESH_COOLDOWN`'s own docblock for why this is the single owner both
	 * `Library_Controller` and `Moderation_Page` call rather than each
	 * carrying its own copy.
	 *
	 * @param int $igdb_id IGDB game id.
	 * @return string
	 */
	public static function refresh_cooldown_key( $igdb_id ) {
		return 'game_library_refresh_' . absint( $igdb_id );
	}

	/**
	 * Fetches one cached game by IGDB id.
	 *
	 * @param int $igdb_id IGDB game id.
	 * @return array<string,mixed>|null The hydrated row, or null when no game
	 *                                  with this id has been cached.
	 */
	public function get( $igdb_id ) {
		$igdb_id = absint( $igdb_id );

		if ( ! $igdb_id ) {
			return null;
		}

		$cache_key = $this->game_cache_key( $igdb_id );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return false === $cached ? null : $cached;
		}

		global $wpdb;
		$table = Schema::games_table();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE igdb_id = %d", $igdb_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::games_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached (including negative results) via wp_cache_set() below.

		$game = $row ? $this->hydrate_row( $row ) : null;

		// Negative results are cached too (via the false sentinel) so a
		// request for a stale/typoed slug or id does not re-query on every
		// hit within the TTL window.
		wp_cache_set( $cache_key, null === $game ? false : $game, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $game;
	}

	/**
	 * Fetches one cached game by its public catalog slug.
	 *
	 * @param string $slug Game slug.
	 * @return array<string,mixed>|null The hydrated row, or null when no game
	 *                                  with this slug has been cached.
	 */
	public function get_by_slug( $slug ) {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return null;
		}

		$slug_cache_key = $this->game_slug_cache_key( $slug );
		$cached_id      = wp_cache_get( $slug_cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return false === $cached_id ? null : $this->get( $cached_id );
		}

		global $wpdb;
		$table = Schema::games_table();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::games_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached (including negative results) via wp_cache_set() below.

		if ( ! $row ) {
			wp_cache_set( $slug_cache_key, false, self::CACHE_GROUP, HOUR_IN_SECONDS );

			return null;
		}

		$game = $this->hydrate_row( $row );

		// Prime both the slug->id mapping and the canonical per-id row cache
		// so a later get( $igdb_id ) for the same game is also a hit.
		wp_cache_set( $slug_cache_key, $game['igdb_id'], self::CACHE_GROUP, HOUR_IN_SECONDS );
		wp_cache_set( $this->game_cache_key( $game['igdb_id'] ), $game, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $game;
	}

	/**
	 * Fetches many games by id, batching every object-cache miss into one
	 * `IN (…)` query — never one query per id.
	 *
	 * @param int[] $igdb_ids IGDB game ids.
	 * @return array<int,array<string,mixed>> Hydrated rows keyed by igdb_id,
	 *                                         in the order requested. Ids with
	 *                                         no matching row are omitted.
	 */
	public function get_many( array $igdb_ids ) {
		$igdb_ids = array_values( array_unique( array_filter( array_map( 'absint', $igdb_ids ) ) ) );

		if ( empty( $igdb_ids ) ) {
			return array();
		}

		$results = array();
		$missing = array();

		foreach ( $igdb_ids as $igdb_id ) {
			$cached = wp_cache_get( $this->game_cache_key( $igdb_id ), self::CACHE_GROUP, false, $found );

			if ( $found ) {
				if ( false !== $cached ) {
					$results[ $igdb_id ] = $cached;
				}
			} else {
				$missing[] = $igdb_id;
			}
		}

		if ( ! empty( $missing ) ) {
			global $wpdb;
			$table        = Schema::games_table();
			$placeholders = implode( ', ', array_fill( 0, count( $missing ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::games_table() and $placeholders is a fixed run of %d tokens built by array_fill(), neither is user data; every value is bound through prepare() below; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE igdb_id IN ({$placeholders})", $missing ), ARRAY_A );

			$found_ids = array();

			foreach ( (array) $rows as $row ) {
				$hydrated                       = $this->hydrate_row( $row );
				$results[ $hydrated['igdb_id'] ] = $hydrated;
				$found_ids[]                     = $hydrated['igdb_id'];

				wp_cache_set( $this->game_cache_key( $hydrated['igdb_id'] ), $hydrated, self::CACHE_GROUP, HOUR_IN_SECONDS );
			}

			// Cache a negative result for every requested id that still went
			// unmatched, so a request for an id that does not exist does not
			// re-query on every subsequent hit within the TTL window.
			foreach ( array_diff( $missing, $found_ids ) as $unmatched_id ) {
				wp_cache_set( $this->game_cache_key( $unmatched_id ), false, self::CACHE_GROUP, HOUR_IN_SECONDS );
			}
		}

		$ordered = array();

		foreach ( $igdb_ids as $igdb_id ) {
			if ( isset( $results[ $igdb_id ] ) ) {
				$ordered[ $igdb_id ] = $results[ $igdb_id ];
			}
		}

		return $ordered;
	}

	/**
	 * Inserts a new cached game row, or overwrites every field but
	 * `date_cached` on an existing one, keyed by `igdb_id`.
	 *
	 * Writes `genres`/`platforms` through `wp_json_encode()` (never raw
	 * `json_encode()`), defaulting to `[]`. `first_release_date` is stored as
	 * the caller's value in seconds, untouched — no multiplication or
	 * division by 1000 (AC-005).
	 *
	 * On success, invalidates the single-row cache entries for this game and
	 * bumps both `games_gen` (this class's own `search_by_name()` scope) and
	 * the shared `catalog_gen` (the public catalog listing/count, PB-5,
	 * cycle-5) — every caller gets both invalidated automatically, with
	 * nothing left for the caller to remember (arch-pre-2 finding AR-1).
	 * `reference_count()` stays on `activity_gen`, unaffected by this split
	 * — see `Generations::catalog_key()`'s own docblock.
	 *
	 * @param array<string,mixed> $data {
	 *     Game fields. `igdb_id`, `slug`, and `name` are required; every other
	 *     key is optional and nullable.
	 *
	 *     @type int         $igdb_id             IGDB game id.
	 *     @type string      $slug                Public catalog slug.
	 *     @type string      $name                Game title.
	 *     @type string|null $summary             Short description.
	 *     @type int|null    $first_release_date  Unix timestamp in seconds.
	 *     @type string|null $cover_image_id      IGDB `cover.image_id`.
	 *     @type string[]    $genres              Genre names.
	 *     @type string[]    $platforms           Platform names.
	 *     @type float|null  $aggregated_rating   Critic aggregate, 0–100.
	 *     @type string|null $igdb_url            Attribution target.
	 * }
	 * @return true|WP_Error True on success, WP_Error on invalid input or a
	 *                       database failure.
	 */
	public function upsert( array $data ) {
		$igdb_id = isset( $data['igdb_id'] ) ? absint( $data['igdb_id'] ) : 0;

		if ( ! $igdb_id ) {
			return new WP_Error( 'gl_invalid_game', __( 'A game requires a valid IGDB id.', 'game-library' ) );
		}

		$slug = isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '';
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';

		if ( '' === $slug || '' === $name ) {
			return new WP_Error( 'gl_invalid_game', __( 'A game requires a slug and a name.', 'game-library' ) );
		}

		// PB-7: read before write, to detect a slug change below — cheap,
		// since get() is itself cached.
		$existing = $this->get( $igdb_id );

		$summary            = isset( $data['summary'] ) ? sanitize_textarea_field( $data['summary'] ) : null;
		// (int), not absint() (CO-4): IGDB returns a genuine negative Unix
		// timestamp for any game released before 1970 — absint()'s
		// absolute-value coercion would silently mirror that into a
		// positive, wrong date instead of preserving it. The %d
		// placeholder below (and the widened bigint(20) column,
		// Schema::games_ddl()) both already support a signed value.
		$first_release_date = isset( $data['first_release_date'] ) ? (int) $data['first_release_date'] : null;
		$cover_image_id     = isset( $data['cover_image_id'] ) ? sanitize_key( $data['cover_image_id'] ) : null;
		$genres             = isset( $data['genres'] ) && is_array( $data['genres'] ) ? array_values( array_map( 'sanitize_text_field', $data['genres'] ) ) : array();
		$platforms          = isset( $data['platforms'] ) && is_array( $data['platforms'] ) ? array_values( array_map( 'sanitize_text_field', $data['platforms'] ) ) : array();
		$aggregated_rating  = isset( $data['aggregated_rating'] ) ? min( 100.0, max( 0.0, floatval( $data['aggregated_rating'] ) ) ) : null;
		$igdb_url           = isset( $data['igdb_url'] ) ? esc_url_raw( $data['igdb_url'] ) : null;

		$now = gmdate( 'Y-m-d H:i:s' );

		global $wpdb;
		$table = Schema::games_table();

		$fields = array(
			'igdb_id'             => array( $igdb_id, '%d' ),
			'slug'                => array( $slug, '%s' ),
			'name'                => array( $name, '%s' ),
			'summary'             => array( $summary, '%s' ),
			'first_release_date'  => array( $first_release_date, '%d' ),
			'cover_image_id'      => array( $cover_image_id, '%s' ),
			'genres'              => array( wp_json_encode( $genres ), '%s' ),
			'platforms'           => array( wp_json_encode( $platforms ), '%s' ),
			'aggregated_rating'   => array( $aggregated_rating, '%f' ),
			'igdb_url'            => array( $igdb_url, '%s' ),
			'date_cached'         => array( $now, '%s' ),
			'date_refreshed'      => array( $now, '%s' ),
		);

		list( $columns_sql, $placeholders_sql, $values ) = $this->build_insert_clause( $fields );

		// date_cached is deliberately absent from the UPDATE clause so the
		// first-write timestamp survives every later refresh/moderation edit.
		$sql = "INSERT INTO {$table} ({$columns_sql}) VALUES ({$placeholders_sql})
			ON DUPLICATE KEY UPDATE
				slug = VALUES(slug),
				name = VALUES(name),
				summary = VALUES(summary),
				first_release_date = VALUES(first_release_date),
				cover_image_id = VALUES(cover_image_id),
				genres = VALUES(genres),
				platforms = VALUES(platforms),
				aggregated_rating = VALUES(aggregated_rating),
				igdb_url = VALUES(igdb_url),
				date_refreshed = VALUES(date_refreshed)";

		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql's dynamic portions are hardcoded column literals and the literal keyword NULL only; every scalar value is bound through the %d/%s/%f placeholders built above.
		}

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $sql was built by $wpdb->prepare() above; ON DUPLICATE KEY UPDATE has no $wpdb->insert()/update() equivalent.

		if ( false === $result ) {
			return new WP_Error( 'gl_db_error', __( 'The game could not be saved.', 'game-library' ) );
		}

		wp_cache_delete( $this->game_cache_key( $igdb_id ), self::CACHE_GROUP );
		wp_cache_delete( $this->game_slug_cache_key( $slug ), self::CACHE_GROUP );

		// PB-7: a slug change (e.g. AC-008's manual "Refresh from IGDB"
		// pulling a changed slug) leaves the OLD slug => id mapping alive in
		// the object cache for up to an hour otherwise — the ON DUPLICATE
		// KEY UPDATE clause above already rewrites the row's own slug
		// column, but nothing previously invalidated get_by_slug()'s cache
		// entry for the slug this game no longer uses, so /games/{old-slug}/
		// kept returning 200 with this game's content: a duplicate,
		// non-canonical, indexable URL for the same entity (DD-005/ADR-005).
		if ( $existing && $existing['slug'] !== $slug ) {
			wp_cache_delete( $this->game_slug_cache_key( $existing['slug'] ), self::CACHE_GROUP );
			Page_Cache::purge( Router::game_url( $existing['slug'] ) );
		}

		Generations::bump( Generations::games_key() );
		// Every other write path against this table (Library_Controller's
		// refresh/add-from-IGDB REST actions, Moderation_Page's save/refresh
		// forms) reads a hydrated game row through this class, so upsert()
		// owning the shared catalog_gen bump too — not just its own
		// single-writer games_gen — means every caller's cache invalidation
		// is automatic rather than a second call the caller must remember
		// (arch-pre-2 architecture review, finding AR-1). PB-5 (cycle-5):
		// this was activity_key() before the catalog scope split — a game's
		// own data changing has nothing to do with any member's library
		// write, so bumping the coarser activity_gen here invalidated
		// status_counts_for_game()/public_holders_for_game() for this game
		// (an unrelated scope) on every moderator correction for no reason;
		// catalog_key() is the scope that actually needs invalidating when
		// a cached game row's own content changes.
		Generations::bump( Generations::catalog_key() );

		// VIP-2: same reasoning as the generation-counter bumps above,
		// extended to VIP's edge cache — /games/{slug}/ and the /games/
		// listing are both anonymous, edge-cached HTTP 200 routes, so a
		// moderator's correction (or an automated refresh) would otherwise
		// keep serving the previous content at the edge for up to 30
		// minutes after the object-cache/database write already took
		// effect.
		Page_Cache::purge( Router::game_url( $slug ) );
		Page_Cache::purge( Router::catalog_url() );

		return true;
	}

	/**
	 * Bounded, case-insensitive substring search over cached game names — the
	 * moderation screen's game finder (AC-047). Never unbounded: `$limit` is
	 * always applied.
	 *
	 * @param string $query Free-text search query.
	 * @param int    $limit Maximum rows to return.
	 * @return array<int,array<string,mixed>> Hydrated rows, ordered by name.
	 */
	public function search_by_name( $query, $limit = 20 ) {
		$query = sanitize_text_field( (string) $query );
		$limit = max( 1, absint( $limit ) );

		if ( '' === $query ) {
			return array();
		}

		$games_gen = Generations::read( Generations::games_key() );
		$cache_key = sprintf( 'game_search_%s_%d_%d', md5( $query ), $limit, $games_gen );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table = Schema::games_table();
		$like  = '%' . $wpdb->esc_like( $query ) . '%';

		// PB-10: a leading-wildcard LIKE ('%…%') cannot use the declared
		// KEY name (name(50)) index — it forces a full table scan
		// regardless. Bounded today (admin-only, LIMIT 20, cached under
		// games_gen); only worth revisiting if gl_games grows into the tens
		// of thousands of rows, at which point a prefix-match ('…%', which
		// can use the index) with this substring form as a fallback when it
		// returns nothing would be the fix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::games_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE name LIKE %s ORDER BY name ASC, igdb_id ASC LIMIT %d", $like, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::games_table(), never user input; $like is bound through prepare()'s %s placeholder.
			ARRAY_A
		);

		$items = array();

		foreach ( (array) $rows as $row ) {
			$hydrated = $this->hydrate_row( $row );
			$items[]  = $hydrated;

			// Prime the per-id cache too, matching get_many()'s own priming.
			wp_cache_set( $this->game_cache_key( $hydrated['igdb_id'] ), $hydrated, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		wp_cache_set( $cache_key, $items, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $items;
	}

	/**
	 * Counts the library entries across every member that reference a game —
	 * used to decide whether `/games/{slug}/` still has an active holder
	 * (AC-040).
	 *
	 * This is the one place `Game_Repository` reads `gl_library_entries`
	 * rather than `gl_games`; the spec assigns `reference_count()` to this
	 * class specifically because the figure describes the game, not a
	 * member's library. Freshness rides the same `activity_gen` generation
	 * counter `Library_Repository` bumps on every add/remove — see the
	 * Transients and object cache table in section 6 of the spec.
	 *
	 * @param int $igdb_id IGDB game id.
	 * @return int Number of library entries referencing this game.
	 */
	public function reference_count( $igdb_id ) {
		$igdb_id = absint( $igdb_id );

		if ( ! $igdb_id ) {
			return 0;
		}

		$cache_key = sprintf( 'game_refcount_%d_%d', $igdb_id, $this->read_activity_generation() );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table = Schema::library_entries_table();

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE igdb_id = %d", $igdb_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::library_entries_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.

		// HOUR_IN_SECONDS +/- 10% jitter (PB-4): every key on this shared
		// activity_gen scope created in the same write burst otherwise
		// expires in the same instant — see
		// Library_Repository::jittered_ttl()'s own docblock for the full
		// reasoning, applied here identically. Freshness still comes from
		// the generation counter, never this TTL.
		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, HOUR_IN_SECONDS + wp_rand( -360, 360 ) ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- the expression is HOUR_IN_SECONDS +/- a bounded 360s jitter (PB-4), always >= 3240s; the sniff cannot statically evaluate wp_rand()'s runtime result.

		return $count;
	}

	/**
	 * One page of the public catalog — every cached game held by at least one
	 * member, 24 per page (AC-039), ordered alphabetically for a stable browse
	 * order. Added in Task 15: not part of Task 3's own Description, but the
	 * spec's own Transients and object cache table (section 6) already names
	 * this exact cache key (`catalog_{page}_{actgen}`) as the "Public catalog
	 * index page" entry, confirming the method was always intended here —
	 * see the Task 15 coder decision log and
	 * `principal/adr/008-catalog-listing-and-games-pagination-route.md`.
	 *
	 * Freshness rides `catalog_gen` (PB-5, cycle-5) — bumped by
	 * `Library_Repository::add_or_update()`/`remove()` only when the set of
	 * referenced games actually changes (a game's first-ever reference or
	 * its last one being removed), by `Game_Repository::upsert()` (a
	 * game's own cached data changing), and by `Erasure_Service`. Split out
	 * of the coarser `activity_gen` `reference_count()` above still reads —
	 * see `Generations::catalog_key()`'s own docblock for why.
	 *
	 * @param int $page     1-based page number.
	 * @param int $per_page Page size.
	 * @return array<int,array<string,mixed>> Hydrated rows, ordered by name.
	 */
	public function get_referenced_games( $page, $per_page ) {
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, absint( $per_page ) );

		// MR-1: defence in depth. The template/REST callers already 404 an
		// out-of-range page before calling this method, but a caller that
		// skips that check (a future template, a direct call) must not still
		// run the correlated-EXISTS query and write an hour-long dead cache
		// entry for every one of an unbounded number of out-of-range pages.
		// Only checked for page 2+: page 1 is always legitimate (including a
		// genuinely empty catalog), and this avoids a second cached read on
		// the common case.
		$offset = ( $page - 1 ) * $per_page;

		if ( $offset > 0 && $offset >= $this->count_referenced_games() ) {
			return array();
		}

		$gen       = $this->read_catalog_generation();
		$cache_key = sprintf( 'catalog_%d_%d_%d', $page, $per_page, $gen );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		// PB-6: single-flight regeneration lock — see under_regen_lock()'s
		// own docblock. A caller that loses the race serves the previous
		// generation's cached page if it is still around, else an empty
		// result; it never queues on the database behind the winner.
		return $this->under_regen_lock(
			'gl_regen_' . $cache_key,
			function () use ( $page, $per_page, $cache_key ) {
				global $wpdb;
				$games_table   = Schema::games_table();
				$entries_table = Schema::library_entries_table();
				$offset        = ( $page - 1 ) * $per_page;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $games_table/$entries_table are Schema constants, never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT g.* FROM {$games_table} g WHERE EXISTS ( SELECT 1 FROM {$entries_table} e WHERE e.igdb_id = g.igdb_id ) ORDER BY g.name ASC, g.igdb_id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $games_table/$entries_table are Schema constants, never user input.
						$per_page,
						$offset
					),
					ARRAY_A
				);

				$items = array();

				foreach ( (array) $rows as $row ) {
					$hydrated = $this->hydrate_row( $row );
					$items[]  = $hydrated;

					// Prime the per-id cache too, matching get_many()'s own
					// priming — a later get()/get_many() call for one of
					// these ids in the same request is then a cache hit.
					wp_cache_set( $this->game_cache_key( $hydrated['igdb_id'] ), $hydrated, self::CACHE_GROUP, HOUR_IN_SECONDS );
				}

				// HOUR_IN_SECONDS +/- 10% jitter (PB-4): the catalog listing
				// is one of the two heaviest reads on this shared
				// catalog_gen scope — see Library_Repository::jittered_ttl()'s
				// own docblock for the full reasoning, applied here
				// identically. Freshness still comes from the generation
				// counter, never this TTL.
				wp_cache_set( $cache_key, $items, self::CACHE_GROUP, HOUR_IN_SECONDS + wp_rand( -360, 360 ) ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- the expression is HOUR_IN_SECONDS +/- a bounded 360s jitter (PB-4), always >= 3240s; the sniff cannot statically evaluate wp_rand()'s runtime result.

				return $items;
			},
			function () use ( $page, $per_page, $gen ) {
				// MR-1: a miss here must return null, never array() — see
				// under_regen_lock()'s own docblock for why a fabricated
				// empty catalog is strictly worse than the duplicate query a
				// null triggers.
				$previous_key = sprintf( 'catalog_%d_%d_%d', $page, $per_page, $gen - 1 );
				$previous     = wp_cache_get( $previous_key, self::CACHE_GROUP );

				return false !== $previous ? $previous : null;
			}
		);
	}

	/**
	 * One page of `/games/{slug}/` slugs only — every cached game held by at
	 * least one member, ordered the same as `get_referenced_games()` (PB-2).
	 *
	 * `Sitemap_Provider::game_url_list()` (the only caller) consumes nothing
	 * but `slug`, but `get_referenced_games()` was written for the
	 * 24-per-page catalog listing: it `SELECT g.*`s every column (including
	 * `summary` `LONGTEXT`) and primes a per-row object-cache entry for each
	 * one, and the sitemap's own page size (`wp_sitemaps_get_max_urls()`,
	 * 2,000) is nearly two orders of magnitude larger than the catalog's own
	 * 24 — at typical IGDB summary sizes that is 1-4 MB serialized per page,
	 * past a single Memcached item's 1 MB limit, so the cache write silently
	 * fails and every sitemap request re-runs the full query. This method
	 * selects only `slug`, primes no per-row entry, and is cached under the
	 * same `catalog_gen`-scoped key shape as `get_referenced_games()` (PB-5,
	 * cycle-5).
	 *
	 * @param int $page     1-based page number.
	 * @param int $per_page Page size.
	 * @return string[] Game slugs.
	 */
	public function referenced_game_slugs( $page, $per_page ) {
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, absint( $per_page ) );

		// MR-2: same defence-in-depth ceiling get_referenced_games() already
		// carries — this method was written without it (cycle-2's PB-2 fix
		// rewired the sitemap's games subtype onto this method, unguarded),
		// which let an out-of-range sitemap page number reach the query below
		// and write an hour-long dead cache entry. Sitemap_Provider::
		// get_url_list() now clamps first, but a caller that skips the
		// provider must not be able to reintroduce this.
		$offset = ( $page - 1 ) * $per_page;

		if ( $offset > 0 && $offset >= $this->count_referenced_games() ) {
			return array();
		}

		$gen       = $this->read_catalog_generation();
		$cache_key = sprintf( 'catalog_slugs_%d_%d_%d', $page, $per_page, $gen );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		// PB-6: single-flight regeneration lock — see under_regen_lock()'s
		// and get_referenced_games()'s own comments on the identical shape.
		return $this->under_regen_lock(
			'gl_regen_' . $cache_key,
			function () use ( $page, $per_page, $cache_key ) {
				global $wpdb;
				$games_table   = Schema::games_table();
				$entries_table = Schema::library_entries_table();
				$offset        = ( $page - 1 ) * $per_page;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $games_table/$entries_table are Schema constants, never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
				$slugs = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT g.slug FROM {$games_table} g WHERE EXISTS ( SELECT 1 FROM {$entries_table} e WHERE e.igdb_id = g.igdb_id ) ORDER BY g.name ASC, g.igdb_id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $games_table/$entries_table are Schema constants, never user input.
						$per_page,
						$offset
					)
				);

				$slugs = array_map( 'strval', (array) $slugs );

				// HOUR_IN_SECONDS +/- 10% jitter (PB-4) — same reasoning as
				// get_referenced_games() above.
				wp_cache_set( $cache_key, $slugs, self::CACHE_GROUP, HOUR_IN_SECONDS + wp_rand( -360, 360 ) ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- the expression is HOUR_IN_SECONDS +/- a bounded 360s jitter (PB-4), always >= 3240s; the sniff cannot statically evaluate wp_rand()'s runtime result.

				return $slugs;
			},
			function () use ( $page, $per_page, $gen ) {
				// MR-1: null on a miss, not array() — see under_regen_lock().
				$previous_key = sprintf( 'catalog_slugs_%d_%d_%d', $page, $per_page, $gen - 1 );
				$previous     = wp_cache_get( $previous_key, self::CACHE_GROUP );

				return false !== $previous ? $previous : null;
			}
		);
	}

	/**
	 * Total number of cached games held by at least one member — the
	 * companion count `get_referenced_games()`'s pagination needs (Task 15).
	 *
	 * @return int
	 */
	public function count_referenced_games() {
		$gen       = $this->read_catalog_generation();
		$cache_key = sprintf( 'catalog_count_%d', $gen );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		// PB-6: single-flight regeneration lock — see under_regen_lock()'s
		// and get_referenced_games()'s own comments on the identical shape.
		// MR-1: a caller that loses the race serves the previous
		// generation's cached count if it is still around, else runs the
		// query itself (under_regen_lock() falls through to $regenerate()
		// on a null fallback) — never fabricates 0. A fabricated 0 turned
		// $total_pages = 1 on the pagination ceiling every caller of this
		// method builds from, hard-404ing every genuinely valid page 2+.
		return $this->under_regen_lock(
			'gl_regen_' . $cache_key,
			function () use ( $cache_key ) {
				global $wpdb;
				$games_table   = Schema::games_table();
				$entries_table = Schema::library_entries_table();

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $games_table/$entries_table are Schema constants, never user input; no variable to bind; cached via wp_cache_set() below.
				$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$games_table} g WHERE EXISTS ( SELECT 1 FROM {$entries_table} e WHERE e.igdb_id = g.igdb_id )" );

				// HOUR_IN_SECONDS +/- 10% jitter (PB-4) — see
				// get_referenced_games() above and Library_Repository::
				// jittered_ttl()'s docblock.
				wp_cache_set( $cache_key, $count, self::CACHE_GROUP, HOUR_IN_SECONDS + wp_rand( -360, 360 ) ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- the expression is HOUR_IN_SECONDS +/- a bounded 360s jitter (PB-4), always >= 3240s; the sniff cannot statically evaluate wp_rand()'s runtime result.

				return $count;
			},
			function () use ( $gen ) {
				// MR-1: null on a miss, not 0 — see under_regen_lock().
				$previous_key = sprintf( 'catalog_count_%d', $gen - 1 );
				$previous     = wp_cache_get( $previous_key, self::CACHE_GROUP );

				return false !== $previous ? (int) $previous : null;
			}
		);
	}

	/**
	 * Reads the shared `activity_gen` generation counter — the same counter
	 * `Library_Repository` bumps on every library add/remove. Delegates to
	 * `Generations::read()` for the read protocol (cache-miss default of
	 * generation 1), not just the key (arch-pre-2 architecture review,
	 * finding AR-3) — `Generations`'s own docblock owns the cold-start-seed
	 * reasoning this used to duplicate.
	 *
	 * @return int
	 */
	private function read_activity_generation() {
		return Generations::read( Generations::activity_key() );
	}

	/**
	 * Reads the `catalog_gen` generation counter (PB-5, cycle-5) — see
	 * `Generations::catalog_key()`'s own docblock for the full scope-split
	 * reasoning and this scope's exact bump sites.
	 *
	 * @return int
	 */
	private function read_catalog_generation() {
		return Generations::read( Generations::catalog_key() );
	}

	/**
	 * Runs `$regenerate` (a real query + `wp_cache_set()`) under a
	 * single-flight lock keyed by `$lock_key`, preferring `$fallback` (never
	 * issuing the query) for every concurrent caller that loses the race
	 * (PB-6, cycle-5).
	 *
	 * The three catalog reads this backs (`get_referenced_games()`,
	 * `referenced_game_slugs()`, `count_referenced_games()`) go straight
	 * from a cache miss to a full correlated-EXISTS query with no lock.
	 * `catalog_gen` (PB-5) changes the cache key for every one of these
	 * reads at the same instant a write bumps it — TTL jitter staggers only
	 * natural expiry, never a generation bump — so a popular catalog page
	 * can send every concurrent viewer straight at the database the moment
	 * the generation changes.
	 *
	 * MR-1 (cycle-7): `$fallback` MUST return `null`, never a fabricated
	 * empty value, when it has nothing usable to serve — this method treats
	 * a `null` fallback result as "fall through and run `$regenerate()`
	 * directly," never as the real answer. The three fallback closures this
	 * backs read the *previous* generation's cache entry and can legitimately
	 * miss (a cold seed, an evicted key, a first-ever request for this
	 * scope); before this fix they returned `array()`/`0` on that miss,
	 * which every caller then treated as "the catalog is empty" —
	 * `game-catalog.php` rendered "No games in the catalog yet." at HTTP 200
	 * and hard-404'd a genuinely valid `/games/page/2/`, and
	 * `Sitemap_Provider` emitted an empty `<urlset>`. Once a lock is
	 * contended and there is nothing to fall back to, a duplicate query is
	 * strictly cheaper than a 30-minute edge-cached wrong answer, so
	 * `$regenerate()` may now run more than once concurrently — it always
	 * did, in fact, on the ruled non-persistent-cache target (MR-2), where
	 * this whole lock is a no-op.
	 *
	 * Human ruling 4 (`interrupts/review:conflict-pending-resolution.md`)
	 * names `wp_cache_add()` as the lock mechanism for this class of
	 * problem, but ruling 1 fixes this project's deployment target
	 * (`portable`) as core's stock, non-persistent object cache — on that
	 * substrate `WP_Object_Cache::add()` only ever checks the *current
	 * request's* empty in-process array, so every concurrent request would
	 * "win" a `wp_cache_add()` lock and none would actually be excluded;
	 * implementing ruling 4 as literally written would ship an inert
	 * primitive (the exact defect ruling 1 already named in `Activator::
	 * maybe_upgrade()`, PB-8). `Data\Option_Lock::acquire()` is used instead
	 * (MR-2, cycle-8) — the same raw-`INSERT IGNORE`-plus-`rows_affected`
	 * primitive `Activator::acquire_upgrade_lock()` (MR-3, cycle-7) proved
	 * first, now shared from one class rather than carried as two
	 * independently-drifting copies; see that class's own docblock for why
	 * `add_option()` cannot be trusted as a check-then-act lock. Same
	 * staleness-reclaim shape as `Activator::maybe_upgrade()`'s own lock
	 * (PB-8), scaled to a 30-second window rather than 5 minutes — a stuck
	 * request here blocks a page render, not a deploy, so it needs to
	 * self-heal much faster.
	 *
	 * MR-2 (cycle-7, human ruling CONF-1(a)): this entire lock is
	 * short-circuited to a direct `$regenerate()` call when no persistent
	 * object cache is present — see the `! wp_using_ext_object_cache()`
	 * guard below and `principal/adr/004-generation-counter-cache-invalidation.md`
	 * for why. It stays correct (and re-engages automatically) the moment a
	 * persistent cache is installed, where `catalog_gen` — and therefore
	 * `$lock_key` — is stable across requests.
	 *
	 * @param string   $lock_key   Unique lock name for this exact query
	 *                             shape (folds in every argument the
	 *                             caller's own cache key does).
	 * @param callable $regenerate Runs the real query, writes the cache, and
	 *                             returns the result. May be called more
	 *                             than once under contention — see above.
	 * @param callable $fallback   Runs instead — no query, no lock wait —
	 *                             for every caller that does not win the
	 *                             lock. MUST return `null`, never a
	 *                             fabricated empty value, when nothing
	 *                             usable is cached.
	 * @return mixed Whatever `$regenerate`/`$fallback` returns.
	 */
	private function under_regen_lock( $lock_key, callable $regenerate, callable $fallback ) {
		// MR-2 (cycle-7): no-op on the ruled portable (uncached) target —
		// see this method's own docblock. catalog_gen re-seeds on every
		// request when nothing persists it, which makes $lock_key unique
		// per request: get_option() always misses and
		// Option_Lock::acquire() always wins, so the "lock" would exclude
		// nobody while still paying for the extra wp_options queries and
		// writes. Running $regenerate() directly is strictly cheaper and
		// behaviourally identical.
		if ( ! wp_using_ext_object_cache() ) {
			return $regenerate();
		}

		$held = get_option( $lock_key );

		if ( false !== $held && ( time() - (int) $held ) < self::REGEN_LOCK_STALE_AFTER ) {
			$fallback_value = $fallback();

			return null !== $fallback_value ? $fallback_value : $regenerate();
		}

		if ( false !== $held ) {
			// Stale — the previous holder died mid-query and never
			// released it. Option_Lock::acquire()'s INSERT IGNORE cannot
			// overwrite an existing row, so it has to be cleared before
			// this request can try to re-acquire it.
			delete_option( $lock_key );
		}

		if ( ! Option_Lock::acquire( $lock_key ) ) {
			// Lost the race — prefer $fallback(), but never serve a
			// fabricated empty answer (MR-1): fall through to $regenerate()
			// when $fallback() has nothing usable.
			$fallback_value = $fallback();

			return null !== $fallback_value ? $fallback_value : $regenerate();
		}

		try {
			return $regenerate();
		} finally {
			delete_option( $lock_key );
		}
	}

	/**
	 * Normalises a raw `gl_games` row into typed, nullable PHP values.
	 *
	 * @param array<string,mixed> $row Raw row from `$wpdb->get_row()`/`get_results()`.
	 * @return array<string,mixed>
	 */
	private function hydrate_row( array $row ) {
		return array(
			'igdb_id'             => isset( $row['igdb_id'] ) ? (int) $row['igdb_id'] : 0,
			'slug'                => isset( $row['slug'] ) ? (string) $row['slug'] : '',
			'name'                => isset( $row['name'] ) ? (string) $row['name'] : '',
			'summary'             => isset( $row['summary'] ) ? (string) $row['summary'] : null,
			'first_release_date'  => isset( $row['first_release_date'] ) ? (int) $row['first_release_date'] : null,
			'cover_image_id'      => isset( $row['cover_image_id'] ) ? (string) $row['cover_image_id'] : null,
			'genres'              => $this->decode_json_list( isset( $row['genres'] ) ? $row['genres'] : null ),
			'platforms'           => $this->decode_json_list( isset( $row['platforms'] ) ? $row['platforms'] : null ),
			'aggregated_rating'   => isset( $row['aggregated_rating'] ) ? (float) $row['aggregated_rating'] : null,
			'igdb_url'            => isset( $row['igdb_url'] ) ? (string) $row['igdb_url'] : null,
			'date_cached'         => isset( $row['date_cached'] ) ? (string) $row['date_cached'] : '',
			'date_refreshed'      => isset( $row['date_refreshed'] ) ? (string) $row['date_refreshed'] : '',
		);
	}

	/**
	 * Decodes a `wp_json_encode()`-produced JSON array column back to a PHP
	 * list, defaulting to `[]` for null, empty, or malformed input.
	 *
	 * @param string|null $value Raw column value.
	 * @return string[]
	 */
	private function decode_json_list( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? array_values( $decoded ) : array();
	}

	/**
	 * Splits a `column => [ value, format ]` map into the column list,
	 * placeholder list, and prepare()-ready value list an `INSERT` needs,
	 * substituting the literal keyword `NULL` for every null value — the
	 * `%d`/`%s`/`%f` placeholders `$wpdb->prepare()` supports have no way to
	 * bind a real SQL `NULL`.
	 *
	 * Every column name is a hardcoded literal defined by the caller, never
	 * user input, so interpolating them (and the literal `NULL` keyword)
	 * into the SQL string alongside prepare()'s placeholders is safe.
	 *
	 * @param array<string,array{0:mixed,1:string}> $fields Column => [ value, printf format ].
	 * @return array{0:string,1:string,2:array<int,mixed>} Columns SQL, placeholders SQL, prepare() values.
	 */
	private function build_insert_clause( array $fields ) {
		$columns      = array();
		$placeholders = array();
		$values       = array();

		foreach ( $fields as $column => $spec ) {
			list( $value, $format ) = $spec;

			$columns[] = $column;

			if ( null === $value ) {
				$placeholders[] = 'NULL';
			} else {
				$placeholders[] = $format;
				$values[]       = $value;
			}
		}

		return array( implode( ', ', $columns ), implode( ', ', $placeholders ), $values );
	}

	/**
	 * The object-cache key for one game row.
	 *
	 * @param int $igdb_id IGDB game id.
	 * @return string
	 */
	private function game_cache_key( $igdb_id ) {
		return 'game_' . absint( $igdb_id );
	}

	/**
	 * The object-cache key for one slug->id mapping.
	 *
	 * @param string $slug Game slug.
	 * @return string
	 */
	private function game_slug_cache_key( $slug ) {
		return 'game_slug_' . $slug;
	}
}
