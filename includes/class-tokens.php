<?php
/**
 * Design tokens contributed to the site's global styles.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's design tokens under `settings.custom.gamelib.*`
 * through the `wp_theme_json_data_default` filter (DD-011).
 *
 * The repository ships no theme and no `theme.json`, so the plugin has to bring
 * its own value space or its CSS would be a pile of literals. Contributing at
 * the *default* origin — rather than shipping a `:root { … }` rule — means a
 * site can override any of these from its own `theme.json` or a style variation
 * without touching plugin CSS, and the values arrive through core's global
 * stylesheet like every other token. The filter runs whether or not the active
 * theme has a `theme.json` of its own, and core emits the `variables` layer in
 * both cases, so classic themes get the tokens too.
 *
 * The origin is load-bearing and was wrong (DES-7). `wp_theme_json_data_theme`
 * merges this fragment *over* the theme's already-parsed data, so a theme that
 * set `settings.custom.gamelib.*` in its own `theme.json` had its values
 * silently replaced by the plugin's — the opposite of the seam this docblock
 * advertises. `wp_theme_json_data_default` is the origin core reserves for
 * defaults a theme is expected to override; theme beats default, user beats
 * theme, which is the precedence a plugin's suggested values want.
 *
 * WordPress flattens each nesting level with `--` and kebab-cases the keys, so
 * `custom.gamelib.status.playing` is `--wp--custom--gamelib--status--playing`
 * and `custom.gamelib.coverAspect` is `--wp--custom--gamelib--cover-aspect`.
 * Shipped CSS still carries a literal fallback on every reference (Always Do
 * #13) — the plugin must render correctly on a site whose global stylesheet is
 * disabled entirely.
 *
 * Contrast (AC-NFR-004): every status, feedback, and visibility hue is a
 * background paired with `statusForeground` (`#ffffff`); each pairing is
 * between 5.0:1 and 7.6:1, comfortably past the 4.5:1 floor. Re-check any hue
 * that is edited.
 *
 * That contract is the *only* way these hues may be painted. A hue used as
 * foreground text over whatever background the active theme paints is not
 * covered by it and does not hold: `feedback.error` (`#b91c1c`) is 6.47:1 on
 * `#ffffff` but 2.94:1 on `#111111` (DES-3). The stylesheet's notice, list-state
 * and form-error rules therefore mix the hue with `currentcolor` rather than
 * consuming it raw — measured for the error hue: 8.1:1 on `#ffffff` and 4.6:1
 * on `#111111`, both directions past the floor.
 */
final class GameLib_Tokens {

	/**
	 * Group all plugin tokens sit under, inside `settings.custom`.
	 *
	 * @var string
	 */
	const GROUP = 'gamelib';

	/**
	 * Merge the plugin's tokens into the theme's global-styles data.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme-origin global styles data.
	 * @return WP_Theme_JSON_Data Data including the plugin's tokens.
	 */
	public static function filter_theme_json( $theme_json ) {
		if ( ! is_object( $theme_json ) || ! method_exists( $theme_json, 'update_with' ) ) {
			return $theme_json;
		}

		return $theme_json->update_with(
			array(
				/*
				 * Schema version of *this fragment*, not of the theme's data.
				 * `settings.custom` is byte-identical across v2 and v3, and
				 * core migrates a v2 fragment forward before merging, so
				 * declaring 2 is what keeps the plugin working on the oldest
				 * WordPress its header supports (6.5, whose latest schema is
				 * 2) as well as on current releases.
				 */
				'version'  => 2,
				'settings' => array(
					'custom' => array(
						self::GROUP => self::tokens(),
					),
				),
			)
		);
	}

