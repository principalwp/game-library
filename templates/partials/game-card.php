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
$release_year  = isset( $card_game['first_release_year'] ) ? $card_game['first_release_year'] : null;
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

	<div class="gl-game-card__body">
		<p class="gl-game-card__title">
			<?php if ( $game_url ) : ?>
				<a href="<?php echo esc_url( $game_url ); ?>"><?php echo esc_html( $name ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $name ); ?>
			<?php endif; ?>
		</p>
		<p class="gl-game-card__meta">
			<?php echo null !== $release_year ? esc_html( (string) $release_year ) : esc_html__( 'Unreleased', 'game-library' ); ?>
		</p>
		<?php if ( null !== $entry_status ) : ?>
			<?php
			$badge_status = $entry_status;
			include __DIR__ . '/status-badge.php';
			?>
		<?php endif; ?>
	</div>

	<?php // CO-7: the whole block is conditional, not just its two controls — game-card.php is also included by game-catalog.php (/games/, anonymous) and member-library.php (/library/{nicename}/, read-only), neither of which enqueues library.js, so an unconditional block rendered nothing but an empty, reserved-height error paragraph on the plugin's only indexable grid surfaces. $show_select/$show_remove line up exactly with the one route (owner library) that actually loads library.js. ?>
	<?php if ( $show_select || $show_remove ) : ?>
		<div class="gl-game-card__actions">
			<?php if ( $show_select ) : ?>
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
				>
					<?php foreach ( Statuses::all() as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $entry_status, $option ); ?>><?php echo esc_html( Statuses::label( $option ) ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<?php if ( $show_remove ) : ?>
				<button
					type="button"
					class="gl-button gl-button--danger"
					data-gl-remove
					data-igdb-id="<?php echo esc_attr( (string) $igdb_id ); ?>"
					aria-label="<?php
					printf(
						/* translators: %s: game title. */
						esc_attr__( 'Remove %s from your library', 'game-library' ),
						esc_attr( $name )
					);
					?>"
				>
					<?php esc_html_e( 'Remove', 'game-library' ); ?>
				</button>
			<?php endif; ?>

			<?php // MR-4: role="alert" so a screen reader announces a failed status change/remove. ?>
			<p class="gl-error-text" data-gl-card-error role="alert"></p>
		</div>
	<?php endif; ?>

</li>
