<?php
/**
 * Route template: `/my-library/imports/{id}/`.
 *
 * Reached only by an authenticated member (AC-021, enforced in the router).
 * Ownership of *this* import job is a second, narrower gate: the job's owner is
 * a property of the row, so it is loaded here and a job belonging to somebody
 * else — or one that never existed — is handed to `GameLib_Router::deny()`, the
 * same shared 404 every other denial uses (AC-042, DD-014).
 *
 * The screen is server-rendered first and polled after (AC-043b): the panel
 * below holds the same `import-status` fragment `GET /imports/{id}` returns, so
 * the first paint of a queued job, every poll of it, and the swap after a
 * decision are one markup path (ADR-002). Members with JavaScript disabled are
 * out of scope for the *controls* (D-REQ-52) but still get this read-only
 * report of where their import stands.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_import = GameLib_Importer::get_for_user( GameLib_Router::import_id(), get_current_user_id() );

if ( is_wp_error( $gamelib_import ) ) {
	/*
	 * The earliest point ownership can be judged is after `template_include`
	 * has already chosen this file, so the denial is completed here: `deny()`
	 * performs the same `set_404()` + 404 status as every other gate, and the
	 * active theme's own 404 template renders the body. Nothing has been output
	 * yet, so another member's import id is indistinguishable from one that
	 * does not exist (AC-042, AC-NFR-001).
	 */
	GameLib_Router::deny();

	$gamelib_denied_template = get_404_template();

	if ( '' !== $gamelib_denied_template ) {
		require $gamelib_denied_template;
	}

	return;
}

$gamelib_payload = GameLib_Importer::payload( $gamelib_import );
$gamelib_items   = GameLib_Importer::report_items( $gamelib_import );

GameLib_Router::part( 'document-open', array( 'body_class' => 'gamelib-route-import-review' ) );

?>
<header class="gamelib__header">
	<h1 class="gamelib__title"><?php esc_html_e( 'Review import', 'game-library' ); ?></h1>
	<p class="gamelib__lead">
		<a href="<?php echo esc_url( GameLib_Router::route_url( GameLib_Router::ROUTE_MY_LIBRARY ) ); ?>">
			<?php esc_html_e( 'Back to your library', 'game-library' ); ?>
		</a>
	</p>
</header>

<section class="gamelib__section" aria-labelledby="gamelib-import-title">
	<h2 id="gamelib-import-title" class="gamelib__section-title" tabindex="-1"><?php esc_html_e( 'Import results', 'game-library' ); ?></h2>
	<div
		id="gamelib-import"
		class="gamelib__panel"
		data-gamelib-import-id="<?php echo esc_attr( (string) (int) $gamelib_import['id'] ); ?>"
		data-gamelib-error="<?php echo esc_attr__( 'That did not go through. Check your connection and try again.', 'game-library' ); ?>"
	><?php GameLib_Router::part( 'import-status', array( 'import' => $gamelib_payload, 'items' => $gamelib_items ) ); ?></div>
	<?php
	/*
	 * The announcement region lives outside the swapped panel on purpose:
	 * replacing a live region's own node is not reliably announced, so the
	 * bundle writes the server's sentence into this one instead (AC-NFR-004).
	 */
	?>
	<div class="gamelib-visually-hidden" data-gamelib-live role="status" aria-live="polite"></div>
</section>
<?php

GameLib_Router::part( 'document-close' );
