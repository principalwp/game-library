<?php
/**
 * `/games/{slug}/` — the public per-game catalog page (AC-038, AC-041,
 * AC-046).
 *
 * Rendered by `Router` (Task 10) via `template_include`, or by a theme's own
 * override at `game-library/game-single.php` (`locate_template()`, DD-008).
 * `Router::gate_game_single()` already resolves and validates the `{slug}`
 * segment (404 on an unknown slug, or on a game no active member's library
 * currently references, AC-040) before this template is ever selected — the
 * guard below only protects a theme that calls this template directly
 * outside that resolution path, matching `templates/member-library.php`'s
 * own precedent.
 *
 * Reads exclusively through `Game_Repository`/`Library_Repository`, with an
 * explicit page and page size of 24 for the holder list (AC-038(g),
 * AC-NFR-005) — no `$wpdb` call and no unbounded query happens in this file,
 * and no IGDB/Twitch HTTP call is ever made from a template render path.
 *
 * Batching (this task's own constraint): the distinct holder `user_id`
 * values for the page are collected and primed with one `cache_users()` call
 * before any holder is rendered, so every `get_userdata()`, `get_avatar()`,
 * and `_gl_profile_public` meta read below is a cache hit — never one user
 * query per holder, so the query count does not grow with the number of
 * distinct holders on the page (AC-NFR-005). A member who has not opted
 * `_gl_profile_public` to `'1'` still counts toward the per-status totals
 * below but is filtered out of `$visible_holders` before any markup is
 * built, so their name, nicename, and avatar never reach the response body
 * (AC-038(g)).
 *
 * `Robots` (this task) emits this page's canonical link and indexability;
 * `Schema_Org` (this task) emits its single `VideoGame` JSON-LD object —
 * both hook `wp_head` directly and need no template-side call.
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

$slug = sanitize_title( (string) get_query_var( 'gl_param' ) );

$game_repo = new Game_Repository();
$game      = '' !== $slug ? $game_repo->get_by_slug( $slug ) : null;

if ( null === $game ) {
	status_header( 404 );
	include __DIR__ . '/partials/site-header.php';
	?>
	<main id="gl-main" class="gl-root gl-container">
		<p><?php esc_html_e( 'Game not found.', 'game-library' ); ?></p>
	</main>
	<?php
	include __DIR__ . '/partials/site-footer.php';
	return;
}

$partials_dir = __DIR__ . '/partials/';
$page_size    = 24;

// $current_page avoids the reserved $page WP global name
// (WordPress.WP.GlobalVariablesOverride). Pagination reads the plugin-owned
// `gl_page` query var, never `paged` — see
// templates/partials/pagination.php's own docblock for why.
$current_page = max( 1, absint( get_query_var( 'gl_page' ) ) );

$library_repo = new Library_Repository();
$mapper       = new Game_Mapper();

// AC-038(g): $status_counts stays unfiltered (private holders count but do
// not appear), while $total_holders/pagination is built from the
// PUBLIC-only count (PB-7) — profiles are private by default (AC-034), so
// array_sum( $status_counts ) (every holder, public and private) previously
// over-counted the page total against what the list below actually has to
// show, producing pages that could render mostly or entirely empty.
$status_counts = $library_repo->status_counts_for_game( $game['igdb_id'] );
$total_holders = $library_repo->count_public_holders_for_game( $game['igdb_id'] );
$total_pages   = max( 1, (int) ceil( $total_holders / $page_size ) );

// MR-1: an out-of-range holder-list page must 404, not render the "no
// public member libraries hold this game on this page" empty state at
// HTTP 200 — same defect class as the catalog listing. Checked, and
// returned, before public_holders_for_game() ever runs.
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

$holders = $library_repo->public_holders_for_game( $game['igdb_id'], $page_size, ( $current_page - 1 ) * $page_size );

// One call primes every holder row's user/usermeta object cache — never one
// get_userdata()/get_user_meta() per row (this task's own batching
// constraint, AC-NFR-005).
$holder_ids = array_values( array_unique( wp_list_pluck( $holders, 'user_id' ) ) );

if ( ! empty( $holder_ids ) ) {
	cache_users( $holder_ids );
}

$visible_holders = array();

foreach ( $holders as $holder ) {
	$holder_user = get_userdata( $holder['user_id'] );

	if ( ! $holder_user ) {
		continue;
	}

	// AC-038(g): a member who has not opted their profile public contributes
	// to $status_counts above but is entirely omitted here — no name,
	// nicename, or avatar for them anywhere in the response body.
	if ( ! Visibility::is_public( $holder_user->ID ) ) {
		continue;
	}

	$visible_holders[] = array(
		'user'   => $holder_user,
		'status' => $holder['status'],
	);
}

$cover_url    = $mapper->cover_url( $game['cover_image_id'] );
$game_url     = Router::game_url( $game['slug'] );

/**
 * Formats a per-status holder count as a translated "%d member(s)" phrase
 * (this task's own "use _n() for the holder-count strings" constraint).
 *
 * @param int $count Holder count.
 * @return string
 */
