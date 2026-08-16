<?php
/**
 * Activity log and feed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Activity {

	const TYPES = array( 'added_game', 'status_change', 'followed_user' );

	private static function table() {
		return GC_Install::table( 'activity' );
	}

	/**
	 * @param string $type One of self::TYPES.
	 * @param array  $data Keys: game_id, status, target_user_id (as relevant).
	 */
	public static function log( $user_id, $type, $data = array() ) {
		global $wpdb;

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return;
		}

		$wpdb->insert(
			self::table(),
			array(
				'user_id'        => (int) $user_id,
				'type'           => $type,
				'game_id'        => isset( $data['game_id'] ) ? (int) $data['game_id'] : null,
				'target_user_id' => isset( $data['target_user_id'] ) ? (int) $data['target_user_id'] : null,
				'status'         => isset( $data['status'] ) ? $data['status'] : null,
				'created_at'     => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Feed for a viewer: their own actions plus those of everyone they follow.
	 *
	 * @return object[] Activity rows joined with game data.
	 */
	public static function get_feed_for( $viewer_id, $page = 1, $per_page = 30 ) {
		$user_ids   = GC_Follows::get_following_ids( $viewer_id );
		$user_ids[] = (int) $viewer_id;

		return self::query( $user_ids, $page, $per_page );
	}

	/**
	 * A single member's recent activity.
	 */
	public static function get_user_activity( $user_id, $page = 1, $per_page = 30 ) {
		return self::query( array( (int) $user_id ), $page, $per_page );
	}

	private static function query( $user_ids, $page, $per_page ) {
		global $wpdb;

		$user_ids = array_filter( array_map( 'intval', $user_ids ) );
		if ( ! $user_ids ) {
			return array();
		}

		$per_page = min( 100, max( 1, (int) $per_page ) );
		$offset   = ( max( 1, (int) $page ) - 1 ) * $per_page;

		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$games_table  = GC_Install::table( 'games' );

		$sql = 'SELECT a.*, g.name AS game_name, g.cover_image_id, g.release_year
			FROM ' . self::table() . " a
			LEFT JOIN {$games_table} g ON g.id = a.game_id
			WHERE a.user_id IN ({$placeholders})
			ORDER BY a.created_at DESC, a.id DESC
			LIMIT %d OFFSET %d";

		$params   = $user_ids;
		$params[] = $per_page;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Human-readable sentence for one activity row (HTML, escaped).
	 */
	public static function describe( $item ) {
		$labels = GC_Library::status_labels();

		$actor      = get_userdata( $item->user_id );
		$actor_name = $actor ? $actor->display_name : __( 'A former member', 'game-collector' );
		$actor_html = $actor
			? '<a href="' . esc_url( GC_Frontend::library_url( $actor ) ) . '">' . esc_html( $actor_name ) . '</a>'
			: esc_html( $actor_name );

		$game_html = '';
		if ( $item->game_id && ! empty( $item->game_name ) ) {
			$game_html = '<strong>' . esc_html( $item->game_name ) . '</strong>';
		}

		switch ( $item->type ) {
			case 'added_game':
				$status = isset( $labels[ $item->status ] ) ? $labels[ $item->status ] : $item->status;
				if ( 'wishlist' === $item->status ) {
					/* translators: 1: member link, 2: game name */
					return sprintf( __( '%1$s added %2$s to their wishlist', 'game-collector' ), $actor_html, $game_html );
				}
				/* translators: 1: member link, 2: game name, 3: status label */
				return sprintf( __( '%1$s added %2$s to their library (%3$s)', 'game-collector' ), $actor_html, $game_html, esc_html( $status ) );

			case 'status_change':
				$status = isset( $labels[ $item->status ] ) ? $labels[ $item->status ] : $item->status;
				/* translators: 1: member link, 2: game name, 3: status label */
				return sprintf( __( '%1$s moved %2$s to %3$s', 'game-collector' ), $actor_html, $game_html, '<em>' . esc_html( $status ) . '</em>' );

			case 'followed_user':
				$target      = $item->target_user_id ? get_userdata( $item->target_user_id ) : false;
				$target_html = $target
					? '<a href="' . esc_url( GC_Frontend::library_url( $target ) ) . '">' . esc_html( $target->display_name ) . '</a>'
					: esc_html__( 'a former member', 'game-collector' );
				/* translators: 1: member link, 2: followed member link */
				return sprintf( __( '%1$s started following %2$s', 'game-collector' ), $actor_html, $target_html );
		}

		return '';
	}
}
