<?php
/**
 * The message a list renders instead of items.
 *
 * Four situations share this part: an empty library (AC-020), a filter that
 * matched nothing (AC-017), a search that found nothing (AC-009), and a search
 * that failed (AC-010). It is rendered by the REST fragments and by the first
 * paint of `/my-library/` alike, so a state reads identically whether the page
 * was just loaded or just swapped (ADR-002).
 *
 * The copy itself is the caller's — {@see GameLib_REST_Library::state_message()}
 * is the shared catalog — because the same string is also the `message` field
 * of a JSON error response, and it must be authored once for both.
 *
 * Expected `$args`:
 * - `message` (string) The message. Without one the part renders nothing.
 * - `state`   (string) State slug, exposed as `data-gamelib-state`.
 * - `tone`    (string) `error` for a failure, anything else for a neutral state.
 * - `tag`     (string) `li` inside the grid's `<ul>`, `p` anywhere else.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_message = isset( $args['message'] ) ? (string) $args['message'] : '';

if ( '' === $gamelib_message ) {
	return;
}

$gamelib_state = isset( $args['state'] ) ? sanitize_key( (string) $args['state'] ) : '';
$gamelib_tone  = ( isset( $args['tone'] ) && 'error' === $args['tone'] ) ? 'error' : 'neutral';
$gamelib_tag   = ( isset( $args['tag'] ) && 'li' === $args['tag'] ) ? 'li' : 'p';

printf(
	'<%1$s class="gamelib-state gamelib-state--%2$s" data-gamelib-state="%3$s">%4$s</%1$s>',
	esc_html( $gamelib_tag ),
	esc_attr( $gamelib_tone ),
	esc_attr( $gamelib_state ),
	esc_html( $gamelib_message )
);
