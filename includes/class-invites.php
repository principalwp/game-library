<?php
/**
 * Invite issuance, redemption, and revocation: `gamelib_invites`.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The only class that reads or writes `gamelib_invites`.
 *
 * An invite is a 32-character hex code minted by a member and consumable
 * exactly once. Three rules shape every method here:
 *
 * - **The code is unguessable, not merely unique.** It comes from
 *   `random_bytes()` — a CSPRNG — never from `wp_generate_password()` or
 *   `rand()` (AC-001a). The `UNIQUE KEY code` on the table is the collision
 *   backstop, not the source of the guarantee.
 * - **The database arbitrates redemption, not PHP.** Consumption is one
 *   conditional `UPDATE … WHERE code = %s AND status = 'outstanding'` whose
 *   affected-row count decides the outcome (AC-004a). A read-then-write pair
 *   would hand two concurrent submissions the same invite; the loser of this
 *   race sees the same generic invalid message an unknown code produces
 *   (AC-004b). {@see GameLib_Invites::redeem()} owns the whole claim → create →
 *   revert-on-failure sequence for that reason: no caller can forget the revert
 *   half (AC-004c).
 * - **The quota is site-wide and counts everything ever issued.** Redeemed and
 *   revoked invites never replenish a member's allowance (AC-002b), and there
 *   is no per-member numeric override anywhere in this plugin (Q-SPEC-2). The
 *   only per-member control is the binary AC-007(b) disable toggle, checked
 *   before the quota and independent of it.
 *
 * Caching follows the plugin's generation-counter discipline under the shared
 * `invites` scope — with one deliberate exception, the write-path lookups
 * ({@see GameLib_Invites::is_redeemable()} and the conditional UPDATEs). Those
 * read the table directly on every call: their answer decides the very next
 * statement, and an object-cache copy written a moment earlier by another
 * request would be exactly the wrong input (AC-NFR-010c).
 */
final class GameLib_Invites {

	/**
	 * Schema suffix of the invite table.
	 *
	 * @var string
	 */
	const TABLE = 'invites';

	/**
	 * Site-wide invite quota option; `0` means unlimited (AC-002a, AC-054c).
	 *
	 * @var string
	 */
	const QUOTA_OPTION = 'gamelib_invite_quota';

	/**
	 * Status of an invite that has never been used.
	 *
	 * @var string
	 */
	const STATUS_OUTSTANDING = 'outstanding';

	/**
	 * Status of an invite that created an account.
	 *
	 * @var string
	 */
	const STATUS_REDEEMED = 'redeemed';

	/**
	 * Status of an invite an administrator withdrew (AC-006f).
	 *
	 * @var string
	 */
	const STATUS_REVOKED = 'revoked';

	/**
	 * The three statuses a row may carry — the write whitelist (no ENUM: the
	 * DDL has to survive SQLite translation).
	 *
	 * @var string[]
	 */
	const STATUSES = array(
		self::STATUS_OUTSTANDING,
		self::STATUS_REDEEMED,
		self::STATUS_REVOKED,
	);

	/**
	 * Random bytes behind one code. 16 bytes → 32 hex characters (AC-001a).
	 *
	 * @var int
	 */
	const CODE_BYTES = 16;

	/**
	 * The shape every stored code has, and the only shape a lookup will spend a
	 * query on.
	 *
	 * @var string
	 */
	const CODE_PATTERN = '/^[0-9a-f]{32}$/';

	/**
	 * Value {@see GameLib_Invites::allowance()} reports as `remaining` when the
	 * site quota is unlimited — "no ceiling", which no non-negative count could
	 * express.
	 *
	 * @var int
	 */
	const UNLIMITED = -1;

	/**
	 * Default rows in one list read.
	 *
	 * @var int
	 */
	const LIST_LIMIT = 20;

	/**
	 * Hard ceiling on a single list read, whatever a caller asks for. There is
	 * no unbounded invite query in this plugin (Never Do #8).
	 *
	 * @var int
	 */
	const MAX_LIST_LIMIT = 100;

	/**
	 * Error code: the issuing user id is missing or does not exist.
	 *
	 * @var string
	 */
	const ERROR_NO_ISSUER = 'gamelib_invite_no_issuer';

