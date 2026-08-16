<?php
/**
 * Route template: `/my-library/`.
 *
 * Reached only by an authenticated member — the logged-out redirect and the
 * capability gate both run in `GameLib_Router::dispatch()` before this file is
 * chosen (AC-021), so nothing here re-checks them.
 *
 * The two container ids below are part of the instrumentation contract
 * (AC-NFR-009 r,s): they are stable across fragment swaps and safe to key on.
 *
 * Three things shape the markup:
 *
 * 1. **The first paint is the whole read-only view.** The grid is rendered
 *    server-side from `GameLib_Library::query()` through the same `game-card`
 *    part every REST fragment uses, so a member without JavaScript still reads
 *    their library, their filters' initial state, and where they are in it
 *    (D-REQ-52 removes only the *controls* from that promise). No outbound HTTP
 *    happens here — the grid reads the local cache table only (AC-013).
 * 2. **Every control is server-rendered, once.** The bundle binds behavior to
 *    this markup and swaps in fragments PHP produced; it composes no markup and
 *    authors no member-facing sentence (ADR-002). The two strings no response
 *    can supply — the offline failure and the search-in-flight label — are
 *    authored here and read off the DOM.
 * 3. **State that has nothing to show is `hidden`, not absent.** An empty
 *    library shows its empty state with no filters, sort, or pager around it
 *    (AC-020), but those controls exist in the document so the bundle can
 *    reveal them the moment the first game lands, without composing markup.
 *    The same applies to the account panel's copy-link control (AC-050): it is
 *    shown only while the member's own profile is public, and a toggle that
 *    flips without a reload needs the control present-but-hidden rather than
 *    conjured by JavaScript. Nothing here is another member's surface — this
 *    route is the owner's own account — so nothing hidden is a disclosure.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_user_id = get_current_user_id();

// The default view: everything, newest first, page 1 — the same criteria the
// REST route falls back to, so the first paint and the first refresh agree.
$gamelib_view = GameLib_Library::query( $gamelib_user_id );

$gamelib_total = (int) $gamelib_view['total'];
$gamelib_pages = (int) $gamelib_view['pages'];
$gamelib_page  = (int) $gamelib_view['page'];

// AC-020 vs AC-017: an unfiltered first paint with nothing in it is an empty
// library, and an empty library has nothing to filter, sort, or page through.
$gamelib_has_entries = $gamelib_total > 0;

$gamelib_status_labels = array(
	'playing'  => _x( 'Playing', 'library status', 'game-library' ),
	'finished' => _x( 'Finished', 'library status', 'game-library' ),
	'backlog'  => _x( 'Backlog', 'library status', 'game-library' ),
	'wishlist' => _x( 'Wishlist', 'library status', 'game-library' ),
);

// AC-017(a): All plus the four statuses, in that order.
$gamelib_filters = array_merge(
	array( GameLib_Library::STATUS_ALL => _x( 'All', 'library status filter', 'game-library' ) ),
	$gamelib_status_labels
);

// AC-017(b): the four orders, default first.
$gamelib_sorts = array(
	'added_desc' => __( 'Date added — newest first', 'game-library' ),
	'added_asc'  => __( 'Date added — oldest first', 'game-library' ),
	'title_asc'  => __( 'Title A–Z', 'game-library' ),
	'title_desc' => __( 'Title Z–A', 'game-library' ),
);

/*
 * AC-019(c): the bulk-remove confirmation, in both plural forms, with the count
 * left as a placeholder. `_n()` is asked for the form of 1 and the form of 2
 * because the count is a client-side fact — a member's ticked cards, or the
 * total the last list response reported — and the bundle imports no i18n
 * runtime (ADR-002). Locales with more than two plural forms get the
 * count-of-2 form for every count above one; see the coder decision log.
 */
/* translators: %s: number of games about to be removed. */
$gamelib_bulk_confirm_one = _n( 'Remove %s game from your library? This cannot be undone.', 'Remove %s games from your library? This cannot be undone.', 1, 'game-library' );

/* translators: %s: number of games about to be removed. */
$gamelib_bulk_confirm_other = _n( 'Remove %s game from your library? This cannot be undone.', 'Remove %s games from your library? This cannot be undone.', 2, 'game-library' );

/*
 * The same two-form treatment for the allowance sentence the bundle rewrites
 * after `POST /invites`. The *server* rendering below still calls `_n()` with
 * the real count, so a first paint is correct in every locale; only the
 * client-side update is limited to two forms.
 */
/* translators: %s: number of invites the member may still create. */
$gamelib_allowance_one = _n( 'You have %s invite left.', 'You have %s invites left.', 1, 'game-library' );

/* translators: %s: number of invites the member may still create. */
$gamelib_allowance_other = _n( 'You have %s invite left.', 'You have %s invites left.', 2, 'game-library' );

