<?php
/**
 * Head output, indexing rules, and the public-member sitemap.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's own SEO layer (AC-047–AC-049).
 *
 * The site ships no theme and no SEO plugin (C-REQ-11, D-REQ-41), so every
 * head signal a public plugin surface needs is composed here: the document
 * title, the meta description, the canonical link, the Open Graph and Twitter
 * card properties, and — on game pages only — one `schema.org/VideoGame`
 * JSON-LD block. Nothing in this class depends on a third-party plugin being
 * installed, and nothing outside it writes to `<head>`.
 *
 * Three rules shape the output:
 *
 * 1. **Absent means omitted.** A field IGDB never sent produces no tag and no
 *    JSON key — never an empty `content=""` or `"description": ""` (AC-047f).
 *    The store row is the only source; a description is a summary excerpt or
 *    it does not exist.
 * 2. **Exactly one canonical.** Core's `rel_canonical()` prints one for every
 *    singular post, so a game page would carry two once this class prints its
 *    own. {@see link_canonical()} therefore removes core's callback *after*
 *    writing the plugin's — a surface this class returns early on keeps core's
 *    untouched.
 * 3. **Indexing follows access, not markup.** The `noindex` set is exactly the
 *    four member-facing routes (AC-048b). A members-only profile gets no
 *    robots signal at all, because the crawler that rule would govern is
 *    logged out and receives the shared 404 instead (AC-048c, DD-014).
 *
 * The sitemap provider ({@see GameLib_Members_Sitemap_Provider}) reads member
 * visibility at render time rather than from a stored list, which is what makes
 * the AC-029(b) exclusion immediate: a member who flips to members-only leaves
 * the sitemap on the next request, because {@see GameLib_Visibility::set()}
 * bumps the same `visibility` generation this class caches its query under.
 */
final class GameLib_SEO {

	/**
	 * Sitemap provider name — the `{name}` in `/wp-sitemap-{name}-{page}.xml`.
	 *
	 * Core's rewrite rule for a provider page is
	 * `^wp-sitemap-([a-z]+?)-(\d+?)\.xml$`, so the name is restricted to
	 * lowercase letters: a hyphenated `gamelib-members` would register fine and
	 * then 404 on its own URL.
	 *
	 * @var string
	 */
	const SITEMAP_PROVIDER = 'members';

	/**
	 * Sitemap object type, in core's `post`/`term`/`user` vocabulary.
	 *
	 * @var string
	 */
	const SITEMAP_OBJECT_TYPE = 'user';

	/**
	 * Upper bound on the member sitemap: the last page this provider serves, and
	 * the last one a visibility flip will purge (VIP-7, VIP-1).
	 *
	 * A safety valve, not a policy: at core's default 2,000 URLs per user
	 * sitemap page this site's whole public membership fits on one page, so the
	 * bound is never reached in practice.
	 *
	 * It bounds the *serving* side as well as the purge, because the two have to
	 * agree. `get_max_num_pages()` used to report the true count while only the
	 * purge was clamped, so core would happily serve page 51 — a page whose
	 * offset is 100,000 rows into a `wp_users` × `wp_usermeta` join, and one no
	 * flip would ever purge.
	 *
	 * @var int
	 */
	const SITEMAP_PURGE_MAX_PAGES = 50;

	/**
	 * Length of a meta description, in words (AC-047b: "summary excerpt").
	 *
	 * @var int
	 */
	const DESCRIPTION_WORDS = 30;

	/**
	 * Open Graph type of a game page.
	 *
	 * @var string
	 */
	const OG_TYPE_GAME = 'article';

	/**
	 * Open Graph type of a member profile — a standard OG type, and the one
	 * that describes the page honestly.
	 *
	 * @var string
	 */
	const OG_TYPE_PROFILE = 'profile';

