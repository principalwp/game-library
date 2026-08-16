<?php
/**
 * Plugin container — wires every service onto its hooks.
 *
 * @package Game_Library
 */

namespace Game_Library;

use Game_Library\Admin\Invites_List_Table;
use Game_Library\Admin\Moderation_Page;
use Game_Library\Admin\Settings_Page;
use Game_Library\Cron\Invite_Maintenance;
use Game_Library\Invites\Registration;
use Game_Library\Privacy\Privacy;
use Game_Library\Rest\Invite_Controller;
use Game_Library\Rest\Library_Controller;
use Game_Library\Rest\Search_Controller;
use Game_Library\Rest\Social_Controller;
use Game_Library\Seo\Robots;
use Game_Library\Seo\Schema_Org;
use Game_Library\Seo\Sitemap_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin.
 *
 * Singleton that instantiates every service class and attaches it to the
 * hooks it needs. Grows one `register_*` call at a time as later tasks add
 * services; the role model, the conditional asset enqueue, the admin
 * settings/invites/moderation screens, routing (rewrite rules, template
 * resolution, access gates), the search/library/social/invite REST
 * controllers, the robots/canonical/JSON-LD SEO services, the sitemap
 * provider, the privacy exporter/eraser, and the daily invite maintenance
 * cron are wired so far.
 */
final class Plugin {

	/**
	 * The single instance of this class.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Capability and access-control service.
	 *
	 * @var Roles
	 */
	private $roles;

	/**
	 * Conditional CSS/JS enqueue service.
	 *
	 * @var Assets
	 */
	private $assets;

	/**
	 * Admin settings screen and connection test.
	 *
	 * @var Settings_Page
	 */
	private $settings_page;

	/**
	 * Admin invite list screen (filters, resend, revoke).
	 *
	 * @var Invites_List_Table|null Null on a front-end request — see the
	 *                              is_admin() guard in the constructor.
	 */
	private $invites_list_table;

	/**
	 * Admin moderation screen (game correction, refresh, activity delete).
	 *
	 * @var Moderation_Page
	 */
	private $moderation_page;

	/**
	 * Rewrite rules, template resolution, and access gates.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * `GET /search` REST controller.
	 *
	 * @var Search_Controller
	 */
	private $search_controller;

	/**
	 * Library CRUD and manual-refresh REST controller.
	 *
	 * @var Library_Controller
	 */
	private $library_controller;

	/**
	 * Follow, activity feed, member directory, and profile-visibility REST
	 * controller.
	 *
	 * @var Social_Controller
	 */
	private $social_controller;

	/**
	 * Invite create/revoke/resend REST controller.
	 *
	 * @var Invite_Controller
	 */
	private $invite_controller;

	/**
	 * `/join/` form handling and account creation (CO-12) — instantiated here
	 * (not inside `templates/join.php`) so a theme override of that template
	 * (DD-008) still gets `handle_submission()`'s `template_redirect`-hooked
	 * side effect; `templates/join.php` reads its result via `registration()`.
	 *
	 * @var Registration
	 */
	private $registration;

	/**
	 * `wp_robots` policy and canonical link.
	 *
	 * @var Robots
	 */
	private $robots;

	/**
	 * `VideoGame`/`CollectionPage` JSON-LD.
	 *
	 * @var Schema_Org
	 */
	private $schema_org;

	/**
	 * `WP_Sitemaps_Provider` for the public games and opted-public-member
	 * library routes.
	 *
	 * @var Sitemap_Provider
	 */
	private $sitemap_provider;

	/**
	 * Core personal-data exporter/eraser registration and the `deleted_user`
	 * cascade hook.
	 *
	 * @var Privacy
	 */
	private $privacy;

	/**
	 * Daily invite expire/purge maintenance.
	 *
	 * @var Invite_Maintenance
	 */
	private $invite_maintenance;

