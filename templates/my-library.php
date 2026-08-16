<?php
/**
 * `/my-library/` — the owner's own library (AC-013).
 *
 * Rendered by `Router` (Task 10) via `template_include`, or by a theme's own
 * override at `game-library/my-library.php` (`locate_template()`, DD-008).
 * Access is already gated logged-in-only by `Router::enforce_access_gates()`
 * before this template is ever selected.
 *
 * Reads entries/counts/games only through the repositories, with an explicit
 * page and per-page of 24 (AC-017) — no `$wpdb` call and no unbounded query
 * happens in this file. Every status change, removal, and visibility toggle
 * is handled by `library.js`; every search state and the "add to library"
 * action is handled by `search.js` (both this task) — this template only
 * renders the initial page.
 *
 * MR-2: this template does not itself 404 an out-of-range `gl_page`, unlike
 * `templates/game-catalog.php`/`member-library.php`. It is an
 * authenticated-only route (never anonymously cached or crawled), and the
 * underlying `Library_Repository::get_page()` call already carries its own
 * offset ceiling against `status_counts()` (MR-2's data-layer fix), so an
 * out-of-range page renders this route's own empty state rather than
 * running an unbounded query or writing a dead cache entry — a 404 branch
 * here would only improve the HTTP status code on a surface no crawler ever
 * sees.
 *
 * @package Game_Library
 */

use Game_Library\Data\Game_Repository;
use Game_Library\Data\Library_Repository;
use Game_Library\Igdb\Game_Mapper;
use Game_Library\Router;
use Game_Library\Statuses;
use Game_Library\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$partials_dir = __DIR__ . '/partials/';
$page_size    = 24;
$user_id      = get_current_user_id();

// Read-only filter/pagination params from a plain GET navigation — not a
// state-changing action, so no nonce applies; see
// includes/admin/class-invites-list-table.php for the same accepted pattern
// elsewhere in this codebase. Local var names avoid the reserved
// $status/$page/$per_page WP global names (WordPress.WP.GlobalVariablesOverride).
// Pagination reads the plugin-owned `gl_page` query var, never `paged` — see
// templates/partials/pagination.php's own docblock for why.
$current_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter param, not a state-changing action.
$current_status = Statuses::is_valid( $current_status ) ? $current_status : '';
$current_page   = max( 1, absint( get_query_var( 'gl_page' ) ) );

$library_repo = new Library_Repository();
$game_repo    = new Game_Repository();
$mapper       = new Game_Mapper();

$entries = $library_repo->get_page( $user_id, $current_status, $current_page, $page_size );
$counts  = $library_repo->status_counts( $user_id );
$total   = '' !== $current_status ? $counts[ $current_status ] : array_sum( $counts );
$games   = $game_repo->get_many( wp_list_pluck( $entries, 'igdb_id' ) );

$is_public = Visibility::is_public( $user_id );
$base_url  = Router::my_library_url();

// DES-50 (restyle §2): the navy header band's subtitle. $total_games avoids
// the reserved $total WP global name is not needed ($total already exists
// above), so a distinct name is used for the whole-library figure the sub
// reports (the active filter's own $total drives pagination, not this).
$total_games = array_sum( $counts );
$library_sub = sprintf(
	/* translators: %d: total number of games the member is tracking. */
	_n( '%d game tracked', '%d games tracked', $total_games, 'game-library' ),
	$total_games
);

/**
 * Formats a per-status entry count as a translated "%d game(s)" phrase
 * (this task's own "use _n() for every count string" constraint).
 *
 * @param int $count Entry count.
 * @return string
 */
$count_label = function ( $count ) {
	return sprintf(
		/* translators: %d: number of games. */
		_n( '%d game', '%d games', $count, 'game-library' ),
		$count
	);
};

include $partials_dir . 'site-header.php';
?>

