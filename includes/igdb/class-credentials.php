<?php
/**
 * The single accessor for IGDB/Twitch credential material.
 *
 * @package Game_Library
 */

namespace Game_Library\Igdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Credentials.
 *
 * Resolves the Twitch/IGDB `client_id` and `client_secret` from a defined PHP
 * constant first and an environment variable second (AC-006), and exposes
 * only `is_configured()`, `client_id()`, and `client_secret()` — it never
 * writes either value anywhere (not an option, not user meta, not a
 * transient) and never logs one. `Client` and every other class in the
 * plugin obtain credentials exclusively through this class; no other file
 * may name `GAME_LIBRARY_IGDB_CLIENT_ID`, `GAME_LIBRARY_IGDB_CLIENT_SECRET`,
 * call `vip_get_env_var()`, `getenv()`, or read `$_ENV` for a credential.
 */
final class Credentials {

	/**
	 * Name of the PHP constant / environment variable carrying the Twitch
	 * app client id.
	 *
	 * @var string
	 */
	private const CLIENT_ID_NAME = 'GAME_LIBRARY_IGDB_CLIENT_ID';

	/**
	 * Name of the PHP constant / environment variable carrying the Twitch
	 * app client secret.
	 *
	 * @var string
	 */
	private const CLIENT_SECRET_NAME = 'GAME_LIBRARY_IGDB_CLIENT_SECRET';

	/**
	 * Whether both credentials resolve to a non-empty value.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->client_id() && '' !== $this->client_secret();
	}

	/**
	 * The Twitch app client id.
	 *
	 * @return string Empty string when unresolved.
	 */
	public function client_id() {
		return $this->resolve( self::CLIENT_ID_NAME );
	}

	/**
	 * The Twitch app client secret.
	 *
	 * @return string Empty string when unresolved.
	 */
	public function client_secret() {
		return $this->resolve( self::CLIENT_SECRET_NAME );
	}

	/**
	 * Resolves one credential: a defined constant first, an environment
	 * variable second.
	 *
	 * @param string $name Constant / environment variable name.
	 * @return string Empty string when neither source yields a value.
	 */
	private function resolve( $name ) {
		if ( defined( $name ) ) {
			$value = constant( $name );

			return is_string( $value ) ? $value : '';
		}

		// function_exists() guard (VIP-3): this resolves unconditionally on
		// VIP. Off VIP without the deployment pipeline's vip-polyfill.php
		// bundled yet, the un-guarded call fataled with "Call to undefined
		// function" — reached from Settings_Page::render_credentials_notice(),
		// hooked to admin_notices and therefore evaluated on every wp-admin
		// page load, so this was a total admin outage rather than a
		// contained failure. Degrading to "unresolved" (matching the
		// already-nullable return contract every caller handles) is
		// correct here; authoring the polyfill itself is the pipeline's
		// job, not this class's.
		$value = function_exists( 'vip_get_env_var' ) ? vip_get_env_var( $name ) : false;

		return is_string( $value ) ? $value : '';
	}
}
