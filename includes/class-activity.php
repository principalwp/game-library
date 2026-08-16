<?php
/**
 * The activity log and the followed-members feed: `gamelib_activity`.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The only class that reads or writes `gamelib_activity`.
 *
 * Four event types exist and no fifth can be recorded (AC-024 e–h). Two of them
 * are about one game (`game_added`, `status_changed`) and two are aggregates
 * (`games_imported`, `bulk_status_changed`) — and the aggregates *refuse* an
 * IGDB id, which is how Never Do #11 is enforced rather than merely documented:
 * an importer or a bulk action looping over its games cannot express a
 * per-game event through this API at all (DD-009, AC-044a).
 *
 * The feed (DD-007) is one indexed JOIN of `gamelib_activity` × `gamelib_follows`
 * with keyset pagination on `activity.id` — never OFFSET, never a materialized
 * fan-out, never a realtime channel (AC-025c). A member's own events cannot
 * appear in their own feed: the JOIN only yields events by members they follow,
 * self-follow is refused by {@see GameLib_Follows::follow()}, and the query
 * additionally excludes the viewer as actor so a row seeded straight into the
 * table cannot violate AC-024's last sentence either.
 *
 * Rendering data is produced here too ({@see render_items()}), for one reason:
 * the feed page, the "Load more" fragments, and a profile's recent-activity
 * list must read identically, and the `_n()` count strings (AC-NFR-008) belong
 * with the event vocabulary that defines them. Actor and game lookups are
 * hoisted out of the item loop — users through a single `cache_users()` call
 * and posts through a single prime — so a page of items costs a fixed number of
 * queries no matter how many items it holds (WPP-05).
 *
 * Reads are cached under the `activity` generation scope, which is bumped by
 * every event insert *and* by every follow-edge change, since both alter what a
 * feed query returns (AC-NFR-010a,b).
 */
final class GameLib_Activity {

	/**
	 * Schema suffix of the activity table.
	 *
	 * @var string
	 */
	const TABLE = 'activity';

	/**
	 * A member added a game to their library: "{member} added {game} ({status})".
	 *
	 * @var string
	 */
	const TYPE_GAME_ADDED = 'game_added';

	/**
	 * A member moved a game between statuses: "{member} moved {game} to {status}".
	 *
	 * @var string
	 */
	const TYPE_STATUS_CHANGED = 'status_changed';

	/**
	 * One import finished: "{member} imported {N} games". Exactly one per
	 * import, never one per game (AC-044a).
	 *
	 * @var string
	 */
	const TYPE_GAMES_IMPORTED = 'games_imported';

	/**
	 * One bulk status action: "{member} moved {N} games to {status}". Exactly
	 * one per bulk action (DD-009, AC-019e).
	 *
	 * @var string
	 */
	const TYPE_BULK_STATUS_CHANGED = 'bulk_status_changed';

	/**
	 * The whole event vocabulary — the write whitelist and the render switch.
	 *
	 * @var string[]
	 */
	const TYPES = array(
		self::TYPE_GAME_ADDED,
		self::TYPE_STATUS_CHANGED,
		self::TYPE_GAMES_IMPORTED,
		self::TYPE_BULK_STATUS_CHANGED,
	);

	/**
	 * The types that name one game and therefore carry an `igdb_id` and a game
	 * link (AC-024d).
	 *
	 * @var string[]
	 */
	const SINGLE_GAME_TYPES = array(
		self::TYPE_GAME_ADDED,
		self::TYPE_STATUS_CHANGED,
	);

	/**
	 * The four library statuses (§6 Data Model). Event meta carries them, so
	 * the whitelist is enforced here on the way in; `GameLib_Library` applies
	 * the same list to the library row itself.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'playing', 'finished', 'backlog', 'wishlist' );

	/**
	 * Feed page size (AC-025a).
	 *
	 * @var int
	 */
	const FEED_PAGE_SIZE = 20;

	/**
	 * Page size of a member's own recent-activity list on their profile
	 * (AC-031d).
	 *
	 * @var int
	 */
	const MEMBER_PAGE_SIZE = 10;

