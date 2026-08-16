<?php
/**
 * Route template: `/members/{nicename}/`.
 *
 * Reached only when the AC-028 matrix allowed this viewer through — the router
 * ran `GameLib_Visibility::can_view_member()` and, on a denial, handed the
 * request to WordPress's own 404 without ever selecting this file (DD-014).
 * Nothing below re-checks access: a second, template-level gate that rendered a
 * different body is exactly what AC-028(e) forbids.
 *
 * Surfaces here render for `GameLib_Router::viewer_id()`, not
 * `get_current_user_id()`: under preview-as-visitor the owner is deliberately
 * viewer 0 for the whole request, which is what makes the preview show exactly
 * what a logged-out visitor sees (AC-030). Every viewer-dependent decision on
 * this page — the follow toggle, the owner's own note — reads that one value.
 *
 * AC-031's seven parts, and where each lives:
 *
 * - (a) display name — the page heading.
 * - (b) followers and following counts — the header list, both through `_n()`
 *   (AC-NFR-008). Only the followers sentence can change without a reload, so
 *   only it carries the two plural forms the bundle picks between.
 * - (c) the member's library, read-only: the same `game-card` part
 *   `/my-library/` renders, with no `controls` flag, so a read-only grid is the
 *   identical markup with nothing interactive in it. Filter and pagination go
 *   through `GET /members/{id}/library`, whose defaults this first paint
 *   matches exactly.
 * - (d) the ten most recent events, through the same `feed-item` part
 *   `/activity/` uses.
 * - (e) the follow toggle, for a logged-in member who is not the owner.
 * - (f) the copy-link, rendered *only* for a public profile — absent, not
 *   hidden, on a members-only one (AC-050d).
 * - (g) zero outbound HTTP: every value read here comes from the plugin's own
 *   tables or the shared game store (Never Do #6).
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_member = GameLib_Router::member();

if ( ! $gamelib_member instanceof WP_User ) {
	/*
	 * Unreachable through the router: an unresolvable nicename fails
	 * `can_view_member()` and is denied before this template can be chosen
	 * (DD-014). Kept as a guard so a direct include cannot emit warnings.
	 */
	return;
}

$gamelib_owner_id = (int) $gamelib_member->ID;
$gamelib_name     = (string) $gamelib_member->display_name;
$gamelib_viewer   = GameLib_Router::viewer_id();
$gamelib_is_owner = ( $gamelib_viewer > 0 && $gamelib_viewer === $gamelib_owner_id );

/*
 * AC-031(e): the toggle belongs to a logged-in member looking at somebody
 * else's profile. `viewer_id()` rather than `get_current_user_id()` is what
 * removes it from the AC-030 preview as well — the owner previewing their own
 * page is viewer 0 and sees what a visitor sees.
 */
$gamelib_can_follow = (
	$gamelib_viewer > 0
	&& ! $gamelib_is_owner
	&& current_user_can( GameLib_Capabilities::CAP_FOLLOW )
);

$gamelib_following = $gamelib_can_follow
	&& GameLib_Follows::is_following( $gamelib_viewer, $gamelib_owner_id );

// AC-031(b). Both counts are the owner's, not the viewer's.
$gamelib_follower_count  = GameLib_Follows::follower_count( $gamelib_owner_id );
$gamelib_following_count = GameLib_Follows::following_count( $gamelib_owner_id );

/*
 * The followers sentence is the one string on this page a successful follow
 * changes without a reload, so it ships the way every other client-updated
 * count string in this plugin does: `_n()` twice in PHP — the form of 1 and the
 * form of 2 — read off the element, with the number substituted by the bundle.
 * The first paint below still calls `_n()` with the real count, so a
 * server-rendered page is correct in every locale.
 */
/* translators: %s: number of members following this member. */
$gamelib_followers_one = _n( '%s follower', '%s followers', 1, 'game-library' );

/* translators: %s: number of members following this member. */
$gamelib_followers_other = _n( '%s follower', '%s followers', 2, 'game-library' );

$gamelib_followers_text = sprintf(
	/* translators: %s: number of members following this member. */
	_n( '%s follower', '%s followers', $gamelib_follower_count, 'game-library' ),
	number_format_i18n( $gamelib_follower_count )
);

$gamelib_following_text = sprintf(
	/* translators: %s: number of members this member follows. */
	_n( 'Following %s member', 'Following %s members', $gamelib_following_count, 'game-library' ),
	number_format_i18n( $gamelib_following_count )
);

/*
 * AC-031(c). The criteria are the route's own defaults — every status, newest
 * first, page 1, 24 per page — so the first paint and the first REST swap
 * describe the same view.
 */
