<?php
/**
 * Plugin wiring and lifecycle.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Instantiates and hooks every Game Library service, and owns the plugin's
 * activation/deactivation lifecycle.
 *
 * Lifecycle contract (AC-051): activation prepares persistent state,
 * deactivation removes only scheduled work. Neither ever deletes member data
 * — data removal happens exclusively through `uninstall.php`, and only when
 * the `gamelib_uninstall_remove_data` opt-in is set.
 */
final class GameLib_Plugin {

	/**
	 * Recurring hourly cron hook that refreshes stale rows in the shared
	 * IGDB game store.
	 *
	 * @var string
	 */
	const CRON_REFRESH_HOOK = 'gamelib_refresh_games';

	/**
	 * Chained single-event cron hook that advances one import job
	 * (args: import id).
	 *
	 * @var string
	 */
	const CRON_IMPORT_HOOK = 'gamelib_import_tick';

	/**
	 * Chained single-event cron hook that drains the remainder of a filter-mode
	 * bulk library action (args: the job description, keyed by member).
	 *
	 * A bulk action over "everything matching this filter" is bounded by the
	 * member's library rather than by the 200-id cap core enforces on the
	 * explicit-id mode, so it applies what one request may and hands the rest
	 * here (PB-3).
	 *
	 * @var string
	 */
	const CRON_BULK_HOOK = 'gamelib_library_bulk';

	/**
	 * Chained single-event cron hook that continues the purge of a deleted
	 * account (args: the deleted member's id).
	 *
	 * `deleted_user` fires once and can never fire again, so the bounded pass it
	 * runs supplies its own successor rather than leaving a half-purged account
	 * behind (VIP-1).
	 *
	 * @var string
	 */
	const CRON_PURGE_HOOK = 'gamelib_purge_user';

	/**
	 * Singleton instance.
	 *
	 * @var GameLib_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the shared plugin instance.
	 *
	 * @return GameLib_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Service class files, loaded in dependency order.
	 *
	 * The bootstrap requires only the three files it needs to exist before this
	 * class runs (cache, schema, this file); every service beyond those is
	 * listed here, because `game-library.php` is closed to further edits — it
	 * is not in any later task's file list, while this class is the documented
	 * place where services are "instantiated and hooked" (§6 Components).
	 *
	 * Not every service in this list hooks anything: the external-API clients
	 * are called on demand from REST routes, admin actions, and cron
	 * callbacks, so they appear here — one shared class per integration — and
	 * not in {@see GameLib_Plugin::boot()}.
	 *
	 * @var string[]
	 */
	const SERVICE_FILES = array(
		'class-capabilities.php',
		'class-visibility.php',
		'class-router.php',
		'class-tokens.php',
		'class-assets.php',
		'class-igdb-client.php',
		'class-steam-client.php',
		'class-game-store.php',
		'class-follows.php',
		'class-activity.php',
		'class-library.php',
		'class-game-cpt.php',
		'class-refresh-job.php',
		'class-invites.php',
		'class-registration.php',
		'class-exporter.php',
		'class-importer.php',
		'class-seo.php',
		'class-privacy.php',
		'rest/class-rest-library.php',
		'rest/class-rest-social.php',
		'rest/class-rest-account.php',
		'rest/class-rest-import.php',
	);

	/**
	 * Admin-only service class files.
	 *
	 * Kept out of {@see GameLib_Plugin::SERVICE_FILES} because every hook they
	 * register — `admin_menu`, `admin_init`, `admin_notices`,
	 * `edit_user_profile`, and the `admin_post_*` family — fires only where
	 * `is_admin()` is true (`admin-post.php` defines `WP_ADMIN` before it
	 * loads WordPress), so a front-end request has no reason to parse them.
	 *
	 * `admin/class-invites-table.php` is deliberately absent: it extends
	 * `WP_List_Table`, which does not exist until `wp-admin/includes` has been
	 * loaded, so it is required by the screen that renders it.
	 *
	 * @var string[]
	 */
	const ADMIN_SERVICE_FILES = array(
		'admin/class-admin-settings.php',
		'admin/class-admin-user.php',
	);

	/**
	 * Private constructor — use {@see GameLib_Plugin::instance()}.
	 */
	private function __construct() {}

