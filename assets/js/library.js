/**
 * Game Library — front-end interactions: status edit, remove, follow,
 * visibility toggle, copy-to-clipboard, invite create/revoke, quick-look.
 *
 * All mutating requests carry the wp_rest nonce; the server re-checks it.
 * No error path is swallowed and no control is left in a loading state.
 */
( function () {
	'use strict';

	var D = window.GameLibraryData || {};
	var i18n = D.i18n || {};
	var statusLabels = D.statusLabels || {};

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value === undefined || value === null ? '' : String( value );
		return div.innerHTML;
	}

	function api( path, method, body ) {
		return fetch( D.restRoot + path, {
			method: method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': D.nonce
			},
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().catch( function () { return null; } ).then( function ( data ) {
				if ( ! res.ok ) {
					var err = new Error( ( data && data.message ) || i18n.genericError || 'Error' );
					err.data = data;
					err.status = res.status;
					throw err;
				}
				return data;
			} );
		} );
	}

	// Expose the helpers for search.js (loaded after this file).
	window.GameLibrary = { api: api, escapeHtml: escapeHtml };

	var lastFocus = null;

	document.addEventListener( 'click', function ( e ) {
		var cycle = e.target.closest( '[data-gl-status-cycle]' );
		if ( cycle ) {
			var wrap = cycle.closest( '.gl-game-card__status' );
			if ( ! wrap ) { return; }
			var badge = wrap.querySelector( '.gl-status-badge' );
			var select = wrap.querySelector( '[data-gl-status-select]' );
			if ( badge ) { badge.hidden = true; }
			cycle.hidden = true;
			if ( select ) { select.hidden = false; select.focus(); }
			return;
		}

		var rm = e.target.closest( '[data-gl-remove]' );
		if ( rm ) {
			var rcard = rm.closest( '.gl-game-card' );
			if ( ! rcard ) { return; }
			if ( ! window.confirm( i18n.removeConfirm || 'Remove?' ) ) { return; }
			var rid = rcard.getAttribute( 'data-gl-library-id' );
			var rerr = rcard.querySelector( '[data-gl-card-error]' );
			rm.disabled = true;
			api( 'library/' + rid, 'DELETE' ).then( function () {
				rcard.parentNode.removeChild( rcard );
			} ).catch( function ( err ) {
				rm.disabled = false;
				if ( rerr ) { rerr.textContent = err.message; }
			} );
			return;
		}

		var fb = e.target.closest( '[data-gl-follow]' );
		if ( fb ) {
			var uid = Number( fb.getAttribute( 'data-gl-user-id' ) );
			var active = fb.classList.contains( 'is-active' );
			fb.disabled = true;
			api( 'follow', active ? 'DELETE' : 'POST', { user_id: uid } ).then( function ( data ) {
				if ( data && data.following ) {
					fb.classList.add( 'is-active' );
					fb.textContent = i18n.following || 'Following';
					fb.setAttribute( 'aria-pressed', 'true' );
				} else {
					fb.classList.remove( 'is-active' );
					fb.textContent = i18n.follow || 'Follow';
					fb.setAttribute( 'aria-pressed', 'false' );
				}
			} ).catch( function () {} ).then( function () { fb.disabled = false; } );
			return;
		}

		var copyBtn = e.target.closest( '[data-gl-copy]' );
		if ( copyBtn ) {
			handleCopy( copyBtn );
			return;
		}

		var rev = e.target.closest( '[data-gl-revoke]' );
		if ( rev ) {
			var revId = rev.getAttribute( 'data-gl-invite-id' );
			rev.disabled = true;
			api( 'invites/' + revId + '/revoke', 'POST', {} ).then( function ( data ) {
				if ( data && data.revoked ) {
					var row = rev.closest( '.gl-invite-row' );
					var chip = row ? row.querySelector( '[data-gl-invite-status]' ) : null;
					if ( chip ) {
						chip.className = 'gl-invite-row__status gl-invite-row__status--revoked';
						chip.textContent = i18n.revoked || 'Revoked';
					}
					if ( rev.parentNode ) { rev.parentNode.removeChild( rev ); }
					updateRemaining( data.remaining );
				} else {
					rev.disabled = false;
				}
			} ).catch( function () { rev.disabled = false; } );
			return;
		}

		var ql = e.target.closest( '[data-gl-quicklook]' );
		if ( ql ) {
			openQuickLook( ql );
			return;
		}

		var qlClose = e.target.closest( '[data-gl-quicklook-close]' );
		if ( qlClose ) {
			var dlg = qlClose.closest( 'dialog' );
			if ( dlg && typeof dlg.close === 'function' ) { dlg.close(); }
			return;
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		var select = e.target.closest( '[data-gl-status-select]' );
		if ( select ) {
			var card = select.closest( '.gl-game-card' );
			var wrap = select.closest( '.gl-game-card__status' );
			var errSlot = card ? card.querySelector( '[data-gl-card-error]' ) : null;
			var id = card ? card.getAttribute( 'data-gl-library-id' ) : '';
			var status = select.value;
			select.disabled = true;
			api( 'library/' + id + '/status', 'POST', { status: status } ).then( function ( data ) {
				var badge = wrap.querySelector( '.gl-status-badge' );
				if ( badge ) {
					badge.className = 'gl-status-badge gl-status-badge--' + data.status;
					badge.textContent = statusLabels[ data.status ] || data.status;
					badge.hidden = false;
				}
				select.hidden = true;
				select.disabled = false;
				var cyc = wrap.querySelector( '[data-gl-status-cycle]' );
				if ( cyc ) { cyc.hidden = false; }
				if ( errSlot ) { errSlot.textContent = ''; }
			} ).catch( function ( err ) {
				select.disabled = false;
				if ( errSlot ) { errSlot.textContent = err.message; }
			} );
			return;
		}

		var vis = e.target.closest( '[data-gl-visibility-toggle]' );
		if ( vis ) {
			var value = vis.checked ? 'public' : 'private';
			vis.disabled = true;
			api( 'visibility', 'POST', { value: value } ).then( function ( data ) {
				var label = document.querySelector( '[data-gl-visibility-label]' );
				if ( label ) {
					label.textContent = data.visibility === 'public'
						? ( i18n.publicLabel || 'Public library' )
						: ( i18n.privateLabel || 'Private library' );
				}
			} ).catch( function () {
				vis.checked = ! vis.checked; // revert on failure
			} ).then( function () { vis.disabled = false; } );
			return;
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		var invForm = e.target.closest( '[data-gl-invite-form]' );
		if ( ! invForm ) { return; }
		e.preventDefault();
		var notices = document.getElementById( 'gl-invite-notices' );
		var btn = invForm.querySelector( '[data-gl-invite-create]' );
		if ( btn ) { btn.disabled = true; }
		api( 'invites', 'POST', {} ).then( function ( data ) {
			if ( data && data.created ) {
				var list = document.getElementById( 'gl-invite-list' );
				if ( list && data.row_html ) { list.insertAdjacentHTML( 'afterbegin', data.row_html ); }
				updateRemaining( data.remaining );
				if ( notices ) { notices.innerHTML = ''; }
			} else if ( notices ) {
				notices.innerHTML = '<div class="gl-notice gl-notice--error">' + escapeHtml( data && data.message ) + '</div>';
			}
		} ).catch( function ( err ) {
			if ( notices ) { notices.innerHTML = '<div class="gl-notice gl-notice--error">' + escapeHtml( err.message ) + '</div>'; }
		} ).then( function () { if ( btn ) { btn.disabled = false; } } );
	} );

	function handleCopy( btn ) {
		var url = btn.getAttribute( 'data-gl-url' ) || '';
		var row = btn.closest( '.gl-invite-row' );
		var copied = row ? row.querySelector( '.gl-invite-row__copied' ) : null;
		var reveal = function ( text ) {
			if ( copied ) {
				if ( text ) { copied.textContent = text; }
				copied.hidden = false;
			}
		};
		var fallback = function () {
			var link = row ? row.querySelector( '[data-gl-join-url]' ) : null;
			if ( link && window.getSelection ) {
				var range = document.createRange();
				range.selectNodeContents( link );
				var sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange( range );
			}
			reveal( i18n.copyFailed || 'Copy failed' );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( url ).then( function () { reveal( i18n.copied || 'Copied!' ); } ).catch( fallback );
		} else {
			fallback();
		}
	}

	function updateRemaining( n ) {
		if ( n === undefined || n === null ) { return; }
		var el = document.querySelector( '[data-gl-remaining]' );
		if ( el ) { el.textContent = String( n ); }
	}

	function openQuickLook( trigger ) {
		var dlg = document.getElementById( 'gl-quicklook' );
		if ( ! dlg ) { return; }
		lastFocus = trigger;
		var title = trigger.getAttribute( 'data-gl-title' ) || '';
		var cover = trigger.getAttribute( 'data-gl-cover' ) || '';
		var titleEl = dlg.querySelector( '[data-gl-quicklook-title]' );
		var coverEl = dlg.querySelector( '[data-gl-quicklook-cover]' );
		if ( titleEl ) { titleEl.textContent = title; }
		if ( coverEl ) {
			if ( cover ) { coverEl.src = cover; coverEl.alt = title; coverEl.hidden = false; } else { coverEl.removeAttribute( 'src' ); coverEl.hidden = true; }
		}
		if ( typeof dlg.showModal === 'function' ) { dlg.showModal(); } else { dlg.setAttribute( 'open', '' ); }
	}

	var quicklook = document.getElementById( 'gl-quicklook' );
	if ( quicklook ) {
		quicklook.addEventListener( 'close', function () {
			if ( lastFocus && typeof lastFocus.focus === 'function' ) { lastFocus.focus(); }
		} );
	}
}() );
