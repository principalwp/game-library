<?php
/**
 * The follow graph: `gamelib_follows`.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The only class that reads or writes `gamelib_follows`.
 *
 * Following is **open**: an edge is created the moment a member asks for it,
 * with no approval step and no notification anywhere in the plugin (AC-022a).
 * The one gate on the write is that the target user exists — not their
 * visibility, not whether they already follow back (DD-008, human override at
 * the spec checkpoint 2026-08-12). Under the Q-SPEC-1 auth-only visibility
 * model there is no members-only-to-nonfollower state between logged-in
 * members for this class to guard against, so `GameLib_Visibility` is
 * deliberately never consulted here.
 *
 * Both writes are idempotent (AC-023b). `follow()` is a select-then-insert
 * under the composite primary key — the key is what makes a duplicate edge
 * impossible, and a refused insert is read as "somebody else created the same
 * edge first", never as an error the member should see. `unfollow()` deleting
 * nothing is a success for the same reason.
 *
 * Every read is cached under the per-member `follows_{user}` generation scope,
 * and every write bumps *both* endpoints' scopes plus `activity`: a new edge
 * changes the follower's following list, the target's follower list, and the
 * follower's feed contents, so all three have to be invalidated in the same
 * request the row is written (AC-NFR-010b).
 */
final class GameLib_Follows {

	/**
	 * Schema suffix of the follow-edge table.
	 *
	 * @var string
	 */
	const TABLE = 'follows';

	/**
	 * Default size of one edge list — the "up to 50 each" the admin override
	 * screen shows (AC-007c).
	 *
	 * @var int
	 */
	const LIST_LIMIT = 50;

	/**
	 * Hard ceiling on a single list read, whatever a caller asks for. There is
	 * no unbounded edge query in this plugin (Never Do #8).
	 *
	 * @var int
	 */
	const MAX_LIST_LIMIT = 200;

	/**
	 * Edges {@see purge_user()} reads and deletes per statement.
	 *
	 * The counterpart read used to be the plugin's only SELECT with no LIMIT
	 * (PB-3): a member followed by ten thousand people produced one unbounded
	 * result set and then ten thousand `wp_cache_incr` round trips inside a
	 * synchronous request.
	 *
	 * @var int
	 */
	const PURGE_BATCH = 500;

	/**
	 * Counterpart follow scopes one purge call will bump individually.
	 *
	 * Each bump is a network round trip on VIP, so beyond this many counterparts
	 * the purge stops naming them one by one and lets their cached lists and
	 * counts age out under the 15-minute floor instead (PB-3). The feed
	 * generations are always bumped, so nobody's *feed* is stale either way —
	 * only a follower count on a profile, for at most one TTL.
	 *
	 * @var int
	 */
	const PURGE_SCOPE_CAP = 100;

	/**
	 * Error code: a member tried to follow themselves (AC-023a). Maps to 400 —
	 * a real user id, an impossible edge.
	 *
	 * @var string
	 */
	const ERROR_SELF = 'gamelib_follow_self';

	/**
	 * Error code: the target user id does not exist. The *only* condition that
	 * produces a 404 from this class (AC-023c).
	 *
	 * @var string
	 */
	const ERROR_NO_MEMBER = 'gamelib_follow_no_member';

	/**
	 * Error code: the acting user id is missing or does not exist. A logged-out
	 * requester never reaches this — the REST layer's capability/nonce check
	 * rejects them at 401 first (AC-NFR-001g).
	 *
	 * @var string
	 */
	const ERROR_NO_ACTOR = 'gamelib_follow_no_actor';

	/**
	 * Error code: the database refused the write.
	 *
	 * @var string
	 */
	const ERROR_FAILED = 'gamelib_follow_failed';

