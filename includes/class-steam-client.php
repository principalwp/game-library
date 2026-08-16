<?php
/**
 * Steam Web API client — key handling, id validation, and the failure taxonomy.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's only path to the Steam Web API.
 *
 * Steam is reached for exactly three things, all of them in service of the
 * member-initiated import: turning a vanity name into a SteamID64, asking
 * whether that profile's game details are public, and reading the owned-games
 * list. Every one of them is a GET through {@see get()}, which attaches the
 * site's Web API key, sets an explicit timeout, and reduces the answer to one
 * of the typed outcomes callers switch on — the same taxonomy shape
 * {@see GameLib_IGDB_Client} uses, with two Steam-specific terms added:
 *
 * - `unconfigured`    — no key, or Steam refused the one set (403).
 * - `rate_limited`    — HTTP 429.
 * - `unavailable`     — network error, timeout, or 5xx.
 * - `malformed`       — 4xx other than auth, or a body that is not JSON.
 * - `not_found`       — no such vanity name / no such SteamID64.
 * - `private_profile` — the profile's game details are not public.
 * - `empty`           — a public profile that owns nothing (not an error).
 *
 * Three rules shape the rest of this class:
 *
 * 1. **`private_profile` is retryable, never persisted.** Steam privacy is a
 *    setting the member can flip at any moment, and an empty owned-games body
 *    is indistinguishable from a transient Steam hiccup, so one refusal is
 *    surfaced as an error the member can act on and re-run — it is never
 *    written to user meta, an option, or a "this account is private" flag
 *    (D-REQ-24). The only Steam state this plugin stores is the 30-minute
 *    owned-games transient (§6 Transients).
 * 2. **The key never leaves this class.** Steam authenticates with a query
 *    parameter, so the URL of every call below contains the site's secret. It
 *    is read at call time, never persisted, and no request URL is ever handed
 *    to a hook, a message, or a stored value: the failure taxonomy carries a
 *    code and a hand-written sentence, never anything Steam or the URL builder
 *    produced. A key cannot be logged by a surface that emits no diagnostics
 *    (AC-NFR-003c).
 * 3. **Valve documents the requests, not the responses.** Field names in the
 *    bodies are community-sourced, so every read is guarded — a missing key is
 *    a normal outcome, never a notice (researcher-external-api §5.5).
 *
 * Authorization is the caller's job: this class is invoked from REST routes,
 * admin actions, and cron alike, so it performs no capability or nonce checks
 * of its own.
 */
final class GameLib_Steam_Client {

	/**
	 * Public Steam Web API host.
	 *
	 * `partner.steam-api.com` is the publisher-key host and is deliberately not
	 * used: publisher keys are a documented cause of empty owned-games
	 * responses (§6 Development Prerequisites).
	 *
	 * @var string
	 */
	const API_BASE = 'https://api.steampowered.com';

	/**
	 * Vanity name → SteamID64.
	 *
	 * @var string
	 */
	const ENDPOINT_VANITY = 'ISteamUser/ResolveVanityURL/v1/';

	/**
	 * Profile summaries — the source of `communityvisibilitystate`.
	 *
	 * @var string
	 */
	const ENDPOINT_SUMMARIES = 'ISteamUser/GetPlayerSummaries/v2/';

	/**
	 * Owned-games list.
	 *
	 * @var string
	 */
	const ENDPOINT_OWNED = 'IPlayerService/GetOwnedGames/v1/';

	/**
	 * Key prefix for the per-SteamID owned-games transient:
	 * `gamelib_owned_{steamid}` (AC-040d).
	 *
	 * @var string
	 */
	const OWNED_TRANSIENT_PREFIX = 'gamelib_owned_';

	/**
	 * Lifetime of that transient. Long enough that mashing the import button
	 * issues one upstream call, short enough that a member who just bought a
	 * game does not wait long to see it (AC-040d, §6 Transients).
	 *
	 * @var int
	 */
	const OWNED_CACHE_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Largest owned-games list this class will cache (VIP-9).
	 *
	 * Memcached refuses an entry over **1MB** and does so silently — the `set`
	 * reports nothing and the next `get` is a miss. A serialized
	 * `{appid, name}` list runs ~70–90 bytes a row, so the plugin's own
	 * 10,000-row import cap is already ~700–900KB. 5,000 rows keeps the entry
	 * comfortably inside the limit; a larger library skips the cache rather than
	 * paying to serialize something that will never be read back.
	 *
	 * @var int
	 */
	const OWNED_CACHE_MAX_ROWS = 5000;

