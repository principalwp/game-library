<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GCOLLECTOR_Database {
	const DB_VERSION = '1.0.0';

	public static function table( $name ) {
		global $wpdb;

		$tables = array(
			'games'       => $wpdb->prefix . 'gc_games',
			'library'     => $wpdb->prefix . 'gc_library',
			'follows'     => $wpdb->prefix . 'gc_follows',
			'activities'  => $wpdb->prefix . 'gc_activities',
			'invitations' => $wpdb->prefix . 'gc_invitations',
		);

		return isset( $tables[ $name ] ) ? $tables[ $name ] : '';
	}

	public static function activate() {
		self::create_tables();
		self::create_library_page();
		update_option( 'gcollector_db_version', self::DB_VERSION, false );
		update_option( 'users_can_register', 0 );
		flush_rewrite_rules();
	}

	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE " . self::table( 'games' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			igdb_id bigint(20) unsigned NOT NULL,
			name varchar(255) NOT NULL,
			slug varchar(255) NOT NULL DEFAULT '',
			cover_url text NOT NULL,
			release_date datetime NULL,
			platforms text NOT NULL,
			summary longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY igdb_id (igdb_id)
		) $charset;

		CREATE TABLE " . self::table( 'library' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			game_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_game (user_id,game_id),
			KEY user_status (user_id,status),
			KEY game_id (game_id)
		) $charset;

		CREATE TABLE " . self::table( 'follows' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			follower_id bigint(20) unsigned NOT NULL,
			followed_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY relationship (follower_id,followed_id),
			KEY followed_id (followed_id)
		) $charset;

		CREATE TABLE " . self::table( 'activities' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_id bigint(20) unsigned NOT NULL,
			verb varchar(30) NOT NULL,
			game_id bigint(20) unsigned NULL,
			target_user_id bigint(20) unsigned NULL,
			meta longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY actor_created (actor_id,created_at),
			KEY created_at (created_at)
		) $charset;

		CREATE TABLE " . self::table( 'invitations' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			token_hash char(64) NOT NULL,
			invited_by bigint(20) unsigned NOT NULL,
			expires_at datetime NOT NULL,
			accepted_by bigint(20) unsigned NULL,
			accepted_at datetime NULL,
			revoked_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY email (email),
			KEY expires_at (expires_at)
		) $charset;";

		dbDelta( $sql );
	}

	private static function create_library_page() {
		$page = get_page_by_path( 'my-library', OBJECT, 'page' );
		if ( $page ) {
			update_option( 'gcollector_library_page_id', (int) $page->ID, false );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'My Library', 'game-collector' ),
				'post_name'    => 'my-library',
				'post_content' => '[game_collector_library]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		if ( ! is_wp_error( $page_id ) ) {
			update_option( 'gcollector_library_page_id', (int) $page_id, false );
		}
	}
}
