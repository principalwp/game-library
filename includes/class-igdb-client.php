<?php
/**
 * IGDB / Twitch client: credential resolution, token exchange, and search.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to Twitch (client-credentials token exchange) and IGDB (Apicalypse
 * search) server-side only. Credentials and the bearer token never leave this
 * class — no caller ever receives them.
 */
final class Game_Library_IGDB_Client {

	const TWITCH_TOKEN_URL = 'https://id.twitch.tv/oauth2/token';
	const IGDB_SEARCH_URL  = 'https://api.igdb.com/v4/games';
	const HTTP_TIMEOUT     = 10;
	const SEARCH_TTL       = 900; // 15 minutes (NFR-002).
	const TOKEN_OPTION     = 'game_library_igdb_token';
	const ERROR_TRANSIENT  = 'game_library_last_igdb_error';

	/**
	 * No hooks of its own; wired via callers.
	 *
	 * @return void
	 */
	public function hooks() {}

	/**
	 * Whether both a Client ID and a Client Secret are available (constant or stored).
	 *
	 * @return bool
	 */
	public function credentials_configured() {
		return '' !== $this->client_id() && null !== $this->client_secret();
	}

	/**
	 * The most recent recorded IGDB failure, for the admin notice.
	 *
	 * @return array{message:string,time:int}|null
	 */
	public function last_error() {
		$error = get_transient( self::ERROR_TRANSIENT );
		return is_array( $error ) ? $error : null;
	}

	/**
	 * Clear the recorded failure.
	 *
	 * @return void
	 */
	public function clear_error() {
		delete_transient( self::ERROR_TRANSIENT );
	}

