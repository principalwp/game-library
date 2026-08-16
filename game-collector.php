<?php
/**
 * Plugin Name: Game Collector
 * Description: An invite-only social game library powered by IGDB.
 * Version: 1.0.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: Game Collector
 * Text Domain: game-collector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GCOLLECTOR_VERSION', '1.0.0' );
define( 'GCOLLECTOR_FILE', __FILE__ );
define( 'GCOLLECTOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'GCOLLECTOR_URL', plugin_dir_url( __FILE__ ) );

require_once GCOLLECTOR_PATH . 'includes/class-gc-database.php';
require_once GCOLLECTOR_PATH . 'includes/class-gc-igdb.php';
require_once GCOLLECTOR_PATH . 'includes/class-gc-invitations.php';
require_once GCOLLECTOR_PATH . 'includes/class-gc-rest.php';
require_once GCOLLECTOR_PATH . 'includes/class-gc-admin.php';
require_once GCOLLECTOR_PATH . 'includes/class-gc-frontend.php';
require_once GCOLLECTOR_PATH . 'includes/class-gc-plugin.php';

register_activation_hook( __FILE__, array( 'GCOLLECTOR_Database', 'activate' ) );

GCOLLECTOR_Plugin::instance();
