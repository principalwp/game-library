<?php
/**
 * Activation, schema management and the rewrite-flush guard.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the database schema, the version options and the on-init upgrade guard.
 */
class Activator {

	/**
	 * Register the runtime upgrade/rewrite guard.
	 *
	 * Runs after Router::register_rules() (init 10) so the freshly registered
	 * rules are in $wp_rewrite before any flush.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'maybe_upgrade' ), 20 );
	}

	/**
	 * Activation callback: build schema, then register rewrite rules and flush.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		update_option( 'gl_db_version', GL_VERSION );

		if ( false === get_option( 'gl_invite_quota', false ) ) {
			add_option( 'gl_invite_quota', 5 );
		}

		// The IGDB credentials are read only in gated paths (settings screen +
		// server-side IGDB client), never on a front-end render, so keep them out
		// of the autoloaded alloptions cache. Pre-create with autoload=no before
		// the Settings API ever writes them: add_option() won't overwrite an
		// existing row, and update_option() preserves an existing autoload flag.
		add_option( Settings::OPTION_SECRET, '', '', false );
		add_option( Settings::OPTION_ID, '', '', false );

		Plugin::instance()->router()->register_rules();
		flush_rewrite_rules();
		update_option( 'gl_rewrite_version', self::rewrite_signature() );
	}

	/**
	 * Deactivation callback: drop our rewrite rules from the cache.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * On every request, re-run schema + flush when a version marker drifts.
	 *
	 * (a)/(b) schema drift, (c) permalink-structure drift per AC-003.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		if ( get_option( 'gl_db_version' ) !== GL_VERSION ) {
			self::create_tables();
			update_option( 'gl_db_version', GL_VERSION );
			Plugin::instance()->router()->register_rules();
			flush_rewrite_rules();
			update_option( 'gl_rewrite_version', self::rewrite_signature() );
			return;
		}

		if ( get_option( 'gl_rewrite_version' ) !== self::rewrite_signature() ) {
			// Rules were registered on init 10; persist them once here.
			flush_rewrite_rules();
			update_option( 'gl_rewrite_version', self::rewrite_signature() );
		}
	}

	/**
	 * The rewrite signature: plugin version + a hash of the permalink structure.
	 *
	 * @return string
	 */
	private static function rewrite_signature() {
		return GL_VERSION . '-' . md5( (string) get_option( 'permalink_structure' ) );
	}

	/**
	 * Create or update the five plugin tables with dbDelta().
	 *
	 * All types are the portable subset (no ENUM / FULLTEXT / generated cols)
	 * so the schema also builds under Playground's SQLite translation layer.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$games = "CREATE TABLE {$prefix}gl_games (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			igdb_id bigint(20) unsigned NOT NULL,
			name varchar(255) NOT NULL,
			slug varchar(200) NOT NULL,
			cover_image_id varchar(64) NULL,
			first_release_date int NULL,
			genres text NULL,
			platforms text NULL,
			summary text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY igdb_id (igdb_id),
			UNIQUE KEY slug (slug),
			KEY name (name(100))
		) {$charset_collate};";

		$library = "CREATE TABLE {$prefix}gl_library (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			game_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_game (user_id, game_id),
			KEY user_status (user_id, status),
			KEY game_id (game_id)
		) {$charset_collate};";

		$follows = "CREATE TABLE {$prefix}gl_follows (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			follower_id bigint(20) unsigned NOT NULL,
			followed_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY follow_pair (follower_id, followed_id),
			KEY followed_id (followed_id)
		) {$charset_collate};";

		$activity = "CREATE TABLE {$prefix}gl_activity (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_id bigint(20) unsigned NOT NULL,
			verb varchar(20) NOT NULL,
			game_id bigint(20) unsigned NOT NULL,
			from_status varchar(20) NULL,
			to_status varchar(20) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY actor_created (actor_id, created_at),
			KEY created_at (created_at),
			KEY game_id (game_id)
		) {$charset_collate};";

		$invites = "CREATE TABLE {$prefix}gl_invites (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(32) NOT NULL,
			inviter_id bigint(20) unsigned NOT NULL,
			invitee_id bigint(20) unsigned NULL DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			expires_at datetime NULL,
			used_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY inviter_status (inviter_id, status)
		) {$charset_collate};";

		dbDelta( $games );
		dbDelta( $library );
		dbDelta( $follows );
		dbDelta( $activity );
		dbDelta( $invites );
	}
}