	/**
	 * Search IGDB for games matching a title query.
	 *
	 * @param string $query Raw member query.
	 * @return array<int,array{igdb_id:int,name:string,cover_url:string}>|WP_Error
	 */
	public function search( $query ) {
		$query = trim( wp_strip_all_tags( (string) $query ) );
		if ( '' === $query ) {
			return array();
		}

		$cache_key = 'game_library_search_' . md5( strtolower( $query ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$apicalypse = sprintf(
			'search "%s"; fields name,cover.image_id; where cover != null; limit %d;',
			$this->escape_apicalypse( $query ),
			Game_Library_Activity::PAGE_SIZE
		);

		$response = wp_remote_post(
			self::IGDB_SEARCH_URL,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					'Client-ID'     => $this->client_id(),
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'text/plain',
				),
				'body'    => $apicalypse,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->fail( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return $this->fail(
				sprintf(
					/* translators: %d: HTTP status code returned by IGDB. */
					__( 'IGDB search returned HTTP %d.', 'game-library' ),
					$code
				)
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return $this->fail( __( 'IGDB returned an unreadable response.', 'game-library' ) );
		}

		$results = array();
		foreach ( $decoded as $game ) {
			if ( empty( $game['id'] ) || empty( $game['name'] ) ) {
				continue;
			}
			$results[] = array(
				'igdb_id'   => absint( $game['id'] ),
				'name'      => sanitize_text_field( $game['name'] ),
				'cover_url' => $this->cover_url_from_image_id(
					isset( $game['cover']['image_id'] ) ? $game['cover']['image_id'] : ''
				),
			);
		}

		set_transient( $cache_key, $results, self::SEARCH_TTL );
		$this->clear_error();

		return $results;
	}

	/**
	 * Return a valid access token, refreshing via Twitch when the cache is expired.
	 *
	 * @return string|WP_Error
	 */
	public function get_access_token() {
		$cached = get_option( self::TOKEN_OPTION, array() );
		if ( is_array( $cached )
			&& ! empty( $cached['token'] )
			&& ! empty( $cached['expires'] )
			&& (int) $cached['expires'] > time()
		) {
			$token = $this->decode_cached_token( $cached );
			if ( '' !== $token ) {
				return $token;
			}
		}

		return $this->exchange_token();
	}

	/**
	 * Recover the plaintext bearer token from a cache entry, decrypting when sealed.
	 *
	 * @param array<string,mixed> $cached Stored token cache entry.
	 * @return string Plaintext token, or '' when it can't be recovered (forces a refresh).
	 */
	private function decode_cached_token( array $cached ) {
		$stored = (string) $cached['token'];

		// Legacy/unsupported-runtime entries were stored in the clear.
		if ( empty( $cached['encrypted'] ) ) {
			return $stored;
		}

		$plaintext = Game_Library_Secret_Store::open( $stored );

		return null === $plaintext ? '' : (string) $plaintext;
	}

	/**
	 * Perform the Twitch client-credentials exchange and cache the token + expiry.
	 *
	 * @return string|WP_Error
	 */
	public function exchange_token() {
		$client_id     = $this->client_id();
		$client_secret = $this->client_secret();

		if ( '' === $client_id || null === $client_secret ) {
			return $this->fail( __( 'IGDB credentials are not configured.', 'game-library' ) );
		}

		$response = wp_remote_post(
			self::TWITCH_TOKEN_URL,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'grant_type'    => 'client_credentials',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->fail( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
			return $this->fail( __( 'Twitch token exchange failed — check the Client ID and Secret.', 'game-library' ) );
		}

		$expires_in = isset( $body['expires_in'] ) ? absint( $body['expires_in'] ) : 3600;
		// Refresh a minute early rather than keying off a hardcoded lifetime.
		$expires = time() + max( 60, $expires_in - 60 );

		$access_token = sanitize_text_field( $body['access_token'] );

		// Seal the token at rest for parity with the Client Secret (SE-1). If the
		// runtime lacks libsodium, fall back to a plaintext cache so token caching
		// still works, and record which form was stored so the reader can tell.
		$sealed    = Game_Library_Secret_Store::seal( $access_token );
		$encrypted = ( null !== $sealed );

		update_option(
			self::TOKEN_OPTION,
			array(
				'token'     => $encrypted ? $sealed : $access_token,
				'encrypted' => $encrypted,
				'expires'   => $expires,
			),
			false
		);

		$this->clear_error();

		return $access_token;
	}

	/**
	 * Resolve the Client ID — the wp-config.php constant wins over the stored option.
	 *
	 * @return string
	 */
	public function client_id() {
		if ( defined( 'GAME_LIBRARY_IGDB_CLIENT_ID' ) && '' !== (string) GAME_LIBRARY_IGDB_CLIENT_ID ) {
			return (string) GAME_LIBRARY_IGDB_CLIENT_ID;
		}
		return (string) get_option( Game_Library_Activator::OPTION_CLIENT_ID, '' );
	}

	/**
	 * Whether the credentials are locked by wp-config.php constants.
	 *
	 * @return bool
	 */
	public function credentials_locked_by_constant() {
		return defined( 'GAME_LIBRARY_IGDB_CLIENT_ID' ) || defined( 'GAME_LIBRARY_IGDB_CLIENT_SECRET' );
	}

	/**
	 * Resolve the Client Secret for internal token exchange only. Never returned
	 * to a caller other than exchange_token().
	 *
	 * @return string|null
	 */
	private function client_secret() {
		if ( defined( 'GAME_LIBRARY_IGDB_CLIENT_SECRET' ) && '' !== (string) GAME_LIBRARY_IGDB_CLIENT_SECRET ) {
			return (string) GAME_LIBRARY_IGDB_CLIENT_SECRET;
		}
		return Game_Library_Secret_Store::reveal();
	}

	/**
	 * Record a failure (admin-notice source) and return a member-facing WP_Error.
	 *
	 * @param string $message Human-readable failure detail.
	 * @return WP_Error
	 */
	private function fail( $message ) {
		set_transient(
			self::ERROR_TRANSIENT,
			array(
				'message' => (string) $message,
				'time'    => time(),
			),
			DAY_IN_SECONDS
		);

		return new WP_Error(
			'game_library_search_unavailable',
			__( 'Search is temporarily unavailable. Please try again later.', 'game-library' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Build a cover URL from an IGDB image id, host-locked to images.igdb.com.
	 *
	 * @param string $image_id IGDB cover image id.
	 * @return string
	 */
	private function cover_url_from_image_id( $image_id ) {
		$image_id = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $image_id );
		if ( '' === $image_id ) {
			return '';
		}
		return sprintf( 'https://images.igdb.com/igdb/image/upload/t_cover_big/%s.jpg', $image_id );
	}

	/**
	 * Escape a query string for embedding inside an Apicalypse search literal.
	 *
	 * @param string $query Raw query.
	 * @return string
	 */
	private function escape_apicalypse( $query ) {
		// Strip the string delimiter and control characters; IGDB search is a quoted literal.
		$query = str_replace( array( '"', '\\' ), '', $query );
		return preg_replace( '/[\x00-\x1F]/', '', $query );
	}
}
