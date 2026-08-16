<?php
/**
 * Plugin capabilities and the roles that carry them.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the four plugin capabilities, the role map they attach to, and the
 * per-member gates layered on top of them (DD-004, D-REQ-48).
 *
 * There is no membership table and no "member" role. Membership is a
 * capability: a user belongs to the library exactly when their role carries
 * `gamelib_manage_library`. Stated plainly — **granting Subscriber anywhere on
 * this site grants library membership**, with the invite flow, the personal
 * library, follows, and the activity feed that come with it. That is the
 * D-REQ-48 tradeoff, accepted so the plugin never has to shadow WordPress's own
 * user model; a site that hands Subscriber out for an unrelated reason hands
 * out membership too. Administrators additionally carry
 * `gamelib_admin_override`, the capability behind every AC-007 override action.
 *
 * Because the map is the plugin's own, {@see GameLib_Capabilities::sync()}
 * enforces it in both directions on the two roles it manages: it adds a missing
 * grant and it removes a grant the map does not make (an older version's
 * `gamelib_admin_override` on Subscriber, say). It never touches a role outside
 * the map, so a site that deliberately grants these capabilities to Editor
 * keeps that grant. The supported way to stop one member from issuing invites
 * is the AC-007(b) toggle below, not a hand-edited role.
 */
final class GameLib_Capabilities {

	/**
	 * Create invites from `/my-library/` (AC-001).
	 *
	 * @var string
	 */
	const CAP_ISSUE_INVITES = 'gamelib_issue_invites';

	/**
	 * Search IGDB and own a library: add, restatus, remove, import, export.
	 * Doubles as the membership marker — {@see GameLib_Capabilities::is_member()}.
	 *
	 * @var string
	 */
	const CAP_MANAGE_LIBRARY = 'gamelib_manage_library';

	/**
	 * Follow and unfollow other members (AC-023).
	 *
	 * @var string
	 */
	const CAP_FOLLOW = 'gamelib_follow';

	/**
	 * Administrator-only overrides: force members-only, disable a member's
	 * invites, remove follow edges, force an IGDB refresh (AC-007, AC-035).
	 *
	 * @var string
	 */
	const CAP_ADMIN_OVERRIDE = 'gamelib_admin_override';

	/**
	 * The three capabilities that constitute membership.
	 *
	 * @var string[]
	 */
	const MEMBER_CAPS = array(
		self::CAP_ISSUE_INVITES,
		self::CAP_MANAGE_LIBRARY,
		self::CAP_FOLLOW,
	);

	/**
	 * Every capability this plugin defines — the set `sync()` reconciles.
	 *
	 * @var string[]
	 */
	const ALL_CAPS = array(
		self::CAP_ISSUE_INVITES,
		self::CAP_MANAGE_LIBRARY,
		self::CAP_FOLLOW,
		self::CAP_ADMIN_OVERRIDE,
	);

	/**
	 * The role map (DD-004): role slug => exactly the capabilities that role
	 * carries. Roles absent from this map are never modified.
	 *
	 * @var array<string, string[]>
	 */
	const ROLE_CAPS = array(
		'administrator' => self::ALL_CAPS,
		'subscriber'    => self::MEMBER_CAPS,
	);

	/**
	 * Boolean user meta set by the AC-007(b) admin override. Present and truthy
	 * means this member may not issue invites; absent means they may.
	 *
	 * @var string
	 */
	const INVITES_DISABLED_META = 'gamelib_invites_disabled';

