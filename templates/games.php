<?php
/**
 * /games/ — the read-only game catalog.
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this    Template renderer.
 * @var array<string, mixed>    $context Route context.
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$gl_games = Plugin::instance()->games();

$gl_page   = $this->current_page();
$gl_per    = self::CATALOG_PER_PAGE;
$gl_offset = ( $gl_page - 1 ) * $gl_per;
$gl_rows   = $gl_games->get_catalog( $gl_per, $gl_offset );
$gl_total  = $gl_games->count_catalog();
$gl_pages  = (int) ceil( $gl_total / $gl_per );
$gl_base   = home_url( '/games/' );
?>
<div class="gl-container gl-container--wide">
	<header class="gl-page-head">
		<span class="gl-page-head__eyebrow"><?php esc_html_e( 'Everything played', 'game-library-3' ); ?></span>
		<h1><?php esc_html_e( 'Game Catalog', 'game-library-3' ); ?></h1>
	</header>

	<?php if ( empty( $gl_rows ) ) : ?>
		<?php
		$this->output(
			$this->empty_state(
				__( 'Empty catalog', 'game-library-3' ),
				__( 'No games have been added by any member yet.', 'game-library-3' )
			)
		);
		?>
	<?php else : ?>
		<h2 class="screen-reader-text"><?php esc_html_e( 'Games', 'game-library-3' ); ?></h2>
		<ul class="gl-library-grid gl-game-catalog-grid">
			<?php
			$gl_i = 0;
			foreach ( $gl_rows as $gl_row ) {
				$this->partial(
					'game-card',
					array(
						'entry' => $gl_row,
						'owner' => false,
						'mode'  => 'catalog',
						'index' => $gl_i,
					)
				);
				++$gl_i;
			}
			?>
		</ul>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pagination().
		echo $this->pagination( $gl_base, $gl_page, $gl_pages );
		?>
	<?php endif; ?>

	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in attribution().
	echo $this->attribution();
	?>
</div>
