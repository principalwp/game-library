<?php
/**
 * Admin screen for issuing and listing invites.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A manage_options-only wp-admin screen to issue invites directly (not counted
 * against any per-member allowance) and list outstanding + redeemed invites. The
 * issue action is nonce + capability protected.
 */
final class Game_Library_Invite_Admin {

	const MENU_SLUG    = 'game-library-invites';
	const ISSUE_ACTION = 'game_library_issue_invite';
	const NONCE_ACTION = 'game_library_issue_invite';

	/**
	 * Invite service.
	 *
	 * @var Game_Library_Invite_Service
	 */
	private $invites;

	/**
	 * Constructor.
	 *
	 * @param Game_Library_Invite_Service $invites Shared invite service.
	 */
	public function __construct( Game_Library_Invite_Service $invites ) {
		$this->invites = $invites;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		add_action( 'admin_post_' . self::ISSUE_ACTION, array( $this, 'handle_issue' ) );
	}

	/**
	 * Add the Invites submenu under Game Library.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			Game_Library_Settings::MENU_SLUG,
			__( 'Invites', 'game-library' ),
			__( 'Invites', 'game-library' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the invites screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage invites.', 'game-library' ) );
		}

		$issued_link = '';
		if ( isset( $_GET['gl_issued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a just-issued link after redirect.
			$issued_link = esc_url_raw( wp_unslash( $_GET['gl_issued'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read-only flag.
		}

		$invites = $this->invites->list_invites();

		// Prime the user cache once so the per-row get_user_by('id',…) for redeemed
		// invites below resolves from cache instead of one wp_users query per row.
		$redeemed_ids = array();
		foreach ( (array) $invites as $invite ) {
			if ( ! empty( $invite->redeemed_by ) ) {
				$redeemed_ids[] = (int) $invite->redeemed_by;
			}
		}
		if ( ! empty( $redeemed_ids ) ) {
			cache_users( array_unique( $redeemed_ids ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Game Library Invites', 'game-library' ); ?></h1>

			<?php if ( '' !== $issued_link ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Invite issued. Share this link:', 'game-library' ); ?>
						<code><?php echo esc_html( $issued_link ); ?></code></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ISSUE_ACTION ); ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<?php submit_button( __( 'Issue new invite', 'game-library' ), 'primary', 'gl_issue', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Invites', 'game-library' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Link', 'game-library' ); ?></th>
						<th><?php esc_html_e( 'Issued', 'game-library' ); ?></th>
						<th><?php esc_html_e( 'Status', 'game-library' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $invites ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No invites yet.', 'game-library' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $invites as $invite ) : ?>
							<?php
							$redeemed = ! empty( $invite->redeemed_by );
							$link     = home_url( '/join/' . rawurlencode( $invite->code ) );
							?>
							<tr>
								<td><code><?php echo esc_html( $link ); ?></code></td>
								<td><?php echo esc_html( $invite->created_at ); ?></td>
								<td>
									<?php
									if ( $redeemed ) {
										$user = get_user_by( 'id', (int) $invite->redeemed_by );
										echo esc_html(
											$user
												/* translators: %s: display name of the member who redeemed the invite. */
												? sprintf( __( 'Redeemed by %s', 'game-library' ), $user->display_name )
												: __( 'Redeemed', 'game-library' )
										);
									} else {
										esc_html_e( 'Outstanding', 'game-library' );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle the issue action (nonce + capability).
	 *
	 * @return void
	 */
	public function handle_issue() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to issue invites.', 'game-library' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$result = $this->invites->issue_as_admin();
		$link   = is_wp_error( $result ) ? '' : $result['link'];

		// menu_page_url() is unavailable in the admin-post.php context (the admin
		// menu is not built there), so construct the screen URL directly.
		wp_safe_redirect(
			add_query_arg(
				array( 'gl_issued' => rawurlencode( $link ) ),
				admin_url( 'admin.php?page=' . self::MENU_SLUG )
			)
		);
		exit;
	}
}
