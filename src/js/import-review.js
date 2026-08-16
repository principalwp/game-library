/**
 * Game Library — `/my-library/imports/{id}/` entry point.
 *
 * Compiles to `build/import-review.js`, enqueued only on the import-review
 * route. The base layer it renders against is a separate entry (`style.js`),
 * enqueued once for the whole plugin (PF-2).
 *
 * The screen is server-rendered first (`templates/import-review.php`) and this
 * bundle only ever does four things to it: poll `GET /imports/{id}` while the
 * job is still working, POST a decision, swap in the HTML fragment the server
 * answered with, and move focus / announce what changed. No markup and no
 * member-facing sentence is composed here — every string this file shows comes
 * from the server, either in a fragment or in a JSON `message`/`notice` field
 * (ADR-002). That is also why there is no `@wordpress/i18n` import: there are
 * no client-side strings to translate.
 *
 * The REST transport comes from `shared/rest.js`, shared with the other two
 * entries. This screen used to carry its own copy, which threw a bare Error —
 * so a failed decision here could read the server's sentence but not its
 * `state`, unlike everywhere else in the plugin.
 *
 * **Only a panel write may supersede a panel write.** Every path here writes
 * through one rule about which response may touch the DOM, and there are two
 * counters because there are two kinds of write (CO-1). The poll, a decision,
 * Finish/Retry and the re-read all rewrite the whole panel, and `seq` names the
 * most recently dispatched of them; a manual search replaces one row's candidate
 * list and nothing else, so it takes its tokens from `rowSeq` and cannot discard
 * a decision the server has already committed. A response whose token is no
 * longer current is dropped.
 *
 * A write additionally aborts whatever poll is in flight; a poll never aborts
 * anything, and a write carries no signal at all (CO-3) — there is exactly one
 * controller, and it belongs to the poll. With a shared controller a decision
 * dispatched while an earlier decision was still out cancelled its sibling —
 * whose POST had already committed server-side — so the surviving panel could
 * show the aborted row in its pre-decision bucket, and the aborted row's control
 * stayed disabled forever.
 *
 * **A superseding panel write that fails owes the panel the repaint its sibling's
 * discarded response will never make** — and that obligation is carried by
 * *every* panel writer rather than by one of them (CO-1). The panel writers are
 * exactly the callers of `claimWrite()`, and there are exactly two: `decide()`
 * and `advance()`. `grep -n 'claimWrite()' src/js/import-review.js` is the whole
 * enumeration — `claimRowWrite()` covers the one row write and `claimPoll()` the
 * two reads — so this class is closed by counting rather than by narrowing, and a
 * third panel writer would have to add a third line to that grep. Both existing
 * ones capture `overlapped` before dispatching and re-read the job from their
 * `catch` when they superseded a sibling and then failed.
 *
 * `overlapped` counts *panel* writes only (CO-4). A manual row search cannot be
 * discarded by a decision — it is arbitrated on its own row's counter — so
 * counting one as a sibling in need of reconciliation forced a whole-job repaint
 * for nothing, and the repaint destroyed the member's typed query and the
 * candidates they were about to pick from. `writesInFlight` still counts every
 * write, because the poll's guard asks "is anything writing", not "which writes
 * may supersede which".
 *
 * **A decision moves one row** (PF-3). The choose/skip routes answer with the
 * moved row, the counts region and the per-bucket totals beside the whole job,
 * so the ordinary case is ~10 DOM operations rather than a ~4,000-node
 * reconstruction of the entire panel — which also leaves every other row's
 * half-typed manual search and the page's scroll position where the member put
 * them. Anything the server cannot express as a move (a bucket that would empty,
 * a finished job, a retry) falls back to the whole-fragment `swap()`.
 *
 * **The poll is cheap by design** (PF-4). It round-trips a `fingerprint` of the
 * fragment on screen — seeded from the first paint — and the server answers
 * without `html` when nothing moved, so an unchanged tick neither re-sends
 * ~25–35KB nor rebuilds thousands of nodes under a member's cursor. A hidden
 * tab does not poll at all, and the interval backs off toward a 15s ceiling
 * while nothing changes. `visibilitychange` re-arms it — never
 * `unload`/`beforeunload`, either of which would cost the page its bfcache
 * eligibility.
 */

// No `main.scss` import here: the base layer is its own entry, `style.js`,
// enqueued once under a single handle on every plugin surface (PF-2).
import { request } from './shared/rest';

