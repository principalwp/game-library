<?php
/**
 * One activity-feed item.
 *
 * Shared by the `/activity/` first paint, the feed's "Load more" fragments, and
 * a profile's recent-activity list, so an event reads identically everywhere
 * (AC-024, AC-031d).
 *
 * The event *sentence* is composed by the caller, because only the caller knows
 * the event vocabulary and its `_n()` counts — but it is escaped here, against
 * a narrow allowlist, so a game title arriving from IGDB inside a sentence can
 * never introduce markup (Always Do #1).
 *
 * Every item is a programmatic focus target (`tabindex="-1"`, never in the tab
 * order): after a keyset "Load more" the bundle moves focus to the first item it
 * appended, which is what turns an append into something a keyboard or screen
 * reader user lands on rather than has to hunt for (AC-NFR-004).
 *
 * Expected `$args`:
 * - `id`         (int)    Event id, exposed as `data-gamelib-item-id`. Optional:
 *                         the REST fragment renderer passes no id, and nothing
 *                         keys on its presence.
 * - `type`       (string) Event type, for styling and instrumentation.
 * - `actor_name` (string) Actor's display name. Omit it when the sentence
 *                already opens with the actor's linked name — which is what
 *                {@see GameLib_Activity::render_items()} composes (AC-024a) —
 *                or the member's name prints twice in every item.
 * - `actor_url`  (string) Actor's profile URL; empty renders the name unlinked.
 * - `sentence`   (string) The event sentence; may contain `<a>`, `<strong>`, `<em>`, `<span>`.
 * - `created_at` (string) UTC `Y-m-d H:i:s`, rendered in the site's timezone.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_actor_name = isset( $args['actor_name'] ) ? (string) $args['actor_name'] : '';
$gamelib_sentence   = isset( $args['sentence'] ) ? (string) $args['sentence'] : '';

if ( '' === $gamelib_actor_name && '' === $gamelib_sentence ) {
	return;
}

$gamelib_item_id    = isset( $args['id'] ) ? absint( $args['id'] ) : 0;
$gamelib_type       = isset( $args['type'] ) ? sanitize_key( (string) $args['type'] ) : '';
$gamelib_actor_url  = isset( $args['actor_url'] ) ? (string) $args['actor_url'] : '';
$gamelib_created_at = isset( $args['created_at'] ) ? (string) $args['created_at'] : '';
$gamelib_created_ts = ( '' === $gamelib_created_at ) ? false : strtotime( $gamelib_created_at . ' UTC' );

$gamelib_allowed_html = array(
	'a'      => array(
		'href'                => array(),
		'class'               => array(),
		'data-gamelib-action' => array(),
	),
	'strong' => array( 'class' => array() ),
	'em'     => array( 'class' => array() ),
	'span'   => array( 'class' => array() ),
);

?>
<li
	class="gamelib-feed__item"
	tabindex="-1"
	data-gamelib-event-type="<?php echo esc_attr( $gamelib_type ); ?>"
	<?php if ( $gamelib_item_id > 0 ) : ?>
		data-gamelib-item-id="<?php echo esc_attr( (string) $gamelib_item_id ); ?>"
	<?php endif; ?>
>
	<?php if ( '' !== $gamelib_actor_name ) : ?>
		<p class="gamelib-feed__actor">
			<?php if ( '' !== $gamelib_actor_url ) : ?>
				<a href="<?php echo esc_url( $gamelib_actor_url ); ?>"><?php echo esc_html( $gamelib_actor_name ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $gamelib_actor_name ); ?>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $gamelib_sentence ) : ?>
		<p class="gamelib-feed__sentence"><?php echo wp_kses( $gamelib_sentence, $gamelib_allowed_html ); ?></p>
	<?php endif; ?>

	<?php if ( false !== $gamelib_created_ts ) : ?>
		<time class="gamelib-feed__time" datetime="<?php echo esc_attr( gmdate( 'c', $gamelib_created_ts ) ); ?>">
			<?php
			echo esc_html(
				wp_date(
					(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ),
					$gamelib_created_ts
				)
			);
			?>
		</time>
	<?php endif; ?>
</li>
