<?php
/**
 * A single activity feed row.
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this Template renderer.
 * @var array<string, mixed>    $args row.
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$gl_row = isset( $args['row'] ) ? $args['row'] : null;
if ( ! $gl_row ) {
	return;
}

$gl_actor    = $this->member_link( (int) $gl_row->actor_id );
$gl_game_em  = '<em>' . esc_html( (string) $gl_row->game_name ) . '</em>';
$gl_to_label = esc_html( $this->status_label( (string) $gl_row->to_status ) );

if ( 'status-changed' === $gl_row->verb ) {
	/* translators: 1: member link, 2: game title, 3: status. */
	$gl_pattern = __( '%1$s changed %2$s to %3$s', 'game-library-3' );
} else {
	/* translators: 1: member link, 2: game title, 3: status. */
	$gl_pattern = __( '%1$s added %2$s to %3$s', 'game-library-3' );
}
$gl_sentence = sprintf( $gl_pattern, $gl_actor, $gl_game_em, $gl_to_label );

$gl_game_url = ! empty( $gl_row->game_slug ) ? home_url( '/games/' . rawurlencode( (string) $gl_row->game_slug ) . '/' ) : '';
$gl_thumb    = $this->cover_img_tag( isset( $gl_row->cover_image_id ) ? $gl_row->cover_image_id : '', (string) $gl_row->game_name, false );
?>
<li class="gl-activity-row">
	<span class="gl-activity-row__avatar"><?php echo get_avatar( (int) $gl_row->actor_id, 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe avatar markup. ?></span>
	<div class="gl-activity-row__content">
		<p class="gl-activity-row__sentence"><?php echo $gl_sentence; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component parts escaped above. ?></p>
		<div class="gl-activity-row__game">
			<?php if ( '' !== $gl_thumb && '' !== $gl_game_url ) : ?>
				<a class="gl-activity-row__game-cover" href="<?php echo esc_url( $gl_game_url ); ?>"><?php echo $gl_thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built + escaped in cover_img_tag(). ?></a>
			<?php endif; ?>
			<?php echo $this->render_status_badge( (string) $gl_row->to_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built + escaped. ?>
		</div>
	</div>
	<span class="gl-activity-row__time"><?php echo esc_html( $this->relative_time( (string) $gl_row->created_at ) ); ?></span>
</li>