/**
 * Milliseconds between polls of a queued or processing job.
 *
 * Inside the 2–5s band the task leaves to judgment: fast enough that a short
 * import feels live, slow enough that a ten-minute one costs ~200 requests.
 *
 * @type {number}
 */
const POLL_INTERVAL_MS = 3000;

/**
 * Ceiling the poll interval backs off to while nothing changes.
 *
 * A long import spends most of its life between ticks, and a poll that answers
 * "identical" is pure cost. The interval grows toward this and snaps back to
 * {@link POLL_INTERVAL_MS} the moment the server sends a fragment.
 *
 * @type {number}
 */
const POLL_MAX_INTERVAL_MS = 15000;

/** @type {number} How much a no-change poll lengthens the interval. */
const POLL_BACKOFF_FACTOR = 1.5;

/** @type {string} Id of the swappable panel the server rendered into. */
const PANEL_ID = 'gamelib-import';

/**
 * Is this rejection the caller's own abort rather than a failure?
 *
 * An aborted request is a request whose answer is no longer wanted — announcing
 * it would tell the member something went wrong when nothing did.
 *
 * @param {*} error Rejection value.
 * @return {boolean} True for an AbortError.
 */
function isAbort( error ) {
	return !! error && 'AbortError' === error.name;
}

/**
 * Wire the review screen up.
 *
 * @return {void}
 */
