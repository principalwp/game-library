<?php
/**
 * `VideoGame` and `CollectionPage` JSON-LD.
 *
 * @package Game_Library
 */

namespace Game_Library\Seo;

use Game_Library\Data\Game_Repository;
use Game_Library\Data\Library_Repository;
use Game_Library\Igdb\Game_Mapper;
use Game_Library\Router;
use Game_Library\Statuses;
use Game_Library\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Schema_Org.
 *
 * Emits exactly one `VideoGame` JSON-LD object on `/games/{slug}/` (DD-005) —
 * the single canonical structured-data entity for that game across the
 * whole site — and a `CollectionPage`/`ItemList` of `@id`/`url`/`position`
 * references on an opted-public `/library/{nicename}/`, never re-declaring
 * `VideoGame` properties there (AC-041).
 *
 * Both render methods independently resolve the current route's game/member
 * data through the same cached repositories the corresponding template
 * (`templates/game-single.php`/`templates/member-library.php`) already
 * calls with identical parameters, so the underlying `wp_cache_get()` reads
 * here are cache hits, not a second uncached query.
 */
final class Schema_Org {

	/**
	 * Cached game reads.
	 *
	 * @var Game_Repository
	 */
	private $game_repository;

	/**
	 * Cached library-entry reads.
	 *
	 * @var Library_Repository
	 */
	private $library_repository;

	/**
	 * Cover-URL builder.
	 *
	 * @var Game_Mapper
	 */
	private $mapper;

	/**
	 * Page size for the `ItemList` on an opted-public library page — matches
	 * `templates/member-library.php`'s own 24-per-page so the structured data
	 * describes exactly what that page renders.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 24;

	/**
	 * Constructor.
	 *
	 * @param Game_Repository|null    $game_repository    Game repository. Defaults to a new instance.
	 * @param Library_Repository|null $library_repository Library repository. Defaults to a new instance.
	 * @param Game_Mapper|null        $mapper             Game mapper. Defaults to a new instance.
	 */
	public function __construct( ?Game_Repository $game_repository = null, ?Library_Repository $library_repository = null, ?Game_Mapper $mapper = null ) {
		$this->game_repository     = $game_repository ?: new Game_Repository();
		$this->library_repository = $library_repository ?: new Library_Repository();
		$this->mapper              = $mapper ?: new Game_Mapper();
	}

	/**
	 * Registers this class's hooks.
	 *
	 * CF-PF-3 (cycle-5): priority 20, not 1 — core runs `wp_resource_hints`
	 * at priority 2 (the `images.igdb.com` preconnect, added specifically
	 * to give the LCP cover image a head start) and `wp_print_styles` at 8
	 * on this same `wp_head` action. At priority 1 this JSON-LD payload
	 * printed before both, pushing the preload scanner's discovery of the
	 * preconnect and the two stylesheet links one payload later than
	 * necessary (~0.4-1.8 KB on `/games/{slug}/`, ~2.5 KB on an
	 * opted-public `/library/{nicename}/`). Structured data has no
	 * ordering requirement in `<head>` and nothing in ADR-005 or AC-041
	 * calls for priority 1 — 20 stays comfortably inside `<head>` and after
	 * `wp_print_styles` (8) and `wp_print_head_scripts` (9).
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_head', array( $this, 'render' ), 20 );
	}

	/**
	 * Emits the route-appropriate JSON-LD block, if any.
	 *
	 * @return void
	 */
	public function render() {
		// CO-1: Router::serve_404() sets $wp_query->set_404() for an
		// out-of-range/unreferenced-game request but leaves gl_route set to
		// its original value, so a 404 response must not also declare a
		// VideoGame/CollectionPage entity for the URL it just 404'd.
		if ( is_404() ) {
			return;
		}

		$route = get_query_var( 'gl_route' );

		if ( ! is_string( $route ) || '' === $route ) {
			return;
		}

		if ( Router::ROUTE_GAME === $route ) {
			$this->render_video_game();

			return;
		}

		if ( Router::ROUTE_LIBRARY === $route ) {
			$this->render_collection_page();
		}
	}