	/**
	 * Cache key prefix for a resolved vanity name (PB-9).
	 *
	 * @var string
	 */
	const VANITY_CACHE_PREFIX = 'vanity:';

	/**
	 * Cache key prefix for a profile summary (PB-9).
	 *
	 * @var string
	 */
	const SUMMARY_CACHE_PREFIX = 'summary:';

	/**
	 * Lifetime of a resolved vanity name.
	 *
	 * A vanity → SteamID64 mapping is effectively immutable: the name is owned
	 * by the account until the member renames it, which is rare and self-
	 * correcting (the import fails against the old id and they retype it).
	 *
	 * @var int
	 */
	const VANITY_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * User meta holding the member's SteamID64 (§6 Data Model).
	 *
	 * The canonical name of the key, for every surface that reads, writes, or
	 * deletes it — the account panel, the Steam import, the REST disconnect, the
	 * privacy exporter and eraser. It lives here beside
	 * {@see sanitize_steamid64()}, the rule that decides what may be stored under
	 * it, so the validated value and the persisted value cannot drift.
	 * `uninstall.php` sweeps the same key as a literal (the plugin's classes are
	 * not loaded for the option/meta passes).
	 *
	 * @var string
	 */
	const STEAMID_META = 'gamelib_steamid';

	/**
	 * Timeout for anything on a user-visible request path (AC-NFR-005).
	 *
	 * @var int
	 */
	const TIMEOUT_USER = 3;

	/**
	 * Timeout for background (cron) fetches. Five, not the ten a background
	 * IGDB batch may take: every Steam call goes through
	 * `vip_safe_wp_remote_get()`, whose ceiling is five (DD-017).
	 *
	 * @var int
	 */
	const TIMEOUT_BACKGROUND = 5;

	/**
	 * `communityvisibilitystate` value meaning "public". Higher is more public;
	 * anything below this is treated as private (AC-040b).
	 *
	 * @var int
	 */
	const VISIBILITY_PUBLIC = 3;

	/**
	 * `ResolveVanityURL` success codes: 1 = resolved, 42 = no such name.
	 *
	 * @var int
	 */
	const VANITY_SUCCESS = 1;

	/**
	 * No vanity name matched (AC-040a).
	 *
	 * @var int
	 */
	const VANITY_NO_MATCH = 42;

	/**
	 * Longest member-supplied vanity name this client will send. Steam's own
	 * custom-URL field stops at 32 characters; anything past double that is not
	 * a name Steam could resolve, so it fails locally rather than becoming an
	 * upstream request.
	 *
	 * @var int
	 */
	const VANITY_MAX_LENGTH = 64;

	/**
	 * Vanity name used by {@see test_connection()}.
	 *
	 * Deliberately not a real person's profile: whether Steam answers `1` or
	 * `42`, an HTTP 200 with a parseable body is proof that the key works,
	 * which is the whole question that action asks (AC-054b).
	 *
	 * @var string
	 */
	const TEST_VANITY = 'gamelib-connection-test';

	/**
	 * Taxonomy: no Steam Web API key, or Steam refused the one configured.
	 *
	 * @var string
	 */
	const ERROR_UNCONFIGURED = 'unconfigured';

	/**
	 * Taxonomy: HTTP 429 — Steam's 100,000 calls/day allowance is exhausted or
	 * the site is being throttled.
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
	 * Taxonomy: a 4xx that is not authentication, or an unparseable body.
	 *
	 * @var string
	 */
	const ERROR_MALFORMED = 'malformed';

	/**
	 * Taxonomy: Steam has no such vanity name or SteamID64 (AC-040a).
	 *
	 * @var string
	 */
	const ERROR_NOT_FOUND = 'not_found';

	/**
	 * Taxonomy: the profile exists but its game details are not public
	 * (AC-040b). Retryable by design — see the class docblock.
	 *
	 * @var string
	 */
	const ERROR_PRIVATE_PROFILE = 'private_profile';

