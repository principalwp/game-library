/**
 * Game Library front-end controls.
 *
 * Progressive-enhancement, dependency-free. Every mutating call carries the REST
 * cookie nonce and hits the plugin's own REST routes; the server remains the
 * enforcement boundary. No IGDB credential or token is ever read here — the only
 * secret-adjacent value present is a REST nonce.
 */
( function () {
	'use strict';

	var data = window.gameLibraryData || {};
	var REST = data.restUrl || '';
	var NONCE = data.nonce || '';
	var STATUSES = data.statuses || [ 'playing', 'finished', 'backlog', 'wishlist' ];
	var I18N = data.i18n || {};
	var PLACEHOLDER =
		'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	/**
	 * Perform a REST request against a plugin route.
	 *
	 * @param {string} route  Route path after the namespace, e.g. "search".
	 * @param {string} method HTTP method.
	 * @param {Object} body   JSON body (optional).
	 * @param {AbortSignal} [signal] Optional signal to cancel the request.
	 * @return {Promise<{ok:boolean,status:number,json:Object}>} Result.
	 */
	function apiRequest( route, method, body, signal ) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': NONCE,
			},
		};
		if ( body ) {
			opts.body = JSON.stringify( body );
		}
		if ( signal ) {
			opts.signal = signal;
		}
		return fetch( REST + route, opts ).then( function ( response ) {
			return response
				.json()
				.catch( function () {
					return {};
				} )
				.then( function ( json ) {
					return {
						ok: response.ok,
						status: response.status,
						json: json,
					};
				} );
		} );
	}

	/**
	 * Create an element with class + optional text.
	 *
	 * @param {string} tag  Tag name.
	 * @param {string} cls  Class name.
	 * @param {string} text Text content.
	 * @return {HTMLElement} The element.
	 */
	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text != null ) {
			node.textContent = text;
		}
		return node;
	}

	/* ------------------------------------------------------------- Tabs */

	function initTabs( root ) {
		var tabs = root.querySelectorAll( '[data-gl-tab]' );
		var panels = root.querySelectorAll( '[data-gl-panel]' );
		if ( ! tabs.length ) {
			return;
		}
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				var target = tab.getAttribute( 'data-gl-tab' );
				tabs.forEach( function ( t ) {
					var active = t === tab;
					t.classList.toggle( 'is-active', active );
					t.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				} );
				panels.forEach( function ( panel ) {
					var show = panel.getAttribute( 'data-gl-panel' ) === target;
					panel.hidden = ! show;
					panel.classList.toggle( 'is-active', show );
				} );
			} );
		} );
	}

	/* ----------------------------------------------------------- Search */

	function initSearch( root ) {
		var form = root.querySelector( '[data-role="search-form"]' );
		if ( ! form ) {
			return;
		}
		var input = root.querySelector( '[data-role="search-input"]' );
		var status = root.querySelector( '[data-role="search-status"]' );
		var results = root.querySelector( '[data-role="search-results"]' );
		var inFlight = null;

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var q = ( input.value || '' ).trim();
			if ( ! q ) {
				return;
			}

			// Cancel any earlier request still in flight so a slow older query
			// can never overwrite the results of a newer one.
			if ( inFlight ) {
				inFlight.abort();
			}
			var controller =
				typeof AbortController !== 'undefined' ? new AbortController() : null;
			inFlight = controller;
			var signal = controller ? controller.signal : undefined;

			results.innerHTML = '';
			status.className = 'gl-search__status';
			status.textContent = I18N.searching || 'Searching…';

			apiRequest( 'search', 'POST', { q: q }, signal )
				.then( function ( res ) {
					if ( inFlight === controller ) {
						inFlight = null;
					}
					if ( ! res.ok ) {
						// AC-017: an integration failure is a distinct error state,
						// never a silent empty list.
						status.className = 'gl-search__status is-error';
						status.textContent =
							I18N.searchUnavailable || 'Search is unavailable right now.';
						return;
					}
					var list = ( res.json && res.json.results ) || [];
					if ( ! list.length ) {
						// AC-018: distinct "no games found" empty state.
						status.textContent = I18N.noGamesFound || 'No games found.';
						return;
					}
					status.textContent = '';
					renderResults( results, list );
				} )
				.catch( function ( err ) {
					// A superseded request was aborted on purpose — ignore it and
					// leave the newer request's state untouched.
					if ( err && err.name === 'AbortError' ) {
						return;
					}
					if ( inFlight === controller ) {
						inFlight = null;
					}
					status.className = 'gl-search__status is-error';
					status.textContent =
						I18N.searchUnavailable || 'Search is unavailable right now.';
				} );
		} );
	}

	function renderResults( container, list ) {
		container.innerHTML = '';
		list.forEach( function ( game ) {
			var row = el( 'div', 'gl-search-result' );
			row.setAttribute( 'data-igdb-id', String( game.igdb_id ) );

			var thumb = el( 'img', 'gl-search-result__thumb' );
			thumb.src = game.cover_url || PLACEHOLDER;
			thumb.alt = game.name || '';
			thumb.loading = 'lazy';
			row.appendChild( thumb );

			row.appendChild( el( 'span', 'gl-search-result__title', game.name || '' ) );

			var actions = el( 'div', 'gl-search-result__actions' );
			var select = el( 'select', 'gl-status-control' );
			STATUSES.forEach( function ( s ) {
				var opt = el( 'option', '', label( s ) );
				opt.value = s;
				select.appendChild( opt );
			} );
			actions.appendChild( select );

			var addBtn = el( 'button', 'gl-button gl-button--primary', I18N.add || 'Add' );
			addBtn.type = 'button';
			addBtn.addEventListener( 'click', function () {
				addBtn.disabled = true;
				apiRequest( 'library', 'POST', {
					igdb_id: game.igdb_id,
					status: select.value,
					game_name: game.name,
					cover_url: game.cover_url,
				} ).then( function ( res ) {
					if ( res.ok ) {
						addBtn.textContent = I18N.added || 'Added';
						row.classList.add( 'is-added' );
					} else {
						addBtn.disabled = false;
						addBtn.textContent =
							( res.json && res.json.message ) || I18N.genericError || 'Error';
					}
				} );
			} );
			actions.appendChild( addBtn );

			row.appendChild( actions );
			container.appendChild( row );
		} );
	}

	function label( slug ) {
		return slug.charAt( 0 ).toUpperCase() + slug.slice( 1 );
	}

	/**
	 * Surface an error message in a polite live region inside the given scope.
	 *
	 * Reuses an existing [data-role="gl-error"] node or creates one, so a failed
	 * mutation (bad response or network error) is announced instead of being
	 * swallowed into a silent no-op.
	 *
	 * @param {HTMLElement} scope Container to announce within.
	 * @param {string}      msg   Message text.
	 */
	function announceError( scope, msg ) {
		if ( ! scope ) {
			return;
		}
		var node = scope.querySelector( '[data-role="gl-error"]' );
		if ( ! node ) {
			node = el( 'div', 'gl-error' );
			node.setAttribute( 'data-role', 'gl-error' );
			node.setAttribute( 'role', 'status' );
			node.setAttribute( 'aria-live', 'polite' );
			scope.appendChild( node );
		}
		node.textContent = msg || I18N.genericError || 'Error';
	}

	/* --------------------------------------------------- Card controls */

	function initCards( root ) {
		root.addEventListener( 'change', function ( event ) {
			var control = event.target.closest( '[data-role="status-control"]' );
			if ( ! control ) {
				return;
			}
			var card = control.closest( '.gl-card' );
			var id = card && card.getAttribute( 'data-entry-id' );
			if ( ! id ) {
				return;
			}
			var badge = card.querySelector( '[data-role="status-badge"]' );
			// The badge only ever reflects a server-confirmed status, so it is the
			// value to revert the <select> to if the change fails to persist.
			var confirmed = '';
			if ( badge ) {
				var match = badge.className.match( /gl-badge--(\S+)/ );
				if ( match ) {
					confirmed = match[ 1 ];
				}
			}
			card.classList.add( 'gl-is-loading' );
			apiRequest( 'library/' + encodeURIComponent( id ), 'POST', {
				status: control.value,
			} )
				.then( function ( res ) {
					card.classList.remove( 'gl-is-loading' );
					if ( res.ok ) {
						if ( badge ) {
							badge.textContent = label( control.value );
							badge.className = 'gl-badge gl-badge--' + control.value;
							badge.setAttribute( 'data-role', 'status-badge' );
						}
					} else {
						if ( confirmed ) {
							control.value = confirmed;
						}
						announceError( card, ( res.json && res.json.message ) || I18N.genericError );
					}
				} )
				.catch( function () {
					card.classList.remove( 'gl-is-loading' );
					if ( confirmed ) {
						control.value = confirmed;
					}
					announceError( card, I18N.genericError );
				} );
		} );

		root.addEventListener( 'click', function ( event ) {
			var remove = event.target.closest( '[data-role="remove-entry"]' );
			if ( ! remove ) {
				return;
			}
			var card = remove.closest( '.gl-card' );
			var id = card && card.getAttribute( 'data-entry-id' );
			if ( ! id ) {
				return;
			}
			if ( ! window.confirm( I18N.confirmRemove || 'Remove this game?' ) ) {
				return;
			}
			card.classList.add( 'gl-is-loading' );
			apiRequest( 'library/' + encodeURIComponent( id ), 'DELETE', null )
				.then( function ( res ) {
					if ( res.ok ) {
						card.parentNode.removeChild( card );
					} else {
						card.classList.remove( 'gl-is-loading' );
						announceError( card, ( res.json && res.json.message ) || I18N.genericError );
					}
				} )
				.catch( function () {
					card.classList.remove( 'gl-is-loading' );
					announceError( card, I18N.genericError );
				} );
		} );
	}

	/* --------------------------------------------------- Follow toggle */

	function initFollow( root ) {
		root.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-role="follow-toggle"]' );
			if ( ! button ) {
				return;
			}
			var followeeId = parseInt( button.getAttribute( 'data-followee-id' ), 10 );
			var following = button.getAttribute( 'data-following' ) === '1';
			var route = following ? 'unfollow' : 'follow';
			button.disabled = true;
			apiRequest( route, 'POST', { followee_id: followeeId } )
				.then( function ( res ) {
					button.disabled = false;
					if ( ! res.ok ) {
						announceError(
							button.parentNode,
							( res.json && res.json.message ) || I18N.genericError
						);
						return;
					}
					following = ! following;
					button.setAttribute( 'data-following', following ? '1' : '0' );
					button.classList.toggle( 'is-following', following );
					button.textContent = following
						? I18N.unfollow || 'Unfollow'
						: I18N.follow || 'Follow';
				} )
				.catch( function () {
					button.disabled = false;
					announceError( button.parentNode, I18N.genericError );
				} );
		} );
	}

	/* -------------------------------------------------- Invite generate */

	function initInvites( root ) {
		var button = root.querySelector( '[data-role="generate-invite"]' );
		var result = root.querySelector( '[data-role="invite-result"]' );
		if ( ! button || ! result ) {
			return;
		}
		button.addEventListener( 'click', function () {
			button.disabled = true;
			apiRequest( 'invite', 'POST', {} )
				.then( function ( res ) {
					if ( res.ok && res.json && res.json.link ) {
						result.textContent = res.json.link;
					} else {
						result.textContent =
							( res.json && res.json.message ) || I18N.genericError || 'Error';
						button.disabled = false;
					}
				} )
				.catch( function () {
					result.textContent = I18N.genericError || 'Error';
					button.disabled = false;
				} );
		} );
	}

	/* -------------------------------------------------------------- Init */

	document.addEventListener( 'DOMContentLoaded', function () {
		var root = document.querySelector( '.gl-app' );
		if ( ! root || ! REST ) {
			return;
		}
		initTabs( root );
		initSearch( root );
		initCards( root );
		initFollow( root );
		initInvites( root );
	} );
} )();