$gamelib_view = GameLib_Library::query(
	$gamelib_owner_id,
	array(
		'status'   => GameLib_Library::STATUS_ALL,
		'sort'     => GameLib_Library::SORT_DEFAULT,
		'page'     => 1,
		'per_page' => GameLib_Library::PAGE_SIZE,
	)
);

$gamelib_total       = (int) $gamelib_view['total'];
$gamelib_pages       = (int) $gamelib_view['pages'];
$gamelib_page        = (int) $gamelib_view['page'];
$gamelib_has_entries = $gamelib_total > 0;

// AC-017(a)'s five filters, reused read-only: All plus the four statuses.
$gamelib_filters = array(
	GameLib_Library::STATUS_ALL => _x( 'All', 'library status filter', 'game-library' ),
	'playing'                   => _x( 'Playing', 'library status', 'game-library' ),
	'finished'                  => _x( 'Finished', 'library status', 'game-library' ),
	'backlog'                   => _x( 'Backlog', 'library status', 'game-library' ),
	'wishlist'                  => _x( 'Wishlist', 'library status', 'game-library' ),
);

// AC-031(d): the owner's ten most recent events, rendered by the same part the
// feed uses. The sentences already open with the owner's linked name, so no
// actor is passed — passing one prints the member's name twice per item.
$gamelib_events = GameLib_Activity::render_items(
	GameLib_Activity::for_member(
		$gamelib_owner_id,
		array( 'limit' => GameLib_Activity::MEMBER_PAGE_SIZE )
	)
);

/*
 * AC-031(f) / AC-050(a,d): the copy-link exists only on a public profile. Not
 * rendered-and-hidden, the way the owner's own control on `/my-library/` is —
 * this is somebody else's surface, and the toggle that would reveal it is not
 * on this page. Members-only means the control is absent from the document.
 */
$gamelib_is_public   = GameLib_Visibility::is_public( $gamelib_owner_id );
$gamelib_profile_url = GameLib_Visibility::profile_url( $gamelib_owner_id );
$gamelib_can_share   = ( $gamelib_is_public && '' !== $gamelib_profile_url );

GameLib_Router::part( 'document-open', array( 'body_class' => 'gamelib-route-profile' ) );

?>
<?php
/*
 * One polite live region for the route, outside every element the bundle swaps:
 * replacing a live region's own node is not reliably announced (AC-NFR-004).
 */
?>
<div class="gamelib-visually-hidden" data-gamelib-live role="status" aria-live="polite"></div>

<header class="gamelib__header gamelib-profile__header" data-gamelib-profile data-gamelib-member-id="<?php echo esc_attr( (string) $gamelib_owner_id ); ?>">
	<h1 class="gamelib__title"><?php echo esc_html( $gamelib_name ); ?></h1>

	<ul class="gamelib-profile__counts">
		<li
			class="gamelib-profile__count"
			data-gamelib-followers
			data-gamelib-followers-one="<?php echo esc_attr( $gamelib_followers_one ); ?>"
			data-gamelib-followers-other="<?php echo esc_attr( $gamelib_followers_other ); ?>"
		><?php echo esc_html( $gamelib_followers_text ); ?></li>
		<li class="gamelib-profile__count"><?php echo esc_html( $gamelib_following_text ); ?></li>
	</ul>

	<?php if ( $gamelib_can_follow ) : ?>
		<?php
		/*
		 * AC-022(c): the state is `aria-pressed` and the accessible name names
		 * the member — "Follow Ada" / "Unfollow Ada". The label is the button's
		 * visible text rather than an `aria-label`, so the two cannot drift, and
		 * it is the same catalog the REST response answers with: after a write
		 * the bundle writes the server's own `label` back into this element and
		 * composes nothing (ADR-002).
		 */
		?>
		<p class="gamelib-profile__actions">
			<button
				type="button"
				class="gamelib-control gamelib-profile__follow"
				aria-pressed="<?php echo $gamelib_following ? 'true' : 'false'; ?>"
				data-gamelib-action="follow.toggle"
				data-gamelib-user-id="<?php echo esc_attr( (string) $gamelib_owner_id ); ?>"
				data-gamelib-error="<?php echo esc_attr__( 'That did not go through. Check your connection and try again.', 'game-library' ); ?>"
			><?php echo esc_html( GameLib_REST_Social::follow_label( $gamelib_name, $gamelib_following ) ); ?></button>
		</p>
	<?php endif; ?>

	<?php if ( $gamelib_is_owner ) : ?>
		<?php
		/*
		 * The owner's own note, and the only element on this page gated on
		 * ownership besides the toggle's absence. It renders for viewer ===
		 * owner and therefore never for another member and never inside the
		 * AC-030 preview, where the owner is viewer 0.
		 */
		?>
		<p class="gamelib-profile__own">
			<?php esc_html_e( 'This is your profile, as other members see it.', 'game-library' ); ?>
			<a href="<?php echo esc_url( GameLib_Router::route_url( GameLib_Router::ROUTE_MY_LIBRARY ) ); ?>">
				<?php esc_html_e( 'Manage your library', 'game-library' ); ?>
			</a>
		</p>
	<?php endif; ?>

	<?php if ( $gamelib_can_share ) : ?>
		<?php
		/*
		 * AC-050: the same three attributes every copy control in the plugin
		 * carries — the action, the URL to write, and the two sentences the
		 * bundle chooses between — plus the `role="status"` sibling its
		 * confirmation lands in.
		 */
		?>
		<p class="gamelib-share">
			<button
				type="button"
				class="gamelib-control gamelib-share__button"
				data-gamelib-action="share.copy"
				data-gamelib-url="<?php echo esc_url( $gamelib_profile_url ); ?>"
				data-gamelib-share-done="<?php echo esc_attr__( 'Profile link copied.', 'game-library' ); ?>"
				data-gamelib-share-error="<?php echo esc_attr__( 'Copying is not available here — select the link and copy it yourself.', 'game-library' ); ?>"
			>
				<?php esc_html_e( 'Copy link to this profile', 'game-library' ); ?>
			</button>
			<span class="gamelib-share__feedback" data-gamelib-share-feedback role="status"></span>
		</p>
	<?php endif; ?>