	/**
	 * Taxonomy: a successful call that returned nothing — a public profile
	 * that owns no games. Not a failure.
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
	 * Every failure term, in the order the class docblock lists them.
	 *
	 * @var string[]
	 */
	const ERROR_CODES = array(
		self::ERROR_UNCONFIGURED,
		self::ERROR_RATE_LIMITED,
		self::ERROR_UNAVAILABLE,
		self::ERROR_MALFORMED,
		self::ERROR_NOT_FOUND,
		self::ERROR_PRIVATE_PROFILE,
	);

	/**
	 * Whether a Steam Web API key resolves to a non-empty value.
	 *
	 * Presence only — the key itself is never returned, echoed, or stored,
	 * which is what lets the settings screen render a status line without ever
	 * holding a secret (AC-054b, AC-NFR-003b).
	 *
	 * @return bool True when a request can at least be attempted.
	 */
	public static function is_configured() {
		return '' !== gamelib_get_secret( 'STEAM_WEB_API_KEY' );
	}

	/**
	 * Whether a value is a syntactically valid SteamID64 (AC-040a).
	 *
	 * Format only: Steam alone knows whether the account exists, and finding
	 * that out costs a request. Surrounding whitespace is tolerated because
	 * members paste these ids.
	 *
	 * @param mixed $value Candidate id.
	 * @return bool True for exactly 17 digits.
	 */
	public static function is_valid_steamid64( $value ) {
		return '' !== self::sanitize_steamid64( $value );
	}

	/**
	 * Canonical form of a SteamID64, or '' when the value is not one.
	 *
	 * The single place the `/^[0-9]{17}$/` rule of §6's user-meta table lives:
	 * callers that store `gamelib_steamid` should store what this returns, so
	 * the validated value and the persisted value cannot drift.
	 *
	 * @param mixed $value Candidate id.
	 * @return string 17-digit id, or '' when the input is not a SteamID64.
	 */
	public static function sanitize_steamid64( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$candidate = trim( (string) $value );

		return preg_match( '/^[0-9]{17}$/', $candidate ) ? $candidate : '';
	}

