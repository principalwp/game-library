<?php
/**
 * The shared game store: `gamelib_games` and the Steam appid map.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The only class that reads or writes `gamelib_games` and `gamelib_steam_map`.
 *
 * `gamelib_games` is the plugin's canonical game record (DD-003). IGDB is the
 * sole authority for its contents, but IGDB is never the *source of a render*:
 * every surface — game pages, library cards, feed items, import review — reads
 * the local row and nothing else, so no page can make an outbound request while
 * rendering (AC-033a/c, Never Do #6).
 *
 * Freshness is therefore a background concern, not a read-time one. A row past
 * `GAMELIB_IGDB_CACHE_TTL` is *stale*, which means "due for revalidation by
 * cron or an admin refresh" — never "unusable". Staleness is asked about in
 * exactly one place, {@see get_stale_ids()}, and only by the hourly job: no
 * reader may hide, skip, or delete a row because of the answer (AC-033b), and
 * no foreground write may block on revalidating one (PB-5, D-REQ-15). Nothing
 * in this class deletes a game row at all.
 *
 * Two rules shape every write:
 *
 * 1. **Select, then insert or update.** `ON DUPLICATE KEY UPDATE` is MySQL-only
 *    and the E2E suite runs on Playground's SQLite driver (Never Do #9), so an
 *    upsert is an explicit lookup followed by the matching statement.
 * 2. **Every write bumps the `games` generation.** Reads are cached under a
 *    generation-scoped key, so one `wp_cache_incr()` invalidates the whole
 *    scope and the next read of any row sees the new state (AC-NFR-010a/b).
 *
 * The `post_id` column is owned here as well, but only as a *slot*: the CPT
 * projection (ADR-003) claims it, fills it, and releases it through
 * {@see claim_post_slot()} / {@see attach_post()} / {@see release_post_claim()},
 * so the conditional-UPDATE claim that guarantees one post per IGDB id lives in
 * the class that owns the table rather than being re-derived by its callers.
 */
final class GameLib_Game_Store {

	/**
	 * Schema suffix of the shared game table.
	 *
	 * @var string
	 */
	const TABLE = 'games';

	/**
	 * Schema suffix of the Steam appid → IGDB id map.
	 *
	 * @var string
	 */
	const STEAM_TABLE = 'steam_map';

	/**
	 * Columns of `gamelib_games`, in schema order — the select list every read
	 * in this class uses, and the shape {@see hydrate()} promises to return.
	 *
	 * @var string
	 */
	const COLUMNS = 'igdb_id, name, slug, summary, first_release_date, cover_image_id, platforms, genres, total_rating, total_rating_count, igdb_url, post_id, updated_at';

	/**
	 * IGDB's image CDN root (R-REQ-6).
	 *
	 * `cover.image_id` is a token, not a URL, and the API's own `url` field
	 * returns the `t_thumb` size only — a cover has to be composed from the
	 * token and the wanted size.
	 *
	 * @var string
	 */
	const IMAGE_BASE = self::IMAGE_ORIGIN . '/igdb/image/upload';

	/**
	 * Origin of {@see IMAGE_BASE}, on its own.
	 *
	 * A `<link rel="preconnect">` target is an origin, never a path — the
	 * separate constant is what keeps the two from drifting (PF-3).
	 *
	 * @var string
	 */
	const IMAGE_ORIGIN = 'https://images.igdb.com';

	/**
	 * File extension every composed cover URL carries.
	 *
	 * The CDN transcodes on the extension, so this is a real choice and it was
	 * measured against `co1r76` rather than assumed (bytes, all three renditions
	 * the plugin asks for):
	 *
	 * | size        | .jpg  | .png  | .webp |
	 * |-------------|-------|-------|-------|
	 * | thumb       |  3.9K |  8.5K | 24.7K |
	 * | cover_small |  4.6K | 10.4K | 25.3K |
	 * | cover_big   | 27.6K | 68.9K | 45.9K |
	 *
	 * WebP is honoured (HTTP 200, `content-type: image/webp`) but loses at every
	 * size here: IGDB's PNG renditions are 8-bit palettised, and the CDN encodes
	 * WebP losslessly, so it inflates the small sizes it was supposed to shrink.
	 * JPEG is the smallest everywhere and is what IGDB serves on its own site;
	 * covers are opaque artwork, so there is no alpha channel to lose.
	 *
	 * @var string
	 */
	const IMAGE_FORMAT = 'jpg';

	/**
	 * Default cover size: the one AC-032(b) names for the game page hero and for
	 * the OG/JSON-LD image, which want the largest rendition.
	 *
	 * @var string
	 */
	const COVER_SIZE = 'cover_big';

	/**
	 * Cover size for the grid surfaces — the `/my-library/` and profile cards.
	 *
	 * The grid track is `minmax(11rem, 1fr)` and the image fills it, so a card
	 * cover is never painted narrower than 176 CSS px. `cover_small` (90×120)
	 * was therefore a ~1.96× upscale on the two surfaces AC-014a and AC-031c are
	 * written about, and `cover_big` (264×352) is 28.3KB — 662KB for a 24-card
	 * page, which is the weight cycle-1's PF-1 fix existed to remove (CO-1).
	 *
	 * `cover_small_2x` is the rendition between them: 180×240 at 14.6KB,
	 * measured against the CDN, which covers the painted box at 1× with no
	 * upscale. {@see CARD_COVER_SRCSET} offers `cover_big` beside it so a HiDPI
	 * screen can still ask for the larger one.
	 *
	 * @var string
	 */
	const CARD_COVER_SIZE = 'cover_small_2x';

	/**
	 * Renditions a grid card offers, smallest first (CO-1).
	 *
	 * Composed into a `srcset` by {@see card_cover_srcset()} with each
	 * rendition's intrinsic width as its descriptor, so the browser — which is
	 * the only party that knows the device pixel ratio and the track's final
	 * width — makes the choice.
	 *
	 * @var string[]
	 */
	const CARD_COVER_SRCSET = array( 'cover_small_2x', 'cover_big' );

	/**
	 * Sizes {@see cover_url()} will compose. The size becomes a path segment on
	 * a third-party CDN, so it comes from this list or not at all.
	 *
	 * @var string[]
	 */
	const COVER_SIZES = array( 'thumb', 'cover_small', 'cover_small_2x', 'cover_big', 'screenshot_med', '720p', '1080p' );

	/**
	 * Intrinsic pixel dimensions of the sizes the plugin renders, so an `<img>`
	 * can carry `width`/`height` and reserve its own box without waiting for the
	 * stylesheet that sets `aspect-ratio`.
	 *
	 * Measured against the CDN, not taken from IGDB's documentation — the served
	 * `cover_big` is 264×352, not the documented 264×374, and `thumb` is a 90×90
	 * square crop rather than a 3/4 cover. Transfer sizes for `co1r76.jpg`, same
	 * measurement: `cover_small` 4.6KB, `cover_small_2x` 14.3KB, `cover_big`
	 * 27.6KB.
	 *
	 * @var array<string, int[]>
	 */
	const COVER_DIMENSIONS = array(
		'thumb'          => array( 90, 90 ),
		'cover_small'    => array( 90, 120 ),
		'cover_small_2x' => array( 180, 240 ),
		'cover_big'      => array( 264, 352 ),
	);