	/**
	 * Register the plugin's hooks.
	 *
	 * Called once from the bootstrap while plugins load. Every service
	 * registers here so hook order is inspectable in a single place; cron
	 * callbacks in particular are registered unconditionally, never behind
	 * `is_admin()` (ADR-004).
	 *
	 * The schema and capability checks run at `init` priority 5 — ahead of the
	 * rewrite rules and the CPT at priority 10 — so no service can query a
	 * table, or test a capability, before the two reconciliation routines have
	 * had their chance to run.
	 *
	 * @return void
	 */
	public function boot() {
		self::load_services();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( 'GameLib_Schema', 'maybe_upgrade' ), 5 );
		add_action( 'init', array( 'GameLib_Capabilities', 'sync' ), 5 );
		add_action( 'init', array( 'GameLib_Refresh_Job', 'ensure_scheduled' ), 5 );
		add_filter( 'user_has_cap', array( 'GameLib_Capabilities', 'filter_user_has_cap' ), 10, 4 );

		/*
		 * Background work. Registered here, unconditionally and outside every
		 * `is_admin()` branch below: a cron callback runs in neither the admin
		 * nor a front-end context under VIP's Cron Control (ADR-004).
		 */
		add_action( self::CRON_REFRESH_HOOK, array( 'GameLib_Refresh_Job', 'run' ) );
		add_action( self::CRON_IMPORT_HOOK, array( 'GameLib_Importer', 'tick' ) );
		add_action( self::CRON_BULK_HOOK, array( 'GameLib_Library', 'run_bulk' ) );
		add_action( self::CRON_PURGE_HOOK, array( 'GameLib_Privacy', 'run_purge' ) );

		/*
		 * The recovery path for a purge whose continuation `wp_schedule_single_event()`
		 * refused (VIP-1/CO-5/SE-2). It rides the refresh tick rather than
		 * carrying a schedule of its own for the reason it exists: the thing that
		 * failed *was* scheduling, so the only event worth draining from is one
		 * that is already on the calendar on every install — and on VIP, one Cron
		 * Control already runs.
		 */
		add_action( self::CRON_REFRESH_HOOK, array( 'GameLib_Privacy', 'drain_pending' ) );

		// Routing: rules at `init` 10 (never flushed here — see activate()),
		// then the four hooks that turn a matched rule into a rendered
		// document (DD-006).
		add_action( 'init', array( 'GameLib_Router', 'register_rewrites' ) );
		add_filter( 'query_vars', array( 'GameLib_Router', 'filter_query_vars' ) );
		add_filter( 'posts_pre_query', array( 'GameLib_Router', 'filter_posts_pre_query' ), 10, 2 );
		add_filter( 'pre_handle_404', array( 'GameLib_Router', 'filter_pre_handle_404' ), 10, 2 );
		add_action( 'template_redirect', array( 'GameLib_Router', 'dispatch' ), 5 );
		add_filter( 'template_include', array( 'GameLib_Router', 'filter_template_include' ) );

		/*
		 * Registration. The `/join/` handler runs at `template_redirect` 6 —
		 * after the router has resolved the route at 5, and still before any
		 * output, which a successful redemption needs for its auth cookie and
		 * redirect. The `registration_errors` filter is registered
		 * unconditionally so an enabled `users_can_register` option cannot mint
		 * an account outside the invite flow, whichever surface asks (AC-005d).
		 */
		add_action( 'template_redirect', array( 'GameLib_Registration', 'prepare' ), 6 );
		add_filter( 'registration_errors', array( 'GameLib_Registration', 'filter_registration_errors' ), 10, 3 );

		/*
		 * The REST surface every interactive control talks to (DD-005). Routes
		 * are registered on `rest_api_init` — never earlier — and each one
		 * carries its own permission callback, so registration itself is
		 * unconditional. The one route whose gate is not a capability is
		 * `GET /members/{id}/library`: it serves logged-out visitors at a
		 * public member's URL, so its callback is the AC-028 visibility matrix.
		 */
		add_action( 'rest_api_init', array( 'GameLib_REST_Library', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'GameLib_REST_Social', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'GameLib_REST_Account', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'GameLib_REST_Import', 'register_routes' ) );

		// The game projection: the CPT itself, the page it renders from the
		// store row, and the two paths a page enters or returns to `publish`
		// (ADR-003).
		add_action( 'init', array( 'GameLib_Game_CPT', 'register' ) );
		add_action( 'gamelib_first_add', array( 'GameLib_Game_CPT', 'on_first_add' ), 10, 2 );
		add_filter( 'the_content', array( 'GameLib_Game_CPT', 'filter_the_content' ) );
		add_filter( 'wp_untrash_post_status', array( 'GameLib_Game_CPT', 'filter_untrash_status' ), 10, 3 );

