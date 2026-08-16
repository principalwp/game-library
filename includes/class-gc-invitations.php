<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GCOLLECTOR_Invitations {
	public function hooks() {
		add_filter( 'option_users_can_register', array( $this, 'gate_registration' ) );
		add_action( 'register_form', array( $this, 'registration_fields' ) );
		add_filter( 'registration_errors', array( $this, 'validate_registration' ), 10, 3 );
		add_action( 'user_register', array( $this, 'accept_invitation' ) );
		add_filter( 'login_message', array( $this, 'registration_message' ) );
	}

	public function gate_registration( $allowed ) {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'register' !== $action ) {
			return false;
		}

		return (bool) $this->get_request_invitation();
	}

	public function registration_fields() {
		$token      = $this->request_token();
		$invitation = $this->find_valid( $token );
		if ( ! $invitation ) {
			return;
		}

		printf( '<input type="hidden" name="gcollector_invite" value="%s">', esc_attr( $token ) );
		printf(
			'<p class="message">%s</p>',
			esc_html( sprintf( __( 'This invitation is for %s.', 'game-collector' ), $invitation->email ) )
		);
		?>
		<script>
		(function () {
			var field = document.getElementById('user_email');
			if (field) {
				field.value = <?php echo wp_json_encode( $invitation->email ); ?>;
				field.readOnly = true;
			}
		}());
		</script>
		<?php
	}

	public function validate_registration( $errors, $sanitized_user_login, $user_email ) {
		$invitation = $this->get_request_invitation();
		if ( ! $invitation ) {
			$errors->add( 'invalid_invitation', __( 'This invitation is invalid, expired, or has already been used.', 'game-collector' ) );
			return $errors;
		}

		if ( strtolower( trim( $user_email ) ) !== strtolower( $invitation->email ) ) {
			$errors->add( 'invitation_email', __( 'Please register with the email address that was invited.', 'game-collector' ) );
		}

		return $errors;
	}

	public function accept_invitation( $user_id ) {
		global $wpdb;

		$invitation = $this->get_request_invitation();
		if ( ! $invitation ) {
			return;
		}

		$wpdb->update(
			GCOLLECTOR_Database::table( 'invitations' ),
			array(
				'accepted_by' => absint( $user_id ),
				'accepted_at' => current_time( 'mysql', true ),
			),
			array(
				'id'          => absint( $invitation->id ),
				'accepted_by' => null,
			),
			array( '%d', '%s' ),
			array( '%d', null )
		);
	}

	public function registration_message( $message ) {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'register' === $action && ! $this->get_request_invitation() ) {
			$message .= '<p class="message register">' . esc_html__( 'Registration is invitation-only.', 'game-collector' ) . '</p>';
		}

		return $message;
	}

	public function create( $email, $days = 7, $invited_by = 0 ) {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Enter a valid email address.', 'game-collector' ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'existing_user', __( 'A user with that email address already exists.', 'game-collector' ) );
		}

		$days  = min( 30, max( 1, absint( $days ) ) );
		$token = bin2hex( random_bytes( 32 ) );

		$wpdb->update(
			GCOLLECTOR_Database::table( 'invitations' ),
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array(
				'email'       => $email,
				'accepted_by' => null,
				'revoked_at'  => null,
			),
			array( '%s' ),
			array( '%s', null, null )
		);

		$inserted = $wpdb->insert(
			GCOLLECTOR_Database::table( 'invitations' ),
			array(
				'email'       => $email,
				'token_hash'  => hash( 'sha256', $token ),
				'invited_by'  => absint( $invited_by ),
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + ( DAY_IN_SECONDS * $days ) ),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'invite_failed', __( 'The invitation could not be created.', 'game-collector' ) );
		}

		$url  = add_query_arg(
			array(
				'action' => 'register',
				'invite' => $token,
			),
			wp_login_url()
		);
		$sent = wp_mail(
			$email,
			sprintf( __( 'You are invited to %s', 'game-collector' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf(
				/* translators: 1: site name, 2: invitation URL, 3: number of days. */
				__( "You have been invited to join %1\$s.\n\nCreate your account: %2\$s\n\nThis link expires in %3\$d days.", 'game-collector' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				$url,
				$days
			)
		);

		return array(
			'url'  => $url,
			'sent' => (bool) $sent,
		);
	}

	public function revoke( $invitation_id ) {
		global $wpdb;

		return false !== $wpdb->update(
			GCOLLECTOR_Database::table( 'invitations' ),
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array(
				'id'          => absint( $invitation_id ),
				'accepted_by' => null,
			),
			array( '%s' ),
			array( '%d', null )
		);
	}

	public function all() {
		global $wpdb;

		return $wpdb->get_results(
			'SELECT * FROM ' . GCOLLECTOR_Database::table( 'invitations' ) . ' ORDER BY created_at DESC LIMIT 100'
		);
	}

	private function get_request_invitation() {
		return $this->find_valid( $this->request_token() );
	}

	private function request_token() {
		$token = '';
		if ( isset( $_REQUEST['gcollector_invite'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token = sanitize_text_field( wp_unslash( $_REQUEST['gcollector_invite'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_REQUEST['invite'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token = sanitize_text_field( wp_unslash( $_REQUEST['invite'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return preg_match( '/^[a-f0-9]{64}$/', $token ) ? $token : '';
	}

	private function find_valid( $token ) {
		global $wpdb;

		if ( ! $token ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . GCOLLECTOR_Database::table( 'invitations' ) . '
				WHERE token_hash = %s AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at >= %s LIMIT 1',
				hash( 'sha256', $token ),
				current_time( 'mysql', true )
			)
		);
	}
}