<main id="gl-main" class="gl-root gl-my-library">

	<?php // DES-50 (restyle §2, §13): the navy header band carries the title, count, and the visibility switch; the light-ground content moves into the inner .gl-container below it, so <main> no longer carries the container classes itself. ?>
	<div class="gl-header-band">
		<div class="gl-container gl-container--wide">
			<div>
				<h1><?php esc_html_e( 'My library', 'game-library' ); ?></h1>
				<p class="gl-header-band__sub"><?php echo esc_html( $library_sub ); ?></p>
			</div>
			<div class="gl-header-band__actions">
				<?php // DES-50 (restyle §B row 8): the switch wraps the EXISTING #gl-profile-public-toggle checkbox in two decorative spans; library.js binds to [data-gl-visibility-toggle], unchanged. ?>
				<div class="gl-visibility-toggle">
					<span class="gl-toggle">
						<input
							type="checkbox"
							id="gl-profile-public-toggle"
							data-gl-visibility-toggle
							aria-describedby="gl-visibility-error"
							<?php checked( $is_public ); ?>
						/>
						<span class="gl-toggle__track" aria-hidden="true"></span>
						<span class="gl-toggle__thumb" aria-hidden="true"></span>
					</span>
					<label for="gl-profile-public-toggle" class="gl-visibility-toggle__label"><?php esc_html_e( 'Make my library visible to logged-out visitors', 'game-library' ); ?></label>
					<?php // MR-4: role="alert" so a screen reader announces a failed visibility save; aria-describedby above ties the checkbox to this exact slot. ?>
					<p class="gl-error-text" id="gl-visibility-error" role="alert"></p>
				</div>
			</div>
		</div>
	</div>

	<div class="gl-container gl-container--wide">

	<?php include $partials_dir . 'search-form.php'; ?>

	<ul class="gl-status-filters" id="gl-status-filters">
		<li>
			<a href="<?php echo esc_url( $base_url ); ?>" <?php echo '' === $current_status ? 'aria-current="true"' : ''; ?>>
				<?php
				printf(
					/* translators: %s: "N games" count text. */
					esc_html__( 'All (%s)', 'game-library' ),
					esc_html( $count_label( array_sum( $counts ) ) )
				);
				?>
			</a>
		</li>
		<?php foreach ( Statuses::all() as $status_option ) : ?>
			<li>
				<a
					href="<?php echo esc_url( add_query_arg( 'status', $status_option, $base_url ) ); ?>"
					<?php echo $current_status === $status_option ? 'aria-current="true"' : ''; ?>
				>
					<?php
					printf(
						/* translators: 1: status label, 2: "N games" count text. */
						esc_html__( '%1$s (%2$s)', 'game-library' ),
						esc_html( Statuses::label( $status_option ) ),
						esc_html( $count_label( $counts[ $status_option ] ) )
					);
					?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( empty( $entries ) ) : ?>
		<?php // DES-50 (restyle §B row 10 / §F.4): eyebrow + italic-serif line in a dashed box. ?>
		<div class="gl-empty-state">
			<span class="gl-empty-state__eyebrow"><?php esc_html_e( 'Nothing here yet', 'game-library' ); ?></span>
			<p class="gl-empty-state__line"><?php esc_html_e( 'No games in your library yet. Search above to add one.', 'game-library' ); ?></p>
		</div>
	<?php else : ?>
		<ul class="gl-library-grid" id="gl-library-grid">
			<?php
			// PF-1 (cycle-8): keyed off the first card that actually
			// renders a cover, not the loop index — see game-catalog.php's
			// own comment for the full reasoning. $priority_used is set
			// AFTER the orphaned-entry `continue` below, not before it —
			// an orphaned first entry must not silently consume the hint.
			$priority_used = false;

			foreach ( $entries as $entry ) :
				$game = isset( $games[ $entry['igdb_id'] ] ) ? $games[ $entry['igdb_id'] ] : null;

				if ( null === $game ) {
					continue;
				}

				$card_game = array(
					'igdb_id'            => $game['igdb_id'],
					'slug'               => $game['slug'],
					'name'               => $game['name'],
					'cover_url'          => $mapper->cover_url( $game['cover_image_id'] ),
					'first_release_year' => $mapper->first_release_year( $game ),
				);
				$card_status               = $entry['status'];
				$card_show_status_selector = true;
				$card_show_remove          = true;
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
	$pagination_current_page = $current_page;
	$pagination_total_pages  = max( 1, (int) ceil( $total / $page_size ) );
	$pagination_base_url     = '' !== $current_status ? add_query_arg( 'status', $current_status, $base_url ) : $base_url;
	include $partials_dir . 'pagination.php';
	?>

	<?php include $partials_dir . 'attribution.php'; ?>

	</div>

</main>

<?php
include $partials_dir . 'site-footer.php';
