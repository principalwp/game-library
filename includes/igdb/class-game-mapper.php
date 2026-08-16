<?php
/**
 * Converts an IGDB game payload into a `gl_games` row.
 *
 * @package Game_Library
 */

namespace Game_Library\Igdb;

use Game_Library\Data\Game_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Game_Mapper.
 *
 * Shapes one raw IGDB `games` endpoint object (as returned by
 * `Client::search()`/`Client::fetch_games()`) into the array
 * `Game_Repository::upsert()` expects, and builds cover-image URLs from a
 * stored `cover_image_id`. Every field IGDB may omit — `summary`, `cover`,
 * `genres`, `platforms`, `aggregated_rating`, `first_release_date`, `url`,
 * and `slug` — maps to `null` (nullable columns) or `[]` (list columns)
 * rather than a missing array key, matching the Data Model's nullability
 * contract (AC-005).
 */
final class Game_Mapper {

	/**
	 * Pixel width of a `t_cover_big` IGDB cover image — IGDB's own fixed
	 * size for this slug (DES-38, cycle-5). The single source every
	 * template's `<img width>` attribute and `tokens.css`'s
	 * `--gl-game-single-cover-width`/`--gl-cover-aspect-ratio` tokens must
	 * agree with; there is no build step (DD-007) to derive the CSS tokens
	 * from this constant automatically, so `tokens.css`'s own comment
	 * points back here and the two must be kept in sync by hand.
	 *
	 * @var int
	 */
	public const COVER_BIG_WIDTH = 264;

	/**
	 * Pixel height of a `t_cover_big` IGDB cover image — see
	 * `COVER_BIG_WIDTH`'s own docblock.
	 *
	 * @var int
	 */
	public const COVER_BIG_HEIGHT = 374;

	/**
	 * Used only to resolve slug collisions against already-cached games.
	 *
	 * @var Game_Repository
	 */
	private $games;

	/**
	 * Constructor.
	 *
	 * @param Game_Repository|null $games Game repository. Defaults to a new
	 *                                    instance.
	 */
	public function __construct( ?Game_Repository $games = null ) {
		$this->games = $games ?: new Game_Repository();
	}

	/**
	 * Maps one raw IGDB game object to a `Game_Repository::upsert()`-ready
	 * row.
	 *
	 * @param array<string,mixed> $payload Raw IGDB game object.
	 * @return array<string,mixed>
	 */
	public function to_row( array $payload ) {
		$igdb_id = isset( $payload['id'] ) ? absint( $payload['id'] ) : 0;
		$name    = isset( $payload['name'] ) && '' !== $payload['name']
			? sanitize_text_field( $payload['name'] )
			: '';

		return array(
			'igdb_id'            => $igdb_id,
			'slug'               => $this->resolve_slug( $igdb_id, $payload, $name ),
			'name'               => $name,
			'summary'            => $this->nullable_text( isset( $payload['summary'] ) ? $payload['summary'] : null ),
			// (int), not absint(): IGDB returns a genuine negative Unix
			// timestamp for any game released before 1970 (CO-4) —
			// absint()'s absolute-value coercion would silently mirror that
			// into a positive, wrong date instead of preserving it.
			'first_release_date' => isset( $payload['first_release_date'] ) ? (int) $payload['first_release_date'] : null,
			'cover_image_id'     => $this->extract_cover_image_id( $payload ),
			'genres'             => $this->extract_names( isset( $payload['genres'] ) ? $payload['genres'] : null ),
			'platforms'          => $this->extract_names( isset( $payload['platforms'] ) ? $payload['platforms'] : null ),
			'aggregated_rating'  => isset( $payload['aggregated_rating'] )
				? min( 100.0, max( 0.0, floatval( $payload['aggregated_rating'] ) ) )
				: null,
			'igdb_url'           => isset( $payload['url'] ) && '' !== $payload['url']
				? esc_url_raw( $payload['url'] )
				: null,
		);
	}

