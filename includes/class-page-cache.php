<?php
/**
 * Purges VIP's edge cache for one URL.
 *
 * @package Game_Library
 */

namespace Game_Library;

use Game_Library\Data\Library_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Page_Cache.
 *
 * VIP-2: three of this plugin's routes serve HTTP 200 to logged-out
 * visitors (`/games/`, `/games/{slug}/`, and an opted-public member's
 * `/library/{nicename}/`) and are held at VIP's edge for up to 30 minutes.
 * Every write path that changes what one of those routes renders was
 * previously invalidating the object cache only (via the DD-004/ADR-004
 * generation counters) — correct for the PHP-rendered content on the next
 * *origin* request, but silently ineffective at the edge, which never
 * revalidates against origin until its own TTL expires. A member flipping
 * their profile back to private, a moderator correcting a cached game, or
 * the last library entry referencing a game being removed (AC-040's 404)
 * could all keep serving the previous, now-wrong page at the edge for up to
 * half an hour.
 *
 * `function_exists()`-guarded because the deployment target is portable
 * (this helper is a no-op off VIP, where nothing is held at an edge cache
 * this plugin can address). VIP exposes no full-site purge to plugin code —
 * every call site below purges only the specific URL(s) its own write
 * actually changed; anything this class is not explicitly told to purge
 * ages out on its own 30-minute edge TTL.
 *
 * CF-VIP-1 (cycle-5): before `purge_member_library()`/`purge_catalog_pages()`/
 * `purge_game_pages()` existed, every purge site on a paginated route
 * purged only that route's canonical/first-page URL — but `/games/`,
 * `/games/{slug}/`, and `/library/{nicename}/` are all reachable at
 * further anonymously-cacheable `?gl_page=N`/`?status=X` variants this
 * plugin itself links to and, for the two public routes, actively drives
 * crawlers toward. A member with more than one page of entries who flips
 * private, or a game whose public-holder pagination shifts, left every
 * variant beyond the first serving the previous, now-wrong (in the
 * member-library case, previously *private*) content at the edge for up to
 * 30 minutes — a privacy residual, not just a freshness one. These three
 * methods enumerate the page/status axes a route actually exposes, capped
 * by `MAX_PURGE_PAGES`, and are called everywhere a write already purged
 * that route's base URL.
 *
 * MR-1 (cycle-5): the deferred multi-slug purge path (`schedule_purge_for_user()`/
 * `schedule_purge_for_captured_slugs()`) is guarded by the same
 * `function_exists()` check FIRST, before anything is ever scheduled — on
 * this project's ruled `portable` deployment target (human ruling 1,
 * `interrupts/review:conflict-pending-resolution.md`) that makes the whole
 * deferred path a complete no-op, closing the cron-option-bloat defect at
 * the source rather than mitigating it. The two-tier scheduling shape below
 * (a tiny "resolve" event that fans out into small, chunked "purge" events)
 * is kept anyway so the same code is correct if this plugin is ever
 * deployed to a real VIP environment, where the guard passes and the
 * deferred path actually runs.
 *
 * Known limitation (VIP-5): `Settings_Page::sanitize_settings()`'s
 * `catalog_indexable` flip purges the catalog listing and the sitemap index
 * plus each subtype's own first page, not every already-edge-cached
 * `/games/{slug}/`/`/library/{nicename}/` page or every sitemap page beyond
 * the first — those age out on their own 30-minute TTL like any other
 * un-purged URL. The settings screen's own inline help text documents this
 * for an administrator flipping the switch.
 */
final class Page_Cache {

	/**
	 * Action hook the small, chunked purge events (`purge_game_slugs()`)
	 * fire — at most `PURGE_CHUNK_SIZE` slugs per event.
	 *
	 * @var string
	 */
	public const PURGE_GAME_SLUGS_HOOK = 'gl_purge_game_urls';

	/**
	 * Action hook the tiny "resolve, then fan out" event
	 * `schedule_purge_for_user()` queues — carries only a user id.
	 *
	 * @var string
	 */
	public const PURGE_FOR_USER_HOOK = 'gl_purge_game_urls_for_user';

