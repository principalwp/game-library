<?php
/**
 * Plugin Name:       Game Library
 * Description:       An invite-only, IGDB-backed game-tracking community: invite links, a personal library at four statuses, follows, an activity feed and a member directory.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            PrincipalWP
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       game-library-3
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

define( 'GL_VERSION', '1.0.0' );
define( 'GL_PATH', plugin_dir_path( __FILE__ ) );
define( 'GL_URL', plugin_dir_url( __FILE__ ) );
define( 'GL_FILE', __FILE__ );

if ( ! defined( 'GL_MEMBER_ROLE' ) ) {
	define( 'GL_MEMBER_ROLE', 'subscriber' );
}

/**
 * PSR-ish autoloader for the plugin's own namespace.
 *
 * Maps Game_Library\Foo_Bar -> includes/class-foo-bar.php.
 *
 * @param string $class Fully-qualified class name.
 * @return void
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( __NAMESPACE__ . '\\' ) );
		$relative = strtolower( str_replace( '_', '-', $relative ) );
		$file     = GL_PATH . 'includes/class-' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

/**
 * Convenience accessor for the plugin container.
 *
 * @return Plugin
 */
function gl_plugin() {
	return Plugin::instance();
}

register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Plugin::instance()->boot();
	}
);
