<?php
/**
 * Template: /my-library/ — the authenticated member's editable library, search,
 * "Following" feed, and invite generator.
 *
 * @package Game_Library
 *
 * @var array $context {
 *     @type WP_User $user
 *     @type array   $entries
 *     @type array   $following_feed
 *     @type int     $invite_used
 *     @type int     $invite_allowance
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$game_library_user             = $context['user'];
$game_library_entries          = $context['entries'];
$game_library_feed             = $context['following_feed'];
$game_library_invite_used      = (int) $context['invite_used'];
$game_library_invite_allowance = (int) $context['invite_allowance'];
$game_library_entry_count      = count( $game_library_entries );
?>
<div class="gl-app gl-my-library">

	<nav class="gl-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Library sections', 'game-library' ); ?>">
		<button type="button" id="gl-tab-library" class="gl-tab is-active" role="tab" aria-selected="true" aria-controls="gl-panel-library" data-gl-tab="library">
			<?php esc_html_e( 'My Library', 'game-library' ); ?>
		</button>
		<button type="button" id="gl-tab-following" class="gl-tab" role="tab" aria-selected="false" aria-controls="gl-panel-following" data-gl-tab="following">
			<?php esc_html_e( 'Following', 'game-library' ); ?>
		</button>
	</nav>

	<section class="gl-panel is-active" id="gl-panel-library" role="tabpanel" aria-labelledby="gl-tab-library" data-gl-panel="library">

		<div class="gl-search" data-role="search">
			<h2 class="gl-search__heading"><?php esc_html_e( 'Find a game', 'game-library' ); ?></h2>
			<form class="gl-search__form" data-role="search-form" role="search">
				<label class="screen-reader-text" for="gl-search-input"><?php esc_html_e( 'Search IGDB', 'game-library' ); ?></label>
				<input type="search" id="gl-search-input" class="gl-search__input" data-role="search-input"
					placeholder="<?php esc_attr_e( 'Search for a game title…', 'game-library' ); ?>" autocomplete="off" />
				<button type="submit" class="gl-button gl-button--primary" data-role="search-submit">
					<?php esc_html_e( 'Search', 'game-library' ); ?>
				</button>
			</form>
			<div class="gl-search__status" data-role="search-status" role="status" aria-live="polite"></div>
			<div class="gl-search__results" data-role="search-results"></div>
		</div>

		<div class="gl-library">
			<h2 class="gl-library__heading">
				<?php esc_html_e( 'Your games', 'game-library' ); ?>
				<span class="gl-count">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of games in the library. */
							_n( '%s game', '%s games', $game_library_entry_count, 'game-library' ),
							number_format_i18n( $game_library_entry_count )
						)
					);
					?>
				</span>
			</h2>

			<?php if ( $game_library_entry_count > 0 ) : ?>
				<div class="gl-grid" data-role="library-grid">
					<?php
					$game_library_card_index = 0;
					foreach ( $game_library_entries as $game_library_entry ) {
						echo Game_Library_Router::render_card( $game_library_entry, true, $game_library_card_index++ ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_card escapes internally.
					}
					?>
				</div>
			<?php else : ?>
				<?php
				echo Game_Library_Router::render_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_empty_state escapes internally.
					__( 'Your library is empty. Search for a game above and add it to get started.', 'game-library' )
				);
				?>
			<?php endif; ?>
		</div>

		<div class="gl-invites">
			<h2 class="gl-invites__heading"><?php esc_html_e( 'Invite a friend', 'game-library' ); ?></h2>
			<p class="gl-invites__allowance">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: invites used, 2: total invite allowance. */
						_n(
							'You have used %1$s of your %2$s invite.',
							'You have used %1$s of your %2$s invites.',
							$game_library_invite_allowance,
							'game-library'
						),
						number_format_i18n( $game_library_invite_used ),
						number_format_i18n( $game_library_invite_allowance )
					)
				);
				?>
			</p>
			<button type="button" class="gl-button gl-button--primary" data-role="generate-invite"
				<?php disabled( $game_library_invite_used >= $game_library_invite_allowance ); ?>>
				<?php esc_html_e( 'Generate invite link', 'game-library' ); ?>
			</button>
			<div class="gl-invites__result" data-role="invite-result" role="status" aria-live="polite"></div>
		</div>

	</section>

	<section class="gl-panel" id="gl-panel-following" role="tabpanel" aria-labelledby="gl-tab-following" data-gl-panel="following" hidden>
		<h2 class="gl-feed__heading"><?php esc_html_e( 'Following activity', 'game-library' ); ?></h2>
		<?php if ( ! empty( $game_library_feed ) ) : ?>
			<div class="gl-feed">
				<?php
				foreach ( $game_library_feed as $game_library_event ) {
					echo Game_Library_Router::render_feed_item( $game_library_event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_feed_item escapes internally.
				}
				?>
			</div>
		<?php else : ?>
			<?php
			echo Game_Library_Router::render_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_empty_state escapes internally.
				__( 'Follow members to see their activity here.', 'game-library' )
			);
			?>
		<?php endif; ?>
	</section>

</div>
<?php unset( $game_library_user ); ?>
