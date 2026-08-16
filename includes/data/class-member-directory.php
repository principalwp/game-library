<?php
/**
 * Cached reads over the `gl_manage_library` capability-holder directory.
 *
 * @package Game_Library
 */

namespace Game_Library\Data;

use Game_Library\Roles;
use Game_Library\Visibility;
use WP_User_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Member_Directory.
 *
 * PATTERN-CLASS (MR-4): `templates/members.php`, `Social_Controller::get_members()`,
 * and `Sitemap_Provider`'s `members` subtype each ran the same
 * capability-filtered `WP_User_Query` fresh on every request — core used to
 * expand `'capability' => 'gl_manage_library'` into a leading-wildcard
 * `meta_value LIKE` against `wp_usermeta` (no index can serve that pattern,
 * and it scans every user on the site regardless of role) and, with
 * `count_total` defaulting to `true`, prefixed the query with
 * `SQL_CALC_FOUND_ROWS`, materialising the whole matching set just to render
 * one page. This class gives that one shared query shape one cached owner
 * instead of three independent copies.
 *
 * PB-2 (cycle-7): both query shapes below now filter on `Roles::MEMBER_META_KEY`
 * instead of `'capability' => 'gl_manage_library'` — a flat marker
 * `Roles` keeps in sync with the real capability (see that class's own
 * docblock) purely so this class's queries have something `wp_usermeta`'s
 * `meta_key` index can actually serve. This class never writes the marker
 * itself, only reads it.
 *
 * `member_ids()`/`member_count()` back the general directory (every
 * `gl_manage_library` holder, `/members/` and `GET /members`);
 * `public_member_nicenames()`/`public_member_count()` back the sitemap's
 * public-only subset (also opted `_gl_profile_public`, PB-6). Both share the
 * single `members_gen` generation counter (`Generations::members_key()`) —
 * see that method's own docblock for every bump site.
 *
 * PB-5 (cycle-3): the count-only halves (`member_count()`/
 * `public_member_count()`) pass `'number' => 1` so `get_total()` reads
 * `SQL_CALC_FOUND_ROWS` off a cheap one-row query; the list-reading halves
 * (`member_ids()`/`public_member_nicenames()`) never call `get_total()` at
 * all, so they instead pass `'count_total' => false` to skip
 * `SQL_CALC_FOUND_ROWS` (and the full-matching-set evaluation it forces)
 * entirely — the other half of the same trade-off, previously applied only
 * to the count queries.
 */
final class Member_Directory {

	/**
	 * Object-cache group for every cache entry this class reads/writes.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'game_library';

	/**
	 * One page of every `gl_manage_library` holder's user id, ordered by
	 * display name — the general member-directory shape `/members/` and
	 * `GET /members` both render.
	 *
	 * @param int $page     1-based page number.
	 * @param int $per_page Page size.
	 * @return int[]
	 */
	public function member_ids( $page, $per_page ) {
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, absint( $per_page ) );

		// MR-2 sibling: same offset ceiling as public_member_nicenames()
		// below, for the same reason — member_count() is already cached, so
		// this costs nothing extra on the common (in-range) case, and stops
		// an out-of-range /members/ page from running the leading-wildcard
		// capability query and writing a dead cache entry for it.
		$offset = ( $page - 1 ) * $per_page;

		if ( $offset > 0 && $offset >= $this->member_count() ) {
			return array();
		}

		$gen       = Generations::read( Generations::members_key() );
		$cache_key = sprintf( 'members_%d_%d_%d', $page, $per_page, $gen );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$query = new WP_User_Query(
			array(
				'meta_key'    => Roles::MEMBER_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- PB-2: an indexed meta_key/meta_value pair, replacing the leading-wildcard 'capability' clause this query used to run; every call site bounds it with an explicit number/offset.
				'meta_value'  => Roles::MEMBER_META_VALUE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'     => 'display_name',
				'order'       => 'ASC',
				'number'      => $per_page,
				'offset'      => ( $page - 1 ) * $per_page,
				'fields'      => 'ID',
				// PB-5: this is a pure list read — get_total() is never
				// called on this query (member_count() below is a
				// separate, 'number' => 1 query with its own cache key).
				// count_total defaults to true, which prefixes the query
				// with SQL_CALC_FOUND_ROWS and forces evaluation of the
				// entire matching set regardless of LIMIT, for a result
				// this method discards.
				'count_total' => false,
			)
		);

		$ids = array_values( array_map( 'absint', (array) $query->get_results() ) );

		wp_cache_set( $cache_key, $ids, self::CACHE_GROUP, self::jittered_ttl() ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- jittered_ttl() is HOUR_IN_SECONDS +/- a bounded 360s jitter, always >= 3240s; the sniff cannot statically evaluate the method call's runtime result.

		return $ids;
	}