	/**
	 * Resolve a member-supplied vanity name to a SteamID64 (AC-040a).
	 *
	 * `steamcommunity.com/id/{name}` is what members know; the rest of the
	 * import speaks SteamID64 only. A name Steam does not carry comes back as
	 * `success == 42`, which becomes the `not_found` term with the message
	 * AC-040(a) pins.
	 *
	 * @param string $name Raw member input.
	 * @param array  $args Optional. {
	 *     @type bool $background True inside a cron tick (5s timeout). Default false.
	 * }
	 * @return string|WP_Error SteamID64, or a taxonomy WP_Error.
	 */
	public static function resolve_vanity( $name, array $args = array() ) {
		$vanity = sanitize_text_field( (string) $name );
		$vanity = trim( $vanity );

		if ( '' === $vanity || strlen( $vanity ) > self::VANITY_MAX_LENGTH ) {
			return self::not_found_error();
		}

		/*
		 * PB-9: a member who mistypes, retries or double-submits used to pay a
		 * fresh 3s-timeout round trip every time for a mapping that never
		 * changes. Only the *successful* resolution is cached — a failure stays
		 * retryable by design (D-REQ-24) — and the key is a hash of the name, so
		 * nothing member-typed becomes a cache key.
		 */
		$cache_key = self::VANITY_CACHE_PREFIX . md5( strtolower( $vanity ) );
		$found     = false;
		$cached    = GameLib_Cache::get( GameLib_Cache::SCOPE_STEAM_MAP, $cache_key, $found );

		if ( $found && is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$payload = self::get(
			self::ENDPOINT_VANITY,
			array(
				'vanityurl' => $vanity,
				'url_type'  => '1',
			),
			$args
		);

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$success = isset( $payload['success'] ) && is_scalar( $payload['success'] ) ? (int) $payload['success'] : 0;

		if ( self::VANITY_SUCCESS !== $success ) {
			/*
			 * 42 is the documented "no match"; every other value is
			 * undocumented and still means no id came back, so the member sees
			 * the one answer that is true either way.
			 */
			return self::not_found_error();
		}

		$steamid = self::sanitize_steamid64( isset( $payload['steamid'] ) ? $payload['steamid'] : '' );

		if ( '' === $steamid ) {
			// Steam claimed success without a usable id — an upstream shape
			// change, not something the member can fix.
			return self::error(
				self::ERROR_MALFORMED,
				__( 'Steam returned an unreadable response.', 'game-library' )
			);
		}

		GameLib_Cache::set( GameLib_Cache::SCOPE_STEAM_MAP, $cache_key, $steamid, self::VANITY_CACHE_TTL );

		return $steamid;
	}

	/**
	 * Read one profile summary — the `communityvisibilitystate` pre-flight
	 * source (AC-040b).
	 *
	 * Only the fields this plugin has a use for are returned, each sanitized on
	 * the way out, so nothing downstream has to remember that these values came
	 * from a third party.
	 *
	 * @param string $steamid SteamID64.
	 * @param array  $args    Optional. `background` bool, as in {@see resolve_vanity()}.
	 * @return array|WP_Error {
	 *     @type string $steamid                 SteamID64 as Steam reported it.
	 *     @type int    $communityvisibilitystate 3 = public; lower is more private; 0 = absent.
	 *     @type string $personaname             Display name, may be ''.
	 *     @type string $profileurl              Profile URL, may be ''.
	 * }
	 *     Or a taxonomy WP_Error.
	 */
	public static function get_player_summary( $steamid, array $args = array() ) {
		$steamid = self::sanitize_steamid64( $steamid );

		if ( '' === $steamid ) {
			return self::not_found_error();
		}

		// PB-9, same reasoning as resolve_vanity(): the pre-flight is a second
		// blocking call on the REST POST a member is waiting on, and a repeat
		// within the cache floor learns nothing new. Successes only.
		$cache_key = self::SUMMARY_CACHE_PREFIX . $steamid;
		$found     = false;
		$cached    = GameLib_Cache::get( GameLib_Cache::SCOPE_STEAM_MAP, $cache_key, $found );

		if ( $found && is_array( $cached ) ) {
			return $cached;
		}

		$payload = self::get(
			self::ENDPOINT_SUMMARIES,
			array( 'steamids' => $steamid ),
			$args
		);

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$players = isset( $payload['players'] ) && is_array( $payload['players'] ) ? $payload['players'] : array();
		$player  = null;

		foreach ( $players as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// `steamids` carried exactly one id, but the response is a list:
			// prefer the row that answers the question that was asked.
			if ( isset( $row['steamid'] ) && self::sanitize_steamid64( $row['steamid'] ) === $steamid ) {
				$player = $row;
				break;
			}

			if ( null === $player ) {
				$player = $row;
			}
		}

		if ( null === $player ) {
			// A well-formed answer with no player in it: no such account.
			return self::not_found_error();
		}

		$summary = array(
			'steamid'                  => $steamid,
			'communityvisibilitystate' => isset( $player['communityvisibilitystate'] ) && is_scalar( $player['communityvisibilitystate'] )
				? (int) $player['communityvisibilitystate']
				: 0,
			'personaname'              => isset( $player['personaname'] ) && is_scalar( $player['personaname'] )
				? sanitize_text_field( (string) $player['personaname'] )
				: '',
			'profileurl'               => isset( $player['profileurl'] ) && is_scalar( $player['profileurl'] )
				? esc_url_raw( (string) $player['profileurl'] )
				: '',
		);

		GameLib_Cache::set( GameLib_Cache::SCOPE_STEAM_MAP, $cache_key, $summary );

		return $summary;
	}

	/**
	 * The AC-040(b) pre-flight: may this profile's games be read?
	 *
	 * Runs before the owned-games call so a private profile produces the
	 * actionable message naming the exact Steam setting instead of the
	 * "owns zero games" reading an empty body would otherwise get.
	 *
	 * @param string $steamid SteamID64.
	 * @param array  $args    Optional. `background` bool, as in {@see resolve_vanity()}.
	 * @return true|WP_Error True when the profile is public; otherwise a
	 *                       taxonomy WP_Error (`private_profile` when the
	 *                       account exists but is not public).
	 */
	public static function preflight( $steamid, array $args = array() ) {
		$summary = self::get_player_summary( $steamid, $args );

		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		if ( self::VISIBILITY_PUBLIC !== $summary['communityvisibilitystate'] ) {
			return self::private_profile_error();
		}

		return true;
	}

	/**
	 * The member's owned games (AC-040d).
	 *
	 * `include_appinfo` is what makes Steam send titles rather than bare
	 * appids, and `include_played_free_games` is what keeps free-to-play games
	 * — a large part of most libraries — from being silently omitted.
	 *
	 * The result is cached per SteamID for {@see OWNED_CACHE_TTL} so repeated
	 * import attempts cost one upstream call; failures are never cached, since
	 * `private_profile` in particular describes a setting the member is
	 * probably on their way to changing (D-REQ-24).
	 *
	 * @param string $steamid SteamID64.
	 * @param array  $args    Optional. `background` bool, as in {@see resolve_vanity()}.
	 * @return array|WP_Error List of `array( 'appid' => int, 'name' => string )`
	 *                        (possibly empty — the `empty` outcome), or a
	 *                        taxonomy WP_Error.
	 */
	public static function get_owned_games( $steamid, array $args = array() ) {
		$steamid = self::sanitize_steamid64( $steamid );

		if ( '' === $steamid ) {
			return self::not_found_error();
		}

		$transient_key = self::OWNED_TRANSIENT_PREFIX . $steamid;
		$cached        = get_transient( $transient_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$payload = self::get(
			self::ENDPOINT_OWNED,
			array(
				'steamid'                   => $steamid,
				'include_appinfo'           => 'true',
				'include_played_free_games' => 'true',
				'format'                    => 'json',
			),
			$args
		);

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( empty( $payload ) ) {
			/*
			 * `{"response":{}}` — HTTP 200, no `game_count`, no `games`. The
			 * documented shape for "game details are not public", and
			 * indistinguishable from a Steam hiccup, so it is reported as the
			 * retryable private-profile error rather than an empty library.
			 */
			return self::private_profile_error();
		}

		$games = isset( $payload['games'] ) && is_array( $payload['games'] ) ? $payload['games'] : array();
		$owned = array();

		foreach ( $games as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['appid'] ) || ! is_scalar( $row['appid'] ) ) {
				continue;
			}

			$appid = (int) $row['appid'];

			if ( $appid < 1 || isset( $owned[ $appid ] ) ) {
				continue;
			}

			$owned[ $appid ] = array(
				'appid' => $appid,
				// `name` arrives only with include_appinfo, and Steam titles
				// are member-visible strings from a third party.
				'name'  => isset( $row['name'] ) && is_scalar( $row['name'] )
					? sanitize_text_field( (string) $row['name'] )
					: '',
			);
		}

		$owned = array_values( $owned );

		/*
		 * An empty list from a profile that answered with a real payload means
		 * the member owns nothing — worth caching, so a retry does not re-ask.
		 *
		 * Above OWNED_CACHE_MAX_ROWS the write is skipped (VIP-9): a serialized
		 * {appid,name} list at the plugin's own 10,000-row cap is ~700–900KB,
		 * close to Memcached's 1MB entry limit, and over it the backend rejects
		 * the `set` **silently** — so the entry would never be readable and
		 * every import start would re-request the list anyway, having paid to
		 * serialize and ship it first. Skipping is the same outcome without the
		 * cost, and the only members it affects are the ones whose import is
		 * about to be refused by the row cap regardless.
		 */
		if ( count( $owned ) <= self::OWNED_CACHE_MAX_ROWS ) {
			set_transient( $transient_key, $owned, self::OWNED_CACHE_TTL );
		}

		return $owned;
	}

