<?php
/**
 * IGDB API client (via Twitch OAuth client-credentials flow).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_IGDB {

	const TOKEN_TRANSIENT = 'gc_igdb_access_token';
	const TOKEN_URL       = 'https://id.twitch.tv/oauth2/token';
	const API_BASE        = 'https://api.igdb.com/v4/';

	public static function is_configured() {
		return get_option( 'gc_igdb_client_id' ) && get_option( 'gc_igdb_client_secret' );
	}

	/**
	 * @return string|WP_Error
	 */
	private static function get_token() {
		$token = get_transient( self::TOKEN_TRANSIENT );
		if ( $token ) {
			return $token;
		}

		$client_id     = get_option( 'gc_igdb_client_id' );
		$client_secret = get_option( 'gc_igdb_client_secret' );

		if ( ! $client_id || ! $client_secret ) {
			return new WP_Error( 'gc_igdb_not_configured', __( 'IGDB API credentials are not configured.', 'game-collector' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'grant_type'    => 'client_credentials',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			return new WP_Error( 'gc_igdb_auth_failed', __( 'Could not authenticate with IGDB. Check your Client ID and Secret.', 'game-collector' ) );
		}

		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : HOUR_IN_SECONDS;
		// Renew a bit early so we never use a token at the edge of expiry.
		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], max( 60, $expires_in - 300 ) );

		return $body['access_token'];
	}

	/**
	 * Run an IGDB APIcalypse query.
	 *
	 * @param string $endpoint e.g. 'games'.
	 * @param string $query    APIcalypse body.
	 * @return array|WP_Error Decoded rows.
	 */
	public static function request( $endpoint, $query ) {
		$token = self::get_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			self::API_BASE . $endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Client-ID'     => get_option( 'gc_igdb_client_id' ),
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
				'body'    => $query,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $code ) {
			// Token revoked upstream; drop cache so the next call re-authenticates.
			delete_transient( self::TOKEN_TRANSIENT );
			return new WP_Error( 'gc_igdb_unauthorized', __( 'IGDB rejected the API token. Try again.', 'game-collector' ) );
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'gc_igdb_error',
				sprintf( __( 'IGDB request failed (HTTP %d).', 'game-collector' ), $code )
			);
		}

		$rows = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $rows ) ? $rows : array();
	}

	private static function fields() {
		return 'fields id,name,slug,summary,first_release_date,cover.image_id,platforms.abbreviation,platforms.name;';
	}

	/**
	 * Search games by name.
	 *
	 * @return array|WP_Error List of normalized games.
	 */
	public static function search( $term, $limit = 20 ) {
		$term  = str_replace( array( '"', '\\' ), '', $term );
		$limit = min( 50, max( 1, (int) $limit ) );

		$query = sprintf( 'search "%s"; %s limit %d;', $term, self::fields(), $limit );

		$rows = self::request( 'games', $query );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		return array_map( array( __CLASS__, 'normalize' ), $rows );
	}

	/**
	 * Fetch a single game by IGDB id.
	 *
	 * @return array|WP_Error|null Normalized game, null if not found.
	 */
	public static function get_game( $igdb_id ) {
		$query = sprintf( '%s where id = %d;', self::fields(), (int) $igdb_id );

		$rows = self::request( 'games', $query );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		return $rows ? self::normalize( $rows[0] ) : null;
	}

	private static function normalize( $row ) {
		$platforms = array();
		if ( ! empty( $row['platforms'] ) && is_array( $row['platforms'] ) ) {
			foreach ( $row['platforms'] as $platform ) {
				$label = $platform['abbreviation'] ?? ( $platform['name'] ?? '' );
				if ( $label ) {
					$platforms[] = $label;
				}
			}
		}

		return array(
			'igdb_id'        => (int) $row['id'],
			'name'           => $row['name'] ?? '',
			'slug'           => $row['slug'] ?? '',
			'summary'        => $row['summary'] ?? '',
			'cover_image_id' => $row['cover']['image_id'] ?? '',
			'release_year'   => ! empty( $row['first_release_date'] ) ? (int) gmdate( 'Y', (int) $row['first_release_date'] ) : null,
			'platforms'      => implode( ', ', $platforms ),
		);
	}

	public static function cover_url( $image_id, $size = 'cover_big' ) {
		if ( ! $image_id ) {
			return '';
		}
		return sprintf( 'https://images.igdb.com/igdb/image/upload/t_%s/%s.jpg', $size, rawurlencode( $image_id ) );
	}
}
