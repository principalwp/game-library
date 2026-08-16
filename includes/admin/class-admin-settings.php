<?php
/**
 * The plugin's two admin screens: Games → Settings and Games → Invites.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns everything under the Games menu that is not the game list itself
 * (AC-054, AC-006).
 *
 * Two screens live here, plus the two `admin-post.php` actions they submit to:
 *
 * - **Settings** — integration status, a live connection test per integration,
 *   the site-wide invite quota, and the uninstall data-removal opt-in. The
 *   quota and the opt-in are saved through the Settings API, so their write
 *   goes to `options.php`, which verifies the option group's nonce and
 *   `manage_options` before it stores anything (AC-NFR-001u).
 * - **Invites** — the {@see GameLib_Invites_Table} list table and the Revoke
 *   action its rows link to (AC-006f).
 *
 * Three rules shape the whole file:
 *
 * 1. **A secret is never read, echoed, or accepted here.** The status line is
 *    derived from `is_configured()`, which reports presence and nothing else;
 *    there is no credential field of any kind on the screen, masked or
 *    otherwise, and no failure message carries a response body (AC-054e,
 *    Never Do #5).
 * 2. **Every admin action verifies its own nonce and capability, in that
 *    order.** `check_admin_referer()` first — so a forged submission is
 *    refused before any state is read — then
 *    `gamelib_admin_override` (AC-NFR-001s,k). A logged-out request never
 *    reaches either check: `admin-post.php` routes it to the `nopriv` hook,
 *    which {@see reject_anonymous()} answers with 401 rather than the silent
 *    200 an unhandled action would produce.
 * 3. **A connection test reports a failure *class*, never a body.** The two
 *    clients already reduce every outcome to one taxonomy term; the redirect
 *    carries that term and this class turns it into a sentence from a fixed
 *    catalog.
 */
final class GameLib_Admin_Settings {

	/**
	 * Menu slug of the settings screen.
	 *
	 * @var string
	 */
	const PAGE_SETTINGS = 'gamelib-settings';

	/**
	 * Menu slug of the invites screen.
	 *
	 * @var string
	 */
	const PAGE_INVITES = 'gamelib-invites';

	/**
	 * Settings API option group. `options.php` gates a save on this group's
	 * nonce and on `manage_options`.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'gamelib_settings';

	/**
	 * Settings API section holding the invite quota field.
	 *
	 * @var string
	 */
	const SECTION_INVITES = 'gamelib_settings_invites';

	/**
	 * Settings API section holding the uninstall opt-in.
	 *
	 * @var string
	 */
	const SECTION_DATA = 'gamelib_settings_data';

	/**
	 * Uninstall data-removal opt-in option (AC-054d, AC-051c). Stored as the
	 * string `'1'` or `'0'`, because `uninstall.php` compares it as a string
	 * without loading any of this plugin's code.
	 *
	 * @var string
	 */
	const UNINSTALL_OPTION = 'gamelib_uninstall_remove_data';

	/**
	 * `admin-post.php` action behind the two "Test connection" buttons.
	 *
	 * @var string
	 */
	const TEST_ACTION = 'gamelib_test_connection';

	/**
	 * `admin-post.php` action behind the invites table's Revoke row action.
	 *
	 * @var string
	 */
	const REVOKE_ACTION = 'gamelib_revoke_invite';

	/**
	 * Integration key: IGDB (AC-054a).
	 *
	 * @var string
	 */
	const PROVIDER_IGDB = 'igdb';

	/**
	 * Integration key: Steam (AC-054b).
	 *
	 * @var string
	 */
	const PROVIDER_STEAM = 'steam';

	/**
	 * The two integrations — the whitelist a submitted provider is matched
	 * against.
	 *
	 * @var string[]
	 */
	const PROVIDERS = array( self::PROVIDER_IGDB, self::PROVIDER_STEAM );

	/**
	 * Query arg naming which integration was tested.
	 *
	 * @var string
	 */
	const ARG_PROVIDER = 'gamelib_tested';

	/**
	 * Query arg carrying the test's outcome term.
	 *
	 * @var string
	 */
	const ARG_RESULT = 'gamelib_result';

