<?php
/**
 * VIP Polyfills
 *
 * Runtime polyfills for WordPress VIP platform helper functions. Enables
 * VIP-first plugin code to run on non-VIP environments (local dev, staging,
 * self-hosted, other managed hosts) without modification.
 *
 * How it works:
 *   On VIP, the platform's MU-plugins load before this file and define the
 *   real functions. Every polyfill below is guarded by function_exists(),
 *   so on VIP this file is effectively a no-op. Off VIP, the polyfills
 *   register thin fallbacks using core WordPress APIs.
 *
 * Caveats — read before relying on these in production:
 *   - Fallbacks are best-effort. They do NOT replicate VIP's circuit-breaker
 *     behaviour, edge cache, memcached layer, or SSRF protections.
 *   - Signatures are pinned to the public VIP MU-plugins upstream at
 *     https://github.com/Automattic/vip-go-mu-plugins (tracked: 2026-04).
 *     If VIP changes an upstream signature, this file must be updated.
 *   - This file deliberately does NOT define VIP_GO_APP_* constants. Those
 *     constants are how application code detects the VIP environment —
 *     defining them off-VIP would break environment branching.
 *
 * Integration:
 *   require_once __DIR__ . '/vip-polyfill.php';
 *
 *   Load from your plugin's main file before any code that may call VIP
 *   helpers. The function_exists() guards make double-loading safe.
 *
 * Related: tests/e2e/blueprints/vip-polyfills.php in this repo is a
 * separate test-oriented stub loaded by Playground E2E fixtures. That file
 * includes additional stubs (branding, loader, VIP constants) intentionally
 * excluded here to avoid corrupting production environment detection.
 *
 * @package VipPolyfill
 */

defined( 'ABSPATH' ) || exit;

/* =========================================================================
 * HTTP / remote data
 * ========================================================================= */

if ( ! function_exists( 'vip_safe_wp_remote_get' ) ) {
	/**
	 * Polyfill for vip_safe_wp_remote_get().
	 *
	 * Performs an HTTP GET with VIP's default defensive timeout. Does NOT
	 * implement circuit-breaker behaviour — on failure, this polyfill simply
	 * returns $fallback_value. It does not track URL failure counts across
	 * requests the way the VIP platform function does.
	 *
	 * @param string $url            URL to fetch.
	 * @param mixed  $fallback_value Returned on failure (default '').
	 * @param int    $threshold      Failure threshold. Unused in polyfill. (VIP default: 3.)
	 * @param int    $timeout        Request timeout in seconds. Capped at 3 to
	 *                               match VIP's safety ceiling. (VIP default: 1.)
	 * @param int    $retry          Retry interval in seconds. Unused. (VIP default: 20.)
	 * @param array  $args           Additional wp_remote_get() arguments.
	 *
	 * @return array|mixed wp_remote_get() response array on success,
	 *                     $fallback_value on failure.
	 */
	function vip_safe_wp_remote_get( $url, $fallback_value = '', $threshold = 3, $timeout = 1, $retry = 20, $args = array() ) {
		$timeout = min( max( 1, (int) $timeout ), 3 );
		$args    = array_merge( array( 'timeout' => $timeout ), (array) $args );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $fallback_value;
		}
		if ( (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return $fallback_value;
		}

		return $response;
	}
}

if ( ! function_exists( 'vip_safe_wp_remote_request' ) ) {
	/**
	 * Polyfill for vip_safe_wp_remote_request().
	 *
	 * Like vip_safe_wp_remote_get() but accepts any HTTP method via the
	 * 'method' key of $args (POST, PUT, DELETE, HEAD). Polyfill has no
	 * circuit-breaker.
	 *
	 * @param string $url            URL.
	 * @param mixed  $fallback_value Returned on failure (default '').
	 * @param int    $threshold      Unused in polyfill.
	 * @param int    $timeout        Capped at 3 seconds.
	 * @param int    $retry          Unused in polyfill.
	 * @param array  $args           wp_remote_request() args including 'method'.
	 *
	 * @return array|mixed
	 */
	function vip_safe_wp_remote_request( $url, $fallback_value = '', $threshold = 3, $timeout = 1, $retry = 20, $args = array() ) {
		$timeout = min( max( 1, (int) $timeout ), 3 );
		$args    = array_merge( array( 'timeout' => $timeout ), (array) $args );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $fallback_value;
		}
		if ( (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return $fallback_value;
		}

		return $response;
	}
}