function start() {
	const panel = document.getElementById( PANEL_ID );

	if ( ! panel ) {
		return;
	}

	const importId = parseInt( panel.dataset.gamelibImportId || '0', 10 );

	if ( ! importId ) {
		return;
	}

	const live = document.querySelector( '[data-gamelib-live]' );

	// The one sentence this bundle may need that no response carries: a request
	// that never reached the server has no server message. It is authored in
	// PHP and read off the panel, so it is translated with everything else.
	const offlineMessage = panel.dataset.gamelibError || '';

	let pollTimer = 0;
	let lastAnnounced = '';
	let pollInterval = POLL_INTERVAL_MS;

	/*
	 * Two sequence counters, and one AbortController for the poll — the only
	 * cancellable thing here (CO-1, CO-3).
	 *
	 * The invariant is "only a panel write may supersede a panel write".
	 * poll(), decide(), advance() and refetch() all rewrite the *panel*, so
	 * which of their responses may touch the DOM is a single question with a
	 * single answer — the most recently dispatched of them — and `seq` is that
	 * answer. searchRow() is not one of them: its only write is one row's
	 * candidate list, so it can never repaint a panel whose decision response it
	 * just invalidated, and it takes its tokens from `rowSeq` instead. Sharing
	 * one counter meant a manual search silently discarded the response of a
	 * decision the server had already committed, leaving that row under its old
	 * bucket heading with pre-decision counts and no path back — a job in review
	 * reports `data-gamelib-import-active="false"`, so the poller never re-arms.
	 *
	 * `rowSeq` is keyed by item id, not one counter for every row (CO-3). The
	 * scope of a guard is the scope of the write it arbitrates, and a row
	 * search's response can only ever write `[data-gamelib-candidates="{id}"]`
	 * — so a search on row B may not discard a search on row A, whose results
	 * it could not have rendered. With one shared counter it did: two not-found
	 * rows searched inside one round trip left the first row's candidate list
	 * unchanged, nothing announced, and its control re-enabled, which reads as
	 * "the Search button did nothing".
	 *
	 * A write aborts the poll, because a poll's answer describes the job as it
	 * was before the write. A write is never aborted by anything: its POST has
	 * already committed server-side by the time a response could be cancelled,
	 * so discarding it would leave the panel describing a decision the database
	 * has already taken. The poll controller is re-created and never nulled, so
	 * `pollController.signal` is always a live signal.
	 *
	 * Two counts of writes in flight, because two different questions are asked
	 * of them (CO-4): `writesInFlight` is every write and answers the poll's
	 * "is anything writing"; `panelWritesInFlight` is panel writes alone and
	 * answers "did this write supersede a sibling that will need reconciling".
	 * A row search increments only the first — it supersedes nothing on the
	 * panel — so a decision taken alongside one no longer repaints the whole
	 * job and throws the member's search away.
	 */
	let seq = 0;
	const rowSeq = new Map();
	let pollController = new window.AbortController();
	let writesInFlight = 0;
	let panelWritesInFlight = 0;

	/*
	 * The digest of the fragment currently on screen, round-tripped so an
	 * unchanged job answers without re-sending it (PF-4). Seeded from the first
	 * paint, so even the very first poll can be a no-op.
	 */
	let fingerprint = '';

	/**
	 * Say something in the polite live region.
	 *
	 * @param {string} message Sentence to announce.
	 * @return {void}
	 */
	const announce = ( message ) => {
		if ( ! live || ! message || message === lastAnnounced ) {
			return;
		}

		lastAnnounced = message;
		// Emptying first re-triggers the announcement when a member repeats an
		// action whose outcome sentence is unchanged.
		live.textContent = '';
		live.textContent = message;
	};

	/**
	 * Is the rendered job still queued or processing (AC-043b)?
	 *
	 * Read from the fragment rather than remembered, so the answer always
	 * describes the markup currently on screen.
	 *
	 * @return {boolean} True while background work is outstanding.
	 */
	const isActive = () => {
		const root = panel.querySelector( '[data-gamelib-import-active]' );

		return !! root && 'true' === root.dataset.gamelibImportActive;
	};

	/**
	 * Take the next sequence token for a write, aborting any poll in flight.
	 *
	 * The write itself carries no signal: nothing may cancel a request whose
	 * server-side effect has already happened (CO-3). Two writes in flight at
	 * once are arbitrated by the sequence token alone, which drops the older
	 * response without cancelling it.
	 *
	 * @return {{token: number}} Token.
	 */
	const claimWrite = () => {
		pollController.abort();
		pollController = new window.AbortController();
		seq += 1;
		writesInFlight += 1;
		panelWritesInFlight += 1;

		return { token: seq };
	};

	/**
	 * Take the next token for a write that touches one row and nothing else
	 * (CO-1, CO-3).
	 *
	 * Same treatment of the poll as `claimWrite()` — the poll's answer is stale
	 * either way — and it counts toward `writesInFlight`, which the poll guards
	 * on. It deliberately does *not* count toward `panelWritesInFlight` (CO-4):
	 * a candidate list landing in one row is not a reason to discard a decision
	 * the server has already committed to another, and it is not a sibling a
	 * failing decision owes a repaint to either.
	 *
	 * The token is per row, so a search on one row cannot supersede a search on
	 * a different one (CO-3).
	 *
	 * @param {number} itemId Row the write belongs to.
	 * @return {{token: number}} Token, valid against `currentRow( itemId, … )`.
	 */
	const claimRowWrite = ( itemId ) => {
		pollController.abort();
		pollController = new window.AbortController();
		writesInFlight += 1;
		rowSeq.set( itemId, ( rowSeq.get( itemId ) || 0 ) + 1 );

		return { token: rowSeq.get( itemId ) };
	};

	/**
	 * Take the next sequence token for a poll, and the signal the next write
	 * will abort it with.
	 *
	 * @return {{token: number, signal: AbortSignal}} Token and abort signal.
	 */
	const claimPoll = () => {
		seq += 1;

		return { token: seq, signal: pollController.signal };
	};

	/**
	 * Is this token still the most recently dispatched request?
	 *
	 * @param {number} token Token taken by `claimWrite()` or `claimPoll()`.
	 * @return {boolean} True when its response may touch the DOM.
	 */
	const current = ( token ) => token === seq;

	/**
	 * Is this token still the most recently dispatched write *to this row*
	 * (CO-1, CO-3)?
	 *
	 * @param {number} itemId Row the write belonged to.
	 * @param {number} token  Token taken by `claimRowWrite()`.
	 * @return {boolean} True when its response may touch its row.
	 */
	const currentRow = ( itemId, token ) => token === rowSeq.get( itemId );

	/**
	 * Read the digest of whatever fragment is on screen now.
	 *
	 * @return {string} Digest, or '' when the panel carries none.
	 */
	const renderedFingerprint = () => {
		const root = panel.querySelector( '[data-gamelib-fingerprint]' );

		return root ? root.dataset.gamelibFingerprint || '' : '';
	};

	/**
	 * Poll `GET /imports/{id}` once and re-arm if work remains.
	 *
	 * @return {Promise<void>}
	 */
	const poll = async () => {
		// A hidden tab polls nothing; `visibilitychange` re-arms the loop when
		// the member comes back. Never `unload`/`beforeunload` — either would
		// make the page ineligible for the back/forward cache.
		if ( 'visible' !== document.visibilityState ) {
			return;
		}

		// A decision is in flight and will re-render the whole job itself.
		if ( writesInFlight > 0 ) {
			schedule();

			return;
		}

		const { token, signal } = claimPoll();
		const query = fingerprint
			? `?fingerprint=${ encodeURIComponent( fingerprint ) }`
			: '';

		let job;

		try {
			job = await request( `imports/${ importId }${ query }`, {
				signal,
			} );
		} catch ( error ) {
			if ( isAbort( error ) ) {
				return;
			}

			// A failed poll stops the loop rather than hammering: the member
			// still has Retry and a reload.
			announce( error.message || offlineMessage );

			return;
		}

		if ( ! current( token ) ) {
			return;
		}

		const changed = swap( job );

		// Nothing moved: lengthen the interval toward the ceiling. Anything at
		// all moved: back to the responsive interval.
		pollInterval = changed
			? POLL_INTERVAL_MS
			: Math.min(
					POLL_MAX_INTERVAL_MS,
					Math.round( pollInterval * POLL_BACKOFF_FACTOR )
			  );

		announce( job.message || '' );
		schedule();
	};

	/**
	 * Arm the next poll, but only while the job is still working.
	 *
	 * @return {void}
	 */
	const schedule = () => {
		window.clearTimeout( pollTimer );

		if ( isActive() ) {
			pollTimer = window.setTimeout( poll, pollInterval );
		}
	};

	/**
	 * Replace the panel with the server's freshly rendered fragment.
	 *
	 * A response with no `html` is the server saying "identical to what you
	 * already have" (PF-4): the panel is left alone, which is also what keeps a
	 * member's half-typed manual search and their focus where they put them.
	 *
	 * @param {Object} job Response payload carrying `html`.
	 * @return {boolean} True when the panel was rewritten.
	 */
	const swap = ( job ) => {
		if ( job && 'string' === typeof job.fingerprint ) {
			fingerprint = job.fingerprint;
		}

		if ( ! job || 'string' !== typeof job.html || ! job.html ) {
			return false;
		}

		panel.innerHTML = job.html;

		if ( ! fingerprint ) {
			fingerprint = renderedFingerprint();
		}

		return true;
	};

	/**
	 * Apply a decision as a move of one row, rather than as a whole-panel
	 * rebuild (PF-3).
	 *
	 * Refuses — leaving the panel untouched for the whole-fragment swap to
	 * handle — in every case a move cannot express: the server sent no row
	 * fragment, the destination bucket has no section on screen yet, or the
	 * source bucket would be emptied (its heading and section have to go with
	 * it).
	 *
	 * @param {Object} job    Response payload from a choose/skip route.
	 * @param {number} itemId Row the decision was about.
	 * @return {boolean} True when the panel was updated.
	 */
	const moveRow = ( job, itemId ) => {
		if (
			! job ||
			'string' !== typeof job.row ||
			! job.row ||
			! job.bucket
		) {
			return false;
		}

		if ( 'string' !== typeof job.counts_html ) {
			return false;
		}

		const row = panel.querySelector(
			`[data-gamelib-item-id="${ itemId }"]`
		);
		const source = row ? row.closest( '[data-gamelib-rows]' ) : null;
		const target = panel.querySelector(
			`[data-gamelib-bucket="${ job.bucket }"] [data-gamelib-rows]`
		);
		const counts = panel.querySelector( '[data-gamelib-counts]' );

		if ( ! row || ! source || ! target || ! counts ) {
			return false;
		}

		// The last row of a bucket takes its section's heading with it, which is
		// a change to the panel's structure rather than to its contents.
		if ( source !== target && source.children.length < 2 ) {
			return false;
		}

		row.remove();
		target.insertAdjacentHTML( 'beforeend', job.row );

		counts.outerHTML = job.counts_html;

		// Server-formatted numbers written into elements PHP rendered — the
		// locale's own grouping, not a client-side String().
		const totals = job.bucket_counts || {};

		Object.keys( totals ).forEach( ( bucket ) => {
			const label = panel.querySelector(
				`[data-gamelib-bucket="${ bucket }"] [data-gamelib-bucket-count]`
			);

			if ( label ) {
				label.textContent = totals[ bucket ];
			}
		} );

		const status = panel.querySelector( '.gamelib-import__status' );

		if ( status && 'string' === typeof job.message ) {
			status.textContent = job.message;
		}

		if ( 'string' === typeof job.fingerprint ) {
			fingerprint = job.fingerprint;
		}

		return true;
	};

	/**
	 * Re-read the whole job and repaint (PB-2).
	 *
	 * The safety net for the one thing neither side can predict: a decision the
	 * server judged expressible as a move, applied against markup that has since
	 * stopped matching — a row that is no longer in the panel, a counts region
	 * that is not there. `GET /imports/{id}` is asked without a fingerprint, so
	 * it always answers with the fragment.
	 *
	 * It is a panel write like any other and takes a token like any other
	 * (CO-2). Its caller — `decide()` or `advance()` — still holds both
	 * `writesInFlight` and `panelWritesInFlight` at >= 1 while it awaits, so the
	 * member can dispatch a second decision meanwhile: one that sees a panel
	 * sibling in flight, asks for `full`, and repaints. Untokened, this re-read
	 * could land afterwards and rewrite the panel from a job state that predates
	 * that decision, putting the decided row back in its old bucket until a
	 * reload.
	 *
	 * @return {Promise<void>}
	 */
	const refetch = async () => {
		const { token, signal } = claimPoll();

		try {
			const job = await request( `imports/${ importId }`, { signal } );

			if ( current( token ) ) {
				swap( job );
			}
		} catch ( error ) {
			if ( ! isAbort( error ) ) {
				announce( error.message || offlineMessage );
			}
		}
	};

	/**
	 * Move focus to the heading of the bucket a decision changed, so a keyboard
	 * member lands next to the rows still waiting (AC-NFR-004).
	 *
	 * @param {string} bucket Bucket the row was in before the swap.
	 * @return {void}
	 */
	const focusBucket = ( bucket ) => {
		const heading = bucket
			? panel.querySelector(
					`[data-gamelib-bucket="${ bucket }"] .gamelib-import__bucket-title`
			  )
			: null;
		const fallback = document.getElementById( 'gamelib-import-title' );
		const target = heading || fallback;

		if ( target ) {
			target.focus();
		}
	};

	/**
	 * The bucket a control's row currently sits in.
	 *
	 * @param {HTMLElement} control Control that was activated.
	 * @return {string} Bucket slug, or '' outside a bucket.
	 */
	const bucketOf = ( control ) => {
		const section = control.closest( '[data-gamelib-bucket]' );

		return section ? section.dataset.gamelibBucket || '' : '';
	};

	/**
	 * Pick a candidate or skip a row, then re-render the whole job.
	 *
	 * @param {HTMLElement} control Control that was activated.
	 * @param {string}      verb    `choose` or `skip`.
	 * @return {Promise<void>}
	 */
	const decide = async ( control, verb ) => {
		// Re-entry refusal: a disabled control already has a request out for
		// this row, and a second one would race its own predecessor (CO-1).
		if ( control.disabled ) {
			return;
		}

		const itemId = parseInt( control.dataset.gamelibItemId || '0', 10 );

		if ( ! itemId ) {
			return;
		}

		// eslint-disable-next-line @wordpress/no-unused-vars-before-return -- Read before the swap on purpose: after it, the control is detached and has no bucket to report.
		const bucket = bucketOf( control );

		control.disabled = true;

		const { token } = claimWrite();

		/*
		 * A sibling write was already out when this one was claimed (CO-1).
		 * Deciding two rows inside one round trip is this screen's dominant
		 * interaction, and the sequence guard below discards the older response —
		 * so the older row keeps its old bucket heading while the counts written
		 * from *this* response already exclude it, and nothing reconciles: a job
		 * in `review` reports `data-gamelib-import-active="false"`, so the poller
		 * never re-arms. This response therefore has to repaint the whole panel
		 * rather than move one row, which is the only update that can also
		 * correct a row it is not about. It is the one reason for the fallback
		 * the server cannot see, so it travels with the request (PB-2).
		 *
		 * Panel writes only (CO-4). A manual row search in flight is not a
		 * sibling this response invalidates — its own row counter arbitrates
		 * it — so counting one here asked the server to re-render the whole job
		 * for nothing, and `swap()` then destroyed the query the member had
		 * typed and the candidates they were about to pick from.
		 */
		const overlapped = panelWritesInFlight > 1;
		const body = { full: overlapped };

		if ( 'choose' === verb ) {
			body.igdb_id = parseInt( control.dataset.gamelibIgdbId || '0', 10 );
		}

		try {
			const job = await request(
				`imports/${ importId }/items/${ itemId }/${ verb }`,
				{ method: 'POST', body }
			);

			if ( ! current( token ) ) {
				return;
			}

			pollInterval = POLL_INTERVAL_MS;

			// One row where the server could express it that way, the whole
			// panel where it could not (PF-3) — or where a sibling write left a
			// row this response is not about out of date (CO-1). The server
			// ships the fragment only for the second case, so a move that fails
			// against markup the server could not predict re-reads the job
			// rather than leaving the panel stale (PB-2).
			if ( overlapped || ! moveRow( job, itemId ) ) {
				if ( ! swap( job ) ) {
					await refetch();
				}
			}

			announce( job.notice || job.message || '' );
			focusBucket( bucket );
			schedule();
		} catch ( error ) {
			// Kept for the shape, never taken: a write carries no signal
			// (CO-3).
			if ( ! isAbort( error ) ) {
				announce( error.message || offlineMessage );
			}

			/*
			 * This decision superseded a sibling and then failed (CO-1). The
			 * sibling's own response was dropped by the sequence guard on the
			 * strength of this one repainting the panel — which is now not going
			 * to happen — so the row it decided is still rendered under its old
			 * bucket heading, with counts that no longer match the database, and
			 * nothing else will come: the poller does not re-arm for a job in
			 * review. Re-read the job instead of leaving the panel lying.
			 */
			if ( overlapped && current( token ) ) {
				await refetch();
			}
		} finally {
			// Each claim releases exactly what it took (CO-4).
			writesInFlight = Math.max( 0, writesInFlight - 1 );
			panelWritesInFlight = Math.max( 0, panelWritesInFlight - 1 );

			/*
			 * Unconditionally, on every path (CO-3). On success the control has
			 * usually been detached by the move or the swap, where this is a
			 * harmless write to an orphan; on every failure it is the difference
			 * between a row a member can decide again and one whose control is
			 * dead until they reload.
			 */
			control.disabled = false;
		}
	};

	/**
	 * Run the manual IGDB search on a not-found row and swap its candidate list
	 * (AC-042c).
	 *
	 * @param {HTMLElement} control Search button that was activated.
	 * @return {Promise<void>}
	 */
	const searchRow = async ( control ) => {
		/*
		 * Re-entry refusal (CO-1). The Search button is reachable twice — a
		 * click and Enter in the field — and the handler re-enters itself from
		 * the keydown listener, so without this a held Enter key dispatches a
		 * request per repeat and the results applied are whichever landed last.
		 */
		if ( control.disabled ) {
			return;
		}

		/*
		 * From the parent, never from the control itself: `closest()` starts at
		 * the element it is called on, and the Search button carries
		 * `data-gamelib-item-id` as well as its row does — so
		 * `control.closest()` returns the button, the field lookup below finds
		 * nothing inside it, and the handler returns having done nothing.
		 * See principal/adr/021-manual-search-row-lookup.md — the same defect
		 * ADR-020 records for the Add control on a search result.
		 */
		const parent = control.parentElement;
		const row = parent ? parent.closest( '[data-gamelib-item-id]' ) : null;
		const itemId = parseInt( control.dataset.gamelibItemId || '0', 10 );
		const field = row
			? row.querySelector( '[data-gamelib-import-search]' )
			: null;

		if ( ! row || ! itemId || ! field ) {
			return;
		}

		control.disabled = true;

		// This row's sequence, not the panel's and not the other rows' (CO-1,
		// CO-3): this response may be superseded by another search *on this
		// row*, and by nothing else.
		const { token } = claimRowWrite( itemId );

		try {
			const results = await request(
				`imports/${ importId }/items/${ itemId }/search`,
				{ method: 'POST', body: { q: field.value } }
			);

			if ( ! currentRow( itemId, token ) ) {
				return;
			}

			const target = row.querySelector(
				`[data-gamelib-candidates="${ itemId }"]`
			);

			if ( target && 'string' === typeof results.html ) {
				target.outerHTML = results.html;
			}

			// The first candidate takes focus, which is also what announces the
			// result; an empty search leaves the server's own message on screen
			// and says it instead.
			const first = row.querySelector(
				'[data-gamelib-action="import.choose"]'
			);

			if ( first ) {
				first.focus();
			} else {
				const state = row.querySelector( '[data-gamelib-state]' );

				announce( state ? state.textContent || '' : '' );
			}
		} catch ( error ) {
			if ( ! isAbort( error ) ) {
				announce( error.message || offlineMessage );
			}
		} finally {
			writesInFlight = Math.max( 0, writesInFlight - 1 );
			control.disabled = false;
		}
	};

	/**
	 * Close the import (AC-044a) or pick a stalled one back up (AC-043d).
	 *
	 * @param {HTMLElement} control Control that was activated.
	 * @param {string}      verb    `finish` or `retry`.
	 * @return {Promise<void>}
	 */
	const advance = async ( control, verb ) => {
		// Re-entry refusal (CO-1): Finish and Retry are both once-only.
		if ( control.disabled ) {
			return;
		}

		control.disabled = true;

		const { token } = claimWrite();

		/*
		 * A sibling decision was already out when this was claimed (CO-1).
		 * Finish and Retry take a panel token exactly as `decide()` does, so an
		 * outstanding decision's response fails `current( token )` and is
		 * dropped — safely on success, because the job this repaints from
		 * already includes that decision. On failure nothing repaints, so the
		 * decided row stays rendered under its old bucket heading with
		 * pre-decision counts, and nothing else is coming: a job in `review`
		 * reports `data-gamelib-import-active="false"`, so the poller never
		 * re-arms, and the re-enabled control would only re-send a decision the
		 * server has already committed. Panel writes only, for the reason
		 * `decide()` gives (CO-4).
		 */
		const overlapped = panelWritesInFlight > 1;

		try {
			const job = await request( `imports/${ importId }/${ verb }`, {
				method: 'POST',
				body: {},
			} );

			if ( ! current( token ) ) {
				return;
			}

			pollInterval = POLL_INTERVAL_MS;

			swap( job );
			announce( job.message || '' );
			focusBucket( '' );
			schedule();
		} catch ( error ) {
			// Kept for the shape, never taken: a write carries no signal
			// (CO-3).
			if ( ! isAbort( error ) ) {
				announce( error.message || offlineMessage );
			}

			// The repaint the dropped sibling was counting on is not coming
			// (CO-1). Re-read the job rather than leave the panel lying.
			if ( overlapped && current( token ) ) {
				await refetch();
			}
		} finally {
			// Each claim releases exactly what it took (CO-4).
			writesInFlight = Math.max( 0, writesInFlight - 1 );
			panelWritesInFlight = Math.max( 0, panelWritesInFlight - 1 );

			// Every path re-enables (CO-3). Finish and Retry both re-render the
			// panel on success, so this is a write to a detached control there.
			control.disabled = false;
		}
	};

	panel.addEventListener( 'click', ( event ) => {
		const control = event.target.closest( '[data-gamelib-action]' );

		if ( ! control || ! panel.contains( control ) ) {
			return;
		}

		switch ( control.dataset.gamelibAction ) {
			case 'import.choose':
				decide( control, 'choose' );
				break;

			case 'import.skip':
				decide( control, 'skip' );
				break;

			case 'import.search':
				searchRow( control );
				break;

			case 'import.finish':
				advance( control, 'finish' );
				break;

			case 'import.retry':
				advance( control, 'retry' );
				break;

			default:
				break;
		}
	} );

	// Enter in the manual search field runs the search rather than doing
	// nothing — the field is deliberately not inside a <form> (Never Do #12).
	panel.addEventListener( 'keydown', ( event ) => {
		if ( 'Enter' !== event.key ) {
			return;
		}

		const field = event.target.closest( '[data-gamelib-import-search]' );

		if ( ! field ) {
			return;
		}

		const row = field.closest( '[data-gamelib-item-id]' );
		const button = row
			? row.querySelector( '[data-gamelib-action="import.search"]' )
			: null;

		if ( button ) {
			event.preventDefault();
			searchRow( button );
		}
	} );

	/*
	 * A backgrounded tab stops polling and picks straight back up when the
	 * member returns, at the responsive interval — the state they are coming
	 * back to look at is exactly the state worth fetching immediately.
	 */
	document.addEventListener( 'visibilitychange', () => {
		if ( 'visible' !== document.visibilityState ) {
			window.clearTimeout( pollTimer );

			return;
		}

		pollInterval = POLL_INTERVAL_MS;
		schedule();
	} );

	// The digest of the server-rendered first paint, so the first poll can
	// already answer "identical" (PF-4).
	fingerprint = renderedFingerprint();

	schedule();
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
