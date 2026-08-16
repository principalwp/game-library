<?php
/**
 * Capability grants, wp-admin lockdown, and admin-bar suppression.
 *
 * @package Game_Library
 */

namespace Game_Library;

use Game_Library\Data\Generations;
use WP_Role;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Roles.
 *
 * Owns the plugin's capability model: which roles gain which `gl_*`
 * capabilities (AC-002 (a)-(d)), the Subscriber wp-admin lockdown
 * (AC-002 (e)), and admin-bar suppression on the front end for anyone who
 * cannot `edit_posts` (AC-002 (f)).
 */
final class Roles {

	/**
	 * Screens exempt from the wp-admin lockdown redirect.
	 *
	 * @var string[]
	 */
	private const EXEMPT_SCREENS = array( 'profile.php', 'admin-ajax.php' );

	/**
	 * User meta key flatly marking "this user currently holds
	 * `gl_manage_library`" (PB-2, cycle-7) — see `sync_member_marker()`'s
	 * own docblock for why `Member_Directory` needs this alongside the real
	 * role-level capability.
	 *
	 * @var string
	 */
	public const MEMBER_META_KEY = '_gl_member';

	/**
	 * The stored value meaning "is a member" — a literal string, matching
	 * `Visibility::PUBLIC_VALUE`'s own load-bearing string encoding.
	 *
	 * @var string
	 */
	public const MEMBER_META_VALUE = '1';

	/**
	 * Users processed per `backfill_member_markers()` batch (MR-3, cycle-8) —
	 * see that method's own docblock for why the backfill is bounded rather
	 * than running `get_users()` with no `number` argument over the whole
	 * `gl_manage_library` set.
	 *
	 * @var int
	 */
	private const BACKFILL_BATCH_SIZE = 500;

	/**
	 * The scheduled single-event hook `backfill_member_markers()` queues
	 * itself onto to continue a bounded batch that did not finish the whole
	 * set (MR-3, cycle-8).
	 *
	 * @var string
	 */
	public const BACKFILL_CONTINUE_HOOK = 'game_library_backfill_member_markers';

	/**
	 * Durably records that a `backfill_member_markers()` batch could not
	 * schedule its own continuation (MR9-2, cycle-9) — `wp_schedule_single_event()`
	 * returns `false` whenever the `pre_schedule_event`/`schedule_event` filter
	 * chain refuses it (a `DISABLE_WP_CRON` host with no external runner, a
	 * cron-option write failure, or VIP's Cron Control), and nothing else in
	 * this class's own resume path retries. `install_or_upgrade()` records
	 * `Activator::DB_VERSION_OPTION` BEFORE this backfill runs (MR-3,
	 * cycle-8), so `maybe_upgrade()` never re-enters on a later request — the
	 * only way back in is `wp game-library migrate` (`drain_member_marker_backfill()`)
	 * or a later successful continuation. Set when a batch's own
	 * `wp_schedule_single_event()` call fails, cleared once a batch finishes
	 * the whole set (a batch smaller than `BACKFILL_BATCH_SIZE`) or
	 * `drain_member_marker_backfill()` runs to completion. Surfaced via
	 * `Plugin::render_backfill_stalled_notice()` — see that method's own
	 * docblock; same "never silently skipped" reasoning
	 * `Page_Cache::schedule_purge_for_user()` already documents for its own
	 * discarded `wp_schedule_single_event()` return.
	 *
	 * @var string
	 */
	public const BACKFILL_STALLED_OPTION = 'gl_backfill_stalled';

