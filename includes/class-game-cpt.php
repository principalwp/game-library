<?php
/**
 * The `glib_game` post type: a read-only projection of the shared game store.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the public game page end to end (ADR-003).
 *
 * A `glib_game` post is a *projection*, not a record. It exists so a game has a
 * real WordPress URL — permalinks, the theme's single template, core sitemaps,
 * the trash workflow — while `gamelib_games` stays the single authority for
 * what the page says (D-REQ-45). The post therefore carries three things and
 * nothing else: a title mirroring the game name, a slug pinned to the IGDB slug
 * at creation (DD-013), and the `_gamelib_igdb_id` pointer back to the store
 * row. Every rendered field is read from that row at view time.
 *
 * Three consequences shape this class:
 *
 * 1. **Creation is a claim, not a check.** `gamelib_first_add` can fire from
 *    several concurrent requests for the same new game. The winner is decided
 *    by the conditional UPDATE in
 *    {@see GameLib_Game_Store::claim_post_slot()} — one statement, at the
 *    database — so exactly one post is ever inserted (AC-034a). A cache-pair
 *    lock, which two requests can both pass, is explicitly not used
 *    (Never Do #15).
 * 2. **There is no editing path.** `create_posts` maps to `do_not_allow` and
 *    `supports` is `array( 'title' )`, but neither flag removes the Edit and
 *    Quick Edit affordances or closes `post.php` — verified on WP 7.0 before
 *    this class was written, which is why both the `post_row_actions` filter
 *    *and* the `load-post.php` redirect exist (AC-035c/d). Anything that reads
 *    like editing a game is either removed or redirected.
 * 3. **The page never fetches.** {@see filter_the_content()} reads one store
 *    row into a local variable and renders from it; a row older than
 *    `GAMELIB_IGDB_CACHE_TTL` renders in full (AC-033b). Revalidation happens
 *    on the hourly job and on the admin "Refresh from IGDB" action — never
 *    inline (AC-033c, Never Do #6).
 */
final class GameLib_Game_CPT {

	/**
	 * Post type of the game projection.
	 *
	 * @var string
	 */
	const POST_TYPE = 'glib_game';

	/**
	 * Post meta holding the IGDB id — the post's half of the join with
	 * `gamelib_games.post_id`.
	 *
	 * @var string
	 */
	const META_IGDB_ID = '_gamelib_igdb_id';

	/**
	 * `admin-post.php` action behind the "Refresh from IGDB" row action.
	 *
	 * @var string
	 */
	const REFRESH_ACTION = 'gamelib_refresh_game';

	/**
	 * Query arg carrying a refresh outcome back to the list screen.
	 *
	 * @var string
	 */
	const NOTICE_ARG = 'gamelib_refreshed';

	/**
	 * Outcome value of a successful refresh.
	 *
	 * @var string
	 */
	const OUTCOME_OK = 'ok';

	/**
	 * Outcome value for a post whose IGDB pointer is missing — refusable
	 * without ever calling IGDB.
	 *
	 * @var string
	 */
	const OUTCOME_NO_ID = 'no_id';

	/**
	 * List-table column: the IGDB id (AC-035e).
	 *
	 * @var string
	 */
	const COLUMN_IGDB_ID = 'gamelib_igdb_id';

	/**
	 * List-table column: `gamelib_games.updated_at` (AC-035e).
	 *
	 * @var string
	 */
	const COLUMN_REFRESHED = 'gamelib_refreshed_at';

	/**
	 * Tags the attribution line may carry once its link is composed.
	 *
	 * @var array<string, array<string, array<mixed>>>
	 */
	const ATTRIBUTION_HTML = array(
		'a' => array(
			'class' => array(),
			'href'  => array(),
			'rel'   => array(),
		),
	);