	/**
	 * The AC-048(b) set: the four member-facing routes that must never be
	 * indexed.
	 *
	 * `/members/{nicename}/` is deliberately absent — a public profile is
	 * indexable (AC-048a) and a members-only one 404s to every crawler
	 * (AC-048c), so neither state needs a robots signal.
	 *
	 * @var string[]
	 */
	const NOINDEX_ROUTES = array(
		GameLib_Router::ROUTE_MY_LIBRARY,
		GameLib_Router::ROUTE_IMPORT_REVIEW,
		GameLib_Router::ROUTE_ACTIVITY,
		GameLib_Router::ROUTE_JOIN,
	);

	/**
	 * Store row for the game being rendered, resolved once per request.
	 *
	 * `false` means "not looked up yet"; `null` means "looked up, no row".
	 *
	 * @var array<string, mixed>|null|false
	 */
	private static $game = false;

	/**
	 * Supply the document title for every plugin surface (AC-047a).
	 *
	 * The filter is the single source: `templates/parts/document-open.php`
	 * prints `wp_get_document_title()` for a classic theme and core prints it
	 * for a block theme, so both paths read whatever is returned here.
	 *
	 * A game page's title comes from the store row rather than from
	 * `post_title` — the row is the authority for every rendered field
	 * (AC-033a), and the projection's title merely mirrors it.
	 *
	 * @param array<string, string> $parts Title parts: `title`, `page`, `tagline`, `site`.
	 * @return array<string, string> Filtered parts.
	 */
	public static function filter_document_title_parts( $parts ) {
		if ( ! is_array( $parts ) || ! did_action( 'wp' ) ) {
			return $parts;
		}

		$title = self::document_title();

		if ( '' !== $title ) {
			$parts['title'] = $title;
		}

		return $parts;
	}

	/**
	 * Apply `noindex` to the four member-facing routes (AC-048b).
	 *
	 * `/join/` is covered twice — {@see GameLib_Registration::prepare()} adds
	 * the same core callback for its own reasons — and that is deliberate: both
	 * set `noindex` to true, so the route stays unindexable even if either
	 * caller stops running.
	 *
	 * @param array<string, bool|string> $robots Robots directives.
	 * @return array<string, bool|string> Filtered directives.
	 */
	public static function filter_robots( $robots ) {
		if ( ! is_array( $robots ) || ! in_array( GameLib_Router::route(), self::NOINDEX_ROUTES, true ) ) {
			return $robots;
		}

		return wp_robots_no_robots( $robots );
	}

	/**
	 * Print the head block for a public plugin surface (AC-047 b–f).
	 *
	 * Runs at `wp_head` priority 5 — before core's `rel_canonical()` at 10, so
	 * the removal in {@see link_canonical()} lands before the duplicate would
	 * have printed.
	 *
	 * Surfaces: a `glib_game` singular, and `/members/{nicename}/` for a member
	 * who opted public. A members-only profile prints nothing here, matching
	 * AC-050(d)'s treatment of the copy-link: sharing and indexing signals
	 * belong to public surfaces only, and the crawler that would read them is
	 * served a 404 anyway (AC-048c).
	 *
	 * @return void
	 */
	public static function render_head() {
		// Before the context check on purpose: `/my-library/` renders the most
		// covers of any surface and has no head context of its own.
		self::link_preconnect();

		$context = self::context();

		if ( null === $context ) {
			return;
		}

		self::link_canonical( $context['url'] );

		if ( '' !== $context['description'] ) {
			self::meta_name( 'description', $context['description'] );
		}

		self::meta_property( 'og:title', $context['title'] );

		if ( '' !== $context['description'] ) {
			self::meta_property( 'og:description', $context['description'] );
		}

		self::meta_property( 'og:type', $context['og_type'] );
		self::meta_property_url( 'og:url', $context['url'] );

		if ( '' !== $context['image'] ) {
			self::meta_property_url( 'og:image', $context['image'] );
		}

		// AC-047(e): the large card is only honest when there is an image to
		// put in it.
		self::meta_name( 'twitter:card', ( '' !== $context['image'] ) ? 'summary_large_image' : 'summary' );

		if ( is_array( $context['game'] ) ) {
			self::render_json_ld( $context['game'], $context['url'] );
		}
	}

