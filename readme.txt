=== Game Library ===
Contributors: principalwp
Tags: games, community, igdb, invite-only, activity
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An invite-only, IGDB-backed game-tracking community: invite links, a personal library at four statuses, follows, an activity feed and a member directory.

== Description ==

Game Library turns a WordPress site into a private, invite-only community for tracking what
everyone is playing. Members hold a monthly quota of single-use invite links; invited people
register through a gated Join page; once in, a member searches IGDB, adds games to a personal
library at one of four statuses (Playing, Finished, Backlog, Wishlist), curates that library behind
a public/private toggle, follows other members, and reads an activity feed with a
Following / Everyone audience toggle.

The plugin serves eight front-end routes as plugin-owned documents (template takeover):

* `/my-library/` — your cover-forward library
* `/library/{member}/` — another member's library (read-only)
* `/activity/` — the activity feed
* `/members/` — the member directory
* `/invites/` — your invite links
* `/join/{code}/` — the gated registration page
* `/games/` — the game catalog
* `/games/{slug}/` — a game's detail + holders

== IGDB credentials ==

Configure your IGDB (Twitch) Client ID and Client Secret under **Settings → Game Library**, or
define `GL_IGDB_CLIENT_ID` and `GL_IGDB_CLIENT_SECRET` in `wp-config.php` (constants take
precedence and make the fields read-only). A secret entered through the settings screen is
encrypted at rest with `sodium_crypto_secretbox` under a key derived from the site's salts.

== Requirements ==

Pretty permalinks must be enabled (Settings → Permalinks, anything other than "Plain").

== Changelog ==

= 1.0.0 =
* Initial release.
