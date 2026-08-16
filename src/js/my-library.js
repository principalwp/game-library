/**
 * Game Library — `/my-library/` entry point.
 *
 * Compiles to `build/my-library.js`, enqueued only on the `/my-library/` route.
 * The base layer it renders against is a separate entry (`style.js`), enqueued
 * once for the whole plugin (PF-2).
 *
 * The page is server-rendered first (`templates/my-library.php`) and this bundle
 * only ever does five things to it: read the member's intent off controls PHP
 * rendered, call a REST route, swap in the HTML fragment the server answered
 * with, move focus, and announce the server's own sentence in the polite live
 * region. It composes no markup and authors no member-facing string — every
 * word it shows comes from a fragment, a JSON `message`, or a `data-` attribute
 * the template wrote (ADR-002). That is why there is no `@wordpress/i18n`
 * import: there is nothing here to translate.
 *
 * Two rules keep the asynchrony honest:
 *
 * - **A late response never overwrites a newer one.** Searches and list
 *   refreshes each carry a sequence number; a response whose number is no longer
 *   the current one is dropped on arrival, so a slow "hal" cannot land on top of
 *   a fast "halo".
 * - **A control that started a request is disabled until it comes back**, so the
 *   same intent cannot be submitted twice.
 *
 * The three radiogroups — the four-way status control on every card, the status
 * filter chips, and the account panel's visibility toggle — deliberately do
 * *not* select on focus: each selection costs a round trip, so arrows move the
 * roving tab stop and Space/Enter (or a click) commits, which is the
 * alternative the ARIA authoring practices allow for exactly this case.
 *
 * The account panel adds three cases where a response carries no fragment, and
 * each is handled the same way — by writing a value into markup PHP already
 * rendered, never by composing any: a new invite is a clone of a server-rendered
 * `<template>` with its URL written in, the allowance sentence is one of four
 * strings the element carries with a number substituted, and the Steam
 * connection is two pre-rendered states of which one is hidden.
 *
 * The REST transport and the radiogroup helpers live in `shared/rest.js` and
 * `shared/dom.js` — neither is a build entry, so webpack inlines them here and
 * the enqueued file list is unchanged.
 */

// No `main.scss` import here: the base layer is its own entry, `style.js`,
// enqueued once under a single handle on every plugin surface (PF-2).
import { download, request } from './shared/rest';
import {
	NEXT_KEYS,
	PREVIOUS_KEYS,
	ancestor,
	moveWithin,
	selectRadio,
	selectedRadio,
} from './shared/dom';

/** @type {string} Container id of the library grid (AC-NFR-009 r). */
const GRID_ID = 'gamelib-library';

/** @type {string} Container id of the search surface (AC-NFR-009 s). */
const SEARCH_ID = 'gamelib-search';

/** @type {string} Id of the grid's region heading — the post-swap focus target. */
const HEADING_ID = 'gamelib-library-title';

/**
 * Milliseconds of quiet before an in-library title search is sent.
 *
 * Long enough that typing a title is one request rather than eight, short
 * enough that the grid still feels like it is following along.
 *
 * @type {number}
 */
const FILTER_DEBOUNCE_MS = 300;

/**
 * Is this rejection the caller's own abort rather than a failure?
 *
 * An aborted request is one whose answer is no longer wanted — announcing it
 * would tell the member something went wrong when nothing did.
 *
 * @param {*} error Rejection value.
 * @return {boolean} True for an AbortError.
 */
function isAbort( error ) {
	return !! error && 'AbortError' === error.name;
}

/**
 * Wire the library surface up.
 *
 * @return {void}
 */