	/**
	 * Emits the single `VideoGame` object for `/games/{slug}/` (AC-041).
	 *
	 * @return void
	 */
	private function render_video_game() {
		$slug = sanitize_title( (string) get_query_var( 'gl_param' ) );
		$game = '' !== $slug ? $this->game_repository->get_by_slug( $slug ) : null;

		if ( null === $game ) {
			return;
		}

		$game_url  = Router::game_url( $game['slug'] );
		$cover_url = $this->mapper->cover_url( $game['cover_image_id'] );

		$video_game = array(
			'@context' => 'https://schema.org',
			'@type'    => 'VideoGame',
			'name'     => $game['name'],
			'url'      => esc_url( $game_url ),
		);

		if ( $cover_url ) {
			$video_game['image'] = esc_url( $cover_url );
		}

		if ( ! empty( $game['summary'] ) ) {
			$video_game['description'] = $game['summary'];
		}

		// null !== ..., not empty() (CO-12 sibling): a first_release_date of
		// exactly 0 (1970-01-01 UTC) is a real value, not an absent one —
		// empty( 0 ) is true, which would omit datePublished for it
		// identically to a genuinely missing date.
		if ( null !== $game['first_release_date'] ) {
			$video_game['datePublished'] = gmdate( 'Y-m-d', $game['first_release_date'] );
		}

		if ( ! empty( $game['genres'] ) ) {
			$video_game['genre'] = array_values( $game['genres'] );
		}

		$this->print_json_ld( $video_game );
	}

	/**
	 * Emits the `CollectionPage`/`ItemList` of `@id` references on an
	 * opted-public `/library/{nicename}/` (AC-041) — mirrors
	 * `templates/member-library.php`'s own nicename/status/page resolution
	 * exactly, so the `Library_Repository::get_page()` call below hits the
	 * same cache entry that template's own render already populated.
	 *
	 * @return void
	 */
	private function render_collection_page() {
		$nicename = sanitize_title( (string) get_query_var( 'gl_param' ) );
		$member   = '' !== $nicename ? get_user_by( 'slug', $nicename ) : false;

		if ( false === $member ) {
			return;
		}

		if ( ! Visibility::is_public( $member->ID ) ) {
			return;
		}

		// Read-only filter param from a plain GET navigation — not a
		// state-changing action, so no nonce applies; mirrors
		// templates/member-library.php's own accepted pattern. Pagination
		// reads the plugin-owned `gl_page` query var, never `paged` — see
		// templates/partials/pagination.php's own docblock for why.
		$current_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter param, not a state-changing action.
		$current_status = Statuses::is_valid( $current_status ) ? $current_status : '';
		$current_page   = max( 1, absint( get_query_var( 'gl_page' ) ) );

		$entries = $this->library_repository->get_page( $member->ID, $current_status, $current_page, self::PAGE_SIZE );

		if ( empty( $entries ) ) {
			return;
		}

		$games = $this->game_repository->get_many( wp_list_pluck( $entries, 'igdb_id' ) );

		$items    = array();
		$position = 1;

		foreach ( $entries as $entry ) {
			$game = isset( $games[ $entry['igdb_id'] ] ) ? $games[ $entry['igdb_id'] ] : null;

			if ( null === $game ) {
				continue;
			}

			$game_url = esc_url( Router::game_url( $game['slug'] ) );

			$items[] = array(
				'@id'      => $game_url,
				'url'      => $game_url,
				'position' => $position,
			);

			++$position;
		}

		if ( empty( $items ) ) {
			return;
		}

		$collection_page = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'CollectionPage',
			'url'        => esc_url( Router::member_library_url( $member->user_nicename ) ),
			'mainEntity' => array(
				'@type'           => 'ItemList',
				'itemListElement' => $items,
			),
		);

		$this->print_json_ld( $collection_page );
	}

	/**
	 * Prints one JSON-LD `<script>` block with `wp_json_encode()` — never raw
	 * `json_encode()`.
	 *
	 * @param array<string,mixed> $data Structured-data object.
	 * @return void
	 */
	private function print_json_ld( array $data ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode()'s output is JSON, not HTML; wrapping it in esc_html() would corrupt it. wp_json_encode() escapes forward slashes by default (no JSON_UNESCAPED_SLASHES flag passed), which is what prevents a value containing "</script>" from breaking out of the tag below.
		echo '<script type="application/ld+json">' . wp_json_encode( $data ) . '</script>' . "\n";
	}
}
