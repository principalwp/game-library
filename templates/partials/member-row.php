<?php
/**
 * A single member-directory row.
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this Template renderer.
 * @var array<string, mixed>    $args user, game_count, is_public, is_self, viewer, is_following.
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$gl_user = isset( $args['user'] ) ? $args['user'] : null;
if ( ! $gl_user ) {
	return;
}

$gl_id        = (int) $gl_user->ID;
$gl_public    = ! empty( $args['is_public'] );
$gl_self      = ! empty( $args['is_self'] );
$gl_viewer    = isset( $args['viewer'] ) ? (int) $args['viewer'] : 0;
$gl_count     = isset( $args['game_count'] ) ? (int) $args['game_count'] : 0;
$gl_following = ! empty( $args['is_following'] );
?>
<li class="gl-member-row">
	<span class="gl-member-row__avatar"><?php echo get_avatar( $gl_id, 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe avatar markup. ?></span>
	<div class="gl-member-row__identity">
		<span class="gl-member-row__name"><a href="<?php echo esc_url( $this->member_url( $gl_user->user_nicename ) ); ?>"><?php echo esc_html( $gl_user->display_name ); ?></a></span>
		<span class="gl-member-row__meta">
			<?php if ( $gl_public ) : ?>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of games. */
						_n( '%d game', '%d games', $gl_count, 'game-library-3' ),
						$gl_count
					)
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'Library is private', 'game-library-3' ); ?>
			<?php endif; ?>
		</span>
	</div>
	<?php if ( $gl_public ) : ?>
		<span class="gl-member-row__public-marker"><?php esc_html_e( 'Public', 'game-library-3' ); ?></span>
	<?php endif; ?>
	<div class="gl-member-row__actions">
		<?php if ( $gl_viewer > 0 && ! $gl_self ) : ?>
			<?php
			$this->partial(
				'follow-button',
				array(
					'target_id'    => $gl_id,
					'is_following' => $gl_following,
				)
			);
			?>
		<?php endif; ?>
	</div>
</li>
