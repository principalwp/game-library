<?php
/**
 * Settings screen: IGDB credentials, invite allowance, wipe-on-uninstall.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the plugin's settings screen and saves it through a nonce +
 * manage_options gated admin-post handler. The Client Secret is written only via
 * the encrypted Game_Library_Secret_Store — never as a plaintext option — and is
 * never echoed back to the screen. When wp-config.php constants supply the
 * credentials the fields are locked and no secret is persisted (AC-016).
 */
final class Game_Library_Settings {

	const MENU_SLUG    = 'game-library';
	const PAGE_HOOK_ID = 'toplevel_page_game-library';
	const SAVE_ACTION  = 'game_library_save_settings';
	const NONCE_ACTION = 'game_library_settings';

	/**
	 * IGDB client (for lock state, credential status, and last error).
	 *
	 * @var Game_Library_IGDB_Client
	 */
	private $igdb;

	/**
	 * Constructor.
	 *
	 * @param Game_Library_IGDB_Client $igdb Shared IGDB client.
	 */
	public function __construct( Game_Library_IGDB_Client $igdb ) {
		$this->igdb = $igdb;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
	}

	/**
	 * Add the top-level Game Library menu and its Settings page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Game Library', 'game-library' ),
			__( 'Game Library', 'game-library' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-games',
			58
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'game-library' ),
			__( 'Settings', 'game-library' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'game-library' ) );
		}

		$locked     = $this->igdb->credentials_locked_by_constant();
		$client_id  = get_option( Game_Library_Activator::OPTION_CLIENT_ID, '' );
		$has_secret = Game_Library_Secret_Store::has_secret();
		$allowance  = (int) get_option( Game_Library_Activator::OPTION_INVITE_ALLOWANCE, Game_Library_Activator::DEFAULT_INVITE_ALLOWANCE );
		$wipe       = (bool) get_option( Game_Library_Activator::OPTION_WIPE_ON_UNINSTALL, 0 );
		$sodium_ok  = Game_Library_Secret_Store::is_supported();
		$updated    = isset( $_GET['gl_updated'] ) ? sanitize_text_field( wp_unslash( $_GET['gl_updated'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag after a redirect.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Game Library Settings', 'game-library' ); ?></h1>

			<?php if ( '1' === $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'game-library' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<h2><?php esc_html_e( 'IGDB / Twitch Credentials', 'game-library' ); ?></h2>

				<?php if ( $locked ) : ?>
					<div class="notice notice-info inline"><p>
						<?php esc_html_e( 'Credentials are defined in wp-config.php. The fields below are locked and take precedence over any stored settings.', 'game-library' ); ?>
					</p></div>
				<?php elseif ( ! $sodium_ok ) : ?>
					<div class="notice notice-error inline"><p>
						<?php esc_html_e( 'The Sodium extension is unavailable, so the Client Secret cannot be encrypted at rest. Define GAME_LIBRARY_IGDB_CLIENT_SECRET in wp-config.php instead.', 'game-library' ); ?>
					</p></div>
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gl_client_id"><?php esc_html_e( 'Client ID', 'game-library' ); ?></label></th>
						<td>
							<input name="gl_client_id" id="gl_client_id" type="text" class="regular-text"
								value="<?php echo esc_attr( $client_id ); ?>" <?php disabled( $locked ); ?> autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gl_client_secret"><?php esc_html_e( 'Client Secret', 'game-library' ); ?></label></th>
						<td>
							<input name="gl_client_secret" id="gl_client_secret" type="password" class="regular-text"
								value="" placeholder="<?php echo $has_secret ? esc_attr__( 'Stored (encrypted). Leave blank to keep.', 'game-library' ) : esc_attr__( 'Enter Client Secret', 'game-library' ); ?>"
								<?php disabled( $locked ); ?> autocomplete="off" />
							<p class="description">
								<?php esc_html_e( 'Stored encrypted at rest (Sodium) and readable only by this plugin server-side. It is never displayed here again.', 'game-library' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Invites', 'game-library' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gl_allowance"><?php esc_html_e( 'Invites per member', 'game-library' ); ?></label></th>
						<td>
							<input name="gl_allowance" id="gl_allowance" type="number" min="0" step="1" class="small-text"
								value="<?php echo esc_attr( (string) $allowance ); ?>" />
							<p class="description"><?php esc_html_e( 'How many invites each member may generate. Admin-issued invites are not counted against this.', 'game-library' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Data', 'game-library' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'game-library' ); ?></th>
						<td>
							<label>
								<input name="gl_wipe_on_uninstall" type="checkbox" value="1" <?php checked( $wipe ); ?> />
								<?php esc_html_e( 'Remove all game-library data on uninstall', 'game-library' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. When unchecked, uninstalling the plugin keeps all library, follow, activity, and invite data.', 'game-library' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'game-library' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save handler: nonce + capability + per-field sanitization.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'game-library' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$locked = $this->igdb->credentials_locked_by_constant();

		// Credentials are only writable when not locked by a constant (AC-016).
		if ( ! $locked ) {
			$client_id = isset( $_POST['gl_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gl_client_id'] ) ) : '';
			update_option( Game_Library_Activator::OPTION_CLIENT_ID, $client_id );

			$secret_input = isset( $_POST['gl_client_secret'] ) ? (string) wp_unslash( $_POST['gl_client_secret'] ) : '';
			$secret_input = trim( $secret_input );
			if ( '' !== $secret_input ) {
				// Never store the secret in plaintext — always via the encrypted store.
				Game_Library_Secret_Store::store( $secret_input );
				if ( function_exists( 'sodium_memzero' ) ) {
					sodium_memzero( $secret_input );
				}
			}

			// If either side of the credentials is now present, try to prime the token cache.
			if ( $this->igdb->credentials_configured() ) {
				$this->igdb->exchange_token();
			}
		}

		$allowance = isset( $_POST['gl_allowance'] ) ? absint( wp_unslash( $_POST['gl_allowance'] ) ) : 0;
		update_option( Game_Library_Activator::OPTION_INVITE_ALLOWANCE, $allowance );

		$wipe = isset( $_POST['gl_wipe_on_uninstall'] ) ? 1 : 0;
		update_option( Game_Library_Activator::OPTION_WIPE_ON_UNINSTALL, $wipe );

		// menu_page_url() is unavailable in the admin-post.php context, so build the URL directly.
		wp_safe_redirect( add_query_arg( array( 'gl_updated' => '1' ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * Show the IGDB failure notice on the plugin's admin screens (AC-017 admin side).
	 *
	 * @return void
	 */
	public function render_admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, self::MENU_SLUG ) ) {
			return;
		}

		$error = $this->igdb->last_error();
		if ( ! $error ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Game Library: the last IGDB request failed.', 'game-library' ); ?></strong>
				<?php echo esc_html( $error['message'] ); ?>
			</p>
		</div>
		<?php
	}
}
