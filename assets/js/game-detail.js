/**
 * Quick-look game detail — in-place card expansion (DES-50, restyle §C,
 * reworked).
 *
 * Enhances every card's `[data-gl-quicklook]` cover-link on `/my-library/`,
 * `/library/{nicename}/`, and `/games/`. The link is a REAL navigation to
 * `/games/{slug}/`; this script intercepts the click and, instead of opening a
 * modal, EXPANDS the clicked card in place — un-hiding that card's own
 * `.gl-game-card__detail` region (`[data-gl-detail]`) and filling its
 * `[data-gl-detail-*]` hooks. With JS off, or before this defer-loaded script
 * runs, the link still navigates, so there is never a dead end (progressive
 * enhancement is structural).
 *
 * The interaction is a disclosure, not a dialog: the trigger carries
 * `aria-expanded`/`aria-controls`, only one card is open at a time (opening a
 * second collapses the first), and Escape or the region's close button
 * collapses the open card and returns focus to its trigger. The panel repeats
 * the game title as its own header (server-rendered) and renders the relocated
 * release year (AC-005(e), §B row 9), genres and platforms (as bordered
 * boxes), summary, a live region for loading/error, and an always-present
 * "View full page" link (the no-dead-end fallback, server-rendered).
 *
 * Mirrors the idle/loading/populated/error state shape and the delegated-
 * listener / `wp.apiFetch` / element-API (never `innerHTML`) conventions
 * `assets/js/search.js` established. Depends on `wp-api-fetch` (which
 * transitively loads `wp-i18n`) and the `window.gameLibraryData` global, both
 * wired by `Assets::localize_scripts()`.
 *
 * @package
 */

