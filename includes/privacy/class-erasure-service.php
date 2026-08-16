<?php
/**
 * The single cascade-delete routine for a member's plugin data.
 *
 * @package Game_Library
 */

namespace Game_Library\Privacy;

use Game_Library\Data\Game_Repository;
use Game_Library\Data\Generations;
use Game_Library\Data\Invite_Repository;
use Game_Library\Data\Library_Repository;
use Game_Library\Page_Cache;
use Game_Library\Roles;
use Game_Library\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Erasure_Service.
 *
 * `erase()` is the only place a member's rows are deleted (AC-036) — both
 * `Privacy::erase_data()` (the Tools -> Erase Personal Data eraser callback)
 * and `Privacy::on_deleted_user()` (the `deleted_user` cascade) call this one
 * method, so the two paths can never diverge.
 *
 * The eight-part cascade:
 *
 * (a) their library entries
 * (b) follows where they are the follower
 * (c) follows where they are followed
 * (d) activity rows they authored
 * (e) activity rows where they are `object_user_id`
 * (f) invites they issued, including the stored recipient emails — the whole
 *     row is deleted, which removes the email with it
 * (g) the recipient email on the invite that created their own account —
 *     `email` is set to null but the row itself is kept, because it belongs
 *     to a different member's (the inviter's) audit trail, not this one
 * (h) every `_gl_*` user meta key
 *
 * (f) and (g) both route through `Invite_Repository::erase_for_user()`
 * (arch-pre-4 architecture review, finding AR-1) rather than writing directly
 * against `gl_invites` — the repository's own `invalidate_row()` clears the
 * id/code-keyed single-row cache entries those two writes would otherwise
 * leave stale for up to an hour, the same class of fix arch-pre-2's AR-1
 * already applied to the daily invite-maintenance cron's writes.
 *
 * It never deletes a `gl_games` row and never touches another member's
 * entries — every statement below is scoped by this member's own id.
 *
 * The `follower_id`/`following_id` lists on the two sides of the follow edge
 * are read before any delete runs, then used after the cascade to bump the
 * generation counters of every member whose own cached follow state just
 * changed, in addition to the global `activity_gen`/`invite_gen` counters —
 * so no follower keeps serving a cached feed, following list, or follower
 * count containing an erased member (per the task's own Constraints).
 *
 * These direct `$wpdb` reads and writes are deliberately not wrapped in
 * `wp_cache_get()`/`wp_cache_set()` — see `Privacy`'s class docblock and
 * principal/adr/010-privacy-reads-bypass-object-cache.md for why a GDPR
 * cascade specifically wants the current database state, not a cached one,
 * and gains nothing from caching a routine this rare.
 *
 * Custom tables outside `wp_posts`/`wp_postmeta` like the five this class
 * writes to are recommended to go through VIP's database review process for
 * backup/restore compatibility.
 */
final class Erasure_Service {

	/**
	 * Safety bound on how many follower/following ids a single lookup query
	 * returns before the cascade runs — matches `Follow_Repository`'s own
	 * `MAX_FOLLOWING` bound, never realistically reached at this plugin's
	 * sizing assumption (~1,000 total members, D10).
	 *
	 * @var int
	 */
	private const MAX_AFFECTED = 2000;

	/**
	 * Safety bound (PB-2, cycle-5) on how many follower/following ids
	 * `bump_generations()` bumps a per-member generation counter for,
	 * inline, in one call — deliberately far smaller than `MAX_AFFECTED`
	 * above. `bump_generations()`'s two loops are the only consumers of
	 * `$followers`/`$following`, and each iteration is its own cache
	 * round-trip inside a synchronous request (both the `deleted_user` hook
	 * and the Tools -> Erase Personal Data eraser run this cascade
	 * inline) — at `MAX_AFFECTED`'s 2,000 that is up to 4,000 round-trips
	 * for one erasure. Correctness is preserved beyond this cap the same
	 * way `Invite_Repository::erase_for_user()`'s own bounded
	 * invalidation-read already accepts for a matching-row count past its
	 * own limit (see that method's docblock): the (b)/(c) `DELETE`s below
	 * are unconditional and unbounded — every one of this member's follow
	 * rows is actually deleted regardless of this cap — only the
	 * cache-invalidation loop for the excess is skipped, leaving those
	 * followers'/followed members' own follow-list/follower-count caches
	 * to expire on `Follow_Repository`'s own TTL instead of invalidating
	 * immediately. 200 is well above what this plugin's D10 sizing
	 * (~1,000 total members) makes a realistic single-member follower/
	 * following count.
	 *
	 * @var int
	 */
	private const MAX_INLINE_FOLLOW_BUMPS = 200;

	/**
	 * Invite storage — the only piece of the cascade routed through a
	 * repository rather than a direct `$wpdb` write (see the class docblock).
	 *
	 * @var Invite_Repository
	 */
	private $invites;

	/**
	 * Used only to read (never write) the distinct `/games/{slug}/` game
	 * slugs an erased member's library referenced, before the cascade
	 * deletes those entries — VIP-1's edge-cache purge list.
	 *
	 * @var Library_Repository
	 */
	private $library;

	/**
	 * Constructor.
	 *
	 * @param Invite_Repository|null  $invites Invite repository. Defaults to a
	 *                                         new instance.
	 * @param Library_Repository|null $library Library repository. Defaults to
	 *                                         a new instance.
	 */
	public function __construct( ?Invite_Repository $invites = null, ?Library_Repository $library = null ) {
		$this->invites = $invites ?: new Invite_Repository();
		$this->library = $library ?: new Library_Repository();
	}

	/**
	 * Runs the full cascade for one member.
	 *
	 * @param int         $user_id  The member being erased.
	 * @param string|null $nicename The member's `user_nicename`, when the
	 *                               caller already holds it (VIP-1: core's
	 *                               `deleted_user` hook passes the `WP_User`
	 *                               object it fires with, since the account
	 *                               row is already gone by the time this
	 *                               runs). Null (the eraser-path default)
	 *                               falls back to `get_userdata( $user_id )`,
	 *                               which only works while the account still
	 *                               exists.
	 * @return bool True when at least one row or meta key was actually
	 *              removed/modified, false when the member had nothing to
	 *              erase (or `$user_id` was invalid).
	 */
	public function erase( $user_id, $nicename = null ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		global $wpdb;

		$follows_table  = Schema::follows_table();
		$library_table  = Schema::library_entries_table();
		$activity_table = Schema::activity_table();

		// Read the two sides of this member's follow edges before deleting
		// them — needed afterward to know whose generation counters to
		// bump. Limited to MAX_INLINE_FOLLOW_BUMPS (PB-2), not MAX_AFFECTED
		// — these two arrays have no other consumer, so there is no reason
		// to fetch more rows than bump_generations() will actually use; see
		// that constant's own docblock.
		$followers = $wpdb->get_col( $wpdb->prepare( "SELECT follower_id FROM {$follows_table} WHERE following_id = %d LIMIT %d", $user_id, self::MAX_INLINE_FOLLOW_BUMPS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $follows_table is Schema::follows_table(), never user input; deliberately uncached, see class docblock.
		$following = $wpdb->get_col( $wpdb->prepare( "SELECT following_id FROM {$follows_table} WHERE follower_id = %d LIMIT %d", $user_id, self::MAX_INLINE_FOLLOW_BUMPS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $follows_table is Schema::follows_table(), never user input; deliberately uncached, see class docblock.

		// VIP-1: same "read before delete" pattern, for the edge-cache purge
		// list — an erased opted-public member's own /library/{nicename}/ and
		// their name/avatar on every /games/{slug}/ their library referenced
		// would otherwise keep serving from VIP's edge for up to 30 minutes
		// with no account left to re-trigger invalidation. $nicename is only
		// re-read from get_userdata() when the caller did not already supply
		// it — on the deleted_user path the account no longer exists by the
		// time this runs, so that read would always return false.
		if ( null === $nicename ) {
			$user     = get_userdata( $user_id );
			$nicename = $user ? $user->user_nicename : '';
		}

		// CF-VIP-1: status_counts() must be read here, BEFORE the (a) delete
		// below removes every row it would otherwise count — purge_edge_cache()
		// runs at the very end of this method, by which point the member's
		// own library rows no longer exist to query. MR-5 (cycle-7): guarded
		// FIRST — this plugin's ruled portable target never has an edge to
		// purge (CONF-2(i)), so both reads (a GROUP BY plus a slug scan) ran
		// for nothing on every erasure before this guard.
		$status_counts = array();
		$game_slugs    = array();

		if ( Page_Cache::is_edge_purge_available() ) {
			$status_counts = $this->library->status_counts( $user_id );
			$game_slugs    = $this->library->game_slugs_for_user( $user_id, self::MAX_AFFECTED );
		}

		// (a) library entries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $library_table is Schema::library_entries_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; deliberately uncached, see class docblock.
		$removed = $this->ran( $wpdb->delete( $library_table, array( 'user_id' => $user_id ), array( '%d' ) ) );

		// (b) follows where they are the follower.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $follows_table is Schema::follows_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; deliberately uncached, see class docblock.
		$removed = $this->ran( $wpdb->delete( $follows_table, array( 'follower_id' => $user_id ), array( '%d' ) ) ) || $removed;

		// (c) follows where they are followed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $follows_table is Schema::follows_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; deliberately uncached, see class docblock.
		$removed = $this->ran( $wpdb->delete( $follows_table, array( 'following_id' => $user_id ), array( '%d' ) ) ) || $removed;

		// (d) activity rows they authored.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $activity_table is Schema::activity_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; deliberately uncached, see class docblock.
		$removed = $this->ran( $wpdb->delete( $activity_table, array( 'user_id' => $user_id ), array( '%d' ) ) ) || $removed;

		// (e) activity rows referencing them as the followed member.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $activity_table is Schema::activity_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; deliberately uncached, see class docblock.
		$removed = $this->ran( $wpdb->delete( $activity_table, array( 'object_user_id' => $user_id ), array( '%d' ) ) ) || $removed;

		// (f) invites they issued, and (g) the recipient email on the invite
		// that created their own account — both routed through
		// Invite_Repository::erase_for_user() (arch-pre-4 finding AR-1) so the
		// id/code-keyed row caches are invalidated via invalidate_row(), not
		// left stale by a direct write here.
		$removed = $this->invites->erase_for_user( $user_id, self::MAX_AFFECTED ) || $removed;

		// (h) every _gl_* user meta key.
		$removed = $this->ran( $this->delete_gl_user_meta( $user_id ) ) || $removed;

		// MR-1 (cycle-8): re-assert MEMBER_META_KEY when the account still
		// exists. delete_gl_user_meta() above is a blanket sweep of every
		// `_gl_*` key by design (h) — MEMBER_META_KEY happens to live in
		// that namespace even though it is a DERIVED mirror of the real
		// gl_manage_library capability, not personal data of its own. On
		// the Tools -> Erase Personal Data eraser path the account (and the
		// capability) survives this cascade, so leaving the marker deleted
		// would silently drop a still-active member from /members/, GET
		// /members, and the members sitemap subtype with no re-sync path.
		// The get_userdata() guard is NOT optional: it is what makes this a
		// no-op on the deleted_user path, where the account no longer
		// exists by the time this runs (see this method's own docblock) —
		// writing the marker back for a nonexistent account would break
		// AC-036(h)'s own erasure verification, which deletes the account
		// via wp_delete_user().
		if ( get_userdata( $user_id ) ) {
			Roles::sync_member_marker( $user_id );
		}

		$this->bump_generations( $user_id, (array) $followers, (array) $following );

		$this->purge_edge_cache( $nicename, $status_counts, $game_slugs );

		return $removed;
	}

	/**
	 * Purges VIP's edge cache (VIP-1) for every anonymously-cached HTTP 200
	 * response this erasure could leave stale: every paginated/status
	 * variant of the member's own `/library/{nicename}/` (CF-VIP-1; skipped
	 * when `$nicename` is `''`), every `/games/{slug}/` page their library
	 * referenced (their name/avatar no longer appears there once erased),
	 * and the public catalog listing.
	 *
	 * CO-9 (cycle-3, docblock correction): `$nicename` is `''` when no
	 * nicename could be resolved for this member (a hard-deleted account
	 * with no `WP_User` object supplied — see `erase()`'s own docblock),
	 * never a signal that the member "was never public." Visibility is
	 * deliberately not consulted here — purging a library URL that was never
	 * public is harmless (`Page_Cache::purge()` on an unindexed/uncached URL
	 * is a no-op at the edge), so this purge is unconditional for any
	 * resolvable account regardless of opt-in state.
	 *
	 * @param string             $nicename      Erased member's
	 *                                          `user_nicename`, or '' when
	 *                                          none could be resolved.
	 * @param array<string,int>  $status_counts The member's own per-status
	 *                                          entry counts, read by the
	 *                                          caller BEFORE the (a) delete
	 *                                          removed the rows they count
	 *                                          (CF-VIP-1) — this method runs
	 *                                          after that delete and could
	 *                                          not re-read them itself.
	 * @param string[]           $game_slugs    Distinct game slugs the
	 *                                          member's library referenced,
	 *                                          capped at `MAX_AFFECTED`.
	 * @return void
	 */
	private function purge_edge_cache( $nicename, array $status_counts, array $game_slugs ) {
		// MR-5 (cycle-7): guarded FIRST, matching purge()'s own guard — the
		// caller (erase()) already skips computing $status_counts/$game_slugs
		// when there is no edge to purge, so this is defence in depth.
		if ( ! Page_Cache::is_edge_purge_available() ) {
			return;
		}

		if ( '' !== $nicename ) {
			// CF-VIP-1: every paginated/status variant, not just the base
			// URL — an erased member's library page can hold more than one
			// page of entries just like any other member's. Every status is
			// enumerated unconditionally (the default empty $scopes), not
			// narrowed to one entry's affected status, because an erasure
			// removes the WHOLE library, not one status's worth of it.
			Page_Cache::purge_member_library( $nicename, $status_counts, Library_Repository::DEFAULT_PER_PAGE );
		}

		// PB-2/MR-1 (cycle-5): the per-slug purge is queued via
		// schedule_purge_for_captured_slugs(), not schedule_purge_for_slugs()
		// — by the time any deferred event this call queues actually fires,
		// erase()'s own cascade (above, in the caller) has already deleted
		// this member's gl_library_entries rows, so game_slugs_for_user()
		// could no longer re-derive the list even if a re-derive path were
		// used here. $game_slugs was read BEFORE that cascade ran (see
		// erase()'s own docblock) and is captured into a single
		// non-autoloaded option row rather than the event's own args, which
		// is what keeps this (also possibly cron-triggered, via
		// deleted_user) call from writing an up-to-MAX_AFFECTED-slug blob
		// into the cron option itself.
		Page_Cache::schedule_purge_for_captured_slugs( $game_slugs );

		if ( ! empty( $game_slugs ) ) {
			// CF-VIP-1: every catalog page, not just the first — an erased
			// member's library referencing games can shift which page a
			// given game now falls on just like any other membership
			// change.
			Page_Cache::purge_catalog_pages( ( new Game_Repository() )->count_referenced_games(), Library_Repository::DEFAULT_PER_PAGE ); // AC-039's page size matches AC-017's (Library_Repository::DEFAULT_PER_PAGE, CO-10).
		}
	}

	/**
	 * Deletes every `_gl_*` user meta row for one member directly against
	 * `wp_usermeta` — `delete_user_meta()` requires a known key, and this
	 * cascade must remove every key this plugin has ever written without
	 * hardcoding an exhaustive, easily-stale list of them.
	 *
	 * Because this bypasses `delete_user_meta()`, it must also clear core's
	 * own `user_meta` object-cache group itself (`delete_metadata()` does the
	 * same after its own delete) — otherwise `get_user_meta()` keeps serving
	 * the pre-erasure meta from a persistent object cache with no expiry,
	 * which is exactly what `Visibility::is_public()` reads to gate
	 * `/library/{nicename}/` (arch-pre-6 finding AR-1).
	 *
	 * @param int $user_id The member being erased.
	 * @return int|false Number of rows deleted, or false on a database error.
	 */
	private function delete_gl_user_meta( $user_id ) {
		global $wpdb;

		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s", $user_id, $wpdb->esc_like( '_gl_' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->usermeta is a core wpdb property, never user input; deliberately uncached, see class docblock.

		wp_cache_delete( $user_id, 'user_meta' );

		return $deleted;
	}

	/**
	 * Whether a `$wpdb` write result indicates at least one row was affected.
	 *
	 * @param int|false $result Return value of a `$wpdb` insert/update/delete/query call.
	 * @return bool
	 */
	private function ran( $result ) {
		return false !== $result && $result > 0;
	}

	/**
	 * Bumps every generation counter the cascade needs to invalidate — the
	 * erased member's own library/follow/follower scopes, the global
	 * `activity_gen`/`catalog_gen`/`invite_gen` counters, and the
	 * `follow_gen`/`follower_gen` scopes of every member on the other side
	 * of a deleted follow edge (per the class docblock).
	 *
	 * `catalog_gen` (PB-5, cycle-5): unconditional here, unlike
	 * `Library_Repository`'s own precise reference-count-transition bump —
	 * this cascade's (a) delete is a single bulk `$wpdb->delete()` across
	 * every one of the member's entries with no per-game before/after
	 * reference-count check, so there is no cheap way to tell which of
	 * their games (if any) just dropped to zero holders. An erasure is a
	 * rare, low-volume, account-level action (per the class docblock, which
	 * already accepts the same trade-off for `activity_gen`/`invite_gen`
	 * here), so bumping unconditionally — over-invalidating rather than
	 * under-invalidating — is the correct, cheap choice.
	 *
	 * @param int   $user_id   The erased member.
	 * @param int[] $followers Ids of members who followed the erased member.
	 * @param int[] $following Ids of members the erased member followed.
	 * @return void
	 */
	private function bump_generations( $user_id, array $followers, array $following ) {
		Generations::bump( Generations::activity_key() );
		Generations::bump( Generations::catalog_key() );
		Generations::bump( Generations::invite_key() );
		Generations::bump( Generations::library_key( $user_id ) );
		Generations::bump( Generations::follow_key( $user_id ) );
		Generations::bump( Generations::follower_key( $user_id ) );
		// PB-3: delete_gl_user_meta() below removes _gl_profile_public, which
		// Member_Directory::public_member_query_args() filters on — members_gen
		// is the fifth mutator of that data (see Generations::members_key()'s
		// own docblock for the other four) and was missing here, so an eraser
		// run (the account survives that path, unlike deleted_user) kept
		// serving the member's old public-directory membership from the
		// sitemap and public_member_count() for up to an hour.
		Generations::bump( Generations::members_key() );

		foreach ( $followers as $follower_id ) {
			Generations::bump( Generations::follow_key( absint( $follower_id ) ) );
		}

		foreach ( $following as $following_id ) {
			Generations::bump( Generations::follower_key( absint( $following_id ) ) );
		}
	}
}
