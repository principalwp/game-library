<?php
/**
 * The member library: `gamelib_library`.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The only class that reads or writes `gamelib_library`.
 *
 * A library entry is a member, a game, and one of four statuses. The
 * one-row-per-member-per-game invariant is enforced by the database — the
 * `UNIQUE KEY user_game (user_id, igdb_id)` of `GameLib_Schema` — not by a PHP
 * read-then-write, so {@see add()} treats a refused insert as the dedup outcome
 * AC-011(e) describes: no second row, the original status and `added_at`
 * preserved, and a duplicate flag the caller can turn into feedback.
 *
 * Three rules shape the writes:
 *
 * 1. **Status is whitelisted before anything is written** ({@see STATUSES}),
 *    on single and bulk paths alike — the column is a `varchar` because SQLite
 *    has no ENUM (Never Do #9), so PHP is the constraint.
 * 2. **Every write bumps the owner's `lib_{user}` generation**, which is what
 *    makes a status change visible on the very next read (AC-NFR-010b). Events
 *    bump `activity` through {@see GameLib_Activity::record()}.
 * 3. **Bulk actions summarize.** A bulk status change emits exactly one
 *    aggregated `bulk_status_changed` event (DD-009, AC-019e) and a removal
 *    emits none at all (AC-016b) — the activity API offers no per-game variant
 *    for either (Never Do #11).
 *
 * Reads are a JOIN with the shared game store, because AC-017 asks for a title
 * search and a title sort and those are not answerable from `gamelib_library`
 * alone — a two-step "read every row, then sort in PHP" would have to load a
 * whole library into memory to paginate it. The JOIN is **read-only**: every
 * write to `gamelib_games` still goes through {@see GameLib_Game_Store}, which
 * remains the only class that may modify it. It is an INNER JOIN on purpose —
 * an entry whose game row is missing has no title, no cover, and no page, so it
 * could not render (Never Do #10 makes it unreachable in normal operation).
 *
 * Query results are cached under the owner's scope with the `games` generation
 * folded into the key, so an IGDB refresh that renames a game invalidates the
 * library pages that show it without every library write having to bump the
 * shared scope.
 */
final class GameLib_Library {

	/**
	 * Schema suffix of the library table.
	 *
	 * @var string
	 */
	const TABLE = 'library';

	/**
	 * The four library statuses (§6 Data Model). The write whitelist and the
	 * filter vocabulary; {@see GameLib_Activity::STATUSES} carries the same list
	 * for the event meta that records them.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'playing', 'finished', 'backlog', 'wishlist' );

	/**
	 * The "no status filter" value behind AC-017(a)'s All chip. Never stored.
	 *
	 * @var string
	 */
	const STATUS_ALL = 'all';

	/**
	 * Status an import gives a matched game (AC-041a).
	 *
	 * @var string
	 */
	const STATUS_DEFAULT = 'backlog';

	/**
	 * The four sort orders of AC-017(b).
	 *
	 * @var string[]
	 */
	const SORTS = array( 'added_desc', 'added_asc', 'title_asc', 'title_desc' );

	/**
	 * Default sort: date added, newest first (AC-017b).
	 *
	 * @var string
	 */
	const SORT_DEFAULT = 'added_desc';

	/**
	 * Entries per page of the grid (AC-018a).
	 *
	 * @var int
	 */
	const PAGE_SIZE = 24;

	/**
	 * Hard ceiling on a page, whatever a caller asks for (AC-018a).
	 *
	 * @var int
	 */
	const MAX_PAGE_SIZE = 60;

	/**
	 * Longest search term this class will match on. A term longer than a game
	 * title cannot match anything, and the value becomes part of a cache key.
	 *
	 * @var int
	 */
	const MAX_SEARCH_LENGTH = 100;

	/**
	 * Ids one explicit-id bulk request may carry (AC-019f). Over-cap requests
	 * are refused rather than truncated: silently applying an action to the
	 * first 200 of 300 selected games is a data-loss surprise, not a cap.
	 *
	 * @var int
	 */
	const MAX_BULK_IDS = 200;

	/**
	 * Rows one statement of a filter-mode bulk action touches. The loop keeps
	 * re-selecting until nothing matches, so the whole filter is applied
	 * server-side (AC-019f) without one unbounded UPDATE or DELETE.
	 *
	 * @var int
	 */
	const BULK_BATCH = 500;

	/**
	 * Rows read per statement by {@see export_rows()} and {@see purge_user()}.
	 *
	 * @var int
	 */
	const READ_BATCH = 500;

	/**
	 * Rows a filter-mode bulk action will touch inside the request that started
	 * it — four batches (PB-3).
	 *
	 * `all: true` is bounded only by the size of the member's library, and the
	 * `maxItems` cap core enforces applies to the explicit-id mode alone. A
	 * 40,000-entry filter was therefore 80 statements plus one aggregated event
	 * in one synchronous request, and a request killed anywhere in the middle
	 * left committed rows with no event and nothing to resume it. Past this
	 * ceiling the remainder drains from a chained single cron event and the
	 * caller is told the job was queued.
	 *
	 * @var int
	 */
	const BULK_SYNC_MAX = 2000;

	/**
	 * Wall-clock seconds one bulk pass may spend, whichever comes first with
	 * {@see BULK_SYNC_MAX}.
	 *
	 * Mirrors `GAMELIB_IMPORT_TICK_SECONDS`, for the same reason the importer has
	 * one: neither a row count nor a batch count knows how slow the database is
	 * today, and the tail of a pass has to fit inside the request that runs it.
	 *
	 * @var int
	 */
	const BULK_TICK_SECONDS = 20;

	/**
	 * Columns of one library page: the entry, plus the game fields a card needs
	 * (AC-014 a–d). Aliased to the `l.`/`g.` of the JOIN.
	 *
	 * @var string
	 */
	const COLUMNS = 'l.igdb_id, l.status, l.added_at, l.updated_at, g.name, g.slug, g.cover_image_id, g.post_id';

	/**
	 * Error code: no member owns this call (a logged-out caller reaches the REST
	 * layer's 401 first — AC-NFR-001).
	 *
	 * @var string
	 */
	const ERROR_OWNER = 'gamelib_library_owner';

	/**
	 * Error code: the game id is missing or not a positive integer.
	 *
	 * @var string
	 */
	const ERROR_GAME = 'gamelib_library_game';

	/**
	 * Error code: the status is not one of the four.
	 *
	 * @var string
	 */
	const ERROR_STATUS = 'gamelib_library_status';

	/**
	 * Error code: the shared store has no record of the game and none could be
	 * fetched — a library row without a game record is the one thing this table
	 * may never hold (Never Do #10).
	 *
	 * @var string
	 */
	const ERROR_UNKNOWN_GAME = 'gamelib_library_unknown_game';

	/**
	 * Error code: the member does not have that game in their library.
	 *
	 * @var string
	 */
	const ERROR_MISSING = 'gamelib_library_missing';

	/**
	 * Error code: a bulk request said neither which ids to act on nor that it
	 * meant everything matching the current filter.
	 *
	 * @var string
	 */
	const ERROR_BULK_MODE = 'gamelib_library_bulk_mode';

	/**
	 * Error code: an explicit-id bulk request carried more than
	 * {@see MAX_BULK_IDS} ids (AC-019f).
	 *
	 * @var string
	 */
	const ERROR_BULK_LIMIT = 'gamelib_library_bulk_limit';

	/**
	 * Error code: the database refused the write.
	 *
	 * @var string
	 */
	const ERROR_FAILED = 'gamelib_library_failed';

