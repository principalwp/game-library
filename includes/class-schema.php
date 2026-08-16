<?php
/**
 * Custom-table schema, installation, and upgrades.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns 100% of the plugin's DDL.
 *
 * WordPress has no persistence or migration machinery for custom tables, so
 * this class carries the whole lifecycle: `dbDelta()` on activation, a stored
 * schema version, and an idempotent upgrade check on `init` for the case where
 * the code is newer than the database (D-REQ-4, ADR-001). Nothing here ever
 * drops a table or a column — destructive work belongs only to `uninstall.php`
 * behind its explicit opt-in (AC-051).
 *
 * The DDL stays inside the subset `dbDelta()` can parse and Playground's
 * SQLite driver can translate: `CREATE TABLE` uppercase, one field per line,
 * lowercase types, two spaces after `PRIMARY KEY`, a named `KEY` per line, and
 * no ENUM (Never Do #9).
 */
final class GameLib_Schema {

	/**
	 * Option holding the schema version the database was last built at.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'gamelib_db_version';

	/**
	 * Object-cache key one process claims before running `dbDelta()` (VIP-2).
	 *
	 * @var string
	 */
	const UPGRADE_LOCK = 'gamelib_schema_upgrade_lock';

	/**
	 * Shared prefix of every table this plugin owns, appended to
	 * `$wpdb->prefix`.
	 *
	 * @var string
	 */
	const TABLE_PREFIX = 'gamelib_';

	/**
	 * The eight table suffixes, in creation order (ADR-001).
	 *
	 * @var string[]
	 */
	const TABLES = array(
		'games',
		'library',
		'follows',
		'activity',
		'invites',
		'imports',
		'import_items',
		'steam_map',
	);

	/**
	 * Fully-qualified name of one plugin table.
	 *
	 * The single source of table names for the whole plugin — no other class
	 * concatenates `$wpdb->prefix` with a table literal.
	 *
	 * @param string $name One of {@see GameLib_Schema::TABLES}.
	 * @return string Prefixed table name.
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_PREFIX . $name;
	}

	/**
	 * Every plugin table, fully qualified.
	 *
	 * @return string[] Prefixed table names.
	 */
	public static function tables() {
		return array_map( array( __CLASS__, 'table' ), self::TABLES );
	}

	/**
	 * Create or update every table, then stamp the schema version.
	 *
	 * Idempotent by construction: `dbDelta()` compares the declared schema
	 * against the live one and emits only the differences, so a second run
	 * against an up-to-date database issues no statements. Called from
	 * activation and from {@see GameLib_Schema::maybe_upgrade()}.
	 *
	 * The one thing `dbDelta()` cannot do is drop an index, which is why the
	 * version-3 step below is hand-written (CO-4/PB-1).
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$installed = (string) get_option( self::VERSION_OPTION, '' );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::schema_statements( $wpdb->get_charset_collate() ) );

		/*
		 * The version-3 step, and the only destructive statement outside
		 * `uninstall.php`: take the version-2 `KEY name (name(191))` back out
		 * (CO-4/PB-1).
		 *
		 * Guarded on the stored version, so it costs nothing after the first
		 * pass — and skipped entirely for `''`, a database this same call has
		 * just created from a declaration that does not name the index. That
		 * leaves exactly the population that can have it: an install stamped 1
		 * or 2 whose table predates this code. (Stamped *1* matters: the
		 * cycle-3 revert re-stamped a version-2 install back to 1 while leaving
		 * the index in place, which is the state this exists for.)
		 *
		 * It inherits {@see maybe_upgrade()}'s `is_admin()`/`wp_doing_cron()`/
		 * WP-CLI gate and its `wp_cache_add()` claim — the deploy-window path
		 * VIP-2 built those guards for — so a `SHOW INDEX` never runs on a
		 * front-end request. It is not the front-end `SHOW TABLES` anti-pattern:
		 * this is a schema-change path that runs once per install, ever.
		 */
		if ( '' !== $installed && (string) GAMELIB_DB_VERSION !== $installed ) {
			self::drop_legacy_name_index();
		}

