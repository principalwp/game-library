<?php
/**
 * IGDB API client — token lifecycle, request wrapper, and error taxonomy.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's only path to IGDB.
 *
 * Every outbound IGDB call in the plugin goes through {@see request()}: it
 * attaches the Twitch app access token, sets an explicit timeout, performs the
 * single permitted 401 → re-mint → retry, and turns every failure into one of
 * five typed outcomes that callers switch on (AC-010):
 *
 * - `unconfigured`  — no credentials, or Twitch/IGDB rejected the ones set.
 * - `rate_limited`  — HTTP 429; the 4 req/s ceiling was crossed.
 * - `unavailable`   — network error, timeout, or 5xx.
 * - `malformed`     — 4xx other than auth, or a body that is not JSON array.
 * - `empty`         — a successful call that matched nothing (not an error).
 *
 * The first four are `WP_Error` objects whose error code is the taxonomy term
 * verbatim; the fifth is an empty array. {@see classify()} collapses any return
 * value to one term so a caller needs a single switch, and no caller has to
 * re-derive a failure class from an HTTP status.
 *
 * Two hard rules shape the rest of this class:
 *
 * 1. **One token, reused.** An application may hold at most 25 Twitch app
 *    access tokens; minting per request would break the whole site once that
 *    ceiling was reached. The token lives in ONE transient holding both the
 *    value and its absolute expiry — never a fresh/backup key pair — and a
 *    cache miss simply mints again (AC-012).
 * 2. **Secrets stay in memory.** `IGDB_CLIENT_ID` / `IGDB_CLIENT_SECRET` are
 *    read through the bootstrap accessor at call time and used only as request
 *    headers or a form-encoded body. They are never persisted, never placed in
 *    a URL (which webservers and proxies log), never interpolated into an error
 *    message, and this class writes no log lines at all (AC-NFR-003).
 *
 * Authorization is the caller's job: this class is invoked from REST routes,
 * admin actions, and cron alike, so it deliberately performs no capability or
 * nonce checks of its own (AC-008e is enforced at the REST layer).
 */
final class GameLib_IGDB_Client {

	/**
	 * Twitch OAuth2 token endpoint (client-credentials grant).
	 *
	 * @var string
	 */
	const TOKEN_ENDPOINT = 'https://id.twitch.tv/oauth2/token';

	/**
	 * IGDB API v4 base URL. Every IGDB endpoint is a POST beneath it.
	 *
	 * @var string
	 */
	const API_BASE = 'https://api.igdb.com/v4';

	/**
	 * Single transient holding `array( 'token' => string, 'expires_at' => int )`
	 * (AC-012a).
	 *
	 * @var string
	 */
	const TOKEN_TRANSIENT = 'gamelib_igdb_token';

	/**
	 * Transient holding the runtime-resolved Steam external-game-source id
	 * (DD-016 — the deprecated `category = 1` literal is never used).
	 *
	 * @var string
	 */
	const EXTERNAL_SOURCE_TRANSIENT = 'gamelib_ext_source';

	/**
	 * Key prefix for search-result transients: `gamelib_search_{md5}` (AC-008d).
	 *
	 * @var string
	 */
	const SEARCH_TRANSIENT_PREFIX = 'gamelib_search_';

	/**
	 * Key prefix for the short-lived marker recording that a search term's last
	 * upstream attempt failed retryably (PB-4).
	 *
	 * A distinct key, not the result cache: this is a coordination entry, and a
	 * later successful search must be able to overwrite the results without
	 * having to reason about whether the value it is replacing is a result set
	 * or an error.
	 *
	 * @var string
	 */
	const SEARCH_FAILURE_PREFIX = 'gamelib_search_fail_';

	/**
	 * How long a failure marker is *honoured*, in seconds.
	 *
	 * Long enough that a member re-running the same search — or a page of
	 * members hitting the same popular term — does not answer an IGDB 429 with
	 * more traffic; short enough that a transient outage clears on its own
	 * without anybody having to wait out the 15-minute result cache.
	 *
	 * This is a window carried *inside* the entry, not the entry's lifetime
	 * (VIP-3). The marker is read back and returned to the caller as the answer,
	 * which makes it a cached read — and a cached read may not be stored below
	 * AC-NFR-010(a)'s 900-second floor, which is also VIP's own object-cache
	 * floor. `set_transient()` is not inspected by
	 * `WordPressVIPMinimum.Performance.LowExpiryCacheTime`, so nothing but this
	 * comment would have caught it.
	 *
	 * @var int
	 */
	const SEARCH_FAILURE_TTL = 90;

	/**
	 * Lifetime of the stored failure marker (VIP-3).
	 *
	 * The 15-minute floor every other cached read in the plugin is clamped to by
	 * {@see GameLib_Cache::set()}; this call goes to `set_transient()` directly,
	 * so it carries the floor itself. A marker older than
	 * {@see SEARCH_FAILURE_TTL} is treated as absent on read, so the longer lease
	 * changes no behaviour — it only stops a sub-900s entry reaching memcached.
	 *
	 * @var int
	 */
	const SEARCH_FAILURE_ENTRY_TTL = GameLib_Cache::MIN_TTL;

