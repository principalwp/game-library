<?php
/**
 * Invites persistence (gl_invites): quota, code generation, listing, revoke, atomic redeem.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * Owns invite rows. Effective status (expiry) is computed on read; no cron.
 */
class Invites_Repository {

	/**
	 * Unambiguous code alphabet (no I, O, 0, 1).
	 */
	const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	/**
	 * Invite lifetime in seconds (OQ-5: 30 days).
	 */
	const EXPIRY_SECONDS = 30 * DAY_IN_SECONDS;

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'gl_invites';
	}

	/**
	 * The configured monthly quota.
	 *
	 * @return int
	 */
	public function quota() {
		return max( 0, (int) get_option( 'gl_invite_quota', 5 ) );
	}

	/**
	 * Compute an invite's effective status, resolving expiry.
	 *
	 * @param object $invite Invite row.
	 * @return string One of pending|accepted|revoked|expired.
	 */
	public function effective_status( $invite ) {
		if ( 'pending' === $invite->status && ! empty( $invite->expires_at ) ) {
			if ( strtotime( $invite->expires_at . ' UTC' ) < time() ) {
				return 'expired';
			}
		}
		return (string) $invite->status;
	}

	/**
	 * Count of a member's invites this calendar month that hold against quota
	 * (effective pending or accepted). Computed in SQL, UTC month window.
	 *
	 * @param int $inviter_id Member id.
	 * @return int
	 */
	public function used_this_month( $inviter_id ) {
		global $wpdb;
		$table = $this->table();

		$month_start = gmdate( 'Y-m-01 00:00:00' );
		$next_month  = gmdate( 'Y-m-01 00:00:00', strtotime( 'first day of next month', strtotime( $month_start . ' UTC' ) ) );
		$now         = current_time( 'mysql', true );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE inviter_id = %d AND created_at >= %s AND created_at < %s AND ( status = 'accepted' OR ( status = 'pending' AND ( expires_at IS NULL OR expires_at > %s ) ) )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $inviter_id ),
				$month_start,
				$next_month,
				$now
			)
		);
	}

	/**
	 * Invites remaining this month for a member.
	 *
	 * @param int $inviter_id Member id.
	 * @return int
	 */
	public function remaining_this_month( $inviter_id ) {
		return max( 0, $this->quota() - $this->used_this_month( $inviter_id ) );
	}

	/**
	 * The UTC first-of-next-month datetime (for the "quota resets" copy).
	 *
	 * @return string Y-m-d H:i:s
	 */
	public function next_reset() {
		$month_start = gmdate( 'Y-m-01 00:00:00' );
		return gmdate( 'Y-m-d H:i:s', strtotime( 'first day of next month', strtotime( $month_start . ' UTC' ) ) );
	}

	/**
	 * Create one invite for a member, generating a unique code. Quota is the
	 * caller's responsibility (enforced server-side before calling this).
	 *
	 * @param int $inviter_id Member id.
	 * @return object|null The created row, or null on failure.
	 */
	public function create( $inviter_id ) {
		global $wpdb;
		$table = $this->table();

		$created = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::EXPIRY_SECONDS );

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$code = $this->generate_code();

			$suppress = $wpdb->suppress_errors( true );
			$ok       = $wpdb->insert(
				$table,
				array(
					'code'       => $code,
					'inviter_id' => absint( $inviter_id ),
					'status'     => 'pending',
					'created_at' => $created,
					'expires_at' => $expires,
				),
				array( '%s', '%d', '%s', '%s', '%s' )
			);
			$wpdb->suppress_errors( $suppress );

			if ( $ok ) {
				return $this->get( (int) $wpdb->insert_id );
			}
		}

		return null;
	}

	/**
	 * A member's invites, newest first.
	 *
	 * @param int $inviter_id Member id.
	 * @param int $limit      Safety cap.
	 * @return array<int, object>
	 */
	public function get_for_inviter( $inviter_id, $limit = 200 ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE inviter_id = %d ORDER BY created_at DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $inviter_id ),
				absint( $limit )
			)
		);
	}

	/**
	 * Fetch an invite by id.
	 *
	 * @param int $id Invite id.
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Fetch an invite by code.
	 *
	 * @param string $code Invite code.
	 * @return object|null
	 */
	public function get_by_code( $code ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Revoke an owned pending invite.
	 *
	 * @param int $id         Invite id.
	 * @param int $inviter_id Owner id.
	 * @return bool True when a pending row was revoked.
	 */
	public function revoke( $id, $inviter_id ) {
		global $wpdb;
		$table   = $this->table();
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'revoked' WHERE id = %d AND inviter_id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $id ),
				absint( $inviter_id )
			)
		);
		return ( 1 === (int) $updated );
	}

	/**
	 * Mark a pending, expired invite as expired.
	 *
	 * @param int $id Invite id.
	 * @return void
	 */
	public function mark_expired( $id ) {
		global $wpdb;
		$table = $this->table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'expired' WHERE id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $id )
			)
		);
	}

	/**
	 * Atomically claim an invite by id: the single conditional UPDATE from
	 * AC-015 / T16. Returns rows affected (1 on success, 0 if already taken).
	 * The invitee id is recorded separately once the user exists, so this
	 * statement — and its rows_affected===1 check — runs BEFORE wp_insert_user().
	 *
	 * @param int $id Invite id.
	 * @return int Rows affected.
	 */
	public function claim( $id ) {
		global $wpdb;
		$table = $this->table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'accepted', used_at = %s WHERE id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				absint( $id )
			)
		);
		return (int) $wpdb->rows_affected;
	}

	/**
	 * Record the invitee on a claimed invite.
	 *
	 * @param int $id         Invite id.
	 * @param int $invitee_id New member id.
	 * @return void
	 */
	public function set_invitee( $id, $invitee_id ) {
		global $wpdb;
		$this->table();
		$wpdb->update(
			$this->table(),
			array( 'invitee_id' => absint( $invitee_id ) ),
			array( 'id' => absint( $id ) ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Revert a claim if user creation fails after claiming (rare rollback).
	 *
	 * @param int $id Invite id.
	 * @return void
	 */
	public function revert_claim( $id ) {
		global $wpdb;
		$table = $this->table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', used_at = NULL, invitee_id = NULL WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $id )
			)
		);
	}

	/**
	 * OQ-7 cleanup: keep a deleted user's invite rows but null the links they own.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function null_links_for_user( $user_id ) {
		global $wpdb;
		$table   = $this->table();
		$user_id = absint( $user_id );

		// inviter_id is NOT NULL; 0 is the "unknown member" sentinel.
		$wpdb->update( $table, array( 'inviter_id' => 0 ), array( 'inviter_id' => $user_id ), array( '%d' ), array( '%d' ) );
		$wpdb->update( $table, array( 'invitee_id' => null ), array( 'invitee_id' => $user_id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Generate a code of three 4-char groups from the unambiguous alphabet.
	 *
	 * @return string
	 */
	private function generate_code() {
		$max    = strlen( self::ALPHABET ) - 1;
		$groups = array();
		for ( $g = 0; $g < 3; $g++ ) {
			$group = '';
			for ( $c = 0; $c < 4; $c++ ) {
				$group .= self::ALPHABET[ random_int( 0, $max ) ];
			}
			$groups[] = $group;
		}
		return implode( '-', $groups );
	}
}
