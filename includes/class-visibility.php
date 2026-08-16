<?php
/**
 * The single owner of the `_gl_profile_public` opt-in contract.
 *
 * @package Game_Library
 */

namespace Game_Library;

use Game_Library\Data\Generations;
use Game_Library\Data\Library_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Visibility.
 *
 * Whether a member has opted their library public gates AC-033(e)/(g),
 * AC-034, AC-038(g), AC-041, AC-043(f)/(j), AC-044, and AC-045(c) — it is the
 * plugin's single most security-sensitive read. Before this class existed,
 * nine sites across the plugin each re-declared the `_gl_profile_public`
 * meta-key literal and re-implemented the `'1' === get_user_meta( … )`
 * string comparison by hand (arch-pre-3 architecture review, finding AR-4).
 *
 * The `'1'`/`'0'` string encoding (not an int, not a bool) is load-bearing:
 * `set_public()` is the only writer, and every reader compares against
 * `self::PUBLIC_VALUE` as a strict string — a future writer that stored an
 * int `1` or a bool `true` instead would make every reader silently return
 * "private" with no error anywhere.
 *
 * Known, deliberately unresolved divergence (out of scope for this fix —
 * escalated to the lead as a product decision): `Sitemap_Provider`'s own
 * `public_member_query_args()` additionally requires the `gl_manage_library`
 * capability alongside this class's `META_KEY`/`PUBLIC_VALUE` pair, while
 * every render-time reader below (`Router`, `Robots`, `Schema_Org`,
 * `Library_Controller`, `Social_Controller`, and the `my-library.php`/
 * `members.php`/`game-single.php` templates) checks the meta alone. A member
 * holding `_gl_profile_public = '1'` without `gl_manage_library` is
 * therefore served and indexable at `/library/{nicename}/` today but absent
 * from the sitemap — this class intentionally does not resolve that gap
 * either direction; it only gives the shared half of the definition one
 * owner instead of nine.
 */
final class Visibility {

	/**
	 * User meta key storing the opt-in.
	 *
	 * @var string
	 */
	public const META_KEY = '_gl_profile_public';

	/**
	 * The stored value meaning "public" — a literal string, never an int or
	 * bool. See the class docblock for why this encoding is load-bearing.
	 *
	 * @var string
	 */
	public const PUBLIC_VALUE = '1';

	/**
	 * Whether a member has opted their library public.
	 *
	 * @param int $user_id Member.
	 * @return bool
	 */
	public static function is_public( $user_id ): bool {
		return self::PUBLIC_VALUE === get_user_meta( (int) $user_id, self::META_KEY, true );
	}

	/**
	 * Sets a member's own public/private opt-in — the only writer of
	 * `META_KEY` in this plugin. Purges every anonymously-cacheable
	 * paginated/status-filtered variant of `/library/{nicename}/` at VIP's
	 * edge in both directions (VIP-2, widened CF-VIP-1) — an opted-public
	 * member's library is one of the three routes this plugin serves
	 * anonymously at HTTP 200, held at the edge for up to 30 minutes;
	 * without this, flipping back to private would keep serving the
	 * library publicly at the edge — including every `?gl_page=N`/
	 * `?status=X` variant a member with more than one page of entries
	 * exposes, not just the base URL — for up to that long after the
	 * object-cache/database write already took effect.
	 * Also purges every `/games/{slug}/` page this member's library
	 * references (VIP-1) — a flip either direction changes whether that
	 * game's own opted-public-holder list includes this member (AC-038(g)),
	 * which `Page_Cache::purge()` on only the member's own library URL never
	 * accounted for. Also bumps `Member_Directory`'s shared generation
	 * counter (MR-4) — a visibility toggle changes membership in the
	 * sitemap's public-only subset even though it does not touch the
	 * `gl_manage_library` capability.
	 *
	 * MR-1 (cycle-3): returns early, before any write or purge, when `$public`
	 * already matches the stored value. Core's own `update_user_meta()`
	 * already short-circuits the DB write for an unchanged value, but nothing
	 * previously stopped a repeated `{"public": true}` call while already
	 * public from re-running the full purge fan-out below — a shared-cache
	 * generation bump and a deferred purge, all for a request that wrote
	 * nothing. This is the same guard `Follow_Repository::follow()` already
	 * applies before its own activity-record/generation-bump side effects
	 * (only on a genuinely new row, not a repeated no-op call) — it bounds the
	 * fan-out below to genuine state changes.
	 *
	 * MR-1 (cycle-5): the member's own game-page purges are queued via
	 * `Page_Cache::schedule_purge_for_user()`, not by reading
	 * `game_slugs_for_user()` inline here — the deferred event re-derives
	 * the slug list itself when it fires (this write does not delete any
	 * `gl_library_entries` rows, so the list is still live in the database
	 * then), keeping this synchronous, member-facing request's own cron
	 * payload to a single int rather than up to a couple thousand slugs.
	 *
	 * @param int  $user_id Member.
	 * @param bool $public  Whether the library should be visible to
	 *                      logged-out visitors.
	 * @return void
	 */
	public static function set_public( $user_id, bool $public ): void {
		$user_id = (int) $user_id;

		if ( self::is_public( $user_id ) === $public ) {
			return;
		}

		update_user_meta( $user_id, self::META_KEY, $public ? self::PUBLIC_VALUE : '0' );

		// MR-5 (cycle-7): guarded FIRST — status_counts() is a real GROUP BY
		// query on a cache miss, not a free read, and this plugin's ruled
		// portable target never has an edge to purge (CONF-2(i)), so the
		// query ran for nothing on every visibility flip before this guard.
		if ( Page_Cache::is_edge_purge_available() ) {
			$user = get_userdata( $user_id );

			if ( $user ) {
				// CF-VIP-1: every paginated/status variant, not just the
				// base URL — every status is enumerated unconditionally
				// (the default empty $scopes), not narrowed to a single
				// entry's affected status, because a visibility flip
				// changes whether the WHOLE library is served at the edge,
				// not one status's worth of it.
				Page_Cache::purge_member_library(
					$user->user_nicename,
					( new Library_Repository() )->status_counts( $user_id ),
					Library_Repository::DEFAULT_PER_PAGE
				);
			}
		}

		// PB-2/MR-1: queued as a tiny deferred event rather than resolved and
		// purged inline — this runs inside PATCH /profile/visibility, a
		// synchronous member-facing request.
		Page_Cache::schedule_purge_for_user( $user_id );

		Generations::bump( Generations::members_key() );
	}
}
