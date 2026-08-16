<?php
/**
 * Games persistence (gl_games): upsert by igdb_id, slug generation, catalog + detail reads.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the shared game catalog.
 */
class Games_Repository {

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'gl_games';
	}

	/**
	 * Upsert a game keyed on its IGDB id. Returns the local game id.
	 *
	 * @param array<string, mixed> $game Normalized IGDB payload.
	 * @return int Local game id, or 0 on failure.
	 */
	public function upsert_from_igdb( array $game ) {
		global $wpdb;

		$igdb_id = isset( $game['igdb_id'] ) ? absint( $game['igdb_id'] ) : 0;
		if ( $igdb_id <= 0 ) {
			return 0;
		}

		$name           = isset( $game['name'] ) ? sanitize_text_field( $game['name'] ) : '';
		$cover_image_id = ! empty( $game['cover_image_id'] ) ? sanitize_key( $game['cover_image_id'] ) : null;
		$release        = isset( $game['first_release_date'] ) && '' !== $game['first_release_date'] ? absint( $game['first_release_date'] ) : null;
		$genres         = $this->encode_names( isset( $game['genres'] ) ? (array) $game['genres'] : array() );
		$platforms      = $this->encode_names( isset( $game['platforms'] ) ? (array) $game['platforms'] : array() );
		$summary        = isset( $game['summary'] ) ? sanitize_textarea_field( $game['summary'] ) : null;
		$now            = current_time( 'mysql', true );

		$table = $this->table();

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, slug FROM {$table} WHERE igdb_id = %d", $igdb_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'name'               => $name,
					'cover_image_id'     => $cover_image_id,
					'first_release_date' => $release,
					'genres'             => $genres,
					'platforms'          => $platforms,
					'summary'            => $summary,
					'updated_at'         => $now,
				),
				array( 'id' => (int) $existing->id ),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return (int) $existing->id;
		}

		$slug = $this->unique_slug( '' !== $name ? $name : (string) $igdb_id );

		$inserted = $wpdb->insert(
			$table,
			array(
				'igdb_id'            => $igdb_id,
				'name'               => $name,
				'slug'               => $slug,
				'cover_image_id'     => $cover_image_id,
				'first_release_date' => $release,
				'genres'             => $genres,
				'platforms'          => $platforms,
				'summary'            => $summary,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch a single game row by id.
	 *
	 * @param int $game_id Game id.
	 * @return object|null
	 */
	public function get( $game_id ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $game_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Fetch a single game row by slug.
	 *
	 * @param string $slug Game slug.
	 * @return object|null
	 */
	public function get_by_slug( $slug ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Paginated catalog, newest game first.
	 *
	 * @param int $per_page Rows per page.
	 * @param int $offset   Offset.
	 * @return array<int, object>
	 */
	public function get_catalog( $per_page, $offset ) {
		global $wpdb;
		$table = $this->table();
		// id is monotonic with created_at (each game is inserted once), so
		// ordering by the PRIMARY key yields the identical newest-first order
		// with no filesort over the whole games table.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $per_page ),
				absint( $offset )
			)
		);
	}

	/**
	 * Total catalog size.
	 *
	 * @return int
	 */
	public function count_catalog() {
		global $wpdb;
		$table = $this->table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Decode a JSON name list column into an array of strings.
	 *
	 * @param string|null $json Stored JSON.
	 * @return array<int, string>
	 */
	public function decode_names( $json ) {
		if ( empty( $json ) ) {
			return array();
		}
		$decoded = json_decode( (string) $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $decoded ) ) );
	}

	/**
	 * Sanitize + JSON-encode a list of names for storage.
	 *
	 * @param array<int, mixed> $names Raw names.
	 * @return string|null
	 */
	private function encode_names( array $names ) {
		$clean = array();
		foreach ( $names as $name ) {
			$name = sanitize_text_field( (string) $name );
			if ( '' !== $name ) {
				$clean[] = $name;
			}
		}
		if ( empty( $clean ) ) {
			return null;
		}
		return wp_json_encode( $clean );
	}

	/**
	 * Build a slug unique within gl_games, appending -N on collision.
	 *
	 * @param string $name Source name.
	 * @return string
	 */
	private function unique_slug( $name ) {
		global $wpdb;
		$table = $this->table();

		$base = sanitize_title( $name );
		if ( '' === $base ) {
			$base = 'game';
		}
		$base = substr( $base, 0, 190 );

		$slug   = $base;
		$suffix = 2;
		while ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$slug = $base . '-' . $suffix;
			++$suffix;
		}
		return $slug;
	}
}
