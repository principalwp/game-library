<?php
/**
 * The games one unresolved import row may be attached to (AC-042 b,c).
 *
 * One part, two sources: the candidate list the Steam cascade stored on a
 * `needs_choice` row (AC-041c), and the results of the manual IGDB search a
 * member ran on a `not-found` row (AC-042c). Both are the same offer — *is this
 * the game?* — and both are answered by the same control, so they render
 * through one file and are escaped in one place (ADR-002). Names come from
 * IGDB and are untrusted.
 *
 * The wrapper always renders, even with nothing to show: it is the element the
 * review bundle swaps a search result into, so it has to exist on a row that
 * has never been searched.
 *
 * Expected `$args`:
 * - `item_id`    (int)      Row these candidates belong to. Without one the
 *                           part renders nothing — a candidate control with no
 *                           row to attach to is not renderable.
 * - `name`       (string)   The row's source name, for the controls' accessible names.
 * - `candidates` (array[])  `{igdb_id, name, year}` rows, in offer order.
 * - `message`    (string)   Rendered instead of the list when there are none.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_item_id = isset( $args['item_id'] ) ? absint( $args['item_id'] ) : 0;

if ( $gamelib_item_id < 1 ) {
	return;
}

$gamelib_row_name   = isset( $args['name'] ) ? (string) $args['name'] : '';
$gamelib_candidates = ( isset( $args['candidates'] ) && is_array( $args['candidates'] ) ) ? $args['candidates'] : array();
$gamelib_message    = isset( $args['message'] ) ? (string) $args['message'] : '';

?>
<div class="gamelib-import__candidates" data-gamelib-candidates="<?php echo esc_attr( (string) $gamelib_item_id ); ?>">
	<?php if ( ! empty( $gamelib_candidates ) ) : ?>
		<ul class="gamelib-import__candidate-list">
			<?php foreach ( $gamelib_candidates as $gamelib_candidate ) : ?>
				<?php
				if ( ! is_array( $gamelib_candidate ) ) {
					continue;
				}

				$gamelib_candidate_id   = isset( $gamelib_candidate['igdb_id'] ) ? absint( $gamelib_candidate['igdb_id'] ) : 0;
				$gamelib_candidate_name = isset( $gamelib_candidate['name'] ) ? (string) $gamelib_candidate['name'] : '';

				// Never Do #10: a control that would add a library row without an
				// IGDB id is not rendered at all.
				if ( $gamelib_candidate_id < 1 || '' === $gamelib_candidate_name ) {
					continue;
				}

				$gamelib_candidate_year = isset( $gamelib_candidate['year'] ) ? (string) $gamelib_candidate['year'] : '';

				$gamelib_choose_label = ( '' === $gamelib_row_name )
					? sprintf(
						/* translators: %s: candidate game name. */
						__( 'Add %s to your library', 'game-library' ),
						$gamelib_candidate_name
					)
					: sprintf(
						/* translators: 1: candidate game name, 2: the imported title it would be matched to. */
						__( 'Add %1$s to your library as the match for %2$s', 'game-library' ),
						$gamelib_candidate_name,
						$gamelib_row_name
					);
				?>
				<li class="gamelib-import__candidate">
					<span class="gamelib-import__candidate-name"><?php echo esc_html( $gamelib_candidate_name ); ?></span>
					<?php if ( '' !== $gamelib_candidate_year ) : ?>
						<span class="gamelib-import__candidate-year"><?php echo esc_html( $gamelib_candidate_year ); ?></span>
					<?php endif; ?>
					<button
						type="button"
						class="gamelib-control gamelib-import__choose"
						data-gamelib-action="import.choose"
						data-gamelib-item-id="<?php echo esc_attr( (string) $gamelib_item_id ); ?>"
						data-gamelib-igdb-id="<?php echo esc_attr( (string) $gamelib_candidate_id ); ?>"
						aria-label="<?php echo esc_attr( $gamelib_choose_label ); ?>"
					>
						<?php esc_html_e( 'Add', 'game-library' ); ?>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( '' !== $gamelib_message ) : ?>
		<?php
		GameLib_Router::part(
			'list-state',
			array(
				'message' => $gamelib_message,
				'state'   => GameLib_REST_Library::STATE_SEARCH_EMPTY,
				'tag'     => 'p',
			)
		);
		?>
	<?php endif; ?>
</div>