	/**
	 * The total `gl_manage_library` holder count — the companion count
	 * `member_ids()`'s pagination needs. `number => 1` (PB-6) so
	 * `get_total()` comes from `SQL_CALC_FOUND_ROWS` on a one-row query
	 * instead of materialising and counting every matching row.
	 *
	 * @return int
	 */
	public function member_count() {
		$gen       = Generations::read( Generations::members_key() );
		$cache_key = sprintf( 'members_count_%d', $gen );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$query = new WP_User_Query(
			array(
				'meta_key'   => Roles::MEMBER_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- PB-2: see member_ids()'s own comment on the identical replacement.
				'meta_value' => Roles::MEMBER_META_VALUE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		$count = (int) $query->get_total();

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::jittered_ttl() ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- jittered_ttl() is HOUR_IN_SECONDS +/- a bounded 360s jitter, always >= 3240s; the sniff cannot statically evaluate the method call's runtime result.

		return $count;
	}

	/**
	 * One page of every opted-public `gl_manage_library` holder's id and
	 * `user_nicename`, ordered by id — the sitemap's `members` subtype
	 * (AC-044, PB-1).
	 *
	 * Returns `id => nicename` directly from the query rather than a bare id
	 * list the caller would then resolve one `get_userdata()` at a time:
	 * `Sitemap_Provider::member_url_list()` (the only caller) consumes
	 * nothing but the nicename, and core's own default sitemap page size
	 * (`wp_sitemaps_get_max_urls()`, 2,000) is far larger than this plugin's
	 * own 24-per-page listings — an uncached per-row `wp_users`/`wp_usermeta`
	 * lookup loop at that size was the single most expensive read a crawler
	 * request to this plugin could trigger. Supersedes the earlier
	 * `public_member_ids()`.
	 *
	 * @param int $page     1-based page number.
	 * @param int $per_page Page size.
	 * @return array<int,string> `user_id => user_nicename`.
	 */
	public function public_member_nicenames( $page, $per_page ) {
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, absint( $per_page ) );

		// MR-2: defence-in-depth offset ceiling, matching
		// Game_Repository::get_referenced_games()'s own guard.
		// Sitemap_Provider::get_url_list() now clamps the page number before
		// calling this, but a caller that skips the provider must not still
		// be able to run the (leading-wildcard, unindexable) capability query
		// and write an hour-long dead cache entry for an out-of-range page.
		$offset = ( $page - 1 ) * $per_page;

		if ( $offset > 0 && $offset >= $this->public_member_count() ) {
			return array();
		}

		$gen       = Generations::read( Generations::members_key() );
		$cache_key = sprintf( 'public_member_nicenames_%d_%d_%d', $page, $per_page, $gen );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$query = new WP_User_Query(
			array_merge(
				$this->public_member_query_args(),
				array(
					'number'      => $per_page,
					'offset'      => ( $page - 1 ) * $per_page,
					'fields'      => array( 'ID', 'user_nicename' ),
					// PB-5: added at this array_merge() layer, not inside
					// public_member_query_args() — that helper is shared
					// with public_member_count() below, whose own
					// 'number' => 1 query correctly relies on count_total's
					// default (true) for its cheap SQL_CALC_FOUND_ROWS
					// path. This method is the pure-list read, which never
					// calls get_total() on this query.
					'count_total' => false,
				)
			)
		);

		$nicenames = array();

		foreach ( (array) $query->get_results() as $row ) {
			$nicenames[ absint( $row->ID ) ] = (string) $row->user_nicename;
		}

		wp_cache_set( $cache_key, $nicenames, self::CACHE_GROUP, self::jittered_ttl() ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- jittered_ttl() is HOUR_IN_SECONDS +/- a bounded 360s jitter, always >= 3240s; the sniff cannot statically evaluate the method call's runtime result.

		return $nicenames;
	}

	/**
	 * The total opted-public `gl_manage_library` holder count (PB-6) —
	 * `number => 1` for the same reason as `member_count()`.
	 *
	 * @return int
	 */
	public function public_member_count() {
		$gen       = Generations::read( Generations::members_key() );
		$cache_key = sprintf( 'public_members_count_%d', $gen );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$query = new WP_User_Query(
			array_merge(
				$this->public_member_query_args(),
				array( 'number' => 1 )
			)
		);

		$count = (int) $query->get_total();

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::jittered_ttl() ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- jittered_ttl() is HOUR_IN_SECONDS +/- a bounded 360s jitter, always >= 3240s; the sniff cannot statically evaluate the method call's runtime result.

		return $count;
	}

	/**
	 * Shared `WP_User_Query` args for the public-only subset — every
	 * `gl_manage_library` holder who has opted `_gl_profile_public` to `'1'`.
	 * Every call site bounds this query with an explicit `number`/`offset`
	 * (`public_member_nicenames()`/`public_member_count()` above) — this
	 * method is never called unbounded.
	 *
	 * PB-2 (cycle-7): two AND'd meta clauses now, both on an indexed
	 * `meta_key` — `Roles::MEMBER_META_KEY` replaces the leading-wildcard
	 * `'capability' => 'gl_manage_library'` clause this used to carry
	 * alongside `Visibility::META_KEY`. `WP_User_Query` only accepts one
	 * top-level `meta_key`/`meta_value` pair, so both conditions move into
	 * an explicit `meta_query` (implicit `AND` relation).
	 *
	 * @return array<string,mixed>
	 */
	private function public_member_query_args() {
		return array(
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- both clauses are indexed meta_key/meta_value pairs (PB-2); every call site bounds this query with an explicit number/offset.
				array(
					'key'   => Roles::MEMBER_META_KEY,
					'value' => Roles::MEMBER_META_VALUE,
				),
				array(
					'key'   => Visibility::META_KEY,
					'value' => Visibility::PUBLIC_VALUE,
				),
			),
			'orderby'    => 'ID',
			'order'      => 'ASC',
			'fields'     => 'ID',
		);
	}

	/**
	 * A TTL of `HOUR_IN_SECONDS` +/- 10% (PB-4-style jitter) — every key on
	 * this shared `members_gen` scope created in the same write burst
	 * otherwise expires in the same instant. Freshness still comes from the
	 * generation counter, never this TTL.
	 *
	 * @return int
	 */
	private static function jittered_ttl() {
		return HOUR_IN_SECONDS + wp_rand( -360, 360 );
	}
}
