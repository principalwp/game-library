<?php
/**
 * Template: /library/{nicename}/ — a read-only view of a member's library.
 * No add/change/remove controls are emitted here.
 *
 * @package Game_Library
 *
 * @var array $context {
 *     @type WP_User $target
 *     @type array   $entries
 *     @type bool    $can_follow
 *     @type bool    $is_following
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$game_library_target       = $context['target'];
$game_library_entries      = $context['entries'];
$game_library_can_follow   = ! empty( $context['can_follow'] );
$game_library_is_following = ! empty( $context['is_following'] );
$game_library_entry_count  = count( $game_library_entries );
?>
<div class="gl-app gl-library-view" data-readonly="1">

	<header class="gl-library-view__header">
		<h2 class="gl-library-view__title">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: member display name. */
					__( '%s’s Library', 'game-library' ),
					$game_library_target->display_name
				)
			);
			?>
		</h2>
		<span class="gl-count">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of games. */
					_n( '%s game', '%s games', $game_library_entry_count, 'game-library' ),
					number_format_i18n( $game_library_entry_count )
				)
			);
			?>
		</span>

		<?php if ( $game_library_can_follow ) : ?>
			<button type="button"
				class="gl-button gl-button--primary gl-follow-button<?php echo $game_library_is_following ? ' is-following' : ''; ?>"
				data-role="follow-toggle"
				data-followee-id="<?php echo esc_attr( (string) $game_library_target->ID ); ?>"
				data-following="<?php echo $game_library_is_following ? '1' : '0'; ?>">
				<?php echo esc_html( $game_library_is_following ? __( 'Unfollow', 'game-library' ) : __( 'Follow', 'game-library' ) ); ?>
			</button>
		<?php endif; ?>
	</header>

	<?php if ( $game_library_entry_count > 0 ) : ?>
		<div class="gl-grid" data-role="library-grid">
			<?php
			$game_library_card_index = 0;
			foreach ( $game_library_entries as $game_library_entry ) {
				echo Game_Library_Router::render_card( $game_library_entry, false, $game_library_card_index++ ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_card escapes internally.
			}
			?>
		</div>
	<?php else : ?>
		<?php
		$game_library_empty = sprintf(
			/* translators: %s: member display name. */
			__( '%s has not added any games yet.', 'game-library' ),
			$game_library_target->display_name
		);
		echo Game_Library_Router::render_empty_state( $game_library_empty ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_empty_state escapes internally.
		?>
	<?php endif; ?>

</div>
