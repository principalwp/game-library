# Game Library — starter build

> An invite-only community where members build and share personal video-game
> libraries backed by the [IGDB](https://www.igdb.com/) catalog — search,
> curate, follow, and browse.

A self-contained WordPress plugin with plain WordPress-theme styling (one
stylesheet, no design system). This is the **starter** build — the functional,
reviewed plugin produced without a design brief. It pairs naturally as the
"before" against a design-guided build.

**Requires** WordPress 6.2+ and PHP 8.0+. Licensed **GPL-2.0-or-later**.

---

## About this repository

This plugin was built end-to-end by an agentic development pipeline.

| Branch | What it is |
|--------|------------|
| [`full-initial`](../../tree/full-initial) | Full build — first-pass output, original design. |
| [`full-designed`](../../tree/full-designed) | Full build — after a dedicated design pass. |
| [`starter-initial`](../../tree/starter-initial) | **This branch** — the starter build, plain WordPress-theme styling, no design system. |

> **You are viewing the `starter-initial` branch.**

Write-up: _link — TBD._

---

## Demo

A short silent walkthrough recorded against
[WordPress Playground](https://wordpress.github.io/wordpress-playground/):

**▶ [`game-library-demo.webm`](game-library-demo.webm)**

![My Library — the member's landing page: cover art and per-game status](my-library.webp)

---

## Install

Runtime files only — the static-analysis config (`phpcs.xml`, `phpstan.neon`,
`phpstan-bootstrap.php`) is intentionally excluded from this branch. Copy the
plugin files into a `game-library/` folder under `wp-content/plugins/` and
activate, or zip them and upload via **Plugins → Add New → Upload**.
