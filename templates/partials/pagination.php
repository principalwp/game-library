<?php
/**
 * Previous/next pagination controls (AC-017, widened by DES-22 cycle-2).
 *
 * Two calling conventions, chosen by which variables the caller sets:
 *
 * (a) `$pagination_previous_url` / `$pagination_next_url` — string URLs, or
 *     `''` for "no such page" — plus an optional, already-translated
 *     `$pagination_status_text`. Used by a caller with no cheap total-page
 *     count (`activity.php`, `invites.php` — `Activity_Repository`/
 *     `Invite_Repository` expose only a bounded page, never a total) or a
 *     path-based, not query-string, page URL scheme (`game-catalog.php`'s
 *     `/games/page/{n}/`, which this partial's own `add_query_arg(
 *     'gl_page', … )` derivation cannot build).
 * (b) `$pagination_current_page` / `$pagination_total_pages` /
 *     `$pagination_base_url` — the original `?gl_page=N`-based derivation,
 *     still used by every other caller (`game-single.php`,
 *     `member-library.php`).
 *
 * Renders nothing when there is only one page either way: convention (a)
 * when both URLs resolve empty, convention (b) when `$pagination_total_pages`
 * resolves to 1. This single "render nothing" guard is why DES-21(a)'s
 * missing per-template guard (`activity.php`'s own inline `<nav>` rendered
 * unconditionally, showing two dead Previous/Next controls on a
 * single-page feed) cannot recur once a caller is pointed at this partial
 * instead of its own copy of the markup.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( isset( $pagination_previous_url, $pagination_next_url ) ) {
	$pagination_previous_url = (string) $pagination_previous_url;
	$pagination_next_url     = (string) $pagination_next_url;

	if ( '' === $pagination_previous_url && '' === $pagination_next_url ) {
		// CO-3 (cycle-5): unset before returning, matching the closing
		// unset() at the bottom of this file — a bare `return` here left
		// convention (b)'s three names ($pagination_current_page,
		// $pagination_total_pages, $pagination_base_url) unset (never a
		// problem, they were never set on this branch) but also left THESE
		// two names set to '' in global scope. A later convention-(b)
		// include in the same request would then hit the isset() branch
		// above, see two empty-string URLs, and silently return with no
		// pagination rendered at all — exactly the failure mode CO-8's own
		// comment below describes, just triggered from the single-page
		// case instead of a literal double-include.
		unset( $pagination_previous_url, $pagination_next_url, $pagination_status_text, $pagination_current_page, $pagination_total_pages, $pagination_base_url );
		return;
	}

	$pagination_status_text = isset( $pagination_status_text ) ? (string) $pagination_status_text : '';
} else {
	$pagination_current_page = isset( $pagination_current_page ) ? max( 1, (int) $pagination_current_page ) : 1;
	$pagination_total_pages  = isset( $pagination_total_pages ) ? max( 1, (int) $pagination_total_pages ) : 1;
	$pagination_base_url     = isset( $pagination_base_url ) ? (string) $pagination_base_url : '';

	if ( $pagination_total_pages <= 1 ) {
		// CO-3 (cycle-5): see the matching comment on convention (a)'s own
		// early return above — this unset() previously left convention
		// (b)'s three names (plus the never-computed
		// $pagination_previous_url/$pagination_next_url, absent here since
		// this branch returns before they are ever set) unset() only via
		// the bottom-of-file call, which never ran for this early return.
		unset( $pagination_previous_url, $pagination_next_url, $pagination_status_text, $pagination_current_page, $pagination_total_pages, $pagination_base_url );
		return;
	}

	// `gl_page`, never `paged` — `paged` is one of WordPress core's own public
	// query vars and feeds the *main* query, 404-ing the whole request once the
	// site's own default post count can't support the requested page, regardless
	// of this route's own content (confirmed at runtime; same class of bug
	// `Router`'s `/games/page/(N)/` rule already avoids for its own
	// `gl_page`-via-rewrite-rule case, ADR-008/Task 15). `gl_page` is whitelisted
	// as a public query var by `Router::register_query_vars()`, so it merges into
	// `get_query_var( 'gl_page' )` from a plain query string too, without feeding
	// `WP_Query`'s own pagination logic.
	$pagination_previous_url = 1 < $pagination_current_page ? add_query_arg( 'gl_page', $pagination_current_page - 1, $pagination_base_url ) : '';
	$pagination_next_url     = $pagination_current_page < $pagination_total_pages ? add_query_arg( 'gl_page', $pagination_current_page + 1, $pagination_base_url ) : '';

	$pagination_status_text = sprintf(
		/* translators: 1: current page number, 2: total number of pages. */
		__( 'Page %1$d of %2$d', 'game-library' ),
		$pagination_current_page,
		$pagination_total_pages
	);
}
?>
<nav class="gl-pagination" aria-label="<?php esc_attr_e( 'Pagination', 'game-library' ); ?>">
	<?php if ( $pagination_previous_url ) : ?>
		<a class="gl-pagination__link" href="<?php echo esc_url( $pagination_previous_url ); ?>"><?php esc_html_e( 'Previous', 'game-library' ); ?></a>
	<?php else : ?>
		<span class="gl-pagination__link" aria-disabled="true"><?php esc_html_e( 'Previous', 'game-library' ); ?></span>
	<?php endif; ?>

	<span class="gl-pagination__status">
		<?php echo esc_html( $pagination_status_text ); ?>
	</span>

	<?php if ( $pagination_next_url ) : ?>
		<a class="gl-pagination__link" href="<?php echo esc_url( $pagination_next_url ); ?>"><?php esc_html_e( 'Next', 'game-library' ); ?></a>
	<?php else : ?>
		<span class="gl-pagination__link" aria-disabled="true"><?php esc_html_e( 'Next', 'game-library' ); ?></span>
	<?php endif; ?>
</nav>
<?php
// CO-8 (cycle-3): templates run in PHP's global scope (this plugin's own
// convention, documented in CLAUDE.md), and `include` shares that scope —
// without this, $pagination_previous_url/$pagination_next_url/
// $pagination_status_text would persist past this include. A second
// pagination render later in the same request would then take convention
// (a) above (isset() on the leftover vars) regardless of which convention
// it actually intended, rendering the FIRST listing's stale URLs. No live
// call site includes this partial twice in one request today (verified by
// grepping every include site), but the next one that does would hit this
// silently, with no error.
//
// CO-3 (cycle-5): widened to all six calling-convention names (not just
// convention (a)'s three) — convention (b)'s own
// $pagination_current_page/$pagination_total_pages/$pagination_base_url
// leaked past this include exactly the same way before this fix, just
// with no observable symptom yet since nothing downstream happened to
// read them via isset() the way convention (a)'s three names are read.
unset( $pagination_previous_url, $pagination_next_url, $pagination_status_text, $pagination_current_page, $pagination_total_pages, $pagination_base_url );