	/**
	 * Key prefix for the per-term regeneration claim.
	 *
	 * @var string
	 */
	const SEARCH_LOCK_PREFIX = 'gamelib_search_lock_';

	/**
	 * Lifetime of a regeneration claim — comfortably past the 3s user-visible
	 * timeout, so the claim outlives the request that took it out even when that
	 * request is killed.
	 *
	 * @var int
	 */
	const SEARCH_LOCK_TTL = 10;

	/**
	 * Jitter applied to the search cache TTL, as a fraction (PB-4).
	 *
	 * A fixed TTL means every entry written during a traffic spike expires in
	 * the same second and the spike repeats against IGDB. +/-10% spreads it.
	 *
	 * @var float
	 */
	const SEARCH_TTL_JITTER = 0.1;

	/**
	 * Floor for the token transient's lifetime.
	 *
	 * `expires_in − DAY_IN_SECONDS` goes negative for any token minted with a
	 * lifetime under a day, which would make the transient expire on write and
	 * turn every request into a mint. One hour is the shortest lease this class
	 * will take out.
	 *
	 * @var int
	 */
	const TOKEN_TTL_FLOOR = HOUR_IN_SECONDS;

	/**
	 * Ceiling for the token transient's lifetime.
	 *
	 * Memcached — and therefore every transient on an object-cache-backed
	 * install, VIP included — reads an expiry **above 30 days** as an absolute
	 * Unix timestamp rather than a relative offset. A Twitch app token lives
	 * ~60 days, so `expires_in − DAY_IN_SECONDS` is ~59 days: the entry would be
	 * stored with an expiry of `1970-03-02`, i.e. already expired, and every
	 * IGDB call would re-mint a token. Invisible off-platform, where the
	 * options-table fallback treats the same number as an offset and works.
	 *
	 * 29 days keeps the whole lease on the relative side of that boundary.
	 * {@see get_token()} re-checks the absolute `expires_at` it stored anyway,
	 * so a shorter lease only means an earlier re-mint, never a stale token.
	 *
	 * The plugin's three other transients are all well under the boundary
	 * (search 15 min, Steam owned-games 30 min, external-source id 1 day).
	 *
	 * @var int
	 */
	const TOKEN_TTL_CEILING = 29 * DAY_IN_SECONDS;

	/**
	 * Timeout for anything on a user-visible request path (AC-NFR-005).
	 *
	 * @var int
	 */
	const TIMEOUT_USER = 3;

	/**
	 * Timeout ceiling for background (cron) batch calls (AC-NFR-005, DD-017).
	 *
	 * @var int
	 */
	const TIMEOUT_BACKGROUND = 10;

	/**
	 * IGDB's maximum `limit` — and therefore the largest id/appid chunk one
	 * request may ask for.
	 *
	 * @var int
	 */
	const MAX_PER_REQUEST = 500;

	/**
	 * Default result count for a member-facing search (AC-008a).
	 *
	 * @var int
	 */
	const SEARCH_LIMIT = 20;

	/**
	 * Field list every game query requests, verbatim from AC-008(a).
	 *
	 * IGDB omits null fields rather than nulling them, so asking for a field is
	 * no guarantee of receiving it — everything except `id` and `name` is
	 * optional downstream (C-REQ-16).
	 *
	 * @var string
	 */
	const GAME_FIELDS = 'name,slug,summary,first_release_date,cover.image_id,platforms.name,genres.name,total_rating,total_rating_count,url';

	/**
	 * IGDB endpoints this client may address.
	 *
	 * {@see request()} composes a URL from `API_BASE` plus this value, so the
	 * list is what keeps a caller-supplied string from steering the request
	 * anywhere else.
	 *
	 * @var string[]
	 */
	const ENDPOINTS = array( 'games', 'external_games', 'external_game_sources', 'search', 'multiquery' );

	/**
	 * Taxonomy: credentials absent, or rejected by Twitch/IGDB.
	 *
	 * @var string
	 */
	const ERROR_UNCONFIGURED = 'unconfigured';

	/**
	 * Taxonomy: HTTP 429 — the documented 4 requests/second ceiling.
	 *
	 * @var string
	 */
	const ERROR_RATE_LIMITED = 'rate_limited';

	/**
	 * Taxonomy: transport failure, timeout, or 5xx.
	 *
	 * @var string
	 */
	const ERROR_UNAVAILABLE = 'unavailable';

	/**
	 * Taxonomy: a 4xx that is not authentication, or an unparseable body — a
	 * query/code defect rather than a transient condition.
	 *
	 * @var string
	 */
	const ERROR_MALFORMED = 'malformed';

