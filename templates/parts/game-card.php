<?php
/**
 * One game card.
 *
 * The single rendering of a library entry: the first paint and the REST HTML
 * fragments both come through here, so the markup and its escaping exist once
 * (ADR-002). Fields (a)–(d) of AC-014 live in this part unconditionally; the
 * owner's controls — the bulk-select checkbox (AC-019a), the four-way status
 * radiogroup and remove (AC-014 e,f) — render only when `controls` is passed,
 * so a read-only rendering (a member's public library, AC-031c) is the
 * identical markup with nothing interactive in it. Opt-in rather than opt-out
 * on purpose: a future caller that forgets the flag renders a card that does
 * nothing, not one that offers to edit somebody else's library.
 *
 * The status control is an ARIA radiogroup rather than four toggle buttons:
 * exactly one of the four is true at a time, so `aria-checked` on radios is what
 * a screen reader announces as "Playing, radio button, 2 of 4, selected"
 * (AC-NFR-004b). Only the selected option is in the tab order — the roving
 * tabindex the pattern requires — and the bundle moves it with the arrow keys.
 *
 * Titles come from IGDB and are untrusted: every field is escaped here, at
 * render time (Always Do #1).
 *
 * Expected `$args`:
 * - `igdb_id`   (int)    IGDB id; the card's stable key.
 * - `name`      (string) Game title. A card without one renders nothing.
 * - `permalink` (string) Public game page URL; empty renders the title unlinked.
 * - `cover_url` (string) Cover image URL; empty leaves the reserved box empty.
 * - `status`    (string) `playing`|`finished`|`backlog`|`wishlist`; empty renders no badge.
 * - `added_at`  (string) UTC `Y-m-d H:i:s`, rendered in the site's timezone.
 * - `controls`  (bool)   Render the owner's status and remove controls.
 * - `priority`  (bool)   This card is inside the initial viewport: fetch its
 *                        cover eagerly rather than lazily, and render it
 *                        without the deferred-rendering containment the rest of
 *                        the grid carries.
 * - `lcp`       (bool)   This card's cover is the page's LCP candidate: the one
 *                        image that asks for `fetchpriority="high"`.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_name = isset( $args['name'] ) ? (string) $args['name'] : '';

if ( '' === $gamelib_name ) {
	return;
}

$gamelib_igdb_id   = isset( $args['igdb_id'] ) ? absint( $args['igdb_id'] ) : 0;
$gamelib_permalink = isset( $args['permalink'] ) ? (string) $args['permalink'] : '';
$gamelib_cover     = isset( $args['cover_url'] ) ? (string) $args['cover_url'] : '';
$gamelib_srcset    = isset( $args['cover_srcset'] ) ? (string) $args['cover_srcset'] : '';
$gamelib_status    = isset( $args['status'] ) ? (string) $args['status'] : '';
$gamelib_added_at  = isset( $args['added_at'] ) ? (string) $args['added_at'] : '';

/*
 * Display labels for the four statuses in the §6 Data Model whitelist. The
 * whitelist itself is enforced on write; this map is presentation only, and a
 * value outside it renders no badge rather than an unlabelled one.
 */
$gamelib_status_labels = array(
	'playing'  => _x( 'Playing', 'library status', 'game-library' ),
	'finished' => _x( 'Finished', 'library status', 'game-library' ),
	'backlog'  => _x( 'Backlog', 'library status', 'game-library' ),
	'wishlist' => _x( 'Wishlist', 'library status', 'game-library' ),
);

$gamelib_added_ts = ( '' === $gamelib_added_at ) ? false : strtotime( $gamelib_added_at . ' UTC' );

/*
 * The grid is the LCP element on `/my-library/` and `/members/{nicename}/`, and
 * a lazy image is invisible to the preload scanner and fetched at Low priority.
 * The first row of cards therefore opts out of lazy loading (PF-2); everything
 * below the fold keeps `loading="lazy"`.
 *
 * Eagerness and priority are two flags, not one (PF-5). Only one image on a
 * page can be the LCP element, and marking all four of the first row
 * `fetchpriority="high"` put them in one tier where they split early bandwidth
 * four ways — cancelling most of the ordering the attribute exists to buy. The
 * first card asks for the tier; its three neighbours stay eager, which is what
 * keeps them out of the lazy queue and visible to the preload scanner.
 */
$gamelib_lcp      = ! empty( $args['lcp'] );
$gamelib_priority = $gamelib_lcp || ! empty( $args['priority'] );