	/**
	 * Extracts the release year from a payload's `first_release_date` (Unix
	 * seconds), for use in search-result rendering.
	 *
	 * @param array<string,mixed> $payload Raw IGDB game object.
	 * @return int|null
	 */
	public function first_release_year( array $payload ) {
		// isset(), not empty() (CO-12): a first_release_date of exactly 0
		// (1970-01-01 UTC) is a real value, not an absent one — empty( 0 )
		// is true, which would render it as "Unreleased" identically to a
		// genuinely missing date. isset() already returns false for both an
		// absent key and an explicit null value, so no separate null check
		// is needed.
		if ( ! isset( $payload['first_release_date'] ) ) {
			return null;
		}

		// gmdate() itself handles a negative timestamp (CO-4, a game
		// released before 1970) correctly — absint() here would have
		// mirrored it into a positive, wrong year.
		return (int) gmdate( 'Y', (int) $payload['first_release_date'] );
	}

	/**
	 * Extracts a payload's cover `image_id`, or null when absent.
	 *
	 * @param array<string,mixed> $payload Raw IGDB game object.
	 * @return string|null
	 */
	public function extract_cover_image_id( array $payload ) {
		if ( empty( $payload['cover']['image_id'] ) ) {
			return null;
		}

		return sanitize_key( $payload['cover']['image_id'] );
	}

	/**
	 * Builds the public cover-image URL for a stored `cover_image_id`.
	 *
	 * PF-3: `$size` defaults to `'big'` so no existing call site's behaviour
	 * changes — `t_cover_big`, IGDB's largest standard slug, is correctly
	 * sized for every card/hero-sized render box in this plugin (220-374px).
	 * `'thumb'` maps to `t_cover_small` (90x128) instead, for the two boxes
	 * that render a cover at `--gl-avatar-lg` (64px):
	 * `.gl-activity-row__game-cover` and `.gl-search-result__cover`. Without
	 * it, those two boxes decoded a `t_cover_big` source (a 4.1x linear/17x
	 * area mismatch) purely to downscale it in CSS — wasted bytes and
	 * main-thread decode time on /activity/'s always-in-viewport first rows
	 * and on the INP-sensitive /my-library/ search results.
	 *
	 * @param string|null $cover_image_id Stored `gl_games.cover_image_id`.
	 * @param string      $size           `'big'` (default, `t_cover_big`) or
	 *                                    `'thumb'` (`t_cover_small`).
	 * @return string|null `null` when no cover exists (AC-003(c)) — never an
	 *                      absent key, never an empty string.
	 */
	public function cover_url( $cover_image_id, $size = 'big' ) {
		if ( empty( $cover_image_id ) ) {
			return null;
		}

		$igdb_size = 'thumb' === $size ? 't_cover_small' : 't_cover_big';

		return esc_url( sprintf( 'https://images.igdb.com/igdb/image/upload/%s/%s.jpg', $igdb_size, sanitize_key( $cover_image_id ) ) );
	}

	/**
	 * Extracts a `name` list from a payload's genres/platforms sub-objects.
	 *
	 * @param mixed $list Raw `genres`/`platforms` value, or absent.
	 * @return string[]
	 */
	public function extract_names( $list ) {
		if ( ! is_array( $list ) ) {
			return array();
		}

		$names = array();

		foreach ( $list as $item ) {
			if ( is_array( $item ) && isset( $item['name'] ) && '' !== $item['name'] ) {
				$names[] = sanitize_text_field( $item['name'] );
			}
		}

		return $names;
	}

	/**
	 * Sanitizes an optional long-text field, returning null when absent or
	 * empty rather than an empty string.
	 *
	 * @param mixed $value Raw value, or absent/null.
	 * @return string|null
	 */
	private function nullable_text( $value ) {
		if ( empty( $value ) ) {
			return null;
		}

		return sanitize_textarea_field( $value );
	}

	/**
	 * Resolves this game's public catalog slug: the IGDB `slug` when
	 * present, falling back to `sanitize_title( $name )`, suffixed
	 * `-{igdb_id}` when the candidate is already used by a *different*
	 * cached game.
	 *
	 * @param int                  $igdb_id This game's IGDB id.
	 * @param array<string,mixed>  $payload Raw IGDB game object.
	 * @param string               $name    Already-sanitized game name.
	 * @return string
	 */
	private function resolve_slug( $igdb_id, array $payload, $name ) {
		$candidate = ! empty( $payload['slug'] )
			? sanitize_title( $payload['slug'] )
			: sanitize_title( $name );

		if ( '' === $candidate ) {
			$candidate = 'game-' . $igdb_id;
		}

		$existing = $this->games->get_by_slug( $candidate );

		if ( $existing && absint( $existing['igdb_id'] ) !== $igdb_id ) {
			return $candidate . '-' . $igdb_id;
		}

		return $candidate;
	}
}
