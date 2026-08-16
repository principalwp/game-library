<?php
/**
 * Uninstall handler.
 *
 * Preserve-by-default: only when the admin has explicitly opted in via the
 * "Remove all game-library data on uninstall" setting does this drop the four
 * custom tables and delete every plugin option. Otherwise it deletes nothing.
 *
 * @package Game_Library
 */

// Guard: never run outside the uninstall lifecycle.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Enumerate every option the plugin writes.
$game_library_options = array(
	'game_library_igdb_client_id',
	'game_library_igdb_client_secret',
	'game_library_igdb_token',
	'game_library_invite_allowance',
	'game_library_wipe_on_uninstall',
	'game_library_db_version',
);

// Preserve by default — bail before touching anything unless the flag is set.
if ( ! get_option( 'game_library_wipe_on_uninstall', 0 ) ) {
	return;
}

global $wpdb;

$game_library_tables = array(
	$wpdb->prefix . 'game_library_entries',
	$wpdb->prefix . 'game_library_follows',
	$wpdb->prefix . 'game_library_activity',
	$wpdb->prefix . 'game_library_invites',
);

foreach ( $game_library_tables as $game_library_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier passed through the %i placeholder.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $game_library_table ) );
}

foreach ( $game_library_options as $game_library_option ) {
	delete_option( $game_library_option );
}

// Clean up the error transient and any lingering search-result cache transients.
delete_transient( 'game_library_last_igdb_error' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall cleanup of dynamically-keyed transients.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_game_library_search\_%' OR option_name LIKE '\_transient\_timeout\_game_library_search\_%'"
);
