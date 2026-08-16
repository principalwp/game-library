<?php
/**
 * Core personal-data exporter/eraser registration.
 *
 * @package Game_Library
 */

namespace Game_Library\Privacy;

use Game_Library\Data\Game_Repository;
use Game_Library\Schema;
use Game_Library\Statuses;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Privacy.
 *
 * Registers the plugin's WordPress-core personal-data exporter and eraser
 * (`wp_privacy_personal_data_exporters`/`wp_privacy_personal_data_erasers`,
 * AC-035/AC-036) and hooks `deleted_user` so an account deletion runs the
 * identical cascade a Tools -> Erase Personal Data request does. Both the
 * eraser callback and the `deleted_user` handler call the single
 * `Erasure_Service::erase()` — neither implements its own delete logic, so
 * the two paths can never diverge.
 *
 * `export_data()` gathers the six AC-035 groups with direct, uncached reads
 * against the plugin's five tables rather than the cached repository classes
 * the rest of the plugin uses. A personal-data export is a rare,
 * admin-triggered, per-member request, never a hot request-cycle path, so
 * caching it buys no measurable performance — and a GDPR export/erasure
 * specifically wants the current database state at request time, not a
 * cache-TTL-delayed snapshot, which caching here would work against. See
 * `principal/adr/010-privacy-reads-bypass-object-cache.md`.
 */
final class Privacy {

	/**
	 * Hard cap on rows read per export group — generous for one member's own
	 * data (never sitewide), while still satisfying the "paginate every list
	 * query with an explicit limit" rule.
	 *
	 * @var int
	 */
	private const EXPORT_LIMIT = 5000;

	/**
	 * The six AC-035 export groups, one per page (PB-9, cycle-2) — each is
	 * this class's own private method name, called via `$this->{$method}()`,
	 * which visibility allows from inside the class regardless of the
	 * method's own `private` modifier.
	 *
	 * @var string[]
	 */
	private const EXPORT_GROUPS = array(
		'export_library_entries',
		'export_following',
		'export_followers',
		'export_activity',
		'export_invites_issued',
		'export_invite_redeemed',
	);

	/**
	 * The single cascade-delete routine, shared with `on_deleted_user()`.
	 *
	 * @var Erasure_Service
	 */
	private $erasure_service;

	/**
	 * Cached game metadata, used to label library entries and activity rows
	 * with a game name instead of a bare IGDB id.
	 *
	 * @var Game_Repository
	 */
	private $games;

	/**
	 * Constructor.
	 *
	 * @param Erasure_Service|null $erasure_service Cascade-delete routine.
	 *                                               Defaults to a new instance.
	 * @param Game_Repository|null $games           Cached game metadata.
	 *                                               Defaults to a new instance.
	 */
	public function __construct( ?Erasure_Service $erasure_service = null, ?Game_Repository $games = null ) {
		$this->erasure_service = $erasure_service ?: new Erasure_Service();
		$this->games            = $games ?: new Game_Repository();
	}

