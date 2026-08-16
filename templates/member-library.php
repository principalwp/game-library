<?php
/**
 * `/library/{nicename}/` — another member's library, read-only (AC-014).
 *
 * Rendered by `Router` (Task 10) via `template_include`, or by a theme's own
 * override at `game-library/member-library.php` (`locate_template()`,
 * DD-008). `Router::gate_member_library()` already resolves and validates the
 * `{nicename}` segment (404 on an unknown member, a login redirect for a
 * logged-out visitor unless the member has opted `_gl_profile_public`) before
 * this template is ever selected — the guard below only protects a theme
 * that calls this template directly outside that resolution path.
 *
 * Reads entries/counts/games only through the repositories, with an explicit
 * page and per-page of 24 (AC-017) — no `$wpdb` call and no unbounded query
 * happens in this file. This route enqueues `social.js` (Assets::ROUTE_SCRIPTS,
 * Task 4), a later task — the Follow/Unfollow control below only renders the
 * markup contract that script binds to; no status selector and no remove
 * control are ever rendered here (AC-014).
 *
 * @package Game_Library
 */

use Game_Library\Data\Follow_Repository;
use Game_Library\Data\Game_Repository;
use Game_Library\Data\Library_Repository;
use Game_Library\Igdb\Game_Mapper;
use Game_Library\Router;
use Game_Library\Statuses;
use Game_Library\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nicename = sanitize_title( (string) get_query_var( 'gl_param' ) );
$member   = '' !== $nicename ? get_user_by( 'slug', $nicename ) : false;

if ( ! $member ) {
	status_header( 404 );
	include __DIR__ . '/partials/site-header.php';
	?>
	<main id="gl-main" class="gl-root gl-container">
		<p><?php esc_html_e( 'Member not found.', 'game-library' ); ?></p>
	</main>
	<?php
	include __DIR__ . '/partials/site-footer.php';
	return;
}

$partials_dir = __DIR__ . '/partials/';
$page_size    = 24;
$viewer_id    = get_current_user_id();
$is_self      = $viewer_id === $member->ID;

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
$follow_repo  = new Follow_Repository();

// MR-2: counts/total are read BEFORE get_page(), so an out-of-range page can
// 404 (mirroring templates/game-catalog.php's own MR-1 branch) without ever
// reaching get_page()'s query. Both reads are already cached and keyed on
// the member's own generation counter, so moving them earlier costs nothing
// extra.
$counts      = $library_repo->status_counts( $member->ID );
$total       = '' !== $current_status ? $counts[ $current_status ] : array_sum( $counts );
$total_pages = max( 1, (int) ceil( $total / $page_size ) );

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

$entries = $library_repo->get_page( $member->ID, $current_status, $current_page, $page_size );
$games   = $game_repo->get_many( wp_list_pluck( $entries, 'igdb_id' ) );

$is_following = ( $viewer_id && ! $is_self ) ? $follow_repo->is_following( $viewer_id, $member->ID ) : false;
$base_url     = Router::member_library_url( $member->user_nicename );

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

<main id="gl-main" class="gl-root gl-container gl-container--wide gl-member-library">

	<div class="gl-member-header">
		<span class="gl-member-header__avatar"><?php echo get_avatar( $member->ID, 64 ); ?></span>
		<?php // DES-50 (restyle §5): name + a "Public library" marker (only when the profile is actually public — a logged-in viewer may be seeing a private one). ?>
		<div class="gl-member-header__identity">
			<h1><?php echo esc_html( $member->display_name ); ?></h1>
			<?php if ( Visibility::is_public( $member->ID ) ) : ?>
				<span class="gl-member-header__public-marker"><?php esc_html_e( 'Public library', 'game-library' ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $viewer_id && ! $is_self ) : ?>
			<button
				type="button"
				id="gl-follow-toggle"
				class="gl-button gl-button--secondary"
				data-gl-follow-toggle
				data-user-id="<?php echo esc_attr( (string) $member->ID ); ?>"
				data-following="<?php echo esc_attr( $is_following ? '1' : '0' ); ?>"
			>
				<?php echo esc_html( $is_following ? __( 'Unfollow', 'game-library' ) : __( 'Follow', 'game-library' ) ); ?>
			</button>
			<?php // MR-4: pre-rendered, not lazily created by social.js — carries role="alert" so a screen reader announces a failed follow/unfollow, which a JS-created element never got. ?>
			<p class="gl-error-text" data-gl-follow-error role="alert"></p>
		<?php elseif ( ! $viewer_id ) : ?>
			<a class="gl-button gl-button--secondary" href="<?php echo esc_url( wp_login_url( $base_url ) ); ?>">
				<?php esc_html_e( 'Log in to follow', 'game-library' ); ?>
			</a>
		<?php endif; ?>
	</div>

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
			<p class="gl-empty-state__line"><?php esc_html_e( 'This library is empty.', 'game-library' ); ?></p>
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
	$pagination_current_page = $current_page;
	$pagination_total_pages  = $total_pages;
	$pagination_base_url     = '' !== $current_status ? add_query_arg( 'status', $current_status, $base_url ) : $base_url;
	include $partials_dir . 'pagination.php';
	?>

	<?php include $partials_dir . 'attribution.php'; ?>

</main>

<?php
include $partials_dir . 'site-footer.php';
