/* Game Collector front-end. */
( function () {
	'use strict';

	if ( typeof gcConfig === 'undefined' ) {
		return;
	}

	var i18n = gcConfig.i18n;

	function gcFetch( path, options ) {
		options = options || {};
		options.headers = Object.assign(
			{ 'X-WP-Nonce': gcConfig.nonce, 'Content-Type': 'application/json' },
			options.headers || {}
		);
		options.credentials = 'same-origin';

		return fetch( gcConfig.restUrl + path, options ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					var message = ( data && data.message ) ? data.message : i18n.error;
					throw new Error( message );
				}
				return data;
			} );
		} );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	/* ---- Search panel (own library) ---- */

	var searchPanel = document.getElementById( 'gc-search-panel' );
	var searchInput = document.getElementById( 'gc-search-input' );
	var searchResults = document.getElementById( 'gc-search-results' );
	var openBtn = document.getElementById( 'gc-open-search' );
	var closeBtn = document.getElementById( 'gc-close-search' );
	var searchTimer = null;
	var lastQuery = '';

	if ( openBtn && searchPanel ) {
		openBtn.addEventListener( 'click', function () {
			searchPanel.hidden = false;
			searchInput.focus();
		} );
	}

	if ( closeBtn && searchPanel ) {
		closeBtn.addEventListener( 'click', function () {
			searchPanel.hidden = true;
		} );
	}

	if ( searchInput ) {
		searchInput.addEventListener( 'input', function () {
			var query = searchInput.value.trim();

			clearTimeout( searchTimer );

			if ( query.length < 2 ) {
				searchResults.innerHTML = '';
				return;
			}

			searchTimer = setTimeout( function () {
				runSearch( query );
			}, 350 );
		} );
	}

	function runSearch( query ) {
		lastQuery = query;
		searchResults.innerHTML = '';
		searchResults.appendChild( el( 'p', 'gc-search-hint', i18n.searching ) );

		gcFetch( 'search?q=' + encodeURIComponent( query ) )
			.then( function ( data ) {
				if ( query !== lastQuery ) {
					return; // A newer search superseded this one.
				}
				renderResults( data.results || [] );
			} )
			.catch( function ( err ) {
				if ( query !== lastQuery ) {
					return;
				}
				searchResults.innerHTML = '';
				searchResults.appendChild( el( 'p', 'gc-search-hint gc-error', err.message ) );
			} );
	}

	function renderResults( results ) {
		searchResults.innerHTML = '';

		if ( ! results.length ) {
			searchResults.appendChild( el( 'p', 'gc-search-hint', i18n.noResults ) );
			return;
		}

		var list = el( 'ul', 'gc-result-list' );

		results.forEach( function ( game ) {
			var item = el( 'li', 'gc-result' );

			var coverWrap = el( 'div', 'gc-result-cover' );
			if ( game.cover_url ) {
				var img = document.createElement( 'img' );
				img.src = game.cover_url;
				img.alt = '';
				img.loading = 'lazy';
				coverWrap.appendChild( img );
			}
			item.appendChild( coverWrap );

			var body = el( 'div', 'gc-result-body' );
			body.appendChild( el( 'strong', 'gc-result-title', game.name ) );
			var metaParts = [];
			if ( game.release_year ) {
				metaParts.push( game.release_year );
			}
			if ( game.platforms ) {
				metaParts.push( game.platforms );
			}
			body.appendChild( el( 'span', 'gc-result-meta', metaParts.join( ' · ' ) ) );
			item.appendChild( body );

			var actions = el( 'div', 'gc-result-actions' );

			if ( game.in_library ) {
				actions.appendChild( el( 'span', 'gc-result-owned', i18n.inLibrary ) );
			} else {
				var select = document.createElement( 'select' );
				select.className = 'gc-status-select';
				Object.keys( gcConfig.statuses ).forEach( function ( key ) {
					var option = document.createElement( 'option' );
					option.value = key;
					option.textContent = gcConfig.statuses[ key ];
					select.appendChild( option );
				} );
				select.value = 'backlog';

				var addBtn = el( 'button', 'gc-btn gc-btn-primary gc-btn-small', i18n.add );
				addBtn.type = 'button';
				addBtn.addEventListener( 'click', function () {
					addBtn.disabled = true;
					gcFetch( 'library', {
						method: 'POST',
						body: JSON.stringify( { igdb_id: game.igdb_id, status: select.value } ),
					} )
						.then( function () {
							actions.innerHTML = '';
							actions.appendChild( el( 'span', 'gc-result-owned', i18n.added ) );
						} )
						.catch( function ( err ) {
							addBtn.disabled = false;
							window.alert( err.message );
						} );
				} );

				actions.appendChild( select );
				actions.appendChild( addBtn );
			}

			item.appendChild( actions );
			list.appendChild( item );
		} );

		searchResults.appendChild( list );
	}

	/* ---- Library cards: status change + remove ---- */

	document.querySelectorAll( '.gc-card' ).forEach( function ( card ) {
		var gameId = card.getAttribute( 'data-game-id' );
		var select = card.querySelector( '.gc-status-select' );
		var removeBtn = card.querySelector( '.gc-remove-btn' );

		if ( select ) {
			select.addEventListener( 'change', function () {
				var previous = select.getAttribute( 'data-prev' ) || select.value;
				select.disabled = true;

				gcFetch( 'library/' + gameId, {
					method: 'PUT',
					body: JSON.stringify( { status: select.value } ),
				} )
					.then( function () {
						select.setAttribute( 'data-prev', select.value );
						select.disabled = false;
					} )
					.catch( function ( err ) {
						select.value = previous;
						select.disabled = false;
						window.alert( err.message );
					} );
			} );
			select.setAttribute( 'data-prev', select.value );
		}

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( i18n.confirmRemove ) ) {
					return;
				}
				gcFetch( 'library/' + gameId, { method: 'DELETE' } )
					.then( function () {
						card.remove();
					} )
					.catch( function ( err ) {
						window.alert( err.message );
					} );
			} );
		}
	} );

	/* ---- Follow / unfollow ---- */

	document.querySelectorAll( '.gc-follow-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var userId = btn.getAttribute( 'data-user-id' );
			var following = btn.getAttribute( 'data-following' ) === '1';

			btn.disabled = true;

			gcFetch( 'follows/' + userId, { method: following ? 'DELETE' : 'POST' } )
				.then( function ( data ) {
					var nowFollowing = !! data.following;
					btn.setAttribute( 'data-following', nowFollowing ? '1' : '0' );
					btn.textContent = nowFollowing ? i18n.unfollow : i18n.follow;
					btn.classList.toggle( 'gc-btn-primary', ! nowFollowing );
					btn.classList.toggle( 'gc-btn-secondary', nowFollowing );
					btn.disabled = false;
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					window.alert( err.message );
				} );
		} );
	} );

	/* ---- Activity feed: load more ---- */

	var feed = document.getElementById( 'gc-feed' );
	var loadMoreBtn = document.getElementById( 'gc-load-more' );

	if ( feed && loadMoreBtn ) {
		loadMoreBtn.addEventListener( 'click', function () {
			var nextPage = parseInt( feed.getAttribute( 'data-page' ), 10 ) + 1;

			loadMoreBtn.disabled = true;

			gcFetch( 'activity?page=' + nextPage )
				.then( function ( data ) {
					var items = data.items || [];

					items.forEach( function ( item ) {
						var li = el( 'li', 'gc-feed-item' );

						var body = el( 'div', 'gc-feed-body' );
						var text = el( 'p', 'gc-feed-text' );
						text.innerHTML = item.html; // Server-side escaped.
						body.appendChild( text );
						body.appendChild( el( 'time', 'gc-feed-time', item.time_ago ) );
						li.appendChild( body );

						if ( item.cover_url ) {
							var coverWrap = el( 'div', 'gc-feed-cover' );
							var img = document.createElement( 'img' );
							img.src = item.cover_url;
							img.alt = '';
							img.loading = 'lazy';
							coverWrap.appendChild( img );
							li.appendChild( coverWrap );
						}

						feed.appendChild( li );
					} );

					feed.setAttribute( 'data-page', String( nextPage ) );
					loadMoreBtn.disabled = false;

					if ( items.length < 30 ) {
						loadMoreBtn.parentElement.remove();
					}
				} )
				.catch( function () {
					loadMoreBtn.disabled = false;
				} );
		} );
	}
} )();
