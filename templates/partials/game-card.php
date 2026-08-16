<?php
/**
 * Game card partial — owner, read-only member, or catalog mode.
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this Template renderer.
 * @var array<string, mixed>    $args entry, owner, mode, index.
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$entry = isset( $args['entry'] ) ? $args['entry'] : null;
if ( ! $entry ) {
	return;
}

$gl_owner = ! empty( $args['owner'] );
$gl_mode  = isset( $args['mode'] ) ? $args['mode'] : ( $gl_owner ? 'owner' : 'member' );
$gl_index = isset( $args['index'] ) ? (int) $args['index'] : 1;

$gl_name   = isset( $entry->name ) ? (string) $entry->name : '';
$gl_slug   = isset( $entry->slug ) ? (string) $entry->slug : '';
$gl_cover  = isset( $entry->cover_image_id ) ? $entry->cover_image_id : '';
$gl_status = isset( $entry->status ) ? (string) $entry->status : '';
$gl_lib_id = isset( $entry->id ) ? (int) $entry->id : 0;

$gl_game_url = $gl_slug ? home_url( '/games/' . rawurlencode( $gl_slug ) . '/' ) : '';
$gl_img      = $this->cover_img_tag( $gl_cover, $gl_name, 0 === $gl_index );
?>
<li class="gl-game-card"<?php echo ( 'owner' === $gl_mode ) ? ' data-gl-library-id="' . esc_attr( (string) $gl_lib_id ) . '"' : ''; ?>>
	<div class="gl-game-card__cover">
		<?php if ( '' !== $gl_img ) : ?>
			<?php echo $gl_img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built + escaped in cover_img_tag(). ?>
		<?php else : ?>
			<div class="gl-game-card__cover-placeholder"><?php echo esc_html( $gl_name ); ?> · <?php esc_html_e( 'No cover', 'game-library-3' ); ?></div>
		<?php endif; ?>
	</div>

	<div class="gl-game-card__body">
		<h3 class="gl-game-card__title">
			<?php if ( '' !== $gl_game_url ) : ?>
				<a href="<?php echo esc_url( $gl_game_url ); ?>"><?php echo esc_html( $gl_name ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $gl_name ); ?>
			<?php endif; ?>
		</h3>

		<?php if ( 'catalog' !== $gl_mode && '' !== $gl_status ) : ?>
			<div class="gl-game-card__status">
				<?php echo $this->render_status_badge( $gl_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built + escaped in render_status_badge(). ?>
				<?php if ( 'owner' === $gl_mode ) : ?>
					<button type="button" class="gl-status-cycle-btn" data-gl-status-cycle aria-label="<?php esc_attr_e( 'Change status', 'game-library-3' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5V2L8 6l4 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7z" fill="currentColor"/></svg>
					</button>
					<select class="gl-status-select__control" data-gl-status-select hidden aria-label="<?php esc_attr_e( 'Set status', 'game-library-3' ); ?>">
						<?php foreach ( Library_Repository::STATUSES as $gl_opt ) : ?>
							<option value="<?php echo esc_attr( $gl_opt ); ?>" <?php selected( $gl_opt, $gl_status ); ?>><?php echo esc_html( $this->status_label( $gl_opt ) ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( 'owner' === $gl_mode ) : ?>
		<div class="gl-game-card__actions">
			<span class="gl-error-text" data-gl-card-error aria-live="polite"></span>
			<button
				type="button"
				class="gl-button gl-button--icon"
				data-gl-quicklook
				data-gl-title="<?php echo esc_attr( $gl_name ); ?>"
				data-gl-cover="<?php echo esc_attr( '' !== $gl_img ? Plugin::instance()->igdb()->cover_url( $gl_cover ) : '' ); ?>"
				aria-label="<?php echo esc_attr( sprintf( /* translators: %s: game title. */ __( 'Quick look at %s', 'game-library-3' ), $gl_name ) ); ?>"
			>
				<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6" stroke="currentColor" stroke-width="1.6" fill="none"/><path d="m20 20-4-4" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round"/></svg>
			</button>
			<button type="button" class="gl-button gl-button--danger gl-button--icon" data-gl-remove aria-label="<?php echo esc_attr( sprintf( /* translators: %s: game title. */ __( 'Remove %s from your library', 'game-library-3' ), $gl_name ) ); ?>">
				<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 7h12M9 7V5h6v2m-8 0 1 12h8l1-12" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</button>
		</div>
	<?php endif; ?>
</li>