	/**
	 * Registers this service's hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		// VIP-1 (cycle-3): 3 accepted args, not the default 1 — on_deleted_user()
		// needs the $user object core still passes, since it can no longer
		// re-read the account by the time this fires (see that method's own
		// docblock).
		add_action( 'deleted_user', array( $this, 'on_deleted_user' ), 10, 3 );
	}

	/**
	 * Adds this plugin's exporter to core's registry.
	 *
	 * @param array<string,array<string,mixed>> $exporters Registered exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( $exporters ) {
		$exporters['game-library'] = array(
			'exporter_friendly_name' => __( 'Game Library', 'game-library' ),
			'callback'               => array( $this, 'export_data' ),
		);

		return $exporters;
	}

	/**
	 * Adds this plugin's eraser to core's registry.
	 *
	 * @param array<string,array<string,mixed>> $erasers Registered erasers.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_eraser( $erasers ) {
		$erasers['game-library'] = array(
			'eraser_friendly_name' => __( 'Game Library', 'game-library' ),
			'callback'             => array( $this, 'erase_data' ),
		);

		return $erasers;
	}

	/**
	 * The core personal-data exporter callback (AC-035): (a) library entries
	 * with status and dates, (b) members followed, (c) members following,
	 * (d) activity entries, (e) invites issued including recipient emails,
	 * and (f) the invite that created this account including inviter and
	 * channel.
	 *
	 * Honours core's own `$page` argument (PB-9, cycle-2) instead of always
	 * reading and returning all six groups on page 1 — one group per page,
	 * `done` flips `true` only on the last (`EXPORT_GROUPS`). Each group's
	 * own read is still bounded at `EXPORT_LIMIT` (5000) rows, but a single
	 * request now holds at most one group's rows (plus, for the two groups
	 * that embed one, up to `EXPORT_LIMIT` hydrated game rows) instead of
	 * all six groups' worth simultaneously — a power user's export
	 * previously reached ~25,000 rows plus up to 5,000 hydrated game arrays
	 * in one response, defeating the bounded-paging contract core's own
	 * exporter API exists to provide.
	 *
	 * @param string $email_address Requester's email address.
	 * @param int    $page          1-based page number core passes in.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export_data( $email_address, $page = 1 ) {
		$user       = get_user_by( 'email', $email_address );
		$page       = max( 1, (int) $page );
		$last_page  = count( self::EXPORT_GROUPS );

		if ( ! $user || $page > $last_page ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$user_id = (int) $user->ID;
		$method  = self::EXPORT_GROUPS[ $page - 1 ];

		return array(
			'data' => $this->{$method}( $user_id ),
			'done' => $page === $last_page,
		);
	}

	/**
	 * The core personal-data eraser callback (AC-036) — delegates the actual
	 * cascade to `Erasure_Service::erase()`, the single place a member's rows
	 * are deleted.
	 *
	 * @param string $email_address Requester's email address.
	 * @param int    $page          Unused; the cascade is a single, atomic
	 *                              pass, never paginated.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase_data( $email_address, $page = 1 ) {
		unset( $page );

		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		return array(
			'items_removed'  => $this->erasure_service->erase( (int) $user->ID ),
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Runs the identical cascade an account deletion needs (AC-036). The
	 * `deleted_user` action fires after WordPress core has already removed
	 * the account row and its own user meta, but the plugin's five custom
	 * tables are untouched by core and still need this cascade.
	 *
	 * VIP-1 (cycle-3): passes core's own `$user` argument's `user_nicename`
	 * straight through to `Erasure_Service::erase()` instead of letting it
	 * re-read the account — by the time `deleted_user` fires, core has
	 * already deleted the `wp_users` row and every `wp_usermeta` row and
	 * called `clean_user_cache()`, so `get_userdata( $user_id )` returns
	 * `false` and the member's own opted-public `/library/{nicename}/` never
	 * gets purged from VIP's edge. The `WP_User` object core still holds at
	 * this point is the only place `user_nicename` survives past this hook.
	 *
	 * @param int          $user_id  Deleted user's id.
	 * @param int|null     $reassign Unused; the id posts/links were
	 *                                reassigned to, or null.
	 * @param WP_User|null $user     The now-deleted user object, still held
	 *                                by core at the point this fires.
	 * @return void
	 */
	public function on_deleted_user( $user_id, $reassign = null, $user = null ) {
		unset( $reassign );

		$this->erasure_service->erase( (int) $user_id, $user instanceof WP_User ? $user->user_nicename : null );
	}

