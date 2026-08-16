<?php
/**
 * Route-scoped asset enqueue and the REST/i18n JS payload.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the plugin's CSS + JS only on its own eight routes.
 */
class Assets {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue tokens + component CSS and the two scripts on plugin routes only.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( '' === (string) get_query_var( 'gl_route' ) ) {
			return;
		}

		wp_enqueue_style( 'gl-tokens', GL_URL . 'assets/css/tokens.css', array(), GL_VERSION );
		wp_enqueue_style( 'gl-components', GL_URL . 'assets/css/game-library.css', array( 'gl-tokens' ), GL_VERSION );

		wp_enqueue_script( 'gl-library', GL_URL . 'assets/js/library.js', array(), GL_VERSION, true );
		wp_enqueue_script( 'gl-search', GL_URL . 'assets/js/search.js', array( 'gl-library' ), GL_VERSION, true );

		wp_localize_script( 'gl-library', 'GameLibraryData', $this->payload() );
	}

	/**
	 * The localized payload shared by both scripts.
	 *
	 * @return array<string, mixed>
	 */
	private function payload() {
		return array(
			'restRoot'     => esc_url_raw( rest_url( Rest_Controller::NAMESPACE . '/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'currentUser'  => get_current_user_id(),
			'statusLabels' => array(
				'playing'  => __( 'Playing', 'game-library-3' ),
				'finished' => __( 'Finished', 'game-library-3' ),
				'backlog'  => __( 'Backlog', 'game-library-3' ),
				'wishlist' => __( 'Wishlist', 'game-library-3' ),
			),
			'i18n'         => array(
				'follow'        => __( 'Follow', 'game-library-3' ),
				'following'     => __( 'Following', 'game-library-3' ),
				'noResults'     => __( 'No games found.', 'game-library-3' ),
				'unavailable'   => __( 'Search is unavailable right now. Please try again.', 'game-library-3' ),
				'searching'     => __( 'Searching…', 'game-library-3' ),
				'genericError'  => __( 'Something went wrong. Please try again.', 'game-library-3' ),
				'duplicate'     => __( 'That game is already in your library.', 'game-library-3' ),
				'copied'        => __( 'Copied!', 'game-library-3' ),
				'copyFailed'    => __( 'Copy failed — the link is selected; press Ctrl/Cmd+C.', 'game-library-3' ),
				'add'           => __( 'Add', 'game-library-3' ),
				'adding'        => __( 'Adding…', 'game-library-3' ),
				'added'         => __( 'Added', 'game-library-3' ),
				'revoked'       => __( 'Revoked', 'game-library-3' ),
				'publicLabel'   => __( 'Public library', 'game-library-3' ),
				'privateLabel'  => __( 'Private library', 'game-library-3' ),
				'chooseStatus'  => __( 'Choose a status…', 'game-library-3' ),
				'removeConfirm' => __( 'Remove this game from your library?', 'game-library-3' ),
			),
		);
	}
}
