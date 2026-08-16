<?php
/**
 * Join-form handling, invite redemption, auto-login and registration gating.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps registration invite-only and turns a valid join into a member.
 */
class Registration {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'option_users_can_register', '__return_zero' );
		add_action( 'login_init', array( $this, 'redirect_register' ) );
		add_filter( 'register_url', array( $this, 'register_url' ) );
		add_action( 'deleted_user', array( $this, 'on_user_deleted' ) );
	}

	/**
	 * Point the "register" URL at the plugin's Join page.
	 *
	 * @param string $url Default register URL.
	 * @return string
	 */
	public function register_url( $url ) {
		unset( $url );
		return home_url( '/join/' );
	}

	/**
	 * Redirect wp-login.php?action=register to /join/.
	 *
	 * @return void
	 */
	public function redirect_register() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision on a public page.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'register' === $action ) {
			wp_safe_redirect( home_url( '/join/' ) );
			exit;
		}
	}

	/**
	 * OQ-7 cleanup on user deletion.
	 *
	 * @param int $user_id Deleted user id.
	 * @return void
	 */
	public function on_user_deleted( $user_id ) {
		$plugin = Plugin::instance();
		$plugin->library()->delete_for_user( $user_id );
		$plugin->follows()->delete_for_user( $user_id );
		$plugin->activity()->delete_for_user( $user_id );
		$plugin->invites()->null_links_for_user( $user_id );
	}

	/**
	 * Process a join-form POST for a given code. On success it logs the new
	 * member in and redirects (exits); otherwise it returns field errors and
	 * submitted values for re-rendering. Returns null when nothing was posted.
	 *
	 * @param string      $code   The invite code from the URL.
	 * @param object|null $invite The invite row (already resolved), or null.
	 * @return array{errors:array<string,string>, values:array<string,string>}|null
	 */
	public function process_join( $code, $invite ) {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return null;
		}
		if ( ! isset( $_POST['gl_join_submit'] ) ) {
			return null;
		}

		$nonce_ok = isset( $_POST['gl_join_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gl_join_nonce'] ) ), 'gl_join' );

		$username = isset( $_POST['gl_username'] ) ? sanitize_user( wp_unslash( $_POST['gl_username'] ) ) : '';
		$email    = isset( $_POST['gl_email'] ) ? sanitize_email( wp_unslash( $_POST['gl_email'] ) ) : '';
		// Password is validated for length but never sanitized or trimmed.
		$password = isset( $_POST['gl_password'] ) ? (string) wp_unslash( $_POST['gl_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$values = array(
			'username' => $username,
			'email'    => $email,
		);

		$errors = array();

		if ( ! $nonce_ok ) {
			$errors['general'] = __( 'Your session expired. Please try again.', 'game-library-3' );
		}

		$invites = Plugin::instance()->invites();
		$status  = $invite ? $invites->effective_status( $invite ) : '';

		if ( ! $invite || 'pending' !== $status ) {
			// Write-through per the Data Model: when a pending row is encountered
			// past its expiry on the redeem path, persist the 'expired' transition.
			if ( $invite && 'expired' === $status ) {
				$invites->mark_expired( (int) $invite->id );
			}
			$errors['general'] = __( 'This invite is no longer valid.', 'game-library-3' );
			return array(
				'errors' => $errors,
				'values' => $values,
			);
		}

		if ( '' === $username || ! validate_username( $username ) ) {
			$errors['username'] = __( 'Please choose a valid username.', 'game-library-3' );
		} elseif ( username_exists( $username ) ) {
			$errors['username'] = __( 'That username is already taken.', 'game-library-3' );
		}

		if ( '' === $email || ! is_email( $email ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'game-library-3' );
		} elseif ( email_exists( $email ) ) {
			$errors['email'] = __( 'That email address is already registered.', 'game-library-3' );
		}

		if ( strlen( $password ) < 8 ) {
			$errors['password'] = __( 'Please use a password of at least 8 characters.', 'game-library-3' );
		}

		if ( ! empty( $errors ) ) {
			return array(
				'errors' => $errors,
				'values' => $values,
			);
		}

		// Single conditional claim; only proceed to create the user on ===1.
		if ( 1 !== $invites->claim( (int) $invite->id ) ) {
			return array(
				'errors' => array( 'general' => __( 'This invite has just been used.', 'game-library-3' ) ),
				'values' => $values,
			);
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $username,
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => GL_MEMBER_ROLE,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			$invites->revert_claim( (int) $invite->id );
			return array(
				'errors' => array( 'general' => $user_id->get_error_message() ),
				'values' => $values,
			);
		}

		$invites->set_invitee( (int) $invite->id, (int) $user_id );

		// OQ-2 default visibility (also enforced by the user_register hook).
		Plugin::instance()->visibility()->update( (int) $user_id, 'private' );

		wp_set_auth_cookie( (int) $user_id, true );
		wp_set_current_user( (int) $user_id );

		wp_safe_redirect( home_url( '/my-library/' ) );
		exit;
	}
}
