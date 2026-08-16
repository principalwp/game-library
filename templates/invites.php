<?php
/**
 * /invites/ — the member's own invites: quota, creation, listing, revoke.
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this    Template renderer.
 * @var array<string, mixed>    $context Route context.
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$gl_user_id = get_current_user_id();
$gl_invites = Plugin::instance()->invites();

$gl_quota     = $gl_invites->quota();
$gl_remaining = $gl_invites->remaining_this_month( $gl_user_id );
$gl_reset     = $gl_invites->next_reset();
$gl_reset_lbl = date_i18n( (string) get_option( 'date_format' ), (int) strtotime( $gl_reset . ' UTC' ) );

$gl_rows = $gl_invites->get_for_inviter( $gl_user_id );
?>
<div class="gl-container">
	<header class="gl-page-head">
		<span class="gl-page-head__eyebrow"><?php esc_html_e( 'Grow the community', 'game-library-3' ); ?></span>
		<h1><?php esc_html_e( 'Invites', 'game-library-3' ); ?></h1>
	</header>

	<div class="gl-notice gl-notice--info" id="gl-quota-notice">
		<?php
		// The count is wrapped in a span so JS can update it after create/revoke.
		$gl_remaining_html = '<span data-gl-remaining>' . esc_html( number_format_i18n( $gl_remaining ) ) . '</span>';
		/* translators: 1: remaining count (HTML), 2: total quota. */
		$gl_quota_tmpl = _n( '%1$s of %2$d invite remaining this month', '%1$s of %2$d invites remaining this month', $gl_remaining, 'game-library-3' );
		// The template is a static translation; the count is a pre-escaped span and the quota is cast to int.
		$this->output( sprintf( $gl_quota_tmpl, $gl_remaining_html, (int) $gl_quota ) );
		echo ' · ';
		echo esc_html(
			sprintf(
				/* translators: %s: reset date. */
				__( 'quota resets %s', 'game-library-3' ),
				$gl_reset_lbl
			)
		);
		?>
	</div>

	<form class="gl-invite-form" id="gl-invite-form" data-gl-invite-form>
		<button type="submit" class="gl-button gl-button--primary" data-gl-invite-create><?php esc_html_e( 'Create an invite', 'game-library-3' ); ?></button>
	</form>
	<div class="gl-invite-form-notices" id="gl-invite-notices" aria-live="polite"></div>

	<?php if ( empty( $gl_rows ) ) : ?>
		<?php
		$this->output(
			$this->empty_state(
				__( 'No invites yet', 'game-library-3' ),
				__( 'Create an invite above and share the link with someone you want in the community.', 'game-library-3' )
			)
		);
		?>
	<?php endif; ?>

	<ul class="gl-invite-list" id="gl-invite-list">
		<?php
		// Prime the redeemer set so the accepted-row member_link() lookups hit
		// cache (the inviter is the current user and already cached).
		$gl_invitee_ids = array_filter( array_map( 'intval', wp_list_pluck( $gl_rows, 'invitee_id' ) ) );
		if ( ! empty( $gl_invitee_ids ) ) {
			cache_users( $gl_invitee_ids );
		}
		foreach ( $gl_rows as $gl_row ) {
			$this->partial( 'invite-row', array( 'invite' => $gl_row ) );
		}
		?>
	</ul>
</div>