if ( ! function_exists( 'wpcom_vip_file_get_contents' ) ) {
	/**
	 * Polyfill for wpcom_vip_file_get_contents().
	 *
	 * Fetches the body of a URL with transient-backed caching. VIP caches
	 * in memcached; transients in non-VIP environments may fall back to
	 * database storage — less efficient than memcached but still prevents
	 * hammering origins.
	 *
	 * @param string $url        URL to fetch.
	 * @param int    $timeout    Request timeout in seconds (default 3).
	 * @param int    $cache_time Cache TTL in seconds (default 900).
	 * @param array  $extra_args Additional wp_remote_get() arguments.
	 *
	 * @return string|false Response body on success, false on failure.
	 */
	function wpcom_vip_file_get_contents( $url, $timeout = 3, $cache_time = 900, $extra_args = array() ) {
		$cache_key = 'vip_pfgc_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$args = array_merge( array( 'timeout' => (int) $timeout ), (array) $extra_args );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return false;
		}
		if ( (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		set_transient( $cache_key, $body, max( 1, (int) $cache_time ) );

		return $body;
	}
}

/* =========================================================================
 * Environment
 * ========================================================================= */

if ( ! function_exists( 'vip_get_env_var' ) ) {
	/**
	 * Polyfill for vip_get_env_var().
	 *
	 * Reads a configuration value, checking PHP constants first (VIP's
	 * convention via vip-config.php), then getenv(). Returns $default when
	 * neither is set.
	 *
	 * @param string $name    Variable / constant name.
	 * @param mixed  $default Default when unset.
	 *
	 * @return mixed
	 */
	function vip_get_env_var( $name, $default = null ) {
		if ( defined( $name ) ) {
			return constant( $name );
		}
		$value = getenv( $name );
		if ( false === $value ) {
			return $default;
		}
		return $value;
	}
}

/* =========================================================================
 * Queries
 * ========================================================================= */

if ( ! function_exists( 'vip_get_random_posts' ) ) {
	/**
	 * Polyfill for vip_get_random_posts().
	 *
	 * Samples random posts without ORDER BY RAND() (forbidden on VIP and
	 * slow on non-VIP at scale). Strategy: pull a pool of recent posts
	 * (10× the requested count, minimum 50), then pick at random in PHP.
	 *
	 * Trade-off: the sample is biased toward recent posts. Matches VIP's
	 * own implementation approach closely enough for typical uses.
	 *
	 * @param int    $number     Number of posts to return.
	 * @param string $post_type  Post type (default 'post').
	 * @param bool   $return_ids Return IDs instead of WP_Post objects.
	 *
	 * @return WP_Post[]|int[]
	 */
	function vip_get_random_posts( $number = 1, $post_type = 'post', $return_ids = false ) {
		$number = max( 1, (int) $number );
		$pool   = max( $number * 10, 50 );

		$ids = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => $pool,
				'fields'                 => 'ids',
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		shuffle( $ids );
		$picked = array_slice( $ids, 0, $number );

		if ( $return_ids ) {
			return $picked;
		}
		return array_map( 'get_post', $picked );
	}
}

/* =========================================================================
 * Cached lookups
 * ========================================================================= */

if ( ! function_exists( 'wpcom_vip_url_to_postid' ) ) {
	/**
	 * Polyfill for wpcom_vip_url_to_postid().
	 *
	 * Adds transient-backed caching around url_to_postid(), which is
	 * expensive on uncached requests because it walks the rewrite rule set.
	 *
	 * @param string $url
	 *
	 * @return int Post ID, or 0 if not found.
	 */
	function wpcom_vip_url_to_postid( $url ) {
		$cache_key = 'vip_pu2p_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$post_id = (int) url_to_postid( $url );
		set_transient( $cache_key, $post_id, 3 * HOUR_IN_SECONDS );
		return $post_id;
	}
}

if ( ! function_exists( 'wpcom_vip_get_page_by_path' ) ) {
	/**
	 * Polyfill for wpcom_vip_get_page_by_path().
	 *
	 * Thin wrapper around get_page_by_path(). Core's post cache makes this
	 * cheap on cache hits, so no additional transient layer.
	 *
	 * @param string       $page_path
	 * @param string       $output
	 * @param string|array $post_type
	 *
	 * @return WP_Post|array|null
	 */
	function wpcom_vip_get_page_by_path( $page_path, $output = OBJECT, $post_type = 'page' ) {
		return get_page_by_path( $page_path, $output, $post_type );
	}
}

if ( ! function_exists( 'wpcom_vip_get_term_by' ) ) {
	/**
	 * Polyfill for wpcom_vip_get_term_by().
	 *
	 * Thin wrapper around get_term_by(). Core already caches term lookups.
	 */
	function wpcom_vip_get_term_by( $field, $value, $taxonomy, $output = OBJECT, $filter = 'raw' ) {
		return get_term_by( $field, $value, $taxonomy, $output, $filter );
	}
}

if ( ! function_exists( 'wpcom_vip_get_category_by_slug' ) ) {
	/**
	 * Polyfill for wpcom_vip_get_category_by_slug().
	 *
	 * Thin wrapper around core's get_category_by_slug().
	 */
	function wpcom_vip_get_category_by_slug( $slug ) {
		return get_category_by_slug( $slug );
	}
}

/* =========================================================================
 * Cache helpers
 * ========================================================================= */

if ( ! function_exists( 'wpcom_vip_cache_get' ) ) {
	/** Polyfill for wpcom_vip_cache_get(). */
	function wpcom_vip_cache_get( $key, $group = '' ) {
		return wp_cache_get( $key, $group );
	}
}

if ( ! function_exists( 'wpcom_vip_cache_set' ) ) {
	/** Polyfill for wpcom_vip_cache_set(). */
	function wpcom_vip_cache_set( $key, $value, $group = '', $expiration = 0 ) {
		return wp_cache_set( $key, $value, $group, $expiration );
	}
}

if ( ! function_exists( 'wpcom_vip_cache_delete' ) ) {
	/** Polyfill for wpcom_vip_cache_delete(). */
	function wpcom_vip_cache_delete( $key, $group = '' ) {
		return wp_cache_delete( $key, $group );
	}
}

if ( ! function_exists( 'vip_reset_local_object_cache' ) ) {
	/**
	 * Polyfill for vip_reset_local_object_cache().
	 *
	 * VIP's version flushes only the in-process cache without touching
	 * memcached. On non-VIP environments without a persistent object cache,
	 * the in-process cache IS the only cache, so wp_cache_flush() is
	 * equivalent. On environments with a persistent cache, callers should
	 * be aware this will flush it too.
	 */
	function vip_reset_local_object_cache() {
		wp_cache_flush();
	}
}

/* =========================================================================
 * Redirect helpers
 * ========================================================================= */

if ( ! function_exists( 'vip_redirects' ) ) {
	/**
	 * Polyfill for vip_redirects().
	 *
	 * Registers exact-path 301 redirects via the 'template_redirect' hook.
	 *
	 * @param array $redirects        Map of old_path => new_url.
	 * @param bool  $case_insensitive When true, path matching is case-insensitive.
	 */
	function vip_redirects( $redirects = array(), $case_insensitive = false ) {
		add_action(
			'template_redirect',
			function () use ( $redirects, $case_insensitive ) {
				$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
				$request     = strtok( $request_uri, '?' );
				foreach ( $redirects as $old => $new ) {
					$match = $case_insensitive
						? ( 0 === strcasecmp( $old, $request ) )
						: ( $old === $request );
					if ( $match ) {
						wp_safe_redirect( $new, 301 );
						exit;
					}
				}
			}
		);
	}
}

if ( ! function_exists( 'vip_substr_redirects' ) ) {
	/**
	 * Polyfill for vip_substr_redirects().
	 *
	 * Registers prefix-based 301 redirects.
	 *
	 * @param array $redirects      Map of old_prefix => new_url.
	 * @param bool  $append_old_uri When true, the matched suffix is appended
	 *                              to $new_url.
	 */
	function vip_substr_redirects( $redirects = array(), $append_old_uri = false ) {
		add_action(
			'template_redirect',
			function () use ( $redirects, $append_old_uri ) {
				$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
				foreach ( $redirects as $old => $new ) {
					if ( 0 === strpos( $request, $old ) ) {
						$target = $append_old_uri ? $new . substr( $request, strlen( $old ) ) : $new;
						wp_safe_redirect( $target, 301 );
						exit;
					}
				}
			}
		);
	}
}

if ( ! function_exists( 'vip_regex_redirects' ) ) {
	/**
	 * Polyfill for vip_regex_redirects().
	 *
	 * Registers regex-based 301 redirects.
	 *
	 * @param array $redirects        Map of regex_pattern => replacement_url.
	 * @param bool  $with_querystring When true, the query string is included
	 *                                in the matched subject.
	 */
	function vip_regex_redirects( $redirects = array(), $with_querystring = false ) {
		add_action(
			'template_redirect',
			function () use ( $redirects, $with_querystring ) {
				$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
				if ( ! $with_querystring ) {
					$request = strtok( $request, '?' );
				}
				foreach ( $redirects as $pattern => $new ) {
					if ( preg_match( $pattern, $request ) ) {
						$target = preg_replace( $pattern, $new, $request );
						wp_safe_redirect( $target, 301 );
						exit;
					}
				}
			}
		);
	}
}
