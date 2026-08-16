=== Game Collector ===
Contributors: gamecollector
Tags: games, collection, igdb, community, invite
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An invite-only community for video-game collectors: IGDB-powered libraries, statuses, follows, and an activity feed.

== Description ==

Game Collector turns a WordPress site into a private community for video-game collectors.

* **Invite-only membership** — nobody can register without a valid invite code. Generate and share codes (or one-click invite links) from the dashboard.
* **IGDB search** — members search the IGDB database and add games to their library in one click.
* **Statuses** — every game is Now Playing, Finished, Backlog, or Wishlist.
* **Front-end library** — each member's collection lives at `/my-library/` and is browsable by other members at `/library/{username}/`.
* **Follows** — members follow each other from library pages.
* **Activity feed** — `/activity/` shows what you and everyone you follow have been adding, finishing, and wishlisting.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` and activate it.
2. Create an application in the Twitch Developer Console (https://dev.twitch.tv/console/apps) — IGDB authenticates through Twitch.
3. Enter the Client ID and Client Secret under **Game Collector → Settings** and use "Test IGDB connection".
4. Generate invite codes under **Game Collector → Invites** and share the invite links.
5. Visit **Settings → Permalinks** once (or just activate the plugin) so the `/my-library/`, `/library/…/`, and `/activity/` URLs work. Pretty permalinks must be enabled.

== Frequently Asked Questions ==

= Do libraries show to logged-out visitors? =

No. The whole community is invite-only, so every front-end page requires login.

= Can themes customize the pages? =

Yes — copy a template from the plugin's `templates/` directory into a `game-collector/` folder inside your theme and edit it there.

== Changelog ==

= 1.0.0 =
* Initial release.
