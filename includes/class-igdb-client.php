<?php
/**
 * IGDB HTTP client: token cache, one-401 retry, Apicalypse search, cover URLs.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to Twitch OAuth + IGDB. Both endpoint URLs are compile-time constants.
 */
class Igdb_Client {

	const TOKEN_URL     = 'https://id.twitch.tv/oauth2/token';
	const GAMES_URL     = 'https://api.igdb.com/v4/games';
	const IMAGE_BASE    = 'https://images.igdb.com/igdb/image/upload/t_cover_big/';
	const TOKEN_TTL_MAX = 30 * DAY_IN_SECONDS;

	/**
	 * Build a t_cover_big cover URL from an image id.
	 *
	 * @param string $image_id IGDB image id.
	 * @return string
	 */
	public function cover_url( $image_id ) {
		$image_id = sanitize_key( $image_id );
		if ( '' === $image_id ) {
			return '';
		}
		return self::IMAGE_BASE . $image_id . '.jpg';
	}

	/**
	 * Obtain (and cache) the IGDB bearer token.
	 *
	 * @param bool $force Skip the cache and fetch fresh.
	 * @return string Empty string on failure.
	 */
	public function get_token( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( 'gl_igdb_token' );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$settings  = Plugin::instance()->settings();
		$client_id = $settings->get_client_id();
		$secret    = $settings->get_secret();

		if ( '' === $client_id || '' === $secret ) {
			$this->record_error( __( 'IGDB credentials are not configured.', 'game-library-3' ) );
			return '';
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 8,
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $secret,
					'grant_type'    => 'client_credentials',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->record_error( __( 'Could not reach the IGDB token endpoint.', 'game-library-3' ) );
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$this->record_error( sprintf( /* translators: %d: HTTP status code. */ __( 'IGDB token endpoint returned HTTP %d.', 'game-library-3' ), $code ) );
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$this->record_error( __( 'IGDB token response was not understood.', 'game-library-3' ) );
			return '';
		}

		$token      = (string) $data['access_token'];
		$expires_in = isset( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 0;
		$ttl        = min( $expires_in - 300, self::TOKEN_TTL_MAX );
		if ( $ttl < 60 ) {
			$ttl = 60;
		}
		set_transient( 'gl_igdb_token', $token, $ttl );

		return $token;
	}

	/**
	 * Search IGDB for games. Returns a render-ready state + game list.
	 *
	 * @param string $term Search term (already trimmed).
	 * @return array{state:string, games:array<int, array<string, mixed>>, code:int}
	 */
	public function search( $term ) {
		$term = trim( $term );
		if ( mb_strlen( $term ) < 2 ) {
			return $this->result( 'empty', array(), 0 );
		}

		$token = $this->get_token();
		if ( '' === $token ) {
			return $this->result( 'unavailable', array(), 0 );
		}

		$response = $this->request_games( $term, $token );

		if ( ! is_wp_error( $response ) && 401 === (int) wp_remote_retrieve_response_code( $response ) ) {
			// Exactly one refresh + one retry on a 401.
			delete_transient( 'gl_igdb_token' );
			$token = $this->get_token( true );
			if ( '' === $token ) {
				return $this->result( 'unavailable', array(), 401 );
			}
			$response = $this->request_games( $term, $token );
		}

		if ( is_wp_error( $response ) ) {
			$this->record_error( __( 'The IGDB games request failed or timed out.', 'game-library-3' ) );
			return $this->result( 'unavailable', array(), 0 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->record_error( sprintf( /* translators: %d: HTTP status code. */ __( 'IGDB games endpoint returned HTTP %d.', 'game-library-3' ), $code ) );
			return $this->result( 'unavailable', array(), $code );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			$this->record_error( __( 'IGDB games response was not understood.', 'game-library-3' ) );
			return $this->result( 'unavailable', array(), $code );
		}

		if ( empty( $data ) ) {
			return $this->result( 'empty', array(), $code );
		}

		$games = array();
		foreach ( $data as $raw ) {
			$mapped = $this->map_game( $raw );
			if ( null !== $mapped ) {
				$games[] = $mapped;
			}
		}

		if ( empty( $games ) ) {
			return $this->result( 'empty', array(), $code );
		}

		return $this->result( 'results', $games, $code );
	}

	/**
	 * Issue a single games request. The URL is a constant; only the body carries
	 * the (escaped) search term.
	 *
	 * @param string $term  Search term.
	 * @param string $token Bearer token.
	 * @return array|\WP_Error
	 */
	private function request_games( $term, $token ) {
		$body = sprintf(
			'search "%s"; fields name,cover.image_id,first_release_date,genres.name,platforms.name,summary; limit 20;',
			$this->escape_term( $term )
		);

		return wp_remote_post(
			self::GAMES_URL,
			array(
				'timeout' => 10,
				'headers' => array(
					'Client-ID'     => Plugin::instance()->settings()->get_client_id(),
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
				'body'    => $body,
			)
		);
	}

	/**
	 * Escape a search term for the Apicalypse body (quotes + line breaks).
	 *
	 * @param string $term Raw term.
	 * @return string
	 */
	private function escape_term( $term ) {
		$term = str_replace( array( "\r", "\n" ), ' ', $term );
		$term = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $term );
		return $term;
	}

	/**
	 * Normalize a raw IGDB game object into the shape the repository expects.
	 *
	 * @param mixed $raw Raw decoded game.
	 * @return array<string, mixed>|null
	 */
	private function map_game( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw['id'] ) || empty( $raw['name'] ) ) {
			return null;
		}

		$cover_image_id = '';
		if ( isset( $raw['cover']['image_id'] ) ) {
			$cover_image_id = (string) $raw['cover']['image_id'];
		}

		$genres    = $this->names_from( isset( $raw['genres'] ) ? $raw['genres'] : array() );
		$platforms = $this->names_from( isset( $raw['platforms'] ) ? $raw['platforms'] : array() );

		return array(
			'igdb_id'            => absint( $raw['id'] ),
			'name'               => (string) $raw['name'],
			'cover_image_id'     => $cover_image_id,
			'cover_url'          => '' !== $cover_image_id ? $this->cover_url( $cover_image_id ) : '',
			'first_release_date' => isset( $raw['first_release_date'] ) ? absint( $raw['first_release_date'] ) : null,
			'genres'             => $genres,
			'platforms'          => $platforms,
			'summary'            => isset( $raw['summary'] ) ? (string) $raw['summary'] : '',
		);
	}

	/**
	 * Extract a name list from an IGDB sub-object array.
	 *
	 * @param mixed $items Array of {name} objects.
	 * @return array<int, string>
	 */
	private function names_from( $items ) {
		$names = array();
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( is_array( $item ) && ! empty( $item['name'] ) ) {
					$names[] = (string) $item['name'];
				}
			}
		}
		return $names;
	}

	/**
	 * Record a failure summary (HTTP code + timestamp, never the secret).
	 *
	 * @param string $message Human-readable summary.
	 * @return void
	 */
	private function record_error( $message ) {
		set_transient(
			'gl_igdb_last_error',
			array(
				'message' => $message,
				'time'    => current_time( 'mysql' ),
			),
			15 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Shape a search result.
	 *
	 * @param string                           $state Render state.
	 * @param array<int, array<string, mixed>> $games Games.
	 * @param int                              $code  HTTP code.
	 * @return array{state:string, games:array, code:int}
	 */
	private function result( $state, $games, $code ) {
		return array(
			'state' => $state,
			'games' => $games,
			'code'  => $code,
		);
	}
}
