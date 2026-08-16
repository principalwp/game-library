/**
 * `.gl-site-header`'s Menu toggle (header/footer redesign task) — opens/
 * closes the secondary account disclosure (`#gl-site-header-menu`) rendered
 * by `templates/partials/site-header.php` on every route. Server-rendered
 * `[hidden]`, so the page works identically before this script runs; no REST
 * call, no translated string, no dependency on `wp-api-fetch` — this file is
 * enqueued unconditionally alongside the CSS bundle (`includes/class-assets.php`),
 * not through the per-route `ROUTE_SCRIPTS` map the other front-end scripts use.
 *
 * @package
 */

( function () {
	'use strict';

	const toggle = document.querySelector( '[data-gl-menu-toggle]' );
	const menu = document.querySelector( '[data-gl-menu]' );

	if ( ! toggle || ! menu ) {
		return;
	}

	/**
	 * Opens or closes the menu, keeping the toggle's `aria-expanded` and the
	 * panel's `hidden` attribute in sync.
	 *
	 * @param {boolean} open Whether the menu should end up open.
	 */
	function setOpen( open ) {
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		menu.hidden = ! open;
	}

	toggle.addEventListener( 'click', function () {
		setOpen( toggle.getAttribute( 'aria-expanded' ) !== 'true' );
	} );

	// Escape closes the menu and returns focus to the toggle — the same
	// dismissal contract every other disclosure in this plugin's front end
	// offers (game-detail.js's own quick-look close control).
	document.addEventListener( 'keydown', function ( event ) {
		if (
			'Escape' === event.key &&
			'true' === toggle.getAttribute( 'aria-expanded' )
		) {
			setOpen( false );
			toggle.focus();
		}
	} );

	// A click outside the header closes the menu, matching common disclosure
	// conventions (the trigger itself is excluded so its own click handler
	// above — not this one — decides the next state).
	document.addEventListener( 'click', function ( event ) {
		if ( 'true' !== toggle.getAttribute( 'aria-expanded' ) ) {
			return;
		}

		const header = toggle.closest( '.gl-site-header' );

		if ( header && ! header.contains( event.target ) ) {
			setOpen( false );
		}
	} );
} )();