$holder_count_label = function ( $count ) {
	return sprintf(
		/* translators: %d: number of members. */
		_n( '%d member', '%d members', $count, 'game-library' ),
		$count
	);
};

include $partials_dir . 'site-header.php';
?>

<main id="gl-main" class="gl-root gl-game-single">

	<?php // DES-50 (restyle §11): the navy hero band carries the cover + meta; the summary and holder list move onto the light ground below it, so <main> no longer carries gl-container itself — the header IS the container inside the band. ?>
	<div class="gl-header-band">
		<div class="gl-container gl-game-single__header">
		<div class="gl-game-single__cover">
			<?php if ( $cover_url ) : ?>
				<img
					src="<?php echo esc_url( $cover_url ); ?>"
					alt="<?php echo esc_attr( $game['name'] ); ?>"
					width="<?php echo esc_attr( (string) Game_Mapper::COVER_BIG_WIDTH ); ?>"
					height="<?php echo esc_attr( (string) Game_Mapper::COVER_BIG_HEIGHT ); ?>"
					fetchpriority="high"
					decoding="async"
				/>
			<?php else : ?>
				<span class="gl-game-single__cover-placeholder"><?php echo esc_html( $game['name'] ); ?></span>
			<?php endif; ?>
		</div>

		<div class="gl-game-single__meta">
			<h1><?php echo esc_html( $game['name'] ); ?></h1>

			<p class="gl-game-single__release-date">
				<?php
				// null !== ..., not a truthy check (CO-12 sibling): a
				// first_release_date of exactly 0 (1970-01-01 UTC) is a
				// real value, not an absent one — a bare truthy check
				// treats 0 as falsy and renders "Unreleased" identically to
				// a genuinely missing date.
				if ( null !== $game['first_release_date'] ) {
					// date_i18n(), deliberately not wp_date() (CO-5): unlike
					// this plugin's own UTC-anchored deadlines (invite
					// quota/expiry dates), a release date has no "site
					// timezone" to convert to — IGDB's date is the calendar
					// day the game released, and rendering that day
					// unconverted is the correct behaviour here, not a bug.
					echo esc_html( date_i18n( get_option( 'date_format' ), $game['first_release_date'] ) );
				} else {
					esc_html_e( 'Unreleased', 'game-library' );
				}
				?>
			</p>

			<?php if ( ! empty( $game['genres'] ) ) : ?>
				<h2 class="gl-game-single__label" id="gl-genres-label"><?php esc_html_e( 'Genres', 'game-library' ); ?></h2>
				<ul class="gl-game-single__genres" aria-labelledby="gl-genres-label">
					<?php foreach ( $game['genres'] as $genre ) : ?>
						<li><?php echo esc_html( $genre ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $game['platforms'] ) ) : ?>
				<h2 class="gl-game-single__label" id="gl-platforms-label"><?php esc_html_e( 'Platforms', 'game-library' ); ?></h2>
				<ul class="gl-game-single__platforms" aria-labelledby="gl-platforms-label">
					<?php foreach ( $game['platforms'] as $platform ) : ?>
						<li><?php echo esc_html( $platform ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		</div>
	</div>

	<div class="gl-container">

	<?php if ( ! empty( $game['summary'] ) ) : ?>
		<div class="gl-game-single__summary"><?php echo wp_kses_post( $game['summary'] ); ?></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Who holds this game', 'game-library' ); ?></h2>

	<?php // DES-43 (cycle-8): aria-label, not aria-labelledby — unlike the genres/platforms lists above (:205/:214), this list has no visible heading of its own (the <h2> above is shared with .gl-game-single__holders below it), so there is no id to point at without adding a new visually-hidden one for no other purpose. ?>
	<ul class="gl-game-single__status-counts" aria-label="<?php esc_attr_e( 'Status counts', 'game-library' ); ?>">
		<?php foreach ( Statuses::all() as $status_option ) : ?>
			<li>
				<?php
				printf(
					/* translators: 1: status label, 2: "N members" count text. */
					esc_html__( '%1$s: %2$s', 'game-library' ),
					esc_html( Statuses::label( $status_option ) ),
					esc_html( $holder_count_label( $status_counts[ $status_option ] ) )
				);
				?>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( empty( $visible_holders ) ) : ?>
		<?php // DES-50 (restyle §B row 10 / §F.4): eyebrow + italic-serif line in a dashed box. ?>
		<div class="gl-empty-state">
			<span class="gl-empty-state__eyebrow"><?php esc_html_e( 'Nothing here yet', 'game-library' ); ?></span>
			<p class="gl-empty-state__line"><?php esc_html_e( 'No public member libraries hold this game on this page.', 'game-library' ); ?></p>
		</div>
	<?php else : ?>
		<?php // DES-43 (cycle-8): aria-label, matching .gl-game-single__status-counts above — same reasoning, no visible heading of its own to point aria-labelledby at. ?>
		<ul class="gl-game-single__holders" id="gl-game-holders" aria-label="<?php esc_attr_e( 'Members holding this game', 'game-library' ); ?>">
			<?php foreach ( $visible_holders as $holder_index => $visible_holder ) : ?>
				<?php
				$holder_user  = $visible_holder['user'];
				$holder_url   = Router::member_library_url( $holder_user->user_nicename );
				$is_first_row = 0 === $holder_index;
				?>
				<li class="gl-game-single__holder">
					<?php
					/**
					 * Same redundant-adjacent-link fix as members.php's own
					 * avatar link — this holder avatar links to the exact same
					 * URL as the named link right after it, so it is hidden
					 * from assistive tech entirely rather than given a
					 * duplicate accessible name.
					 */
					?>
					<a class="gl-game-single__holder-avatar" href="<?php echo esc_url( $holder_url ); ?>" aria-hidden="true" tabindex="-1">
						<?php
						// DES-36: 24, matching --gl-avatar-sm — this row
						// requested 32 against a 24px CSS box. PF-2: the
						// first holder's avatar loads eagerly, same
						// $is_first_row shape activity.php/members.php use
						// (PF-5/PF-2) — keyed off $visible_holders' own
						// first element since that is what actually reaches
						// the DOM (a private holder in $holders is filtered
						// out before $visible_holders is built).
						echo get_avatar( $holder_user->ID, 24, '', '', array( 'loading' => $is_first_row ? 'eager' : 'lazy' ) );
						?>
					</a>
					<a href="<?php echo esc_url( $holder_url ); ?>"><?php echo esc_html( $holder_user->display_name ); ?></a>
					<?php
					$badge_status = $visible_holder['status'];
					include $partials_dir . 'status-badge.php';
					?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php
	$pagination_current_page = $current_page;
	$pagination_total_pages  = $total_pages;
	$pagination_base_url     = $game_url;
	include $partials_dir . 'pagination.php';
	?>

	<?php
	$attribution_href = ! empty( $game['igdb_url'] ) ? $game['igdb_url'] : null;
	include $partials_dir . 'attribution.php';
	?>

	</div>

</main>

<?php
include $partials_dir . 'site-footer.php';