	/**
	 * Hard ceiling on any single page of events, whatever a caller asks for.
	 *
	 * @var int
	 */
	const MAX_PAGE_SIZE = 50;

	/**
	 * Rows deleted per statement by {@see purge_user()}. The activity table is
	 * the fastest-growing one in the plugin, so a purge walks it in batches
	 * rather than issuing one unbounded DELETE.
	 *
	 * @var int
	 */
	const PURGE_BATCH = 500;

	/**
	 * Columns of `gamelib_activity`, in schema order — the select list of every
	 * read here, aliased to the `a.` the feed JOIN uses.
	 *
	 * @var string
	 */
	const COLUMNS = 'a.id, a.user_id, a.type, a.igdb_id, a.meta, a.created_at';

	/**
	 * Error code: the event type is not one of the four.
	 *
	 * @var string
	 */
	const ERROR_TYPE = 'gamelib_activity_type';

	/**
	 * Error code: the actor id is missing or not a positive integer.
	 *
	 * @var string
	 */
	const ERROR_ACTOR = 'gamelib_activity_actor';

	/**
	 * Error code: a single-game event arrived without a game, or an aggregate
	 * event arrived with one (Never Do #11).
	 *
	 * @var string
	 */
	const ERROR_GAME = 'gamelib_activity_game';

	/**
	 * Error code: the meta payload does not match the shape its type requires.
	 *
	 * @var string
	 */
	const ERROR_META = 'gamelib_activity_meta';

	/**
	 * Error code: the database refused the insert.
	 *
	 * @var string
	 */
	const ERROR_FAILED = 'gamelib_activity_failed';

	/**
	 * Append one event to the log.
	 *
	 * Every argument is validated against the vocabulary before anything is
	 * written: an unknown type, a single-game event with no game, an aggregate
	 * event carrying a game, or a meta payload that does not match its type's
	 * shape are each refused with a `WP_Error` rather than stored as a row no
	 * renderer could turn into a sentence.
	 *
	 * Meta shapes (§6 Data Model):
	 *
	 * - `game_added`          — `{ to: status }`
	 * - `status_changed`      — `{ from: status, to: status }`
	 * - `games_imported`      — `{ count: int >= 1 }`
	 * - `bulk_status_changed` — `{ count: int >= 1, to: status }`
	 *
	 * A count below 1 is refused on purpose: an import that added nothing emits
	 * no event at all (AC-044c).
	 *
	 * @param int    $user_id Actor.
	 * @param string $type    One of {@see GameLib_Activity::TYPES}.
	 * @param int    $igdb_id Game the event is about; 0 for the aggregates.
	 * @param array  $meta    Payload for the type's shape.
	 * @return int|WP_Error New event id, or the reason it was refused.
	 */
	public static function record( $user_id, $type, $igdb_id = 0, array $meta = array() ) {
		$user_id = self::valid_id( $user_id );

		if ( $user_id < 1 ) {
			return new WP_Error(
				self::ERROR_ACTOR,
				__( 'An activity event needs the member it belongs to.', 'game-library' ),
				array( 'status' => 400 )
			);
		}

		$type = sanitize_key( (string) $type );

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error(
				self::ERROR_TYPE,
				__( 'That is not an activity event type this site records.', 'game-library' ),
				array( 'status' => 400 )
			);
		}

		$igdb_id   = self::valid_id( $igdb_id );
		$is_single = in_array( $type, self::SINGLE_GAME_TYPES, true );

