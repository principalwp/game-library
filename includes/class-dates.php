<?php
/**
 * Formats a stored UTC datetime using the site's date/time format.
 *
 * @package Game_Library
 */

namespace Game_Library;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Dates.
 *
 * CO-10 (cycle-2): the identical UTC-to-site date formatter existed as three
 * hand-maintained copies — `templates/invites.php`'s own closure,
 * `Invites_List_Table::format_date()`, and `Moderation_Page::format_date()`
 * (which additionally appended the site's time format) — each carrying its
 * own verbatim copy of the explanatory comment this class's own `format()`
 * docblock now owns. Cycle-1's CO-5 fix (switching `date_i18n()` to
 * `wp_date()` for the same reason) had to be applied to all three by hand;
 * this class is the single owner going forward.
 */
final class Dates {

	/**
	 * Formats a stored UTC `Y-m-d H:i:s` value using the site's date format,
	 * optionally appending the time format too.
	 *
	 * `wp_date()`, not `date_i18n()`: `date_i18n()` reinterprets its
	 * timestamp as an already-site-offset wall clock, so the real UTC epoch
	 * `strtotime()` produces here would render the UTC date unconverted —
	 * one calendar day off near midnight on any site not at UTC. `wp_date()`
	 * is core's function that actually converts.
	 *
	 * CO-2 (cycle-3): the empty guard is `empty()`, not a strict `'' ===`
	 * comparison — restoring the falsy check the three hand-written originals
	 * this class replaced (CO-10, cycle-2) all used. A strict `'' ===` guard
	 * lets `null` (a genuinely nullable column, e.g. `gl_invites.date_redeemed`)
	 * reach `strtotime( null . ' UTC' )`, which does NOT return `false` for
	 * that degenerate input — confirmed at runtime, it returns the CURRENT
	 * timestamp — so the `false === $timestamp` guard below never fires and
	 * this silently renders today's date instead of ''. Every live call site
	 * happens to coerce to '' before calling this (so the narrower guard was
	 * latent, not yet observed), but the signature accepts a nullable column
	 * and the docblock advertises '' for "absent" — `empty()` covers '', null,
	 * and '0' alike, matching that contract and the pre-extraction behaviour.
	 *
	 * @param string|null $utc_datetime UTC datetime string, or ''/null when
	 *                                  absent.
	 * @param bool        $with_time    Whether to also append the site's time
	 *                                  format. Defaults to false (date only).
	 * @return string '' when `$utc_datetime` is empty or otherwise unparsable.
	 */
	public static function format( $utc_datetime, $with_time = false ) {
		if ( empty( $utc_datetime ) ) {
			return '';
		}

		$timestamp = strtotime( $utc_datetime . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		$format = get_option( 'date_format' );

		if ( $with_time ) {
			$format .= ' ' . get_option( 'time_format' );
		}

		return wp_date( $format, $timestamp );
	}
}
