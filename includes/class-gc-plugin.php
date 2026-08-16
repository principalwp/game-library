<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GCOLLECTOR_Plugin {
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$igdb        = new GCOLLECTOR_IGDB();
		$invitations = new GCOLLECTOR_Invitations();

		new GCOLLECTOR_REST( $igdb );
		new GCOLLECTOR_Admin( $invitations );
		new GCOLLECTOR_Frontend();

		$invitations->hooks();
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'delete_user', array( $this, 'delete_user_data' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'game-collector', false, dirname( plugin_basename( GCOLLECTOR_FILE ) ) . '/languages' );
	}

	public function delete_user_data( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$wpdb->delete( GCOLLECTOR_Database::table( 'library' ), array( 'user_id' => $user_id ), array( '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . GCOLLECTOR_Database::table( 'follows' ) . ' WHERE follower_id = %d OR followed_id = %d',
				$user_id,
				$user_id
			)
		);
		$wpdb->delete( GCOLLECTOR_Database::table( 'activities' ), array( 'actor_id' => $user_id ), array( '%d' ) );
	}
}