		update_option( self::VERSION_OPTION, GAMELIB_DB_VERSION );
	}

	/**
	 * Drop the version-2 `name` index from `gamelib_games` (CO-4/PB-1).
	 *
	 * Version 2 declared `KEY name (name(191))` for the two title sorts, and
	 * ADR-032 established that it cannot serve them. Removing it from the
	 * declaration stopped new installs from creating it and did nothing for the
	 * ones that had it: `dbDelta()` adds indexes, never drops them.
	 *
	 * The probe comes first so the `ALTER TABLE` is issued only where there is
	 * something to alter — the statement is an error on MySQL when the index is
	 * absent. Errors are suppressed around the probe because it is not portable:
	 * on Playground's SQLite driver `SHOW INDEX` answers an empty set (measured
	 * on the shared instance), which reads here as "nothing to drop" and is the
	 * right answer for a database that never had the index.
	 *
	 * @return void
	 */
	private static function drop_legacy_name_index() {
		global $wpdb;

		$games      = self::table( 'games' );
		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema read on the upgrade path; there is no core API for it, and caching a one-shot deploy-window probe would be wrong.
		$index = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $games is self::table(); the one value is a bound placeholder.
			$wpdb->prepare( "SHOW INDEX FROM `{$games}` WHERE Key_name = %s", 'name' )
		);

		$wpdb->suppress_errors( $suppressed );

		if ( empty( $index ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- DROP INDEX takes identifiers, which prepare() cannot bind; both come from self::table() and a class-chosen literal, never from input.
		$wpdb->query( "ALTER TABLE `{$games}` DROP INDEX `name`" );
	}

	/**
	 * Bring the database up to the running code's schema version.
	 *
	 * Runs on `init` but does nothing — one autoloaded option read — unless the
	 * stored version differs from `GAMELIB_DB_VERSION`. It also covers the case
	 * where the plugin's files arrived without the activation hook ever firing,
	 * which is how mounted plugins reach a Playground instance.
	 *
	 * Two guards stand between the version check and `dbDelta()` (VIP-2), and
	 * both exist because of what a *platform* deploy looks like: the new
	 * constant reaches every container at once and nothing re-fires activation,
	 * so without them every in-flight request would run `dbDelta()` against the
	 * same eight tables until one won the `update_option()` race — and the
	 * `ALTER TABLE` metadata locks would block reads of the tables behind
	 * `/my-library/`, `/activity/` and every game page.
	 *
	 * 1. Only wp-admin, cron, and WP-CLI may upgrade. A schema bump therefore
	 *    applies on the first wp-admin or cron request after a deploy (or via
	 *    `wp eval`), which the README states.
	 * 2. Of those, one claims the work with `wp_cache_add()`. This guards no
	 *    cached read — it is a coordination entry, not a `wp_cache_get`/`set`
	 *    pair standing in for one (AC-NFR-010a, Never Do #15) — and a lost
	 *    claim simply means another process is already doing it.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( (string) GAMELIB_DB_VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}

		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- A 5-minute deploy-window claim, not a cached read: it holds no data and nothing reads its value.
		if ( ! wp_cache_add( self::UPGRADE_LOCK, 1, GameLib_Cache::GROUP, 5 * MINUTE_IN_SECONDS ) ) {
			return;
		}

		self::install();
	}

	/**
	 * The `CREATE TABLE` statement for each table (§6 Data Model).
	 *
	 * All datetimes are stored UTC by their writers (`gmdate()`); statuses,
	 * buckets, and event types are `varchar` validated against PHP whitelists
	 * rather than ENUM, so the DDL survives SQLite translation.
	 *
	 * @param string $charset_collate Result of `$wpdb->get_charset_collate()`.
	 * @return string[] One statement per table.
	 */
	private static function schema_statements( $charset_collate ) {
		$statements = array();

		$games = self::table( 'games' );

		/*
		 * post_id is signed on purpose: ADR-003 claims the row for post
		 * creation with a -1 sentinel before the winner writes the real post
		 * ID, and an unsigned column cannot hold that sentinel. See
		 * principal/adr/006-signed-post-id-claim-column.md.
		 *
		 * There is deliberately **no index on `name`** (PB-1), and version 3
		 * drops it from any database that has one — see
		 * {@see GameLib_Schema::drop_legacy_name_index()}, because `dbDelta()`
		 * cannot. Version 2 added `KEY name (name(191))` for the two title sorts
		 * and it could not serve them, for two independent reasons: the sort is
		 * `ORDER BY g.name, l.id` on a join whose `WHERE l.user_id = %d` forces
		 * `gamelib_library` to drive, and MySQL can only resolve an ORDER BY from
		 * an index on the first non-constant table; and a *prefix* index cannot
		 * resolve an ORDER BY even single-table, because it stores only the
		 * prefix. So it changed no read plan while every write to this table —
		 * 500 rows a tick on the hourly refresh, plus per-chunk import hydration
		 * — maintained a fourth secondary index. The title sort still filesorts;
		 * see principal/adr/032-title-sort-filesort-is-a-known-limitation.md for
		 * the shape that would fix it and why it is not this change.
		 */
		$statements[] = "CREATE TABLE {$games} (
	igdb_id bigint(20) unsigned NOT NULL,
	name varchar(255) NOT NULL,
	slug varchar(200) NOT NULL,
	summary text NULL,
	first_release_date bigint(20) unsigned NULL,
	cover_image_id varchar(64) NULL,
	platforms longtext NULL,
	genres longtext NULL,
	total_rating decimal(5,2) NULL,
	total_rating_count int(10) unsigned NULL,
	igdb_url varchar(255) NULL,
	post_id bigint(20) NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (igdb_id),
	UNIQUE KEY slug (slug),
	KEY post_id (post_id),
	KEY updated_at (updated_at)
) {$charset_collate};";

		$library = self::table( 'library' );

		$statements[] = "CREATE TABLE {$library} (
	id bigint(20) unsigned NOT NULL auto_increment,
	user_id bigint(20) unsigned NOT NULL,
	igdb_id bigint(20) unsigned NOT NULL,
	status varchar(20) NOT NULL,
	added_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY user_game (user_id,igdb_id),
	KEY user_status (user_id,status,added_at),
	KEY game (igdb_id)
) {$charset_collate};";

		$follows = self::table( 'follows' );

		$statements[] = "CREATE TABLE {$follows} (
	follower_id bigint(20) unsigned NOT NULL,
	followed_id bigint(20) unsigned NOT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (follower_id,followed_id),
	KEY followed (followed_id)
) {$charset_collate};";

		$activity = self::table( 'activity' );

		$statements[] = "CREATE TABLE {$activity} (
	id bigint(20) unsigned NOT NULL auto_increment,
	user_id bigint(20) unsigned NOT NULL,
	type varchar(32) NOT NULL,
	igdb_id bigint(20) unsigned NULL,
	meta longtext NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY actor (user_id,id),
	KEY created (created_at)
) {$charset_collate};";

		$invites = self::table( 'invites' );

		$statements[] = "CREATE TABLE {$invites} (
	id bigint(20) unsigned NOT NULL auto_increment,
	code char(32) NOT NULL,
	issuer_id bigint(20) unsigned NOT NULL,
	redeemer_id bigint(20) unsigned NULL,
	status varchar(16) NOT NULL,
	created_at datetime NOT NULL,
	redeemed_at datetime NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY code (code),
	KEY issuer (issuer_id)
) {$charset_collate};";

		$imports = self::table( 'imports' );

		/*
		 * `cursor` is a reserved word in MySQL 8, so it is back-quoted here
		 * and in every query that touches it. dbDelta strips the quotes before
		 * comparing field names, so the column is still diffed normally.
		 */
		$statements[] = "CREATE TABLE {$imports} (
	id bigint(20) unsigned NOT NULL auto_increment,
	user_id bigint(20) unsigned NOT NULL,
	source varchar(10) NOT NULL,
	status varchar(20) NOT NULL,
	`cursor` int(10) unsigned NOT NULL default 0,
	attempts tinyint(3) unsigned NOT NULL default 0,
	next_attempt_at datetime NULL,
	counts longtext NULL,
	error text NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY user (user_id)
) {$charset_collate};";

		$import_items = self::table( 'import_items' );

		$statements[] = "CREATE TABLE {$import_items} (
	id bigint(20) unsigned NOT NULL auto_increment,
	import_id bigint(20) unsigned NOT NULL,
	source_name varchar(255) NOT NULL,
	steam_appid bigint(20) unsigned NULL,
	igdb_id bigint(20) unsigned NULL,
	bucket varchar(20) NOT NULL,
	candidates longtext NULL,
	note varchar(255) NULL,
	PRIMARY KEY  (id),
	KEY import_bucket (import_id,bucket)
) {$charset_collate};";

		$steam_map = self::table( 'steam_map' );

		/*
		 * A row with a NULL igdb_id is the negative cache: an appid known not
		 * to match anything in IGDB, so a re-import skips it (AC-045).
		 */
		$statements[] = "CREATE TABLE {$steam_map} (
	steam_appid bigint(20) unsigned NOT NULL,
	igdb_id bigint(20) unsigned NULL,
	matched_at datetime NOT NULL,
	PRIMARY KEY  (steam_appid)
) {$charset_collate};";

		return $statements;
	}
}
