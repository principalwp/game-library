<?php
/**
 * Shared permission callbacks and error->HTTP status mapping for every REST
 * controller.
 *
 * @package Game_Library
 */

namespace Game_Library\Rest;

use WP_Error;
use WP_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class REST_Controller.
 *
 * Base class every concrete controller (`Search_Controller`,
 * `Library_Controller`, and later `Social_Controller`/`Invite_Controller`)
 * extends. Holds the one `game-library/v1` namespace constant, the
 * `rest_api_init` wiring convention (`register_hooks()`), the two
 * capability/login permission-callback helpers every controller reuses, and
 * `map_error()` — the single place that turns an IGDB-taxonomy `WP_Error`
 * (from `Igdb\Client`) into a `WP_Error` carrying the HTTP status this
 * plugin's REST surface commits to.
 *
 * Nonce handling: none of the permission callbacks in this class or its
 * subclasses verify `X-WP-Nonce` themselves. WordPress core's own
 * `rest_cookie_check_errors()` (hooked to `rest_authentication_errors`,
 * `wp-includes/rest-api.php`) already runs during REST dispatch, strictly
 * before any route's `permission_callback` — for a cookie-authenticated
 * request it requires and verifies the nonce itself (missing nonce ->
 * treated as logged out; invalid nonce -> `rest_cookie_invalid_nonce`, 403),
 * and for a request authenticated any other way (Basic Auth, application
 * passwords — the scheme this project's own `tests/e2e/specs/rest-api.spec.ts`
 * uses) it does not require one at all, because `$wp_rest_auth_cookie` never
 * gets set to `true` for those requests. `Assets::localize_scripts()`
 * already wires `wp.apiFetch.createNonceMiddleware()` for the plugin's own
 * cookie-authenticated front-end scripts, so core's automatic check is the
 * only nonce enforcement this REST surface needs; a second, manual
 * `wp_verify_nonce()` inside a permission callback would only add a
 * spurious failure mode for non-cookie callers. See the Task 11 coder
 * decision log for the full reasoning.
 */
abstract class REST_Controller extends WP_REST_Controller {

	/**
	 * The plugin's one REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'game-library/v1';

	/**
	 * `WP_Error` code => HTTP status for the errors `Igdb\Client` and this
	 * plugin's own refresh cooldown can produce. Every other error code
	 * (invalid input, a database failure) is left to `WP_REST_Server`'s own
	 * default of 500 — see `map_error()`.
	 *
	 * @var array<string,int>
	 */
	private const ERROR_STATUS_MAP = array(
		'gl_igdb_rate_limited'   => 429,
		'gl_refresh_cooldown'    => 429,
		'gl_igdb_unavailable'    => 503,
		'gl_igdb_not_configured' => 503,
	);

	/**
	 * Registers this controller's `register_routes()` on `rest_api_init`.
	 * Called from `Plugin`'s constructor for every concrete controller
	 * (ADR-007).
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 10 );
	}

	/**
	 * Whether the current request is logged in — the permission callback for
	 * every route with no capability requirement beyond authentication
	 * (AC-033 (f)-(h)).
	 *
	 * @return true|WP_Error True when logged in, a 401 WP_Error otherwise.
	 */
	protected function require_login() {
		if ( is_user_logged_in() ) {
			return true;
		}

		return new WP_Error(
			'gl_rest_unauthorized',
			__( 'You must be logged in to do that.', 'game-library' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Whether the current user is logged in and holds a capability — the
	 * permission callback for every member write route. Reuses core's own
	 * `rest_authorization_required_code()` so a logged-out caller gets 401
	 * and a logged-in caller lacking the capability gets 403, matching the
	 * AC-033/this task's constraint's status split without duplicating that
	 * logic per controller.
	 *
	 * @param string $capability Capability to check.
	 * @return true|WP_Error
	 */
	protected function require_capability( $capability ) {
		if ( current_user_can( $capability ) ) {
			return true;
		}

		return new WP_Error(
			'gl_rest_forbidden',
			__( 'You are not allowed to do that.', 'game-library' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Builds a 403 WP_Error for an ownership failure that is not a simple
	 * missing-capability case (e.g. the refresh route's "you must hold this
	 * game or be able to moderate" gate).
	 *
	 * @param string $message Translated, user-facing message.
	 * @return WP_Error
	 */
	protected function forbidden( $message ) {
		return new WP_Error( 'gl_rest_forbidden', $message, array( 'status' => 403 ) );
	}

	/**
	 * Builds a 404 WP_Error for a missing entry, member, or game.
	 *
	 * @param string $message Translated, user-facing message.
	 * @return WP_Error
	 */
	protected function not_found( $message ) {
		return new WP_Error( 'gl_rest_not_found', $message, array( 'status' => 404 ) );
	}

	/**
	 * Maps an IGDB-taxonomy (or refresh-cooldown) `WP_Error` onto the HTTP
	 * status this plugin's REST surface commits to for that code — 429 for
	 * `gl_igdb_rate_limited`/`gl_refresh_cooldown`, 503 for
	 * `gl_igdb_unavailable`/`gl_igdb_not_configured`. Any other error code is
	 * returned unchanged; `WP_REST_Server` falls back to 500 for a `WP_Error`
	 * with no `status` in its data, which is the correct outcome for an
	 * unexpected validation/database failure.
	 *
	 * @param WP_Error $error Error to map.
	 * @return WP_Error
	 */
	protected function map_error( WP_Error $error ) {
		$code = $error->get_error_code();

		if ( ! isset( self::ERROR_STATUS_MAP[ $code ] ) ) {
			return $error;
		}

		$data = $error->get_error_data( $code );
		$data = is_array( $data ) ? $data : array();
		$data['status'] = self::ERROR_STATUS_MAP[ $code ];

		return new WP_Error( $code, $error->get_error_message(), $data );
	}
}