	/**
	 * Register this service's hooks.
	 *
	 * See principal/adr/006-wp-admin-lockdown-on-init-not-admin-init.md — the
	 * wp-admin lockdown is registered on `init`, not `admin_init` as the spec's
	 * Integration Points table names, because core denies access to any admin
	 * screen a user lacks the registered menu capability for — via
	 * `wp-admin/menu.php`'s own `wp_die()` gate — before `admin_init` ever
	 * fires; an `admin_init` callback never gets a chance to run for exactly
	 * the screens AC-002 (e) needs it for (e.g. `edit.php`,
	 * `options-general.php`, or this plugin's own `manage_options`-gated
	 * settings screen). `init` fires earlier, before
	 * that core gate, for both admin and front-end requests alike, so the
	 * redirect is scoped with an explicit `is_admin()` check instead.
	 *
	 * PB-2/PB-9 (cycle-7): `set_user_role`/`add_user_role`/`remove_user_role`
	 * are the mutation paths an earlier pass (MR-4) missed — an administrator
	 * moving a user between roles changes membership in the
	 * `gl_manage_library` set (and therefore both `MEMBER_META_KEY` and
	 * `Member_Directory`'s cached id lists/totals) with no bump anywhere
	 * before this. `user_register`'s own new-user role assignment already
	 * runs (core sets the role before firing this action), so
	 * `sync_member_marker()` there sees the final capability state.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'maybe_redirect_from_admin' ), 10 );
		add_filter( 'show_admin_bar', array( $this, 'maybe_hide_admin_bar' ) ); // phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected -- maybe_hide_admin_bar() always excludes administrator/vip_support (both hold edit_posts, the capability the filter gates on), matching this exact rule's own override text ("if these roles are already excluded, this warning can be ignored").
		// MR-4/PB-2/PB-9: every hook that can change membership in the
		// gl_manage_library set — see Generations::members_key()'s own
		// docblock for the full bump-site list. deleted_user cannot sync the
		// marker (the account, and its meta with it, is already gone by the
		// time this fires) so it only bumps; every other site both syncs
		// MEMBER_META_KEY and bumps.
		add_action( 'user_register', array( __CLASS__, 'sync_and_bump_member_directory' ) );
		add_action( 'deleted_user', array( __CLASS__, 'bump_member_directory_generation' ) );
		add_action( 'set_user_role', array( __CLASS__, 'sync_and_bump_member_directory' ) );
		add_action( 'add_user_role', array( __CLASS__, 'sync_and_bump_member_directory' ) );
		add_action( 'remove_user_role', array( __CLASS__, 'sync_and_bump_member_directory' ) );
		// MR-3 (cycle-8): the bounded backfill's own continuation — see
		// backfill_member_markers()'s docblock for why a batch that does not
		// finish the whole set schedules itself here instead of looping.
		add_action( self::BACKFILL_CONTINUE_HOOK, array( __CLASS__, 'backfill_member_markers' ) );
	}

	/**
	 * Bumps `Member_Directory`'s shared generation counter (MR-4) — see
	 * `Generations::members_key()`'s own docblock for every call site.
	 *
	 * @return void
	 */
	public static function bump_member_directory_generation() {
		Generations::bump( Generations::members_key() );
	}

	/**
	 * Syncs `MEMBER_META_KEY` for one user, then bumps the shared
	 * `members_gen` generation counter — the combined callback every role-
	 * change hook in `register_hooks()` (other than `deleted_user`, which
	 * has no account left to sync) registers.
	 *
	 * @param int $user_id User whose role just changed.
	 * @return void
	 */
	public static function sync_and_bump_member_directory( $user_id ) {
		self::sync_member_marker( $user_id );
		self::bump_member_directory_generation();
	}

	/**
	 * Keeps `MEMBER_META_KEY` in sync with the real `gl_manage_library`
	 * capability for one user (PB-2, cycle-7).
	 *
	 * `Member_Directory::member_ids()`/`member_count()` used to query
	 * `WP_User_Query`'s `'capability' => 'gl_manage_library'` directly, which
	 * core expands into a leading-wildcard `meta_value LIKE '%"..."%'`
	 * clause against `wp_usermeta` — unindexable by construction, and a scan
	 * of every user on the site regardless of role. This flat marker gives
	 * that same query something `wp_usermeta`'s own `meta_key` index can
	 * serve. `grant_capabilities()`/`remove_capabilities()` change the
	 * capability at the ROLE level, not per user, so they cannot call this
	 * method directly (no single `$user_id` to sync) —
	 * `Activator::install_or_upgrade()` instead runs a one-time, bounded
	 * `backfill_member_markers()` bulk sync after `grant_capabilities()`
	 * (MR-3, cycle-8); see that method's own docblock for why the marker is
	 * deliberately NOT bulk-deleted from `remove_capabilities()`.
	 *
	 * `Erasure_Service::erase()` (MR-1, cycle-8) also calls this directly for
	 * one user, on the eraser path only, guarded on the account still
	 * existing — see that call site's own comment for why.
	 *
	 * @param int $user_id User to sync.
	 * @return void
	 */
	public static function sync_member_marker( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return;
		}

