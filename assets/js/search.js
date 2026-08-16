/**
 * Game Library — debounced IGDB search + add-to-library.
 *
 * Renders exactly one of four states (loading / results / empty / unavailable)
 * and adds a chosen result to the library through the REST endpoint.
 */
( function () {
	'use strict';

	var D = window.GameLibraryData || {};
	var i18n = D.i18n || {};
	var shared = window.GameLibrary || {};
	var DEBOUNCE = 350;

	function escapeHtml( value ) {
		if ( shared.escapeHtml ) { return shared.escapeHtml( value ); }
		var div = document.createElement( 'div' );
		div.textContent = value === undefined || value === null ? '' : String( value );
		return div.innerHTML;
	}

	// Attribute-context escaping — escapes the double quote that escapeHtml (an
	// innerHTML round-trip) leaves untouched, so JSON in data-* attributes is safe.
	function escapeAttr( value ) {
		return String( value === undefined || value === null ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	var input = document.getElementById( 'gl-search-input' );
	var results = document.getElementById( 'gl-search-results' );
	var form = document.getElementById( 'gl-search-form' );
	if ( ! input || ! results ) { return; }

	var timer = null;
	// Guards against out-of-order responses: abort the previous request and drop
	// any response whose generation is no longer the latest.
	var controller = null;
	var requestId = 0;

	function loadingHtml() {
		return '<div class="gl-search-results"><div class="gl-search-loading"><span class="gl-spinner" aria-hidden="true"></span>' + escapeHtml( i18n.searching || 'Searching…' ) + '</div></div>';
	}
	function emptyHtml() {
		return '<div class="gl-search-results"><div class="gl-search-empty">' + escapeHtml( i18n.noResults || 'No games found.' ) + '</div></div>';
	}
	function unavailableHtml() {
		return '<div class="gl-search-results"><div class="gl-search-unavailable">' + escapeHtml( i18n.unavailable || 'Search is unavailable.' ) + '</div></div>';
	}

	function statusOptions() {
		var labels = D.statusLabels || {};
		var order = [ 'playing', 'finished', 'backlog', 'wishlist' ];
		var out = '<option value="" disabled selected>' + escapeHtml( i18n.chooseStatus || 'Choose a status…' ) + '</option>';
		for ( var i = 0; i < order.length; i++ ) {
			out += '<option value="' + order[ i ] + '">' + escapeHtml( labels[ order[ i ] ] || order[ i ] ) + '</option>';
		}
		return out;
	}

	function renderResults( data ) {
		if ( ! data || data.state === 'unavailable' ) {
			results.innerHTML = unavailableHtml();
			return;
		}
		if ( data.state === 'empty' || ! data.games || ! data.games.length ) {
			results.innerHTML = emptyHtml();
			return;
		}

		var html = '<div class="gl-search-results">';
		for ( var i = 0; i < data.games.length; i++ ) {
			var g = data.games[ i ];
			var cover = g.cover_url
				? '<span class="gl-search-result__cover"><img src="' + escapeAttr( g.cover_url ) + '" alt="' + escapeAttr( g.name ) + '" loading="lazy"></span>'
				: '';
			var meta = [];
			if ( g.first_release_date ) { meta.push( new Date( g.first_release_date * 1000 ).getFullYear() ); }
			if ( g.genres && g.genres.length ) { meta.push( g.genres[ 0 ] ); }
			html += '<div class="gl-search-result"' +
				' data-igdb-id="' + escapeAttr( g.igdb_id ) + '"' +
				' data-name="' + escapeAttr( g.name ) + '"' +
				' data-cover-image-id="' + escapeAttr( g.cover_image_id || '' ) + '"' +
				' data-first-release-date="' + escapeAttr( g.first_release_date || '' ) + '"' +
				' data-genres="' + escapeAttr( JSON.stringify( g.genres || [] ) ) + '"' +
				' data-platforms="' + escapeAttr( JSON.stringify( g.platforms || [] ) ) + '"' +
				' data-summary="' + escapeAttr( g.summary || '' ) + '">' +
				cover +
				'<div class="gl-search-result__body">' +
					'<span class="gl-search-result__title">' + escapeHtml( g.name ) + '</span>' +
					'<span class="gl-search-result__meta">' + escapeHtml( meta.join( ' · ' ) ) + '</span>' +
					'<span class="gl-error-text" data-gl-result-error></span>' +
				'</div>' +
				'<div class="gl-search-result__actions">' +
					'<select data-gl-add-status aria-label="' + escapeHtml( i18n.chooseStatus || 'Choose a status' ) + '">' + statusOptions() + '</select>' +
					'<button type="button" class="gl-button gl-button--primary gl-button--small" data-gl-add disabled>' + escapeHtml( i18n.add || 'Add' ) + '</button>' +
				'</div>' +
			'</div>';
		}
		html += '</div>';
		results.innerHTML = html;
	}

	function run( term ) {
		term = ( term || '' ).trim();
		// A newer search supersedes any in-flight one, so abort it and bump the
		// generation before doing anything else — including the < 2 early return.
		if ( controller ) { controller.abort(); }
		requestId += 1;
		var myId = requestId;
		if ( term.length < 2 ) { results.innerHTML = ''; return; }
		controller = ( typeof AbortController !== 'undefined' ) ? new AbortController() : null;
		results.innerHTML = loadingHtml();
		fetch( D.restRoot + 'search?term=' + encodeURIComponent( term ), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': D.nonce },
			signal: controller ? controller.signal : undefined
		} ).then( function ( r ) {
			if ( ! r.ok ) { throw new Error( 'http' ); }
			return r.json();
		} ).then( function ( data ) {
			if ( myId !== requestId ) { return; }
			renderResults( data );
		} ).catch( function ( err ) {
			if ( err && err.name === 'AbortError' ) { return; }
			if ( myId !== requestId ) { return; }
			results.innerHTML = unavailableHtml();
		} );
	}

	input.addEventListener( 'input', function () {
		clearTimeout( timer );
		timer = setTimeout( function () { run( input.value ); }, DEBOUNCE );
	} );
	if ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			clearTimeout( timer );
			run( input.value );
		} );
	}

	results.addEventListener( 'change', function ( e ) {
		var sel = e.target.closest( '[data-gl-add-status]' );
		if ( ! sel ) { return; }
		var item = sel.closest( '.gl-search-result' );
		var add = item ? item.querySelector( '[data-gl-add]' ) : null;
		if ( add ) { add.disabled = ! sel.value; }
	} );

	results.addEventListener( 'click', function ( e ) {
		var add = e.target.closest( '[data-gl-add]' );
		if ( ! add ) { return; }
		var item = add.closest( '.gl-search-result' );
		if ( ! item ) { return; }
		var sel = item.querySelector( '[data-gl-add-status]' );
		var errSlot = item.querySelector( '[data-gl-result-error]' );
		var status = sel ? sel.value : '';
		if ( ! status ) { return; }

		var payload = {
			igdb_id: Number( item.getAttribute( 'data-igdb-id' ) ),
			name: item.getAttribute( 'data-name' ) || '',
			cover_image_id: item.getAttribute( 'data-cover-image-id' ) || '',
			first_release_date: Number( item.getAttribute( 'data-first-release-date' ) ) || 0,
			genres: JSON.parse( item.getAttribute( 'data-genres' ) || '[]' ),
			platforms: JSON.parse( item.getAttribute( 'data-platforms' ) || '[]' ),
			summary: item.getAttribute( 'data-summary' ) || '',
			status: status
		};

		add.disabled = true;
		add.textContent = i18n.adding || 'Adding…';
		if ( errSlot ) { errSlot.textContent = ''; }

		shared.api( 'library', 'POST', payload ).then( function ( data ) {
			if ( data && data.duplicate ) {
				var body = item.querySelector( '.gl-search-result__body' );
				if ( body && ! body.querySelector( '.gl-notice--info' ) ) {
					body.insertAdjacentHTML( 'beforeend', '<span class="gl-notice gl-notice--info" role="status">' + escapeHtml( data.message || i18n.duplicate ) + '</span>' );
				}
				add.disabled = false;
				add.textContent = i18n.add || 'Add';
				return;
			}
			var grid = document.getElementById( 'gl-library-grid' );
			if ( grid && data && data.card_html ) {
				var empty = document.querySelector( '.gl-empty-state' );
				if ( empty && empty.parentNode ) { empty.parentNode.removeChild( empty ); }
				grid.insertAdjacentHTML( 'afterbegin', data.card_html );
			}
			add.textContent = i18n.added || 'Added';
		} ).catch( function ( err ) {
			if ( errSlot ) { errSlot.textContent = err.message; }
			add.disabled = false;
			add.textContent = i18n.add || 'Add';
		} );
	} );
}() );
