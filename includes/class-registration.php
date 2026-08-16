<?php
/**
 * Invite-gated registration: /join form handler + core-path gating.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns an unredeemed invite code into exactly one account. The /join form posts
 * here; the core wp-login.php registration path is blocked unless it carries a
 * valid unredeemed code. Redemption is atomic (see Invite_Service::redeem).
 */
final class Game_Library_Registration {

	const JOIN_ACTION  = 'game_library_join';
	const NONCE_ACTION = 'game_library_join';
	const CODE_FIELD   = 'game_library_invite_code';
	const ERROR_PREFIX = 'game_library_join_';

	/**
	 * When true, the user_register auto-redeem hook stands down because the /join
	 * handler is performing the redemption explicitly.
	 *
	 * @var bool
	 */
	private static $skip_auto_redeem = false;

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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_post_nopriv_' . self::JOIN_ACTION, array( $this, 'handle_join' ) );
		add_action( 'admin_post_' . self::JOIN_ACTION, array( $this, 'handle_join' ) );
		add_filter( 'registration_errors', array( $this, 'gate_core_registration' ), 10, 1 );
		add_action( 'user_register', array( $this, 'auto_redeem_core_path' ), 10, 1 );
	}

	/**
	 * Handle a /join form submission: validate, create the account, atomically
	 * redeem, then log the new member in.
	 *
	 * @return void
	 */
	public function handle_join() {
		$code = isset( $_POST[ self::CODE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CODE_FIELD ] ) ) : '';

		check_admin_referer( self::NONCE_ACTION );

		$redirect = home_url( '/join/' . rawurlencode( $code ) );

		if ( is_user_logged_in() ) {
			$this->redirect_with_errors( $redirect, array( __( 'You are already a member.', 'game-library' ) ), array() );
		}

		$username = isset( $_POST['gl_username'] ) ? sanitize_user( wp_unslash( $_POST['gl_username'] ), true ) : '';
		$email    = isset( $_POST['gl_email'] ) ? sanitize_email( wp_unslash( $_POST['gl_email'] ) ) : '';
		$password = isset( $_POST['gl_password'] ) ? (string) wp_unslash( $_POST['gl_password'] ) : '';

		$errors = array();

		if ( ! $this->invites->is_valid_unredeemed( $code ) ) {
			$errors[] = __( 'This invite code is missing, unknown, or already used.', 'game-library' );
		}
		if ( '' === $username || ! validate_username( $username ) ) {
			$errors[] = __( 'Please choose a valid username.', 'game-library' );
		} elseif ( username_exists( $username ) ) {
			$errors[] = __( 'That username is already taken.', 'game-library' );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			$errors[] = __( 'Please enter a valid email address.', 'game-library' );
		} elseif ( email_exists( $email ) ) {
			$errors[] = __( 'That email address is already registered.', 'game-library' );
		}
		if ( strlen( $password ) < 8 ) {
			$errors[] = __( 'Please choose a password of at least 8 characters.', 'game-library' );
		}

		if ( ! empty( $errors ) ) {
			$this->redirect_with_errors(
				$redirect,
				$errors,
				array(
					'username' => $username,
					'email'    => $email,
				)
			);
		}

		// Create the account. Suppress the auto-redeem hook — we redeem explicitly.
		self::$skip_auto_redeem = true;
		$user_id                = wp_insert_user(
			array(
				'user_login' => $username,
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => get_option( 'default_role', 'subscriber' ),
			)
		);
		self::$skip_auto_redeem = false;

		if ( is_wp_error( $user_id ) ) {
			$this->redirect_with_errors(
				$redirect,
				array( $user_id->get_error_message() ),
				array(
					'username' => $username,
					'email'    => $email,
				)
			);
		}

		// Atomic single-use redemption. If we lost a concurrent race, roll back.
		if ( ! $this->invites->redeem( $code, $user_id ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
			$this->redirect_with_errors( $redirect, array( __( 'This invite has just been used. Please request another.', 'game-library' ) ), array() );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );

		wp_safe_redirect( home_url( '/my-library/' ) );
		exit;
	}

	/**
	 * Block the core wp-login.php registration path unless it carries a valid
	 * unredeemed code (AC-014).
	 *
	 * @param WP_Error $errors Accumulating registration errors.
	 * @return WP_Error
	 */
	public function gate_core_registration( $errors ) {
		$code = isset( $_POST[ self::CODE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CODE_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core registration form owns its own nonce; we only read the code.

		if ( ! $this->invites->is_valid_unredeemed( $code ) ) {
			$errors->add(
				'game_library_invite_required',
				__( '<strong>Registration is invite-only.</strong> A valid invite code is required.', 'game-library' )
			);
		}

		return $errors;
	}

	/**
	 * Redeem the invite for a core-path registration once the account exists.
	 * Skipped when the /join handler is redeeming explicitly.
	 *
	 * @param int $user_id Newly-created user id.
	 * @return void
	 */
	public function auto_redeem_core_path( $user_id ) {
		if ( self::$skip_auto_redeem ) {
			return;
		}

		$code = isset( $_POST[ self::CODE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CODE_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core registration form owns its own nonce; we only read the code.
		if ( '' === $code ) {
			return;
		}

		if ( ! $this->invites->redeem( $code, $user_id ) ) {
			// The code was consumed concurrently — remove the orphaned account.
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
		}
	}

	/**
	 * Store errors + prior input in a short-lived transient and redirect back to
	 * the /join page (post/redirect/get).
	 *
	 * @param string   $redirect Target /join URL.
	 * @param string[] $errors   Human-readable error messages.
	 * @param array    $input    Prior non-secret input to repopulate.
	 * @return void
	 */
	private function redirect_with_errors( $redirect, array $errors, array $input ) {
		$token = wp_generate_password( 20, false );
		set_transient(
			self::ERROR_PREFIX . $token,
			array(
				'errors' => array_map( 'wp_strip_all_tags', $errors ),
				'input'  => array_map( 'sanitize_text_field', $input ),
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( add_query_arg( 'gl_join', $token, $redirect ) );
		exit;
	}

	/**
	 * Read (and clear) the stored join errors/input for a token.
	 *
	 * @param string $token The gl_join token.
	 * @return array{errors:string[],input:array<string,string>}
	 */
	public static function consume_errors( $token ) {
		$token = sanitize_text_field( $token );
		$empty = array(
			'errors' => array(),
			'input'  => array(),
		);
		if ( '' === $token ) {
			return $empty;
		}
		$data = get_transient( self::ERROR_PREFIX . $token );
		if ( ! is_array( $data ) ) {
			return $empty;
		}
		delete_transient( self::ERROR_PREFIX . $token );
		return array(
			'errors' => isset( $data['errors'] ) ? (array) $data['errors'] : array(),
			'input'  => isset( $data['input'] ) ? (array) $data['input'] : array(),
		);
	}
}
