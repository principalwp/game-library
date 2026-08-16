<?php
/**
 * Invite service: member generation, admin issue, and atomic single-use redemption.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invites gate registration only. Member generation is capped by the admin-set
 * allowance (enforced server-side). Redemption is a single atomic UPDATE so a code
 * redeems exactly one account even under concurrent submits.
 */
final class Game_Library_Invite_Service {

	/**
	 * The created_by value used for admin-issued invites (not counted against any
	 * per-member allowance).
	 */
	const ADMIN_ISSUER = 0;

	/**
	 * Register the member-facing generate route.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the /invite generate route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			GAME_LIBRARY_REST_NAMESPACE,
			'/invite',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_generate' ),
				'permission_callback' => array( $this, 'require_member' ),
			)
		);
	}

	/**
	 * Permission callback: any logged-in member.
	 *
	 * @return true|WP_Error
	 */
	public function require_member() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new WP_Error(
			'game_library_not_logged_in',
			__( 'You must be logged in.', 'game-library' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * REST: generate an invite for the current member (allowance-enforced).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_generate( WP_REST_Request $request ) {
		unset( $request );
		$result = $this->generate_for_member( get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			array(
				'code' => $result['code'],
				'link' => $result['link'],
				'used' => $this->count_for_member( get_current_user_id() ),
			),
			201
		);
	}

	/**
	 * Generate an invite for a member, enforcing the allowance server-side.
	 *
	 * @param int $user_id Member.
	 * @return array{code:string,link:string}|WP_Error
	 */
	public function generate_for_member( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return new WP_Error( 'game_library_no_user', __( 'You must be logged in.', 'game-library' ), array( 'status' => 401 ) );
		}

		$allowance = (int) get_option( Game_Library_Activator::OPTION_INVITE_ALLOWANCE, Game_Library_Activator::DEFAULT_INVITE_ALLOWANCE );
		if ( $this->count_for_member( $user_id ) >= $allowance ) {
			return new WP_Error(
				'game_library_over_allowance',
				sprintf(
					/* translators: %d: the per-member invite allowance. */
					_n(
						'You have used your %d invite.',
						'You have used all %d of your invites.',
						$allowance,
						'game-library'
					),
					$allowance
				),
				array( 'status' => 403 )
			);
		}

		return $this->insert_invite( $user_id );
	}

	/**
	 * Issue an invite as an admin (not counted against any per-member allowance).
	 *
	 * @return array{code:string,link:string}|WP_Error
	 */
	public function issue_as_admin() {
		return $this->insert_invite( self::ADMIN_ISSUER );
	}

	/**
	 * Insert one invite row with a unique unguessable code.
	 *
	 * @param int $created_by Issuing user id (0 for admin-issued).
	 * @return array{code:string,link:string}|WP_Error
	 */
	private function insert_invite( $created_by ) {
		global $wpdb;

		$table = Game_Library_Activator::table( 'invites' );

		// Retry a couple of times in the astronomically-unlikely event of a collision.
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$code = wp_generate_password( 32, false );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; uniqueness enforced by UNIQUE(code).
			$inserted = $wpdb->insert(
				$table,
				array(
					'code'       => $code,
					'created_by' => absint( $created_by ),
					'created_at' => current_time( 'mysql' ),
				),
				array( '%s', '%d', '%s' )
			);

			if ( $inserted ) {
				return array(
					'code' => $code,
					'link' => home_url( '/join/' . rawurlencode( $code ) ),
				);
			}
		}

		return new WP_Error( 'game_library_invite_failed', __( 'Could not create an invite. Please try again.', 'game-library' ), array( 'status' => 500 ) );
	}

	/**
	 * Count invites a member has issued (admin-issued rows have created_by 0 and
	 * never count against a member).
	 *
	 * @param int $user_id Member.
	 * @return int
	 */
	public function count_for_member( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return 0;
		}
		$table = Game_Library_Activator::table( 'invites' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, value via %d.
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_by = %d', $table, $user_id )
		);
	}

	/**
	 * Whether a code matches an existing, unredeemed invite.
	 *
	 * @param string $code Invite code.
	 * @return bool
	 */
	public function is_valid_unredeemed( $code ) {
		global $wpdb;

		$code = sanitize_text_field( $code );
		if ( '' === $code ) {
			return false;
		}
		$table = Game_Library_Activator::table( 'invites' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, code via %s.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE code = %s AND redeemed_by IS NULL',
				$table,
				$code
			)
		);

		return ! empty( $found );
	}

	/**
	 * Atomically redeem a code for a user. Requires exactly one affected row so a
	 * code redeems exactly one account, even under concurrent submits.
	 *
	 * @param string $code    Invite code.
	 * @param int    $user_id Newly-created account.
	 * @return bool True when this call won the redemption.
	 */
	public function redeem( $code, $user_id ) {
		global $wpdb;

		$code    = sanitize_text_field( $code );
		$user_id = absint( $user_id );
		if ( '' === $code || $user_id <= 0 ) {
			return false;
		}
		$table = Game_Library_Activator::table( 'invites' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, all values via placeholders.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET redeemed_by = %d, redeemed_at = %s WHERE code = %s AND redeemed_by IS NULL',
				$table,
				$user_id,
				current_time( 'mysql' ),
				$code
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * List invites for the admin screen, newest first, bounded.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,object>
	 */
	public function list_invites( $limit = 50 ) {
		global $wpdb;

		$limit = min( 100, max( 1, absint( $limit ) ) );
		$table = Game_Library_Activator::table( 'invites' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table via %i, LIMIT via %d.
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC, id DESC LIMIT %d', $table, $limit )
		);
	}
}
