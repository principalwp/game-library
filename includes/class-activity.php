<?php
/**
 * Activity repository: records events and reads the bounded feeds.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable activity log. Events are only ever appended (never cascade-deleted
 * when the underlying entry/follow is removed — Open Question 4). Every read is
 * bounded to at most PAGE_SIZE rows and ordered on the indexed created_at column
 * (NFR-001).
 */
final class Game_Library_Activity {

	/**
	 * Maximum rows returned per feed page (NFR-001: LIMIT <= 20).
	 */
	const PAGE_SIZE = 20;

	/**
	 * The three permitted event types.
	 */
	const EVENT_TYPES = array( 'added', 'status_change', 'follow' );

	/**
	 * Append one activity event. event_type is server-set and validated.
	 *
	 * @param int    $actor_id       User who performed the action.
	 * @param string $event_type     One of EVENT_TYPES.
	 * @param array  $data           Optional igdb_id, target_user_id, status, game_name.
	 * @return int|false Inserted row id, or false on failure/invalid type.
	 */
	public static function record( $actor_id, $event_type, array $data = array() ) {
		global $wpdb;

		$actor_id = absint( $actor_id );
		if ( ! in_array( $event_type, self::EVENT_TYPES, true ) || $actor_id <= 0 ) {
			return false;
		}

		$row = array(
			'actor_id'       => $actor_id,
			'event_type'     => $event_type,
			'igdb_id'        => isset( $data['igdb_id'] ) ? absint( $data['igdb_id'] ) : null,
			'target_user_id' => isset( $data['target_user_id'] ) ? absint( $data['target_user_id'] ) : null,
			'status'         => isset( $data['status'] ) ? substr( (string) $data['status'], 0, 20 ) : null,
			'game_name'      => isset( $data['game_name'] ) ? substr( (string) $data['game_name'], 0, 255 ) : null,
			'created_at'     => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API exists.
		$result = $wpdb->insert(
			Game_Library_Activator::table( 'activity' ),
			$row,
			array( '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Newest events authored by everyone a member follows (the "Following" feed).
	 *
	 * Resolved as one bounded, indexed JOIN — `follows.follower_id` and
	 * `activity.(actor_id,created_at)` are both indexed — so the set of followees
	 * is never materialized into an unbounded `IN (…)` list (NFR-001: no unbounded
	 * SELECT over the high-volume tables).
	 *
	 * @param int $follower_id The viewing member.
	 * @param int $limit       Page size (clamped to PAGE_SIZE).
	 * @return array<int,object> Event rows, newest first.
	 */
	public static function following_feed_for( $follower_id, $limit = self::PAGE_SIZE ) {
		global $wpdb;

		$follower_id = absint( $follower_id );
		if ( $follower_id <= 0 ) {
			return array();
		}

		$limit    = self::clamp_limit( $limit );
		$activity = Game_Library_Activator::table( 'activity' );
		$follows  = Game_Library_Activator::table( 'follows' );

		$sql = $wpdb->prepare(
			'SELECT a.* FROM %i AS a INNER JOIN %i AS f ON f.followee_id = a.actor_id WHERE f.follower_id = %d ORDER BY a.created_at DESC, a.id DESC LIMIT %d',
			$activity,
			$follows,
			$follower_id,
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql was built with $wpdb->prepare() directly above.
		return $wpdb->get_results( $sql );
	}

	/**
	 * Newest site-wide events, paginated. Fetches one extra row to signal a next page.
	 *
	 * @param int $page 1-based page number.
	 * @return array{items:array<int,object>,has_more:bool}
	 */
	public static function sitewide_feed( $page = 1 ) {
		global $wpdb;

		$page   = max( 1, absint( $page ) );
		$limit  = self::PAGE_SIZE;
		$offset = ( $page - 1 ) * $limit;
		$table  = Game_Library_Activator::table( 'activity' );

		$sql = $wpdb->prepare(
			'SELECT * FROM %i ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
			$table,
			$limit + 1,
			$offset
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql was built with $wpdb->prepare() directly above.
		$rows     = $wpdb->get_results( $sql );
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			$rows = array_slice( $rows, 0, $limit );
		}

		return array(
			'items'    => $rows,
			'has_more' => $has_more,
		);
	}

	/**
	 * Clamp a requested limit to the NFR-001 ceiling.
	 *
	 * @param int $limit Requested limit.
	 * @return int
	 */
	private static function clamp_limit( $limit ) {
		$limit = absint( $limit );
		if ( $limit < 1 || $limit > self::PAGE_SIZE ) {
			return self::PAGE_SIZE;
		}
		return $limit;
	}
}
