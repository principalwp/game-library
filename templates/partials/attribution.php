<?php
/**
 * "Powered by IGDB.com" attribution partial (AC-046, DD-005).
 *
 * Expects, in scope:
 *
 * @var string|null $attribution_href Optional link target. Defaults to
 *                                    https://www.igdb.com/ when unset — every
 *                                    surface links there except
 *                                    `/games/{slug}/` (a later task), which
 *                                    passes that one game's own `igdb_url`
 *                                    instead.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$attribution_target = ! empty( $attribution_href ) ? $attribution_href : 'https://www.igdb.com/';
?>
<p class="gl-attribution">
	<a href="<?php echo esc_url( $attribution_target ); ?>" target="_blank" rel="noopener noreferrer">
		<?php esc_html_e( 'Powered by IGDB.com', 'game-library' ); ?>
	</a>
</p>
