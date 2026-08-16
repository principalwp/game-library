<?php
/**
 * Shared single-flight `wp_options` lock primitive.
 *
 * @package Game_Library
 */

namespace Game_Library\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Option_Lock.
 *
 * MR-2 (cycle-8): the one primitive both of this plugin's `wp_options`-backed
 * single-flight locks reduce to — a raw `INSERT IGNORE` against `wp_options`,
 * checked via `$wpdb->rows_affected` rather than `add_option()`'s own return
 * value. `add_option()` cannot be trusted as a check-then-act lock: it issues
 * `INSERT ... ON DUPLICATE KEY UPDATE`, which SUCCEEDS on an `option_name`
 * collision once `notoptions` is warm — and a lock's own caller almost
 * always warms it first with a `get_option()` staleness pre-read.
 * `INSERT IGNORE` genuinely fails (0 rows affected) on that same collision
 * regardless of any option-cache state, which is what a real lock needs.
 *
 * `Activator::acquire_upgrade_lock()` (PB-8/MR-3, cycle-7) proved this
 * primitive first; `Game_Repository::under_regen_lock()` (PB-6, cycle-5)
 * carried its own second, independently-drifting copy of the identical
 * shape until this class extracted the one shared home both now call. See
 * `principal/adr/004-generation-counter-cache-invalidation.md` for the full
 * reasoning on why neither `add_option()` nor `wp_cache_add()` is a valid
 * lock on this plugin's ruled `portable` deployment target.
 *
 * Do not reuse this primitive for a security-critical one-shot (e.g. a
 * redemption token) — `INSERT IGNORE` is a coordination lock, not a secrecy
 * or single-use guarantee, and the option row it writes is world-readable
 * via `get_option()`.
 */
final class Option_Lock {

	/**
	 * Attempts to acquire a lock keyed on one `wp_options` row.
	 *
	 * @param string $option_name The option name to use as the lock key —
	 *                             the caller owns uniqueness/collision-safety
	 *                             for this value.
	 * @return bool True when this call acquired the lock.
	 */
	public static function acquire( $option_name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the raw INSERT IGNORE is the lock-acquire primitive itself: add_option() upserts on collision and cannot be used for a real single-flight lock; there is nothing to cache, this is a write.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->options is a wpdb property, never user input.
				$option_name,
				(string) time()
			)
		);

		$acquired = 1 === (int) $wpdb->rows_affected;

		if ( $acquired ) {
			wp_cache_delete( $option_name, 'options' );

			// Mirror add_option()'s own notoptions maintenance -- never flush the
			// whole negative-cache map. under_regen_lock() calls this on every
			// catalog regeneration, i.e. exactly under load.
			$notoptions = wp_cache_get( 'notoptions', 'options' );

			if ( is_array( $notoptions ) && isset( $notoptions[ $option_name ] ) ) {
				unset( $notoptions[ $option_name ] );
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}
		}

		return $acquired;
	}
}