		/*
		 * The projection's admin surface. Unlike the cron callbacks, none of
		 * these hooks has a front-end counterpart — `load-post.php`,
		 * `admin_post_*`, and the list-table filters only ever fire inside
		 * wp-admin — so they are registered where they run.
		 */
		if ( is_admin() ) {
			add_filter( 'post_row_actions', array( 'GameLib_Game_CPT', 'filter_row_actions' ), 10, 2 );
			add_filter( 'bulk_actions-edit-' . GameLib_Game_CPT::POST_TYPE, array( 'GameLib_Game_CPT', 'filter_bulk_actions' ) );
			add_filter( 'manage_' . GameLib_Game_CPT::POST_TYPE . '_posts_columns', array( 'GameLib_Game_CPT', 'filter_columns' ) );
			// Before the column renderer, so the store rows for the whole page
			// are read in one batch instead of once per row (PB-2).
			add_filter( 'the_posts', array( 'GameLib_Game_CPT', 'prime_list_rows' ), 10, 2 );
			add_action( 'manage_' . GameLib_Game_CPT::POST_TYPE . '_posts_custom_column', array( 'GameLib_Game_CPT', 'render_column' ), 10, 2 );
			add_action( 'load-post.php', array( 'GameLib_Game_CPT', 'block_post_edit' ) );
			add_action( 'admin_post_' . GameLib_Game_CPT::REFRESH_ACTION, array( 'GameLib_Game_CPT', 'handle_refresh' ) );
			/*
			 * The `nopriv` half of the same action. Without it `admin-post.php`
			 * refuses an unregistered action itself, with 400 — where
			 * AC-NFR-001(t) asks for the 401 the three settings/override
			 * actions below answer. Same rejecter, same sentence.
			 */
			add_action( 'admin_post_nopriv_' . GameLib_Game_CPT::REFRESH_ACTION, array( 'GameLib_Admin_Settings', 'reject_anonymous' ) );
			add_action( 'admin_notices', array( 'GameLib_Game_CPT', 'render_notice' ) );

			self::load_admin_services();

			/*
			 * The two screens under the Games menu (AC-054, AC-006) and the
			 * user-edit override section (AC-007), with the three
			 * `admin-post.php` actions they submit to. Each action is
			 * registered twice: once for authenticated callers, and once on
			 * the `nopriv` hook so a logged-out submission is refused with 401
			 * rather than ending as a silent, empty 200 (AC-NFR-001 s,k).
			 */
			add_action( 'admin_menu', array( 'GameLib_Admin_Settings', 'register_menus' ) );
			add_action( 'admin_init', array( 'GameLib_Admin_Settings', 'register_settings' ) );
			add_action( 'admin_notices', array( 'GameLib_Admin_Settings', 'render_notice' ) );
			add_action( 'admin_post_' . GameLib_Admin_Settings::TEST_ACTION, array( 'GameLib_Admin_Settings', 'handle_test' ) );
			add_action( 'admin_post_nopriv_' . GameLib_Admin_Settings::TEST_ACTION, array( 'GameLib_Admin_Settings', 'reject_anonymous' ) );
			add_action( 'admin_post_' . GameLib_Admin_Settings::REVOKE_ACTION, array( 'GameLib_Admin_Settings', 'handle_revoke' ) );
			add_action( 'admin_post_nopriv_' . GameLib_Admin_Settings::REVOKE_ACTION, array( 'GameLib_Admin_Settings', 'reject_anonymous' ) );

			add_action( 'edit_user_profile', array( 'GameLib_Admin_User', 'render_section' ) );
			add_action( 'admin_notices', array( 'GameLib_Admin_User', 'render_notice' ) );
			add_action( 'admin_post_' . GameLib_Admin_User::OVERRIDE_ACTION, array( 'GameLib_Admin_User', 'handle_override' ) );
			add_action( 'admin_post_nopriv_' . GameLib_Admin_User::OVERRIDE_ACTION, array( 'GameLib_Admin_User', 'reject_anonymous' ) );
		}

		// Presentation: tokens reach the global stylesheet, bundles reach only
		// the route that owns them.
		/*
		 * The *default* origin, not the theme origin (DES-7): a plugin's tokens
		 * are defaults a theme may override, and merging them over the theme's
		 * own parsed data was the exact opposite of the override seam the class
		 * advertises. See GameLib_Tokens' docblock.
		 */
		add_filter( 'wp_theme_json_data_default', array( 'GameLib_Tokens', 'filter_theme_json' ) );
		add_action( 'wp_enqueue_scripts', array( 'GameLib_Assets', 'enqueue' ) );