	/**
	 * Register the public-member sitemap provider (AC-049b).
	 *
	 * Hooked to `init` at priority 20, after core's own
	 * `wp_sitemaps_get_server()` at 10 has created the registry the provider is
	 * added to. The game CPT needs no wiring of its own: core's posts provider
	 * lists every post type registered `public => true`, which `glib_game` is
	 * (AC-049a — verified against `wp-sitemap.xml` on the shared Playground,
	 * which lists `wp-sitemap-posts-glib_game-1.xml` with no filter added).
	 *
	 * Two registration functions, because core renamed this one: WP 7.0 ships
	 * `wp_register_sitemap_provider()` and no `wp_sitemaps_add_provider()` — not
	 * even a deprecated shim (measured on the shared Playground, WP 7.0.4). The
	 * older name is kept as the fallback so the plugin registers on 5.5–6.x too.
	 * See principal/adr/018-sitemap-provider-registration.md.
	 *
	 * @return void
	 */
	public static function register_sitemap_provider() {
		$provider = new GameLib_Members_Sitemap_Provider();

		if ( function_exists( 'wp_register_sitemap_provider' ) ) {
			wp_register_sitemap_provider( self::SITEMAP_PROVIDER, $provider );

			return;
		}

		if ( function_exists( 'wp_sitemaps_add_provider' ) ) {
			wp_sitemaps_add_provider( self::SITEMAP_PROVIDER, $provider );
		}
	}

	/**
	 * Profile URLs of the opted-in public members on one sitemap page.
	 *
	 * @param int $page 1-based page number.
	 * @return string[] Absolute profile URLs.
	 */
	public static function public_member_urls( $page ) {
		return self::public_members( $page );
	}

