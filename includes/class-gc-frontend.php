<?php
/**
 * Front-end routing, templates, and assets.
 *
 * Routes:
 *   /my-library/        the logged-in member's own library
 *   /library/{member}/  another member's library (member = user_nicename)
 *   /activity/          feed of the member's own actions + everyone they follow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Frontend {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
	}

	public static function register_rewrites() {
		add_rewrite_rule( '^my-library/?$', 'index.php?gc_page=my-library', 'top' );
		add_rewrite_rule( '^library/([^/]+)/?$', 'index.php?gc_page=library&gc_member=$matches[1]', 'top' );
		add_rewrite_rule( '^activity/?$', 'index.php?gc_page=activity', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'gc_page';
		$vars[] = 'gc_member';
		return $vars;
	}

	public static function register_assets() {
		wp_register_style( 'gc-frontend', GC_PLUGIN_URL . 'assets/css/gc.css', array(), GC_VERSION );
		wp_register_script( 'gc-frontend', GC_PLUGIN_URL . 'assets/js/gc.js', array(), GC_VERSION, true );

		wp_localize_script(
			'gc-frontend',
			'gcConfig',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'gc/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'statuses' => GC_Library::status_labels(),
				'i18n'     => array(
					'add'          => __( 'Add', 'game-collector' ),
					'inLibrary'    => __( 'In library', 'game-collector' ),
					'searching'    => __( 'Searching…', 'game-collector' ),
					'noResults'    => __( 'No games found.', 'game-collector' ),
					'error'        => __( 'Something went wrong. Please try again.', 'game-collector' ),
					'confirmRemove' => __( 'Remove this game from your library?', 'game-collector' ),
					'follow'       => __( 'Follow', 'game-collector' ),
					'unfollow'     => __( 'Unfollow', 'game-collector' ),
					'added'        => __( 'Added!', 'game-collector' ),
				),
			)
		);
	}

	/* ---- URLs ---- */

	public static function my_library_url() {
		return home_url( '/my-library/' );
	}

	public static function library_url( $user ) {
		if ( is_numeric( $user ) ) {
			$user = get_userdata( $user );
		}
		if ( ! $user ) {
			return '';
		}
		return home_url( '/library/' . rawurlencode( $user->user_nicename ) . '/' );
	}

	public static function activity_url() {
		return home_url( '/activity/' );
	}

	/* ---- Rendering ---- */

	public static function document_title( $parts ) {
		$page = get_query_var( 'gc_page' );

		if ( 'my-library' === $page ) {
			$parts['title'] = __( 'My Library', 'game-collector' );
		} elseif ( 'activity' === $page ) {
			$parts['title'] = __( 'Activity', 'game-collector' );
		} elseif ( 'library' === $page ) {
			$member = get_user_by( 'slug', get_query_var( 'gc_member' ) );
			if ( $member ) {
				/* translators: %s: member display name */
				$parts['title'] = sprintf( __( '%s’s Library', 'game-collector' ), $member->display_name );
			}
		}

		return $parts;
	}

	public static function maybe_render() {
		$page = get_query_var( 'gc_page' );

		if ( ! $page ) {
			return;
		}

		// The whole community is invite-only: every page requires login.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( add_query_arg( array() ) ) ) );
			exit;
		}

		status_header( 200 );

		wp_enqueue_style( 'gc-frontend' );
		wp_enqueue_script( 'gc-frontend' );

		switch ( $page ) {
			case 'my-library':
				self::render_library( wp_get_current_user(), true );
				break;

			case 'library':
				$member = get_user_by( 'slug', get_query_var( 'gc_member' ) );

				if ( ! $member ) {
					self::render_not_found();
					break;
				}

				if ( get_current_user_id() === (int) $member->ID ) {
					wp_safe_redirect( self::my_library_url() );
					exit;
				}

				self::render_library( $member, false );
				break;

			case 'activity':
				self::render_template( 'activity', array( 'viewer' => wp_get_current_user() ) );
				break;

			default:
				return;
		}

		exit;
	}

	private static function render_library( $member, $is_own ) {
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		if ( ! GC_Library::is_valid_status( $status_filter ) ) {
			$status_filter = '';
		}

		self::render_template(
			'library',
			array(
				'member'        => $member,
				'is_own'        => $is_own,
				'status_filter' => $status_filter,
				'entries'       => GC_Library::get_user_library( $member->ID, $status_filter ? $status_filter : null ),
				'counts'        => GC_Library::get_counts( $member->ID ),
			)
		);
	}

	private static function render_not_found() {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );

		get_header();
		echo '<div class="gc-wrap"><p>' . esc_html__( 'Member not found.', 'game-collector' ) . '</p></div>';
		get_footer();
	}

	/**
	 * Render a plugin template inside the theme's header/footer.
	 *
	 * Themes can override by placing game-collector/{name}.php in the theme dir.
	 */
	private static function render_template( $name, $vars = array() ) {
		$template = locate_template( 'game-collector/' . $name . '.php' );
		if ( ! $template ) {
			$template = GC_PLUGIN_DIR . 'templates/' . $name . '.php';
		}

		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract

		get_header();
		include $template;
		get_footer();
	}
}
