<?php
/**
 * `/join/{code}/`: the invite-gated registration flow.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The only way an account is created on this site (AC-003, AC-005d).
 *
 * Two halves, both owned here so the gate has no seam:
 *
 * - **The `/join/{code}/` route.** A GET resolves one of three states — the
 *   registration form for an outstanding code, the single generic invalid
 *   message for anything else, or an "already a member" note for a visitor who
 *   is signed in. A POST validates the submission with core's own username and
 *   email functions, then hands the code to {@see GameLib_Invites::redeem()},
 *   which claims it atomically, creates the account, and reverts the claim if
 *   creation fails (AC-004). This class therefore never writes to
 *   `gamelib_invites` itself.
 * - **The core registration gate.** `registration_errors` refuses every
 *   `wp-login.php?action=register` submission, so a site that has
 *   `users_can_register` switched on — by an administrator, a migration, or
 *   another plugin — still cannot mint an account outside the invite flow
 *   (AC-005d).
 *
 * Three properties of the invalid response are load-bearing, and all three are
 * about not leaking: it is HTTP **200**, not 404 or 410, so the URL space gives
 * nothing away; it is `noindex`, like every `/join/` view (AC-048b); and it is
 * one message for unknown, redeemed, and revoked alike, naming no issuer
 * (AC-005a,b).
 *
 * A new member is created with the Subscriber role — which is what carries the
 * plugin's capabilities (DD-004) — and with nothing else: no activity event
 * (AC-003e) and no visibility meta, because an absent value already means
 * members-only and writing the default would only create a row to migrate
 * later (AC-027a).
 */
final class GameLib_Registration {

	/**
	 * Role every member is created with (DD-004).
	 *
	 * @var string
	 */
	const MEMBER_ROLE = 'subscriber';

	/**
	 * Render state: a valid outstanding code — show the registration form.
	 *
	 * @var string
	 */
	const STATE_FORM = 'form';

	/**
	 * Render state: unknown, redeemed, or revoked — show the one generic
	 * message (AC-005).
	 *
	 * @var string
	 */
	const STATE_INVALID = 'invalid';

	/**
	 * Render state: the visitor is already signed in, so there is nothing to
	 * redeem. The invite is left untouched.
	 *
	 * @var string
	 */
	const STATE_MEMBER = 'member';

	/**
	 * Form field: the requested username.
	 *
	 * @var string
	 */
	const FIELD_USERNAME = 'gamelib_username';

	/**
	 * Form field: the requested email address.
	 *
	 * @var string
	 */
	const FIELD_EMAIL = 'gamelib_email';

	/**
	 * Form field: the chosen password.
	 *
	 * @var string
	 */
	const FIELD_PASSWORD = 'gamelib_password';

	/**
	 * Form field carrying the submission nonce.
	 *
	 * @var string
	 */
	const NONCE_FIELD = 'gamelib_join_nonce';

	/**
	 * Prefix of the nonce action; the invite code is appended, so a nonce minted
	 * for one invite cannot be replayed against another.
	 *
	 * @var string
	 */
	const NONCE_ACTION_PREFIX = 'gamelib_join_';

	/**
	 * Minimum password length this form accepts.
	 *
	 * WordPress core imposes no minimum of its own, and the form is a public,
	 * unauthenticated write path — so the rule is stated here, enforced
	 * server-side, and shown to the member as a field hint.
	 *
	 * @var int
	 */
	const MIN_PASSWORD_LENGTH = 8;

	/**
	 * Error key for a problem with the submission as a whole rather than one
	 * field (an expired nonce, a refused account creation).
	 *
	 * @var string
	 */
	const ERROR_FORM = 'form';

	/**
	 * Resolved render state for this request, '' when off-route.
	 *
	 * @var string
	 */
	private static $state = '';

	/**
	 * Normalized invite code for this request.
	 *
	 * @var string
	 */
	private static $code = '';

	/**
	 * Field-keyed submission errors.
	 *
	 * @var WP_Error|null
	 */
	private static $errors = null;

	/**
	 * Values worth re-rendering after a failed submission — never the password.
	 *
	 * @var array<string, string>
	 */
	private static $values = array(
		self::FIELD_USERNAME => '',
		self::FIELD_EMAIL    => '',
	);