	/**
	 * Query arg carrying a revoke outcome back to the invites screen.
	 *
	 * @var string
	 */
	const ARG_REVOKED = 'gamelib_revoked';

	/**
	 * Outcome value of a successful action. The same literal both clients use
	 * for a successful call, so a classified result needs no translation here.
	 *
	 * @var string
	 */
	const RESULT_OK = 'ok';

	/**
	 * Outcome value of a refused revoke (already redeemed, already revoked, or
	 * gone).
	 *
	 * @var string
	 */
	const RESULT_FAILED = 'failed';

	/**
	 * Screen ids of the two pages, keyed by menu slug, as `add_submenu_page()`
	 * reported them. Populated on `admin_menu` and read on `admin_notices` in
	 * the same request, which is what lets a notice scope itself to its own
	 * screen without reading `$_GET['page']`.
	 *
	 * @var array<string, string>
	 */
	private static $screens = array();

	/**
	 * Add both screens under the Games menu.
	 *
	 * Hooked to `admin_menu`. The settings screen takes `manage_options`
	 * because that is the capability its own save path (`options.php`)
	 * enforces; the invites screen takes `gamelib_admin_override`, the
	 * capability its Revoke action requires (AC-006f).
	 *
	 * @return void
	 */
	public static function register_menus() {
		$settings_hook = add_submenu_page(
			self::parent_slug(),
			__( 'Game Library Settings', 'game-library' ),
			__( 'Settings', 'game-library' ),
			'manage_options',
			self::PAGE_SETTINGS,
			array( __CLASS__, 'render_settings_page' )
		);

		$invites_hook = add_submenu_page(
			self::parent_slug(),
			__( 'Game Library Invites', 'game-library' ),
			__( 'Invites', 'game-library' ),
			GameLib_Capabilities::CAP_ADMIN_OVERRIDE,
			self::PAGE_INVITES,
			array( __CLASS__, 'render_invites_page' )
		);

		self::$screens = array(
			self::PAGE_SETTINGS => is_string( $settings_hook ) ? $settings_hook : '',
			self::PAGE_INVITES  => is_string( $invites_hook ) ? $invites_hook : '',
		);
	}

	/**
	 * Register the two stored settings and the fields that write them.
	 *
	 * Hooked to `admin_init`. Both options are sanitized on the way in — the
	 * quota by the domain class that reads it, so the value the field accepts
	 * and the value `GameLib_Invites` enforces can never disagree (AC-054c).
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			GameLib_Invites::QUOTA_OPTION,
			array(
				'type'              => 'integer',
				'description'       => __( 'Invites each member may issue in total. 0 means unlimited.', 'game-library' ),
				'sanitize_callback' => array( 'GameLib_Invites', 'sanitize_quota' ),
				'default'           => 0,
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			self::UNINSTALL_OPTION,
			array(
				'type'              => 'string',
				'description'       => __( 'Whether deleting the plugin also deletes its data.', 'game-library' ),
				'sanitize_callback' => array( __CLASS__, 'sanitize_uninstall' ),
				'default'           => '0',
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			self::SECTION_INVITES,
			__( 'Invites', 'game-library' ),
			array( __CLASS__, 'render_invites_section' ),
			self::PAGE_SETTINGS
		);

		add_settings_field(
			GameLib_Invites::QUOTA_OPTION,
			__( 'Invite quota', 'game-library' ),
			array( __CLASS__, 'render_quota_field' ),
			self::PAGE_SETTINGS,
			self::SECTION_INVITES,
			array( 'label_for' => GameLib_Invites::QUOTA_OPTION )
		);

		add_settings_section(
			self::SECTION_DATA,
			__( 'Data', 'game-library' ),
			array( __CLASS__, 'render_data_section' ),
			self::PAGE_SETTINGS
		);

		add_settings_field(
			self::UNINSTALL_OPTION,
			__( 'On uninstall', 'game-library' ),
			array( __CLASS__, 'render_uninstall_field' ),
			self::PAGE_SETTINGS,
			self::SECTION_DATA,
			array( 'label_for' => self::UNINSTALL_OPTION )
		);
	}

	/**
	 * A submitted uninstall opt-in, reduced to the two values the option holds.
	 *
	 * An absent checkbox reaches this callback as `null` (core hands
	 * `update_option()` a null value for a whitelisted option missing from the
	 * request), which is exactly the "leave nothing behind switched off" case
	 * AC-051(c) defaults to.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string `'1'` when data removal is switched on, `'0'` otherwise.
	 */
	public static function sanitize_uninstall( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '0';
		}

