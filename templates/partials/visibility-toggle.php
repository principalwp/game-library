<?php
/**
 * Library visibility toggle (checkbox styled as a switch).
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this Template renderer.
 * @var array<string, mixed>    $args is_public.
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$gl_is_public = ! empty( $args['is_public'] );
?>
<div class="gl-visibility-toggle">
	<span class="gl-toggle">
		<input
			type="checkbox"
			id="gl-profile-public-toggle"
			data-gl-visibility-toggle
			<?php checked( $gl_is_public ); ?>
		/>
		<span class="gl-toggle__track" aria-hidden="true"></span>
		<span class="gl-toggle__thumb" aria-hidden="true"></span>
	</span>
	<label class="gl-visibility-toggle__label" for="gl-profile-public-toggle">
		<strong><?php esc_html_e( 'Library visibility', 'game-library-3' ); ?></strong>
		<span data-gl-visibility-label>
			<?php echo $gl_is_public ? esc_html__( 'Public library', 'game-library-3' ) : esc_html__( 'Private library', 'game-library-3' ); ?>
		</span>
	</label>
</div>
