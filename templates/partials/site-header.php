<?php
/**
 * Site chrome header (DES-1).
 *
 * Every front-end template in this plugin renders a full HTML document by
 * bracketing its own markup with this partial and `site-footer.php`, relying
 * on the active theme to supply the surrounding chrome (DD-008's "no
 * theme.json, must run under any theme" requirement). The classic-theme
 * contract for that is `get_header()`, which loads the theme's own
 * `header.php` — but WordPress has shipped a block theme by default since
 * 5.9, and a block theme ships no `header.php` at all. `get_header()` then
 * falls through to WordPress core's own `wp-includes/theme-compat/header.php`,
 * which fires a `_deprecated_file()` notice ahead of a bare, unstyled
 * pre-block-themes shell instead of the active theme's real header.
 *
 * `wp_is_block_theme()` branches to `block_header_area()` (WP 6.3+) instead,
 * which renders the theme's own "header" template-part block markup. Unlike
 * `get_header()`'s classic contract, `block_header_area()` does not open
 * `<html>`/`<head>`, call `wp_head()`/`wp_body_open()`, or open `<body>` — a
 * classic theme's `header.php` does all of that itself by convention, so
 * this file takes over that responsibility only on the block-theme branch.
 * `site-footer.php` is this file's matching close.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( wp_is_block_theme() ) :
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
	<?php wp_body_open(); ?>
	<?php block_header_area(); ?>
	<?php
else :
	get_header();
endif;
