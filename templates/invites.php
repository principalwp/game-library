<?php
/**
 * `/invites/` — the member's own invite screen (AC-024, AC-026).
 *
 * Rendered by `Router` (Task 10) via `template_include`, or by a theme's own
 * override at `game-library/invites.php` (`locate_template()`, DD-008).
 * Access is already gated logged-in-only by `Router::enforce_access_gates()`
 * before this template is ever selected.
 *
 * Renders the quota summary and the member's own existing invites
 * server-side, from `Invite_Service`/`Invite_Repository` only — no `$wpdb`
 * call and no unbounded query happens in this file. Creating an invite,
 * revoking one, and copying a redemption URL are all handled by
 * `assets/js/invites.js` (this task) against `Invite_Controller`'s REST
 * routes (this task) — this template only renders the initial page and the
 * markup contract that script binds to
 * (`#gl-invite-form`/`#gl-invite-list`/`[data-gl-revoke-invite]`/
 * `[data-gl-copy-invite]`).
 *
 * No attribution partial here (unlike my-library.php/member-library.php/
 * activity.php/games.php/game-single.php): this screen renders no cached
 * IGDB metadata at all (AC-046 only requires attribution on surfaces that do).
 *
 * PB-3 (cycle-8): `Invite_Repository::count_for_inviter()` now exists —
 * pagination below reads the total first (mirroring
 * `templates/member-library.php`'s own pattern), 404s an out-of-range
 * `gl_page`, and derives previous/next from `$total_pages` rather than a
 * `count( $invites ) === $page_size` heuristic. Before this, an unbounded
 * `?gl_page=` also had `get_for_inviter()` write its own hour-TTL cache
 * entry for every offset requested, with no ceiling.
 *
 * @package Game_Library
 */

use Game_Library\Data\Invite_Repository;
use Game_Library\Dates;
use Game_Library\Invites\Invite_Service;
use Game_Library\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$page_size = 20;
$user_id   = get_current_user_id();

// $current_page avoids the reserved $page WP global name
// (WordPress.WP.GlobalVariablesOverride). Pagination reads the plugin-owned
// `gl_page` query var, never `paged` — see
// templates/partials/pagination.php's own docblock for why.
$current_page = max( 1, absint( get_query_var( 'gl_page' ) ) );
$offset       = ( $current_page - 1 ) * $page_size;

$repository = new Invite_Repository();
$service    = new Invite_Service( $repository );

$quota = $service->quota_status( $user_id );

// PB-3 (cycle-8): total is read BEFORE get_for_inviter(), so an
// out-of-range page can 404 (mirroring templates/member-library.php's own
// MR-2 pattern) without ever reaching get_for_inviter()'s query.
// count_for_inviter() is cached, keyed to match get_for_inviter()'s own
// key shape, so this costs nothing extra on the common (in-range) case.
$total       = $repository->count_for_inviter( $user_id );
$total_pages = max( 1, (int) ceil( $total / $page_size ) );

if ( $current_page > $total_pages ) {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );

	include __DIR__ . '/partials/site-header.php';
	?>
	<main id="gl-main" class="gl-container">
		<p><?php esc_html_e( 'Page not found.', 'game-library' ); ?></p>
	</main>
	<?php
	include __DIR__ . '/partials/site-footer.php';
	return;
}

$invites = $repository->get_for_inviter( $user_id, $page_size, $offset );

$base_url = Router::invites_url();

$status_labels = array(
	'pending'  => __( 'Pending', 'game-library' ),
	'redeemed' => __( 'Redeemed', 'game-library' ),
	'expired'  => __( 'Expired', 'game-library' ),
	'revoked'  => __( 'Revoked', 'game-library' ),
);

$remaining_label = sprintf(
	/* translators: %d: number of invites remaining. */
	_n( '%d invite remaining.', '%d invites remaining.', $quota['remaining'], 'game-library' ),
	$quota['remaining']
);

$reset_label = $quota['reset_date'] ? sprintf(
	/* translators: %s: the date the invite quota next frees up. */
	__( 'Quota resets %s.', 'game-library' ),
	$quota['reset_date']
) : '';

include __DIR__ . '/partials/site-header.php';
?>