	/**
	 * AC-035 (a): library entries with status and dates.
	 *
	 * @param int $user_id Member.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_library_entries( $user_id ) {
		global $wpdb;
		$table = Schema::library_entries_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::library_entries_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; deliberately uncached, see class docblock.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT igdb_id, status, date_added, date_modified FROM {$table} WHERE user_id = %d ORDER BY date_added DESC, id DESC LIMIT %d", $user_id, self::EXPORT_LIMIT ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::library_entries_table(), never user input; deliberately uncached, see class docblock.
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$games = $this->games_chunked( wp_list_pluck( $rows, 'igdb_id' ) );
		$items = array();

		foreach ( $rows as $row ) {
			$igdb_id = (int) $row['igdb_id'];
			$game    = isset( $games[ $igdb_id ] ) ? $games[ $igdb_id ] : null;

			$items[] = array(
				'group_id'    => 'game-library-library-entries',
				'group_label' => __( 'Game Library: Library Entries', 'game-library' ),
				'item_id'     => 'game-library-entry-' . $igdb_id,
				'data'        => array(
					array(
						'name'  => __( 'Game', 'game-library' ),
						'value' => $game ? $game['name'] : sprintf(
							/* translators: %d: IGDB game id. */
							__( 'Game #%d', 'game-library' ),
							$igdb_id
						),
					),
					array(
						'name'  => __( 'Status', 'game-library' ),
						'value' => Statuses::label( $row['status'] ),
					),
					array(
						'name'  => __( 'Date added', 'game-library' ),
						'value' => $row['date_added'],
					),
					array(
						'name'  => __( 'Date last modified', 'game-library' ),
						'value' => $row['date_modified'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * AC-035 (b): the members this user follows.
	 *
	 * @param int $user_id Member.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_following( $user_id ) {
		global $wpdb;
		$table = Schema::follows_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::follows_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; deliberately uncached, see class docblock.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT following_id, date_created FROM {$table} WHERE follower_id = %d ORDER BY id ASC LIMIT %d", $user_id, self::EXPORT_LIMIT ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::follows_table(), never user input; deliberately uncached, see class docblock.
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		cache_users( array_map( 'absint', wp_list_pluck( $rows, 'following_id' ) ) );

		$items = array();

		foreach ( $rows as $row ) {
			$following_id = (int) $row['following_id'];
			$member       = get_userdata( $following_id );

			$items[] = array(
				'group_id'    => 'game-library-following',
				'group_label' => __( 'Game Library: Members You Follow', 'game-library' ),
				'item_id'     => 'game-library-following-' . $following_id,
				'data'        => array(
					array(
						'name'  => __( 'Member', 'game-library' ),
						'value' => $member ? $member->display_name : (string) $following_id,
					),
					array(
						'name'  => __( 'Followed on', 'game-library' ),
						'value' => $row['date_created'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * AC-035 (c): the members following this user.
	 *
	 * @param int $user_id Member.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_followers( $user_id ) {
		global $wpdb;
		$table = Schema::follows_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::follows_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; deliberately uncached, see class docblock.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT follower_id, date_created FROM {$table} WHERE following_id = %d ORDER BY id ASC LIMIT %d", $user_id, self::EXPORT_LIMIT ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::follows_table(), never user input; deliberately uncached, see class docblock.
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		cache_users( array_map( 'absint', wp_list_pluck( $rows, 'follower_id' ) ) );

		$items = array();

		foreach ( $rows as $row ) {
			$follower_id = (int) $row['follower_id'];
			$member      = get_userdata( $follower_id );

			$items[] = array(
				'group_id'    => 'game-library-followers',
				'group_label' => __( 'Game Library: Members Following You', 'game-library' ),
				'item_id'     => 'game-library-follower-' . $follower_id,
				'data'        => array(
					array(
						'name'  => __( 'Member', 'game-library' ),
						'value' => $member ? $member->display_name : (string) $follower_id,
					),
					array(
						'name'  => __( 'Followed on', 'game-library' ),
						'value' => $row['date_created'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * AC-035 (d): this user's activity entries.
	 *
	 * @param int $user_id Member.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_activity( $user_id ) {
		global $wpdb;
		$table = Schema::activity_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::activity_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; deliberately uncached, see class docblock.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, event_type, igdb_id, object_user_id, status_from, status_to, date_created FROM {$table} WHERE user_id = %d ORDER BY date_created DESC, id DESC LIMIT %d", $user_id, self::EXPORT_LIMIT ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::activity_table(), never user input; deliberately uncached, see class docblock.
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$igdb_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'igdb_id' ) ) ) );
		$games    = $igdb_ids ? $this->games_chunked( $igdb_ids ) : array();

		$object_user_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'object_user_id' ) ) ) );

		if ( $object_user_ids ) {
			cache_users( $object_user_ids );
		}

		$items = array();

		foreach ( $rows as $row ) {
			$data = array(
				array(
					'name'  => __( 'Event', 'game-library' ),
					'value' => $this->event_label( $row['event_type'] ),
				),
			);

			if ( ! empty( $row['igdb_id'] ) ) {
				$igdb_id = (int) $row['igdb_id'];
				$game    = isset( $games[ $igdb_id ] ) ? $games[ $igdb_id ] : null;

				$data[] = array(
					'name'  => __( 'Game', 'game-library' ),
					'value' => $game ? $game['name'] : sprintf(
						/* translators: %d: IGDB game id. */
						__( 'Game #%d', 'game-library' ),
						$igdb_id
					),
				);
			}

			if ( ! empty( $row['object_user_id'] ) ) {
				$object_user = get_userdata( (int) $row['object_user_id'] );

				$data[] = array(
					'name'  => __( 'Member', 'game-library' ),
					'value' => $object_user ? $object_user->display_name : (string) $row['object_user_id'],
				);
			}

			if ( ! empty( $row['status_from'] ) ) {
				$data[] = array(
					'name'  => __( 'Previous status', 'game-library' ),
					'value' => Statuses::label( $row['status_from'] ),
				);
			}

			if ( ! empty( $row['status_to'] ) ) {
				$data[] = array(
					'name'  => __( 'New status', 'game-library' ),
					'value' => Statuses::label( $row['status_to'] ),
				);
			}

			$data[] = array(
				'name'  => __( 'Date', 'game-library' ),
				'value' => $row['date_created'],
			);

			$items[] = array(
				'group_id'    => 'game-library-activity',
				'group_label' => __( 'Game Library: Activity Entries', 'game-library' ),
				'item_id'     => 'game-library-activity-' . (int) $row['id'],
				'data'        => $data,
			);
		}

		return $items;
	}

	/**
	 * AC-035 (e): invites this user issued, including recipient emails.
	 *
	 * @param int $user_id Member.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_invites_issued( $user_id ) {
		global $wpdb;
		$table = Schema::invites_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; deliberately uncached, see class docblock.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, code, email, channel, status, date_created, date_expires FROM {$table} WHERE inviter_id = %d ORDER BY date_created DESC, id DESC LIMIT %d", $user_id, self::EXPORT_LIMIT ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input; deliberately uncached, see class docblock.
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$items = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'game-library-invites-issued',
				'group_label' => __( 'Game Library: Invites You Issued', 'game-library' ),
				'item_id'     => 'game-library-invite-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Code', 'game-library' ),
						'value' => $row['code'],
					),
					array(
						'name'  => __( 'Recipient email', 'game-library' ),
						'value' => ! empty( $row['email'] ) ? $row['email'] : '',
					),
					array(
						'name'  => __( 'Channel', 'game-library' ),
						'value' => $row['channel'],
					),
					array(
						'name'  => __( 'Status', 'game-library' ),
						'value' => $row['status'],
					),
					array(
						'name'  => __( 'Created', 'game-library' ),
						'value' => $row['date_created'],
					),
					array(
						'name'  => __( 'Expires', 'game-library' ),
						'value' => $row['date_expires'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * AC-035 (f): the invite that created this user's own account, including
	 * the inviter and channel.
	 *
	 * @param int $user_id Member.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_invite_redeemed( $user_id ) {
		global $wpdb;
		$table = Schema::invites_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is Schema::invites_table(), never user input; five custom tables (DD-001/ADR-001) need direct wpdb access; deliberately uncached, see class docblock.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, inviter_id, channel, date_redeemed FROM {$table} WHERE redeemed_user_id = %d LIMIT 1", $user_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema::invites_table(), never user input; deliberately uncached, see class docblock.
			ARRAY_A
		);

		if ( ! $row ) {
			return array();
		}

		$inviter = get_userdata( (int) $row['inviter_id'] );

		return array(
			array(
				'group_id'    => 'game-library-invite-redeemed',
				'group_label' => __( 'Game Library: Invite That Created Your Account', 'game-library' ),
				'item_id'     => 'game-library-invite-redeemed-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Invited by', 'game-library' ),
						'value' => $inviter ? $inviter->display_name : (string) $row['inviter_id'],
					),
					array(
						'name'  => __( 'Channel', 'game-library' ),
						'value' => $row['channel'],
					),
					array(
						'name'  => __( 'Redeemed on', 'game-library' ),
						'value' => (string) $row['date_redeemed'],
					),
				),
			),
		);
	}

	/**
	 * `Game_Repository::get_many()`, chunked at 500 ids per call (PB-11) —
	 * a power user's `export_library_entries()`/`export_activity()` reads
	 * can each return up to `EXPORT_LIMIT` (5000) rows, and an unchunked
	 * `get_many()` call builds a single `$wpdb->prepare()` `IN (…)` clause
	 * with up to 5000 `%d` placeholders and writes up to 5000 individual
	 * `wp_cache_set()` entries in one call — this bounds both to a fixed,
	 * predictable batch size regardless of how large one member's own data
	 * grows.
	 *
	 * @param array<int,mixed> $igdb_ids IGDB game ids (need not be pre-deduplicated).
	 * @return array<int,array<string,mixed>> Hydrated rows keyed by igdb_id.
	 */
	private function games_chunked( array $igdb_ids ) {
		$games = array();

		foreach ( array_chunk( $igdb_ids, 500 ) as $chunk ) {
			$games += $this->games->get_many( $chunk );
		}

		return $games;
	}

	/**
	 * The translated label for an activity event type.
	 *
	 * @param string $event_type One of `game_added`/`status_changed`/`member_followed`.
	 * @return string
	 */
	private function event_label( $event_type ) {
		$labels = array(
			'game_added'      => __( 'Game added', 'game-library' ),
			'status_changed'  => __( 'Status changed', 'game-library' ),
			'member_followed' => __( 'Followed a member', 'game-library' ),
		);

		return isset( $labels[ $event_type ] ) ? $labels[ $event_type ] : $event_type;
	}
}
