<?php
/**
 * Credential status, connection test, quota/expiry/kill-switch settings, and
 * cached totals counters.
 *
 * @package Game_Library
 */

namespace Game_Library\Admin;

use Game_Library\Igdb\Client;
use Game_Library\Igdb\Credentials;
use Game_Library\Page_Cache;
use Game_Library\Router;
use Game_Library\Schema;
use Game_Library\Seo\Sitemap_Provider;
use Game_Library\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings_Page.
 *
 * Registers the top-level "Game Library" admin menu (`manage_options`) and
 * renders its settings screen: read-only credential status with a
 * "Test connection" action that issues one live token request through
 * `Client` (AC-006), the editable `invite_quota`, `invite_expiry_days`,
 * `catalog_indexable`, and `delete_data_on_uninstall` fields stored in the
 * single `game_library_settings` option (AC-045), and the two cached totals
 * counters (AC-050). Also renders a global admin notice when credentials are
 * not configured.
 *
 * `Follow_Repository` and `Activity_Repository` (Task 12) do not exist yet at
 * this point in the task sequence — Task 6 depends only on Task 5 — and
 * neither of those classes' own descriptions (Task 12) ever assigns them a
 * global "total row count" aggregate method, only per-user/per-page ones. The
 * two counters below are computed with direct, cached `$wpdb` queries against
 * `Schema::follows_table()`/`Schema::activity_table()` instead, which fully
 * satisfies AC-050's actual requirement ("computed from the database and
 * cached for at least 900 seconds") without depending on a class this task
 * cannot create. See the coder decision log for Task 6.
 */
final class Settings_Page {

	/**
	 * `register_setting()` option group.
	 *
	 * @var string
	 */
	private const SETTINGS_GROUP = 'game_library_settings_group';

	/**
	 * Top-level admin menu / page slug.
	 *
	 * @var string
	 */
	private const MENU_SLUG = 'game-library';

	/**
	 * `admin-post.php` action name for the "Test connection" control.
	 *
	 * @var string
	 */
	private const TEST_CONNECTION_ACTION = 'gl_test_connection';

	/**
	 * Nonce field name for the "Test connection" form — deliberately distinct
	 * from `settings_fields()`'s own default `_wpnonce` field, so the two
	 * separate `<form>` elements on this screen never render a duplicate
	 * `id="_wpnonce"`.
	 *
	 * @var string
	 */
	private const TEST_CONNECTION_NONCE_NAME = '_gl_test_connection_nonce';

	/**
	 * Object-cache group for every cache entry this class reads/writes.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'game_library';

	/**
	 * Cache key for the two admin counters (AC-050).
	 *
	 * @var string
	 */
	private const STATS_CACHE_KEY = 'stats_totals';

	/**
	 * The `wp_cache_set()` call below uses the `15 * MINUTE_IN_SECONDS`
	 * expression directly (900s, never lower) as its TTL rather than a
	 * `self::` class-constant reference —
	 * `WordPressVIPMinimum.Performance.LowExpiryCacheTime` can only
	 * statically evaluate a literal number, arithmetic on literals, or one
	 * of the named WP time constants; a `self::` class-constant reference is
	 * an unresolvable token to that sniff regardless of its actual value.
	 */

	/**
	 * Credential accessor.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * IGDB/Twitch client, used only for the live "Test connection" check.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Credentials|null $credentials Credential accessor. Defaults to a
	 *                                      new instance.
	 * @param Client|null      $client      IGDB client. Defaults to a new
	 *                                      instance built from $credentials.
	 */
	public function __construct( ?Credentials $credentials = null, ?Client $client = null ) {
		$this->credentials = $credentials ?: new Credentials();
		$this->client      = $client ?: new Client( $this->credentials );
	}

