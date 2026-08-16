<?php
/**
 * The plugin-owned document shell wrapping every route template.
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this    Template renderer.
 * @var string                  $route   Route slug.
 * @var array<string, mixed>    $context Route context.
 * @var string                  $file    Absolute path to the route template.
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'gl-body gl-route-' . esc_attr( $route ) ); ?>>
<?php wp_body_open(); ?>
<main id="gl-main" class="gl-main">
	<?php require $file; ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
