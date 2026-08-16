<?php
/**
 * The only outbound HTTP path to Twitch and IGDB.
 *
 * @package Game_Library
 */

namespace Game_Library\Igdb;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Client.
 *
 * Routes every Twitch and IGDB HTTP call through `request()` (DD-002/
 * ADR-002) — the only method in the plugin that calls
 * `vip_safe_wp_remote_request()`, and therefore the single interception
 * point the project's E2E-only test layer needs. Owns the cached Twitch
 * access token, the 429 backoff, the one-and-only-one re-authenticate-and-
 * retry on 401/403, and the mapping onto the AC-007 error taxonomy:
 * `gl_igdb_not_configured`, `gl_igdb_rate_limited`, and `gl_igdb_unavailable`
 * — plus a successful empty result, which is an empty array and not an
 * error.
 *
 * No credential material is ever persisted by this class — only the Twitch
 * access token (never the client id/secret) is cached, in a transient, never
 * in a database table, option, or user meta.
 */
final class Client {

	/**
	 * Twitch OAuth2 client-credentials token endpoint.
	 *
	 * @var string
	 */
	private const TOKEN_ENDPOINT = 'https://id.twitch.tv/oauth2/token';

	/**
	 * IGDB `games` endpoint — the only IGDB endpoint this plugin calls.
	 *
	 * @var string
	 */
	private const GAMES_ENDPOINT = 'https://api.igdb.com/v4/games';

	/**
	 * Explicit timeout, in seconds, for every outbound request. 3, not 5
	 * (PB-1) — the 401/403 re-authenticate-and-retry cycle (AC-007(a))
	 * issues up to four sequential outbound calls (token, failed original,
	 * re-auth token, retry) for one member-triggered request; a shorter
	 * per-call timeout bounds the worst case a single PHP worker can be
	 * occupied for while IGDB/Twitch is degraded.
	 *
	 * @var int
	 */
	private const TIMEOUT = 3;

	/**
	 * Transient name for the cached Twitch access token. Public (SE-4,
	 * cycle-5) — the derived OAuth bearer this stores is credential
	 * material (human ruling 6), and `uninstall.php` needs this exact name
	 * to delete it on a full, administrator-opted-in uninstall (AC-037)
	 * without re-declaring the literal a second time.
	 *
	 * @var string
	 */
	public const TOKEN_TRANSIENT = 'game_library_igdb_token';

	/**
	 * Transient name for the 429 short-circuit backoff.
	 *
	 * @var string
	 */
	private const BACKOFF_TRANSIENT = 'game_library_igdb_backoff';

	/**
	 * Backoff transient TTL in seconds (AC-007(b)).
	 *
	 * @var int
	 */
	private const BACKOFF_TTL = 60;

	/**
	 * Transient name for the short failure-backoff window (PB-1) — unlike
	 * `BACKOFF_TRANSIENT` (429 only), this is set on a transport-level
	 * `WP_Error` or any 5xx response, or an `authenticate()` failure, none
	 * of which previously left any negative cache: every GET /search and
	 * POST /library issued a fresh outbound call for as long as IGDB/Twitch
	 * stayed degraded.
	 *
	 * @var string
	 */
	private const FAILURE_TRANSIENT = 'game_library_igdb_failure';

	/**
	 * Failure-backoff transient TTL in seconds — the same 60s window as
	 * `BACKOFF_TTL` (AC-007(b) mandates that figure for the 429 case; this
	 * mirrors it for the "transport/5xx/auth failure" case rather than
	 * inventing a second, undocumented window).
	 *
	 * @var int
	 */
	private const FAILURE_TTL = 60;

	/**
	 * Per-member outbound-call budget window, in seconds (SE-2).
	 *
	 * @var int
	 */
	private const BUDGET_WINDOW = 300;