	/**
	 * Retrieve (and lazily create) the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor — instantiates every service and registers its hooks.
	 */
	private function __construct() {
		// PB-2 (cycle-3): registers the deferred-purge event callbacks
		// Visibility::set_public()/Erasure_Service::erase() queue via
		// Page_Cache::schedule_purge_for_user()/schedule_purge_for_captured_slugs()
		// (MR-1, cycle-5). A static utility, not a per-request service — same
		// rationale as the ADR-007 cases below for why this needs an explicit
		// wiring call rather than firing on its own.
		Page_Cache::register_hooks();

		$this->roles = new Roles();
		$this->roles->register_hooks();

		// See principal/adr/007-wire-new-services-in-plugin-constructor.md —
		// Task 4's Files list does not name this file, but Assets is inert
		// (never enqueues anything) unless something instantiates it and
		// calls register_hooks(), so it is wired here alongside Roles.
		$this->assets = new Assets();
		$this->assets->register_hooks();

		// Same rationale as Assets above (ADR-007) — Task 6's Files list does
		// not name this file, but Settings_Page is inert (its admin_menu/
		// admin_init/admin_post/admin_notices hooks never fire) unless
		// something instantiates it and calls register_hooks().
		$this->settings_page = new Settings_Page();
		$this->settings_page->register_hooks();

		// Same rationale as Assets/Settings_Page above (ADR-007) — Task 9's
		// Files list does not name this file, but Invites_List_Table is inert
		// unless something instantiates it and calls register_hooks().
		// Unlike Assets/Settings_Page, it extends WP_List_Table, an
		// admin-only core class never autoloaded on a front-end request, so
		// it is wired here behind an is_admin() guard rather than
		// unconditionally.
		if ( is_admin() ) {
			$this->invites_list_table = new Invites_List_Table();
			$this->invites_list_table->register_hooks();
		}

		// Same rationale as Assets/Settings_Page above (ADR-007) — Task 18's
		// Files list does not name this file either, but Moderation_Page is
		// inert unless something instantiates it and calls register_hooks().
		// Unlike Invites_List_Table, it does not extend WP_List_Table (see
		// that class's own docblock for why), so it is wired here
		// unconditionally, matching Settings_Page.
		$this->moderation_page = new Moderation_Page();
		$this->moderation_page->register_hooks();

		// Task 10's Files list names this file explicitly (unlike the
		// ADR-007 cases above). register_hooks() wires only Router's
		// always-on template/access-gate hooks; rewrite-rule and query-var
		// registration is dispatched from on_init() below instead — see
		// Router's own docblock for why.
		$this->router = new Router();
		$this->router->register_hooks();

		// Same rationale as Assets/Settings_Page/Invites_List_Table above
		// (ADR-007) — Task 11's Files list does not name this file, but
		// Search_Controller/Library_Controller are inert (their
		// register_routes() callbacks never fire on rest_api_init) unless
		// something instantiates them and calls register_hooks().
		$this->search_controller = new Search_Controller();
		$this->search_controller->register_hooks();

		$this->library_controller = new Library_Controller();
		$this->library_controller->register_hooks();

		// Same rationale as Search_Controller/Library_Controller above
		// (ADR-007) — Task 12's Files list does not name this file, but
		// Social_Controller is inert (its register_routes() callback never
		// fires on rest_api_init) unless something instantiates it and calls
		// register_hooks().
		$this->social_controller = new Social_Controller();
		$this->social_controller->register_hooks();

		// Same rationale as Search_Controller/Library_Controller/
		// Social_Controller above (ADR-007) — Task 14's Files list does not
		// name this file, but Invite_Controller is inert (its
		// register_routes() callback never fires on rest_api_init) unless
		// something instantiates it and calls register_hooks().
		$this->invite_controller = new Invite_Controller();
		$this->invite_controller->register_hooks();

		// Same rationale as the REST controllers above (ADR-007) — Registration
		// was previously instantiated fresh inside templates/join.php on every
		// request, with no hook of its own; wiring it here instead is CO-12's
		// fix (cycle-2) — see that class's own docblock.
		$this->registration = new Registration();
		$this->registration->register_hooks();

		// Same rationale as the REST controllers above (ADR-007) — Task 15's
		// Files list does not name this file, but Robots/Schema_Org are inert
		// (their wp_robots/wp_head/admin_head hooks never fire) unless
		// something instantiates them and calls register_hooks().
		$this->robots = new Robots();
		$this->robots->register_hooks();

		$this->schema_org = new Schema_Org();
		$this->schema_org->register_hooks();

		// Same rationale as Robots/Schema_Org above (ADR-007) — Task 16's
		// Files list does not name this file, but Sitemap_Provider is inert
		// (its wp_sitemaps_init callback never fires) unless something
		// instantiates it and calls register_hooks().
		$this->sitemap_provider = new Sitemap_Provider();
		$this->sitemap_provider->register_hooks();

		// Same rationale as the services above (ADR-007) — Task 17's Files
		// list does not name this file either, but Privacy is inert (its
		// wp_privacy_personal_data_exporters/erasers filters and its
		// deleted_user cascade hook never fire) unless something instantiates
		// it and calls register_hooks().
		$this->privacy = new Privacy();
		$this->privacy->register_hooks();

		// Same rationale as Privacy above (ADR-007) — Invite_Maintenance's
		// game_library_invite_maintenance callback never fires unless
		// something instantiates it and calls register_hooks(); the event
		// itself is scheduled/unscheduled by Activator (Task 1), independent
		// of this wiring.
		$this->invite_maintenance = new Invite_Maintenance();
		$this->invite_maintenance->register_hooks();

		add_action( 'init', array( $this, 'on_init' ), 10 );
	}

	/**
	 * The shared `Registration` instance (CO-12) — `templates/join.php` (or a
	 * theme override of it) calls this to read `last_result()` rather than
	 * instantiating its own copy, which would never see the
	 * `template_redirect`-hooked instance's stashed submission result.
	 *
	 * @return Registration
	 */
	public function registration() {
		return $this->registration;
	}

