/**
 * Game Library — public surfaces entry point.
 *
 * Compiles to `build/public.js`, enqueued on member profiles, `/activity/`,
 * `/join/{code}/`, and game pages; the base layer it renders against is a
 * separate entry (`style.js`), enqueued once for the whole plugin (PF-2). Four
 * controls live here, and no surface carries all of them — every lookup below is
 * optional, so the same bundle is inert on `/join/` and does exactly one thing
 * on a game page:
 *
 * - **`follow.toggle`** (a profile) — POST/DELETE `follow`, then write the
 *   server's own `aria-pressed` state, label, and follower count back.
 * - **`library.filter` / `library.paginate`** (a profile) — re-read
 *   `GET /members/{id}/library` and swap the read-only grid.
 * - **`feed.paginate`** (`/activity/`) — `GET /feed?before={cursor}`, append the
 *   fragment, move focus to the first new item.
 * - **`share.copy`** (a public profile, a game page) — write the canonical URL
 *   the control carries to the clipboard and confirm beside it.
 *
 * Like the other two entries this one composes no markup and authors no
 * member-facing string: every word it displays came from a server-rendered
 * fragment, a JSON `message`, or a `data-` attribute PHP wrote (ADR-002). That
 * is why there is no `@wordpress/i18n` import — there is nothing here to
 * translate.
 *
 * Two rules keep the asynchrony honest, the same two `/my-library/` uses: a
 * response older than the newest request is dropped on arrival, and a control
 * that started a request is disabled until it comes back. Both entries reach
 * the server through `shared/rest.js` and drive their radiogroups with
 * `shared/dom.js`; neither is a build entry, so webpack inlines them here and
 * the enqueued file list is unchanged.
 */

// No `main.scss` import here: the base layer is its own entry, `style.js`,
// enqueued once under a single handle on every plugin surface (PF-2).
import { request } from './shared/rest';
import {
	NEXT_KEYS,
	PREVIOUS_KEYS,
	ancestor,
	moveWithin,
	selectRadio,
	selectedRadio,
} from './shared/dom';

/** @type {string} Container id of the read-only library grid (AC-NFR-009 r). */
const GRID_ID = 'gamelib-library';

/** @type {string} Container id of the activity feed (AC-NFR-009 t). */
const FEED_ID = 'gamelib-feed';

/** @type {string} Id of the profile grid's heading — the post-swap focus target. */
const GRID_HEADING_ID = 'gamelib-profile-library-title';

/**
 * Substitute a count into one of two PHP-authored plural forms.
 *
 * The count is a client-side fact — how many items a response carried, what a
 * follow left the follower total at — so both forms are rendered by `_n()` in
 * PHP and read off the element. Locales with more than two plural forms get the
 * count-of-2 form for every count above one; the server-rendered first paint
 * still calls `_n()` with the real number.
 *
 * @param {HTMLElement} element Element carrying the two forms.
 * @param {string}      one     Dataset key of the count-of-1 form.
 * @param {string}      other   Dataset key of the count-of-2 form.
 * @param {number}      count   Count to substitute.
 * @return {string} The sentence, or '' when the element carries no form.
 */
function pluralize( element, one, other, count ) {
	const sentence =
		1 === count ? element.dataset[ one ] : element.dataset[ other ];

	return ( sentence || '' ).replace( '%s', String( count ) );
}

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
 * Wire up whichever of the public surfaces this document happens to be.
 *
 * @return {void}
 */
