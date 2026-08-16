<?php
/**
 * `/members/` — the member directory (AC-018).
 *
 * Rendered by `Router` (Task 10) via `template_include`, or by a theme's own
 * override at `game-library/members.php` (`locate_template()`, DD-008).
 * Access is already gated logged-in-only by `Router::enforce_access_gates()`
 * before this template is ever selected. This route enqueues `social.js`
 * (`Assets::ROUTE_SCRIPTS`, Task 4, this task) — the Follow/Unfollow control
 * below only renders the markup contract that script binds to (the exact same
 * `data-gl-follow-toggle`/`data-user-id`/`data-following` contract
 * `templates/member-library.php`, Task 13, already renders).
 *
 * Batching (this task's own constraint, mirroring
 * `Social_Controller::get_members()`'s already-established pattern, Task 12):
 * one `WP_User_Query` for the page's member ids, one `cache_users()` call to
 * prime every row's user/usermeta object cache, one
 * `Library_Repository::counts_for_users()` call for every row's entry count,
 * and one `Follow_Repository::is_following_map()` call for every row's follow
 * state — never a per-row `get_userdata()`, `get_user_meta()`, or repository
 * call inside the loop below (AC-NFR-005).
 *
 * MR-2: this template does not itself 404 an out-of-range `gl_page`. It is
 * an authenticated-only route (never anonymously cached or crawled), and
 * `Member_Directory::member_ids()` already carries its own offset ceiling
 * against `member_count()` (MR-2's data-layer fix), so an out-of-range page
 * renders this route's own empty state rather than running the leading-
 * wildcard capability query or writing a dead cache entry.
 *
 * @package Game_Library
 */

use Game_Library\Data\Follow_Repository;
use Game_Library\Data\Library_Repository;
use Game_Library\Data\Member_Directory;
use Game_Library\Router;
use Game_Library\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$partials_dir = __DIR__ . '/partials/';
$page_size    = 24;
$viewer_id    = get_current_user_id();

// $current_page avoids the reserved $page WP global name
// (WordPress.WP.GlobalVariablesOverride) — reads the plugin-owned `gl_page`
// query var, never `paged` — see templates/partials/pagination.php's own
// docblock for why.
$current_page = max( 1, absint( get_query_var( 'gl_page' ) ) );

// MR-4: Member_Directory caches both the id list and the total — this was
// previously an uncached WP_User_Query on every request, the heaviest shape
// that API can produce (a leading-wildcard capability meta_value LIKE plus
// SQL_CALC_FOUND_ROWS).
$member_directory = new Member_Directory();
$member_ids       = $member_directory->member_ids( $current_page, $page_size );

$counts    = array();
$following = array();

if ( ! empty( $member_ids ) ) {
	// One call primes every member row's user/usermeta object cache — never
	// one get_userdata()/get_user_meta() per row (this task's own batching
	// constraint).
	cache_users( $member_ids );

	$library_repo = new Library_Repository();
	$follow_repo  = new Follow_Repository();

	$counts    = $library_repo->counts_for_users( $member_ids );
	$following = $follow_repo->is_following_map( $viewer_id, $member_ids );
}

$total    = $member_directory->member_count();
$base_url = Router::members_url();

