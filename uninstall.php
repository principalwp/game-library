<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Collections are intentionally retained. Define GCOLLECTOR_DELETE_DATA as true
// in wp-config.php before uninstalling to remove all plugin data.
if ( ! defined( 'GCOLLECTOR_DELETE_DATA' ) || true !== GCOLLECTOR_DELETE_DATA ) {
	return;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'gc_library',
	$wpdb->prefix . 'gc_follows',
	$wpdb->prefix . 'gc_activities',
	$wpdb->prefix . 'gc_invitations',
	$wpdb->prefix . 'gc_games',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'gcollector_db_version' );
delete_option( 'gcollector_library_page_id' );
delete_option( 'gcollector_igdb_client_id' );
delete_option( 'gcollector_igdb_client_secret' );
delete_transient( 'gcollector_igdb_token' );
