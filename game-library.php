<?php
/**
 * Plugin Name:       Game Library
 * Plugin URI:        https://example.com/game-library
 * Description:        Invite-only library for video-game collectors — search IGDB, track games by status, follow members, and read personal and site-wide activity feeds. Browsing is public; only registration is invite-gated.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Game Library
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       game-library
 *
 * @package Game_Library
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAME_LIBRARY_VERSION', '1.0.0' );
define( 'GAME_LIBRARY_DB_VERSION', '1' );
define( 'GAME_LIBRARY_FILE', __FILE__ );
define( 'GAME_LIBRARY_PATH', plugin_dir_path( __FILE__ ) );
define( 'GAME_LIBRARY_URL', plugin_dir_url( __FILE__ ) );
define( 'GAME_LIBRARY_REST_NAMESPACE', 'game-library/v1' );

require_once GAME_LIBRARY_PATH . 'includes/class-loader.php';

// Load every component class at plugin load so activation/uninstall callbacks resolve.
Game_Library_Loader::instance()->require_dependencies();

// Top-level lifecycle hooks (must be registered at file load, not inside another hook).
register_activation_hook( __FILE__, array( 'Game_Library_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Game_Library_Activator', 'deactivate' ) );

// Wire the feature classes onto their hooks once all plugins are loaded.
add_action( 'plugins_loaded', array( Game_Library_Loader::instance(), 'run' ) );
