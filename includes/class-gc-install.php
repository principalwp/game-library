<?php
/**
 * Schema installation and upgrades.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Install {

	const DB_VERSION = '1.0.0';

	public static function activate() {
		self::create_tables();
		update_option( 'gc_db_version', self::DB_VERSION );

		if ( false === get_option( 'gc_force_registration', false ) ) {
			add_option( 'gc_force_registration', '1' );
		}

		GC_Frontend::register_rewrites();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'gc_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( 'gc_db_version', self::DB_VERSION );
		}
	}

	/**
	 * Table names (with blog prefix).
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'gc_' . $name;
	}

	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$games      = self::table( 'games' );
		$user_games = self::table( 'user_games' );
		$follows    = self::table( 'follows' );
		$activity   = self::table( 'activity' );
		$invites    = self::table( 'invites' );

		$sql = "
CREATE TABLE {$games} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	igdb_id BIGINT UNSIGNED NOT NULL,
	name VARCHAR(255) NOT NULL,
	slug VARCHAR(255) NOT NULL DEFAULT '',
	cover_image_id VARCHAR(64) NOT NULL DEFAULT '',
	release_year SMALLINT UNSIGNED NULL,
	platforms VARCHAR(500) NOT NULL DEFAULT '',
	summary TEXT NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY igdb_id (igdb_id),
	KEY name (name(191))
) {$charset_collate};
CREATE TABLE {$user_games} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id BIGINT UNSIGNED NOT NULL,
	game_id BIGINT UNSIGNED NOT NULL,
	status VARCHAR(20) NOT NULL DEFAULT 'backlog',
	added_at DATETIME NOT NULL,
	updated_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY user_game (user_id,game_id),
	KEY user_status (user_id,status),
	KEY game_id (game_id)
) {$charset_collate};
CREATE TABLE {$follows} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	follower_id BIGINT UNSIGNED NOT NULL,
	following_id BIGINT UNSIGNED NOT NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY pair (follower_id,following_id),
	KEY following_id (following_id)
) {$charset_collate};
CREATE TABLE {$activity} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id BIGINT UNSIGNED NOT NULL,
	type VARCHAR(32) NOT NULL,
	game_id BIGINT UNSIGNED NULL,
	target_user_id BIGINT UNSIGNED NULL,
	status VARCHAR(20) NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	KEY user_created (user_id,created_at),
	KEY created_at (created_at)
) {$charset_collate};
CREATE TABLE {$invites} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	code VARCHAR(40) NOT NULL,
	note VARCHAR(255) NOT NULL DEFAULT '',
	created_by BIGINT UNSIGNED NOT NULL,
	used_by BIGINT UNSIGNED NULL,
	created_at DATETIME NOT NULL,
	used_at DATETIME NULL,
	expires_at DATETIME NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY code (code)
) {$charset_collate};
";

		dbDelta( $sql );
	}
}
