<?php
/**
 * Library visibility: user-meta read/write, registration default, viewer checks.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * The single decision point for every library-visibility question.
 */
class Visibility {

	/**
	 * User-meta key.
	 */
	const META_KEY = 'gl_library_visibility';

	/**
	 * Allowed values.
	 */
	const VALUES = array( 'public', 'private' );

	/**
	 * Register hooks: default visibility on any new user.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'user_register', array( $this, 'set_registration_default' ) );
	}

	/**
	 * Ensure a newly registered user has the OQ-2 default (private) unless a
	 * value was already written (e.g. by the join flow).
	 *
	 * @param int $user_id New user id.
	 * @return void
	 */
	public function set_registration_default( $user_id ) {
		$existing = get_user_meta( absint( $user_id ), self::META_KEY, true );
		if ( ! in_array( $existing, self::VALUES, true ) ) {
			update_user_meta( absint( $user_id ), self::META_KEY, 'private' );
		}
	}

	/**
	 * A user's visibility. Absent meta reads as private.
	 *
	 * @param int $user_id User id.
	 * @return string 'public' | 'private'
	 */
	public function get( $user_id ) {
		$value = get_user_meta( absint( $user_id ), self::META_KEY, true );
		return ( 'public' === $value ) ? 'public' : 'private';
	}

	/**
	 * Whether a user's library is public.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function is_public( $user_id ) {
		return 'public' === $this->get( $user_id );
	}

	/**
	 * Validate + persist a visibility value for one user. Allowlist-enforced.
	 *
	 * @param int    $user_id User id (the acting user; caller guarantees this).
	 * @param string $value   Candidate value.
	 * @return string|null The stored value, or null when rejected.
	 */
	public function update( $user_id, $value ) {
		if ( ! in_array( $value, self::VALUES, true ) ) {
			return null;
		}
		update_user_meta( absint( $user_id ), self::META_KEY, $value );
		return $value;
	}

	/**
	 * Whether $viewer may see $owner's library. Owners always can; public
	 * libraries are open to all; a private library is visible to any logged-in
	 * member but never to logged-out visitors (AC-006c / AC-034).
	 *
	 * @param int $owner_id  Library owner id.
	 * @param int $viewer_id Viewer id (0 when logged out).
	 * @return bool
	 */
	public function viewer_can_see( $owner_id, $viewer_id ) {
		$owner_id  = absint( $owner_id );
		$viewer_id = absint( $viewer_id );

		if ( $owner_id > 0 && $owner_id === $viewer_id ) {
			return true;
		}
		if ( $this->is_public( $owner_id ) ) {
			return true;
		}
		return $viewer_id > 0;
	}
}
