<?php
/**
 * Site chrome header (DES-1; header/footer redesign task).
 *
 * Every front-end template in this plugin renders a full HTML document by
 * bracketing its own markup with this partial and `site-footer.php`. This
 * partial opens the document itself — `<html>`/`<head>`, `wp_head()`,
 * `<body>`, `wp_body_open()` — and then renders the plugin's own navy site
 * header as the page's single `banner` landmark, rather than deferring to the
 * active theme's own header.
 *
 * Originally this branched on `wp_is_block_theme()` to ALSO render the active
 * theme's own chrome (`block_header_area()` / `get_header()`) above a plain
 * `<div>` nav strip. That produced a second, redundant header on every plugin
 * route — the default block theme's site title + Page-List nav ("Game Library
 * Demo" / "Sample Page") stacked above this plugin's own nav bar — and the
 * core Navigation/Page-List block markup it emitted also tripped axe-core's
 * `list` rule (AC-NFR-003). The plugin now owns the page chrome outright: no
 * theme header/footer is rendered, `wp_head()` / `wp_footer()` (here and in
 * `site-footer.php`) still enqueue everything the active theme registers, and
 * DD-008 ("no theme.json, runs under any theme") still holds because opening
 * the document manually is theme-agnostic.
 *
 * `.gl-site-header` is therefore now the real `<header>` (one `banner` per
 * page); its matching close, `site-footer.php`, is the real `<footer>`
 * (`contentinfo`). The inner `<nav>` carries a brand-qualified `aria-label`.
 *
 * @package Game_Library
 */

use Game_Library\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
<?php
$gl_current_route = get_query_var( 'gl_route' );

