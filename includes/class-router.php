<?php
/**
 * Front-end router: rewrite routes rendered as virtual pages inside theme chrome.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the four pretty routes and renders each one's plugin-owned body into
 * a virtual page that flows through the active theme's normal template pipeline
 * (Open Question 2, option a) so the theme header/footer and its colour/type
 * tokens wrap the plugin content on block and classic themes alike.
 */
final class Game_Library_Router {

	const ROUTES = array( 'my-library', 'library', 'activity', 'join' );

	/**
	 * Set when an unknown nicename should 404.
	 *
	 * @var bool
	 */
	private $force_404 = false;

	/**
	 * The resolved user for the /library/{nicename}/ route.
	 *
	 * @var WP_User|null
	 */
	private $route_user = null;

	/**
	 * Register front-end hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'the_posts', array( $this, 'inject_virtual_page' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_or_404' ), 1 );
		add_filter( 'redirect_canonical', array( $this, 'disable_canonical_on_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the rewrite rules. Static so activation can call it directly.
	 *
	 * @return void
	 */
	public static function register_rewrite_rules() {
		add_rewrite_rule( '^my-library/?$', 'index.php?game_library_route=my-library', 'top' );
		add_rewrite_rule( '^library/([^/]+)/?$', 'index.php?game_library_route=library&game_library_user=$matches[1]', 'top' );
		add_rewrite_rule( '^game-activity/page/([0-9]+)/?$', 'index.php?game_library_route=activity&game_library_page=$matches[1]', 'top' );
		add_rewrite_rule( '^game-activity/?$', 'index.php?game_library_route=activity', 'top' );
		add_rewrite_rule( '^join/([^/]+)/?$', 'index.php?game_library_route=join&game_library_code=$matches[1]', 'top' );
	}

	/**
	 * Register the plugin's query vars.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'game_library_route';
		$vars[] = 'game_library_user';
		$vars[] = 'game_library_code';
		$vars[] = 'game_library_page';
		return $vars;
	}

	/**
	 * The current plugin route, or '' when this isn't one of ours.
	 *
	 * @return string
	 */
	public static function current_route() {
		$route = get_query_var( 'game_library_route' );
		return in_array( $route, self::ROUTES, true ) ? $route : '';
	}

	/**
	 * Inject a virtual page for our routes so the theme renders our content.
	 *
	 * @param array    $posts Posts from the main query.
	 * @param WP_Query $query The query.
	 * @return array
	 */
	public function inject_virtual_page( $posts, $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return $posts;
		}
		$route = $query->get( 'game_library_route' );
		if ( ! in_array( $route, self::ROUTES, true ) ) {
			return $posts;
		}

		// Logged-out /my-library/ is handled by a redirect in maybe_redirect_or_404().
		if ( 'my-library' === $route && ! is_user_logged_in() ) {
			return $posts;
		}

		// Resolve the target member for the read-only route; unknown => 404.
		if ( 'library' === $route ) {
			$user = get_user_by( 'slug', (string) $query->get( 'game_library_user' ) );
			if ( ! $user ) {
				$this->force_404 = true;
				return $posts;
			}
			$this->route_user = $user;
		}

		$rendered = $this->render_route_body( $route, $query );

		$post = new WP_Post(
			(object) array(
				'ID'                    => 0,
				'post_author'           => 0,
				'post_date'             => current_time( 'mysql' ),
				'post_date_gmt'         => current_time( 'mysql', true ),
				'post_content'          => $rendered['content'],
				'post_title'            => $rendered['title'],
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'game-library-' . $route,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => current_time( 'mysql' ),
				'post_modified_gmt'     => current_time( 'mysql', true ),
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => home_url( '/?game_library_route=' . $route ),
				'menu_order'            => 0,
				'post_type'             => 'page',
				'post_mime_type'        => '',
				'comment_count'         => 0,
				'filter'                => 'raw',
			)
		);

		$query->is_home           = false;
		$query->is_page           = true;
		$query->is_singular       = true;
		$query->is_archive        = false;
		$query->is_category       = false;
		$query->is_tag            = false;
		$query->is_404            = false;
		$query->is_paged          = false;
		$query->queried_object    = $post;
		$query->queried_object_id = 0;
		$query->post              = $post;
		$query->posts             = array( $post );
		$query->post_count        = 1;
		$query->found_posts       = 1;
		$query->max_num_pages     = 1;

		// Keep our block-level markup intact through the_content.
		remove_filter( 'the_content', 'wpautop' );