	/**
	 * Taxonomy: a successful call that matched nothing. Not a failure — the
	 * caller renders its own no-results state (AC-009).
	 *
	 * @var string
	 */
	const RESULT_EMPTY = 'empty';

	/**
	 * Taxonomy: a successful call with at least one row.
	 *
	 * @var string
	 */
	const RESULT_OK = 'ok';

	/**
	 * The four failure terms, in the order AC-010 lists them.
	 *
	 * @var string[]
	 */
	const ERROR_CODES = array(
		self::ERROR_UNCONFIGURED,
		self::ERROR_RATE_LIMITED,
		self::ERROR_UNAVAILABLE,
		self::ERROR_MALFORMED,
	);

	/**
	 * Whether both IGDB credentials resolve to a non-empty value.
	 *
	 * Presence only — the values themselves are never returned, echoed, or
	 * stored, which is what lets the settings screen render a status line
	 * without ever holding a secret (AC-054a, AC-NFR-003b).
	 *
	 * @return bool True when a request can at least be attempted.
	 */
	public static function is_configured() {
		return '' !== gamelib_get_secret( 'IGDB_CLIENT_ID' ) && '' !== gamelib_get_secret( 'IGDB_CLIENT_SECRET' );
	}

	/**
	 * Search IGDB for games matching a member's query (AC-008).
	 *
	 * The query is normalized (trimmed, whitespace-collapsed, lower-cased) and
	 * that normalized form is both what gets sent and what gets hashed into the
	 * transient key, so two spellings that differ only in case or spacing share
	 * one cache entry and one upstream request. Nothing about the query is
	 * logged, here or anywhere else in the plugin (Never Do #13).
	 *
	 * @param string $query Raw member input.
	 * @param array  $args  Optional. {
	 *     @type int  $limit      Result cap, 1–500. Default self::SEARCH_LIMIT.
	 *     @type bool $background True inside a cron tick (10s timeout, plain
	 *                            wp_remote_post). Default false.
	 * }
	 * @return array|WP_Error Game rows (possibly empty — the `empty` outcome),
	 *                        or a taxonomy WP_Error.
	 */
	public static function search( $query, array $args = array() ) {
		$normalized = self::normalize_query( $query );

		if ( '' === $normalized ) {
			return array();
		}

		$limit = isset( $args['limit'] ) ? absint( $args['limit'] ) : self::SEARCH_LIMIT;
		$limit = max( 1, min( self::MAX_PER_REQUEST, $limit ) );

		/*
		 * The limit is part of the hashed material: a 5-row candidate lookup
		 * (import stage 3) and a 20-row member search share a query string but
		 * not a result set, and serving one for the other would silently
		 * truncate the search page.
		 */
		$hash          = md5( $normalized . '|' . $limit );
		$transient_key = self::SEARCH_TRANSIENT_PREFIX . $hash;
		$cached        = get_transient( $transient_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$recent_failure = self::recent_search_failure( $hash );

		if ( null !== $recent_failure ) {
			return $recent_failure;
		}

		if ( ! self::claim_search( $hash ) ) {
			/*
			 * Another request for this exact term is already in flight. Waiting
			 * on it is not an option (no blocking primitive, and the whole point
			 * is to not hold a worker), so this one answers with the degraded
			 * state AC-010 already renders rather than opening a second socket
			 * to the same endpoint for the same rows.
			 */
			return self::error(
				self::ERROR_UNAVAILABLE,
				__( 'IGDB search is busy. Try again in a moment.', 'game-library' )
			);
		}

		$body = sprintf(
			'search "%s"; fields %s; where version_parent = null; limit %d;',
			self::escape_string( $normalized ),
			self::GAME_FIELDS,
			$limit
		);

		$response = self::request( 'games', $body, $args );

		self::release_search( $hash );

		if ( is_wp_error( $response ) ) {
			self::remember_search_failure( $hash, $response );

			return $response;
		}

		$games = self::usable_games( $response );

		// An empty result is cached too — a search for a game IGDB does not
		// carry must not re-query on every keystroke that repeats it.
		set_transient( $transient_key, $games, self::search_cache_ttl() );

		return $games;
	}

	/**
	 * The recorded failure for a search term, if its last attempt failed
	 * retryably and recently (PB-4).
	 *
	 * Without this, every `rate_limited`/`unavailable` answer returned uncached
	 * and the next identical search re-issued the request at a 3s blocking
	 * timeout — the plugin answering an IGDB 429 with more traffic.
	 *
	 * @param string $hash Hashed search term and limit.
	 * @return WP_Error|null The same taxonomy error the failed attempt returned,
	 *                       or null when there is no live marker.
	 */
	private static function recent_search_failure( $hash ) {
		$marker = get_transient( self::SEARCH_FAILURE_PREFIX . $hash );

		if ( ! is_array( $marker ) || ! isset( $marker['code'], $marker['message'] ) ) {
			return null;
		}

		/*
		 * The window is inside the payload, not in the TTL (VIP-3): the entry
		 * lives for the object cache's 900-second floor, and a marker older than
		 * the window it advertises is simply absent as far as a caller is
		 * concerned.
		 */
		$recorded_at = isset( $marker['at'] ) ? (int) $marker['at'] : 0;

		if ( $recorded_at < 1 || ( time() - $recorded_at ) > self::SEARCH_FAILURE_TTL ) {
			return null;
		}

		$code = (string) $marker['code'];

		// A marker whose code is outside the taxonomy is not something a caller
		// knows how to render — treat it as absent.
		if ( ! in_array( $code, self::ERROR_CODES, true ) ) {
			return null;
		}

		return self::error( $code, (string) $marker['message'] );
	}

	/**
	 * Record a retryable upstream failure against a search term (PB-4).
	 *
	 * `unconfigured` and `malformed` are deliberately not recorded:
	 * `unconfigured` is an operator state that clears the moment a constant is
	 * defined, and re-running it costs no outbound request at all.
	 *
	 * @param string   $hash  Hashed search term and limit.
	 * @param WP_Error $error The taxonomy error to replay.
	 * @return void
	 */
	private static function remember_search_failure( $hash, WP_Error $error ) {
		$code = $error->get_error_code();

		if ( self::ERROR_RATE_LIMITED !== $code && self::ERROR_UNAVAILABLE !== $code ) {
			return;
		}

		set_transient(
			self::SEARCH_FAILURE_PREFIX . $hash,
			array(
				'code'    => $code,
				'message' => (string) $error->get_error_message(),
				// The window this marker is honoured for is read from here, not
				// from the entry's own expiry (VIP-3).
				'at'      => time(),
			),
			self::SEARCH_FAILURE_ENTRY_TTL
		);
	}

	/**
	 * Claim the right to regenerate one search term's results (PB-4).
	 *
	 * `wp_cache_add()` is atomic under Memcached and Redis, so exactly one
	 * concurrent caller wins. This is a coordination entry, not a cached read
	 * standing in for a lock: no `wp_cache_get`/`wp_cache_set` pair, and nothing
	 * reads the value (Never Do #15, AC-NFR-010a). On an install with no
	 * persistent object cache the array is per-request, so the add always
	 * succeeds and the behaviour is exactly what it was before.
	 *
	 * @param string $hash Hashed search term and limit.
	 * @return bool True when this caller may dispatch the request.
	 */
	private static function claim_search( $hash ) {
		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- SEARCH_LOCK_TTL is a 10s coordination claim, not a cached read; a longer lease would refuse legitimate searches after a killed request.
		return (bool) wp_cache_add( self::SEARCH_LOCK_PREFIX . $hash, 1, GameLib_Cache::GROUP, self::SEARCH_LOCK_TTL );
	}

	/**
	 * Drop a regeneration claim once the request it guarded has answered.
	 *
	 * @param string $hash Hashed search term and limit.
	 * @return void
	 */
	private static function release_search( $hash ) {
		wp_cache_delete( self::SEARCH_LOCK_PREFIX . $hash, GameLib_Cache::GROUP );
	}

	/**
	 * Search cache TTL with jitter applied (PB-4).
	 *
	 * @return int Lifetime in seconds.
	 */
	private static function search_cache_ttl() {
		$jitter = (int) round( GAMELIB_SEARCH_CACHE_TTL * self::SEARCH_TTL_JITTER );

		if ( $jitter < 1 ) {
			return GAMELIB_SEARCH_CACHE_TTL;
		}

		return max( 1, GAMELIB_SEARCH_CACHE_TTL + wp_rand( -$jitter, $jitter ) );
	}

	/**
	 * Fetch canonical game records by IGDB id.
	 *
	 * Ids are de-duplicated and split into chunks of `MAX_PER_REQUEST`, one
	 * request each, run serially — parallelism would approach IGDB's 8-open-
	 * request ceiling for no gain at this volume.
	 *
	 * @param int[] $ids  IGDB game ids.
	 * @param array $args Optional. `background` bool, as in {@see search()}.
	 * @return array|WP_Error Game rows keyed numerically, or a taxonomy
	 *                        WP_Error from the first chunk that failed.
	 */
	public static function get_games_by_ids( array $ids, array $args = array() ) {
		$ids = self::positive_ints( $ids );

		if ( empty( $ids ) ) {
			return array();
		}

		$games = array();

		foreach ( array_chunk( $ids, self::MAX_PER_REQUEST ) as $chunk ) {
			$body = sprintf(
				'fields id,%s; where id = (%s); limit %d;',
				self::GAME_FIELDS,
				implode( ',', $chunk ),
				count( $chunk )
			);

			$response = self::request( 'games', $body, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$games = array_merge( $games, self::usable_games( $response ) );
		}

		return $games;
	}

	/**
	 * Cross-reference Steam appids against IGDB game ids (import stage 1).
	 *
	 * `uid` is a **String** field on IGDB's side, so the appids are quoted in
	 * the `where` clause. An unquoted integer list is accepted by the API and
	 * matches nothing at all — a silent zero-result import rather than an
	 * error, which is why the quoting lives here and not in the caller.
	 *
	 * The source filter is the id resolved from `/v4/external_game_sources`
	 * at runtime; the deprecated `category = 1` literal is never sent
	 * (DD-016, Never Do #18).
	 *
	 * @param int[] $appids Steam application ids.
	 * @param array $args   Optional. `background` bool, as in {@see search()}.
	 * @return array|WP_Error Rows of `array( 'uid' => string, 'game' => int,
	 *                        'name' => string )`, or a taxonomy WP_Error.
	 */
	public static function get_external_games_by_appids( array $appids, array $args = array() ) {
		$appids = self::positive_ints( $appids );

		if ( empty( $appids ) ) {
			return array();
		}

		$source_id = self::get_external_source_id( $args );

		if ( is_wp_error( $source_id ) ) {
			return $source_id;
		}

		$rows = array();

		foreach ( array_chunk( $appids, self::MAX_PER_REQUEST ) as $chunk ) {
			$body = sprintf(
				'fields uid,game,name,external_game_source; where external_game_source = %d & uid = ("%s"); limit %d;',
				$source_id,
				implode( '","', $chunk ),
				count( $chunk )
			);

			$response = self::request( 'external_games', $body, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			foreach ( $response as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['uid'], $row['game'] ) ) {
					continue;
				}

				if ( ! is_scalar( $row['uid'] ) || absint( $row['game'] ) < 1 ) {
					continue;
				}

				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * The IGDB id of the Steam external-game source, resolved once a day.
	 *
	 * IGDB does not document a numeric id for the Steam source and the legacy
	 * `category` enum is deprecated, so the id is looked up by name from
	 * `/v4/external_game_sources` and cached for a day (DD-016). Failures are
	 * deliberately not cached: the lookup is one request per day, and a
	 * negative cache would extend one 429 into 24 hours of broken imports.
	 *
	 * @param array $args Optional. `background` bool, as in {@see search()}.
	 * @return int|WP_Error Source id, or a taxonomy WP_Error.
	 */
	public static function get_external_source_id( array $args = array() ) {
		$cached = get_transient( self::EXTERNAL_SOURCE_TRANSIENT );

		if ( is_numeric( $cached ) && (int) $cached > 0 ) {
			return (int) $cached;
		}

		$response = self::request( 'external_game_sources', 'fields id,name; limit ' . self::MAX_PER_REQUEST . ';', $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$source_id = 0;

		foreach ( $response as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['name'] ) || ! is_string( $row['name'] ) ) {
				continue;
			}

			if ( 'steam' === strtolower( trim( $row['name'] ) ) ) {
				$source_id = absint( $row['id'] );
				break;
			}
		}

		if ( $source_id < 1 ) {
			return self::error(
				self::ERROR_MALFORMED,
				__( 'IGDB did not return a Steam entry in its external game sources.', 'game-library' )
			);
		}

		set_transient( self::EXTERNAL_SOURCE_TRANSIENT, $source_id, DAY_IN_SECONDS );

		return $source_id;
	}

	/**
	 * Live end-to-end check for the admin settings screen (AC-054a).
	 *
	 * Exercises the whole path a member's search takes — credentials, token,
	 * signed request, JSON body — with the smallest query IGDB accepts, on the
	 * user-visible 3-second budget because an admin is waiting on it.
	 *
	 * @return true|WP_Error True on success, or the taxonomy WP_Error whose
	 *                       code names the failure class. The message is safe
	 *                       to display: it never contains a credential.
	 */
	public static function test_connection() {
		$response = self::request( 'games', 'fields id,name; limit 1;' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Perform one authenticated IGDB request.
	 *
	 * This is the wrapper every public method above delegates to, and the one
	 * later services (import stages 2 and 3) compose their own APICalypse
	 * bodies for — values inside such a body must be run through
	 * {@see escape_string()} first.
	 *
	 * A 401 gets exactly one re-mint and one retry, then fails. IGDB's 25-token
	 * ceiling makes an unbounded refresh loop a way to lock the whole site out,
	 * and 429/5xx/network failures get no inline retry at all on user-facing
	 * paths (D-REQ-17) — the caller renders a degraded state instead.
	 *
	 * @param string $endpoint One of {@see ENDPOINTS} (no leading slash).
	 * @param string $body     APICalypse query body.
	 * @param array  $args     Optional. `background` bool, as in {@see search()}.
	 * @return array|WP_Error Decoded rows, or a taxonomy WP_Error.
	 */
	public static function request( $endpoint, $body, array $args = array() ) {
		if ( ! in_array( $endpoint, self::ENDPOINTS, true ) ) {
			return self::error(
				self::ERROR_MALFORMED,
				__( 'Unknown IGDB endpoint requested.', 'game-library' )
			);
		}

		$client_id = gamelib_get_secret( 'IGDB_CLIENT_ID' );

		if ( '' === $client_id ) {
			return self::unconfigured_error();
		}

		$background = ! empty( $args['background'] );
		$token      = self::get_token( false, $background );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = self::dispatch( $endpoint, $body, $client_id, $token, $background );

		if ( 401 === self::status_code( $response ) ) {
			// The one retry AC-012(c) allows: re-mint, replay, stop.
			$token = self::get_token( true, $background );

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$response = self::dispatch( $endpoint, $body, $client_id, $token, $background );
		}

		return self::parse( $response );
	}

	/**
	 * Reduce any client return value to a single taxonomy term.
	 *
	 * Gives callers one switch — `ok` / `empty` / the four failure terms —
	 * instead of a chain of `is_wp_error()`/`empty()` tests, and keeps the
	 * mapping of failure class to user-facing copy in exactly one place per
	 * consumer (AC-010).
	 *
	 * @param mixed $result Return value from any public method of this class.
	 * @return string One of the six taxonomy terms.
	 */
	public static function classify( $result ) {
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();

			return in_array( $code, self::ERROR_CODES, true ) ? $code : self::ERROR_UNAVAILABLE;
		}

		if ( is_array( $result ) && empty( $result ) ) {
			return self::RESULT_EMPTY;
		}

		return self::RESULT_OK;
	}

	/**
	 * Make a value safe to embed inside a double-quoted APICalypse string.
	 *
	 * Backslashes and double quotes are escaped, and control characters —
	 * newlines above all, since APICalypse statements are newline/semicolon
	 * delimited — are dropped. Public because import stages 2 and 3 build their
	 * own `where name ~ "…"` bodies and must not hand-roll this.
	 *
	 * @param string $value Untrusted value (member input, Steam-supplied title).
	 * @return string Escaped value, without surrounding quotes.
	 */
	public static function escape_string( $value ) {
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $value );

		if ( null === $value ) {
			return '';
		}

		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
	}

	/**
	 * Normalize a search query for both transport and cache keying (AC-008d).
	 *
	 * Trim, collapse internal whitespace, lower-case. IGDB's `search` operator
	 * is fuzzy and case-insensitive, so sending the normalized form costs
	 * nothing in result quality and guarantees the string that was hashed into
	 * the cache key is the string that produced the cached results.
	 *
	 * @param string $query Raw query.
	 * @return string Normalized query; '' when the input held nothing usable.
	 */
	public static function normalize_query( $query ) {
		$normalized = preg_replace( '/\s+/u', ' ', (string) $query );

		if ( null === $normalized ) {
			return '';
		}

		$normalized = trim( $normalized );

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $normalized, 'UTF-8' );
		}

		return strtolower( $normalized );
	}

	/**
	 * The current Twitch app access token, minting one when needed.
	 *
	 * The transient holds the token and its absolute expiry in a single entry
	 * (AC-012a): no fresh/backup key pair, so there is no window in which two
	 * entries disagree about which token is live. A miss — first call, an
	 * object-cache flush, an expiry — mints a replacement rather than failing
	 * (AC-012b).
	 *
	 * @param bool $force      True to ignore the cached value and mint (the
	 *                         401 path).
	 * @param bool $background True inside a cron tick.
	 * @return string|WP_Error Access token, or a taxonomy WP_Error.
	 */
	private static function get_token( $force = false, $background = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TOKEN_TRANSIENT );

			if ( is_array( $cached ) && isset( $cached['token'], $cached['expires_at'] ) ) {
				$token = is_string( $cached['token'] ) ? $cached['token'] : '';

				if ( '' !== $token && (int) $cached['expires_at'] > time() ) {
					return $token;
				}
			}
		}

