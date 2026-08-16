<?php
/**
 * One game card — a library entry, owner or read-only (AC-013, AC-014,
 * AC-016).
 *
 * Expects, in scope:
 *
 * @var array<string,mixed> $card_game Hydrated game fields: `igdb_id`,
 *                                      `slug`, `name`, `cover_url` (string|
 *                                      null), `first_release_year` (int|
 *                                      null) — the same shape
 *                                      `Library_Controller::format_entry()`'s
 *                                      `game` key returns.
 * @var string|null $card_status               Status value (one of
 *                                              Game_Library\Statuses::all())
 *                                              to render as a badge, or null
 *                                              to omit the badge entirely.
 * @var bool        $card_show_status_selector  Whether to render an owner
 *                                               status selector (AC-013(e));
 *                                               only takes effect when
 *                                               `$card_status` is not null.
 * @var bool        $card_show_remove           Whether to render a Remove
 *                                               control (AC-013(f)).
 * @var bool        $card_is_priority           Optional, defaults to false.
 *                                               True only for the single
 *                                               card a calling template
 *                                               knows will be its route's
 *                                               LCP element — the first card
 *                                               that renders a cover (PF-1,
 *                                               cycle-8; not simply the first
 *                                               card by loop index, since a
 *                                               coverless card, `cover_url`
 *                                               being nullable per AC-005(f),
 *                                               renders no `<img>` for this
 *                                               flag to act on at all) — that
 *                                               card renders eagerly with
 *                                               `fetchpriority="high"` and
 *                                               no `loading` attribute so
 *                                               the preload scanner can find
 *                                               it; every other card stays
 *                                               `loading="lazy"` (PF-2).
 *                                               Never mark more than one
 *                                               card per page priority.
 *
 * Every id/class/data attribute below is derived from the game's stable
 * `igdb_id`, never a loop index (this task's own constraint). `library.js`
 * (this task) binds its status-change and remove handlers to
 * `[data-gl-status-select]`/`[data-gl-remove]` below.
 *
 * @package Game_Library
 */