function start() {
	const live = document.querySelector( '[data-gamelib-live]' );

	// A profile (the follow toggle, the read-only grid and its controls).
	const profile = document.querySelector( '[data-gamelib-profile]' );
	const memberId = profile
		? parseInt( profile.dataset.gamelibMemberId, 10 ) || 0
		: 0;
	const followers = profile
		? profile.querySelector( '[data-gamelib-followers]' )
		: null;

	const panel = document.querySelector( '[data-gamelib-member-library]' );
	const grid = document.getElementById( GRID_ID );
	const gridHeading = document.getElementById( GRID_HEADING_ID );
	const chips = panel ? panel.querySelector( '.gamelib-chips' ) : null;
	const pager = panel ? panel.querySelector( '[data-gamelib-pager]' ) : null;

	// `/activity/` (the keyset feed). The load-more control is reached through
	// the delegated click handler, so it needs no reference of its own.
	const feed = document.getElementById( FEED_ID );

	// The one sentence no response can carry: a request that never reached the
	// server has no server message. Authored in PHP, read off the markup.
	const offlineSource =
		panel || ( feed ? feed.closest( '[data-gamelib-error]' ) : null );
	const offline = offlineSource
		? offlineSource.dataset.gamelibError || ''
		: '';

	let listSeq = 0;
	let page = 1;

	/*
	 * One AbortController for the one supersedable lane on these surfaces
	 * (PF-2): the profile grid's filter and pager. The sequence counter below
	 * decides which response may touch the DOM; it cannot stop a superseded one
	 * from being downloaded and parsed first. Re-created rather than nulled, so
	 * the signal is always live, and it aborts only its own predecessor — the
	 * follow toggle and "Load more" are writes and appends, never superseded.
	 */
	let listController = new window.AbortController();

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
	 * The status filter currently applied to the profile grid.
	 *
	 * @return {string} One of the four statuses, or `all`.
	 */
	const currentStatus = () => {
		const chosen = chips ? selectedRadio( chips ) : null;

		return chosen ? chosen.dataset.gamelibStatus || 'all' : 'all';
	};

	/**
	 * Bring the profile pager in line with the page the server just rendered.
	 *
	 * Only numbers are written here — the sentence around them was translated in
	 * PHP and its two placeholders are elements this fills in.
	 *
	 * @param {Object} payload Response from `GET /members/{id}/library`.
	 * @return {void}
	 */
	const syncPager = ( payload ) => {
		const pages = parseInt( payload.pages, 10 ) || 0;
		const current = parseInt( payload.page, 10 ) || 1;
		const total = parseInt( payload.total, 10 ) || 0;

		page = current;

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
	 * Re-read one page of the member's library and swap the grid (AC-031c).
	 *
	 * @param {Object}  options         Refresh options.
	 * @param {number}  [options.page]  Page to read; defaults to the current one.
	 * @param {boolean} [options.focus] Move focus to the grid heading afterwards.
	 * @return {Promise<void>}
	 */
	const refresh = async ( options = {} ) => {
		if ( ! grid || memberId < 1 ) {
			return;
		}

		const wanted = undefined === options.page ? page : options.page;
		const { seq, signal } = claimList();

		const query = new window.URLSearchParams( {
			status: currentStatus(),
			page: String( Math.max( 1, wanted ) ),
		} ).toString();

		let payload;

		try {
			payload = await request(
				`members/${ memberId }/library?${ query }`,
				{ signal }
			);
		} catch ( error ) {
			// This read was superseded by a newer one: its answer was never
			// wanted, and nothing went wrong.
			if ( isAbort( error ) ) {
				return;
			}

			if ( seq === listSeq ) {
				announce( error.message || offline );
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

		syncPager( payload );
		announceState( grid );

		if ( options.focus && gridHeading ) {
			gridHeading.focus();
		}
	};

	/**
	 * Apply a status filter chip on the profile grid (AC-031c).
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
	 * Follow or unfollow the profile's owner (AC-022).
	 *
	 * Open follow: one round trip, no approval, no reload. The button's state,
	 * its accessible name, and the follower count all come back from the server
	 * — `aria-pressed` is written from `payload.following` rather than toggled
	 * locally, so a double-clicked control cannot end up claiming a state the
	 * database does not hold.
	 *
	 * @param {HTMLElement} control Toggle that was activated.
	 * @return {Promise<void>}
	 */
	const toggleFollow = async ( control ) => {
		const targetId = parseInt( control.dataset.gamelibUserId, 10 ) || 0;

		if ( targetId < 1 ) {
			return;
		}

		const following = 'true' === control.getAttribute( 'aria-pressed' );

		/*
		 * Disabling the element that has focus drops focus to <body>, so a
		 * reader who pressed this toggle with the keyboard would be returned to
		 * the top of the document and lose the state change they just made
		 * (AC-NFR-004b). The bundle's other write paths already hand focus back
		 * — `changeStatus()` refocuses the option it re-rendered, "Load more"
		 * moves focus to the first appended item — so this one does too.
		 * See principal/adr/022-follow-toggle-keeps-focus.md — this file is
		 * outside Task 29's Files list.
		 */
		const hadFocus = control.ownerDocument.activeElement === control;

		control.disabled = true;

		try {
			const payload = await request( 'follow', {
				method: following ? 'DELETE' : 'POST',
				body: { user_id: targetId },
			} );

			control.setAttribute(
				'aria-pressed',
				payload.following ? 'true' : 'false'
			);

			if ( typeof payload.label === 'string' && payload.label ) {
				// AC-022(c): the accessible name is the visible label, and it
				// names the member — composed by PHP, in both states.
				control.textContent = payload.label;
			}

			if ( followers ) {
				const count = parseInt( payload.followers, 10 ) || 0;

				followers.textContent = pluralize(
					followers,
					'gamelibFollowersOne',
					'gamelibFollowersOther',
					count
				);
			}

			announce( payload.message || '' );
		} catch ( error ) {
			announce(
				error.message || control.dataset.gamelibError || offline
			);
		}

		control.disabled = false;

		if ( hadFocus ) {
			control.focus();
		}
	};

	/**
	 * Append the next keyset page of the feed (AC-025b).
	 *
	 * The cursor lives on the control, is sent as `before`, and is replaced by
	 * whatever the server answers with — there is no offset, no page number, and
	 * no total anywhere in this path.
	 *
	 * Accepted trade-off (PF-11): appended items are never trimmed, so 25
	 * activations hold 500 items and every later style recalculation walks the
	 * whole list. Capping the retained window would mean removing items from the
	 * *top* of a list the member has scrolled past, which moves the scroll
	 * position under them — a worse regression than the recalculation cost, and
	 * `.gamelib-feed__item`'s `content-visibility` already keeps the off-screen
	 * items out of layout and paint. The growth is bounded by deliberate clicks
	 * and the design rules out infinite scroll (AC-025b).
	 *
	 * @param {HTMLElement} control Load-more button that was activated.
	 * @return {Promise<void>}
	 */
	const loadMore = async ( control ) => {
		const before = parseInt( control.dataset.gamelibCursor, 10 ) || 0;

		if ( ! feed || before < 1 ) {
			return;
		}

		// Where the appended items will start, read before the append.
		const boundary = feed.children.length;

		control.disabled = true;

		try {
			const payload = await request( `feed?before=${ before }` );
			const count = parseInt( payload.count, 10 ) || 0;
			const next = parseInt( payload.next, 10 ) || 0;

			if ( count > 0 && typeof payload.html === 'string' ) {
				feed.insertAdjacentHTML( 'beforeend', payload.html );
			}

			control.dataset.gamelibCursor = String( next );
			control.hidden = ! payload.has_more || next < 1;

			const first = feed.children[ boundary ];

			if ( first && 'function' === typeof first.focus ) {
				// AC-NFR-004: an append that nobody is looking at is not an
				// update — focus lands on the first item that arrived.
				first.focus();
			}

			announce(
				count > 0
					? pluralize(
							control,
							'gamelibMoreOne',
							'gamelibMoreOther',
							count
					  )
					: control.dataset.gamelibMoreEnd || ''
			);
		} catch ( error ) {
			announce( error.message || offline );
		}

		if ( ! control.hidden ) {
			control.disabled = false;
		}
	};

	/**
	 * Copy a URL the server put on the control, and say so (AC-050 b,c).
	 *
	 * The confirmation goes into the control's own `role="status"` sibling
	 * rather than the page's live region, so a copy on a game page and a copy on
	 * a profile behave identically without either announcing over the other.
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
	 * Turn one activated control into the thing it does.
	 *
	 * @param {HTMLElement} control Element carrying `data-gamelib-action`.
	 * @return {void}
	 */
	const act = ( control ) => {
		switch ( control.dataset.gamelibAction ) {
			case 'follow.toggle':
				toggleFollow( control );
				break;

			case 'library.filter':
				if ( 'radio' === control.getAttribute( 'role' ) ) {
					applyFilter( control );
				}
				break;

			case 'library.paginate': {
				const step =
					parseInt( control.dataset.gamelibPageStep, 10 ) || 0;

				refresh( { page: page + step, focus: true } );
				break;
			}

			case 'feed.paginate':
				loadMore( control );
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

		if ( ! radio ) {
			return;
		}

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
	} );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
