<?php
/**
 * /games/{slug}/ — the game hero and holders list.
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this    Template renderer.
 * @var array<string, mixed>    $context Route context (game).
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$gl_game = isset( $context['game'] ) ? $context['game'] : null;
if ( ! $gl_game ) {
	return;
}

$gl_plugin  = Plugin::instance();
$gl_games   = $gl_plugin->games();
$gl_library = $gl_plugin->library();
$gl_vis     = $gl_plugin->visibility();
$gl_viewer  = get_current_user_id();

$gl_genres    = $gl_games->decode_names( $gl_game->genres );
$gl_platforms = $gl_games->decode_names( $gl_game->platforms );
$gl_hero_img  = $this->cover_img_tag( $gl_game->cover_image_id, (string) $gl_game->name, true );

$gl_release = '';
if ( ! empty( $gl_game->first_release_date ) ) {
	$gl_release = date_i18n( (string) get_option( 'date_format' ), (int) $gl_game->first_release_date );
}

// Visibility filter applied identically to holders + counts (single rule).
$gl_all_holders = $gl_library->holders_for_game( (int) $gl_game->id, false, 200 );
// Prime the whole holder set (users AND usermeta) so the per-holder
// viewer_can_see()/is_public() and holders-list get_userdata()/get_avatar()
// reads hit cache instead of one query each.
cache_users( array_map( 'intval', wp_list_pluck( $gl_all_holders, 'user_id' ) ) );
$gl_holders     = array();
$gl_counts      = array(
	'playing'  => 0,
	'finished' => 0,
	'backlog'  => 0,
	'wishlist' => 0,
);
foreach ( $gl_all_holders as $gl_h ) {
	if ( $gl_vis->viewer_can_see( (int) $gl_h->user_id, $gl_viewer ) ) {
		$gl_holders[] = $gl_h;
		if ( isset( $gl_counts[ $gl_h->status ] ) ) {
			++$gl_counts[ $gl_h->status ];
		}
	}
}
?>
<div class="gl-header-band">
	<div class="gl-container gl-container--wide gl-game-single__header">
		<div class="gl-game-single__cover">
			<?php if ( '' !== $gl_hero_img ) : ?>
				<?php echo $gl_hero_img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built + escaped in cover_img_tag(). ?>
			<?php else : ?>
				<div class="gl-game-single__cover-placeholder">
					<strong><?php echo esc_html( (string) $gl_game->name ); ?></strong>
					<span><?php esc_html_e( 'No cover', 'game-library-3' ); ?></span>
				</div>
			<?php endif; ?>
		</div>
		<div class="gl-game-single__meta">
			<span class="gl-header-band__eyebrow"><?php echo esc_html( ! empty( $gl_genres ) ? $gl_genres[0] : __( 'Game', 'game-library-3' ) ); ?></span>
			<h1><?php echo esc_html( (string) $gl_game->name ); ?></h1>
			<p class="gl-game-single__release-date">
				<?php echo '' !== $gl_release ? esc_html( $gl_release ) : esc_html__( 'Unreleased', 'game-library-3' ); ?>
			</p>

			<?php if ( ! empty( $gl_genres ) ) : ?>
				<h2 class="gl-game-single__label"><?php esc_html_e( 'Genres', 'game-library-3' ); ?></h2>
				<ul class="gl-game-single__genres">
					<?php foreach ( $gl_genres as $gl_g ) : ?>
						<li><?php echo esc_html( $gl_g ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $gl_platforms ) ) : ?>
				<h2 class="gl-game-single__label"><?php esc_html_e( 'Platforms', 'game-library-3' ); ?></h2>
				<ul class="gl-game-single__platforms">
					<?php foreach ( $gl_platforms as $gl_p ) : ?>
						<li><?php echo esc_html( $gl_p ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</div>

<div class="gl-container gl-container--wide">
	<?php if ( ! empty( $gl_game->summary ) ) : ?>
		<p class="gl-game-single__summary"><?php echo esc_html( (string) $gl_game->summary ); ?></p>
	<?php endif; ?>

	<ul class="gl-game-single__status-counts">
		<?php foreach ( Library_Repository::STATUSES as $gl_status ) : ?>
			<li>
				<?php echo $this->render_status_badge( $gl_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built + escaped. ?>
				<span data-gl-count="<?php echo esc_attr( $gl_status ); ?>"><?php echo esc_html( (string) number_format_i18n( (int) $gl_counts[ $gl_status ] ) ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php $this->partial( 'holders-list', array( 'holders' => $gl_holders ) ); ?>

	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in attribution().
	echo $this->attribution();
	?>
</div>
