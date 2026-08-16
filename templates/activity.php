<?php
/**
 * `/activity/` — the viewer's activity feed (AC-020, AC-021, AC-022, AC-023).
 *
 * Rendered by `Router` (Task 10) via `template_include`, or by a theme's own
 * override at `game-library/activity.php` (`locate_template()`, DD-008).
 * Access is already gated logged-in-only by `Router::enforce_access_gates()`
 * before this template is ever selected.
 *
 * Reads the feed only through `Follow_Repository`/`Activity_Repository`/
 * `Game_Repository`, with an explicit page and per-page of 20 (AC-020) — no
 * `$wpdb` call and no unbounded query happens in this file. This route
 * enqueues no script (`Assets::ROUTE_SCRIPTS`, Task 4) — the feed is entirely
 * read-only, so everything below is a plain server render.
 *
 * Batching (this task's own constraint, mirroring
 * `Game_Repository::get_many()`'s pattern): `Activity_Repository::get_feed()`
 * already primes the WordPress user object cache for every distinct actor and
 * followed-member id on the returned page, unconditionally, including on a
 * cache hit against its own `game_library`-group cache (see that method's own
 * docblock) — so no separate `cache_users()` call is needed here. Every
 * `get_userdata()` call in the loop below is therefore a cache hit, and
 * `Game_Repository::get_many()` batches every referenced game row in one
 * call, so the query count does not grow with the number of distinct actors
 * or games on the page (AC-NFR-005).
 *
 * PB-3 (cycle-8): `Activity_Repository::count_feed()` now exists — the
 * previous two paragraphs described a real gap (an out-of-range `gl_page`
 * ran the full `IN (…)`/`ORDER BY`/`LIMIT …OFFSET …` query for nothing, and
 * this template could not 404 an out-of-range page the way
 * `game-catalog.php`/`member-library.php` already do), not a permanent
 * design property. Pagination below now mirrors `member-library.php`'s own
 * pattern: read the total first (cached, keyed to match `get_feed()`'s own
 * key shape), 404 when `$current_page` exceeds it, and otherwise derive
 * previous/next from `$total_pages` rather than a `count( $entries ) ===
 * $page_size` heuristic.
 *
 * @package Game_Library
 */

use Game_Library\Data\Activity_Repository;
use Game_Library\Data\Follow_Repository;
use Game_Library\Data\Game_Repository;
use Game_Library\Igdb\Game_Mapper;
use Game_Library\Router;
use Game_Library\Statuses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$partials_dir = __DIR__ . '/partials/';
$page_size    = 20;
$viewer_id    = get_current_user_id();

// $current_page avoids the reserved $page WP global name
// (WordPress.WP.GlobalVariablesOverride) — reads the plugin-owned `gl_page`
// query var, never `paged` — see templates/partials/pagination.php's own
// docblock for why.
$current_page = max( 1, absint( get_query_var( 'gl_page' ) ) );

$follow_repo   = new Follow_Repository();
$activity_repo = new Activity_Repository();
$game_repo     = new Game_Repository();
$mapper        = new Game_Mapper();

$following_ids = $follow_repo->following_ids( $viewer_id );

// PB-3 (cycle-8): total is read BEFORE get_feed(), so an out-of-range page
// can 404 (mirroring templates/member-library.php's own MR-2 pattern)
// without ever reaching get_feed()'s query — count_feed() is cached, keyed
// to match get_feed()'s own key shape, so this costs nothing extra on the
// common (in-range) case.
$total       = $activity_repo->count_feed( $viewer_id, $following_ids );
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

$entries = $activity_repo->get_feed( $viewer_id, $following_ids, $current_page, $page_size );

$game_ids = array_values( array_unique( array_filter( wp_list_pluck( $entries, 'igdb_id' ) ) ) );
$games    = ! empty( $game_ids ) ? $game_repo->get_many( $game_ids ) : array();

$base_url = Router::activity_url();

include $partials_dir . 'site-header.php';
?>

