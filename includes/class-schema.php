<?php
/**
 * Table names and dbDelta DDL for the plugin's five custom tables.
 *
 * @package Game_Library
 */

namespace Game_Library;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Schema.
 *
 * The single source of prefixed table names for the plugin's five custom
 * tables — cached game metadata, library entries, follows, activity, and
 * invites — and the `dbDelta()` DDL that creates and upgrades them
 * (DD-001/ADR-001). No other class concatenates a table name inline; every
 * repository reads the prefixed name from the accessor methods below.
 *
 * These tables live outside `wp_posts`/`wp_postmeta`; if this plugin is later
 * deployed to VIP, custom tables like these are recommended to go through
 * VIP's database review process for backup/restore compatibility.
 */
final class Schema {

	/**
	 * Unprefixed name of the cached-game-metadata table.
	 *
	 * @var string
	 */
	public const TABLE_GAMES = 'gl_games';

	/**
	 * Unprefixed name of the library-entries table.
	 *
	 * @var string
	 */
	public const TABLE_LIBRARY_ENTRIES = 'gl_library_entries';

	/**
	 * Unprefixed name of the follows table.
	 *
	 * @var string
	 */
	public const TABLE_FOLLOWS = 'gl_follows';

	/**
	 * Unprefixed name of the activity table.
	 *
	 * @var string
	 */
	public const TABLE_ACTIVITY = 'gl_activity';

	/**
	 * Unprefixed name of the invites table.
	 *
	 * @var string
	 */
	public const TABLE_INVITES = 'gl_invites';

