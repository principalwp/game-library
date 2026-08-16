<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GCOLLECTOR_Frontend {
	public function __construct() {
		add_shortcode( 'game_collector_library', array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post || ! has_shortcode( $post->post_content, 'game_collector_library' ) ) {
			return;
		}

		wp_enqueue_style(
			'gcollector-app',
			GCOLLECTOR_URL . 'assets/game-collector.css',
			array(),
			GCOLLECTOR_VERSION
		);

		if ( ! is_user_logged_in() ) {
			return;
		}

		wp_enqueue_script(
			'gcollector-app',
			GCOLLECTOR_URL . 'assets/game-collector.js',
			array(),
			GCOLLECTOR_VERSION,
			true
		);

		$requested_user = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$config         = array(
			'root'        => esc_url_raw( rest_url( GCOLLECTOR_REST::NAMESPACE ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'currentUser' => get_current_user_id(),
			'initialUser' => $requested_user ?: get_current_user_id(),
			'libraryUrl'  => get_permalink( get_option( 'gcollector_library_page_id' ) ),
			'statuses'    => array(
				'playing'  => __( 'Playing', 'game-collector' ),
				'finished' => __( 'Finished', 'game-collector' ),
				'backlog'  => __( 'Backlog', 'game-collector' ),
				'wishlist' => __( 'Wishlist', 'game-collector' ),
			),
		);
		wp_add_inline_script( 'gcollector-app', 'window.GameCollector = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	public function shortcode() {
		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<div class="gc-login"><h2>%1$s</h2><p>%2$s</p><a class="gc-button" href="%3$s">%4$s</a></div>',
				esc_html__( 'Your collection awaits', 'game-collector' ),
				esc_html__( 'Sign in with your invited account to view the collector community.', 'game-collector' ),
				esc_url( wp_login_url( get_permalink() ) ),
				esc_html__( 'Sign in', 'game-collector' )
			);
		}

		ob_start();
		?>
		<div id="game-collector-app" class="gc-app">
			<header class="gc-app__header">
				<div><span class="gc-eyebrow"><?php esc_html_e( 'Game Collector', 'game-collector' ); ?></span><h1><?php esc_html_e( 'Your games, in good company.', 'game-collector' ); ?></h1></div>
				<nav class="gc-nav" aria-label="<?php esc_attr_e( 'Collector navigation', 'game-collector' ); ?>">
					<button class="is-active" data-gc-view="library"><?php esc_html_e( 'Library', 'game-collector' ); ?></button>
					<button data-gc-view="search"><?php esc_html_e( 'Add games', 'game-collector' ); ?></button>
					<button data-gc-view="feed"><?php esc_html_e( 'Activity', 'game-collector' ); ?></button>
					<button data-gc-view="members"><?php esc_html_e( 'Collectors', 'game-collector' ); ?></button>
				</nav>
			</header>

			<div class="gc-notice" role="status" aria-live="polite" hidden></div>

			<section class="gc-panel is-active" data-gc-panel="library">
				<div id="gc-profile"></div>
				<div class="gc-toolbar">
					<label for="gc-library-status"><?php esc_html_e( 'Show', 'game-collector' ); ?></label>
					<select id="gc-library-status"><option value=""><?php esc_html_e( 'All games', 'game-collector' ); ?></option></select>
				</div>
				<div id="gc-library" class="gc-grid"><div class="gc-loading"><?php esc_html_e( 'Loading library…', 'game-collector' ); ?></div></div>
			</section>

			<section class="gc-panel" data-gc-panel="search" hidden>
				<div class="gc-section-heading"><div><span class="gc-eyebrow"><?php esc_html_e( 'Powered by IGDB', 'game-collector' ); ?></span><h2><?php esc_html_e( 'Find your next game', 'game-collector' ); ?></h2></div></div>
				<form id="gc-search-form" class="gc-search">
					<label class="screen-reader-text" for="gc-game-query"><?php esc_html_e( 'Search games', 'game-collector' ); ?></label>
					<input id="gc-game-query" type="search" minlength="2" required placeholder="<?php esc_attr_e( 'Search by game title…', 'game-collector' ); ?>">
					<button class="gc-button" type="submit"><?php esc_html_e( 'Search', 'game-collector' ); ?></button>
				</form>
				<div id="gc-search-results" class="gc-grid"></div>
			</section>

			<section class="gc-panel" data-gc-panel="feed" hidden>
				<div class="gc-section-heading"><div><span class="gc-eyebrow"><?php esc_html_e( 'Following', 'game-collector' ); ?></span><h2><?php esc_html_e( 'Community activity', 'game-collector' ); ?></h2></div><button id="gc-refresh-feed" class="gc-text-button"><?php esc_html_e( 'Refresh', 'game-collector' ); ?></button></div>
				<div id="gc-feed" class="gc-feed"><div class="gc-loading"><?php esc_html_e( 'Loading activity…', 'game-collector' ); ?></div></div>
			</section>

			<section class="gc-panel" data-gc-panel="members" hidden>
				<div class="gc-section-heading"><div><span class="gc-eyebrow"><?php esc_html_e( 'Invite-only community', 'game-collector' ); ?></span><h2><?php esc_html_e( 'Meet other collectors', 'game-collector' ); ?></h2></div></div>
				<form id="gc-member-search" class="gc-search">
					<label class="screen-reader-text" for="gc-member-query"><?php esc_html_e( 'Search collectors', 'game-collector' ); ?></label>
					<input id="gc-member-query" type="search" placeholder="<?php esc_attr_e( 'Search collectors…', 'game-collector' ); ?>">
					<button class="gc-button" type="submit"><?php esc_html_e( 'Search', 'game-collector' ); ?></button>
				</form>
				<div id="gc-members" class="gc-members"><div class="gc-loading"><?php esc_html_e( 'Loading collectors…', 'game-collector' ); ?></div></div>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}
}