( function () {
	'use strict';

	// Repeat opens of the same card are instant — the fetched payload is cached
	// by igdb_id for the life of the page, so no second request is issued.
	const cache = new Map();

	// The currently expanded card and its triggering cover-link. Only one card
	// is open at a time (accordion); the trigger is refocused when the card is
	// collapsed via Escape or the close button.
	let openCard = null;
	let openTrigger = null;

	// Guards against a stale in-flight response painting a card that has since
	// been collapsed or superseded: each expand/collapse bumps this token, and a
	// fetch only populates when its own token is still current.
	let openToken = 0;

	// Sideways-float restyle: pending "finish collapsing" timers, keyed by each
	// card's own [data-gl-detail] region (a WeakMap so a removed card's entry
	// is never leaked). Per-region, not a single shared variable — an accordion
	// switch can leave one card's collapse still winding down while a different
	// card opens, and a shared timer would let the second unrelated collapse
	// cancel the first card's own pending hide.
	const collapseTimers = new WeakMap();

	// Kept in sync BY HAND with game-library.css's own
	// `@media ( min-width: 64em )` — the breakpoint above which the quick-look
	// floats sideways instead of expanding downward. A CSS custom property
	// cannot be read inside an @media condition, so there is no way to share
	// this number between the two files other than this comment.
	const WIDE_BREAKPOINT_QUERY = '(min-width: 64em)';

	// A "quick fade+shrink" collapse (not a full mirrored reverse of the staged
	// expand) — long enough to clear the widen/fade stages' own combined
	// ~380ms (--gl-delay-fade + --gl-duration-fade, the later-finishing of the
	// two), short enough that Escape/close/second-click still reads as prompt.
	const COLLAPSE_ANIMATION_MS = 400;

	/**
	 * The plugin's REST namespace, exposed by `Assets::localize_scripts()`.
	 *
	 * @return {string} REST namespace.
	 */
	function restNamespace() {
		return (
			( window.gameLibraryData &&
				window.gameLibraryData.restNamespace ) ||
			'game-library/v1'
		);
	}

	/**
	 * The `[data-gl-detail]` region inside a card.
	 *
	 * @param {HTMLElement|null} card A `.gl-game-card`.
	 * @return {HTMLElement|null} Its detail region, or null.
	 */
	function regionOf( card ) {
		return card ? card.querySelector( '[data-gl-detail]' ) : null;
	}

	/**
	 * The populatable hooks within one detail region.
	 *
	 * @param {HTMLElement} region A `[data-gl-detail]` element.
	 * @return {Object} The `[data-gl-detail-*]` elements.
	 */
	function hooks( region ) {
		return {
			year: region.querySelector( '[data-gl-detail-year]' ),
			genres: region.querySelector( '[data-gl-detail-genres]' ),
			platforms: region.querySelector( '[data-gl-detail-platforms]' ),
			summary: region.querySelector( '[data-gl-detail-summary]' ),
			status: region.querySelector( '[data-gl-detail-status]' ),
		};
	}

	/**
	 * Shows or hides the whole `[…]__detail-row` (label + chip boxes) so an
	 * empty genre/platform list never leaves a dangling "Genres"/"Platforms"
	 * label with nothing below it.
	 *
	 * @param {HTMLElement|null} list    The genres/platforms `<ul>`.
	 * @param {boolean}          visible Whether the list has items to show.
	 */
	function setRowVisibility( list, visible ) {
		if ( ! list ) {
			return;
		}

		const row = list.closest( '.gl-game-card__detail-row' );

		if ( row ) {
			row.hidden = ! visible;
		}
	}

	/**
	 * Clears every populated region back to empty — run at the start of every
	 * expand so a slower fetch never flashes the previous state.
	 *
	 * @param {HTMLElement} region A `[data-gl-detail]` element.
	 */
	function resetRegion( region ) {
		const h = hooks( region );

		if ( h.year ) {
			h.year.textContent = '';
		}
		if ( h.summary ) {
			h.summary.textContent = '';
		}
		if ( h.genres ) {
			h.genres.replaceChildren();
			setRowVisibility( h.genres, false );
		}
		if ( h.platforms ) {
			h.platforms.replaceChildren();
			setRowVisibility( h.platforms, false );
		}
		if ( h.status ) {
			h.status.replaceChildren();
			h.status.setAttribute( 'aria-busy', 'false' );
		}
	}

	/**
	 * Loading state — a spinner and a visible text alternative in the
	 * `role="status"` live region, `aria-busy="true"` (DES-14: a stopped spinner
	 * alone gives a reduced-motion visitor no cue, and the live region needs
	 * something explicit to announce).
	 *
	 * @param {HTMLElement|null} status The `[data-gl-detail-status]` live region.
	 */
	function setLoadingState( status ) {
		if ( ! status ) {
			return;
		}

		status.replaceChildren();
		status.setAttribute( 'aria-busy', 'true' );

		const spinner = document.createElement( 'span' );
		spinner.className = 'gl-spinner';
		status.appendChild( spinner );

		const message = document.createElement( 'span' );
		message.textContent = wp.i18n.__( 'Loading…', 'game-library' );
		status.appendChild( message );
	}

	/**
	 * Builds each `<li>` for a genre/platform list off-document and commits it
	 * in one mutation, then toggles its row's visibility.
	 *
	 * @param {HTMLElement|null} list  Target `<ul>`.
	 * @param {Array}            items List of already-plain-text names.
	 */
	function fillList( list, items ) {
		if ( ! list ) {
			return;
		}

		const values = Array.isArray( items ) ? items : [];
		const fragment = document.createDocumentFragment();

		values.forEach( function ( name ) {
			const li = document.createElement( 'li' );
			// textContent, never innerHTML — REST data is escaped by treating it
			// as text, matching search.js's element-API contract.
			li.textContent = name;
			fragment.appendChild( li );
		} );

		list.replaceChildren( fragment );
		setRowVisibility( list, values.length > 0 );
	}

	/**
	 * Populates every `[data-gl-detail-*]` hook from one `/games/{id}` payload
	 * and clears the loading state.
	 *
	 * @param {HTMLElement} region A `[data-gl-detail]` element.
	 * @param {Object}      data   One `GET /game-library/v1/games/{id}` response.
	 */
	function populate( region, data ) {
		const h = hooks( region );

		if ( h.year ) {
			// The card's own release-year meta, relocated here (AC-005(e), §B
			// row 9): a real year, or "Unreleased" for a null date — the exact
			// text the card used to render.
			h.year.textContent = data.first_release_year
				? String( data.first_release_year )
				: wp.i18n.__( 'Unreleased', 'game-library' );
		}

		fillList( h.genres, data.genres );
		fillList( h.platforms, data.platforms );

		if ( h.summary ) {
			h.summary.textContent = data.summary || '';
		}

		if ( h.status ) {
			h.status.replaceChildren();
			h.status.setAttribute( 'aria-busy', 'false' );
		}
	}

	/**
	 * Error state — an inline message in the live region. The "View full page"
	 * link is server-rendered and always present in the region below, so the
	 * fallback is structural (never a dead end) whether or not the fetch fails.
	 *
	 * @param {HTMLElement|null} status The `[data-gl-detail-status]` live region.
	 */
	function setErrorState( status ) {
		if ( ! status ) {
			return;
		}

		status.replaceChildren();
		status.setAttribute( 'aria-busy', 'false' );

		const message = document.createElement( 'p' );
		message.className = 'gl-error-text';
		message.setAttribute( 'role', 'alert' );
		message.textContent = wp.i18n.__(
			'Game details are unavailable right now.',
			'game-library'
		);
		status.appendChild( message );
	}

	/**
	 * Whether the visitor's OS/browser asks for no non-essential motion — the
	 * codebase's existing `@media ( prefers-reduced-motion: reduce )` gate,
	 * mirrored here since the staged sideways animation needs a JS-side
	 * decision too (whether to delay a collapse for the reverse transition to
	 * finish, or hide immediately).
	 *
	 * @return {boolean} True under `prefers-reduced-motion: reduce`.
	 */
	function prefersReducedMotion() {
		return !! (
			window.matchMedia &&
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
		);
	}

	/**
	 * Whether the viewport currently matches the sideways-float breakpoint —
	 * see `WIDE_BREAKPOINT_QUERY`'s own comment.
	 *
	 * @return {boolean} True at/above the wide breakpoint.
	 */
	function isWideLayout() {
		return !! (
			window.matchMedia &&
			window.matchMedia( WIDE_BREAKPOINT_QUERY ).matches
		);
	}

	/**
	 * Reads a `rem`-valued CSS custom property off the root element and
	 * converts it to pixels against the root font-size — the mechanism that
	 * keeps the flip/clamp math below in agreement with tokens.css's own
	 * numbers (the recommended technique: JS and CSS read the same source)
	 * rather than duplicating them as separate JS literals.
	 *
	 * @param {string} name        Custom property name, e.g. `--gl-quicklook-width`.
	 * @param {number} fallbackRem Value (in rem) to use if the property is
	 *                             unset or unparsable.
	 * @return {number} The resolved value in pixels.
	 */
	function remVarPx( name, fallbackRem ) {
		const root = document.documentElement;
		const raw = window
			.getComputedStyle( root )
			.getPropertyValue( name )
			.trim();
		const rem = parseFloat( raw );
		const rootPx = parseFloat( window.getComputedStyle( root ).fontSize );

		return (
			( Number.isFinite( rem ) ? rem : fallbackRem ) * ( rootPx || 16 )
		);
	}

	/**
	 * Sideways-float direction + width for one card's floating panel — the
	 * recommended technique: measure the card's rect against the grid
	 * container's edges (falling back to the viewport when the card is not
	 * inside a `.gl-library-grid`) and flip to the side with more room.
	 * Generalised beyond "only the rightmost column flips": the WIDTH is also
	 * clamped to whatever space is actually free on the chosen side, so a card
	 * in the MIDDLE of a many-column row — where neither direction has the
	 * full target width free — never overflows past either edge either. This
	 * is what actually guarantees no horizontal page scroll; the CSS
	 * `min( …, 92vw )` fallback is only a backstop for when this cannot run.
	 *
	 * @param {HTMLElement} card The `.gl-game-card` about to expand.
	 * @return {{direction: string, width: number}} `direction` is `'right'` or
	 *                                               `'left'`; `width` is in
	 *                                               pixels.
	 */
	function computePanelPlacement( card ) {
		const targetWidth = remVarPx( '--gl-quicklook-width', 27.75 );
		const gap = remVarPx( '--gl-space-sm', 0.75 );
		const gridEl = card.closest( '.gl-library-grid' );
		const cardRect = card.getBoundingClientRect();
		const gridRect = gridEl ? gridEl.getBoundingClientRect() : null;
		const viewportWidth = document.documentElement.clientWidth;

		const rightBoundary = gridRect
			? Math.min( gridRect.right, viewportWidth )
			: viewportWidth;
		const leftBoundary = gridRect ? Math.max( gridRect.left, 0 ) : 0;

		const spaceRight = rightBoundary - cardRect.right - gap;
		const spaceLeft = cardRect.left - leftBoundary - gap;

		const direction = spaceRight >= spaceLeft ? 'right' : 'left';
		const available = Math.max(
			'right' === direction ? spaceRight : spaceLeft,
			0
		);

		return {
			direction,
			width: Math.max( Math.min( targetWidth, available ), 0 ),
		};
	}

	/**
	 * Collapses the currently open card (if any): drops the expanded state and
	 * resets its trigger's `aria-expanded` immediately, then re-hides its
	 * region — on wide screens, under `prefers-reduced-motion: no-preference`,
	 * after a short delay so the panel's own "quick fade+shrink" (the reverse
	 * of the widen/fade stages, driven by the same CSS transitions playing
	 * backwards once `.is-open` is removed) has a moment to actually play
	 * before the region leaves the render tree; everywhere else (narrow
	 * screens, or reduced motion) the hide is immediate, matching the existing
	 * behaviour exactly. Bumps the token so any in-flight fetch for it is
	 * discarded.
	 */
	function collapse() {
		if ( ! openCard ) {
			return;
		}

		openToken += 1;

		const card = openCard;
		const region = regionOf( card );

		if ( openTrigger ) {
			openTrigger.setAttribute( 'aria-expanded', 'false' );
		}

		openCard = null;
		openTrigger = null;

		if ( ! region ) {
			card.classList.remove(
				'is-expanded',
				'is-expanded--left',
				'is-expanded--right'
			);
			return;
		}

		const pendingTimer = collapseTimers.get( region );

		if ( pendingTimer ) {
			clearTimeout( pendingTimer );
			collapseTimers.delete( region );
		}

		region.classList.remove( 'is-open' );

		const finishCollapse = function () {
			region.hidden = true;
			card.classList.remove(
				'is-expanded',
				'is-expanded--left',
				'is-expanded--right'
			);
			card.style.removeProperty( '--gl-quicklook-computed-width' );
		};

		if ( isWideLayout() && ! prefersReducedMotion() ) {
			const timerId = setTimeout( function () {
				collapseTimers.delete( region );
				finishCollapse();
			}, COLLAPSE_ANIMATION_MS );

			collapseTimers.set( region, timerId );
		} else {
			finishCollapse();
		}
	}

	/**
	 * Expands one card — collapsing any other open card first (accordion),
	 * showing the loading state immediately (avoiding a dead click while the
	 * request is in flight), then populating from the in-memory cache when
	 * present or a single `wp.apiFetch` when not.
	 *
	 * @param {HTMLElement} card    The `.gl-game-card` to expand.
	 * @param {HTMLElement} trigger The activated `[data-gl-quicklook]` cover-link.
	 * @param {string}      igdbId  The game's IGDB id.
	 */
	function expand( card, trigger, igdbId ) {
		const region = regionOf( card );

		if ( ! region ) {
			return;
		}

		// Accordion: only one card open at a time.
		if ( openCard && openCard !== card ) {
			collapse();
		}

		// This card's own previous collapse may still be mid-"quick fade+shrink"
		// (collapse()'s delayed hide) — cancel it so that timer cannot yank
		// `hidden` back to true out from under this fresh expand.
		const pendingTimer = collapseTimers.get( region );

		if ( pendingTimer ) {
			clearTimeout( pendingTimer );
			collapseTimers.delete( region );
		}

		openCard = card;
		openTrigger = trigger;
		openToken += 1;
		const token = openToken;

		resetRegion( region );

		// Sideways-float direction + clamped width (wide screens only — inert
		// below the breakpoint, since no wide-screen rule reads either the
		// modifier class or the custom property there).
		const placement = computePanelPlacement( card );
		card.classList.remove( 'is-expanded--left', 'is-expanded--right' );
		card.classList.add( 'is-expanded--' + placement.direction );
		card.style.setProperty(
			'--gl-quicklook-computed-width',
			placement.width + 'px'
		);

		region.hidden = false;
		card.classList.add( 'is-expanded' );
		trigger.setAttribute( 'aria-expanded', 'true' );

		// Two-frame reveal: this tick, the region is still governed by its
		// pre-open transient styles (prefers-reduced-motion: no-preference,
		// wide screens only — inert everywhere else). Waiting two animation
		// frames lets the browser paint that starting state once — an element
		// switching from `hidden` (display: none) has no prior rendered box to
		// interpolate from, so without this the widen/fade would snap straight
		// to their resting state instead of transitioning into it.
		window.requestAnimationFrame( function () {
			window.requestAnimationFrame( function () {
				if ( token === openToken ) {
					region.classList.add( 'is-open' );
				}
			} );
		} );

		if ( cache.has( igdbId ) ) {
			populate( region, cache.get( igdbId ) );
			return;
		}

		setLoadingState( hooks( region ).status );

		wp.apiFetch( {
			path: '/' + restNamespace() + '/games/' + igdbId,
		} )
			.then( function ( data ) {
				cache.set( igdbId, data );

				// A newer expand/collapse superseded this one — leave it alone.
				if ( token !== openToken ) {
					return;
				}

				populate( region, data );
			} )
			.catch( function () {
				if ( token !== openToken ) {
					return;
				}

				setErrorState( hooks( region ).status );
			} );
	}

	/**
	 * Delegated click handler — bound on `document` so it catches every card
	 * grid without per-route wiring. Handles both the collapse control inside an
	 * open card and the cover-link trigger, plus click-outside-to-close (a
	 * natural addition now that the wide-screen panel floats above its
	 * neighbours, in addition to the existing Escape/close-button/second-click/
	 * accordion collapse paths).
	 *
	 * @param {Event} event Delegated `click` event.
	 */
	function handleClick( event ) {
		if ( ! event.target.closest ) {
			return;
		}

		// The region's own close control collapses the open card.
		if ( event.target.closest( '[data-gl-detail-close]' ) ) {
			event.preventDefault();
			const trigger = openTrigger;
			collapse();
			if ( trigger && 'function' === typeof trigger.focus ) {
				trigger.focus();
			}
			return;
		}

		const trigger = event.target.closest( '[data-gl-quicklook]' );

		if ( ! trigger ) {
			// Click-outside-to-close: a click that is neither the close button
			// (handled above) nor a quick-look trigger, landing outside the
			// currently open card entirely, collapses it. A click inside the open
			// card's own detail content (e.g. its summary text, or the "View full
			// page" link — real navigation, left to proceed normally) does not.
			if ( openCard && ! openCard.contains( event.target ) ) {
				collapse();
			}
			return;
		}

		// Let modified clicks (new tab/window, middle-click) fall through to the
		// browser's own real-link handling — a quick-look is a same-tab action.
		if (
			event.defaultPrevented ||
			event.button ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}

		const igdbId = trigger.dataset.igdbId;
		const card = trigger.closest( '.gl-game-card' );

		if ( ! igdbId || ! card ) {
			return;
		}

		event.preventDefault();

		// Toggle: a second click on the open card's own trigger collapses it
		// (focus is already on the trigger, so no explicit refocus is needed).
		if ( openCard === card ) {
			collapse();
			return;
		}

		expand( card, trigger, igdbId );
	}

	document.addEventListener( 'click', handleClick );

	// Escape collapses the open card and returns focus to its trigger — the
	// disclosure analogue of a dialog's native Escape-to-close.
	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key || ! openCard ) {
			return;
		}

		const trigger = openTrigger;
		collapse();

		if ( trigger && 'function' === typeof trigger.focus ) {
			trigger.focus();
		}
	} );
} )();
