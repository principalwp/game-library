<?php
/**
 * Activation / deactivation: schema installer, default options, rewrite flush.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the four custom tables via dbDelta, seeds default options, and
 * registers + flushes the front-end rewrite routes.
 */
final class Game_Library_Activator {

	const OPTION_INVITE_ALLOWANCE  = 'game_library_invite_allowance';
	const OPTION_WIPE_ON_UNINSTALL = 'game_library_wipe_on_uninstall';
	const OPTION_DB_VERSION        = 'game_library_db_version';
	const OPTION_CLIENT_ID         = 'game_library_igdb_client_id';
	const OPTION_CLIENT_SECRET     = 'game_library_igdb_client_secret';
	const OPTION_TOKEN             = 'game_library_igdb_token';
	const DEFAULT_INVITE_ALLOWANCE = 5;

	/**
	 * Fully-qualified table name for one of the plugin's custom tables.
	 *
	 * @param string $name One of entries|follows|activity|invites.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'game_library_' . $name;
	}

	/**
	 * Activation callback: create tables, seed options, register + flush rewrites.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::set_default_options();

		// Register the routes then flush so the pretty URLs resolve immediately.
		Game_Library_Router::register_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation callback: flush so the plugin's rewrite rules are removed.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Create the four custom tables. Uses KEY indexes on id columns and the
	 * activity created_at, plus named UNIQUE constraints — never SQL FOREIGN KEY
	 * syntax (the e2e SQLite shim mishandles it).
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$entries         = self::table( 'entries' );
		$follows         = self::table( 'follows' );
		$activity        = self::table( 'activity' );
		$invites         = self::table( 'invites' );

		$schemas = array();

		$schemas[] = "CREATE TABLE {$entries} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	user_id bigint(20) unsigned NOT NULL,
	igdb_id bigint(20) unsigned NOT NULL,
	status varchar(20) NOT NULL DEFAULT 'backlog',
	game_name varchar(255) NOT NULL DEFAULT '',
	cover_url varchar(255) NOT NULL DEFAULT '',
	created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY user_game (user_id,igdb_id),
	KEY user_id (user_id)
) {$charset_collate};";

		$schemas[] = "CREATE TABLE {$follows} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	follower_id bigint(20) unsigned NOT NULL,
	followee_id bigint(20) unsigned NOT NULL,
	created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY follower_followee (follower_id,followee_id),
	KEY follower_id (follower_id),
	KEY followee_id (followee_id)
) {$charset_collate};";

		$schemas[] = "CREATE TABLE {$activity} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	actor_id bigint(20) unsigned NOT NULL,
	event_type varchar(20) NOT NULL,
	igdb_id bigint(20) unsigned DEFAULT NULL,
	target_user_id bigint(20) unsigned DEFAULT NULL,
	status varchar(20) DEFAULT NULL,
	game_name varchar(255) DEFAULT NULL,
	created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	PRIMARY KEY  (id),
	KEY actor_created (actor_id,created_at),
	KEY created_at (created_at)
) {$charset_collate};";

		$schemas[] = "CREATE TABLE {$invites} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	code varchar(64) NOT NULL,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	redeemed_by bigint(20) unsigned DEFAULT NULL,
	created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	redeemed_at datetime DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY code (code),
	KEY created_by (created_by)
) {$charset_collate};";

		foreach ( $schemas as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::OPTION_DB_VERSION, GAME_LIBRARY_DB_VERSION );
	}

	/**
	 * Seed default options without clobbering values an admin already set.
	 *
	 * @return void
	 */
	public static function set_default_options() {
		if ( false === get_option( self::OPTION_INVITE_ALLOWANCE, false ) ) {
			add_option( self::OPTION_INVITE_ALLOWANCE, self::DEFAULT_INVITE_ALLOWANCE );
		}
		if ( false === get_option( self::OPTION_WIPE_ON_UNINSTALL, false ) ) {
			add_option( self::OPTION_WIPE_ON_UNINSTALL, 0 );
		}
	}
}
