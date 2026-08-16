<?php
/**
 * One row of an import job.
 *
 * Split out of `import-status.php` (PF-3) so a decision can answer with the one
 * row it moved instead of the whole job. Both callers render the same markup:
 * the whole-job part loops over this for every bucket, and the choose/skip
 * routes render it once for the row that just moved, so a client-side move and
 * a reload produce identical markup (ADR-002, AC-042e).
 *
 * Row names come from the file (or, for a Steam import, from Steam) and are
 * therefore untrusted: every field is escaped here, at render time (Always Do
 * #1).
 *
 * Expected `$args`:
 * - `item`   (array)  One row from {@see GameLib_Importer::report_items()}.
 * - `bucket` (string) Bucket the row is being rendered in; decides whether it
 *                     carries decision controls.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_item = ( isset( $args['item'] ) && is_array( $args['item'] ) ) ? $args['item'] : array();

if ( empty( $gamelib_item['id'] ) ) {
	return;
}

$gamelib_bucket = isset( $args['bucket'] ) ? (string) $args['bucket'] : '';
$gamelib_name   = isset( $gamelib_item['name'] ) ? (string) $gamelib_item['name'] : '';
$gamelib_note   = isset( $gamelib_item['note'] ) ? (string) $gamelib_item['note'] : '';

if ( '' === $gamelib_name ) {
	$gamelib_name = sprintf(
		/* translators: %d: IGDB game id. */
		__( 'IGDB #%d', 'game-library' ),
		isset( $gamelib_item['igdb_id'] ) ? (int) $gamelib_item['igdb_id'] : 0
	);
}

$gamelib_appid     = isset( $gamelib_item['steam_appid'] ) ? absint( $gamelib_item['steam_appid'] ) : 0;
$gamelib_not_found = ( GameLib_Importer::BUCKET_NOT_FOUND === $gamelib_bucket );

// The two buckets a member can still act on: candidates to pick from, and rows
// IGDB had nothing for. Everything else on this screen is a report.
$gamelib_open = in_array(
	$gamelib_bucket,
	array( GameLib_Importer::BUCKET_NEEDS_CHOICE, GameLib_Importer::BUCKET_NOT_FOUND ),
	true
);

$gamelib_search_id = 'gamelib-import-search-' . (int) $gamelib_item['id'];

$gamelib_skip_label = sprintf(
	/* translators: %s: the imported title being skipped. */
	__( 'Skip %s', 'game-library' ),
	$gamelib_name
);

?>
<li class="gamelib-import__row" data-gamelib-item-id="<?php echo esc_attr( (string) (int) $gamelib_item['id'] ); ?>">
	<span class="gamelib-import__row-name"><?php echo esc_html( $gamelib_name ); ?></span>
	<?php if ( '' !== $gamelib_note ) : ?>
		<span class="gamelib-import__row-note"><?php echo esc_html( $gamelib_note ); ?></span>
	<?php endif; ?>

	<?php if ( $gamelib_not_found && $gamelib_appid > 0 ) : ?>
		<span class="gamelib-import__row-appid">
			<?php
			printf(
				/* translators: %s: Steam application id. */
				esc_html__( 'Steam app %s', 'game-library' ),
				esc_html( (string) $gamelib_appid )
			);
			?>
		</span>
		<a
			class="gamelib-import__store-link"
			href="<?php echo esc_url( 'https://store.steampowered.com/app/' . $gamelib_appid . '/' ); ?>"
			rel="nofollow noopener external"
		>
			<?php esc_html_e( 'Open this title’s Steam store page', 'game-library' ); ?>
		</a>
	<?php endif; ?>

	<?php if ( $gamelib_open ) : ?>
		<?php if ( $gamelib_not_found ) : ?>
			<div class="gamelib-import__search">
				<label class="gamelib-visually-hidden" for="<?php echo esc_attr( $gamelib_search_id ); ?>">
					<?php
					printf(
						/* translators: %s: the imported title being matched. */
						esc_html__( 'Search IGDB for a match for %s', 'game-library' ),
						esc_html( $gamelib_name )
					);
					?>
				</label>
				<input
					type="search"
					id="<?php echo esc_attr( $gamelib_search_id ); ?>"
					class="gamelib-control gamelib-import__search-field"
					value="<?php echo esc_attr( $gamelib_name ); ?>"
					data-gamelib-import-search
				/>
				<button
					type="button"
					class="gamelib-control gamelib-import__search-button"
					data-gamelib-action="import.search"
					data-gamelib-item-id="<?php echo esc_attr( (string) (int) $gamelib_item['id'] ); ?>"
				>
					<?php esc_html_e( 'Search', 'game-library' ); ?>
				</button>
			</div>
		<?php endif; ?>

		<?php
		GameLib_Router::part(
			'import-candidates',
			array(
				'item_id'    => (int) $gamelib_item['id'],
				'name'       => $gamelib_name,
				'candidates' => isset( $gamelib_item['candidates'] ) ? $gamelib_item['candidates'] : array(),
			)
		);
		?>

		<button
			type="button"
			class="gamelib-control gamelib-import__skip"
			data-gamelib-action="import.skip"
			data-gamelib-item-id="<?php echo esc_attr( (string) (int) $gamelib_item['id'] ); ?>"
			aria-label="<?php echo esc_attr( $gamelib_skip_label ); ?>"
		>
			<?php esc_html_e( 'Skip', 'game-library' ); ?>
		</button>
	<?php endif; ?>
</li>