function start() {
	const grid = document.getElementById( GRID_ID );
	const search = document.getElementById( SEARCH_ID );

	if ( ! grid || ! search ) {
		return;
	}

	const panel = grid.closest( '.gamelib-library' );
	const heading = document.getElementById( HEADING_ID );
	const live = document.querySelector( '[data-gamelib-live]' );

	const toolbar = panel
		? panel.querySelector( '[data-gamelib-toolbar]' )
		: null;
	const chips = panel ? panel.querySelector( '.gamelib-chips' ) : null;
	const sort = panel ? panel.querySelector( '[data-gamelib-sort]' ) : null;
	const filter = panel
		? panel.querySelector( '[data-gamelib-library-search]' )
		: null;
	const pager = panel ? panel.querySelector( '[data-gamelib-pager]' ) : null;

	const results = search.querySelector( '[data-gamelib-results]' );
	const skeleton = search.querySelector( '[data-gamelib-result-skeleton]' );
	// The PHP-authored failure state, for the failures that carry no fragment
	// of their own (PF-2/CO-2).
	const offlineState = search.querySelector( '[data-gamelib-offline-state]' );
	const loading = search.querySelector( '[data-gamelib-loading]' );
	const queryField = search.querySelector( '[data-gamelib-search-input]' );
	const submit = search.querySelector( '[data-gamelib-search-submit]' );

	// AC-019: the bulk bar. Its nonce is the dedicated `gamelib_bulk` one the
	// route verifies on top of the cookie nonce — without it every bulk request
	// is a 403, which is the point of it.
	const bulk = panel ? panel.querySelector( '[data-gamelib-bulk]' ) : null;
	const bulkNonce = bulk ? bulk.dataset.gamelibBulkNonce || '' : '';
	const bulkActions = bulk
		? bulk.querySelector( '[data-gamelib-bulk-actions]' )
		: null;
	const bulkToggle = bulk
		? bulk.querySelector( '[data-gamelib-bulk-toggle]' )
		: null;
	const bulkAll = bulk
		? bulk.querySelector( '[data-gamelib-bulk-all]' )
		: null;
	const bulkStatus = bulk
		? bulk.querySelector( '[data-gamelib-bulk-status]' )
		: null;
	const bulkApply = bulk
		? bulk.querySelector( '[data-gamelib-action="library.bulk-status"]' )
		: null;
	const bulkRemove = bulk
		? bulk.querySelector( '[data-gamelib-action="library.bulk-remove"]' )
		: null;

	// The account panel (AC-001, AC-027, AC-030, AC-046, AC-050).
	const account = document.querySelector( '[data-gamelib-account]' );
	const shareBlock = account
		? account.querySelector( '[data-gamelib-share]' )
		: null;
	const inviteList = account
		? account.querySelector( '[data-gamelib-invite-list]' )
		: null;
	const inviteTemplate = account
		? account.querySelector( '[data-gamelib-invite-template]' )
		: null;
	const allowance = account
		? account.querySelector( '[data-gamelib-allowance]' )
		: null;
	const steamConnected = account
		? account.querySelector( '[data-gamelib-steam-connected]' )
		: null;
	const steamDisconnected = account
		? account.querySelector( '[data-gamelib-steam-disconnected]' )
		: null;
	const steamId = account
		? account.querySelector( '[data-gamelib-steamid]' )
		: null;
	const steamAccount = account
		? account.querySelector( '[data-gamelib-steam-account]' )
		: null;
	const importFile = account
		? account.querySelector( '[data-gamelib-import-file]' )
		: null;

	// The one sentence no response can carry: a request that never reached the
	// server has no server message. Authored in PHP, read off the markup.
	const offline = search.dataset.gamelibError || '';

	let searchSeq = 0;
	let listSeq = 0;
	let filterTimer = 0;
	let page = 1;

	/*
	 * One AbortController per supersedable lane (PF-2).
	 *
	 * The sequence counters above decide which *response* may touch the DOM;
	 * they cannot stop a superseded response from being downloaded and parsed
	 * first. The 300ms-debounced library filter dispatches three or four
	 * `GET /library` calls for a typed title at ~35–45KB each, so a member
	 * waiting on the last one also pays for ~100KB and ~15–30ms of parsing they
	 * will never see. A lane aborts only its own predecessor — never a sibling
	 * write — and each controller is re-created rather than nulled, so its
	 * `signal` is always live.
	 */
	let listController = new window.AbortController();
	let searchController = new window.AbortController();

	/**
	 * Supersede the list lane: abort whatever read is in flight and take the
	 * next sequence number.
	 *
	 * @return {{seq: number, signal: AbortSignal}} Sequence number and signal.
	 */
	const claimList = () => {
		listController.abort();
		listController = new window.AbortController();

		return { seq: ++listSeq, signal: listController.signal };
	};

	/**
	 * Supersede the search lane: abort whatever search is in flight and take
	 * the next sequence number.
	 *
	 * @return {{seq: number, signal: AbortSignal}} Sequence number and signal.
	 */
	const claimSearch = () => {
		searchController.abort();
		searchController = new window.AbortController();

		return { seq: ++searchSeq, signal: searchController.signal };
	};

	/*
	 * Per-entry sequence counters for the status radiogroups (CO-1).
	 *
	 * changeStatus() writes a whole card back with `card.outerHTML`, so two
	 * clicks on the same entry's group applied in arrival order can leave the
	 * card showing the *earlier* status while the database holds the later one.
	 * One counter per IGDB id rather than one for the screen: two different
	 * entries are independent writes, and neither should discard the other.
	 */
	const statusSeq = new window.Map();

	/*
	 * The same guard for the one-of-a-kind visibility toggle, which writes into
	 * `shareBlock.hidden` (CO-1).
	 */
	let visibilitySeq = 0;

	// Entries the applied filter matches — what "select all matching this
	// filter" acts on, and the number its removal confirmation names. The first
	// paint publishes it on the bulk bar; every list response updates it.
	let viewTotal = bulk
		? parseInt( bulk.dataset.gamelibBulkTotal, 10 ) || 0
		: 0;

	/**
	 * Say something in the polite live region.
	 *
	 * @param {string} message Sentence to announce.
	 * @return {void}
	 */
	const announce = ( message ) => {
		if ( ! live || ! message ) {
			return;
		}

		// Emptying first re-triggers the announcement when a member repeats an
		// action whose outcome sentence is unchanged.
		live.textContent = '';
		live.textContent = message;
	};

	/**
	 * Announce the state a fragment reports, if it reports one.
	 *
	 * The empty and degraded states (AC-009, AC-010) are the only things a list
	 * fragment says in words, and they say it in a `data-gamelib-state` element.
	 *
	 * @param {HTMLElement|null} container Element the fragment was swapped into.
	 * @return {void}
	 */
	const announceState = ( container ) => {
		const state = container
			? container.querySelector( '[data-gamelib-state]' )
			: null;

		if ( state ) {
			announce( state.textContent || '' );
		}
	};

	/**
	 * The status filter currently applied.
	 *
	 * @return {string} One of the four statuses, or `all`.
	 */
	const currentStatus = () => {
		const chosen = chips ? selectedRadio( chips ) : null;

		return chosen ? chosen.dataset.gamelibStatus || 'all' : 'all';
	};

	/**
	 * The view the controls currently describe.
	 *
	 * The same object serves a list read and a bulk action: a bulk request
	 * carries the filter criteria so the server can both apply the action to
	 * "everything matching this filter" (AC-019f) and repaint the same page.
	 *
	 * `per_page` is deliberately absent: the server's own default is the
	 * AC-018(a) page size, and asking for a different one is not a member-facing
	 * capability.
	 *
	 * @param {number} wanted Page to read.
	 * @return {Object} Filter, sort, and page criteria.
	 */
	const criteria = ( wanted ) => ( {
		status: currentStatus(),
		sort: sort ? sort.value : 'added_desc',
		search: filter ? filter.value : '',
		page: Math.max( 1, wanted ),
	} );

	/**
	 * The query string for one page of the library, from the applied controls.
	 *
	 * @param {number} wanted Page to read.
	 * @return {string} Encoded query string.
	 */
	const listQuery = ( wanted ) =>
		new window.URLSearchParams( criteria( wanted ) ).toString();

	/**
	 * Bring the pager and the toolbar in line with the page the server just
	 * rendered.
	 *
	 * Only numbers are written here — the sentence around them was translated in
	 * PHP and its two placeholders are elements this fills in.
	 *
	 * @param {Object} payload Response from `GET /library`.
	 * @return {void}
	 */
	const syncControls = ( payload ) => {
		const pages = parseInt( payload.pages, 10 ) || 0;
		const current = parseInt( payload.page, 10 ) || 1;
		const counts = payload.counts || {};
		const total = parseInt( counts.all, 10 ) || 0;

		page = current;
		viewTotal = parseInt( payload.total, 10 ) || 0;

		if ( toolbar ) {
			// AC-020: an empty library has nothing to filter, sort, or page.
			toolbar.hidden = total < 1;
		}

		if ( bulk ) {
			// …and nothing to select in bulk either.
			bulk.hidden = total < 1;
		}

		if ( ! pager ) {
			return;
		}

		pager.hidden = pages < 2 || total < 1;

		const previousButton = pager.querySelector(
			'[data-gamelib-page-step="-1"]'
		);
		const nextButton = pager.querySelector(
			'[data-gamelib-page-step="1"]'
		);
		const currentLabel = pager.querySelector(
			'[data-gamelib-page-current]'
		);
		const totalLabel = pager.querySelector( '[data-gamelib-page-total]' );

		if ( previousButton ) {
			previousButton.disabled = current <= 1;
		}

		if ( nextButton ) {
			nextButton.disabled = current >= pages;
		}

		if ( currentLabel ) {
			currentLabel.textContent = String( current );
		}

		if ( totalLabel ) {
			totalLabel.textContent = String( Math.max( 1, pages ) );
		}
	};

	/**
	 * The card checkboxes of the page currently rendered.
	 *
	 * @return {HTMLElement[]} Checkbox inputs, in document order.
	 */
	const cardBoxes = () =>
		Array.from( grid.querySelectorAll( '[data-gamelib-bulk-id]' ) );

	/**
	 * The IGDB ids a member has ticked on this page (AC-019a).
	 *
	 * @return {number[]} Ticked ids.
	 */
	const tickedIds = () =>
		cardBoxes()
			.filter( ( box ) => box.checked )
			.map( ( box ) => parseInt( box.dataset.gamelibBulkId, 10 ) || 0 )
			.filter( ( id ) => id > 0 );

	/**
	 * Is bulk-select mode on?
	 *
	 * Read off the toggle's own `aria-pressed`, so the state a screen reader is
	 * told and the state the bundle acts on cannot disagree.
	 *
	 * @return {boolean} True while the checkboxes are showing.
	 */
	const selecting = () =>
		!! bulkToggle && 'true' === bulkToggle.getAttribute( 'aria-pressed' );

	/**
	 * How many entries the next bulk action would touch.
	 *
	 * In filter mode that is the whole filtered set as the server last counted
	 * it — the client never enumerates those ids (AC-019f).
	 *
	 * @return {number} Entries in the current selection.
	 */
	const selectionSize = () => {
		if ( ! selecting() ) {
			return 0;
		}

		return bulkAll && bulkAll.checked ? viewTotal : tickedIds().length;
	};

	/**
	 * Bring the bulk bar in line with the selection.
	 *
	 * @return {void}
	 */
	const syncBulk = () => {
		const active = selecting();
		const size = selectionSize();

		if ( panel ) {
			// The checkboxes live on every owner card and are revealed by this
			// modifier, so a card the server swaps in arrives in the right state
			// without the bundle touching it.
			panel.classList.toggle( 'gamelib-library--selecting', active );
		}

		if ( bulkActions ) {
			bulkActions.hidden = ! active;
		}

		if ( bulkApply ) {
			bulkApply.disabled = size < 1;
		}

		if ( bulkRemove ) {
			bulkRemove.disabled = size < 1;
		}
	};

	/**
	 * Drop the selection — after a swap the ticked cards no longer exist, and a
	 * filter-mode selection describes a filter that may have just changed.
	 *
	 * @return {void}
	 */
	const clearSelection = () => {
		if ( bulkAll ) {
			bulkAll.checked = false;
		}

		cardBoxes().forEach( ( box ) => {
			box.checked = false;
		} );

		syncBulk();
	};

	/**
	 * Hold the grid's painted height across a swap (PF-1).
	 *
	 * The grid is the largest swapped container on this route and nothing held
	 * its space: a filter keystroke took it from 1,009px to 174px ~1.5s later,
	 * which is ~3x outside the 500ms `hadRecentInput` window, and measured CLS
	 * 0.344 at 1280x800 / 0.484 at 390x844 on one keystroke — a Good to Poor
	 * transition on the plugin's busiest authenticated route, accumulating
	 * across a session because a member filters more than once. The bulk path
	 * is worse in kind: `window.confirm()` blocks between the click and the
	 * request, so the gap is unbounded by anything this code controls.
	 *
	 * A CSS floor is the wrong shape here, unlike the search container's: a
	 * 24-card page is ~4,400px on desktop, so there is no static measure token
	 * to write. The height has to be read from painted content, which is why
	 * this is the one place either front-end bundle writes an inline style —
	 * deliberately, because the value is a *measurement* and not a design
	 * decision, and there is no other way to pin a height that depends on what
	 * is on screen. It is the single exception to the plugin's
	 * zero-`element.style` provenance rule, and a provenance sweep should read
	 * it as that rather than as drift.
	 *
	 * Clear, measure, set — in that order and in one task, so what gets pinned
	 * is the honest current height rather than a stale pin from the last
	 * interaction, and so the collapse a *previous* narrowing caused lands
	 * inside the window where the member's own input put it. One deliberate
	 * forced reflow per interaction, in read-then-write order on a single
	 * container: one layout, not one per card.
	 *
	 * Callers must run this synchronously inside the interaction — before the
	 * `await`, and before `window.confirm()`, which detaches everything after
	 * it from the click.
	 *
	 * @return {number} Pixels now held, or 0 when nothing was.
	 */
	const holdGrid = () => {
		grid.style.minBlockSize = '';

		const held = Math.round( grid.getBoundingClientRect().height );

		if ( held > 0 ) {
			grid.style.minBlockSize = `${ held }px`;
		}

		return held;
	};

	/**
	 * Give the held space back, as soon as giving it back is free (PF-1).
	 *
	 * Releasing is free whenever the answer is at least as tall as what was
	 * held — the pin is floor-only, so dropping it moves nothing. When the
	 * answer is *shorter*, dropping it here would be the defect this helper
	 * exists for: the collapse would land in the response's task, a second or
	 * more after the member's last input, and count in full. So the pin stays
	 * until the next interaction, where {@see holdGrid()}'s own clear releases
	 * it inside that input's window — the same trade `:has(> *)` makes
	 * permanently for the search container one section above, and the same
	 * reading of held space the stylesheet's `align-content: start` gives it:
	 * blank room below an answer says "nothing further", not "still coming".
	 *
	 * The clear and the re-set happen in one task, so nothing is painted
	 * between them and the read costs one more layout on the same container.
	 *
	 * @param {number} held Pixels {@see holdGrid()} pinned.
	 * @return {void}
	 */
	const releaseGrid = ( held ) => {
		grid.style.minBlockSize = '';

		if ( held <= 0 ) {
			return;
		}

		if ( Math.round( grid.getBoundingClientRect().height ) < held ) {
			grid.style.minBlockSize = `${ held }px`;
		}
	};

	/**
	 * Re-read one page of the library and swap the grid (AC-017, AC-018b).
	 *
	 * @param {Object}  options         Refresh options.
	 * @param {number}  [options.page]  Page to read; defaults to the current one.
	 * @param {boolean} [options.focus] Move focus to the grid heading afterwards
	 *                                  (AC-018c).
	 * @return {Promise<void>}
	 */
	const refresh = async ( options = {} ) => {
		const wanted = undefined === options.page ? page : options.page;

		// Before the await, inside the caller's own task (PF-1). The filter's
		// 300ms debounce still puts this inside the 500ms exclusion window; the
		// response ~1.5s later does not.
		// eslint-disable-next-line @wordpress/no-unused-vars-before-return -- Taken here on purpose: pinning the height anywhere later is outside the interaction, which is the whole defect.
		const held = holdGrid();
		const { seq, signal } = claimList();

		let payload;

		try {
			payload = await request( `library?${ listQuery( wanted ) }`, {
				signal,
			} );
		} catch ( error ) {
			// This read was superseded by a newer one: its answer was never
			// wanted, and nothing went wrong — the newer read owns the pin.
			if ( isAbort( error ) ) {
				return;
			}

			if ( seq === listSeq ) {
				announce( error.message || offline );
				releaseGrid( held );
			}

			return;
		}

		// A newer refresh is already out: this answer describes a view the
		// member has moved on from.
		if ( seq !== listSeq ) {
			return;
		}

		if ( typeof payload.html === 'string' ) {
			grid.innerHTML = payload.html;
		}

		releaseGrid( held );
		syncControls( payload );
		clearSelection();
		announceState( grid );

		if ( options.focus && heading ) {
			heading.focus();
		}
	};

	/**
	 * Hold the space a result set will occupy (PF-1).
	 *
	 * Clones the server-rendered skeleton into the results container, replacing
	 * whatever is there — a previous result set included, since it is about to
	 * be replaced anyway and leaving it would reserve the wrong height.
	 *
	 * @return {void}
	 */
	const reserveResults = () => {
		if ( ! results || ! skeleton || ! skeleton.content ) {
			return;
		}

		results.innerHTML = '';
		results.appendChild( skeleton.content.cloneNode( true ) );
	};

	/**
	 * Run an IGDB search and swap its results, empty state, or degraded state
	 * (AC-008–AC-010).
	 *
	 * @return {Promise<void>}
	 */
	const runSearch = async () => {
		if ( ! queryField || ! results ) {
			return;
		}

		const query = queryField.value.trim();

		if ( '' === query ) {
			return;
		}

		/*
		 * Reserve the space the results will need, synchronously, before the
		 * request goes out (PF-1) — this runs in the same task as the click or
		 * the Enter key, so the shift it causes falls inside the 500ms
		 * `hadRecentInput` window and is excluded from CLS, where the response
		 * three seconds later would not have been. The skeleton is a
		 * PHP-authored `<template>`; nothing is composed here (ADR-002).
		 */
		reserveResults();

		const { seq, signal } = claimSearch();

		if ( submit ) {
			submit.disabled = true;
		}

		if ( loading ) {
			loading.hidden = false;
		}

		try {
			const payload = await request( 'search', {
				method: 'POST',
				body: { q: query },
				signal,
			} );

			if ( seq !== searchSeq ) {
				return;
			}

			/*
			 * Never blank the container, on this branch as well as on the one
			 * below (DES-3/PF-3). A 200 whose `html` is '' — or which carries
			 * no `html` key at all — used to empty it, and an empty container
			 * stops matching `:has(> *)`, which switches the stylesheet's floor
			 * off and drops the box from 764px to 0px ~3s after the gesture:
			 * a measured CLS of 0.24 unscrolled and 0.45 scrolled. The shipped
			 * route cannot emit that today, but a response filter, a new state
			 * slug falling through `state_message()`, or a proxy stripping the
			 * key all can — and the stylesheet's own comment three lines from
			 * the floor says a later landing must not be able to switch it back
			 * off.
			 */
			if ( typeof payload.html === 'string' && '' !== payload.html ) {
				results.innerHTML = payload.html;
				announceState( results );
			}
		} catch ( error ) {
			// A superseded search: its predecessor's skeleton is already on
			// screen and the newer search owns the container.
			if ( isAbort( error ) ) {
				return;
			}

			if ( seq !== searchSeq ) {
				return;
			}

			// AC-010: the server renders the degraded state, exactly like a
			// successful one, and says the same thing in its message.
			const data = error.payload ? error.payload.data : null;

			/*
			 * Never blank the container, and never leave it lying (PF-1,
			 * PF-2/CO-2). Writing '' replaced ~720px of skeleton with 0px ~3s
			 * after the gesture; leaving the skeleton standing instead was
			 * worse in kind — the `finally` below hides the "Searching…"
			 * chrome while six shimmering placeholder rows stay lit, so the
			 * surface reads as loading forever, thirty infinite compositor
			 * animations keep ticking for the rest of the session, and the only
			 * word the member gets goes to a visually hidden live region.
			 *
			 * So: the server's own fragment where the failure carried one
			 * (every AC-010 class does), and otherwise a clone of the
			 * PHP-authored failure state, which says the same sentence from the
			 * same catalog. Nothing is composed here (ADR-002), and the box the
			 * floor needs is never empty.
			 */
			if ( data && typeof data.html === 'string' && '' !== data.html ) {
				results.innerHTML = data.html;
				announceState( results );
			} else if ( offlineState && offlineState.content ) {
				results.replaceChildren(
					offlineState.content.cloneNode( true )
				);
				announce( error.message || offline );
			} else {
				announce( error.message || offline );
			}
		} finally {
			if ( seq === searchSeq ) {
				if ( loading ) {
					loading.hidden = true;
				}

				if ( submit ) {
					submit.disabled = false;
				}
			}
		}
	};

	/**
	 * Add a search result to the library with the status chosen beside it
	 * (AC-011f).
	 *
	 * @param {HTMLElement} control Add button that was activated.
	 * @return {Promise<void>}
	 */
	const addResult = async ( control ) => {
		const igdbId = parseInt( control.dataset.gamelibIgdbId || '0', 10 );
		/*
		 * The search-result row and its Add button both carry
		 * `data-gamelib-igdb-id`, and `closest()` starts at the element itself —
		 * so the walk has to begin one level up, or it stops on the button and
		 * never sees the status chooser beside it.
		 * See principal/adr/020-add-control-row-lookup.md — this file is outside
		 * Task 26's Files list; the control was inert without the fix.
		 */
		const parent = control.parentElement;
		const row = parent ? parent.closest( '[data-gamelib-igdb-id]' ) : null;
		const chooser = row
			? row.querySelector( '[data-gamelib-add-status]' )
			: null;

		if ( ! igdbId || ! chooser ) {
			return;
		}

		control.disabled = true;

		try {
			const payload = await request( 'library', {
				method: 'POST',
				body: { igdb_id: igdbId, status: chooser.value },
			} );

			// The duplicate notice and the added confirmation are both the
			// server's sentence (AC-011e).
			announce( payload.message || '' );
			await refresh( { page: 1 } );
		} catch ( error ) {
			announce( error.message || offline );
		}

		control.disabled = false;
	};

	/**
	 * Move one entry to another status (AC-015).
	 *
	 * With a status filter applied the entry may have just left the view, so the
	 * whole page is re-read; unfiltered, the server's re-rendered card replaces
	 * the one on screen and focus returns to the option that was activated.
	 *
	 * @param {HTMLElement} control Status radio that was activated.
	 * @return {Promise<void>}
	 */
	const changeStatus = async ( control ) => {
		const igdbId = parseInt( control.dataset.gamelibIgdbId || '0', 10 );
		const status = control.dataset.gamelibStatus || '';
		const group = control.closest( '[role="radiogroup"]' );

		if ( ! igdbId || '' === status || ! group ) {
			return;
		}

		if ( 'true' === control.getAttribute( 'aria-checked' ) ) {
			return;
		}

		// eslint-disable-next-line @wordpress/no-unused-vars-before-return -- Read before selectRadio() overwrites it; it is the rollback target and cannot be recovered later.
		const previous = selectedRadio( group );

		// Optimistic only in the accessibility tree: the badge, the date, and
		// the card itself still come from the server below.
		selectRadio( group, control );

		const seq = ( statusSeq.get( igdbId ) || 0 ) + 1;

		statusSeq.set( igdbId, seq );

		let payload;

		try {
			payload = await request( `library/${ igdbId }`, {
				method: 'POST',
				body: { status },
			} );
		} catch ( error ) {
			// A superseded request's failure is not this entry's state: the
			// later write owns the group now, and rolling back to `previous`
			// would undo its optimistic selection (CO-1).
			if ( seq !== statusSeq.get( igdbId ) ) {
				return;
			}

			if ( previous ) {
				selectRadio( group, previous );
			}

			announce( error.message || offline );

			return;
		}

		// Superseded while in flight: the later write re-renders this card, and
		// writing an older status over it would show a state the server no
		// longer holds (CO-1).
		if ( seq !== statusSeq.get( igdbId ) ) {
			return;
		}

		if ( 'all' !== currentStatus() ) {
			await refresh( { focus: true } );

			return;
		}

		const card = grid.querySelector(
			`:scope > [data-gamelib-igdb-id="${ igdbId }"]`
		);

		if ( ! card || typeof payload.html !== 'string' ) {
			return;
		}

		card.outerHTML = payload.html;

		const restored = grid.querySelector(
			`:scope > [data-gamelib-igdb-id="${ igdbId }"] [data-gamelib-status="${ status }"]`
		);

		if ( restored ) {
			restored.focus();
		}
	};

	/**
	 * Remove one entry, behind the confirmation the card carries (AC-016).
	 *
	 * @param {HTMLElement} control Remove button that was activated.
	 * @return {Promise<void>}
	 */
	const removeEntry = async ( control ) => {
		const igdbId = parseInt( control.dataset.gamelibIgdbId || '0', 10 );
		const question = control.dataset.gamelibConfirm || '';

		/*
		 * AC-016 requires an explicit confirmation step, and the task leaves the
		 * native dialog vs. a custom one to judgment: native wins here because a
		 * hand-rolled modal is a focus trap, an escape handler, and a labelled
		 * dialog to get right for one yes/no question, while the browser's own is
		 * already accessible and already translated. The question itself is the
		 * server's string, read off the control.
		 *
		 * Known and accepted (PF-12): a native dialog blocks the click handler,
		 * so Event Timing attributes the member's whole think-time to this
		 * interaction and field INP will show multi-second outliers for it.
		 * Yielding first to close the interaction early is not a fix — the
		 * dialog would then open outside the user-gesture window, which browsers
		 * may suppress outright. The accessibility argument above wins and the
		 * INP cost is carried knowingly. Same reasoning for the bulk removal.
		 */
		// eslint-disable-next-line no-alert -- Deliberate: see above.
		if ( ! igdbId || ! window.confirm( question ) ) {
			return;
		}

		control.disabled = true;

		try {
			const payload = await request( `library/${ igdbId }`, {
				method: 'DELETE',
			} );

			announce( payload.message || '' );

			// The card is gone, and with it whatever had focus — the page is
			// re-read so pagination and the empty state stay true.
			await refresh( { focus: true } );
		} catch ( error ) {
			control.disabled = false;
			announce( error.message || offline );
		}
	};

	/**
	 * Handle one of the three selection controls (AC-019a).
	 *
	 * @param {HTMLElement} control Control that was activated.
	 * @return {void}
	 */
	const select = ( control ) => {
		if ( control === bulkToggle ) {
			const next = ! selecting();

			bulkToggle.setAttribute( 'aria-pressed', next ? 'true' : 'false' );

			if ( ! next ) {
				clearSelection();

				return;
			}

			syncBulk();

			return;
		}

		if ( control === bulkAll ) {
			// Ticking every visible card is presentation: the request carries
			// `all` and the server re-derives the set from the filter (AC-019f).
			cardBoxes().forEach( ( box ) => {
				box.checked = bulkAll.checked;
			} );

			syncBulk();

			return;
		}

		if ( bulkAll && ! control.checked ) {
			// One card taken out of an "everything matching" selection makes it
			// an ordinary list of ids again.
			bulkAll.checked = false;
		}

		syncBulk();
	};

	/**
	 * Run a bulk status change or removal over the current selection (AC-019).
	 *
	 * The response is the refreshed page of the same view, so one round trip
	 * both performs the action and repaints the grid.
	 *
	 * @param {HTMLElement} control Control that was activated.
	 * @param {string}      action  `status` or `remove`.
	 * @return {Promise<void>}
	 */
	const runBulk = async ( control, action ) => {
		const everything = !! ( bulkAll && bulkAll.checked );
		const ids = everything ? [] : tickedIds();
		const size = everything ? viewTotal : ids.length;

		if ( size < 1 ) {
			return;
		}

		/*
		 * Before `window.confirm()`, not after (PF-1). The dialog blocks the
		 * task it is called from, and everything queued behind it belongs to a
		 * task the click has already been detached from — a pin taken there is
		 * a pin taken outside the interaction, which is where the un-excluded
		 * shift lives. Measured un-pinned: 1,165px to 99px, 613ms after the
		 * click, CLS 0.339 for removing eight ticked cards.
		 */
		const held = holdGrid();

		if ( 'remove' === action ) {
			const sentence =
				1 === size
					? control.dataset.gamelibConfirmOne
					: control.dataset.gamelibConfirmOther;

			/*
			 * AC-019(c): an explicit confirmation naming the count. Both plural
			 * forms are translated in PHP and read off the control — the only
			 * thing composed here is the substitution of a number the server has
			 * not been asked for yet. Native dialog by the same reasoning as the
			 * single-entry removal: the browser's is already accessible and
			 * already translated — including its known INP cost (PF-12, see the
			 * comment in removeEntry()).
			 */
			// eslint-disable-next-line no-alert -- Deliberate: see above.
			const agreed = window.confirm(
				( sentence || '' ).replace( '%s', size )
			);

			if ( ! agreed ) {
				// Nothing is going to be swapped and the grid is exactly as
				// tall as it was, so the pin comes straight back off.
				releaseGrid( held );

				return;
			}
		}

		const body = Object.assign( criteria( page ), {
			bulk_action: action,
			ids,
			all: everything,
			// AC-019(d): the dedicated nonce, beside the cookie nonce every
			// request already carries.
			nonce: bulkNonce,
		} );

		if ( 'status' === action ) {
			body.target_status = bulkStatus ? bulkStatus.value : '';
		}

		control.disabled = true;

		try {
			const payload = await request( 'library/bulk', {
				method: 'POST',
				body,
			} );

			if ( typeof payload.html === 'string' ) {
				grid.innerHTML = payload.html;
			}

			releaseGrid( held );
			syncControls( payload );
			clearSelection();
			announce( payload.message || '' );

			if ( heading ) {
				heading.focus();
			}
		} catch ( error ) {
			announce( error.message || offline );
			releaseGrid( held );
		}

		// Whether the action landed or failed, the buttons' state is the
		// selection's state — which clearSelection() has just recomputed.
		syncBulk();
	};

	/**
	 * Copy a URL the server put on the control, and say so (AC-050 b,c).
	 *
	 * The confirmation goes into the control's own `role="status"` sibling
	 * rather than the page's live region, so a copy on one invite row does not
	 * announce over another.
	 *
	 * @param {HTMLElement} control Copy button that was activated.
	 * @return {Promise<void>}
	 */
	const copyLink = async ( control ) => {
		const url = control.dataset.gamelibUrl || '';
		const parent = control.parentElement;
		const feedback = parent
			? parent.querySelector( '[data-gamelib-share-feedback]' )
			: null;

		if ( '' === url ) {
			return;
		}

		let message = control.dataset.gamelibShareError || '';

		try {
			await window.navigator.clipboard.writeText( url );
			message = control.dataset.gamelibShareDone || '';
		} catch ( error ) {
			// Clipboard access can be refused outright (no permission, or an
			// insecure context); the control says so rather than failing quietly.
			message = control.dataset.gamelibShareError || '';
		}

		if ( feedback ) {
			// Emptying first re-announces a repeated copy of the same link.
			feedback.textContent = '';
			feedback.textContent = message;
		}
	};

	/**
	 * Flip the opt-in public toggle (AC-027, AC-050d).
	 *
	 * @param {HTMLElement} control Visibility radio that was activated.
	 * @return {Promise<void>}
	 */
	const setVisibility = async ( control ) => {
		const group = control.closest( '[role="radiogroup"]' );
		const value = control.dataset.gamelibVisibilityValue || '';

		if ( ! group || '' === value ) {
			return;
		}

		if ( 'true' === control.getAttribute( 'aria-checked' ) ) {
			return;
		}

		// eslint-disable-next-line @wordpress/no-unused-vars-before-return -- Read before selectRadio() overwrites it; it is the rollback target and cannot be recovered later.
		const previous = selectedRadio( group );

		selectRadio( group, control );

		const seq = ++visibilitySeq;

		try {
			const payload = await request( 'account/visibility', {
				method: 'POST',
				body: { visibility: value },
			} );

			// Superseded while in flight (CO-1): the later write is the one
			// that decides whether the copy-link block is on screen.
			if ( seq !== visibilitySeq ) {
				return;
			}

			if ( shareBlock ) {
				// The copy-link control belongs to a public profile only.
				shareBlock.hidden = ! payload.is_public;
			}

			announce( payload.message || '' );
		} catch ( error ) {
			if ( seq !== visibilitySeq ) {
				return;
			}

			if ( previous ) {
				selectRadio( group, previous );
			}

			announce( error.message || offline );
		}
	};

	/**
	 * Say what is left of the member's invite allowance (AC-001, AC-002c).
	 *
	 * Every form of the sentence was translated in PHP and put on the element;
	 * this picks one and fills in the number, exactly as the pager does.
	 *
	 * @param {Object} payload Allowance from an invite response.
	 * @return {void}
	 */
	const sayAllowance = ( payload ) => {
		if ( ! allowance || ! payload ) {
			return;
		}

		const data = allowance.dataset;

		if ( payload.unlimited ) {
			allowance.textContent = data.gamelibAllowanceUnlimited || '';

			return;
		}

		const left = parseInt( payload.remaining, 10 ) || 0;

		if ( left < 1 ) {
			allowance.textContent = data.gamelibAllowanceNone || '';

			return;
		}

		const sentence =
			1 === left ? data.gamelibAllowanceOne : data.gamelibAllowanceOther;

		allowance.textContent = ( sentence || '' ).replace( '%s', left );
	};

	/**
	 * Mint an invite and put it at the top of the member's own list (AC-001).
	 *
	 * `POST /invites` answers JSON only, so the row is cloned from the
	 * server-rendered `<template>` and only its URL is written in — no markup
	 * and no copy is composed here (ADR-002, ADR-014).
	 *
	 * @param {HTMLElement} control Create button that was activated.
	 * @return {Promise<void>}
	 */
	const createInvite = async ( control ) => {
		control.disabled = true;

		try {
			const payload = await request( 'invites', {
				method: 'POST',
				body: {},
			} );

			const invite = payload.invite || {};
			const url = typeof invite.url === 'string' ? invite.url : '';

			if ( inviteList && inviteTemplate && '' !== url ) {
				const row = inviteTemplate.content
					.querySelector( '[data-gamelib-invite]' )
					.cloneNode( true );
				const label = row.querySelector( '[data-gamelib-invite-url]' );
				const copy = row.querySelector(
					'[data-gamelib-action="share.copy"]'
				);

				if ( label ) {
					label.textContent = url;
				}

				if ( copy ) {
					copy.dataset.gamelibUrl = url;
				}

				inviteList.prepend( row );
			}

			sayAllowance( payload.allowance );
			announce( payload.message || '' );

			// That may have been the last one in the allowance, in which case
			// the control stays disabled rather than offering a refusal.
			if ( payload.allowance && ! payload.allowance.can_create ) {
				return;
			}
		} catch ( error ) {
			announce( error.message || offline );
		}

		control.disabled = false;
	};

	/**
	 * Disconnect the stored SteamID (AC-046b).
	 *
	 * @param {HTMLElement} control Disconnect button that was activated.
	 * @return {Promise<void>}
	 */
	const disconnectSteam = async ( control ) => {
		control.disabled = true;

		try {
			// Left disabled on success: the row it sits in is hidden below, and
			// there is nothing left to disconnect.
			const payload = await request( 'account/steam', {
				method: 'DELETE',
			} );

			if ( steamId ) {
				steamId.textContent = '';
			}

			if ( steamAccount ) {
				steamAccount.value = '';
			}

			if ( steamConnected ) {
				steamConnected.hidden = true;
			}

			if ( steamDisconnected ) {
				steamDisconnected.hidden = false;
			}

			announce( payload.message || '' );
		} catch ( error ) {
			control.disabled = false;
			announce( error.message || offline );
		}
	};

	/**
	 * Download the member's own library as a file (AC-037 a,b).
	 *
	 * Fetched rather than navigated to (SE-1): a rendered anchor would have to
	 * carry the member's `wp_rest` nonce in the URL, where access logs, proxy
	 * logs and the browser's download history all keep a copy of a token that is
	 * valid for every `gamelib/v1` mutation route. `download()` sends it as
	 * `X-WP-Nonce` instead and hands back the bytes, which are saved through a
	 * one-shot object URL and a synthetic anchor — the only way to give a Blob
	 * the filename the server's `Content-Disposition` asked for.
	 *
	 * @param {HTMLElement} control Download button that was activated.
	 * @return {Promise<void>}
	 */
	const downloadExport = async ( control ) => {
		const format = control.dataset.gamelibExportFormat || '';
		const hadFocus = control.ownerDocument.activeElement === control;

		control.disabled = true;

		try {
			const file = await download(
				'export?format=' + window.encodeURIComponent( format )
			);
			const url = window.URL.createObjectURL( file.blob );
			const link = document.createElement( 'a' );

			link.href = url;
			link.download = file.filename;
			link.hidden = true;

			// Appended because Firefox ignores a click on a detached anchor.
			document.body.appendChild( link );
			link.click();
			link.remove();

			/*
			 * Revoked on the next task, not on this one (CO-6). The object URL
			 * pins the whole file in memory until it is revoked, but nothing in
			 * the platform specifies that the download's *read* of the blob has
			 * started by the time `click()` returns — on an engine that resolves
			 * the source asynchronously, revoking here cancels the save outright
			 * and the member gets a button that blinks and no file, with nothing
			 * to reject on.
			 */
			window.setTimeout( () => window.URL.revokeObjectURL( url ), 0 );
		} catch ( error ) {
			announce( error.message || offline );
		}

		control.disabled = false;

		// Disabling a focused control throws focus to <body>; the button is
		// still here, so it takes it back (ADR-022).
		if ( hadFocus ) {
			control.focus();
		}
	};

	/**
	 * Start a file or Steam import and follow it to its review screen.
	 *
	 * @param {HTMLElement} control Start button that was activated.
	 * @return {Promise<void>}
	 */
	const startImport = async ( control ) => {
		const type = control.dataset.gamelibImportType || 'file';

		let body;

		if ( 'steam' === type ) {
			body = {
				type: 'steam',
				steam_account: steamAccount ? steamAccount.value : '',
			};
		} else {
			if ( ! importFile || ! importFile.files.length ) {
				return;
			}

			body = new window.FormData();
			body.append( 'type', 'file' );
			body.append( 'file', importFile.files[ 0 ] );
		}

		control.disabled = true;

		try {
			const payload = await request( 'imports', {
				method: 'POST',
				body,
			} );

			announce( payload.message || '' );

			if ( typeof payload.url === 'string' && payload.url ) {
				// The review screen (AC-042) owns everything that happens next.
				window.location.assign( payload.url );

				return;
			}
		} catch ( error ) {
			announce( error.message || offline );
		}

		control.disabled = false;
	};

	/**
	 * Apply a status filter chip (AC-017a).
	 *
	 * @param {HTMLElement} control Chip that was activated.
	 * @return {Promise<void>}
	 */
	const applyFilter = async ( control ) => {
		const group = control.closest( '[role="radiogroup"]' );

		if ( ! group || 'true' === control.getAttribute( 'aria-checked' ) ) {
			return;
		}

		selectRadio( group, control );

		await refresh( { page: 1 } );
	};

	/**
	 * Turn one activated control into the thing it does.
	 *
	 * @param {HTMLElement} control Element carrying `data-gamelib-action`.
	 * @return {void}
	 */
	const act = ( control ) => {
		switch ( control.dataset.gamelibAction ) {
			case 'library.search':
				if ( 'BUTTON' === control.tagName ) {
					runSearch();
				}
				break;

			case 'library.add':
				addResult( control );
				break;

			case 'library.status':
				changeStatus( control );
				break;

			case 'library.remove':
				removeEntry( control );
				break;

			case 'library.filter':
				if ( 'radio' === control.getAttribute( 'role' ) ) {
					applyFilter( control );
				}
				break;

			case 'library.paginate':
				refresh( {
					page:
						page +
						( parseInt( control.dataset.gamelibPageStep, 10 ) ||
							0 ),
					focus: true,
				} );
				break;

			case 'library.bulk-select':
				select( control );
				break;

			case 'library.bulk-status':
				runBulk( control, 'status' );
				break;

			case 'library.bulk-remove':
				runBulk( control, 'remove' );
				break;

			case 'account.visibility':
				if ( 'radio' === control.getAttribute( 'role' ) ) {
					setVisibility( control );
				}
				break;

			case 'account.disconnect-steam':
				disconnectSteam( control );
				break;

			case 'invite.create':
				createInvite( control );
				break;

			case 'import.start':
				startImport( control );
				break;

			case 'export.download':
				downloadExport( control );
				break;

			case 'share.copy':
				copyLink( control );
				break;

			default:
				break;
		}
	};

	document.addEventListener( 'click', ( event ) => {
		const control = ancestor( event, '[data-gamelib-action]' );

		if ( control && ! control.disabled ) {
			act( control );
		}
	} );

	document.addEventListener( 'keydown', ( event ) => {
		const radio = ancestor( event, '[role="radio"]' );

		if ( radio ) {
			const group = radio.closest( '[role="radiogroup"]' );

			if ( ! group ) {
				return;
			}

			if ( NEXT_KEYS.includes( event.key ) ) {
				event.preventDefault();
				moveWithin( group, radio, 1 );
			} else if ( PREVIOUS_KEYS.includes( event.key ) ) {
				event.preventDefault();
				moveWithin( group, radio, -1 );
			} else if ( ' ' === event.key ) {
				// Enter already activates a <button>; Space would scroll.
				event.preventDefault();
				act( radio );
			}

			return;
		}

		if ( 'Enter' !== event.key ) {
			return;
		}

		// The two search fields are deliberately not inside a <form>
		// (Never Do #12), so Enter is wired here rather than submitted.
		if ( queryField && event.target === queryField ) {
			event.preventDefault();
			runSearch();

			return;
		}

		if ( filter && event.target === filter ) {
			event.preventDefault();
			window.clearTimeout( filterTimer );
			refresh( { page: 1 } );
		}
	} );

	if ( sort ) {
		sort.addEventListener( 'change', () => {
			refresh( { page: 1 } );
		} );
	}

	if ( filter ) {
		filter.addEventListener( 'input', () => {
			window.clearTimeout( filterTimer );
			filterTimer = window.setTimeout( () => {
				refresh( { page: 1 } );
			}, FILTER_DEBOUNCE_MS );
		} );
	}

	if ( importFile ) {
		// The start control offers itself only once there is a file to send,
		// which is why no "choose a file first" sentence exists.
		importFile.addEventListener( 'change', () => {
			const button = importFile.parentElement
				? importFile.parentElement.querySelector(
						'[data-gamelib-action="import.start"]'
				  )
				: null;

			if ( button ) {
				button.disabled = ! importFile.files.length;
			}
		} );
	}

	syncBulk();
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
