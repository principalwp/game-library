<?php
/**
 * Follow / Following toggle button.
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this Template renderer.
 * @var array<string, mixed>    $args target_id, is_following.
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$gl_target    = isset( $args['target_id'] ) ? (int) $args['target_id'] : 0;
$gl_following = ! empty( $args['is_following'] );
if ( $gl_target <= 0 ) {
	return;
}
?>
<button
	type="button"
	class="gl-button gl-button--secondary<?php echo $gl_following ? ' is-active' : ''; ?>"
	data-gl-follow
	data-gl-user-id="<?php echo esc_attr( (string) $gl_target ); ?>"
	aria-pressed="<?php echo $gl_following ? 'true' : 'false'; ?>"
>
	<?php echo $gl_following ? esc_html__( 'Following', 'game-library-3' ) : esc_html__( 'Follow', 'game-library-3' ); ?>
</button>