<main id="gl-main" class="gl-root gl-container gl-activity">

	<?php // DES-50 (restyle §B row 1): light-surface eyebrow + editorial <h1>. ?>
	<div class="gl-page-head">
		<span class="gl-page-head__eyebrow"><?php esc_html_e( 'Recent activity', 'game-library' ); ?></span>
		<h1><?php esc_html_e( 'Activity', 'game-library' ); ?></h1>
	</div>

	<?php if ( empty( $entries ) ) : ?>
		<?php
		// AC-022: renders whenever the viewer follows nobody, or follows only
		// members with no activity — both collapse to $entries being empty.
		$members_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( Router::members_url() ),
			esc_html__( 'Find members to follow', 'game-library' )
		);
		?>
		<p class="gl-empty-state">
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %s: "Find members to follow" link. */
					__( 'Nobody you follow has posted any activity yet. %s.', 'game-library' ),
					$members_link
				)
			);
			?>
		</p>
	<?php else : ?>
		<ul class="gl-activity-list" id="gl-activity-list">
			<?php
			// PF-5: counts only rows that actually render (past the
			// orphaned-row `continue` below), matching game-card.php's own
			// $card_index precedent — the first rendered row is always in
			// the initial viewport, so it should not be loading="lazy".
			$row_index = 0;

			foreach ( $entries as $entry ) :
				$actor = get_userdata( $entry['user_id'] );

				if ( ! $actor ) {
					continue;
				}

				$game        = ( null !== $entry['igdb_id'] && isset( $games[ $entry['igdb_id'] ] ) ) ? $games[ $entry['igdb_id'] ] : null;
				$object_user = null !== $entry['object_user_id'] ? get_userdata( $entry['object_user_id'] ) : false;
				$timestamp   = strtotime( $entry['date_created'] . ' UTC' );

				$actor_url  = Router::member_library_url( $actor->user_nicename );
				$actor_link = sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( $actor_url ),
					esc_html( $actor->display_name )
				);

				$sentence = '';

				if ( 'member_followed' === $entry['event_type'] && $object_user ) {
					$object_link = sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( Router::member_library_url( $object_user->user_nicename ) ),
						esc_html( $object_user->display_name )
					);

					$sentence = sprintf(
						/* translators: 1: actor name/library link, 2: followed member name/library link. */
						__( '%1$s started following %2$s.', 'game-library' ),
						$actor_link,
						$object_link
					);
				} elseif ( 'game_added' === $entry['event_type'] && $game ) {
					$sentence = sprintf(
						/* translators: 1: actor name/library link, 2: game title. */
						__( '%1$s added %2$s to their library.', 'game-library' ),
						$actor_link,
						esc_html( $game['name'] )
					);
				} elseif ( 'status_changed' === $entry['event_type'] && $game ) {
					$sentence = sprintf(
						/* translators: 1: actor name/library link, 2: game title, 3: previous status label, 4: new status label. */
						__( '%1$s moved %2$s from %3$s to %4$s.', 'game-library' ),
						$actor_link,
						esc_html( $game['name'] ),
						esc_html( Statuses::label( $entry['status_from'] ) ),
						esc_html( Statuses::label( $entry['status_to'] ) )
					);
				}

				// A corrupt/orphaned row (e.g. a followed member's account or
				// referenced game no longer resolves) renders nothing rather
				// than a broken sentence — matches game-card.php's own
				// `if ( null === $game ) { continue; }` precedent.
				if ( '' === $sentence ) {
					continue;
				}

				$is_first_row = 0 === $row_index;
				++$row_index;
				?>
				<li class="gl-activity-row">
					<span class="gl-activity-row__avatar">
						<?php
						/**
						 * Same redundant-adjacent-link fix as members.php's own
						 * avatar link — this actor avatar links to the exact same
						 * URL as the named `$actor_link` embedded in the sentence
						 * below, so it is hidden from assistive tech entirely
						 * rather than given a duplicate accessible name.
						 */
						?>
						<a href="<?php echo esc_url( $actor_url ); ?>" aria-hidden="true" tabindex="-1">
							<?php
							// DES-36: 40, matching --gl-avatar-md — this row
							// requested 48 against a 40px CSS box. PF-2: the
							// first row's actor avatar loads eagerly, same
							// $is_first_row this file's own game-cover
							// already uses (PF-5) — left lazy here even
							// though it renders in the same always-in-viewport
							// first row.
							echo get_avatar( $actor->ID, 40, '', '', array( 'loading' => $is_first_row ? 'eager' : 'lazy' ) );
							?>
						</a>
					</span>
					<div class="gl-activity-row__content">
						<p class="gl-activity-row__sentence"><?php echo wp_kses_post( $sentence ); ?></p>

						<?php if ( $game ) : ?>
							<?php
							$game_url = Router::game_url( $game['slug'] );
							// PF-3: 'thumb' — this box renders at
							// --gl-avatar-lg (64px), not the 'big' size's
							// card/hero dimensions.
							$cover_url = $mapper->cover_url( $game['cover_image_id'], 'thumb' );
							?>
							<div class="gl-activity-row__game">
								<?php
								/**
								 * Same redundant-adjacent-link fix as the actor avatar
								 * above — this cover link (with or without an <img>
								 * inside it) shares its href with the named game-title
								 * link right after it, so it is hidden from assistive
								 * tech entirely rather than given a duplicate
								 * accessible name.
								 */
								?>
								<a class="gl-activity-row__game-cover" href="<?php echo esc_url( $game_url ); ?>" aria-hidden="true" tabindex="-1">
									<?php if ( $cover_url ) : ?>
										<?php if ( $is_first_row ) : ?>
											<?php // PF-5: the first rendered row is always in the initial viewport — no loading="lazy" (a lazy in-viewport image is invisible to the preload scanner). No fetchpriority="high" either: a 64px thumbnail is not this route's LCP element. ?>
											<img src="<?php echo esc_url( $cover_url ); ?>" alt="" decoding="async" />
										<?php else : ?>
											<img src="<?php echo esc_url( $cover_url ); ?>" alt="" loading="lazy" decoding="async" />
										<?php endif; ?>
									<?php endif; ?>
								</a>
								<a href="<?php echo esc_url( $game_url ); ?>"><?php echo esc_html( $game['name'] ); ?></a>
								<?php if ( $entry['status_to'] ) : ?>
									<?php
									$badge_status = $entry['status_to'];
									include $partials_dir . 'status-badge.php';
									?>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<time class="gl-activity-row__time" datetime="<?php echo esc_attr( gmdate( 'c', $timestamp ) ); ?>">
							<?php
							printf(
								/* translators: %s: human-readable time difference, e.g. "2 hours". */
								esc_html__( '%s ago', 'game-library' ),
								esc_html( human_time_diff( $timestamp ) )
							);
							?>
						</time>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php
	// DES-21/DES-22: passes explicit previous/next URLs rather than
	// duplicating the <nav> markup a third time — the duplication is what
	// let this template's own copy of the DES-12(b) empty-pagination guard
	// go missing in cycle-1 while partials/pagination.php's and invites.php's
	// copies were fixed. PB-3 (cycle-8): next/previous are now derived from
	// $total_pages (read above, before get_feed()), not a
	// count( $entries ) === $page_size heuristic.
	$pagination_previous_url = 1 < $current_page ? add_query_arg( 'gl_page', $current_page - 1, $base_url ) : '';
	$pagination_next_url     = $current_page < $total_pages ? add_query_arg( 'gl_page', $current_page + 1, $base_url ) : '';
	$pagination_status_text  = sprintf(
		/* translators: %d: current page number. */
		__( 'Page %d', 'game-library' ),
		$current_page
	);
	include $partials_dir . 'pagination.php';
	?>

	<?php include $partials_dir . 'attribution.php'; ?>

</main>

<?php
include $partials_dir . 'site-footer.php';
