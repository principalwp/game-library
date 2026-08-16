/**
 * `/my-library/` entry mutations — status change, removal, and the
 * public/private profile toggle (AC-013(e), AC-013(f), AC-013(i)).
 *
 * Every handler updates the DOM only after its REST call resolves
 * successfully — never optimistically on click/change — per this task's own
 * constraint. Depends on `wp-api-fetch` (which transitively loads `wp-i18n`)
 * and the `window.gameLibraryData` global, both wired by
 * `Assets::localize_scripts()` (Task 4).
 *
 * @package
 */

( function () {
	'use strict';

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
	 * Reloads the current page when a `?status=` filter is active (CO-11).
	 *
	 * `handleStatusChange()`/`handleRemoveClick()` update a card's own badge
	 * or remove it from the DOM, but neither touches the server-rendered
	 * `#gl-status-filters` per-status counts (`my-library.php`), and while a
	 * status filter is active a card whose status no longer matches the
	 * filter should leave the grid entirely, which a live DOM patch alone
	 * cannot do generically. A reload is the simplest correct fix for
	 * exactly the case where it matters — reserved for a filtered view, so
	 * the common, unfiltered "All" view keeps its fast, no-reload update.
	 *
	 * @return {void}
	 */
	function reloadIfStatusFiltered() {
		if ( -1 !== window.location.search.indexOf( 'status=' ) ) {
			window.location.reload();
		}
	}

	/**
	 * Shows a card-scoped error message in the `[data-gl-card-error]` slot
	 * `game-card.php` renders for every entry.
	 *
	 * PF-4/DES-29 (cycle-3): no `hidden` attribute — `game-card.php` renders
	 * the slot always in the DOM (and the accessibility tree), empty; its
	 * reserved min-height (`.gl-error-text` in game-library.css) keeps the
	 * empty slot from taking visible space that would shift on first error.
	 *
	 * @param {HTMLElement} card    The `.gl-game-card` element.
	 * @param {string}      message Translated error text.
	 */
	function showCardError( card, message ) {
		const slot = card.querySelector( '[data-gl-card-error]' );

		if ( slot ) {
			slot.textContent = message;
		}
	}

	/**
	 * Clears a card-scoped error message.
	 *
	 * @param {HTMLElement} card The `.gl-game-card` element.
	 */
	function clearCardError( card ) {
		const slot = card.querySelector( '[data-gl-card-error]' );

		if ( slot ) {
			slot.textContent = '';
		}
	}

	/**
	 * Handles a status-selector change (AC-013(e), AC-011). Reverts the
	 * selector to its previous value on failure; updates the sibling status
	 * badge's class and text only after the REST call confirms success.
	 *
	 * @param {Event} event `change` event, delegated from the library grid.
	 */
	function handleStatusChange( event ) {
		const select = event.target;

		if (
			! select ||
			! select.matches ||
			! select.matches( '[data-gl-status-select]' )
		) {
			return;
		}

		// Resolve the `.gl-game-card` <li>, NOT `[data-igdb-id]`: the select
		// itself carries data-igdb-id (read on the next line), so
		// closest('[data-igdb-id]') resolves to the select — and the badge /
		// error lookups below (card.querySelector) then find nothing, leaving
		// the badge un-updated after an otherwise-successful status change.
		const card = select.closest( '.gl-game-card' );
		const igdbId = select.dataset.igdbId;
		const previousStatus = select.dataset.currentStatus;
		const newStatus = select.value;

		select.disabled = true;

		if ( card ) {
			clearCardError( card );
		}

		wp.apiFetch( {
			path: '/' + restNamespace() + '/library/' + igdbId,
			method: 'PATCH',
			data: { status: newStatus },
		} )
			.then( function () {
				select.dataset.currentStatus = newStatus;
				select.disabled = false;

				if ( card ) {
					const badge = card.querySelector( '.gl-status-badge' );

					if ( badge ) {
						badge.className =
							'gl-status-badge gl-status-badge--' + newStatus;
						badge.textContent =
							statusLabels()[ newStatus ] || newStatus;
					}
				}

				// CO-11: see reloadIfStatusFiltered()'s own docblock.
				reloadIfStatusFiltered();
			} )
			.catch( function () {
				select.value = previousStatus;
				select.disabled = false;

				if ( card ) {
					showCardError(
						card,
						wp.i18n.__(
							'Could not update status. Please try again.',
							'game-library'
						)
					);
				}
			} );
	}

	/**
	 * Handles a Remove click (AC-013(f), AC-012). Removes the card from the
	 * DOM only after the REST call confirms success.
	 *
	 * @param {Event} event `click` event, delegated from the library grid.
	 */
	function handleRemoveClick( event ) {
		const button = event.target.closest
			? event.target.closest( '[data-gl-remove]' )
			: null;

		if ( ! button ) {
			return;
		}

		// The remove button also carries data-igdb-id (read on the next line),
		// so target the `.gl-game-card` <li> explicitly — else closest()
		// returns the button and the removeChild() below detaches the button
		// instead of the card. See handleStatusChange() above.
		const card = button.closest( '.gl-game-card' );
		const igdbId = button.dataset.igdbId;

		button.disabled = true;

		if ( card ) {
			clearCardError( card );
		}

		wp.apiFetch( {
			path: '/' + restNamespace() + '/library/' + igdbId,
			method: 'DELETE',
		} )
			.then( function () {
				if ( card && card.parentNode ) {
					card.parentNode.removeChild( card );
				}

				// CO-11: see reloadIfStatusFiltered()'s own docblock.
				reloadIfStatusFiltered();
			} )
			.catch( function () {
				button.disabled = false;

				if ( card ) {
					showCardError(
						card,
						wp.i18n.__(
							'Could not remove this game. Please try again.',
							'game-library'
						)
					);
				}
			} );
	}

	/**
	 * Handles the public/private profile toggle (AC-013(i)). Immediately
	 * reverts the checkbox's native, pre-event toggle back to its prior
	 * value so the visible state never changes ahead of a confirmed REST
	 * response, then applies the new value only on success.
	 *
	 * PF-4/DES-29 (cycle-3): no `hidden` attribute on `errorEl` — see
	 * `showCardError()`'s own docblock above for why.
	 *
	 * @param {Event} event `change` event on `[data-gl-visibility-toggle]`.
	 */
	function handleVisibilityChange( event ) {
		const checkbox = event.target;
		const newValue = checkbox.checked;
		const errorEl = document.getElementById( 'gl-visibility-error' );

		checkbox.checked = ! newValue;
		checkbox.disabled = true;

		if ( errorEl ) {
			errorEl.textContent = '';
		}

		wp.apiFetch( {
			path: '/' + restNamespace() + '/profile/visibility',
			method: 'PATCH',
			data: { public: newValue },
		} )
			.then( function () {
				checkbox.checked = newValue;
				checkbox.disabled = false;
			} )
			.catch( function () {
				checkbox.disabled = false;

				if ( errorEl ) {
					errorEl.textContent = wp.i18n.__(
						'Could not update visibility. Please try again.',
						'game-library'
					);
				}
			} );
	}

	const grid = document.getElementById( 'gl-library-grid' );

	if ( grid ) {
		grid.addEventListener( 'change', handleStatusChange );
		grid.addEventListener( 'click', handleRemoveClick );
	}

	const visibilityToggle = document.querySelector(
		'[data-gl-visibility-toggle]'
	);

	if ( visibilityToggle ) {
		visibilityToggle.addEventListener( 'change', handleVisibilityChange );
	}
} )();