<main id="gl-main" class="gl-container gl-invites">

	<h1><?php esc_html_e( 'Invites', 'game-library' ); ?></h1>

	<div class="gl-notice gl-notice--info" id="gl-invite-quota">
		<p id="gl-invite-quota-remaining">
			<?php echo esc_html( $remaining_label ); ?>
			<?php if ( '' !== $reset_label ) : ?>
				<span id="gl-invite-quota-reset"><?php echo esc_html( $reset_label ); ?></span>
			<?php endif; ?>
		</p>
	</div>

	<?php // DES-28/DES-29: no [hidden] attribute and no data-gl-visible toggle — both notices stay in flow, always rendered, sharing ONE reserved-height slot (.gl-invite-form-notices); assets/js/invites.js only ever writes textContent, and :empty (game-library.css) hides an empty notice's chrome while keeping both role="alert"/role="status" live regions in the accessibility tree at all times. ?>
	<div class="gl-invite-form-notices">
		<div class="gl-notice gl-notice--error gl-invite-form-notice" id="gl-invite-form-error" role="alert"></div>
		<div class="gl-notice gl-notice--success gl-invite-form-notice" id="gl-invite-form-success" role="status"></div>
	</div>

	<form class="gl-invite-form" id="gl-invite-form" novalidate>
		<div class="gl-field" id="gl-invite-email-field" data-invalid="false">
			<label for="gl-invite-email"><?php esc_html_e( 'Recipient email (optional)', 'game-library' ); ?></label>
			<input type="email" id="gl-invite-email" name="email" autocomplete="off" />
			<p class="gl-error-text" id="gl-invite-email-error" hidden></p>
		</div>
		<button type="submit" class="gl-button gl-button--primary">
			<?php esc_html_e( 'Create invite', 'game-library' ); ?>
		</button>
	</form>

	<?php if ( empty( $invites ) ) : ?>
		<p class="gl-empty-state" id="gl-invites-empty"><?php esc_html_e( 'You have not created any invites yet.', 'game-library' ); ?></p>
	<?php endif; ?>

	<ul class="gl-invite-list" id="gl-invite-list">
		<?php
		foreach ( $invites as $invite ) :
			$redemption_url = Router::join_url( $invite['code'] );
			$status_label   = isset( $status_labels[ $invite['status'] ] ) ? $status_labels[ $invite['status'] ] : $invite['status'];
			?>
			<li class="gl-invite-row" id="gl-invite-<?php echo esc_attr( (string) $invite['id'] ); ?>" data-invite-id="<?php echo esc_attr( (string) $invite['id'] ); ?>" data-status="<?php echo esc_attr( $invite['status'] ); ?>">
				<div class="gl-invite-row__body">
					<p class="gl-invite-row__code"><?php echo esc_html( $invite['code'] ); ?></p>
					<p class="gl-invite-row__url">
						<a href="<?php echo esc_url( $redemption_url ); ?>"><?php echo esc_html( $redemption_url ); ?></a>
						<button
							type="button"
							class="gl-button gl-button--secondary"
							data-gl-copy-invite
							data-copy-value="<?php echo esc_attr( $redemption_url ); ?>"
						>
							<?php esc_html_e( 'Copy link', 'game-library' ); ?>
						</button>
						<span class="gl-invite-row__copied" data-gl-copy-confirmation hidden><?php esc_html_e( 'Copied!', 'game-library' ); ?></span>
					</p>
					<p class="gl-invite-row__meta">
						<span class="gl-invite-row__status" data-gl-invite-status><?php echo esc_html( $status_label ); ?></span>
						<?php
						// DES-24: matches the middot separator already between the
						// created/expires dates below, so the status reads as its
						// own separate token instead of running directly into them.
						echo '· ';
						printf(
							/* translators: 1: created date, 2: expiry date. */
							esc_html__( 'Created %1$s · Expires %2$s', 'game-library' ),
							esc_html( Dates::format( $invite['date_created'] ) ),
							esc_html( Dates::format( $invite['date_expires'] ) )
						);
						?>
					</p>
				</div>
				<div class="gl-invite-row__actions">
					<?php if ( 'pending' === $invite['status'] ) : ?>
						<button
							type="button"
							class="gl-button gl-button--danger"
							data-gl-revoke-invite
							data-invite-id="<?php echo esc_attr( (string) $invite['id'] ); ?>"
						>
							<?php esc_html_e( 'Revoke', 'game-library' ); ?>
						</button>
					<?php endif; ?>
					<p class="gl-error-text" data-gl-invite-row-error hidden></p>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php
	// DES-22: passes explicit previous/next URLs — same URL-based calling
	// convention as activity.php, instead of a fourth copy of the <nav>
	// markup. PB-3 (cycle-8): next/previous are now derived from
	// $total_pages (read above, before get_for_inviter()), not a
	// count( $invites ) === $page_size heuristic.
	$pagination_previous_url = 1 < $current_page ? add_query_arg( 'gl_page', $current_page - 1, $base_url ) : '';
	$pagination_next_url     = $current_page < $total_pages ? add_query_arg( 'gl_page', $current_page + 1, $base_url ) : '';
	$pagination_status_text  = sprintf(
		/* translators: %d: current page number. */
		__( 'Page %d', 'game-library' ),
		$current_page
	);
	include __DIR__ . '/partials/pagination.php';
	?>

</main>

<?php
include __DIR__ . '/partials/site-footer.php';