		return array( $post );
	}

	/**
	 * On template_redirect: redirect logged-out /my-library/ to login, or 404 an
	 * unknown nicename.
	 *
	 * @return void
	 */
	public function maybe_redirect_or_404() {
		$route = get_query_var( 'game_library_route' );
		if ( ! in_array( $route, self::ROUTES, true ) ) {
			return;
		}

		if ( 'my-library' === $route && ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/my-library/' ) ) );
			exit;
		}

		if ( $this->force_404 ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Prevent WordPress's canonical redirect from interfering with virtual pages.
	 *
	 * @param string|false $redirect_url Proposed canonical URL.
	 * @return string|false
	 */
	public function disable_canonical_on_routes( $redirect_url ) {
		return '' !== self::current_route() ? false : $redirect_url;
	}

	/**
	 * Enqueue the plugin CSS (all routes) and JS (interactive routes).
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$route = self::current_route();
		if ( '' === $route ) {
			return;
		}

		wp_enqueue_style(
			'game-library',
			GAME_LIBRARY_URL . 'assets/css/game-library.css',
			array(),
			GAME_LIBRARY_VERSION
		);

		if ( in_array( $route, array( 'my-library', 'library' ), true ) ) {
			wp_enqueue_script(
				'game-library',
				GAME_LIBRARY_URL . 'assets/js/game-library.js',
				array(),
				GAME_LIBRARY_VERSION,
				true
			);
			// No credential or token is ever exposed here — only a REST nonce.
			wp_localize_script(
				'game-library',
				'gameLibraryData',
				array(
					'restUrl'       => esc_url_raw( rest_url( GAME_LIBRARY_REST_NAMESPACE . '/' ) ),
					'nonce'         => wp_create_nonce( 'wp_rest' ),
					'currentUserId' => get_current_user_id(),
					'statuses'      => Game_Library_Library_Service::STATUSES,
					'i18n'          => array(
						'searching'         => __( 'Searching…', 'game-library' ),
						'searchUnavailable' => __( 'Search is unavailable right now. Please try again shortly.', 'game-library' ),
						'noGamesFound'      => __( 'No games found. Try a different title.', 'game-library' ),
						'add'               => __( 'Add', 'game-library' ),
						'added'             => __( 'Added', 'game-library' ),
						'remove'            => __( 'Remove', 'game-library' ),
						'confirmRemove'     => __( 'Remove this game from your library?', 'game-library' ),
						'follow'            => __( 'Follow', 'game-library' ),
						'unfollow'          => __( 'Unfollow', 'game-library' ),
						'genericError'      => __( 'Something went wrong. Please try again.', 'game-library' ),
					),
				)
			);
		}
	}

	/**
	 * Render one route's body markup and title.
	 *
	 * @param string   $route The route slug.
	 * @param WP_Query $query The main query.
	 * @return array{title:string,content:string}
	 */
	private function render_route_body( $route, $query ) {
		switch ( $route ) {
			case 'my-library':
				return $this->render_my_library();
			case 'library':
				return $this->render_library_view( $this->route_user );
			case 'activity':
				return $this->render_activity( max( 1, absint( $query->get( 'game_library_page' ) ) ) );
			case 'join':
			default:
				return $this->render_join( (string) $query->get( 'game_library_code' ) );
		}
	}

	/**
	 * Render the authenticated /my-library/ page.
	 *
	 * @return array{title:string,content:string}
	 */
	private function render_my_library() {
		$user    = wp_get_current_user();
		$service = new Game_Library_Library_Service();
		$invites = new Game_Library_Invite_Service();

		$following_feed = Game_Library_Activity::following_feed_for( $user->ID );
		// Prime the user cache once so the per-item get_userdata()/library_url()
		// calls in the feed loop don't each fire a wp_users query.
		self::prime_feed_user_cache( $following_feed );

		$context = array(
			'user'             => $user,
			'entries'          => $service->get_entries_for_user( $user->ID ),
			'following_feed'   => $following_feed,
			'invite_used'      => $invites->count_for_member( $user->ID ),
			'invite_allowance' => (int) get_option( Game_Library_Activator::OPTION_INVITE_ALLOWANCE, Game_Library_Activator::DEFAULT_INVITE_ALLOWANCE ),
		);

		return array(
			'title'   => __( 'My Library', 'game-library' ),
			'content' => $this->render_template( 'my-library.php', $context ),
		);
	}

	/**
	 * Render the read-only /library/{nicename}/ page.
	 *
	 * @param WP_User $target The member whose library is shown.
	 * @return array{title:string,content:string}
	 */
	private function render_library_view( $target ) {
		$service = new Game_Library_Library_Service();
		$viewer  = get_current_user_id();
		$follow  = new Game_Library_Follow_Controller();

		$context = array(
			'target'       => $target,
			'entries'      => $service->get_entries_for_user( $target->ID ),
			'can_follow'   => ( $viewer > 0 && $viewer !== (int) $target->ID ),
			'is_following' => $viewer > 0 ? $follow->is_following( $viewer, $target->ID ) : false,
		);

		return array(
			/* translators: %s: member display name. */
			'title'   => sprintf( __( '%s’s Library', 'game-library' ), $target->display_name ),
			'content' => $this->render_template( 'library-view.php', $context ),
		);
	}

	/**
	 * Render the public /game-activity/ feed.
	 *
	 * @param int $page 1-based page number.
	 * @return array{title:string,content:string}
	 */
	private function render_activity( $page ) {
		$feed = Game_Library_Activity::sitewide_feed( $page );
		// Prime the user cache once so the per-item get_userdata()/library_url()
		// calls in the feed loop don't each fire a wp_users query.
		self::prime_feed_user_cache( $feed['items'] );

		$context = array(
			'items'    => $feed['items'],
			'has_more' => $feed['has_more'],
			'page'     => $page,
		);

		return array(
			'title'   => __( 'Game Activity', 'game-library' ),
			'content' => $this->render_template( 'activity.php', $context ),
		);
	}

	/**
	 * Render the /join/{code} registration page.
	 *
	 * @param string $code Invite code from the URL.
	 * @return array{title:string,content:string}
	 */
	private function render_join( $code ) {
		$invites = new Game_Library_Invite_Service();
		$token   = isset( $_GET['gl_join'] ) ? sanitize_text_field( wp_unslash( $_GET['gl_join'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only PRG token for surfacing prior errors.
		$state   = Game_Library_Registration::consume_errors( $token );

		$context = array(
			'code'   => sanitize_text_field( $code ),
			'valid'  => $invites->is_valid_unredeemed( $code ),
			'errors' => $state['errors'],
			'input'  => $state['input'],
		);

		return array(
			'title'   => __( 'Join the Game Library', 'game-library' ),
			'content' => $this->render_template( 'join.php', $context ),
		);
	}

	/**
	 * Buffer a template file into a string. The $context array is in scope.
	 *
	 * @param string $template Template filename under templates/.
	 * @param array  $context  Data for the template.
	 * @return string
	 */
	private function render_template( $template, array $context ) {
		ob_start();
		// $context is read by the included template's inherited scope.
		include GAME_LIBRARY_PATH . 'templates/' . $template;
		unset( $context );
		return (string) ob_get_clean();
	}

	// Shared presentation helpers (used by the templates) follow.

	/**
	 * Prime the WordPress user cache for a batch of feed events so the per-row
	 * get_userdata()/library_url() lookups in the render loop resolve from cache
	 * instead of each firing its own SELECT against wp_users (the feed N+1).
	 *
	 * @param array $events Activity rows (each with actor_id, and target_user_id on follows).
	 * @return void
	 */
	private static function prime_feed_user_cache( $events ) {
		$user_ids = array();
		foreach ( (array) $events as $event ) {
			if ( ! empty( $event->actor_id ) ) {
				$user_ids[] = (int) $event->actor_id;
			}
			if ( isset( $event->event_type ) && 'follow' === $event->event_type && ! empty( $event->target_user_id ) ) {
				$user_ids[] = (int) $event->target_user_id;
			}
		}
		if ( ! empty( $user_ids ) ) {
			cache_users( array_unique( $user_ids ) );
		}
	}

	/**
	 * Human-readable label for a status slug.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'playing'  => __( 'Playing', 'game-library' ),
			'finished' => __( 'Finished', 'game-library' ),
			'backlog'  => __( 'Backlog', 'game-library' ),
			'wishlist' => __( 'Wishlist', 'game-library' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( (string) $status );
	}

	/**
	 * URL of a member's read-only library.
	 *
	 * @param WP_User|int $user User or id.
	 * @return string
	 */
	public static function library_url( $user ) {
		if ( is_numeric( $user ) ) {
			$user = get_user_by( 'id', (int) $user );
		}
		if ( ! $user ) {
			return home_url( '/game-activity/' );
		}
		return home_url( '/library/' . rawurlencode( $user->user_nicename ) . '/' );
	}

	/**
	 * Render a library card (cover-forward). Controls are only emitted when editable.
	 *
	 * @param array $entry    Entry row.
	 * @param bool  $editable Whether to render mutate controls.
	 * @param int   $index    0-based position in the grid; the first row is eager-loaded
	 *                        because a cover is the plugin's likely LCP element.
	 * @return string
	 */
	public static function render_card( array $entry, $editable, $index = 0 ) {
		$status      = isset( $entry['status'] ) ? (string) $entry['status'] : 'backlog';
		$name        = isset( $entry['game_name'] ) ? (string) $entry['game_name'] : '';
		$cover       = isset( $entry['cover_url'] ) ? (string) $entry['cover_url'] : '';
		$entry_id    = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
		$igdb_id     = isset( $entry['igdb_id'] ) ? (int) $entry['igdb_id'] : 0;
		$placeholder = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
		$img_src     = '' !== $cover ? $cover : $placeholder;
		// First row (~4 cards) sits above the fold: eager-load + high priority so the
		// LCP cover fetch isn't deferred; the rest of the grid stays lazy.
		$eager = (int) $index < 4;

		ob_start();
		?>
		<article class="gl-card" data-entry-id="<?php echo esc_attr( (string) $entry_id ); ?>" data-igdb-id="<?php echo esc_attr( (string) $igdb_id ); ?>">
			<div class="gl-card__cover">
				<img class="gl-card__img" src="<?php echo esc_url( $img_src ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="<?php echo esc_attr( $eager ? 'eager' : 'lazy' ); ?>" decoding="async"
				<?php
				if ( $eager ) {
					echo ' fetchpriority="high"';
				}
				?>
				/>
			</div>
			<div class="gl-card__body">
				<h3 class="gl-card__title"><?php echo esc_html( $name ); ?></h3>
				<span class="gl-badge gl-badge--<?php echo esc_attr( $status ); ?>" data-role="status-badge">
					<?php echo esc_html( self::status_label( $status ) ); ?>
				</span>
				<?php if ( $editable ) : ?>
					<div class="gl-card__controls">
						<label class="screen-reader-text" for="gl-status-<?php echo esc_attr( (string) $entry_id ); ?>">
							<?php esc_html_e( 'Change status', 'game-library' ); ?>
						</label>
						<select class="gl-status-control" id="gl-status-<?php echo esc_attr( (string) $entry_id ); ?>" data-role="status-control">
							<?php foreach ( Game_Library_Library_Service::STATUSES as $slug ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $status, $slug ); ?>>
									<?php echo esc_html( self::status_label( $slug ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="gl-button gl-button--danger" data-role="remove-entry">
							<?php esc_html_e( 'Remove', 'game-library' ); ?>
						</button>
					</div>
				<?php endif; ?>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render one activity feed item as a discrete component.
	 *
	 * @param object $event Activity row.
	 * @return string
	 */
	public static function render_feed_item( $event ) {
		$actor      = get_userdata( (int) $event->actor_id );
		$actor_name = $actor ? $actor->display_name : __( 'A member', 'game-library' );
		$actor_url  = $actor ? self::library_url( $actor ) : '';
		// created_at is stored in the site's *local* time (current_time('mysql')),
		// so convert it to GMT before diffing against time() (a real UTC epoch);
		// otherwise every relative timestamp is off by the site's UTC offset.
		$when_ts = strtotime( get_gmt_from_date( (string) $event->created_at ) . ' UTC' );
		if ( ! $when_ts ) {
			$when_ts = time();
		}
		/* translators: %s: human-readable time difference, e.g. "5 mins". */
		$relative = sprintf( __( '%s ago', 'game-library' ), human_time_diff( $when_ts, time() ) );

		$verb   = '';
		$object = '';
		switch ( $event->event_type ) {
			case 'added':
				$verb   = __( 'added', 'game-library' );
				$object = (string) $event->game_name;
				break;
			case 'status_change':
				$verb = sprintf(
					/* translators: %s: status label such as Playing. */
					__( 'set the status to %s for', 'game-library' ),
					self::status_label( (string) $event->status )
				);
				$object = (string) $event->game_name;
				break;
			case 'follow':
				$verb   = __( 'followed', 'game-library' );
				$target = get_userdata( (int) $event->target_user_id );
				$object = $target ? $target->display_name : __( 'a member', 'game-library' );
				break;
		}

		ob_start();
		?>
		<article class="gl-feed-item" data-event-type="<?php echo esc_attr( (string) $event->event_type ); ?>">
			<span class="gl-feed-item__actor">
				<?php if ( $actor_url ) : ?>
					<a href="<?php echo esc_url( $actor_url ); ?>"><?php echo esc_html( $actor_name ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $actor_name ); ?>
				<?php endif; ?>
			</span>
			<span class="gl-feed-item__verb"><?php echo esc_html( $verb ); ?></span>
			<span class="gl-feed-item__object"><?php echo esc_html( $object ); ?></span>
			<time class="gl-feed-item__time" datetime="<?php echo esc_attr( gmdate( 'c', $when_ts ) ); ?>">
				<?php echo esc_html( $relative ); ?>
			</time>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a worded empty-state block.
	 *
	 * @param string $message The empty-state message.
	 * @return string
	 */
	public static function render_empty_state( $message ) {
		return '<div class="gl-empty-state"><p>' . esc_html( $message ) . '</p></div>';
	}
}