	/**
	 * Drop everything this class cached about one SteamID.
	 *
	 * For the paths that end a member's relationship with a Steam account —
	 * the AC-046(b) disconnect and the AC-047 eraser — so nothing read on behalf
	 * of that account outlives it.
	 *
	 * The profile summary joins the owned-games list here (PB-9): it is the same
	 * account's data with the same lifetime obligation. The vanity mapping does
	 * not — it is a public name→id fact about Steam, holds no data about this
	 * site's member, and is reachable only by someone who already knows the
	 * name.
	 *
	 * @param string $steamid SteamID64.
	 * @return void
	 */
	public static function delete_owned_games_cache( $steamid ) {
		$steamid = self::sanitize_steamid64( $steamid );

		if ( '' === $steamid ) {
			return;
		}

		delete_transient( self::OWNED_TRANSIENT_PREFIX . $steamid );
		GameLib_Cache::delete( GameLib_Cache::SCOPE_STEAM_MAP, self::SUMMARY_CACHE_PREFIX . $steamid );
	}

	/**
	 * Live end-to-end check for the admin settings screen (AC-054b).
	 *
	 * Exercises the key, the URL builder, the transport, and the JSON parse
	 * without touching anyone's account: a vanity lookup for a name nobody
	 * owns answers `42`, which is a perfectly successful API call. A rejected
	 * key answers 403 instead, which is the failure class an administrator
	 * needs to see.
	 *
	 * @return true|WP_Error True on success, or the taxonomy WP_Error whose
	 *                       code names the failure class. The message is safe
	 *                       to display: it never contains the key.
	 */
	public static function test_connection() {
		$payload = self::get(
			self::ENDPOINT_VANITY,
			array(
				'vanityurl' => self::TEST_VANITY,
				'url_type'  => '1',
			)
		);

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		return true;
	}