	/**
	 * Action hook the tiny "resolve, then fan out" event
	 * `schedule_purge_for_captured_slugs()` queues — carries only an option
	 * key, never the slug list itself.
	 *
	 * @var string
	 */
	public const PURGE_CAPTURED_SLUGS_HOOK = 'gl_purge_captured_game_urls';

	/**
	 * `wp_options` row name prefix a captured slug list is stashed under
	 * between `schedule_purge_for_captured_slugs()` queuing the resolve
	 * event and that event reading (and deleting) it (MR-1) — never
	 * autoloaded (see that method's own `add_option()` call).
	 *
	 * @var string
	 */
	private const CAPTURED_SLUGS_OPTION_PREFIX = 'gl_purge_slugs_';

	/**
	 * Max distinct game slugs purged by one chunked `PURGE_GAME_SLUGS_HOOK`
	 * event (MR-1) — the whole reason a resolve/fan-out event is queued at
	 * a tiny size at all is so this class never again has to hold or act on
	 * a several-thousand-slug array in one PHP tick.
	 *
	 * @var int
	 */
	private const PURGE_CHUNK_SIZE = 100;

	/**
	 * Safety bound on how many paginated URL variants
	 * `purge_member_library()`/`purge_catalog_pages()`/`purge_game_pages()`
	 * (CF-VIP-1) will individually purge for one status/route in one call —
	 * never unbounded, matching the same defence-in-depth ceiling this
	 * plugin's read paths (`MR-1`/`MR-2`) already apply to pagination.
	 *
	 * @var int
	 */
	public const MAX_PURGE_PAGES = 25;

	/**
	 * Whether VIP's edge-purge helper is available on this deployment target
	 * (MR-5, cycle-7) — the single owner of the `function_exists()` check
	 * every purge site in this class and its callers gate on. `purge()`
	 * already guarded itself; the point of pulling this into a named,
	 * public method is so CALLERS can guard too, before doing the work of
	 * assembling a purge's arguments — see `Library_Repository::add_or_update()`/
	 * `remove()`, `Visibility::set_public()`, and `Erasure_Service::purge_edge_cache()`
	 * for the query-avoidance this makes possible. On the ruled portable
	 * (uncached edge) target this is always false.
	 *
	 * @return bool
	 */
	public static function is_edge_purge_available() {
		return function_exists( 'wpcom_vip_purge_edge_cache_for_url' );
	}

	/**
	 * Purges one URL from VIP's edge cache.
	 *
	 * @param string $url Absolute URL to purge.
	 * @return void
	 */
	public static function purge( $url ) {
		if ( self::is_edge_purge_available() ) {
			wpcom_vip_purge_edge_cache_for_url( $url );
		}
	}

	/**
	 * Registers the deferred-purge event callbacks. Called once from
	 * `Plugin`'s constructor (PB-2).
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( self::PURGE_GAME_SLUGS_HOOK, array( __CLASS__, 'purge_game_slugs' ) );
		add_action( self::PURGE_FOR_USER_HOOK, array( __CLASS__, 'resolve_and_purge_for_user' ) );
		add_action( self::PURGE_CAPTURED_SLUGS_HOOK, array( __CLASS__, 'resolve_and_purge_captured_slugs' ) );
	}

	/**
	 * Queues one small background event that, when it fires, re-derives
	 * `$user_id`'s current library game slugs and fans out their
	 * `/games/{slug}/` purges (MR-1) — used by `Visibility::set_public()`,
	 * whose write does not delete any `gl_library_entries` rows, so the
	 * slug list is still live in the database at the time the deferred
	 * event runs. The cron payload here is a single int, never the slug
	 * list itself.
	 *
	 * `function_exists()`-guarded first, matching `purge()` — on the ruled
	 * `portable` target this is a complete no-op, never scheduling
	 * anything.
	 *
	 * @param int $user_id Member whose library slugs should be purged.
	 * @return void
	 */
	public static function schedule_purge_for_user( $user_id ) {
		if ( ! function_exists( 'wpcom_vip_purge_edge_cache_for_url' ) ) {
			return;
		}

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return;
		}

