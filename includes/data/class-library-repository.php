<?php
/**
 * The only read/write path to `gl_library_entries`.
 *
 * @package Game_Library
 */

namespace Game_Library\Data;

use Game_Library\Page_Cache;
use Game_Library\Schema;
use Game_Library\Statuses;
use Game_Library\Visibility;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Library_Repository.
 *
 * Cached CRUD over the `gl_library_entries` table (DD-001/ADR-001) — one row
 * per member per game (D35). Every direct `$wpdb` read is wrapped in
 * `wp_cache_get()`/`wp_cache_set()` in the `game_library` object-cache group
 * at a 3600-second TTL; freshness comes from two generation counters folded
 * into every cache key rather than a short TTL (DD-004/ADR-004):
 *
 * - `lib_gen_{user_id}` — bumped on any write for that member's own library
 *   (add, status change, remove). Scopes `get_page()`, `status_counts()`,
 *   `counts_for_users()`, and the internal per-entry lookup.
 * - `activity_gen` — the same global counter `Activity_Repository` (Task 12)
 *   bumps on every feed insert/delete, also bumped here on every library
 *   add/remove (per the Transients and object cache table in spec section
 *   6) because a game's reference count, per-status holder counts, and
 *   holder list all change exactly when some member's library entries do.
 *   Scopes `status_counts_for_game()` and (jointly with `members_gen`,
 *   PB-7) `public_holders_for_game()`/`count_public_holders_for_game()`,
 *   and is read (never bumped) by `Game_Repository::reference_count()`.
 *
 * This class also owns two narrow, directly-coupled writes to `gl_activity`
 * that AC-010/AC-011/AC-012 tie to a library-entry write: `add_or_update()`
 * inserts the `game_added`/`status_changed` row for its own write, and
 * `remove()` cascade-deletes the member's own activity rows for the removed
 * game (DD-011). `Activity_Repository` (Task 12) owns every other read and
 * write against `gl_activity` — the feed query, its own generation-counter
 * bump on insert, and the `member_followed` event.
 *
 * The generation-counter key builders and the read/bump pair itself live in
 * `Generations` (arch-pre-1 architecture review, finding AR-2) — every
 * repository that shares a scope with another (the global `activity_gen`
 * scope here, `Follow_Repository`, `Activity_Repository`, `Invite_Repository`,
 * `Erasure_Service`) calls the same static methods rather than each keeping
 * its own copy of the key format and the "seed the cold bump to 2, not 1"
 * rule.
 *
 * Custom tables outside `wp_posts`/`wp_postmeta` like this one are
 * recommended to go through VIP's database review process for
 * backup/restore compatibility.
 */
final class Library_Repository {

	/**
	 * Object-cache group for every cache entry this repository reads/writes.
	 *
	 * Every `wp_cache_set()` call below uses the `HOUR_IN_SECONDS` WordPress
	 * time constant (never below the 900s floor) as its TTL — a literal WP
	 * time constant, not a class constant, because
	 * `WordPressVIPMinimum.Performance.LowExpiryCacheTime` can only
	 * statically evaluate a literal number, arithmetic on literals, or one
	 * of the named WP time constants; a `self::` class-constant reference is
	 * an unresolvable token to that sniff regardless of its actual value.
	 * Freshness comes from the generation counters above, not a short TTL.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'game_library';

	/**
	 * Default page size (AC-017). Public (CO-10, cycle-7) — this class is
	 * the de-facto owner of AC-017's page-size figure, and `Erasure_Service`/
	 * `Visibility` used to carry their own literal `24` (with a
	 * `// AC-017's page size.` comment) at the three `Page_Cache::purge_member_library()`
	 * call sites outside this class, which would silently desynchronise
	 * from the rendered pagination if this figure ever changed.
	 *
	 * @var int
	 */
	public const DEFAULT_PER_PAGE = 24;

	/**
	 * A TTL of `HOUR_IN_SECONDS` +/- 10% (PB-4) for the reads on this
	 * class's shared `activity_gen` scope — `status_counts_for_game()`,
	 * `public_holders_for_game()`, and `count_public_holders_for_game()`
	 * (PB-7) are read on every /games/{slug}/ view and invalidated together
	 * by any member's library write, so without jitter every key created in
	 * the same burst also expires in the same instant, and a popular game's
	 * page can synchronise every visitor's cache-miss onto the same moment.
	 * This only staggers the natural expiry; freshness still comes from the
	 * DD-004/ADR-004 generation counter(s), never from the TTL itself, and
	 * the floor stays well above the 900s Boundary.
	 *
	 * @return int
	 */
	private static function jittered_ttl() {
		return HOUR_IN_SECONDS + wp_rand( -360, 360 );
	}

	/**
	 * Used only by `remove()` to check whether a removed game has any
	 * holder left, so a game that drops to zero references can have its
	 * now-404-should-serve `/games/{slug}/` page purged from VIP's edge
	 * (VIP-2) — see that method's own docblock.
	 *
	 * @var Game_Repository
	 */
	private $games;