/*
 * Intrinsic dimensions of the rendition every caller of this part requests, so
 * the box is reserved by the markup and not only by the stylesheet's
 * `aspect-ratio` (PF-9).
 */
$gamelib_cover_size = GameLib_Game_Store::cover_dimensions( GameLib_Game_Store::CARD_COVER_SIZE );

$gamelib_controls = ! empty( $args['controls'] ) && $gamelib_igdb_id > 0;

/*
 * The radiogroup's roving tabindex: the selected option is the group's single
 * tab stop. An entry whose status somehow sits outside the whitelist would leave
 * every option at -1 and the group unreachable, so the first option takes the
 * tab stop in that case.
 */
$gamelib_tab_stop = isset( $gamelib_status_labels[ $gamelib_status ] )
	? $gamelib_status
	: (string) key( $gamelib_status_labels );

$gamelib_group_label    = '';
$gamelib_remove_label   = '';
$gamelib_remove_confirm = '';
$gamelib_select_label   = '';
$gamelib_select_id      = '';

if ( $gamelib_controls ) {
	$gamelib_group_label = sprintf(
		/* translators: %s: game name. */
		__( 'Status for %s', 'game-library' ),
		$gamelib_name
	);

	/*
	 * AC-019(a): the bulk-select checkbox. It is rendered on every owner card,
	 * on the first paint and in every REST fragment alike, and revealed by the
	 * `gamelib-library--selecting` modifier the bundle puts on the panel — a
	 * checkbox the bundle had to inject would be markup and an accessible name
	 * composed client-side, which ADR-002 puts in PHP.
	 *
	 * See principal/adr/017-bulk-select-checkbox-in-the-card-part.md — this file
	 * is outside Task 20's Files list, and it is the plugin's only rendering
	 * path for a card.
	 */
	$gamelib_select_label = sprintf(
		/* translators: %s: game name. */
		__( 'Select %s', 'game-library' ),
		$gamelib_name
	);

	$gamelib_select_id = 'gamelib-select-' . $gamelib_igdb_id;

	$gamelib_remove_label = sprintf(
		/* translators: %s: game name. */
		__( 'Remove %s from your library', 'game-library' ),
		$gamelib_name
	);

	/*
	 * AC-016's confirmation copy is authored here, in PHP, and read off the
	 * control — the bundle composes no member-facing sentence.
	 */
	$gamelib_remove_confirm = sprintf(
		/* translators: %s: game name. */
		__( 'Remove “%s” from your library?', 'game-library' ),
		$gamelib_name
	);
}

?>
<?php
/*
 * `gamelib-card--readonly` is not decoration — it is what lets the stylesheet
 * reserve the right height for a card with no controls (PF-3).
 * `content-visibility: auto` needs a `contain-intrinsic-size` placeholder for
 * every card below the fold, and the owner card's is calibrated to an owner
 * card. A read-only rendering drops the whole `gamelib-card__controls` block —
 * the bulk-select row, the four-button status radiogroup and Remove — so it is
 * several rem shorter, and on /members/{nicename}/, the plugin's one
 * logged-out, field-measured surface, every unrendered row over-reserved on the
 * first scroll pass.
 */
$gamelib_card_classes = array( 'gamelib-card' );

if ( $gamelib_priority ) {
	$gamelib_card_classes[] = 'gamelib-card--priority';
}

