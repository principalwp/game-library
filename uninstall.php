<?php
/**
 * Uninstall cleanup: drop tables, delete options/transients/user meta.
 *
 * @package Game_Library
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$gl_tables = array(
	$wpdb->prefix . 'gl_games',
	$wpdb->prefix . 'gl_library',
	$wpdb->prefix . 'gl_follows',
	$wpdb->prefix . 'gl_activity',
	$wpdb->prefix . 'gl_invites',
);

foreach ( $gl_tables as $gl_table ) {
	// Table name is assembled from $wpdb->prefix only, never request data.
	$wpdb->query( "DROP TABLE IF EXISTS {$gl_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

$gl_options = array(
	'gl_db_version',
	'gl_rewrite_version',
	'gl_igdb_client_id',
	'gl_igdb_client_secret_enc',
	'gl_invite_quota',
);
foreach ( $gl_options as $gl_option ) {
	delete_option( $gl_option );
}

delete_transient( 'gl_igdb_token' );
delete_transient( 'gl_igdb_last_error' );

// Remove the visibility meta for every user.
delete_metadata( 'user', 0, 'gl_library_visibility', '', true );