		return self::mint_token( $background );
	}

	/**
	 * Mint a fresh app access token from Twitch.
	 *
	 * The three credentials travel in a form-encoded request body, never the
	 * query string: a URL is logged by webservers, proxies, and WP's own HTTP
	 * debugging hooks, and the client secret must not appear in any of them
	 * (AC-NFR-003c).
	 *
	 * @param bool $background True inside a cron tick.
	 * @return string|WP_Error Access token, or a taxonomy WP_Error.
	 */
	private static function mint_token( $background = false ) {
		$client_id     = gamelib_get_secret( 'IGDB_CLIENT_ID' );
		$client_secret = gamelib_get_secret( 'IGDB_CLIENT_SECRET' );

		if ( '' === $client_id || '' === $client_secret ) {
			return self::unconfigured_error();
		}

		$timeout = $background ? self::TIMEOUT_BACKGROUND : self::TIMEOUT_USER;

		$args = array(
			'method'     => 'POST',
			'timeout'    => $timeout,
			'headers'    => array( 'Accept' => 'application/json' ),
			'body'       => array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'client_credentials',
			),
			'user-agent' => self::user_agent(),
		);

		$response = self::send( self::TOKEN_ENDPOINT, $args, $background );
		$status   = self::status_code( $response );

		if ( is_wp_error( $response ) ) {
			return self::error(
				self::ERROR_UNAVAILABLE,
				__( 'Could not reach the IGDB authentication service.', 'game-library' )
			);
		}

		if ( 429 === $status ) {
			return self::error(
				self::ERROR_RATE_LIMITED,
				__( 'The IGDB authentication service is rate limiting this site.', 'game-library' )
			);
		}

		if ( $status >= 400 && $status < 500 ) {
			// 400/401/403 from the token endpoint means the credentials
			// themselves were refused — an operator problem, not a transient.
			return self::unconfigured_error();
		}

		if ( 200 !== $status ) {
			return self::error(
				self::ERROR_UNAVAILABLE,
				__( 'The IGDB authentication service is temporarily unavailable.', 'game-library' )
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || ! isset( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			return self::error(
				self::ERROR_MALFORMED,
				__( 'The IGDB authentication service returned an unreadable response.', 'game-library' )
			);
		}

		$token = trim( $data['access_token'] );

		if ( '' === $token ) {
			return self::error(
				self::ERROR_MALFORMED,
				__( 'The IGDB authentication service returned an empty token.', 'game-library' )
			);
		}

		/*
		 * Twitch app tokens live ~60 days. Retiring ours a full day early
		 * means a token is replaced during ordinary traffic rather than at the
		 * moment it expires mid-request, and the floor keeps a short-lived or
		 * absent `expires_in` from producing a transient that expires on write.
		 *
		 * The ceiling keeps the lease under Memcached's 30-day relative/absolute
		 * expiry boundary — see TOKEN_TTL_CEILING. Both clamps are load-bearing
		 * and both fail silently on a stock install, so neither may be dropped
		 * because "the number looks fine".
		 */
		$expires_in = isset( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 0;
		$ttl        = max( self::TOKEN_TTL_FLOOR, $expires_in - DAY_IN_SECONDS );
		$ttl        = min( self::TOKEN_TTL_CEILING, $ttl );

		/*
		 * The value below is a live credential (VIP-4).
		 *
		 * On VIP — and on any install with a persistent object cache — a
		 * transient is a memcached entry and never touches the database, which is
		 * the intended storage. Without one, WordPress falls back to `wp_options`
		 * and this bearer token sits in a row named `_transient_gamelib_igdb_token`,
		 * readable from a database dump, from `wp option get`, or from any plugin
		 * that enumerates options. There is no better portable store, so the
		 * mitigations are: the token is derived and re-mintable (the Client ID and
		 * Secret it comes from are environment constants and are never stored),
		 * and `uninstall.php` deletes it by name for exactly this reason. The
		 * README's configuration section documents the exposure.
		 */
		set_transient(
			self::TOKEN_TRANSIENT,
			array(
				'token'      => $token,
				'expires_at' => time() + $ttl,
			),
			$ttl
		);

		return $token;
	}

	/**
	 * Send one POST to an IGDB endpoint with the auth headers attached.
	 *
	 * @param string $endpoint   Endpoint segment, already whitelisted.
	 * @param string $body       APICalypse query body.
	 * @param string $client_id  IGDB client id (a public identifier, unlike the secret).
	 * @param string $token      Bearer token.
	 * @param bool   $background True inside a cron tick.
	 * @return array|WP_Error Raw HTTP response.
	 */
	private static function dispatch( $endpoint, $body, $client_id, $token, $background ) {
		$args = array(
			'method'     => 'POST',
			'timeout'    => $background ? self::TIMEOUT_BACKGROUND : self::TIMEOUT_USER,
			'headers'    => array(
				// Capitalisation is significant to IGDB, "Bearer " included.
				'Client-ID'     => $client_id,
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'text/plain',
			),
			'body'       => (string) $body,
			'user-agent' => self::user_agent(),
		);

		return self::send( self::API_BASE . '/' . $endpoint, $args, $background );
	}

	/**
	 * Transport for every outbound call this class makes.
	 *
	 * User-visible requests go through `vip_safe_wp_remote_request()` so VIP's
	 * circuit breaker can shed a failing upstream; background batches use
	 * `wp_remote_post()` because the safe wrapper clamps timeouts to 5 seconds
	 * and a cron batch is allowed 10 (DD-017). Both land in the WP HTTP API, so
	 * `pre_http_request` intercepts every one of them — which is what makes the
	 * E2E suite able to mock IGDB at all (D-REQ-47).
	 *
	 * Off-VIP installs (local development, Playground) have no
	 * `vip_safe_wp_remote_request()`; there the equivalent core call carries
	 * the same explicit timeout.
	 *
	 * @param string $url        Absolute URL.
	 * @param array  $args       Request args, always including an explicit timeout.
	 * @param bool   $background True inside a cron tick.
	 * @return array|WP_Error Raw HTTP response.
	 */
	private static function send( $url, array $args, $background ) {
		if ( $background ) {
			return wp_remote_post( $url, $args );
		}

		if ( function_exists( 'vip_safe_wp_remote_request' ) ) {
			return vip_safe_wp_remote_request( $url, '', 3, self::TIMEOUT_USER, 20, $args );
		}

		return wp_remote_request( $url, $args );
	}

	/**
	 * Turn a raw HTTP response into rows or a taxonomy error (AC-010).
	 *
	 * @param array|WP_Error $response Raw HTTP response.
	 * @return array|WP_Error Decoded rows, or a taxonomy WP_Error.
	 */
	private static function parse( $response ) {
		if ( is_wp_error( $response ) ) {
			// Timeouts and DNS/connection failures arrive here.
			return self::error(
				self::ERROR_UNAVAILABLE,
				__( 'IGDB could not be reached.', 'game-library' )
			);
		}

		$status = self::status_code( $response );

		if ( 429 === $status ) {
			return self::error(
				self::ERROR_RATE_LIMITED,
				__( 'IGDB is rate limiting this site.', 'game-library' )
			);
		}

		if ( 401 === $status || 403 === $status ) {
			// Reached only after the single re-mint and retry above: the
			// credentials, not the token, are what IGDB is refusing.
			return self::unconfigured_error();
		}

		if ( $status >= 400 && $status < 500 ) {
			return self::error(
				self::ERROR_MALFORMED,
				__( 'IGDB rejected the query.', 'game-library' )
			);
		}

		if ( 200 !== $status ) {
			return self::error(
				self::ERROR_UNAVAILABLE,
				__( 'IGDB is temporarily unavailable.', 'game-library' )
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return self::error(
				self::ERROR_MALFORMED,
				__( 'IGDB returned an unreadable response.', 'game-library' )
			);
		}

		return $data;
	}

	/**
	 * HTTP status of a response, or 0 for a transport-level failure.
	 *
	 * @param array|WP_Error $response Raw HTTP response.
	 * @return int Status code.
	 */
	private static function status_code( $response ) {
		if ( is_wp_error( $response ) ) {
			return 0;
		}

		return (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Keep only rows a caller can actually store.
	 *
	 * IGDB omits null fields, so a game row is a sparse map — but a row without
	 * a positive `id` cannot be written to the shared store at all (Never Do
	 * #10), and one without a name has nothing to render. Everything else is
	 * left exactly as IGDB sent it for the store to sanitize (C-REQ-16).
	 *
	 * @param array $rows Decoded response rows.
	 * @return array Usable game rows, re-indexed.
	 */
	private static function usable_games( array $rows ) {
		$games = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['name'] ) ) {
				continue;
			}

			if ( absint( $row['id'] ) < 1 || ! is_string( $row['name'] ) || '' === trim( $row['name'] ) ) {
				continue;
			}

			$games[] = $row;
		}

		return $games;
	}

	/**
	 * Cast a caller-supplied list to unique positive integers.
	 *
	 * Out-of-range values are dropped, not folded: `absint()` would turn a
	 * negative id into a positive one and quietly fetch a different game, so a
	 * value that is not already a positive integer is discarded instead.
	 * See principal/adr/008-drop-out-of-range-igdb-ids.md — §6's Data Model
	 * prescribes `absint` for id columns; that is a write-side guarantee, and
	 * as a lookup filter it fabricates ids (`absint( -3 ) === 3`).
	 *
	 * @param array $values Ids or appids in any scalar form.
	 * @return int[] Unique positive integers, re-indexed.
	 */
	private static function positive_ints( array $values ) {
		$ints = array();

		foreach ( $values as $value ) {
			$int = is_scalar( $value ) ? (int) $value : 0;

			if ( $int > 0 ) {
				$ints[] = $int;
			}
		}

		return array_values( array_unique( $ints ) );
	}

	/**
	 * The one `unconfigured` error, so every path that means "credentials"
	 * says it identically.
	 *
	 * @return WP_Error Taxonomy error.
	 */
	private static function unconfigured_error() {
		return self::error(
			self::ERROR_UNCONFIGURED,
			__( 'IGDB credentials are missing or were rejected.', 'game-library' )
		);
	}

	/**
	 * Build a taxonomy error.
	 *
	 * The message is written for an administrator reading a Test-connection
	 * result: it names the failure class and nothing else. No credential, no
	 * token, no upstream response body ever reaches it (AC-NFR-003b/c).
	 *
	 * @param string $code    One of {@see ERROR_CODES}.
	 * @param string $message Human-readable failure class.
	 * @return WP_Error Taxonomy error.
	 */
	private static function error( $code, $message ) {
		return new WP_Error( $code, $message );
	}

	/**
	 * User-agent identifying this site to IGDB.
	 *
	 * @return string User-agent header value.
	 */
	private static function user_agent() {
		return 'GameLibrary/' . GAMELIB_VERSION . '; ' . home_url( '/' );
	}
}
