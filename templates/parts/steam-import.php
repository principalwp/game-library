<?php
/**
 * The Steam import launcher and its pre-start disclosure (AC-046a).
 *
 * Rendered by the account panel on `/my-library/`, but authored here, with the
 * import routes it starts: the disclosure is a promise about what
 * `POST /imports` with `type=steam` does, and the two have to be changed
 * together. The copy states, before a member can press anything, that the site
 * reads their **public** Steam game list through the Steam Web API, that it
 * stores their SteamID so a later import does not need it again, and that Steam
 * data is never used for marketing.
 *
 * Nothing here carries a Steam or Valve mark, and nothing implies Valve is
 * involved with this site: the feature is named after what it does — "Import
 * from your public Steam profile" — and the only Steam URLs the plugin renders
 * anywhere are the plain store links of AC-042(c) on unmatched rows.
 *
 * Expected `$args`:
 * - `steamid`      (string) The member's stored SteamID64, when they have one;
 *                           prefills the field so a re-import is one press.
 * - `heading_tag`  (string) `h2`|`h3`|`h4`. Default `h3` — the launcher sits
 *                           inside the account panel's own section heading.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

$gamelib_steamid = isset( $args['steamid'] ) ? (string) $args['steamid'] : '';

$gamelib_heading_tag = isset( $args['heading_tag'] ) ? sanitize_key( (string) $args['heading_tag'] ) : 'h3';

if ( ! in_array( $gamelib_heading_tag, array( 'h2', 'h3', 'h4' ), true ) ) {
	$gamelib_heading_tag = 'h3';
}

?>
<div class="gamelib-steam" data-gamelib-steam>
	<?php
	printf(
		'<%1$s class="gamelib-steam__title">%2$s</%1$s>',
		esc_html( $gamelib_heading_tag ),
		esc_html__( 'Import from your public Steam profile', 'game-library' )
	);
	?>

	<div class="gamelib-steam__disclosure" data-gamelib-steam-disclosure>
		<p><?php esc_html_e( 'This site reads the list of games on your public Steam profile through the Steam Web API, and matches those titles against IGDB.', 'game-library' ); ?></p>
		<p><?php esc_html_e( 'Your SteamID is stored on your account so a later import does not have to ask for it again. You can remove it at any time.', 'game-library' ); ?></p>
		<p><?php esc_html_e( 'Nothing read from Steam is ever used for marketing, and nothing is shared with anyone else.', 'game-library' ); ?></p>
	</div>

	<div class="gamelib-steam__start">
		<label class="gamelib-steam__label" for="gamelib-steam-account">
			<?php esc_html_e( 'Your SteamID64 or Steam profile name', 'game-library' ); ?>
		</label>
		<input
			type="text"
			id="gamelib-steam-account"
			class="gamelib-control gamelib-steam__field"
			value="<?php echo esc_attr( $gamelib_steamid ); ?>"
			autocomplete="off"
			inputmode="text"
			data-gamelib-steam-account
		/>
		<button
			type="button"
			class="gamelib-control gamelib-steam__button"
			data-gamelib-action="import.start"
			data-gamelib-import-type="steam"
		>
			<?php esc_html_e( 'Start Steam import', 'game-library' ); ?>
		</button>
	</div>
</div>