	/**
	 * Every cacheable sitemap URL that can name a public member (VIP-7).
	 *
	 * The generation bump in {@see GameLib_Visibility::set()} drops this
	 * plugin's *object*-cached member query, so the next render of a sitemap is
	 * already correct. The rendered XML documents are separate cacheable URLs,
	 * though, and on VIP they sit at the edge under their own TTL — so a crawler
	 * fetching `/wp-sitemap.xml` or `/wp-sitemap-members-1.xml` inside that TTL
	 * would still be handed a withdrawn profile, and AC-029(b) would not be
	 * literally met. This is the list of URLs that has to be purged with the
	 * profile.
	 *
	 * The index is included because a flip can change the *set* of provider
	 * pages, not just their contents: the last public member on the site leaves
	 * and `get_max_num_pages()` drops to 0, at which point the members entry
	 * disappears from the index entirely.
	 *
	 * Named pages, not a range (VIP-5). One flip issued the index plus up to 50
	 * provider pages — 51 `wpcom_vip_purge_edge_cache_for_url()` calls inside a
	 * member-triggered REST request, and VIP throttles purge volume, so the
	 * AC-029(b) guarantee the loop existed to provide was the first thing lost
	 * under load. The caller names the one page the member appears on
	 * ({@see member_sitemap_page()}), which is the only page whose cached copy
	 * can still list a withdrawn profile; later pages shift by one entry, and
	 * every URL on them is still a public profile.
	 *
	 * @param int[] $pages Provider page numbers to purge alongside the index.
	 * @return string[] Absolute sitemap URLs, index first. Empty before WP 5.5.1.
	 */
	public static function sitemap_urls( array $pages = array() ) {
		// Core renamed `wp_sitemaps_get_sitemap_url()` to this in WP 7.0; the
		// plugin supports 6.5+, where only the old name may exist (ADR-018).
		if ( ! function_exists( 'get_sitemap_url' ) ) {
			return array();
		}

		$urls  = array();
		$index = get_sitemap_url( 'index' );

		if ( is_string( $index ) && '' !== $index ) {
			$urls[] = $index;
		}

		$wanted = array();

		foreach ( $pages as $page ) {
			$page = absint( $page );

			if ( $page > 0 && $page <= self::SITEMAP_PURGE_MAX_PAGES ) {
				$wanted[ $page ] = true;
			}
		}

		foreach ( array_keys( $wanted ) as $page ) {
			$url = get_sitemap_url( self::SITEMAP_PROVIDER, '', $page );

			if ( is_string( $url ) && '' !== $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Number of sitemap pages the public members fill.
	 *
	 * Zero when no member has opted in — core reads this as "this provider
	 * contributes no sitemap", so the index carries no empty member entry.
	 *
	 * @return int Page count.
	 */
	public static function public_member_pages() {
		$total = self::public_member_total();

		if ( $total < 1 ) {
			return 0;
		}

		return (int) ceil( $total / self::sitemap_page_size() );
	}

	/**
	 * How many members have opted their profile public (VIP-1).
	 *
	 * Its own indexed `COUNT(*)` over `wp_usermeta`, cached under the
	 * `visibility` generation, rather than `WP_User_Query`'s `count_total`.
	 * That flag makes core issue `SQL_CALC_FOUND_ROWS` over a
	 * `wp_users` × `wp_usermeta` join paginated by OFFSET — a full scan of the
	 * result set on a query whose page of ids is all this provider wanted.
	 *
	 * @return int Public member count.
	 */
	public static function public_member_total() {
		$key   = 'sitemap-members-total';
		$found = false;

		$cached = GameLib_Cache::get( GameLib_Cache::SCOPE_VISIBILITY, $key, $found );

		if ( $found && is_numeric( $cached ) ) {
			return (int) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The opt-in flag lives in user meta (§6 Data Model) and core exposes no counting API for it that avoids SQL_CALC_FOUND_ROWS; one index range on meta_key, and the result is cached below under the `visibility` generation.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
				GameLib_Visibility::META_KEY,
				GameLib_Visibility::PUBLIC_PROFILE
			)
		);

		$total = ( null === $total ) ? 0 : max( 0, (int) $total );

		GameLib_Cache::set( GameLib_Cache::SCOPE_VISIBILITY, $key, $total );

		return $total;
	}

	/**
	 * The sitemap page a member occupies, or would occupy (VIP-1, VIP-5).
	 *
	 * Derived from how many public members sort *below* them — the provider
	 * orders by user id ascending — so the answer does not depend on whether the
	 * member is public at the moment it is asked. That is what lets
	 * {@see GameLib_Visibility::set()} read it once, before the write, and use it
	 * for both directions of a flip: the page a member is leaving and the page
	 * they are arriving on are the same page.
	 *
	 * A site whose whole public membership fits one page — which
	 * {@see SITEMAP_PURGE_MAX_PAGES} records as every realistic site here — needs
	 * no query at all: page 1 is the only answer available (PB-4). The cached
	 * total answers that case, and callers on the write path read it *before*
	 * bumping `SCOPE_VISIBILITY`, so it is a hit. The bound is strict because the
	 * total is read *before* the flip it describes: a member about to become
	 * public is not in it yet, so `total === per` could still put them on page 2.
	 *
	 * @param int $user_id Member id.
	 * @return int 1-based page number, or 0 for an unusable id.
	 */
	public static function member_sitemap_page( $user_id ) {
		$user_id = absint( $user_id );

		if ( $user_id < 1 ) {
			return 0;
		}

		if ( self::public_member_total() < self::sitemap_page_size() ) {
			return 1;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write-path read: the member's own position, taken once per visibility flip to decide which sitemap page to purge. Caching it would outlive the flip it describes.
		$below = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s AND user_id < %d",
				GameLib_Visibility::META_KEY,
				GameLib_Visibility::PUBLIC_PROFILE,
				$user_id
			)
		);

		$below = ( null === $below ) ? 0 : max( 0, (int) $below );

		// Rank is $below + 1, so the page is floor( below / per ) + 1.
		return (int) floor( $below / self::sitemap_page_size() ) + 1;
	}

	/**
	 * One page of opted-in public members, cached under the `visibility` scope.
	 *
	 * The generation counter is what makes AC-029(b) immediate: flipping a
	 * member to members-only bumps `visibility` in the same request as the meta
	 * write, so this entry becomes unreachable and the very next sitemap render
	 * re-queries without the member.
	 *
	 * User objects are primed in one query before the URLs are composed, so a
	 * 2,000-member page costs one user query rather than one per member
	 * (WPP-05); every URL still goes through
	 * {@see GameLib_Visibility::profile_url()}, the plugin's single composer for
	 * that path.
	 *
	 * The count this provider also needs is a separate, indexed query
	 * ({@see public_member_total()}): `count_total` here would make core run
	 * `SQL_CALC_FOUND_ROWS` over the join for every page read (VIP-1).
	 *
	 * @param int $page 1-based page number.
	 * @return string[] Page URLs.
	 */
	private static function public_members( $page ) {
		$page     = max( 1, absint( $page ) );
		$per_page = self::sitemap_page_size();
		$key      = 'sitemap-members:' . $page . ':' . $per_page;
		$found    = false;

		$cached = GameLib_Cache::get( GameLib_Cache::SCOPE_VISIBILITY, $key, $found );

		if ( $found && is_array( $cached ) ) {
			return $cached;
		}

		$query = new WP_User_Query(
			array(
				'meta_key'    => GameLib_Visibility::META_KEY,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The opt-in flag lives in user meta (§6 Data Model), so there is no column to select on; the query is paginated, bounded by the sitemap page size, and cached below under the `visibility` generation.
				'meta_value'  => GameLib_Visibility::PUBLIC_PROFILE,
				'fields'      => 'ID',
				'number'      => $per_page,
				'offset'      => ( $page - 1 ) * $per_page,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				/*
				 * VIP-1: `true` here is `SQL_CALC_FOUND_ROWS` over a
				 * `wp_users` × `wp_usermeta` join that the OFFSET has already
				 * paginated — a scan of the whole matching set to learn a number
				 * `public_member_total()` answers with one indexed COUNT.
				 */
				'count_total' => false,
			)
		);

		$ids = array_values( array_filter( array_map( 'absint', (array) $query->get_results() ) ) );

		if ( ! empty( $ids ) ) {
			cache_users( $ids );
		}

		$urls = array();

		foreach ( $ids as $id ) {
			$url = GameLib_Visibility::profile_url( $id );

			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		GameLib_Cache::set( GameLib_Cache::SCOPE_VISIBILITY, $key, $urls );

		return $urls;
	}

	/**
	 * URLs per sitemap page, as core's own providers compute it.
	 *
	 * @return int Page size, never below 1.
	 */
	private static function sitemap_page_size() {
		$per_page = (int) wp_sitemaps_get_max_urls( self::SITEMAP_PROVIDER );

		return ( $per_page > 0 ) ? $per_page : 1;
	}

	/**
	 * The title this request's surface should carry.
	 *
	 * @return string Title, or '' to leave core's value alone.
	 */
	private static function document_title() {
		if ( is_singular( GameLib_Game_CPT::POST_TYPE ) ) {
			$game = self::queried_game();

			return ( is_array( $game ) && '' !== $game['name'] ) ? (string) $game['name'] : '';
		}

		switch ( GameLib_Router::route() ) {
			case GameLib_Router::ROUTE_MY_LIBRARY:
				return __( 'My library', 'game-library' );

			case GameLib_Router::ROUTE_IMPORT_REVIEW:
				return __( 'Import review', 'game-library' );

			case GameLib_Router::ROUTE_ACTIVITY:
				return __( 'Activity', 'game-library' );

			case GameLib_Router::ROUTE_JOIN:
				return __( 'Join', 'game-library' );

			case GameLib_Router::ROUTE_PROFILE:
				$member = GameLib_Router::member();

				return ( $member instanceof WP_User ) ? (string) $member->display_name : '';
		}

		return '';
	}

	/**
	 * Everything the head block needs for this request, or null off-surface.
	 *
	 * @return array{title: string, description: string, url: string, image: string, og_type: string, game: array<string, mixed>|null}|null Context.
	 */
	private static function context() {
		$game = self::game_context();

		return ( null === $game ) ? self::profile_context() : $game;
	}

	/**
	 * Head context for a game page.
	 *
	 * @return array{title: string, description: string, url: string, image: string, og_type: string, game: array<string, mixed>|null}|null Context, or null when this is not a game page.
	 */
	private static function game_context() {
		if ( ! did_action( 'wp' ) || ! is_singular( GameLib_Game_CPT::POST_TYPE ) ) {
			return null;
		}

		$post_id = (int) get_queried_object_id();

		if ( $post_id < 1 ) {
			return null;
		}

		$game = self::queried_game();
		$row  = is_array( $game ) ? $game : null;

		$name = ( null !== $row && '' !== $row['name'] )
			? (string) $row['name']
			: (string) get_the_title( $post_id );

		return array(
			'title'       => $name,
			'description' => ( null !== $row ) ? self::excerpt( (string) $row['summary'] ) : '',
			// AC-047(c): the permalink, never the requested URL — a query
			// variant such as `?utm_source=x` canonicalizes to this.
			'url'         => (string) get_permalink( $post_id ),
			'image'       => ( null !== $row ) ? GameLib_Game_Store::cover_url( $row['cover_image_id'] ) : '',
			'og_type'     => self::OG_TYPE_GAME,
			'game'        => $row,
		);
	}

	/**
	 * Head context for an opted-in public member profile.
	 *
	 * @return array{title: string, description: string, url: string, image: string, og_type: string, game: array<string, mixed>|null}|null Context, or null off-route / members-only.
	 */
	private static function profile_context() {
		if ( GameLib_Router::ROUTE_PROFILE !== GameLib_Router::route() ) {
			return null;
		}

		$member = GameLib_Router::member();

		if ( ! $member instanceof WP_User ) {
			return null;
		}

		$owner_id = (int) $member->ID;

		if ( ! GameLib_Visibility::is_public( $owner_id ) ) {
			return null;
		}

		$name = (string) $member->display_name;

		return array(
			'title'       => $name,
			'description' => sprintf(
				/* translators: %s: member display name. */
				__( '%s\'s game library', 'game-library' ),
				$name
			),
			'url'         => GameLib_Visibility::profile_url( $owner_id ),
			// No cover, no avatar: a profile card carries no image, so its
			// Twitter card is the small `summary` variant (AC-047e).
			'image'       => '',
			'og_type'     => self::OG_TYPE_PROFILE,
			'game'        => null,
		);
	}

	/**
	 * The store row behind the game page being rendered, looked up once.
	 *
	 * @return array<string, mixed>|null Store row, or null when there is none.
	 */
	private static function queried_game() {
		if ( false !== self::$game ) {
			return self::$game;
		}

		$post_id = (int) get_queried_object_id();
		$igdb_id = ( $post_id > 0 ) ? GameLib_Game_CPT::igdb_id_for_post( $post_id ) : 0;

		self::$game = ( $igdb_id > 0 ) ? GameLib_Game_Store::get( $igdb_id ) : null;

		return self::$game;
	}

	/**
	 * Warm the connection to IGDB's image CDN (PF-3).
	 *
	 * Every cover the plugin renders comes from `images.igdb.com`, a cross-origin
	 * host the browser has never seen when the document starts parsing — so DNS +
	 * TCP + TLS (200–400ms on mobile) lands directly on LCP, whose element *is*
	 * one of those covers on the grid surfaces.
	 *
	 * `crossorigin` is mandatory, not decorative: images are fetched in anonymous
	 * CORS mode, and a preconnect without it opens a socket the image request
	 * cannot reuse.
	 *
	 * Exactly one preconnect ships, and only on surfaces that actually render a
	 * cover — a speculative connection to a host the page never uses costs the
	 * same handshake it was meant to save.
	 *
	 * @return void
	 */
	private static function link_preconnect() {
		if ( ! self::renders_covers() ) {
			return;
		}

		printf(
			'<link rel="preconnect" href="%s" crossorigin />' . "\n",
			esc_url( GameLib_Game_Store::IMAGE_ORIGIN )
		);
	}

	/**
	 * Whether this request paints IGDB cover art.
	 *
	 * The three grid/hero surfaces: a game page (theme-rendered), and the
	 * plugin's own `/my-library/` and `/members/{nicename}/` documents. The feed
	 * and `/join/` render no images.
	 *
	 * @return bool True when a cover will be rendered.
	 */
	private static function renders_covers() {
		if ( did_action( 'wp' ) && is_singular( GameLib_Game_CPT::POST_TYPE ) ) {
			return true;
		}

		$route = GameLib_Router::route();

		return GameLib_Router::ROUTE_MY_LIBRARY === $route || GameLib_Router::ROUTE_PROFILE === $route;
	}

	/**
	 * Print the canonical link and stand down core's duplicate.
	 *
	 * @param string $url Clean base URL of the surface.
	 * @return void
	 */
	private static function link_canonical( $url ) {
		if ( '' === $url ) {
			return;
		}

		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );

		/*
		 * Core prints its own `<link rel="canonical">` for every singular post
		 * at `wp_head` priority 10. Removed only now, on a surface that just
		 * printed the plugin's — removing it unconditionally would leave a page
		 * this class declines to describe with no canonical at all.
		 */
		remove_action( 'wp_head', 'rel_canonical' );
	}

	/**
	 * The `schema.org/VideoGame` block for a game page (AC-047f).
	 *
	 * Only `name` and `url` are unconditional. Every other key is written only
	 * when the store row actually carries the field, so a sparse game emits a
	 * short, valid object rather than one padded with empty strings.
	 *
	 * @param array<string, mixed> $game Store row.
	 * @param string               $url  Canonical page URL.
	 * @return void
	 */
	private static function render_json_ld( array $game, $url ) {
		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'VideoGame',
			'name'     => (string) $game['name'],
			'url'      => $url,
		);

		$summary = wp_strip_all_tags( (string) $game['summary'], true );

		if ( '' !== $summary ) {
			$data['description'] = $summary;
		}

		$image = GameLib_Game_Store::cover_url( $game['cover_image_id'] );

		if ( '' !== $image ) {
			$data['image'] = $image;
		}

		if ( (int) $game['first_release_date'] > 0 ) {
			$data['datePublished'] = gmdate( 'Y-m-d', (int) $game['first_release_date'] );
		}

		if ( ! empty( $game['genres'] ) ) {
			$data['genre'] = array_values( (array) $game['genres'] );
		}

		if ( ! empty( $game['platforms'] ) ) {
			$data['gamePlatform'] = array_values( (array) $game['platforms'] );
		}

		if ( null !== $game['total_rating'] && (int) $game['total_rating_count'] > 0 ) {
			// IGDB rates out of 100, so the scale has to be stated: schema.org
			// assumes 1–5 when `bestRating` is absent.
			$data['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => round( (float) $game['total_rating'], 2 ),
				'ratingCount' => (int) $game['total_rating_count'],
				'worstRating' => 0,
				'bestRating'  => 100,
			);
		}

		$json = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			return;
		}