	/**
	 * Resolve the `/join/` request before anything renders.
	 *
	 * Hooked to `template_redirect` after {@see GameLib_Router::dispatch()}, so
	 * the route is already resolved — and still early enough that a successful
	 * registration can set an auth cookie and redirect, both of which need
	 * headers that have not been sent.
	 *
	 * @return void
	 */
	public static function prepare() {
		if ( GameLib_Router::ROUTE_JOIN !== GameLib_Router::route() ) {
			return;
		}

		/*
		 * Every `/join/` view is noindex (AC-048b) — the valid form as much as
		 * the invalid message, which is served as a 200 and would otherwise be
		 * an indexable page (AC-005c). And nothing here may be cached: the form
		 * carries a nonce, and the invite behind it can be consumed at any
		 * moment by whoever else holds the link.
		 */
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		nocache_headers();

		self::$code = GameLib_Invites::sanitize_code( GameLib_Router::invite_code() );

		if ( is_user_logged_in() ) {
			// Signed in already: nothing to redeem, and the invite stays
			// outstanding for whoever the link was meant for.
			self::$state = self::STATE_MEMBER;

			return;
		}

		if ( ! self::is_post() ) {
			self::$state = GameLib_Invites::is_redeemable( self::$code )
				? self::STATE_FORM
				: self::STATE_INVALID;

			return;
		}

		self::handle_submission();
	}

	/**
	 * Refuse core's own registration form (AC-005d).
	 *
	 * `registration_errors` is the last filter `register_new_user()` consults
	 * before creating anything, so adding an error here is what makes an
	 * enabled `users_can_register` option — however it got enabled — unable to
	 * bypass the invite gate. The message names the way in without naming any
	 * member.
	 *
	 * @param WP_Error $errors               Errors accumulated so far.
	 * @param string   $sanitized_user_login Submitted login (unused).
	 * @param string   $user_email           Submitted email (unused).
	 * @return WP_Error Errors, with the invite requirement added.
	 */
	public static function filter_registration_errors( $errors, $sanitized_user_login = '', $user_email = '' ) {
		if ( ! $errors instanceof WP_Error ) {
			$errors = new WP_Error();
		}

		$errors->add(
			'gamelib_invite_required',
			__( 'This site is invite-only. Ask a member for an invite link to create an account.', 'game-library' )
		);

		return $errors;
	}

	/**
	 * What `templates/join.php` should render.
	 *
	 * @return string One of the `STATE_*` constants, '' off-route.
	 */
	public static function state() {
		return self::$state;
	}

	/**
	 * The invite code this request is about.
	 *
	 * @return string Normalized code, '' when the URL carried nothing usable.
	 */
	public static function code() {
		return self::$code;
	}

	/**
	 * Where the registration form posts: its own canonical URL.
	 *
	 * @return string Absolute URL, '' when there is no usable code.
	 */
	public static function form_action() {
		return GameLib_Invites::join_url( self::$code );
	}

	/**
	 * Nonce action for this request's invite.
	 *
	 * @return string Nonce action.
	 */
	public static function nonce_action() {
		return self::NONCE_ACTION_PREFIX . self::$code;
	}

	/**
	 * The error message for one field, if it has one.
	 *
	 * @param string $field Field name, or {@see GameLib_Registration::ERROR_FORM}.
	 * @return string Message, '' when the field is fine.
	 */
	public static function error( $field ) {
		if ( ! self::$errors instanceof WP_Error ) {
			return '';
		}

		return (string) self::$errors->get_error_message( (string) $field );
	}

	/**
	 * Did the submission fail validation?
	 *
	 * @return bool True when at least one message is queued.
	 */
	public static function has_errors() {
		return self::$errors instanceof WP_Error && self::$errors->has_errors();
	}

	/**
	 * A submitted value worth showing again after a failed attempt.
	 *
	 * Only the username and the email are retained; a password is never
	 * re-rendered into markup.
	 *
	 * @param string $field Field name.
	 * @return string Previously submitted value, '' when there is none.
	 */
	public static function value( $field ) {
		return isset( self::$values[ $field ] ) ? (string) self::$values[ $field ] : '';
	}

