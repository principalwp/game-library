<?php
/**
 * Holders list for a game detail page.
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this Template renderer.
 * @var array<string, mixed>    $args holders (array of {user_id, status}).
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$gl_holders = isset( $args['holders'] ) ? (array) $args['holders'] : array();
?>
<h2 class="gl-game-single__label"><?php esc_html_e( 'In these libraries', 'game-library-3' ); ?></h2>
<?php if ( empty( $gl_holders ) ) : ?>
	<p class="gl-game-single__release-date"><?php esc_html_e( 'No members hold this game yet.', 'game-library-3' ); ?></p>
<?php else : ?>
	<ul class="gl-game-single__holders">
		<?php foreach ( $gl_holders as $gl_holder ) : ?>
			<?php $gl_user = get_userdata( (int) $gl_holder->user_id ); ?>
			<?php if ( $gl_user ) : ?>
				<li class="gl-game-single__holder">
					<span class="gl-game-single__holder-avatar"><?php echo get_avatar( (int) $gl_holder->user_id, 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe avatar markup. ?></span>
					<a href="<?php echo esc_url( $this->member_url( $gl_user->user_nicename ) ); ?>"><?php echo esc_html( $gl_user->display_name ); ?></a>
					<?php echo $this->render_status_badge( (string) $gl_holder->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built + escaped. ?>
				</li>
			<?php endif; ?>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
