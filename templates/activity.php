<?php
/**
 * Template: /game-activity/ — the public, site-wide activity feed (paginated).
 *
 * @package Game_Library
 *
 * @var array $context {
 *     @type array $items
 *     @type bool  $has_more
 *     @type int   $page
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$game_library_items    = $context['items'];
$game_library_has_more = ! empty( $context['has_more'] );
$game_library_page     = max( 1, (int) $context['page'] );
?>
<div class="gl-app gl-activity">

	<h2 class="gl-feed__heading"><?php esc_html_e( 'What the community is playing', 'game-library' ); ?></h2>

	<?php if ( ! empty( $game_library_items ) ) : ?>
		<div class="gl-feed" data-role="site-feed">
			<?php
			foreach ( $game_library_items as $game_library_event ) {
				echo Game_Library_Router::render_feed_item( $game_library_event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_feed_item escapes internally.
			}
			?>
		</div>

		<nav class="gl-pagination" aria-label="<?php esc_attr_e( 'Activity pagination', 'game-library' ); ?>">
			<?php if ( $game_library_page > 1 ) : ?>
				<a class="gl-button gl-pagination__prev" rel="prev"
					href="<?php echo esc_url( home_url( 2 === $game_library_page ? '/game-activity/' : '/game-activity/page/' . ( $game_library_page - 1 ) . '/' ) ); ?>">
					<?php esc_html_e( '← Newer', 'game-library' ); ?>
				</a>
			<?php endif; ?>
			<?php if ( $game_library_has_more ) : ?>
				<a class="gl-button gl-pagination__next" rel="next" data-role="feed-next"
					href="<?php echo esc_url( home_url( '/game-activity/page/' . ( $game_library_page + 1 ) . '/' ) ); ?>">
					<?php esc_html_e( 'Older →', 'game-library' ); ?>
				</a>
			<?php endif; ?>
		</nav>
	<?php else : ?>
		<?php
		echo Game_Library_Router::render_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_empty_state escapes internally.
			__( 'No activity yet. When members add games and follow each other, it will show up here.', 'game-library' )
		);
		?>
	<?php endif; ?>

</div>
