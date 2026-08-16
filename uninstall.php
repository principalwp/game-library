<?php
/**
 * Uninstall routine — full cleanup, gated on the `delete_data_on_uninstall`
 * setting (AC-037).
 *
 * WordPress includes this file directly when the plugin is deleted from the
 * Plugins screen (or via `wp plugin uninstall`) without loading the main
 * plugin file first, so this file has no access to the plugin's own
 * autoloader. `class-schema.php`, `class-settings.php`, and
 * `class-activator.php` are all required directly — for the canonical
 * table-name accessors, the `game_library_settings` option contract, and the
 * `game_library_db_version` option's owning constant, respectively — rather
 * than duplicating any of them inline.
 *
 * @package Game_Library
 */

namespace Game_Library;

// Exit if accessed directly, or outside a real uninstall request.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-schema.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-activator.php';
require_once __DIR__ . '/includes/igdb/class-client.php';
require_once __DIR__ . '/includes/class-page-cache.php';
require_once __DIR__ . '/includes/class-roles.php';

// SE-4 (cycle-5): the cached Twitch OAuth bearer and its 429 backoff flag
// are deleted UNCONDITIONALLY, deliberately OUTSIDE the
// delete_data_on_uninstall gate below (unlike every other line in this
// file) — human ruling 6 classifies the cached bearer as credential
// material derived from GAME_LIBRARY_IGDB_CLIENT_SECRET, and leaving
// credential material behind on ANY uninstall (including one that
// preserves member data for a future reinstall) is a different risk
// category than the member-data cleanup delete_data_on_uninstall governs.
// `game_library_igdb_backoff` is not itself a secret, but is deleted
// alongside its credential-adjacent sibling for the same reason, and
// carries only a 60-second TTL regardless. Igdb\Client's own file is
// require_once'd directly (see this file's own class docblock) since this
// file has no access to the plugin's autoloader.
delete_transient( Igdb\Client::TOKEN_TRANSIENT );
delete_transient( 'game_library_igdb_backoff' );

$settings = Settings::all();

// Only ever proceed past this point when the administrator has explicitly
// opted in — every table, row, both options, and every _gl_* user meta key
// is left untouched otherwise (AC-037 (f)).
if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// (a) The five plugin tables.
$tables = array(
	Schema::games_table(),
	Schema::library_entries_table(),
	Schema::follows_table(),
	Schema::activity_table(),
	Schema::invites_table(),
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- $table is one of Schema's five fixed table-name accessors, never user input; DROP TABLE has no $wpdb->prepare()-compatible placeholder for an identifier, and this file runs at most once per uninstall, already gated behind delete_data_on_uninstall above.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// (b), (c) Both plugin options.
delete_option( Settings::OPTION_KEY );
delete_option( Activator::DB_VERSION_OPTION );

// MR9-2 (cycle-9): Roles::BACKFILL_STALLED_OPTION -- written through the
// ordinary update_option()/delete_option() API (unlike the three raw,
// never-through-the-option-API families the sweep below handles), so it
// belongs alongside the two delete_option() calls above, not in that sweep.
delete_option( Roles::BACKFILL_STALLED_OPTION );

// VIP-4 (cycle-8): three option-key families this file previously left
// behind with no cleanup and no TTL — gl_upgrade_lock (Activator's
// maybe_upgrade() single-flight lock, PB-8), gl_regen_* (Game_Repository's
// under_regen_lock() single-flight locks, PB-6 — only ever written under a
// persistent object cache since MR-2's short-circuit, cycle-7, but still
// possible on a VIP deploy of this plugin), and gl_purge_slugs_*
// (Page_Cache::schedule_purge_for_captured_slugs()'s captured-slug rows,
// PB-2/MR-1 cycle-5 — written BEFORE the event that deletes them is
// scheduled, so a deactivation or a lost event orphans the row
// permanently; these rows are also written by the privacy erasure cascade,
// which AC-037 documents as removing every trace). All three are raw
// wp_options rows never written through add_option()/update_option()'s own
// higher-level wrapper in a way this file's two delete_option() calls above
// could name individually, and all three are autoload = false (no
// alloptions inflation), which is why a raw sweep rather than a
// key-by-key delete_option() list is both necessary and safe here.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->options is a wpdb property, never user input; this file runs at most once per uninstall, already gated behind delete_data_on_uninstall above, so this raw sweep is deliberately uncached.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->options is a wpdb property, never user input.
		'gl_upgrade_lock',
		$wpdb->esc_like( 'gl_regen_' ) . '%',
		$wpdb->esc_like( 'gl_purge_slugs_' ) . '%'
	)
);

