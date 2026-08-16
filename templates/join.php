<?php
/**
 * `/join/` and `/join/{code}/` — invite redemption and registration.
 *
 * Rendered by `Router` (Task 10) via `template_include`, or by a theme's own
 * override at `game-library/join.php` (`locate_template()`, DD-008).
 *
 * The POST submission itself is handled on `template_redirect`, before this
 * template is ever selected — `Registration::maybe_handle_submission()`
 * (CO-12, cycle-2) verifies the nonce and either redirects (a successful
 * redemption) or stashes a result array of field-level errors / a general
 * message this file reads via `last_result()` and renders below the form.
 * See that method's own docblock for why this moved off the template: DD-008
 * documents every template (including this one) as theme-overridable, and a
 * side effect living only in the template's own PHP would silently vanish
 * for a theme that reproduces the markup but not that exact code block.
 *
 * @package Game_Library
 */

use Game_Library\Invites\Registration;
use Game_Library\Plugin;
use Game_Library\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// A logged-in visitor has nothing to register for.
if ( is_user_logged_in() ) {
	wp_safe_redirect( Router::my_library_url() );
	exit;
}

$registration   = Plugin::instance()->registration();
$stashed_result = $registration->last_result();

if ( null !== $stashed_result ) {
	$result = $stashed_result;
} else {
	$result = array(
		'errors'          => array(),
		'general_message' => null,
		'show_login_link' => false,
		// `gl_param` carries the `/join/{code}/` URL segment once `Router`
		// (Task 10) registers it — see DD-008 and includes/class-assets.php's
		// docblock for the shared query-var contract.
		'code'            => sanitize_key( (string) get_query_var( 'gl_param' ) ),
		'username'        => '',
		'email'           => '',
	);
}

$field_errors      = $result['errors'];
$code_error        = isset( $field_errors['code'] ) ? $field_errors['code'] : '';
$username_error    = isset( $field_errors['username'] ) ? $field_errors['username'] : '';
$email_error       = isset( $field_errors['email'] ) ? $field_errors['email'] : '';
$password_error    = isset( $field_errors['password'] ) ? $field_errors['password'] : '';
$displayed_invite  = $registration->find_redeemable_invite( $result['code'] );

include __DIR__ . '/partials/site-header.php';
?>

<main id="gl-main" class="gl-container gl-join">

	<h1><?php esc_html_e( 'Join Game Library', 'game-library' ); ?></h1>

	<?php if ( $displayed_invite ) : ?>
		<p class="gl-join__inviter">
			<?php
			printf(
				/* translators: %s: inviting member's display name. */
				esc_html__( 'You were invited by %s.', 'game-library' ),
				esc_html( $displayed_invite['inviter_name'] )
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( $result['general_message'] ) : ?>
		<div class="gl-notice gl-notice--error" role="alert">
			<p>
				<?php echo esc_html( $result['general_message'] ); ?>
				<?php if ( ! empty( $result['show_login_link'] ) ) : ?>
					<a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Log in', 'game-library' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<form class="gl-join-form" method="post" action="<?php echo esc_url( Router::join_url() ); ?>" novalidate>

		<div class="gl-field" data-invalid="<?php echo $code_error ? 'true' : 'false'; ?>">
			<label for="gl-join-code"><?php esc_html_e( 'Invite code', 'game-library' ); ?></label>
			<input
				type="text"
				id="gl-join-code"
				name="gl_code"
				value="<?php echo esc_attr( $result['code'] ); ?>"
				autocomplete="off"
				required
				<?php if ( $code_error ) : ?>
					aria-invalid="true"
					aria-describedby="gl-join-code-error"
				<?php endif; ?>
			/>
			<?php if ( $code_error ) : ?>
				<p class="gl-error-text" id="gl-join-code-error"><?php echo esc_html( $code_error ); ?></p>
			<?php endif; ?>
		</div>

		<div class="gl-field" data-invalid="<?php echo $username_error ? 'true' : 'false'; ?>">
			<label for="gl-join-username"><?php esc_html_e( 'Username', 'game-library' ); ?></label>
			<input
				type="text"
				id="gl-join-username"
				name="gl_username"
				value="<?php echo esc_attr( $result['username'] ); ?>"
				autocomplete="username"
				required
				<?php if ( $username_error ) : ?>
					aria-invalid="true"
					aria-describedby="gl-join-username-error"
				<?php endif; ?>
			/>
			<?php if ( $username_error ) : ?>
				<p class="gl-error-text" id="gl-join-username-error"><?php echo esc_html( $username_error ); ?></p>
			<?php endif; ?>
		</div>

		<div class="gl-field" data-invalid="<?php echo $email_error ? 'true' : 'false'; ?>">
			<label for="gl-join-email"><?php esc_html_e( 'Email address', 'game-library' ); ?></label>
			<input
				type="email"
				id="gl-join-email"
				name="gl_email"
				value="<?php echo esc_attr( $result['email'] ); ?>"
				autocomplete="email"
				required
				<?php if ( $email_error ) : ?>
					aria-invalid="true"
					aria-describedby="gl-join-email-error"
				<?php endif; ?>
			/>
			<?php if ( $email_error ) : ?>
				<p class="gl-error-text" id="gl-join-email-error"><?php echo esc_html( $email_error ); ?></p>
			<?php endif; ?>
		</div>

		<div class="gl-field" data-invalid="<?php echo $password_error ? 'true' : 'false'; ?>">
			<label for="gl-join-password"><?php esc_html_e( 'Password', 'game-library' ); ?></label>
			<input
				type="password"
				id="gl-join-password"
				name="gl_password"
				autocomplete="new-password"
				required
				<?php if ( $password_error ) : ?>
					aria-invalid="true"
					aria-describedby="gl-join-password-error"
				<?php endif; ?>
			/>
			<?php if ( $password_error ) : ?>
				<p class="gl-error-text" id="gl-join-password-error"><?php echo esc_html( $password_error ); ?></p>
			<?php endif; ?>
		</div>

		<div class="gl-notice gl-notice--info">
			<p><?php esc_html_e( 'Your library contents and status changes are visible to every other member of the site.', 'game-library' ); ?></p>
		</div>

		<?php wp_nonce_field( Registration::NONCE_ACTION, Registration::NONCE_FIELD ); ?>

		<button type="submit" class="gl-button gl-button--primary">
			<?php esc_html_e( 'Create account', 'game-library' ); ?>
		</button>

	</form>

</main>

<?php
include __DIR__ . '/partials/site-footer.php';