	/**
	 * Reduce any client return value to a single taxonomy term.
	 *
	 * Gives callers one switch — `ok` / `empty` / the six failure terms —
	 * instead of a chain of `is_wp_error()`/`empty()` tests.
	 *
	 * @param mixed $result Return value from any public method of this class.
	 * @return string One of the eight taxonomy terms.
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
	 * Perform one authenticated Steam GET and hand back its `response` object.
	 *
	 * @param string $endpoint One of the three ENDPOINT_* constants.
	 * @param array  $params   Query parameters, unencoded.
	 * @param array  $args     Optional. `background` bool, as in {@see resolve_vanity()}.
	 * @return array|WP_Error The decoded `response` object (possibly empty), or
	 *                        a taxonomy WP_Error.
	 */
	private static function get( $endpoint, array $params, array $args = array() ) {
		$key = gamelib_get_secret( 'STEAM_WEB_API_KEY' );

		if ( '' === $key ) {
			return self::unconfigured_error();
		}

		$timeout = empty( $args['background'] ) ? self::TIMEOUT_USER : self::TIMEOUT_BACKGROUND;
		$url     = add_query_arg(
			self::encode_params( array_merge( array( 'key' => $key ), $params ) ),
			self::API_BASE . '/' . $endpoint
		);

		$response = self::send(
			$url,
			array(
				'method'     => 'GET',
				'timeout'    => $timeout,
				'headers'    => array( 'Accept' => 'application/json' ),
				'user-agent' => self::user_agent(),
			)
		);

		return self::parse( $response );
	}

	/**
	 * Percent-encode query values exactly once.
	 *
	 * `add_query_arg()` does not encode: it re-emits values through
	 * `build_query()` with encoding switched off, so a member-supplied vanity
	 * name containing `&` or a space would either break the URL or inject a
	 * second parameter next to the key. Encoding here — and only here — is the
	 * single pass the values get.
	 * See principal/adr/010-steam-query-encoding.md — the task text assumes
	 * `add_query_arg()` encodes; measured against WordPress, it does not.
	 *
	 * @param array $params Unencoded parameters.
	 * @return array Parameters with encoded values.
	 */
	private static function encode_params( array $params ) {
		$encoded = array();

		foreach ( $params as $name => $value ) {
			$encoded[ $name ] = rawurlencode( is_scalar( $value ) ? (string) $value : '' );
		}

		return $encoded;
	}

	/**
	 * Transport for every outbound call this class makes.
	 *
	 * `vip_safe_wp_remote_get()` is the wrapper VIP requires for user-visible
	 * GETs and the one this repo's PHPCS ruleset leaves open (`wp_remote_get()`
	 * is restricted). It ships only on VIP and in the E2E polyfill mu-plugin,
	 * so an unguarded call would fatal on a plain WordPress install; the
	 * fallback is the generic core request carrying the identical explicit
	 * timeout. Both land in the WP HTTP API, so `pre_http_request` intercepts
	 * every one of them (D-REQ-47).
	 *
	 * @param string $url  Absolute URL, key included.
	 * @param array  $args Request args, always including an explicit timeout.
	 * @return array|WP_Error Raw HTTP response.
	 */
	private static function send( $url, array $args ) {
		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			return vip_safe_wp_remote_get( $url, '', 3, $args['timeout'], 20, $args );
		}