// The raw DELETE above bypasses delete_option()'s own cache invalidation —
// clear core's option caches the same way Erasure_Service::
// delete_gl_user_meta() already clears the user_meta group after its own
// raw usermeta sweep (see that method's own docblock).
wp_cache_delete( 'notoptions', 'options' );
wp_cache_delete( 'alloptions', 'options' );

// (d) Every scheduled event this plugin can leave behind.
// wp_clear_scheduled_hook() alone (CO-9) — the
// wp_next_scheduled()/wp_unschedule_event() pair this used to also run
// first only ever removes the one occurrence at exactly the timestamp
// wp_next_scheduled() returns, leaving a duplicate entry at any other
// timestamp behind; wp_clear_scheduled_hook() removes every occurrence in
// one call, so it already fully subsumes the pair. Activator::
// unschedule_events() (the deactivation path) now uses the identical call
// for the identical reason, so the two cleanup paths for these hooks agree.
// The four single-event hooks (VIP-4, cycle-8, plus MR-3's own backfill
// continuation) had no cleanup anywhere before this — Page_Cache's own
// public hook constants are used rather than hand-mirrored literals, now
// that this file require_once's that class directly (see this file's own
// class docblock for why a direct require, not a `use`, is what makes a
// second plugin class reachable here).
wp_clear_scheduled_hook( 'game_library_invite_maintenance' );
wp_clear_scheduled_hook( Roles::BACKFILL_CONTINUE_HOOK );
wp_clear_scheduled_hook( Page_Cache::PURGE_GAME_SLUGS_HOOK );
wp_clear_scheduled_hook( Page_Cache::PURGE_FOR_USER_HOOK );
wp_clear_scheduled_hook( Page_Cache::PURGE_CAPTURED_SLUGS_HOOK );

// (e) Every `_gl_*` user meta key, batched at 500 rows per iteration — never
// one unbounded DELETE across a wp_usermeta table that may hold 200k+ rows
// from other plugins. Also selects each row's user_id (CO-10) so the
// affected users' core user_meta object-cache entries can be cleared after
// the raw delete below — a raw $wpdb DELETE against wp_usermeta bypasses
// delete_metadata(), which always ends with wp_cache_delete( $object_id,
// 'user_meta' ) for exactly this reason (the same defect already fixed in
// Erasure_Service::delete_gl_user_meta(), see that method's own docblock).
$batch_size = 500;
$meta_rows  = array();

do {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->usermeta is a core wpdb property, never user input; this file runs at most once per uninstall, already gated behind delete_data_on_uninstall above, so batched reads here are deliberately uncached.
	$meta_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT umeta_id, user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->usermeta is a core wpdb property, never user input.
			$wpdb->esc_like( '_gl_' ) . '%',
			$batch_size
		),
		ARRAY_A
	);

	if ( empty( $meta_rows ) ) {
		break;
	}

	$meta_ids     = array_map( 'absint', wp_list_pluck( $meta_rows, 'umeta_id' ) );
	$placeholders = implode( ', ', array_fill( 0, count( $meta_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->usermeta is a core wpdb property, never user input; this file runs at most once per uninstall, already gated behind delete_data_on_uninstall above, so batched deletes here are deliberately uncached.
	$wpdb->query(
		$wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE umeta_id IN ({$placeholders})", $meta_ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->usermeta is a core wpdb property and $placeholders is a fixed run of %d tokens built by array_fill(), neither is user data; every id is bound through prepare() above.
	);

	foreach ( array_unique( array_map( 'absint', wp_list_pluck( $meta_rows, 'user_id' ) ) ) as $affected_user_id ) {
		wp_cache_delete( $affected_user_id, 'user_meta' );
	}
} while ( count( $meta_rows ) === $batch_size );
