<?php
/**
 * `WP_Sitemaps_Provider` for the public games and opted-public-member
 * library routes.
 *
 * @package Game_Library
 */

namespace Game_Library\Seo;

use Game_Library\Data\Game_Repository;
use Game_Library\Data\Member_Directory;
use Game_Library\Router;
use Game_Library\Settings;
use WP_Sitemaps_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sitemap_Provider.
 *
 * Registers one `WP_Sitemaps_Provider` (name `gamelibrary` — WordPress core's
 * own per-sitemap-page rewrite rule, `^wp-sitemap-([a-z]+?)-([a-z\d_-]+?)-(\d+?)\.xml$`,
 * captures the provider name with `[a-z]+?`, letters only; a hyphenated name
 * like `game-library` splits at the first hyphen and silently 404s every
 * per-page sitemap file — confirmed at runtime against the shared Playground
 * instance, port 9401, see the Task 16 coder decision log) exposing two
 * object subtypes through core's sitemap system (AC-044) — `games` (every
 * `/games/{slug}/` page at least one member's library references) and
 * `members` (every opted-public `/library/{nicename}/` page). Each subtype
 * is its own independently-paginated section/sitemap file in
 * `/wp-sitemap.xml`, the same way a single core provider (e.g.
 * `WP_Sitemaps_Posts`) covers multiple post types as subtypes rather than
 * one class per post type — the Components table (spec section 6) and Task 16
 * (spec.md:2236-2240) both describe exactly one provider class at exactly one
 * file path exposing two object subtypes, which is what this class implements:
 * one registered provider with two subtypes, not two literal subclasses.
 *
 * Registration mechanism — this class registers on `wp_sitemaps_init`, the
 * action WordPress core documents for adding sitemap providers, which is the
 * hook spec.md's Integration Points table (spec.md:1171-1173) and Task 16
 * (spec.md:2236-2240) now name directly. Earlier, pre-ADR-009 task text cited
 * `wp_sitemaps_add_provider` instead, but that hook cannot introduce a
 * provider: `wp_sitemaps_add_provider` is a
 * *filter* WordPress core applies inside
 * `WP_Sitemaps_Registry::add_provider()`, fired only once a provider is
 * already being added under a given name — it has no mechanism for
 * introducing a brand-new provider name, and nothing in core ever calls
 * `add_provider()` for a name it does not already know about on its own.
 * Core's own bundled providers (posts, taxonomies, users) all register
 * themselves from `WP_Sitemaps::register_sitemaps()`, invoked via
 * `do_action( 'wp_sitemaps_init', $wp_sitemaps )` — `wp-includes/sitemaps.php`
 * (`wp_sitemaps_get_server()`) documents this action's purpose directly:
 * "Additional sitemaps should be registered on this hook." This class hooks
 * `wp_sitemaps_init` and calls `wp_register_sitemap_provider()` there, which
 * is itself the call that triggers the `wp_sitemaps_add_provider` filter for
 * this provider instance — the registration genuinely does flow "through"
 * that filter, just not by adding a callback to it directly. See the Task 16
 * coder decision log and `principal/adr/009-sitemap-registration-hook.md`.
 *
 * `catalog_indexable` (AC-045 (d)-(e), DD-013) is read once, at registration
 * time — `wp_sitemaps_init` fires at most once per request, memoized by
 * core's own `wp_sitemaps_get_server()` — so when the setting is off,
 * `register_provider()` never calls `wp_register_sitemap_provider()` at all:
 * both the `games` and `members` sections disappear from `/wp-sitemap.xml`
 * together, and a direct request for one of this provider's own sitemap
 * files 404s exactly like an unregistered core provider would. This mirrors
 * `Robots`' own `catalog_indexable()` check (Task 15) rather than sharing a
 * trait/utility with it — matching this codebase's established precedent of
 * keeping small, single-purpose settings reads local to the class that needs
 * them (see `Library_Repository`'s generation-counter helpers, Task 3).
 *
 * Every URL emitted is escaped with `esc_url()`. Every underlying read goes
 * through `Game_Repository`'s already-cached, `$wpdb->prepare()`d
 * `get_referenced_games()`/`count_referenced_games()` for the `games`
 * subtype (Task 15), or a plain `WP_User_Query` — the same core API
 * `Social_Controller::get_members()` and `templates/members.php` already use
 * for member listings elsewhere in this plugin, never a direct `$wpdb`
 * query — for the `members` subtype. Both subtypes page with core's own
 * `wp_sitemaps_get_max_urls()` limit through an explicit `LIMIT`/`OFFSET` (or
 * `number`/`offset`) per page; neither ever loads every row at once.
 */
final class Sitemap_Provider extends WP_Sitemaps_Provider {

	/**
	 * The provider name (CO-7) — also `$this->object_type`. Promoted to a
	 * public constant so `Settings_Page::purge_catalog_indexable_edge_cache()`
	 * can build the same sitemap URLs this class itself registers, rather
	 * than re-declaring the literal independently: the provider has already
	 * been renamed once (`game-library` -> `gamelibrary`, see the class
	 * docblock), and a future rename that updates only this constant would
	 * otherwise leave that purge silently pointed at URLs that no longer
	 * exist.
	 *
	 * @var string
	 */
	public const PROVIDER_NAME = 'gamelibrary';

	/**
	 * The two object subtypes this provider exposes (AC-044, CO-7) — the
	 * same single source of truth as `PROVIDER_NAME`, for the same reason.
	 *
	 * @var string[]
	 */
	public const SUBTYPES = array( 'games', 'members' );

	/**
	 * Cached game reads for the `games` subtype.
	 *
	 * @var Game_Repository
	 */
	private $games;

	/**
	 * Cached, capability-filtered member reads for the `members` subtype
	 * (MR-4/PB-6).
	 *
	 * @var Member_Directory
	 */
	private $members;

	/**
	 * Constructor.
	 *
	 * @param Game_Repository|null  $games   Game repository. Defaults to a new instance.
	 * @param Member_Directory|null $members Member directory. Defaults to a new instance.
	 */
	public function __construct( ?Game_Repository $games = null, ?Member_Directory $members = null ) {
		$this->name        = self::PROVIDER_NAME;
		$this->object_type = self::PROVIDER_NAME;
		$this->games       = $games ?: new Game_Repository();
		$this->members     = $members ?: new Member_Directory();
	}

	/**
	 * Registers this class's hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_sitemaps_init', array( $this, 'register_provider' ) );
	}

	/**
	 * Registers this provider with core's sitemap registry, unless
	 * `catalog_indexable` is currently disabled (AC-045 (d)-(e)).
	 *
	 * @return void
	 */
	public function register_provider() {
		if ( ! $this->catalog_indexable() ) {
			return;
		}

		wp_register_sitemap_provider( $this->name, $this );
	}

	/**
	 * The two object subtypes this provider exposes (AC-044). Only the array
	 * keys are read by core (`WP_Sitemaps_Provider::get_sitemap_type_data()`
	 * iterates the keys only); the values are never inspected.
	 *
	 * @return array<string,bool>
	 */
	public function get_object_subtypes() {
		return array_fill_keys( self::SUBTYPES, true );
	}

	/**
	 * Gets a URL list for one page of one subtype's sitemap.
	 *
	 * MR-2: clamped against `get_max_num_pages()` before dispatching to
	 * either subtype's query. Core's own per-page sitemap rewrite rule
	 * (`^wp-sitemap-([a-z]+?)-([a-z\d_-]+?)-(\d+?)\.xml$`) takes the page
	 * number straight from the URL and never consults this method — it 404s
	 * only AFTER this provider has already done the work — so an
	 * out-of-range page number (or one past a shrinking member/game count)
	 * reached the underlying query unclamped and wrote an hour-long dead
	 * cache entry keyed on that page. `get_max_num_pages()` reads an
	 * already-cached, generation-keyed count, so this costs one cached read
	 * and never reaches the expensive query on an out-of-range page.
	 *
	 * @param int    $page_num       Page of results.
	 * @param string $object_subtype `games` or `members`.
	 * @return array[]
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$page_num = max( 1, absint( $page_num ) );
		$max      = $this->get_max_num_pages( $object_subtype );

		if ( $max < 1 || $page_num > $max ) {
			return array();
		}

		if ( 'members' === $object_subtype ) {
			return $this->member_url_list( $page_num );
		}

		if ( 'games' === $object_subtype ) {
			return $this->game_url_list( $page_num );
		}

		return array();
	}

	/**
	 * Gets the max number of pages available for one subtype.
	 *
	 * @param string $object_subtype `games` or `members`.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		if ( 'members' === $object_subtype ) {
			return $this->member_max_num_pages();
		}

		if ( 'games' === $object_subtype ) {
			return $this->game_max_num_pages();
		}

		return 0;
	}

	/**
	 * One page of `/games/{slug}/` URLs — every game at least one member
	 * references (AC-044), through `Game_Repository::referenced_game_slugs()`
	 * (PB-2) — a slug-only query with no per-row cache priming, not
	 * `get_referenced_games()`'s full-row catalog-listing query: this
	 * provider's own page size (`wp_sitemaps_get_max_urls()`, 2,000) is far
	 * larger than the catalog's 24, and `slug` is the only field this list
	 * consumes. `Router`'s access gate (Task 10) already 404s an
	 * unreferenced game's own page, so this list can never emit a URL that
	 * route would reject.
	 *
	 * @param int $page_num Page of results.
	 * @return array[]
	 */
	private function game_url_list( $page_num ) {
		$slugs = $this->games->referenced_game_slugs( $page_num, $this->per_page() );

		$url_list = array();

		foreach ( $slugs as $slug ) {
			$url_list[] = array(
				'loc' => esc_url( Router::game_url( $slug ) ),
			);
		}

		return $url_list;
	}

	/**
	 * Total page count for the `games` subtype.
	 *
	 * @return int
	 */
	private function game_max_num_pages() {
		return (int) ceil( $this->games->count_referenced_games() / $this->per_page() );
	}

	/**
	 * One page of `/library/{nicename}/` URLs — every member who has opted
	 * `_gl_profile_public` (AC-044), filtered to `gl_manage_library` holders
	 * (the same "member" definition `Social_Controller::get_members()` and
	 * `templates/members.php` already use elsewhere in this plugin), via
	 * `Member_Directory::public_member_nicenames()` (MR-4/PB-6/PB-1) —
	 * cached and paged with an explicit `number`/`offset`, never an
	 * unbounded `WP_User_Query`, and never a `get_userdata()` call per row
	 * (PB-1): nicename is the only field this method consumes, and it comes
	 * straight out of the already-cached query.
	 *
	 * @param int $page_num Page of results.
	 * @return array[]
	 */
	private function member_url_list( $page_num ) {
		$nicenames = $this->members->public_member_nicenames( $page_num, $this->per_page() );

		$url_list = array();

		foreach ( $nicenames as $nicename ) {
			$url_list[] = array(
				'loc' => esc_url( Router::member_library_url( $nicename ) ),
			);
		}

		return $url_list;
	}

	/**
	 * Total page count for the `members` subtype.
	 *
	 * @return int
	 */
	private function member_max_num_pages() {
		return (int) ceil( $this->members->public_member_count() / $this->per_page() );
	}

	/**
	 * Page size shared by both subtypes — core's own per-object-type sitemap
	 * URL limit, never an unbounded read.
	 *
	 * @return int
	 */
	private function per_page() {
		return max( 1, (int) wp_sitemaps_get_max_urls( $this->object_type ) );
	}

	/**
	 * Whether the public catalog/opted-public-library surfaces are currently
	 * indexable — the same `catalog_indexable` setting `Robots` (Task 15)
	 * reads (AC-045), now both routed through the shared `Settings` class
	 * (arch-pre-1 architecture review, finding AR-1) rather than each
	 * carrying its own copy of the option name and implicit-true default.
	 *
	 * @return bool
	 */
	private function catalog_indexable() {
		$settings = Settings::all();

		return (bool) $settings['catalog_indexable'];
	}
}
