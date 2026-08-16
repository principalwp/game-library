<?php
/**
 * Route template: `/join/{code}/`.
 *
 * Public by design: the whole point of an invite link is that the person
 * holding it has no account yet. {@see GameLib_Registration::prepare()} has
 * already resolved which of the three states this request is — the form, the
 * generic invalid message, or "already a member" — claimed and redeemed the
 * invite where a submission succeeded, and marked the whole route `noindex`
 * (AC-005c, AC-048b). A successful registration never reaches this file: it
 * redirects to `/my-library/` from `template_redirect`.
 *
 * An unknown code, a redeemed one, and a revoked one all arrive here as
 * {@see GameLib_Registration::STATE_INVALID} and render one identical message
 * with no issuer named (AC-005a,b).
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_state      = GameLib_Registration::state();
$gamelib_form_error = GameLib_Registration::error( GameLib_Registration::ERROR_FORM );
$gamelib_user_error = GameLib_Registration::error( GameLib_Registration::FIELD_USERNAME );
$gamelib_mail_error = GameLib_Registration::error( GameLib_Registration::FIELD_EMAIL );
$gamelib_pass_error = GameLib_Registration::error( GameLib_Registration::FIELD_PASSWORD );

GameLib_Router::part( 'document-open', array( 'body_class' => 'gamelib-route-join' ) );

?>
<header class="gamelib__header">
	<h1 class="gamelib__title">
		<?php
		printf(
			/* translators: %s: site name. */
			esc_html__( 'Join %s', 'game-library' ),
			esc_html( get_bloginfo( 'name' ) )
		);
		?>
	</h1>
</header>

<section class="gamelib__section" aria-labelledby="gamelib-join-title">
	<h2 id="gamelib-join-title" class="gamelib__section-title"><?php esc_html_e( 'Redeem your invitation', 'game-library' ); ?></h2>
	<div id="gamelib-join" class="gamelib__panel">
		<?php if ( GameLib_Registration::STATE_MEMBER === $gamelib_state ) : ?>

			<p class="gamelib-notice" data-gamelib-state="invite-member">
				<?php esc_html_e( 'You are already a member, so there is nothing to redeem here. This invite is still waiting for whoever you shared it with.', 'game-library' ); ?>
			</p>
			<p>
				<a class="gamelib-control" href="<?php echo esc_url( GameLib_Router::route_url( GameLib_Router::ROUTE_MY_LIBRARY ) ); ?>">
					<?php esc_html_e( 'Go to your library', 'game-library' ); ?>
				</a>
			</p>

		<?php elseif ( GameLib_Registration::STATE_FORM === $gamelib_state ) : ?>

			<?php if ( '' !== $gamelib_form_error ) : ?>
				<p class="gamelib-notice gamelib-notice--error" role="alert"><?php echo esc_html( $gamelib_form_error ); ?></p>
			<?php endif; ?>

			<p><?php esc_html_e( 'Your invite is valid. Pick a username and password to finish creating your account.', 'game-library' ); ?></p>

			<form class="gamelib-form" method="post" action="<?php echo esc_url( GameLib_Registration::form_action() ); ?>">
				<?php wp_nonce_field( GameLib_Registration::nonce_action(), GameLib_Registration::NONCE_FIELD ); ?>

				<p class="gamelib-form__field">
					<label class="gamelib-form__label" for="gamelib-join-username"><?php esc_html_e( 'Username', 'game-library' ); ?></label>
					<input
						class="gamelib-control gamelib-form__input"
						id="gamelib-join-username"
						type="text"
						name="<?php echo esc_attr( GameLib_Registration::FIELD_USERNAME ); ?>"
						value="<?php echo esc_attr( GameLib_Registration::value( GameLib_Registration::FIELD_USERNAME ) ); ?>"
						autocomplete="username"
						required
						<?php if ( '' !== $gamelib_user_error ) : ?>
							aria-invalid="true" aria-describedby="gamelib-join-username-error"
						<?php endif; ?>
					/>
					<?php if ( '' !== $gamelib_user_error ) : ?>
						<span class="gamelib-form__error" id="gamelib-join-username-error"><?php echo esc_html( $gamelib_user_error ); ?></span>
					<?php endif; ?>
				</p>

				<p class="gamelib-form__field">
					<label class="gamelib-form__label" for="gamelib-join-email"><?php esc_html_e( 'Email address', 'game-library' ); ?></label>
					<input
						class="gamelib-control gamelib-form__input"
						id="gamelib-join-email"
						type="email"
						name="<?php echo esc_attr( GameLib_Registration::FIELD_EMAIL ); ?>"
						value="<?php echo esc_attr( GameLib_Registration::value( GameLib_Registration::FIELD_EMAIL ) ); ?>"
						autocomplete="email"
						required
						<?php if ( '' !== $gamelib_mail_error ) : ?>
							aria-invalid="true" aria-describedby="gamelib-join-email-error"
						<?php endif; ?>
					/>
					<?php if ( '' !== $gamelib_mail_error ) : ?>
						<span class="gamelib-form__error" id="gamelib-join-email-error"><?php echo esc_html( $gamelib_mail_error ); ?></span>
					<?php endif; ?>
				</p>

				<p class="gamelib-form__field">
					<label class="gamelib-form__label" for="gamelib-join-password"><?php esc_html_e( 'Password', 'game-library' ); ?></label>
					<input
						class="gamelib-control gamelib-form__input"
						id="gamelib-join-password"
						type="password"
						name="<?php echo esc_attr( GameLib_Registration::FIELD_PASSWORD ); ?>"
						autocomplete="new-password"
						required
						minlength="<?php echo esc_attr( (string) GameLib_Registration::MIN_PASSWORD_LENGTH ); ?>"
						aria-describedby="gamelib-join-password-hint<?php echo ( '' !== $gamelib_pass_error ) ? ' gamelib-join-password-error' : ''; ?>"
						<?php if ( '' !== $gamelib_pass_error ) : ?>
							aria-invalid="true"
						<?php endif; ?>
					/>
					<span class="gamelib-form__hint" id="gamelib-join-password-hint">
						<?php
						printf(
							/* translators: %s: minimum number of characters. */
							esc_html(
								_n(
									'At least %s character.',
									'At least %s characters.',
									GameLib_Registration::MIN_PASSWORD_LENGTH,
									'game-library'
								)
							),
							esc_html( number_format_i18n( GameLib_Registration::MIN_PASSWORD_LENGTH ) )
						);
						?>
					</span>
					<?php if ( '' !== $gamelib_pass_error ) : ?>
						<span class="gamelib-form__error" id="gamelib-join-password-error"><?php echo esc_html( $gamelib_pass_error ); ?></span>
					<?php endif; ?>
				</p>

				<p>
					<button class="gamelib-control" type="submit" data-gamelib-action="account.register">
						<?php esc_html_e( 'Create your account', 'game-library' ); ?>
					</button>
				</p>
			</form>

		<?php else : ?>

			<p class="gamelib-notice gamelib-notice--error" data-gamelib-state="invite-invalid">
				<?php echo esc_html( GameLib_Invites::invalid_message() ); ?>
			</p>
			<p><?php esc_html_e( 'Invites work once. If someone sent you this link, ask them for a new one.', 'game-library' ); ?></p>

		<?php endif; ?>
	</div>
</section>
<?php

GameLib_Router::part( 'document-close' );
