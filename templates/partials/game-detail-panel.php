<?php
/**
 * Quick-look game detail panel, rendered as a native <dialog>.
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this Template renderer.
 * @var array<string, mixed>    $args (unused).
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;
?>
<dialog class="gl-game-detail-panel" id="gl-quicklook" aria-label="<?php esc_attr_e( 'Game quick look', 'game-library-3' ); ?>">
	<div class="gl-game-detail-panel__cover">
		<img data-gl-quicklook-cover src="" alt="" />
	</div>
	<div class="gl-game-detail-panel__body">
		<h3 data-gl-quicklook-title></h3>
		<button type="button" class="gl-button gl-button--secondary gl-button--small" data-gl-quicklook-close><?php esc_html_e( 'Close', 'game-library-3' ); ?></button>
	</div>
</dialog>
