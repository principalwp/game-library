<?php
/**
 * Plugin loader: requires component classes and wires them onto WordPress hooks.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instantiates and wires the feature classes. Holds no business logic of its own —
 * each service registers its own hooks via its hooks() method.
 */
final class Game_Library_Loader {

	/**
	 * Singleton instance.
	 *
	 * @var Game_Library_Loader|null
	 */
	private static $instance = null;

	/**
	 * Whether run() has already wired the services.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Shared IGDB client instance.
	 *
	 * @var Game_Library_IGDB_Client|null
	 */
	private $igdb_client = null;

	/**
	 * Shared invite service instance.
	 *
	 * @var Game_Library_Invite_Service|null
	 */
	private $invite_service = null;

	/**
	 * Get the singleton loader.
	 *
	 * @return Game_Library_Loader
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Require every component class file. Safe to call on every request.
	 *
	 * @return void
	 */
	public function require_dependencies() {
		$includes = array(
			'class-activator.php',
			'class-activity.php',
			'class-secret-store.php',
			'class-igdb-client.php',
			'class-settings.php',
			'class-search-controller.php',
			'class-library-service.php',
			'class-library-controller.php',
			'class-follow-controller.php',
			'class-invite-service.php',
			'class-invite-admin.php',
			'class-registration.php',
			'class-router.php',
			'class-privacy.php',
		);
		foreach ( $includes as $file ) {
			require_once GAME_LIBRARY_PATH . 'includes/' . $file;
		}
	}

	/**
	 * Instantiate the feature classes and register their hooks.
	 *
	 * @return void
	 */
	public function run() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->igdb_client    = new Game_Library_IGDB_Client();
		$this->invite_service = new Game_Library_Invite_Service();

		$services = array(
			new Game_Library_Router(),
			new Game_Library_Settings( $this->igdb_client ),
			new Game_Library_Search_Controller( $this->igdb_client ),
			new Game_Library_Library_Controller(),
			new Game_Library_Follow_Controller(),
			$this->invite_service,
			new Game_Library_Invite_Admin( $this->invite_service ),
			new Game_Library_Registration( $this->invite_service ),
			new Game_Library_Privacy(),
		);

		foreach ( $services as $service ) {
			$service->hooks();
		}
	}
}
