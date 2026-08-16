<?php
/**
 * One import job: its progress, its counts, and its rows.
 *
 * The single rendering of an import's state. The first paint of
 * `/my-library/imports/{id}/` and every poll of `GET /imports/{id}` come
 * through here, so the markup and its escaping exist once (ADR-002) — a
 * progress update swapped in by JS is byte-for-byte what a reload would have
 * produced.
 *
 * Row names come from the file (or, for a Steam import, from Steam) and are
 * therefore untrusted: every field is escaped here, at render time (Always Do
 * #1).
 *
 * Three of AC-042's surfaces are rendered here rather than composed by the
 * bundle, for the same reason everything else in this plugin is: a decision
 * re-renders the whole job, and a re-rendered job has to be byte-identical to
 * a reload (ADR-002, AC-042e).
 *
 * - A row still awaiting a decision carries its candidate list (or, on a
 *   not-found row, the manual IGDB search box and the appid's Steam store
 *   link) plus Skip — AC-042 (b),(c).
 * - The "N titles couldn't be matched" summary is computed from the stored
 *   counts, so it survives leaving and revisiting the URL — AC-042(d).
 * - Finish appears only while the job is in `review`; pressing it is what fires
 *   the import's single `games_imported` event — AC-044(a).
 *
 * The Retry control is here for a different reason: the stall it recovers from
 * (AC-043d) is a property of the job, not of the review UI.
 *
 * Expected `$args`:
 * - `import` (array) {@see GameLib_Importer::payload()}.
 * - `items`  (array) Rows keyed by bucket, from
 *                    {@see GameLib_Importer::report_items()}.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_import = ( isset( $args['import'] ) && is_array( $args['import'] ) ) ? $args['import'] : array();

if ( empty( $gamelib_import['id'] ) ) {
	return;
}

$gamelib_items  = ( isset( $args['items'] ) && is_array( $args['items'] ) ) ? $args['items'] : array();
$gamelib_counts = ( isset( $gamelib_import['counts'] ) && is_array( $gamelib_import['counts'] ) )
	? $gamelib_import['counts']
	: array();

/*
 * Bucket headings, in the order a member reads them: what happened, then what
 * still wants a decision, then what could not be used. Owned by the importer so
 * this part, the counts part, and the decision routes all name a bucket the
 * same way (PF-3).
 */
$gamelib_bucket_labels = GameLib_Importer::bucket_labels();

?>
<div
	class="gamelib-import"
	data-gamelib-import-id="<?php echo esc_attr( (string) (int) $gamelib_import['id'] ); ?>"
	data-gamelib-import-status="<?php echo esc_attr( (string) $gamelib_import['status'] ); ?>"
	data-gamelib-import-active="<?php echo empty( $gamelib_import['active'] ) ? 'false' : 'true'; ?>"
	<?php
	/*
	 * The digest of everything rendered below (PF-4). The poller round-trips it
	 * so an unchanged tick answers without this whole fragment — and it is
	 * emitted on the *first paint* too, so the very first poll after a page load
	 * already knows what is on screen.
	 */
	?>
	data-gamelib-fingerprint="<?php echo esc_attr( isset( $gamelib_import['fingerprint'] ) ? (string) $gamelib_import['fingerprint'] : '' ); ?>"
>
	<p class="gamelib-import__status" role="status">
		<?php echo esc_html( (string) $gamelib_import['message'] ); ?>
	</p>

	<?php if ( ! empty( $gamelib_import['active'] ) ) : ?>
		<p class="gamelib-import__progress">
			<progress
				max="100"
				value="<?php echo esc_attr( (string) (int) $gamelib_import['progress'] ); ?>"
			><?php echo esc_html( sprintf( '%d%%', (int) $gamelib_import['progress'] ) ); ?></progress>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $gamelib_import['can_retry'] ) ) : ?>
		<p class="gamelib-import__retry">
			<?php
			/*
			 * `gamelib-control`, not `gamelib-button`: the latter has no rule
			 * anywhere in the stylesheet, so this control measured 113×21 —
			 * under the AC-NFR-004(c) 24px floor every other control clears
			 * (DES-2).
			 */
			?>
			<button
				type="button"
				class="gamelib-control"
				data-gamelib-action="import.retry"
				data-gamelib-import-id="<?php echo esc_attr( (string) (int) $gamelib_import['id'] ); ?>"
			>
				<?php esc_html_e( 'Retry this import', 'game-library' ); ?>
			</button>
		</p>
	<?php endif; ?>

	<?php GameLib_Router::part( 'import-counts', array( 'counts' => $gamelib_counts ) ); ?>

	<?php if ( GameLib_Importer::STATUS_REVIEW === $gamelib_import['status'] ) : ?>
		<p class="gamelib-import__finish">
			<button
				type="button"
				class="gamelib-control gamelib-import__finish-button"
				data-gamelib-action="import.finish"
				data-gamelib-import-id="<?php echo esc_attr( (string) (int) $gamelib_import['id'] ); ?>"
			>
				<?php esc_html_e( 'Finish import', 'game-library' ); ?>
			</button>
			<span class="gamelib-import__finish-hint">
				<?php esc_html_e( 'Anything you have not decided stays on this page — you can come back to it later.', 'game-library' ); ?>
			</span>
		</p>
	<?php endif; ?>

	<?php foreach ( $gamelib_bucket_labels as $gamelib_bucket => $gamelib_label ) : ?>
		<?php if ( ! empty( $gamelib_items[ $gamelib_bucket ] ) && is_array( $gamelib_items[ $gamelib_bucket ] ) ) : ?>
		<section class="gamelib-import__bucket" data-gamelib-bucket="<?php echo esc_attr( $gamelib_bucket ); ?>">
			<h3 class="gamelib-import__bucket-title" tabindex="-1">
				<?php
				/*
				 * The count is an element of its own so a decision that moves one
				 * row can write the server's freshly formatted number into it
				 * rather than re-rendering the heading (PF-3) — the same shape the
				 * library pager uses for its two numbers.
				 */
				printf(
					/* translators: 1: bucket name, 2: number of rows in it. */
					esc_html__( '%1$s (%2$s)', 'game-library' ),
					esc_html( $gamelib_label ),
					'<span data-gamelib-bucket-count>' . esc_html( number_format_i18n( (int) $gamelib_counts[ $gamelib_bucket ] ) ) . '</span>'
				);
				?>
			</h3>
			<ul class="gamelib-import__rows" data-gamelib-rows>
				<?php foreach ( $gamelib_items[ $gamelib_bucket ] as $gamelib_item ) : ?>
					<?php
					if ( ! is_array( $gamelib_item ) ) {
						continue;
					}

					GameLib_Router::part(
						'import-row',
						array(
							'item'   => $gamelib_item,
							'bucket' => $gamelib_bucket,
						)
					);
					?>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php endif; ?>
	<?php endforeach; ?>
</div>