/*
 * The account panel (AC-001, AC-027, AC-030, AC-037, AC-046, AC-050). Every
 * value below is read once, here, and rendered server-side; the bundle changes
 * these blocks only by writing values the REST responses hand it back.
 */
$gamelib_visibility  = GameLib_Visibility::get( $gamelib_user_id );
$gamelib_is_public   = ( GameLib_Visibility::PUBLIC_PROFILE === $gamelib_visibility );
$gamelib_profile_url = GameLib_Visibility::profile_url( $gamelib_user_id );

// AC-030: the owner's own profile as a logged-out visitor sees it — for a
// members-only profile that is the 404, which is the point of the preview.
$gamelib_preview_url = ( '' === $gamelib_profile_url )
	? ''
	: add_query_arg( GameLib_Router::QV_PREVIEW, GameLib_Router::PREVIEW_VISITOR, $gamelib_profile_url );

$gamelib_visibility_options = array(
	GameLib_Visibility::MEMBERS_ONLY   => GameLib_Visibility::visibility_label( GameLib_Visibility::MEMBERS_ONLY ),
	GameLib_Visibility::PUBLIC_PROFILE => GameLib_Visibility::visibility_label( GameLib_Visibility::PUBLIC_PROFILE ),
);

$gamelib_can_invite = current_user_can( GameLib_Capabilities::CAP_ISSUE_INVITES );
$gamelib_allowance  = GameLib_Invites::allowance( $gamelib_user_id );
$gamelib_invites    = GameLib_Invites::list_for_user( $gamelib_user_id );

$gamelib_invite_statuses = array(
	GameLib_Invites::STATUS_OUTSTANDING => _x( 'Unused', 'invite status', 'game-library' ),
	GameLib_Invites::STATUS_REDEEMED    => _x( 'Redeemed', 'invite status', 'game-library' ),
	GameLib_Invites::STATUS_REVOKED     => _x( 'Revoked', 'invite status', 'game-library' ),
);

$gamelib_steamid = GameLib_Steam_Client::sanitize_steamid64(
	get_user_meta( $gamelib_user_id, GameLib_Steam_Client::STEAMID_META, true )
);

/**
 * The member-facing sentence for an invite allowance (AC-001, AC-002c).
 *
 * @param array<string, mixed> $allowance Return value of {@see GameLib_Invites::allowance()}.
 * @return string Translated sentence.
 */
$gamelib_allowance_sentence = static function ( array $allowance ) {
	if ( ! empty( $allowance['disabled'] ) ) {
		return __( 'Invite creation is switched off for your account.', 'game-library' );
	}

	if ( ! empty( $allowance['unlimited'] ) ) {
		return __( 'You can create as many invites as you need.', 'game-library' );
	}

	$gamelib_remaining = (int) $allowance['remaining'];

	if ( $gamelib_remaining < 1 ) {
		return __( 'You have used every invite in your allowance.', 'game-library' );
	}

	return sprintf(
		/* translators: %s: number of invites the member may still create. */
		_n( 'You have %s invite left.', 'You have %s invites left.', $gamelib_remaining, 'game-library' ),
		number_format_i18n( $gamelib_remaining )
	);
};

/**
 * One row of the member's own invite list.
 *
 * Called twice: once per stored invite, and once with an empty URL to fill the
 * `<template>` the bundle clones when a new invite is minted. Rendering both
 * from the same closure is what keeps `POST /invites` — which answers JSON only
 * (principal/adr/014-invite-routes-return-json-only.md) — from forcing the
 * bundle to author markup or copy: it clones this row and writes the code's URL
 * into it.
 *
 * @param string                $url      Shareable `/join/{code}/` URL; '' in the template.
 * @param string                $status   Invite status.
 * @param array<string, string> $statuses Status label map.
 * @return void
 */
$gamelib_invite_row = static function ( $url, $status, array $statuses ) {
	$gamelib_outstanding = ( GameLib_Invites::STATUS_OUTSTANDING === $status );

	?>
	<li class="gamelib-invite" data-gamelib-invite>
		<span class="gamelib-invite__url" data-gamelib-invite-url><?php echo esc_html( $url ); ?></span>
		<span class="gamelib-invite__status"><?php echo esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status ); ?></span>
		<?php if ( $gamelib_outstanding ) : ?>
			<button
				type="button"
				class="gamelib-control gamelib-invite__copy"
				data-gamelib-action="share.copy"
				data-gamelib-url="<?php echo esc_url( $url ); ?>"
				data-gamelib-share-done="<?php echo esc_attr__( 'Invite link copied.', 'game-library' ); ?>"
				data-gamelib-share-error="<?php echo esc_attr__( 'Copying is not available here — select the link and copy it yourself.', 'game-library' ); ?>"
			>
				<?php esc_html_e( 'Copy invite link', 'game-library' ); ?>
			</button>
			<span class="gamelib-share__feedback" data-gamelib-share-feedback role="status"></span>
		<?php endif; ?>
	</li>
	<?php
};

