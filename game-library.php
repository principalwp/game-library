<?php
/**
 * Plugin Name:       Game Library
 * Plugin URI:        https://principalwp.com
 * Description:       An invite-only community platform where members build and
 *                     share personal video-game libraries backed by the IGDB
 *                     catalog — search, curate, follow, and browse.
 * Version:            1.0.3
 * Requires at least:  6.4
 * Requires PHP:       8.4
 * Author:             Principal WP
 * Author URI:         https://principalwp.com
 * License:             GPL v2 or later
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:         game-library
 * Domain Path:         /languages
 *
 * @package Game_Library
 */

namespace Game_Library;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 1.0.1: widens gl_games.first_release_date from `int(10) unsigned` to
// `bigint(20)` (CO-4) — the version bump is what makes Activator's own
// maybe_upgrade() re-run dbDelta() on an already-activated install; see
// Schema::games_ddl()'s own docblock.
// 1.0.2: adds a non-prefix `name_order (name, igdb_id)` index to gl_games
// (PB-6) — the catalog listing's `ORDER BY g.name ASC, g.igdb_id ASC` over
// every referenced game could not use the existing `name(50)` prefix
// index for the sort, forcing a filesort on every cache-miss regeneration;
// see Schema::games_ddl()'s own docblock.
// 1.0.3 (cycle-7): one bump covers four additions across three tables —
// PB-5 adds `igdb_date (igdb_id, date_added, id)` to gl_library_entries;
// PB-7 adds `date_created (date_created, id)` to gl_activity; MR-6 adds
// `inviter_channel_window`/`redeemed_user_id`/`date_created` to gl_invites
// and MR-4 adds gl_invites.resend_count. A single bump so `maybe_upgrade()`/
// `wp game-library migrate` applies all four in one dbDelta() pass — see
// each table's own *_ddl() docblock in Schema.
define( 'GAME_LIBRARY_VERSION', '1.0.3' );
define( 'GAME_LIBRARY_PLUGIN_FILE', __FILE__ );

// Only GAME_LIBRARY_VERSION (a literal) and GAME_LIBRARY_PLUGIN_FILE (backed
// by the magic constant __FILE__) are exported as constants. PHPStan's
// cross-file constant discovery cannot type a constant whose value comes
// from a function call (e.g. plugin_dir_path()/plugin_dir_url()) unless the
// define() and the read happen in the same file, so every other file that
// needs the plugin directory, URL, or basename derives it locally from
// GAME_LIBRARY_PLUGIN_FILE — e.g. `plugin_dir_path( GAME_LIBRARY_PLUGIN_FILE )`
// — rather than reading a second, pre-computed constant.

/**
 * Namespace-to-path autoloader for `Game_Library\`.
 *
 * Maps `Game_Library\Igdb\Client` to `includes/igdb/class-client.php` — the
 * sub-namespace (if any) becomes a lower-cased sub-directory under
 * `includes/`, and the short class name becomes a WordPress-style
 * `class-{kebab-case}.php` file name. Classes with no sub-namespace (e.g.
 * `Game_Library\Plugin`) map directly under `includes/`.
 *
 * @param string $class_name Fully-qualified class name being autoloaded.
 */
spl_autoload_register(
	function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$segments        = explode( '\\', $relative_class );
		$short_class     = array_pop( $segments );
		$file_name       = 'class-' . strtolower( str_replace( '_', '-', $short_class ) ) . '.php';
		$sub_path        = $segments ? strtolower( implode( '/', $segments ) ) . '/' : '';
		$plugin_dir      = plugin_dir_path( GAME_LIBRARY_PLUGIN_FILE );
		$file            = $plugin_dir . 'includes/' . $sub_path . $file_name;

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Activator::class, 'deactivate' ) );

// Boot the plugin unconditionally so its hooks (including the `init` callback
// that `Activator::activate()` invokes directly — see class-activator.php)
// are registered on every request, including the activation request itself.
Plugin::instance();

// VIP-1: registers `wp game-library migrate`, the out-of-band replacement
// for the unconditional-on-every-request Activator::maybe_upgrade() call
// Plugin::on_init() used to make — see that method's own docblock and
// Cli\Migrate_Command's. Guarded the same way core/other plugins guard their
// own WP-CLI-only registration: the WP_CLI class (and the add_command()
// method this calls) only exists under the WP-CLI runtime.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	Cli\Migrate_Command::register();
}