	/**
	 * Add a game to a member's library (AC-011 b,d,e).
	 *
	 * The game is resolved against the shared store first: an id the store has
	 * never seen is fetched from IGDB and upserted (AC-011a), and an id that
	 * cannot be resolved at all is refused, because the entry would name a game
	 * nothing could render. A row the store *does* hold is taken as it stands,
	 * however old it is — a stale row is a normal row (AC-033b), and freshness
	 * belongs to the hourly job (PB-5).
	 *
	 * Duplicates are a success, not an error (AC-011e): the existing row is
	 * returned untouched, with its original status and `added_at`, and the
	 * `duplicate` flag set so the caller can show the notice the AC asks for.
	 * The dedup guard is the table's UNIQUE KEY — the lookup before the insert
	 * is an optimization, and the refused insert is handled the same way.
	 *
	 * @param int    $user_id Library owner.
	 * @param int    $igdb_id IGDB game id.
	 * @param string $status  One of {@see GameLib_Library::STATUSES}.
	 * @param array  $args    Optional. `hydrate` (bool, default true) — resolve
	 *                        the game against IGDB when absent or stale; import
	 *                        ticks pass false, having hydrated in batches
	 *                        already. `record_event` (bool, default true) —
	 *                        false suppresses the per-game `game_added` event
	 *                        for import and bulk callers (Never Do #11).
	 *                        `added_at` (string) — a UTC `Y-m-d H:i:s` (or
	 *                        `Y-m-d`) the entry should carry instead of now, for
	 *                        an import restoring a member's own export
	 *                        (AC-039d); anything unparseable or in the future is
	 *                        ignored in favour of now.
	 *                        `known_absent` (bool, default false) — the caller has
	 *                        already proved this pair absent in a batch read, so
	 *                        the pre-insert lookup is skipped (PB-7). The UNIQUE
	 *                        key still arbitrates, and the refused-insert branch
	 *                        below still re-reads, so the concurrent-add race
	 *                        stays covered. `defer_invalidation` (bool, default
	 *                        false) — do not bump the owner's cache scope here;
	 *                        the caller hoists one bump to the end of its batch.
	 *                        Both are for the importer only: the interactive path
	 *                        needs the read and the bump it performs.
	 * @return array{igdb_id:int,status:string,added:bool,duplicate:bool,added_at:string,game:array}|WP_Error
	 *         The resulting entry, or the reason it was refused.
	 */
	public static function add( $user_id, $igdb_id, $status, array $args = array() ) {
		$user_id = self::valid_id( $user_id );
		$igdb_id = self::valid_id( $igdb_id );
		$status  = self::sanitize_status( $status );

		if ( $user_id < 1 ) {
			return self::owner_error();
		}

		if ( $igdb_id < 1 ) {
			return self::game_error();
		}

		if ( '' === $status ) {
			return self::status_error();
		}

		$hydrate = ! isset( $args['hydrate'] ) || (bool) $args['hydrate'];
		$game    = self::resolve_game( $igdb_id, $hydrate );

		if ( is_wp_error( $game ) ) {
			return $game;
		}

		/*
		 * An import chunk has just proved this pair absent through the batched
		 * statuses_for() read a few lines earlier in its own loop, so repeating
		 * the lookup here is one uncached SELECT per row — 250 a tick, ~10,000
		 * across a full import (PB-7). The UNIQUE key is the actual dedup guard;
		 * this read is an optimization for the interactive path, where it also
		 * spares the insert.
		 */
		$existing = empty( $args['known_absent'] ) ? self::read_entry( $user_id, $igdb_id ) : null;

		if ( is_array( $existing ) ) {
			// AC-011(e): one row, the original status, the original date.
			self::maybe_first_add( $igdb_id, $user_id );

			return self::added_result( $existing, $game, false );
		}

		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		/*
		 * AC-039(d): an import may restore the date the member's own export
		 * recorded. It is validated here rather than trusted, because this is
		 * the class that owns the column — a value that is not a past UTC
		 * timestamp becomes "now" instead.
		 */
		$added_at = self::sanitize_datetime( isset( $args['added_at'] ) ? $args['added_at'] : '' );
		$added_at = ( '' === $added_at ) ? $now : $added_at;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->insert() prepares its own statement, and every cached read of this library is invalidated by the generation bump below.
		$written = $wpdb->insert(
			GameLib_Schema::table( self::TABLE ),
			array(
				'user_id'    => $user_id,
				'igdb_id'    => $igdb_id,
				'status'     => $status,
				'added_at'   => $added_at,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $written ) {
			/*
			 * Two requests can reach the insert for the same pair at once — a
			 * double-submitted add is exactly that. UNIQUE KEY user_game refuses
			 * the second, and the winner's row is the answer AC-011(e) wants.
			 */
			$existing = self::read_entry( $user_id, $igdb_id );

			if ( ! is_array( $existing ) ) {
				return new WP_Error(
					self::ERROR_FAILED,
					__( 'That game could not be added to your library. Please try again.', 'game-library' ),
					array( 'status' => 500 )
				);
			}

			self::maybe_first_add( $igdb_id, $user_id );

			return self::added_result( $existing, $game, false );
		}

		/*
		 * One `wp_cache_incr` per row is a network round trip each on VIP,
		 * against a counter that only has to move once for a whole import chunk
		 * — so a caller adding in bulk hoists the bump to the end of its batch
		 * (PB-7). Never deferred on the interactive path.
		 */
		if ( empty( $args['defer_invalidation'] ) ) {
			GameLib_Cache::bump( self::scope( $user_id ) );
		}

		if ( ! isset( $args['record_event'] ) || (bool) $args['record_event'] ) {
			GameLib_Activity::record(
				$user_id,
				GameLib_Activity::TYPE_GAME_ADDED,
				$igdb_id,
				array( 'to' => $status )
			);
		}

		self::maybe_first_add( $igdb_id, $user_id );

		return self::added_result(
			array(
				'igdb_id'  => $igdb_id,
				'status'   => $status,
				'added_at' => $added_at,
			),
			$game,
			true
		);
	}

	/**
	 * Move one entry to another status (AC-015 a,b).
	 *
	 * A request that names the status the entry already has is a success that
	 * writes nothing and records nothing: `updated_at` is a record of change,
	 * and an event reading "moved Hades to Playing" when nothing moved would be
	 * feed noise the member did not create.
	 *
	 * @param int    $user_id Library owner.
	 * @param int    $igdb_id IGDB game id.
	 * @param string $status  One of {@see GameLib_Library::STATUSES}.
	 * @return array{igdb_id:int,from:string,to:string,changed:bool}|WP_Error The
	 *         transition, or the reason it was refused.
	 */
	public static function set_status( $user_id, $igdb_id, $status ) {
		$user_id = self::valid_id( $user_id );
		$igdb_id = self::valid_id( $igdb_id );
		$status  = self::sanitize_status( $status );

		if ( $user_id < 1 ) {
			return self::owner_error();
		}

		if ( $igdb_id < 1 ) {
			return self::game_error();
		}

		if ( '' === $status ) {
			return self::status_error();
		}

		$entry = self::read_entry( $user_id, $igdb_id );

		if ( ! is_array( $entry ) ) {
			return self::missing_error();
		}

		$from = (string) $entry['status'];

		if ( $from === $status ) {
			return array(
				'igdb_id' => $igdb_id,
				'from'    => $from,
				'to'      => $status,
				'changed' => false,
			);
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->update() prepares its own statement, and every cached read of this library is invalidated by the generation bump below.
		$written = $wpdb->update(
			GameLib_Schema::table( self::TABLE ),
			array(
				'status'     => $status,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'user_id' => $user_id,
				'igdb_id' => $igdb_id,
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);

		if ( false === $written ) {
			return new WP_Error(
				self::ERROR_FAILED,
				__( 'That status could not be saved. Please try again.', 'game-library' ),
				array( 'status' => 500 )
			);
		}

		GameLib_Cache::bump( self::scope( $user_id ) );

		GameLib_Activity::record(
			$user_id,
			GameLib_Activity::TYPE_STATUS_CHANGED,
			$igdb_id,
			array(
				'from' => $from,
				'to'   => $status,
			)
		);

		return array(
			'igdb_id' => $igdb_id,
			'from'    => $from,
			'to'      => $status,
			'changed' => true,
		);
	}

	/**
	 * Delete one entry (AC-016 a,b).
	 *
	 * No activity event is recorded — removal is the one library write that
	 * never reaches the feed.
	 *
	 * @param int $user_id Library owner.
	 * @param int $igdb_id IGDB game id.
	 * @return int|WP_Error Rows deleted (0 when the member did not have the
	 *                      game), or the reason the call was refused.
	 */
	public static function remove( $user_id, $igdb_id ) {
		$user_id = self::valid_id( $user_id );
		$igdb_id = self::valid_id( $igdb_id );

		if ( $user_id < 1 ) {
			return self::owner_error();
		}

		if ( $igdb_id < 1 ) {
			return self::game_error();
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->delete() prepares its own statement, and every cached read of this library is invalidated by the generation bump below.
		$deleted = $wpdb->delete(
			GameLib_Schema::table( self::TABLE ),
			array(
				'user_id' => $user_id,
				'igdb_id' => $igdb_id,
			),
			array( '%d', '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error(
				self::ERROR_FAILED,
				__( 'That game could not be removed. Please try again.', 'game-library' ),
				array( 'status' => 500 )
			);
		}

		if ( $deleted > 0 ) {
			GameLib_Cache::bump( self::scope( $user_id ) );
		}

		return (int) $deleted;
	}

	/**
	 * One page of a member's library, filtered, searched, and sorted (AC-017,
	 * AC-018a).
	 *
	 * Every criterion is applied in SQL, on the whole library, before the page
	 * is cut — filtering a page in PHP would make page 2 of "backlog" a
	 * different set than page 2 of the unfiltered list.
	 *
	 * The returned `filtered` flag is what lets a caller tell AC-017's "No games
	 * match." apart from AC-020's empty-library state: `total === 0` with
	 * `filtered === false` is a member who has added nothing.
	 *
	 * @param int   $user_id Library owner (or any member whose library is being
	 *                       viewed — visibility is the caller's gate, not this
	 *                       class's).
	 * @param array $args    Optional. `status` (one of the four, or `all`),
	 *                       `search` (title substring), `sort` (one of
	 *                       {@see SORTS}), `page` (1-based), `per_page`
	 *                       (clamped to {@see MAX_PAGE_SIZE}).
	 * @return array{rows:array[],total:int,page:int,pages:int,per_page:int,status:string,search:string,sort:string,filtered:bool}
	 *         The page and everything needed to render its controls.
	 */
	public static function query( $user_id, array $args = array() ) {
		$user_id  = self::valid_id( $user_id );
		$criteria = self::criteria( $args );
		$per_page = self::clamp_per_page( isset( $args['per_page'] ) ? $args['per_page'] : self::PAGE_SIZE );
		$page     = max( 1, absint( isset( $args['page'] ) ? $args['page'] : 1 ) );

		$result = array(
			'rows'     => array(),
			'total'    => 0,
			'page'     => $page,
			'pages'    => 0,
			'per_page' => $per_page,
			'status'   => $criteria['status'],
			'search'   => $criteria['search'],
			'sort'     => $criteria['sort'],
			'filtered' => ( self::STATUS_ALL !== $criteria['status'] || '' !== $criteria['search'] ),
		);

		if ( $user_id < 1 ) {
			return $result;
		}

		$key = array(
			'page',
			$criteria['status'],
			$criteria['search'],
			$criteria['sort'],
			$page,
			$per_page,
			/*
			 * A game rename or a cover change must reach the cards on the very
			 * next read, so the generation that stands for "rendered game content
			 * changed" is part of the key (CO-2). Deliberately not `SCOPE_GAMES`:
			 * that scope covers the store's own row entries, which every writer
			 * re-primes, so keying pages to it made an hourly refresh discard
			 * every member's pages twice — once for the content change and once
			 * for writes that changed nothing anyone renders.
			 */
			GameLib_Cache::generation( GameLib_Cache::SCOPE_GAME_CONTENT ),
		);

		$found   = false;
		$payload = GameLib_Cache::get( self::scope( $user_id ), $key, $found );

		if ( ! $found || ! is_array( $payload ) || ! isset( $payload['rows'], $payload['total'] ) ) {
			$payload = array(
				'rows'  => self::read_page( $user_id, $criteria, $per_page, ( $page - 1 ) * $per_page ),
				'total' => self::read_total( $user_id, $criteria ),
			);

			GameLib_Cache::set( self::scope( $user_id ), $key, $payload );
		}

		$total = (int) $payload['total'];

		$result['rows']  = self::decorate( is_array( $payload['rows'] ) ? $payload['rows'] : array() );
		$result['total'] = $total;
		$result['pages'] = (int) ceil( $total / $per_page );

		return $result;
	}

	/**
	 * How many games the member holds in each status (AC-017a).
	 *
	 * The five filter chips are the caller of this: `all` plus the four
	 * statuses, every key always present so a chip can render a zero.
	 *
	 * @param int $user_id Library owner.
	 * @return array<string,int> Counts keyed by status, plus `all`.
	 */
	public static function count_by_status( $user_id ) {
		$user_id = self::valid_id( $user_id );

		$counts = array( self::STATUS_ALL => 0 );

		foreach ( self::STATUSES as $status ) {
			$counts[ $status ] = 0;
		}

		if ( $user_id < 1 ) {
			return $counts;
		}

		$found  = false;
		$cached = GameLib_Cache::get( self::scope( $user_id ), 'counts', $found );

		if ( $found && is_array( $cached ) ) {
			return array_merge( $counts, array_map( 'intval', $cached ) );
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; the result is cached below through GameLib_Cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); the one value is a bound placeholder.
				"SELECT status, COUNT(*) AS total FROM {$table} WHERE user_id = %d GROUP BY status",
				$user_id
			),
			ARRAY_A
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['status'], $row['total'] ) ) {
					continue;
				}

				$status = (string) $row['status'];
				$total  = (int) $row['total'];

				if ( ! self::is_status( $status ) ) {
					// A value from outside the whitelist still counts toward the
					// total the All chip shows, but has no chip of its own.
					$counts[ self::STATUS_ALL ] += $total;
					continue;
				}

				$counts[ $status ]           = $total;
				$counts[ self::STATUS_ALL ] += $total;
			}
		}

