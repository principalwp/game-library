<?php
/**
 * Status badge partial — visible-text status pill (AC-015).
 *
 * Expects, in scope:
 *
 * @var string $badge_status One of Game_Library\Statuses::all().
 *
 * @package Game_Library
 */

use Game_Library\Statuses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$badge_status = isset( $badge_status ) ? (string) $badge_status : '';
?>
<span class="gl-status-badge gl-status-badge--<?php echo esc_attr( sanitize_html_class( $badge_status ) ); ?>">
	<?php echo esc_html( Statuses::label( $badge_status ) ); ?>
</span>
