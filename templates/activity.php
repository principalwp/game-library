<?php
/**
 * Activity feed page.
 *
 * Vars: $viewer (WP_User).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$items         = GC_Activity::get_feed_for( $viewer->ID );
$following_ids = GC_Follows::get_following_ids( $viewer->ID );
?>
<div class="gc-wrap gc-wrap-narrow">

	<header class="gc-page-header">
		<h1><?php esc_html_e( 'Activity', 'game-collector' ); ?></h1>
		<a class="gc-btn gc-btn-secondary" href="<?php echo esc_url( GC_Frontend::my_library_url() ); ?>"><?php esc_html_e( 'My Library', 'game-collector' ); ?></a>
	</header>

	<?php if ( ! $items ) : ?>
		<div class="gc-empty">
			<p><?php esc_html_e( 'Nothing here yet.', 'game-collector' ); ?></p>
			<?php if ( ! $following_ids ) : ?>
				<p><?php esc_html_e( 'Follow other collectors from their library pages to fill this feed.', 'game-collector' ); ?></p>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<ul class="gc-feed" id="gc-feed" data-page="1">
			<?php foreach ( $items as $item ) : ?>
				<li class="gc-feed-item">
					<div class="gc-feed-avatar"><?php echo get_avatar( $item->user_id, 40 ); ?></div>
					<div class="gc-feed-body">
						<p class="gc-feed-text"><?php echo wp_kses_post( GC_Activity::describe( $item ) ); ?></p>
						<time class="gc-feed-time" datetime="<?php echo esc_attr( mysql2date( 'c', $item->created_at, false ) ); ?>">
							<?php printf( esc_html__( '%s ago', 'game-collector' ), esc_html( human_time_diff( strtotime( $item->created_at . ' UTC' ) ) ) ); ?>
						</time>
					</div>
					<?php if ( $item->game_id && $item->cover_image_id ) : ?>
						<div class="gc-feed-cover">
							<img src="<?php echo esc_url( GC_IGDB::cover_url( $item->cover_image_id, 'cover_small' ) ); ?>" alt="" loading="lazy" />
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( count( $items ) >= 30 ) : ?>
			<p class="gc-load-more-wrap">
				<button type="button" class="gc-btn gc-btn-secondary" id="gc-load-more"><?php esc_html_e( 'Load more', 'game-collector' ); ?></button>
			</p>
		<?php endif; ?>
	<?php endif; ?>

</div>