	/**
	 * Per-member outbound-call budget within `BUDGET_WINDOW` (SE-2) — every
	 * `call_igdb()` invocation with no cache hit above it (a search()
	 * cache miss, or any fetch_games() call, which has no cache at all)
	 * counts against this. Bounds how much of the site's IGDB quota, and
	 * how many PHP-worker-occupying outbound calls, one Subscriber-role
	 * member can burn — GET /search has no server-side rate limit
	 * otherwise, and a varying-by-one-character query defeats search()'s
	 * own per-normalized-query cache.
	 *
	 * @var int
	 */
	private const BUDGET_LIMIT = 60;

	/**
	 * Search result cache TTL in seconds. At/above the 900-second floor —
	 * never lower.
	 *
	 * @var int
	 */
	private const SEARCH_CACHE_TTL = 900;

	/**
	 * Credential accessor.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Constructor.
	 *
	 * @param Credentials|null $credentials Credential accessor. Defaults to a
	 *                                      new instance.
	 */
	public function __construct( ?Credentials $credentials = null ) {
		$this->credentials = $credentials ?: new Credentials();
	}

	/**
	 * Searches IGDB for games matching a free-text query.
	 *
	 * Results cache in a transient keyed by
	 * `md5( strtolower( escaped query ) )` for 900 seconds (C1 budget /
	 * AC-NFR-007), so an identical repeated query within that window makes no
	 * additional outbound request.
	 *
	 * CO-12 (cycle-3): escapes once, up front, before either the cache key or
	 * the outbound body is built — previously the cache key hashed the raw
	 * normalized query while the body used the escaped value, so two queries
	 * that only differ by characters `escape_apicalypse_string()` strips
	 * (e.g. `zelda"` and `zelda`) built the identical outbound body but
	 * occupied two separate cache entries, weakening AC-NFR-007's "repeating
	 * the identical completed query produces 0 additional outbound
	 * requests." Also short-circuits on an escaped-empty query: a query made
	 * only of stripped characters (`""`, `;;`, `\\`) reduced to `""; fields
	 * ...; limit 10;`, an IGDB-rejected empty search literal that
	 * `call_igdb()` maps to `gl_igdb_unavailable` — AC-004(e)'s "Search is
	 * unavailable right now" for what is really an empty result, AC-004(d).
	 *
	 * @param string $query Free-text search query.
	 * @return array<int,array<string,mixed>>|WP_Error Up to 10 raw IGDB game
	 *                                                  objects, or a WP_Error
	 *                                                  from the AC-007
	 *                                                  taxonomy.
	 */
	public function search( $query ) {
		$escaped = $this->escape_apicalypse_string( trim( (string) $query ) );

		if ( '' === $escaped ) {
			return array();
		}

		$cache_key = 'game_library_search_' . md5( strtolower( $escaped ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$body = sprintf(
			'search "%1$s"; fields id,name,cover.image_id,first_release_date,platforms.name; limit 10;',
			$escaped
		);

		$result = $this->call_igdb( $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		set_transient( $cache_key, $result, self::SEARCH_CACHE_TTL );

		return $result;
	}

	/**
	 * Fetches full metadata for one or more IGDB games by id — used when a
	 * member adds a game not already cached, and by the manual refresh
	 * action.
	 *
	 * @param int[] $igdb_ids IGDB game ids.
	 * @return array<int,array<string,mixed>>|WP_Error Raw IGDB game objects,
	 *                                                  or a WP_Error from the
	 *                                                  AC-007 taxonomy.
	 */
	public function fetch_games( array $igdb_ids ) {
		$igdb_ids = array_values( array_unique( array_filter( array_map( 'absint', $igdb_ids ) ) ) );

		if ( empty( $igdb_ids ) ) {
			return array();
		}

		// SE-2: this method otherwise has no cache at all (unlike search()'s
		// 900s transient) and never remembers a miss, so a single non-existent
		// id — reachable from POST /library with an igdb_id not in gl_games —
		// can be replayed forever, one outbound call each. Only worth doing
		// for the single-id shape: a multi-id batch (the manual-refresh path)
		// legitimately wants a fresh answer for every id every time.
		$is_single_lookup = 1 === count( $igdb_ids );

		if ( $is_single_lookup ) {
			$miss_key = 'gl_igdb_miss_' . $igdb_ids[0];

			if ( false !== get_transient( $miss_key ) ) {
				return array();
			}
		}

		$body = sprintf(
			'fields id,slug,name,summary,first_release_date,cover.image_id,genres.name,platforms.name,aggregated_rating,url; where id = (%1$s); limit %2$d;',
			implode( ',', $igdb_ids ),
			count( $igdb_ids )
		);

		$result = $this->call_igdb( $body );

		if ( $is_single_lookup && ! is_wp_error( $result ) && empty( $result ) ) {
			set_transient( $miss_key, 1, HOUR_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Issues one live Twitch token request and reports whether it succeeded
	 * — used by the settings screen's "Test connection" action (AC-006(c)/
	 * (d)). Deliberately bypasses the cached token so the result reflects a
	 * live check rather than a stale cache hit.
	 *
	 * @return true|WP_Error True on success, WP_Error from the AC-007
	 *                       taxonomy on failure.
	 */
	public function test_connection() {
		if ( ! $this->credentials->is_configured() ) {
			return new WP_Error( 'gl_igdb_not_configured', __( 'IGDB credentials are not configured.', 'game-library' ) );
		}

		if ( $this->is_backoff_active() ) {
			return new WP_Error( 'gl_igdb_rate_limited', __( 'The IGDB service is rate-limited. Try again shortly.', 'game-library' ) );
		}

		$token = $this->authenticate();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		return true;
	}

	/**
	 * Orchestrates one IGDB `games` endpoint call: credential and backoff
	 * short-circuits (AC-007(e)/(b)), token acquisition, the call itself,
	 * and — on a 401/403 — exactly one discard-token/re-authenticate/retry
	 * cycle (AC-007(a)). No retry loop of any kind beyond that single
	 * attempt.
	 *
	 * @param string $apicalypse_body Apicalypse query for the request body.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function call_igdb( $apicalypse_body ) {
		if ( ! $this->credentials->is_configured() ) {
			return new WP_Error( 'gl_igdb_not_configured', __( 'IGDB credentials are not configured.', 'game-library' ) );
		}

		if ( $this->is_backoff_active() ) {
			return new WP_Error( 'gl_igdb_rate_limited', __( 'The IGDB service is rate-limited. Try again shortly.', 'game-library' ) );
		}

		// PB-1: a transport-level failure, a 5xx, or an authenticate()
		// failure short-circuits every call for FAILURE_TTL seconds with
		// zero outbound requests — previously only a 429 left any negative
		// cache, so an IGDB/Twitch outage converted directly into every
		// GET /search and POST /library issuing (and waiting out) a fresh
		// outbound call.
		if ( false !== get_transient( self::FAILURE_TRANSIENT ) ) {
			return new WP_Error( 'gl_igdb_unavailable', __( 'The IGDB service is temporarily unavailable.', 'game-library' ) );
		}

		// SE-2: a per-user outbound-call budget, checked after the backoff
		// checks above so a caller already short-circuited by one of those
		// never consumes budget for a call it didn't actually make.
		if ( $this->is_budget_exhausted() ) {
			return new WP_Error( 'gl_igdb_rate_limited', __( 'The IGDB service is rate-limited. Try again shortly.', 'game-library' ) );
		}

		$token = $this->get_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$result = $this->handle_response( $this->issue_games_request( $token, $apicalypse_body ) );

		if ( ! is_wp_error( $result ) || 'gl_igdb_reauth_required' !== $result->get_error_code() ) {
			return $result;
		}

		// AC-007(a): discard the cached token, re-authenticate once, retry
		// the original request exactly once. Any failure of this retry —
		// including a second 401/403, a 429, or a 5xx — maps to
		// gl_igdb_unavailable per the AC's literal text; it is not a second
		// retry loop.
		delete_transient( self::TOKEN_TRANSIENT );

		$retry_token = $this->authenticate();

		if ( is_wp_error( $retry_token ) ) {
			return new WP_Error( 'gl_igdb_unavailable', __( 'The IGDB service is temporarily unavailable.', 'game-library' ) );
		}

		$retry_result = $this->handle_response( $this->issue_games_request( $retry_token, $apicalypse_body ) );

		if ( is_wp_error( $retry_result ) ) {
			return new WP_Error( 'gl_igdb_unavailable', __( 'The IGDB service is temporarily unavailable.', 'game-library' ) );
		}

		return $retry_result;
	}

	/**
	 * Issues one authenticated POST to the IGDB `games` endpoint.
	 *
	 * @param string $token           Bearer access token.
	 * @param string $apicalypse_body Apicalypse query for the request body.
	 * @return array|WP_Error Raw `vip_safe_wp_remote_request()` result.
	 */
	private function issue_games_request( $token, $apicalypse_body ) {
		return $this->request(
			self::GAMES_ENDPOINT,
			array(
				'method'  => 'POST',
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Client-ID'     => $this->credentials->client_id(),
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => $apicalypse_body,
			)
		);
	}

	/**
	 * Maps a raw HTTP result onto the AC-007 taxonomy plus one internal-only
	 * sentinel (`gl_igdb_reauth_required`) that never escapes this class —
	 * `call_igdb()` always converts it to `gl_igdb_unavailable` before
	 * returning to a caller.
	 *
	 * @param array|WP_Error $response Raw `vip_safe_wp_remote_request()` result.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function handle_response( $response ) {
		if ( is_wp_error( $response ) ) {
			// AC-007(c): a WP_Error from the transport. PB-1: also opens the
			// short failure-backoff window — a transport failure (DNS, TLS,
			// connection refused) is exactly the class of failure that
			// otherwise leaves zero negative cache and re-attempts on every
			// subsequent call while the underlying outage continues.
			$this->open_failure_backoff();

			return new WP_Error( 'gl_igdb_unavailable', __( 'The IGDB service is temporarily unavailable.', 'game-library' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'gl_igdb_reauth_required', __( 'The IGDB access token was rejected.', 'game-library' ) );
		}

		if ( 429 === $code ) {
			// Coordination transient, not a content cache — AC-007(b)
			// mandates this exact 60s backoff window for a rate-limit flag,
			// so the WordPressVIPMinimum.Performance.LowExpiryCacheTime
			// 300s content-cache floor does not apply here (VIP-5).
			set_transient( self::BACKOFF_TRANSIENT, 1, self::BACKOFF_TTL ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- AC-007(b) mandates a 60s backoff window; this is a single rate-limit flag, not a content cache, so the 300s VIP content-TTL floor does not apply.

			return new WP_Error( 'gl_igdb_rate_limited', __( 'The IGDB service is rate-limited. Try again shortly.', 'game-library' ) );
		}

		if ( 200 !== $code ) {
			// AC-007(c): any other non-200 status, e.g. a 5xx. PB-1: also
			// opens the short failure-backoff window — see the transport
			// WP_Error branch above for why.
			$this->open_failure_backoff();

			return new WP_Error( 'gl_igdb_unavailable', __( 'The IGDB service is temporarily unavailable.', 'game-library' ) );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			// AC-007(c): an unparseable body.
			return new WP_Error( 'gl_igdb_unavailable', __( 'The IGDB service is temporarily unavailable.', 'game-library' ) );
		}

		// AC-007(d): HTTP 200 with an empty array is an empty result set,
		// not an error — $decoded passes through as-is, including [].
		return $decoded;
	}

	/**
	 * Returns the cached Twitch access token, fetching and caching a new one
	 * when absent.
	 *
	 * @return string|WP_Error
	 */
	private function get_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		return $this->authenticate();
	}

	/**
	 * Fetches a fresh Twitch access token and caches it for
	 * `expires_in - 300` seconds.
	 *
	 * @return string|WP_Error
	 */
	private function authenticate() {
		$response = $this->request(
			self::TOKEN_ENDPOINT,
			array(
				'method'  => 'POST',
				'timeout' => self::TIMEOUT,
				'body'    => array(
					'client_id'     => $this->credentials->client_id(),
					'client_secret' => $this->credentials->client_secret(),
					'grant_type'    => 'client_credentials',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// PB-1: opens the same failure-backoff window handle_response()
			// does — a Twitch-side outage on the token endpoint is exactly
			// as capable of triggering unbounded retried outbound calls as
			// an IGDB-side one.
			$this->open_failure_backoff();

			return new WP_Error( 'gl_igdb_unavailable', __( 'The IGDB service is temporarily unavailable.', 'game-library' ) );
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->open_failure_backoff();

			return new WP_Error( 'gl_igdb_unavailable', __( 'The IGDB service is temporarily unavailable.', 'game-library' ) );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) || empty( $decoded['access_token'] ) || empty( $decoded['expires_in'] ) ) {
			$this->open_failure_backoff();

			return new WP_Error( 'gl_igdb_unavailable', __( 'The IGDB service is temporarily unavailable.', 'game-library' ) );
		}

		$token = (string) $decoded['access_token'];
		// PB-9/AC-NFR-006(a) (cycle-5): capped at 12 hours, not the raw
		// expires_in - 300 (Twitch tokens live for weeks). Human ruling 6
		// treats this derived OAuth bearer as credential material — a
		// leaked/cached copy of the uncapped token stayed valid for as long
		// as Twitch issued it, weeks past any reasonable rotation window.
		// One reading of "object-cache/TTL-cap it" — moving the token off
		// this transient onto wp_cache_set() — would, on this project's
		// ruled substrate (human ruling 1: core's stock, non-persistent
		// cache), discard it at the end of every single request, so every
		// request reaching call_igdb() would issue a fresh Twitch
		// authenticate() call instead of reusing a cached token: outbound
		// volume against Twitch's token endpoint would scale with traffic
		// rather than token lifetime, the shape that gets an application
		// throttled at the identity provider. A transient is the correct
		// storage precisely because core writes it to wp_options with
		// autoload = 'no' when no persistent object cache exists (confirmed
		// by reading WordPress core's own transient implementation) — this
		// stays a transient, only its own TTL is capped.
		// max( 1, … ) — set_transient() treats an expiration of 0 as "never
		// expires", so a token whose expires_in is under 300s (never happens
		// in practice, Twitch tokens live for weeks) still gets a real TTL.
		$ttl = max( 1, min( absint( $decoded['expires_in'] ) - 300, 12 * HOUR_IN_SECONDS ) );

		set_transient( self::TOKEN_TRANSIENT, $token, $ttl );

		return $token;
	}

	/**
	 * Whether the 429 backoff window is currently active.
	 *
	 * @return bool
	 */
	private function is_backoff_active() {
		return false !== get_transient( self::BACKOFF_TRANSIENT );
	}

	/**
	 * Opens the short failure-backoff window (PB-1) — called from every
	 * transport-WP_Error, 5xx, and `authenticate()`-failure branch above so
	 * a caller inside the window short-circuits in `call_igdb()` with zero
	 * outbound calls, rather than each independently re-attempting (and
	 * waiting out `self::TIMEOUT`) while the underlying outage continues.
	 *
	 * @return void
	 */
	private function open_failure_backoff() {
		set_transient( self::FAILURE_TRANSIENT, 1, self::FAILURE_TTL ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- mirrors AC-007(b)'s 60s rate-limit-flag backoff window; this is a coordination flag, not a content cache, so the 300s VIP content-TTL floor does not apply.
	}

	/**
	 * Checks — and, when not yet exhausted, consumes one unit of — the
	 * current member's `BUDGET_LIMIT`-per-`BUDGET_WINDOW` outbound-call
	 * budget (SE-2). A request with no logged-in member (e.g. a WP-CLI or
	 * cron context) has no per-member budget to check.
	 *
	 * VIP-6: this counter is best-effort and fails open under object-cache
	 * eviction — on VIP, transients live in memcached with LRU eviction
	 * across a multi-node pool, so an evicted counter silently resets to
	 * zero and a member's budget starts over. This is defence in depth over
	 * IGDB's own server-side rate limiting, which remains the authoritative
	 * control; an attacker cannot force eviction of a specific key, so the
	 * impact of this fail-open is small.
	 *
	 * @return bool True when this member has exhausted their budget for the
	 *              current window.
	 */
	private function is_budget_exhausted() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		$key   = 'gl_igdb_budget_' . $user_id;
		$count = get_transient( $key );

		if ( false === $count ) {
			set_transient( $key, 1, self::BUDGET_WINDOW ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- a 300s per-member rate-limit counter, not a content cache; matches this class's other coordination-transient exemptions.

			return false;
		}

		if ( (int) $count >= self::BUDGET_LIMIT ) {
			return true;
		}

		set_transient( $key, (int) $count + 1, self::BUDGET_WINDOW ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- a 300s per-member rate-limit counter, not a content cache; matches this class's other coordination-transient exemptions.

		return false;
	}

	/**
	 * Strips every character that could let a value break out of an
	 * Apicalypse double-quoted string literal and inject additional clauses
	 * (`fields`, `limit`, etc.) into the built query (SE-4).
	 *
	 * Strips outright — the delimiter (`"`), the statement separator (`;`),
	 * a literal backslash, and control characters — rather than
	 * backslash-escaping `"` and `\` the way a caller might for a language
	 * with a documented backslash-escape convention: IGDB's own Apicalypse
	 * syntax documents no such convention, so a previous `\"`-escaping
	 * approach here was relying on undocumented parser behaviour that, if
	 * wrong, would itself let a crafted `\"` sequence terminate the literal
	 * early. A search term has no legitimate use for any of these
	 * characters.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function escape_apicalypse_string( $value ) {
		$value = preg_replace( '/[\x00-\x1F\x7F"\\\\;]/', '', (string) $value );

		return null === $value ? '' : $value;
	}

	/**
	 * The single method in the plugin that calls
	 * `vip_safe_wp_remote_request()` — the interception point DD-002/ADR-002
	 * relies on. Every Twitch and IGDB call, success or failure, passes
	 * through here.
	 *
	 * @param string               $url  Absolute request URL.
	 * @param array<string,mixed>  $args `vip_safe_wp_remote_request()` args —
	 *                                   must include `'method' => 'POST'` and
	 *                                   `'timeout' => 5`.
	 * @return array|WP_Error
	 */
	private function request( $url, array $args ) {
		// function_exists() guard (VIP-3): this resolves unconditionally on
		// VIP. Off VIP without the deployment pipeline's vip-polyfill.php
		// bundled yet, the un-guarded call fataled with "Call to undefined
		// function" on every outbound attempt. Degrading to the same
		// gl_igdb_unavailable WP_Error every other failure branch in this
		// class already returns is correct here — no raw wp_remote_*() call
		// is substituted in its place (the Boundaries section forbids that
		// outside this one designated wrapper); authoring the polyfill
		// itself is the pipeline's job, not this class's. See
		// Credentials::resolve()'s matching guard.
		if ( ! function_exists( 'vip_safe_wp_remote_request' ) ) {
			return new WP_Error( 'gl_igdb_unavailable', __( 'The IGDB service is temporarily unavailable.', 'game-library' ) );
		}

		// VIP-6: a real WP_Error as the fallback value, not ''. On VIP,
		// vip_safe_wp_remote_request() returns this literal fallback value
		// — not a WP_Error — whenever its own circuit breaker is open or
		// the request errored, so an empty-string fallback made
		// handle_response()'s and authenticate()'s is_wp_error() branches
		// (the code documents both as implementing AC-007(c)'s "a WP_Error
		// from the transport" mapping) unreachable on VIP: is_wp_error( '' )
		// is always false. No behavioural difference today —
		// wp_remote_retrieve_response_code( '' ) also returns '', which
		// still fails the 200 !== $code check into the same
		// gl_igdb_unavailable outcome — but the documented mapping was
		// untested-by-construction and one refactor from silently changing
		// behaviour. Both call sites already handle a WP_Error first.
		return vip_safe_wp_remote_request(
			$url,
			new WP_Error( 'gl_igdb_unavailable', __( 'The IGDB service is temporarily unavailable.', 'game-library' ) ),
			3,
			self::TIMEOUT,
			20,
			$args
		);
	}
}
