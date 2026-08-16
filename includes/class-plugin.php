<?php
/**
 * Plugin container / loader.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * Instantiates every service lazily and registers their WordPress hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Service instances, keyed by short name.
	 *
	 * @var array<string, object>
	 */
	private $services = array();

	/**
	 * Get the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Wire up every service's hooks. Runs once on plugins_loaded.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->activator()->register();
		$this->router()->register();
		$this->assets()->register();
		$this->settings()->register();
		$this->rest()->register();
		$this->registration()->register();
		$this->visibility()->register();
	}

	/**
	 * Load the plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'game-library-3', false, dirname( plugin_basename( GL_FILE ) ) . '/languages' );
	}

	/**
	 * Fetch or build a service.
	 *
	 * @param string $key   Short service key.
	 * @param string $class Fully-qualified class name.
	 * @return object
	 */
	private function service( $key, $class ) {
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = new $class();
		}
		return $this->services[ $key ];
	}

	/**
	 * Activation / schema service.
	 *
	 * @return Activator
	 */
	public function activator() {
		return $this->service( 'activator', __NAMESPACE__ . '\\Activator' );
	}

	/**
	 * Front-end routing service.
	 *
	 * @return Router
	 */
	public function router() {
		return $this->service( 'router', __NAMESPACE__ . '\\Router' );
	}

	/**
	 * Template renderer service.
	 *
	 * @return Templates
	 */
	public function templates() {
		return $this->service( 'templates', __NAMESPACE__ . '\\Templates' );
	}

	/**
	 * Asset enqueue service.
	 *
	 * @return Assets
	 */
	public function assets() {
		return $this->service( 'assets', __NAMESPACE__ . '\\Assets' );
	}

	/**
	 * Settings / credentials service.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->service( 'settings', __NAMESPACE__ . '\\Settings' );
	}

	/**
	 * IGDB API client service.
	 *
	 * @return Igdb_Client
	 */
	public function igdb() {
		return $this->service( 'igdb', __NAMESPACE__ . '\\Igdb_Client' );
	}

	/**
	 * Games repository service.
	 *
	 * @return Games_Repository
	 */
	public function games() {
		return $this->service( 'games', __NAMESPACE__ . '\\Games_Repository' );
	}

	/**
	 * Library repository service.
	 *
	 * @return Library_Repository
	 */
	public function library() {
		return $this->service( 'library', __NAMESPACE__ . '\\Library_Repository' );
	}

	/**
	 * Follows repository service.
	 *
	 * @return Follows_Repository
	 */
	public function follows() {
		return $this->service( 'follows', __NAMESPACE__ . '\\Follows_Repository' );
	}

	/**
	 * Activity repository service.
	 *
	 * @return Activity_Repository
	 */
	public function activity() {
		return $this->service( 'activity', __NAMESPACE__ . '\\Activity_Repository' );
	}

	/**
	 * Invites repository service.
	 *
	 * @return Invites_Repository
	 */
	public function invites() {
		return $this->service( 'invites', __NAMESPACE__ . '\\Invites_Repository' );
	}

	/**
	 * Library visibility service.
	 *
	 * @return Visibility
	 */
	public function visibility() {
		return $this->service( 'visibility', __NAMESPACE__ . '\\Visibility' );
	}

	/**
	 * REST controller service.
	 *
	 * @return Rest_Controller
	 */
	public function rest() {
		return $this->service( 'rest', __NAMESPACE__ . '\\Rest_Controller' );
	}

	/**
	 * Registration / lifecycle service.
	 *
	 * @return Registration
	 */
	public function registration() {
		return $this->service( 'registration', __NAMESPACE__ . '\\Registration' );
	}
}
