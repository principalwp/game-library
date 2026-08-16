/**
 * Follow/unfollow toggle, shared by `/library/{nicename}/` (Task 13's
 * member-library.php) and `/members/` (this task's members.php) — both
 * render the same `[data-gl-follow-toggle]`/`data-user-id`/`data-following`
 * markup contract (AC-019).
 *
 * Delegates a single click listener from `#gl-main`, present on every plugin
 * route, to every matching control on the page — member-library.php renders
 * exactly one, members.php renders one per row. The control's visible text
 * and `data-following` state change only after the REST call resolves
 * successfully — never optimistically on click — and the control's
 * accessible name (its own text content) changes with its state, per this
 * task's own constraints.
 *
 * Depends on `wp-api-fetch` (which transitively loads `wp-i18n`) and the
 * `window.gameLibraryData` global, both wired by `Assets::localize_scripts()`
 * (Task 4).
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
	 * Finds the error slot next to a follow/unfollow control (MR-4,
	 * cycle-5). Both templates this file runs on (`members.php`,
	 * `member-library.php`) now pre-render this slot next to the control
	 * with `role="alert"`, so a screen reader announces a failed
	 * follow/unfollow — a slot created via DOM APIs on first use, as this
	 * method used to, is not in the accessibility tree at page load and
	 * never carried the `role`. Returns `null` when the slot is missing
	 * (a theme override of one of those two templates that omits it);
	 * callers must guard for that rather than this method re-creating one
	 * with no `role` to fall back to.
	 *
	 * @param {HTMLElement} button Follow/unfollow control.
	 * @return {?HTMLElement} Error slot, or `null` if not pre-rendered.
	 */
	function getErrorSlot( button ) {
		return button.parentElement.querySelector( '[data-gl-follow-error]' );
	}

	/**
	 * Applies the confirmed follow state to a control: visible text and
	 * `data-following`, both driven from the same boolean so they can never
	 * disagree.
	 *
	 * @param {HTMLElement} button    Follow/unfollow control.
	 * @param {boolean}     following New state.
	 */
	function applyState( button, following ) {
		button.dataset.following = following ? '1' : '0';
		button.textContent = following
			? wp.i18n.__( 'Unfollow', 'game-library' )
			: wp.i18n.__( 'Follow', 'game-library' );
	}

	/**
	 * Handles a follow/unfollow click (AC-019).
	 *
	 * @param {Event} event `click` event, delegated from `#gl-main`.
	 */
	function handleClick( event ) {
		const button = event.target.closest
			? event.target.closest( '[data-gl-follow-toggle]' )
			: null;

		if ( ! button ) {
			return;
		}

		const userId = button.dataset.userId;
		const wasFollowing = '1' === button.dataset.following;
		const errorSlot = getErrorSlot( button );

		button.disabled = true;

		if ( errorSlot ) {
			errorSlot.textContent = '';
		}

		wp.apiFetch( {
			path: '/' + restNamespace() + '/follow/' + userId,
			method: wasFollowing ? 'DELETE' : 'POST',
		} )
			.then( function () {
				applyState( button, ! wasFollowing );
				// CO-10: cleanup duplicated into both settled paths, not
				// Promise.prototype.finally() (ES2018, above this project's
				// DD-007 ES2017 floor — see invites.js's matching fix for
				// the same reason).
				button.disabled = false;
			} )
			.catch( function () {
				if ( errorSlot ) {
					errorSlot.textContent = wp.i18n.__(
						'Could not update follow status. Please try again.',
						'game-library'
					);
				}
				button.disabled = false;
			} );
	}

	const main = document.getElementById( 'gl-main' );

	if ( main ) {
		main.addEventListener( 'click', handleClick );
	}
} )();
