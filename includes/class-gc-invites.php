<?php
/**
 * Invite-only registration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Invites {

	public static function init() {
		add_action( 'register_form', array( __CLASS__, 'render_register_field' ) );
		add_filter( 'registration_errors', array( __CLASS__, 'validate_registration' ), 10, 3 );
		add_action( 'user_register', array( __CLASS__, 'consume_on_register' ) );

		if ( '1' === get_option( 'gc_force_registration', '1' ) ) {
			add_filter( 'option_users_can_register', '__return_true' );
		}
	}

	private static function table() {
		return GC_Install::table( 'invites' );
	}

	/**
	 * Generate invite codes.
	 *
	 * @param int    $count        Number of codes.
	 * @param string $note         Optional note (e.g. who it's for).
	 * @param int    $expires_days 0 = never expires.
	 * @return string[] Generated codes.
	 */
	public static function generate( $count, $note = '', $expires_days = 0, $created_by = 0 ) {
		global $wpdb;

		$count      = min( 50, max( 1, (int) $count ) );
		$created_by = $created_by ? (int) $created_by : get_current_user_id();
		$expires_at = $expires_days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $expires_days * DAY_IN_SECONDS ) : null;
		$codes      = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$code = strtoupper( wp_generate_password( 12, false, false ) );

			$inserted = $wpdb->insert(
				self::table(),
				array(
					'code'       => $code,
					'note'       => $note,
					'created_by' => $created_by,
					'created_at' => current_time( 'mysql', true ),
					'expires_at' => $expires_at,
				)
			);

			if ( $inserted ) {
				$codes[] = $code;
			}
		}

		return $codes;
	}

	/**
	 * @return object|null Invite row.
	 */
	public static function get_by_code( $code ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE code = %s', $code )
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function check( $code ) {
		$code = strtoupper( trim( $code ) );

		if ( '' === $code ) {
			return new WP_Error( 'gc_invite_required', __( '<strong>Error:</strong> An invite code is required to join.', 'game-collector' ) );
		}

		$invite = self::get_by_code( $code );

		if ( ! $invite ) {
			return new WP_Error( 'gc_invite_invalid', __( '<strong>Error:</strong> That invite code is not valid.', 'game-collector' ) );
		}

		if ( $invite->used_by ) {
			return new WP_Error( 'gc_invite_used', __( '<strong>Error:</strong> That invite code has already been used.', 'game-collector' ) );
		}

		if ( $invite->expires_at && strtotime( $invite->expires_at ) < time() ) {
			return new WP_Error( 'gc_invite_expired', __( '<strong>Error:</strong> That invite code has expired.', 'game-collector' ) );
		}

		return true;
	}

	public static function mark_used( $code, $user_id ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'used_by' => (int) $user_id,
				'used_at' => current_time( 'mysql', true ),
			),
			array( 'code' => strtoupper( trim( $code ) ) )
		);
	}

	public static function delete( $invite_id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $invite_id ) );
	}

	public static function get_all() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC, id DESC LIMIT 500' );
	}

	public static function invite_url( $code ) {
		return add_query_arg( 'gc_invite', rawurlencode( $code ), wp_registration_url() );
	}

	/* ---- Registration hooks ---- */

	public static function render_register_field() {
		$prefill = isset( $_REQUEST['gc_invite'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['gc_invite'] ) ) : '';
		?>
		<p>
			<label for="gc_invite"><?php esc_html_e( 'Invite code', 'game-collector' ); ?></label>
			<input type="text" name="gc_invite" id="gc_invite" class="input" value="<?php echo esc_attr( $prefill ); ?>" size="25" autocomplete="off" required />
		</p>
		<?php
	}

	public static function validate_registration( $errors, $sanitized_user_login, $user_email ) {
		$code  = isset( $_POST['gc_invite'] ) ? sanitize_text_field( wp_unslash( $_POST['gc_invite'] ) ) : '';
		$check = self::check( $code );

		if ( is_wp_error( $check ) ) {
			$errors->add( $check->get_error_code(), $check->get_error_message() );
		}

		return $errors;
	}

	public static function consume_on_register( $user_id ) {
		// Admin-created users (wp-admin, WP-CLI) don't go through the invite flow.
		if ( ! isset( $_POST['gc_invite'] ) ) {
			return;
		}

		$code  = sanitize_text_field( wp_unslash( $_POST['gc_invite'] ) );
		$check = self::check( $code );

		if ( ! is_wp_error( $check ) ) {
			self::mark_used( $code, $user_id );
			update_user_meta( $user_id, 'gc_invite_code', strtoupper( trim( $code ) ) );
		}
	}
}
