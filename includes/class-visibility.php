<?php
/**
 * Member visibility: the opt-in public toggle and the access matrix.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The single authority for "may this viewer see this member?" (AC-027–AC-029).
 *
 * Visibility is a strictly two-tier, auth-only gate (Q-SPEC-1, human override
 * at the spec checkpoint 2026-08-12). Every logged-in member always sees every
 * other member's full profile, library, and activity — follow status is never
 * a variable in this matrix, and there is no followers-only tier anywhere in
 * this plugin. The `members`/`public` toggle governs one thing only: whether a
 * **logged-out** visitor gets the page or a 404.
 *
 * Every profile, library, activity, and REST gate calls
 * {@see GameLib_Visibility::can_view_member()}. No surface reimplements the
 * rules, so a change to the matrix changes every surface at once.
 *
 * Storage is deliberately sparse: an absent `gamelib_visibility` meta value
 * means members-only, and nothing backfills a default row for every user. Only
 * a member's own explicit flip (or an AC-007(a) admin override) ever writes.
 */
final class GameLib_Visibility {

	/**
	 * User meta key holding the toggle (§6 Data Model).
	 *
	 * @var string
	 */
	const META_KEY = 'gamelib_visibility';

	/**
	 * Members-only: logged-in members see everything, logged-out visitors get a
	 * 404. The default for every member (AC-027a). Never called "private"
	 * anywhere in this plugin's code or copy (R-REQ-1).
	 *
	 * @var string
	 */
	const MEMBERS_ONLY = 'members';

	/**
	 * Public: additionally exposed to, and indexable by, logged-out visitors.
	 *
	 * @var string
	 */
	const PUBLIC_PROFILE = 'public';

	/**
	 * The write whitelist — the only two values that may reach user meta.
	 *
	 * @var string[]
	 */
	const VALUES = array( self::MEMBERS_ONLY, self::PUBLIC_PROFILE );

	/**
	 * A member's current visibility.
	 *
	 * Anything unrecognized — absent meta, a value written by an older version,
	 * a truncated row — reads as members-only, so a storage surprise can only
	 * ever fail closed.
	 *
	 * @param int $user_id Member id.
	 * @return string One of {@see GameLib_Visibility::VALUES}.
	 */
	public static function get( $user_id ) {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			return self::MEMBERS_ONLY;
		}

		$stored = get_user_meta( $user_id, self::META_KEY, true );