		/*
		 * The plugin's own SEO layer (AC-047–AC-049). `wp_head` at priority 5
		 * puts the head block ahead of core's `rel_canonical()` at 10, which it
		 * stands down once it has printed the plugin's canonical; the sitemap
		 * provider registers at `init` 20, after core's own
		 * `wp_sitemaps_get_server()` has created the registry at 10.
		 */
		add_filter( 'document_title_parts', array( 'GameLib_SEO', 'filter_document_title_parts' ) );
		add_filter( 'wp_robots', array( 'GameLib_SEO', 'filter_robots' ) );
		add_action( 'wp_head', array( 'GameLib_SEO', 'render_head' ), 5 );
		add_action( 'init', array( 'GameLib_SEO', 'register_sitemap_provider' ), 20 );

		/*
		 * Personal data (AC-052, AC-053). The first three are registered
		 * unconditionally and outside the `is_admin()` branch above: `deleted_user`
		 * fires wherever an account is deleted — WP-CLI and REST included — and a
		 * privacy request can be driven from any of them. The fourth needs no
		 * guard either: `admin_init` fires nowhere else, and it is the only
		 * context core accepts `wp_add_privacy_policy_content()` from. The purge
		 * the third one starts continues on {@see CRON_PURGE_HOOK}, registered
		 * with the rest of the background work above (VIP-1).
		 */
		add_filter( 'wp_privacy_personal_data_exporters', array( 'GameLib_Privacy', 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( 'GameLib_Privacy', 'register_erasers' ) );
		add_action( 'deleted_user', array( 'GameLib_Privacy', 'on_deleted_user' ) );
		add_action( 'admin_init', array( 'GameLib_Privacy', 'add_policy_content' ) );
	}

	/**
	 * Require every service class file.
	 *
	 * Runs while the plugin file loads — before `activate()` can fire in the
	 * same request — so activation work may call any service.
	 *
	 * @return void
	 */
	private static function load_services() {
		foreach ( self::SERVICE_FILES as $file ) {
			require_once __DIR__ . '/' . $file;
		}
	}

	/**
	 * Require the admin-only service class files.
	 *
	 * Called from {@see GameLib_Plugin::boot()} inside its `is_admin()` branch
	 * — never from activation, which needs none of them.
	 *
	 * @return void
	 */
	private static function load_admin_services() {
		foreach ( self::ADMIN_SERVICE_FILES as $file ) {
			require_once __DIR__ . '/' . $file;
		}
	}

	/**
	 * Load the plugin text domain.
	 *
	 * Runs on `init` — loading translations earlier trips core's
	 * just-in-time translation notice (WP 6.7+).
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'game-library',
			false,
			dirname( plugin_basename( GAMELIB_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Activation handler.
	 *
	 * One-time setup that must survive across requests belongs here: schema
	 * creation and the DB-version stamp, capability grants, rewrite
	 * registration + flush, and scheduling the recurring refresh event. Each
	 * service adds its own call as it lands; the hook registration itself
	 * lives in the bootstrap and never has to move.
	 *
	 * Activation is additive only — it never deletes tables, options, or
	 * user meta (AC-051).
	 *
	 * @return void
	 */
	public static function activate() {
		self::load_services();

		GameLib_Schema::install();
		GameLib_Capabilities::sync();

		/*
		 * The CPT has to be registered before the router flushes: activation
		 * runs after this request's `init`, so `/games/{slug}/` would otherwise
		 * be missing from the rule set that gets persisted.
		 */
		GameLib_Game_CPT::register();
		GameLib_Router::activate();

		// The hourly store revalidation (AC-036). Idempotent, and re-asserted
		// on `init` as well, so a lost schedule repairs itself.
		GameLib_Refresh_Job::activate();
	}

	/**
	 * Deactivation handler.
	 *
	 * Removes scheduled work and nothing else: every table, option, and
	 * piece of user meta survives a deactivate/reactivate cycle (AC-051a).
	 *
	 * `wp_unschedule_hook()` (rather than `wp_clear_scheduled_hook()`) is
	 * used because import ticks and bulk passes are scheduled with arguments,
	 * and only the former clears events regardless of their arguments.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_unschedule_hook( self::CRON_REFRESH_HOOK );
		wp_unschedule_hook( self::CRON_IMPORT_HOOK );
		wp_unschedule_hook( self::CRON_BULK_HOOK );
		wp_unschedule_hook( self::CRON_PURGE_HOOK );
	}
}