GameLib_Router::part( 'document-open', array( 'body_class' => 'gamelib-route-my-library' ) );

?>
<header class="gamelib__header">
	<h1 class="gamelib__title"><?php esc_html_e( 'My library', 'game-library' ); ?></h1>
</header>

<?php
/*
 * One polite live region for the whole route, deliberately outside every
 * element the bundle swaps: replacing a live region's own node is not reliably
 * announced (AC-NFR-004).
 */
?>
<div class="gamelib-visually-hidden" data-gamelib-live role="status" aria-live="polite"></div>

<section class="gamelib__section" aria-labelledby="gamelib-search-title">
	<h2 id="gamelib-search-title" class="gamelib__section-title"><?php esc_html_e( 'Add a game', 'game-library' ); ?></h2>
	<div
		id="gamelib-search"
		class="gamelib-search"
		data-gamelib-error="<?php echo esc_attr__( 'That did not go through. Check your connection and try again.', 'game-library' ); ?>"
	>
		<?php
		/*
		 * No <form> element: the controls are JavaScript-driven by decision
		 * (D-REQ-52) and a form would advertise a post fallback that does not
		 * exist (Never Do #12). Enter in the field runs the search.
		 */
		?>
		<div class="gamelib-search__field">
			<label class="gamelib-search__label" for="gamelib-search-input">
				<?php esc_html_e( 'Search for a game to add', 'game-library' ); ?>
			</label>
			<div class="gamelib-search__row">
				<input
					type="search"
					id="gamelib-search-input"
					class="gamelib-control gamelib-search__input"
					autocomplete="off"
					data-gamelib-action="library.search"
					data-gamelib-search-input
				/>
				<button
					type="button"
					class="gamelib-control gamelib-search__submit"
					data-gamelib-action="library.search"
					data-gamelib-search-submit
				>
					<?php esc_html_e( 'Search', 'game-library' ); ?>
				</button>
			</div>
		</div>

		<?php
		/*
		 * AC-008(c): the in-flight state. Hidden until a request is out, and
		 * animated only where the reader has not asked for less motion
		 * (AC-NFR-004d, handled in the stylesheet).
		 */
		?>
		<p class="gamelib-search__loading" data-gamelib-loading hidden>
			<span class="gamelib-shimmer gamelib-search__shimmer" aria-hidden="true"></span>
			<span class="gamelib-search__loading-text"><?php esc_html_e( 'Searching…', 'game-library' ); ?></span>
		</p>

		<?php
		/*
		 * The space a result set will occupy, reserved before the request goes
		 * out (PF-1).
		 *
		 * `[data-gamelib-results]` is a zero-height container sitting *above*
		 * the grid, and a response writes up to 20 rows into it — ~2,400px of
		 * page pushed down with nothing holding the space, which is a CLS delta
		 * of ~0.6–0.8 on one interaction. It escaped the metric only when the
		 * response landed inside the 500ms `hadRecentInput` window, and an
		 * uncached IGDB round trip is budgeted at 3s (AC-NFR-005).
		 *
		 * The bundle clones this template into the container synchronously,
		 * inside the click/Enter handler, so the reservation *is* inside that
		 * window and the real rows swap into space that is already held. It is
		 * authored here rather than composed in JS because ADR-002 makes PHP the
		 * plugin's only renderer; the rows are decorative and carry
		 * `aria-hidden`, since the in-flight state is already announced by the
		 * "Searching…" region above.
		 *
		 * A skeleton row composes exactly the classes a real one does — both
		 * columns of it (PF-2). The media span is
		 * `gamelib-result__media gamelib-card__media`, and the body is a
		 * `gamelib-result__body` holding the same four children
		 * `parts/search-result.php` renders: a title, two meta lines, and the add
		 * row. So the row *measures itself* from the tokens the real row measures
		 * itself from — `measure--thumb` and `cover-aspect` on the media side,
		 * the two font-size steps, `control--min` and `space--xs` on the body
		 * side — instead of being pinned to a hand-tuned height (PF-1/DES-1).
		 *
		 * Both halves of that have been wrong once. The media span used to carry
		 * `gamelib-result__media` alone, which is a flex basis with no
		 * aspect-ratio and therefore no height at all, so the stylesheet invented
		 * a number shorter than the box it stood in for; and the body used to be
		 * two bare shimmer bars, 12px each, while a real row is `display: flex`
		 * and takes the *taller* of its two columns — which is the body as soon
		 * as a title wraps, a platform list wraps, or the "In your library" line
		 * renders. Either way the swap the reservation exists to smooth still
		 * shifted, by 44–194px on a six-result response.
		 *
		 * The bars are `inline-block` decoration inside those wrappers, so what
		 * the row reserves is the wrapper's line box, not the bar's height.
		 *
		 * The container must stay free of whitespace between its tags: nothing
		 * keys on emptiness any more (DES-2), but a text node would still be a
		 * child the skeleton clone lands beside.
		 */
		?>
		<template data-gamelib-result-skeleton><ul class="gamelib-results" aria-hidden="true"><?php for ( $gamelib_skeleton_row = 0; $gamelib_skeleton_row < 6; $gamelib_skeleton_row++ ) : ?><li class="gamelib-result gamelib-result--pending"><span class="gamelib-shimmer gamelib-result__media gamelib-card__media"></span><span class="gamelib-result__body"><span class="gamelib-result__title"><span class="gamelib-shimmer gamelib-result__shimmer"></span></span><span class="gamelib-result__meta"><span class="gamelib-shimmer gamelib-result__shimmer gamelib-result__shimmer--meta"></span></span><span class="gamelib-result__meta"><span class="gamelib-shimmer gamelib-result__shimmer gamelib-result__shimmer--meta"></span></span><span class="gamelib-result__add"><span class="gamelib-shimmer gamelib-result__shimmer gamelib-result__shimmer--action"></span></span></span></li><?php endfor; ?></ul></template>

		<?php
		/*
		 * The failure that carries no fragment (PF-2/CO-2).
		 *
		 * Every AC-010 answer the plugin's own route composes ships `html`, so
		 * the bundle renders the server's paragraph. A failure that never
		 * reached the handler carries nothing to render: offline, an expired
		 * REST cookie nonce, `rest_no_route`, a 500 raised before the callback.
		 * Without this the bundle left the six-row skeleton standing — a
		 * surface that reads as loading forever, with thirty infinite shimmer
		 * animations behind it, and the only correction in a visually hidden
		 * live region.
		 *
		 * The sentence is the same catalog entry the route's own
		 * `search_unavailable` answer uses, so a member cannot tell the two
		 * apart and nothing is composed in JS (ADR-002). Free of whitespace
		 * inside the tags for the same reason the skeleton above is.
		 */
		?>
		<template data-gamelib-offline-state><?php
			GameLib_Router::part(
				'list-state',
				array(
					'state'   => GameLib_REST_Library::STATE_SEARCH_UNAVAILABLE,
					'tag'     => 'p',
					'tone'    => 'error',
					'message' => GameLib_REST_Library::state_message( GameLib_REST_Library::STATE_SEARCH_UNAVAILABLE ),
				)
			);
			?></template>

		<?php
		/*
		 * Results, the AC-009 empty state, and the AC-010 degraded states all
		 * arrive here as one server-rendered fragment.
		 */
		?>
		<div class="gamelib-search__results" data-gamelib-results></div>
	</div>
