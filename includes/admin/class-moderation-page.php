<?php
/**
 * Correct a cached game and delete a single activity entry (AC-047, AC-048).
 *
 * @package Game_Library
 */

namespace Game_Library\Admin;

use Game_Library\Data\Activity_Repository;
use Game_Library\Data\Game_Repository;
use Game_Library\Dates;
use Game_Library\Igdb\Client;
use Game_Library\Igdb\Game_Mapper;
use Game_Library\Statuses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Moderation_Page.
 *
 * Registers the "Moderation" submenu under the top-level "Game Library" menu
 * (`gl_moderate_library`) with two regions:
 *
 * - A game finder (a bounded name search over `Game_Repository::
 *   search_by_name()`) plus an edit form for the selected row's `name`,
 *   `summary`, `first_release_date`, `genres`, and `platforms` (AC-047
 *   (a)-(e)), and a "Refresh from IGDB" action (AC-047 (f)) that reuses the
 *   exact same `game_library_refresh_{igdb_id}` cooldown transient key and
 *   TTL `Game_Repository::refresh_cooldown_key()`/`REFRESH_COOLDOWN`
 *   (arch-pre-3 architecture review, finding AR-3) own, so a member's own
 *   front-end refresh and this screen's refresh share one 3600-second
 *   cooldown per game (AC-008) rather than two independently-maintained
 *   copies.
 * - A recent-activity list (newest first, paginated) with a per-row "Delete"
 *   control (AC-048).
 *
 * Deliberately does **not** extend `WP_List_Table` for the activity list —
 * `Invites_List_Table` (Task 9) documents a fatal
 * (`Call to undefined function convert_to_screen()`) from calling
 * `WP_List_Table::__construct()` at `Plugin::__construct()` time and works
 * around it with a lazy `ensure_table_initialized()` step. A plain
 * `<table>` render (matching `Settings_Page`'s own style) sidesteps that
 * failure mode entirely rather than reproducing the workaround for a single
 * list with no column sorting/bulk-action needs — see the Task 18 coder
 * decision log.
 *
 * Every editable-field save and every "Refresh from IGDB" call goes through
 * `Game_Repository::upsert()` (Task 3/5), which already applies the exact
 * Data Model sanitizers for every field this screen edits; `handle_save_game()`
 * additionally re-applies `sanitize_text_field()`/`sanitize_textarea_field()`/
 * `absint()` itself before building the `upsert()` payload — defense in depth,
 * not a substitute for `upsert()`'s own sanitization. Because `upsert()`
 * overwrites every column but `date_cached` on every call, `handle_save_game()`
 * always re-reads the existing row first and carries its `slug`,
 * `cover_image_id`, `aggregated_rating`, and `igdb_url` through unchanged —
 * none of those four are editable on this screen, and omitting them from the
 * `upsert()` call would silently null them out.
 *
 * `upsert()` itself now bumps both its own `games_gen` scope and the shared
 * `activity_gen` counter the front-end pages that read cached game data by
 * other keys (`/games/{slug}/`, `/my-library/`, the public catalog and game
 * pages — see spec section 6's Transients and object cache table) key off
 * (arch-pre-2 architecture review, finding AR-1). `handle_save_game()`/
 * `handle_refresh_game()` no longer call an `Activity_Repository` bump
 * themselves — `Game_Repository::upsert()` owns that invalidation for every
 * caller now, not just this screen — satisfying AC-047(g)'s "visible on the
 * very next front-end request" automatically.
 *
 * Every write handler (`handle_save_game()`, `handle_refresh_game()`,
 * `handle_delete_activity()`) independently re-checks
 * `current_user_can( 'gl_moderate_library' )` and verifies its own nonce
 * before any write, matching `Invites_List_Table`/`Settings_Page`'s
 * convention — a user who can `edit_posts` but not `gl_moderate_library`
 * (e.g. an Author) gets `wp_die()` with an explicit `'response' => 403`
 * from every one of them (AC-047(h), AC-048). A user who cannot `edit_posts`
 * never reaches these walls at all — `Roles::maybe_redirect_from_admin()`
 * redirects them out of wp-admin first, per AC-002(e).
 */
final class Moderation_Page {

	/**
	 * Top-level parent menu slug, registered by `Settings_Page::register_menu()`.
	 *
	 * @var string
	 */
	private const PARENT_SLUG = 'game-library';

	/**
	 * This submenu's own page slug.
	 *
	 * @var string
	 */
	private const MENU_SLUG = 'game-library-moderation';

	/**
	 * `admin-post.php` action name for saving the five editable game fields.
	 *
	 * @var string
	 */
	private const SAVE_GAME_ACTION = 'gl_moderation_save_game';

	/**
	 * `admin-post.php` action name for the "Refresh from IGDB" control.
	 *
	 * @var string
	 */
	private const REFRESH_GAME_ACTION = 'gl_moderation_refresh_game';

	/**
	 * `admin-post.php` action name for deleting one activity row.
	 *
	 * @var string
	 */
	private const DELETE_ACTIVITY_ACTION = 'gl_moderation_delete_activity';

	/**
	 * Nonce field name for the "Save changes" form — deliberately distinct
	 * from the "Refresh from IGDB" form's own nonce name, so the two
	 * `<form>` elements this screen renders together never produce a
	 * duplicate `id="_wpnonce"`.
	 *
	 * @var string
	 */
	private const SAVE_NONCE_NAME = '_gl_moderation_save_nonce';

	/**
	 * Nonce field name for the "Refresh from IGDB" form.
	 *
	 * @var string
	 */
	private const REFRESH_NONCE_NAME = '_gl_moderation_refresh_nonce';

	/**
	 * Maximum rows the game finder search returns.
	 *
	 * @var int
	 */
	private const GAME_SEARCH_LIMIT = 20;

	/**
	 * Recent-activity list page size.
	 *
	 * @var int
	 */
	private const ACTIVITY_PER_PAGE = 20;

	/**
	 * Cached game metadata.
	 *
	 * @var Game_Repository
	 */
	private $games;

	/**
	 * Activity feed rows.
	 *
	 * @var Activity_Repository
	 */
	private $activity;

	/**
	 * IGDB/Twitch client, used only for the "Refresh from IGDB" action.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Maps a raw IGDB payload onto a `Game_Repository::upsert()` row.
	 *
	 * @var Game_Mapper
	 */
	private $mapper;

	/**
	 * Constructor.
	 *
	 * @param Game_Repository|null     $games    Game repository. Defaults to a new instance.
	 * @param Activity_Repository|null $activity Activity repository. Defaults to a new instance.
	 * @param Client|null              $client   IGDB client. Defaults to a new instance.
	 * @param Game_Mapper|null         $mapper   Payload mapper. Defaults to a new instance.
	 */
	public function __construct(
		?Game_Repository $games = null,
		?Activity_Repository $activity = null,
		?Client $client = null,
		?Game_Mapper $mapper = null
	) {
		$this->games    = $games ?: new Game_Repository();
		$this->activity = $activity ?: new Activity_Repository();
		$this->client   = $client ?: new Client();
		$this->mapper   = $mapper ?: new Game_Mapper();
	}

	/**
	 * Registers this service's hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::SAVE_GAME_ACTION, array( $this, 'handle_save_game' ) );
		add_action( 'admin_post_' . self::REFRESH_GAME_ACTION, array( $this, 'handle_refresh_game' ) );
		add_action( 'admin_post_' . self::DELETE_ACTIVITY_ACTION, array( $this, 'handle_delete_activity' ) );
	}

	/**
	 * Registers the "Moderation" submenu under the top-level "Game Library" menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Moderation', 'game-library' ),
			__( 'Moderation', 'game-library' ),
			'gl_moderate_library',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the full admin screen: heading, flash notice, game finder/edit
	 * form, and the recent-activity list.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'gl_moderate_library' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'game-library' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Moderation', 'game-library' ) . '</h1>';

		$this->render_flash_notice();
		$this->render_game_section();
		$this->render_activity_section();

		echo '</div>';
	}

	/**
	 * Renders the search form, any search results, and (when a game is
	 * selected) its edit form.
	 *
	 * @return void
	 */
	private function render_game_section() {
		$query   = isset( $_GET['gl_game_query'] ) ? sanitize_text_field( wp_unslash( $_GET['gl_game_query'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search param, not a state-changing action.
		$igdb_id = isset( $_GET['gl_igdb_id'] ) ? absint( wp_unslash( $_GET['gl_igdb_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection param, not a state-changing action.

		echo '<h2>' . esc_html__( 'Find a game', 'game-library' ) . '</h2>';

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		echo '<label class="screen-reader-text" for="gl-mod-game-query">' . esc_html__( 'Search cached games by name', 'game-library' ) . '</label>';
		echo '<input type="search" id="gl-mod-game-query" name="gl_game_query" value="' . esc_attr( $query ) . '" placeholder="' . esc_attr__( 'Search cached games by name…', 'game-library' ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Search', 'game-library' ) . '</button>';
		echo '</form>';

		if ( '' !== $query ) {
			$this->render_search_results( $query );
		}

		if ( $igdb_id ) {
			$game = $this->games->get( $igdb_id );

			if ( null === $game ) {
				echo '<p>' . esc_html__( 'That game is not cached.', 'game-library' ) . '</p>';
			} else {
				$this->render_edit_form( $game );
			}
		}
	}

	/**
	 * Renders the bounded name-search results, each linking to this same
	 * screen with `gl_igdb_id` set so the edit form below loads that row.
	 *
	 * @param string $query Sanitized search query.
	 * @return void
	 */
	private function render_search_results( $query ) {
		$results = $this->games->search_by_name( $query, self::GAME_SEARCH_LIMIT );

		if ( empty( $results ) ) {
			echo '<p>' . esc_html__( 'No cached games matched that search.', 'game-library' ) . '</p>';

			return;
		}

		echo '<ul class="gl-moderation-results">';

		foreach ( $results as $result ) {
			$select_url = add_query_arg(
				array(
					'page'          => self::MENU_SLUG,
					'gl_game_query' => $query,
					'gl_igdb_id'    => $result['igdb_id'],
				),
				admin_url( 'admin.php' )
			);

			echo '<li><a href="' . esc_url( $select_url ) . '">' . esc_html( $result['name'] ) . '</a></li>';
		}

		echo '</ul>';
	}

	/**
	 * Renders the edit form for one cached game's five editable fields plus
	 * the "Refresh from IGDB" control.
	 *
	 * @param array<string,mixed> $game Hydrated `gl_games` row.
	 * @return void
	 */
	private function render_edit_form( array $game ) {
		echo '<h2>' . esc_html__( 'Edit game', 'game-library' ) . '</h2>';
		echo '<p>' . esc_html__( 'IGDB id:', 'game-library' ) . ' ' . esc_html( (string) $game['igdb_id'] ) . ' — ' . esc_html__( 'Slug:', 'game-library' ) . ' ' . esc_html( $game['slug'] ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_GAME_ACTION ) . '" />';
		echo '<input type="hidden" name="igdb_id" value="' . esc_attr( (string) $game['igdb_id'] ) . '" />';
		wp_nonce_field( self::SAVE_GAME_ACTION . '_' . $game['igdb_id'], self::SAVE_NONCE_NAME );

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="gl-mod-name">' . esc_html__( 'Name', 'game-library' ) . '</label></th><td>';
		echo '<input type="text" id="gl-mod-name" name="name" class="regular-text" value="' . esc_attr( $game['name'] ) . '" required="required" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="gl-mod-summary">' . esc_html__( 'Summary', 'game-library' ) . '</label></th><td>';
		echo '<textarea id="gl-mod-summary" name="summary" rows="5" class="large-text">' . esc_textarea( null === $game['summary'] ? '' : $game['summary'] ) . '</textarea>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="gl-mod-release-date">' . esc_html__( 'First release date', 'game-library' ) . '</label></th><td>';
		// CO-2: no min="0" — IGDB returns a genuine negative Unix timestamp
		// for a pre-1970 game (e.g. Spacewar!, Pong), and this field must
		// accept one.
		echo '<input type="number" id="gl-mod-release-date" name="first_release_date" value="' . esc_attr( null === $game['first_release_date'] ? '' : (string) $game['first_release_date'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Unix timestamp in seconds. Leave blank if unknown.', 'game-library' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="gl-mod-genres">' . esc_html__( 'Genres', 'game-library' ) . '</label></th><td>';
		// CO-8: newline-separated, not comma-separated — a stored value
		// containing a comma (e.g. "Role-playing (RPG), Adventure") would
		// otherwise silently split into two entries the next time this form
		// is saved for any reason, even an edit to an unrelated field.
		echo '<textarea id="gl-mod-genres" name="genres" rows="4" class="regular-text">' . esc_textarea( implode( "\n", $game['genres'] ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One per line.', 'game-library' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="gl-mod-platforms">' . esc_html__( 'Platforms', 'game-library' ) . '</label></th><td>';
		echo '<textarea id="gl-mod-platforms" name="platforms" rows="4" class="regular-text">' . esc_textarea( implode( "\n", $game['platforms'] ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One per line.', 'game-library' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';

		submit_button( __( 'Save changes', 'game-library' ) );

		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::REFRESH_GAME_ACTION ) . '" />';
		echo '<input type="hidden" name="igdb_id" value="' . esc_attr( (string) $game['igdb_id'] ) . '" />';
		wp_nonce_field( self::REFRESH_GAME_ACTION . '_' . $game['igdb_id'], self::REFRESH_NONCE_NAME );
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Refresh from IGDB', 'game-library' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Renders the paginated recent-activity list with a per-row delete link
	 * (AC-048), batching every referenced game in one
	 * `Game_Repository::get_many()` call rather than one lookup per row.
	 *
	 * @return void
	 */
	private function render_activity_section() {
		$per_page     = self::ACTIVITY_PER_PAGE;
		$current_page = isset( $_GET['activity_paged'] ) ? max( 1, absint( wp_unslash( $_GET['activity_paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination param, not a state-changing action.

		// MR-2: clamp against count_recent() (already cached) before calling
		// get_recent() — an out-of-range activity_paged value otherwise ran
		// the query below anyway and wrote an hour-long dead cache entry for
		// that offset.
		$total        = $this->activity->count_recent();
		$total_pages  = max( 1, (int) ceil( $total / $per_page ) );
		$current_page = min( $current_page, $total_pages );
		$offset       = ( $current_page - 1 ) * $per_page;

		$entries = $this->activity->get_recent( $per_page, $offset );

		$game_ids = array_values( array_unique( array_filter( wp_list_pluck( $entries, 'igdb_id' ) ) ) );
		$games    = ! empty( $game_ids ) ? $this->games->get_many( $game_ids ) : array();

		echo '<h2>' . esc_html__( 'Recent activity', 'game-library' ) . '</h2>';

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No activity has been recorded yet.', 'game-library' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Event', 'game-library' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Date', 'game-library' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Action', 'game-library' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			echo '<tr>';
			echo '<td>' . esc_html( $this->describe_event( $entry, $games ) ) . '</td>';
			echo '<td>' . esc_html( Dates::format( $entry['date_created'], true ) ) . '</td>';
			echo '<td><a href="' . esc_url( $this->build_delete_activity_url( $entry['id'] ) ) . '">' . esc_html__( 'Delete', 'game-library' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$links = paginate_links(
			array(
				'base'    => add_query_arg( 'activity_paged', '%#%' ),
				'format'  => '',
				'current' => $current_page,
				'total'   => $total_pages,
			)
		);

		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . $links . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() is a WordPress core function returning pre-escaped HTML, matching submit_button()'s/wp_nonce_field()'s own unescaped-echo convention elsewhere in this codebase.
		}
	}

	/**
	 * Handles the "Save changes" form post (AC-047 (a)-(e), (g)).
	 *
	 * @return void
	 */
	public function handle_save_game() {
		if ( ! current_user_can( 'gl_moderate_library' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'game-library' ), '', array( 'response' => 403 ) );
		}

		$igdb_id = isset( $_POST['igdb_id'] ) ? absint( wp_unslash( $_POST['igdb_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() below.

		check_admin_referer( self::SAVE_GAME_ACTION . '_' . $igdb_id, self::SAVE_NONCE_NAME );

		$game = $this->games->get( $igdb_id );

		if ( null === $game ) {
			$this->redirect_with_notice( 'game_not_found', 0 );
		}

		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() above.
		$summary = isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['summary'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() above.
		// CO-7: null, not '', when cleared — AC-005(d) specifies "string or
		// null" for this column; an empty string is neither, and it no
		// longer round-trips as absent for a later read-back.
		$summary = '' === $summary ? null : $summary;

		$raw_release_date = isset( $_POST['first_release_date'] ) ? sanitize_text_field( wp_unslash( $_POST['first_release_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() above.
		// (int), not absint() (CO-2): IGDB returns a genuine negative Unix
		// timestamp for a pre-1970 game — absint()'s absolute-value coercion
		// silently mirrored that into a positive, wrong date instead of
		// preserving it, matching the fix Game_Repository::upsert() already
		// carries for the same column (cycle-1 CO-4).
		$first_release_date = '' === $raw_release_date ? null : (int) $raw_release_date;

		// CO-8: sanitize_textarea_field(), not sanitize_text_field() —
		// the field is now a <textarea> (newline-separated, not
		// comma-separated) so each line's own trailing whitespace is
		// preserved for parse_list_field() to trim, and an embedded comma in
		// a genre/platform name (e.g. "Role-playing (RPG)") no longer
		// collides with the list's own separator.
		$genres    = $this->parse_list_field( isset( $_POST['genres'] ) ? sanitize_textarea_field( wp_unslash( $_POST['genres'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() above.
		$platforms = $this->parse_list_field( isset( $_POST['platforms'] ) ? sanitize_textarea_field( wp_unslash( $_POST['platforms'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() above.

		// slug/cover_image_id/aggregated_rating/igdb_url are not editable on
		// this screen — carried through unchanged from the existing row so
		// upsert() (which overwrites every column but date_cached) does not
		// null them out. See the class docblock.
		$result = $this->games->upsert(
			array(
				'igdb_id'            => $igdb_id,
				'slug'               => $game['slug'],
				'name'               => $name,
				'summary'            => $summary,
				'first_release_date' => $first_release_date,
				'cover_image_id'     => $game['cover_image_id'],
				'genres'             => $genres,
				'platforms'          => $platforms,
				'aggregated_rating'  => $game['aggregated_rating'],
				'igdb_url'           => $game['igdb_url'],
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'game_save_error', $igdb_id );
		}

		$this->redirect_with_notice( 'game_saved', $igdb_id );
	}

	/**
	 * Handles the "Refresh from IGDB" form post (AC-047(f), AC-008) — the
	 * only IGDB call this screen makes, and the only write handler in this
	 * class that reaches `Client`.
	 *
	 * MR-2 (cycle-5, same defect class as `Library_Controller::refresh()`'s
	 * own SE-1 cycle-3 fix, which this handler had drifted out of sync
	 * with): the cooldown is armed immediately after the check passes,
	 * BEFORE `Client::fetch_games()` is called — not after the upsert.
	 * Arming after the outbound call left a window where a concurrent
	 * request inside the same IGDB round-trip read the cooldown as absent
	 * and reached IGDB too, and a consistently-failing game (whose upsert
	 * never runs) never armed the cooldown at all, so it could be replayed
	 * without limit. Deliberately does NOT clear the cooldown on a failure
	 * branch (`is_wp_error( $fetched )`, empty result, or a failed upsert)
	 * — the attempt already consumed the outbound-mail-adjacent IGDB budget
	 * this cooldown exists to bound, and a consistently-failing game must
	 * not be replayable just because it fails; the moderator sees the error
	 * notice and can wait out the same cooldown a successful refresh would
	 * have armed.
	 *
	 * @return void
	 */
	public function handle_refresh_game() {
		if ( ! current_user_can( 'gl_moderate_library' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'game-library' ), '', array( 'response' => 403 ) );
		}

		$igdb_id = isset( $_POST['igdb_id'] ) ? absint( wp_unslash( $_POST['igdb_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() below.

		check_admin_referer( self::REFRESH_GAME_ACTION . '_' . $igdb_id, self::REFRESH_NONCE_NAME );

		$cooldown_key = Game_Repository::refresh_cooldown_key( $igdb_id );

		if ( false !== get_transient( $cooldown_key ) ) {
			$this->redirect_with_notice( 'refresh_cooldown', $igdb_id );
		}

		set_transient( $cooldown_key, 1, Game_Repository::REFRESH_COOLDOWN );

		$fetched = $this->client->fetch_games( array( $igdb_id ) );

		if ( is_wp_error( $fetched ) ) {
			$this->redirect_with_notice( 'refresh_error', $igdb_id );
		}

		if ( empty( $fetched ) ) {
			$this->redirect_with_notice( 'refresh_not_found', $igdb_id );
		}

		$upserted = $this->games->upsert( $this->mapper->to_row( $fetched[0] ) );

		if ( is_wp_error( $upserted ) ) {
			$this->redirect_with_notice( 'refresh_error', $igdb_id );
		}

		$this->redirect_with_notice( 'refreshed', $igdb_id );
	}

	/**
	 * Handles the "Delete" row action on the recent-activity list (AC-048).
	 *
	 * @return void
	 */
	public function handle_delete_activity() {
		if ( ! current_user_can( 'gl_moderate_library' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'game-library' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['activity_id'] ) ? absint( wp_unslash( $_GET['activity_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() below.

		check_admin_referer( self::DELETE_ACTIVITY_ACTION . '_' . $id );

		$deleted = $this->activity->delete( $id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => self::MENU_SLUG,
					'gl_moderation_notice' => $deleted ? 'activity_deleted' : 'activity_delete_error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Redirects back to this screen carrying a flash-notice query arg and,
	 * when non-zero, the just-edited game's id so its edit form reloads with
	 * the saved/refreshed values. Exits — every caller treats this as a
	 * terminal statement.
	 *
	 * @param string $notice  One of the keys `render_flash_notice()` recognises.
	 * @param int    $igdb_id Game id to re-select, or 0 for none.
	 * @return void
	 */
	private function redirect_with_notice( $notice, $igdb_id ) {
		$args = array(
			'page'                 => self::MENU_SLUG,
			'gl_moderation_notice' => $notice,
		);

		$igdb_id = absint( $igdb_id );

		if ( $igdb_id ) {
			$args['gl_igdb_id'] = $igdb_id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renders the flash result of a just-completed save/refresh/delete
	 * action, read from the query string `redirect_with_notice()`/
	 * `handle_delete_activity()` redirected with.
	 *
	 * @return void
	 */
	private function render_flash_notice() {
		if ( ! isset( $_GET['gl_moderation_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash flag, not a state-changing action; the actions themselves are nonce-verified in their own handlers.
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['gl_moderation_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.

		$messages = array(
			'game_saved'            => array( 'success', __( 'Game updated.', 'game-library' ) ),
			'game_save_error'       => array( 'error', __( 'The game could not be saved.', 'game-library' ) ),
			'game_not_found'        => array( 'error', __( 'That game is not cached.', 'game-library' ) ),
			'refreshed'             => array( 'success', __( 'Game refreshed from IGDB.', 'game-library' ) ),
			'refresh_cooldown'      => array( 'error', __( 'This game was recently refreshed. Try again shortly.', 'game-library' ) ),
			'refresh_not_found'     => array( 'error', __( 'That game could not be found on IGDB.', 'game-library' ) ),
			'refresh_error'         => array( 'error', __( 'The IGDB service is temporarily unavailable.', 'game-library' ) ),
			'activity_deleted'      => array( 'success', __( 'Activity entry deleted.', 'game-library' ) ),
			'activity_delete_error' => array( 'error', __( 'That activity entry could not be deleted.', 'game-library' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		list( $type, $text ) = $messages[ $notice ];

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}

	/**
	 * Splits a newline-separated form field into a sanitized, non-empty list
	 * — shared by the `genres`/`platforms` fields (AC-047 (d)-(e)). Each item
	 * is sanitized individually with `sanitize_text_field()`, matching the
	 * Data Model's own `genres`/`platforms` sanitizer before
	 * `Game_Repository::upsert()` JSON-encodes the result.
	 *
	 * Newline-separated, not comma-separated (CO-8): the field's own display
	 * form (`implode( ', ', … )`) round-tripped through a comma-separated
	 * `<input>` collided with any stored value that itself contained a comma
	 * (e.g. "Role-playing (RPG)"), silently splitting it into two entries the
	 * next time the form was saved for any reason. No plausible genre or
	 * platform name contains a newline.
	 *
	 * @param string $raw Raw, already-unslashed newline-separated field value.
	 * @return string[]
	 */
	private function parse_list_field( $raw ) {
		$normalized = str_replace( "\r\n", "\n", (string) $raw );
		$parts      = explode( "\n", $normalized );
		$items      = array();

		foreach ( $parts as $part ) {
			$part = sanitize_text_field( trim( $part ) );

			if ( '' !== $part ) {
				$items[] = $part;
			}
		}

		return $items;
	}

	/**
	 * Builds a plain-text description of one activity row for the recent-
	 * activity list — unlike `templates/activity.php`'s own feed sentence,
	 * this never skips a row for a missing actor/game/followed-member (an
	 * orphaned row still needs to be visible so a moderator can delete it).
	 *
	 * @param array<string,mixed>            $entry One hydrated activity row.
	 * @param array<int,array<string,mixed>> $games Batched `Game_Repository::get_many()` result, keyed by igdb_id.
	 * @return string
	 */
	private function describe_event( array $entry, array $games ) {
		$actor      = get_userdata( $entry['user_id'] );
		$actor_name = $actor ? $actor->display_name : sprintf(
			/* translators: %d: user id of a since-deleted account. */
			__( '(deleted user #%d)', 'game-library' ),
			$entry['user_id']
		);

		if ( 'member_followed' === $entry['event_type'] ) {
			$object      = null !== $entry['object_user_id'] ? get_userdata( $entry['object_user_id'] ) : false;
			$object_name = $object ? $object->display_name : __( '(deleted member)', 'game-library' );

			return sprintf(
				/* translators: 1: actor display name, 2: followed member display name. */
				__( '%1$s started following %2$s.', 'game-library' ),
				$actor_name,
				$object_name
			);
		}

		$game      = ( null !== $entry['igdb_id'] && isset( $games[ $entry['igdb_id'] ] ) ) ? $games[ $entry['igdb_id'] ] : null;
		$game_name = $game ? $game['name'] : __( '(unknown game)', 'game-library' );

		if ( 'status_changed' === $entry['event_type'] ) {
			return sprintf(
				/* translators: 1: actor display name, 2: game title, 3: previous status label, 4: new status label. */
				__( '%1$s moved %2$s from %3$s to %4$s.', 'game-library' ),
				$actor_name,
				$game_name,
				Statuses::label( $entry['status_from'] ),
				Statuses::label( $entry['status_to'] )
			);
		}

		// 'game_added', and any future/unknown event_type, falls back to this
		// neutral sentence.
		return sprintf(
			/* translators: 1: actor display name, 2: game title. */
			__( '%1$s added %2$s to their library.', 'game-library' ),
			$actor_name,
			$game_name
		);
	}

	/**
	 * Builds one nonced `admin-post.php` delete-activity row-action URL. The
	 * nonce action string embeds the row id (`{action}_{id}`) so one row's
	 * link can never be replayed against another row.
	 *
	 * @param int $id Activity row id.
	 * @return string
	 */
	private function build_delete_activity_url( $id ) {
		$id = absint( $id );

		$url = add_query_arg(
			array(
				'action'      => self::DELETE_ACTIVITY_ACTION,
				'activity_id' => $id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::DELETE_ACTIVITY_ACTION . '_' . $id );
	}
}