	/**
	 * Registers this service's hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::TEST_CONNECTION_ACTION, array( $this, 'handle_test_connection' ) );
		add_action( 'admin_notices', array( $this, 'render_credentials_notice' ) );
		// VIP-7 (cycle-5): the catalog_indexable edge-cache purge moved here,
		// off sanitize_settings() — see maybe_purge_on_catalog_indexable_change()'s
		// own docblock for why.
		add_action( 'update_option_' . Settings::OPTION_KEY, array( $this, 'maybe_purge_on_catalog_indexable_change' ), 10, 2 );
	}

	/**
	 * Registers the top-level "Game Library" admin menu.
	 *
	 * `Invites_List_Table` (Task 9) and `Moderation_Page` (Task 18) register
	 * their own submenus under this same slug.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Game Library', 'game-library' ),
			__( 'Game Library', 'game-library' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-games',
			26
		);
	}

	/**
	 * Registers the `game_library_settings` option with a single sanitize
	 * callback that implements every key's bounds from the Data Model table.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => Settings::DEFAULTS,
			)
		);
	}

	/**
	 * Sanitizes a submitted `game_library_settings` value, clamping each key
	 * to its documented bounds and defaulting any key that is absent (e.g. an
	 * unchecked checkbox).
	 *
	 * @param mixed $value Raw submitted value.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $value ) {
		$value = is_array( $value ) ? $value : array();

		$sanitized = array(
			'invite_quota'             => isset( $value['invite_quota'] )
				? min( 100, max( 1, absint( $value['invite_quota'] ) ) )
				: Settings::DEFAULTS['invite_quota'],
			'invite_expiry_days'       => isset( $value['invite_expiry_days'] )
				? min( 90, max( 1, absint( $value['invite_expiry_days'] ) ) )
				: Settings::DEFAULTS['invite_expiry_days'],
			'catalog_indexable'        => isset( $value['catalog_indexable'] )
				? rest_sanitize_boolean( $value['catalog_indexable'] )
				: false,
			'delete_data_on_uninstall' => isset( $value['delete_data_on_uninstall'] )
				? rest_sanitize_boolean( $value['delete_data_on_uninstall'] )
				: false,
		);

		return $sanitized;
	}

	/**
	 * The `update_option_{Settings::OPTION_KEY}` callback (VIP-7, cycle-5) —
	 * purges VIP's edge cache when a save actually changes
	 * `catalog_indexable` (DD-013's "no code change or deploy" kill switch,
	 * AC-045). Moved here from inside `sanitize_settings()`, which
	 * `register_setting()`'s own `sanitize_callback` contract does not
	 * guarantee runs exactly once per save — core invokes it on every
	 * `update_option()` for this key, including a REST
	 * `POST /wp/v2/settings` write, and WordPress has historically invoked
	 * settings sanitization more than once in a single request under some
	 * flows. Each redundant invocation issued a real, live VIP purge call.
	 * `update_option_{$option}` fires exactly once per actual option write
	 * and, unlike the sanitize callback, is handed both the old and new
	 * value directly — removing the need for `sanitize_settings()`'s own
	 * `Settings::all()` re-read to discover what changed.
	 *
	 * `catalog_indexable` changes the robots meta tag every one of
	 * `/games/` (a), `/games/{slug}/` (b), every opted-public
	 * `/library/{nicename}/` (c), and both sitemap sections (d)/(e)
	 * render — all anonymously-cached HTTP 200 responses held at VIP's edge
	 * for up to 30 minutes, so without a purge the switch's effect is
	 * invisible at the edge until that TTL naturally expires.
	 *
	 * @param mixed $old_value The option's previous value.
	 * @param mixed $new_value The option's newly saved value.
	 * @return void
	 */
	public function maybe_purge_on_catalog_indexable_change( $old_value, $new_value ) {
		$old_value = is_array( $old_value ) ? $old_value : array();
		$new_value = is_array( $new_value ) ? $new_value : array();

		$previous = ! empty( $old_value['catalog_indexable'] );
		$current  = ! empty( $new_value['catalog_indexable'] );

		if ( $previous !== $current ) {
			$this->purge_catalog_indexable_edge_cache();
		}
	}

	/**
	 * Purges VIP's edge cache for the surfaces a `catalog_indexable` flip
	 * changes (VIP-5). VIP exposes no full-site purge to plugin code, so
	 * this purges only the catalog listing plus the sitemap index and each
	 * subtype's own first page — a per-game (`/games/{slug}/`) or
	 * opted-public (`/library/{nicename}/`) page already held at the edge,
	 * and any sitemap page beyond the first, is left to age out on its own
	 * 30-minute edge TTL; the checkbox's own inline help text
	 * (`render_settings_form()`) documents that limitation for an
	 * administrator flipping this switch.
	 *
	 * @return void
	 */
	private function purge_catalog_indexable_edge_cache() {
		Page_Cache::purge( Router::catalog_url() );
		Page_Cache::purge( home_url( '/wp-sitemap.xml' ) );

		// CO-7: built from Sitemap_Provider's own PROVIDER_NAME/SUBTYPES
		// constants rather than re-declaring the literals here — the
		// provider has already been renamed once (see that class's own
		// docblock), and a rename that updated only the provider would
		// otherwise leave this purge silently pointed at URLs that no
		// longer exist.
		foreach ( Sitemap_Provider::SUBTYPES as $subtype ) {
			Page_Cache::purge( home_url( '/wp-sitemap-' . Sitemap_Provider::PROVIDER_NAME . '-' . $subtype . '-1.xml' ) );
		}
	}