use Game_Library\Igdb\Game_Mapper;
use Game_Library\Router;
use Game_Library\Statuses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$igdb_id       = isset( $card_game['igdb_id'] ) ? (int) $card_game['igdb_id'] : 0;
$name          = isset( $card_game['name'] ) ? (string) $card_game['name'] : '';
$slug          = isset( $card_game['slug'] ) ? (string) $card_game['slug'] : '';
$cover_url     = isset( $card_game['cover_url'] ) ? $card_game['cover_url'] : null;
// DES-50 (restyle §B row 9): the per-card release-year meta is dropped
// everywhere, so `first_release_year` is intentionally no longer read here —
// the calling templates still pass it, harmlessly, for shape compatibility.
$game_url      = '' !== $slug ? Router::game_url( $slug ) : '';
// $entry_status avoids the reserved $status WP global name
// (WordPress.WP.GlobalVariablesOverride).
$entry_status  = isset( $card_status ) ? (string) $card_status : null;
$show_select   = ! empty( $card_show_status_selector ) && null !== $entry_status;
$show_remove   = ! empty( $card_show_remove );
$is_priority   = ! empty( $card_is_priority );
// DES-38 (cycle-5): the shared source both <img> branches below and
// game-single.php's own hero <img> echo, rather than each hardcoding the
// literal "264"/"374" a third and fourth time — see Game_Mapper's own
// docblock.
$cover_width   = (string) Game_Mapper::COVER_BIG_WIDTH;
$cover_height  = (string) Game_Mapper::COVER_BIG_HEIGHT;
?>
<li class="gl-game-card" id="gl-entry-<?php echo esc_attr( (string) $igdb_id ); ?>" data-igdb-id="<?php echo esc_attr( (string) $igdb_id ); ?>">

	<?php
	// DES-50 (restyle §C, reworked): the cover is the quick-look trigger — a
	// REAL link to /games/{slug}/ (so it navigates with JS off), which
	// game-detail.js intercepts to EXPAND this card in place (an inline
	// disclosure, not a modal), revealing `.gl-game-card__detail` below. Wrap
	// only when a slug/URL exists; a coverless-and-slugless card renders the
	// cover box unwrapped rather than an empty-href link. `aria-expanded`/
	// `aria-controls` (replacing the former `aria-haspopup="dialog"`) describe
	// the disclosure the script toggles; with JS off the link just navigates
	// and the `[hidden]` detail region below never shows.
	?>
	<?php if ( $game_url ) : ?>
	<a href="<?php echo esc_url( $game_url ); ?>" class="gl-game-card__cover-link" data-gl-quicklook data-igdb-id="<?php echo esc_attr( (string) $igdb_id ); ?>" aria-expanded="false" aria-controls="gl-detail-<?php echo esc_attr( (string) $igdb_id ); ?>">
	<?php endif; ?>
		<div class="gl-game-card__cover">
			<?php if ( $cover_url ) : ?>
				<?php if ( $is_priority ) : ?>
					<img src="<?php echo esc_url( $cover_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" width="<?php echo esc_attr( $cover_width ); ?>" height="<?php echo esc_attr( $cover_height ); ?>" fetchpriority="high" decoding="async" />
				<?php else : ?>
					<img src="<?php echo esc_url( $cover_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" width="<?php echo esc_attr( $cover_width ); ?>" height="<?php echo esc_attr( $cover_height ); ?>" loading="lazy" decoding="async" />
				<?php endif; ?>
			<?php else : ?>
				<span class="gl-game-card__cover-placeholder"><?php echo esc_html( $name ); ?></span>
			<?php endif; ?>
		</div>
	<?php if ( $game_url ) : ?>
	</a>
	<?php endif; ?>

	<div class="gl-game-card__body">
		<p class="gl-game-card__title">
			<?php if ( $game_url ) : ?>
				<a href="<?php echo esc_url( $game_url ); ?>"><?php echo esc_html( $name ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $name ); ?>
			<?php endif; ?>
		</p>
		<?php
		// DES-50 (restyle §B row 9): the release-year meta is gone. On a
		// READ-ONLY card (no owner controls) the status badge sits in the body,
		// matching the design's read-only variant; on an owner card it moves
		// into the merged actions row below instead, beside the click-to-edit
		// trigger, so it never appears twice.
		?>
		<?php if ( null !== $entry_status && ! $show_select && ! $show_remove ) : ?>
			<?php
			$badge_status = $entry_status;
			include __DIR__ . '/status-badge.php';
			?>
		<?php endif; ?>
	</div>

	<?php // CO-7: the whole block is conditional — game-card.php is also included by game-catalog.php (/games/, anonymous) and member-library.php (/library/{nicename}/, read-only), neither of which enqueues library.js. $show_select/$show_remove line up exactly with the one route (owner library) that actually loads library.js. ?>
	<?php if ( $show_select || $show_remove ) : ?>
		<div class="gl-game-card__actions">
			<?php if ( null !== $entry_status ) : ?>
				<?php // DES-50 (restyle §B row 4): badge + a small cycle trigger library.js swaps for the <select> in place. Query hook: `.gl-game-card__status` (the display cluster), `[data-gl-status-trigger]` (the reveal button). ?>
				<span class="gl-game-card__status">
					<?php
					$badge_status = $entry_status;
					include __DIR__ . '/status-badge.php';
					?>
					<?php if ( $show_select ) : ?>
						<button
							type="button"
							class="gl-status-cycle-btn"
							data-gl-status-trigger
							aria-label="<?php
							printf(
								/* translators: %s: game title. */
								esc_attr__( 'Change status for %s', 'game-library' ),
								esc_attr( $name )
							);
							?>"
						>
							<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M13.25,4.37 A6.5,6.5 0 0,1 11.13,16.40" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" /><path d="M6.75,15.63 A6.5,6.5 0 0,1 8.87,3.60" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" /><polygon points="8.57,16.85 11.94,17.97 11.35,14.65" fill="currentColor" /><polygon points="11.43,3.15 8.06,2.03 8.65,5.35" fill="currentColor" /></svg>
						</button>
					<?php endif; ?>
				</span>
			<?php endif; ?>

			<?php if ( $show_select ) : ?>
				<?php // DES-50 (restyle §B row 5): initially hidden; library.js un-hides it (and hides `.gl-game-card__status`) on the cycle trigger. Each option is "●"-prefixed so the native control carries the matching dot open or closed. DES-102: the per-option colour is no longer an inline style here — the `.gl-game-card__actions select option[value=x]` rules in game-library.css (beside the closed-select `:has()` colouring) own the status->colour mapping in one place. ?>
				<select
					id="gl-status-select-<?php echo esc_attr( (string) $igdb_id ); ?>"
					data-gl-status-select
					data-igdb-id="<?php echo esc_attr( (string) $igdb_id ); ?>"
					data-current-status="<?php echo esc_attr( $entry_status ); ?>"
					aria-label="<?php
					printf(
						/* translators: %s: game title. */
						esc_attr__( 'Change status for %s', 'game-library' ),
						esc_attr( $name )
					);
					?>"
					hidden
				>
					<?php foreach ( Statuses::all() as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $entry_status, $option ); ?>>&#9679; <?php echo esc_html( Statuses::label( $option ) ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<?php if ( $show_remove ) : ?>
				<?php // DES-50 (restyle §B row 9/12): icon-only Remove — same element, data hook, and aria-label as before; only the visible text node becomes an inline SVG trash glyph. ?>
				<button
					type="button"
					class="gl-button gl-button--danger gl-button--icon"
					data-gl-remove
					data-igdb-id="<?php echo esc_attr( (string) $igdb_id ); ?>"
					title="<?php esc_attr_e( 'Remove', 'game-library' ); ?>"
					aria-label="<?php
					printf(
						/* translators: %s: game title. */
						esc_attr__( 'Remove %s from your library', 'game-library' ),
						esc_attr( $name )
					);
					?>"
				>
					<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><rect x="7.5" y="2.5" width="5" height="3" rx="1" fill="none" stroke="currentColor" stroke-width="1.3" /><rect x="3" y="5.3" width="14" height="1.5" rx="0.75" fill="currentColor" /><path d="M4.3 7.6 L5.5 17.3 L14.5 17.3 L15.7 7.6" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" /><path d="M8 9.5V15.5M12 9.5V15.5" stroke="currentColor" stroke-width="1.1" stroke-linecap="round" /></svg>
				</button>
			<?php endif; ?>

			<?php // MR-4: role="alert" so a screen reader announces a failed status change/remove. ?>
			<p class="gl-error-text" data-gl-card-error role="alert"></p>
		</div>
	<?php endif; ?>

	<?php
	// DES-50 (restyle §C, reworked): the quick-look detail lives INSIDE each
	// card now (it was a single shared <dialog> in site-footer.php). It starts
	// `[hidden]`; game-detail.js un-hides it on cover click, fetches
	// `GET /games/{igdb_id}` once (cached for the page), and fills the
	// `[data-gl-detail-*]` hooks below. The panel repeats the game title as its
	// own header (static, server-rendered) and adds what the card body omits: the
	// relocated release year (AC-005(e), §B row 9), genres, platforms, summary,
	// a `role="status"` live region for the loading/error state, and an
	// always-present "View full page" link — the structural no-dead-end
	// fallback (§C). Gated on `$game_url`, the same condition as the trigger.
	?>
	<?php if ( $game_url ) : ?>
		<div class="gl-game-card__detail" id="gl-detail-<?php echo esc_attr( (string) $igdb_id ); ?>" data-gl-detail hidden>
			<?php
			// Sideways-float restyle (this task): everything the region renders is
			// wrapped in this inner element so, on wide screens, game-library.css
			// can fade it in as its own animation stage independently of the outer
			// box's width — see .gl-game-card__detail-inner's own comment there. On
			// narrow screens this wrapper changes nothing visible (the outer above
			// still supplies the flex/gap/padding contract it always has).
			?>
			<div class="gl-game-card__detail-inner">
				<button
					type="button"
					class="gl-game-card__detail-close"
					data-gl-detail-close
					aria-label="<?php
					printf(
						/* translators: %s: game title. */
						esc_attr__( 'Close details for %s', 'game-library' ),
						esc_attr( $name )
					);
					?>"
				>
					<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 5 15 15M15 5 5 15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" /></svg>
				</button>
				<p class="gl-game-card__detail-title"><?php echo esc_html( $name ); ?></p>
				<p class="gl-game-card__detail-meta" data-gl-detail-year></p>
				<div class="gl-game-card__detail-row">
					<span class="gl-game-card__detail-label"><?php esc_html_e( 'Genres', 'game-library' ); ?></span>
					<ul class="gl-game-card__detail-list" data-gl-detail-genres></ul>
				</div>
				<div class="gl-game-card__detail-row">
					<span class="gl-game-card__detail-label"><?php esc_html_e( 'Platforms', 'game-library' ); ?></span>
					<ul class="gl-game-card__detail-list" data-gl-detail-platforms></ul>
				</div>
				<p class="gl-game-card__detail-summary" data-gl-detail-summary></p>
				<div class="gl-game-card__detail-status" data-gl-detail-status role="status" aria-live="polite"></div>
				<a class="gl-game-card__detail-fullpage" href="<?php echo esc_url( $game_url ); ?>"><?php esc_html_e( 'View full page', 'game-library' ); ?></a>
			</div>
		</div>
	<?php endif; ?>

</li>