	/**
	 * The token set.
	 *
	 * @return array<string, mixed> Nested token tree for `settings.custom.gamelib`.
	 */
	public static function tokens() {
		return array(
			// The four library statuses (§6 Data Model whitelist), as badge
			// backgrounds.
			'status'           => array(
				'playing'  => '#2563eb',
				'finished' => '#15803d',
				'backlog'  => '#b45309',
				'wishlist' => '#7c3aed',
			),

			// The one foreground every colored badge uses.
			'statusForeground' => '#ffffff',

			// Result feedback: save confirmations, request failures, caps.
			'feedback'         => array(
				'success' => '#15803d',
				'error'   => '#b91c1c',
				'warning' => '#b45309',
			),

			// The two visibility states — labelled "Members-only" and
			// "Public", never "private" (AC-027b).
			'visibility'       => array(
				'members' => '#475569',
				'public'  => '#0f766e',
			),

			/*
			 * The plugin's type scale (DES-4). Without it a site could retune
			 * the plugin's colours from its own theme.json but not its density —
			 * every dimensional value in the shipped stylesheet was a literal.
			 *
			 * Landing under `custom.gamelib` rather than
			 * `settings.typography.fontSizes` is deliberate: these are the
			 * plugin's internal steps, not presets a member should see in an
			 * editor picker, and adding real `fontSizes` to a *plugin* fragment
			 * would make core's v3 migration turn `defaultFontSizes` off for the
			 * whole site.
			 */
			'fontSize'         => array(
				'xs' => '0.75rem',
				'sm' => '0.875rem',
				'md' => '1rem',
				'lg' => '1.125rem',
			),

			/*
			 * The spacing scale, same reasoning as `fontSize` — and the same
			 * reason it is not `settings.spacing.spacingSizes`.
			 *
			 * Word keys only: WordPress kebab-cases each key, so a key like
			 * `2xl` would land as `--wp--custom--gamelib--space--2-xl`.
			 */
			'space'            => array(
				'xxs' => '0.125rem',
				'xs'  => '0.25rem',
				'sm'  => '0.5rem',
				'md'  => '0.75rem',
				'lg'  => '1rem',
				'xl'  => '1.5rem',
				'xxl' => '2rem',
			),

			/*
			 * Control sizing (DES-19). Deliberately its own group rather than a
			 * reference into `space`: `min` is the AC-NFR-004(c) hit-area floor,
			 * and coupling a WCAG minimum to the density scale would let a site
			 * retuning `space` for a compact layout shrink the floor with it.
			 *
			 * `min` is the ≥ 24 CSS px target size every plugin control clears
			 * (2rem = 32px at the default root size); `checkbox` is the size the
			 * two checkboxes are painted at, since a user-agent checkbox is well
			 * under the floor.
			 */
			'control'          => array(
				'min'      => '2rem',
				'checkbox' => '1.5rem',
			),

			/*
			 * Measures (DES-19): the widths this plugin constrains content to.
			 * Every one of these was a bare literal in the stylesheet, so a site
			 * could retune the plugin's colour, type, and density from its own
			 * theme.json but not the width of a form or a card track.
			 *
			 * - `page`     the document measure for the plugin's own routes;
			 * - `form`     the /join/ form and the search field column;
			 * - `card`     the grid track's minimum, which sets the cover width;
			 * - `field`    the flex basis of an inline text field;
			 * - `launcher` the flex basis of the two import launcher fields;
			 * - `cover`    the game-page hero cover's ceiling — 16.5rem is the
			 *              264px intrinsic width of IGDB's `cover_big`, so the
			 *              hero is never upscaled (DES-12);
			 * - `thumb`    the search-result thumbnail column;
			 * - `results`  the height the search-result container holds open
			 *              while a request is out (DES-2): the six skeleton rows
			 *              the template clones in, plus five `space.lg` gaps.
			 *              Folded from the row as it *paints* rather than from a
			 *              sum of the tokens that feed it (PF-4) — a row is
			 *              `max(media, body)` and the body is a stack of line
			 *              boxes, so it resolves to the active theme's
			 *              line-height, not to the font-size steps. Measured
			 *              under Twenty Twenty-Five: a skeleton row paints
			 *              109.58px (~6.85rem) against a 106.66px real row (the
			 *              media box — on this theme the body is the shorter
			 *              column), so 6 x 6.85 + 5 x 1 = 46.1rem and the
			 *              skeleton list paints 737.47px. 47.75rem is a
			 *              deliberate ~26.5px over-reservation, kept because
			 *              over-reserving is the safe direction. What defends it
			 *              is the painted-vs-token gate in
			 *              `tests/e2e/specs/gamelib-a11y.spec.ts` (+/-10%,
			 *              currently 0.965), not this arithmetic: retuning
			 *              either font-size step, `control.min`, `thumb` or
			 *              `coverAspect` moves the painted row, and that gate is
			 *              what says so.
			 */
			'measure'          => array(
				'page'     => '62rem',
				'form'     => '30rem',
				'card'     => '11rem',
				'field'    => '12rem',
				'launcher' => '14rem',
				'cover'    => '16.5rem',
				'thumb'    => '5rem',
				'results'  => '47.75rem',
			),

			'radius'           => '4px',

			// Fixed cover ratio so a card reserves its space before the image
			// resolves (AC-014a).
			'coverAspect'      => '3 / 4',

			'transition'       => array(
				'duration' => '150ms',
				'easing'   => 'ease-out',
			),
		);
	}
}
