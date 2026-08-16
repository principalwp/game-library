/**
 * Game Library — shared REST transport for the three front-end bundles.
 *
 * Not a build entry: `my-library.js`, `public.js`, and `import-review.js` each
 * import it and webpack inlines it into their bundle, so the
 * `wp-scripts build <entry…>` invocation and every `wp_enqueue_script()` call
 * stay as they are.
 *
 * One definition of the plugin's client-side transport contract: the cookie
 * nonce header, `credentials: 'same-origin'`, decode-the-body-or-null, and the
 * failure envelope. It had three, and they had already drifted — the
 * import-review copy threw without `failure.payload`, so a caller there could
 * not read the server's own `state`.
 *
 * Like the entries that import it, this module authors no member-facing string
 * and imports no `@wordpress/i18n`: every sentence a caller shows comes from a
 * server fragment, a JSON `message`, or a `data-` attribute PHP wrote (ADR-002).
 */

/**
 * Read the bootstrap payload `GameLib_Assets` printed before this script.
 *
 * Module-private: the two exported callers are its only consumers, and a bundle
 * wants nothing else from this file (AR-6 — it was exported with no importer
 * outside this module, which advertised a shared surface that does not exist).
 *
 * @return {{restUrl: string, nonce: string}} REST base URL and cookie nonce.
 */
function settings() {
	const data = window.gameLibrarySettings || {};

	return {
		restUrl: typeof data.restUrl === 'string' ? data.restUrl : '',
		nonce: typeof data.nonce === 'string' ? data.nonce : '',
	};
}

/**
 * The failure an unsuccessful response should be thrown as.
 *
 * Shared by {@link request} and {@link download} so both callers read a server
 * refusal the same way: the server's own sentence as the message, and the whole
 * error body as `error.payload`.
 *
 * @param {Response} response Response that was not 2xx.
 * @return {Promise<Error>} The error to throw.
 */
async function failureFrom( response ) {
	let payload = null;

	try {
		payload = await response.json();
	} catch ( error ) {
		payload = null;
	}

	const failure = new Error(
		payload && typeof payload.message === 'string' ? payload.message : ''
	);

	failure.payload = payload;

	return failure;
}

/**
 * The filename a `Content-Disposition` header asks for.
 *
 * @param {string|null} header Raw header value.
 * @return {string} Filename, or '' when the header names none.
 */
function dispositionFilename( header ) {
	const found = /filename="([^"]+)"/.exec( header || '' );

	return found ? found[ 1 ] : '';
}

/**
 * Fetch one of the plugin's routes as a file rather than as a payload.
 *
 * The export download used to be a rendered anchor to
 * `…/export?format=csv&_wpnonce=…` (SE-1, CWE-598). That nonce is the member's
 * generic `wp_rest` token — valid for every `gamelib/v1` mutation route for
 * ~12–24h — and a URL-borne copy lands in access logs, proxy logs, and the
 * browser's own download history. Fetching the file instead puts the same token
 * in the `X-WP-Nonce` header, where none of those record it, and the caller
 * saves the response through an object URL.
 *
 * @param {string} path Route path below `gamelib/v1/`.
 * @return {Promise<{blob: Blob, filename: string}>} The file and the name the
 *                                                   server asked it be saved as.
 * @throws {Error} Carrying the server's own message, and its payload as
 *                 `error.payload`, when the response is not 2xx.
 */
export async function download( path ) {
	const { restUrl, nonce } = settings();

	const response = await window.fetch( restUrl + path, {
		method: 'GET',
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': nonce },
	} );

	if ( ! response.ok ) {
		throw await failureFrom( response );
	}

	return {
		blob: await response.blob(),
		filename: dispositionFilename(
			response.headers.get( 'Content-Disposition' )
		),
	};
}

/**
 * Call one of the plugin's REST routes with the cookie nonce.
 *
 * @param {string}          path             Route path below `gamelib/v1/`.
 * @param {Object}          options          Request options.
 * @param {string}          [options.method] HTTP method; defaults to GET.
 * @param {Object|FormData} [options.body]   JSON body, or the multipart body an
 *                                           upload travels in.
 * @param {AbortSignal}     [options.signal] Cancels the request. A caller that
 *                                           supersedes its own in-flight
 *                                           request passes one so the response
 *                                           it no longer wants stops costing
 *                                           bandwidth and cannot be applied;
 *                                           the rejection is a DOMException
 *                                           named `AbortError`, which callers
 *                                           swallow rather than announce.
 * @return {Promise<Object>} The decoded payload.
 * @throws {Error} Carrying the server's own message, and its payload as
 *                 `error.payload`, when the response is not 2xx.
 */
export async function request( path, options = {} ) {
	const { restUrl, nonce } = settings();
	const method = options.method || 'GET';
	const hasBody = undefined !== options.body;

	// An upload has to travel as multipart, and the browser is the only thing
	// that can write that Content-Type — it carries the boundary.
	const isForm = hasBody && options.body instanceof window.FormData;

	const headers = { 'X-WP-Nonce': nonce };

	if ( hasBody && ! isForm ) {
		headers[ 'Content-Type' ] = 'application/json';
	}

	let body;

	if ( hasBody ) {
		body = isForm ? options.body : JSON.stringify( options.body );
	}

	const response = await window.fetch( restUrl + path, {
		method,
		credentials: 'same-origin',
		headers,
		body,
		signal: options.signal,
	} );

	if ( ! response.ok ) {
		// The thrown error carries the whole body as `error.payload`, so a
		// caller can read the server's `state` and the degraded-state fragment
		// some routes answer with (AC-010).
		throw await failureFrom( response );
	}

	let payload = null;

	try {
		payload = await response.json();
	} catch ( error ) {
		payload = null;
	}

	return payload || {};
}
