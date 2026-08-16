/**
 * Game Library — shared DOM helpers for the front-end bundles.
 *
 * Not a build entry: the bundles that need these import them and webpack
 * inlines the module, so the `wp-scripts build <entry…>` invocation is
 * unchanged.
 *
 * Two groups, both previously code-identical in `my-library.js` and
 * `public.js`: the delegated-event ancestor lookup, and the roving-tabindex
 * radiogroup helpers behind the plugin's three radiogroups (the four-way status
 * control on a card, the status filter chips, the visibility toggle).
 *
 * The radiogroups deliberately do **not** select on focus: each selection costs
 * a round trip, so the arrows move the roving tab stop and Space/Enter (or a
 * click) commits — the alternative the ARIA authoring practices allow for
 * exactly this case. That is why {@link moveWithin} moves focus without
 * touching `aria-checked`, and {@link selectRadio} is called separately by the
 * handler that commits.
 */

/** @type {string[]} Keys that move the roving tab stop forward in a radiogroup. */
export const NEXT_KEYS = [ 'ArrowRight', 'ArrowDown' ];

/** @type {string[]} Keys that move it back. */
export const PREVIOUS_KEYS = [ 'ArrowLeft', 'ArrowUp' ];

/**
 * The nearest ancestor of an event's target matching a selector.
 *
 * Events delegated at the document can land on the document itself, which has
 * no `closest()` — every delegated lookup goes through here so that case is a
 * miss rather than a thrown listener.
 *
 * @param {Event}  event    Delegated event.
 * @param {string} selector CSS selector to match.
 * @return {HTMLElement|null} Matching element, or null.
 */
export function ancestor( event, selector ) {
	const target = event.target;

	if ( ! target || typeof target.closest !== 'function' ) {
		return null;
	}

	return target.closest( selector );
}

/**
 * The radios of a radiogroup, in document order.
 *
 * Module-private: the three exported helpers below are what the bundles use,
 * and no entry has ever imported this one (AR-6 — it was exported by the
 * extraction pass, advertising a shared surface that does not exist).
 *
 * @param {HTMLElement} group Radiogroup element.
 * @return {HTMLElement[]} Its radios.
 */
function radios( group ) {
	return Array.from( group.querySelectorAll( '[role="radio"]' ) );
}

/**
 * Mark one radio as the group's selection.
 *
 * @param {HTMLElement} group  Radiogroup element.
 * @param {HTMLElement} chosen Radio to select.
 * @return {void}
 */
export function selectRadio( group, chosen ) {
	radios( group ).forEach( ( radio ) => {
		const selected = radio === chosen;

		radio.setAttribute( 'aria-checked', selected ? 'true' : 'false' );
		radio.tabIndex = selected ? 0 : -1;
	} );
}

/**
 * The radio a group currently reports as selected.
 *
 * @param {HTMLElement} group Radiogroup element.
 * @return {HTMLElement|null} Selected radio, or null when none is.
 */
export function selectedRadio( group ) {
	return group.querySelector( '[role="radio"][aria-checked="true"]' );
}

/**
 * Move the roving tab stop within a radiogroup, without selecting.
 *
 * @param {HTMLElement} group Radiogroup element.
 * @param {HTMLElement} from  Radio focus is on now.
 * @param {number}      delta -1 for previous, 1 for next.
 * @return {void}
 */
export function moveWithin( group, from, delta ) {
	const options = radios( group );
	const index = options.indexOf( from );

	if ( index < 0 || options.length < 2 ) {
		return;
	}

	const next = options[ ( index + delta + options.length ) % options.length ];

	options.forEach( ( radio ) => {
		radio.tabIndex = radio === next ? 0 : -1;
	} );

	next.focus();
}
