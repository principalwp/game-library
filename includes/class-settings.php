<?php
/**
 * The single `game_library_settings` option contract.
 *
 * @package Game_Library
 */

namespace Game_Library;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings.
 *
 * The single owner of the `game_library_settings` option name, its
 * documented defaults, and the default-backfill read every consumer needs
 * (`invite_quota`, `invite_expiry_days`, `catalog_indexable`,
 * `delete_data_on_uninstall`). Before this class existed, the option name
 * and its defaults were re-declared independently in six files, each with
 * its own copy and its own shape of default-resolution logic — a drift in
 * any one of them (e.g. `catalog_indexable`'s default changing in one reader
 * but not another) would silently disagree with no error anywhere (arch-pre-1
 * architecture review, finding AR-1).
 *
 * `all()` merges the stored option row over `DEFAULTS`, so every key is
 * always present regardless of whether the row exists yet or is missing a
 * key a later version added. This is a like-for-like consolidation of
 * existing values already read the same way by every consumer prior to this
 * class — not a new abstraction layer, config option, or filter.
 *
 * `Settings_Page::register_settings()`/`sanitize_settings()` (the
 * `register_setting()` wiring, its own `SETTINGS_GROUP`, and the settings
 * form's field names) are unaffected by this consolidation and stay exactly
 * as they were — only the option name constant and the default-array/
 * backfill logic moved here.
 */
final class Settings {

	/**
	 * The `game_library_settings` option name.
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'game_library_settings';

	/**
	 * Default settings, used both to seed the option on activation and to
	 * backfill any key missing from a stored option row.
	 *
	 * @var array<string,mixed>
	 */
	public const DEFAULTS = array(
		'invite_quota'             => 10,
		'invite_expiry_days'       => 14,
		'catalog_indexable'        => true,
		'delete_data_on_uninstall' => false,
	);

	/**
	 * The documented defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return self::DEFAULTS;
	}

	/**
	 * Reads `game_library_settings`, merging the stored option row over the
	 * documented defaults so every key is always present — even before the
	 * option row exists, or after a version adds a key an older stored row
	 * predates.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array_merge( self::DEFAULTS, $stored );
	}

	/**
	 * One setting value, backfilled with its documented default when absent
	 * from the stored option row.
	 *
	 * @param string $key One of the `DEFAULTS` keys.
	 * @return mixed
	 */
	public static function get( $key ) {
		$settings = self::all();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}
}