	/**
	 * Reconcile the two managed roles with {@see GameLib_Capabilities::ROLE_CAPS}.
	 *
	 * Idempotent and, once the roles match the map, free: `WP_Role::add_cap()`
	 * and `remove_cap()` are called only for a capability whose stored state
	 * actually differs, so a settled site performs no option write — the checks
	 * read `$wp_roles`, which WordPress has already loaded from the autoloaded
	 * `wp_user_roles` option on every request.
	 *
	 * Called from activation *and* from `init` (priority 5) for the same reason
	 * the schema check runs there: a plugin whose files are mounted into a site
	 * where it is already active never re-fires `register_activation_hook()`, so
	 * an activation-only grant would leave that site with no members at all.
	 *
	 * See principal/adr/007-capability-sync-on-init.md — T3's VERIFY block says
	 * grants belong in the activation hook because per-request granting would
	 * write options on every page load; a guarded reconciliation writes nothing
	 * once the roles match, which is what makes the `init` call affordable.
	 *
	 * @return void
	 */
	public static function sync() {
		foreach ( self::ROLE_CAPS as $role_name => $granted_caps ) {
			$role = get_role( $role_name );

			if ( ! $role instanceof WP_Role ) {
				// A site without this role (Subscriber removed, for instance).
				continue;
			}

			foreach ( self::ALL_CAPS as $cap ) {
				$should_have = in_array( $cap, $granted_caps, true );
				$has         = ! empty( $role->capabilities[ $cap ] );

				if ( $should_have === $has ) {
					continue;
				}

				if ( $should_have ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * Is this user a member of the library?
	 *
	 * The membership test for the whole plugin. It asks for a specific
	 * capability rather than `is_user_logged_in()` (Always Do #3), so a
	 * logged-in user whose role carries none of the plugin's capabilities — an
	 * Editor on a site that also publishes editorial content, say — is treated
	 * as a visitor by every gate, including the AC-028 matrix.
	 *
	 * @param int $user_id User id; 0 or a deleted user is never a member.
	 * @return bool True when the user's role carries the membership capability.
	 */
	public static function is_member( $user_id ) {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		return user_can( $user_id, self::CAP_MANAGE_LIBRARY );
	}

	/**
	 * Has an administrator switched off this member's invite issuance?
	 *
	 * Binary and independent of the site-wide AC-002 quota (Q-SPEC-2): the
	 * override is never a numeric per-member allowance.
	 *
	 * @param int $user_id Member to inspect.
	 * @return bool True when the member may not issue invites.
	 */
	public static function invites_disabled( $user_id ) {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		$value = (string) get_user_meta( $user_id, self::INVITES_DISABLED_META, true );

		return '' !== $value && '0' !== $value;
	}

	/**
	 * The AC-NFR-001 permission-gate contract, in one place.
	 *
	 * Every `gamelib/v1` route answers a logged-out caller with 401 and a
	 * signed-in caller whose role lacks the route's capability with 403. The
	 * codes (`gamelib_rest_unauthorized` / `gamelib_rest_forbidden`), the
	 * statuses, and the `state` keys are the same for all 21 endpoints — only
	 * the capability and the two sentences differ, which is why they are the
	 * only parameters. The E2E matrix (T25–T28) asserts the codes, statuses, and
	 * states verbatim, so a change here is a change to every route family at
	 * once, which is the point of there being one definition.
	 *
	 * A cookie-authenticated request that omits or fails the `wp_rest` nonce
	 * never reaches a caller of this method: core treats a nonce-less request as
	 * anonymous (so this answers 401 even though a login cookie was sent) and
	 * rejects a wrong nonce with its own 403 before any permission callback runs.
	 *
	 * @param string $cap             Capability the route requires.
	 * @param string $signin_message  Sentence for a logged-out caller (401).
	 * @param string $denied_message  Sentence for a signed-in caller without the
	 *                                capability (403).
	 * @return true|WP_Error True when the caller may proceed.
	 */
	public static function rest_gate( $cap, $signin_message, $denied_message ) {
		if ( ! is_user_logged_in() ) {
			return self::rest_signin_error( $signin_message );
		}

		if ( ! current_user_can( $cap ) ) {
			return self::rest_forbidden_error( $denied_message );
		}

		return true;
	}

	/**
	 * The one 401 this plugin's REST surface produces.
	 *
	 * Separate from {@see rest_gate()} because one gate interleaves a check
	 * between the two halves: {@see GameLib_REST_Account::check_issuer()} reports
	 * the AC-007(b) invite switch-off with its own code, and it has to do so
	 * *before* the capability test, since `filter_user_has_cap()` has already
	 * withdrawn the capability from a switched-off member.
	 *
	 * @param string $message Sentence for a logged-out caller.
	 * @return WP_Error 401, state `unauthorized`.
	 */
	public static function rest_signin_error( $message ) {
		return new WP_Error(
			'gamelib_rest_unauthorized',
			$message,
			array(
				'status' => 401,
				'state'  => 'unauthorized',
			)
		);
	}

	/**
	 * The one "signed in, but not allowed" 403 this plugin's REST surface
	 * produces. See {@see rest_signin_error()} for why it is callable on its own.
	 *
	 * @param string $message Sentence for a caller without the capability.
	 * @return WP_Error 403, state `forbidden`.
	 */
	public static function rest_forbidden_error( $message ) {
		return new WP_Error(
			'gamelib_rest_forbidden',
			$message,
			array(
				'status' => 403,
				'state'  => 'forbidden',
			)
		);
	}

	/**
	 * Enforce the AC-007(b) override at the capability-check layer.
	 *
	 * `user_has_cap` runs inside `WP_User::has_cap()`, so withdrawing
	 * `gamelib_issue_invites` here blocks issuance outright for a disabled
	 * member: the REST route's capability check fails, and every UI affordance
	 * gated on `current_user_can()` disappears with it. No caller has to
	 * remember to consult the meta value separately.
	 *
	 * Only the invite capability is inspected, and only when the user actually
	 * holds it, so the common case costs one `isset()`.
	 *
	 * @param array<string, bool> $allcaps All capabilities the user currently has.
	 * @param string[]            $caps    Primitive capabilities being checked.
	 * @param array               $args    Context: [ requested cap, user id, ... ].
	 * @param WP_User             $user    The user being tested.
	 * @return array<string, bool> Filtered capability map.
	 */
	public static function filter_user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( empty( $allcaps[ self::CAP_ISSUE_INVITES ] ) ) {
			return $allcaps;
		}

		$user_id = ( $user instanceof WP_User ) ? (int) $user->ID : 0;

		if ( $user_id > 0 && self::invites_disabled( $user_id ) ) {
			$allcaps[ self::CAP_ISSUE_INVITES ] = false;
		}

		return $allcaps;
	}
}
