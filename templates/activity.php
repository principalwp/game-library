<?php
/**
 * /activity/ — audience toggle + chronological feed.
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this    Template renderer.
 * @var array<string, mixed>    $context Route context.
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$gl_viewer    = get_current_user_id();
$gl_logged_in = is_user_logged_in();
$gl_plugin    = Plugin::instance();
$gl_activity  = $gl_plugin->activity();
$gl_follows   = $gl_plugin->follows();

$gl_audience = sanitize_key( (string) get_query_var( 'gl_audience' ) );
if ( ! in_array( $gl_audience, array( 'following', 'everyone' ), true ) ) {
	$gl_audience = $gl_logged_in ? 'following' : 'everyone';
}
if ( ! $gl_logged_in ) {
	$gl_audience = 'everyone';
}

$gl_page   = $this->current_page();
$gl_per    = self::FEED_PER_PAGE;
$gl_offset = ( $gl_page - 1 ) * $gl_per;
$gl_base   = home_url( '/activity/' );

if ( 'following' === $gl_audience ) {
	$gl_followed = $gl_follows->followed_ids( $gl_viewer );
	$gl_rows     = $gl_activity->feed_following( $gl_viewer, $gl_followed, $gl_per, $gl_offset );
	$gl_total    = $gl_activity->count_following( $gl_viewer, $gl_followed );
} else {
	$gl_rows  = $gl_activity->feed_everyone( $gl_per, $gl_offset );
	$gl_total = $gl_activity->count_everyone();
}
$gl_pages = (int) ceil( $gl_total / $gl_per );
?>
<div class="gl-container">
	<header class="gl-page-head">
		<span class="gl-page-head__eyebrow"><?php esc_html_e( 'Community', 'game-library-3' ); ?></span>
		<h1><?php esc_html_e( 'Activity', 'game-library-3' ); ?></h1>
	</header>

	<ul class="gl-audience-toggle">
		<?php if ( $gl_logged_in ) : ?>
			<li><a href="<?php echo esc_url( add_query_arg( 'gl_audience', 'following', $gl_base ) ); ?>"<?php echo 'following' === $gl_audience ? ' aria-current="true"' : ''; ?>><?php esc_html_e( 'Following', 'game-library-3' ); ?></a></li>
		<?php endif; ?>
		<li><a href="<?php echo esc_url( add_query_arg( 'gl_audience', 'everyone', $gl_base ) ); ?>"<?php echo 'everyone' === $gl_audience ? ' aria-current="true"' : ''; ?>><?php esc_html_e( 'Everyone', 'game-library-3' ); ?></a></li>
	</ul>

	<?php if ( empty( $gl_rows ) ) : ?>
		<?php
		$this->output(
			$this->empty_state(
				__( 'No activity yet', 'game-library-3' ),
				'following' === $gl_audience
					? __( 'Follow other members to see what they are playing here.', 'game-library-3' )
					: __( 'When members make their libraries public, their moves show up here.', 'game-library-3' )
			)
		);
		?>
	<?php else : ?>
		<ul class="gl-activity-list">
			<?php
			// Prime the whole page's actor set in one query so the per-row
			// member_link()/get_avatar() calls hit the user cache (NFR-001).
			cache_users( array_map( 'intval', wp_list_pluck( $gl_rows, 'actor_id' ) ) );
			foreach ( $gl_rows as $gl_row ) {
				$this->partial( 'activity-row', array( 'row' => $gl_row ) );
			}
			?>
		</ul>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pagination().
		echo $this->pagination( $gl_base, $gl_page, $gl_pages, array( 'gl_audience' => $gl_audience ) );
		?>
	<?php endif; ?>
</div>