	/**
	 * Handles the "Test connection" form post — verifies its own nonce and
	 * capability (independent of the Settings API form), issues one live
	 * token request through `Client::test_connection()`, and redirects back
	 * to the settings screen with the outcome in the query string.
	 *
	 * When credentials are not configured, `Client::test_connection()` itself
	 * returns `gl_igdb_not_configured` without issuing any outbound request
	 * (AC-006 (d)) — this handler does not need a second guard to enforce
	 * that.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'game-library' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::TEST_CONNECTION_ACTION, self::TEST_CONNECTION_NONCE_NAME );

		$result = $this->client->test_connection();

		$redirect_args = array( 'page' => self::MENU_SLUG );

		if ( is_wp_error( $result ) ) {
			$redirect_args['gl_test_connection']      = 'error';
			$redirect_args['gl_test_connection_code'] = $result->get_error_code();
		} else {
			$redirect_args['gl_test_connection'] = 'success';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renders a dismissible-by-navigation warning on every admin screen when
	 * IGDB credentials are not configured — the concrete case this plugin can
	 * detect without an outbound call (`Credentials::is_configured()`); a
	 * live 401/403 rejection of *configured* credentials is only observable
	 * inside `Client`, which this task's Files list does not include (see the
	 * class docblock and the coder decision log for Task 6).
	 *
	 * @return void
	 */
	public function render_credentials_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $this->credentials->is_configured() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Game Library: IGDB credentials are not configured, so members cannot search for games.', 'game-library' ),
			esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Configure now', 'game-library' )
		);
	}

	/**
	 * Renders the settings screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'game-library' ), '', array( 'response' => 403 ) );
		}

		$configured = $this->credentials->is_configured();
		$settings   = $this->get_settings();
		$totals     = $this->get_stats_totals();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Game Library', 'game-library' ) . '</h1>';

		$this->render_test_connection_result();
		$this->render_credential_status( $configured );
		$this->render_settings_form( $settings );
		$this->render_counters( $totals );

		echo '</div>';
	}

	/**
	 * Renders the flash result of a just-completed "Test connection" action,
	 * read from the query string `handle_test_connection()` redirected with.
	 *
	 * @return void
	 */
	private function render_test_connection_result() {
		if ( ! isset( $_GET['gl_test_connection'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash flag, not a state-changing action; the action itself is nonce-verified in handle_test_connection().
			return;
		}

		$outcome = sanitize_key( wp_unslash( $_GET['gl_test_connection'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.

		if ( 'success' === $outcome ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Connection to IGDB succeeded.', 'game-library' )
			);

			return;
		}

		if ( 'error' === $outcome ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html__( 'Connection to IGDB failed. Check the credentials and try again.', 'game-library' )
			);
		}
	}

	/**
	 * Renders the credential status text and the "Test connection" control
	 * (AC-006).
	 *
	 * @param bool $configured Whether credentials resolve.
	 * @return void
	 */
	private function render_credential_status( $configured ) {
		echo '<h2>' . esc_html__( 'IGDB credentials', 'game-library' ) . '</h2>';
		echo '<p>';
		echo esc_html__( 'Status:', 'game-library' ) . ' <strong>';
		echo $configured
			? esc_html__( 'Configured', 'game-library' )
			: esc_html__( 'Not configured', 'game-library' );
		echo '</strong></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::TEST_CONNECTION_ACTION ) . '" />';
		wp_nonce_field( self::TEST_CONNECTION_ACTION, self::TEST_CONNECTION_NONCE_NAME );
		echo '<button type="submit" class="button button-secondary"' . disabled( $configured, false, false ) . '>';
		echo esc_html__( 'Test connection', 'game-library' );
		echo '</button>';
		echo '</form>';
	}

	/**
	 * Renders the editable settings form.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 * @return void
	 */
	private function render_settings_form( array $settings ) {
		echo '<h2>' . esc_html__( 'Settings', 'game-library' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';

		settings_fields( self::SETTINGS_GROUP );

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="gl-invite-quota">' . esc_html__( 'Invite quota', 'game-library' ) . '</label></th><td>';
		echo '<input type="number" id="gl-invite-quota" name="' . esc_attr( Settings::OPTION_KEY ) . '[invite_quota]" min="1" max="100" value="' . esc_attr( $settings['invite_quota'] ) . '" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Maximum pending/redeemed invites a member may issue in a rolling 30-day window (1-100).', 'game-library' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="gl-invite-expiry-days">' . esc_html__( 'Invite expiry (days)', 'game-library' ) . '</label></th><td>';
		echo '<input type="number" id="gl-invite-expiry-days" name="' . esc_attr( Settings::OPTION_KEY ) . '[invite_expiry_days]" min="1" max="90" value="' . esc_attr( $settings['invite_expiry_days'] ) . '" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Number of days before an unredeemed invite expires (1-90).', 'game-library' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Public catalog indexable', 'game-library' ) . '</th><td>';
		echo '<label for="gl-catalog-indexable">';
		echo '<input type="hidden" name="' . esc_attr( Settings::OPTION_KEY ) . '[catalog_indexable]" value="0" />';
		echo '<input type="checkbox" id="gl-catalog-indexable" name="' . esc_attr( Settings::OPTION_KEY ) . '[catalog_indexable]" value="1"' . checked( $settings['catalog_indexable'], true, false ) . ' />';
		echo ' ' . esc_html__( 'Allow the public game catalog and opted-public member libraries to be indexed by search engines.', 'game-library' );
		echo '</label>';
		// VIP-5: this switch purges the catalog listing and the sitemap
		// index/first pages immediately, but VIP exposes no full-site purge
		// to plugin code — a per-game or opted-public member page already
		// held at the edge (and any sitemap page beyond the first) is not
		// purged, so it keeps serving without the updated robots meta for
		// up to its own 30-minute edge TTL.
		echo '<p class="description">' . esc_html__( 'Per-game and per-member pages already cached at the edge may continue serving for up to 30 minutes after this change.', 'game-library' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Delete data on uninstall', 'game-library' ) . '</th><td>';
		echo '<label for="gl-delete-data-on-uninstall">';
		echo '<input type="hidden" name="' . esc_attr( Settings::OPTION_KEY ) . '[delete_data_on_uninstall]" value="0" />';
		echo '<input type="checkbox" id="gl-delete-data-on-uninstall" name="' . esc_attr( Settings::OPTION_KEY ) . '[delete_data_on_uninstall]" value="1"' . checked( $settings['delete_data_on_uninstall'], true, false ) . ' />';
		echo ' ' . esc_html__( 'Permanently delete all Game Library tables, options, and user meta when the plugin is uninstalled.', 'game-library' );
		echo '</label>';
		echo '</td></tr>';

		echo '</table>';

		submit_button();

		echo '</form>';
	}

	/**
	 * Renders the two cached totals counters (AC-050).
	 *
	 * @param array<string,int> $totals { follows: int, activity: int }.
	 * @return void
	 */
	private function render_counters( array $totals ) {
		echo '<h2>' . esc_html__( 'Activity', 'game-library' ) . '</h2>';
		echo '<ul>';

		echo '<li>' . esc_html(
			sprintf(
				/* translators: %d: number of follow relationships. */
				_n( '%d follow relationship', '%d follow relationships', $totals['follows'], 'game-library' ),
				$totals['follows']
			)
		) . '</li>';

		echo '<li>' . esc_html(
			sprintf(
				/* translators: %d: number of activity entries. */
				_n( '%d activity entry', '%d activity entries', $totals['activity'], 'game-library' ),
				$totals['activity']
			)
		) . '</li>';

		echo '</ul>';
	}

	/**
	 * Reads `game_library_settings`, backfilling any key absent from the
	 * stored option row with its documented default.
	 *
	 * @return array<string,mixed>
	 */
	private function get_settings() {
		return Settings::all();
	}

	/**
	 * The two cached admin counters — total follow relationships and total
	 * activity entries — cached under `stats_totals` for at least 900 seconds
	 * (AC-050).
	 *
	 * Computed with direct, cached `$wpdb` queries rather than through
	 * `Follow_Repository`/`Activity_Repository`; see the class docblock.
	 *
	 * @return array<string,int> { follows: int, activity: int }.
	 */
	private function get_stats_totals() {
		$cached = wp_cache_get( self::STATS_CACHE_KEY, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$follows_table  = Schema::follows_table();
		$activity_table = Schema::activity_table();

		// Neither query below carries a variable to bind — both table names
		// come from Schema, never user input — so $wpdb->prepare() does not
		// apply; each result is cached immediately below.
		$totals = array(
			'follows'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$follows_table}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see comment above; cached via wp_cache_set() below.
			'activity' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$activity_table}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see comment above; cached via wp_cache_set() below.
		);

		wp_cache_set( self::STATS_CACHE_KEY, $totals, self::CACHE_GROUP, 15 * MINUTE_IN_SECONDS );

		return $totals;
	}
}
