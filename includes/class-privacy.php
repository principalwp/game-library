<?php
/**
 * Privacy integration: policy text, exporter, eraser, and user-deletion cleanup.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin into WordPress's privacy tooling and removes a user's rows
 * across all four tables when their account is deleted.
 */
final class Game_Library_Privacy {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'deleted_user', array( $this, 'purge_user' ), 10, 1 );
	}

	/**
	 * Register suggested privacy-policy text disclosing IGDB/Twitch data egress.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = wp_kses_post(
			__( 'When a member searches for a game, the title they type is sent to the third-party IGDB and Twitch APIs (operated by Amazon/Twitch Interactive) so that matching games and cover art can be returned. This search query data leaves this site and is subject to those providers’ privacy policies. No account credentials are shared with those services. This site also stores each member’s game library, follow relationships, and activity events.', 'game-library' )
		);

		wp_add_privacy_policy_content(
			__( 'Game Library', 'game-library' ),
			'<p>' . $content . '</p>'
		);
	}

	/**
	 * Register the personal-data exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['game-library'] = array(
			'exporter_friendly_name' => __( 'Game Library', 'game-library' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the personal-data eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['game-library'] = array(
			'eraser_friendly_name' => __( 'Game Library', 'game-library' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export a member's library entries and follow relationships.
	 *
	 * @param string $email_address Member email.
	 * @param int    $page          Page (unused; single page).
	 * @return array{data:array,done:bool}
	 */
	public function export( $email_address, $page = 1 ) {
		unset( $page );
		global $wpdb;

		$user = get_user_by( 'email', $email_address );
		$data = array();
		if ( ! $user ) {
			return array(
				'data' => $data,
				'done' => true,
			);
		}

		$entries_table = Game_Library_Activator::table( 'entries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, user id via %d.
		$entries = $wpdb->get_results( $wpdb->prepare( 'SELECT game_name, status FROM %i WHERE user_id = %d', $entries_table, $user->ID ) );
		foreach ( (array) $entries as $entry ) {
			$data[] = array(
				'group_id'    => 'game-library-entries',
				'group_label' => __( 'Game Library entries', 'game-library' ),
				'item_id'     => 'gl-entry-' . md5( $entry->game_name . $entry->status ),
				'data'        => array(
					array(
						'name'  => __( 'Game', 'game-library' ),
						'value' => $entry->game_name,
					),
					array(
						'name'  => __( 'Status', 'game-library' ),
						'value' => Game_Library_Router::status_label( $entry->status ),
					),
				),
			);
		}

		$follows_table = Game_Library_Activator::table( 'follows' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, follower via %d.
		$followees = $wpdb->get_col( $wpdb->prepare( 'SELECT followee_id FROM %i WHERE follower_id = %d', $follows_table, $user->ID ) );
		// Prime the user cache once (the followee set is unbounded) so the per-followee
		// get_user_by('id',…) in the loop below resolves from cache, not one query each.
		if ( ! empty( $followees ) ) {
			cache_users( array_map( 'intval', (array) $followees ) );
		}
		foreach ( (array) $followees as $followee_id ) {
			$followee = get_user_by( 'id', (int) $followee_id );
			$data[]   = array(
				'group_id'    => 'game-library-follows',
				'group_label' => __( 'Game Library follows', 'game-library' ),
				'item_id'     => 'gl-follow-' . (int) $followee_id,
				'data'        => array(
					array(
						'name'  => __( 'Following', 'game-library' ),
						'value' => $followee ? $followee->display_name : (string) $followee_id,
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase a member's library entries and follow relationships.
	 *
	 * @param string $email_address Member email.
	 * @param int    $page          Page (unused; single page).
	 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
	 */
	public function erase( $email_address, $page = 1 ) {
		unset( $page );
		global $wpdb;

		$user    = get_user_by( 'email', $email_address );
		$removed = false;
		if ( $user ) {
			$entries_table = Game_Library_Activator::table( 'entries' );
			$follows_table = Game_Library_Activator::table( 'follows' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, user id via %d.
			$e = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', $entries_table, $user->ID ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, ids via %d.
			$f       = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE follower_id = %d OR followee_id = %d', $follows_table, $user->ID, $user->ID ) );
			$removed = ( $e > 0 || $f > 0 );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Remove a deleted user's rows from all four tables.
	 *
	 * @param int $user_id The deleted user's id.
	 * @return void
	 */
	public function purge_user( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return;
		}

		$entries  = Game_Library_Activator::table( 'entries' );
		$follows  = Game_Library_Activator::table( 'follows' );
		$activity = Game_Library_Activator::table( 'activity' );
		$invites  = Game_Library_Activator::table( 'invites' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, id via %d.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', $entries, $user_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, ids via %d.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE follower_id = %d OR followee_id = %d', $follows, $user_id, $user_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, ids via %d.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE actor_id = %d OR target_user_id = %d', $activity, $user_id, $user_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, id via %d.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_by = %d', $invites, $user_id ) );
	}
}
