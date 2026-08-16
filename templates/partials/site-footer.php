<?php
/**
 * Site chrome footer — matching close for `templates/partials/site-header.php`
 * (DES-1; header/footer redesign task); see that file's docblock for why this
 * plugin now opens/closes the document itself and no longer renders the active
 * theme's own header/footer chrome.
 *
 * `.gl-site-footer` is the page's real `<footer>` (its single `contentinfo`
 * landmark), the matching bookend to `site-header.php`'s `<header>`. This file
 * closes the document with `wp_footer()` (still fired so the active theme and
 * any plugin keep their footer-enqueued scripts) and `</body></html>`.
 *
 * @package Game_Library
 */

use Game_Library\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * The quick-look detail is no longer a shared modal dialog emitted here
 * (DES-50, restyle §C, reworked). Each card now carries its own inline,
 * `[hidden]` `.gl-game-card__detail` disclosure (see
 * `templates/partials/game-card.php`), which `assets/js/game-detail.js` expands
 * in place on cover click. This footer therefore emits no detail markup of its
 * own.
 */
?>
<footer class="gl-site-footer">
	<div class="gl-container gl-container--wide gl-site-footer__row">
		<div class="gl-site-footer__brand">
			<span class="gl-site-footer__mark" aria-hidden="true">G</span>
			<span class="gl-site-footer__wordmark"><?php esc_html_e( 'Game Library', 'game-library' ); ?></span>
		</div>
		<nav class="gl-site-footer__nav" aria-label="<?php esc_attr_e( 'Game Library footer', 'game-library' ); ?>">
			<a class="gl-site-footer__nav-link" href="<?php echo esc_url( Router::catalog_url() ); ?>"><?php esc_html_e( 'Game collection', 'game-library' ); ?></a>
			<a class="gl-site-footer__nav-link" href="<?php echo esc_url( Router::members_url() ); ?>"><?php esc_html_e( 'Members', 'game-library' ); ?></a>
			<a class="gl-site-footer__nav-link" href="<?php echo esc_url( Router::activity_url() ); ?>"><?php esc_html_e( 'Recent activity', 'game-library' ); ?></a>
			<a class="gl-site-footer__nav-link" href="<?php echo esc_url( Router::my_library_url() ); ?>"><?php esc_html_e( 'My library', 'game-library' ); ?></a>
		</nav>
		<p class="gl-site-footer__meta">
			<?php
			printf(
				/* translators: 1: current year, 2: site name. */
				esc_html__( '© %1$d %2$s', 'game-library' ),
				(int) gmdate( 'Y' ),
				esc_html( get_bloginfo( 'name' ) )
			);
			?>
		</p>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
