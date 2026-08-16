<?php
/**
 * `/join/` form handling and account creation.
 *
 * @package Game_Library
 */

namespace Game_Library\Invites;

use Game_Library\Data\Invite_Repository;
use Game_Library\Router;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Registration.
 *
 * Handles the `/join/` and `/join/{code}/` route's registration form: every
 * AC-027 (a)-(g) branch, the AC-028 field-level error contract, and AC-030's
 * provenance stamping.
 *
 * `handle_submission()` calls `Invite_Repository::claim_by_code()` — the
 * atomic redemption claim (AC-027 g) — only after every field passes
 * validation and no account already exists for the submitted email/username,
 * so a submission that fails on, say, an empty password never consumes a
 * valid code. The claim's `bool` result is the sole authority on whether this
 * request actually redeemed the invite: a losing concurrent submission
 * re-reads the invite afterward and reports its now-current state (almost
 * always AC-027 (d), "already been used") rather than trusting the
 * pre-claim read.
 *
 * On a successful claim + account creation, this method sets the role to
 * `subscriber` explicitly, logs the member in, and redirects to
 * `/my-library/` — it never returns on that branch. Every other branch
 * returns a result array the `templates/join.php` template renders.
 *
 * CO-12: `handle_submission()` itself is called from `maybe_handle_submission()`
 * on `template_redirect`, not directly from `templates/join.php` — every
 * other plugin side effect lives on a hook, and `join.php` is the template
 * most likely to be restyled via DD-008's documented theme-override escape
 * hatch (`locate_template( 'game-library/join.php' )`). A theme override
 * that reproduces the markup but not a direct `handle_submission()` call
 * would otherwise silently lose invite redemption/account creation
 * entirely — the form would post, nothing would handle it, and the page
 * would re-render with no error. `last_result()` gives the template (the
 * plugin's own copy or a theme override) the outcome to render without
 * needing to invoke this class itself.
 */
final class Registration {

	/**
	 * Nonce action name for the join form.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'gl_join_submit';

	/**
	 * Nonce field name for the join form.
	 *
	 * @var string
	 */
	public const NONCE_FIELD = '_gl_join_nonce';

	/**
	 * Invite data access.
	 *
	 * @var Invite_Repository
	 */
	private $repository;

	/**
	 * This request's `handle_submission()` result, stashed by
	 * `maybe_handle_submission()` for `last_result()` to return (CO-12). Null
	 * when this request was not a `/join/` POST submission.
	 *
	 * @var array{errors:array<string,string>,general_message:?string,show_login_link:bool,code:string,username:string,email:string}|null
	 */
	private $last_result;

	/**
	 * Constructor.
	 *
	 * @param Invite_Repository|null $repository Invite data access. Defaults
	 *                                            to a new instance.
	 */
	public function __construct( ?Invite_Repository $repository = null ) {
		$this->repository = $repository ?: new Invite_Repository();
	}

	/**
	 * Registers this class's hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'template_redirect', array( $this, 'maybe_handle_submission' ), 5 );
	}

	/**
	 * Runs `handle_submission()` when the current request is a `/join/` or
	 * `/join/{code}/` POST carrying the join form's own nonce field, stashing
	 * the result for `last_result()` — a no-op on every other request (CO-12).
	 * Hooked at `template_redirect` priority 5, ahead of `Router`'s own
	 * priority-10 access-gate hook, since `/join/` needs no access gate of
	 * its own but a successful submission's redirect should happen as early
	 * as any other terminal `template_redirect` branch.
	 *
	 * @return void
	 */
	public function maybe_handle_submission() {
		if ( Router::ROUTE_JOIN !== get_query_var( 'gl_route' ) ) {
			return;
		}

		$is_post_submission = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST[ self::NONCE_FIELD ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check only; the nonce value itself is verified inside handle_submission() before any other use.

		if ( ! $is_post_submission ) {
			return;
		}

		$this->last_result = $this->handle_submission( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside handle_submission() before any other use.
	}

	/**
	 * This request's `handle_submission()` result, or null when this was not
	 * a `/join/` POST submission — `templates/join.php` (or a theme override)
	 * renders this instead of calling `handle_submission()` directly (CO-12).
	 *
	 * @return array{errors:array<string,string>,general_message:?string,show_login_link:bool,code:string,username:string,email:string}|null
	 */
	public function last_result() {
		return $this->last_result;
	}

	/**
	 * A read-only invite lookup used only to display the inviting member's
	 * name for a currently valid, redeemable code (the Design table's
	 * "inviter name" field on `/join/{code}/`). Never used to decide a
	 * validation outcome — `handle_submission()` always re-derives the code's
	 * validity itself.
	 *
	 * @param string $code Raw code (any case; sanitized internally).
	 * @return array{invite:array<string,mixed>,inviter_name:string}|null Null
	 *              when the code is empty, unknown, or not currently a valid
	 *              `pending` invite.
	 */
	public function find_redeemable_invite( $code ) {
		$code = sanitize_key( (string) $code );

		if ( '' === $code ) {
			return null;
		}

		$invite = $this->repository->get_by_code( $code );

		if ( null === $invite || null !== $this->code_error( $invite ) ) {
			return null;
		}

		$inviter = get_userdata( $invite['inviter_id'] );

		return array(
			'invite'       => $invite,
			'inviter_name' => $inviter ? $inviter->display_name : '',
		);
	}

	/**
	 * Processes one `/join/` form submission end to end.
	 *
	 * Verifies the nonce and the logged-out precondition before any other
	 * validation, then runs, in order: the AC-027 (a)-(d) code-validity
	 * check, the AC-028 field-format checks, the AC-027 (e) existing-account
	 * check, and finally the AC-027 (g) atomic claim. On success it creates
	 * exactly one Subscriber account, stamps provenance (AC-030), logs the
	 * member in, and redirects to `/my-library/` — this method does not
	 * return on that branch.
	 *
	 * @param array<string,mixed> $post Raw form fields (already
	 *                                  `wp_unslash()`-ed by the caller).
	 * @return array{errors:array<string,string>,general_message:?string,show_login_link:bool,code:string,username:string,email:string}
	 */
	public function handle_submission( array $post ) {
		$nonce = isset( $post[ self::NONCE_FIELD ] ) && is_string( $post[ self::NONCE_FIELD ] ) ? $post[ self::NONCE_FIELD ] : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the page and try again.', 'game-library' ), '', array( 'response' => 403 ) );
		}

		if ( is_user_logged_in() ) {
			wp_safe_redirect( Router::my_library_url() );
			exit;
		}

		$code     = isset( $post['gl_code'] ) && is_string( $post['gl_code'] ) ? sanitize_key( $post['gl_code'] ) : '';
		$username = isset( $post['gl_username'] ) && is_string( $post['gl_username'] ) ? sanitize_user( $post['gl_username'], true ) : '';
		$email    = isset( $post['gl_email'] ) && is_string( $post['gl_email'] ) ? sanitize_email( $post['gl_email'] ) : '';
		$password = isset( $post['gl_password'] ) && is_string( $post['gl_password'] ) ? $post['gl_password'] : '';

		$errors     = array();
		$invite     = '' !== $code ? $this->repository->get_by_code( $code ) : null;
		$code_error = $this->code_error( $invite );

		if ( null !== $code_error ) {
			$errors['code'] = $code_error;
		}

		if ( '' === $username ) {
			$errors['username'] = __( 'Enter a username.', 'game-library' );
		} elseif ( ! validate_username( $username ) ) {
			$errors['username'] = __( 'Enter a valid username.', 'game-library' );
		} elseif ( mb_strlen( $username ) > 60 || mb_strlen( sanitize_title( $username ) ) > 50 ) {
			// CO-3: wp_insert_user() itself rejects a user_login over 60
			// chars (user_login_too_long) and a derived user_nicename over
			// 50 (user_nicename_too_long) — neither sanitize_user() nor
			// validate_username() above catches either. Without this check,
			// a too-long username reaches claim_by_code() below and
			// consumes the invite before wp_insert_user() ever runs,
			// leaving the code redeemed with no account created and no way
			// back except a fresh invite (AC-027(f) requires exactly one
			// account on the success branch).
			$errors['username'] = __( 'That username is too long. Use 60 characters or fewer.', 'game-library' );
		}

		if ( '' === $email || ! is_email( $email ) ) {
			$errors['email'] = __( 'Enter a valid email address.', 'game-library' );
		}

		// MR-3/AC-028 (cycle-5, human ruling 7): AC-028 names only "an empty
		// password" as a rejection case, and this bare check is exactly
		// that — there is no minimum length and no strength rule beyond it,
		// here or anywhere else in this class, and wp_insert_user() (below)
		// adds none either. Accepted as-is, a documented product risk, per
		// the ruling; no MIN_PASSWORD_LENGTH constant exists in this
		// plugin (confirmed by grep, reviewer-security and reviewer-code
		// both independently this cycle) and none should be added here to
		// make this comment's premise true after the fact.
		if ( '' === $password ) {
			$errors['password'] = __( 'Enter a password.', 'game-library' );
		}

		if ( ! empty( $errors ) ) {
			return $this->result( $errors, null, false, $code, $username, $email );
		}

		// Every field is well-formed and the code is a valid, redeemable
		// `pending` invite (otherwise $errors['code'] would already have
		// short-circuited above) — AC-027(e): does an account already exist
		// for the submitted email or username?
		if ( email_exists( $email ) || username_exists( $username ) ) {
			return $this->result(
				array(),
				__( 'You already have an account — log in instead.', 'game-library' ),
				true,
				$code,
				$username,
				$email
			);
		}

		$claimed = $this->repository->claim_by_code( $code );

		if ( ! $claimed ) {
			// AC-027(g)'s loser, or the invite's state changed between the
			// read above and this claim attempt — report the invite's
			// current status rather than the now-stale "valid" read.
			$latest = $this->repository->get_by_code( $code );

			return $this->result(
				array( 'code' => $this->code_error( $latest ) ?: __( 'That invite has already been used.', 'game-library' ) ),
				null,
				false,
				$code,
				$username,
				$email
			);
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $username,
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			// The invite is already claimed (redeemed) at this point, with
			// no rollback (CO-3): the length check above closes the one
			// previously-reachable cause of this branch (an over-long
			// username), but this is intentionally not narrowed to "cannot
			// happen" — a genuine email_exists()/username_exists() race
			// between the check above and this insert, or any other
			// wp_insert_user() rejection this class does not anticipate,
			// still lands here and still consumes the invite with no
			// account created. See the coder decision log for Task 8/CO-3.
			return $this->result(
				array( 'username' => $this->wp_error_message( $user_id ) ),
				null,
				false,
				$code,
				$username,
				$email
			);
		}

		$user_id = (int) $user_id;

		update_user_meta( $user_id, '_gl_invited_by', absint( $invite['inviter_id'] ) );
		update_user_meta( $user_id, '_gl_invite_channel', in_array( $invite['channel'], array( 'email', 'link' ), true ) ? $invite['channel'] : 'link' );

		$this->repository->set_redeemed_user( $invite['id'], $user_id );

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );

		wp_safe_redirect( Router::my_library_url() );
		exit;
	}

	/**
	 * The AC-027 (a)-(d) code-validity message for a given invite, or null
	 * when it is currently a valid, redeemable `pending` invite. Treats a
	 * `pending` invite whose `date_expires` has already passed as expired
	 * (b), even though its stored `status` has not yet been flipped by the
	 * daily maintenance cron (`Invite_Maintenance`).
	 *
	 * @param array<string,mixed>|null $invite Result of `get_by_code()`, or
	 *                                         null for an unknown/absent code.
	 * @return string|null
	 */
	private function code_error( $invite ) {
		if ( null === $invite ) {
			return __( 'That invite code is not valid.', 'game-library' );
		}

		if ( 'revoked' === $invite['status'] ) {
			return __( 'That invite has been revoked.', 'game-library' );
		}

		if ( 'redeemed' === $invite['status'] ) {
			return __( 'That invite has already been used.', 'game-library' );
		}

		$expires_ts = strtotime( $invite['date_expires'] . ' UTC' );

		if ( 'expired' === $invite['status'] || ( false !== $expires_ts && $expires_ts <= time() ) ) {
			return __( 'That invite has expired.', 'game-library' );
		}

		return null;
	}

	/**
	 * Builds the result array `handle_submission()` returns on every
	 * non-redirecting branch.
	 *
	 * @param array<string,string> $errors          Field name => message.
	 * @param string|null          $general_message AC-027(e)'s notice text, or null.
	 * @param bool                 $show_login_link Whether to render a link to `wp-login.php`.
	 * @param string               $code            Submitted code, for re-display.
	 * @param string               $username        Submitted username, for re-display.
	 * @param string               $email           Submitted email, for re-display.
	 * @return array{errors:array<string,string>,general_message:?string,show_login_link:bool,code:string,username:string,email:string}
	 */
	private function result( array $errors, $general_message, $show_login_link, $code, $username, $email ) {
		return array(
			'errors'          => $errors,
			'general_message' => $general_message,
			'show_login_link' => $show_login_link,
			'code'            => $code,
			'username'        => $username,
			'email'           => $email,
		);
	}

	/**
	 * The first error message on a `WP_Error`, or a generic fallback when it
	 * carries none.
	 *
	 * @param WP_Error $error Error returned from `wp_insert_user()`.
	 * @return string
	 */
	private function wp_error_message( WP_Error $error ) {
		$message = $error->get_error_message();

		return '' !== $message ? $message : __( 'Your account could not be created. Try again.', 'game-library' );
	}
}