	/**
	 * Runs on the `init` hook.
	 *
	 * Loads the text domain, re-runs the capability grant whenever the
	 * installed schema version drifts from the running plugin version — the
	 * self-healing path for updates that do not re-fire the activation hook
	 * — and registers the plugin's rewrite rules and query vars via
	 * `Router::register_routes()`.
	 *
	 * `Activator::activate()` calls this method directly before flushing
	 * rewrite rules, because `init` has already fired for the activating
	 * request by the time the activation hook runs — see
	 * class-activator.php. Calling `Router::register_routes()` from here
	 * (rather than `Router` adding its own `init` hook) is what makes that
	 * direct call register the rewrite rules synchronously, in time for the
	 * flush that follows it.
	 *
	 * VIP-1: `Activator::maybe_upgrade()` is no longer called unconditionally
	 * here. This method previously ran on `init` for every request —
	 * anonymous front-end, REST, and admin alike — so a version bump with no
	 * matching deactivate/reactivate cycle had every one of those requests
	 * re-run `install_or_upgrade()`'s check on every hit until the version
	 * option caught up: `dbDelta()` over five `CREATE TABLE`s plus
	 * `Roles::grant_capabilities()`'s five `add_cap()` calls (each an
	 * unconditional `update_option( 'wp_user_roles' )`, invalidating
	 * alloptions). VIP requires schema changes to run out of band from live
	 * traffic, and a request killed by the execution time ceiling mid-`try`
	 * does not run PB-8's `finally` release either, so the exposure was not
	 * only "wasteful," it could wedge the upgrade lock while the version
	 * option never landed. Now this only self-heals on `WP_CLI` and
	 * `wp_doing_cron()` requests — no anonymous/REST/admin page-load request
	 * ever triggers a schema change. The documented, intentional path is the
	 * `wp game-library migrate` command (`Cli\Migrate_Command`), run once as
	 * a deploy step after any release that bumps `GAME_LIBRARY_VERSION`;
	 * `Activator::activate()`'s own direct, unconditional call from
	 * `activate()` itself is unaffected by this guard — activation is
	 * already out-of-band (AC-001 depends on it running synchronously there).
	 *
	 * VIP-5 (cycle-7): the `wp_doing_cron()` backstop above is dead on VIP
	 * specifically — VIP disables `/wp-cron.php` and dispatches scheduled
	 * events through Cron Control's own REST route, which defines
	 * `DOING_CRON` inside its route callback, after `init` (and therefore
	 * this method) has already run for that request. `wp_doing_cron()` is
	 * reliably false at `init` on every VIP request, so on that platform a
	 * missed `wp game-library migrate` deploy step is silently never
	 * self-healed. Rather than chase the backstop, a missed migration is
	 * made loud instead: on any admin request while the installed schema
	 * version still lags `GAME_LIBRARY_VERSION`, register an admin notice
	 * naming the required command. This costs one `get_option()` read on
	 * admin requests only (`Activator::DB_VERSION_OPTION` is autoloaded, so
	 * it is not an extra query) and nothing on the front end.
	 *
	 * @return void
	 */
	public function on_init() {
		load_plugin_textdomain(
			'game-library',
			false,
			dirname( plugin_basename( GAME_LIBRARY_PLUGIN_FILE ) ) . '/languages'
		);

		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			Activator::maybe_upgrade();
		}

		if ( is_admin() && get_option( Activator::DB_VERSION_OPTION ) !== GAME_LIBRARY_VERSION ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_migration_notice' ) );
		}

		// MR9-2 (cycle-9): a version-matched install can still have a stalled
		// member-marker backfill (Roles::BACKFILL_STALLED_OPTION) — a state
		// this method's own version-drift notice above cannot see, since the
		// schema version was already recorded before the backfill ran. See
		// render_backfill_stalled_notice()'s own docblock.
		if ( is_admin() && get_option( Roles::BACKFILL_STALLED_OPTION ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_backfill_stalled_notice' ) );
		}

		$this->router->register_routes();
	}

	/**
	 * VIP-5: warns an administrator that the installed database schema is
	 * behind the running plugin version and names the required deploy step
	 * — the loud counterpart to `on_init()`'s dead-on-VIP `wp_doing_cron()`
	 * backstop. See `on_init()`'s own docblock for the full reasoning.
	 *
	 * @return void
	 */
	public static function render_migration_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Game Library: the installed database schema is out of date. Run wp game-library migrate to bring it up to date.', 'game-library' )
		);
	}

	/**
	 * MR9-2 (cycle-9): warns an administrator that a bounded
	 * `Roles::backfill_member_markers()` batch could not schedule its own
	 * continuation and has stalled — a state distinct from the version-drift
	 * `render_migration_notice()` already covers, since the schema version is
	 * recorded before this backfill runs (`Activator::install_or_upgrade()`),
	 * so a stalled backfill can persist on an install whose version already
	 * matches `GAME_LIBRARY_VERSION`. See `Roles::BACKFILL_STALLED_OPTION`'s
	 * own docblock for how the flag is set/cleared.
	 *
	 * @return void
	 */
	public static function render_backfill_stalled_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Game Library: the member directory backfill could not schedule its next batch and has stalled. Run wp game-library migrate to finish it.', 'game-library' )
		);
	}
}