	/**
	 * Error code: an administrator switched this member's issuance off
	 * (AC-007b). Checked before the quota and independent of it.
	 *
	 * @var string
	 */
	const ERROR_DISABLED = 'gamelib_invite_disabled';

	/**
	 * Error code: the member has used their whole site-wide allowance (AC-002c).
	 *
	 * @var string
	 */
	const ERROR_QUOTA = 'gamelib_invite_quota';

	/**
	 * Error code: the database refused the write.
	 *
	 * @var string
	 */
	const ERROR_FAILED = 'gamelib_invite_failed';

	/**
	 * Error code: unknown, already redeemed, or revoked — the three causes
	 * AC-005 requires be indistinguishable from outside.
	 *
	 * @var string
	 */
	const ERROR_INVALID = 'gamelib_invite_invalid';

	/**
	 * The site-wide invite quota.
	 *
	 * @return int Invites each member may ever issue; 0 means unlimited (AC-002a).
	 */
	public static function quota() {
		return absint( get_option( self::QUOTA_OPTION, 0 ) );
	}

	/**
	 * A quota value on its way into the option (AC-054c).
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int Non-negative integer; 0 means unlimited.
	 */
	public static function sanitize_quota( $value ) {
		return is_scalar( $value ) ? absint( $value ) : 0;
	}

	/**
	 * What one member may still issue, and why not when they may not.
	 *
	 * The single answer to "can this member create an invite right now?", shared
	 * by the REST routes, the account panel, and {@see GameLib_Invites::create()}
	 * itself, so the number the UI shows and the number the write enforces can
	 * never disagree.
	 *
	 * `issued` counts every invite the member has ever created regardless of
	 * status: revoking or redeeming one never gives the slot back (AC-002b).
	 *
	 * @param int $user_id Member id.
	 * @return array{quota:int,issued:int,remaining:int,unlimited:bool,disabled:bool,can_create:bool}
	 *         Allowance; `remaining` is {@see GameLib_Invites::UNLIMITED} when the quota is 0.
	 */
	public static function allowance( $user_id ) {
		$user_id   = self::valid_id( $user_id );
		$quota     = self::quota();
		$counts    = self::counts_for_user( $user_id );
		$issued    = $counts['total'];
		$unlimited = ( $quota < 1 );
		$remaining = $unlimited ? self::UNLIMITED : max( 0, $quota - $issued );
		$disabled  = GameLib_Capabilities::invites_disabled( $user_id );

		return array(
			'quota'      => $quota,
			'issued'     => $issued,
			'remaining'  => $remaining,
			'unlimited'  => $unlimited,
			'disabled'   => $disabled,
			'can_create' => ( ! $disabled && ( $unlimited || $remaining > 0 ) ),
		);
	}