	/**
	 * The prefixed `gl_games` table name — cached IGDB metadata, keyed by
	 * IGDB id (D26, D34).
	 *
	 * @return string
	 */
	public static function games_table() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_GAMES;
	}

	/**
	 * The prefixed `gl_library_entries` table name — one row per member per
	 * game (D35).
	 *
	 * @return string
	 */
	public static function library_entries_table() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_LIBRARY_ENTRIES;
	}

	/**
	 * The prefixed `gl_follows` table name — one-directional follow edges
	 * (D8).
	 *
	 * @return string
	 */
	public static function follows_table() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_FOLLOWS;
	}

	/**
	 * The prefixed `gl_activity` table name — one row per transition, no
	 * dedup (D37).
	 *
	 * @return string
	 */
	public static function activity_table() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_ACTIVITY;
	}

	/**
	 * The prefixed `gl_invites` table name — four-state lifecycle (D40).
	 *
	 * @return string
	 */
	public static function invites_table() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_INVITES;
	}

	/**
	 * Creates (or upgrades) the plugin's five custom tables via `dbDelta()`.
	 *
	 * Called from `Activator::install_or_upgrade()` on activation and on
	 * every version bump. `dbDelta()` lives in
	 * `wp-admin/includes/upgrade.php`, which is not autoloaded outside
	 * wp-admin — `Activator::maybe_upgrade()` runs on every `init`, including
	 * logged-out front-end requests, so the file is required explicitly on
	 * every call rather than assumed to already be loaded. `dbDelta()` also
	 * needs lowercase column types and exactly two spaces between
	 * `PRIMARY KEY` and its opening parenthesis to parse each table's
	 * indexes correctly; every statement below follows both rules, and each
	 * `CREATE TABLE` is idempotent — re-running it against an
	 * already-current table is a no-op.
	 *
	 * @return void
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = self::games_ddl( $charset_collate )
			. self::library_entries_ddl( $charset_collate )
			. self::follows_ddl( $charset_collate )
			. self::activity_ddl( $charset_collate )
			. self::invites_ddl( $charset_collate );

		dbDelta( $sql );
	}

	/**
	 * DDL for `gl_games`.
	 *
	 * Indexes: PK `igdb_id`; UNIQUE `slug`; KEY `name(50)`; KEY
	 * `name_order (name, igdb_id)`.
	 *
	 * `first_release_date` is `bigint(20)`, not `int(10) unsigned` (CO-4):
	 * IGDB returns a real negative Unix timestamp for any game released
	 * before 1970 — an unsigned column can never store that value,
	 * whatever the application-layer sanitizer does. No SQL comment is
	 * embedded in the DDL string itself: `dbDelta()` parses `CREATE TABLE`
	 * field definitions with its own regexes and does not reliably handle
	 * arbitrary embedded `--` comments.
	 *
	 * `name_order (name, igdb_id)` (PB-6, 1.0.2): a real, non-prefix index
	 * `Game_Repository::get_referenced_games()`/`referenced_game_slugs()`
	 * can use to serve their `ORDER BY g.name ASC, g.igdb_id ASC` directly
	 * from the index rather than a filesort over the whole referenced-game
	 * result set — the pre-existing `name (name(50))` key is a 50-character
	 * prefix, which MySQL cannot use to satisfy a sort on the full column.
	 * `varchar(255)` in `utf8mb4` is 1,020 bytes, within InnoDB's 3,072-byte
	 * index-key limit under the `DYNAMIC`/`COMPRESSED` row formats (the
	 * default since MySQL 5.7), so a full, non-prefix index on this column
	 * is safe to add. Left the existing prefix index in place rather than
	 * replacing it — its own callers (`search_by_name()`) are unaffected
	 * either way, and removing it is out of this fix's scope.
	 *
	 * @param string $charset_collate Result of `$wpdb->get_charset_collate()`.
	 * @return string
	 */
	private static function games_ddl( $charset_collate ) {
		$table = self::games_table();

		return "CREATE TABLE {$table} (
			igdb_id bigint(20) unsigned NOT NULL,
			slug varchar(200) NOT NULL,
			name varchar(255) NOT NULL,
			summary longtext NULL,
			first_release_date bigint(20) NULL,
			cover_image_id varchar(64) NULL,
			genres text NOT NULL,
			platforms text NOT NULL,
			aggregated_rating decimal(5,2) NULL,
			igdb_url varchar(255) NULL,
			date_cached datetime NOT NULL,
			date_refreshed datetime NOT NULL,
			PRIMARY KEY  (igdb_id),
			UNIQUE KEY slug (slug),
			KEY name (name(50)),
			KEY name_order (name, igdb_id)
		) {$charset_collate};\n";
	}

	/**
	 * DDL for `gl_library_entries`.
	 *
	 * Indexes: PK `id`; UNIQUE `user_game (user_id, igdb_id)`; KEY
	 * `user_status (user_id, status, date_added)`; KEY
	 * `user_date (user_id, date_added, id)`; KEY `igdb_id (igdb_id)`; KEY
	 * `igdb_date (igdb_id, date_added, id)` (PB-5, 1.0.3).
	 * `user_date` (PB-12) exists because `Library_Repository::get_page()`'s
	 * default, no-status-filter listing query — the one `/my-library/` and
	 * `/library/{nicename}/` both run on every uncached page view — is
	 * `WHERE user_id = %d ORDER BY date_added DESC, id DESC`; `user_status`
	 * satisfies the `WHERE` but, with `status` sitting between `user_id` and
	 * `date_added`, cannot serve that `ORDER BY` for the no-filter case,
	 * forcing a filesort of the member's whole row set on every such read.
	 * `igdb_date (igdb_id, date_added, id)` (PB-5, cycle-7) mirrors that same
	 * reasoning for `Library_Repository::public_holders_for_game()`'s
	 * `WHERE e.igdb_id = %d ORDER BY e.date_added DESC, e.id DESC` on
	 * `/games/{slug}/` — the pre-existing single-column `igdb_id` key can
	 * serve the `WHERE` but not that `ORDER BY`, forcing MySQL to fetch every
	 * row for the game, join each to `wp_usermeta`, then filesort the whole
	 * joined set to return one page. Left the single-column `igdb_id` key in
	 * place; `dbDelta()` adds the new composite key alongside it.
	 *
	 * @param string $charset_collate Result of `$wpdb->get_charset_collate()`.
	 * @return string
	 */
	private static function library_entries_ddl( $charset_collate ) {
		$table = self::library_entries_table();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			igdb_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL,
			date_added datetime NOT NULL,
			date_modified datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_game (user_id, igdb_id),
			KEY user_status (user_id, status, date_added),
			KEY user_date (user_id, date_added, id),
			KEY igdb_id (igdb_id),
			KEY igdb_date (igdb_id, date_added, id)
		) {$charset_collate};\n";
	}

	/**
	 * DDL for `gl_follows`.
	 *
	 * Indexes: PK `id`; UNIQUE `edge (follower_id, following_id)`; KEY
	 * `following_id (following_id)`.
	 *
	 * @param string $charset_collate Result of `$wpdb->get_charset_collate()`.
	 * @return string
	 */
	private static function follows_ddl( $charset_collate ) {
		$table = self::follows_table();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			follower_id bigint(20) unsigned NOT NULL,
			following_id bigint(20) unsigned NOT NULL,
			date_created datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY edge (follower_id, following_id),
			KEY following_id (following_id)
		) {$charset_collate};\n";
	}

	/**
	 * DDL for `gl_activity`.
	 *
	 * Indexes: PK `id`; KEY `user_date (user_id, date_created, id)`; KEY
	 * `igdb_id (igdb_id)`; KEY `object_user_id (object_user_id)`; KEY
	 * `date_created (date_created, id)` (PB-7, 1.0.3).
	 *
	 * PB-10: `user_date` gives `Activity_Repository::get_feed()`'s
	 * `WHERE user_id IN (…) ORDER BY date_created DESC, id DESC` query one
	 * ordered range per followed member, not one ordered stream across a
	 * whole `IN (…)` set — see that method's own docblock for the full
	 * reasoning and why this is a known, currently-acceptable limitation
	 * rather than a fix landed this cycle.
	 *
	 * `date_created (date_created, id)` (PB-7, cycle-7) is for
	 * `Activity_Repository::get_recent()` — the moderation screen's `SELECT
	 * ... ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d` with no
	 * `WHERE` at all. `user_date` leads with `user_id`, so it cannot serve
	 * that global ordering; without a key on `date_created` alone MySQL read
	 * every row and sorted it to return one page, and this table is
	 * append-only with no retention policy.
	 *
	 * @param string $charset_collate Result of `$wpdb->get_charset_collate()`.
	 * @return string
	 */
	private static function activity_ddl( $charset_collate ) {
		$table = self::activity_table();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			event_type varchar(20) NOT NULL,
			igdb_id bigint(20) unsigned NULL,
			object_user_id bigint(20) unsigned NULL,
			status_from varchar(20) NULL,
			status_to varchar(20) NULL,
			date_created datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_date (user_id, date_created, id),
			KEY igdb_id (igdb_id),
			KEY object_user_id (object_user_id),
			KEY date_created (date_created, id)
		) {$charset_collate};\n";
	}

	/**
	 * DDL for `gl_invites`.
	 *
	 * Indexes: PK `id`; UNIQUE `code (code)`; KEY
	 * `inviter_window (inviter_id, status, date_created)`; KEY
	 * `status_expires (status, date_expires)`; KEY
	 * `inviter_channel_window (inviter_id, channel, date_created)`; KEY
	 * `redeemed_user_id (redeemed_user_id)`; KEY
	 * `date_created (date_created, id)` (all three MR-6, 1.0.3).
	 *
	 * MR-6 (cycle-7): three query shapes had no usable index. (1)
	 * `Invite_Repository::email_invite_count_in_window()` filters
	 * `inviter_id` + `channel` + `date_created`, but `channel` is in no
	 * index and `status` sits between the two columns `inviter_window`
	 * actually filters on — only the `inviter_id` prefix was usable, and
	 * this method is deliberately uncached (it gates a real send), so it
	 * scanned an inviter's LIFETIME rows on every send rather than the
	 * 30-day window it filters on. `inviter_channel_window (inviter_id,
	 * channel, date_created)` makes that count a covering index range scan
	 * with no row lookups, and also serves `get_for_inviter()`'s `ORDER BY
	 * date_created DESC, id DESC` without a filesort. (2) three queries
	 * filter on `redeemed_user_id` (`Privacy`'s exporter/eraser, and
	 * `Invite_Service`'s own redemption-audit reads), which was in no index
	 * at all — each a full scan. (3) `get_page()`'s `ORDER BY date_created`
	 * listing had no index that could serve it either — `date_created (date_created,
	 * id)` closes both this and `Activity_Repository::get_recent()`'s
	 * identical shape (see `activity_ddl()`'s own docblock).
	 *
	 * `resend_count` (MR-4, 1.0.3): a durable, atomically-claimable slot
	 * counter for `resend_invite()` — see
	 * `Invite_Repository::consume_resend_slot()`'s own docblock for why a
	 * resend needs one at all (a resend inserts no `gl_invites` row, so
	 * nothing else in this table counts it).
	 *
	 * @param string $charset_collate Result of `$wpdb->get_charset_collate()`.
	 * @return string
	 */
	private static function invites_ddl( $charset_collate ) {
		$table = self::invites_table();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(32) NOT NULL,
			inviter_id bigint(20) unsigned NOT NULL,
			email varchar(100) NULL,
			channel varchar(20) NOT NULL,
			status varchar(20) NOT NULL,
			date_created datetime NOT NULL,
			date_expires datetime NOT NULL,
			date_redeemed datetime NULL,
			redeemed_user_id bigint(20) unsigned NULL,
			resend_count int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY inviter_window (inviter_id, status, date_created),
			KEY status_expires (status, date_expires),
			KEY inviter_channel_window (inviter_id, channel, date_created),
			KEY redeemed_user_id (redeemed_user_id),
			KEY date_created (date_created, id)
		) {$charset_collate};\n";
	}
}