		GameLib_Cache::set( self::scope( $user_id ), 'counts', $counts );

		return $counts;
	}

	/**
	 * One entry, or null when the member does not have that game.
	 *
	 * The cached read behind a status control's initial state. Write paths use
	 * their own uncached lookup — the answer there decides the next statement.
	 *
	 * @param int $user_id Library owner.
	 * @param int $igdb_id IGDB game id.
	 * @return array{igdb_id:int,status:string,added_at:string,updated_at:string}|null Entry, or null.
	 */
	public static function entry( $user_id, $igdb_id ) {
		$user_id = self::valid_id( $user_id );
		$igdb_id = self::valid_id( $igdb_id );

		if ( $user_id < 1 || $igdb_id < 1 ) {
			return null;
		}

		$found  = false;
		$cached = GameLib_Cache::get( self::scope( $user_id ), 'entry:' . $igdb_id, $found );

		if ( $found ) {
			return is_array( $cached ) ? $cached : null;
		}

		$entry = self::read_entry( $user_id, $igdb_id );

		GameLib_Cache::set( self::scope( $user_id ), 'entry:' . $igdb_id, $entry );

		return $entry;
	}

	/**
	 * The statuses a member already holds for a set of games.
	 *
	 * The duplicate rule of an import (AC-039b) is one call to this per batch:
	 * an appid whose IGDB id comes back with a status is a `duplicate` row whose
	 * status must be preserved, never reset.
	 *
	 * @param int   $user_id  Library owner.
	 * @param int[] $igdb_ids IGDB game ids.
	 * @return array<int,string> Status keyed by IGDB id; ids the member does not
	 *                           have are absent.
	 */
	public static function statuses_for( $user_id, array $igdb_ids ) {
		$user_id  = self::valid_id( $user_id );
		$igdb_ids = self::positive_ints( $igdb_ids );

		if ( $user_id < 1 || empty( $igdb_ids ) ) {
			return array();
		}

		sort( $igdb_ids );

		$key    = array( 'statuses', $igdb_ids );
		$found  = false;
		$cached = GameLib_Cache::get( self::scope( $user_id ), $key, $found );

		if ( $found && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$table    = GameLib_Schema::table( self::TABLE );
		$statuses = array();

		foreach ( array_chunk( $igdb_ids, self::READ_BATCH ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %d literals bound by prepare() below; the result is cached under the member's `lib_{user}` generation scope.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above; $table comes from GameLib_Schema::table().
					"SELECT igdb_id, status FROM {$table} WHERE user_id = %d AND igdb_id IN ({$placeholders})",
					array_merge( array( $user_id ), $chunk )
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				if ( is_array( $row ) && isset( $row['igdb_id'], $row['status'] ) ) {
					$statuses[ (int) $row['igdb_id'] ] = (string) $row['status'];
				}
			}
		}

		GameLib_Cache::set( self::scope( $user_id ), $key, $statuses );

		return $statuses;
	}

	/**
	 * Move many entries to one status, emitting exactly one event (AC-019 b,e,f).
	 *
	 * Two selection modes, and a caller must name one:
	 *
	 * - `ids`  — the games the member ticked, capped at {@see MAX_BULK_IDS};
	 * - `all`  — everything matching the applied filter, evaluated server-side
	 *            from the same criteria {@see query()} uses, so the operation
	 *            covers rows the member never saw on screen.
	 *
	 * Rows already at the target status are excluded from the statement, which
	 * is both what makes the reported count honest and what terminates the
	 * filter-mode loop (a processed row stops matching).
	 *
	 * A filter-mode action that does not finish inside {@see BULK_SYNC_MAX} rows
	 * or {@see BULK_TICK_SECONDS} hands the remainder to a chained cron event and
	 * answers `queued` (PB-3); the aggregated event is emitted once, when the
	 * last pass finishes, so AC-019(e) holds however many passes it took.
	 *
	 * @param int    $user_id Library owner.
	 * @param string $status  Target status.
	 * @param array  $args    Selection: `ids` (int[]) or `all` (bool) plus the
	 *                        `status`/`search` criteria of the applied filter.
	 * @return array{updated:int,status:string,queued:bool,abandoned:bool}|WP_Error
	 *         How many entries moved, whether the rest is draining in the
	 *         background, and whether the rest was dropped instead (CO-5).
	 */
	public static function bulk_set_status( $user_id, $status, array $args = array() ) {
		$user_id = self::valid_id( $user_id );
		$status  = self::sanitize_status( $status );

		if ( $user_id < 1 ) {
			return self::owner_error();
		}

		if ( '' === $status ) {
			return self::status_error();
		}

		$selection = self::selection( $args );

		if ( is_wp_error( $selection ) ) {
			return $selection;
		}

		if ( 'ids' === $selection['mode'] ) {
			$updated = self::update_ids( $user_id, $selection['ids'], $status );

			if ( $updated > 0 ) {
				GameLib_Cache::bump( self::scope( $user_id ) );
				self::record_bulk_status_event( $user_id, $status, $updated );
			}

			return array(
				'updated'   => $updated,
				'status'    => $status,
				'queued'    => false,
				'abandoned' => false,
			);
		}

		$pass = self::drain_bulk(
			array(
				'user_id'  => $user_id,
				'action'   => 'status',
				'status'   => $status,
				'criteria' => $selection['criteria'],
				'done'     => 0,
			)
		);

		return array(
			'updated'   => $pass['processed'],
			'status'    => $status,
			'queued'    => $pass['queued'],
			'abandoned' => $pass['abandoned'],
		);
	}

	/**
	 * Apply as much of a filter-mode bulk action as one request may (PB-3).
	 *
	 * Bounded twice — by {@see BULK_SYNC_MAX} rows and by
	 * {@see BULK_TICK_SECONDS} of wall clock — with the owner's cache scope
	 * bumped once per batch, inside the loop, so the object cache can never be
	 * ahead of the database if the request dies. Whatever is left is handed to
	 * one chained `gamelib_library_bulk` event keyed by the member, the shape
	 * {@see GameLib_Importer::schedule_tick()} already uses; there is never a
	 * per-item fan-out.
	 *
	 * A job ends in one of three ways, and the caller is told which (CO-5): the
	 * filter is exhausted, the remainder is `queued` on cron, or the remainder
	 * was `abandoned` because nothing could be scheduled to continue it — a
	 * `pre_schedule_event` filter, a full cron array, anything answering false.
	 * The third used to be reported as the first, so a member was told a 2,000-row
	 * job had completed while the rest was silently dropped.
	 *
	 * @param array $job Job description: `user_id`, `action` (`status`|`remove`),
	 *                   `status` (for `status`), `criteria`, and `done` — rows
	 *                   already processed by earlier passes.
	 * @return array{processed:int,queued:bool,abandoned:bool} Rows this pass
	 *         touched, whether more is draining, and whether the remainder was
	 *         dropped.
	 */
	private static function drain_bulk( array $job ) {
		$user_id   = self::valid_id( isset( $job['user_id'] ) ? $job['user_id'] : 0 );
		$action    = ( isset( $job['action'] ) && 'remove' === $job['action'] ) ? 'remove' : 'status';
		$status    = isset( $job['status'] ) ? self::sanitize_status( $job['status'] ) : '';
		$criteria  = ( isset( $job['criteria'] ) && is_array( $job['criteria'] ) ) ? $job['criteria'] : self::criteria( array() );
		$done      = isset( $job['done'] ) ? max( 0, absint( $job['done'] ) ) : 0;
		$processed = 0;

		if ( $user_id < 1 || ( 'status' === $action && '' === $status ) ) {
			return array(
				'processed' => 0,
				'queued'    => false,
				'abandoned' => false,
			);
		}

		$deadline  = microtime( true ) + (float) self::BULK_TICK_SECONDS;
		$exhausted = false;

		while ( $processed < self::BULK_SYNC_MAX && microtime( true ) < $deadline ) {
			$ids = self::select_ids( $user_id, $criteria, self::BULK_BATCH, ( 'status' === $action ) ? $status : '' );

			if ( empty( $ids ) ) {
				$exhausted = true;

				break;
			}

			/*
			 * The batch cannot repeat: a moved row no longer matches the select
			 * (the target status is excluded from it) and a deleted one is gone.
			 * A batch that changes nothing ends the pass rather than re-reading
			 * the same ids forever.
			 */
			$changed = ( 'status' === $action )
				? self::update_ids( $user_id, $ids, $status )
				: self::delete_ids( $user_id, $ids );

			if ( $changed < 1 ) {
				$exhausted = true;

				break;
			}

			$processed += $changed;

			// Once per batch, never per row, and before the next select: the
			// cache must not be able to describe rows the database no longer has.
			GameLib_Cache::bump( self::scope( $user_id ) );

			if ( count( $ids ) < self::BULK_BATCH ) {
				$exhausted = true;

				break;
			}
		}

		$total = $done + $processed;

		if ( ! $exhausted ) {
			$queued = self::schedule_bulk(
				array(
					'user_id'  => $user_id,
					'action'   => $action,
					'status'   => $status,
					'criteria' => $criteria,
					'done'     => $total,
				)
			);

			if ( $queued ) {
				return array(
					'processed' => $processed,
					'queued'    => true,
					'abandoned' => false,
				);
			}
		}

		/*
		 * The job is over — either the filter is exhausted or nothing could be
		 * scheduled to continue it. Either way this is where its single
		 * aggregated event belongs (AC-019e): the count is what actually moved,
		 * which stays honest in the abandoned case even though it is smaller than
		 * what was asked for.
		 */
		if ( 'status' === $action && $total > 0 ) {
			self::record_bulk_status_event( $user_id, $status, $total );
		}

		return array(
			'processed' => $processed,
			'queued'    => false,
			// Rows were left matching the filter and no pass will come for them
			// (CO-5). The caller has to say so rather than report a success.
			'abandoned' => ! $exhausted,
		);
	}

	/**
	 * Continue a bulk action from cron (PB-3).
	 *
	 * Registered on `GameLib_Plugin::CRON_BULK_HOOK`. Each pass schedules the
	 * next one itself, so a job of any size is a chain of bounded passes rather
	 * than one unbounded request.
	 *
	 * @param array $job Job description, as {@see drain_bulk()} takes it.
	 * @return void
	 */
	public static function run_bulk( $job ) {
		if ( is_array( $job ) ) {
			self::drain_bulk( $job );
		}
	}

	/**
	 * Schedule the next pass of a bulk action.
	 *
	 * One event per job, keyed by the member and carrying how far the job has
	 * got, so `wp_schedule_single_event()`'s duplicate guard cannot mistake a
	 * chained pass for a repeat of its own predecessor.
	 *
	 * @param array $job Job description, as {@see drain_bulk()} takes it.
	 * @return bool True when a pass is scheduled.
	 */
	private static function schedule_bulk( array $job ) {
		$scheduled = wp_schedule_single_event( time(), GameLib_Plugin::CRON_BULK_HOOK, array( $job ), true );

		if ( is_wp_error( $scheduled ) ) {
			return 'duplicate_event' === $scheduled->get_error_code();
		}

		return false !== $scheduled;
	}

	/**
	 * The one aggregated event a bulk status change emits (AC-019e, DD-009).
	 *
	 * Never one per game (Never Do #11) — the activity API has no per-game
	 * variant of this type to call — and never one per pass: a job that drains
	 * across several cron ticks still emits exactly one, at the end, counting
	 * every row it moved.
	 *
	 * @param int    $user_id Library owner.
	 * @param string $status  Target status.
	 * @param int    $count   Entries moved by the whole job.
	 * @return void
	 */
	private static function record_bulk_status_event( $user_id, $status, $count ) {
		GameLib_Activity::record(
			$user_id,
			GameLib_Activity::TYPE_BULK_STATUS_CHANGED,
			0,
			array(
				'count' => $count,
				'to'    => $status,
			)
		);
	}

	/**
	 * Delete many entries, emitting no event (AC-016b, AC-019 c,f).
	 *
	 * Same two selection modes as {@see bulk_set_status()}; the filter-mode loop
	 * terminates because a deleted row stops matching.
	 *
	 * Filter mode is bounded and drains in the background exactly as
	 * {@see bulk_set_status()}'s is (PB-3); a removal emits no event, so there
	 * is nothing to defer to the end of the chain.
	 *
	 * @param int   $user_id Library owner.
	 * @param array $args    Selection: `ids` (int[]) or `all` (bool) plus the
	 *                       `status`/`search` criteria of the applied filter.
	 * @return array{removed:int,queued:bool,abandoned:bool}|WP_Error How many
	 *         entries were deleted, whether the rest is draining in the
	 *         background, and whether the rest was dropped instead (CO-5).
	 */
	public static function bulk_remove( $user_id, array $args = array() ) {
		$user_id = self::valid_id( $user_id );

		if ( $user_id < 1 ) {
			return self::owner_error();
		}

		$selection = self::selection( $args );

		if ( is_wp_error( $selection ) ) {
			return $selection;
		}

		if ( 'ids' === $selection['mode'] ) {
			$removed = self::delete_ids( $user_id, $selection['ids'] );

			if ( $removed > 0 ) {
				GameLib_Cache::bump( self::scope( $user_id ) );
			}

			return array(
				'removed'   => $removed,
				'queued'    => false,
				'abandoned' => false,
			);
		}

		$pass = self::drain_bulk(
			array(
				'user_id'  => $user_id,
				'action'   => 'remove',
				'criteria' => $selection['criteria'],
				'done'     => 0,
			)
		);

		return array(
			'removed'   => $pass['processed'],
			'queued'    => $pass['queued'],
			'abandoned' => $pass['abandoned'],
		);
	}

	/**
	 * Walk a member's library as export rows — exactly the four AC-037 fields.
	 *
	 * `igdb_id`, `title`, `status`, `date_added` and nothing else: no summary,
	 * no cover URL, no other IGDB metadata. `date_added` is the UTC calendar
	 * date of the stored timestamp, so an export is reproducible regardless of
	 * who renders it or where.
	 *
	 * A *walk*, not a read (PB-8). This used to return the whole library as one
	 * array: the SQL was batched, but every batch was merged into one accumulator
	 * and handed back, so peak memory was exactly what an unbounded query would
	 * have cost — and the caller then built the whole document as a second copy
	 * beside it. Nothing caps a library (the 10,000 cap is per *import*), so the
	 * only bound was the member's own patience. `$each` now receives one batch of
	 * at most {@see READ_BATCH} rows at a time and the batch is dropped as soon
	 * as it returns, which is what keeps peak memory to one batch plus whatever
	 * the caller is accumulating.
	 *
	 * Paging is by keyset on `l.id`, never OFFSET: with a growing offset the
	 * twentieth batch would make the engine scan and discard 49,500 joined rows
	 * to reach the ones it wants. `l.id` is the table's auto-increment PRIMARY
	 * KEY and the order the rows are returned in, so `id > {last}` is both the
	 * cursor and the sort.
	 *
	 * @param int      $user_id Library owner.
	 * @param callable $each    Receives each batch as
	 *                          `array<int,array{igdb_id:int,title:string,status:string,date_added:string}>`,
	 *                          in insertion order (oldest entry first). Never
	 *                          called with an empty batch.
	 * @param array    $args    Optional. `limit` (int) — stop after this many
	 *                          rows in total; omitted walks the whole library.
	 *                          `after_id` (int) — resume after this library row
	 *                          id.
	 * @return int Rows passed to `$each`.
	 */
	public static function export_rows( $user_id, callable $each, array $args = array() ) {
		$user_id = self::valid_id( $user_id );

		if ( $user_id < 1 ) {
			return 0;
		}

		$limit = isset( $args['limit'] ) ? max( 0, absint( $args['limit'] ) ) : 0;
		$after = isset( $args['after_id'] ) ? max( 0, (int) $args['after_id'] ) : 0;
		$total = 0;

		while ( true ) {
			$batch_size = ( $limit > 0 ) ? min( self::READ_BATCH, $limit - $total ) : self::READ_BATCH;

			if ( $batch_size < 1 ) {
				break;
			}

			$batch = self::read_export_batch( $user_id, $batch_size, $after );
			$read  = count( $batch['rows'] );

			if ( $read > 0 ) {
				$after  = $batch['last_id'];
				$total += $read;

				call_user_func( $each, $batch['rows'] );
			}

			if ( $read < $batch_size ) {
				break;
			}
		}

		return $total;
	}

	/**
	 * Delete every library row a member owns (AC-052b).
	 *
	 * The privacy eraser and the `deleted_user` purge end here, because this
	 * class owns the table. Walked in batches so a member with a five-figure
	 * library is not one lock-holding DELETE. Game rows and game posts are never
	 * touched (AC-052f).
	 *
	 * Row ids rather than the criteria select the batches: an erasure has to
	 * remove every row the member owns, including one whose game record is
	 * somehow missing and which the reading JOIN would therefore never see.
	 *
	 * The walk is bounded per call (PB-3): `limit` caps the rows one call
	 * removes and `remaining` tells {@see GameLib_Privacy::erase()} — which core
	 * re-invokes page by page — that there is more to do, so no single request
	 * promises to delete an unbounded number of rows.
	 *
	 * @param int   $user_id Member being purged.
	 * @param array $args    Optional. `limit` (int) — stop after this many rows;
	 *                       0, the default, walks to exhaustion.
	 * @return array{deleted:int,remaining:bool} Rows removed, and whether the
	 *                                           limit stopped the walk short.
	 */
	public static function purge_user( $user_id, array $args = array() ) {
		$user_id = self::valid_id( $user_id );
		$limit   = isset( $args['limit'] ) ? max( 0, absint( $args['limit'] ) ) : 0;

		$result = array(
			'deleted'   => 0,
			'remaining' => false,
		);

		if ( $user_id < 1 ) {
			return $result;
		}

		global $wpdb;

		$table   = GameLib_Schema::table( self::TABLE );
		$deleted = 0;

		do {
			if ( $limit > 0 && $deleted >= $limit ) {
				$result['remaining'] = true;

				break;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write-path read: the batch of row ids the next statement deletes; caching rows about to be removed would be wrong.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); both values are bound placeholders.
					"SELECT id FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d",
					$user_id,
					self::READ_BATCH
				)
			);

			$ids = self::positive_ints( is_array( $ids ) ? $ids : array() );

			if ( empty( $ids ) ) {
				break;
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %d literals bound by prepare() below; $table comes from GameLib_Schema::table(). A write is never cached.
			$removed = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
					"DELETE FROM {$table} WHERE id IN ({$placeholders})",
					$ids
				)
			);

			if ( ! is_int( $removed ) || $removed < 1 ) {
				break;
			}

			$deleted += $removed;

			// Inside the loop, once per batch (PB-3): a request killed between
			// batches must not leave committed deletes behind a cache that still
			// serves the rows.
			GameLib_Cache::bump( self::scope( $user_id ) );
		} while ( count( $ids ) === self::READ_BATCH );

		$result['deleted'] = $deleted;

		return $result;
	}

	/**
	 * Is this one of the four library statuses?
	 *
	 * @param string $status Candidate status.
	 * @return bool True when the value is on the whitelist.
	 */
	public static function is_status( $status ) {
		return in_array( $status, self::STATUSES, true );
	}

	/**
	 * A status, or '' when the value is not one of the four.
	 *
	 * The single gate every write goes through (Always Do #2), and the one the
	 * REST layer's `validate_callback` should reuse rather than re-derive.
	 *
	 * @param mixed $status Candidate status.
	 * @return string Whitelisted status, or ''.
	 */
	public static function sanitize_status( $status ) {
		$status = is_scalar( $status ) ? sanitize_key( (string) $status ) : '';

		return self::is_status( $status ) ? $status : '';
	}

	/**
	 * A caller-supplied `added_at`, or '' when it cannot be trusted.
	 *
	 * Accepts the two shapes a member's own export can produce — the calendar
	 * date the exporter writes, and a full timestamp a hand-edited file may
	 * carry — both read as UTC, which is how every datetime in this plugin is
	 * stored. A future date is refused rather than clamped: it would sort the
	 * entry above everything the member has actually added, for as long as the
	 * date says.
	 *
	 * @param mixed $value Candidate timestamp.
	 * @return string UTC `Y-m-d H:i:s`, or '' to mean "use now".
	 */
	private static function sanitize_datetime( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value || ! preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $value ) ) {
			return '';
		}

		$timestamp = strtotime( $value . ' UTC' );

		if ( false === $timestamp || $timestamp > time() ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * A sort key, or the default when the value is not one of the four.
	 *
	 * @param mixed $sort Candidate sort key.
	 * @return string One of {@see GameLib_Library::SORTS}.
	 */
	public static function sanitize_sort( $sort ) {
		$sort = is_scalar( $sort ) ? sanitize_key( (string) $sort ) : '';

		return in_array( $sort, self::SORTS, true ) ? $sort : self::SORT_DEFAULT;
	}

	/**
	 * Make sure the shared store holds a record for this game (AC-011a).
	 *
	 * Exactly one case reaches IGDB from the foreground: a game the store has
	 * never seen. A library row without a game record is forbidden outright
	 * (Never Do #10), so there is nothing else that add could do — and the id
	 * being absent is by definition the first time anyone has added it.
	 *
	 * A row that exists but is past `GAMELIB_IGDB_CACHE_TTL` is deliberately
	 * *not* revalidated here (PB-5). It used to be, and that made the common
	 * case the expensive one: every row is over a day old within a day, so an
	 * ordinary add paid a blocking ~3s IGDB call (another ~3s with a cold token)
	 * on a click whose result was then discarded on failure anyway. AC-011(a)
	 * asks for the fetch on an *absent* row; AC-033(b) makes a stale row a
	 * normal row; AC-036 gives the hourly job the whole of freshness, and it
	 * finds this row through {@see GameLib_Game_Store::get_stale_ids()} without
	 * being told. Nothing schedules a per-game catch-up event either — a cron
	 * option fan-out is the wrong trade for a field that is at most a day old.
	 *
	 * @param int  $igdb_id IGDB game id.
	 * @param bool $hydrate Fetch from IGDB when the store holds no row at all.
	 * @return array|WP_Error The store row, or the reason it could not be had.
	 */
	private static function resolve_game( $igdb_id, $hydrate ) {
		$game = GameLib_Game_Store::get( $igdb_id );

		if ( is_array( $game ) ) {
			return $game;
		}

		if ( ! $hydrate ) {
			return new WP_Error(
				self::ERROR_UNKNOWN_GAME,
				__( 'That game is not in this site’s game data yet.', 'game-library' ),
				array( 'status' => 404 )
			);
		}

		return GameLib_Game_Store::refresh( $igdb_id );
	}

	/**
	 * Announce a game that has no published page yet (consumed by the CPT
	 * projection, ADR-003).
	 *
	 * The condition is "no *published* post", not merely "no post id": a page in
	 * the trash when a member adds the game has to be restored rather than
	 * duplicated (AC-034e), and the handler can only do that if it hears about
	 * the add. The in-flight creation sentinel reads as "no post" through
	 * {@see GameLib_Game_Store::live_post_id()}, so a concurrent add fires too
	 * and is refused by the conditional-UPDATE claim rather than by this check
	 * (AC-034a).
	 *
	 * A duplicate add fires it as well — the member's row is unchanged, but the
	 * game's page may still be missing.
	 *
	 * @param int $igdb_id IGDB game id.
	 * @param int $user_id Member who added the game.
	 * @return void
	 */
	private static function maybe_first_add( $igdb_id, $user_id ) {
		$post_id = GameLib_Game_Store::live_post_id( $igdb_id );

		if ( $post_id > 0 && 'publish' === get_post_status( $post_id ) ) {
			return;
		}

		/**
		 * Fires when a member adds a game that has no published game page.
		 *
		 * The CPT projection listens here to create the page on the first add by
		 * any member, or to restore it from the trash on a later one. Creation
		 * is guarded by an atomic claim on `gamelib_games.post_id`, so several
		 * concurrent adds of the same game still produce exactly one post
		 * (AC-034a).
		 *
		 * @param int $igdb_id IGDB game id.
		 * @param int $user_id Member who added the game.
		 */
		do_action( 'gamelib_first_add', $igdb_id, $user_id );
	}

	/**
	 * The shape {@see add()} returns.
	 *
	 * @param array $entry Library row (`igdb_id`, `status`, `added_at`).
	 * @param array $game  Store row for the game.
	 * @param bool  $added True when this call created the row.
	 * @return array{igdb_id:int,status:string,added:bool,duplicate:bool,added_at:string,game:array} Result.
	 */
	private static function added_result( array $entry, array $game, $added ) {
		return array(
			'igdb_id'   => isset( $entry['igdb_id'] ) ? (int) $entry['igdb_id'] : 0,
			'status'    => isset( $entry['status'] ) ? (string) $entry['status'] : '',
			'added'     => (bool) $added,
			'duplicate' => ! $added,
			'added_at'  => isset( $entry['added_at'] ) ? (string) $entry['added_at'] : '',
			'game'      => $game,
		);
	}

	/**
	 * Normalize the filter criteria shared by {@see query()} and the bulk paths.
	 *
	 * @param array $args Raw arguments.
	 * @return array{status:string,search:string,sort:string} Normalized criteria.
	 */
	private static function criteria( array $args ) {
		$status = isset( $args['status'] ) ? self::sanitize_status( $args['status'] ) : '';
		$search = isset( $args['search'] ) && is_scalar( $args['search'] )
			? trim( sanitize_text_field( (string) $args['search'] ) )
			: '';

		if ( function_exists( 'mb_substr' ) ) {
			$search = mb_substr( $search, 0, self::MAX_SEARCH_LENGTH );
		} else {
			$search = substr( $search, 0, self::MAX_SEARCH_LENGTH );
		}

		return array(
			'status' => ( '' === $status ) ? self::STATUS_ALL : $status,
			'search' => $search,
			'sort'   => self::sanitize_sort( isset( $args['sort'] ) ? $args['sort'] : self::SORT_DEFAULT ),
		);
	}

	/**
	 * The WHERE clause for a set of criteria, with its values collected for
	 * `$wpdb->prepare()`.
	 *
	 * Filter input never reaches the SQL string: the clause is built from
	 * literals and placeholders only, and every value — including the escaped
	 * LIKE term — is bound (Always Do #4).
	 *
	 * @param int    $user_id  Library owner.
	 * @param array  $criteria Normalized criteria.
	 * @param array  $params   Collected by reference, in placeholder order.
	 * @param string $not_status Optional. Exclude rows already at this status.
	 * @return string WHERE clause without the keyword.
	 */
	private static function where_clause( $user_id, array $criteria, array &$params, $not_status = '' ) {
		global $wpdb;

		$clauses  = array( 'l.user_id = %d' );
		$params[] = $user_id;

		if ( self::STATUS_ALL !== $criteria['status'] ) {
			$clauses[] = 'l.status = %s';
			$params[]  = $criteria['status'];
		}

		if ( '' !== $criteria['search'] ) {
			$clauses[] = 'g.name LIKE %s';
			$params[]  = '%' . $wpdb->esc_like( $criteria['search'] ) . '%';
		}

		if ( '' !== $not_status ) {
			$clauses[] = 'l.status != %s';
			$params[]  = $not_status;
		}

		return implode( ' AND ', $clauses );
	}

	/**
	 * The ORDER BY clause for a sort key (AC-017b).
	 *
	 * Composed from literals only — the key was whitelisted by
	 * {@see sanitize_sort()} and never becomes part of the SQL itself. Each
	 * order ends in the row id so a page boundary is stable when two entries
	 * share a title or a date.
	 *
	 * The two title orders name `g.name`, a column of the *joined* table, so
	 * they filesort: `WHERE l.user_id = %d` makes `gamelib_library` drive the
	 * join, and MySQL resolves an ORDER BY only from an index on the first
	 * non-constant table. That is a known limitation, not an oversight — no index
	 * on `gamelib_games.name` can change it. See
	 * principal/adr/032-title-sort-filesort-is-a-known-limitation.md (PB-1) for
	 * the `sort_title` denormalisation that would, and why it is not here.
	 *
	 * @param string $sort Whitelisted sort key.
	 * @return string ORDER BY clause without the keyword.
	 */
	private static function order_clause( $sort ) {
		switch ( $sort ) {
			case 'title_asc':
				return 'g.name ASC, l.id ASC';

			case 'title_desc':
				return 'g.name DESC, l.id DESC';

			case 'added_asc':
				return 'l.added_at ASC, l.id ASC';
		}

		return 'l.added_at DESC, l.id DESC';
	}

	/**
	 * Read one page of entries joined to their game rows.
	 *
	 * @param int   $user_id  Library owner.
	 * @param array $criteria Normalized criteria.
	 * @param int   $limit    Rows to read.
	 * @param int   $offset   Rows to skip.
	 * @return array[] Hydrated rows.
	 */
	private static function read_page( $user_id, array $criteria, $limit, $offset ) {
		global $wpdb;

		$params = array();
		$where  = self::where_clause( $user_id, $criteria, $params );
		$order  = self::order_clause( $criteria['sort'] );

		$library  = GameLib_Schema::table( self::TABLE );
		$games    = GameLib_Schema::table( GameLib_Game_Store::TABLE );
		$params[] = (int) $limit;
		$params[] = (int) $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables have no core API; the caller caches this page under the member's `lib_{user}` generation scope.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Both table names come from GameLib_Schema::table(), COLUMNS and the ORDER BY come from class constants/whitelists, and $where is built from literals holding %d/%s placeholders; every value is bound by prepare().
				'SELECT ' . self::COLUMNS . " FROM {$library} l INNER JOIN {$games} g ON g.igdb_id = l.igdb_id WHERE {$where} ORDER BY {$order} LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);

		if ( ! is_array( $results ) ) {
			return array();
		}

		$rows = array();

		foreach ( $results as $result ) {
			if ( is_array( $result ) ) {
				$rows[] = self::hydrate( $result );
			}
		}

		return $rows;
	}

	/**
	 * Count every entry matching a set of criteria (the pagination total).
	 *
	 * @param int   $user_id  Library owner.
	 * @param array $criteria Normalized criteria.
	 * @return int Matching entries.
	 */
	private static function read_total( $user_id, array $criteria ) {
		global $wpdb;

		$params = array();
		$where  = self::where_clause( $user_id, $criteria, $params );

		$library = GameLib_Schema::table( self::TABLE );
		$games   = GameLib_Schema::table( GameLib_Game_Store::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables have no core API; the caller caches this total alongside its page under the member's `lib_{user}` generation scope.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Both table names come from GameLib_Schema::table() and $where is built from literals holding %d/%s placeholders; every value is bound by prepare().
				"SELECT COUNT(*) FROM {$library} l INNER JOIN {$games} g ON g.igdb_id = l.igdb_id WHERE {$where}",
				$params
			)
		);
	}

	/**
	 * One batch of export rows, plus the cursor for the next one (AC-037 a,b).
	 *
	 * `l.id` is selected so the walk has somewhere to resume from, and dropped
	 * from the returned shape — it is a paging detail, not one of the four
	 * fields an export may carry.
	 *
	 * @param int $user_id  Library owner.
	 * @param int $limit    Rows to read.
	 * @param int $after_id Resume after this library row id; 0 starts at the top.
	 * @return array{rows:array<int,array{igdb_id:int,title:string,status:string,date_added:string}>,last_id:int}
	 *         The batch, and the id to pass as `$after_id` for the next one.
	 */
	private static function read_export_batch( $user_id, $limit, $after_id ) {
		global $wpdb;

		$library = GameLib_Schema::table( self::TABLE );
		$games   = GameLib_Schema::table( GameLib_Game_Store::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables have no core API; an export is a one-shot download of the caller's own rows, read in batches rather than cached (an object-cache copy of a whole library would be evicted before it were read twice).
		$results = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both table names come from GameLib_Schema::table(); every value is a bound placeholder.
				"SELECT l.id, l.igdb_id, l.status, l.added_at, g.name FROM {$library} l INNER JOIN {$games} g ON g.igdb_id = l.igdb_id WHERE l.user_id = %d AND l.id > %d ORDER BY l.id ASC LIMIT %d",
				$user_id,
				(int) $after_id,
				(int) $limit
			),
			ARRAY_A
		);

		$batch = array(
			'rows'    => array(),
			'last_id' => (int) $after_id,
		);

		if ( ! is_array( $results ) ) {
			return $batch;
		}

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}

			$added_at = isset( $result['added_at'] ) ? (string) $result['added_at'] : '';
			$added_ts = ( '' === $added_at ) ? false : strtotime( $added_at . ' UTC' );

			$batch['last_id'] = isset( $result['id'] ) ? (int) $result['id'] : $batch['last_id'];

			// Exactly the four AC-037 fields — no summary, no cover, nothing else.
			$batch['rows'][] = array(
				'igdb_id'    => isset( $result['igdb_id'] ) ? (int) $result['igdb_id'] : 0,
				'title'      => isset( $result['name'] ) ? (string) $result['name'] : '',
				'status'     => isset( $result['status'] ) ? (string) $result['status'] : '',
				'date_added' => ( false === $added_ts ) ? '' : gmdate( 'Y-m-d', $added_ts ),
			);
		}

		return $batch;
	}

	/**
	 * The ids of entries matching a set of criteria, for a bulk statement.
	 *
	 * @param int    $user_id    Library owner.
	 * @param array  $criteria   Normalized criteria.
	 * @param int    $limit      Ids to read.
	 * @param string $not_status Optional. Exclude rows already at this status.
	 * @return int[] IGDB ids.
	 */
	private static function select_ids( $user_id, array $criteria, $limit, $not_status = '' ) {
		global $wpdb;

		$params = array();
		$where  = self::where_clause( $user_id, $criteria, $params, $not_status );

		$library  = GameLib_Schema::table( self::TABLE );
		$games    = GameLib_Schema::table( GameLib_Game_Store::TABLE );
		$params[] = (int) $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Write-path read: the batch of ids the very next statement updates or deletes; caching rows about to change would be wrong.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Both table names come from GameLib_Schema::table() and $where is built from literals holding %d/%s placeholders; every value is bound by prepare().
				"SELECT l.igdb_id FROM {$library} l INNER JOIN {$games} g ON g.igdb_id = l.igdb_id WHERE {$where} ORDER BY l.id ASC LIMIT %d",
				$params
			)
		);

		return self::positive_ints( is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Move a known set of entries to one status.
	 *
	 * Scoped to the owner in the statement itself, so an id belonging to another
	 * member cannot be moved by passing it in a bulk request. Rows already at
	 * the target are excluded, which makes the affected-row count the same on
	 * MySQL and on Playground's SQLite driver.
	 *
	 * @param int    $user_id  Library owner.
	 * @param int[]  $igdb_ids Ids to move.
	 * @param string $status   Target status.
	 * @return int Entries moved.
	 */
	private static function update_ids( $user_id, array $igdb_ids, $status ) {
		$igdb_ids = self::positive_ints( $igdb_ids );

		if ( empty( $igdb_ids ) ) {
			return 0;
		}

		global $wpdb;

		$table   = GameLib_Schema::table( self::TABLE );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$updated = 0;

		foreach ( array_chunk( $igdb_ids, self::BULK_BATCH ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %d literals bound by prepare() below; $table comes from GameLib_Schema::table(). A write is never cached.
			$affected = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
					"UPDATE {$table} SET status = %s, updated_at = %s WHERE user_id = %d AND status != %s AND igdb_id IN ({$placeholders})",
					array_merge( array( $status, $now, $user_id, $status ), $chunk )
				)
			);

			if ( is_int( $affected ) && $affected > 0 ) {
				$updated += $affected;
			}
		}

		return $updated;
	}

	/**
	 * Delete a known set of entries.
	 *
	 * Scoped to the owner in the statement itself: an id belonging to another
	 * member cannot be deleted by passing it in a bulk request.
	 *
	 * @param int   $user_id  Library owner.
	 * @param int[] $igdb_ids Ids to delete.
	 * @return int Entries deleted.
	 */
	private static function delete_ids( $user_id, array $igdb_ids ) {
		$igdb_ids = self::positive_ints( $igdb_ids );

		if ( empty( $igdb_ids ) ) {
			return 0;
		}

		global $wpdb;

		$table   = GameLib_Schema::table( self::TABLE );
		$deleted = 0;

		foreach ( array_chunk( $igdb_ids, self::BULK_BATCH ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %d literals bound by prepare() below; $table comes from GameLib_Schema::table(). A write is never cached.
			$affected = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
					"DELETE FROM {$table} WHERE user_id = %d AND igdb_id IN ({$placeholders})",
					array_merge( array( $user_id ), $chunk )
				)
			);

			if ( is_int( $affected ) && $affected > 0 ) {
				$deleted += $affected;
			}
		}

		return $deleted;
	}

	/**
	 * Work out which entries a bulk request means (AC-019f).
	 *
	 * A request must say so explicitly: an empty id list is never read as "every
	 * game", because a mis-serialized selection would otherwise wipe a library.
	 *
	 * @param array $args Bulk arguments.
	 * @return array{mode:string,ids:int[],criteria:array}|WP_Error Selection, or
	 *         the reason it was refused.
	 */
	private static function selection( array $args ) {
		$wants_all = ! empty( $args['all'] );

		if ( ! $wants_all ) {
			$ids = ( isset( $args['ids'] ) && is_array( $args['ids'] ) ) ? self::positive_ints( $args['ids'] ) : array();

			if ( empty( $ids ) ) {
				return new WP_Error(
					self::ERROR_BULK_MODE,
					__( 'Select at least one game, or choose everything matching the current filter.', 'game-library' ),
					array( 'status' => 400 )
				);
			}

			if ( count( $ids ) > self::MAX_BULK_IDS ) {
				return new WP_Error(
					self::ERROR_BULK_LIMIT,
					sprintf(
						/* translators: %s: maximum number of games one bulk action may name. */
						_n(
							'Bulk actions can cover %s game at a time. Use “select all matching this filter” for more.',
							'Bulk actions can cover %s games at a time. Use “select all matching this filter” for more.',
							self::MAX_BULK_IDS,
							'game-library'
						),
						number_format_i18n( self::MAX_BULK_IDS )
					),
					array( 'status' => 400 )
				);
			}

			return array(
				'mode'     => 'ids',
				'ids'      => $ids,
				'criteria' => self::criteria( array() ),
			);
		}

		return array(
			'mode'     => 'filter',
			'ids'      => array(),
			'criteria' => self::criteria( $args ),
		);
	}

	/**
	 * Uncached single-entry lookup, for the write paths.
	 *
	 * Deliberately not {@see entry()}: the answer decides the statement on the
	 * next line, so it is read from the table rather than from an entry a
	 * concurrent request may have invalidated a moment ago.
	 *
	 * Impure by nature — that is the point. Two calls inside one {@see add()}
	 * can legitimately disagree, because between them another request may have
	 * inserted the very row whose absence the first call reported.
	 *
	 * @phpstan-impure
	 *
	 * @param int $user_id Library owner.
	 * @param int $igdb_id IGDB game id.
	 * @return array{igdb_id:int,status:string,added_at:string,updated_at:string}|null Entry, or null.
	 */
	private static function read_entry( $user_id, $igdb_id ) {
		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write-path existence lookup: the "select" half of the select-then-insert guarded by UNIQUE KEY user_game; caching a value the next statement invalidates would be wrong.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); both values are bound placeholders.
				"SELECT igdb_id, status, added_at, updated_at FROM {$table} WHERE user_id = %d AND igdb_id = %d",
				$user_id,
				$igdb_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'igdb_id'    => isset( $row['igdb_id'] ) ? (int) $row['igdb_id'] : 0,
			'status'     => isset( $row['status'] ) ? (string) $row['status'] : '',
			'added_at'   => isset( $row['added_at'] ) ? (string) $row['added_at'] : '',
			'updated_at' => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '',
		);
	}

	/**
	 * Turn one raw joined row into the shape callers consume.
	 *
	 * `$wpdb` returns every column as a string, and `post_id` carries the
	 * creation sentinel (-1) as well as real ids, so "no usable post" is
	 * normalized to 0 here rather than in every renderer (ADR-006).
	 *
	 * @param array $row Raw joined row.
	 * @return array{igdb_id:int,status:string,added_at:string,updated_at:string,name:string,slug:string,cover_image_id:string,post_id:int} Hydrated row.
	 */
	private static function hydrate( array $row ) {
		$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;

		return array(
			'igdb_id'        => isset( $row['igdb_id'] ) ? (int) $row['igdb_id'] : 0,
			'status'         => isset( $row['status'] ) ? (string) $row['status'] : '',
			'added_at'       => isset( $row['added_at'] ) ? (string) $row['added_at'] : '',
			'updated_at'     => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '',
			'name'           => isset( $row['name'] ) ? (string) $row['name'] : '',
			'slug'           => isset( $row['slug'] ) ? (string) $row['slug'] : '',
			'cover_image_id' => isset( $row['cover_image_id'] ) ? (string) $row['cover_image_id'] : '',
			'post_id'        => ( $post_id > 0 ) ? $post_id : 0,
		);
	}

	/**
	 * Add the two derived fields a card needs, outside the cached payload.
	 *
	 * Cover URLs and permalinks are composed per request rather than stored:
	 * neither is table data, and a cached permalink would outlive a permalink
	 * structure change. The posts of a whole page are primed in one call, so the
	 * loop performs no per-row query (WPP-05).
	 *
	 * @param array[] $rows Hydrated rows.
	 * @return array[] Rows with `cover_url` and `permalink` added.
	 */
	private static function decorate( array $rows ) {
		$post_ids = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['post_id'] ) && (int) $row['post_id'] > 0 ) {
				$post_ids[] = (int) $row['post_id'];
			}
		}

		if ( ! empty( $post_ids ) ) {
			_prime_post_caches( $post_ids, false, false );
		}

		$decorated = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$post_id   = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
			$permalink = ( $post_id > 0 ) ? get_permalink( $post_id ) : false;

			// A grid card paints a ~176px box: it asks for the rendition that
			// covers it, not the game page's hero (PF-1), and offers the larger
			// one beside it so a HiDPI screen can choose (CO-1).
			$image_id = isset( $row['cover_image_id'] ) ? $row['cover_image_id'] : '';

			$row['cover_url']    = GameLib_Game_Store::cover_url( $image_id, GameLib_Game_Store::CARD_COVER_SIZE );
			$row['cover_srcset'] = GameLib_Game_Store::card_cover_srcset( $image_id );
			$row['permalink']    = is_string( $permalink ) ? $permalink : '';

			$decorated[] = $row;
		}

		return $decorated;
	}

	/**
	 * The member's cache scope.
	 *
	 * @param int $user_id Library owner.
	 * @return string Generation scope name.
	 */
	private static function scope( $user_id ) {
		return GameLib_Cache::library_scope( $user_id );
	}

	/**
	 * A caller-supplied page size, clamped to the class's own ceiling (AC-018a).
	 *
	 * @param mixed $per_page Requested rows.
	 * @return int Rows this class will actually read.
	 */
	private static function clamp_per_page( $per_page ) {
		$per_page = absint( $per_page );

		if ( $per_page < 1 ) {
			$per_page = self::PAGE_SIZE;
		}

		return min( self::MAX_PAGE_SIZE, $per_page );
	}

	/**
	 * "Sign in to manage your library."
	 *
	 * @return WP_Error Refusal.
	 */
	private static function owner_error() {
		return new WP_Error(
			self::ERROR_OWNER,
			__( 'Sign in to manage your library.', 'game-library' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * "That game could not be identified."
	 *
	 * @return WP_Error Refusal.
	 */
	private static function game_error() {
		return new WP_Error(
			self::ERROR_GAME,
			__( 'That game could not be identified.', 'game-library' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * "Choose one of the four library statuses."
	 *
	 * @return WP_Error Refusal.
	 */
	private static function status_error() {
		return new WP_Error(
			self::ERROR_STATUS,
			__( 'Choose Playing, Finished, Backlog, or Wishlist.', 'game-library' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * "That game is not in your library."
	 *
	 * @return WP_Error Refusal.
	 */
	private static function missing_error() {
		return new WP_Error(
			self::ERROR_MISSING,
			__( 'That game is not in your library.', 'game-library' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * One id, validated as a positive integer.
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
	 * @return int[] Unique positive integers, input order preserved.
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
