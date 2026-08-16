<?php
/**
 * Opening half of a plugin-owned full document.
 *
 * Every route template opens with this part and closes with `document-close`,
 * so the five routes share one `<head>`, one landmark structure, and one skip
 * link. No theme `header.php` is loaded — the repository ships no theme, and
 * the plugin has to render the same way under any of them (D-REQ-41) — but
 * `wp_head()`, `wp_body_open()`, and `body_class()` all fire, so themes and
 * plugins keep every hook they would get from a theme template.
 *
 * The viewport meta and the `<title>` are emitted conditionally, because
 * whether core emits them depends on the active theme and a duplicate would be
 * the bug. Under a block theme core adds both from `locate_block_template()` —
 * the title unconditionally, `title-tag` support or not — so the plugin emits
 * neither. Under a classic theme core emits no viewport at all (the theme's own
 * `header.php`, which this document deliberately does not load, normally would)
 * and emits a title only with `title-tag` support. Verified on the shared
 * Playground: guarding the title on `title-tag` support alone produced two
 * `<title>` elements under Twenty Twenty-Five.
 *
 * The title text itself always comes from `wp_get_document_title()`, so the
 * `document_title_parts` filter stays the single source (AC-047a).
 *
 * Expected `$args`:
 * - `body_class` (string) Extra body class naming the route, e.g. `gamelib-route-activity`.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_body_class  = isset( $args['body_class'] ) ? sanitize_html_class( (string) $args['body_class'] ) : '';
$gamelib_block_theme = wp_is_block_theme();
$gamelib_needs_title = ! $gamelib_block_theme && ! current_theme_supports( 'title-tag' );

/*
 * `gamelib-document` beside the route class, and it is the one the stylesheet
 * selects on (DES-5). The page measure has to apply to the plugin's own
 * documents and *not* to the game page, where the theme owns the measure
 * (DES-11); keying that on the route class needed a substring match
 * (`[class*="gamelib-route-"]`), which would also fire for any theme or plugin
 * class that merely contained the string — `no-gamelib-route-notice` among them.
 * A class of its own is the same 0-2-0 specificity with none of that.
 */
$gamelib_body_classes = array( 'gamelib-document' );

if ( '' !== $gamelib_body_class ) {
	$gamelib_body_classes[] = $gamelib_body_class;
}

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<?php if ( ! $gamelib_block_theme ) : ?>
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php endif; ?>
	<?php if ( $gamelib_needs_title ) : ?>
	<title><?php echo esc_html( wp_get_document_title() ); ?></title>
	<?php endif; ?>
	<?php wp_head(); ?>
</head>
<body <?php body_class( $gamelib_body_classes ); ?>>
<?php wp_body_open(); ?>
<a class="gamelib-skip-link" href="#gamelib-main"><?php esc_html_e( 'Skip to content', 'game-library' ); ?></a>
<div class="gamelib">
	<main id="gamelib-main" class="gamelib__main" tabindex="-1">