	/**
	 * Sentinel written into `post_id` while a CPT post is being created
	 * (ADR-003). The column is signed precisely to hold it (ADR-006); readers
	 * treat anything not greater than zero as "no usable post".
	 *
	 * @var int
	 */
	const POST_CLAIM = -1;

	/**
	 * Cached marker for an IGDB id the store has no row for.
	 *
	 * A negative entry has to survive a cache round trip as *something other
	 * than* `null` or `false` (PB-5): `GameLib_Cache::get_many()` can only tell a
	 * miss from a hit by `false !== $value`, and while core's in-process cache
	 * preserves a stored `null`, a persistent drop-in may normalise it — at which
	 * point every deliberately primed negative entry becomes a permanent miss and
	 * the not-found ids are re-queried on every batch, silently, since only the
	 * query count moves.
	 *
	 * Readers translate it back to `null`, which is the value the store's own
	 * contract uses for "no such game".
	 *
	 * @var string
	 */
	const NO_ROW = 'no-row';

	/**
	 * Cached marker for a Steam appid with no `gamelib_steam_map` row at all.
	 *
	 * The map has three states and all three must survive a cache round trip: a
	 * positive int (matched), `null` (the negative row — known unmatchable), and
	 * this (never looked up, so the cascade has work to do).
	 *
	 * It is a string rather than `false` because `wp_cache_get_multiple()`
	 * returns `false` for a miss, which would make a cached `false` and an
	 * absent entry indistinguishable (PB-2).
	 *
	 * @var string
	 */
	const STEAM_NO_ROW = 'no-row';

	/**
	 * Largest id batch any single statement here handles — the same 500 that
	 * caps an IGDB request (AC-036a/b), so a stale batch maps one-to-one onto
	 * one upstream call.
	 *
	 * @var int
	 */
	const MAX_BATCH = 500;

	/**
	 * One game row by IGDB id.
	 *
	 * Cached under the `games` generation, misses included: a game page for an
	 * id nobody has ever added must not re-query on every request. Nothing bumps
	 * `SCOPE_GAMES` (CO-2/PB-2) — a negative entry is not invalidated, it is
	 * *overwritten*: {@see upsert_from_igdb()} re-primes the id it just wrote
	 * through {@see reprime()}, and {@see get_many()} primes every id it reads
	 * through {@see prime()}. The cached marker is {@see NO_ROW} (PB-5), which
	 * this method translates back to `null`.
	 *
	 * @param int $igdb_id IGDB game id.
	 * @return array|null Hydrated row (see {@see hydrate()}), or null when the
	 *                    store has no record of that id.
	 */
	public static function get( $igdb_id ) {
		$igdb_id = self::valid_id( $igdb_id );

		if ( $igdb_id < 1 ) {
			return null;
		}

		$found  = false;
		$cached = GameLib_Cache::get( GameLib_Cache::SCOPE_GAMES, self::row_key( $igdb_id ), $found );

		if ( $found ) {
			return is_array( $cached ) ? $cached : null;
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; the result is cached below through GameLib_Cache.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is GameLib_Schema::table() and COLUMNS is a class constant; the one value is a bound placeholder.
				'SELECT ' . self::COLUMNS . " FROM {$table} WHERE igdb_id = %d",
				$igdb_id
			),
			ARRAY_A
		);

		$game = is_array( $row ) ? self::hydrate( $row ) : null;

		self::prime( $game, $igdb_id );

