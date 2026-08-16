<?php
/**
 * The four-status allowlist and label map.
 *
 * @package Game_Library
 */

namespace Game_Library;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Statuses.
 *
 * The single authority for the library-entry status vocabulary — usable in
 * SQL, REST `enum` schemas, and status-badge label maps. No other file may
 * hardcode one of these status strings.
 */
final class Statuses {

	/**
	 * A member is actively playing the game.
	 *
	 * @var string
	 */
	public const PLAYING = 'playing';

	/**
	 * A member has finished the game.
	 *
	 * @var string
	 */
	public const FINISHED = 'finished';

	/**
	 * A member owns the game but has not started it.
	 *
	 * @var string
	 */
	public const BACKLOG = 'backlog';

	/**
	 * A member wants the game but does not yet own it.
	 *
	 * @var string
	 */
	public const WISHLIST = 'wishlist';

	/**
	 * The full allowlist of valid status values.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::PLAYING, self::FINISHED, self::BACKLOG, self::WISHLIST );
	}

	/**
	 * Whether a value is a recognised status.
	 *
	 * @param string $status Candidate status value.
	 * @return bool
	 */
	public static function is_valid( $status ) {
		return in_array( $status, self::all(), true );
	}

	/**
	 * The translated, human-readable label for a status.
	 *
	 * @param string $status One of Statuses::all().
	 * @return string Translated label, or an empty string for an unknown status.
	 */
	public static function label( $status ) {
		$labels = array(
			self::PLAYING  => __( 'Playing', 'game-library' ),
			self::FINISHED => __( 'Finished', 'game-library' ),
			self::BACKLOG  => __( 'Backlog', 'game-library' ),
			self::WISHLIST => __( 'Wishlist', 'game-library' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : '';
	}
}
