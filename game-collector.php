<?php
/**
 * Plugin Name:       Game Collector
 * Plugin URI:        https://example.com/game-collector
 * Description:       Invite-only community for video-game collectors. Members search IGDB, track their library (playing / finished / backlog / wishlist), follow each other, and browse an activity feed.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Game Collector
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       game-collector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GC_VERSION', '1.0.0' );
define( 'GC_PLUGIN_FILE', __FILE__ );
define( 'GC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once GC_PLUGIN_DIR . 'includes/class-gc-install.php';
require_once GC_PLUGIN_DIR . 'includes/class-gc-igdb.php';
require_once GC_PLUGIN_DIR . 'includes/class-gc-invites.php';
require_once GC_PLUGIN_DIR . 'includes/class-gc-library.php';
require_once GC_PLUGIN_DIR . 'includes/class-gc-follows.php';
require_once GC_PLUGIN_DIR . 'includes/class-gc-activity.php';
require_once GC_PLUGIN_DIR . 'includes/class-gc-rest.php';
require_once GC_PLUGIN_DIR . 'includes/class-gc-frontend.php';

if ( is_admin() ) {
	require_once GC_PLUGIN_DIR . 'admin/class-gc-admin.php';
}

register_activation_hook( __FILE__, array( 'GC_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GC_Install', 'deactivate' ) );

add_action( 'plugins_loaded', 'gc_bootstrap' );

function gc_bootstrap() {
	GC_Install::maybe_upgrade();
	GC_Invites::init();
	GC_Rest::init();
	GC_Frontend::init();

	if ( is_admin() ) {
		GC_Admin::init();
	}
}
