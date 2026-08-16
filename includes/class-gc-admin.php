<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GCOLLECTOR_Admin {
	private $invitations;

	public function __construct( GCOLLECTOR_Invitations $invitations ) {
		$this->invitations = $invitations;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_gcollector_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_gcollector_create_invite', array( $this, 'create_invite' ) );
		add_action( 'admin_post_gcollector_revoke_invite', array( $this, 'revoke_invite' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'Game Collector', 'game-collector' ),
			__( 'Game Collector', 'game-collector' ),
			'manage_options',
			'game-collector',
			array( $this, 'render' ),
			'dashicons-games',
			58
		);
	}

	public function save_settings() {
		$this->authorize( 'gcollector_save_settings' );

		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$secret    = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';
		update_option( 'gcollector_igdb_client_id', $client_id, false );
		if ( '' !== $secret ) {
			update_option( 'gcollector_igdb_client_secret', $secret, false );
		}
		delete_transient( GCOLLECTOR_IGDB::TOKEN_TRANSIENT );
		$this->redirect( 'settings-saved' );
	}

	public function create_invite() {
		$this->authorize( 'gcollector_create_invite' );

		$email  = isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '';
		$days   = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 7;
		$result = $this->invitations->create( $email, $days, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}

		set_transient(
			'gcollector_invite_' . get_current_user_id(),
			array(
				'url'  => $result['url'],
				'sent' => $result['sent'],
			),
			MINUTE_IN_SECONDS
		);
		$this->redirect( 'invite-created' );
	}

	public function revoke_invite() {
		$this->authorize( 'gcollector_revoke_invite' );
		$id = isset( $_POST['invitation_id'] ) ? absint( $_POST['invitation_id'] ) : 0;
		$this->invitations->revoke( $id );
		$this->redirect( 'invite-revoked' );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = isset( $_GET['gc_notice'] ) ? sanitize_key( wp_unslash( $_GET['gc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error  = isset( $_GET['gc_error'] ) ? sanitize_text_field( wp_unslash( $_GET['gc_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$invite = get_transient( 'gcollector_invite_' . get_current_user_id() );
		if ( $invite ) {
			delete_transient( 'gcollector_invite_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Game Collector', 'game-collector' ); ?></h1>
			<?php if ( 'settings-saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'IGDB settings saved.', 'game-collector' ); ?></p></div>
			<?php elseif ( 'invite-revoked' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Invitation revoked.', 'game-collector' ); ?></p></div>
			<?php elseif ( 'error' === $notice ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_array( $invite ) ) : ?>
				<div class="notice <?php echo $invite['sent'] ? 'notice-success' : 'notice-warning'; ?>">
					<p>
						<?php echo $invite['sent'] ? esc_html__( 'Invitation sent. Copy the link now if needed:', 'game-collector' ) : esc_html__( 'Email could not be sent. Copy this one-time invitation link:', 'game-collector' ); ?>
						<br><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $invite['url'] ); ?>" onfocus="this.select()">
					</p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'IGDB credentials', 'game-collector' ); ?></h2>
			<p><?php esc_html_e( 'Create a Twitch developer application, then enter its client ID and client secret. Credentials stay on the server.', 'game-collector' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gcollector_save_settings">
				<?php wp_nonce_field( 'gcollector_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gc-client-id"><?php esc_html_e( 'Client ID', 'game-collector' ); ?></label></th>
						<td><input id="gc-client-id" name="client_id" class="regular-text" required value="<?php echo esc_attr( get_option( 'gcollector_igdb_client_id', '' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="gc-client-secret"><?php esc_html_e( 'Client secret', 'game-collector' ); ?></label></th>
						<td><input id="gc-client-secret" name="client_secret" class="regular-text" type="password" autocomplete="new-password" placeholder="<?php echo get_option( 'gcollector_igdb_client_secret', '' ) ? esc_attr__( 'Leave blank to keep the current secret', 'game-collector' ) : ''; ?>"></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'game-collector' ) ); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Invite a collector', 'game-collector' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gcollector_create_invite">
				<?php wp_nonce_field( 'gcollector_create_invite' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gc-email"><?php esc_html_e( 'Email', 'game-collector' ); ?></label></th>
						<td><input id="gc-email" name="email" type="email" class="regular-text" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="gc-days"><?php esc_html_e( 'Expires after', 'game-collector' ); ?></label></th>
						<td><input id="gc-days" name="days" type="number" min="1" max="30" value="7"> <?php esc_html_e( 'days', 'game-collector' ); ?></td>
					</tr>
				</table>
				<?php submit_button( __( 'Send invitation', 'game-collector' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent invitations', 'game-collector' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Email', 'game-collector' ); ?></th><th><?php esc_html_e( 'Created', 'game-collector' ); ?></th><th><?php esc_html_e( 'Expires', 'game-collector' ); ?></th><th><?php esc_html_e( 'Status', 'game-collector' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $this->invitations->all() as $row ) : ?>
					<?php
					if ( $row->accepted_at ) {
						$status = __( 'Accepted', 'game-collector' );
					} elseif ( $row->revoked_at ) {
						$status = __( 'Revoked', 'game-collector' );
					} elseif ( strtotime( $row->expires_at . ' UTC' ) < time() ) {
						$status = __( 'Expired', 'game-collector' );
					} else {
						$status = __( 'Pending', 'game-collector' );
					}
					?>
					<tr>
						<td><?php echo esc_html( $row->email ); ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) ) ); ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $row->expires_at, get_option( 'date_format' ) ) ); ?></td>
						<td><?php echo esc_html( $status ); ?></td>
						<td>
						<?php if ( __( 'Pending', 'game-collector' ) === $status ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="gcollector_revoke_invite"><input type="hidden" name="invitation_id" value="<?php echo esc_attr( $row->id ); ?>">
								<?php wp_nonce_field( 'gcollector_revoke_invite' ); ?><button class="button-link-delete"><?php esc_html_e( 'Revoke', 'game-collector' ); ?></button>
							</form>
						<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'game-collector' ), 403 );
		}
		check_admin_referer( $action );
	}

	private function redirect( $notice, $error = '' ) {
		$url = add_query_arg(
			array_filter(
				array(
					'page'     => 'game-collector',
					'gc_notice' => $notice,
					'gc_error'  => $error,
				)
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