		$value = (string) $value;

		return ( '1' === $value || 'on' === $value || 'true' === $value ) ? '1' : '0';
	}

	/**
	 * The settings screen (AC-054).
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage Game Library settings.', 'game-library' ),
				esc_html__( 'Game Library', 'game-library' ),
				array( 'response' => 403 )
			);
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Game Library Settings', 'game-library' ); ?></h1>
			<?php settings_errors(); ?>

			<h2><?php esc_html_e( 'Integrations', 'game-library' ); ?></h2>
			<p>
				<?php
				esc_html_e(
					'Credentials are read at call time from PHP constants or environment variables. This plugin never stores them in the database and never displays them — the status below reports only whether a value was found.',
					'game-library'
				);
				?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Integration', 'game-library' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'game-library' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Connection test', 'game-library' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					self::render_integration_row(
						self::PROVIDER_IGDB,
						GameLib_IGDB_Client::is_configured(),
						/* translators: %s: names of the constants that configure IGDB. */
						sprintf( __( 'Set %s.', 'game-library' ), 'IGDB_CLIENT_ID, IGDB_CLIENT_SECRET' )
					);

					self::render_integration_row(
						self::PROVIDER_STEAM,
						GameLib_Steam_Client::is_configured(),
						/* translators: %s: name of the constant that configures Steam. */
						sprintf( __( 'Set %s.', 'game-library' ), 'STEAM_WEB_API_KEY' )
					);
					?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Background refresh', 'game-library' ); ?></h2>
			<p><?php esc_html_e( 'The hourly job that revalidates cached game data against IGDB.', 'game-library' ); ?></p>
			<table class="widefat striped">
				<tbody>
					<?php
					foreach ( self::refresh_rows() as $row ) {
						printf(
							'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
							esc_html( $row['label'] ),
							esc_html( $row['value'] )
						);
					}
					?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SETTINGS );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Intro copy for the invite-quota section.
	 *
	 * @return void
	 */
	public static function render_invites_section() {
		?>
		<p>
			<?php
			esc_html_e(
				'The quota is site-wide and counts every invite a member has ever created: redeeming or revoking one does not free up a slot. To stop one member from issuing invites, use the Disable invites control on their user profile instead.',
				'game-library'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Intro copy for the data section.
	 *
	 * @return void
	 */
	public static function render_data_section() {
		?>
		<p>
			<?php
			esc_html_e(
				'Deactivating the plugin never deletes anything. Deleting it removes data only when the box below is ticked.',
				'game-library'
			);
			?>
		</p>
		<?php
	}

	/**
	 * The invite-quota field (AC-054c).
	 *
	 * @return void
	 */
	public static function render_quota_field() {
		$quota = GameLib_Invites::quota();

		?>
		<input
			type="number"
			min="0"
			step="1"
			class="small-text"
			id="<?php echo esc_attr( GameLib_Invites::QUOTA_OPTION ); ?>"
			name="<?php echo esc_attr( GameLib_Invites::QUOTA_OPTION ); ?>"
			value="<?php echo esc_attr( (string) $quota ); ?>"
		/>
		<p class="description">
			<?php esc_html_e( 'Invites each member may issue in total. Set 0 for unlimited.', 'game-library' ); ?>
			<?php
			if ( $quota > 0 ) {
				echo ' ';
				echo esc_html(
					sprintf(
						/* translators: %s: number of invites each member may issue. */
						_n(
							'Each member may issue %s invite.',
							'Each member may issue %s invites.',
							$quota,
							'game-library'
						),
						number_format_i18n( $quota )
					)
				);
			}
			?>
		</p>
		<?php
	}

	/**
	 * The uninstall data-removal opt-in (AC-054d, AC-051).
	 *
	 * The hidden field ahead of the checkbox makes an unticked box an explicit
	 * `'0'` submission rather than an absent key — the sanitizer handles both,
	 * but only one of them is legible in a request log.
	 *
	 * @return void
	 */
	public static function render_uninstall_field() {
		$enabled = ( '1' === (string) get_option( self::UNINSTALL_OPTION, '0' ) );

		?>
		<input type="hidden" name="<?php echo esc_attr( self::UNINSTALL_OPTION ); ?>" value="0" />
		<label for="<?php echo esc_attr( self::UNINSTALL_OPTION ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( self::UNINSTALL_OPTION ); ?>"
				name="<?php echo esc_attr( self::UNINSTALL_OPTION ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Delete all Game Library data when the plugin is deleted', 'game-library' ); ?>
		</label>
		<p class="description">
			<?php
			esc_html_e(
				'Removes every member library, follow, activity event, invite, import, and cached game record, plus the game pages projected from them. This cannot be undone.',
				'game-library'
			);
			?>
		</p>
		<?php
	}

	/**
	 * The invites screen (AC-006).
	 *
	 * The list table class is required here rather than at plugin load: it
	 * extends `WP_List_Table`, which only exists once `wp-admin/includes` has
	 * been loaded, so declaring it on a front-end request would be a fatal
	 * error.
	 *
	 * @return void
	 */
	public static function render_invites_page() {
		if ( ! current_user_can( GameLib_Capabilities::CAP_ADMIN_OVERRIDE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage Game Library invites.', 'game-library' ),
				esc_html__( 'Game Library', 'game-library' ),
				array( 'response' => 403 )
			);
		}

		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		require_once __DIR__ . '/class-invites-table.php';

		$table = new GameLib_Invites_Table();
		$table->prepare_items();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Invites', 'game-library' ); ?></h1>
			<p>
				<?php
				esc_html_e(
					'Every invite issued on this site. Revoking an outstanding invite stops its link working immediately; an invite that has already been redeemed cannot be revoked.',
					'game-library'
				);
				?>
			</p>
			<?php $table->display(); ?>
		</div>
		<?php
	}

	/**
	 * Run one live connection test (AC-054a, AC-054b).
	 *
	 * One of the few paths in this plugin allowed to make an outbound request
	 * from a page request (Never Do #6): an administrator is waiting on it, so
	 * both clients run it on their user-visible 3-second budget (DD-017).
	 *
	 * The outcome travels back as a taxonomy term, never as a message and never
	 * as a response body — {@see result_messages()} turns the term into the
	 * sentence the notice shows.
	 *
	 * @return void
	 */
	public static function handle_test() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce bound to this exact provider is verified on the next line; the value has to be read to name both the action and the field it is bound to.
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';

		check_admin_referer( self::TEST_ACTION . '_' . $provider, self::nonce_name( $provider ) );

		if ( ! current_user_can( GameLib_Capabilities::CAP_ADMIN_OVERRIDE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to test the Game Library integrations.', 'game-library' ),
				esc_html__( 'Game Library', 'game-library' ),
				array( 'response' => 403 )
			);
		}

		if ( ! in_array( $provider, self::PROVIDERS, true ) ) {
			wp_safe_redirect( self::settings_url() );
			exit;
		}

		if ( self::PROVIDER_IGDB === $provider ) {
			$result = GameLib_IGDB_Client::classify( GameLib_IGDB_Client::test_connection() );
		} else {
			$result = GameLib_Steam_Client::classify( GameLib_Steam_Client::test_connection() );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					self::ARG_PROVIDER => rawurlencode( $provider ),
					self::ARG_RESULT   => rawurlencode( $result ),
				),
				self::settings_url()
			)
		);
		exit;
	}

	/**
	 * Withdraw one outstanding invite (AC-006f, AC-NFR-001k).
	 *
	 * The conditional UPDATE that decides the outcome belongs to
	 * {@see GameLib_Invites::revoke()}; this handler contributes the nonce, the
	 * capability, and the notice.
	 *
	 * @return void
	 */
	public static function handle_revoke() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce bound to this exact invite id is verified on the next line; the id has to be read to name the action it is bound to.
		$invite_id = isset( $_REQUEST['invite'] ) ? absint( wp_unslash( $_REQUEST['invite'] ) ) : 0;

		check_admin_referer( self::REVOKE_ACTION . '_' . $invite_id );

		if ( ! current_user_can( GameLib_Capabilities::CAP_ADMIN_OVERRIDE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to revoke invites.', 'game-library' ),
				esc_html__( 'Game Library', 'game-library' ),
				array( 'response' => 403 )
			);
		}

		$revoked = GameLib_Invites::revoke( $invite_id );

		wp_safe_redirect(
			add_query_arg(
				self::ARG_REVOKED,
				is_wp_error( $revoked ) ? self::RESULT_FAILED : self::RESULT_OK,
				self::invites_url()
			)
		);
		exit;
	}

	/**
	 * Answer a logged-out submission of either admin action with 401.
	 *
	 * `admin-post.php` routes an unauthenticated request to
	 * `admin_post_nopriv_{action}`; with nothing listening there the request
	 * would end as a silent, empty 200, which reads like a successful state
	 * change. AC-NFR-001 wants the refusal explicit.
	 *
	 * @return void
	 */
	public static function reject_anonymous() {
		wp_die(
			esc_html__( 'Sign in as an administrator to perform this action.', 'game-library' ),
			esc_html__( 'Game Library', 'game-library' ),
			array( 'response' => 401 )
		);
	}

	/**
	 * Report a connection test or a revoke on the screen it came from.
	 *
	 * Both values are display-only flags on a redirect target: the state change
	 * each reports was nonce- and capability-checked in its handler, and each
	 * value is matched against a fixed list before it is used.
	 *
	 * @return void
	 */
	public static function render_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof WP_Screen ) {
			return;
		}

		if ( self::screen_id( self::PAGE_SETTINGS ) === $screen->id ) {
			self::render_test_notice();

			return;
		}

		if ( self::screen_id( self::PAGE_INVITES ) === $screen->id ) {
			self::render_revoke_notice();
		}
	}

	/**
	 * URL of the settings screen.
	 *
	 * @return string Admin URL.
	 */
	public static function settings_url() {
		return add_query_arg(
			array(
				'post_type' => GameLib_Game_CPT::POST_TYPE,
				'page'      => self::PAGE_SETTINGS,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * URL of the invites screen.
	 *
	 * @return string Admin URL.
	 */
	public static function invites_url() {
		return add_query_arg(
			array(
				'post_type' => GameLib_Game_CPT::POST_TYPE,
				'page'      => self::PAGE_INVITES,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * The nonce-protected Revoke URL for one invite (AC-006f).
	 *
	 * Public because {@see GameLib_Invites_Table} composes the row action from
	 * it; the nonce is bound to the individual invite id, so a URL minted for
	 * one row cannot revoke another.
	 *
	 * @param int $invite_id Invite row id.
	 * @return string Absolute `admin-post.php` URL, or '' for a malformed id.
	 */
	public static function revoke_url( $invite_id ) {
		$invite_id = absint( $invite_id );

		if ( $invite_id < 1 ) {
			return '';
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::REVOKE_ACTION,
					'invite' => $invite_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::REVOKE_ACTION . '_' . $invite_id
		);
	}

	/**
	 * One integration's status row, with its test control (AC-054a,b).
	 *
	 * @param string $provider   One of {@see GameLib_Admin_Settings::PROVIDERS}.
	 * @param bool   $configured Whether the integration's credentials resolved.
	 * @param string $hint       What to set when they did not.
	 * @return void
	 */
	private static function render_integration_row( $provider, $configured, $hint ) {
		$label = self::provider_label( $provider );

		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<span class="gamelib-integration-status" data-gamelib-status="<?php echo esc_attr( $configured ? 'configured' : 'not-configured' ); ?>">
					<?php echo esc_html( $configured ? __( 'Configured', 'game-library' ) : __( 'Not configured', 'game-library' ) ); ?>
				</span>
				<?php if ( ! $configured ) : ?>
					<p class="description"><?php echo esc_html( $hint ); ?></p>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( current_user_can( GameLib_Capabilities::CAP_ADMIN_OVERRIDE ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST_ACTION ); ?>" />
						<input type="hidden" name="provider" value="<?php echo esc_attr( $provider ); ?>" />
						<?php
						wp_nonce_field( self::TEST_ACTION . '_' . $provider, self::nonce_name( $provider ) );

						submit_button(
							__( 'Test connection', 'game-library' ),
							'secondary',
							'submit',
							false,
							array(
								// Both integration rows render this control, and
								// core derives the field id from the field name
								// — without an explicit id the screen would carry
								// two `id="submit"` and two `id="_wpnonce"`
								// elements (the nonce name above is scoped for
								// the same reason).
								'id'                  => 'gamelib-test-' . $provider,
								'data-gamelib-action' => 'admin.test-connection',
								'aria-label'          => sprintf(
									/* translators: %s: integration name, e.g. IGDB. */
									__( 'Test the %s connection', 'game-library' ),
									$label
								),
							)
						);
						?>
					</form>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Only an administrator can run this test.', 'game-library' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * The connection-test notice.
	 *
	 * @return void
	 */
	private static function render_test_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag on a redirect target; the outbound call it reports was nonce- and capability-checked in handle_test(), and both values are matched against fixed lists before use.
		$provider = isset( $_GET[ self::ARG_PROVIDER ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG_PROVIDER ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$result = isset( $_GET[ self::ARG_RESULT ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG_RESULT ] ) ) : '';

		if ( '' === $result || ! in_array( $provider, self::PROVIDERS, true ) ) {
			return;
		}

		$messages = self::result_messages( self::provider_label( $provider ) );
		$success  = ( self::RESULT_OK === $result );

		wp_admin_notice(
			esc_html(
				isset( $messages[ $result ] )
					? $messages[ $result ]
					: sprintf(
						/* translators: %s: integration name, e.g. IGDB. */
						__( 'The %s connection test failed. Nothing was changed.', 'game-library' ),
						self::provider_label( $provider )
					)
			),
			array(
				'type'        => $success ? 'success' : 'error',
				'dismissible' => true,
			)
		);
	}

	/**
	 * The revoke notice.
	 *
	 * @return void
	 */
	private static function render_revoke_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag on a redirect target; the write it reports was nonce- and capability-checked in handle_revoke(), and the value is matched against a fixed list before use.
		$outcome = isset( $_GET[ self::ARG_REVOKED ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG_REVOKED ] ) ) : '';

		if ( self::RESULT_OK !== $outcome && self::RESULT_FAILED !== $outcome ) {
			return;
		}

		$success = ( self::RESULT_OK === $outcome );

		wp_admin_notice(
			esc_html(
				$success
					? __( 'Invite revoked. Its link no longer works.', 'game-library' )
					: __( 'That invite could not be revoked: only an outstanding invite can be withdrawn.', 'game-library' )
			),
			array(
				'type'        => $success ? 'success' : 'error',
				'dismissible' => true,
			)
		);
	}

	/**
	 * The sentence each outcome term produces, keyed by term.
	 *
	 * The IGDB and Steam clients publish the same four shared failure terms
	 * (`unconfigured`, `rate_limited`, `unavailable`, `malformed`) and Steam
	 * adds two of its own, so one map keyed by the term serves both providers.
	 * None of these sentences carries a response body or a credential — the
	 * class of failure is the whole message (AC-054a, Never Do #5).
	 *
	 * @param string $label Provider display name.
	 * @return array<string, string> Term => translated sentence.
	 */
	private static function result_messages( $label ) {
		return array(
			self::RESULT_OK                             => sprintf(
				/* translators: %s: integration name, e.g. IGDB. */
				__( 'The %s connection test succeeded.', 'game-library' ),
				$label
			),
			GameLib_IGDB_Client::ERROR_UNCONFIGURED     => sprintf(
				/* translators: %s: integration name, e.g. IGDB. */
				__( '%s is not configured: no credentials were found in this site\'s constants or environment.', 'game-library' ),
				$label
			),
			GameLib_IGDB_Client::ERROR_RATE_LIMITED     => sprintf(
				/* translators: %s: integration name, e.g. IGDB. */
				__( '%s refused the request as rate limited (HTTP 429). Try again in a moment.', 'game-library' ),
				$label
			),
			GameLib_IGDB_Client::ERROR_UNAVAILABLE      => sprintf(
				/* translators: %s: integration name, e.g. IGDB. */
				__( '%s is unreachable or returned a server error. Try again shortly.', 'game-library' ),
				$label
			),
			GameLib_IGDB_Client::ERROR_MALFORMED        => sprintf(
				/* translators: %s: integration name, e.g. IGDB. */
				__( '%s returned a response this site could not read. Check that the credentials belong to the right application.', 'game-library' ),
				$label
			),
			GameLib_Steam_Client::ERROR_NOT_FOUND       => sprintf(
				/* translators: %s: integration name, e.g. Steam. */
				__( '%s answered, but reported the test lookup as unknown. The key works.', 'game-library' ),
				$label
			),
			GameLib_Steam_Client::ERROR_PRIVATE_PROFILE => sprintf(
				/* translators: %s: integration name, e.g. Steam. */
				__( '%s answered, but the account it was asked about is private.', 'game-library' ),
				$label
			),
		);
	}

	/**
	 * The status rows of the hourly refresh job.
	 *
	 * Read-only, and read entirely from state the job persists — this screen
	 * never runs or reschedules it.
	 *
	 * @return array<int, array{label: string, value: string}> Label/value rows.
	 */
	private static function refresh_rows() {
		$state = GameLib_Refresh_Job::state();
		$next  = wp_next_scheduled( GameLib_Plugin::CRON_REFRESH_HOOK );
		$never = __( 'Never', 'game-library' );

		$outcome = __( 'No run recorded yet.', 'game-library' );

		if ( $state['last_run_at'] > 0 ) {
			$outcome = ( '' === $state['last_error'] )
				? __( 'Succeeded', 'game-library' )
				: sprintf(
					/* translators: %s: failure class reported by the IGDB client, e.g. rate_limited. */
					__( 'Failed (%s)', 'game-library' ),
					$state['last_error']
				);
		}

		return array(
			array(
				'label' => __( 'Next scheduled run', 'game-library' ),
				'value' => is_int( $next ) ? self::format_timestamp( $next ) : __( 'Not scheduled', 'game-library' ),
			),
			array(
				'label' => __( 'Last run', 'game-library' ),
				'value' => ( $state['last_run_at'] > 0 ) ? self::format_timestamp( $state['last_run_at'] ) : $never,
			),
			array(
				'label' => __( 'Last successful run', 'game-library' ),
				'value' => ( $state['last_success_at'] > 0 ) ? self::format_timestamp( $state['last_success_at'] ) : $never,
			),
			array(
				'label' => __( 'Last outcome', 'game-library' ),
				'value' => $outcome,
			),
			array(
				'label' => __( 'Games in the last batch', 'game-library' ),
				'value' => number_format_i18n( $state['last_batch'] ),
			),
		);
	}

	/**
	 * One UTC timestamp in the site's own format and timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string Formatted date and time.
	 */
	private static function format_timestamp( $timestamp ) {
		return (string) wp_date(
			(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ),
			(int) $timestamp
		);
	}

	/**
	 * Name of the nonce field one integration's test form carries.
	 *
	 * Scoped to the provider so the two forms on the screen do not both emit
	 * an element with `id="_wpnonce"`; `handle_test()` reads the same name back
	 * through `check_admin_referer()`'s second argument.
	 *
	 * @param string $provider Provider key, as submitted.
	 * @return string Field name.
	 */
	private static function nonce_name( $provider ) {
		return self::TEST_ACTION . '_nonce_' . sanitize_key( (string) $provider );
	}

	/**
	 * Display name of one integration.
	 *
	 * @param string $provider One of {@see GameLib_Admin_Settings::PROVIDERS}.
	 * @return string Provider name.
	 */
	private static function provider_label( $provider ) {
		return ( self::PROVIDER_STEAM === $provider ) ? 'Steam' : 'IGDB';
	}

	/**
	 * The screen id one of this class's pages renders under.
	 *
	 * @param string $page Menu slug.
	 * @return string Screen id, or '' when the menu has not been built.
	 */
	private static function screen_id( $page ) {
		return isset( self::$screens[ $page ] ) ? (string) self::$screens[ $page ] : '';
	}

	/**
	 * Parent menu slug: the game list screen both pages hang under.
	 *
	 * @return string Menu slug.
	 */
	private static function parent_slug() {
		return 'edit.php?post_type=' . GameLib_Game_CPT::POST_TYPE;
	}
}