</header>

<section class="gamelib__section" aria-labelledby="gamelib-profile-library-title">
	<h2 id="gamelib-profile-library-title" class="gamelib__section-title" tabindex="-1">
		<?php
		printf(
			/* translators: %s: member display name. */
			esc_html__( '%s’s games', 'game-library' ),
			esc_html( $gamelib_name )
		);
		?>
	</h2>

	<div
		class="gamelib-library"
		data-gamelib-member-library
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
		</div>

		<ul id="gamelib-library" class="gamelib-grid">
			<?php
			if ( $gamelib_has_entries ) {
				$gamelib_card_index = 0;

				foreach ( $gamelib_view['rows'] as $gamelib_row ) {
					if ( ! is_array( $gamelib_row ) ) {
						continue;
					}

					/*
					 * The first grid row is inside the initial viewport, so those
					 * four fetch eagerly and the rest stay lazy (PF-2). Only the
					 * first of them is the LCP candidate, and only it asks for the
					 * high-priority tier (PF-5).
					 */
					$gamelib_row['priority'] = ( $gamelib_card_index < 4 );
					$gamelib_row['lcp']      = ( 0 === $gamelib_card_index );

					// No `controls` flag: another member's library is read-only,
					// and a read-only card is this markup with nothing
					// interactive in it (AC-031c).
					GameLib_Router::part( 'game-card', $gamelib_row );

					++$gamelib_card_index;
				}
			} else {
				GameLib_Router::part(
					'list-state',
					array(
						'state'   => GameLib_REST_Social::STATE_MEMBER_EMPTY,
						'tag'     => 'li',
						'tone'    => 'neutral',
						// The shared catalog, so this paint and every REST swap
						// say the same thing.
						'message' => GameLib_REST_Social::state_message(
							GameLib_REST_Social::STATE_MEMBER_EMPTY,
							$gamelib_name
						),
					)
				);
			}
			?>
		</ul>

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

<section class="gamelib__section" aria-labelledby="gamelib-profile-activity-title">
	<h2 id="gamelib-profile-activity-title" class="gamelib__section-title"><?php esc_html_e( 'Recent activity', 'game-library' ); ?></h2>

	<ul class="gamelib-feed">
		<?php
		if ( empty( $gamelib_events ) ) {
			GameLib_Router::part(
				'list-state',
				array(
					'state'   => 'member_activity_empty',
					'tag'     => 'li',
					'tone'    => 'neutral',
					'message' => sprintf(
						/* translators: %s: member display name. */
						__( '%s has not done anything here yet.', 'game-library' ),
						$gamelib_name
					),
				)
			);
		} else {
			foreach ( $gamelib_events as $gamelib_event ) {
				GameLib_Router::part(
					'feed-item',
					array(
						'id'         => isset( $gamelib_event['id'] ) ? (int) $gamelib_event['id'] : 0,
						'type'       => isset( $gamelib_event['type'] ) ? (string) $gamelib_event['type'] : '',
						'sentence'   => isset( $gamelib_event['sentence'] ) ? (string) $gamelib_event['sentence'] : '',
						'created_at' => isset( $gamelib_event['created_at'] ) ? (string) $gamelib_event['created_at'] : '',
					)
				);
			}
		}
		?>
	</ul>
</section>
<?php

GameLib_Router::part( 'document-close' );
