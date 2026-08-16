<?php
/**
 * IGDB search form + results region (AC-013(g), AC-004).
 *
 * Rendered on `/my-library/` only (the only front-end route this task's own
 * `search.js` runs on). All five search states (AC-004) are driven entirely
 * by that script against the `#gl-search-results` element below — this file
 * only establishes the idle-state markup and the persistent `aria-live`
 * region every later state renders into.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="gl-search" id="gl-search">
	<form class="gl-search-form" id="gl-search-form" role="search">
		<label for="gl-search-input"><?php esc_html_e( 'Search IGDB for a game', 'game-library' ); ?></label>
		<input
			type="search"
			id="gl-search-input"
			name="q"
			autocomplete="off"
			placeholder="<?php esc_attr_e( 'Search for a game…', 'game-library' ); ?>"
		/>
	</form>
	<div id="gl-search-results" class="gl-search-results" aria-live="polite" aria-busy="false" hidden></div>
</div>
