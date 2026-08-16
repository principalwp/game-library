<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GCOLLECTOR_IGDB {
	const TOKEN_TRANSIENT = 'gcollector_igdb_token';

	public function is_configured() {
		return '' !== trim( (string) get_option( 'gcollector_igdb_client_id', '' ) )
			&& '' !== trim( (string) get_option( 'gcollector_igdb_client_secret', '' ) );
	}

	public function search( $query ) {
		$query = trim( sanitize_text_field( $query ) );
		if ( mb_strlen( $query ) < 2 ) {
			return new WP_Error( 'query_too_short', __( 'Enter at least two characters.', 'game-collector' ), array( 'status' => 400 ) );
		}

		$escaped = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $query );
		$body    = 'fields id,name,slug,summary,first_release_date,cover.url,platforms.name; '
			. 'search "' . $escaped . '"; where version_parent = null; limit 20;';

		return $this->request( $body );
	}

	public function get_game( $igdb_id ) {
		$igdb_id = absint( $igdb_id );
		if ( ! $igdb_id ) {
			return new WP_Error( 'invalid_game', __( 'Invalid game.', 'game-collector' ), array( 'status' => 400 ) );
		}

		$result = $this->request(
			'fields id,name,slug,summary,first_release_date,cover.url,platforms.name; where id = '
			. $igdb_id . '; limit 1;'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return new WP_Error( 'game_not_found', __( 'Game not found on IGDB.', 'game-collector' ), array( 'status' => 404 ) );
		}

		return $result[0];
	}

	private function request( $body ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'igdb_not_configured', __( 'IGDB has not been configured by an administrator.', 'game-collector' ), array( 'status' => 503 ) );
		}

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			'https://api.igdb.com/v4/games',
			array(
				'timeout' => 15,
				'headers' => array(
					'Client-ID'     => trim( (string) get_option( 'gcollector_igdb_client_id', '' ) ),
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'text/plain',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'igdb_unavailable', __( 'IGDB could not be reached.', 'game-collector' ), array( 'status' => 502 ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $data ) ) {
			return new WP_Error( 'igdb_error', __( 'IGDB returned an unexpected response.', 'game-collector' ), array( 'status' => 502 ) );
		}

		return array_map( array( $this, 'normalize_game' ), $data );
	}

	private function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			add_query_arg(
				array(
					'client_id'     => trim( (string) get_option( 'gcollector_igdb_client_id', '' ) ),
					'client_secret' => trim( (string) get_option( 'gcollector_igdb_client_secret', '' ) ),
					'grant_type'    => 'client_credentials',
				),
				'https://id.twitch.tv/oauth2/token'
			),
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'igdb_auth_unavailable', __( 'IGDB authentication could not be reached.', 'game-collector' ), array( 'status' => 502 ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $data['access_token'] ) ) {
			return new WP_Error( 'igdb_auth_failed', __( 'IGDB credentials were rejected.', 'game-collector' ), array( 'status' => 502 ) );
		}

		$ttl = max( 300, absint( isset( $data['expires_in'] ) ? $data['expires_in'] : HOUR_IN_SECONDS ) - 300 );
		set_transient( self::TOKEN_TRANSIENT, sanitize_text_field( $data['access_token'] ), $ttl );

		return sanitize_text_field( $data['access_token'] );
	}

	private function normalize_game( $game ) {
		$cover = isset( $game['cover']['url'] ) ? (string) $game['cover']['url'] : '';
		if ( 0 === strpos( $cover, '//' ) ) {
			$cover = 'https:' . $cover;
		}
		$cover = str_replace( '/t_thumb/', '/t_cover_big/', $cover );

		$platforms = array();
		if ( ! empty( $game['platforms'] ) && is_array( $game['platforms'] ) ) {
			foreach ( $game['platforms'] as $platform ) {
				if ( ! empty( $platform['name'] ) ) {
					$platforms[] = sanitize_text_field( $platform['name'] );
				}
			}
		}

		return array(
			'igdb_id'      => absint( isset( $game['id'] ) ? $game['id'] : 0 ),
			'name'         => sanitize_text_field( isset( $game['name'] ) ? $game['name'] : '' ),
			'slug'         => sanitize_title( isset( $game['slug'] ) ? $game['slug'] : '' ),
			'summary'      => sanitize_textarea_field( isset( $game['summary'] ) ? $game['summary'] : '' ),
			'release_date' => ! empty( $game['first_release_date'] ) ? gmdate( 'Y-m-d', absint( $game['first_release_date'] ) ) : null,
			'cover_url'    => esc_url_raw( $cover ),
			'platforms'    => array_values( array_unique( $platforms ) ),
		);
	}
}
