/**
 * Game Library — stylesheet-only entry point.
 *
 * Compiles to `build/style.css` + `build/style-rtl.css`, enqueued under a single
 * `gamelib-style` handle on every plugin surface by `GameLib_Assets`.
 *
 * It exists because all three behaviour bundles need the same base layer, and
 * importing `main.scss` from each of them made wp-scripts emit three
 * byte-identical stylesheets under three URLs (PF-2): a member who visited a
 * public profile and then `/my-library/` paid a second render-blocking
 * ~16.8 KB / ~2.7 KB gz `<link>` in `<head>` for bytes the browser already had,
 * and a third on the import-review route. One handle, one URL, one cache entry.
 *
 * Per-route splitting of the base layer is deliberately out of scope: the whole
 * sheet is one design system, and the three surfaces overlap in most of it.
 *
 * The emitted `build/style.js` is a near-empty artefact of wp-scripts needing a
 * JS entry to hang a stylesheet on; nothing enqueues it.
 */
import '../scss/main.scss';