// Which of the three primary nav destinations the current request belongs
// to. A drill-down page still marks its parent section active — a single
// game's own page under "Game collection", a member's public library page
// under "Members" — since a visitor reaches either by following that
// section's own link in the first place.
$gl_nav_games_active    = in_array( $gl_current_route, array( Router::ROUTE_GAMES, Router::ROUTE_GAME ), true );
$gl_nav_library_active  = Router::ROUTE_MY_LIBRARY === $gl_current_route;
$gl_nav_members_active  = in_array( $gl_current_route, array( Router::ROUTE_MEMBERS, Router::ROUTE_LIBRARY ), true );
$gl_nav_activity_active = Router::ROUTE_ACTIVITY === $gl_current_route;
?>
<header class="gl-site-header">
	<div class="gl-container gl-container--wide gl-site-header__row">
		<a class="gl-site-header__brand" href="<?php echo esc_url( Router::catalog_url() ); ?>">
			<span class="gl-site-header__mark" aria-hidden="true">G</span>
			<span class="gl-site-header__wordmark"><?php esc_html_e( 'Game Library', 'game-library' ); ?></span>
		</a>
		<nav class="gl-site-header__nav" aria-label="<?php esc_attr_e( 'Game Library navigation', 'game-library' ); ?>">
			<a
				class="gl-site-header__link"
				href="<?php echo esc_url( Router::catalog_url() ); ?>"
				<?php echo $gl_nav_games_active ? 'aria-current="page"' : ''; ?>
			><?php esc_html_e( 'Game collection', 'game-library' ); ?></a>
			<?php if ( is_user_logged_in() ) : ?>
				<a
					class="gl-site-header__link"
					href="<?php echo esc_url( Router::my_library_url() ); ?>"
					<?php echo $gl_nav_library_active ? 'aria-current="page"' : ''; ?>
				><?php esc_html_e( 'My library', 'game-library' ); ?></a>
			<?php endif; ?>
			<a
				class="gl-site-header__link"
				href="<?php echo esc_url( Router::members_url() ); ?>"
				<?php echo $gl_nav_members_active ? 'aria-current="page"' : ''; ?>
			><?php esc_html_e( 'Members', 'game-library' ); ?></a>
			<a
				class="gl-site-header__link"
				href="<?php echo esc_url( Router::activity_url() ); ?>"
				<?php echo $gl_nav_activity_active ? 'aria-current="page"' : ''; ?>
			><?php esc_html_e( 'Recent activity', 'game-library' ); ?></a>
			<button
				type="button"
				class="gl-site-header__menu-toggle"
				data-gl-menu-toggle
				aria-expanded="false"
				aria-controls="gl-site-header-menu"
			>
				<span class="gl-site-header__menu-icon" aria-hidden="true"><span></span><span></span><span></span></span>
				<?php esc_html_e( 'Menu', 'game-library' ); ?>
			</button>
		</nav>
	</div>
	<div class="gl-site-header__menu" id="gl-site-header-menu" data-gl-menu hidden>
		<div class="gl-container gl-container--wide">
			<ul class="gl-site-header__menu-list">
				<?php if ( is_user_logged_in() ) : ?>
					<li><a class="gl-site-header__menu-link" href="<?php echo esc_url( Router::my_library_url() ); ?>"><?php esc_html_e( 'My library', 'game-library' ); ?></a></li>
					<li><a class="gl-site-header__menu-link" href="<?php echo esc_url( Router::invites_url() ); ?>"><?php esc_html_e( 'Invites', 'game-library' ); ?></a></li>
					<li><a class="gl-site-header__menu-link" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log out', 'game-library' ); ?></a></li>
				<?php else : ?>
					<li><a class="gl-site-header__menu-link" href="<?php echo esc_url( Router::join_url() ); ?>"><?php esc_html_e( 'Join', 'game-library' ); ?></a></li>
					<li><a class="gl-site-header__menu-link" href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Log in', 'game-library' ); ?></a></li>
				<?php endif; ?>
			</ul>
			<?php
			/*
			 * Optional login-credential hint shown beneath the account menu so a
			 * visitor can sign in as a sample account. Ships inert: the plugin
			 * registers no callback, so this filter returns null and nothing
			 * renders. A demo/sandbox environment supplies the credentials via
			 * the `game_library_login_hint` filter (see the demo
			 * gl-demo-login-hint mu-plugin).
			 *
			 * Two filter shapes are accepted: a single account
			 * ( array with `username`/`password`/`label` keys ), or a list of
			 * accounts under an `accounts` key so several sign-ins (e.g. a member
			 * and an admin) can be advertised at once. An optional top-level
			 * `note` renders once beneath the whole block.
			 */
			$gl_login_hint    = apply_filters( 'game_library_login_hint', null );
			$gl_hint_accounts = array();

			if ( is_array( $gl_login_hint ) ) {
				if ( ! empty( $gl_login_hint['accounts'] ) && is_array( $gl_login_hint['accounts'] ) ) {
					$gl_hint_accounts = $gl_login_hint['accounts'];
				} elseif ( ! empty( $gl_login_hint['username'] ) && ! empty( $gl_login_hint['password'] ) ) {
					$gl_hint_accounts = array( $gl_login_hint );
				}
			}

			if ( ! empty( $gl_hint_accounts ) ) :
				$gl_hint_note = ( is_array( $gl_login_hint ) && ! empty( $gl_login_hint['note'] ) ) ? $gl_login_hint['note'] : '';
				?>
				<div class="gl-site-header__login-hint">
					<?php
					foreach ( $gl_hint_accounts as $gl_hint_account ) :
						if ( empty( $gl_hint_account['username'] ) || empty( $gl_hint_account['password'] ) ) {
							continue;
						}
						$gl_hint_label = ! empty( $gl_hint_account['label'] ) ? $gl_hint_account['label'] : __( 'Demo account', 'game-library' );
						?>
						<div class="gl-site-header__login-hint-account">
							<p class="gl-site-header__login-hint-title"><?php echo esc_html( $gl_hint_label ); ?></p>
							<dl class="gl-site-header__login-hint-creds">
								<div class="gl-site-header__login-hint-cred">
									<dt><?php esc_html_e( 'Username', 'game-library' ); ?></dt>
									<dd><code><?php echo esc_html( $gl_hint_account['username'] ); ?></code></dd>
								</div>
								<div class="gl-site-header__login-hint-cred">
									<dt><?php esc_html_e( 'Password', 'game-library' ); ?></dt>
									<dd><code><?php echo esc_html( $gl_hint_account['password'] ); ?></code></dd>
								</div>
							</dl>
						</div>
					<?php endforeach; ?>
					<?php if ( '' !== $gl_hint_note ) : ?>
						<p class="gl-site-header__login-hint-note"><?php echo esc_html( $gl_hint_note ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</header>