		if ( user_can( $user_id, 'gl_manage_library' ) ) {
			update_user_meta( $user_id, self::MEMBER_META_KEY, self::MEMBER_META_VALUE );
		} else {
			delete_user_meta( $user_id, self::MEMBER_META_KEY );
		}
	}

	/**
	 * Redirects a user who cannot `edit_posts` out of wp-admin, except for
	 * the screens and request types every Subscriber still needs
	 * (AC-002 (e)).
	 *
	 * @return void
	 */
	public function maybe_redirect_from_admin() {
		if ( ! is_admin() ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( current_user_can( 'edit_posts' ) ) {
			return;
		}

		global $pagenow;

		if ( in_array( $pagenow, self::EXEMPT_SCREENS, true ) ) {
			return;
		}

		wp_safe_redirect( Router::my_library_url() );
		exit;
	}

	/**
	 * Hides the admin bar on the front end for a user who cannot
	 * `edit_posts` (AC-002 (f)). wp-admin's own admin bar is left alone —
	 * this only governs the front end.
	 *
	 * @param bool $show Whether the admin bar would otherwise be shown.
	 * @return bool
	 */
	public function maybe_hide_admin_bar( $show ) {
		if ( is_admin() ) {
			return $show;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		return $show;
	}

	/**
	 * Grants every `gl_*` capability this plugin defines (AC-002 (a)-(c)).
	 * `Administrator` retains `manage_options` by default — it is never
	 * removed or altered here (AC-002 (d)).
	 *
	 * @return void
	 */
	public static function grant_capabilities() {
		$subscriber = get_role( 'subscriber' );

		if ( $subscriber instanceof WP_Role ) {
			$subscriber->add_cap( 'gl_manage_library' );
			$subscriber->add_cap( 'gl_issue_invites' );
		}

		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );

			if ( $role instanceof WP_Role ) {
				$role->add_cap( 'gl_moderate_library' );
			}
		}

		// PB-2 (cycle-7): MEMBER_META_KEY must be backfilled for every
		// existing capability holder — without it, every subscriber who
		// registered before this marker existed would be invisible to
		// Member_Directory's now meta_key-keyed queries. MR-3 (cycle-8):
		// the backfill itself is no longer called from here — it is now a
		// separate, bounded, self-resuming call
		// (`Activator::install_or_upgrade()` invokes
		// `backfill_member_markers()` explicitly, AFTER the schema version
		// is recorded, so a killed backfill batch does not also lose the
		// schema/capability half; see that method's own docblock and
		// `backfill_member_markers()`'s own docblock for why an unbounded
		// `get_users()` loop here was unsafe at scale).
		self::bump_member_directory_generation();
	}

	/**
	 * Removes every `gl_*` capability this plugin granted (AC-001 (f)).
	 *
	 * Deliberately does NOT touch `MEMBER_META_KEY` user meta, even though
	 * this call (from `Activator::deactivate()` only) removes the
	 * capability the marker mirrors — AC-001 (g) requires deactivation to
	 * "drop no table, delete no option, delete no row, and delete no user
	 * meta," and a bulk `delete_user_meta()` sweep here would violate that.
	 * The marker going stale between deactivation and a future reactivation
	 * is harmless: `Member_Directory` is only ever queried by this plugin's
	 * own routes/REST endpoints, none of which are reachable while the
	 * plugin is inactive, and the `backfill_member_markers()` call
	 * `Activator::install_or_upgrade()` makes on reactivation (MR-3,
	 * cycle-8) resyncs every marker regardless of what deactivation left
	 * behind. See principal/adr/018-member-marker-not-cleared-on-deactivation.md
	 * — a deliberate deviation from PB-2's literal fix hint, which suggested
	 * deleting the marker here.
	 *
	 * @return void
	 */
	public static function remove_capabilities() {
		$subscriber = get_role( 'subscriber' );

		if ( $subscriber instanceof WP_Role ) {
			$subscriber->remove_cap( 'gl_manage_library' );
			$subscriber->remove_cap( 'gl_issue_invites' );
		}

		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );

			if ( $role instanceof WP_Role ) {
				$role->remove_cap( 'gl_moderate_library' );
			}
		}

		// PB-9 (cycle-7): mirrors grant_capabilities()'s own bump — the set
		// of gl_manage_library holders changes here too (to nobody).
		// Object-cache only (Generations::bump() never writes a table,
		// option, row, or user meta), so this does not touch AC-001 (g).
		self::bump_member_directory_generation();
	}

	/**
	 * Bulk sync of `MEMBER_META_KEY` for every current `gl_manage_library`
	 * holder that does not already carry the marker (PB-2, cycle-7) — the
	 * backfill `Activator::install_or_upgrade()` needs so a fresh
	 * activation/upgrade does not leave `Member_Directory` reporting zero
	 * members for every subscriber who registered before this marker
	 * existed. The leading-wildcard `'capability' => 'gl_manage_library'`
	 * query this runs is exactly the shape `Member_Directory` moved off of
	 * for its own per-request reads — acceptable here because this runs
	 * once per activation/version bump, never per page render.
	 *
	 * MR-3 (cycle-8): the previous version of this method ran `get_users()`
	 * with no `number` argument — effectively every capability holder on
	 * the site, since `gl_manage_library` is granted to the whole
	 * Subscriber role — then looped a synchronous `update_user_meta()` per
	 * result, unconditionally inside `maybe_upgrade()`'s 5-minute upgrade
	 * lock on every activation/version bump. Now bounded to
	 * `BACKFILL_BATCH_SIZE` per call via `'number'` plus a `meta_query`
	 * `NOT EXISTS` clause (so already-marked users are cheap to skip on a
	 * re-run) — public, not private, so both this class's own
	 * `BACKFILL_CONTINUE_HOOK` callback and `Cli\Migrate_Command`'s
	 * synchronous drain can call it directly. When a full batch comes back,
	 * a single `wp_schedule_single_event()` queues the next batch rather
	 * than looping the whole set inside the caller's own request/lock.
	 *
	 * MR9-2 (cycle-9): the scheduling call's own return is now checked. A
	 * `false` return (the continuation was refused, not merely already
	 * queued) sets `BACKFILL_STALLED_OPTION` durably — see that constant's
	 * own docblock for why nothing else in this class's resume path retries
	 * on its own. A batch that finishes the whole set (fewer than
	 * `BACKFILL_BATCH_SIZE` processed) clears the flag, so a later
	 * successful run un-stalls itself with no manual step needed.
	 *
	 * @return void
	 */
	public static function backfill_member_markers() {
		$processed = self::run_backfill_batch();

		self::bump_member_directory_generation();

		if ( self::BACKFILL_BATCH_SIZE === $processed ) {
			if ( ! wp_next_scheduled( self::BACKFILL_CONTINUE_HOOK ) ) {
				$scheduled = wp_schedule_single_event( time(), self::BACKFILL_CONTINUE_HOOK );

				if ( false === $scheduled ) {
					// The continuation is the ONLY resume path -- install_or_upgrade()
					// records DB_VERSION_OPTION before this runs, so maybe_upgrade()
					// will not retry. Record the stall durably rather than leaving
					// Member_Directory silently short. Same "never silently skipped"
					// reasoning as Page_Cache::schedule_purge_for_user(); the recovery
					// here is `wp game-library migrate`, not an inline loop (MR-3).
					update_option( self::BACKFILL_STALLED_OPTION, 1, false );
				}
			}
		} elseif ( get_option( self::BACKFILL_STALLED_OPTION ) ) {
			delete_option( self::BACKFILL_STALLED_OPTION );
		}
	}

	/**
	 * Drains every remaining bounded `backfill_member_markers()` batch
	 * synchronously, in a loop, with no cron scheduling (MR-3, cycle-8) —
	 * `Cli\Migrate_Command`'s own call. WP-CLI runs with no execution-time
	 * limit, so the deploy step this command documents can safely finish
	 * the whole backfill in one synchronous pass rather than waiting on
	 * `BACKFILL_CONTINUE_HOOK`'s scheduled continuation.
	 *
	 * MR9-2 (cycle-9): this is the documented recovery step for a stalled
	 * backfill (`wp game-library migrate`) — clears `BACKFILL_STALLED_OPTION`
	 * once the loop below finishes the whole set, regardless of whether the
	 * stall flag was ever set.
	 *
	 * @return void
	 */
	public static function drain_member_marker_backfill() {
		do {
			$processed = self::run_backfill_batch();

			self::bump_member_directory_generation();
		} while ( self::BACKFILL_BATCH_SIZE === $processed );

		if ( get_option( self::BACKFILL_STALLED_OPTION ) ) {
			delete_option( self::BACKFILL_STALLED_OPTION );
		}
	}

	/**
	 * Runs one bounded batch of the `MEMBER_META_KEY` backfill — see
	 * `backfill_member_markers()`'s own docblock.
	 *
	 * @return int Number of users processed by this batch. Equal to
	 *             `BACKFILL_BATCH_SIZE` when a further batch may remain,
	 *             smaller when this was the last one.
	 */
	private static function run_backfill_batch() {
		$user_ids = get_users(
			array(
				'capability' => 'gl_manage_library',
				'fields'     => 'ID',
				'number'     => self::BACKFILL_BATCH_SIZE,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- NOT EXISTS on an indexed meta_key, scoped to a bounded 'number' batch; the point of this meta_query is to make a re-run cheap by skipping already-marked users, not a slow query.
					array(
						'key'     => self::MEMBER_META_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( (array) $user_ids as $user_id ) {
			update_user_meta( absint( $user_id ), self::MEMBER_META_KEY, self::MEMBER_META_VALUE );
		}

		return count( (array) $user_ids );
	}
}
