<?php
/**
 * Removes all Game Collector data when the plugin is deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'user_games', 'follows', 'activity', 'invites', 'games' ) as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'gc_' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL
}

delete_option( 'gc_db_version' );
delete_option( 'gc_igdb_client_id' );
delete_option( 'gc_igdb_client_secret' );
delete_option( 'gc_force_registration' );
delete_transient( 'gc_igdb_access_token' );

delete_metadata( 'user', 0, 'gc_invite_code', '', true );
