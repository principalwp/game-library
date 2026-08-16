<?php
/**
 * A single invite row.
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this Template renderer.
 * @var array<string, mixed>    $args invite.
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$gl_invite = isset( $args['invite'] ) ? $args['invite'] : null;
if ( ! $gl_invite ) {
	return;
}

$gl_invites = Plugin::instance()->invites();
$gl_status  = $gl_invites->effective_status( $gl_invite );

// Write-through per the Data Model: when a still-pending row is rendered past
// its expiry, persist the 'expired' transition so the stored column matches the
// displayed chip (idempotent — guarded by status = 'pending' in the WHERE).
if ( 'expired' === $gl_status && 'pending' === (string) $gl_invite->status ) {
	$gl_invites->mark_expired( (int) $gl_invite->id );
}

$gl_code = (string) $gl_invite->code;
$gl_join = $this->join_url( $gl_code );

$gl_status_labels = array(
	'pending'  => __( 'Pending', 'game-library-3' ),
	'accepted' => __( 'Accepted', 'game-library-3' ),
	'revoked'  => __( 'Revoked', 'game-library-3' ),
	'expired'  => __( 'Expired', 'game-library-3' ),
);
$gl_status_label  = isset( $gl_status_labels[ $gl_status ] ) ? $gl_status_labels[ $gl_status ] : $gl_status;
?>
<li class="gl-invite-row" data-gl-invite-id="<?php echo esc_attr( (string) $gl_invite->id ); ?>">
	<div class="gl-invite-row__body">
		<span class="gl-invite-row__code"><?php echo esc_html( $gl_code ); ?></span>
		<span class="gl-invite-row__url">
			<a href="<?php echo esc_url( $gl_join ); ?>" data-gl-join-url><?php echo esc_html( $gl_join ); ?></a>
			<button type="button" class="gl-button gl-button--small" data-gl-copy data-gl-url="<?php echo esc_attr( $gl_join ); ?>"><?php esc_html_e( 'Copy', 'game-library-3' ); ?></button>
			<span class="gl-invite-row__copied" hidden><?php esc_html_e( 'Copied!', 'game-library-3' ); ?></span>
		</span>
		<?php if ( 'accepted' === $gl_status && ! empty( $gl_invite->invitee_id ) ) : ?>
			<span class="gl-invite-row__meta">
				<?php
				printf(
					/* translators: %s: member link. */
					esc_html__( 'Redeemed by %s', 'game-library-3' ),
					$this->member_link( (int) $gl_invite->invitee_id ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built + escaped in member_link().
				);
				?>
			</span>
		<?php endif; ?>
	</div>
	<div class="gl-invite-row__actions">
		<span class="gl-invite-row__status gl-invite-row__status--<?php echo esc_attr( $gl_status ); ?>" data-gl-invite-status><?php echo esc_html( $gl_status_label ); ?></span>
		<?php if ( 'pending' === $gl_status ) : ?>
			<button type="button" class="gl-button gl-button--small gl-button--danger" data-gl-revoke data-gl-invite-id="<?php echo esc_attr( (string) $gl_invite->id ); ?>"><?php esc_html_e( 'Revoke', 'game-library-3' ); ?></button>
		<?php endif; ?>
	</div>
</li>