	/**
	 * Validate a POSTed registration and, if it holds up, redeem the invite.
	 *
	 * The order matters and is the AC order:
	 *
	 * 1. the nonce, before any submitted value is read;
	 * 2. the invite, so a stale link produces the generic invalid message
	 *    rather than a list of field complaints (AC-005a);
	 * 3. the fields, so a typo never consumes the invite;
	 * 4. the atomic claim + account creation (AC-004).
	 *
	 * @return void
	 */
	private static function handle_submission() {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, self::nonce_action() ) ) {
			self::$state = GameLib_Invites::is_redeemable( self::$code )
				? self::STATE_FORM
				: self::STATE_INVALID;

			self::add_error(
				self::ERROR_FORM,
				__( 'That form expired before it was submitted. Please try again.', 'game-library' )
			);

			return;
		}

		if ( ! GameLib_Invites::is_redeemable( self::$code ) ) {
			self::$state = self::STATE_INVALID;

			return;
		}

		self::$state = self::STATE_FORM;

		// Nonce verified above, in this scope.
		$username_input = isset( $_POST[ self::FIELD_USERNAME ] )
			? trim( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_USERNAME ] ) ) )
			: '';
		$email_input    = isset( $_POST[ self::FIELD_EMAIL ] )
			? trim( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_EMAIL ] ) ) )
			: '';

		/*
		 * A password is the one submitted value that must not be sanitized:
		 * wp_insert_user() hashes it, it is never rendered back into markup,
		 * and stripping characters would silently change the secret the member
		 * chose. Only the slashes WordPress added are removed.
		 */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above: the value is hashed by wp_insert_user() and never echoed. Length is validated below.
		$password = isset( $_POST[ self::FIELD_PASSWORD ] ) ? (string) wp_unslash( $_POST[ self::FIELD_PASSWORD ] ) : '';

		self::$values[ self::FIELD_USERNAME ] = $username_input;
		self::$values[ self::FIELD_EMAIL ]    = $email_input;

		$username = self::validate_username( $username_input );
		$email    = self::validate_email( $email_input );

		self::validate_password( $password );

		if ( self::has_errors() ) {
			// Nothing has touched the invite: it is still outstanding, and the
			// member can correct the form and try again (AC-004).
			return;
		}

		$user_id = GameLib_Invites::redeem(
			self::$code,
			static function () use ( $username, $email, $password ) {
				return self::create_member( $username, $email, $password );
			}
		);

		if ( is_wp_error( $user_id ) ) {
			self::handle_failure( $user_id );

			return;
		}

		self::sign_in_and_redirect( (int) $user_id, $username, $password );
	}

	/**
	 * Turn a failed redemption into the right rendered state.
	 *
	 * A lost race for the invite is indistinguishable from an unknown code
	 * (AC-004b, AC-005a). Anything else — a duplicate email that slipped
	 * through validation, a filter refusing the account — is a form error, and
	 * {@see GameLib_Invites::redeem()} has already returned the invite to
	 * `outstanding` by the time we get here (AC-004c).
	 *
	 * @param WP_Error $error Failure from the redemption.
	 * @return void
	 */
	private static function handle_failure( WP_Error $error ) {
		if ( GameLib_Invites::ERROR_INVALID === $error->get_error_code() ) {
			self::$state = self::STATE_INVALID;

			return;
		}

		self::$state = self::STATE_FORM;

		self::add_error( self::field_for( $error ), $error->get_error_message() );
	}

	/**
	 * Which field a `wp_insert_user()` failure belongs next to.
	 *
	 * @param WP_Error $error Failure from account creation.
	 * @return string Field name, or {@see GameLib_Registration::ERROR_FORM}.
	 */
	private static function field_for( WP_Error $error ) {
		switch ( $error->get_error_code() ) {
			case 'existing_user_login':
			case 'invalid_username':
			case 'user_login_too_long':
			case 'empty_user_login':
				return self::FIELD_USERNAME;

			case 'existing_user_email':
			case 'invalid_email':
				return self::FIELD_EMAIL;
		}

		return self::ERROR_FORM;
	}

	/**
	 * Validate the requested username with core's own rules.
	 *
	 * `sanitize_user()` in strict mode is what `validate_username()` measures
	 * against, so comparing the two is how the form tells a member their
	 * username contained something WordPress would have silently removed —
	 * rather than creating an account under a name they did not type.
	 *
	 * @param string $username Submitted username, trimmed.
	 * @return string The username to create, '' when it is unusable.
	 */
	private static function validate_username( $username ) {
		if ( '' === $username ) {
			self::add_error( self::FIELD_USERNAME, __( 'Choose a username.', 'game-library' ) );

			return '';
		}

		$sanitized = sanitize_user( $username, true );

		if ( '' === $sanitized || $sanitized !== $username || ! validate_username( $sanitized ) ) {
			self::add_error(
				self::FIELD_USERNAME,
				__( 'Usernames can use letters, numbers, spaces, and . _ - @ only.', 'game-library' )
			);

			return '';
		}

		if ( username_exists( $sanitized ) ) {
			self::add_error( self::FIELD_USERNAME, __( 'That username is already taken.', 'game-library' ) );

			return '';
		}

		return $sanitized;
	}

	/**
	 * Validate the requested email address with core's own rules.
	 *
	 * @param string $email Submitted address, trimmed.
	 * @return string The address to register, '' when it is unusable.
	 */
	private static function validate_email( $email ) {
		if ( '' === $email ) {
			self::add_error( self::FIELD_EMAIL, __( 'Enter an email address.', 'game-library' ) );

			return '';
		}

		$sanitized = sanitize_email( $email );

		if ( '' === $sanitized || ! is_email( $sanitized ) ) {
			self::add_error( self::FIELD_EMAIL, __( 'That email address does not look right.', 'game-library' ) );

			return '';
		}

		if ( email_exists( $sanitized ) ) {
			self::add_error(
				self::FIELD_EMAIL,
				__( 'An account already uses that email address.', 'game-library' )
			);

			return '';
		}

		return $sanitized;
	}

	/**
	 * Validate the chosen password.
	 *
	 * @param string $password Submitted password, unsanitized by design.
	 * @return void
	 */
	private static function validate_password( $password ) {
		if ( '' === $password ) {
			self::add_error( self::FIELD_PASSWORD, __( 'Choose a password.', 'game-library' ) );

			return;
		}

		/*
		 * Characters, not bytes (CO-3). `strlen()` measures a four-character
		 * emoji or CJK password as well over the minimum, so the enforced rule
		 * and the field hint — "At least 8 characters." — disagreed. Only ever
		 * in the permissive direction, but they should agree. Same
		 * `function_exists()` guard `GameLib_Library::criteria()` uses for
		 * `mb_substr()`.
		 */
		$length = function_exists( 'mb_strlen' )
			? mb_strlen( $password, 'UTF-8' )
			: strlen( $password );

		if ( $length < self::MIN_PASSWORD_LENGTH ) {
			self::add_error(
				self::FIELD_PASSWORD,
				sprintf(
					/* translators: %s: minimum number of characters. */
					_n(
						'Passwords need at least %s character.',
						'Passwords need at least %s characters.',
						self::MIN_PASSWORD_LENGTH,
						'game-library'
					),
					number_format_i18n( self::MIN_PASSWORD_LENGTH )
				)
			);
		}
	}

	/**
	 * Create the member the invite was redeemed for (AC-003a).
	 *
	 * Runs inside {@see GameLib_Invites::redeem()}, after the invite has been
	 * claimed and before the claim is either completed or reverted — which is
	 * why it does nothing but create the user and report the outcome.
	 *
	 * `wp_insert_user()` sends no email of any kind on its own (notifications
	 * are a separate, deliberately uncalled function), which is what D-REQ-9
	 * requires: this site never emails anybody.
	 *
	 * @param string $username Validated username.
	 * @param string $email    Validated email address.
	 * @param string $password Chosen password.
	 * @return int|WP_Error New user id, or the reason creation failed.
	 */
	private static function create_member( $username, $email, $password ) {
		/*
		 * Role and nothing else. No activity event is recorded (AC-003e), and
		 * no `gamelib_visibility` meta is written — absent already reads as
		 * members-only, which is the default every member starts at (AC-027a).
		 */
		return wp_insert_user(
			array(
				'user_login' => $username,
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => self::MEMBER_ROLE,
			)
		);
	}

	/**
	 * Sign the new member in and send them to their library (AC-003c,d).
	 *
	 * `wp_signon()` rather than a bare `wp_set_auth_cookie()`: it runs the
	 * `authenticate` filter chain and fires `wp_login`, so a site with login
	 * restrictions or session logging sees this exactly as it sees any other
	 * sign-in.
	 *
	 * @param int    $user_id  New member.
	 * @param string $username Validated username.
	 * @param string $password Chosen password.
	 * @return void
	 */
	private static function sign_in_and_redirect( $user_id, $username, $password ) {
		$library_url = GameLib_Router::route_url( GameLib_Router::ROUTE_MY_LIBRARY );

		$signed_in = wp_signon(
			array(
				'user_login'    => $username,
				'user_password' => $password,
				'remember'      => true,
			),
			is_ssl()
		);

		if ( is_wp_error( $signed_in ) ) {
			/*
			 * The account exists and the invite is spent — only the session
			 * failed. Hand them the login form rather than the library they
			 * cannot see yet.
			 */
			wp_safe_redirect( wp_login_url( $library_url ) );
			exit;
		}

		wp_set_current_user( $user_id );

		wp_safe_redirect( $library_url );
		exit;
	}

	/**
	 * Queue one message against a field.
	 *
	 * @param string $field   Field name, or {@see GameLib_Registration::ERROR_FORM}.
	 * @param string $message Message to show.
	 * @return void
	 */
	private static function add_error( $field, $message ) {
		if ( ! self::$errors instanceof WP_Error ) {
			self::$errors = new WP_Error();
		}

		self::$errors->add( (string) $field, (string) $message );
	}

	/**
	 * Is this request a form submission?
	 *
	 * @return bool True on POST.
	 */
	private static function is_post() {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: '';

		return 'POST' === $method;
	}
}