	/**
	 * Create a follow edge, or confirm the one that already exists.
	 *
	 * Idempotent by contract (AC-023b): following someone twice is a success
	 * with one row, not a duplicate and not an error. The existence check is a
	 * write-path lookup and is deliberately uncached — its answer decides the
	 * very next statement, and the primary key is the real guarantee behind it.
	 *
	 * @param int $follower_id Member doing the following.
	 * @param int $followed_id Member being followed.
	 * @return true|WP_Error True when the edge exists on return.
	 */
	public static function follow( $follower_id, $followed_id ) {
		$pair = self::validate_pair( $follower_id, $followed_id );

		if ( is_wp_error( $pair ) ) {
			return $pair;
		}

		list( $follower_id, $followed_id ) = $pair;

		if ( self::edge_exists( $follower_id, $followed_id ) ) {
			return true;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->insert() prepares its own statement, and every cached read of this edge is invalidated by the generation bumps below.
		$written = $wpdb->insert(
			GameLib_Schema::table( self::TABLE ),
			array(
				'follower_id' => $follower_id,
				'followed_id' => $followed_id,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s' )
		);

		if ( false === $written ) {
			/*
			 * Two requests can reach the insert for the same edge at once — a
			 * double-clicked toggle is exactly that. The composite primary key
			 * refuses the second, which is the idempotent outcome AC-023(b)
			 * asks for, so the edge existing now is a success.
			 */
			if ( ! self::edge_exists( $follower_id, $followed_id ) ) {
				return new WP_Error(
					self::ERROR_FAILED,
					__( 'That follow could not be saved. Please try again.', 'game-library' ),
					array( 'status' => 500 )
				);
			}
		}

		self::invalidate( $follower_id, $followed_id );

		return true;
	}

	/**
	 * Remove a follow edge, or confirm that there is none.
	 *
	 * @param int $follower_id Member doing the unfollowing.
	 * @param int $followed_id Member being unfollowed.
	 * @return true|WP_Error True when no edge exists on return.
	 */
	public static function unfollow( $follower_id, $followed_id ) {
		$pair = self::validate_pair( $follower_id, $followed_id );

		if ( is_wp_error( $pair ) ) {
			return $pair;
		}

		list( $follower_id, $followed_id ) = $pair;

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->delete() prepares its own statement, and every cached read of this edge is invalidated by the generation bumps below.
		$deleted = $wpdb->delete(
			GameLib_Schema::table( self::TABLE ),
			array(
				'follower_id' => $follower_id,
				'followed_id' => $followed_id,
			),
			array( '%d', '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error(
				self::ERROR_FAILED,
				__( 'That unfollow could not be saved. Please try again.', 'game-library' ),
				array( 'status' => 500 )
			);
		}

		if ( $deleted > 0 ) {
			// Nothing deleted means nothing changed: a repeat unfollow leaves
			// every cached list exactly as valid as it was (AC-023b).
			self::invalidate( $follower_id, $followed_id );
		}

		return true;
	}

	/**
	 * Does this member follow that one?
	 *
	 * The read behind every follow toggle's initial state, so it is cached
	 * under the follower's scope; both endpoints' scopes are bumped by any edge
	 * write, so the answer can never survive its own edge (AC-NFR-010b).
	 *
	 * @param int $follower_id Member doing the following.
	 * @param int $followed_id Member being followed.
	 * @return bool True when the edge exists.
	 */
	public static function is_following( $follower_id, $followed_id ) {
		$follower_id = self::valid_id( $follower_id );
		$followed_id = self::valid_id( $followed_id );

		if ( $follower_id < 1 || $followed_id < 1 ) {
			return false;
		}

		return (bool) self::cached(
			$follower_id,
			'edge:' . $followed_id,
			static function () use ( $follower_id, $followed_id ) {
				return self::edge_exists( $follower_id, $followed_id );
			}
		);
	}

	/**
	 * How many members follow this one (AC-031b).
	 *
	 * @param int $user_id Member being counted.
	 * @return int Follower count.
	 */
	public static function follower_count( $user_id ) {
		$user_id = self::valid_id( $user_id );

		if ( $user_id < 1 ) {
			return 0;
		}

		return (int) self::cached(
			$user_id,
			'follower_count',
			static function () use ( $user_id ) {
				global $wpdb;

				$table = GameLib_Schema::table( self::TABLE );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; the caller caches this value under the member's `follows_{user}` generation scope.
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); the one value is a bound placeholder.
						"SELECT COUNT(*) FROM {$table} WHERE followed_id = %d",
						$user_id
					)
				);
			}
		);
	}

	/**
	 * How many members this one follows (AC-031b).
	 *
	 * @param int $user_id Member being counted.
	 * @return int Following count.
	 */
	public static function following_count( $user_id ) {
		$user_id = self::valid_id( $user_id );

		if ( $user_id < 1 ) {
			return 0;
		}

		return (int) self::cached(
			$user_id,
			'following_count',
			static function () use ( $user_id ) {
				global $wpdb;

				$table = GameLib_Schema::table( self::TABLE );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; the caller caches this value under the member's `follows_{user}` generation scope.
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); the one value is a bound placeholder.
						"SELECT COUNT(*) FROM {$table} WHERE follower_id = %d",
						$user_id
					)
				);
			}
		);
	}

	/**
	 * The members who follow this one.
	 *
	 * @param int   $user_id Member being followed.
	 * @param int   $limit   Optional. Rows to return; clamped to {@see MAX_LIST_LIMIT}.
	 * @param array $args    Optional. `offset` (int), `after_id` (int), `cache`
	 *                       (bool, default true) — see {@see list_ids()}.
	 * @return int[] Follower user ids.
	 */
	public static function followers( $user_id, $limit = self::LIST_LIMIT, array $args = array() ) {
		return self::list_ids( 'followers', $user_id, $limit, $args );
	}

	/**
	 * The members this one follows.
	 *
	 * @param int   $user_id Member doing the following.
	 * @param int   $limit   Optional. Rows to return; clamped to {@see MAX_LIST_LIMIT}.
	 * @param array $args    Optional. `offset` (int), `after_id` (int), `cache`
	 *                       (bool, default true) — see {@see list_ids()}.
	 * @return int[] Followed user ids.
	 */
	public static function following( $user_id, $limit = self::LIST_LIMIT, array $args = array() ) {
		return self::list_ids( 'following', $user_id, $limit, $args );
	}

	/**
	 * One page of edge ids in either direction.
	 *
	 * The two public readers differ only in which column is selected and which
	 * is filtered on, so the paging, clamping, caching, and validation live here
	 * once rather than twice.
	 *
	 * **Two paging forms, and which to use.** The default is `LIMIT/OFFSET`
	 * ordered newest edge first — the order a list surface wants, and the form a
	 * numbered pager needs. Passing `after_id` switches to a keyset walk ordered
	 * by the counterpart id instead (PB-11): `WHERE {owner} = %d AND
	 * {counterpart} > %d ORDER BY {counterpart} ASC`, which both of the table's
	 * indexes cover — the PRIMARY KEY `(follower_id, followed_id)` for
	 * `following`, and `KEY followed (followed_id)` plus InnoDB's implicit
	 * primary-key suffix for `followers`. That matters only for a *whole graph*
	 * walk: at 200 rows a page, a member with 100,000 followers costs 500
	 * statements whose offsets climb to 99,800, and an offset is scanned rows,
	 * not skipped ones. The keyset form reads each page in one seek. It gives up
	 * the created_at order in exchange, which is why it is opt-in.
	 *
	 * `cache => false` is for a *one-shot walk* — the privacy exporter reading a
	 * member's whole follow graph once, and nothing else (PB-7). A page of a
	 * walk is read once and never asked for again, so writing each one into the
	 * object cache under a 15-minute TTL fills the shared pool with entries no
	 * request will ever hit, evicting live entries under LRU. The read still
	 * goes *through* the cache — a page a live surface already primed is free
	 * either way; only the write back is skipped.
	 *
	 * @param string $direction `followers` or `following`.
	 * @param int    $user_id   Member the edges belong to.
	 * @param int    $limit     Rows to return; clamped to {@see MAX_LIST_LIMIT}.
	 * @param array  $args      `offset` (int), `after_id` (int; its presence
	 *                          selects the keyset form), `cache` (bool, default
	 *                          true).
	 * @return int[] Counterpart user ids — descending by edge age in the offset
	 *               form, ascending by id in the keyset one.
	 */
	private static function list_ids( $direction, $user_id, $limit, array $args ) {
		$user_id = self::valid_id( $user_id );
		$limit   = self::clamp_limit( $limit );
		$offset  = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$keyset  = array_key_exists( 'after_id', $args );
		$after   = $keyset ? max( 0, (int) $args['after_id'] ) : 0;
		$store   = ! isset( $args['cache'] ) || (bool) $args['cache'];

		if ( $user_id < 1 ) {
			return array();
		}

		$select = ( 'followers' === $direction ) ? 'follower_id' : 'followed_id';
		$where  = ( 'followers' === $direction ) ? 'followed_id' : 'follower_id';

		$key = $keyset
			? $direction . ':after:' . $after . ':' . $limit
			: $direction . ':' . $limit . ':' . $offset;

		$ids = self::cached(
			$user_id,
			$key,
			static function () use ( $user_id, $limit, $offset, $after, $keyset, $select, $where ) {
				global $wpdb;

				$table = GameLib_Schema::table( self::TABLE );

				if ( $keyset ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; the caller caches this list under the member's `follows_{user}` generation scope, or deliberately opts out for a one-shot walk.
					return $wpdb->get_col(
						$wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table() and both column names are class-chosen literals, never caller input; every value is a bound placeholder.
							"SELECT {$select} FROM {$table} WHERE {$where} = %d AND {$select} > %d ORDER BY {$select} ASC LIMIT %d",
							$user_id,
							$after,
							$limit
						)
					);
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; the caller caches this list under the member's `follows_{user}` generation scope.
				return $wpdb->get_col(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table() and both column names are class-chosen literals, never caller input; every value is a bound placeholder.
						"SELECT {$select} FROM {$table} WHERE {$where} = %d ORDER BY created_at DESC, {$select} DESC LIMIT %d OFFSET %d",
						$user_id,
						$limit,
						$offset
					)
				);
			},
			$store
		);

		return self::positive_ints( is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Delete every edge a member is part of, in both directions (AC-052b,c).
	 *
	 * The privacy eraser and the `deleted_user` purge both end here, because
	 * this class owns the table — and because the counterparts' cached lists
	 * and counts have to be invalidated too, which needs the edges read before
	 * they are deleted.
	 *
	 * Both halves are walked in batches of {@see PURGE_BATCH} and the caller may
	 * cap the whole call (PB-3). The counterpart read was the plugin's only
	 * SELECT with no LIMIT, and every counterpart cost a `wp_cache_incr` — a
	 * network round trip on VIP — inside a synchronous request.
	 *
	 * The purged member's own scopes are bumped once per batch, inside the walk
	 * (CO-6), so an interrupted purge cannot leave deleted edges being served
	 * from cached lists and counts.
	 *
	 * @param int   $user_id Member being purged.
	 * @param array $args    Optional. `limit` (int) — stop after this many edges;
	 *                       0, the default, walks to exhaustion.
	 * @return array{deleted:int,remaining:bool} Edges removed, and whether the
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

		$deleted     = 0;
		$counterparts = array();

		// Outgoing edges first, then incoming: two columns, one batched walk
		// each, so neither side can read the whole table at once.
		foreach ( array( 'follower_id' => 'followed_id', 'followed_id' => 'follower_id' ) as $own => $other ) {
			while ( true ) {
				if ( $limit > 0 && $deleted >= $limit ) {
					$result['remaining'] = true;

					break 2;
				}

				$batch = self::purge_batch( $user_id, $own, $other );

				if ( $batch['deleted'] < 1 ) {
					break;
				}

				$deleted     += $batch['deleted'];
				$counterparts = array_merge( $counterparts, $batch['counterparts'] );

				/*
				 * Inside the loop, once per batch (CO-6) — the same discipline
				 * {@see GameLib_Library::purge_user()} and
				 * {@see GameLib_Activity::purge_user()} follow: a request killed
				 * between batches must never leave committed deletes behind a
				 * cache that still serves the edges they removed.
				 *
				 * The member's *own* scope only (PB-2). The two site-wide
				 * generations this purge also invalidates are bumped once at the
				 * end of the call instead: they are one counter each for the
				 * whole site, so bumping them per batch discarded every cached
				 * feed on the site up to sixteen times per member deleted — a
				 * network round trip each on VIP — where one bump per call
				 * invalidates exactly the same readers. The counterparts' own
				 * scopes stay at the end too, where {@see PURGE_SCOPE_CAP} bounds
				 * their fan-out.
				 */
				GameLib_Cache::bump( GameLib_Cache::follows_scope( $user_id ) );

				if ( $batch['deleted'] < self::PURGE_BATCH ) {
					break;
				}
			}
		}

		if ( $deleted > 0 ) {
			/*
			 * The two site-wide generations, once per call (PB-2). An edge change
			 * reshapes the head page of every feed, and a deep page below a
			 * cursor as well — a member the purge unfollowed must not keep
			 * appearing in either (PB-1) — but both are single counters for the
			 * whole site, so the invalidation is identical whether they are
			 * bumped once here or once per batch, and only the cost differs.
			 */
			GameLib_Cache::bump_many(
				array(
					GameLib_Cache::SCOPE_ACTIVITY,
					GameLib_Cache::SCOPE_ACTIVITY_PAGES,
				)
			);

			self::invalidate_counterparts( $counterparts );
		}

		$result['deleted'] = $deleted;

		return $result;
	}

	/**
	 * Delete one batch of a member's edges in one direction (PB-3).
	 *
	 * @param int    $user_id Member being purged.
	 * @param string $own     Column holding the member's own id.
	 * @param string $other   Column holding the counterpart's id.
	 * @return array{deleted:int,counterparts:int[]} Rows deleted and whose
	 *                                               cached lists they were in.
	 */
	private static function purge_batch( $user_id, $own, $other ) {
		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// Both column names are keys of the literal map in purge_user(); no
		// caller-supplied value reaches this interpolation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write-path read: the counterpart ids whose cached lists this purge is about to invalidate, needed before the rows are gone.
		$counterparts = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table() and the two column names are literals from purge_user()'s own map; both values are bound placeholders.
				"SELECT {$other} FROM {$table} WHERE {$own} = %d ORDER BY {$other} ASC LIMIT %d",
				$user_id,
				self::PURGE_BATCH
			)
		);

		$counterparts = self::positive_ints( is_array( $counterparts ) ? $counterparts : array() );

		if ( empty( $counterparts ) ) {
			return array(
				'deleted'      => 0,
				'counterparts' => array(),
			);
		}

		$placeholders = implode( ',', array_fill( 0, count( $counterparts ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %d literals bound by prepare() below; $table and the column names are as above. A write is never cached.
		$removed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
				"DELETE FROM {$table} WHERE {$own} = %d AND {$other} IN ({$placeholders})",
				array_merge( array( $user_id ), $counterparts )
			)
		);

		return array(
			'deleted'      => is_int( $removed ) ? max( 0, $removed ) : 0,
			'counterparts' => $counterparts,
		);
	}

	/**
	 * Name the counterparts a purge changed, without one round trip each.
	 *
	 * Individual counterparts are named up to {@see PURGE_SCOPE_CAP}; past that
	 * their cached follower counts age out under the 15-minute floor rather than
	 * costing a network round trip each inside a member-triggered request
	 * (PB-3). This is the unbounded half, so it stays at the end of the walk —
	 * the purged member's own scopes are bumped per batch instead (CO-6).
	 *
	 * @param int[] $counterparts Ids on the other end of the deleted edges.
	 * @return void
	 */
	private static function invalidate_counterparts( array $counterparts ) {
		$scopes = array();

		foreach ( array_slice( array_values( array_unique( $counterparts ) ), 0, self::PURGE_SCOPE_CAP ) as $counterpart_id ) {
			$scopes[] = GameLib_Cache::follows_scope( $counterpart_id );
		}

		if ( empty( $scopes ) ) {
			return;
		}

		GameLib_Cache::bump_many( $scopes );
	}

	/**
	 * Validate both ends of an edge write.
	 *
	 * The order is the AC-023 order: a self-follow is refused before anything
	 * is looked up (a), and the only 404 this class produces is a target user
	 * id that does not exist (c). Visibility is never consulted — a
	 * members-only member is followable exactly like a public one (DD-008).
	 *
	 * @param int $follower_id Member doing the following.
	 * @param int $followed_id Member being followed.
	 * @return array{0:int,1:int}|WP_Error Validated pair, or the refusal.
	 */
	private static function validate_pair( $follower_id, $followed_id ) {
		$follower_id = self::valid_id( $follower_id );
		$followed_id = self::valid_id( $followed_id );

		if ( $follower_id < 1 ) {
			return new WP_Error(
				self::ERROR_NO_ACTOR,
				__( 'Sign in to follow other members.', 'game-library' ),
				array( 'status' => 401 )
			);
		}

		if ( $follower_id === $followed_id ) {
			return new WP_Error(
				self::ERROR_SELF,
				__( 'You cannot follow yourself.', 'game-library' ),
				array( 'status' => 400 )
			);
		}

		if ( $followed_id < 1 || ! get_userdata( $followed_id ) instanceof WP_User ) {
			return new WP_Error(
				self::ERROR_NO_MEMBER,
				__( 'That member could not be found.', 'game-library' ),
				array( 'status' => 404 )
			);
		}

		if ( ! get_userdata( $follower_id ) instanceof WP_User ) {
			return new WP_Error(
				self::ERROR_NO_ACTOR,
				__( 'Sign in to follow other members.', 'game-library' ),
				array( 'status' => 401 )
			);
		}

		return array( $follower_id, $followed_id );
	}

	/**
	 * Uncached single-edge lookup, for the write paths.
	 *
	 * Deliberately not {@see is_following()}: the answer decides the statement
	 * on the next line, so it is read from the table rather than from an entry
	 * a concurrent request may have written a moment ago.
	 *
	 * Impure by nature — that is the point. Two calls inside one `follow()` can
	 * legitimately disagree, because between them another request may have
	 * inserted the very edge whose absence the first call reported.
	 *
	 * @phpstan-impure
	 *
	 * @param int $follower_id Member doing the following.
	 * @param int $followed_id Member being followed.
	 * @return bool True when the row exists.
	 */
	private static function edge_exists( $follower_id, $followed_id ) {
		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write-path existence lookup: the "select" half of the select-then-insert under the composite primary key; caching a value the next statement invalidates would be wrong.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); both values are bound placeholders.
				"SELECT 1 FROM {$table} WHERE follower_id = %d AND followed_id = %d",
				$follower_id,
				$followed_id
			)
		);

		return null !== $found;
	}

	/**
	 * Invalidate everything an edge write can have changed.
	 *
	 * Three scopes, always together: the follower's lists and counts, the
	 * target's lists and counts, and the activity feed — whose contents are a
	 * function of the follower's edges (AC-NFR-010b).
	 *
	 * @param int $follower_id Member doing the following.
	 * @param int $followed_id Member being followed.
	 * @return void
	 */
	private static function invalidate( $follower_id, $followed_id ) {
		GameLib_Cache::bump_many(
			array(
				GameLib_Cache::follows_scope( $follower_id ),
				GameLib_Cache::follows_scope( $followed_id ),
				/*
				 * Both feed generations (PB-1). An edge change is the one write
				 * that reaches a *deep* feed page as well as the head one: a
				 * newly followed member's older events belong below the cursor,
				 * where an event insert can never reach.
				 */
				GameLib_Cache::SCOPE_ACTIVITY,
				GameLib_Cache::SCOPE_ACTIVITY_PAGES,
			)
		);
	}

	/**
	 * Read through the member's follow scope, loading on a miss.
	 *
	 * One place where a follow read meets the object cache, so the discipline
	 * — scope, generation key, no memoization — is stated once rather than
	 * copied into five methods (Always Do #5).
	 *
	 * @param int      $user_id Member whose scope owns the entry.
	 * @param string   $key     Entry key within the scope.
	 * @param callable $loader  Produces the value on a miss.
	 * @param bool     $store   Optional. False reads through but does not write
	 *                          back — a one-shot walk must not evict live
	 *                          entries with pages nothing will read again (PB-7).
	 * @return mixed Cached or freshly loaded value.
	 */
	private static function cached( $user_id, $key, $loader, $store = true ) {
		$scope = GameLib_Cache::follows_scope( $user_id );
		$found = false;
		$value = GameLib_Cache::get( $scope, $key, $found );

		if ( $found ) {
			return $value;
		}

		$value = call_user_func( $loader );

		if ( $store ) {
			GameLib_Cache::set( $scope, $key, $value );
		}

		return $value;
	}

	/**
	 * A caller-supplied list size, clamped to the class's own ceiling.
	 *
	 * @param int $limit Requested rows.
	 * @return int Rows this class will actually read.
	 */
	private static function clamp_limit( $limit ) {
		$limit = absint( $limit );

		return max( 1, min( self::MAX_LIST_LIMIT, $limit ) );
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