		$scheduled = wp_schedule_single_event( time(), self::PURGE_FOR_USER_HOOK, array( $user_id ) );

		if ( false === $scheduled ) {
			// A privacy/visibility-affecting purge must never be silently
			// skipped — fall back to resolving and purging inline, in this
			// same request, rather than dropping it.
			self::resolve_and_purge_for_user( $user_id );
		}
	}

	/**
	 * `PURGE_FOR_USER_HOOK` callback — re-derives `$user_id`'s current
	 * library game slugs and fans them out to chunked purge events (MR-1).
	 *
	 * @param int $user_id Member whose library slugs should be purged.
	 * @return void
	 */
	public static function resolve_and_purge_for_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return;
		}

		$slugs = ( new Library_Repository() )->game_slugs_for_user( $user_id, self::PURGE_CHUNK_SIZE * self::MAX_PURGE_PAGES );

		self::fan_out_chunked_purge( $slugs );
	}

	/**
	 * Captures `$slugs` into one non-autoloaded `wp_options` row and queues
	 * one small background event carrying only that row's key (MR-1) — used
	 * by `Erasure_Service::purge_edge_cache()`, whose cascade has already
	 * deleted the member's `gl_library_entries` rows by the time a deferred
	 * event would run, so the slug list cannot be re-derived at fire time
	 * and must be captured now instead. The captured row is deleted the
	 * moment the resolve event reads it, whether or not scheduling below
	 * ultimately succeeds.
	 *
	 * `function_exists()`-guarded first, matching `purge()` — on the ruled
	 * `portable` target this never writes the capture row at all.
	 *
	 * @param string[] $slugs Distinct `/games/{slug}/` slugs to purge.
	 * @return void
	 */
	public static function schedule_purge_for_captured_slugs( array $slugs ) {
		if ( empty( $slugs ) || ! function_exists( 'wpcom_vip_purge_edge_cache_for_url' ) ) {
			return;
		}

		$key = self::CAPTURED_SLUGS_OPTION_PREFIX . uniqid( '', true );

		add_option( $key, $slugs, '', false );

		$scheduled = wp_schedule_single_event( time(), self::PURGE_CAPTURED_SLUGS_HOOK, array( $key ) );

		if ( false === $scheduled ) {
			// A privacy purge must never be silently skipped — fall back to
			// resolving (which also cleans up the capture row) and purging
			// inline, in this same request.
			self::resolve_and_purge_captured_slugs( $key );
		}
	}

	/**
	 * `PURGE_CAPTURED_SLUGS_HOOK` callback — reads and deletes the captured
	 * slug list at `$option_key` and fans it out to chunked purge events
	 * (MR-1).
	 *
	 * @param string $option_key The `add_option()` key `schedule_purge_for_captured_slugs()` wrote.
	 * @return void
	 */
	public static function resolve_and_purge_captured_slugs( $option_key ) {
		$option_key = (string) $option_key;

		if ( '' === $option_key || 0 !== strpos( $option_key, self::CAPTURED_SLUGS_OPTION_PREFIX ) ) {
			return;
		}

		$slugs = get_option( $option_key, array() );

		delete_option( $option_key );

		self::fan_out_chunked_purge( is_array( $slugs ) ? $slugs : array() );
	}

	/**
	 * Splits `$slugs` into `PURGE_CHUNK_SIZE`-sized chunks and queues one
	 * `PURGE_GAME_SLUGS_HOOK` event per chunk (MR-1) — never one event
	 * looping every slug in a single PHP tick, and never one event carrying
	 * more than `PURGE_CHUNK_SIZE` slugs in its own cron-option payload.
	 * Each chunk's event is staggered by `time() + $i` so that two distinct
	 * purges queued in the same request (or two requests landing in the
	 * same second) never collide on core's own 10-minute duplicate
	 * hook+timestamp+args refusal — without the stagger, two chunk-0 events
	 * scheduled in the same second would otherwise look identical to
	 * `wp_schedule_single_event()` and the second would be silently
	 * dropped.
	 *
	 * @param string[] $slugs Distinct `/games/{slug}/` slugs to purge.
	 * @return void
	 */
	private static function fan_out_chunked_purge( array $slugs ) {
		$slugs = array_values( array_unique( array_map( 'strval', $slugs ) ) );

		if ( empty( $slugs ) ) {
			return;
		}

		$chunks = array_chunk( $slugs, self::PURGE_CHUNK_SIZE );

		foreach ( $chunks as $i => $chunk ) {
			$scheduled = wp_schedule_single_event( time() + $i, self::PURGE_GAME_SLUGS_HOOK, array( $chunk ) );

			if ( false === $scheduled ) {
				// A privacy/visibility-affecting purge must never be
				// silently skipped — fall back to purging this chunk
				// inline, in this same request.
				self::purge_game_slugs( $chunk );
			}
		}
	}

	/**
	 * The `PURGE_GAME_SLUGS_HOOK` callback (PB-2) — purges every queued game
	 * page, off-request. At most `PURGE_CHUNK_SIZE` slugs per call (MR-1).
	 *
	 * @param string[] $slugs Distinct `/games/{slug}/` slugs to purge.
	 * @return void
	 */
	public static function purge_game_slugs( array $slugs ) {
		foreach ( $slugs as $slug ) {
			self::purge( Router::game_url( (string) $slug ) );
		}
	}

	/**
	 * Purges every anonymously-cacheable paginated variant of one opted-public
	 * member's `/library/{nicename}/` (CF-VIP-1) — the base URL, every
	 * `?gl_page=N` page of the unfiltered list, and every `?status=X` /
	 * `?status=X&gl_page=N` variant `member-library.php`'s own status filter
	 * exposes. Before this method existed, every purge site on this route
	 * purged only the single base URL, leaving `?gl_page=2`+ (a member with
	 * more than one page of entries) and every `?status=` variant serving a
	 * stale response at the edge for up to 30 minutes after a visibility
	 * flip, a status change, or an erasure — a privacy residual, not just a
	 * freshness one, on a route this plugin serves anonymously at HTTP 200.
	 *
	 * `$status_counts` is the same shape `Library_Repository::status_counts()`
	 * returns (already cached and generation-keyed, per that method's own
	 * docblock).
	 *
	 * VIP-2/CO-6/MR-5 (cycle-7, designed as one change — narrowing and
	 * widening the same enumeration cannot land independently):
	 *
	 * - `$scopes` narrows which status variants are purged. An ordinary
	 *   single-entry write (`Library_Repository::add_or_update()`/`remove()`)
	 *   passes only the affected status, the status the entry just left (if
	 *   any), and `''` (the unfiltered view) — VIP-2's fix for the up to
	 *   ~175-URL worst case an unconditional five-status enumeration produced
	 *   on every member write. The default, empty `$scopes` enumerates every
	 *   status unconditionally (CO-6) — kept for the two privacy-relevant
	 *   callers, `Visibility::set_public()` and
	 *   `Erasure_Service::purge_edge_cache()`, where every variant really is
	 *   wrong and `array_filter( $status_counts )` (the pre-cycle-7 shape)
	 *   silently dropped exactly the status a member just moved every
	 *   remaining entry OUT of.
	 * - The page ceiling overshoots `ceil( $total / $page_size )` by one
	 *   page (CO-6) — `$status_counts` is read AFTER `Generations::bump()`
	 *   runs, so a page that just fell below a page boundary (25 -> 24
	 *   entries) is already outside the post-write ceiling even though the
	 *   edge is still serving that now-out-of-range page's pre-write
	 *   content.
	 * - MR-5: guarded by `is_edge_purge_available()` the way `purge()` is —
	 *   every caller already hoists this same guard around computing
	 *   `$status_counts` itself, so this is defence in depth, not the
	 *   primary saving.
	 *
	 * @param string             $nicename   Member's `user_nicename`, or ''
	 *                                       to skip (matches
	 *                                       `Erasure_Service`'s own
	 *                                       no-nicename-resolved case).
	 * @param array<string,int>  $status_counts Status => count, e.g. the
	 *                                          return of `status_counts()`.
	 * @param int                $page_size  Entries per page
	 *                                       (`Library_Repository::DEFAULT_PER_PAGE`).
	 * @param string[]           $scopes     Status values to purge, ''
	 *                                       meaning the unfiltered view.
	 *                                       Empty (the default) enumerates
	 *                                       every status unconditionally.
	 * @param int                $max_pages  Ceiling on pages purged per
	 *                                       scope.
	 * @return void
	 */
	public static function purge_member_library( $nicename, array $status_counts, $page_size, array $scopes = array(), $max_pages = self::MAX_PURGE_PAGES ) {
		if ( ! self::is_edge_purge_available() ) {
			return;
		}

		$nicename = (string) $nicename;

		if ( '' === $nicename ) {
			return;
		}

		$page_size = max( 1, absint( $page_size ) );
		$max_pages = max( 1, absint( $max_pages ) );

		$scopes = empty( $scopes )
			? array_merge( array( '' ), Statuses::all() )
			: array_values( array_unique( $scopes ) );

		foreach ( $scopes as $status ) {
			$total       = '' === $status ? array_sum( $status_counts ) : ( $status_counts[ $status ] ?? 0 );
			$total_pages = min( $max_pages, max( 1, (int) ceil( $total / $page_size ) + 1 ) );

			for ( $page = 1; $page <= $total_pages; $page++ ) {
				self::purge( Router::member_library_page_url( $nicename, $status, $page ) );
			}
		}
	}

	/**
	 * Purges every anonymously-cacheable paginated variant of the public
	 * game catalog, `/games/` and `/games/page/{n}/` (CF-VIP-1) — the same
	 * page-enumeration shape as `purge_member_library()`, applied to
	 * `Router::catalog_url()`. `$total_referenced_games` is the same cached,
	 * generation-keyed count `Game_Repository::count_referenced_games()`
	 * returns; callers pass it straight through.
	 *
	 * @param int $total_referenced_games Total games with at least one
	 *                                    holder — the catalog's own total.
	 * @param int $page_size              Games per page (AC-039's 24).
	 * @return void
	 */
	public static function purge_catalog_pages( $total_referenced_games, $page_size ) {
		if ( ! self::is_edge_purge_available() ) {
			return;
		}

		$page_size   = max( 1, absint( $page_size ) );
		$total_pages = min( self::MAX_PURGE_PAGES, max( 1, (int) ceil( absint( $total_referenced_games ) / $page_size ) ) );

		for ( $page = 1; $page <= $total_pages; $page++ ) {
			self::purge( Router::catalog_url( $page ) );
		}
	}

	/**
	 * Purges every anonymously-cacheable paginated variant of one game's
	 * `/games/{slug}/` public-holder list (CF-VIP-1) — the same
	 * page-enumeration shape as `purge_member_library()`, applied to
	 * `Router::game_url()`'s `?gl_page=N` pagination.
	 *
	 * @param string $slug             Game slug.
	 * @param int    $total_holders    Total PUBLIC holders (PB-7) — the
	 *                                 total the template's own pagination is
	 *                                 built from, not `status_counts_for_game()`'s
	 *                                 all-holders total.
	 * @param int    $page_size        Holders per page.
	 * @return void
	 */
	public static function purge_game_pages( $slug, $total_holders, $page_size ) {
		if ( ! self::is_edge_purge_available() ) {
			return;
		}

		$slug        = (string) $slug;
		$page_size   = max( 1, absint( $page_size ) );
		$total_pages = min( self::MAX_PURGE_PAGES, max( 1, (int) ceil( absint( $total_holders ) / $page_size ) ) );

		for ( $page = 1; $page <= $total_pages; $page++ ) {
			self::purge( Router::game_page_url( $slug, $page ) );
		}
	}
}
