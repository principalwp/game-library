<?php
/**
 * /my-library/ — the acting member's cover-forward library.
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this    Template renderer.
 * @var array<string, mixed>    $context Route context.
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$gl_user_id = get_current_user_id();
$gl_user    = wp_get_current_user();
$gl_plugin  = Plugin::instance();
$gl_library = $gl_plugin->library();
$gl_vis     = $gl_plugin->visibility();

$gl_counts = $gl_library->status_counts( $gl_user_id );
$gl_total  = array_sum( $gl_counts );

$gl_active = sanitize_key( (string) get_query_var( 'gl_status' ) );
if ( ! Library_Repository::is_status( $gl_active ) ) {
	$gl_active = '';
}

$gl_page       = $this->current_page();
$gl_per        = self::LIBRARY_PER_PAGE;
$gl_offset     = ( $gl_page - 1 ) * $gl_per;
$gl_entries    = $gl_library->get_library( $gl_user_id, '' !== $gl_active ? $gl_active : null, $gl_per, $gl_offset );
$gl_count_here = ( '' !== $gl_active ) ? (int) $gl_counts[ $gl_active ] : $gl_total;
$gl_pages      = (int) ceil( $gl_count_here / $gl_per );
$gl_base       = home_url( '/my-library/' );
?>
<div class="gl-header-band">
	<div class="gl-container gl-container--wide">
		<span class="gl-header-band__eyebrow"><?php esc_html_e( 'My Collection', 'game-library-3' ); ?></span>
		<h1><?php esc_html_e( 'My Library', 'game-library-3' ); ?></h1>
		<p class="gl-header-band__sub">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of games. */
					_n( '%d game tracked', '%d games tracked', $gl_total, 'game-library-3' ),
					$gl_total
				)
			);
			?>
		</p>
		<div class="gl-header-band__actions">
			<a class="gl-button gl-button--secondary" href="<?php echo esc_url( home_url( '/games/' ) ); ?>"><?php esc_html_e( 'Browse the catalog', 'game-library-3' ); ?></a>
		</div>
	</div>
</div>

<div class="gl-container gl-container--wide">
	<section class="gl-search" aria-label="<?php esc_attr_e( 'Add a game', 'game-library-3' ); ?>">
		<form class="gl-search-form" id="gl-search-form" role="search">
			<label class="screen-reader-text" for="gl-search-input"><?php esc_html_e( 'Search IGDB for a game', 'game-library-3' ); ?></label>
			<input type="search" id="gl-search-input" name="gl_search" placeholder="<?php esc_attr_e( 'Search IGDB for a game…', 'game-library-3' ); ?>" autocomplete="off" />
			<button type="submit" class="gl-button gl-button--primary"><?php esc_html_e( 'Search', 'game-library-3' ); ?></button>
		</form>
		<div id="gl-search-results" aria-live="polite"></div>
	</section>

	<?php $this->partial( 'visibility-toggle', array( 'is_public' => $gl_vis->is_public( $gl_user_id ) ) ); ?>

	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped in status_filters().
	echo $this->status_filters( $gl_base, $gl_counts, $gl_total, $gl_active );
	?>

	<?php if ( 0 === $gl_total ) : ?>
		<?php
		$this->output(
			$this->empty_state(
				__( 'Nothing here yet', 'game-library-3' ),
				__( 'Search above to add the first game to your library.', 'game-library-3' )
			)
		);
		?>
	<?php endif; ?>

	<?php // The grid is always present so a first add can insert without a reload. ?>
	<h2 class="screen-reader-text"><?php esc_html_e( 'Games', 'game-library-3' ); ?></h2>
	<ul id="gl-library-grid" class="gl-library-grid">
		<?php
		$gl_i = 0;
		foreach ( $gl_entries as $gl_entry ) {
			$this->partial(
				'game-card',
				array(
					'entry' => $gl_entry,
					'owner' => true,
					'mode'  => 'owner',
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

	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in attribution().
	echo $this->attribution();
	?>
</div>
<?php $this->partial( 'game-detail-panel' ); ?>
