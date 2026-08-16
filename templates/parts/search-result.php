<?php
/**
 * One IGDB search result (AC-008b).
 *
 * Rendered only by `POST /gamelib/v1/search` — a search result exists nowhere
 * at first paint — but it renders here, in a template, for the same reason
 * every other list item does: PHP is the single markup-and-escaping path, and
 * the fields on this card come straight from IGDB and are untrusted (ADR-002,
 * AC-NFR-002).
 *
 * The add control is server-rendered too, so the `/my-library/` bundle binds
 * behavior to markup it never has to compose (AC-011, AC-NFR-009b).
 *
 * Expected `$args`:
 * - `igdb_id`   (int)      IGDB id; the result's stable key.
 * - `name`      (string)   Game title. A result without one renders nothing.
 * - `cover_url` (string)   Thumbnail URL; empty leaves the reserved box empty.
 * - `year`      (string)   Release year; empty renders no year.
 * - `platforms` (string[]) Platform names; empty renders no platform line.
 * - `status`    (string)   The member's existing status for this game, when
 *                          they already have it; empty for a game they do not.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_name = isset( $args['name'] ) ? (string) $args['name'] : '';

if ( '' === $gamelib_name ) {
	return;
}

$gamelib_igdb_id   = isset( $args['igdb_id'] ) ? absint( $args['igdb_id'] ) : 0;
$gamelib_cover     = isset( $args['cover_url'] ) ? (string) $args['cover_url'] : '';
$gamelib_year      = isset( $args['year'] ) ? (string) $args['year'] : '';
$gamelib_platforms = ( isset( $args['platforms'] ) && is_array( $args['platforms'] ) ) ? $args['platforms'] : array();
$gamelib_status    = isset( $args['status'] ) ? (string) $args['status'] : '';

/*
 * Presentation labels for the four statuses in the §6 Data Model whitelist —
 * the same map `game-card.php` carries, for the same reason: the whitelist is
 * enforced on write, and a value outside it renders no label rather than an
 * unlabelled one.
 */
$gamelib_status_labels = array(
	'playing'  => _x( 'Playing', 'library status', 'game-library' ),
	'finished' => _x( 'Finished', 'library status', 'game-library' ),
	'backlog'  => _x( 'Backlog', 'library status', 'game-library' ),
	'wishlist' => _x( 'Wishlist', 'library status', 'game-library' ),
);

/*
 * Intrinsic dimensions of the rendition `GameLib_REST_Library::SEARCH_COVER_SIZE`
 * requests (PF-9). A search result is always below the fold and only ever
 * appears after a member-initiated request, so it keeps `loading="lazy"`.
 */
$gamelib_cover_size = GameLib_Game_Store::cover_dimensions( GameLib_REST_Library::SEARCH_COVER_SIZE );

$gamelib_select_id = 'gamelib-add-status-' . $gamelib_igdb_id;

$gamelib_add_label = sprintf(
	/* translators: %s: game name. */
	__( 'Add %s to your library', 'game-library' ),
	$gamelib_name
);

?>
<li class="gamelib-result" data-gamelib-igdb-id="<?php echo esc_attr( (string) $gamelib_igdb_id ); ?>">
	<div class="gamelib-result__media gamelib-card__media">
		<?php if ( '' !== $gamelib_cover ) : ?>
			<img
				class="gamelib-card__image"
				src="<?php echo esc_url( $gamelib_cover ); ?>"
				alt=""
				<?php if ( isset( $gamelib_cover_size[0], $gamelib_cover_size[1] ) ) : ?>
					width="<?php echo esc_attr( (string) $gamelib_cover_size[0] ); ?>"
					height="<?php echo esc_attr( (string) $gamelib_cover_size[1] ); ?>"
				<?php endif; ?>
				loading="lazy"
				decoding="async"
			/>
		<?php endif; ?>
	</div>
	<div class="gamelib-result__body">
		<h3 class="gamelib-result__title"><?php echo esc_html( $gamelib_name ); ?></h3>

		<?php if ( '' !== $gamelib_year ) : ?>
			<p class="gamelib-result__meta">
				<time datetime="<?php echo esc_attr( $gamelib_year ); ?>"><?php echo esc_html( $gamelib_year ); ?></time>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $gamelib_platforms ) ) : ?>
			<p class="gamelib-result__meta gamelib-result__platforms">
				<?php echo esc_html( implode( wp_get_list_item_separator(), $gamelib_platforms ) ); ?>
			</p>
		<?php endif; ?>

		<?php if ( isset( $gamelib_status_labels[ $gamelib_status ] ) ) : ?>
			<p class="gamelib-result__owned">
				<?php
				printf(
					/* translators: %s: library status the member already has this game in. */
					esc_html__( 'In your library — %s', 'game-library' ),
					esc_html( $gamelib_status_labels[ $gamelib_status ] )
				);
				?>
			</p>
		<?php endif; ?>

		<div class="gamelib-result__add">
			<label class="gamelib-visually-hidden" for="<?php echo esc_attr( $gamelib_select_id ); ?>">
				<?php
				printf(
					/* translators: %s: game name. */
					esc_html__( 'Status for %s', 'game-library' ),
					esc_html( $gamelib_name )
				);
				?>
			</label>
			<select
				id="<?php echo esc_attr( $gamelib_select_id ); ?>"
				class="gamelib-control gamelib-result__status"
				data-gamelib-add-status
			>
				<?php foreach ( $gamelib_status_labels as $gamelib_value => $gamelib_label ) : ?>
					<option
						value="<?php echo esc_attr( $gamelib_value ); ?>"
						<?php selected( $gamelib_value, GameLib_Library::STATUS_DEFAULT ); ?>
					>
						<?php echo esc_html( $gamelib_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button
				type="button"
				class="gamelib-control gamelib-result__add-button"
				data-gamelib-action="library.add"
				data-gamelib-igdb-id="<?php echo esc_attr( (string) $gamelib_igdb_id ); ?>"
				aria-label="<?php echo esc_attr( $gamelib_add_label ); ?>"
			>
				<?php esc_html_e( 'Add', 'game-library' ); ?>
			</button>
		</div>
	</div>
</li>
