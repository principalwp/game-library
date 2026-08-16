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

				// DES-50 (restyle §B row 4): the change succeeded, so collapse
				// the in-place select back to the badge + cycle trigger and
				// return keyboard focus to that trigger (never leaving focus
				// on a now-hidden control).
				hideStatusSelect( card );

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
	 * Reveals a card's in-place status `<select>` (design §3, restyle §B row
	 * 4): hides the badge + cycle-trigger cluster, un-hides the select, and
	 * moves focus onto it. No REST call — this is a pure display toggle; the
	 * actual status change still goes through `handleStatusChange` only on the
	 * select's `change`.
	 *
	 * @param {HTMLElement} card The `.gl-game-card` element, or null.
	 */
	function showStatusSelect( card ) {
		if ( ! card ) {
			return;
		}

		const display = card.querySelector( '.gl-game-card__status' );
		const select = card.querySelector( '[data-gl-status-select]' );

		if ( ! display || ! select ) {
			return;
		}

		display.hidden = true;
		select.hidden = false;
		select.focus();
	}

	/**
	 * Collapses a card's in-place status `<select>` back to the badge + cycle
	 * trigger and returns focus to the trigger. Idempotent — safe to call when
	 * the select is already hidden (e.g. from both the `change` success path
	 * and the `focusout` handler).
	 *
	 * @param {HTMLElement} card The `.gl-game-card` element, or null.
	 */
	function hideStatusSelect( card ) {
		if ( ! card ) {
			return;
		}

		const display = card.querySelector( '.gl-game-card__status' );
		const select = card.querySelector( '[data-gl-status-select]' );

		if ( ! display || ! select ) {
			return;
		}

		select.hidden = true;
		display.hidden = false;

		const trigger = display.querySelector( '[data-gl-status-trigger]' );

		if ( trigger ) {
			trigger.focus();
		}
	}

	/**
	 * Handles a cycle-trigger click (restyle §B row 4), delegated from the
	 * grid — reveals the in-place select for that card.
	 *
	 * @param {Event} event `click` event, delegated from the library grid.
	 */
	function handleStatusTriggerClick( event ) {
		const trigger = event.target.closest
			? event.target.closest( '[data-gl-status-trigger]' )
			: null;

		if ( ! trigger ) {
			return;
		}

		showStatusSelect( trigger.closest( '.gl-game-card' ) );
	}

	/**
	 * Collapses the in-place select when focus leaves it without a change
	 * (the user tabbed or clicked away). `focusout` (which bubbles), not
	 * `blur` (which does not), so a single delegated listener on the grid
	 * catches it. A successful `change` collapses the select itself, so by the
	 * time this fires the select is already hidden and `hideStatusSelect` is a
	 * no-op.
	 *
	 * @param {Event} event `focusout` event, delegated from the library grid.
	 */
	function handleStatusSelectFocusOut( event ) {
		const select = event.target;

		if (
			! select ||
			! select.matches ||
			! select.matches( '[data-gl-status-select]' ) ||
			select.hidden
		) {
			return;
		}

		hideStatusSelect( select.closest( '.gl-game-card' ) );
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
		grid.addEventListener( 'click', handleStatusTriggerClick );
		grid.addEventListener( 'focusout', handleStatusSelectFocusOut );
	}

	const visibilityToggle = document.querySelector(
		'[data-gl-visibility-toggle]'
	);

	if ( visibilityToggle ) {
		visibilityToggle.addEventListener( 'change', handleVisibilityChange );
	}
} )();