	/**
	 * Register the post type.
	 *
	 * Runs on `init` at priority 10 (§6 Integration Points) and again, directly,
	 * during activation — the activation request has already passed `init`, so
	 * the rewrite flush that follows would otherwise persist a rule set without
	 * `/games/{slug}/` in it.
	 *
	 * `public => true` is what puts the pages in core's sitemap and makes them
	 * readable logged-out (AC-032); `show_in_rest => false` keeps the block
	 * editor from ever being offered for them; `create_posts => 'do_not_allow'`
	 * removes every "Add New" affordance, including the admin bar's (AC-035b).
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'                  => _x( 'Games', 'post type general name', 'game-library' ),
					'singular_name'         => _x( 'Game', 'post type singular name', 'game-library' ),
					'menu_name'             => _x( 'Games', 'admin menu', 'game-library' ),
					'all_items'             => __( 'All Games', 'game-library' ),
					'search_items'          => __( 'Search Games', 'game-library' ),
					'not_found'             => __( 'No games yet — a game page is created the first time a member adds it.', 'game-library' ),
					'not_found_in_trash'    => __( 'No games in the trash.', 'game-library' ),
					'view_item'             => __( 'View Game', 'game-library' ),
					'items_list'            => __( 'Games list', 'game-library' ),
					'items_list_navigation' => __( 'Games list navigation', 'game-library' ),
				),
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => false,
				'has_archive'        => false,
				'hierarchical'       => false,
				'supports'           => array( 'title' ),
				'menu_icon'          => 'dashicons-games',
				'menu_position'      => 20,
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
				'capabilities'       => array( 'create_posts' => 'do_not_allow' ),
				'delete_with_user'   => false,
				'rewrite'            => array(
					'slug'       => 'games',
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Create — or restore — the page for a game a member just added.
	 *
	 * Hooked to `gamelib_first_add`, which fires whenever a member adds a game
	 * that has no *published* page: a brand-new game, a game whose page is in
	 * the trash (AC-034e), and a concurrent add racing the first one. They end
	 * here, and the outcomes are decided in this order:
	 *
	 * - the row points at a real page → make sure it is published, and stop;
	 * - the row points at nothing     → claim the slot; the winner inserts the
	 *   page and links it to the row, the loser does nothing (AC-034a);
	 * - the row points at a page that no longer exists → re-create in place.
	 *
	 * That last case is an administrator emptying the trash: `Delete
	 * permanently` removes the post but leaves `gamelib_games.post_id` naming
	 * it, and a non-NULL column can never be claimed again — so without this the
	 * game would be permanently unable to have a page. The re-creation
	 * overwrites the dangling pointer through
	 * {@see GameLib_Game_Store::attach_post()}, leaving exactly one live page
	 * for the id, as AC-034(a) requires.
	 *
	 * @param int $igdb_id IGDB game id.
	 * @param int $user_id Member who added the game. Deliberately unused: a game
	 *                     page is not attributed to whoever added it first.
	 * @return void
	 */
	public static function on_first_add( $igdb_id, $user_id = 0 ) {
		$igdb_id = self::valid_id( $igdb_id );

		if ( $igdb_id < 1 ) {
			return;
		}

		$game = GameLib_Game_Store::get( $igdb_id );

		if ( null === $game || '' === $game['name'] ) {
			// No local record, no page: a game page with no game data behind it
			// would render nothing but the attribution line (Never Do #10).
			return;
		}

		$post_id = GameLib_Game_Store::live_post_id( $igdb_id );

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );

			if ( $post instanceof WP_Post && self::POST_TYPE === $post->post_type ) {
				self::restore_post( $post );

				return;
			}

			// The page this row names is gone (deleted permanently, or its id
			// was reused by something else). Rebuild it and re-point the row.
			self::create_post( $igdb_id, $game, false );

