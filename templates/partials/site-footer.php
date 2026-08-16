<?php
/**
 * Site chrome footer — matching close for `templates/partials/site-header.php`
 * (DES-1); see that file's docblock for the full reasoning behind the
 * `wp_is_block_theme()` branch.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( wp_is_block_theme() ) :
	block_footer_area();
	wp_footer();
	?>
</body>
</html>
	<?php
else :
	get_footer();
endif;