if ( ! $gamelib_controls ) {
	$gamelib_card_classes[] = 'gamelib-card--readonly';
}
?>
<li class="<?php echo esc_attr( implode( ' ', $gamelib_card_classes ) ); ?>" data-gamelib-igdb-id="<?php echo esc_attr( (string) $gamelib_igdb_id ); ?>">
	<div class="gamelib-card__media">
		<?php if ( '' !== $gamelib_cover ) : ?>
			<img
				class="gamelib-card__image"
				src="<?php echo esc_url( $gamelib_cover ); ?>"
				<?php if ( '' !== $gamelib_srcset ) : ?>
					<?php
					/*
					 * The grid track is `minmax(11rem, 1fr)`, so 11rem is the
					 * painted box's floor and the honest lower bound to declare
					 * (CO-1). `srcset` carries each rendition's intrinsic width,
					 * so the browser resolves the pair against its own device
					 * pixel ratio. Both attributes are raw URLs composed by
					 * GameLib_Game_Store and escaped here, at render time.
					 */
					?>
					srcset="<?php echo esc_attr( $gamelib_srcset ); ?>"
					sizes="11rem"
				<?php endif; ?>
				alt=""
				<?php if ( isset( $gamelib_cover_size[0], $gamelib_cover_size[1] ) ) : ?>
					width="<?php echo esc_attr( (string) $gamelib_cover_size[0] ); ?>"
					height="<?php echo esc_attr( (string) $gamelib_cover_size[1] ); ?>"
				<?php endif; ?>
				<?php if ( $gamelib_lcp ) : ?>
					fetchpriority="high"
				<?php endif; ?>
				<?php if ( ! $gamelib_priority ) : ?>
					loading="lazy"
				<?php endif; ?>
				decoding="async"
			/>
		<?php endif; ?>
	</div>
	<div class="gamelib-card__body">
		<h3 class="gamelib-card__title">
			<?php if ( '' !== $gamelib_permalink ) : ?>
				<a href="<?php echo esc_url( $gamelib_permalink ); ?>"><?php echo esc_html( $gamelib_name ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $gamelib_name ); ?>
			<?php endif; ?>
		</h3>

		<?php if ( isset( $gamelib_status_labels[ $gamelib_status ] ) ) : ?>
			<p class="gamelib-card__meta">
				<span class="gamelib-badge gamelib-badge--<?php echo esc_attr( $gamelib_status ); ?>">
					<?php echo esc_html( $gamelib_status_labels[ $gamelib_status ] ); ?>
				</span>
			</p>
		<?php endif; ?>

		<?php if ( false !== $gamelib_added_ts ) : ?>
			<p class="gamelib-card__meta">
				<time datetime="<?php echo esc_attr( gmdate( 'c', $gamelib_added_ts ) ); ?>">
					<?php
					printf(
						/* translators: %s: date a game was added to the library. */
						esc_html__( 'Added %s', 'game-library' ),
						esc_html( wp_date( (string) get_option( 'date_format' ), $gamelib_added_ts ) )
					);
					?>
				</time>
			</p>
		<?php endif; ?>

		<?php
		/*
		 * The entry's controls (AC-014 e,f). The bulk-select checkbox (AC-019a)
		 * joins them here. Read-only renderings pass no `controls` flag and get
		 * none of this.
		 */
		?>
		<?php if ( $gamelib_controls ) : ?>
			<div class="gamelib-card__controls">
				<p class="gamelib-card__select" data-gamelib-card-select>
					<input
						type="checkbox"
						id="<?php echo esc_attr( $gamelib_select_id ); ?>"
						class="gamelib-card__checkbox"
						data-gamelib-action="library.bulk-select"
						data-gamelib-bulk-id="<?php echo esc_attr( (string) $gamelib_igdb_id ); ?>"
					/>
					<label class="gamelib-card__select-label" for="<?php echo esc_attr( $gamelib_select_id ); ?>">
						<?php echo esc_html( $gamelib_select_label ); ?>
					</label>
				</p>

				<div
					class="gamelib-status"
					role="radiogroup"
					aria-label="<?php echo esc_attr( $gamelib_group_label ); ?>"
				>
					<?php foreach ( $gamelib_status_labels as $gamelib_value => $gamelib_label ) : ?>
						<button
							type="button"
							role="radio"
							class="gamelib-control gamelib-status__option gamelib-status__option--<?php echo esc_attr( $gamelib_value ); ?>"
							aria-checked="<?php echo ( $gamelib_value === $gamelib_status ) ? 'true' : 'false'; ?>"
							tabindex="<?php echo ( $gamelib_value === $gamelib_tab_stop ) ? '0' : '-1'; ?>"
							data-gamelib-action="library.status"
							data-gamelib-igdb-id="<?php echo esc_attr( (string) $gamelib_igdb_id ); ?>"
							data-gamelib-status="<?php echo esc_attr( $gamelib_value ); ?>"
						>
							<?php echo esc_html( $gamelib_label ); ?>
						</button>
					<?php endforeach; ?>
				</div>

				<button
					type="button"
					class="gamelib-control gamelib-card__remove"
					data-gamelib-action="library.remove"
					data-gamelib-igdb-id="<?php echo esc_attr( (string) $gamelib_igdb_id ); ?>"
					data-gamelib-confirm="<?php echo esc_attr( $gamelib_remove_confirm ); ?>"
					aria-label="<?php echo esc_attr( $gamelib_remove_label ); ?>"
				>
					<?php esc_html_e( 'Remove', 'game-library' ); ?>
				</button>
			</div>
		<?php endif; ?>
	</div>
</li>