		/*
		 * `JSON_HEX_TAG | JSON_HEX_AMP` leaves no `<`, `>`, or `&` anywhere in
		 * the document, so the string cannot close the script element or open a
		 * new one. HTML-escaping it instead would corrupt the JSON that is the
		 * whole point of the block (Never Do #14).
		 */
		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See above: wp_json_encode() with JSON_HEX_TAG|JSON_HEX_AMP is the escaping for this context.
	}

	/**
	 * A summary trimmed to description length, or '' when there is no summary.
	 *
	 * @param string $text Raw summary from the store row.
	 * @return string Excerpt, or '' when the source field is absent.
	 */
	private static function excerpt( $text ) {
		$text = wp_strip_all_tags( (string) $text, true );

		if ( '' === $text ) {
			return '';
		}

		return wp_trim_words( $text, self::DESCRIPTION_WORDS, '…' );
	}

	/**
	 * Print a `name`/`content` meta tag.
	 *
	 * @param string $name    Meta name.
	 * @param string $content Meta content.
	 * @return void
	 */
	private static function meta_name( $name, $content ) {
		printf( '<meta name="%1$s" content="%2$s" />' . "\n", esc_attr( $name ), esc_attr( $content ) );
	}

	/**
	 * Print a `property`/`content` meta tag.
	 *
	 * @param string $property Open Graph property.
	 * @param string $content  Property content.
	 * @return void
	 */
	private static function meta_property( $property, $content ) {
		printf( '<meta property="%1$s" content="%2$s" />' . "\n", esc_attr( $property ), esc_attr( $content ) );
	}

	/**
	 * Print a `property`/`content` meta tag whose content is a URL.
	 *
	 * @param string $property Open Graph property.
	 * @param string $url      Absolute URL.
	 * @return void
	 */
	private static function meta_property_url( $property, $url ) {
		printf( '<meta property="%1$s" content="%2$s" />' . "\n", esc_attr( $property ), esc_url( $url ) );
	}
}

