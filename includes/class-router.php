<?php
/**
 * Rewrite rules, query vars, template takeover and per-route auth gating.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the eight front-end routes.
 */
class Router {

	/**
	 * The eight route slugs.
	 */
	const ROUTES = array( 'my-library', 'member-library', 'activity', 'members', 'invites', 'join', 'games', 'game-single' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_rules' ), 10 );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_template_redirect' ) );
	}

	/**
	 * Register the plugin's rewrite rules (also called during activation).
	 *
	 * @return void
	 */
	public function register_rules() {
		add_rewrite_rule( '^my-library/?$', 'index.php?gl_route=my-library', 'top' );
		add_rewrite_rule( '^library/([^/]+)/?$', 'index.php?gl_route=member-library&gl_member=$matches[1]', 'top' );
		add_rewrite_rule( '^activity/?$', 'index.php?gl_route=activity', 'top' );
		add_rewrite_rule( '^members/?$', 'index.php?gl_route=members', 'top' );
		add_rewrite_rule( '^invites/?$', 'index.php?gl_route=invites', 'top' );
		add_rewrite_rule( '^join/([^/]+)/?$', 'index.php?gl_route=join&gl_code=$matches[1]', 'top' );
		add_rewrite_rule( '^join/?$', 'index.php?gl_route=join', 'top' );
		add_rewrite_rule( '^games/([^/]+)/?$', 'index.php?gl_route=game-single&gl_game=$matches[1]', 'top' );
		add_rewrite_rule( '^games/?$', 'index.php?gl_route=games', 'top' );
	}

	/**
	 * Register the plugin's query vars.
	 *
	 * @param array<int, string> $vars Existing vars.
	 * @return array<int, string>
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'gl_route';
		$vars[] = 'gl_member';
		$vars[] = 'gl_code';
		$vars[] = 'gl_game';
		$vars[] = 'gl_status';
		$vars[] = 'gl_audience';
		$vars[] = 'gl_page';
		return $vars;
	}

	/**
	 * Take over rendering for a matched plugin route.
	 *
	 * @return void
	 */
	public function handle_template_redirect() {
		$route = sanitize_key( (string) get_query_var( 'gl_route' ) );
		if ( '' === $route || ! in_array( $route, self::ROUTES, true ) ) {
			return;
		}

		$context = array( 'route' => $route );

		switch ( $route ) {
			case 'my-library':
			case 'invites':
			case 'members':
				if ( ! $this->require_login() ) {
					return; // redirected.
				}
				break;

			case 'member-library':
				$nicename = sanitize_title( (string) get_query_var( 'gl_member' ) );
				$owner    = $nicename ? get_user_by( 'slug', $nicename ) : false;
				if ( ! $owner ) {
					$this->send_404();
					return;
				}
				$context['owner'] = $owner;
				break;

			case 'game-single':
				$slug = sanitize_title( (string) get_query_var( 'gl_game' ) );
				$game = $slug ? Plugin::instance()->games()->get_by_slug( $slug ) : null;
				if ( ! $game ) {
					$this->send_404();
					return;
				}
				$context['game'] = $game;
				break;

			case 'join':
				$code              = $this->clean_code( (string) get_query_var( 'gl_code' ) );
				$invite            = '' !== $code ? Plugin::instance()->invites()->get_by_code( $code ) : null;
				$context['code']   = $code;
				$context['invite'] = $invite;
				$result            = Plugin::instance()->registration()->process_join( $code, $invite );
				if ( null !== $result ) {
					$context['join_result'] = $result;
				}
				break;
		}

		global $wp_query;
		status_header( 200 );
		$wp_query->is_404 = false;

		Plugin::instance()->templates()->render( $route, $context );
		exit;
	}

	/**
	 * Require a logged-in member; otherwise redirect to the login screen.
	 *
	 * @return bool True when logged in.
	 */
	private function require_login() {
		if ( is_user_logged_in() ) {
			return true;
		}
		wp_safe_redirect( wp_login_url( $this->current_url() ), 302 );
		exit;
	}

	/**
	 * Emit a normal WordPress 404 for this request.
	 *
	 * @return void
	 */
	private function send_404() {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * The fully-qualified URL of the current request.
	 *
	 * @return string
	 */
	private function current_url() {
		$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return home_url( $request );
	}

	/**
	 * Normalize an invite code from the URL to the code alphabet + dashes.
	 *
	 * @param string $raw Raw code.
	 * @return string
	 */
	private function clean_code( $raw ) {
		$raw = strtoupper( $raw );
		return preg_replace( '/[^A-Z0-9-]/', '', $raw );
	}
}
