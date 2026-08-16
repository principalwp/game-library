<?php
/**
 * `/games/` — the public game catalog index (AC-039).
 *
 * Rendered by `Router` (Task 10) via `template_include`, or by a theme's own
 * override at `game-library/game-catalog.php` (`locate_template()`, DD-008).
 * This route is not access-gated — it is public and, unless the
 * `catalog_indexable` kill switch is off, indexable (`Robots`, this task).
 *
 * Reads exclusively through `Game_Repository::get_referenced_games()`/
 * `count_referenced_games()` (Task 15 additions — see
 * `principal/adr/008-catalog-listing-and-games-pagination-route.md`), with an
 * explicit page and page size of 24 (AC-039) — no `$wpdb` call and no
 * unbounded query happens in this file, and no IGDB/Twitch HTTP call is ever
 * made from a template render path.
 *
 * Pagination uses the `/games/page/{n}/` path (the `Router` rule this task
 * adds) and the plugin-owned `gl_page` query var, not `?paged=`/`paged` — see
 * `Router::add_rewrite_rules()`'s own docblock for why: `paged` feeds
 * WordPress's main query and 404s a page beyond the *site's* own post count
 * regardless of this route's own content, confirmed at runtime. This
 * template's own Previous/Next links and `Robots::canonical_url()`'s
 * self-referencing canonical (built from `$wp->request`) always agree on
 * page 2+'s URL (AC-042).
 *
 * @package Game_Library
 */

use Game_Library\Data\Game_Repository;
use Game_Library\Igdb\Game_Mapper;
use Game_Library\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$partials_dir = __DIR__ . '/partials/';
$page_size    = 24;

// $current_page avoids the reserved $page WP global name
// (WordPress.WP.GlobalVariablesOverride) — populated by the
// `/games/page/{n}/` rewrite rule's `gl_page` query var (never `paged`, see
// the docblock above).
$current_page = max( 1, absint( get_query_var( 'gl_page' ) ) );

$game_repo = new Game_Repository();
$mapper    = new Game_Mapper();

$total       = $game_repo->count_referenced_games();
$total_pages = max( 1, (int) ceil( $total / $page_size ) );

// MR-1: an out-of-range page (e.g. /games/page/999/) must 404, not render
// the "no games yet" empty state at HTTP 200 with a self-referencing
// canonical and no noindex — checked, and returned, before
// get_referenced_games() ever runs the correlated-EXISTS catalog query.
// Page 1 is always allowed, including a genuinely empty catalog.
if ( $current_page > $total_pages ) {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );

	include $partials_dir . 'site-header.php';
	?>
	<main id="gl-main" class="gl-root gl-container">
		<p><?php esc_html_e( 'Page not found.', 'game-library' ); ?></p>
	</main>
	<?php
	include $partials_dir . 'site-footer.php';
	return;
}

$games = $game_repo->get_referenced_games( $current_page, $page_size );

$catalog_base_url = Router::catalog_url();
$previous_url     = 1 < $current_page
	? Router::catalog_url( $current_page - 1 )
	: '';
$next_url         = $current_page < $total_pages
	? Router::catalog_url( $current_page + 1 )
	: '';

include $partials_dir . 'site-header.php';
?>

<main id="gl-main" class="gl-root gl-container gl-container--wide gl-game-catalog">

	<?php // DES-50 (restyle §B row 1): light-surface eyebrow + editorial <h1>. ?>
	<div class="gl-page-head">
		<span class="gl-page-head__eyebrow"><?php esc_html_e( 'Browse the catalog', 'game-library' ); ?></span>
		<h1><?php esc_html_e( 'Games', 'game-library' ); ?></h1>
	</div>

	<?php if ( empty( $games ) ) : ?>
		<?php // DES-50 (restyle §B row 10 / §F.4): eyebrow + italic-serif line in a dashed box. ?>
		<div class="gl-empty-state">
			<span class="gl-empty-state__eyebrow"><?php esc_html_e( 'Nothing here yet', 'game-library' ); ?></span>
			<p class="gl-empty-state__line"><?php esc_html_e( 'No games in the catalog yet.', 'game-library' ); ?></p>
		</div>
	<?php else : ?>
		<ul class="gl-library-grid gl-game-catalog-grid" id="gl-game-catalog-grid">
			<?php
			// PF-1 (cycle-8): keyed off the first card that actually
			// renders a cover, not the loop index — game-card.php only
			// spends the eager+fetchpriority="high" treatment inside its
			// own `if ( $cover_url )` branch, so a coverless first game
			// (cover_image_id is nullable, AC-005(f)) used to spend the hint
			// on a card with no <img> at all, leaving the real LCP element
			// (the first card that does render one) on the lazy branch.
			$priority_used = false;

			foreach ( $games as $game ) :
				$card_game = array(
					'igdb_id'            => $game['igdb_id'],
					'slug'               => $game['slug'],
					'name'               => $game['name'],
					'cover_url'          => $mapper->cover_url( $game['cover_image_id'] ),
					'first_release_year' => $mapper->first_release_year( $game ),
				);
				$card_status               = null;
				$card_show_status_selector = false;
				$card_show_remove          = false;
				// Only this page's first card with a cover is the route's
				// LCP element (PF-1/PF-2) — never mark more than one per
				// page.
				$card_is_priority          = ! $priority_used && null !== $card_game['cover_url'];
				$priority_used             = $priority_used || $card_is_priority;

				include $partials_dir . 'game-card.php';
			endforeach;
			?>
		</ul>
	<?php endif; ?>

	<?php
	// DES-22: path-based /games/page/{n}/ URLs (not the ?gl_page=N scheme
	// partials/pagination.php derives on its own), so this passes the two
	// URLs it already computed directly rather than duplicating the <nav>
	// markup a fourth time.
	$pagination_previous_url = $previous_url;
	$pagination_next_url     = $next_url;
	$pagination_status_text  = sprintf(
		/* translators: 1: current page number, 2: total number of pages. */
		__( 'Page %1$d of %2$d', 'game-library' ),
		$current_page,
		$total_pages
	);
	include $partials_dir . 'pagination.php';
	?>

	<?php include $partials_dir . 'attribution.php'; ?>

</main>

<?php
include $partials_dir . 'site-footer.php';
