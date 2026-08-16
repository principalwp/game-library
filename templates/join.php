<?php
/**
 * /join/{code}/ — the gated registration card (and its invalid state).
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this    Template renderer.
 * @var array<string, mixed>    $context Route context (invite, code, join_result).
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$gl_invite = isset( $context['invite'] ) ? $context['invite'] : null;
$gl_code   = isset( $context['code'] ) ? (string) $context['code'] : '';
$gl_result = isset( $context['join_result'] ) ? $context['join_result'] : null;
$gl_errors = ( is_array( $gl_result ) && isset( $gl_result['errors'] ) ) ? $gl_result['errors'] : array();
$gl_values = ( is_array( $gl_result ) && isset( $gl_result['values'] ) ) ? $gl_result['values'] : array(
	'username' => '',
	'email'    => '',
);

$gl_invites = Plugin::instance()->invites();
$gl_valid   = ( $gl_invite && 'pending' === $gl_invites->effective_status( $gl_invite ) );
$gl_inviter = $gl_invite ? get_userdata( (int) $gl_invite->inviter_id ) : false;

$gl_field_class = static function ( $field ) use ( $gl_errors ) {
	return isset( $gl_errors[ $field ] ) ? ' data-invalid="true"' : '';
};
?>
<div class="gl-join-ground">
	<div class="gl-join-card">
		<span class="gl-join-card__eyebrow"><?php esc_html_e( 'You are invited', 'game-library-3' ); ?></span>
		<h1><?php esc_html_e( 'Join the Game Library', 'game-library-3' ); ?></h1>

		<?php if ( $gl_valid ) : ?>
			<p class="gl-join__inviter">
				<?php
				printf(
					/* translators: %s: inviter display name (italic). */
					esc_html__( 'Invited by %s', 'game-library-3' ),
					'<em>' . esc_html( $gl_inviter ? $gl_inviter->display_name : __( 'a member', 'game-library-3' ) ) . '</em>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- name escaped inline.
				);
				?>
			</p>

			<?php if ( ! empty( $gl_errors['general'] ) ) : ?>
				<div class="gl-notice gl-notice--error"><?php echo esc_html( $gl_errors['general'] ); ?></div>
			<?php endif; ?>

			<form class="gl-join-form" method="post" action="<?php echo esc_url( $this->join_url( $gl_code ) ); ?>">
				<?php wp_nonce_field( 'gl_join', 'gl_join_nonce' ); ?>

				<div class="gl-field"<?php echo $gl_field_class( 'username' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute. ?>>
					<label for="gl-join-username"><?php esc_html_e( 'Username', 'game-library-3' ); ?></label>
					<input id="gl-join-username" name="gl_username" type="text" value="<?php echo esc_attr( (string) $gl_values['username'] ); ?>" autocomplete="username" required />
					<span class="gl-error-text"><?php echo isset( $gl_errors['username'] ) ? esc_html( $gl_errors['username'] ) : ''; ?></span>
				</div>

				<div class="gl-field"<?php echo $gl_field_class( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute. ?>>
					<label for="gl-join-email"><?php esc_html_e( 'Email', 'game-library-3' ); ?></label>
					<input id="gl-join-email" name="gl_email" type="email" value="<?php echo esc_attr( (string) $gl_values['email'] ); ?>" autocomplete="email" required />
					<span class="gl-error-text"><?php echo isset( $gl_errors['email'] ) ? esc_html( $gl_errors['email'] ) : ''; ?></span>
				</div>

				<div class="gl-field"<?php echo $gl_field_class( 'password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute. ?>>
					<label for="gl-join-password"><?php esc_html_e( 'Password', 'game-library-3' ); ?></label>
					<input id="gl-join-password" name="gl_password" type="password" autocomplete="new-password" minlength="8" required />
					<span class="gl-field-hint"><?php esc_html_e( 'At least 8 characters.', 'game-library-3' ); ?></span>
					<span class="gl-error-text"><?php echo isset( $gl_errors['password'] ) ? esc_html( $gl_errors['password'] ) : ''; ?></span>
				</div>

				<input type="hidden" name="gl_join_submit" value="1" />
				<button type="submit" class="gl-button gl-button--primary"><?php esc_html_e( 'Create account & join', 'game-library-3' ); ?></button>
			</form>
		<?php else : ?>
			<div class="gl-notice gl-notice--error"><?php esc_html_e( 'This invite link is not valid. It may have expired, been revoked, or already been used.', 'game-library-3' ); ?></div>
			<a class="gl-button gl-button--secondary" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to the site', 'game-library-3' ); ?></a>
		<?php endif; ?>
	</div>
</div>
