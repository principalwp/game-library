=== Game Collector ===
Contributors: gamecollector
Tags: games, collection, igdb, social, library
Requires at least: 6.3
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

An invite-only social game library powered by IGDB.

== Description ==

Game Collector gives registered members a front-end collection at `/my-library/`.
Members can search IGDB, organize games as Playing, Finished, Backlog, or Wishlist,
follow other collectors, browse their libraries, and read a personalized activity feed.

Registration is closed unless a visitor has a valid, unexpired invitation link.

== Installation ==

1. Upload the `game-collector` directory to `/wp-content/plugins/` and activate it.
2. In wp-admin, open Game Collector.
3. Create a Twitch developer application and save its IGDB client ID and secret.
4. Send invitations from the same screen.
5. Add `/my-library/` to your site navigation. The page is created on activation.

IGDB credentials never reach the browser. Search and game-detail requests are proxied
through WordPress and require an authenticated user.

== Data removal ==

Data is retained by default when the plugin is deleted. To remove its custom tables and
options on uninstall, define `GCOLLECTOR_DELETE_DATA` as `true` in `wp-config.php` first.
