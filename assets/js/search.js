/**
 * IGDB search UI on `/my-library/` (AC-004, AC-013(g)).
 *
 * Drives the five mutually-exclusive search states (idle/loading/results/
 * empty/unavailable) against the `#gl-search-results` region `search-form.php`
 * renders, debounced at 300ms, aborting any in-flight request before issuing
 * a new one so a late response can never overwrite a newer query's results.
 * Also posts the "Add to library" action for each result — this task's own
 * Description assigns both responsibilities to this file.
 *
 * Depends on `wp-api-fetch` (which transitively loads `wp-i18n` and `wp-url`)
 * and the `window.gameLibraryData` global — both wired by
 * `Assets::localize_scripts()` (Task 4).
 *
 * @package
 */

( function () {
	'use strict';

	const DEBOUNCE_MS = 300;
	const MIN_QUERY_LENGTH = 2;

	const searchInput = document.getElementById( 'gl-search-input' );
	const resultsRegion = document.getElementById( 'gl-search-results' );

	if ( ! searchInput || ! resultsRegion ) {
		return;
	}

	const searchForm = document.getElementById( 'gl-search-form' );

	let debounceTimer = null;
	let activeController = null;

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
	 * The translated status => label map, exposed by
	 * `Assets::localize_scripts()`.
	 *
	 * @return {Object} Status labels.
	 */
	function statusLabels() {
		return (
			( window.gameLibraryData && window.gameLibraryData.statusLabels ) ||
			{}
		);
	}

	/**
	 * Removes every child node from the results region — never
	 * `innerHTML = ''`, matching the "never insert REST data via innerHTML"
	 * constraint's spirit of building/clearing the DOM through element APIs.
	 */
	function clearResults() {
		resultsRegion.replaceChildren();
	}

	/**
	 * AC-004(a) — no query entered, no results region rendered.
	 */
	function setIdleState() {
		if ( activeController ) {
			activeController.abort();
			activeController = null;
		}

		clearResults();
		resultsRegion.hidden = true;
		resultsRegion.setAttribute( 'aria-busy', 'false' );
	}

	/**
	 * AC-004(b) — a spinner and `aria-busy="true"` while a request is in
	 * flight.
	 */
	function setLoadingState() {
		clearResults();
		resultsRegion.hidden = false;
		resultsRegion.setAttribute( 'aria-busy', 'true' );

		const wrap = document.createElement( 'div' );
		wrap.className = 'gl-search-loading';

		const spinner = document.createElement( 'span' );
		spinner.className = 'gl-spinner';
		wrap.appendChild( spinner );

		// DES-14: a visible text alternative, not just the spinner's own
		// motion — a prefers-reduced-motion visitor gets a stopped spinner
		// with no motion cue at all otherwise, and this also gives the
		// aria-live region something explicit to announce.
		const message = document.createElement( 'span' );
		message.textContent = wp.i18n.__( 'Searching…', 'game-library' );
		wrap.appendChild( message );

		resultsRegion.appendChild( wrap );
	}

	/**
	 * AC-004(d) — "No games found" when the API returned an empty array.
	 */
	function setEmptyState() {
		clearResults();
		resultsRegion.hidden = false;
		resultsRegion.setAttribute( 'aria-busy', 'false' );

		const message = document.createElement( 'p' );
		message.className = 'gl-search-empty';
		message.textContent = wp.i18n.__( 'No games found', 'game-library' );
		resultsRegion.appendChild( message );
	}

	/**
	 * AC-004(e) — a fixed message when the API returned any error.
	 */
	function setUnavailableState() {
		clearResults();
		resultsRegion.hidden = false;
		resultsRegion.setAttribute( 'aria-busy', 'false' );

		const message = document.createElement( 'p' );
		message.className = 'gl-search-unavailable';
		message.textContent = wp.i18n.__(
			'Search is unavailable right now. Please try again.',
			'game-library'
		);
		resultsRegion.appendChild( message );
	}

	/**
	 * Builds one result's cover container — a fixed-aspect-ratio box with an
	 * `alt`-carrying `img`, or a text placeholder when the result has no
	 * cover (AC-016, shared visual contract with `game-card.php`).
	 *
	 * @param {Object} item One `/search` response item.
	 * @return {HTMLElement} Cover container.
	 */
	function buildCover( item ) {
		const wrap = document.createElement( 'div' );
		wrap.className = 'gl-search-result__cover';

		if ( item.cover_url ) {
			const img = document.createElement( 'img' );
			img.src = item.cover_url;
			img.alt = item.name;
			img.loading = 'lazy';
			img.decoding = 'async';
			wrap.appendChild( img );
		} else {
			const placeholder = document.createElement( 'span' );
			// DES-23: this block's own class, not a borrowed
			// .gl-game-card__cover-placeholder — both are listed together on
			// the shared rule in game-library.css.
			placeholder.className = 'gl-search-result__cover-placeholder';
			placeholder.textContent = item.name;
			wrap.appendChild( placeholder );
		}

		return wrap;
	}

	/**
	 * Builds one result's status selector — the four visible-text options
	 * AC-015(d) requires, in the order `window.gameLibraryData.statusLabels`
	 * was serialized (matching `Statuses::all()`'s order).
	 *
	 * @param {Object} item One `/search` response item.
	 * @return {HTMLSelectElement} Status selector.
	 */
	function buildStatusSelect( item ) {
		const select = document.createElement( 'select' );
		select.setAttribute( 'data-gl-add-status', '' );
		select.setAttribute(
			'aria-label',
			wp.i18n.sprintf(
				// translators: %s: game title.
				wp.i18n.__( 'Status for %s', 'game-library' ),
				item.name
			)
		);

		const labels = statusLabels();

		Object.keys( labels ).forEach( function ( status ) {
			const option = document.createElement( 'option' );
			option.value = status;
			// DES-50 (restyle §B row 5): "●"-prefixed per status — the same
			// native-<option> treatment game-card.php renders, so the search
			// picker carries the matching status dot whether the control is
			// open or closed. DES-102: the per-option colour is no longer set
			// inline here — the `.gl-search-result__actions select
			// option[value=x]` rules in game-library.css (beside the
			// closed-select `:has()` colouring) own the status->colour mapping
			// in one place, so the open list, the closed control, and the card
			// badge all match.
			option.textContent = '● ' + labels[ status ];
			select.appendChild( option );
		} );

		return select;
	}

	/**
	 * Handles the "Add to library" click for one result — the search UI's
	 * own POST, per this task's Description. Fires the confirmed state change
	 * (button text, disabled selector) only after the REST call resolves.
	 *
	 * @param {HTMLButtonElement} button    "Add to library" control.
	 * @param {HTMLSelectElement} select    That result's status selector.
	 * @param {HTMLElement}       errorSlot Inline error element for this result.
	 * @param {Object}            item      One `/search` response item.
	 */
	function handleAdd( button, select, errorSlot, item ) {
		button.disabled = true;
		errorSlot.hidden = true;

		wp.apiFetch( {
			path: '/' + restNamespace() + '/library',
			method: 'POST',
			data: {
				igdb_id: item.igdb_id,
				status: select.value,
			},
		} )
			.then( function () {
				button.textContent = wp.i18n.__( 'Added', 'game-library' );
				select.disabled = true;
			} )
			.catch( function () {
				button.disabled = false;
				errorSlot.textContent = wp.i18n.__(
					'Could not add this game. Please try again.',
					'game-library'
				);
				errorSlot.hidden = false;
			} );
	}

	/**
	 * Builds one full search-result row (AC-004(c)). The "Add to library"
	 * control reads `item.already_in_library` (AC-003, `Search_Controller`)
	 * and swaps its label to "Update status" for a result the member already
	 * holds, so re-adding a held game does not look like a first-time add —
	 * the POST itself already updates rather than duplicates an existing
	 * entry, this only makes that visible. The status selector cannot be
	 * preselected to the member's stored status: the search payload only
	 * carries the boolean, not the status value itself.
	 *
	 * @param {Object} item One `/search` response item.
	 * @return {HTMLElement} Result row.
	 */
	function buildResultRow( item ) {
		const row = document.createElement( 'div' );
		row.className = 'gl-search-result';
		row.id = 'gl-search-result-' + item.igdb_id;

		row.appendChild( buildCover( item ) );

		const body = document.createElement( 'div' );
		body.className = 'gl-search-result__body';

		const title = document.createElement( 'p' );
		// DES-23: this block's own classes, not borrowed .gl-game-card__*
		// ones — both blocks' classes are listed together on the shared
		// rules in game-library.css.
		title.className = 'gl-search-result__title';
		title.textContent = item.name;
		body.appendChild( title );

		const meta = document.createElement( 'p' );
		meta.className = 'gl-search-result__meta';
		meta.textContent = item.first_release_year
			? String( item.first_release_year )
			: wp.i18n.__( 'Unreleased', 'game-library' );
		body.appendChild( meta );

		row.appendChild( body );

		const actions = document.createElement( 'div' );
		actions.className = 'gl-search-result__actions';

		const select = buildStatusSelect( item );
		actions.appendChild( select );

		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'gl-button gl-button--primary';
		button.setAttribute( 'data-gl-add', '' );
		button.dataset.igdbId = String( item.igdb_id );
		button.textContent = item.already_in_library
			? wp.i18n.__( 'Update status', 'game-library' )
			: wp.i18n.__( 'Add to library', 'game-library' );

		// DES-32: .gl-error-text (was .gl-field__error) — block-agnostic
		// name; this slot keeps the `hidden`-attribute toggle unchanged
		// (PF-4 exempts it — it lives inside the fixed-height
		// .gl-search-results scroll box and cannot shift anything outside
		// it), unlike library.js's/social.js's error slots.
		const errorSlot = document.createElement( 'p' );
		errorSlot.className = 'gl-error-text';
		errorSlot.setAttribute( 'data-gl-result-error', '' );
		errorSlot.hidden = true;

		button.addEventListener( 'click', function () {
			handleAdd( button, select, errorSlot, item );
		} );

		actions.appendChild( button );
		actions.appendChild( errorSlot );

		row.appendChild( actions );

		return row;
	}

	/**
	 * AC-004(c) — a list of matches, each with an "Add to library" control
	 * and a status selector.
	 *
	 * @param {Array} items `/search` response items.
	 */
	function setResultsState( items ) {
		// Build off-document and commit in one mutation (PF-8) — ten
		// separate appends into this aria-live="polite" region can queue
		// ten separate screen-reader announcements, and each append dirties
		// layout for the already-rendered subtree.
		const fragment = document.createDocumentFragment();

		items.forEach( function ( item ) {
			fragment.appendChild( buildResultRow( item ) );
		} );

		clearResults();
		resultsRegion.hidden = false;
		resultsRegion.setAttribute( 'aria-busy', 'false' );
		resultsRegion.appendChild( fragment );
	}

	/**
	 * Issues the debounced search request, aborting any request still in
	 * flight first so a late response can never overwrite a newer query's
	 * results.
	 *
	 * @param {string} query Trimmed search query.
	 */
	function performSearch( query ) {
		if ( activeController ) {
			activeController.abort();
		}

		// PF-6: capture this request's own controller in a local and
		// compare identity before touching shared state or the DOM in
		// either callback. `Response.json()` resolves in a separate task,
		// so it is possible for a newer request's headers to arrive, start
		// (and reassign `activeController` to) request B, and only then
		// have request A's body finish parsing — without this guard, A's
		// `.then` would null out B's controller and paint A's stale
		// results over B's still-loading state.
		const controller = new AbortController();
		activeController = controller;
		setLoadingState();

		wp.apiFetch( {
			path: wp.url.addQueryArgs( '/' + restNamespace() + '/search', {
				q: query,
			} ),
			signal: controller.signal,
		} )
			.then( function ( items ) {
				if ( activeController !== controller ) {
					return;
				}

				activeController = null;

				if ( ! items || 0 === items.length ) {
					setEmptyState();
				} else {
					setResultsState( items );
				}
			} )
			.catch( function ( error ) {
				if ( activeController !== controller ) {
					return;
				}

				if ( error && 'AbortError' === error.name ) {
					// A newer query already superseded this one; leave its
					// state alone.
					return;
				}

				activeController = null;
				setUnavailableState();
			} );
	}

	/**
	 * Debounces the search input at 300ms (this task's own constraint).
	 */
	function handleInput() {
		const query = searchInput.value.trim();

		window.clearTimeout( debounceTimer );

		if ( query.length < MIN_QUERY_LENGTH ) {
			setIdleState();
			return;
		}

		debounceTimer = window.setTimeout( function () {
			performSearch( query );
		}, DEBOUNCE_MS );
	}

	searchInput.addEventListener( 'input', handleInput );

	if ( searchForm ) {
		searchForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
		} );
	}
} )();