		return in_array( $stored, self::VALUES, true ) ? (string) $stored : self::MEMBERS_ONLY;
	}

	/**
	 * Is this member's profile exposed to logged-out visitors?
	 *
	 * @param int $user_id Member id.
	 * @return bool True only for an explicit `public` setting.
	 */
	public static function is_public( $user_id ) {
		return self::PUBLIC_PROFILE === self::get( $user_id );
	}

	/**
	 * Display label for a visibility state (AC-027b).
	 *
	 * The two states are "Members-only" and "Public", and this is the one place
	 * either word is written: the REST response, the account panel's toggle, the
	 * user-edit override section, and the privacy export all read it from here.
	 * Neither this method nor anything else in the plugin ever calls the first
	 * one "private" (R-REQ-1).
	 *
	 * @param string $visibility One of {@see GameLib_Visibility::VALUES}.
	 * @return string Translated label.
	 */
	public static function visibility_label( $visibility ) {
		if ( self::PUBLIC_PROFILE === $visibility ) {
			return _x( 'Public', 'profile visibility', 'game-library' );
		}

		return _x( 'Members-only', 'profile visibility', 'game-library' );
	}

	/**
	 * Persist a member's visibility and run the AC-029 side effects.
	 *
	 * Authorization is the caller's job — the REST route and the admin override
	 * each verify their own nonce and capability before calling this. What
	 * belongs here is everything that must happen *with* the write, in the same
	 * request: the generation-counter bump that drops cached visibility-derived
	 * reads (the sitemap provider's query among them), and the best-effort edge
	 * purge for every URL that names the member. Bundling them is what makes
	 * AC-029(a) true — the very next logged-out request already sees the new
	 * state.
	 *
	 * Three URLs, not one (VIP-7). The profile page is the obvious one, but the
	 * sitemap index and the members sitemap page are separately cacheable
	 * documents that list the profile, and the generation bump only reaches this
	 * plugin's *object* cache — a crawler hitting the XML inside the edge TTL
	 * would still be handed a withdrawn profile, so AC-029(b) would not be
	 * literally met.
	 *
	 * A no-op flip (the stored state already matches) writes nothing and skips
	 * every side effect.
	 *
	 * @param int    $user_id Member id.
	 * @param string $value   `members` or `public`; anything else is rejected.
	 * @return bool True when the member's visibility is the requested value on return.
	 */
	public static function set( $user_id, $value ) {
		$user_id = absint( $user_id );
		$value   = sanitize_key( (string) $value );

		if ( $user_id <= 0 || ! in_array( $value, self::VALUES, true ) ) {
			return false;
		}

		if ( ! get_userdata( $user_id ) instanceof WP_User ) {
			return false;
		}

		if ( self::get( $user_id ) === $value ) {
			return true;
		}

		/*
		 * Read once, before the write, and never again afterwards (VIP-1).
		 *
		 * The old shape read the provider's page *count* before the meta write
		 * and then again after the generation bump — a guaranteed cache miss
		 * running the whole public-member scan inside a member-triggered REST
		 * request. This asks a different question, and one whose answer the flip
		 * does not change: which sitemap page does this member sit on? The
		 * provider orders by user id, so the answer is derived from the members
		 * *below* them, and it is the same page whichever direction the flip goes
		 * — the page they are leaving, or the page they are arriving on.
		 */
		$sitemap_page = self::can_purge() ? GameLib_SEO::member_sitemap_page( $user_id ) : 0;

		update_user_meta( $user_id, self::META_KEY, $value );

		GameLib_Cache::bump( GameLib_Cache::SCOPE_VISIBILITY );

		self::purge_url( self::profile_url( $user_id ) );
		self::purge_sitemap( $sitemap_page );

		return true;
	}

	/**
	 * Purge the sitemap documents that can name a member (AC-029b, VIP-7).
	 *
	 * Two URLs, not up to 51 (VIP-5): the index, because a flip can add or
	 * remove the members entry from it entirely, and the one provider page the
	 * member appears on. Both directions of AC-029(b) are covered by the single
	 * page number, which is computed from the member's position rather than from
	 * the page count: a withdrawal purges the page that was listing them (even
	 * when that page has just ceased to exist), and an opt-in purges the page
	 * that must now list them.
	 *
	 * Pages after the member's shift by one entry, and every URL on them is
	 * still a public profile — a pagination drift the next TTL corrects, not a
	 * withdrawn profile left on display. Purge volume is throttled on VIP, and a
	 * throttled purge silently no-ops, so spending the budget on the URLs that
	 * carry the guarantee is what makes the guarantee hold.
	 *
	 * @param int $page Provider page the member occupies; 0 purges the index only.
	 * @return int Number of URLs actually handed to the purge helper.
	 */
	private static function purge_sitemap( $page ) {
		if ( ! self::can_purge() ) {
			return 0;
		}

		$page   = absint( $page );
		$pages  = ( $page > 0 ) ? array( $page ) : array();
		$purged = 0;

		foreach ( GameLib_SEO::sitemap_urls( $pages ) as $url ) {
			$purged += self::purge_url( $url ) ? 1 : 0;
		}

		return $purged;
	}

	/**
	 * Is a page-cache purge reachable from PHP at all?
	 *
	 * Only on VIP. Checked before the sitemap enumeration as well as inside
	 * {@see purge_url()}, so a non-VIP install pays neither the page-count query
	 * nor the URL composition for a call that could only no-op.
	 *
	 * @return bool True when the platform exposes an edge-purge helper.
	 */
	private static function can_purge() {
		return function_exists( 'wpcom_vip_purge_edge_cache_for_url' );
	}

	/**
	 * The AC-028 access matrix — enforced server-side on every request.
	 *
	 * Keyed on exactly two inputs: whether the viewer is an authenticated
	 * member, and the owner's own setting. Follow status is not an input
	 * (DD-008/DD-014).
	 *
	 * - (a) the owner always sees their own page;
	 * - (b) any other logged-in member sees the full page, whatever the owner's
	 *   setting and whoever follows whom;
	 * - (c) a logged-out visitor at a members-only member: denied → the caller
	 *   renders the shared 404 (byte-comparable to a nonexistent member, AC-028e);
	 * - (d) a logged-out visitor at a public member: allowed.
	 *
	 * A logged-in user who is not a member (no plugin capability) is a visitor
	 * for (b) and falls through to (c)/(d), per the spec's definition of
	 * "member" — the check is a capability, never `is_user_logged_in()`.
	 *
	 * @param int $viewer_id Viewer's user id; 0 for a logged-out visitor.
	 * @param int $owner_id  Member whose profile/library/activity is requested.
	 * @return bool True when the viewer may see the owner's full page.
	 */
	public static function can_view_member( $viewer_id, $owner_id ) {
		$viewer_id = absint( $viewer_id );
		$owner_id  = absint( $owner_id );

		if ( $owner_id <= 0 || ! get_userdata( $owner_id ) instanceof WP_User ) {
			// No such member: denied by the same gate that denies (c), so the
			// two cases are indistinguishable from outside (AC-028e).
			return false;
		}

		// (a) The owner, always — even if their role carries no plugin capability.
		if ( $viewer_id > 0 && $viewer_id === $owner_id ) {
			return true;
		}

		// (b) Any other logged-in member, regardless of the owner's setting.
		if ( GameLib_Capabilities::is_member( $viewer_id ) ) {
			return true;
		}

		// (c)/(d) Logged-out (or non-member): only public profiles.
		return self::is_public( $owner_id );
	}

	/**
	 * Canonical URL of a member's profile route (DD-006).
	 *
	 * The one place the `/members/{nicename}/` URL is composed; the router and
	 * every purge/canonical/sitemap caller reuse it rather than concatenating
	 * the path again.
	 *
	 * @param int $user_id Member id.
	 * @return string Absolute URL, or '' when the user does not exist.
	 */
	public static function profile_url( $user_id ) {
		$user = get_userdata( absint( $user_id ) );

		if ( ! $user instanceof WP_User || '' === (string) $user->user_nicename ) {
			return '';
		}

		return home_url( user_trailingslashit( 'members/' . $user->user_nicename ) );
	}

	/**
	 * Best-effort page-cache purge for one URL (AC-029c).
	 *
	 * Only VIP's edge cache is reachable from PHP, and only on VIP — hence the
	 * `function_exists()` guard, and hence "best effort". The spec states it
	 * plainly: copies already cached or scraped elsewhere cannot be recalled by
	 * this or any other call.
	 *
	 * @param string $url Absolute URL to purge.
	 * @return bool True when a purge was actually requested.
	 */
	public static function purge_url( $url ) {
		$url = esc_url_raw( (string) $url );

		/*
		 * The guard is written out here rather than delegated to can_purge():
		 * static analysis only narrows an undefined function from a
		 * `function_exists()` call in the same scope as the call it protects.
		 */
		if ( '' === $url || ! function_exists( 'wpcom_vip_purge_edge_cache_for_url' ) ) {
			return false;
		}

		wpcom_vip_purge_edge_cache_for_url( $url );

		return true;
	}
}