		return $game;
	}

	/**
	 * Many game rows in one batch (PB-2).
	 *
	 * Five loops in this plugin used to call {@see get()} once per id — the feed
	 * renderer, the projection title sync right after a 500-id refresh
	 * invalidated the scope, the admin list column, and two importer chunk
	 * loops. Each of those single reads is two object-cache operations (the
	 * scope's generation, then the entry), which on VIP are network hops.
	 *
	 * One multi-get, one chunked `WHERE igdb_id IN (…)` for the misses, and one
	 * multi-set that primes **every** miss — the rows that exist and the ids
	 * that do not — so a later single `get()` for any of them is free.
	 *
	 * @param int[] $igdb_ids IGDB game ids.
	 * @return array<int, array|null> Hydrated rows keyed by IGDB id, `null`
	 *                                where the store has no record. Ids that
	 *                                were not positive integers are absent.
	 */
	public static function get_many( array $igdb_ids ) {
		$igdb_ids = self::positive_ints( $igdb_ids );

		if ( empty( $igdb_ids ) ) {
			return array();
		}

		$wanted = array();

		foreach ( $igdb_ids as $igdb_id ) {
			$wanted[ $igdb_id ] = self::row_key( $igdb_id );
		}

		$cached = GameLib_Cache::get_many( GameLib_Cache::SCOPE_GAMES, $wanted );

		$games  = array();
		$misses = array();

		foreach ( $igdb_ids as $igdb_id ) {
			if ( array_key_exists( $igdb_id, $cached ) ) {
				// The primed negative entry is NO_ROW (PB-5), never a literal
				// null; anything that is not a row reads back as "no such game".
				$games[ $igdb_id ] = is_array( $cached[ $igdb_id ] ) ? $cached[ $igdb_id ] : null;

				continue;
			}

			$games[ $igdb_id ] = null;
			$misses[]          = $igdb_id;
		}

		if ( empty( $misses ) ) {
			return $games;
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		foreach ( array_chunk( $misses, self::MAX_BATCH ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %d literals bound by prepare() below; $table is GameLib_Schema::table() and COLUMNS is a class constant. Every row read here is primed into the cache below.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- See above.
					'SELECT ' . self::COLUMNS . " FROM {$table} WHERE igdb_id IN ({$placeholders})",
					$chunk
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$game = self::hydrate( $row );

				if ( $game['igdb_id'] > 0 ) {
					$games[ $game['igdb_id'] ] = $game;
				}
			}
		}

		$prime = array();

		foreach ( $misses as $igdb_id ) {
			$game = $games[ $igdb_id ];

			// NO_ROW, never a literal null: see the constant (PB-5).
			$prime[ self::row_key( $igdb_id ) ] = is_array( $game ) ? $game : self::NO_ROW;

			if ( is_array( $game ) && '' !== $game['slug'] ) {
				$prime[ self::slug_key( $game['slug'] ) ] = $game['igdb_id'];
			}
		}

		GameLib_Cache::set_many( GameLib_Cache::SCOPE_GAMES, $prime );

		return $games;
	}

	/**
	 * One game row by its IGDB slug — the lookup a `/games/{igdb-slug}/` page
	 * resolves with.
	 *
	 * The slug column is UNIQUE, so this is a single-row read; both the slug
	 * entry and the id entry are primed from it, and a later `get()` for the
	 * same game costs nothing.
	 *
	 * @param string $slug IGDB slug.
	 * @return array|null Hydrated row, or null when no game carries that slug.
	 */
	public static function get_by_slug( $slug ) {
		$slug = sanitize_title( (string) $slug );

		if ( '' === $slug ) {
			return null;
		}

		$found  = false;
		$cached = GameLib_Cache::get( GameLib_Cache::SCOPE_GAMES, self::slug_key( $slug ), $found );

		if ( $found ) {
			// The slug entry holds an id, not a row: one copy of a row in the
			// cache, reachable by either key.
			return is_numeric( $cached ) ? self::get( (int) $cached ) : null;
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; the result is cached below through GameLib_Cache.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is GameLib_Schema::table() and COLUMNS is a class constant; the one value is a bound placeholder.
				'SELECT ' . self::COLUMNS . " FROM {$table} WHERE slug = %s",
				$slug
			),
			ARRAY_A
		);

		$game = is_array( $row ) ? self::hydrate( $row ) : null;

		self::prime( $game, null === $game ? 0 : $game['igdb_id'], $slug );

		return $game;
	}

	/**
	 * Ids of the rows most overdue for revalidation (AC-036a).
	 *
	 * Deliberately uncached and computed fresh inside the caller's tick: the
	 * whole point of the query is to observe the state of the table *now*, and
	 * a cached list would hand the refresh job ids it already refreshed —
	 * exactly the "never cached or precomputed" the AC calls for. It runs once
	 * an hour, from cron, and is not a repeated read.
	 *
	 * @param int $limit Maximum ids to return; clamped to {@see MAX_BATCH}.
	 * @return int[] IGDB ids, oldest `updated_at` first.
	 */
	public static function get_stale_ids( $limit = self::MAX_BATCH ) {
		$limit = absint( $limit );
		$limit = max( 1, min( self::MAX_BATCH, $limit ) );

		global $wpdb;

		$table  = GameLib_Schema::table( self::TABLE );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - GAMELIB_IGDB_CACHE_TTL );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- AC-036(a): the stale-row query is never cached or precomputed.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); both values are bound placeholders.
				"SELECT igdb_id FROM {$table} WHERE updated_at < %s ORDER BY updated_at ASC LIMIT %d",
				$cutoff,
				$limit
			)
		);

		return self::positive_ints( is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Write one IGDB record into the store, inserting or updating as needed.
	 *
	 * The record is the raw shape IGDB returns — sparse, because the API omits
	 * null fields rather than nulling them (C-REQ-16) — and every column is
	 * sanitized here, on the way in, per §6's Data Model table. A record
	 * without a positive id or a usable name is refused outright: a local game
	 * record without an IGDB id is the one thing this table may never hold
	 * (Never Do #10).
	 *
	 * `post_id` is never touched. The CPT projection owns that column, and an
	 * hourly refresh must not disturb a claim in flight or unlink a live post.
	 *
	 * @param array $record IGDB game record (`id`, `name`, `slug`, `summary`,
	 *                      `first_release_date`, `cover.image_id`,
	 *                      `platforms[].name`, `genres[].name`, `total_rating`,
	 *                      `total_rating_count`, `url`).
	 * @return int The IGDB id written, or 0 when the record was unusable or the
	 *             write failed.
	 */
	public static function upsert_from_igdb( array $record ) {
		$data = self::map_record( $record );

		if ( null === $data ) {
			return 0;
		}

		global $wpdb;

		$table   = GameLib_Schema::table( self::TABLE );
		$igdb_id = $data['igdb_id'];

		/*
		 * `post_id` rides along on the existence lookup (PB-3). The column is the
		 * one thing an upsert never writes but {@see reprime()} has to carry
		 * forward, and resolving it afterwards costs a cache read plus — on a
		 * miss, which is the normal case for the hourly job's stale rows and for
		 * an importer chunk's already-known games — a second single-row SELECT.
		 * Reading it here is free: the statement runs either way.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write-path existence lookup: the "select" half of the select-then-insert/update upsert (Never Do #9); caching a value the next statement invalidates would be wrong.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); the one value is a bound placeholder.
				"SELECT igdb_id, slug, post_id FROM {$table} WHERE igdb_id = %d",
				$igdb_id
			),
			ARRAY_A
		);

		$current_slug = is_array( $existing ) ? (string) $existing['slug'] : '';
		$data['slug'] = self::available_slug( $data['slug'], $igdb_id, $current_slug );

		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$formats = self::column_formats( $data );

		// True only for a row this statement created, where `post_id` is
		// definitionally absent and needs no lookup (PB-2).
		$inserted = false;

		if ( is_array( $existing ) ) {
			$values = $data;
			unset( $values['igdb_id'] );
			unset( $formats['igdb_id'] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->update() prepares its own statement, and the cached read of this row is re-primed below.
			$written = $wpdb->update( $table, $values, array( 'igdb_id' => $igdb_id ), array_values( $formats ), array( '%d' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->insert() prepares its own statement, and the cached read of this row is re-primed below.
			$written = $wpdb->insert( $table, $data, array_values( $formats ) );

			$inserted = ( false !== $written );

			if ( false === $written ) {
				/*
				 * Two requests can reach the insert for the same new game at
				 * once — a member adding it while an import hydrates it. The
				 * PRIMARY KEY refuses the second, and the right answer is the
				 * update the loser would have run had it looked a moment later.
				 */
				$values = $data;
				unset( $values['igdb_id'] );
				$update_formats = $formats;
				unset( $update_formats['igdb_id'] );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->update() prepares its own statement, and the cached read of this row is re-primed below.
				$written = $wpdb->update( $table, $values, array( 'igdb_id' => $igdb_id ), array_values( $update_formats ), array( '%d' ) );
			}
		}

		if ( false === $written ) {
			return 0;
		}

		/*
		 * Re-prime this one row rather than bumping the site-wide `games`
		 * generation (PB-1). That generation is folded into every game key,
		 * every slug pointer and every member's cached library page, so bumping
		 * it here made one import row discard the whole site's game cache — and
		 * the hourly job issued up to 501 bumps a tick.
		 *
		 * The freshly written values are already in hand, so the re-prime costs
		 * no read: `$data` is exactly what was stored, and `post_id` (the one
		 * column this method never touches) is carried over from the row it is
		 * replacing.
		 */
		if ( '' !== $current_slug && $current_slug !== $data['slug'] ) {
			// The old pointer now names a slug this row no longer carries, and
			// no other row can claim it while the pointer lives.
			GameLib_Cache::delete( GameLib_Cache::SCOPE_GAMES, self::slug_key( $current_slug ) );
		}

		if ( is_array( $existing ) ) {
			// The UPDATE branch: the column was read a few lines above, and this
			// method never writes it, so the value is still current (PB-3).
			$known_post_id = (int) $existing['post_id'];
		} elseif ( $inserted ) {
			// A row this statement created has no post yet, by construction.
			$known_post_id = 0;
		} else {
			// The insert lost a race and became an update: another request owns
			// the row, so its `post_id` has to be resolved rather than assumed.
			$known_post_id = null;
		}

		self::reprime( $igdb_id, $data, $known_post_id );

		return $igdb_id;
	}

	/**
	 * Re-prime one row's cache entries from the values just written (PB-1).
	 *
	 * `post_id` is not part of an upsert, so it is read back from whatever copy
	 * of the row the cache already holds; when there is none the row is re-read
	 * once, which is still one query against one row rather than a site-wide
	 * invalidation.
	 *
	 * A caller that already knows the column's value passes it and skips both
	 * steps — a row this request just INSERTed has no `post_id` by construction,
	 * and an UPDATE read the column on its way in (PB-3). Looking one up cost the
	 * hourly tick up to 500 single-row queries the pre-PB-1 code never issued
	 * (PB-2); the null path below is the safety net for a caller with no row in
	 * hand, not the common case.
	 *
	 * @param int      $igdb_id IGDB game id.
	 * @param array    $data    Column values as written by {@see map_record()},
	 *                          plus `slug` and `updated_at`.
	 * @param int|null $post_id Optional. Known `post_id`; null resolves it.
	 * @return void
	 */
	private static function reprime( $igdb_id, array $data, $post_id = null ) {
		if ( null === $post_id ) {
			$found    = false;
			$previous = GameLib_Cache::get( GameLib_Cache::SCOPE_GAMES, self::row_key( $igdb_id ), $found );

			if ( $found && is_array( $previous ) ) {
				$post_id = isset( $previous['post_id'] ) ? (int) $previous['post_id'] : 0;
			} else {
				$post_id = self::stored_post_id( $igdb_id );
			}
		}

		$row            = $data;
		$row['post_id'] = $post_id;

		self::prime( self::hydrate( $row ), $igdb_id );
	}

	/**
	 * Read `post_id` straight from the table, bypassing the cache.
	 *
	 * @param int $igdb_id IGDB game id.
	 * @return int Stored post id, the claim sentinel, or 0.
	 */
	private static function stored_post_id( $igdb_id ) {
		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-column read taken *because* the cached copy is being rebuilt; caching it here would be circular.
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); the one value is a bound placeholder.
				"SELECT post_id FROM {$table} WHERE igdb_id = %d",
				$igdb_id
			)
		);

		return ( null === $post_id ) ? 0 : (int) $post_id;
	}

	/**
	 * Rewrite one field of a cached row without re-reading it (PB-1).
	 *
	 * The three `post_id` writers change a single column of a single row, and
	 * the cached copy — if there is one — differs from the truth by exactly that
	 * column. A miss needs no work at all: the next read will fetch the row it
	 * just wrote.
	 *
	 * @param int   $igdb_id IGDB game id.
	 * @param array $changes Column → value.
	 * @return void
	 */
	private static function patch_cached_row( $igdb_id, array $changes ) {
		$found = false;
		$row   = GameLib_Cache::get( GameLib_Cache::SCOPE_GAMES, self::row_key( $igdb_id ), $found );

		if ( ! $found || ! is_array( $row ) ) {
			return;
		}

		foreach ( $changes as $column => $value ) {
			$row[ $column ] = $value;
		}

		GameLib_Cache::set( GameLib_Cache::SCOPE_GAMES, self::row_key( $igdb_id ), $row );
	}

	/**
	 * Re-fetch one game from IGDB and write it back to the store.
	 *
	 * The admin "Refresh from IGDB" row action and any single-row revalidation
	 * go through here; the hourly job batches its own by-id request instead,
	 * because AC-036(b) wants one request per 500 ids rather than 500 requests.
	 *
	 * A game IGDB no longer returns is left exactly as it is — data a delisted
	 * game already has beats no data (AC-036c). The freshness stamp is *not*
	 * bumped for that case here: bumping it is the batch job's decision, made
	 * for a whole processed batch through {@see touch()}.
	 *
	 * @param int   $igdb_id IGDB game id.
	 * @param array $args    Optional. `background` bool — true inside a cron
	 *                       tick (10s timeout), false on an admin action (3s).
	 * @return array|WP_Error The refreshed row, or the IGDB taxonomy error that
	 *                        stopped it (see {@see GameLib_IGDB_Client}).
	 */
	public static function refresh( $igdb_id, array $args = array() ) {
		$igdb_id = self::valid_id( $igdb_id );

		if ( $igdb_id < 1 ) {
			return new WP_Error(
				GameLib_IGDB_Client::ERROR_MALFORMED,
				__( 'That game has no valid IGDB id.', 'game-library' )
			);
		}

		$records = GameLib_IGDB_Client::get_games_by_ids( array( $igdb_id ), $args );

		if ( is_wp_error( $records ) ) {
			return $records;
		}

		foreach ( $records as $record ) {
			if ( is_array( $record ) && isset( $record['id'] ) && self::valid_id( $record['id'] ) === $igdb_id ) {
				if ( self::upsert_from_igdb( $record ) < 1 ) {
					return new WP_Error(
						GameLib_IGDB_Client::ERROR_MALFORMED,
						__( 'IGDB returned a record this site could not store.', 'game-library' )
					);
				}

				/*
				 * This is a deliberate act — the AC-035(f) admin row action, or
				 * an importer hydration — that rewrites a game's title and cover,
				 * so the pages rendering them have to see it (CO-2). One bump per
				 * act, and never the per-write bump that made a single import row
				 * discard the whole site's cached library pages.
				 */
				GameLib_Cache::bump( GameLib_Cache::SCOPE_GAME_CONTENT );

				$row = self::get( $igdb_id );

				if ( null !== $row ) {
					return $row;
				}
			}
		}

		return new WP_Error(
			GameLib_IGDB_Client::RESULT_EMPTY,
			__( 'IGDB returned no record for this game.', 'game-library' )
		);
	}

	/**
	 * Stamp rows as revalidated without changing their data (AC-036c).
	 *
	 * The refresh job calls this for every id in a processed batch, including
	 * the ids IGDB left out of its response: a delisted game keeps the data it
	 * has, and stamping it stops the same batch from being re-requested every
	 * hour forever.
	 *
	 * @param int[] $igdb_ids Ids to stamp.
	 * @return int Rows stamped.
	 */
	public static function touch( array $igdb_ids ) {
		$igdb_ids = self::positive_ints( $igdb_ids );

		if ( empty( $igdb_ids ) ) {
			return 0;
		}

		global $wpdb;

		$table   = GameLib_Schema::table( self::TABLE );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$stamped = 0;

		foreach ( array_chunk( $igdb_ids, self::MAX_BATCH ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %d literals bound by prepare() below; $table comes from GameLib_Schema::table(). A write is never cached.
			$affected = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
					"UPDATE {$table} SET updated_at = %s WHERE igdb_id IN ({$placeholders})",
					array_merge( array( $now ), $chunk )
				)
			);

			if ( is_int( $affected ) ) {
				$stamped += $affected;
			}
		}

		/*
		 * Only `updated_at` moved, and nothing rendered anywhere reads it except
		 * the admin list's "Last refreshed" column — so the cached copies are
		 * patched in place rather than the whole `games` scope being discarded
		 * (PB-1). One multi-get and one multi-set for the batch; ids with no
		 * cached copy need nothing, because their next read comes from the table.
		 */
		self::restamp_cached_rows( $igdb_ids, $now );

		return $stamped;
	}

	/**
	 * Carry a {@see touch()} stamp into whatever cached copies exist.
	 *
	 * @param int[]  $igdb_ids Ids that were stamped.
	 * @param string $now      The UTC `Y-m-d H:i:s` that was written.
	 * @return void
	 */
	private static function restamp_cached_rows( array $igdb_ids, $now ) {
		foreach ( array_chunk( $igdb_ids, self::MAX_BATCH ) as $chunk ) {
			$wanted = array();

			foreach ( $chunk as $igdb_id ) {
				$wanted[ $igdb_id ] = self::row_key( $igdb_id );
			}

			$cached  = GameLib_Cache::get_many( GameLib_Cache::SCOPE_GAMES, $wanted );
			$rewrite = array();

			foreach ( $cached as $igdb_id => $row ) {
				// A cached NO_ROW (PB-5) is "no such game": a stamp cannot
				// conjure one, so anything that is not a row is skipped.
				if ( ! is_array( $row ) ) {
					continue;
				}

				$row['updated_at']                  = $now;
				$rewrite[ self::row_key( $igdb_id ) ] = $row;
			}

			GameLib_Cache::set_many( GameLib_Cache::SCOPE_GAMES, $rewrite );
		}
	}

	/**
	 * Claim the `post_id` slot for CPT creation (AC-034a).
	 *
	 * The whole guarantee of "exactly one post per IGDB id, even under
	 * concurrent adds" rests on this one statement: the database decides the
	 * winner, because only the request whose conditional UPDATE matched a row
	 * with `post_id IS NULL` may go on to insert a post. It is not a lock built
	 * from a `wp_cache_get`/`wp_cache_set` pair (Never Do #15), which two
	 * requests can both pass.
	 *
	 * The loser does nothing and the game keeps the winner's post.
	 *
	 * @param int $igdb_id IGDB game id.
	 * @return bool True when this caller won the claim and must create the post.
	 */
	public static function claim_post_slot( $igdb_id ) {
		$igdb_id = self::valid_id( $igdb_id );

		if ( $igdb_id < 1 ) {
			return false;
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The atomic creation claim of ADR-003; a write is never cached.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); both values are bound placeholders.
				"UPDATE {$table} SET post_id = %d WHERE igdb_id = %d AND post_id IS NULL",
				self::POST_CLAIM,
				$igdb_id
			)
		);

		if ( 1 !== (int) $claimed ) {
			return false;
		}

		// One column of one row changed (PB-1).
		self::patch_cached_row( $igdb_id, array( 'post_id' => self::POST_CLAIM ) );

		return true;
	}

	/**
	 * Link a created post to its game row, completing the claim.
	 *
	 * @param int $igdb_id IGDB game id.
	 * @param int $post_id Published `glib_game` post id.
	 * @return bool True when the row now points at the post.
	 */
	public static function attach_post( $igdb_id, $post_id ) {
		$igdb_id = self::valid_id( $igdb_id );
		$post_id = self::valid_id( $post_id );

		if ( $igdb_id < 1 || $post_id < 1 ) {
			return false;
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->update() prepares its own statement, and the cached copy of this row is patched below.
		$written = $wpdb->update(
			$table,
			array( 'post_id' => $post_id ),
			array( 'igdb_id' => $igdb_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $written ) {
			return false;
		}

		// One column of one row changed (PB-1).
		self::patch_cached_row( $igdb_id, array( 'post_id' => $post_id ) );

		return true;
	}

	/**
	 * Hand back a claim whose post never got created.
	 *
	 * `wp_insert_post()` can fail, and a row left holding the sentinel would
	 * never be eligible for a post again — the next add's claim would find
	 * `post_id` non-NULL and stand down. Releasing restores the retry.
	 *
	 * Only the sentinel is released: a real post id is never nulled by this.
	 *
	 * @param int $igdb_id IGDB game id.
	 * @return bool True when a claim was released.
	 */
	public static function release_post_claim( $igdb_id ) {
		$igdb_id = self::valid_id( $igdb_id );

		if ( $igdb_id < 1 ) {
			return false;
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional release of the ADR-003 claim sentinel; a write is never cached.
		$released = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); both values are bound placeholders.
				"UPDATE {$table} SET post_id = NULL WHERE igdb_id = %d AND post_id = %d",
				$igdb_id,
				self::POST_CLAIM
			)
		);

		if ( 1 !== (int) $released ) {
			return false;
		}

		// One column of one row changed (PB-1). `hydrate()` maps the NULL this
		// wrote to 0, so that is what the cached copy carries.
		self::patch_cached_row( $igdb_id, array( 'post_id' => 0 ) );

		return true;
	}

	/**
	 * The live `glib_game` post id for a game, if it has one.
	 *
	 * "Live" excludes both NULL (nobody has added this game yet) and the
	 * in-flight claim sentinel, so a caller deciding whether a post must be
	 * created never has to know that -1 is a thing (ADR-006).
	 *
	 * @param int $igdb_id IGDB game id.
	 * @return int Post id, or 0 when the game has no post.
	 */
	public static function live_post_id( $igdb_id ) {
		$row = self::get( $igdb_id );

		if ( null === $row || $row['post_id'] < 1 ) {
			return 0;
		}

		return $row['post_id'];
	}

	/**
	 * Look up Steam appids in the persistent match map (AC-045).
	 *
	 * The map answers two different questions and the return shape keeps them
	 * apart, because a re-import must skip both kinds of already-known appid
	 * without spending an IGDB request on either:
	 *
	 * - key present, value `int`  — matched before; re-use this IGDB id;
	 * - key present, value `null` — the negative cache: known unmatchable;
	 * - key absent                — never looked up; the cascade has work to do.
	 *
	 * @param int[] $appids Steam application ids.
	 * @return array<int,int|null> Map of appid → IGDB id or null, containing
	 *                             only appids that have a row.
	 */
	public static function get_steam_matches( array $appids ) {
		$appids = self::positive_ints( $appids );

		if ( empty( $appids ) ) {
			return array();
		}

		$wanted = array();

		foreach ( $appids as $appid ) {
			$wanted[ $appid ] = self::steam_key( $appid );
		}

		// One multi-get for the whole chunk, not one round trip per appid — an
		// import resolves up to 500 at a time (PB-2).
		$cached = GameLib_Cache::get_many( GameLib_Cache::SCOPE_STEAM_MAP, $wanted );

		$matches = array();
		$misses  = array();

		foreach ( $appids as $appid ) {
			if ( ! array_key_exists( $appid, $cached ) ) {
				$misses[] = $appid;

				continue;
			}

			// STEAM_NO_ROW is the cached "no row at all"; null is a cached
			// negative row, which is a real answer the cascade must honour.
			if ( self::STEAM_NO_ROW !== $cached[ $appid ] ) {
				$matches[ $appid ] = ( null === $cached[ $appid ] ) ? null : (int) $cached[ $appid ];
			}
		}

		if ( empty( $misses ) ) {
			return $matches;
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::STEAM_TABLE );
		$rows  = array();

		foreach ( array_chunk( $misses, self::MAX_BATCH ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %d literals bound by prepare() below; every row read here is cached under the `games` generation immediately after.
			$found_rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
					"SELECT steam_appid, igdb_id FROM {$table} WHERE steam_appid IN ({$placeholders})",
					$chunk
				),
				ARRAY_A
			);

			if ( is_array( $found_rows ) ) {
				$rows = array_merge( $rows, $found_rows );
			}
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['steam_appid'] ) ) {
				continue;
			}

			$appid = (int) $row['steam_appid'];

			$matches[ $appid ] = ( null === $row['igdb_id'] || '' === $row['igdb_id'] )
				? null
				: (int) $row['igdb_id'];
		}

		$prime = array();

		foreach ( $misses as $appid ) {
			$prime[ self::steam_key( $appid ) ] = array_key_exists( $appid, $matches )
				? $matches[ $appid ]
				: self::STEAM_NO_ROW;
		}

		GameLib_Cache::set_many( GameLib_Cache::SCOPE_STEAM_MAP, $prime );

		return $matches;
	}

	/**
	 * Persist one appid → IGDB id resolution, positive or negative (AC-045).
	 *
	 * A null (or non-positive) `$igdb_id` writes the negative row that tells a
	 * later import "this appid matched nothing; do not run the cascade again".
	 * That row is the reason a second import of the same Steam library spends
	 * zero requests on appids the first one could not resolve.
	 *
	 * @param int      $appid   Steam application id.
	 * @param int|null $igdb_id Matched IGDB id, or null for the negative row.
	 * @return bool True on a successful write.
	 */
	public static function set_steam_match( $appid, $igdb_id = null ) {
		$appid = self::valid_id( $appid );

		if ( $appid < 1 ) {
			return false;
		}

		$matched = self::valid_id( $igdb_id );
		$matched = ( $matched > 0 ) ? $matched : null;

		global $wpdb;

		$table = GameLib_Schema::table( self::STEAM_TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write-path existence lookup for the select-then-insert/update upsert (Never Do #9).
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); the one value is a bound placeholder.
				"SELECT steam_appid FROM {$table} WHERE steam_appid = %d",
				$appid
			)
		);

		$data = array(
			'igdb_id'    => $matched,
			'matched_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( null === $exists ) {
			$data['steam_appid'] = $appid;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->insert() prepares its own statement, and the cached lookup is overwritten below (nothing bumps SCOPE_STEAM_MAP).
			$written = $wpdb->insert( $table, $data, array( '%d', '%s', '%d' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->update() prepares its own statement, and the cached lookup is re-primed below.
			$written = $wpdb->update( $table, $data, array( 'steam_appid' => $appid ), array( '%d', '%s' ), array( '%d' ) );
		}

		if ( false === $written ) {
			return false;
		}

		// The map has its own scope (PB-1) and this is the one entry that
		// changed, so the resolution is written straight over it.
		GameLib_Cache::set( GameLib_Cache::SCOPE_STEAM_MAP, self::steam_key( $appid ), $matched );

		return true;
	}

	/**
	 * Compose a cover URL from an IGDB image token (R-REQ-6).
	 *
	 * `cover.image_id` is a token — `co1r76`, not a URL — and IGDB's own `url`
	 * field only ever returns the `t_thumb` size, so every cover the plugin
	 * renders is built here. The token is checked against a conservative
	 * character class before it becomes a path segment on a third-party CDN,
	 * and the size comes from {@see COVER_SIZES}.
	 *
	 * The return value is a raw URL: callers escape it with `esc_url()` at
	 * render time, like every other output in the plugin (Always Do #1).
	 *
	 * @param string $image_id IGDB image token.
	 * @param string $size     Optional. One of {@see COVER_SIZES}. Default
	 *                         {@see COVER_SIZE} (`cover_big`, AC-032b).
	 * @return string Absolute image URL, or '' when there is no usable token.
	 */
	public static function cover_url( $image_id, $size = self::COVER_SIZE ) {
		$image_id = trim( (string) $image_id );

		if ( '' === $image_id || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $image_id ) ) {
			return '';
		}

		if ( ! in_array( $size, self::COVER_SIZES, true ) ) {
			$size = self::COVER_SIZE;
		}

		return self::IMAGE_BASE . '/t_' . $size . '/' . $image_id . '.' . self::IMAGE_FORMAT;
	}

	/**
	 * The `srcset` a grid card offers for one cover (CO-1).
	 *
	 * Width descriptors, not density ones: the painted box is a grid track whose
	 * final width the server does not know, so the browser is given each
	 * rendition's intrinsic width and picks against its own `sizes` calculation
	 * and device pixel ratio. `cover_small_2x` (180w, 14.6KB) covers the 176px
	 * track at 1× with no upscale; `cover_big` (264w, 27.6KB) is there for HiDPI.
	 *
	 * The return value is raw, like {@see cover_url()}'s: the template escapes it
	 * where it renders it.
	 *
	 * @param string $image_id IGDB image token.
	 * @return string `srcset` value, or '' when there is no usable token.
	 */
	public static function card_cover_srcset( $image_id ) {
		$candidates = array();

		foreach ( self::CARD_COVER_SRCSET as $size ) {
			$url        = self::cover_url( $image_id, $size );
			$dimensions = self::cover_dimensions( $size );

			if ( '' === $url || ! isset( $dimensions[0] ) ) {
				continue;
			}

			$candidates[] = $url . ' ' . (int) $dimensions[0] . 'w';
		}

		return implode( ', ', $candidates );
	}

	/**
	 * Intrinsic dimensions of a rendition, for an `<img>`'s width/height.
	 *
	 * Sizes outside {@see COVER_DIMENSIONS} return an empty array rather than a
	 * guess: a wrong `width`/`height` pair reserves the wrong box, which is a
	 * worse layout shift than reserving none.
	 *
	 * @param string $size Optional. One of {@see COVER_SIZES}. Default
	 *                     {@see COVER_SIZE}.
	 * @return array{0?:int,1?:int} `array( width, height )`, or an empty array.
	 */
	public static function cover_dimensions( $size = self::COVER_SIZE ) {
		return isset( self::COVER_DIMENSIONS[ $size ] ) ? self::COVER_DIMENSIONS[ $size ] : array();
	}

	/**
	 * Turn a raw database row into the shape the rest of the plugin consumes.
	 *
	 * `$wpdb` hands back every column as a string, and two of them are JSON.
	 * Normalizing once, here, is what lets a template write
	 * `$row['platforms']` and get an array — and what keeps "absent" legible:
	 * `total_rating` stays null when IGDB has no rating, because 0.0 is a real
	 * (terrible) score and AC-032(g) renders a rating only when present.
	 *
	 * @param array $row Raw row from `gamelib_games`.
	 * @return array{igdb_id:int,name:string,slug:string,summary:string,first_release_date:int,cover_image_id:string,platforms:string[],genres:string[],total_rating:float|null,total_rating_count:int,igdb_url:string,post_id:int,updated_at:string} Hydrated row.
	 */
	private static function hydrate( array $row ) {
		// A NULL column, an empty string, or anything non-numeric all mean "IGDB
		// has no rating for this game" — never the real score 0.
		$rating = isset( $row['total_rating'] ) && is_numeric( $row['total_rating'] )
			? (float) $row['total_rating']
			: null;

		return array(
			'igdb_id'            => isset( $row['igdb_id'] ) ? (int) $row['igdb_id'] : 0,
			'name'               => isset( $row['name'] ) ? (string) $row['name'] : '',
			'slug'               => isset( $row['slug'] ) ? (string) $row['slug'] : '',
			'summary'            => isset( $row['summary'] ) ? (string) $row['summary'] : '',
			'first_release_date' => isset( $row['first_release_date'] ) ? (int) $row['first_release_date'] : 0,
			'cover_image_id'     => isset( $row['cover_image_id'] ) ? (string) $row['cover_image_id'] : '',
			'platforms'          => self::decode_names( isset( $row['platforms'] ) ? $row['platforms'] : '' ),
			'genres'             => self::decode_names( isset( $row['genres'] ) ? $row['genres'] : '' ),
			'total_rating'       => $rating,
			'total_rating_count' => isset( $row['total_rating_count'] ) ? (int) $row['total_rating_count'] : 0,
			'igdb_url'           => isset( $row['igdb_url'] ) ? (string) $row['igdb_url'] : '',
			// Raw: 0 = no post, -1 = creation claim in flight, >0 = live post.
			'post_id'            => isset( $row['post_id'] ) ? (int) $row['post_id'] : 0,
			'updated_at'         => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '',
		);
	}

	/**
	 * Map an IGDB API record onto the table's columns, sanitizing each one.
	 *
	 * Absent fields are stored as NULL rather than as an empty string or a
	 * zero, so "IGDB has no release date for this game" and "this game came out
	 * at the Unix epoch" stay distinguishable at the column level.
	 *
	 * @param array $record Raw IGDB game record.
	 * @return array|null Column map ready for `$wpdb`, or null when the record
	 *                    cannot become a row (no id, or no name).
	 */
	private static function map_record( array $record ) {
		/*
		 * Positive-int validation rather than the absint() §6's Data Model
		 * prescribes: absint( -3 ) is 3, a real and different IGDB game, and
		 * Never Do #10 is about the id being honest as well as present. See
		 * principal/adr/009-store-write-path-id-validation.md (extending
		 * ADR-008 to this class's write path).
		 */
		$igdb_id = isset( $record['id'] ) ? self::valid_id( $record['id'] ) : 0;

		if ( $igdb_id < 1 ) {
			return null;
		}

		$name = isset( $record['name'] ) && is_scalar( $record['name'] )
			? sanitize_text_field( (string) $record['name'] )
			: '';

		if ( '' === $name ) {
			// A row with no title has nothing any surface could render.
			return null;
		}

		$slug = isset( $record['slug'] ) && is_scalar( $record['slug'] ) ? sanitize_title( (string) $record['slug'] ) : '';

		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}

		if ( '' === $slug ) {
			// A title of nothing but punctuation or CJK can sanitize away.
			$slug = 'game-' . $igdb_id;
		}

		$summary = isset( $record['summary'] ) && is_scalar( $record['summary'] )
			? sanitize_textarea_field( (string) $record['summary'] )
			: '';

		/*
		 * IGDB dates before 1970 are negative Unix timestamps and the column is
		 * unsigned; absint() would turn one into a date decades in the future,
		 * so an out-of-range date is stored as "unknown" instead.
		 */
		$released = isset( $record['first_release_date'] ) && is_scalar( $record['first_release_date'] )
			? (int) $record['first_release_date']
			: 0;

		$cover = '';

		if ( isset( $record['cover']['image_id'] ) && is_scalar( $record['cover']['image_id'] ) ) {
			$cover = sanitize_text_field( (string) $record['cover']['image_id'] );
		} elseif ( isset( $record['cover_image_id'] ) && is_scalar( $record['cover_image_id'] ) ) {
			$cover = sanitize_text_field( (string) $record['cover_image_id'] );
		}

		$platforms = self::encode_names( isset( $record['platforms'] ) ? $record['platforms'] : array() );
		$genres    = self::encode_names( isset( $record['genres'] ) ? $record['genres'] : array() );

		$rating = null;

		if ( isset( $record['total_rating'] ) && is_numeric( $record['total_rating'] ) ) {
			$rating = round( max( 0, min( 100, (float) $record['total_rating'] ) ), 2 );
		}

		$rating_count = isset( $record['total_rating_count'] ) && is_numeric( $record['total_rating_count'] )
			? absint( $record['total_rating_count'] )
			: null;

		$url = isset( $record['url'] ) && is_scalar( $record['url'] ) ? esc_url_raw( (string) $record['url'] ) : '';

		return array(
			'igdb_id'            => $igdb_id,
			'name'               => self::truncate( $name, 255 ),
			'slug'               => self::truncate( $slug, 200 ),
			'summary'            => ( '' === $summary ) ? null : $summary,
			'first_release_date' => ( $released > 0 ) ? $released : null,
			'cover_image_id'     => ( '' === $cover ) ? null : self::truncate( $cover, 64 ),
			'platforms'          => $platforms,
			'genres'             => $genres,
			'total_rating'       => $rating,
			'total_rating_count' => $rating_count,
			'igdb_url'           => ( '' === $url ) ? null : self::truncate( $url, 255 ),
		);
	}

	/**
	 * `$wpdb` format specifiers for a column map, keyed by column.
	 *
	 * Keyed rather than positional so the update path can drop `igdb_id` from
	 * the values and its format in one step and keep the two lists aligned.
	 *
	 * @param array $data Column map from {@see map_record()}, plus `updated_at`.
	 * @return array<string,string> Column → format.
	 */
	private static function column_formats( array $data ) {
		$formats = array(
			'igdb_id'            => '%d',
			'name'               => '%s',
			'slug'               => '%s',
			'summary'            => '%s',
			'first_release_date' => '%d',
			'cover_image_id'     => '%s',
			'platforms'          => '%s',
			'genres'             => '%s',
			'total_rating'       => '%f',
			'total_rating_count' => '%d',
			'igdb_url'           => '%s',
			'updated_at'         => '%s',
		);

		return array_intersect_key( $formats, $data );
	}

	/**
	 * A slug this game may hold without colliding with another game's.
	 *
	 * `slug` is UNIQUE, so an insert carrying a slug another IGDB id already
	 * owns would fail outright and lose the record. IGDB slugs are unique
	 * upstream, so this is a guard against the edge — a fallback slug derived
	 * from a duplicate title, or an upstream slug being reassigned between
	 * games — and it is skipped entirely when the slug has not changed, which
	 * is every row of an ordinary refresh batch.
	 *
	 * @param string $slug         Wanted slug.
	 * @param int    $igdb_id      Game claiming it.
	 * @param string $current_slug Slug the game already holds, if any.
	 * @return string A slug free for this game.
	 */
	private static function available_slug( $slug, $igdb_id, $current_slug ) {
		if ( $slug === $current_slug ) {
			return $slug;
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write-path uniqueness check, run only when a slug actually changes.
		$owner = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); both values are bound placeholders.
				"SELECT igdb_id FROM {$table} WHERE slug = %s AND igdb_id != %d LIMIT 1",
				$slug,
				$igdb_id
			)
		);

		if ( null === $owner ) {
			return $slug;
		}

		// Suffixing with the id is deterministic: the same game gets the same
		// fallback slug on every later refresh, so its URL does not drift.
		return self::truncate( $slug . '-' . $igdb_id, 200 );
	}

	/**
	 * Prime the cache entries for one lookup result.
	 *
	 * Both keys point at the same single copy of the row: the id key holds it,
	 * the slug key holds the id. A miss is cached too, as null / 0.
	 *
	 * @param array|null  $game    Hydrated row, or null for a miss.
	 * @param int         $igdb_id Id that was looked up (0 when unknown).
	 * @param string|null $slug    Slug that was looked up, when the lookup was by slug.
	 * @return void
	 */
	private static function prime( $game, $igdb_id, $slug = null ) {
		if ( $igdb_id > 0 ) {
			// The same NO_ROW discipline the batch reader uses: these two write
			// the same keys, and `get_many()` cannot read a normalised null
			// (PB-5).
			GameLib_Cache::set(
				GameLib_Cache::SCOPE_GAMES,
				self::row_key( $igdb_id ),
				is_array( $game ) ? $game : self::NO_ROW
			);
		}

		if ( null !== $slug && '' !== $slug ) {
			GameLib_Cache::set( GameLib_Cache::SCOPE_GAMES, self::slug_key( $slug ), $igdb_id );
		}

		if ( is_array( $game ) && '' !== $game['slug'] && $game['slug'] !== $slug ) {
			GameLib_Cache::set( GameLib_Cache::SCOPE_GAMES, self::slug_key( $game['slug'] ), $game['igdb_id'] );
		}
	}

	/**
	 * Cache key for one game row.
	 *
	 * @param int $igdb_id IGDB game id.
	 * @return string Entry key.
	 */
	private static function row_key( $igdb_id ) {
		return 'game:' . (int) $igdb_id;
	}

	/**
	 * Cache key for a slug → id pointer.
	 *
	 * @param string $slug IGDB slug.
	 * @return string Entry key.
	 */
	private static function slug_key( $slug ) {
		return 'game_slug:' . (string) $slug;
	}

	/**
	 * Cache key for one Steam-map entry.
	 *
	 * @param int $appid Steam application id.
	 * @return string Entry key.
	 */
	private static function steam_key( $appid ) {
		return 'steam:' . (int) $appid;
	}

	/**
	 * Extract the display names from an expanded IGDB sub-resource list and
	 * encode them for storage.
	 *
	 * IGDB returns `platforms`/`genres` as objects when the field is expanded
	 * (`platforms.name`) and as bare ids when it is not; only names are worth
	 * storing, so unexpanded ids are dropped rather than written as numbers a
	 * template would render literally.
	 *
	 * @param mixed $values Raw field value from an IGDB record.
	 * @return string|null JSON array of names, or null when there are none.
	 */
	private static function encode_names( $values ) {
		if ( ! is_array( $values ) ) {
			return null;
		}

		$names = array();

		foreach ( $values as $value ) {
			if ( is_array( $value ) && isset( $value['name'] ) && is_scalar( $value['name'] ) ) {
				$name = sanitize_text_field( (string) $value['name'] );
			} elseif ( is_string( $value ) ) {
				$name = sanitize_text_field( $value );
			} else {
				continue;
			}

			if ( '' !== $name && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}

		if ( empty( $names ) ) {
			return null;
		}

		return wp_json_encode( $names );
	}

	/**
	 * Decode a stored name list back into an array of strings.
	 *
	 * Anything unreadable — a truncated column, a value written by an older
	 * version — decodes to an empty list, so a template renders nothing rather
	 * than warning.
	 *
	 * @param mixed $json Stored JSON string.
	 * @return string[] Display names.
	 */
	private static function decode_names( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$names = array();

		foreach ( $decoded as $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$names[] = $value;
			}
		}

		return $names;
	}

	/**
	 * Cut a value to a column's width without splitting a UTF-8 character.
	 *
	 * MySQL in strict mode rejects an over-long value outright; without strict
	 * mode it truncates mid-byte. Doing it here keeps both databases —
	 * and Playground's SQLite, which does neither — storing the same thing.
	 *
	 * @param string $value  Value to store.
	 * @param int    $length Column width in characters.
	 * @return string Value, at most $length characters long.
	 */
	private static function truncate( $value, $length ) {
		$value = (string) $value;

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}

	/**
	 * One id, validated as a positive integer.
	 *
	 * Used on lookups *and* writes: `absint( -3 )` is 3, which on a write path
	 * fabricates a row for a game nobody asked for. See
	 * principal/adr/009-store-write-path-id-validation.md — §6's Data Model
	 * names `absint` as the sanitizer for the id columns; this keeps that
	 * guarantee by refusing the value instead of rewriting it.
	 *
	 * @param mixed $value Candidate id.
	 * @return int The id, or 0 when the value is not a positive integer.
	 */
	private static function valid_id( $value ) {
		$id = is_scalar( $value ) ? (int) $value : 0;

		return ( $id > 0 ) ? $id : 0;
	}

	/**
	 * A list of ids, validated, de-duplicated, and re-indexed.
	 *
	 * @param array $values Candidate ids in any scalar form.
	 * @return int[] Unique positive integers.
	 */
	private static function positive_ints( array $values ) {
		$ids = array();

		foreach ( $values as $value ) {
			$id = self::valid_id( $value );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