		return wp_remote_request( $url, $args );
	}

	/**
	 * Turn a raw HTTP response into Steam's `response` object or a taxonomy
	 * error.
	 *
	 * The request URL is deliberately not passed in: it carries the site's key
	 * in a query parameter, and nothing downstream of here has a use for it that
	 * would be worth the risk of it reaching a message or a hook (AC-NFR-003c).
	 *
	 * @param array|WP_Error $response Raw HTTP response.
	 * @return array|WP_Error Decoded `response` object, or a taxonomy WP_Error.
	 */
	private static function parse( $response ) {
		if ( is_wp_error( $response ) ) {
			// Timeouts and DNS/connection failures arrive here.
			return self::error(
				self::ERROR_UNAVAILABLE,
				__( 'Steam could not be reached.', 'game-library' )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $status ) {
			return self::error(
				self::ERROR_RATE_LIMITED,
				__( 'Steam is rate limiting this site.', 'game-library' )
			);
		}

		if ( 403 === $status ) {
			// Steam's answer to a key it does not accept.
			return self::unconfigured_error();
		}

		if ( 401 === $status ) {
			// Steam refuses data the member has not made visible.
			return self::private_profile_error();
		}

		if ( $status >= 400 && $status < 500 ) {
			return self::error(
				self::ERROR_MALFORMED,
				__( 'Steam rejected the request.', 'game-library' )
			);
		}

		if ( 200 !== $status ) {
			return self::error(
				self::ERROR_UNAVAILABLE,
				__( 'Steam is temporarily unavailable.', 'game-library' )
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || ! isset( $data['response'] ) || ! is_array( $data['response'] ) ) {
			return self::error(
				self::ERROR_MALFORMED,
				__( 'Steam returned an unreadable response.', 'game-library' )
			);
		}

		return $data['response'];
	}

	/**
	 * The one `not_found` error, with the message AC-040(a) pins verbatim.
	 *
	 * @return WP_Error Taxonomy error.
	 */
	private static function not_found_error() {
		return self::error(
			self::ERROR_NOT_FOUND,
			__( "We couldn't find that Steam profile.", 'game-library' )
		);
	}

	/**
	 * The one `private_profile` error.
	 *
	 * The message names the exact setting the member has to change, in Steam's
	 * own words, because "your profile is private" leaves them hunting through
	 * four privacy toggles (AC-040b).
	 *
	 * @return WP_Error Taxonomy error.
	 */
	private static function private_profile_error() {
		return self::error(
			self::ERROR_PRIVATE_PROFILE,
			__( 'This Steam profile does not share its game details. In Steam, set Profile → Privacy Settings → Game details → Public, then try again.', 'game-library' )
		);
	}

	/**
	 * The one `unconfigured` error, so every path that means "the key" says it
	 * identically.
	 *
	 * @return WP_Error Taxonomy error.
	 */
	private static function unconfigured_error() {
		return self::error(
			self::ERROR_UNCONFIGURED,
			__( 'The Steam Web API key is missing or was rejected.', 'game-library' )
		);
	}

	/**
	 * Build a taxonomy error.
	 *
	 * The message is written to be shown as-is: it names the failure class and
	 * nothing else. No key, no URL, no upstream response body ever reaches it
	 * (AC-NFR-003b/c).
	 *
	 * @param string $code    One of {@see ERROR_CODES}.
	 * @param string $message Human-readable failure class.
	 * @return WP_Error Taxonomy error.
	 */
	private static function error( $code, $message ) {
		return new WP_Error( $code, $message );
	}

	/**
	 * User-agent identifying this site to Steam.
	 *
	 * @return string User-agent header value.
	 */
	private static function user_agent() {
		return 'GameLibrary/' . GAMELIB_VERSION . '; ' . home_url( '/' );
	}
}