</section>

<section class="gamelib__section" aria-labelledby="gamelib-library-title">
	<h2 id="gamelib-library-title" class="gamelib__section-title" tabindex="-1"><?php esc_html_e( 'Your games', 'game-library' ); ?></h2>

	<div
		class="gamelib-library"
		data-gamelib-error="<?php echo esc_attr__( 'That did not go through. Check your connection and try again.', 'game-library' ); ?>"
	>
		<div class="gamelib-library__toolbar" data-gamelib-toolbar <?php echo esc_attr( $gamelib_has_entries ? '' : 'hidden' ); ?>>
			<div class="gamelib-chips" role="radiogroup" aria-label="<?php echo esc_attr__( 'Filter by status', 'game-library' ); ?>">
				<?php foreach ( $gamelib_filters as $gamelib_value => $gamelib_label ) : ?>
					<?php $gamelib_selected = ( $gamelib_value === $gamelib_view['status'] ); ?>
					<button
						type="button"
						role="radio"
						class="gamelib-control gamelib-chip"
						aria-checked="<?php echo $gamelib_selected ? 'true' : 'false'; ?>"
						tabindex="<?php echo $gamelib_selected ? '0' : '-1'; ?>"
						data-gamelib-action="library.filter"
						data-gamelib-status="<?php echo esc_attr( $gamelib_value ); ?>"
					>
						<?php echo esc_html( $gamelib_label ); ?>
					</button>
				<?php endforeach; ?>
			</div>

			<p class="gamelib-library__control">
				<label class="gamelib-library__label" for="gamelib-library-sort"><?php esc_html_e( 'Sort', 'game-library' ); ?></label>
				<select
					id="gamelib-library-sort"
					class="gamelib-control gamelib-library__select"
					data-gamelib-action="library.sort"
					data-gamelib-sort
				>
					<?php foreach ( $gamelib_sorts as $gamelib_value => $gamelib_label ) : ?>
						<option value="<?php echo esc_attr( $gamelib_value ); ?>" <?php selected( $gamelib_value, $gamelib_view['sort'] ); ?>>
							<?php echo esc_html( $gamelib_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="gamelib-library__control">
				<label class="gamelib-library__label" for="gamelib-library-search"><?php esc_html_e( 'Find in your library', 'game-library' ); ?></label>
				<input
					type="search"
					id="gamelib-library-search"
					class="gamelib-control gamelib-library__input"
					autocomplete="off"
					value="<?php echo esc_attr( (string) $gamelib_view['search'] ); ?>"
					data-gamelib-action="library.filter"
					data-gamelib-library-search
				/>
			</p>
		</div>

		<?php
		/*
		 * AC-019: bulk actions, scoped to the filter currently applied above.
		 *
		 * Three things are deliberate here. The `gamelib_bulk` nonce is rendered
		 * on the container, because the route verifies it in its permission
		 * callback in addition to the REST cookie nonce and a request without it
		 * is a 403 (AC-019d). "Select all matching this filter" is a mode, not an
		 * id list: the bundle sends `all: true` and the server re-derives the
		 * selection from the same criteria it rendered the grid with, so a
		 * thousand-entry filter is never enumerated client-side (AC-019f). And
		 * the remove confirmation's two plural forms are authored here, with the
		 * count as a placeholder the bundle fills — the count is only known once
		 * a member has ticked something, and the sentence still has to be
		 * translated in PHP (AC-019c, Always Do #11).
		 */
		?>
		<div
			class="gamelib-bulk"
			data-gamelib-bulk
			data-gamelib-bulk-nonce="<?php echo esc_attr( wp_create_nonce( GameLib_REST_Library::BULK_NONCE_ACTION ) ); ?>"
			data-gamelib-bulk-total="<?php echo esc_attr( (string) $gamelib_total ); ?>"
			<?php echo esc_attr( $gamelib_has_entries ? '' : 'hidden' ); ?>
		>
			<button
				type="button"
				class="gamelib-control gamelib-bulk__toggle"
				aria-pressed="false"
				aria-controls="gamelib-bulk-actions"
				data-gamelib-action="library.bulk-select"
				data-gamelib-bulk-toggle
			>
				<?php esc_html_e( 'Select games', 'game-library' ); ?>
			</button>

			<div class="gamelib-bulk__actions" id="gamelib-bulk-actions" data-gamelib-bulk-actions hidden>
				<p class="gamelib-bulk__row">
					<input
						type="checkbox"
						id="gamelib-bulk-all"
						class="gamelib-bulk__checkbox"
						data-gamelib-action="library.bulk-select"
						data-gamelib-bulk-all
					/>
					<label for="gamelib-bulk-all">
						<?php esc_html_e( 'Select every game matching the current filter', 'game-library' ); ?>
					</label>
				</p>

				<p class="gamelib-bulk__row">
					<label class="gamelib-library__label" for="gamelib-bulk-status">
						<?php esc_html_e( 'Move selected games to', 'game-library' ); ?>
					</label>
					<select
						id="gamelib-bulk-status"
						class="gamelib-control gamelib-library__select"
						data-gamelib-bulk-status
					>
						<?php foreach ( $gamelib_status_labels as $gamelib_value => $gamelib_label ) : ?>
							<option value="<?php echo esc_attr( $gamelib_value ); ?>"><?php echo esc_html( $gamelib_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button
						type="button"
						class="gamelib-control gamelib-bulk__apply"
						data-gamelib-action="library.bulk-status"
						disabled
					>
						<?php esc_html_e( 'Apply', 'game-library' ); ?>
					</button>
					<button
						type="button"
						class="gamelib-control gamelib-bulk__remove"
						data-gamelib-action="library.bulk-remove"
						data-gamelib-confirm-one="<?php echo esc_attr( $gamelib_bulk_confirm_one ); ?>"
						data-gamelib-confirm-other="<?php echo esc_attr( $gamelib_bulk_confirm_other ); ?>"
						disabled
					>
						<?php esc_html_e( 'Remove selected', 'game-library' ); ?>
					</button>
				</p>
			</div>
		</div>

		<ul id="gamelib-library" class="gamelib-grid">
			<?php
			if ( $gamelib_has_entries ) {
				$gamelib_card_index = 0;

				foreach ( $gamelib_view['rows'] as $gamelib_row ) {
					if ( ! is_array( $gamelib_row ) ) {
						continue;
					}

					// The owner's own grid: the controls of AC-014(e,f) render.
					$gamelib_row['controls'] = true;

					/*
					 * The first grid row is inside the initial viewport at every
					 * width the `minmax(11rem, 1fr)` track produces, so those
					 * four fetch eagerly and the rest stay lazy (PF-2). Only the
					 * first of them is the LCP candidate, and only it asks for
					 * the high-priority tier — four images in one tier split the
					 * early bandwidth four ways (PF-5).
					 */
					$gamelib_row['priority'] = ( $gamelib_card_index < 4 );
					$gamelib_row['lcp']      = ( 0 === $gamelib_card_index );

					GameLib_Router::part( 'game-card', $gamelib_row );

					++$gamelib_card_index;
				}
			} else {
				GameLib_Router::part(
					'list-state',
					array(
						'state'   => GameLib_REST_Library::STATE_LIBRARY_EMPTY,
						'tag'     => 'li',
						'tone'    => 'neutral',
						// The shared catalog, so this paint and every REST swap
						// say the same thing (AC-020).
						'message' => GameLib_REST_Library::state_message( GameLib_REST_Library::STATE_LIBRARY_EMPTY ),
					)
				);
			}
			?>
		</ul>

		<?php
		/*
		 * AC-018(b): page controls, hidden while there is only one page. The
		 * numbers are the only thing the bundle writes into this sentence — the
		 * sentence itself is translated here, with its two placeholders as
		 * elements, so no copy is ever composed client-side.
		 */
		?>
		<nav
			class="gamelib-pager"
			aria-label="<?php echo esc_attr__( 'Library pages', 'game-library' ); ?>"
			data-gamelib-pager
			<?php echo esc_attr( ( $gamelib_pages > 1 ) ? '' : 'hidden' ); ?>
		>
			<button
				type="button"
				class="gamelib-control gamelib-pager__button"
				data-gamelib-action="library.paginate"
				data-gamelib-page-step="-1"
				<?php disabled( $gamelib_page <= 1 ); ?>
			>
				<?php esc_html_e( 'Previous', 'game-library' ); ?>
			</button>

			<p class="gamelib-pager__status">
				<?php
				printf(
					/* translators: 1: current page number, 2: total number of pages. */
					esc_html__( 'Page %1$s of %2$s', 'game-library' ),
					'<span data-gamelib-page-current>' . esc_html( number_format_i18n( $gamelib_page ) ) . '</span>',
					'<span data-gamelib-page-total>' . esc_html( number_format_i18n( max( 1, $gamelib_pages ) ) ) . '</span>'
				);
				?>
			</p>

			<button
				type="button"
				class="gamelib-control gamelib-pager__button"
				data-gamelib-action="library.paginate"
				data-gamelib-page-step="1"
				<?php disabled( $gamelib_page >= $gamelib_pages ); ?>
			>
				<?php esc_html_e( 'Next', 'game-library' ); ?>
			</button>
		</nav>
	</div>
</section>

<section
	class="gamelib__section gamelib-account"
	aria-labelledby="gamelib-account-title"
	data-gamelib-account
	data-gamelib-error="<?php echo esc_attr__( 'That did not go through. Check your connection and try again.', 'game-library' ); ?>"
>
	<h2 id="gamelib-account-title" class="gamelib__section-title"><?php esc_html_e( 'Your account', 'game-library' ); ?></h2>

	<div class="gamelib-account__block">
		<h3 id="gamelib-visibility-title" class="gamelib-account__title"><?php esc_html_e( 'Who can see your profile', 'game-library' ); ?></h3>
		<p class="gamelib-account__hint">
			<?php esc_html_e( 'Signed-in members can always see your profile, library, and activity. This setting decides whether signed-out visitors can too.', 'game-library' ); ?>
		</p>

		<?php
		/*
		 * AC-027(b,d): the two states are "Members-only" and "Public" — the word
		 * "private" appears nowhere in this plugin. A two-option radiogroup
		 * rather than a single pressed button, so each state carries its own
		 * label and the selected one announces itself through `aria-checked`;
		 * the bundle drives it with the same roving-tabindex handling the filter
		 * chips use, and announces the server's own sentence after the write.
		 */
		?>
		<div
			class="gamelib-visibility"
			role="radiogroup"
			aria-labelledby="gamelib-visibility-title"
			data-gamelib-visibility
		>
			<?php foreach ( $gamelib_visibility_options as $gamelib_value => $gamelib_label ) : ?>
				<?php $gamelib_selected = ( $gamelib_value === $gamelib_visibility ); ?>
				<button
					type="button"
					role="radio"
					class="gamelib-control gamelib-visibility__option gamelib-visibility__option--<?php echo esc_attr( $gamelib_value ); ?>"
					aria-checked="<?php echo $gamelib_selected ? 'true' : 'false'; ?>"
					tabindex="<?php echo $gamelib_selected ? '0' : '-1'; ?>"
					data-gamelib-action="account.visibility"
					data-gamelib-visibility-value="<?php echo esc_attr( $gamelib_value ); ?>"
				>
					<?php echo esc_html( $gamelib_label ); ?>
				</button>
			<?php endforeach; ?>
		</div>

		<?php if ( '' !== $gamelib_preview_url ) : ?>
			<p class="gamelib-account__row">
				<a class="gamelib-account__preview" href="<?php echo esc_url( $gamelib_preview_url ); ?>">
					<?php esc_html_e( 'Preview as visitor', 'game-library' ); ?>
				</a>
			</p>
		<?php endif; ?>

		<?php if ( '' !== $gamelib_profile_url ) : ?>
			<?php
			/*
			 * AC-050: the copy-link affordance for the member's own profile,
			 * shown only while that profile is public. It carries the same three
			 * attributes as the game page's control, which is the shape the
			 * plugin's copy controls share.
			 */
			?>
			<p class="gamelib-share" data-gamelib-share <?php echo esc_attr( $gamelib_is_public ? '' : 'hidden' ); ?>>
				<button
					type="button"
					class="gamelib-control gamelib-share__button"
					data-gamelib-action="share.copy"
					data-gamelib-url="<?php echo esc_url( $gamelib_profile_url ); ?>"
					data-gamelib-share-done="<?php echo esc_attr__( 'Profile link copied.', 'game-library' ); ?>"
					data-gamelib-share-error="<?php echo esc_attr__( 'Copying is not available here — select the link and copy it yourself.', 'game-library' ); ?>"
				>
					<?php esc_html_e( 'Copy link to your profile', 'game-library' ); ?>
				</button>
				<span class="gamelib-share__feedback" data-gamelib-share-feedback role="status"></span>
			</p>
		<?php endif; ?>
	</div>

	<?php if ( $gamelib_can_invite || ! empty( $gamelib_invites ) ) : ?>
		<div class="gamelib-account__block">
			<h3 class="gamelib-account__title"><?php esc_html_e( 'Invites', 'game-library' ); ?></h3>
			<p class="gamelib-account__hint">
				<?php esc_html_e( 'An invite link works once, for one person. This site never asks for anyone’s email address.', 'game-library' ); ?>
			</p>

			<p
				class="gamelib-account__allowance"
				data-gamelib-allowance
				data-gamelib-allowance-one="<?php echo esc_attr( $gamelib_allowance_one ); ?>"
				data-gamelib-allowance-other="<?php echo esc_attr( $gamelib_allowance_other ); ?>"
				data-gamelib-allowance-none="<?php echo esc_attr__( 'You have used every invite in your allowance.', 'game-library' ); ?>"
				data-gamelib-allowance-unlimited="<?php echo esc_attr__( 'You can create as many invites as you need.', 'game-library' ); ?>"
			><?php echo esc_html( $gamelib_allowance_sentence( $gamelib_allowance ) ); ?></p>

			<?php if ( $gamelib_can_invite ) : ?>
				<button
					type="button"
					class="gamelib-control gamelib-account__action"
					data-gamelib-action="invite.create"
					<?php disabled( empty( $gamelib_allowance['can_create'] ) ); ?>
				>
					<?php esc_html_e( 'Create an invite link', 'game-library' ); ?>
				</button>
			<?php endif; ?>

			<ul class="gamelib-invites" data-gamelib-invite-list>
				<?php foreach ( $gamelib_invites as $gamelib_invite ) : ?>
					<?php $gamelib_invite_payload = GameLib_Invites::payload( $gamelib_invite ); ?>
					<?php
					$gamelib_invite_row(
						$gamelib_invite_payload['url'],
						$gamelib_invite_payload['status'],
						$gamelib_invite_statuses
					);
					?>
				<?php endforeach; ?>
			</ul>

			<?php
			/*
			 * The row the bundle clones when `POST /invites` answers. A fresh
			 * invite is always outstanding, so the template carries that state's
			 * markup and the bundle writes only the URL into it.
			 */
			?>
			<template data-gamelib-invite-template><?php $gamelib_invite_row( '', GameLib_Invites::STATUS_OUTSTANDING, $gamelib_invite_statuses ); ?></template>
		</div>
	<?php endif; ?>

	<div class="gamelib-account__block">
		<h3 class="gamelib-account__title"><?php esc_html_e( 'Bring in a library', 'game-library' ); ?></h3>

		<div class="gamelib-import-file">
			<label class="gamelib-account__label" for="gamelib-import-file">
				<?php esc_html_e( 'Import a CSV or JSON file you exported from this site', 'game-library' ); ?>
			</label>
			<?php
			/*
			 * `gamelib-control` carries the AC-NFR-004(c) hit-area floor, and a
			 * file input needs it as much as a button does — its own target is
			 * the shadow button the user agent paints inside it, which rendered
			 * 21px tall against the 24px floor AC-038 names this control in
			 * (DES-13). The class is safe on a replaced element since the button
			 * chrome moved to its own rule (DES-16).
			 */
			?>
			<input
				type="file"
				id="gamelib-import-file"
				class="gamelib-control gamelib-import-file__field"
				accept=".csv,.json,text/csv,application/json"
				data-gamelib-import-file
			/>
			<button
				type="button"
				class="gamelib-control gamelib-import-file__button"
				data-gamelib-action="import.start"
				data-gamelib-import-type="file"
				disabled
			>
				<?php esc_html_e( 'Start import', 'game-library' ); ?>
			</button>
		</div>

		<?php
		/*
		 * AC-046(a): the Steam launcher renders its own pre-start disclosure and
		 * its own label — "Import from your public Steam profile", no Valve
		 * marks — and is authored beside the route it starts.
		 */
		GameLib_Router::part(
			'steam-import',
			array(
				'steamid'     => $gamelib_steamid,
				// One level below this block's own heading — the launcher is
				// the second way into the same "bring in a library" step.
				'heading_tag' => 'h4',
			)
		);
		?>
	</div>

	<div class="gamelib-account__block">
		<h3 class="gamelib-account__title"><?php esc_html_e( 'Steam connection', 'game-library' ); ?></h3>

		<?php
		/*
		 * AC-046(b): the stored SteamID and the action that deletes it. Both
		 * states are rendered; the bundle swaps which one is hidden after
		 * `DELETE /account/steam` answers, so no sentence is composed client-side.
		 */
		?>
		<p class="gamelib-account__row" data-gamelib-steam-connected <?php echo esc_attr( ( '' === $gamelib_steamid ) ? 'hidden' : '' ); ?>>
			<span class="gamelib-account__label"><?php esc_html_e( 'Connected SteamID', 'game-library' ); ?></span>
			<span class="gamelib-account__value" data-gamelib-steamid><?php echo esc_html( $gamelib_steamid ); ?></span>
			<button
				type="button"
				class="gamelib-control gamelib-account__action"
				data-gamelib-action="account.disconnect-steam"
			>
				<?php esc_html_e( 'Disconnect', 'game-library' ); ?>
			</button>
		</p>

		<p class="gamelib-account__hint" data-gamelib-steam-disconnected <?php echo esc_attr( ( '' === $gamelib_steamid ) ? '' : 'hidden' ); ?>>
			<?php esc_html_e( 'No Steam profile is connected. Starting a Steam import stores your SteamID so a later import does not have to ask for it again.', 'game-library' ); ?>
		</p>
	</div>

	<div class="gamelib-account__block">
		<h3 class="gamelib-account__title"><?php esc_html_e( 'Export your library', 'game-library' ); ?></h3>
		<p class="gamelib-account__hint">
			<?php esc_html_e( 'A download of every game in your library, with its status and the date you added it.', 'game-library' ); ?>
		</p>
		<?php
		/*
		 * Buttons, not anchors (SE-1, CWE-598). An anchor to the REST route
		 * would have to carry the member's `wp_rest` nonce as `_wpnonce` —
		 * core's cookie check reads the query argument only when the header is
		 * absent — and that token is valid for every `gamelib/v1` mutation route
		 * for ~12–24h, so a URL-borne copy lands in access logs, proxy logs, and
		 * the browser's download history. The bundle fetches the file with the
		 * nonce in `X-WP-Nonce` and saves it through an object URL instead, so
		 * no rendered attribute on this page carries a credential at all. It
		 * also discharges PF-8: there is no same-site document link left for WP
		 * 7.0's Speculative Loading to prefetch on hover, which would have run a
		 * whole library read and CSV build and thrown it away.
		 */
		?>
		<p class="gamelib-account__row">
			<button
				type="button"
				class="gamelib-control gamelib-account__action"
				data-gamelib-action="export.download"
				data-gamelib-export-format="<?php echo esc_attr( GameLib_Exporter::FORMAT_CSV ); ?>"
			>
				<?php esc_html_e( 'Download CSV', 'game-library' ); ?>
			</button>
			<button
				type="button"
				class="gamelib-control gamelib-account__action"
				data-gamelib-action="export.download"
				data-gamelib-export-format="<?php echo esc_attr( GameLib_Exporter::FORMAT_JSON ); ?>"
			>
				<?php esc_html_e( 'Download JSON', 'game-library' ); ?>
			</button>
		</p>
	</div>
</section>
<?php

GameLib_Router::part( 'document-close' );
