/**
 * `/invites/` — create, revoke, and copy an invite (AC-024, AC-026, AC-028).
 *
 * Drives three actions against `Invite_Controller`'s REST routes (this
 * task):
 *
 * - Submitting `#gl-invite-form` posts to `POST /invites` and, on success,
 *   prepends a newly built row to `#gl-invite-list` (AC-026 — code and
 *   redemption URL with a copy control render regardless of email delivery;
 *   a warning renders when the response's `mail_sent` is `false`). A
 *   quota-exceeded rejection renders as a general notice; a malformed email
 *   renders as a field-level error adjacent to the email input, with
 *   `aria-invalid`/`aria-describedby` (AC-028).
 * - Clicking `[data-gl-revoke-invite]` sends `DELETE /invites/{id}` and, on
 *   success, updates that row's status text and removes the Revoke control —
 *   revoking never removes the row (D40), matching the server's own
 *   behaviour.
 * - Clicking `[data-gl-copy-invite]` copies that row's redemption URL to the
 *   clipboard and shows a transient "Copied!" confirmation.
 *
 * Every DOM update happens only after its REST call resolves successfully —
 * never optimistically on click — and every value from a REST response is
 * inserted via `textContent`/element properties, never `innerHTML`, per this
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
	 * The translated label for one of the four D40 invite statuses, mirroring
	 * `invites.php`'s own `$status_labels` map.
	 *
	 * @param {string} status Invite status.
	 * @return {string} Translated label.
	 */
	function statusLabel( status ) {
		const labels = {
			pending: wp.i18n.__( 'Pending', 'game-library' ),
			redeemed: wp.i18n.__( 'Redeemed', 'game-library' ),
			expired: wp.i18n.__( 'Expired', 'game-library' ),
			revoked: wp.i18n.__( 'Revoked', 'game-library' ),
		};

		return labels[ status ] || status;
	}

	/**
	 * Formats a `Y-m-d H:i:s` UTC datetime string (as `Invite_Controller`
	 * returns it) using the browser's own locale — not an exact match for
	 * `invites.php`'s server-rendered `date_i18n()` formatting, but
	 * sufficient for the one newly created row this file ever renders a date
	 * for.
	 *
	 * @param {string} utcDatetime `Y-m-d H:i:s` UTC string.
	 * @return {string} Locale-formatted date, or '' when unparsable.
	 */
	function formatDate( utcDatetime ) {
		if ( ! utcDatetime ) {
			return '';
		}

		const date = new Date( utcDatetime.replace( ' ', 'T' ) + 'Z' );

		return isNaN( date.getTime() ) ? '' : date.toLocaleDateString();
	}

	const form = document.getElementById( 'gl-invite-form' );
	const emailField = document.getElementById( 'gl-invite-email-field' );
	const emailInput = document.getElementById( 'gl-invite-email' );
	const emailError = document.getElementById( 'gl-invite-email-error' );
	const generalError = document.getElementById( 'gl-invite-form-error' );
	const generalSuccess = document.getElementById( 'gl-invite-form-success' );
	const list = document.getElementById( 'gl-invite-list' );
	const emptyState = document.getElementById( 'gl-invites-empty' );
	const quotaRemaining = document.getElementById(
		'gl-invite-quota-remaining'
	);

	/**
	 * Clears every error state the create form can carry.
	 *
	 * DES-29 (cycle-3): no `data-gl-visible` attribute — `generalError`/
	 * `generalSuccess` stay in flow, always rendered, and `:empty`
	 * (game-library.css) hides an empty notice's chrome while keeping both
	 * `role="alert"`/`role="status"` live regions in the accessibility tree
	 * at all times; a `visibility: hidden` toggle removed them from that
	 * tree entirely while empty.
	 */
	function clearFormErrors() {
		if ( emailError ) {
			emailError.hidden = true;
			emailError.textContent = '';
		}

		if ( emailInput ) {
			emailInput.removeAttribute( 'aria-invalid' );
			emailInput.removeAttribute( 'aria-describedby' );
		}

		if ( emailField ) {
			emailField.dataset.invalid = 'false';
		}

		if ( generalError ) {
			generalError.textContent = '';
		}

		if ( generalSuccess ) {
			generalSuccess.textContent = '';
		}
	}

	/**
	 * Shows a transient confirmation that a new invite was created (AC-026),
	 * mirroring `setGeneralError()`'s shape but on the `.gl-notice--success`
	 * element `clearFormErrors()` also resets on the next submission.
	 */
	function setGeneralSuccess() {
		if ( ! generalSuccess ) {
			return;
		}

		generalSuccess.textContent = wp.i18n.__(
			'Invite created.',
			'game-library'
		);
	}

	/**
	 * Renders a field-level error on the email input (AC-028): text adjacent
	 * to the input, referenced by `aria-describedby`, with
	 * `aria-invalid="true"` on the input.
	 *
	 * @param {string} message Translated error text.
	 */
	function setEmailFieldError( message ) {
		if ( ! emailError || ! emailInput || ! emailField ) {
			return;
		}

		emailError.textContent = message;
		emailError.hidden = false;
		emailInput.setAttribute( 'aria-invalid', 'true' );
		emailInput.setAttribute( 'aria-describedby', emailError.id );
		emailField.dataset.invalid = 'true';
	}

	/**
	 * Renders a general (non-field) error, e.g. a quota rejection (AC-024) —
	 * not "adjacent to an offending input" since no single input is at fault.
	 *
	 * @param {string} message Translated error text.
	 */
	function setGeneralError( message ) {
		if ( ! generalError ) {
			return;
		}

		generalError.textContent = message;
	}

	/**
	 * Updates the "N invites remaining" notice from a `create_invite`
	 * response's `quota` figures, without a full page reload.
	 *
	 * @param {Object} quota `{quota, used, remaining, reset_date}`.
	 */
	function updateQuota( quota ) {
		if ( ! quota || ! quotaRemaining ) {
			return;
		}

		while ( quotaRemaining.firstChild ) {
			quotaRemaining.removeChild( quotaRemaining.firstChild );
		}

		quotaRemaining.appendChild(
			document.createTextNode(
				wp.i18n.sprintf(
					/* translators: %d: number of invites remaining. */
					wp.i18n._n(
						'%d invite remaining.',
						'%d invites remaining.',
						quota.remaining,
						'game-library'
					),
					quota.remaining
				)
			)
		);

		if ( quota.reset_date ) {
			quotaRemaining.appendChild( document.createTextNode( ' ' ) );

			const resetSpan = document.createElement( 'span' );
			resetSpan.id = 'gl-invite-quota-reset';
			resetSpan.textContent = wp.i18n.sprintf(
				/* translators: %s: the date the invite quota next frees up. */
				wp.i18n.__( 'Quota resets %s.', 'game-library' ),
				quota.reset_date
			);
			quotaRemaining.appendChild( resetSpan );
		}
	}

	/**
	 * Builds one invite row's DOM, matching `invites.php`'s server-rendered
	 * markup contract exactly (AC-026's code/URL/copy-control/warning, plus
	 * status/created/expires/revoke).
	 *
	 * @param {Object} invite `Invite_Controller::format_delivery()` payload.
	 * @return {HTMLElement} `<li class="gl-invite-row">`.
	 */
	function buildInviteRow( invite ) {
		const li = document.createElement( 'li' );
		li.className = 'gl-invite-row';
		li.id = 'gl-invite-' + invite.id;
		li.dataset.inviteId = String( invite.id );
		li.dataset.status = invite.status;

		const body = document.createElement( 'div' );
		body.className = 'gl-invite-row__body';

		const code = document.createElement( 'p' );
		code.className = 'gl-invite-row__code';
		code.textContent = invite.code;
		body.appendChild( code );

		const urlPara = document.createElement( 'p' );
		urlPara.className = 'gl-invite-row__url';

		const link = document.createElement( 'a' );
		link.href = invite.redemption_url;
		link.textContent = invite.redemption_url;
		urlPara.appendChild( link );

		const copyButton = document.createElement( 'button' );
		copyButton.type = 'button';
		copyButton.className = 'gl-button gl-button--secondary';
		copyButton.setAttribute( 'data-gl-copy-invite', '' );
		copyButton.dataset.copyValue = invite.redemption_url;
		copyButton.textContent = wp.i18n.__( 'Copy link', 'game-library' );
		urlPara.appendChild( copyButton );

		const copiedSpan = document.createElement( 'span' );
		copiedSpan.className = 'gl-invite-row__copied';
		copiedSpan.setAttribute( 'data-gl-copy-confirmation', '' );
		copiedSpan.hidden = true;
		copiedSpan.textContent = wp.i18n.__( 'Copied!', 'game-library' );
		urlPara.appendChild( copiedSpan );

		body.appendChild( urlPara );

		if ( false === invite.mail_sent ) {
			const warning = document.createElement( 'p' );
			warning.className = 'gl-notice gl-notice--error';
			warning.setAttribute( 'role', 'alert' );
			warning.textContent = wp.i18n.__(
				'This invite could not be emailed. Share the link manually.',
				'game-library'
			);
			body.appendChild( warning );
		}

		const meta = document.createElement( 'p' );
		meta.className = 'gl-invite-row__meta';

		const statusSpan = document.createElement( 'span' );
		statusSpan.className = 'gl-invite-row__status';
		statusSpan.setAttribute( 'data-gl-invite-status', '' );
		statusSpan.textContent = statusLabel( invite.status );
		meta.appendChild( statusSpan );

		meta.appendChild( document.createTextNode( ' ' ) );
		meta.appendChild(
			document.createTextNode(
				wp.i18n.sprintf(
					/* translators: 1: created date, 2: expiry date. */
					wp.i18n.__( 'Created %1$s · Expires %2$s', 'game-library' ),
					formatDate( invite.date_created ),
					formatDate( invite.date_expires )
				)
			)
		);

		body.appendChild( meta );
		li.appendChild( body );

		const actions = document.createElement( 'div' );
		actions.className = 'gl-invite-row__actions';

		if ( 'pending' === invite.status ) {
			const revokeButton = document.createElement( 'button' );
			revokeButton.type = 'button';
			revokeButton.className = 'gl-button gl-button--danger';
			revokeButton.setAttribute( 'data-gl-revoke-invite', '' );
			revokeButton.dataset.inviteId = String( invite.id );
			revokeButton.textContent = wp.i18n.__( 'Revoke', 'game-library' );
			actions.appendChild( revokeButton );
		}

		// DES-32: .gl-error-text (was .gl-field__error) — block-agnostic
		// name; this slot keeps the `hidden`-attribute toggle unchanged
		// (handleRevokeClick() below), unlike library.js's/social.js's
		// error slots (PF-4).
		const errorSlot = document.createElement( 'p' );
		errorSlot.className = 'gl-error-text';
		errorSlot.setAttribute( 'data-gl-invite-row-error', '' );
		errorSlot.hidden = true;
		actions.appendChild( errorSlot );

		li.appendChild( actions );

		return li;
	}

	/**
	 * Handles `#gl-invite-form` submission (AC-024, AC-026, AC-028).
	 *
	 * @param {Event} event `submit` event.
	 */
	function handleSubmit( event ) {
		event.preventDefault();
		clearFormErrors();

		const submitButton = form.querySelector( 'button[type="submit"]' );
		const emailValue = emailInput ? emailInput.value.trim() : '';
		const data = {};

		// Omit the key entirely for an empty field rather than sending '' —
		// the REST route's `format => 'email'` schema validation would
		// otherwise reject an empty string as an invalid email even though an
		// absent email is valid (a link-only invite).
		if ( emailValue ) {
			data.email = emailValue;
		}

		if ( submitButton ) {
			submitButton.disabled = true;
		}

		wp.apiFetch( {
			path: '/' + restNamespace() + '/invites',
			method: 'POST',
			data,
		} )
			.then( function ( invite ) {
				if ( list ) {
					list.insertBefore(
						buildInviteRow( invite ),
						list.firstChild
					);
				}

				if ( emptyState && emptyState.parentNode ) {
					emptyState.parentNode.removeChild( emptyState );
				}

				setGeneralSuccess();
				updateQuota( invite.quota );

				if ( emailInput ) {
					emailInput.value = '';
				}

				// CO-10: cleanup duplicated into both settled paths, not
				// Promise.prototype.finally() (ES2018, above this project's
				// DD-007 ES2017 floor — see social.js's matching fix for the
				// same reason).
				if ( submitButton ) {
					submitButton.disabled = false;
				}
			} )
			.catch( function ( error ) {
				const paramsEmail =
					error && error.data && error.data.params
						? error.data.params.email
						: null;
				let emailMessage = null;

				if ( 'string' === typeof paramsEmail ) {
					emailMessage = paramsEmail;
				} else if ( paramsEmail && paramsEmail.message ) {
					emailMessage = paramsEmail.message;
				}

				if ( emailMessage ) {
					setEmailFieldError( emailMessage );
				} else if (
					error &&
					'gl_invalid_invite_email' === error.code
				) {
					setEmailFieldError( error.message );
				} else {
					setGeneralError(
						( error && error.message ) ||
							wp.i18n.__(
								'Could not create that invite. Please try again.',
								'game-library'
							)
					);
				}

				if ( submitButton ) {
					submitButton.disabled = false;
				}
			} );
	}

	/**
	 * Handles a Revoke click (delegated). Never removes the row — revoking
	 * never deletes the invite (D40) — only updates its status text and
	 * removes the Revoke control.
	 *
	 * @param {Event} event `click` event.
	 */
	function handleRevokeClick( event ) {
		const button = event.target.closest
			? event.target.closest( '[data-gl-revoke-invite]' )
			: null;

		if ( ! button ) {
			return;
		}

		const row = button.closest( '.gl-invite-row' );
		const inviteId = button.dataset.inviteId;
		const errorSlot = row
			? row.querySelector( '[data-gl-invite-row-error]' )
			: null;

		button.disabled = true;

		if ( errorSlot ) {
			errorSlot.hidden = true;
		}

		wp.apiFetch( {
			path: '/' + restNamespace() + '/invites/' + inviteId,
			method: 'DELETE',
		} )
			.then( function () {
				if ( ! row ) {
					return;
				}

				row.dataset.status = 'revoked';

				const statusEl = row.querySelector( '[data-gl-invite-status]' );

				if ( statusEl ) {
					statusEl.textContent = statusLabel( 'revoked' );
				}

				if ( button.parentNode ) {
					button.parentNode.removeChild( button );
				}
			} )
			.catch( function () {
				button.disabled = false;

				if ( errorSlot ) {
					errorSlot.textContent = wp.i18n.__(
						'Could not revoke this invite. Please try again.',
						'game-library'
					);
					errorSlot.hidden = false;
				}
			} );
	}

	/**
	 * Copies a value to the clipboard, falling back to a hidden-textarea
	 * `execCommand( 'copy' )` when the async Clipboard API is unavailable or
	 * rejects.
	 *
	 * @param {string} value Value to copy.
	 * @return {Promise} Resolves once the copy attempt has completed.
	 */
	function copyToClipboard( value ) {
		const clipboard = window.navigator.clipboard;

		if ( clipboard && clipboard.writeText ) {
			return clipboard.writeText( value ).catch( function () {
				return legacyCopy( value );
			} );
		}

		return legacyCopy( value );
	}

	/**
	 * The hidden-textarea clipboard fallback for browsers/contexts without
	 * the async Clipboard API.
	 *
	 * @param {string} value Value to copy.
	 * @return {Promise} Always resolves — this is a best-effort fallback.
	 */
	function legacyCopy( value ) {
		return new Promise( function ( resolve ) {
			const textarea = document.createElement( 'textarea' );
			textarea.value = value;
			textarea.setAttribute( 'readonly', '' );
			textarea.className = 'gl-visually-hidden';
			document.body.appendChild( textarea );
			textarea.select();

			try {
				document.execCommand( 'copy' );
			} catch ( error ) {
				// Best-effort fallback; nothing further to do on failure.
			}

			document.body.removeChild( textarea );
			resolve();
		} );
	}

	/**
	 * Handles a "Copy link" click (delegated), showing a transient "Copied!"
	 * confirmation next to the control.
	 *
	 * @param {Event} event `click` event.
	 */
	function handleCopyClick( event ) {
		const button = event.target.closest
			? event.target.closest( '[data-gl-copy-invite]' )
			: null;

		if ( ! button ) {
			return;
		}

		const value = button.dataset.copyValue;
		const confirmation = button.parentElement
			? button.parentElement.querySelector(
					'[data-gl-copy-confirmation]'
			  )
			: null;

		copyToClipboard( value ).then( function () {
			if ( ! confirmation ) {
				return;
			}

			confirmation.hidden = false;

			window.setTimeout( function () {
				confirmation.hidden = true;
			}, 2000 );
		} );
	}

	if ( form ) {
		form.addEventListener( 'submit', handleSubmit );
	}

	const main = document.getElementById( 'gl-main' );

	if ( main ) {
		main.addEventListener( 'click', function ( event ) {
			handleRevokeClick( event );
			handleCopyClick( event );
		} );
	}
} )();