	/**
	 * Constructor.
	 *
	 * @param Game_Repository|null $games Game repository. Defaults to a new
	 *                                    instance.
	 */
	public function __construct( ?Game_Repository $games = null ) {
		$this->games = $games ?: new Game_Repository();
	}

	/**
	 * Fetches one page of a member's library, optionally filtered to one
	 * status, newest-added first.
	 *
	 * @param int    $user_id  Owning member.
	 * @param string $status   One of Statuses::all(), or '' for no filter.
	 *                         Any other non-empty value returns an empty
	 *                         result rather than silently ignoring the
	 *                         filter.
	 * @param int    $page     1-based page number.
	 * @param int    $per_page Page size; defaults to the 24-per-page floor
	 *                         AC-017 requires.
	 * @return array<int,array<string,mixed>> Hydrated entry rows.
	 */
	public function get_page( $user_id, $status, $page, $per_page = self::DEFAULT_PER_PAGE ) {
		$user_id  = absint( $user_id );
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, absint( $per_page ) );

		if ( '' !== $status && ! Statuses::is_valid( $status ) ) {
			return array();
		}

		// MR-2/PB-3: defence-in-depth ceiling, matching Game_Repository::
		// get_referenced_games()'s own guard — status_counts() is already
		// cached, so this costs nothing extra on the common (in-range) case.
		// The status_counts() read itself is moved inside the
		// $ceiling_offset > 0 branch (PB-3) — every sibling ceiling added in
		// the same fix cycle (Member_Directory::member_ids()/
		// public_member_nicenames(), Game_Repository::referenced_game_slugs())
		// short-circuits on page 1 before paying for the count read at all;
		// this one previously read it unconditionally first. get_page()
		// backs the four hottest read paths, and under human ruling 1
		// (core's stock, non-persistent cache) there is no warm cache
		// between requests, so page 1 — the overwhelmingly common case —
		// was paying a real GROUP BY query it never needed.
		$ceiling_offset = ( $page - 1 ) * $per_page;

		if ( $ceiling_offset > 0 ) {
			$ceiling_counts   = $this->status_counts( $user_id );
			$total_for_status = '' !== $status ? $ceiling_counts[ $status ] : array_sum( $ceiling_counts );

			if ( $ceiling_offset >= $total_for_status ) {
				return array();
			}
		}

		$gen       = Generations::read( Generations::library_key( $user_id ) );
		// $per_page is folded into the key (CO-1): GET /library/{id} accepts
		// a caller-controlled per_page, and the three callers of this method
		// (/my-library/, /library/{nicename}/, Schema_Org's CollectionPage)
		// all read with the same default today — but without $per_page in
		// the key, one REST call with per_page=1 would write a one-item
		// array under the identical key those 24-per-page reads use.
		$cache_key = sprintf( 'entries_%d_%s_%d_%d_%d', $user_id, '' === $status ? 'all' : $status, $page, $per_page, $gen );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table  = Schema::library_entries_table();
		$offset = ( $page - 1 ) * $per_page;

		if ( '' !== $status ) {
			$sql = $wpdb->prepare(
				"SELECT id, user_id, igdb_id, status, date_added, date_modified FROM {$table} WHERE user_id = %d AND status = %s ORDER BY date_added DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::library_entries_table(), never user input.
				$user_id,
				$status,
				$per_page,
				$offset
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT id, user_id, igdb_id, status, date_added, date_modified FROM {$table} WHERE user_id = %d ORDER BY date_added DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::library_entries_table(), never user input.
				$user_id,
				$per_page,
				$offset
			);
		}

		$rows  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $sql was built by $wpdb->prepare() in the branch above; $table is Schema::library_entries_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
		$items = array_map( array( $this, 'hydrate_entry' ), (array) $rows );

		wp_cache_set( $cache_key, $items, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $items;
	}

	/**
	 * The four per-status entry counts for one member's own library.
	 *
	 * @param int $user_id Owning member.
	 * @return array<string,int> Status => count, all four Statuses::all()
	 *                           keys present, 0 where the member has none.
	 */
	public function status_counts( $user_id ) {
		$user_id = absint( $user_id );

		$gen       = Generations::read( Generations::library_key( $user_id ) );
		$cache_key = sprintf( 'counts_%d_%d', $user_id, $gen );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table = Schema::library_entries_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::library_entries_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE user_id = %d GROUP BY status", $user_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::library_entries_table(), never user input.
			ARRAY_A
		);