			return;
		}

		if ( ! GameLib_Game_Store::claim_post_slot( $igdb_id ) ) {
			/*
			 * Either another request holds the claim right now, or the row
			 * already points at a post this one could not see. Both mean "not
			 * mine to create" — the database decided (AC-034a).
			 */
			return;
		}

		self::create_post( $igdb_id, $game, true );
	}

	/**
	 * Bring the projection back in line with the store row for one or more
	 * games.
	 *
	 * Only the title moves: it mirrors the game name (AC-034c), and a rename
	 * upstream has to reach the `<h1>` the theme renders from `post_title`. The
	 * slug is never touched — a game's URL is pinned at creation and survives
	 * upstream slug drift (DD-013, AC-034b). See
	 * principal/adr/011-projection-title-sync.md — T10's constraints name only
	 * the slug, and a frozen title would leave the page's most prominent string
	 * outside the "read exclusively from the store row" rule (AC-033a).
	 *
	 * Public because the hourly refresh job (T11) processes ids in batches and
	 * has no other way in; post caches are primed once for the whole batch so a
	 * 500-id tick costs one post query, not 500 (WPP-05).
	 *
	 * @param int[]                   $igdb_ids IGDB game ids whose store rows
	 *                                          have just changed.
	 * @param array<int,array|null>|null $known  Optional. Store rows the caller
	 *                                          already holds, keyed by IGDB id
	 *                                          — the refresh job has just
	 *                                          upserted every one of them, and
	 *                                          re-reading them here was 500
	 *                                          guaranteed cache misses a tick
	 *                                          (PB-2). Null reads them in one
	 *                                          batch.
	 * @param float                   $deadline Optional. A `microtime( true )`
	 *                                          value past which the pass stops
	 *                                          rewriting titles and returns what
	 *                                          it managed (PB-2) — the shape
	 *                                          `GameLib_Library::drain_bulk()`
	 *                                          uses. Default `0.0`: no deadline,
	 *                                          because this is a published
	 *                                          batch-resync surface and its
	 *                                          other caller is a one-id
	 *                                          synchronous refresh with no clock
	 *                                          of its own.
	 * @param int[]|null              $skipped  Optional. Out-parameter, by
	 *                                          reference: the ids of the games
	 *                                          this pass never looked at because
	 *                                          `$deadline` ran out, in the order
	 *                                          they would have been handled
	 *                                          (PB-1). Empty on a pass that ran
	 *                                          to the end, and on one that had
	 *                                          no clock. A caller that stamps
	 *                                          the ids it considers done owes
	 *                                          these ones another turn — their
	 *                                          titles still mirror nothing.
	 *                                          Only ids with a live projection
	 *                                          are ever reported, on both the
	 *                                          spent-clock and the cut-loop
	 *                                          path: an id with no published
	 *                                          post is not a sync this pass
	 *                                          owes, so naming it would leave
	 *                                          the caller re-owing it forever
	 *                                          (CO-2).
	 *
	 * @param-out int[] $skipped
	 *
	 * @return int Number of posts whose title was rewritten.
	 */
	public static function sync_posts( array $igdb_ids, ?array $known = null, $deadline = 0.0, ?array &$skipped = null ) {
		$skipped  = array();
		$rows     = array();
		$post_ids = array();
		$deadline = (float) $deadline;

		$wanted = array();

		foreach ( $igdb_ids as $igdb_id ) {
			$igdb_id = self::valid_id( $igdb_id );

			if ( $igdb_id > 0 ) {
				$wanted[] = $igdb_id;
			}
		}

		if ( empty( $wanted ) ) {
			return 0;
		}

		// One batched read for the whole set, not one per id (PB-2).
		$games = ( null === $known ) ? GameLib_Game_Store::get_many( $wanted ) : $known;

		foreach ( $wanted as $igdb_id ) {
			$game = isset( $games[ $igdb_id ] ) ? $games[ $igdb_id ] : null;

			if ( ! is_array( $game ) || '' === $game['name'] || $game['post_id'] < 1 ) {
				continue;
			}

			// Keyed by id: what the pass does not reach has to be nameable
			// afterwards, and a game appears here at most once.
			$rows[ $igdb_id ] = $game;
			$post_ids[]       = $game['post_id'];
		}

		if ( empty( $rows ) ) {
			return 0;
		}

		if ( $deadline > 0.0 && microtime( true ) >= $deadline ) {
			/*
			 * The caller's clock was already spent when this pass started, so
			 * there is no rewrite to be had, and the post-cache prime below is
			 * pure cost for a pass that would then rewrite nothing (CO-1/PB-1).
			 *
			 * What goes back is the syncable set — the same thing the loop
			 * below reports when the clock stops it on its first row — and
			 * never the caller's whole id list. The out-parameter drives a
			 * write now, and an id with no published projection has no title to
			 * mirror: it can never leave a "still owed a sync" list, so
			 * reporting it here would make it permanently overdue.
			 */
			$skipped = array_keys( $rows );

			return 0;
		}

		_prime_post_caches( $post_ids, false, false );

		$synced  = 0;
		$reached = 0;

		foreach ( $rows as $game ) {
			/*
			 * The caller's wall clock, checked before each rewrite (PB-2). On a
			 * batch where every title has drifted, this is one post update per
			 * row, and each of those fires save_post and clears the post cache.
			 * A row this pass never reaches keeps the title it has, and its id
			 * goes back to the caller through $skipped so the store row it
			 * mirrors is not written off as done (PB-1).
			 */
			if ( $deadline > 0.0 && microtime( true ) >= $deadline ) {
				break;
			}

			++$reached;

			$post = get_post( $game['post_id'] );

			if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
				continue;
			}

			if ( $post->post_title === $game['name'] ) {
				continue;
			}

			/*
			 * `post_name` is deliberately absent from the update: wp_update_post()
			 * carries the stored slug through unchanged, and passing the new
			 * IGDB slug here is exactly the URL drift DD-013 forbids.
			 */
			$updated = wp_update_post(
				array(
					'ID'         => $post->ID,
					'post_title' => $game['name'],
				),
				true
			);

			if ( ! is_wp_error( $updated ) ) {
				++$synced;
			}
		}

		// Everything past the row the clock stopped on. Empty when the loop ran
		// out of rows rather than out of time.
		$skipped = array_slice( array_keys( $rows ), $reached );

		return $synced;
	}

	/**
	 * Render the game page body from the store row (AC-032).
	 *
	 * The post has no editor and therefore no content of its own; everything a
	 * visitor sees is composed here, from one row read into a local variable —
	 * no second lookup, no per-field meta read, no outbound request (AC-033,
	 * WPP-05).
	 *
	 * The guard is `is_singular()` + main query + "this is the queried post"
	 * rather than `in_the_loop()`: under a block theme the content is rendered
	 * by `core/post-content`, which applies this filter without ever entering
	 * the loop. Excerpt generation applies it too (`wp_trim_excerpt()`), and
	 * that path is refused outright — a game page's excerpt is not its body.
	 *
	 * @param string $content Post content, always '' for this post type.
	 * @return string Content with the rendered game appended.
	 */
	public static function filter_the_content( $content ) {
		if ( ! is_singular( self::POST_TYPE ) || ! is_main_query() ) {
			return $content;
		}

		if ( doing_filter( 'get_the_excerpt' ) ) {
			return $content;
		}

		$post_id = (int) get_the_ID();

		if ( $post_id < 1 || $post_id !== (int) get_queried_object_id() ) {
			return $content;
		}

		$igdb_id = self::igdb_id_for_post( $post_id );
		$game    = ( $igdb_id > 0 ) ? GameLib_Game_Store::get( $igdb_id ) : null;

		return $content . self::render_game( $post_id, $igdb_id, $game );
	}

	/**
	 * Restore a game page to `publish` rather than to core's default draft.
	 *
	 * `wp_untrash_post()` restores to draft unless something says otherwise, and
	 * a drafted projection is a 404 for every visitor while the store row still
	 * points at it. Both routes out of the trash — the admin's Restore row
	 * action and a member re-adding the game (AC-034e) — land here.
	 *
	 * @param string $new_status      Status core intends to restore to.
	 * @param int    $post_id         Post being untrashed.
	 * @param string $previous_status Status the post held when it was trashed.
	 * @return string Status to restore to.
	 */
	public static function filter_untrash_status( $new_status, $post_id, $previous_status ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return $new_status;
		}

		return ( 'publish' === $previous_status ) ? 'publish' : $new_status;
	}

	/**
	 * Replace the editing row actions with the ones a projection supports
	 * (AC-035c).
	 *
	 * Edit and Quick Edit are removed because there is nothing to edit; View,
	 * Trash / Restore / Delete are core's and are kept; "Refresh from IGDB" is
	 * added for administrators, pointing at a nonce-protected `admin-post.php`
	 * action.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post    Row's post.
	 * @return array<string, string> Filtered row actions.
	 */
	public static function filter_row_actions( $actions, $post ) {
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		unset( $actions['edit'] );
		// Core's key for the Quick Edit control, verified on the rendered list.
		unset( $actions['inline hide-if-no-js'] );

		$refresh = self::refresh_link( $post );

		if ( '' !== $refresh ) {
			$actions[ self::REFRESH_ACTION ] = $refresh;
		}

		return $actions;
	}

	/**
	 * Remove the bulk "Edit" action.
	 *
	 * Bulk edit is the last surviving inline-editing affordance on the list
	 * screen; leaving it would contradict the same rule the row actions follow
	 * (ADR-003: no hand-editing path). Trash / Restore / Delete are untouched.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string> Filtered bulk actions.
	 */
	public static function filter_bulk_actions( $actions ) {
		unset( $actions['edit'] );

		return $actions;
	}

	/**
	 * The list-table columns of AC-035(e): Title, IGDB ID, Last refreshed, Date.
	 *
	 * Built as a new map rather than by unsetting core's, so the order is the
	 * one the AC states and a column added by something else does not silently
	 * reappear between them.
	 *
	 * @param array<string, string> $columns Registered columns.
	 * @return array<string, string> Column map.
	 */
	public static function filter_columns( $columns ) {
		$replacement = array();

		if ( isset( $columns['cb'] ) ) {
			$replacement['cb'] = $columns['cb'];
		}

		$replacement['title'] = isset( $columns['title'] )
			? $columns['title']
			: _x( 'Title', 'list table column', 'game-library' );

		$replacement[ self::COLUMN_IGDB_ID ]   = __( 'IGDB ID', 'game-library' );
		$replacement[ self::COLUMN_REFRESHED ] = __( 'Last refreshed', 'game-library' );

		$replacement['date'] = isset( $columns['date'] )
			? $columns['date']
			: _x( 'Date', 'list table column', 'game-library' );

		return $replacement;
	}

	/**
	 * Read the whole list page's store rows in one batch (PB-2).
	 *
	 * {@see render_column()} is called once per row per column, and its store
	 * read used to be a single `get()` — so a 20-row admin page cost 20 cache
	 * round trips (network hops on VIP), each of which resolves the scope's
	 * generation first. This runs once for the page and leaves every one of
	 * those reads a hit.
	 *
	 * Scoped to the projection's own list table: `the_posts` fires for every
	 * query in wp-admin, and priming a `WP_Query` for another post type would be
	 * work for nothing.
	 *
	 * @param WP_Post[] $posts Posts the query found.
	 * @param WP_Query  $query The query.
	 * @return WP_Post[] The same posts, untouched.
	 */
	public static function prime_list_rows( $posts, $query ) {
		if ( ! is_array( $posts ) || empty( $posts ) ) {
			return $posts;
		}

		if ( ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return $posts;
		}

		if ( self::POST_TYPE !== $query->get( 'post_type' ) ) {
			return $posts;
		}

		$igdb_ids = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$igdb_id = self::igdb_id_for_post( $post->ID );

			if ( $igdb_id > 0 ) {
				$igdb_ids[] = $igdb_id;
			}
		}

		if ( ! empty( $igdb_ids ) ) {
			GameLib_Game_Store::get_many( $igdb_ids );
		}

		return $posts;
	}

	/**
	 * Fill the two plugin columns for one row.
	 *
	 * Both values come from the store row for this post — the id from the meta
	 * pointer, the freshness stamp from `gamelib_games.updated_at` (AC-035e) —
	 * and each row's read is served from the `games` cache scope, primed for the
	 * whole page by {@see prime_list_rows()}.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Row's post id.
	 * @return void
	 */
	public static function render_column( $column, $post_id ) {
		if ( self::COLUMN_IGDB_ID !== $column && self::COLUMN_REFRESHED !== $column ) {
			return;
		}

		$igdb_id = self::igdb_id_for_post( $post_id );

		if ( self::COLUMN_IGDB_ID === $column ) {
			// An identifier, not a quantity: printed verbatim so it can be
			// pasted into an IGDB URL or a support ticket.
			echo ( $igdb_id > 0 ) ? esc_html( (string) $igdb_id ) : '&#8212;';

			return;
		}

		$game      = ( $igdb_id > 0 ) ? GameLib_Game_Store::get( $igdb_id ) : null;
		$timestamp = ( null === $game || '' === $game['updated_at'] )
			? false
			: strtotime( $game['updated_at'] . ' UTC' );

		if ( false === $timestamp ) {
			echo '&#8212;';

			return;
		}

		echo esc_html(
			wp_date(
				(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ),
				$timestamp
			)
		);
	}

	/**
	 * Send `post.php` edit requests for a game back to the list table
	 * (AC-035d).
	 *
	 * Registration flags do not close this door: with `supports =>
	 * array( 'title' )` core still serves the editor for a direct
	 * `post.php?post={id}&action=edit` URL (verified on WP 7.0). Only `edit`
	 * and its `editpost` submission are intercepted — Trash, Restore, and
	 * Delete are `post.php` actions too, and AC-035(c) requires them to keep
	 * working.
	 *
	 * @return void
	 */
	public static function block_post_edit() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing guard: it inspects which screen was requested and redirects, changing nothing. The actions it lets through carry core's own nonces.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( 'edit' !== $action && 'editpost' !== $action ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above; `post` only identifies the screen being blocked.
		$post_id = isset( $_REQUEST['post'] ) ? absint( wp_unslash( $_REQUEST['post'] ) ) : 0;

		// The editor's own save submission names the post differently.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above; `post_ID` only identifies the screen being blocked.
		$submitted = isset( $_REQUEST['post_ID'] ) ? absint( wp_unslash( $_REQUEST['post_ID'] ) ) : 0;

		$post_id = ( $post_id > 0 ) ? $post_id : $submitted;

		if ( $post_id < 1 || self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		wp_safe_redirect( self::list_url() );
		exit;
	}

	/**
	 * Handle the "Refresh from IGDB" row action (AC-035f).
	 *
	 * An admin action, not a render: this is one of the few places the plugin
	 * is allowed to reach IGDB from a request (Never Do #6). It verifies the
	 * per-post `admin-post` nonce and the `gamelib_admin_override` capability
	 * (Always Do #3), refreshes the store row through the class that owns it,
	 * brings the projection's title back in line, and returns to the list
	 * carrying the outcome.
	 *
	 * @return void
	 */
	public static function handle_refresh() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce for this exact post id is verified two lines down; the id has to be read to name it.
		$post_id = isset( $_REQUEST['post'] ) ? absint( wp_unslash( $_REQUEST['post'] ) ) : 0;

		check_admin_referer( self::REFRESH_ACTION . '_' . $post_id );

		if ( ! current_user_can( GameLib_Capabilities::CAP_ADMIN_OVERRIDE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to refresh game data.', 'game-library' ),
				esc_html__( 'Game Library', 'game-library' ),
				array( 'response' => 403 )
			);
		}

		if ( $post_id < 1 || self::POST_TYPE !== get_post_type( $post_id ) ) {
			wp_safe_redirect( self::list_url() );
			exit;
		}

		$igdb_id = self::igdb_id_for_post( $post_id );

		if ( $igdb_id < 1 ) {
			wp_safe_redirect( add_query_arg( self::NOTICE_ARG, self::OUTCOME_NO_ID, self::list_url() ) );
			exit;
		}

		// User-visible timeout (3s): an administrator is waiting on this
		// response, so the background allowance does not apply (DD-017).
		$refreshed = GameLib_Game_Store::refresh( $igdb_id );
		$outcome   = self::OUTCOME_OK;

		if ( is_wp_error( $refreshed ) ) {
			$outcome = $refreshed->get_error_code();
		} else {
			self::sync_posts( array( $igdb_id ) );
		}

		wp_safe_redirect( add_query_arg( self::NOTICE_ARG, rawurlencode( $outcome ), self::list_url() ) );
		exit;
	}

	/**
	 * Report the outcome of a refresh on the list screen (AC-035f).
	 *
	 * @return void
	 */
	public static function render_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof WP_Screen || 'edit-' . self::POST_TYPE !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag on a redirect target; the state change it reports was nonce- and capability-checked in handle_refresh(), and the value is matched against a fixed list before it is used.
		$outcome = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';

		if ( '' === $outcome ) {
			return;
		}

		$success = ( self::OUTCOME_OK === $outcome );
		$message = $success
			? __( 'Game data refreshed from IGDB.', 'game-library' )
			: self::failure_message( $outcome );

		wp_admin_notice(
			esc_html( $message ),
			array(
				'type'        => $success ? 'success' : 'error',
				'dismissible' => true,
			)
		);
	}

	/**
	 * The IGDB id a post points at.
	 *
	 * @param int $post_id Post id.
	 * @return int IGDB id, or 0 when the post carries no pointer.
	 */
	public static function igdb_id_for_post( $post_id ) {
		$post_id = self::valid_id( $post_id );

		if ( $post_id < 1 ) {
			return 0;
		}

		return self::valid_id( get_post_meta( $post_id, self::META_IGDB_ID, true ) );
	}

	/**
	 * Insert and publish the page for a game, and point its row at it.
	 *
	 * Called from the claim path, where failure has to hand the claim back — a
	 * row left holding the sentinel would never be eligible for a page again, so
	 * a later add could never retry — and from the rebuild path, where the row
	 * holds a dangling post id that no release applies to.
	 *
	 * @param int   $igdb_id IGDB game id.
	 * @param array $game    Store row for the game.
	 * @param bool  $claimed Whether this caller holds the creation claim.
	 * @return int Created post id, or 0 when creation failed.
	 */
	private static function create_post( $igdb_id, array $game, $claimed ) {
		$post_id = wp_insert_post(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'post_title'     => $game['name'],
				// Pinned here and never rewritten (DD-013).
				'post_name'      => $game['slug'],
				'post_content'   => '',
				'post_excerpt'   => '',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				/*
				 * Nobody authors a projection. Attributing it to the member who
				 * happened to add the game first would publish that association
				 * on a page logged-out visitors can read, which is precisely
				 * what the members-only default protects (D-REQ-51).
				 */
				'post_author'    => 0,
				'meta_input'     => array( self::META_IGDB_ID => $igdb_id ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || self::valid_id( $post_id ) < 1 ) {
			if ( $claimed ) {
				GameLib_Game_Store::release_post_claim( $igdb_id );
			}

			return 0;
		}

		$post_id = (int) $post_id;

		if ( ! GameLib_Game_Store::attach_post( $igdb_id, $post_id ) ) {
			// The row does not point at this post and never will — remove the
			// orphan and let a later add try again.
			wp_delete_post( $post_id, true );

			if ( $claimed ) {
				GameLib_Game_Store::release_post_claim( $igdb_id );
			}

			return 0;
		}

		return $post_id;
	}

	/**
	 * Put an existing page back on publish (AC-034e).
	 *
	 * The same post id returns to publish — a re-add never duplicates a page.
	 *
	 * @param WP_Post $post Existing game post.
	 * @return bool True when the post is published afterwards.
	 */
	private static function restore_post( WP_Post $post ) {
		if ( 'publish' === $post->post_status ) {
			return true;
		}

		if ( 'trash' === $post->post_status ) {
			wp_untrash_post( $post->ID );
		}

		if ( 'publish' === get_post_status( $post->ID ) ) {
			return true;
		}

		$updated = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'publish',
			),
			true
		);

		return ! is_wp_error( $updated );
	}

	/**
	 * The page body for one game (AC-032).
	 *
	 * Every field except the name tolerates absence: a sparse row renders the
	 * fields it has and nothing at all for the rest — no "N/A", no empty
	 * headings. The attribution line is the one exception, rendered on every
	 * game page whether or not IGDB gave this game a URL (D-REQ-32).
	 *
	 * @param int        $post_id Post being rendered.
	 * @param int        $igdb_id IGDB id, 0 when the pointer is missing.
	 * @param array|null $game    Store row, null when there is none.
	 * @return string Escaped markup.
	 */
	private static function render_game( $post_id, $igdb_id, $game ) {
		$name = ( is_array( $game ) && '' !== $game['name'] )
			? $game['name']
			: (string) get_the_title( $post_id );

		$html   = array();
		$html[] = '<div class="gamelib gamelib-game" data-gamelib-igdb-id="' . esc_attr( (string) $igdb_id ) . '">';

		$cover = is_array( $game ) ? GameLib_Game_Store::cover_url( $game['cover_image_id'] ) : '';

		if ( '' !== $cover ) {
			/*
			 * The hero is the LCP element on a game page, and a game page is
			 * rendered by the *theme* — so this image competes with the theme's
			 * stylesheets, fonts and header imagery and lands a priority tier
			 * down unless it says otherwise. `fetchpriority="high"` puts it
			 * back, and the intrinsic dimensions reserve the box without waiting
			 * for the stylesheet's `aspect-ratio` (PF-7, PF-9).
			 */
			$size = GameLib_Game_Store::cover_dimensions();

			// The card's media classes carry the fixed cover aspect ratio, so
			// the box reserves its space before the image resolves (AC-032b).
			$html[] = '<div class="gamelib-game__cover gamelib-card__media">';
			$html[] = sprintf(
				'<img class="gamelib-card__image" src="%1$s" alt="%2$s" width="%3$s" height="%4$s" fetchpriority="high" decoding="async" />',
				esc_url( $cover ),
				esc_attr(
					sprintf(
						/* translators: %s: game name. */
						__( 'Cover art for %s', 'game-library' ),
						$name
					)
				),
				esc_attr( isset( $size[0] ) ? (string) $size[0] : '' ),
				esc_attr( isset( $size[1] ) ? (string) $size[1] : '' )
			);
			$html[] = '</div>';
		}

		$html[] = '<div class="gamelib-game__body">';

		$meta = self::meta_rows( $game );

		if ( ! empty( $meta ) ) {
			$html[] = '<dl class="gamelib-game__meta">';

			foreach ( $meta as $row ) {
				$html[] = '<div class="gamelib-game__meta-row">';
				$html[] = '<dt class="gamelib-game__meta-label">' . esc_html( $row['label'] ) . '</dt>';
				// Values are escaped as they are composed in meta_rows().
				$html[] = '<dd class="gamelib-game__meta-value">' . $row['value'] . '</dd>';
				$html[] = '</div>';
			}

			$html[] = '</dl>';
		}

		if ( is_array( $game ) && '' !== $game['summary'] ) {
			$html[] = '<div class="gamelib-game__summary">' . wpautop( wp_kses_post( $game['summary'] ) ) . '</div>';
		}

		$html[] = self::attribution( is_array( $game ) ? $game['igdb_url'] : '' );
		$html[] = self::share_control( $post_id );

		$html[] = '</div>';
		$html[] = '</div>';

		return "\n" . implode( "\n", $html ) . "\n";
	}

	/**
	 * The release date, rating, platform, and genre rows of AC-032, in order.
	 *
	 * A row is built only when its field is present, which is what makes
	 * "absent fields render nothing" true of the whole block rather than of
	 * each value.
	 *
	 * @param array|null $game Store row.
	 * @return array<int, array{label: string, value: string}> Rows with escaped values.
	 */
	private static function meta_rows( $game ) {
		if ( ! is_array( $game ) ) {
			return array();
		}

		$rows = array();

		if ( $game['first_release_date'] > 0 ) {
			$rows[] = array(
				'label' => __( 'Released', 'game-library' ),
				'value' => sprintf(
					'<time datetime="%1$s">%2$s</time>',
					esc_attr( gmdate( 'Y-m-d', $game['first_release_date'] ) ),
					esc_html( wp_date( (string) get_option( 'date_format' ), $game['first_release_date'] ) )
				),
			);
		}

		if ( ! empty( $game['platforms'] ) ) {
			$rows[] = array(
				'label' => __( 'Platforms', 'game-library' ),
				'value' => wp_sprintf( '%l', array_map( 'esc_html', $game['platforms'] ) ),
			);
		}

		if ( ! empty( $game['genres'] ) ) {
			$rows[] = array(
				'label' => __( 'Genres', 'game-library' ),
				'value' => wp_sprintf( '%l', array_map( 'esc_html', $game['genres'] ) ),
			);
		}

		if ( null !== $game['total_rating'] ) {
			$rating = number_format_i18n( (int) round( $game['total_rating'] ) );
			$count  = $game['total_rating_count'];

			if ( $count > 0 ) {
				$value = sprintf(
					/* translators: 1: rating out of 100, 2: number of ratings. */
					_n( '%1$s / 100 from %2$s rating', '%1$s / 100 from %2$s ratings', $count, 'game-library' ),
					$rating,
					number_format_i18n( $count )
				);
			} else {
				$value = sprintf(
					/* translators: %s: rating out of 100. */
					__( '%s / 100', 'game-library' ),
					$rating
				);
			}

			$rows[] = array(
				'label' => __( 'Rating', 'game-library' ),
				'value' => esc_html( $value ),
			);
		}

		return $rows;
	}

	/**
	 * The attribution line (AC-032h, D-REQ-32).
	 *
	 * Unconditional: Twitch's terms forbid removing or obscuring it, so the
	 * sentence renders on every game page. The game's IGDB URL turns the word
	 * "IGDB" into a link when the store has one; without it the same sentence
	 * renders as text.
	 *
	 * @param string $igdb_url The game's IGDB URL, '' when unknown.
	 * @return string Escaped markup.
	 */
	private static function attribution( $igdb_url ) {
		$label = esc_html__( 'IGDB', 'game-library' );

		$target = ( '' === $igdb_url )
			? $label
			: '<a class="gamelib-game__attribution-link" href="' . esc_url( $igdb_url ) . '" rel="external">' . $label . '</a>';

		$line = sprintf(
			/* translators: %s: "IGDB", linked to the game's page on IGDB when the site knows its URL. */
			__( 'Game data provided by %s', 'game-library' ),
			$target
		);

		return '<p class="gamelib-game__attribution">' . wp_kses( $line, self::ATTRIBUTION_HTML ) . '</p>';
	}

	/**
	 * The copy-link affordance (AC-032i, AC-050).
	 *
	 * The canonical page URL travels in `data-gamelib-url` and the confirmation
	 * lands in the adjacent live region, which is the shape every copy control
	 * in the plugin shares; the behaviour itself belongs to the `public` bundle.
	 *
	 * It is also the *same component* as the one `templates/profile.php` and the
	 * account panel render, so it carries the same classes (DES-4). A private
	 * `.gamelib-game__share` family had no rule anywhere in `main.scss`, which
	 * left the plugin's only public page rendering the theme's bare `<p>`: block
	 * instead of flex, the theme's paragraph margin instead of none, and a
	 * confirmation 55% larger than the one every other surface shows. The two
	 * sentences the bundle chooses between travel with it for the same reason —
	 * a control with neither writes an empty confirmation (AC-050c).
	 *
	 * @param int $post_id Post being rendered.
	 * @return string Escaped markup.
	 */
	private static function share_control( $post_id ) {
		$permalink = (string) get_permalink( $post_id );

		if ( '' === $permalink ) {
			return '';
		}

		return sprintf(
			'<p class="gamelib-share"><button type="button" class="gamelib-control gamelib-share__button" data-gamelib-action="share.copy" data-gamelib-url="%1$s" data-gamelib-share-done="%2$s" data-gamelib-share-error="%3$s">%4$s</button> <span class="gamelib-share__feedback" data-gamelib-share-feedback role="status"></span></p>',
			esc_url( $permalink ),
			esc_attr__( 'Game link copied.', 'game-library' ),
			esc_attr__( 'Copying is not available here — select the link and copy it yourself.', 'game-library' ),
			esc_html__( 'Copy link', 'game-library' )
		);
	}

	/**
	 * The "Refresh from IGDB" row-action link for one post.
	 *
	 * Rendered only for a user holding `gamelib_admin_override`, only for a
	 * post that points at a game, and never in the trash — a trashed page's
	 * data is not what an administrator is refreshing.
	 *
	 * @param WP_Post $post Row's post.
	 * @return string Anchor markup, or '' when the action does not apply.
	 */
	private static function refresh_link( WP_Post $post ) {
		if ( 'trash' === $post->post_status || ! current_user_can( GameLib_Capabilities::CAP_ADMIN_OVERRIDE ) ) {
			return '';
		}

		if ( self::igdb_id_for_post( $post->ID ) < 1 ) {
			return '';
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::REFRESH_ACTION,
					'post'   => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			self::REFRESH_ACTION . '_' . $post->ID
		);

		return sprintf(
			'<a href="%1$s" data-gamelib-action="game.refresh" aria-label="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr(
				sprintf(
					/* translators: %s: game name. */
					__( 'Refresh “%s” from IGDB', 'game-library' ),
					get_the_title( $post )
				)
			),
			esc_html__( 'Refresh from IGDB', 'game-library' )
		);
	}

	/**
	 * The admin-facing sentence for a failed refresh.
	 *
	 * Each of the IGDB client's four failure terms gets its own message, so the
	 * notice names the failure class rather than reporting "something went
	 * wrong" (AC-035f).
	 *
	 * @param string $code Error code from the refresh attempt.
	 * @return string Message, unescaped.
	 */
	private static function failure_message( $code ) {
		switch ( $code ) {
			case GameLib_IGDB_Client::ERROR_RATE_LIMITED:
				return __( 'IGDB refused the request as rate limited (HTTP 429). Nothing was changed — try again in a moment.', 'game-library' );

			case GameLib_IGDB_Client::ERROR_UNAVAILABLE:
				return __( 'IGDB is temporarily unavailable. Nothing was changed — try again shortly.', 'game-library' );

			case GameLib_IGDB_Client::ERROR_UNCONFIGURED:
				return __( 'IGDB credentials are not configured, so this game could not be refreshed.', 'game-library' );

			case GameLib_IGDB_Client::ERROR_MALFORMED:
				return __( 'IGDB returned a response this site could not use. Nothing was changed.', 'game-library' );

			case GameLib_IGDB_Client::RESULT_EMPTY:
				return __( 'IGDB has no record for this game any more, so its stored data was kept.', 'game-library' );

			case self::OUTCOME_NO_ID:
				return __( 'This game page has no IGDB id, so there is nothing to refresh.', 'game-library' );

			default:
				return __( 'Refreshing this game from IGDB failed. Nothing was changed.', 'game-library' );
		}
	}

	/**
	 * URL of the game list table.
	 *
	 * @return string Admin URL.
	 */
	private static function list_url() {
		return admin_url( 'edit.php?post_type=' . self::POST_TYPE );
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
