<?php
/**
 * Route template: `/activity/`.
 *
 * Reached only by an authenticated member (AC-021, enforced in the router), so
 * nothing here re-checks the session.
 *
 * The feed is server-rendered first and paged with a keyset cursor, never an
 * offset and never a realtime channel (AC-025 a,c). The first twenty items
 * below come from `GameLib_Activity::feed()`; "Load more" hands the id of the
 * last item back to `GET /feed?before={id}`, which answers with a fragment
 * rendered by the very same `feed-item` part — one markup path, one escaping
 * path (ADR-002).
 *
 * `gamelib-feed` is the stable instrumentation container (AC-NFR-009 t) and the
 * element the bundle appends into; the cursor lives on the button rather than
 * in the bundle's memory, so a fragment swap can never desynchronize it from
 * what the server last answered.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_viewer = get_current_user_id();

// AC-025(a): the first page, server-rendered, exactly as the REST route would
// have answered it — same page size, same ordering, same renderer.
$gamelib_rows  = GameLib_Activity::feed(
	$gamelib_viewer,
	array( 'limit' => GameLib_Activity::FEED_PAGE_SIZE )
);
$gamelib_items = GameLib_Activity::render_items( $gamelib_rows );

/*
 * Cursor and "is there more" both come from the *rows*, not the rendered items:
 * an event a renderer dropped (a game that no longer resolves, say) must still
 * advance the cursor, or "Load more" would re-request the page it just showed.
 */
$gamelib_cursor   = GameLib_Activity::next_cursor( $gamelib_rows );
$gamelib_has_more = ( count( $gamelib_rows ) === GameLib_Activity::FEED_PAGE_SIZE && $gamelib_cursor > 0 );

/*
 * AC-026: the empty state explains what fills the feed, and is the shared
 * catalog entry `GET /feed` answers with, so a first paint and a REST swap say
 * the same thing. It renders only when there is genuinely nothing further to
 * ask for — a page whose every row was dropped still has a next page.
 */
$gamelib_is_empty = ( empty( $gamelib_items ) && ! $gamelib_has_more );

/*
 * Two sentences the server cannot supply after a "Load more", because their
 * count is only known once the response arrives, and because the bundle imports
 * no i18n runtime (ADR-002). Both plural forms are authored here and read off
 * the control, exactly like the bulk-remove confirmation on `/my-library/`.
 */
/* translators: %s: number of activity items just added to the feed. */
$gamelib_more_one = _n( '%s more item added to your feed.', '%s more items added to your feed.', 1, 'game-library' );

/* translators: %s: number of activity items just added to the feed. */
$gamelib_more_other = _n( '%s more item added to your feed.', '%s more items added to your feed.', 2, 'game-library' );

GameLib_Router::part( 'document-open', array( 'body_class' => 'gamelib-route-activity' ) );

?>
<header class="gamelib__header">
	<h1 class="gamelib__title"><?php esc_html_e( 'Activity', 'game-library' ); ?></h1>
</header>

<?php
/*
 * One polite live region for the route, deliberately outside the list the
 * bundle appends to: replacing or moving a live region's own node is not
 * reliably announced (AC-NFR-004).
 */
?>
<div class="gamelib-visually-hidden" data-gamelib-live role="status" aria-live="polite"></div>

<section
	class="gamelib__section"
	aria-labelledby="gamelib-feed-title"
	data-gamelib-error="<?php echo esc_attr__( 'That did not go through. Check your connection and try again.', 'game-library' ); ?>"
>
	<h2 id="gamelib-feed-title" class="gamelib__section-title" tabindex="-1"><?php esc_html_e( 'From members you follow', 'game-library' ); ?></h2>

	<ul id="gamelib-feed" class="gamelib-feed">
		<?php
		if ( $gamelib_is_empty ) {
			GameLib_Router::part(
				'list-state',
				array(
					'state'   => GameLib_REST_Social::STATE_FEED_EMPTY,
					'tag'     => 'li',
					'tone'    => 'neutral',
					'message' => GameLib_REST_Social::state_message( GameLib_REST_Social::STATE_FEED_EMPTY ),
				)
			);
		} else {
			foreach ( $gamelib_items as $gamelib_item ) {
				GameLib_Router::part(
					'feed-item',
					array(
						'id'         => isset( $gamelib_item['id'] ) ? (int) $gamelib_item['id'] : 0,
						'type'       => isset( $gamelib_item['type'] ) ? (string) $gamelib_item['type'] : '',
						'sentence'   => isset( $gamelib_item['sentence'] ) ? (string) $gamelib_item['sentence'] : '',
						'created_at' => isset( $gamelib_item['created_at'] ) ? (string) $gamelib_item['created_at'] : '',
					)
				);
			}
		}
		?>
	</ul>

	<?php
	/*
	 * AC-025(b): the keyset control. It carries the cursor it will send and
	 * nothing else — no page number, no offset, and no total, because the feed
	 * has none of those.
	 */
	?>
	<p class="gamelib-feed__pager">
		<button
			type="button"
			class="gamelib-control gamelib-feed__more"
			data-gamelib-action="feed.paginate"
			data-gamelib-feed-more
			data-gamelib-cursor="<?php echo esc_attr( (string) $gamelib_cursor ); ?>"
			data-gamelib-more-one="<?php echo esc_attr( $gamelib_more_one ); ?>"
			data-gamelib-more-other="<?php echo esc_attr( $gamelib_more_other ); ?>"
			data-gamelib-more-end="<?php echo esc_attr__( 'You have reached the end of your feed.', 'game-library' ); ?>"
			<?php echo esc_attr( $gamelib_has_more ? '' : 'hidden' ); ?>
		>
			<?php esc_html_e( 'Load more', 'game-library' ); ?>
		</button>
	</p>
</section>
<?php

GameLib_Router::part( 'document-close' );
