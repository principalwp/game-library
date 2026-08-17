# Game Library

> An invite-only community platform where members build and share personal
> video-game libraries backed by the [IGDB](https://www.igdb.com/) catalog —
> search, curate, import, follow, and browse.

A self-contained WordPress plugin. **Requires** WordPress 6.5+ and PHP 8.4+.
Licensed **GPL-2.0-or-later**.

---

## About this repository

This plugin was built end-to-end by an agentic development pipeline — spec,
research, architecture, code, review, and demo.

The repo carries three branches. **`full-initial` and `full-designed` are one
build** in two visual designs; **`full-steam-import` is a separate, independent
pipeline run** of the same spec that grew a larger feature set:

| Branch | What it is |
|--------|------------|
| [`full-initial`](../../tree/full-initial) | The first build's first-pass output — full core functionality, the original design. |
| [`full-designed`](../../tree/full-designed) | The same first build after a dedicated design pass — a PrincipalWP-voice restyle and an in-page quick-look panel. |
| [`full-steam-import`](../../tree/full-steam-import) | A separate from-scratch build of the same spec, with an expanded feature set: **Steam library import**, **CSV/JSON file import**, **library export**, **CPT-backed public game pages**, and **bulk library actions** on top of the shared core. |

Unlike the two `full-*` design branches, this branch is **not** functionally
identical to the others — it is its own build with more features, so behaviour
and architecture differ.

> **You are viewing the `full-steam-import` branch.**

---

## Demo

A narrated walkthrough, recorded against
[WordPress Playground](https://wordpress.github.io/wordpress-playground/) with a
live IGDB search proxy (credentials stay server-side and never reach the
browser):

**▶ [`game-library-demo.webm`](game-library-demo.webm)**

![My Library — the member's landing page: cover art and per-game status](my-library.webp)

---

## What it does

Shared core (all branches):

- **Invite-only membership** — single-use invite links, admin management (list
  table, filters, resend/revoke), and mail-failure resilience.
- **IGDB-backed search** — search the IGDB catalog and add games to a personal
  library, each under one of four statuses (playing, finished, backlog,
  wishlist).
- **Personal libraries** with per-game status, cover art (text placeholders when
  none exists), a public/private visibility toggle, and pagination.
- **Members directory, following, and an activity feed** — one-directional
  follows and a per-follower activity stream.
- **Public game pages** with structured data (`VideoGame` JSON-LD), canonical
  URLs, sitemap integration, and IGDB attribution.
- **Moderation tools** — refresh cached game metadata from IGDB, all
  capability-gated.

Additional to this build (`full-steam-import`):

- **Steam library import** — a full Steam Web API client lets a member import
  their owned Steam games (vanity/SteamID resolution, privacy check, owned-games
  read) with candidate disambiguation.
- **File import (CSV/JSON)** — bulk-import a game list with a review UI to
  resolve ambiguous matches against IGDB before they land in a library.
- **Library export (CSV/JSON)** — a member can export their own library,
  separate from the GDPR/privacy exporter.
- **CPT-backed game pages** — each cached game is projected into a real
  WordPress post, backing `/games/{slug}/` URLs and admin list-table row
  actions.
- **Bulk library actions** — select multiple entries to change status or remove
  in one pass.
- **Automatic freshness** — an hourly cron re-validates stale IGDB rows against
  a 24-hour TTL, so game data self-heals within a day.

---

## Installation

1. Copy this branch into `wp-content/plugins/game-library/` (or download the ZIP
   and unzip it there). The compiled front-end assets under `build/` are
   committed, so no build step is required to run the plugin.
2. Activate **Game Library** in the WordPress admin.
3. Under the plugin settings, add your IGDB (Twitch) **Client ID** and **Client
   Secret**. For Steam import, add a **Steam Web API key**. All credentials are
   stored server-side and never exposed to the front end.

To rebuild the front-end assets from source (`src/js`, `src/scss`), run the
`build` script from the pipeline repo's `package.json` (`@wordpress/scripts`).

---

## IGDB attribution

Game metadata is provided by [IGDB.com](https://www.igdb.com/). Public game
pages carry a "Powered by IGDB" attribution linking back to each game's own IGDB
page, per the IGDB terms of use.

## License

Plugin code: **GPL-2.0-or-later**. This build bundles no fonts — it uses the
active theme's typography.