/**
 * Core sitemap provider listing the opted-in public member profiles (AC-049b).
 *
 * A thin adapter: core asks for a page of URLs and a page count, and both come
 * from {@see GameLib_SEO}, where the query, its cache scope, and the page size
 * live. Visibility is read at render time — there is no stored list of public
 * members to keep in sync, which is exactly why AC-029(b) needs no invalidation
 * step of its own.
 */
final class GameLib_Members_Sitemap_Provider extends WP_Sitemaps_Provider {

	/**
	 * Name the provider and the object type it lists.
	 */
	public function __construct() {
		$this->name        = GameLib_SEO::SITEMAP_PROVIDER;
		$this->object_type = GameLib_SEO::SITEMAP_OBJECT_TYPE;
	}

	/**
	 * One page of public member profile URLs.
	 *
	 * @param int    $page_num       1-based page of results.
	 * @param string $object_subtype Unused: members have no subtypes.
	 * @return array<int, array{loc: string}> Sitemap entries.
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$entries = array();

		foreach ( GameLib_SEO::public_member_urls( $page_num ) as $url ) {
			$entries[] = array( 'loc' => $url );
		}

		return $entries;
	}

	/**
	 * How many pages the public members fill.
	 *
	 * Clamped to the same ceiling the purge honours (VIP-1): an uncapped count
	 * here would have core serve page 51 and beyond, whose OFFSET is 100,000
	 * rows into a joined user query, and whose cached copy no visibility flip
	 * would ever purge.
	 *
	 * @param string $object_subtype Unused: members have no subtypes.
	 * @return int Page count; 0 when no member has opted in.
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		return min( GameLib_SEO::public_member_pages(), GameLib_SEO::SITEMAP_PURGE_MAX_PAGES );
	}
}
