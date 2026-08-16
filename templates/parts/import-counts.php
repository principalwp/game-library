<?php
/**
 * The counts region of an import job: the AC-042(d) unmatched sentence and the
 * per-bucket summary.
 *
 * Split out of `import-status.php` (PF-3) so a decision can answer with just
 * this region beside the row it moved, instead of making the client rebuild the
 * whole panel to change two numbers. The whole-job part renders it in place, so
 * a client-side update and a reload produce identical markup (ADR-002).
 *
 * Expected `$args`:
 * - `counts` (array) Bucket → row count, plus `total`, from
 *                    {@see GameLib_Importer::payload()}.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_counts = ( isset( $args['counts'] ) && is_array( $args['counts'] ) ) ? $args['counts'] : array();

/*
 * AC-042(d). "Couldn't be matched" is the set of rows this site could not
 * resolve to a game *for* the member: IGDB had no record of them
 * (`not_found`), or the row itself was unusable (`invalid`). Rows in
 * `needs_choice` are excluded deliberately — candidates were found for those,
 * and the status message above already counts them as needing a decision, so
 * counting them here as well would report one title twice under two
 * contradictory descriptions.
 */
$gamelib_unmatched = ( isset( $gamelib_counts[ GameLib_Importer::BUCKET_NOT_FOUND ] ) ? (int) $gamelib_counts[ GameLib_Importer::BUCKET_NOT_FOUND ] : 0 )
	+ ( isset( $gamelib_counts[ GameLib_Importer::BUCKET_INVALID ] ) ? (int) $gamelib_counts[ GameLib_Importer::BUCKET_INVALID ] : 0 );

?>
<div class="gamelib-import__counts" data-gamelib-counts>
	<?php if ( $gamelib_unmatched > 0 ) : ?>
		<p class="gamelib-import__unmatched" data-gamelib-unmatched="<?php echo esc_attr( (string) $gamelib_unmatched ); ?>">
			<?php
			printf(
				/* translators: %s: number of imported titles with no IGDB match. */
				esc_html(
					_n(
						'%s title couldn’t be matched.',
						'%s titles couldn’t be matched.',
						$gamelib_unmatched,
						'game-library'
					)
				),
				esc_html( number_format_i18n( $gamelib_unmatched ) )
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $gamelib_counts['total'] ) ) : ?>
		<ul class="gamelib-import__summary">
			<?php foreach ( GameLib_Importer::bucket_labels() as $gamelib_bucket => $gamelib_label ) : ?>
				<?php if ( ! empty( $gamelib_counts[ $gamelib_bucket ] ) ) : ?>
					<li class="gamelib-import__count" data-gamelib-bucket="<?php echo esc_attr( $gamelib_bucket ); ?>">
						<?php
						printf(
							/* translators: 1: bucket name, 2: number of rows in it. */
							esc_html__( '%1$s: %2$s', 'game-library' ),
							esc_html( $gamelib_label ),
							esc_html( number_format_i18n( (int) $gamelib_counts[ $gamelib_bucket ] ) )
						);
						?>
					</li>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