	/**
	 * Mint one single-use invite for a member (AC-001).
	 *
	 * Order is the AC order: the AC-007(b) binary disable gate is consulted
	 * first and blocks issuance outright — a disabled member cannot issue even
	 * with a whole quota unspent — then the site-wide quota, then the write.
	 *
	 * Authorization is the caller's: the REST route verifies the cookie nonce
	 * and `gamelib_issue_invites` before arriving here. The two checks below are
	 * domain rules, not a substitute for that.
	 *
	 * @param int $issuer_id Member issuing the invite.
	 * @return array{id:int,code:string,url:string,status:string,created_at:string,redeemed_at:string}|WP_Error
	 *         The new invite, or the reason it was refused.
	 */
	public static function create( $issuer_id ) {
		$issuer_id = self::valid_id( $issuer_id );

		if ( $issuer_id < 1 || ! get_userdata( $issuer_id ) instanceof WP_User ) {
			return new WP_Error(
				self::ERROR_NO_ISSUER,
				__( 'Sign in to create an invite.', 'game-library' ),
				array( 'status' => 401 )
			);
		}

		if ( GameLib_Capabilities::invites_disabled( $issuer_id ) ) {
			return new WP_Error(
				self::ERROR_DISABLED,
				__( 'Invite creation is switched off for your account. Ask an administrator if you think that is a mistake.', 'game-library' ),
				array( 'status' => 403 )
			);
		}

		$allowance = self::allowance( $issuer_id );

		if ( ! $allowance['unlimited'] && $allowance['remaining'] < 1 ) {
			return new WP_Error(
				self::ERROR_QUOTA,
				self::quota_message( $allowance ),
				array(
					'status'    => 403,
					'allowance' => $allowance,
				)
			);
		}

		$code = self::new_code();

		if ( '' === $code ) {
			return new WP_Error(
				self::ERROR_FAILED,
				__( 'That invite could not be created. Please try again.', 'game-library' ),
				array( 'status' => 500 )
			);
		}

		global $wpdb;

		$created_at = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->insert() prepares its own statement, and every cached invite read is invalidated by the generation bump below.
		$written = $wpdb->insert(
			GameLib_Schema::table( self::TABLE ),
			array(
				'code'        => $code,
				'issuer_id'   => $issuer_id,
				'redeemer_id' => null,
				'status'      => self::STATUS_OUTSTANDING,
				'created_at'  => $created_at,
				'redeemed_at' => null,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $written ) {
			return new WP_Error(
				self::ERROR_FAILED,
				__( 'That invite could not be created. Please try again.', 'game-library' ),
				array( 'status' => 500 )
			);
		}

		self::invalidate();

		return array(
			'id'          => (int) $wpdb->insert_id,
			'code'        => $code,
			'url'         => self::join_url( $code ),
			'status'      => self::STATUS_OUTSTANDING,
			'created_at'  => $created_at,
			'redeemed_at' => '',
		);
	}

	/**
	 * Consume one invite, creating its redeemer — or leave it untouched.
	 *
	 * The whole AC-004 sequence lives here rather than in the registration
	 * handler, because the three steps are only correct together:
	 *
	 * 1. **Claim.** One conditional UPDATE (AC-004a). Exactly one affected row
	 *    means this request owns the invite; anything else means it was already
	 *    unknown, redeemed, or revoked, and the caller renders the one generic
	 *    invalid message (AC-004b, AC-005).
	 * 2. **Create.** The callback mints the account. It runs *after* the claim,
	 *    so two simultaneous submissions of the same code can never both create
	 *    a user.
	 * 3. **Revert.** A failed creation returns the invite to `outstanding`
	 *    (AC-004c) — a member whose chosen email turned out to be taken has not
	 *    burned their invitation.
	 *
	 * The redeemer id is attached after creation; the intervening window is a
	 * claimed row with `redeemer_id` still NULL, which is precisely what the
	 * revert keys on so it can never un-claim a completed redemption.
	 *
	 * @param string   $code            Invite code from the URL.
	 * @param callable $create_redeemer Creates the account; returns the new user id or WP_Error.
	 * @return int|WP_Error The new user's id, or the reason nothing was consumed.
	 */
	public static function redeem( $code, $create_redeemer ) {
		$code = self::sanitize_code( $code );

		if ( '' === $code || ! is_callable( $create_redeemer ) ) {
			return self::invalid_error();
		}

		if ( ! self::claim( $code ) ) {
			return self::invalid_error();
		}

		self::invalidate();

		$user_id = call_user_func( $create_redeemer );

		if ( is_wp_error( $user_id ) || ! is_numeric( $user_id ) || (int) $user_id < 1 ) {
			self::release( $code );
			self::invalidate();

			return is_wp_error( $user_id )
				? $user_id
				: new WP_Error(
					self::ERROR_FAILED,
					__( 'That account could not be created. Please try again.', 'game-library' ),
					array( 'status' => 500 )
				);
		}

		$user_id = (int) $user_id;

		self::attach_redeemer( $code, $user_id );
		self::invalidate();

		return $user_id;
	}

	/**
	 * Withdraw an outstanding invite (AC-006f).
	 *
	 * Conditional on the row still being outstanding, so revoking cannot undo a
	 * redemption and a double-submitted Revoke action is not an error the second
	 * time — it is simply a row this call did not change.
	 *
	 * Authorization (the `gamelib_admin_override` capability and the admin-post
	 * nonce) belongs to the caller.
	 *
	 * @param int $invite_id Invite row id.
	 * @return true|WP_Error True when the invite is revoked on return.
	 */
	public static function revoke( $invite_id ) {
		$invite_id = self::valid_id( $invite_id );

		if ( $invite_id < 1 ) {
			return self::invalid_error();
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->update() prepares its own statement, and every cached invite read is invalidated by the generation bump below.
		$updated = $wpdb->update(
			GameLib_Schema::table( self::TABLE ),
			array( 'status' => self::STATUS_REVOKED ),
			array(
				'id'     => $invite_id,
				'status' => self::STATUS_OUTSTANDING,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		if ( 1 !== (int) $updated ) {
			// Already redeemed, already revoked, or gone: nothing to withdraw.
			return new WP_Error(
				self::ERROR_INVALID,
				__( 'Only an outstanding invite can be revoked.', 'game-library' ),
				array( 'status' => 409 )
			);
		}

		self::invalidate();

		return true;
	}

	/**
	 * Is this code one that `/join/` should offer a registration form for?
	 *
	 * Deliberately uncached, and deliberately a boolean: the answer decides
	 * whether the very next statement claims the row (AC-NFR-010c), and no
	 * caller ever learns *which* of unknown, redeemed, or revoked it was
	 * (AC-005a) or who issued it (AC-005b).
	 *
	 * @param string $code Invite code from the URL.
	 * @return bool True when an outstanding invite carries this code.
	 */
	public static function is_redeemable( $code ) {
		$code = self::sanitize_code( $code );

		if ( '' === $code ) {
			return false;
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The documented uncached exception (AC-NFR-010c): a redemption write-path lookup whose answer the next statement acts on. A cached copy would offer a form for an invite another request just consumed.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); both values are bound placeholders.
				"SELECT 1 FROM {$table} WHERE code = %s AND status = %s",
				$code,
				self::STATUS_OUTSTANDING
			)
		);

		return null !== $found;
	}

	/**
	 * How many invites a member has issued, by status.
	 *
	 * @param int $user_id Issuing member.
	 * @return array{total:int,outstanding:int,redeemed:int,revoked:int} Counts.
	 */
	public static function counts_for_user( $user_id ) {
		$user_id = self::valid_id( $user_id );
		$empty   = array(
			'total'                  => 0,
			self::STATUS_OUTSTANDING => 0,
			self::STATUS_REDEEMED    => 0,
			self::STATUS_REVOKED     => 0,
		);

		if ( $user_id < 1 ) {
			return $empty;
		}

		$counts = self::cached(
			'counts:' . $user_id,
			static function () use ( $user_id, $empty ) {
				global $wpdb;

				$table = GameLib_Schema::table( self::TABLE );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; the caller caches this value under the shared `invites` generation scope.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); the one value is a bound placeholder.
						"SELECT status, COUNT(*) AS rows_total FROM {$table} WHERE issuer_id = %d GROUP BY status",
						$user_id
					),
					ARRAY_A
				);

				$counts = $empty;

				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$status = isset( $row['status'] ) ? (string) $row['status'] : '';
					$total  = isset( $row['rows_total'] ) ? absint( $row['rows_total'] ) : 0;

					if ( in_array( $status, self::STATUSES, true ) ) {
						$counts[ $status ] = $total;
					}

					// Every row counts toward the quota, whatever its status
					// (AC-002b) — including a status an older version wrote.
					$counts['total'] += $total;
				}

				return $counts;
			}
		);

		return is_array( $counts ) ? array_merge( $empty, $counts ) : $empty;
	}

	/**
	 * Every invite in the site, newest first (AC-006).
	 *
	 * The admin list table's read. Paginated at the database, never in PHP.
	 *
	 * @param int $limit  Optional. Rows to return; clamped to {@see MAX_LIST_LIMIT}.
	 * @param int $offset Optional. Rows to skip.
	 * @return array[] Rows with id, code, issuer_id, redeemer_id, status, created_at, redeemed_at.
	 */
	public static function list_all( $limit = self::LIST_LIMIT, $offset = 0 ) {
		$limit  = self::clamp_limit( $limit );
		$offset = max( 0, (int) $offset );

		$rows = self::cached(
			'all:' . $limit . ':' . $offset,
			static function () use ( $limit, $offset ) {
				global $wpdb;

				$table = GameLib_Schema::table( self::TABLE );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; the caller caches this page under the shared `invites` generation scope.
				return $wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); both values are bound placeholders.
						"SELECT id, code, issuer_id, redeemer_id, status, created_at, redeemed_at FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
						$limit,
						$offset
					),
					ARRAY_A
				);
			}
		);

		return self::hydrate_rows( $rows );
	}

	/**
	 * One member's own invites, newest first (AC-001 UI).
	 *
	 * @param int $user_id Issuing member.
	 * @param int $limit   Optional. Rows to return; clamped to {@see MAX_LIST_LIMIT}.
	 * @param int $offset  Optional. Rows to skip.
	 * @return array[] Rows with id, code, issuer_id, redeemer_id, status, created_at, redeemed_at.
	 */
	public static function list_for_user( $user_id, $limit = self::LIST_LIMIT, $offset = 0 ) {
		$user_id = self::valid_id( $user_id );
		$limit   = self::clamp_limit( $limit );
		$offset  = max( 0, (int) $offset );

		if ( $user_id < 1 ) {
			return array();
		}

		$rows = self::cached(
			'user:' . $user_id . ':' . $limit . ':' . $offset,
			static function () use ( $user_id, $limit, $offset ) {
				global $wpdb;

				$table = GameLib_Schema::table( self::TABLE );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; the caller caches this page under the shared `invites` generation scope.
				return $wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); every value is a bound placeholder.
						"SELECT id, code, issuer_id, redeemer_id, status, created_at, redeemed_at FROM {$table} WHERE issuer_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
						$user_id,
						$limit,
						$offset
					),
					ARRAY_A
				);
			}
		);

		return self::hydrate_rows( $rows );
	}

	/**
	 * How many invites exist site-wide (the admin table's pagination total).
	 *
	 * @return int Row count.
	 */
	public static function total_count() {
		return (int) self::cached(
			'total',
			static function () {
				global $wpdb;

				$table = GameLib_Schema::table( self::TABLE );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; the caller caches this value under the shared `invites` generation scope.
				return (int) $wpdb->get_var(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is GameLib_Schema::table(); the statement carries no values to bind.
					"SELECT COUNT(*) FROM {$table}"
				);
			}
		);
	}

	/**
	 * Delete a member's unredeemed invites (AC-052b).
	 *
	 * Redeemed rows are deliberately kept: they are the registration trail
	 * behind an account that still exists, and AC-052(d) has the eraser report
	 * them as retained rather than silently dropping them.
	 *
	 * @param int $user_id Member being purged.
	 * @return int Invites deleted.
	 */
	public static function purge_user( $user_id ) {
		$user_id = self::valid_id( $user_id );

		if ( $user_id < 1 ) {
			return 0;
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; the invite generation scope is bumped below.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); both values are bound placeholders.
				"DELETE FROM {$table} WHERE issuer_id = %d AND status <> %s",
				$user_id,
				self::STATUS_REDEEMED
			)
		);

		self::invalidate();

		return max( 0, (int) $deleted );
	}

	/**
	 * The shareable URL of one invite (AC-001b).
	 *
	 * @param string $code Invite code.
	 * @return string Absolute `{home_url}/join/{code}/` URL, or '' for a malformed code.
	 */
	public static function join_url( $code ) {
		$code = self::sanitize_code( $code );

		return ( '' === $code ) ? '' : GameLib_Router::route_url( GameLib_Router::ROUTE_JOIN, $code );
	}

	/**
	 * One row reduced to what a response or a template needs.
	 *
	 * @param array $row Row from a list method.
	 * @return array{id:int,code:string,url:string,status:string,created_at:string,redeemed_at:string} Payload.
	 */
	public static function payload( array $row ) {
		$code = isset( $row['code'] ) ? self::sanitize_code( $row['code'] ) : '';

		return array(
			'id'          => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'code'        => $code,
			'url'         => self::join_url( $code ),
			'status'      => isset( $row['status'] ) ? (string) $row['status'] : '',
			'created_at'  => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			'redeemed_at' => isset( $row['redeemed_at'] ) ? (string) $row['redeemed_at'] : '',
		);
	}

	/**
	 * The one message every invalid invite produces (AC-005).
	 *
	 * Unknown, redeemed, and revoked all end here, with no hint of which and no
	 * mention of the issuer — the distinction is never surfaced anywhere.
	 *
	 * @return string Translated message.
	 */
	public static function invalid_message() {
		return __( 'This invite link is not valid.', 'game-library' );
	}

	/**
	 * A code normalized to the only shape this class stores.
	 *
	 * @param mixed $code Raw code from a URL, a form, or a row.
	 * @return string Lowercase 32-character hex code, or '' when it is not one.
	 */
	public static function sanitize_code( $code ) {
		$code = is_scalar( $code ) ? strtolower( trim( (string) $code ) ) : '';

		return preg_match( self::CODE_PATTERN, $code ) ? $code : '';
	}

	/**
	 * The AC-004(a) claim: one conditional UPDATE, arbitrated by the database.
	 *
	 * Written as a single statement on purpose. A `SELECT … WHERE status =
	 * 'outstanding'` followed by an `UPDATE` would let two concurrent
	 * submissions both observe an outstanding invite and both proceed; here the
	 * second one updates zero rows and is refused.
	 *
	 * @param string $code Normalized invite code.
	 * @return bool True when exactly one row moved from outstanding to redeemed.
	 */
	private static function claim( $code ) {
		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The AC-004(a) conditional claim: a single write statement whose affected-row count is the race's arbiter, and the documented uncached path (AC-NFR-010c).
		$claimed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); every value is a bound placeholder.
				"UPDATE {$table} SET status = %s, redeemed_at = %s WHERE code = %s AND status = %s",
				self::STATUS_REDEEMED,
				gmdate( 'Y-m-d H:i:s' ),
				$code,
				self::STATUS_OUTSTANDING
			)
		);

		return 1 === (int) $claimed;
	}

	/**
	 * Return a claimed invite to `outstanding` (AC-004c).
	 *
	 * Scoped to a claim that never completed — `redeemer_id IS NULL` — so a
	 * redemption that did create its account can never be un-done by a late or
	 * duplicated revert.
	 *
	 * @param string $code Normalized invite code.
	 * @return bool True when the claim was reverted.
	 */
	private static function release( $code ) {
		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The AC-004(c) revert: one conditional write statement, scoped by its own WHERE clause to a claim that never completed. The caller bumps the invite generation scope.
		$released = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); every value is a bound placeholder.
				"UPDATE {$table} SET status = %s, redeemed_at = NULL WHERE code = %s AND status = %s AND redeemer_id IS NULL",
				self::STATUS_OUTSTANDING,
				$code,
				self::STATUS_REDEEMED
			)
		);

		return 1 === (int) $released;
	}

	/**
	 * Record who redeemed a claimed invite (AC-003b).
	 *
	 * @param string $code    Normalized invite code.
	 * @param int    $user_id The account the redemption created.
	 * @return bool True when the row was completed.
	 */
	private static function attach_redeemer( $code, $user_id ) {
		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes the AC-004(a) claim this request already won; one conditional write statement, and the caller bumps the invite generation scope.
		$attached = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); every value is a bound placeholder.
				"UPDATE {$table} SET redeemer_id = %d WHERE code = %s AND status = %s AND redeemer_id IS NULL",
				$user_id,
				$code,
				self::STATUS_REDEEMED
			)
		);

		return 1 === (int) $attached;
	}

	/**
	 * A fresh CSPRNG code (AC-001a).
	 *
	 * `random_bytes()` — never `wp_generate_password()`, `rand()`, or
	 * `uniqid()`: an invite code is a bearer credential, and a predictable one
	 * would let anyone mint themselves an account.
	 *
	 * @return string 32 hex characters, or '' when the platform has no entropy source.
	 */
	private static function new_code() {
		try {
			return bin2hex( random_bytes( self::CODE_BYTES ) );
		} catch ( Exception $exception ) {
			// No CSPRNG available. Falling back to a weaker generator would be
			// worse than refusing: the code is the credential. The exception is
			// deliberately not logged — it carries no actionable detail and this
			// plugin writes no log files (Never Do #3).
			return '';
		}
	}

	/**
	 * The refusal every invalid code produces.
	 *
	 * @return WP_Error Generic invalid-invite error (AC-005).
	 */
	private static function invalid_error() {
		return new WP_Error(
			self::ERROR_INVALID,
			self::invalid_message(),
			array( 'status' => 400 )
		);
	}

	/**
	 * The message a member at their quota ceiling reads (AC-002c).
	 *
	 * Names the remaining allowance explicitly — which is always 0 here, since
	 * that is the only condition that produces this message.
	 *
	 * @param array{quota:int,issued:int,remaining:int,unlimited:bool,disabled:bool,can_create:bool} $allowance Allowance.
	 * @return string Translated message.
	 */
	private static function quota_message( array $allowance ) {
		$issued    = max( 0, (int) $allowance['issued'] );
		$remaining = max( 0, (int) $allowance['remaining'] );

		return sprintf(
			/* translators: 1: number of invites the member has already issued, 2: number of invites they have left. */
			_n(
				'You have issued %1$s invite and have %2$s left. Redeemed and revoked invites do not free up a slot.',
				'You have issued %1$s invites and have %2$s left. Redeemed and revoked invites do not free up a slot.',
				$issued,
				'game-library'
			),
			number_format_i18n( $issued ),
			number_format_i18n( $remaining )
		);
	}

	/**
	 * Rows from a list query, normalized.
	 *
	 * @param mixed $rows Raw `$wpdb` result.
	 * @return array[] Typed rows.
	 */
	private static function hydrate_rows( $rows ) {
		$hydrated = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$hydrated[] = array(
				'id'          => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'code'        => isset( $row['code'] ) ? self::sanitize_code( $row['code'] ) : '',
				'issuer_id'   => isset( $row['issuer_id'] ) ? (int) $row['issuer_id'] : 0,
				'redeemer_id' => isset( $row['redeemer_id'] ) ? (int) $row['redeemer_id'] : 0,
				'status'      => isset( $row['status'] ) ? (string) $row['status'] : '',
				'created_at'  => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
				'redeemed_at' => isset( $row['redeemed_at'] ) ? (string) $row['redeemed_at'] : '',
			);
		}

		return $hydrated;
	}

	/**
	 * Read through the shared invite scope, loading on a miss.
	 *
	 * Aggregates and list pages only. The redemption path never comes through
	 * here (AC-NFR-010c).
	 *
	 * @param string   $key    Entry key within the scope.
	 * @param callable $loader Produces the value on a miss.
	 * @return mixed Cached or freshly loaded value.
	 */
	private static function cached( $key, $loader ) {
		$found = false;
		$value = GameLib_Cache::get( GameLib_Cache::SCOPE_INVITES, $key, $found );

		if ( $found ) {
			return $value;
		}

		$value = call_user_func( $loader );

		GameLib_Cache::set( GameLib_Cache::SCOPE_INVITES, $key, $value );

		return $value;
	}

	/**
	 * Invalidate every cached invite aggregate and list page.
	 *
	 * One counter for the whole table: counts, pages, and totals all change
	 * shape on any write, and the volume here never justifies finer scopes.
	 *
	 * @return void
	 */
	private static function invalidate() {
		GameLib_Cache::bump( GameLib_Cache::SCOPE_INVITES );
	}

	/**
	 * A caller-supplied list size, clamped to the class's own ceiling.
	 *
	 * @param int $limit Requested rows.
	 * @return int Rows this class will actually read.
	 */
	private static function clamp_limit( $limit ) {
		$limit = absint( $limit );

		return max( 1, min( self::MAX_LIST_LIMIT, $limit ) );
	}

	/**
	 * One id, validated as a positive integer.
	 *
	 * @param mixed $value Candidate id.
	 * @return int The id, or 0 when the value is not a positive integer.
	 */
	private static function valid_id( $value ) {
		$id = is_scalar( $value ) ? (int) $value : 0;

		return ( $id > 0 ) ? $id : 0;
	}
}
