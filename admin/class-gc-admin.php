<?php
/**
 * Admin: settings and invite management.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_gc_generate_invites', array( __CLASS__, 'handle_generate_invites' ) );
		add_action( 'admin_post_gc_delete_invite', array( __CLASS__, 'handle_delete_invite' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'Game Collector', 'game-collector' ),
			__( 'Game Collector', 'game-collector' ),
			'manage_options',
			'gc-settings',
			array( __CLASS__, 'render_settings' ),
			'dashicons-games',
			58
		);

		add_submenu_page(
			'gc-settings',
			__( 'Settings', 'game-collector' ),
			__( 'Settings', 'game-collector' ),
			'manage_options',
			'gc-settings',
			array( __CLASS__, 'render_settings' )
		);

		add_submenu_page(
			'gc-settings',
			__( 'Invites', 'game-collector' ),
			__( 'Invites', 'game-collector' ),
			'manage_options',
			'gc-invites',
			array( __CLASS__, 'render_invites' )
		);
	}

	public static function register_settings() {
		register_setting(
			'gc_settings',
			'gc_igdb_client_id',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);
		register_setting(
			'gc_settings',
			'gc_igdb_client_secret',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);
		register_setting(
			'gc_settings',
			'gc_force_registration',
			array(
				'sanitize_callback' => function ( $value ) {
					return '1' === $value ? '1' : '0';
				},
			)
		);
	}

	public static function setup_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! get_option( 'permalink_structure' ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Game Collector: pretty permalinks are required for the /my-library/, /library/…/, and /activity/ pages.', 'game-collector' ),
				esc_url( admin_url( 'options-permalink.php' ) ),
				esc_html__( 'Enable them here', 'game-collector' )
			);
		}

		if ( GC_IGDB::is_configured() ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_gc-settings' === $screen->id ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Game Collector: IGDB API credentials are missing — game search will not work.', 'game-collector' ),
			esc_url( admin_url( 'admin.php?page=gc-settings' ) ),
			esc_html__( 'Add them now', 'game-collector' )
		);
	}

	/* ---- Settings page ---- */

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$test_result = null;
		if ( isset( $_GET['gc_test'] ) && check_admin_referer( 'gc_test_connection' ) ) {
			$test_result = GC_IGDB::search( 'zelda', 1 );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Game Collector Settings', 'game-collector' ); ?></h1>

			<?php if ( null !== $test_result ) : ?>
				<?php if ( is_wp_error( $test_result ) ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $test_result->get_error_message() ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-success"><p><?php esc_html_e( 'IGDB connection works!', 'game-collector' ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'gc_settings' ); ?>

				<h2><?php esc_html_e( 'IGDB API', 'game-collector' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: link to Twitch dev console */
						esc_html__( 'Register an application in the %s to get a Client ID and Secret (IGDB uses Twitch for authentication).', 'game-collector' ),
						'<a href="https://dev.twitch.tv/console/apps" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Twitch Developer Console', 'game-collector' ) . '</a>'
					);
					?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gc_igdb_client_id"><?php esc_html_e( 'Client ID', 'game-collector' ); ?></label></th>
						<td><input name="gc_igdb_client_id" id="gc_igdb_client_id" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'gc_igdb_client_id' ) ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="gc_igdb_client_secret"><?php esc_html_e( 'Client Secret', 'game-collector' ); ?></label></th>
						<td><input name="gc_igdb_client_secret" id="gc_igdb_client_secret" type="password" class="regular-text" value="<?php echo esc_attr( get_option( 'gc_igdb_client_secret' ) ); ?>" autocomplete="off" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Membership', 'game-collector' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Registration', 'game-collector' ); ?></th>
						<td>
							<input type="hidden" name="gc_force_registration" value="0" />
							<label>
								<input name="gc_force_registration" type="checkbox" value="1" <?php checked( get_option( 'gc_force_registration', '1' ), '1' ); ?> />
								<?php esc_html_e( 'Keep the registration form open (an invite code is always required to sign up)', 'game-collector' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When unchecked, registration follows the “Anyone can register” setting under Settings → General — but even then, no one can join without a valid invite code.', 'game-collector' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php if ( GC_IGDB::is_configured() ) : ?>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=gc-settings&gc_test=1' ), 'gc_test_connection' ) ); ?>">
						<?php esc_html_e( 'Test IGDB connection', 'game-collector' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---- Invites page ---- */

	public static function handle_generate_invites() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'game-collector' ) );
		}
		check_admin_referer( 'gc_generate_invites' );

		$count   = isset( $_POST['gc_count'] ) ? (int) $_POST['gc_count'] : 1;
		$note    = isset( $_POST['gc_note'] ) ? sanitize_text_field( wp_unslash( $_POST['gc_note'] ) ) : '';
		$expires = isset( $_POST['gc_expires'] ) ? (int) $_POST['gc_expires'] : 0;

		GC_Invites::generate( $count, $note, $expires );

		wp_safe_redirect( admin_url( 'admin.php?page=gc-invites&gc_generated=' . $count ) );
		exit;
	}

	public static function handle_delete_invite() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'game-collector' ) );
		}

		$invite_id = isset( $_GET['invite'] ) ? (int) $_GET['invite'] : 0;
		check_admin_referer( 'gc_delete_invite_' . $invite_id );

		GC_Invites::delete( $invite_id );

		wp_safe_redirect( admin_url( 'admin.php?page=gc-invites&gc_deleted=1' ) );
		exit;
	}

	public static function render_invites() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$invites = GC_Invites::get_all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Invites', 'game-collector' ); ?></h1>

			<?php if ( isset( $_GET['gc_generated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php printf( esc_html( _n( '%s invite generated.', '%s invites generated.', (int) $_GET['gc_generated'], 'game-collector' ) ), esc_html( number_format_i18n( (int) $_GET['gc_generated'] ) ) ); ?>
				</p></div>
			<?php elseif ( isset( $_GET['gc_deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Invite deleted.', 'game-collector' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 1em 0 2em; padding: 1em; background: #fff; border: 1px solid #ccd0d4; max-width: 640px;">
				<?php wp_nonce_field( 'gc_generate_invites' ); ?>
				<input type="hidden" name="action" value="gc_generate_invites" />
				<h2 style="margin-top:0;"><?php esc_html_e( 'Generate invites', 'game-collector' ); ?></h2>
				<p>
					<label><?php esc_html_e( 'How many:', 'game-collector' ); ?>
						<input type="number" name="gc_count" value="1" min="1" max="50" style="width:70px;" />
					</label>
					&nbsp;
					<label><?php esc_html_e( 'Expires in (days, 0 = never):', 'game-collector' ); ?>
						<input type="number" name="gc_expires" value="0" min="0" max="365" style="width:70px;" />
					</label>
				</p>
				<p>
					<label><?php esc_html_e( 'Note (e.g. who it’s for):', 'game-collector' ); ?>
						<input type="text" name="gc_note" class="regular-text" />
					</label>
				</p>
				<?php submit_button( __( 'Generate', 'game-collector' ), 'primary', 'submit', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Code', 'game-collector' ); ?></th>
						<th><?php esc_html_e( 'Invite link', 'game-collector' ); ?></th>
						<th><?php esc_html_e( 'Note', 'game-collector' ); ?></th>
						<th><?php esc_html_e( 'Status', 'game-collector' ); ?></th>
						<th><?php esc_html_e( 'Created', 'game-collector' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $invites ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No invites yet. Generate some above.', 'game-collector' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $invites as $invite ) : ?>
						<tr>
							<td><code><?php echo esc_html( $invite->code ); ?></code></td>
							<td>
								<?php if ( ! $invite->used_by ) : ?>
									<input type="text" readonly value="<?php echo esc_attr( GC_Invites::invite_url( $invite->code ) ); ?>" style="width: 100%; max-width: 340px;" onclick="this.select();" />
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $invite->note ); ?></td>
							<td>
								<?php
								if ( $invite->used_by ) {
									$member = get_userdata( $invite->used_by );
									printf(
										/* translators: %s: member name */
										esc_html__( 'Used by %s', 'game-collector' ),
										$member ? esc_html( $member->display_name ) : esc_html__( '(deleted user)', 'game-collector' )
									);
								} elseif ( $invite->expires_at && strtotime( $invite->expires_at ) < time() ) {
									esc_html_e( 'Expired', 'game-collector' );
								} else {
									esc_html_e( 'Available', 'game-collector' );
								}
								?>
							</td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $invite->created_at ) ); ?></td>
							<td>
								<?php if ( ! $invite->used_by ) : ?>
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gc_delete_invite&invite=' . $invite->id ), 'gc_delete_invite_' . $invite->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this invite?', 'game-collector' ) ); ?>');">
										<?php esc_html_e( 'Delete', 'game-collector' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
