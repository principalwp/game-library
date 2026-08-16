<?php
/**
 * /library/{nicename}/ — another member's read-only library.
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this    Template renderer.
 * @var array<string, mixed>    $context Route context (owner).
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$gl_owner = isset( $context['owner'] ) ? $context['owner'] : null;
if ( ! $gl_owner ) {
	return;
}

$gl_owner_id = (int) $gl_owner->ID;
$gl_viewer   = get_current_user_id();
$gl_plugin   = Plugin::instance();
$gl_vis      = $gl_plugin->visibility();
$gl_library  = $gl_plugin->library();
$gl_follows  = $gl_plugin->follows();

$gl_can_see   = $gl_vis->viewer_can_see( $gl_owner_id, $gl_viewer );
$gl_is_public = $gl_vis->is_public( $gl_owner_id );
$gl_is_self   = ( $gl_viewer > 0 && $gl_viewer === $gl_owner_id );
?>
<div class="gl-container gl-container--wide">
	<header class="gl-member-header">
		<span class="gl-member-header__avatar"><?php echo get_avatar( $gl_owner_id, 64 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar returns safe markup. ?></span>
		<div class="gl-member-header__identity">
			<h1><?php echo esc_html( $gl_owner->display_name ); ?></h1>
			<?php if ( $gl_is_public ) : ?>
				<span class="gl-member-header__public-marker"><?php esc_html_e( 'Public library', 'game-library-3' ); ?></span>
			<?php else : ?>
				<span class="gl-member-header__public-marker gl-member-header__public-marker--private"><?php esc_html_e( 'Private library', 'game-library-3' ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( $gl_viewer > 0 && ! $gl_is_self ) : ?>
			<?php
			$this->partial(
				'follow-button',
				array(
					'target_id'    => $gl_owner_id,
					'is_following' => $gl_follows->is_following( $gl_viewer, $gl_owner_id ),
				)
			);
			?>
		<?php endif; ?>
	</header>

	<?php if ( ! $gl_can_see ) : ?>
		<div class="gl-notice gl-notice--info"><?php esc_html_e( 'This library is private.', 'game-library-3' ); ?></div>
	<?php else : ?>
		<?php
		$gl_counts = $gl_library->status_counts( $gl_owner_id );
		$gl_total  = array_sum( $gl_counts );

		$gl_active = sanitize_key( (string) get_query_var( 'gl_status' ) );
		if ( ! Library_Repository::is_status( $gl_active ) ) {
			$gl_active = '';
		}

		$gl_page       = $this->current_page();
		$gl_per        = self::LIBRARY_PER_PAGE;
		$gl_offset     = ( $gl_page - 1 ) * $gl_per;
		$gl_entries    = $gl_library->get_library( $gl_owner_id, '' !== $gl_active ? $gl_active : null, $gl_per, $gl_offset );
		$gl_count_here = ( '' !== $gl_active ) ? (int) $gl_counts[ $gl_active ] : $gl_total;
		$gl_pages      = (int) ceil( $gl_count_here / $gl_per );
		$gl_base       = $this->member_url( $gl_owner->user_nicename );
		?>

		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status_filters().
		echo $this->status_filters( $gl_base, $gl_counts, $gl_total, $gl_active );
		?>

		<?php if ( empty( $gl_entries ) ) : ?>
			<?php
			$this->output(
				$this->empty_state(
					__( 'Empty shelf', 'game-library-3' ),
					__( 'This member has not added any games in this view yet.', 'game-library-3' )
				)
			);
			?>
		<?php else : ?>
			<h2 class="screen-reader-text"><?php esc_html_e( 'Games', 'game-library-3' ); ?></h2>
			<ul id="gl-library-grid" class="gl-library-grid">
				<?php
				$gl_i = 0;
				foreach ( $gl_entries as $gl_entry ) {
					$this->partial(
						'game-card',
						array(
							'entry' => $gl_entry,
							'owner' => false,
							'mode'  => 'member',
							'index' => $gl_i,
						)
					);
					++$gl_i;
				}
				?>
			</ul>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pagination().
			echo $this->pagination( $gl_base, $gl_page, $gl_pages, '' !== $gl_active ? array( 'gl_status' => $gl_active ) : array() );
			?>
		<?php endif; ?>
	<?php endif; ?>

	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in attribution().
	echo $this->attribution();
	?>
</div>
