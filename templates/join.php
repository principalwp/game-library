<?php
/**
 * Template: /join/{code} — the invite registration form.
 *
 * @package Game_Library
 *
 * @var array $context {
 *     @type string $code
 *     @type bool   $valid
 *     @type array  $errors
 *     @type array  $input
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$game_library_code   = (string) $context['code'];
$game_library_valid  = ! empty( $context['valid'] );
$game_library_errors = isset( $context['errors'] ) ? (array) $context['errors'] : array();
$game_library_input  = isset( $context['input'] ) ? (array) $context['input'] : array();
$game_library_name   = isset( $game_library_input['username'] ) ? (string) $game_library_input['username'] : '';
$game_library_email  = isset( $game_library_input['email'] ) ? (string) $game_library_input['email'] : '';
?>
<div class="gl-app gl-join">
	<div class="gl-join__card">
		<h2 class="gl-join__title"><?php esc_html_e( 'Join the Game Library', 'game-library' ); ?></h2>

		<?php if ( is_user_logged_in() ) : ?>
			<div class="gl-empty-state">
				<p><?php esc_html_e( 'You are already a member.', 'game-library' ); ?>
					<a href="<?php echo esc_url( home_url( '/my-library/' ) ); ?>"><?php esc_html_e( 'Go to your library →', 'game-library' ); ?></a>
				</p>
			</div>
		<?php elseif ( ! $game_library_valid ) : ?>
			<div class="gl-error-banner" role="alert">
				<p><?php esc_html_e( 'This invite link is invalid or has already been used. Please ask a member for a new invite.', 'game-library' ); ?></p>
			</div>
		<?php else : ?>

			<?php if ( ! empty( $game_library_errors ) ) : ?>
				<div class="gl-error-banner" role="alert">
					<ul>
						<?php foreach ( $game_library_errors as $game_library_error ) : ?>
							<li><?php echo esc_html( $game_library_error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<p class="gl-join__intro"><?php esc_html_e( 'You have been invited. Create your account to start building your library.', 'game-library' ); ?></p>

			<form class="gl-join__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="game_library_join" />
				<input type="hidden" name="game_library_invite_code" value="<?php echo esc_attr( $game_library_code ); ?>" />
				<?php wp_nonce_field( 'game_library_join' ); ?>

				<p class="gl-field">
					<label for="gl-join-username"><?php esc_html_e( 'Username', 'game-library' ); ?></label>
					<input type="text" id="gl-join-username" name="gl_username" required autocomplete="username"
						value="<?php echo esc_attr( $game_library_name ); ?>" />
				</p>
				<p class="gl-field">
					<label for="gl-join-email"><?php esc_html_e( 'Email', 'game-library' ); ?></label>
					<input type="email" id="gl-join-email" name="gl_email" required autocomplete="email"
						value="<?php echo esc_attr( $game_library_email ); ?>" />
				</p>
				<p class="gl-field">
					<label for="gl-join-password"><?php esc_html_e( 'Password', 'game-library' ); ?></label>
					<input type="password" id="gl-join-password" name="gl_password" required autocomplete="new-password" minlength="8" />
				</p>

				<button type="submit" class="gl-button gl-button--primary"><?php esc_html_e( 'Create account', 'game-library' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
</div>