/**
 * Formats a member's library entry count as a translated "%d game(s)" phrase
 * — the same wording `templates/my-library.php`/`templates/member-library.php`
 * (Task 13) already use for the same underlying figure, reused here rather
 * than inventing new copy for the same count. This is also this task's own
 * "use _n() for the invites-remaining and entry-count strings" constraint —
 * see the Task 14 coder decision log for why "follower-count" in that
 * constraint's wording is read as this per-member entry count: no batched
 * per-page follower-count method exists on `Follow_Repository` (only a
 * single-id `follower_count()`, explicitly not consumed by any route as of
 * Task 12), and AC-018 itself enumerates only an entry count, never a
 * follower count, among this route's five required elements.
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

<main id="gl-main" class="gl-container gl-members">

	<h1><?php esc_html_e( 'Members', 'game-library' ); ?></h1>

	<?php if ( empty( $member_ids ) ) : ?>
		<p class="gl-empty-state"><?php esc_html_e( 'No members found.', 'game-library' ); ?></p>
	<?php else : ?>
		<ul class="gl-member-list" id="gl-member-list">
			<?php
			// $row_index/$is_first_row (PF-2): the first rendered row is
			// always in the initial viewport — matching activity.php's own
			// PF-5 precedent for its game-cover thumbnails, extended here to
			// the actor avatar PF-5 itself left lazy.
			$row_index = 0;

			foreach ( $member_ids as $member_id ) :
				$member = get_userdata( $member_id );

				if ( ! $member ) {
					continue;
				}

				// A viewer cannot follow themselves (AC-019) — omit the
				// control entirely on their own row rather than render a
				// "Follow" button that would always fail, matching
				// member-library.php's own $is_self precedent (Task 13).
				$is_self       = $viewer_id === $member_id;
				$is_public     = Visibility::is_public( $member_id );
				$is_following  = isset( $following[ $member_id ] ) ? $following[ $member_id ] : false;
				$entry_count   = isset( $counts[ $member_id ] ) ? $counts[ $member_id ] : 0;
				$library_url   = Router::member_library_url( $member->user_nicename );
				$is_first_row  = 0 === $row_index;
				++$row_index;
				?>
				<li class="gl-member-row" id="gl-member-<?php echo esc_attr( (string) $member_id ); ?>" data-user-id="<?php echo esc_attr( (string) $member_id ); ?>">
					<span class="gl-member-row__avatar">
						<?php
						/**
						 * The avatar image has no accessible name of its own
						 * (`get_avatar()`'s default `alt=""`), and this link is
						 * immediately followed by a second link to the exact same
						 * URL whose text is the member's name
						 * (`.gl-member-row__name`). Rather than duplicate that
						 * name into a second accessible link, this redundant
						 * avatar link is hidden from assistive tech entirely
						 * (`aria-hidden` + `tabindex="-1"`, the standard
						 * "redundant adjacent link" pattern) — a sighted mouse
						 * user can still click the avatar; a keyboard/screen
						 * reader user reaches the same destination via the named
						 * link right after it.
						 */
						?>
						<a href="<?php echo esc_url( $library_url ); ?>" aria-hidden="true" tabindex="-1">
							<?php
							// DES-36: 40, matching --gl-avatar-md — this row
							// requested 48 against a 40px CSS box. PF-2: the
							// first row's avatar loads eagerly, matching
							// activity.php's own first-row cover precedent —
							// a lazy in-viewport image is invisible to the
							// preload scanner.
							echo get_avatar( $member_id, 40, '', '', array( 'loading' => $is_first_row ? 'eager' : 'lazy' ) );
							?>
						</a>
					</span>
					<div class="gl-member-row__identity">
						<span class="gl-member-row__name">
							<a href="<?php echo esc_url( $library_url ); ?>"><?php echo esc_html( $member->display_name ); ?></a>
						</span>
						<span class="gl-member-row__meta"><?php echo esc_html( $count_label( $entry_count ) ); ?></span>
						<?php if ( $is_public ) : ?>
							<span class="gl-member-row__public-marker"><?php esc_html_e( 'Public library', 'game-library' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="gl-member-row__actions">
						<?php if ( ! $is_self ) : ?>
							<button
								type="button"
								class="gl-button gl-button--secondary"
								data-gl-follow-toggle
								data-user-id="<?php echo esc_attr( (string) $member_id ); ?>"
								data-following="<?php echo esc_attr( $is_following ? '1' : '0' ); ?>"
							>
								<?php echo esc_html( $is_following ? __( 'Unfollow', 'game-library' ) : __( 'Follow', 'game-library' ) ); ?>
							</button>
							<?php // MR-4: pre-rendered, not lazily created by social.js — carries role="alert" so a screen reader announces a failed follow/unfollow, which a JS-created element never got. ?>
							<p class="gl-error-text" data-gl-follow-error role="alert"></p>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php
	$pagination_current_page = $current_page;
	$pagination_total_pages  = max( 1, (int) ceil( $total / $page_size ) );
	$pagination_base_url     = $base_url;
	include $partials_dir . 'pagination.php';
	?>

</main>

<?php
include $partials_dir . 'site-footer.php';