		$counts = array_fill_keys( Statuses::all(), 0 );

		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['total'];
			}
		}

		wp_cache_set( $cache_key, $counts, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $counts;
	}

	/**
	 * The total library-entry count for a whole page of members in one
	 * grouped query — never one count query per member (used by the member
	 * directory in Task 14).
	 *
	 * @param int[] $user_ids Member ids for the page.
	 * @return array<int,int> user_id => entry count for every requested id,
	 *                        0 where a member has no entries.
	 */
	public function counts_for_users( array $user_ids ) {
		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );

		if ( empty( $user_ids ) ) {
			return array();
		}

		$results = array();
		$missing = array();

		foreach ( $user_ids as $user_id ) {
			$gen       = Generations::read( Generations::library_key( $user_id ) );
			$cache_key = sprintf( 'entry_count_%d_%d', $user_id, $gen );
			$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

			if ( $found ) {
				$results[ $user_id ] = (int) $cached;
			} else {
				$missing[ $user_id ] = $cache_key;
			}
		}

		if ( ! empty( $missing ) ) {
			global $wpdb;
			$table        = Schema::library_entries_table();
			$ids          = array_keys( $missing );
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::library_entries_table() and $placeholders is a fixed run of %d tokens built by array_fill(), neither is user data; every value is bound through prepare() below; cached via wp_cache_set() below.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, COUNT(*) AS total FROM {$table} WHERE user_id IN ({$placeholders}) GROUP BY user_id", $ids ), ARRAY_A );

			$found_counts = array();

			foreach ( (array) $rows as $row ) {
				$found_counts[ (int) $row['user_id'] ] = (int) $row['total'];
			}

			foreach ( $missing as $user_id => $cache_key ) {
				$count                = isset( $found_counts[ $user_id ] ) ? $found_counts[ $user_id ] : 0;
				$results[ $user_id ]  = $count;

				wp_cache_set( $cache_key, $count, self::CACHE_GROUP, HOUR_IN_SECONDS );
			}
		}

		return $results;
	}

	/**
	 * Whether a member already holds a game.
	 *
	 * @param int $user_id Member.
	 * @param int $igdb_id Game.
	 * @return bool
	 */
	public function has_game( $user_id, $igdb_id ) {
		return null !== $this->get_entry( absint( $user_id ), absint( $igdb_id ) );
	}

	/**
	 * The subset of `$igdb_ids` a member already holds, in one query
	 * (PB-2) — `Search_Controller::search()` calls this once with every
	 * result id on the page instead of `has_game()` in a loop, which issued
	 * up to one `SELECT` per result on a cache miss (the dominant case: the
	 * negative-result cache `get_entry()` also backs is keyed on
	 * `lib_gen_{user_id}`, and every library write bumps it, so the common
	 * search -> add -> search-again flow busts it between the two
	 * searches).
	 *
	 * @param int   $user_id  Member.
	 * @param int[] $igdb_ids Candidate IGDB ids (e.g. one search response's
	 *                        result ids).
	 * @return int[] The subset of `$igdb_ids` this member already holds.
	 */
	public function held_igdb_ids( $user_id, array $igdb_ids ) {
		$user_id  = absint( $user_id );
		$igdb_ids = array_values( array_unique( array_filter( array_map( 'absint', $igdb_ids ) ) ) );

		if ( ! $user_id || empty( $igdb_ids ) ) {
			return array();
		}

		sort( $igdb_ids );

		$gen       = Generations::read( Generations::library_key( $user_id ) );
		$cache_key = sprintf( 'held_%d_%s_%d', $user_id, md5( implode( ',', $igdb_ids ) ), $gen );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table        = Schema::library_entries_table();
		$placeholders = implode( ', ', array_fill( 0, count( $igdb_ids ), '%d' ) );
		$args         = array_merge( array( $user_id ), $igdb_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::library_entries_table() and $placeholders is a fixed run of %d tokens built by array_fill(), neither is user data; every value is bound through prepare() below; cached via wp_cache_set() below.
		$held_ids = $wpdb->get_col( $wpdb->prepare( "SELECT igdb_id FROM {$table} WHERE user_id = %d AND igdb_id IN ({$placeholders})", $args ) );

		$held = array_map( 'absint', (array) $held_ids );

		wp_cache_set( $cache_key, $held, self::CACHE_GROUP, self::jittered_ttl() ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- jittered_ttl() is HOUR_IN_SECONDS +/- a bounded 360s jitter, always >= 3240s; the sniff cannot statically evaluate the method call's runtime result.

		return $held;
	}

	/**
	 * The distinct `/games/{slug}/` game slugs a member's library currently
	 * references, capped at `$limit` (VIP-1) — used by `Erasure_Service` and
	 * `Visibility::set_public()` to build the list of VIP edge-cache URLs an
	 * account-level change (erasure, a visibility flip) needs to purge.
	 *
	 * Deliberately not cached and not generation-scoped: both callers need
	 * this exact instant's database state (an erasure/visibility change is
	 * about to invalidate the very generation this read would otherwise key
	 * off), and both are rare, low-volume, account-level actions, not a
	 * render-path read.
	 *
	 * @param int $user_id Member.
	 * @param int $limit   Maximum distinct slugs to return.
	 * @return string[] Game slugs.
	 */
	public function game_slugs_for_user( $user_id, $limit ) {
		$user_id = absint( $user_id );
		$limit   = max( 1, absint( $limit ) );

		if ( ! $user_id ) {
			return array();
		}

		global $wpdb;
		$entries_table = Schema::library_entries_table();
		$games_table   = Schema::games_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $entries_table/$games_table are Schema constants, never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; deliberately uncached, see method docblock.
		$slugs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT g.slug FROM {$games_table} g INNER JOIN {$entries_table} e ON e.igdb_id = g.igdb_id WHERE e.user_id = %d LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $games_table/$entries_table are Schema constants, never user input.
				$user_id,
				$limit
			)
		);

		return array_map( 'strval', (array) $slugs );
	}

	/**
	 * The narrow `$scopes` list `Page_Cache::purge_member_library()` needs
	 * for an ordinary single-entry write (VIP-2, cycle-7) — the unfiltered
	 * view plus the status the entry just left (if any) plus the status it
	 * now holds (if any), never the full status enumeration a genuinely
	 * public-facing account change (`Visibility::set_public()`,
	 * `Erasure_Service`) still needs.
	 *
	 * @param string|null $previous_status Status the entry held before this
	 *                                     write, or null when there was no
	 *                                     prior entry/it was already deleted.
	 * @param string|null $new_status      Status the entry holds after this
	 *                                     write, or null for a removal.
	 * @return string[]
	 */
	private function member_library_purge_scopes( $previous_status, $new_status = null ) {
		$scopes = array( '' );

		if ( null !== $previous_status ) {
			$scopes[] = $previous_status;
		}

		if ( null !== $new_status ) {
			$scopes[] = $new_status;
		}

		return array_values( array_unique( $scopes ) );
	}

	/**
	 * Creates a library entry, or updates an existing one's status, guarded
	 * by the `user_game` unique key so a repeated add always resolves to
	 * exactly one row (AC-010). Records the matching `gl_activity` row —
	 * `game_added` on insert, `status_changed` (carrying both the previous
	 * and new status) when an update actually changes the status (AC-011) —
	 * then bumps this member's library generation and the global activity
	 * generation so the next feed and library reads are fresh.
	 *
	 * @param int    $user_id Owning member.
	 * @param int    $igdb_id Game to add or update.
	 * @param string $status  One of Statuses::all().
	 * @return array<string,mixed>|WP_Error The saved entry on success,
	 *                                       WP_Error on invalid input or a
	 *                                       database failure.
	 */
	public function add_or_update( $user_id, $igdb_id, $status ) {
		$user_id = absint( $user_id );
		$igdb_id = absint( $igdb_id );

		if ( ! $user_id || ! $igdb_id ) {
			return new WP_Error( 'gl_invalid_library_entry', __( 'A library entry requires a member and a game.', 'game-library' ) );
		}

		if ( ! Statuses::is_valid( $status ) ) {
			return new WP_Error( 'gl_invalid_status', __( 'That status is not recognised.', 'game-library' ) );
		}

		$previous         = $this->get_entry( $user_id, $igdb_id );
		$previous_status  = $previous ? $previous['status'] : null;

		// CO-4: a same-status re-save is a genuine no-op — skip the write
		// (and everything downstream of it) entirely rather than let the
		// ON DUPLICATE KEY UPDATE clause still write date_modified. Before
		// this guard, a no-op PUT /library/{igdb_id} (reachable from the
		// plugin's own status selector, which re-POSTs the currently
		// selected status) advanced date_modified in the database while
		// the MR-1 generation-bump guard below correctly declined to bump
		// the cache key — so GET /library/{user_id} kept serving the old
		// date_modified for up to an hour, a value the database no longer
		// held. Every branch after this point (activity recording,
		// generation bumps, both edge-cache purges) was already gated on
		// `null === $previous_status || $previous_status !== $status`,
		// which is false for exactly the case this guard now short-circuits
		// — none of them ran for a same-status re-save even before this
		// guard existed, so returning early here changes nothing about
		// what those branches do for every other case.
		if ( null !== $previous_status && $previous_status === $status ) {
			return $previous;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		global $wpdb;
		$table = Schema::library_entries_table();

		$fields = array(
			'user_id'       => array( $user_id, '%d' ),
			'igdb_id'       => array( $igdb_id, '%d' ),
			'status'        => array( $status, '%s' ),
			'date_added'    => array( $now, '%s' ),
			'date_modified' => array( $now, '%s' ),
		);

		list( $columns_sql, $placeholders_sql, $values ) = $this->build_insert_clause( $fields );

		// date_added is deliberately absent from the UPDATE clause so a
		// repeated add updates only the status and date_modified (AC-010).
		$sql = "INSERT INTO {$table} ({$columns_sql}) VALUES ({$placeholders_sql})
			ON DUPLICATE KEY UPDATE
				status = VALUES(status),
				date_modified = VALUES(date_modified)";

		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql's dynamic portions are hardcoded column literals and the literal keyword NULL only; every scalar value is bound through the %d/%s placeholders built above.
		}

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql was built by $wpdb->prepare() above; ON DUPLICATE KEY UPDATE has no $wpdb->insert()/update() equivalent; invalidated via the DD-004/ADR-004 generation-counter bumps below.

		if ( false === $result ) {
			return new WP_Error( 'gl_db_error', __( 'The library entry could not be saved.', 'game-library' ) );
		}

		if ( null === $previous_status ) {
			$this->record_activity( $user_id, 'game_added', $igdb_id, null, null, $status );
		} else {
			// CO-4's guard above already returned early for a same-status
			// re-save, so reaching here with a non-null $previous_status
			// means $previous_status !== $status is guaranteed.
			$this->record_activity( $user_id, 'status_changed', $igdb_id, null, $previous_status, $status );
		}

		// MR-1 (cycle-3): bumping/purging only for a genuine change (a
		// brand-new entry or a real status change, never a same-status
		// re-save) is now CO-4's guard's job, not a condition checked here
		// — every same-status re-save already returned before this line.
		Generations::bump( Generations::library_key( $user_id ) );
		Generations::bump( Generations::activity_key() );

		// VIP-4 (cycle-3), widened CF-VIP-1 (cycle-5), narrowed VIP-2/MR-5
		// (cycle-7): purge every paginated/status variant this write can
		// actually change of the member's own /library/{nicename}/, when
		// opted public — this write changes what that page renders (the
		// entry grid, per-status counts, totals), and it is one of the
		// routes VIP's edge serves anonymously at HTTP 200 for up to 30
		// minutes. `is_edge_purge_available()` is checked FIRST — on the
		// ruled portable target it is always false, so status_counts()'s
		// GROUP BY never runs for this. `$scopes` narrows the enumeration
		// to the status this entry just left plus the status it now holds
		// plus the unfiltered view — see `member_library_purge_scopes()`'s
		// own docblock and `Page_Cache::purge_member_library()`'s for why a
		// full five-status enumeration is not needed on an ordinary write.
		if ( Page_Cache::is_edge_purge_available() && Visibility::is_public( $user_id ) ) {
			$member = get_userdata( $user_id );

			if ( $member ) {
				Page_Cache::purge_member_library(
					$member->user_nicename,
					$this->status_counts( $user_id ),
					self::DEFAULT_PER_PAGE,
					$this->member_library_purge_scopes( $previous_status, $status ),
					3
				);
			}
		}

		// CO-6 (cycle-3), widened CF-VIP-1 (cycle-5): gated on ! $was_held,
		// not just the resulting count — reference_count() returns 1 both
		// for this add's genuine first-ever reference AND for an ordinary
		// status flip by a game's sole existing holder (the write above is
		// the single entry point for both add and status change, AC-010).
		// $was_held reflects whether $user_id already held $igdb_id BEFORE
		// this write (from $previous, read above), so a real status change
		// by a sole holder no longer re-triggers this purge — mirror of
		// remove()'s own 0 === reference_count() purge, for the opposite
		// transition: when this add is the game's first-ever reference,
		// /games/{slug}/ flips from a 404 (AC-040) to a real,
		// anonymously-cached HTTP 200 page, and the public catalog gains a
		// new entry, neither of which VIP's edge revalidates against
		// origin on its own before its 30-minute TTL expires. Every
		// paginated variant of both routes is purged (CF-VIP-1), not just
		// each one's base URL.
		$was_held = null !== $previous;

		if ( ! $was_held && 1 === $this->games->reference_count( $igdb_id ) ) {
			// PB-5 (cycle-5): catalog_gen is bumped exactly here — the
			// narrow transition where this add genuinely changes the set of
			// referenced games, not on every write. Bumped BEFORE
			// count_referenced_games() below is read, so that (cached,
			// catalog_gen-scoped) count reflects this write rather than a
			// stale pre-write figure.
			Generations::bump( Generations::catalog_key() );

			// MR-5 (cycle-7): guarded FIRST — on the ruled portable target
			// this skips count_public_holders_for_game() (a join query) and
			// count_referenced_games() entirely, not just their purge.
			if ( Page_Cache::is_edge_purge_available() ) {
				$game = $this->games->get( $igdb_id );

				if ( $game ) {
					Page_Cache::purge_game_pages( $game['slug'], $this->count_public_holders_for_game( $igdb_id ), self::DEFAULT_PER_PAGE );
				}

				Page_Cache::purge_catalog_pages( $this->games->count_referenced_games(), self::DEFAULT_PER_PAGE );
			}
		}

		return $this->get_entry( $user_id, $igdb_id );
	}

	/**
	 * Deletes a member's library entry and, per DD-011, every `gl_activity`
	 * row belonging to that same member that references the same game
	 * (AC-012) — never another member's entry or activity rows for the same
	 * game. Bumps this member's library generation and the global activity
	 * generation. When this removal was the game's last reference,
	 * `Router::gate_game_single()` starts 404-ing `/games/{slug}/` on the
	 * very next origin request (AC-040) — but that route is also served
	 * anonymously at HTTP 200 and held at VIP's edge for up to 30 minutes
	 * (VIP-2), so this also purges the game's page (and the catalog listing
	 * it drops out of) from the edge rather than leaving the stale 200
	 * response there until its own TTL expires.
	 *
	 * @param int $user_id Owning member.
	 * @param int $igdb_id Game to remove.
	 * @return bool True when an entry was removed, false when none existed.
	 */
	public function remove( $user_id, $igdb_id ) {
		$user_id = absint( $user_id );
		$igdb_id = absint( $igdb_id );

		if ( ! $user_id || ! $igdb_id ) {
			return false;
		}

		// VIP-2 (cycle-7): read the entry's status before it is deleted —
		// the only consumer is the narrowed $scopes list passed to
		// Page_Cache::purge_member_library() below, so this cached read is
		// itself skipped when there is no edge to purge (MR-5).
		$removed_status = null;

		if ( Page_Cache::is_edge_purge_available() ) {
			$existing       = $this->get_entry( $user_id, $igdb_id );
			$removed_status = $existing ? $existing['status'] : null;
		}

		global $wpdb;
		$entries_table  = Schema::library_entries_table();
		$activity_table = Schema::activity_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $entries_table is Schema::library_entries_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; invalidated via the DD-004/ADR-004 generation-counter bumps below.
		$deleted = $wpdb->delete(
			$entries_table,
			array(
				'user_id' => $user_id,
				'igdb_id' => $igdb_id,
			),
			array( '%d', '%d' )
		);

		if ( ! $deleted ) {
			return false;
		}

		// DD-011: only this member's own game_added/status_changed rows for
		// this igdb_id are matched — member_followed rows have a null
		// igdb_id and never match, and no other member's user_id matches.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $activity_table is Schema::activity_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; invalidated via the DD-004/ADR-004 generation-counter bumps below.
		$wpdb->delete(
			$activity_table,
			array(
				'user_id' => $user_id,
				'igdb_id' => $igdb_id,
			),
			array( '%d', '%d' )
		);

		Generations::bump( Generations::library_key( $user_id ) );
		Generations::bump( Generations::activity_key() );

		// VIP-4 (cycle-3), widened CF-VIP-1 (cycle-5), narrowed VIP-2/MR-5
		// (cycle-7): see add_or_update()'s own comment on the same purge —
		// this write also changes what an opted-public member's own
		// /library/{nicename}/ renders. $scopes here carries only the
		// status this entry held before removal (a removal has no "new"
		// status) plus the unfiltered view.
		if ( Page_Cache::is_edge_purge_available() && Visibility::is_public( $user_id ) ) {
			$member = get_userdata( $user_id );

			if ( $member ) {
				Page_Cache::purge_member_library(
					$member->user_nicename,
					$this->status_counts( $user_id ),
					self::DEFAULT_PER_PAGE,
					$this->member_library_purge_scopes( $removed_status ),
					3
				);
			}
		}

		if ( 0 === $this->games->reference_count( $igdb_id ) ) {
			// PB-5 (cycle-5): see add_or_update()'s own comment on the same
			// bump — this is the mirror-image transition (last reference
			// removed).
			Generations::bump( Generations::catalog_key() );

			// MR-5 (cycle-7): guarded FIRST — see add_or_update()'s own
			// comment on the identical guard.
			if ( Page_Cache::is_edge_purge_available() ) {
				$game = $this->games->get( $igdb_id );

				if ( $game ) {
					Page_Cache::purge_game_pages( $game['slug'], $this->count_public_holders_for_game( $igdb_id ), self::DEFAULT_PER_PAGE );
				}

				Page_Cache::purge_catalog_pages( $this->games->count_referenced_games(), self::DEFAULT_PER_PAGE );
			}
		}

		return true;
	}

	/**
	 * The four per-status holder counts for one game, in one grouped query
	 * (used by the public game page in Task 15).
	 *
	 * @param int $igdb_id Game.
	 * @return array<string,int> Status => count, all four Statuses::all()
	 *                           keys present, 0 where nobody holds the game
	 *                           at that status.
	 */
	public function status_counts_for_game( $igdb_id ) {
		$igdb_id = absint( $igdb_id );

		$gen       = Generations::read( Generations::activity_key() );
		$cache_key = sprintf( 'game_status_counts_%d_%d', $igdb_id, $gen );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table = Schema::library_entries_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::library_entries_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE igdb_id = %d GROUP BY status", $igdb_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::library_entries_table(), never user input.
			ARRAY_A
		);

		$counts = array_fill_keys( Statuses::all(), 0 );

		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['total'];
			}
		}

		wp_cache_set( $cache_key, $counts, self::CACHE_GROUP, self::jittered_ttl() ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- jittered_ttl() is HOUR_IN_SECONDS +/- a bounded 360s jitter, always >= 3240s; the sniff cannot statically evaluate the method call's runtime result.

		return $counts;
	}

	/**
	 * One bounded page of the PUBLIC user ids holding a game and their
	 * statuses (used by the public game page) — never one query per holder,
	 * and never a non-public member's row at all (PB-7). Filters in SQL via
	 * an inner join against `wp_usermeta` on `Visibility::META_KEY` =
	 * `Visibility::PUBLIC_VALUE`, rather than the previous shape (an
	 * unfiltered page fetched from `holders_for_game()`, then a member who
	 * has not opted public dropped in PHP after the row was already
	 * counted toward that page). Profiles are private by default (AC-034),
	 * so the public subset is the minority by design — a page fetched
	 * unfiltered and post-filtered in PHP could render mostly or entirely
	 * empty while the caller's own pagination still believed it had more
	 * pages of holders to show.
	 *
	 * `status_counts_for_game()` stays unfiltered on purpose — AC-038(g)'s
	 * "private holders count but do not appear" is satisfied by the
	 * per-status totals staying whole while only this list is filtered.
	 *
	 * Cache key folds in both `activity_gen` (bumped on every library
	 * add/remove/status-change referencing this game) and `members_gen`
	 * (bumped by `Visibility::set_public()`) — a visibility flip changes
	 * this exact result set without touching `activity_gen` at all, so
	 * scoping by `activity_gen` alone would leave a stale public-holder
	 * page cached for up to this method's own TTL after a flip.
	 *
	 * @param int $igdb_id Game.
	 * @param int $limit   Page size.
	 * @param int $offset  Row offset.
	 * @return array<int,array{user_id:int,status:string}>
	 */
	public function public_holders_for_game( $igdb_id, $limit, $offset ) {
		$igdb_id = absint( $igdb_id );
		$limit   = max( 1, absint( $limit ) );
		$offset  = max( 0, absint( $offset ) );

		$gen       = Generations::read( Generations::activity_key() ) . '_' . Generations::read( Generations::members_key() );
		$cache_key = sprintf( 'game_public_holders_%d_%d_%d_%s', $igdb_id, $limit, $offset, $gen );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table = Schema::library_entries_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::library_entries_table(), never user input; $wpdb->usermeta is a core wpdb property, never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.user_id, e.status FROM {$table} e INNER JOIN {$wpdb->usermeta} um ON um.user_id = e.user_id AND um.meta_key = %s AND um.meta_value = %s WHERE e.igdb_id = %d ORDER BY e.date_added DESC, e.id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::library_entries_table(), never user input; $wpdb->usermeta is a core wpdb property.
				Visibility::META_KEY,
				Visibility::PUBLIC_VALUE,
				$igdb_id,
				$limit,
				$offset
			),
			ARRAY_A
		);

		$holders = array();

		foreach ( (array) $rows as $row ) {
			$holders[] = array(
				'user_id' => (int) $row['user_id'],
				'status'  => (string) $row['status'],
			);
		}

		wp_cache_set( $cache_key, $holders, self::CACHE_GROUP, self::jittered_ttl() ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- jittered_ttl() is HOUR_IN_SECONDS +/- a bounded 360s jitter, always >= 3240s; the sniff cannot statically evaluate the method call's runtime result.

		return $holders;
	}

	/**
	 * The total count of PUBLIC holders for one game (PB-7) — the figure
	 * `/games/{slug}/`'s own pagination is built from, distinct from
	 * `array_sum( status_counts_for_game( $igdb_id ) )`, which counts every
	 * holder regardless of visibility. Same generation-key shape as
	 * `public_holders_for_game()` above, for the identical reason.
	 *
	 * @param int $igdb_id Game.
	 * @return int
	 */
	public function count_public_holders_for_game( $igdb_id ) {
		$igdb_id = absint( $igdb_id );

		$gen       = Generations::read( Generations::activity_key() ) . '_' . Generations::read( Generations::members_key() );
		$cache_key = sprintf( 'game_public_holder_count_%d_%s', $igdb_id, $gen );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table = Schema::library_entries_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::library_entries_table(), never user input; $wpdb->usermeta is a core wpdb property, never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached via wp_cache_set() below.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} e INNER JOIN {$wpdb->usermeta} um ON um.user_id = e.user_id AND um.meta_key = %s AND um.meta_value = %s WHERE e.igdb_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::library_entries_table(), never user input; $wpdb->usermeta is a core wpdb property.
				Visibility::META_KEY,
				Visibility::PUBLIC_VALUE,
				$igdb_id
			)
		);

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::jittered_ttl() ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- jittered_ttl() is HOUR_IN_SECONDS +/- a bounded 360s jitter, always >= 3240s; the sniff cannot statically evaluate the method call's runtime result.

		return $count;
	}

	/**
	 * Fetches one member's entry for one game, cached (including a cached
	 * negative result). Backs the single-game callers of `has_game()`
	 * (`Library_Controller`'s ownership checks) — the multi-result search
	 * path uses the batched `held_igdb_ids()` above instead (PB-2), which
	 * does not call this method at all.
	 *
	 * @param int $user_id Owning member.
	 * @param int $igdb_id Game.
	 * @return array<string,mixed>|null
	 */
	private function get_entry( $user_id, $igdb_id ) {
		$gen       = Generations::read( Generations::library_key( $user_id ) );
		$cache_key = sprintf( 'entry_%d_%d_%d', $user_id, $igdb_id, $gen );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return false === $cached ? null : $cached;
		}

		global $wpdb;
		$table = Schema::library_entries_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $table is Schema::library_entries_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; cached (including negative results) via wp_cache_set() below.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, user_id, igdb_id, status, date_added, date_modified FROM {$table} WHERE user_id = %d AND igdb_id = %d", $user_id, $igdb_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::library_entries_table(), never user input.
			ARRAY_A
		);

		$entry = $row ? $this->hydrate_entry( $row ) : null;

		wp_cache_set( $cache_key, null === $entry ? false : $entry, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $entry;
	}

	/**
	 * Inserts one `gl_activity` row. Nullable columns (`igdb_id`,
	 * `object_user_id`, `status_from`, `status_to`) are written as real SQL
	 * `NULL` via `build_insert_clause()`, never an empty string or 0.
	 *
	 * @param int         $user_id        Actor.
	 * @param string      $event_type     One of `game_added`/`status_changed`/`member_followed`.
	 * @param int|null    $igdb_id        Subject game, null for follow events.
	 * @param int|null    $object_user_id Followed member, null for game events.
	 * @param string|null $status_from    Previous status, null on add.
	 * @param string|null $status_to      New status, null on follow.
	 * @return void
	 */
	private function record_activity( $user_id, $event_type, $igdb_id, $object_user_id, $status_from, $status_to ) {
		global $wpdb;
		$table = Schema::activity_table();

		$fields = array(
			'user_id'        => array( $user_id, '%d' ),
			'event_type'     => array( $event_type, '%s' ),
			'igdb_id'        => array( $igdb_id, '%d' ),
			'object_user_id' => array( $object_user_id, '%d' ),
			'status_from'    => array( $status_from, '%s' ),
			'status_to'      => array( $status_to, '%s' ),
			'date_created'   => array( gmdate( 'Y-m-d H:i:s' ), '%s' ),
		);

		list( $columns_sql, $placeholders_sql, $values ) = $this->build_insert_clause( $fields );

		$sql = "INSERT INTO {$table} ({$columns_sql}) VALUES ({$placeholders_sql})";

		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql's dynamic portions are hardcoded column literals and the literal keyword NULL only; every scalar value is bound through the %d/%s placeholders built above.
		}

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql was built by $wpdb->prepare() above; nullable columns need the literal NULL keyword build_insert_clause() produces; invalidated via the DD-004/ADR-004 generation-counter bumps in the calling method below.
	}

	/**
	 * Normalises a raw `gl_library_entries` row into typed PHP values.
	 *
	 * @param array<string,mixed> $row Raw row from `$wpdb->get_row()`/`get_results()`.
	 * @return array<string,mixed>
	 */
	private function hydrate_entry( array $row ) {
		return array(
			'id'            => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'user_id'       => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'igdb_id'       => isset( $row['igdb_id'] ) ? (int) $row['igdb_id'] : 0,
			'status'        => isset( $row['status'] ) ? (string) $row['status'] : '',
			'date_added'    => isset( $row['date_added'] ) ? (string) $row['date_added'] : '',
			'date_modified' => isset( $row['date_modified'] ) ? (string) $row['date_modified'] : '',
		);
	}

	/**
	 * Splits a `column => [ value, format ]` map into the column list,
	 * placeholder list, and prepare()-ready value list an `INSERT` needs,
	 * substituting the literal keyword `NULL` for every null value — the
	 * `%d`/`%s` placeholders `$wpdb->prepare()` supports have no way to bind
	 * a real SQL `NULL`.
	 *
	 * Every column name is a hardcoded literal defined by the caller, never
	 * user input, so interpolating them (and the literal `NULL` keyword)
	 * into the SQL string alongside prepare()'s placeholders is safe.
	 *
	 * @param array<string,array{0:mixed,1:string}> $fields Column => [ value, printf format ].
	 * @return array{0:string,1:string,2:array<int,mixed>} Columns SQL, placeholders SQL, prepare() values.
	 */
	private function build_insert_clause( array $fields ) {
		$columns      = array();
		$placeholders = array();
		$values       = array();

		foreach ( $fields as $column => $spec ) {
			list( $value, $format ) = $spec;

			$columns[] = $column;

			if ( null === $value ) {
				$placeholders[] = 'NULL';
			} else {
				$placeholders[] = $format;
				$values[]       = $value;
			}
		}

		return array( implode( ', ', $columns ), implode( ', ', $placeholders ), $values );
	}
}