		if ( $is_single && $igdb_id < 1 ) {
			return new WP_Error(
				self::ERROR_GAME,
				__( 'That activity event needs the game it is about.', 'game-library' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $is_single && $igdb_id > 0 ) {
			// Never Do #11: imports and bulk actions summarize, one event each.
			return new WP_Error(
				self::ERROR_GAME,
				__( 'Import and bulk events summarize a count of games and never name one.', 'game-library' ),
				array( 'status' => 400 )
			);
		}

		$payload = self::normalize_meta( $type, $meta );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->insert() prepares its own statement, and every cached feed read is invalidated by the generation bump below.
		$written = $wpdb->insert(
			GameLib_Schema::table( self::TABLE ),
			array(
				'user_id'    => $user_id,
				'type'       => $type,
				'igdb_id'    => $is_single ? $igdb_id : null,
				'meta'       => empty( $payload ) ? null : wp_json_encode( $payload ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		if ( false === $written ) {
			return new WP_Error(
				self::ERROR_FAILED,
				__( 'That activity event could not be saved.', 'game-library' ),
				array( 'status' => 500 )
			);
		}

		/*
		 * Two scopes, and deliberately not a third (PB-1).
		 *
		 * The actor's own list is a pure function of their own events, so it is
		 * bumped per member — nothing another member does may evict a profile's
		 * activity list. The shared feed generation is bumped too, because a new
		 * event does change the head page of every follower's feed and AC-024
		 * expects them to see it. What is *not* bumped is
		 * `SCOPE_ACTIVITY_PAGES`: a keyset page below a fixed cursor holds only
		 * events older than this one and cannot have changed.
		 */
		GameLib_Cache::bump_many(
			array(
				GameLib_Cache::activity_scope( $user_id ),
				GameLib_Cache::SCOPE_ACTIVITY,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * One page of the viewer's feed: events by the members they follow, newest
	 * first (AC-024, AC-025).
	 *
	 * Pagination is keyset, never OFFSET: pass the id of the last item already
	 * shown as `before` and the next page starts strictly below it. A page
	 * shorter than `limit` is the end of the feed — there is no count query and
	 * no total.
	 *
	 * @param int   $viewer_id Member whose feed this is.
	 * @param array $args      Optional. `before` (int) keyset cursor, 0 for the
	 *                         newest page; `limit` (int) page size, clamped to
	 *                         {@see MAX_PAGE_SIZE}.
	 * @return array[] Event rows (see {@see hydrate()}), newest first.
	 */
	public static function feed( $viewer_id, array $args = array() ) {
		$viewer_id = self::valid_id( $viewer_id );

		if ( $viewer_id < 1 ) {
			return array();
		}

		$before = isset( $args['before'] ) ? self::valid_id( $args['before'] ) : 0;
		$limit  = self::clamp_limit( isset( $args['limit'] ) ? $args['limit'] : self::FEED_PAGE_SIZE );

		/*
		 * The head page and the pages below it live in different scopes (PB-1).
		 * The head page changes whenever a followed member records anything, so
		 * it is keyed in the generation every event bumps; a page below a fixed
		 * cursor contains only events older than that cursor and can change only
		 * through a follow edge or a purge, which bump the archive scope
		 * instead. Keying both alike meant a member paging back through a feed
		 * re-ran the plugin's most expensive query for every page, every time.
		 */
		$scope = ( $before > 0 ) ? GameLib_Cache::SCOPE_ACTIVITY_PAGES : GameLib_Cache::SCOPE_ACTIVITY;

		$found = false;
		$key   = 'feed:' . $viewer_id . ':' . $before . ':' . $limit;
		$rows  = GameLib_Cache::get( $scope, $key, $found );

		if ( $found && is_array( $rows ) ) {
			return $rows;
		}

		global $wpdb;

		$activity = GameLib_Schema::table( self::TABLE );
		$follows  = GameLib_Schema::table( GameLib_Follows::TABLE );

		/*
		 * `a.user_id != %d` is belt and braces on top of the JOIN shape: a
		 * self-follow is already impossible through GameLib_Follows, and this
		 * keeps AC-024's "the member's own events do not appear in their own
		 * feed" true even for an edge written straight into the table by a
		 * fixture or a migration.
		 */
		$cursor_sql = ( $before > 0 ) ? ' AND a.id < %d' : '';

		$params = array( $viewer_id, $viewer_id );

		if ( $before > 0 ) {
			$params[] = $before;
		}

		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables have no core API; the result is cached below through GameLib_Cache.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Both table names come from GameLib_Schema::table(), COLUMNS is a class constant, and $cursor_sql is one of two literals holding a %d placeholder; every value is bound by prepare().
				'SELECT ' . self::COLUMNS . " FROM {$activity} a INNER JOIN {$follows} f ON f.followed_id = a.user_id WHERE f.follower_id = %d AND a.user_id != %d{$cursor_sql} ORDER BY a.id DESC LIMIT %d",
				$params
			),
			ARRAY_A
		);

		$rows = self::hydrate_rows( $results );

		GameLib_Cache::set( $scope, $key, $rows );

		return $rows;
	}

	/**
	 * One page of a single member's own events, newest first (AC-031d).
	 *
	 * Same keyset contract as {@see feed()}; the default page is the ten events
	 * a profile shows.
	 *
	 * `cache => false` is for a *one-shot walk* — the privacy exporter reading a
	 * member's whole history once, and nothing else (PB-7). A page of a walk is
	 * read once and never asked for again, so writing each one into the object
	 * cache under a 15-minute TTL fills the shared pool with entries no request
	 * will ever hit: a member with 50,000 events would push ~1,000 of them in,
	 * evicting live feed and library entries under LRU. Reads still go *through*
	 * the cache — a page a live surface happens to have primed is free either
	 * way; only the write back is skipped.
	 *
	 * @param int   $user_id Member whose events these are.
	 * @param array $args    Optional. `before` (int) keyset cursor; `limit` (int)
	 *                       page size, clamped to {@see MAX_PAGE_SIZE}; `cache`
	 *                       (bool, default true) — false skips the cache write.
	 * @return array[] Event rows (see {@see hydrate()}), newest first.
	 */
	public static function for_member( $user_id, array $args = array() ) {
		$user_id = self::valid_id( $user_id );

		if ( $user_id < 1 ) {
			return array();
		}

		$before = isset( $args['before'] ) ? self::valid_id( $args['before'] ) : 0;
		$limit  = self::clamp_limit( isset( $args['limit'] ) ? $args['limit'] : self::MEMBER_PAGE_SIZE );
		$store  = ! isset( $args['cache'] ) || (bool) $args['cache'];

		// The member's own scope, not the shared feed generation (PB-1): this
		// list is a pure function of this member's events, so nothing anyone
		// else does may evict it.
		$scope = GameLib_Cache::activity_scope( $user_id );

		$found = false;
		$key   = 'member:' . $user_id . ':' . $before . ':' . $limit;
		$rows  = GameLib_Cache::get( $scope, $key, $found );

		if ( $found && is_array( $rows ) ) {
			return $rows;
		}

		global $wpdb;

		$activity   = GameLib_Schema::table( self::TABLE );
		$cursor_sql = ( $before > 0 ) ? ' AND a.id < %d' : '';

		$params = array( $user_id );

		if ( $before > 0 ) {
			$params[] = $before;
		}

		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table has no core API; the result is cached below through GameLib_Cache.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $activity is GameLib_Schema::table(), COLUMNS is a class constant, and $cursor_sql is one of two literals holding a %d placeholder; every value is bound by prepare().
				'SELECT ' . self::COLUMNS . " FROM {$activity} a WHERE a.user_id = %d{$cursor_sql} ORDER BY a.id DESC LIMIT %d",
				$params
			),
			ARRAY_A
		);

		$rows = self::hydrate_rows( $results );

		if ( $store ) {
			GameLib_Cache::set( $scope, $key, $rows );
		}

		return $rows;
	}

	/**
	 * The keyset cursor for the page after these rows (AC-025b).
	 *
	 * Derived from the *rows*, not from the rendered items: an item a renderer
	 * dropped must still advance the cursor, or "Load more" would re-request
	 * the page it just showed.
	 *
	 * @param array[] $rows Rows from {@see feed()} or {@see for_member()}.
	 * @return int Id to pass as `before`, or 0 when there is nothing to page past.
	 */
	public static function next_cursor( array $rows ) {
		$last = end( $rows );

		return ( is_array( $last ) && isset( $last['id'] ) ) ? self::valid_id( $last['id'] ) : 0;
	}

	/**
	 * Turn event rows into everything a feed item needs to render (AC-024 a–d).
	 *
	 * Actors and games are resolved *before* the item loop — one
	 * `cache_users()` call for every actor on the page and one prime for every
	 * game post — so the loop itself performs no lookups at all. A per-item
	 * `get_userdata()` would be an N+1 on a cold cache (WPP-05).
	 *
	 * An event whose actor or game can no longer be resolved is dropped rather
	 * than rendered with a placeholder: without a display name or a title it
	 * cannot satisfy AC-024(a)/(d), and neither is reachable in normal
	 * operation — a deleted member's events are purged with them (AC-052c) and
	 * nothing in the plugin deletes a game row.
	 *
	 * Each item carries: `id`, `type`, `actor_id`, `actor_name`, `actor_url`,
	 * `igdb_id`, `game_name`, `game_url`, `sentence`, `created_at`. The
	 * sentence is an escaped HTML fragment holding the actor link, the game
	 * link where the type has one, and the `_n()` count string.
	 *
	 * @param array[] $rows Rows from {@see feed()} or {@see for_member()}.
	 * @return array[] Render-ready items, input order preserved.
	 */
	public static function render_items( array $rows ) {
		$rows = array_values( array_filter( $rows, 'is_array' ) );

		if ( empty( $rows ) ) {
			return array();
		}

		$actors = self::hydrate_actors( $rows );
		$games  = self::hydrate_games( $rows );
		$items  = array();

		foreach ( $rows as $row ) {
			$actor_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;

			if ( ! isset( $actors[ $actor_id ] ) ) {
				continue;
			}

			$igdb_id = isset( $row['igdb_id'] ) ? (int) $row['igdb_id'] : 0;
			$game    = isset( $games[ $igdb_id ] ) ? $games[ $igdb_id ] : null;

			if ( $igdb_id > 0 && null === $game ) {
				continue;
			}

			$sentence = self::sentence(
				isset( $row['type'] ) ? (string) $row['type'] : '',
				isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : array(),
				self::link( $actors[ $actor_id ]['name'], $actors[ $actor_id ]['url'], 'gamelib-feed__actor-link' ),
				null === $game ? '' : self::link( $game['name'], $game['url'], 'gamelib-feed__game-link' )
			);

			if ( '' === $sentence ) {
				continue;
			}

			$items[] = array(
				'id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'type'       => isset( $row['type'] ) ? (string) $row['type'] : '',
				'actor_id'   => $actor_id,
				'actor_name' => $actors[ $actor_id ]['name'],
				'actor_url'  => $actors[ $actor_id ]['url'],
				'igdb_id'    => $igdb_id,
				'game_name'  => null === $game ? '' : $game['name'],
				'game_url'   => null === $game ? '' : $game['url'],
				'sentence'   => $sentence,
				'created_at' => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			);
		}

		return $items;
	}

	/**
	 * Delete every event a member authored (AC-052b,c,e).
	 *
	 * Walked in batches because this is the plugin's fastest-growing table: one
	 * unbounded DELETE against a member with years of history is the kind of
	 * statement that holds locks for the whole request.
	 *
	 * The walk is bounded per call (PB-3). A member with a five-figure history
	 * is more rows than one synchronous request should promise to finish, and a
	 * killed request used to leave committed deletes behind a cache that had
	 * never been bumped. `limit` caps the rows one call removes and `remaining`
	 * tells the caller — {@see GameLib_Privacy::erase()}, which core re-invokes
	 * page by page — that there is more to do.
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write-path read: the batch of ids the next statement deletes; caching rows about to be removed would be wrong.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); both values are bound placeholders.
					"SELECT id FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d",
					$user_id,
					self::PURGE_BATCH
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

			/*
			 * Inside the loop, once per batch (PB-3): a request killed between
			 * batches must never leave committed deletes behind a cache that
			 * still serves them.
			 *
			 * The member's own scope only (PB-2) — their profile list is the one
			 * a partial purge leaves visibly wrong. The two site-wide generations
			 * that also have to move (every follower's head page, every
			 * already-paged deep page — AC-052e) are bumped once at the end of
			 * the call: they are one counter each for the whole site, so the set
			 * of readers invalidated is identical either way, while a bulk
			 * deletion of fifty members was discarding every cached feed on the
			 * site hundreds of times.
			 */
			GameLib_Cache::bump( GameLib_Cache::activity_scope( $user_id ) );
		} while ( count( $ids ) === self::PURGE_BATCH );

		if ( $deleted > 0 ) {
			GameLib_Cache::bump_many(
				array(
					GameLib_Cache::SCOPE_ACTIVITY,
					GameLib_Cache::SCOPE_ACTIVITY_PAGES,
				)
			);
		}

		$result['deleted'] = $deleted;

		return $result;
	}

	/**
	 * Validate and normalize an event's meta against its type's shape.
	 *
	 * @param string $type Event type, already whitelisted.
	 * @param array  $meta Caller-supplied payload.
	 * @return array|WP_Error Normalized payload, or the reason it was refused.
	 */
	private static function normalize_meta( $type, array $meta ) {
		$to    = isset( $meta['to'] ) ? sanitize_key( (string) $meta['to'] ) : '';
		$from  = isset( $meta['from'] ) ? sanitize_key( (string) $meta['from'] ) : '';
		$count = isset( $meta['count'] ) && is_numeric( $meta['count'] ) ? (int) $meta['count'] : 0;

		$status_error = new WP_Error(
			self::ERROR_META,
			__( 'That activity event needs a valid library status.', 'game-library' ),
			array( 'status' => 400 )
		);

		$count_error = new WP_Error(
			self::ERROR_META,
			__( 'That activity event needs a count of at least one game.', 'game-library' ),
			array( 'status' => 400 )
		);

		switch ( $type ) {
			case self::TYPE_GAME_ADDED:
				// No `from`: the game had no status before it was added.
				return self::is_status( $to ) ? array( 'to' => $to ) : $status_error;

			case self::TYPE_STATUS_CHANGED:
				return ( self::is_status( $from ) && self::is_status( $to ) )
					? array(
						'from' => $from,
						'to'   => $to,
					)
					: $status_error;

			case self::TYPE_GAMES_IMPORTED:
				// AC-044(c): an import that added nothing emits no event.
				return ( $count > 0 ) ? array( 'count' => $count ) : $count_error;

			case self::TYPE_BULK_STATUS_CHANGED:
				if ( $count < 1 ) {
					return $count_error;
				}

				return self::is_status( $to )
					? array(
						'count' => $count,
						'to'    => $to,
					)
					: $status_error;
		}

		return new WP_Error(
			self::ERROR_TYPE,
			__( 'That is not an activity event type this site records.', 'game-library' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * The event vocabulary as sentences (AC-024 e–h).
	 *
	 * Every count string goes through `_n()` (AC-NFR-008), and the two links
	 * arrive already escaped, so the result is a fragment the feed-item part
	 * can hand to `wp_kses()` unchanged.
	 *
	 * @param string $type       Event type.
	 * @param array  $meta       Normalized meta payload.
	 * @param string $actor_link Escaped actor link (or plain escaped name).
	 * @param string $game_link  Escaped game link (or plain escaped title); ''
	 *                           for the aggregate types.
	 * @return string Sentence markup, or '' when the row cannot make one.
	 */
	private static function sentence( $type, array $meta, $actor_link, $game_link ) {
		$status = isset( $meta['to'] ) ? esc_html( self::status_label( $meta['to'] ) ) : '';
		$count  = isset( $meta['count'] ) ? (int) $meta['count'] : 0;

		switch ( $type ) {
			case self::TYPE_GAME_ADDED:
				if ( '' === $game_link || '' === $status ) {
					return '';
				}

				return sprintf(
					/* translators: 1: member's display name, 2: game title, 3: library status such as Playing. */
					__( '%1$s added %2$s (%3$s)', 'game-library' ),
					$actor_link,
					$game_link,
					$status
				);

			case self::TYPE_STATUS_CHANGED:
				if ( '' === $game_link || '' === $status ) {
					return '';
				}

				return sprintf(
					/* translators: 1: member's display name, 2: game title, 3: library status such as Playing. */
					__( '%1$s moved %2$s to %3$s', 'game-library' ),
					$actor_link,
					$game_link,
					$status
				);

			case self::TYPE_GAMES_IMPORTED:
				if ( $count < 1 ) {
					return '';
				}

				return sprintf(
					/* translators: 1: member's display name, 2: number of games imported. */
					_n( '%1$s imported %2$s game', '%1$s imported %2$s games', $count, 'game-library' ),
					$actor_link,
					esc_html( number_format_i18n( $count ) )
				);

			case self::TYPE_BULK_STATUS_CHANGED:
				if ( $count < 1 || '' === $status ) {
					return '';
				}

				return sprintf(
					/* translators: 1: member's display name, 2: number of games moved, 3: library status such as Playing. */
					_n( '%1$s moved %2$s game to %3$s', '%1$s moved %2$s games to %3$s', $count, 'game-library' ),
					$actor_link,
					esc_html( number_format_i18n( $count ) ),
					$status
				);
		}

		return '';
	}

	/**
	 * Display label for a library status.
	 *
	 * The same labels the game card uses, so one status reads identically on a
	 * card and in a sentence. A value from outside the whitelist — only
	 * reachable from a row written by an older version — falls back to its own
	 * key rather than rendering an empty pair of brackets.
	 *
	 * @param string $status Status key.
	 * @return string Translated label.
	 */
	private static function status_label( $status ) {
		$status = sanitize_key( (string) $status );

		$labels = array(
			'playing'  => _x( 'Playing', 'library status', 'game-library' ),
			'finished' => _x( 'Finished', 'library status', 'game-library' ),
			'backlog'  => _x( 'Backlog', 'library status', 'game-library' ),
			'wishlist' => _x( 'Wishlist', 'library status', 'game-library' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Is this one of the four library statuses?
	 *
	 * @param string $status Candidate status.
	 * @return bool True when the value is on the whitelist.
	 */
	private static function is_status( $status ) {
		return in_array( $status, self::STATUSES, true );
	}

	/**
	 * An escaped anchor, or the escaped text alone when there is no URL.
	 *
	 * @param string $text  Link text (raw).
	 * @param string $url   Target URL (raw); '' renders unlinked.
	 * @param string $class CSS class for the anchor.
	 * @return string Escaped markup.
	 */
	private static function link( $text, $url, $class ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return '';
		}

		if ( '' === (string) $url ) {
			return esc_html( $text );
		}

		return sprintf(
			'<a class="%1$s" href="%2$s">%3$s</a>',
			esc_attr( $class ),
			esc_url( $url ),
			esc_html( $text )
		);
	}

	/**
	 * Name and profile URL for every actor on a page of rows.
	 *
	 * One `cache_users()` call primes the whole page — the WPP-05 requirement
	 * behind this method's existence — after which each lookup is served from
	 * the object cache.
	 *
	 * @param array[] $rows Event rows.
	 * @return array<int, array{name:string, url:string}> Keyed by user id;
	 *                                                    unresolvable users absent.
	 */
	private static function hydrate_actors( array $rows ) {
		$ids = array();

		foreach ( $rows as $row ) {
			$id = isset( $row['user_id'] ) ? self::valid_id( $row['user_id'] ) : 0;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		$ids = array_values( array_unique( $ids ) );

		if ( empty( $ids ) ) {
			return array();
		}

		cache_users( $ids );

		$actors = array();

		foreach ( $ids as $id ) {
			$user = get_userdata( $id );

			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$actors[ $id ] = array(
				'name' => (string) $user->display_name,
				'url'  => GameLib_Visibility::profile_url( $id ),
			);
		}

		return $actors;
	}

	/**
	 * Title and public URL for every game named on a page of rows.
	 *
	 * Rows come from the shared store, whose per-row entries are cached under
	 * the `games` generation; the game posts they point at are primed in one
	 * call so `get_permalink()` never queries inside the render loop
	 * (AC-031g's zero-outbound-HTTP render is a store read either way).
	 *
	 * @param array[] $rows Event rows.
	 * @return array<int, array{name:string, url:string}> Keyed by IGDB id;
	 *                                                    unknown games absent.
	 */
	private static function hydrate_games( array $rows ) {
		$ids = array();

		foreach ( $rows as $row ) {
			$id = isset( $row['igdb_id'] ) ? self::valid_id( $row['igdb_id'] ) : 0;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		$ids = array_values( array_unique( $ids ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$games    = array();
		$post_ids = array();

		// One batched store read for the whole page — this runs on the feed
		// render path, where a loop of single reads is one cache round trip per
		// distinct game (PB-2).
		$rows_by_id = GameLib_Game_Store::get_many( $ids );

		foreach ( $ids as $id ) {
			$game = isset( $rows_by_id[ $id ] ) ? $rows_by_id[ $id ] : null;

			if ( ! is_array( $game ) || '' === $game['name'] ) {
				continue;
			}

			$post_id = ( $game['post_id'] > 0 ) ? (int) $game['post_id'] : 0;

			if ( $post_id > 0 ) {
				$post_ids[] = $post_id;
			}

			$games[ $id ] = array(
				'name'    => $game['name'],
				'url'     => '',
				'post_id' => $post_id,
			);
		}

		if ( ! empty( $post_ids ) ) {
			_prime_post_caches( $post_ids, false, false );
		}

		foreach ( $games as $id => $game ) {
			// A game nobody has added yet has no post, and therefore no link:
			// AC-024(d) asks for a game link "where applicable".
			$permalink = ( $game['post_id'] > 0 ) ? get_permalink( $game['post_id'] ) : false;

			$games[ $id ] = array(
				'name' => $game['name'],
				'url'  => is_string( $permalink ) ? $permalink : '',
			);
		}

		return $games;
	}

	/**
	 * Normalize a result set into the row shape the rest of the class promises.
	 *
	 * @param mixed $results Raw `$wpdb` result set.
	 * @return array[] Hydrated rows.
	 */
	private static function hydrate_rows( $results ) {
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
	 * Turn one raw database row into the shape callers consume.
	 *
	 * `$wpdb` returns every column as a string and `meta` is JSON; decoding it
	 * once here is what lets a renderer read `$row['meta']['count']` as an int.
	 *
	 * @param array $row Raw row from `gamelib_activity`.
	 * @return array{id:int,user_id:int,type:string,igdb_id:int,meta:array,created_at:string} Hydrated row.
	 */
	private static function hydrate( array $row ) {
		return array(
			'id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'user_id'    => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'type'       => isset( $row['type'] ) ? (string) $row['type'] : '',
			'igdb_id'    => isset( $row['igdb_id'] ) ? (int) $row['igdb_id'] : 0,
			'meta'       => self::decode_meta( isset( $row['meta'] ) ? $row['meta'] : '' ),
			'created_at' => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
		);
	}

	/**
	 * Decode a stored meta payload.
	 *
	 * Anything unreadable decodes to an empty payload, which the renderer then
	 * drops — a truncated column cannot become a half-written sentence.
	 *
	 * @param mixed $json Stored JSON string.
	 * @return array Decoded payload.
	 */
	private static function decode_meta( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * A caller-supplied page size, clamped to the class's own ceiling.
	 *
	 * @param mixed $limit Requested rows.
	 * @return int Rows this class will actually read.
	 */
	private static function clamp_limit( $limit ) {
		$limit = absint( $limit );

		return max( 1, min( self::MAX_PAGE_SIZE, $limit ) );
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
