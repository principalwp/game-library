<?php
/**
 * Import jobs: `gamelib_imports` and `gamelib_import_items`.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The only class that reads or writes `gamelib_imports` and
 * `gamelib_import_items`, and the plugin's whole background-job framework.
 *
 * An import is two records and a chain of cron ticks:
 *
 * 1. **The job row** (`gamelib_imports`) — who owns it, where the titles came
 *    from, how far processing has got (`cursor`), how many times the current
 *    chunk has failed (`attempts`), and when the next attempt may run
 *    (`next_attempt_at`). Progress is *state*, not a variable held across a
 *    sleeping request: VIP's Cron Control kills a callback that sleeps, so
 *    backoff is a persisted timestamp checked at tick start and nothing here
 *    ever calls `sleep()` (Never Do #7, ADR-004).
 * 2. **The item rows** (`gamelib_import_items`) — one per line of the file (or
 *    per owned Steam game), each in exactly one bucket. The buckets *are* the
 *    progress: a row leaves `pending` the moment it is resolved, so "what is
 *    left to do" is a single indexed query and an interrupted job resumes
 *    exactly where it stopped.
 *
 * **Claiming.** A tick claims the job with one conditional UPDATE — `queued →
 * processing`, guarded on the `cursor` it read a line earlier — and a tick
 * whose claim is refused returns having done nothing (AC-043e). There is no
 * cache-based lock anywhere in this file (Never Do #15): the claim is a status
 * transition, which the database arbitrates, and the claim is genuinely a
 * *change* of value, so it cannot be confused with a no-op UPDATE on either
 * MySQL or Playground's SQLite driver. A tick that dies mid-slice leaves the
 * row `processing` with an old `updated_at` — which is precisely the AC-043(d)
 * stall condition the Retry control acts on.
 *
 * **Budget.** One tick spends at most `GAMELIB_IMPORT_CALLS_PER_TICK` serial
 * IGDB requests (AC-043a), buckets at most `GAMELIB_IMPORT_ROWS_PER_TICK` rows,
 * and runs for at most `GAMELIB_IMPORT_TICK_SECONDS` of wall clock — three
 * independent bounds, because a chunk resolvable entirely from local state
 * spends zero requests and the request budget alone would let one process run a
 * whole 10,000-row job (PB-3). Whichever is reached first hands the job back to
 * `queued` and chains a fresh single event. Nothing is ever fanned out in
 * parallel.
 *
 * **Nothing happens in the starting request.** {@see start_file_import()}
 * validates, writes the two records, and schedules — it performs no IGDB
 * request of any kind, which is the whole of AC-038(e)/D-REQ-27.
 * {@see start_steam_import()} holds the same line: it talks to Steam, because
 * resolving the account and reading its game list is what "start" means there,
 * and then it queues. No matching, and no IGDB request, happens on a request a
 * member is waiting on (AC-040e).
 *
 * **Two chunk processors, one framework.** A file row already carries the IGDB
 * id it wants ({@see process_file_chunk()}); a Steam row carries an appid and a
 * title, and has to be matched through the three-stage cascade of
 * {@see process_steam_chunk()}. Everything around them — the claim, the budget
 * loop, the backoff ladder, the conclusion — is source-agnostic.
 *
 * The class never writes a file. Uploads are read from the PHP-managed
 * temporary file the request already produced and are parsed in memory; the
 * plugin performs no filesystem writes at all (Never Do #3).
 */
final class GameLib_Importer {

	/**
	 * Schema suffix of the job table.
	 *
	 * @var string
	 */
	const TABLE = 'imports';

	/**
	 * Schema suffix of the per-row table.
	 *
	 * @var string
	 */
	const ITEMS_TABLE = 'import_items';

	/**
	 * Source: the plugin's own CSV export (AC-038).
	 *
	 * @var string
	 */
	const SOURCE_CSV = 'csv';

	/**
	 * Source: the plugin's own JSON export (AC-038).
	 *
	 * @var string
	 */
	const SOURCE_JSON = 'json';

	/**
	 * Source: a public Steam profile (AC-040), matched by the three-stage
	 * cascade of {@see process_steam_chunk()}. The job framework is shared with
	 * the file sources; only the chunk processor differs.
	 *
	 * @var string
	 */
	const SOURCE_STEAM = 'steam';

	/**
	 * The three sources of the §6 Data Model whitelist.
	 *
	 * @var string[]
	 */
	const SOURCES = array( self::SOURCE_CSV, self::SOURCE_JSON, self::SOURCE_STEAM );

	/**
	 * The two file sources — the ones an upload can produce.
	 *
	 * @var string[]
	 */
	const FILE_SOURCES = array( self::SOURCE_CSV, self::SOURCE_JSON );

	/**
	 * Status: created, waiting for (or between) ticks. The only status a tick
	 * may claim.
	 *
	 * @var string
	 */
	const STATUS_QUEUED = 'queued';

	/**
	 * Status: a tick owns this job right now.
	 *
	 * @var string
	 */
	const STATUS_PROCESSING = 'processing';

	/**
	 * Status: matching is done and rows are waiting for the member's decisions
	 * (AC-042). Steam imports end here; file imports never enter it.
	 *
	 * @var string
	 */
	const STATUS_REVIEW = 'review';

	/**
	 * Status: finished. A file import reaches it when processing completes; a
	 * Steam import when the member presses Finish (AC-044a).
	 *
	 * @var string
	 */
	const STATUS_COMPLETED = 'completed';

	/**
	 * Status: the job could not be advanced at all.
	 *
	 * @var string
	 */
	const STATUS_FAILED = 'failed';

	/**
	 * The five job statuses of the §6 Data Model whitelist.
	 *
	 * @var string[]
	 */
	const STATUSES = array(
		self::STATUS_QUEUED,
		self::STATUS_PROCESSING,
		self::STATUS_REVIEW,
		self::STATUS_COMPLETED,
		self::STATUS_FAILED,
	);

	/**
	 * The statuses that mean "background work is outstanding" — the ones the
	 * stall check of AC-043(d) applies to.
	 *
	 * @var string[]
	 */
	const ACTIVE_STATUSES = array( self::STATUS_QUEUED, self::STATUS_PROCESSING );

	/**
	 * Bucket: not resolved yet. Every row starts here except one rejected by
	 * per-row validation.
	 *
	 * @var string
	 */
	const BUCKET_PENDING = 'pending';

	/**
	 * Bucket: matched by the Steam cascade's auto-attaching stage (AC-041a).
	 *
	 * @var string
	 */
	const BUCKET_MATCHED = 'matched';

	/**
	 * Bucket: candidates found, the member picks (AC-041 b,c).
	 *
	 * @var string
	 */
	const BUCKET_NEEDS_CHOICE = 'needs_choice';

	/**
	 * Bucket: IGDB has no record of this row (AC-039c, AC-041c).
	 *
	 * @var string
	 */
	const BUCKET_NOT_FOUND = 'not_found';

	/**
	 * Bucket: the member already had this game; their status was left exactly
	 * as it was (AC-039b).
	 *
	 * @var string
	 */
	const BUCKET_DUPLICATE = 'duplicate';

	/**
	 * Bucket: the member dismissed this row on the review screen (AC-042).
	 *
	 * @var string
	 */
	const BUCKET_SKIPPED = 'skipped';

	/**
	 * Bucket: added to the member's library by this import.
	 *
	 * @var string
	 */
	const BUCKET_ADDED = 'added';

	/**
	 * Bucket: the row could not be used. Per-row validation failures land here
	 * (AC-038d), and so does a chunk that exhausted its retries (AC-043c) —
	 * each carries a `note` saying which.
	 *
	 * @var string
	 */
	const BUCKET_INVALID = 'invalid';

	/**
	 * The eight buckets of the §6 Data Model whitelist.
	 *
	 * @var string[]
	 */
	const BUCKETS = array(
		self::BUCKET_PENDING,
		self::BUCKET_MATCHED,
		self::BUCKET_NEEDS_CHOICE,
		self::BUCKET_NOT_FOUND,
		self::BUCKET_DUPLICATE,
		self::BUCKET_SKIPPED,
		self::BUCKET_ADDED,
		self::BUCKET_INVALID,
	);

	/**
	 * The buckets a review or summary screen lists, in reading order. Not
	 * `pending` — a row still in it has nothing to report yet.
	 *
	 * @var string[]
	 */
	const REPORT_BUCKETS = array(
		self::BUCKET_ADDED,
		self::BUCKET_MATCHED,
		self::BUCKET_NEEDS_CHOICE,
		self::BUCKET_DUPLICATE,
		self::BUCKET_NOT_FOUND,
		self::BUCKET_INVALID,
		self::BUCKET_SKIPPED,
	);

	/**
	 * Rows resolved per chunk.
	 *
	 * One chunk of a file import is one batched IGDB by-id request (AC-039a),
	 * so this is also the largest number of ids that request can carry — far
	 * below the client's own 500-per-request ceiling, and small enough that a
	 * chunk which fails and is eventually abandoned (AC-043c) costs the member
	 * a couple of dozen rows rather than a whole import.
	 *
	 * @var int
	 */
	const CHUNK_SIZE = 25;

	/**
	 * Normalized names one `/v4/multiquery` request carries.
	 *
	 * Ten is IGDB's documented ceiling on sub-queries, and batching to it is
	 * what keeps stage 2 of the Steam cascade at one request per ten unmatched
	 * titles instead of one per title (AC-NFR-007; the task leaves the choice
	 * of batching to the coder).
	 *
	 * @var int
	 */
	const NAME_QUERY_BATCH = 10;

	/**
	 * Candidates one unresolved row may offer the member (AC-041c).
	 *
	 * @var int
	 */
	const CANDIDATE_LIMIT = 5;

	/**
	 * Edition qualifiers dropped from the tail of a normalized title
	 * (AC-041b).
	 *
	 * Steam sells "Game of the Year" and "Deluxe" repackagings under names IGDB
	 * files under the base title, so the tail is dropped before the name is
	 * looked up. Dropping `remastered` is safe here for the same reason the AC
	 * calls it a candidate-only signal: nothing in stages 2 and 3 attaches a
	 * game — every hit is a candidate the member confirms.
	 *
	 * Entries are matched against an already-normalized title, so they carry no
	 * punctuation: `director's cut` has become `director s cut` by then.
	 *
	 * @var string[]
	 */
	const EDITION_WORDS = array(
		'game of the year',
		'goty',
		'definitive',
		'deluxe',
		'ultimate',
		'complete',
		'enhanced',
		'special',
		'standard',
		'anniversary',
		'legendary',
		'collector s',
		'collectors',
		'director s cut',
		'directors cut',
		'remastered',
		'remaster',
		'redux',
	);

	/**
	 * Item rows written per INSERT statement. A 10,000-row upload is 100
	 * statements rather than 10,000.
	 *
	 * @var int
	 */
	const INSERT_BATCH = 100;

	/**
	 * Rows of per-bucket detail one status response carries (AC-039e's "per-row
	 * detail"). A 10,000-row import reports counts for everything and detail
	 * for the first slice of each bucket.
	 *
	 * @var int
	 */
	const ITEM_DETAIL_LIMIT = 100;

	/**
	 * Attempts one chunk gets before it is abandoned and the job moves on
	 * (AC-043c).
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Seconds after the 1st, 2nd, and 3rd failed attempt on a chunk (AC-043c).
	 *
	 * The first two space the retries. The third is the cooldown persisted when
	 * the chunk is abandoned: the upstream has just refused three times, so the
	 * tick that picks up the *next* chunk stands down for a moment rather than
	 * starting again immediately.
	 *
	 * @var int[]
	 */
	const BACKOFF = array( 1, 4, 16 );

	/**
	 * How old `updated_at` must be, on an active job, before the UI offers
	 * Retry (AC-043d).
	 *
	 * @var int
	 */
	const STALL_SECONDS = 10 * MINUTE_IN_SECONDS;

	/**
	 * IGDB failures worth retrying — the same two the hourly refresh retries.
	 * `unconfigured` and `malformed` are operator or code defects: three more
	 * attempts twenty seconds apart would fail identically.
	 *
	 * @var string[]
	 */
	const RETRYABLE = array(
		GameLib_IGDB_Client::ERROR_RATE_LIMITED,
		GameLib_IGDB_Client::ERROR_UNAVAILABLE,
	);

	/**
	 * Error code: nobody owns this call.
	 *
	 * @var string
	 */
	const ERROR_OWNER = 'gamelib_import_owner';

	/**
	 * Error code: no usable upload arrived (AC-038a).
	 *
	 * @var string
	 */
	const ERROR_UPLOAD = 'gamelib_import_upload';

	/**
	 * Error code: the file is not one of the plugin's export formats, or could
	 * not be parsed as one (AC-038a).
	 *
	 * @var string
	 */
	const ERROR_FORMAT = 'gamelib_import_format';

	/**
	 * Error code: the upload is over `GAMELIB_IMPORT_MAX_BYTES` (AC-038c).
	 *
	 * @var string
	 */
	const ERROR_TOO_LARGE = 'gamelib_import_too_large';

	/**
	 * Error code: the file holds more than `GAMELIB_IMPORT_MAX_ROWS` rows
	 * (AC-038b).
	 *
	 * @var string
	 */
	const ERROR_TOO_MANY_ROWS = 'gamelib_import_too_many_rows';

	/**
	 * Error code: the file parsed but held nothing to import.
	 *
	 * @var string
	 */
	const ERROR_EMPTY = 'gamelib_import_empty';

	/**
	 * Error code: the database refused a write.
	 *
	 * @var string
	 */
	const ERROR_FAILED = 'gamelib_import_failed';

	/**
	 * Error code: no such import for this member (AC-NFR-001 — another
	 * member's import is indistinguishable from one that does not exist).
	 *
	 * @var string
	 */
	const ERROR_NOT_FOUND = 'gamelib_import_not_found';

	/**
	 * Error code: Retry was asked for on an import that is not stalled
	 * (AC-043d).
	 *
	 * @var string
	 */
	const ERROR_NOT_STALLED = 'gamelib_import_not_stalled';

	/**
	 * Error code: the job row names a source this build cannot advance. Only
	 * reachable if a job outlives the code that created it.
	 *
	 * @var string
	 */
	const ERROR_SOURCE = 'gamelib_import_source';

	/**
	 * Error code: the Steam account the member typed is not usable as either a
	 * SteamID64 or a vanity name (AC-040a).
	 *
	 * @var string
	 */
	const ERROR_STEAM_ACCOUNT = 'gamelib_import_steam_account';

	/**
	 * Error code: Steam itself refused, or could not answer (AC-040 a,b). The
	 * error data carries the client's taxonomy term as `state`.
	 *
	 * @var string
	 */
	const ERROR_STEAM = 'gamelib_import_steam';

	/**
	 * Error code: no such row on this import (AC-042 b,c).
	 *
	 * @var string
	 */
	const ERROR_ITEM = 'gamelib_import_item';

	/**
	 * Error code: the action asked for is not one this job's state allows —
	 * finishing an import that is still matching, or deciding a row that was
	 * decided already.
	 *
	 * @var string
	 */
	const ERROR_STATE = 'gamelib_import_state';

	/**
	 * Validate an uploaded export file and queue it (AC-038).
	 *
	 * The whole of AC-038 happens here and nothing else does: the file is
	 * measured against `GAMELIB_IMPORT_MAX_BYTES` before it is read (c), parsed
	 * (a), measured against `GAMELIB_IMPORT_MAX_ROWS` after parsing (b), and
	 * turned into per-row records where a bad `igdb_id` or an unknown status
	 * marks that row invalid and leaves the others alone (d). Then the job is
	 * queued and the request returns — no IGDB request has been made, and none
	 * will be until a cron tick runs (e).
	 *
	 * @param int   $user_id Member starting the import.
	 * @param array $file    One entry of `WP_REST_Request::get_file_params()`
	 *                       (`name`, `type`, `tmp_name`, `error`, `size`).
	 * @return array{id:int,source:string,rows:int,invalid:array[]}|WP_Error The
	 *         queued job, or the reason the upload was refused.
	 */
	public static function start_file_import( $user_id, array $file ) {
		$user_id = absint( $user_id );

		if ( $user_id < 1 ) {
			return new WP_Error(
				self::ERROR_OWNER,
				__( 'Sign in to import a library.', 'game-library' ),
				array( 'status' => 401 )
			);
		}

		$parsed = self::parse_upload( $file );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$items     = self::items_from_rows( $parsed['rows'] );
		$import_id = self::create( $user_id, $parsed['source'], $items );

		if ( is_wp_error( $import_id ) ) {
			return $import_id;
		}

		$invalid = array();

		foreach ( $parsed['rows'] as $row ) {
			if ( '' !== $row['error'] ) {
				$invalid[] = array(
					'line'    => (int) $row['line'],
					'title'   => (string) $row['title'],
					'message' => (string) $row['error'],
				);
			}
		}

		return array(
			'id'      => $import_id,
			'source'  => $parsed['source'],
			'rows'    => count( $parsed['rows'] ),
			'invalid' => $invalid,
		);
	}

	/**
	 * Resolve a Steam account, read its public library, and queue it (AC-040).
	 *
	 * The whole of AC-040 happens here, in the order the AC lists it, and every
	 * step is a precondition of the next:
	 *
	 * 1. the member's input becomes a SteamID64 — accepted as 17 digits, or
	 *    resolved from a vanity name (a);
	 * 2. `GetPlayerSummaries` answers whether the profile's game details are
	 *    public *before* the owned-games call, so a private profile produces the
	 *    message naming the exact Steam setting rather than "owns nothing" (b);
	 * 3. the owned-games list is read through the client's 30-minute per-SteamID
	 *    transient, so a double-pressed button costs one upstream call (d);
	 * 4. the SteamID is stored only once Steam has answered for it (c);
	 * 5. the job and its rows are written and a tick is scheduled — no IGDB
	 *    request happens on this request path, and no matching of any kind (e).
	 *
	 * @param int    $user_id Member starting the import.
	 * @param string $account SteamID64 or vanity name, as the member typed it.
	 * @return array{id:int,source:string,rows:int,steamid:string,invalid:array[]}|WP_Error
	 *         The queued job, or the reason the account could not be used.
	 */
	public static function start_steam_import( $user_id, $account ) {
		$user_id = absint( $user_id );

		if ( $user_id < 1 ) {
			return new WP_Error(
				self::ERROR_OWNER,
				__( 'Sign in to import a library.', 'game-library' ),
				array( 'status' => 401 )
			);
		}

		$steamid = self::resolve_steam_account( $account );

		if ( is_wp_error( $steamid ) ) {
			return $steamid;
		}

		// AC-040(b): the pre-flight, before anything asks for the game list.
		$public = GameLib_Steam_Client::preflight( $steamid );

		if ( is_wp_error( $public ) ) {
			return self::steam_error( $public );
		}

		/*
		 * AC-040(d). The `include_appinfo` / `include_played_free_games`
		 * parameters and the 30-minute `gamelib_owned_{steamid}` transient both
		 * live in the client; an empty `{"response":{}}` body comes back from it
		 * as the private-profile error, which is why that shape is not handled
		 * again here.
		 */
		$owned = GameLib_Steam_Client::get_owned_games( $steamid );

		if ( is_wp_error( $owned ) ) {
			return self::steam_error( $owned );
		}

		$items = self::items_from_owned( $owned );

		if ( empty( $items ) ) {
			return new WP_Error(
				self::ERROR_EMPTY,
				__( 'That Steam profile does not list any games to import.', 'game-library' ),
				array(
					'status' => 400,
					'state'  => 'steam_empty',
				)
			);
		}

		// The AC-038(b) cap is a property of an import, not of an upload: a
		// Steam library over it is refused before a job row exists.
		if ( count( $items ) > GAMELIB_IMPORT_MAX_ROWS ) {
			return new WP_Error(
				self::ERROR_TOO_MANY_ROWS,
				sprintf(
					/* translators: %s: maximum number of titles one import may carry. */
					_n(
						'This site imports at most %s title at a time, and that Steam library is larger.',
						'This site imports at most %s titles at a time, and that Steam library is larger.',
						GAMELIB_IMPORT_MAX_ROWS,
						'game-library'
					),
					number_format_i18n( GAMELIB_IMPORT_MAX_ROWS )
				),
				array(
					'status' => 400,
					'state'  => 'too_many_rows',
				)
			);
		}

		// AC-040(c). Stored last of the Steam steps: the value written is one
		// Steam has just answered for, and the member's stored account is not
		// replaced by an id that turned out to be unusable.
		update_user_meta( $user_id, GameLib_Steam_Client::STEAMID_META, $steamid );

		$import_id = self::create( $user_id, self::SOURCE_STEAM, $items );

		if ( is_wp_error( $import_id ) ) {
			return $import_id;
		}

		return array(
			'id'      => $import_id,
			'source'  => self::SOURCE_STEAM,
			'rows'    => count( $items ),
			'steamid' => $steamid,
			// The shape the file path returns, so one REST handler serves both.
			'invalid' => array(),
		);
	}

	/**
	 * Create a job row with its item rows and schedule the first tick.
	 *
	 * Shared by the file and Steam start paths: both arrive here with rows
	 * already normalized, and neither has spoken to IGDB.
	 *
	 * @param int    $user_id Member the import belongs to.
	 * @param string $source  One of {@see GameLib_Importer::SOURCES}.
	 * @param array  $items   Item rows: `source_name`, `steam_appid`,
	 *                        `igdb_id`, `bucket`, `candidates`, `note`.
	 * @return int|WP_Error New import id, or the reason it could not be created.
	 */
	public static function create( $user_id, $source, array $items ) {
		$user_id = absint( $user_id );
		$source  = self::sanitize_source( $source );

		if ( $user_id < 1 ) {
			return new WP_Error(
				self::ERROR_OWNER,
				__( 'Sign in to import a library.', 'game-library' ),
				array( 'status' => 401 )
			);
		}

		if ( '' === $source ) {
			return new WP_Error(
				self::ERROR_SOURCE,
				__( 'That is not an import this site can run.', 'game-library' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $items ) ) {
			return new WP_Error(
				self::ERROR_EMPTY,
				__( 'There was nothing to import in that file.', 'game-library' ),
				array( 'status' => 400 )
			);
		}

		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->insert() prepares its own statement, and a job row is polled state that is never cached (see read_row()).
		$written = $wpdb->insert(
			GameLib_Schema::table( self::TABLE ),
			array(
				'user_id'         => $user_id,
				'source'          => $source,
				'status'          => self::STATUS_QUEUED,
				'cursor'          => 0,
				'attempts'        => 0,
				'next_attempt_at' => null,
				'counts'          => wp_json_encode( self::empty_counts( count( $items ) ) ),
				'error'           => null,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $written ) {
			return new WP_Error(
				self::ERROR_FAILED,
				__( 'That import could not be started. Please try again.', 'game-library' ),
				array( 'status' => 500 )
			);
		}

		$import_id = (int) $wpdb->insert_id;

		self::insert_items( $import_id, $items );
		self::refresh_counts( $import_id );

		// AC-038(e): the work is scheduled, never performed here.
		self::schedule_tick( $import_id );

		return $import_id;
	}

	/**
	 * One tick of one import job (AC-043).
	 *
	 * Registered on `gamelib_import_tick` unconditionally from
	 * {@see GameLib_Plugin::boot()} — never behind `is_admin()`, because cron
	 * runs in neither context under VIP's Cron Control (ADR-004).
	 *
	 * @param int|string $import_id Import the event carries.
	 * @return void
	 */
	public static function tick( $import_id ) {
		$import_id = absint( $import_id );

		if ( $import_id < 1 ) {
			return;
		}

		$import = self::read_row( $import_id );

		if ( ! is_array( $import ) || self::STATUS_QUEUED !== $import['status'] ) {
			/*
			 * Not claimable: already finished, already owned by another tick,
			 * or gone. Either way this tick is not the one doing the work
			 * (AC-043e).
			 */
			return;
		}

		$now = time();

		if ( $import['next_attempt_at'] > $now ) {
			/*
			 * The backoff, in the only form a cron callback may express it: a
			 * persisted timestamp checked at tick start. Nothing sleeps — the
			 * event scheduled for that timestamp is the one that continues
			 * (AC-043c, Never Do #7).
			 */
			self::schedule_tick( $import_id, $import['next_attempt_at'] );

			return;
		}

		if ( ! self::claim( $import_id, $import['cursor'] ) ) {
			// A concurrent tick got there first. Exit silently (AC-043e).
			return;
		}

		$import['status'] = self::STATUS_PROCESSING;

		self::run_slice( $import );
	}

	/**
	 * Re-schedule a stalled import (AC-043d).
	 *
	 * "Stalled" is a property of the row, not a claim the caller makes: an
	 * active job whose `updated_at` has not moved for {@see STALL_SECONDS} lost
	 * its chain — a dropped cron event, a killed request — and this puts it
	 * back to `queued` with a fresh event. A job that is merely slow is
	 * refused, so the control cannot be used to run ticks back to back.
	 *
	 * @param int $import_id Import to resume.
	 * @return array|WP_Error The resumed job row, or why it was refused.
	 */
	public static function retry( $import_id ) {
		$import = self::read_row( absint( $import_id ) );

		if ( ! is_array( $import ) ) {
			return self::not_found_error();
		}

		if ( ! in_array( $import['status'], self::ACTIVE_STATUSES, true ) ) {
			return new WP_Error(
				self::ERROR_NOT_STALLED,
				__( 'That import has already finished.', 'game-library' ),
				array(
					'status' => 409,
					'state'  => 'not_stalled',
				)
			);
		}

		if ( ! self::is_stalled( $import ) ) {
			return new WP_Error(
				self::ERROR_NOT_STALLED,
				__( 'That import is still running. Give it a moment.', 'game-library' ),
				array(
					'status' => 409,
					'state'  => 'not_stalled',
				)
			);
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->update() prepares its own statement. Guarded on the status this row was read with, so a tick that woke up in between wins instead of being overwritten.
		$wpdb->update(
			GameLib_Schema::table( self::TABLE ),
			array(
				'status'          => self::STATUS_QUEUED,
				'attempts'        => 0,
				'next_attempt_at' => null,
				'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'     => $import['id'],
				'status' => $import['status'],
			),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);

		self::schedule_tick( $import['id'] );

		return self::read_row( $import['id'] );
	}

	/**
	 * Attach one reviewed row to a game and add it to the library (AC-042 b,c).
	 *
	 * The member's decision — a pre-selected candidate, a candidate they picked
	 * instead, or an id from the manual search box on a not-found row — all land
	 * here, because all three are the same act: this appid means this game.
	 * Which is why the resolution is written to the Steam map (AC-045): the next
	 * import of the same library re-uses it instead of running the cascade
	 * again.
	 *
	 * The add is a normal library add with the import rules applied — status
	 * `backlog` (AC-041d), no per-game event (Never Do #11), and a duplicate
	 * reported rather than rewritten (AC-039b). It counts toward the single
	 * `games_imported` event that {@see finish()} records (AC-044b).
	 *
	 * @param int $import_id Import the row belongs to.
	 * @param int $item_id   Row being decided.
	 * @param int $igdb_id   Game the member chose.
	 * @return array{import:array,item:array,from:string,duplicate:bool}|WP_Error
	 *         The updated job and row, the bucket the row left, or why the
	 *         decision was refused.
	 */
	public static function choose( $import_id, $item_id, $igdb_id ) {
		$import = self::read_row( absint( $import_id ) );

		if ( ! is_array( $import ) ) {
			return self::not_found_error();
		}

		$item = self::item( $import['id'], $item_id );

		if ( ! is_array( $item ) ) {
			return self::item_error();
		}

		if ( ! in_array( $item['bucket'], array( self::BUCKET_NEEDS_CHOICE, self::BUCKET_NOT_FOUND ), true ) ) {
			return self::decided_error();
		}

		$igdb_id = absint( $igdb_id );

		if ( $igdb_id < 1 ) {
			// Never Do #10: no library row without an IGDB id, whoever asked.
			return new WP_Error(
				self::ERROR_ITEM,
				__( 'Choose a game before saving that row.', 'game-library' ),
				array(
					'status' => 400,
					'state'  => 'invalid_choice',
				)
			);
		}

		/*
		 * `hydrate` is left on, unlike the tick path: a candidate the member
		 * picked may be a game no import has ever stored, and a library row
		 * whose game is unknown to the store cannot render.
		 */
		$result = GameLib_Library::add(
			$import['user_id'],
			$igdb_id,
			GameLib_Library::STATUS_DEFAULT,
			array( 'record_event' => false )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $item['steam_appid'] > 0 ) {
			// AC-045: a decision is a match result, and match results persist.
			GameLib_Game_Store::set_steam_match( $item['steam_appid'], $igdb_id );
		}

		$duplicate = ! empty( $result['duplicate'] );

		self::set_bucket(
			array( $item['id'] ),
			$duplicate ? self::BUCKET_DUPLICATE : self::BUCKET_ADDED,
			array(
				'igdb_id' => $igdb_id,
				'note'    => $duplicate
					? sprintf(
						/* translators: %s: the library status the member already had for this game. */
						__( 'Already in your library as %s. Its status was left alone.', 'game-library' ),
						self::status_label( (string) $result['status'] )
					)
					: sprintf(
						/* translators: %s: the library status the game was added with. */
						__( 'Added as %s.', 'game-library' ),
						self::status_label( (string) $result['status'] )
					),
			)
		);

		self::refresh_counts( $import['id'] );

		return array(
			'import' => self::read_row( $import['id'] ),
			'item'   => self::item( $import['id'], $item['id'] ),
			// The bucket the row left, read before the write. A caller deciding
			// how to express the change needs it and cannot recover it
			// afterwards (PB-2).
			'from'      => (string) $item['bucket'],
			'duplicate' => $duplicate,
		);
	}

	/**
	 * Dismiss one reviewed row (AC-042 b,c).
	 *
	 * A skipped row keeps its Steam name and appid on the import record — the
	 * member can see what they passed over — and writes nothing to the Steam
	 * map: "not now" is not "no such game".
	 *
	 * @param int $import_id Import the row belongs to.
	 * @param int $item_id   Row being dismissed.
	 * @return array{import:array,item:array,from:string}|WP_Error The updated job
	 *         and row, the bucket the row left, or why the decision was refused.
	 */
	public static function skip( $import_id, $item_id ) {
		$import = self::read_row( absint( $import_id ) );

		if ( ! is_array( $import ) ) {
			return self::not_found_error();
		}

		$item = self::item( $import['id'], $item_id );

		if ( ! is_array( $item ) ) {
			return self::item_error();
		}

		if ( ! in_array( $item['bucket'], array( self::BUCKET_NEEDS_CHOICE, self::BUCKET_NOT_FOUND ), true ) ) {
			return self::decided_error();
		}

		self::set_bucket(
			array( $item['id'] ),
			self::BUCKET_SKIPPED,
			array( 'note' => __( 'You skipped this title.', 'game-library' ) )
		);

		self::refresh_counts( $import['id'] );

		return array(
			'import' => self::read_row( $import['id'] ),
			'item'   => self::item( $import['id'], $item['id'] ),
			// The bucket the row left, read before the write (PB-2).
			'from'   => (string) $item['bucket'],
		);
	}

	/**
	 * Close a reviewed import and record its one activity event (AC-044).
	 *
	 * A Steam import's games arrive in two waves — auto-attached at stage 1, and
	 * picked during review — and AC-044(b) counts both: N is the `matched`
	 * bucket plus the `added` bucket, which is exactly the set of rows that put
	 * a game into the library. Rows the member never decided stay where they
	 * are, so the record still shows what could not be matched (AC-042e).
	 *
	 * The transition is a conditional UPDATE on the status this call read, so
	 * two Finish presses transition the row once and record one event — and an
	 * import that added nothing records none at all (AC-044c).
	 *
	 * @param int $import_id Import to close.
	 * @return array|WP_Error The completed job row, or why it was refused.
	 */
	public static function finish( $import_id ) {
		$import = self::read_row( absint( $import_id ) );

		if ( ! is_array( $import ) ) {
			return self::not_found_error();
		}

		if ( self::STATUS_REVIEW !== $import['status'] ) {
			return new WP_Error(
				self::ERROR_STATE,
				( self::STATUS_COMPLETED === $import['status'] )
					? __( 'That import has already finished.', 'game-library' )
					: __( 'That import is still matching titles.', 'game-library' ),
				array(
					'status' => 409,
					'state'  => 'not_reviewable',
				)
			);
		}

		$counts = self::refresh_counts( $import['id'] );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->update() prepares its own statement. The WHERE carries the status this call read, which is what makes the transition — and the event below — happen exactly once.
		$closed = $wpdb->update(
			GameLib_Schema::table( self::TABLE ),
			array(
				'status'          => self::STATUS_COMPLETED,
				'attempts'        => 0,
				'next_attempt_at' => null,
				'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'     => $import['id'],
				'status' => self::STATUS_REVIEW,
			),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( 1 !== (int) $closed ) {
			// Another request finished it between the read and the write; its
			// event is the one that counts.
			return self::read_row( $import['id'] );
		}

		$added = self::added_total( $counts );

		if ( $added > 0 ) {
			GameLib_Activity::record(
				$import['user_id'],
				GameLib_Activity::TYPE_GAMES_IMPORTED,
				0,
				array( 'count' => $added )
			);
		}

		return self::read_row( $import['id'] );
	}

	/**
	 * One job row, or null.
	 *
	 * @param int $import_id Import id.
	 * @return array|null Hydrated row (see {@see hydrate()}), or null.
	 */
	public static function get( $import_id ) {
		return self::read_row( absint( $import_id ) );
	}

	/**
	 * One job row the caller owns, or a 404 (AC-NFR-001).
	 *
	 * Another member's import and an import that never existed produce exactly
	 * the same refusal, so an id cannot be probed for existence.
	 *
	 * @param int $import_id Import id.
	 * @param int $user_id   Caller.
	 * @return array|WP_Error The row, or the shared not-found refusal.
	 */
	public static function get_for_user( $import_id, $user_id ) {
		$import  = self::read_row( absint( $import_id ) );
		$user_id = absint( $user_id );

		if ( ! is_array( $import ) || $user_id < 1 || $import['user_id'] !== $user_id ) {
			return self::not_found_error();
		}

		return $import;
	}

	/**
	 * Is this job's chain broken (AC-043d)?
	 *
	 * @param array $import Job row.
	 * @return bool True when an active job has not moved for {@see STALL_SECONDS}.
	 */
	public static function is_stalled( array $import ) {
		if ( ! in_array( $import['status'], self::ACTIVE_STATUSES, true ) ) {
			return false;
		}

		$updated = strtotime( $import['updated_at'] . ' UTC' );

		if ( false === $updated ) {
			return true;
		}

		return ( time() - $updated ) > self::STALL_SECONDS;
	}

	/**
	 * The job as JSON — status, progress, counts, and what the UI may offer.
	 *
	 * @param array $import Job row.
	 * @return array<string, mixed> Transport payload.
	 */
	public static function payload( array $import ) {
		$counts    = $import['counts'];
		$total     = (int) $counts['total'];
		$pending   = (int) $counts['pending'];
		$processed = max( 0, $total - $pending );
		$stalled   = self::is_stalled( $import );
		$progress  = ( $total > 0 ) ? (int) floor( ( $processed / $total ) * 100 ) : 100;
		$message   = self::status_message( $import );

		return array(
			'id'          => $import['id'],
			'source'      => $import['source'],
			'status'      => $import['status'],
			'state'       => $import['status'],
			'total'       => $total,
			'processed'   => $processed,
			'pending'     => $pending,
			'progress'    => $progress,
			'counts'      => $counts,
			'active'      => in_array( $import['status'], self::ACTIVE_STATUSES, true ),
			'stalled'     => $stalled,
			'can_retry'   => $stalled,
			'error'       => $import['error'],
			'message'     => $message,
			'created_at'  => $import['created_at'],
			'updated_at'  => $import['updated_at'],
			'fingerprint' => self::fingerprint( $import['status'], $progress, $counts, (string) $import['updated_at'], $stalled, $message ),
			'url'         => GameLib_Router::route_url( GameLib_Router::ROUTE_IMPORT_REVIEW, $import['id'] ),
		);
	}

	/**
	 * A short digest of everything `templates/parts/import-status.php` renders
	 * (PF-4).
	 *
	 * The review panel is polled every three seconds while a job runs, and the
	 * fragment it answers with is the whole part — up to 700 rows of inputs,
	 * buttons and candidate lists. Re-parsing that unchanged string every three
	 * seconds costs 80–250ms of main thread on a mid-range Android and ~25–35KB
	 * gz on the wire, and rebuilding the nodes silently reverts whatever a
	 * member had typed into a not-found row's search field.
	 *
	 * Computed here rather than in the REST layer so the first paint can emit it
	 * on the fragment and the poller starts already holding the digest of what
	 * is on screen.
	 *
	 * `can_retry` and `message` are in the digest because the AC-043(d) stall is
	 * judged against the *clock*: a job nobody has written to for ten minutes
	 * grows a Retry button and a new sentence with no other value moving, and
	 * skipping the fragment then would hide the one control that recovers it.
	 * The row detail is not in the digest because it cannot move without moving
	 * `counts` — a decision rebuckets a row — and while the job is active there
	 * is no row detail at all (PB-10).
	 *
	 * @param string $status     Job status.
	 * @param int    $progress   Percentage complete.
	 * @param array  $counts     Bucket counts.
	 * @param string $updated_at UTC `Y-m-d H:i:s` of the last write.
	 * @param bool   $stalled    Whether the Retry control renders.
	 * @param string $message    The rendered status sentence.
	 * @return string 32-character digest.
	 */
	private static function fingerprint( $status, $progress, array $counts, $updated_at, $stalled, $message ) {
		return md5(
			(string) wp_json_encode(
				array( $status, $progress, $counts, $updated_at, (bool) $stalled, $message )
			)
		);
	}

	/**
	 * The member-facing sentence for a job's current state.
	 *
	 * Published rather than composed in the REST layer so a first paint and a
	 * polled refresh say the same thing (the same reason
	 * {@see GameLib_REST_Library::state_message()} exists).
	 *
	 * @param array $import Job row.
	 * @return string Translated message.
	 */
	public static function status_message( array $import ) {
		$counts = $import['counts'];
		$total  = (int) $counts['total'];

		if ( self::is_stalled( $import ) ) {
			return __( 'This import has not moved for a while. Use Retry to pick it up again.', 'game-library' );
		}

		switch ( $import['status'] ) {
			case self::STATUS_QUEUED:
				return sprintf(
					/* translators: %s: number of titles in the import. */
					_n(
						'%s title is queued for import.',
						'%s titles are queued for import.',
						$total,
						'game-library'
					),
					number_format_i18n( $total )
				);

			case self::STATUS_PROCESSING:
				/*
				 * Pluralised on the *total* (CO-5): the sentence is about the
				 * import's titles, and the first number is a progress position
				 * inside that set rather than a count of its own. AC-NFR-008
				 * forbids `__()` around a sentence carrying a count.
				 */
				return sprintf(
					/* translators: 1: titles resolved so far, 2: titles in the import. */
					_n(
						'Matching title — %1$s of %2$s done.',
						'Matching titles — %1$s of %2$s done.',
						$total,
						'game-library'
					),
					number_format_i18n( max( 0, $total - (int) $counts['pending'] ) ),
					number_format_i18n( $total )
				);

			case self::STATUS_REVIEW:
				return sprintf(
					/* translators: %s: number of titles needing a decision. */
					_n(
						'%s title needs your decision.',
						'%s titles need your decision.',
						(int) $counts['needs_choice'],
						'game-library'
					),
					number_format_i18n( (int) $counts['needs_choice'] )
				);

			case self::STATUS_FAILED:
				return __( 'This import could not be completed.', 'game-library' );
		}

		$added = self::added_total( $counts );

		return sprintf(
			/* translators: %s: number of games added to the library. */
			_n(
				'Import finished. %s game was added to your library.',
				'Import finished. %s games were added to your library.',
				$added,
				'game-library'
			),
			number_format_i18n( $added )
		);
	}

	/**
	 * Games one import actually put into the member's library (AC-044b).
	 *
	 * Two buckets, because a Steam import adds games twice: `matched` is what
	 * stage 1 auto-attached and `added` is what the member picked during review.
	 * A file import never uses `matched`, so the same sum is its added count
	 * too, and one definition of N serves both sources.
	 *
	 * @param array<string, int> $counts Bucket counts.
	 * @return int Games added.
	 */
	public static function added_total( array $counts ) {
		$matched = isset( $counts[ self::BUCKET_MATCHED ] ) ? (int) $counts[ self::BUCKET_MATCHED ] : 0;
		$added   = isset( $counts[ self::BUCKET_ADDED ] ) ? (int) $counts[ self::BUCKET_ADDED ] : 0;

		return $matched + $added;
	}

	/**
	 * One bucket's rows, oldest first.
	 *
	 * @param int    $import_id Import id.
	 * @param string $bucket    One of {@see GameLib_Importer::BUCKETS}.
	 * @param int    $limit     Rows to read.
	 * @param int    $offset    Rows to skip.
	 * @return array[] Item rows (see {@see hydrate_item()}).
	 */
	public static function items( $import_id, $bucket, $limit = self::ITEM_DETAIL_LIMIT, $offset = 0 ) {
		$import_id = absint( $import_id );
		$bucket    = self::sanitize_bucket( $bucket );

		if ( $import_id < 1 || '' === $bucket ) {
			return array();
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::ITEMS_TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; import items are job state a review screen polls, and the cache floor (15 minutes) is longer than most whole imports, so a cached copy would report the bucket a member just emptied.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); every value is a bound placeholder.
				"SELECT id, source_name, steam_appid, igdb_id, bucket, candidates, note FROM {$table} WHERE import_id = %d AND bucket = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$import_id,
				$bucket,
				max( 1, absint( $limit ) ),
				absint( $offset )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$items = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$items[] = self::hydrate_item( $row );
			}
		}

		return $items;
	}

	/**
	 * One item row of one import, or null.
	 *
	 * Scoped to the import on purpose: a row id is a path parameter on the
	 * review routes, and an id belonging to somebody else's import must read as
	 * "no such row" rather than as a row (AC-NFR-001).
	 *
	 * @phpstan-impure
	 *
	 * @param int $import_id Import the row must belong to.
	 * @param int $item_id   Row id.
	 * @return array|null Hydrated item (see {@see hydrate_item()}), or null.
	 */
	public static function item( $import_id, $item_id ) {
		$import_id = absint( $import_id );
		$item_id   = absint( $item_id );

		if ( $import_id < 1 || $item_id < 1 ) {
			return null;
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::ITEMS_TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; an item row is job state read on the write path of a decision the very next statement acts on, and the plugin's 15-minute cache floor outlives most imports (see read_row()).
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); both values are bound placeholders.
				"SELECT id, source_name, steam_appid, igdb_id, bucket, candidates, note FROM {$table} WHERE id = %d AND import_id = %d",
				$item_id,
				$import_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate_item( $row ) : null;
	}

	/**
	 * Detail rows for every bucket that holds any, capped per bucket.
	 *
	 * A job still `queued` or `processing` reports **no** rows (PB-10). Its rows
	 * are a moving target — nothing on the screen offers a decision until the
	 * job reaches `review`, and the poller renders counts and a progress bar
	 * until then — while reading them costs one uncached `LIMIT 100` query per
	 * non-empty bucket, up to six, every three seconds per importing member.
	 *
	 * The guard lives here rather than in the REST layer on purpose: the first
	 * paint of `/my-library/imports/{id}/` and the polled fragment come through
	 * this method, and ADR-002 requires them to be byte-identical.
	 *
	 * @param array $import Job row, whose counts say which buckets to read.
	 * @return array<string, array[]> Rows keyed by bucket; empty buckets absent.
	 */
	public static function report_items( array $import ) {
		$report = array();

		if ( in_array( $import['status'], self::ACTIVE_STATUSES, true ) ) {
			return $report;
		}

		foreach ( self::REPORT_BUCKETS as $bucket ) {
			if ( empty( $import['counts'][ $bucket ] ) ) {
				continue;
			}

			$rows = self::items( $import['id'], $bucket, self::ITEM_DETAIL_LIMIT );

			if ( ! empty( $rows ) ) {
				$report[ $bucket ] = $rows;
			}
		}

		return $report;
	}

	/**
	 * Schedule one tick of a job.
	 *
	 * `wp_schedule_single_event()` refuses an event whose hook *and* arguments
	 * already have an occurrence due within ten minutes; for this hook the
	 * arguments carry the import id, so the refusal means "a tick for this
	 * import is already coming", which is the outcome the caller wanted anyway.
	 * A chained tick is never refused by its own predecessor: core unschedules
	 * a due event before running its callback.
	 *
	 * @param int $import_id Import to advance.
	 * @param int $timestamp Optional. When to run; defaults to now.
	 * @return bool True when a tick is scheduled (freshly or already).
	 */
	public static function schedule_tick( $import_id, $timestamp = 0 ) {
		$import_id = absint( $import_id );

		if ( $import_id < 1 ) {
			return false;
		}

		$timestamp = absint( $timestamp );

		if ( $timestamp < 1 ) {
			$timestamp = time();
		}

		$args      = array( $import_id );
		$scheduled = wp_schedule_single_event( $timestamp, GameLib_Plugin::CRON_IMPORT_HOOK, $args, true );

		if ( is_wp_error( $scheduled ) ) {
			return 'duplicate_event' === $scheduled->get_error_code();
		}

		return false !== $scheduled;
	}

	/**
	 * A source, or '' when the value is not one of the three.
	 *
	 * @param mixed $source Candidate source.
	 * @return string Whitelisted source, or ''.
	 */
	public static function sanitize_source( $source ) {
		$source = is_scalar( $source ) ? sanitize_key( (string) $source ) : '';

		return in_array( $source, self::SOURCES, true ) ? $source : '';
	}

	/**
	 * A bucket, or '' when the value is not one of the eight.
	 *
	 * @param mixed $bucket Candidate bucket.
	 * @return string Whitelisted bucket, or ''.
	 */
	public static function sanitize_bucket( $bucket ) {
		$bucket = is_scalar( $bucket ) ? sanitize_key( (string) $bucket ) : '';

		return in_array( $bucket, self::BUCKETS, true ) ? $bucket : '';
	}

	/**
	 * The reported buckets and their headings, in the order a member reads them.
	 *
	 * What happened, then what still wants a decision, then what could not be
	 * used. `pending` is deliberately absent: a row still being resolved is
	 * progress, reported by {@see status_message()} and the progress bar, not a
	 * bucket with a heading.
	 *
	 * Published here rather than authored in the template (PF-3) because three
	 * renderers now need the same list and the same labels: the whole-job part,
	 * the counts part, and the decision routes that answer with one moved row.
	 *
	 * @return array<string, string> Bucket → translated heading.
	 */
	public static function bucket_labels() {
		return array(
			self::BUCKET_ADDED        => _x( 'Added to your library', 'import bucket', 'game-library' ),
			self::BUCKET_MATCHED      => _x( 'Matched', 'import bucket', 'game-library' ),
			self::BUCKET_NEEDS_CHOICE => _x( 'Needs your choice', 'import bucket', 'game-library' ),
			self::BUCKET_DUPLICATE    => _x( 'Already in your library', 'import bucket', 'game-library' ),
			self::BUCKET_NOT_FOUND    => _x( 'Not found', 'import bucket', 'game-library' ),
			self::BUCKET_INVALID      => _x( 'Could not be imported', 'import bucket', 'game-library' ),
			self::BUCKET_SKIPPED      => _x( 'Skipped', 'import bucket', 'game-library' ),
		);
	}

	/**
	 * Move item rows into a bucket, optionally stamping a note.
	 *
	 * The single write path for item state, shared by the file processor, the
	 * abandoned-chunk path, and (later) the review screen's pick and skip
	 * actions.
	 *
	 * @param int[]  $item_ids Rows to move.
	 * @param string $bucket   Target bucket.
	 * @param array  $args     Optional. `note` (string) per-row detail;
	 *                         `igdb_id` (int) resolved game; `candidates`
	 *                         (array[]) the AC-041 candidate list, stored as
	 *                         JSON; `from` (string) move only rows currently in
	 *                         that bucket.
	 * @return int Rows moved.
	 */
	public static function set_bucket( array $item_ids, $bucket, array $args = array() ) {
		$item_ids = self::positive_ints( $item_ids );
		$bucket   = self::sanitize_bucket( $bucket );

		if ( empty( $item_ids ) || '' === $bucket ) {
			return 0;
		}

		global $wpdb;

		$assignments = array( 'bucket = %s' );
		$values      = array( $bucket );

		if ( isset( $args['note'] ) ) {
			$assignments[] = 'note = %s';
			$values[]      = self::note( (string) $args['note'] );
		}

		if ( isset( $args['igdb_id'] ) ) {
			$assignments[] = 'igdb_id = %d';
			$values[]      = absint( $args['igdb_id'] );
		}

		if ( ! empty( $args['candidates'] ) && is_array( $args['candidates'] ) ) {
			$assignments[] = 'candidates = %s';
			$values[]      = wp_json_encode( array_values( $args['candidates'] ) );
		}

		$table        = GameLib_Schema::table( self::ITEMS_TABLE );
		$set          = implode( ', ', $assignments );
		$placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
		$where        = "id IN ({$placeholders})";
		$conditions   = $item_ids;

		/*
		 * The optional bucket guard makes this a claim rather than a blind
		 * write: a chunk that failed part way through has rows the cascade
		 * already resolved, and abandoning the chunk must not drag those back
		 * out of the bucket they earned (AC-043c).
		 */
		$from = isset( $args['from'] ) ? self::sanitize_bucket( $args['from'] ) : '';

		if ( '' !== $from ) {
			$where       .= ' AND bucket = %s';
			$conditions[] = $from;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $set, $placeholders, and $where are generated from literals holding %s/%d placeholders bound by prepare() below; $table comes from GameLib_Schema::table(). A write is never cached.
		$changed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
				"UPDATE {$table} SET {$set} WHERE {$where}",
				array_merge( $values, $conditions )
			)
		);

		return is_int( $changed ) ? $changed : 0;
	}

	/**
	 * Recount the buckets and persist the result on the job row.
	 *
	 * The counts column is the summary AC-039(e) reports and the progress the
	 * poll route renders, so it is refreshed whenever items move: at creation,
	 * after every chunk, and at completion.
	 *
	 * @param int $import_id Import id.
	 * @return array<string, int> The counts just written.
	 */
	public static function refresh_counts( $import_id ) {
		$import_id = absint( $import_id );
		$counts    = self::count_buckets( $import_id );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->update() prepares its own statement, and the job row is never cached (see read_row()).
		$wpdb->update(
			GameLib_Schema::table( self::TABLE ),
			array(
				'counts'     => wp_json_encode( $counts ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $import_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return $counts;
	}

	/**
	 * Count every bucket of one import in a single grouped query.
	 *
	 * @param int $import_id Import id.
	 * @return array<string, int> Every bucket key, plus `total`, always present.
	 */
	public static function count_buckets( $import_id ) {
		$import_id = absint( $import_id );
		$counts    = self::empty_counts( 0 );

		if ( $import_id < 1 ) {
			return $counts;
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::ITEMS_TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; the result is written straight onto the job row by refresh_counts(), which is what every reader consults.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); the one value is a bound placeholder.
				"SELECT bucket, COUNT(*) AS total FROM {$table} WHERE import_id = %d GROUP BY bucket",
				$import_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return $counts;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['bucket'], $row['total'] ) ) {
				continue;
			}

			$bucket = self::sanitize_bucket( $row['bucket'] );
			$total  = (int) $row['total'];

			if ( '' !== $bucket ) {
				$counts[ $bucket ] = $total;
			}

			$counts['total'] += $total;
		}

		// The §6 alias for the duplicate bucket, kept so the documented
		// `{total,matched,needs_choice,not_found,duplicates,added,invalid}`
		// shape is what callers read.
		$counts['duplicates'] = $counts[ self::BUCKET_DUPLICATE ];

		return $counts;
	}

	/**
	 * How much import history one member has (AC-052d, SE-1).
	 *
	 * The disclosure read behind {@see GameLib_Privacy::erase()}. This class is
	 * the only one permitted to touch `gamelib_imports` / `gamelib_import_items`,
	 * so the eraser asks here instead of composing a statement of its own.
	 *
	 * It is a disclosure read and not the first half of a deletion: no privacy
	 * path removes these rows — AC-052's enumeration does not name either table,
	 * and `principal/adr/034-import-history-is-outside-the-erasure.md` records
	 * why — so the eraser reports them under `items_retained` rather than letting
	 * a completion confirmation imply they are gone.
	 *
	 * Read live, like every other read of these two tables: they are polled job
	 * state, and the cache floor is fifteen minutes.
	 *
	 * @param int $user_id Member being erased.
	 * @return array{imports:int,items:int} Job rows, and the item rows under them.
	 */
	public static function counts_for_user( $user_id ) {
		$user_id = absint( $user_id );
		$counts  = array(
			'imports' => 0,
			'items'   => 0,
		);

		if ( $user_id < 1 ) {
			return $counts;
		}

		global $wpdb;

		$jobs  = GameLib_Schema::table( self::TABLE );
		$items = GameLib_Schema::table( self::ITEMS_TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; import rows are read live by design (they are polled job state against a 15-minute cache floor).
		$counts['imports'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $jobs is GameLib_Schema::table(); the one value is a bound placeholder.
				"SELECT COUNT(*) FROM {$jobs} WHERE user_id = %d",
				$user_id
			)
		);

		if ( $counts['imports'] < 1 ) {
			return $counts;
		}

		/*
		 * `gamelib_import_items` has no `user_id` column of its own, so the
		 * ownership test is the sub-select against the job table — the same
		 * relationship any deletion here would have to use, and the reason a
		 * deletion would need care.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$counts['items'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both names come from GameLib_Schema::table(); the one value is a bound placeholder.
				"SELECT COUNT(*) FROM {$items} WHERE import_id IN ( SELECT id FROM {$jobs} WHERE user_id = %d )",
				$user_id
			)
		);

		return $counts;
	}

	/**
	 * Advance one job as far as this tick's request budget allows.
	 *
	 * The loop is chunk-by-chunk, and every chunk that resolves persists its
	 * result before the next one starts — a tick that dies half way through
	 * loses no completed work, and the job resumes at the first row still
	 * `pending`.
	 *
	 * Three independent budgets bound the slice, and whichever is reached first
	 * ends it (PB-3):
	 *
	 * - `GAMELIB_IMPORT_CALLS_PER_TICK` — serial IGDB requests, the 4 req/s
	 *   ceiling this was originally written for;
	 * - `GAMELIB_IMPORT_ROWS_PER_TICK` — rows bucketed, because a chunk
	 *   resolvable from local state spends no requests at all and would
	 *   otherwise let one process run the whole 10,000-row job;
	 * - `GAMELIB_IMPORT_TICK_SECONDS` — wall clock, because neither count knows
	 *   how long the work took.
	 *
	 * Overshoot is at most one chunk in each direction, which the chained tick
	 * absorbs: {@see release()} + {@see schedule_tick()} is the same tail an
	 * exhausted request budget already took.
	 *
	 * @param array $import Job row, already claimed by this tick.
	 * @return void
	 */
	private static function run_slice( array $import ) {
		$calls    = 0;
		$rows     = 0;
		$cursor   = $import['cursor'];
		$budget   = (int) GAMELIB_IMPORT_CALLS_PER_TICK;
		$row_cap  = (int) GAMELIB_IMPORT_ROWS_PER_TICK;
		$deadline = microtime( true ) + (float) GAMELIB_IMPORT_TICK_SECONDS;

		while ( $calls < $budget && $rows < $row_cap && microtime( true ) < $deadline ) {
			$items = self::pending_items( $import['id'], self::CHUNK_SIZE );

			if ( empty( $items ) ) {
				self::conclude( $import );

				return;
			}

			$outcome = self::process_chunk( $import, $items );
			$calls  += (int) $outcome['calls'];
			$rows   += count( $items );

			if ( isset( $outcome['error'] ) && is_wp_error( $outcome['error'] ) ) {
				self::record_chunk_failure( $import, $items, $outcome['error'] );

				return;
			}

			$cursor += count( $items );

			self::advance( $import['id'], $cursor );
		}

		// A budget spent with rows left: hand the job back and chain the next
		// tick (AC-043a).
		self::release( $import['id'], $cursor );
		self::schedule_tick( $import['id'] );
	}

	/**
	 * Resolve one chunk of rows against IGDB and the member's library.
	 *
	 * @param array   $import Job row.
	 * @param array[] $items  Pending item rows.
	 * @return array{calls:int,error?:WP_Error} Requests spent, and the failure
	 *         that ended the chunk if there was one.
	 */
	private static function process_chunk( array $import, array $items ) {
		if ( in_array( $import['source'], self::FILE_SOURCES, true ) ) {
			return self::process_file_chunk( $import, $items );
		}

		if ( self::SOURCE_STEAM === $import['source'] ) {
			return self::process_steam_chunk( $import, $items );
		}

		/*
		 * Only reachable for a job row whose source this build cannot advance
		 * — a Steam import created by a later build and processed by an older
		 * one. Not a silent no-op: the chunk is failed with a stated reason
		 * through the same path an exhausted chunk takes.
		 */
		return array(
			'calls' => 0,
			'error' => new WP_Error(
				self::ERROR_SOURCE,
				__( 'This import cannot be processed by the current version of the plugin.', 'game-library' )
			),
		);
	}

	/**
	 * Resolve one chunk of file rows (AC-039).
	 *
	 * The order matters and is the whole of the AC:
	 *
	 * 1. ids the member already has are duplicates — their existing status is
	 *    read, reported, and left alone; "finished" is never reset (b);
	 * 2. ids the shared store has never seen are fetched in **one** batched
	 *    by-id request (a) — the only outbound call this method can make;
	 * 3. an id IGDB did not answer for is not-found (c);
	 * 4. everything else is added with the row's status and, when the file
	 *    carried a usable one, the row's date (d).
	 *
	 * @param array   $import Job row.
	 * @param array[] $items  Pending item rows.
	 * @return array{calls:int,error?:WP_Error} Requests spent, and the failure
	 *         that ended the chunk if there was one.
	 */
	private static function process_file_chunk( array $import, array $items ) {
		$user_id  = $import['user_id'];
		$calls    = 0;
		$igdb_ids = array();

		foreach ( $items as $item ) {
			if ( $item['igdb_id'] > 0 ) {
				$igdb_ids[] = $item['igdb_id'];
			}
		}

		$igdb_ids = array_values( array_unique( $igdb_ids ) );
		$existing = GameLib_Library::statuses_for( $user_id, $igdb_ids );
		$missing  = array();

		// One batched store read for the chunk, not one per row (PB-2). It also
		// primes every id it looked at, so resolve_file_item()'s own get() below
		// is free.
		$known = GameLib_Game_Store::get_many( $igdb_ids );

		foreach ( $igdb_ids as $igdb_id ) {
			if ( isset( $existing[ $igdb_id ] ) ) {
				continue;
			}

			if ( ! isset( $known[ $igdb_id ] ) || ! is_array( $known[ $igdb_id ] ) ) {
				$missing[] = $igdb_id;
			}
		}

		if ( ! empty( $missing ) ) {
			// AC-039(a): one batched request for the whole chunk, on the
			// background timeout budget (DD-017).
			$records = GameLib_IGDB_Client::get_games_by_ids( $missing, array( 'background' => true ) );
			++$calls;

			if ( is_wp_error( $records ) ) {
				return array(
					'calls' => $calls,
					'error' => $records,
				);
			}

			foreach ( $records as $record ) {
				if ( is_array( $record ) ) {
					GameLib_Game_Store::upsert_from_igdb( $record );
				}
			}
		}

		/*
		 * One post-cache prime for the chunk (PB-4). Every add() ends in
		 * maybe_first_add() -> get_post_status(), which is an uncached get_post()
		 * for any game that already has a page — in the common re-import case,
		 * every row. The store rows just read carry those post ids, so the whole
		 * chunk's posts are primed in one query instead of 250 single-row ones.
		 */
		self::prime_game_posts( $known );

		foreach ( $items as $item ) {
			self::resolve_file_item( $user_id, $item, $existing );
		}

		// One bump for the chunk, not one per added row (PB-7).
		GameLib_Cache::bump( GameLib_Cache::library_scope( $user_id ) );

		return array( 'calls' => $calls );
	}

	/**
	 * Prime the post cache for a batch of store rows (PB-4).
	 *
	 * `GameLib_Library::decorate()` is the pattern: collect the non-zero post
	 * ids a batch read already returned and prime them once, before any loop
	 * that will read them one at a time.
	 *
	 * @param array<int, array|null> $rows Store rows keyed by IGDB id.
	 * @return void
	 */
	private static function prime_game_posts( array $rows ) {
		$post_ids = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['post_id'] ) && (int) $row['post_id'] > 0 ) {
				$post_ids[] = (int) $row['post_id'];
			}
		}

		if ( ! empty( $post_ids ) ) {
			_prime_post_caches( array_values( array_unique( $post_ids ) ), false, false );
		}
	}

	/**
	 * Bucket one file row against the store and the member's library.
	 *
	 * @param int   $user_id  Import owner.
	 * @param array $item     Item row.
	 * @param array $existing Statuses the member already holds, keyed by IGDB id.
	 * @return void
	 */
	private static function resolve_file_item( $user_id, array $item, array $existing ) {
		$igdb_id = $item['igdb_id'];

		if ( $igdb_id < 1 ) {
			self::set_bucket(
				array( $item['id'] ),
				self::BUCKET_INVALID,
				array( 'note' => __( 'This row carries no IGDB id.', 'game-library' ) )
			);

			return;
		}

		if ( isset( $existing[ $igdb_id ] ) ) {
			// AC-039(b): already in the library — reported, never rewritten.
			self::set_bucket(
				array( $item['id'] ),
				self::BUCKET_DUPLICATE,
				array(
					'note' => sprintf(
						/* translators: %s: the library status the member already had for this game. */
						__( 'Already in your library as %s. Its status was left alone.', 'game-library' ),
						self::status_label( (string) $existing[ $igdb_id ] )
					),
				)
			);

			return;
		}

		if ( null === GameLib_Game_Store::get( $igdb_id ) ) {
			// AC-039(c): IGDB does not recognize the id.
			self::set_bucket(
				array( $item['id'] ),
				self::BUCKET_NOT_FOUND,
				array( 'note' => __( 'IGDB has no game with that id.', 'game-library' ) )
			);

			return;
		}

		$payload = $item['payload'];
		$status  = GameLib_Library::sanitize_status( isset( $payload['status'] ) ? $payload['status'] : '' );

		if ( '' === $status ) {
			$status = GameLib_Library::STATUS_DEFAULT;
		}

		$result = GameLib_Library::add(
			$user_id,
			$igdb_id,
			$status,
			array(
				// The chunk has already hydrated what it needed; an import row
				// never triggers its own IGDB fetch.
				'hydrate'            => false,
				// Never Do #11: an import emits exactly one aggregated event,
				// at completion — never one per game.
				'record_event'       => false,
				// AC-039(d): the file's own date when it carried a usable one.
				'added_at'           => isset( $payload['date_added'] ) ? (string) $payload['date_added'] : '',
				/*
				 * The batched statuses_for() read above proved this pair absent
				 * a few lines ago, and the chunk bumps the member's scope once
				 * at the end rather than once per row (PB-7).
				 */
				'known_absent'       => true,
				'defer_invalidation' => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			self::set_bucket(
				array( $item['id'] ),
				self::BUCKET_INVALID,
				array( 'note' => $result->get_error_message() )
			);

			return;
		}

		if ( ! empty( $result['duplicate'] ) ) {
			self::set_bucket(
				array( $item['id'] ),
				self::BUCKET_DUPLICATE,
				array(
					'note' => sprintf(
						/* translators: %s: the library status the member already had for this game. */
						__( 'Already in your library as %s. Its status was left alone.', 'game-library' ),
						self::status_label( (string) $result['status'] )
					),
				)
			);

			return;
		}

		self::set_bucket(
			array( $item['id'] ),
			self::BUCKET_ADDED,
			array(
				'note' => sprintf(
					/* translators: %s: the library status the game was added with. */
					__( 'Added as %s.', 'game-library' ),
					self::status_label( (string) $result['status'] )
				),
			)
		);
	}

	/**
	 * Resolve one chunk of Steam rows through the three-stage cascade (AC-041).
	 *
	 * The stages are ordered by cost, and only the first one attaches anything:
	 *
	 * 1. **Appid → IGDB id.** The persistent Steam map answers first, positive
	 *    rows and negative rows alike, for nothing (AC-045); appids it has never
	 *    seen go to `/v4/external_games` in one batched request whose `uid`
	 *    values are quoted strings and whose source filter is the id resolved at
	 *    runtime from `/v4/external_game_sources` — never the deprecated
	 *    `category = 1` literal (DD-016, Never Do #18). Matches are hydrated by
	 *    id in one more request, added to the library as `backlog`, and bucketed
	 *    `matched` (a, d).
	 * 2. **Normalized exact name.** Misses are looked up by their normalized
	 *    title, ten at a time through `/v4/multiquery`; hits become candidates
	 *    the member confirms, never attachments (b).
	 * 3. **Fuzzy search.** Whatever is still unresolved gets one `search` for up
	 *    to five candidates, or lands in `not_found` — and a `not_found` appid
	 *    writes the negative map row that keeps the next import from paying for
	 *    the same two lookups again (c, AC-045b).
	 *
	 * Every request is serial and counted, because the tick's budget
	 * (`GAMELIB_IMPORT_CALLS_PER_TICK`) is the only thing standing between a
	 * 10,000-title library and IGDB's rate limit (AC-NFR-007).
	 *
	 * @param array   $import Job row.
	 * @param array[] $items  Pending item rows.
	 * @return array{calls:int,error?:WP_Error} Requests spent, and the failure
	 *         that ended the chunk if there was one.
	 */
	private static function process_steam_chunk( array $import, array $items ) {
		$user_id = $import['user_id'];
		$args    = array( 'background' => true );
		$calls   = 0;
		$appids  = array();

		foreach ( $items as $item ) {
			if ( $item['steam_appid'] > 0 ) {
				$appids[] = $item['steam_appid'];
			}
		}

		$appids = array_values( array_unique( $appids ) );

		// AC-045(a): what the map already knows costs no request at all.
		$known   = GameLib_Game_Store::get_steam_matches( $appids );
		$matches = array();
		$unseen  = array();

		foreach ( $appids as $appid ) {
			if ( ! array_key_exists( $appid, $known ) ) {
				$unseen[] = $appid;

				continue;
			}

			if ( null !== $known[ $appid ] && $known[ $appid ] > 0 ) {
				$matches[ $appid ] = (int) $known[ $appid ];
			}
		}

		if ( ! empty( $unseen ) ) {
			$stage_one = self::match_appids( $unseen, $args );
			$calls    += (int) $stage_one['calls'];

			if ( isset( $stage_one['error'] ) ) {
				return array(
					'calls' => $calls,
					'error' => $stage_one['error'],
				);
			}

			foreach ( $stage_one['matches'] as $appid => $igdb_id ) {
				$matches[ (int) $appid ] = (int) $igdb_id;
			}
		}

		$hydrated = self::hydrate_games( array_values( $matches ), $args );
		$calls   += (int) $hydrated['calls'];

		if ( isset( $hydrated['error'] ) ) {
			return array(
				'calls' => $calls,
				'error' => $hydrated['error'],
			);
		}

		// AC-041(d) → AC-039(b): one batched read decides which matches are
		// games the member already owns.
		$existing  = GameLib_Library::statuses_for( $user_id, array_values( $matches ) );
		$unmatched = array();

		/*
		 * One post-cache prime for the chunk's matched games (PB-4): every
		 * attach_matched_item() ends in maybe_first_add() -> get_post_status(),
		 * an uncached get_post() for any game that already has a page. The rows
		 * hydrate_games() just wrote carry those post ids.
		 */
		self::prime_game_posts( GameLib_Game_Store::get_many( array_values( $matches ) ) );

		foreach ( $items as $item ) {
			$appid = $item['steam_appid'];

			if ( $appid > 0 && isset( $matches[ $appid ] ) ) {
				self::attach_matched_item( $user_id, $item, $matches[ $appid ], $existing );

				continue;
			}

			if ( $appid > 0 && array_key_exists( $appid, $known ) ) {
				/*
				 * The negative row of AC-045(b): this appid has been through the
				 * cascade before and matched nothing. No stage 2, no stage 3, no
				 * request.
				 */
				self::set_bucket(
					array( $item['id'] ),
					self::BUCKET_NOT_FOUND,
					array( 'note' => __( 'IGDB has no game for this Steam title.', 'game-library' ) )
				);

				continue;
			}

			$name = self::normalize_title( $item['name'] );

			if ( '' === $name ) {
				/*
				 * Steam sent an appid with no usable title, so stages 2 and 3
				 * have nothing to look up. Deliberately no negative map row: the
				 * cascade did not run, and another member's copy of this appid
				 * may well arrive with a name.
				 */
				self::set_bucket(
					array( $item['id'] ),
					self::BUCKET_NOT_FOUND,
					array( 'note' => __( 'Steam did not give this title a name to match on.', 'game-library' ) )
				);

				continue;
			}

			$unmatched[] = array(
				'item' => $item,
				'name' => $name,
			);
		}

		$rest   = self::resolve_by_name( $unmatched, $args );
		$calls += (int) $rest['calls'];

		// One bump for the chunk, not one per attached row (PB-7).
		GameLib_Cache::bump( GameLib_Cache::library_scope( $user_id ) );

		$outcome = array( 'calls' => $calls );

		if ( isset( $rest['error'] ) ) {
			$outcome['error'] = $rest['error'];
		}

		return $outcome;
	}

	/**
	 * Stage 1: cross-reference unseen appids against IGDB (AC-041a).
	 *
	 * Every resolution is written to the Steam map on the way through, which is
	 * what makes a re-import of the same library free (AC-045a).
	 *
	 * @param int[] $appids Appids with no row in the Steam map.
	 * @param array $args   Client args (`background`).
	 * @return array{calls:int,matches:array<int,int>,error?:WP_Error} Requests
	 *         spent, appid → IGDB id, and the failure that ended the stage.
	 */
	private static function match_appids( array $appids, array $args ) {
		$matches = array();

		/*
		 * Resolved here rather than left to the client's own lookup so the
		 * request is counted and a source-resolution failure ends the chunk with
		 * the taxonomy term that caused it. The value is cached for a day, so
		 * this is one request per day site-wide and the count is a ceiling.
		 */
		$source_id = GameLib_IGDB_Client::get_external_source_id( $args );
		$calls     = 1;

		if ( is_wp_error( $source_id ) ) {
			return array(
				'calls'   => $calls,
				'matches' => $matches,
				'error'   => $source_id,
			);
		}

		$rows = GameLib_IGDB_Client::get_external_games_by_appids( $appids, $args );
		++$calls;

		if ( is_wp_error( $rows ) ) {
			return array(
				'calls'   => $calls,
				'matches' => $matches,
				'error'   => $rows,
			);
		}

		foreach ( $rows as $row ) {
			// `uid` is a String field on IGDB's side — it comes back quoted, and
			// is the Steam appid.
			$appid   = isset( $row['uid'] ) && is_scalar( $row['uid'] ) ? (int) $row['uid'] : 0;
			$igdb_id = isset( $row['game'] ) ? absint( $row['game'] ) : 0;

			if ( $appid < 1 || $igdb_id < 1 || isset( $matches[ $appid ] ) ) {
				continue;
			}

			$matches[ $appid ] = $igdb_id;

			// AC-045: the positive row a later import re-uses.
			GameLib_Game_Store::set_steam_match( $appid, $igdb_id );
		}

		return array(
			'calls'   => $calls,
			'matches' => $matches,
		);
	}

	/**
	 * Fetch the matched games the shared store does not hold yet (AC-041a).
	 *
	 * One batched by-id request for the whole chunk. A re-import normally makes
	 * none at all: the games were stored the first time round.
	 *
	 * @param int[] $igdb_ids Matched game ids.
	 * @param array $args     Client args (`background`).
	 * @return array{calls:int,error?:WP_Error} Requests spent, and the failure
	 *         that ended the stage.
	 */
	private static function hydrate_games( array $igdb_ids, array $args ) {
		$missing = array();

		// One batched store read for the chunk, not one per matched id (PB-2).
		$known = GameLib_Game_Store::get_many( $igdb_ids );

		foreach ( array_unique( $igdb_ids ) as $igdb_id ) {
			$igdb_id = (int) $igdb_id;

			if ( ! isset( $known[ $igdb_id ] ) || ! is_array( $known[ $igdb_id ] ) ) {
				$missing[] = $igdb_id;
			}
		}

		if ( empty( $missing ) ) {
			return array( 'calls' => 0 );
		}

		$records = GameLib_IGDB_Client::get_games_by_ids( $missing, $args );

		if ( is_wp_error( $records ) ) {
			return array(
				'calls' => 1,
				'error' => $records,
			);
		}

		foreach ( $records as $record ) {
			if ( is_array( $record ) ) {
				GameLib_Game_Store::upsert_from_igdb( $record );
			}
		}

		return array( 'calls' => 1 );
	}

	/**
	 * Add one stage-1 match to the member's library (AC-041 a,d).
	 *
	 * The only auto-attaching path in the cascade, and the only one that reaches
	 * `GameLib_Library::add()` from a tick: the game is bucketed `matched`
	 * rather than `added` because AC-042(a) presents it as the auto-added
	 * bucket, and {@see finish()} counts both buckets toward N.
	 *
	 * @param int   $user_id  Import owner.
	 * @param array $item     Item row.
	 * @param int   $igdb_id  Matched game.
	 * @param array $existing Statuses the member already holds, keyed by IGDB id.
	 * @return void
	 */
	private static function attach_matched_item( $user_id, array $item, $igdb_id, array $existing ) {
		if ( isset( $existing[ $igdb_id ] ) ) {
			// AC-039(b), reached through AC-041(d): reported, never rewritten.
			self::set_bucket(
				array( $item['id'] ),
				self::BUCKET_DUPLICATE,
				array(
					'igdb_id' => $igdb_id,
					'note'    => sprintf(
						/* translators: %s: the library status the member already had for this game. */
						__( 'Already in your library as %s. Its status was left alone.', 'game-library' ),
						self::status_label( (string) $existing[ $igdb_id ] )
					),
				)
			);

			return;
		}

		$result = GameLib_Library::add(
			$user_id,
			$igdb_id,
			GameLib_Library::STATUS_DEFAULT,
			array(
				// The chunk hydrated the store already; a row never fetches for
				// itself.
				'hydrate'            => false,
				// Never Do #11: one aggregated event per import, at Finish.
				'record_event'       => false,
				/*
				 * The chunk's batched statuses_for() read has just proved this
				 * pair absent (the branch above returns when it has not), and the
				 * chunk bumps the member's scope once at the end (PB-7).
				 */
				'known_absent'       => true,
				'defer_invalidation' => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			self::set_bucket(
				array( $item['id'] ),
				self::BUCKET_INVALID,
				array( 'note' => $result->get_error_message() )
			);

			return;
		}

		if ( ! empty( $result['duplicate'] ) ) {
			self::set_bucket(
				array( $item['id'] ),
				self::BUCKET_DUPLICATE,
				array(
					'igdb_id' => $igdb_id,
					'note'    => sprintf(
						/* translators: %s: the library status the member already had for this game. */
						__( 'Already in your library as %s. Its status was left alone.', 'game-library' ),
						self::status_label( (string) $result['status'] )
					),
				)
			);

			return;
		}

		self::set_bucket(
			array( $item['id'] ),
			self::BUCKET_MATCHED,
			array(
				'igdb_id' => $igdb_id,
				'note'    => sprintf(
					/* translators: %s: the library status the game was added with. */
					__( 'Matched on Steam and added as %s.', 'game-library' ),
					self::status_label( GameLib_Library::STATUS_DEFAULT )
				),
			)
		);
	}

	/**
	 * Stages 2 and 3: turn unmatched rows into candidates (AC-041 b,c).
	 *
	 * Neither stage attaches anything. A row leaves here in `needs_choice` with
	 * up to five candidates for the member to confirm, or in `not_found` — and
	 * only the second kind writes the Steam map, because "IGDB has nothing like
	 * this" is a result worth remembering while "one of these five, probably" is
	 * not (AC-045).
	 *
	 * @param array[] $lookups Rows and their normalized names.
	 * @param array   $args    Client args (`background`).
	 * @return array{calls:int,error?:WP_Error} Requests spent, and the failure
	 *         that ended the stage.
	 */
	private static function resolve_by_name( array $lookups, array $args ) {
		if ( empty( $lookups ) ) {
			return array( 'calls' => 0 );
		}

		$names = array();

		foreach ( $lookups as $index => $lookup ) {
			$names[ $index ] = $lookup['name'];
		}

		$exact = self::exact_name_candidates( $names, $args );
		$calls = (int) $exact['calls'];

		if ( isset( $exact['error'] ) && GameLib_IGDB_Client::ERROR_MALFORMED !== (string) $exact['error']->get_error_code() ) {
			return array(
				'calls' => $calls,
				'error' => $exact['error'],
			);
		}

		/*
		 * A rejected multiquery is not fatal to the chunk: stage 3 finds the
		 * same exact match among its own candidates, so falling through costs
		 * requests rather than rows. Anything transient — rate limiting, an
		 * unreachable upstream — is returned above instead, because stage 3
		 * would fail the same way and the backoff ladder is what that needs.
		 */
		foreach ( $lookups as $index => $lookup ) {
			$item       = $lookup['item'];
			$candidates = isset( $exact['results'][ $index ] ) ? $exact['results'][ $index ] : array();

			if ( ! empty( $candidates ) ) {
				self::set_bucket(
					array( $item['id'] ),
					self::BUCKET_NEEDS_CHOICE,
					array(
						'candidates' => $candidates,
						'note'       => __( 'Matched by name — confirm this is the right game.', 'game-library' ),
					)
				);

				continue;
			}

			// AC-041(c), quoting the spec verbatim: `search "{name}"; where
			// version_parent = null; limit 5;` — GameLib_IGDB_Client::search()
			// composes a body with those exact where/limit clauses (plus a
			// `fields` list this AC doesn't mention).
			$results = GameLib_IGDB_Client::search(
				$lookup['name'],
				array_merge( $args, array( 'limit' => self::CANDIDATE_LIMIT ) )
			);
			++$calls;

			if ( is_wp_error( $results ) ) {
				return array(
					'calls' => $calls,
					'error' => $results,
				);
			}

			$candidates = self::candidates_from_rows( $results );

			if ( empty( $candidates ) ) {
				if ( $item['steam_appid'] > 0 ) {
					// AC-045(b): the negative row that ends the cascade for this
					// appid, for this member and every other one.
					GameLib_Game_Store::set_steam_match( $item['steam_appid'], null );
				}

				self::set_bucket(
					array( $item['id'] ),
					self::BUCKET_NOT_FOUND,
					array( 'note' => __( 'IGDB has no game matching this Steam title.', 'game-library' ) )
				);

				continue;
			}

			self::set_bucket(
				array( $item['id'] ),
				self::BUCKET_NEEDS_CHOICE,
				array(
					'candidates' => $candidates,
					'note'       => __( 'No exact match — pick the right game.', 'game-library' ),
				)
			);
		}

		return array( 'calls' => $calls );
	}

	/**
	 * Stage 2: case-insensitive exact-name lookup, batched (AC-041b).
	 *
	 * `name ~ "…"` is IGDB's case-insensitive equality operator, and
	 * `/v4/multiquery` carries ten of those sub-queries per request — so twenty
	 * unmatched titles cost two requests rather than twenty.
	 *
	 * @param array<int,string> $names Normalized names, keyed by lookup index.
	 * @param array             $args  Client args (`background`).
	 * @return array{calls:int,results:array<int,array[]>,error?:WP_Error}
	 *         Requests spent, candidates per lookup index, and the failure that
	 *         ended the stage.
	 */
	private static function exact_name_candidates( array $names, array $args ) {
		$results = array();
		$calls   = 0;

		foreach ( array_keys( $names ) as $index ) {
			$results[ $index ] = array();
		}

		foreach ( array_chunk( $names, self::NAME_QUERY_BATCH, true ) as $batch ) {
			$body = '';

			foreach ( $batch as $index => $name ) {
				$body .= sprintf(
					'query games "q%1$d" { fields id,name,first_release_date; where name ~ "%2$s" & version_parent = null; limit %3$d; };',
					(int) $index,
					GameLib_IGDB_Client::escape_string( $name ),
					self::CANDIDATE_LIMIT
				);
			}

			$response = GameLib_IGDB_Client::request( 'multiquery', $body, $args );
			++$calls;

			if ( is_wp_error( $response ) ) {
				return array(
					'calls'   => $calls,
					'results' => $results,
					'error'   => $response,
				);
			}

			foreach ( $response as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['name'], $row['result'] ) || ! is_array( $row['result'] ) ) {
					continue;
				}

				// The sub-query name is the only thing tying a result set back
				// to the row that asked for it.
				if ( ! is_scalar( $row['name'] ) || ! preg_match( '/^q(\d+)$/', (string) $row['name'], $named ) ) {
					continue;
				}

				$index = (int) $named[1];

				if ( isset( $results[ $index ] ) ) {
					$results[ $index ] = self::candidates_from_rows( $row['result'] );
				}
			}
		}

		return array(
			'calls'   => $calls,
			'results' => $results,
		);
	}

	/**
	 * Turn IGDB game rows into the stored candidate shape (§6 Data Model).
	 *
	 * `[{igdb_id,name,year}]` — the year comes from `first_release_date`,
	 * because two candidates with the same name are told apart by nothing else.
	 *
	 * @param array $rows IGDB game rows.
	 * @return array[] At most {@see CANDIDATE_LIMIT} candidates.
	 */
	private static function candidates_from_rows( array $rows ) {
		$candidates = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['name'] ) || ! is_scalar( $row['name'] ) ) {
				continue;
			}

			$igdb_id = absint( $row['id'] );
			$name    = sanitize_text_field( (string) $row['name'] );

			if ( $igdb_id < 1 || '' === $name ) {
				continue;
			}

			$released = ( isset( $row['first_release_date'] ) && is_numeric( $row['first_release_date'] ) )
				? absint( $row['first_release_date'] )
				: 0;

			$candidates[] = array(
				'igdb_id' => $igdb_id,
				'name'    => $name,
				'year'    => ( $released > 0 ) ? (int) gmdate( 'Y', $released ) : 0,
			);

			if ( count( $candidates ) >= self::CANDIDATE_LIMIT ) {
				break;
			}
		}

		return $candidates;
	}

	/**
	 * Normalize a Steam title for name matching (AC-041b).
	 *
	 * Steam and IGDB spell the same game differently often enough that a raw
	 * comparison is close to useless: trademark marks, `&` against `and`,
	 * subtitle punctuation, and edition suffixes all differ. This reduces both
	 * sides to the same shape — lower case, letters and digits separated by
	 * single spaces, no edition tail.
	 *
	 * Public so the review screen can compare a manual search result the same
	 * way the cascade did.
	 *
	 * @param string $name Raw title.
	 * @return string Normalized title, or '' when nothing usable is left.
	 */
	public static function normalize_title( $name ) {
		$name = is_scalar( $name ) ? (string) $name : '';

		// Trademark furniture Steam titles carry and IGDB's mostly do not.
		$name = str_replace( array( '™', '®', '©', '℠', '℗' ), ' ', $name );

		/*
		 * `&` becomes a word before the punctuation pass can delete it: to
		 * everyone but a string comparison, "Rick & Morty" and "Rick and Morty"
		 * are the same title.
		 */
		$name = str_replace( array( '&', '＆' ), ' and ', $name );

		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );

		// Everything that is not a letter or a digit — colons, hyphens,
		// apostrophes, the lot — collapses to a single space.
		$collapsed = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $name );

		if ( ! is_string( $collapsed ) ) {
			return '';
		}

		return self::drop_edition_suffix( trim( $collapsed ) );
	}

	/**
	 * Drop trailing edition qualifiers from an already-normalized title.
	 *
	 * Repeated because they stack: "the witcher 3 wild hunt game of the year
	 * edition" sheds "edition" and its qualifier together, and a title that
	 * would be emptied by the pass keeps what it had.
	 *
	 * @param string $name Normalized title.
	 * @return string Title without its edition tail.
	 */
	private static function drop_edition_suffix( $name ) {
		$pattern = '/\s+(?:the\s+)?(?:' . implode( '|', self::EDITION_WORDS ) . ')(?:\s+(?:edition|collection|bundle|version))?$/u';

		for ( $pass = 0; $pass < 3; $pass++ ) {
			$stripped = preg_replace( $pattern, '', $name );

			if ( ! is_string( $stripped ) || '' === $stripped || $stripped === $name ) {
				break;
			}

			$name = $stripped;
		}

		return $name;
	}

	/**
	 * Persist a failed chunk and decide what happens next (AC-043c).
	 *
	 * Attempts one and two schedule a retry of the *same* chunk after the
	 * persisted backoff — its rows are still `pending`, so the next tick picks
	 * up exactly them. The third failure abandons the chunk: its rows are moved
	 * to `invalid` with the upstream failure recorded per row, and the job
	 * continues with the next chunk after a cooldown. A failure that retrying
	 * cannot fix (no credentials, a rejected query) skips the ladder and
	 * abandons the chunk immediately.
	 *
	 * @param array    $import Job row.
	 * @param array[]  $items  The chunk that failed.
	 * @param WP_Error $error  Why it failed.
	 * @return void
	 */
	private static function record_chunk_failure( array $import, array $items, WP_Error $error ) {
		$code      = (string) $error->get_error_code();
		$retryable = in_array( $code, self::RETRYABLE, true );
		$attempts  = $retryable ? min( self::MAX_ATTEMPTS, $import['attempts'] + 1 ) : self::MAX_ATTEMPTS;

		if ( $retryable && $attempts < self::MAX_ATTEMPTS ) {
			$next = time() + self::BACKOFF[ $attempts - 1 ];

			self::update_row(
				$import['id'],
				array(
					'status'          => self::STATUS_QUEUED,
					'attempts'        => $attempts,
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $next ),
					'error'           => $code,
				),
				array( '%s', '%d', '%s', '%s' )
			);

			self::schedule_tick( $import['id'], $next );

			return;
		}

		$item_ids = array();

		foreach ( $items as $item ) {
			$item_ids[] = $item['id'];
		}

		/*
		 * Only what is still unresolved: the Steam cascade resolves rows stage
		 * by stage, so a chunk that failed at stage 3 has rows already bucketed
		 * `matched` or `needs_choice`, and abandoning the chunk must not undo
		 * them.
		 */
		self::set_bucket(
			$item_ids,
			self::BUCKET_INVALID,
			array(
				'from' => self::BUCKET_PENDING,
				'note' => self::failure_note( $error ),
			)
		);

		$next = $retryable ? ( time() + self::BACKOFF[ self::MAX_ATTEMPTS - 1 ] ) : time();

		self::update_row(
			$import['id'],
			array(
				'status'          => self::STATUS_QUEUED,
				'cursor'          => $import['cursor'] + count( $items ),
				'attempts'        => 0,
				'next_attempt_at' => $retryable ? gmdate( 'Y-m-d H:i:s', $next ) : null,
				'error'           => $code,
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);

		self::refresh_counts( $import['id'] );
		self::schedule_tick( $import['id'], $next );
	}

	/**
	 * Close a job whose rows are all resolved.
	 *
	 * A file import is finished the moment its last row is bucketed, and that
	 * is when its single `games_imported` event fires (AC-044a). A Steam import
	 * stops at `review` instead: its event belongs to the member pressing
	 * Finish, because auto-attached games and review picks are one import and
	 * therefore one event (AC-044 a,b).
	 *
	 * The completing UPDATE is conditional on the status this tick claimed, so
	 * exactly one caller can ever transition the row — and therefore exactly
	 * one event can ever be recorded, even if two ticks somehow both reach
	 * here. Zero-added imports record none at all (AC-044c).
	 *
	 * @param array $import Job row held by this tick.
	 * @return void
	 */
	private static function conclude( array $import ) {
		$counts = self::refresh_counts( $import['id'] );
		$status = ( self::SOURCE_STEAM === $import['source'] ) ? self::STATUS_REVIEW : self::STATUS_COMPLETED;

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->update() prepares its own statement. The WHERE carries the claimed status, which is what makes the transition — and the event below — happen exactly once.
		$closed = $wpdb->update(
			GameLib_Schema::table( self::TABLE ),
			array(
				'status'          => $status,
				'attempts'        => 0,
				'next_attempt_at' => null,
				'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'     => $import['id'],
				'status' => self::STATUS_PROCESSING,
			),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( 1 !== (int) $closed || self::STATUS_COMPLETED !== $status ) {
			return;
		}

		$added = self::added_total( $counts );

		if ( $added > 0 ) {
			GameLib_Activity::record(
				$import['user_id'],
				GameLib_Activity::TYPE_GAMES_IMPORTED,
				0,
				array( 'count' => $added )
			);
		}
	}

	/**
	 * Claim a queued job for this tick (AC-043e).
	 *
	 * `queued → processing` is a real change of value, so the affected-row
	 * count answers "did I win?" identically on MySQL and on Playground's
	 * SQLite driver — unlike a no-op UPDATE, whose row count the two disagree
	 * about. The `cursor` in the WHERE clause is the second half of the guard:
	 * a job that moved between the read and the claim is not the job this tick
	 * planned to advance.
	 *
	 * @param int $import_id Import id.
	 * @param int $cursor    Cursor this tick read.
	 * @return bool True when this tick owns the job.
	 */
	private static function claim( $import_id, $cursor ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->update() prepares its own statement (and quotes `cursor`, a MySQL 8 reserved word, itself). A claim is a write and is never cached — Never Do #15 forbids the cache-based lock this replaces.
		$claimed = $wpdb->update(
			GameLib_Schema::table( self::TABLE ),
			array(
				'status'     => self::STATUS_PROCESSING,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'     => $import_id,
				'status' => self::STATUS_QUEUED,
				'cursor' => $cursor,
			),
			array( '%s', '%s' ),
			array( '%d', '%s', '%d' )
		);

		return 1 === (int) $claimed;
	}

	/**
	 * Record progress mid-slice: the job stays claimed.
	 *
	 * @param int $import_id Import id.
	 * @param int $cursor    Rows resolved so far.
	 * @return void
	 */
	private static function advance( $import_id, $cursor ) {
		/*
		 * The cursor only — the stored counts are refreshed at tick boundaries
		 * (PB-6). count_buckets() aggregates every item row of the import, so
		 * recomputing it after each 25-row chunk meant 400 growing aggregates
		 * for a full-cap job to move a number by at most 25. The `updated_at`
		 * stamp the AC-043(d) stall check reads is written here either way.
		 */
		self::update_row(
			$import_id,
			array(
				'cursor'   => $cursor,
				'attempts' => 0,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Hand a job back so the next tick can claim it.
	 *
	 * @param int $import_id Import id.
	 * @param int $cursor    Rows resolved so far.
	 * @return void
	 */
	private static function release( $import_id, $cursor ) {
		// One aggregate per tick, so the poller sees this slice's whole progress
		// the moment the job goes back to `queued` (PB-6).
		self::refresh_counts( $import_id );

		self::update_row(
			$import_id,
			array(
				'status'   => self::STATUS_QUEUED,
				'cursor'   => $cursor,
				'attempts' => 0,
			),
			array( '%s', '%d', '%d' )
		);
	}

	/**
	 * Write a set of columns onto a job row, always stamping `updated_at`.
	 *
	 * `updated_at` is what the AC-043(d) stall check reads, so every write goes
	 * through here rather than touching the table directly.
	 *
	 * @param int   $import_id Import id.
	 * @param array $data      Column => value.
	 * @param array $formats   `$wpdb` formats matching `$data`, in order.
	 * @return void
	 */
	private static function update_row( $import_id, array $data, array $formats ) {
		global $wpdb;

		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$formats[]          = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; $wpdb->update() prepares its own statement, and the job row is never cached (see read_row()).
		$wpdb->update(
			GameLib_Schema::table( self::TABLE ),
			$data,
			array( 'id' => absint( $import_id ) ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * The next rows waiting to be resolved.
	 *
	 * A resolved row leaves the `pending` bucket, so this always returns the
	 * head of the remaining work — which is what makes an interrupted job
	 * resumable without replaying anything.
	 *
	 * @param int $import_id Import id.
	 * @param int $limit     Rows to read.
	 * @return array[] Item rows.
	 */
	private static function pending_items( $import_id, $limit ) {
		return self::items( $import_id, self::BUCKET_PENDING, $limit );
	}

	/**
	 * Read one job row from the table.
	 *
	 * Deliberately uncached, on both the tick and the poll path. A job row is
	 * short-lived state that changes every few seconds and is read once per
	 * request; the plugin's cache floor is fifteen minutes
	 * ({@see GameLib_Cache::MIN_TTL}), which is longer than most imports live,
	 * so a cached copy would report progress that had already been superseded —
	 * and on the tick path it is a write-path read whose answer decides the
	 * very next statement.
	 *
	 * @phpstan-impure
	 *
	 * @param int $import_id Import id.
	 * @return array|null Hydrated row, or null.
	 */
	private static function read_row( $import_id ) {
		if ( $import_id < 1 ) {
			return null;
		}

		global $wpdb;

		$table = GameLib_Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table has no core API; see the docblock for why a job row is read live rather than through GameLib_Cache.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is GameLib_Schema::table(); the one value is a bound placeholder. `cursor` is back-quoted because it is a reserved word on MySQL 8.
				"SELECT id, user_id, source, status, `cursor`, attempts, next_attempt_at, counts, error, created_at, updated_at FROM {$table} WHERE id = %d",
				$import_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Turn one raw job row into the shape callers consume.
	 *
	 * @param array $row Raw row.
	 * @return array<string, mixed> Hydrated row.
	 */
	private static function hydrate( array $row ) {
		$counts = isset( $row['counts'] ) ? json_decode( (string) $row['counts'], true ) : null;
		$next   = isset( $row['next_attempt_at'] ) ? (string) $row['next_attempt_at'] : '';
		$next   = ( '' === $next ) ? 0 : strtotime( $next . ' UTC' );

		return array(
			'id'              => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'user_id'         => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'source'          => isset( $row['source'] ) ? (string) $row['source'] : '',
			'status'          => isset( $row['status'] ) ? (string) $row['status'] : '',
			'cursor'          => isset( $row['cursor'] ) ? (int) $row['cursor'] : 0,
			'attempts'        => isset( $row['attempts'] ) ? (int) $row['attempts'] : 0,
			'next_attempt_at' => ( false === $next ) ? 0 : (int) $next,
			'counts'          => self::normalize_counts( is_array( $counts ) ? $counts : array() ),
			'error'           => isset( $row['error'] ) ? sanitize_key( (string) $row['error'] ) : '',
			'created_at'      => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			'updated_at'      => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '',
		);
	}

	/**
	 * Turn one raw item row into the shape callers consume.
	 *
	 * The row's own `source_name` is the display name everywhere: for a file
	 * import it is the title the member's own export wrote, and for a Steam
	 * import it is the Steam name AC-042 (b),(c) asks for. Nothing here reads
	 * the game store, so a hundred-row bucket costs no per-row query.
	 *
	 * @param array $row Raw row.
	 * @return array<string, mixed> Hydrated item.
	 */
	private static function hydrate_item( array $row ) {
		$payload = isset( $row['candidates'] ) ? json_decode( (string) $row['candidates'], true ) : null;
		$payload = is_array( $payload ) ? $payload : array();

		return array(
			'id'          => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'name'        => isset( $row['source_name'] ) ? (string) $row['source_name'] : '',
			'steam_appid' => isset( $row['steam_appid'] ) ? (int) $row['steam_appid'] : 0,
			'igdb_id'     => isset( $row['igdb_id'] ) ? (int) $row['igdb_id'] : 0,
			'bucket'      => isset( $row['bucket'] ) ? (string) $row['bucket'] : '',
			'note'        => isset( $row['note'] ) ? (string) $row['note'] : '',
			// One JSON column, two shapes: a list of candidates for a Steam row
			// awaiting a decision, and the parsed file row (`status`,
			// `date_added`, `line`) for a file row. Both are written and read
			// only here. See principal/adr/015-import-item-payload-column.md.
			'candidates'  => self::candidate_list( $payload ),
			'payload'     => self::is_candidate_list( $payload ) ? array() : $payload,
		);
	}

	/**
	 * The candidate list of an item payload, or an empty list.
	 *
	 * @param array $payload Decoded `candidates` column.
	 * @return array[] Candidates.
	 */
	private static function candidate_list( array $payload ) {
		return self::is_candidate_list( $payload ) ? $payload : array();
	}

	/**
	 * Is this payload the Steam candidate list rather than a parsed file row?
	 *
	 * @param array $payload Decoded `candidates` column.
	 * @return bool True for a list of candidate objects.
	 */
	private static function is_candidate_list( array $payload ) {
		if ( empty( $payload ) ) {
			return false;
		}

		return array_keys( $payload ) === range( 0, count( $payload ) - 1 );
	}

	/**
	 * Validate an upload and parse it into rows (AC-038 a–c).
	 *
	 * @param array $file One entry of `WP_REST_Request::get_file_params()`.
	 * @return array{source:string,rows:array[]}|WP_Error Parsed file, or why it
	 *         was refused.
	 */
	private static function parse_upload( array $file ) {
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return self::too_large_error();
		}

		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error(
				self::ERROR_UPLOAD,
				__( 'That file did not upload. Please choose it again.', 'game-library' ),
				array(
					'status' => 400,
					'state'  => 'upload_failed',
				)
			);
		}

		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

		/*
		 * The upload's own temporary file, written by PHP before this request
		 * ran. The plugin reads it and never writes anywhere (Never Do #3), and
		 * the path is confirmed to be a genuine upload rather than an arbitrary
		 * local path before anything opens it.
		 */
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return new WP_Error(
				self::ERROR_UPLOAD,
				__( 'That file did not upload. Please choose it again.', 'game-library' ),
				array(
					'status' => 400,
					'state'  => 'upload_failed',
				)
			);
		}

		// AC-038(c): measured before the file is read, not after.
		if ( isset( $file['size'] ) && (int) $file['size'] > GAMELIB_IMPORT_MAX_BYTES ) {
			return self::too_large_error();
		}

		$name   = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$source = self::source_from_filename( $name );

		if ( '' === $source ) {
			return self::format_error(
				__( 'Import files must be the CSV or JSON file this site exports.', 'game-library' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local upload temp file confirmed by is_uploaded_file() above, never a remote URL (Never Do #4); its size is capped at GAMELIB_IMPORT_MAX_BYTES on both sides of this call.
		$contents = file_get_contents( $tmp_name );

		if ( ! is_string( $contents ) ) {
			return new WP_Error(
				self::ERROR_UPLOAD,
				__( 'That file could not be read. Please try again.', 'game-library' ),
				array(
					'status' => 400,
					'state'  => 'upload_failed',
				)
			);
		}

		if ( strlen( $contents ) > GAMELIB_IMPORT_MAX_BYTES ) {
			return self::too_large_error();
		}

		$rows = ( self::SOURCE_JSON === $source )
			? self::parse_json( $contents )
			: self::parse_csv( $contents );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		// AC-038(b): measured after parsing, because the cap is on rows.
		if ( count( $rows ) > GAMELIB_IMPORT_MAX_ROWS ) {
			return new WP_Error(
				self::ERROR_TOO_MANY_ROWS,
				sprintf(
					/* translators: %s: maximum number of rows one import may carry. */
					_n(
						'Import files can hold at most %s row. Split the file and import it in parts.',
						'Import files can hold at most %s rows. Split the file and import it in parts.',
						GAMELIB_IMPORT_MAX_ROWS,
						'game-library'
					),
					number_format_i18n( GAMELIB_IMPORT_MAX_ROWS )
				),
				array(
					'status' => 400,
					'state'  => 'too_many_rows',
				)
			);
		}

		if ( empty( $rows ) ) {
			return self::format_error( __( 'That file has no rows to import.', 'game-library' ) );
		}

		return array(
			'source' => $source,
			'rows'   => $rows,
		);
	}

	/**
	 * The import source an uploaded filename names, or ''.
	 *
	 * The extension is checked through `wp_check_filetype()` against an
	 * explicit two-entry map rather than the site's allowed-mime list, which
	 * carries neither of these types by default.
	 *
	 * @param string $name Sanitized filename.
	 * @return string One of {@see GameLib_Importer::FILE_SOURCES}, or ''.
	 */
	private static function source_from_filename( $name ) {
		$type = wp_check_filetype(
			$name,
			array(
				'csv'  => 'text/csv',
				'json' => 'application/json',
			)
		);

		$ext = isset( $type['ext'] ) ? (string) $type['ext'] : '';

		return in_array( $ext, self::FILE_SOURCES, true ) ? $ext : '';
	}

	/**
	 * Parse the plugin's JSON export into rows (AC-038a).
	 *
	 * @param string $contents File contents.
	 * @return array[]|WP_Error Normalized rows, or a format error.
	 */
	private static function parse_json( $contents ) {
		$data = json_decode( $contents, true );

		if ( ! is_array( $data ) || ( ! empty( $data ) && array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) ) {
			return self::format_error(
				__( 'That JSON file is not a list of library entries. Export your library to see the expected shape.', 'game-library' )
			);
		}

		$rows = array();
		$line = 0;

		foreach ( $data as $entry ) {
			++$line;

			if ( ! is_array( $entry ) ) {
				$rows[] = self::row( $line, 0, '', '', '', __( 'This entry is not a library entry object.', 'game-library' ) );

				continue;
			}

			$rows[] = self::normalize_row(
				$line,
				isset( $entry['igdb_id'] ) ? $entry['igdb_id'] : '',
				isset( $entry['title'] ) ? $entry['title'] : '',
				isset( $entry['status'] ) ? $entry['status'] : '',
				isset( $entry['date_added'] ) ? $entry['date_added'] : ''
			);
		}

		return $rows;
	}

	/**
	 * Parse the plugin's CSV export into rows (AC-038a).
	 *
	 * The header decides which column is which, so a file whose columns were
	 * reordered still imports; a file with no `igdb_id` column is a format
	 * error, because there is nothing in it to import.
	 *
	 * @param string $contents File contents.
	 * @return array[]|WP_Error Normalized rows, or a format error.
	 */
	private static function parse_csv( $contents ) {
		$records = self::csv_records( $contents );

		if ( empty( $records ) ) {
			return self::format_error( __( 'That CSV file is empty.', 'game-library' ) );
		}

		$header = array_shift( $records );
		$map    = array();

		foreach ( $header['fields'] as $index => $label ) {
			$key = sanitize_key( trim( (string) $label ) );

			if ( in_array( $key, GameLib_Exporter::FIELDS, true ) ) {
				$map[ $key ] = $index;
			}
		}

		if ( ! isset( $map['igdb_id'] ) ) {
			return self::format_error(
				__( 'That CSV file has no igdb_id column. Import the CSV this site exports.', 'game-library' )
			);
		}

		$rows = array();

		foreach ( $records as $record ) {
			$rows[] = self::normalize_row(
				$record['line'],
				self::cell( $record['fields'], $map, 'igdb_id' ),
				self::cell( $record['fields'], $map, 'title' ),
				self::cell( $record['fields'], $map, 'status' ),
				self::cell( $record['fields'], $map, 'date_added' )
			);
		}

		return $rows;
	}

	/**
	 * One mapped cell of a CSV record.
	 *
	 * @param array<int, string>    $fields Record fields.
	 * @param array<string, int>    $map    Field name => column index.
	 * @param string                $key    Field name.
	 * @return string Cell value, or ''.
	 */
	private static function cell( array $fields, array $map, $key ) {
		if ( ! isset( $map[ $key ] ) || ! isset( $fields[ $map[ $key ] ] ) ) {
			return '';
		}

		return (string) $fields[ $map[ $key ] ];
	}

	/**
	 * Split a CSV document into records.
	 *
	 * Hand-rolled on purpose: `str_getcsv()` works a line at a time and so
	 * breaks on a quoted field containing a newline, and its `escape` parameter
	 * is deprecated as of PHP 8.4. This scanner handles the two things the
	 * format actually requires — `""` as an escaped quote inside a quoted
	 * field, and CR, LF, or CRLF as a record separator — and blank lines are
	 * dropped while still counting toward the line number a member sees in
	 * their file (AC-038d reports row numbers).
	 *
	 * @param string $contents File contents.
	 * @return array<int, array{line:int,fields:array<int,string>}> Records.
	 */
	private static function csv_records( $contents ) {
		// A UTF-8 BOM would otherwise become part of the first header cell.
		$contents = preg_replace( '/^\xEF\xBB\xBF/', '', $contents );
		$length   = strlen( $contents );

		$records = array();
		$fields  = array();
		$field   = '';
		$quoted  = false;
		$line    = 1;
		$index   = 0;

		while ( $index < $length ) {
			$char = $contents[ $index ];

			if ( $quoted ) {
				if ( '"' === $char ) {
					if ( $index + 1 < $length && '"' === $contents[ $index + 1 ] ) {
						$field .= '"';
						++$index;
					} else {
						$quoted = false;
					}
				} else {
					$field .= $char;
				}

				++$index;

				continue;
			}

			if ( '"' === $char && '' === $field ) {
				$quoted = true;
				++$index;

				continue;
			}

			if ( ',' === $char ) {
				$fields[] = $field;
				$field    = '';
				++$index;

				continue;
			}

			if ( "\n" === $char || "\r" === $char ) {
				if ( "\r" === $char && $index + 1 < $length && "\n" === $contents[ $index + 1 ] ) {
					++$index;
				}

				$fields[] = $field;
				$field    = '';

				self::push_record( $records, $fields, $line );

				$fields = array();
				++$line;
				++$index;

				continue;
			}

			$field .= $char;
			++$index;
		}

		if ( '' !== $field || ! empty( $fields ) ) {
			$fields[] = $field;

			self::push_record( $records, $fields, $line );
		}

		return $records;
	}

	/**
	 * Append a CSV record unless it is a blank line.
	 *
	 * @param array $records Records collected so far, by reference.
	 * @param array $fields  The record's fields.
	 * @param int   $line    1-based line number in the file.
	 * @return void
	 */
	private static function push_record( array &$records, array $fields, $line ) {
		if ( 1 === count( $fields ) && '' === trim( (string) $fields[0] ) ) {
			return;
		}

		$records[] = array(
			'line'   => (int) $line,
			'fields' => $fields,
		);
	}

	/**
	 * Validate one parsed row (AC-038d).
	 *
	 * A row is refused for exactly two reasons — an `igdb_id` that is not a
	 * positive number, and a `status` that is not one of the four — and the
	 * refusal is per row: everything else in the file still imports. An absent
	 * status is not a refusal; it becomes the import default.
	 *
	 * @param int    $line       1-based row number in the file.
	 * @param mixed  $igdb_id    Raw id.
	 * @param mixed  $title      Raw title.
	 * @param mixed  $status     Raw status.
	 * @param mixed  $date_added Raw date.
	 * @return array{line:int,igdb_id:int,title:string,status:string,date_added:string,error:string} Row.
	 */
	private static function normalize_row( $line, $igdb_id, $title, $status, $date_added ) {
		$raw_id = is_scalar( $igdb_id ) ? trim( (string) $igdb_id ) : '';
		$title  = is_scalar( $title ) ? sanitize_text_field( (string) $title ) : '';
		$raw    = is_scalar( $status ) ? trim( (string) $status ) : '';
		$date   = self::normalize_date( $date_added );

		if ( '' === $raw_id || ! ctype_digit( $raw_id ) || (int) $raw_id < 1 ) {
			return self::row(
				$line,
				0,
				$title,
				'',
				$date,
				__( 'igdb_id must be a whole number.', 'game-library' )
			);
		}

		$clean = ( '' === $raw ) ? GameLib_Library::STATUS_DEFAULT : GameLib_Library::sanitize_status( $raw );

		if ( '' === $clean ) {
			return self::row(
				$line,
				(int) $raw_id,
				$title,
				'',
				$date,
				sprintf(
					/* translators: %s: the status value found in the file. */
					__( '“%s” is not Playing, Finished, Backlog, or Wishlist.', 'game-library' ),
					$raw
				)
			);
		}

		return self::row( $line, (int) $raw_id, $title, $clean, $date, '' );
	}

	/**
	 * The row shape the rest of this class consumes.
	 *
	 * @param int    $line       1-based row number in the file.
	 * @param int    $igdb_id    IGDB id, 0 when unusable.
	 * @param string $title      Title as the file spelled it.
	 * @param string $status     Whitelisted status, '' when unusable.
	 * @param string $date_added UTC `Y-m-d H:i:s`, '' when the file had none.
	 * @param string $error      Why the row is invalid, '' when it is fine.
	 * @return array{line:int,igdb_id:int,title:string,status:string,date_added:string,error:string} Row.
	 */
	private static function row( $line, $igdb_id, $title, $status, $date_added, $error ) {
		return array(
			'line'       => (int) $line,
			'igdb_id'    => (int) $igdb_id,
			'title'      => (string) $title,
			'status'     => (string) $status,
			'date_added' => (string) $date_added,
			'error'      => (string) $error,
		);
	}

	/**
	 * A file's `date_added` as a UTC timestamp string, or '' (AC-039d).
	 *
	 * The exporter writes calendar dates (`Y-m-d`); a hand-made file may carry
	 * a full timestamp. Anything else — including a date in the future, which
	 * would put an entry above everything the member has actually added — is
	 * discarded, and the entry gets the time it was imported instead.
	 *
	 * @param mixed $value Raw value.
	 * @return string UTC `Y-m-d H:i:s`, or ''.
	 */
	private static function normalize_date( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value || ! preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $value ) ) {
			return '';
		}

		$timestamp = strtotime( $value . ' UTC' );

		if ( false === $timestamp || $timestamp > time() ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Turn the member's input into a SteamID64 (AC-040a).
	 *
	 * Seventeen digits is a SteamID64 and is accepted as one. Anything else is
	 * treated as the vanity name of `steamcommunity.com/id/{name}` and resolved
	 * upstream — except a value that is *all* digits, which was plainly meant to
	 * be an id and is refused against the 17-digit rule rather than spent on a
	 * lookup that cannot succeed.
	 *
	 * @param mixed $account Raw member input.
	 * @return string|WP_Error SteamID64, or why it could not be resolved.
	 */
	private static function resolve_steam_account( $account ) {
		$account = is_scalar( $account ) ? trim( sanitize_text_field( (string) $account ) ) : '';

		if ( '' === $account ) {
			return new WP_Error(
				self::ERROR_STEAM_ACCOUNT,
				__( 'Enter your SteamID64 or your Steam profile name.', 'game-library' ),
				array(
					'status' => 400,
					'state'  => 'steam_account_missing',
				)
			);
		}

		$steamid = GameLib_Steam_Client::sanitize_steamid64( $account );

		if ( '' !== $steamid ) {
			return $steamid;
		}

		if ( ctype_digit( $account ) ) {
			return new WP_Error(
				self::ERROR_STEAM_ACCOUNT,
				__( 'A SteamID64 is exactly 17 digits. Check the number, or enter your Steam profile name instead.', 'game-library' ),
				array(
					'status' => 400,
					'state'  => 'steam_account_invalid',
				)
			);
		}

		$resolved = GameLib_Steam_Client::resolve_vanity( $account );

		if ( is_wp_error( $resolved ) ) {
			return self::steam_error( $resolved );
		}

		return $resolved;
	}

	/**
	 * Give a Steam client failure an HTTP status and a member-facing message.
	 *
	 * The client's taxonomy carries no status of its own, and two of its terms
	 * carry copy the ACs pin word for word — "We couldn't find that Steam
	 * profile." (AC-040a) and the sentence naming Profile → Privacy Settings →
	 * Game details → Public (AC-040b) — so those pass through untouched. The
	 * rest collapse into one sentence: whether the site has no key, Steam timed
	 * out, or Steam answered nonsense is an operator's question, and the answer
	 * is on the settings screen, not in a member's import dialog.
	 *
	 * @param WP_Error $error Failure from {@see GameLib_Steam_Client}.
	 * @return WP_Error Typed refusal carrying `status` and `state`.
	 */
	private static function steam_error( WP_Error $error ) {
		$code = (string) $error->get_error_code();

		switch ( $code ) {
			case GameLib_Steam_Client::ERROR_NOT_FOUND:
			case GameLib_Steam_Client::ERROR_PRIVATE_PROFILE:
				$status  = 400;
				$message = $error->get_error_message();
				break;

			case GameLib_Steam_Client::ERROR_RATE_LIMITED:
				$status  = 429;
				$message = __( 'Steam is busy right now. Try the import again in a moment.', 'game-library' );
				break;

			default:
				$status  = 503;
				$message = __( 'Steam could not be reached right now. Please try again later.', 'game-library' );
				break;
		}

		return new WP_Error(
			self::ERROR_STEAM,
			$message,
			array(
				'status' => $status,
				'state'  => sanitize_key( $code ),
			)
		);
	}

	/**
	 * Turn an owned-games list into item records (AC-040e).
	 *
	 * Every row starts `pending` with its appid and Steam name and no IGDB id:
	 * the cascade is what fills that in, and it runs in a cron tick, never here.
	 *
	 * @param array[] $owned Rows from {@see GameLib_Steam_Client::get_owned_games()}.
	 * @return array[] Item records ready for {@see insert_items()}.
	 */
	private static function items_from_owned( array $owned ) {
		$items = array();

		foreach ( $owned as $game ) {
			if ( ! is_array( $game ) ) {
				continue;
			}

			$appid = isset( $game['appid'] ) ? absint( $game['appid'] ) : 0;

			if ( $appid < 1 ) {
				continue;
			}

			$items[] = array(
				'source_name' => isset( $game['name'] ) ? (string) $game['name'] : '',
				'steam_appid' => $appid,
				'igdb_id'     => null,
				'bucket'      => self::BUCKET_PENDING,
				'candidates'  => null,
				'note'        => null,
			);
		}

		return $items;
	}

	/**
	 * Turn parsed rows into item records (AC-038 d,e).
	 *
	 * @param array[] $rows Normalized rows.
	 * @return array[] Item records ready for {@see insert_items()}.
	 */
	private static function items_from_rows( array $rows ) {
		$items = array();

		foreach ( $rows as $row ) {
			$invalid = ( '' !== $row['error'] );

			$items[] = array(
				'source_name' => $row['title'],
				'steam_appid' => null,
				'igdb_id'     => ( $row['igdb_id'] > 0 ) ? $row['igdb_id'] : null,
				'bucket'      => $invalid ? self::BUCKET_INVALID : self::BUCKET_PENDING,
				'candidates'  => wp_json_encode(
					array(
						'line'       => $row['line'],
						'status'     => $row['status'],
						'date_added' => $row['date_added'],
					)
				),
				// AC-038(d): the member is told which row, by number, and why.
				'note'        => $invalid
					? sprintf(
						/* translators: 1: row number in the uploaded file, 2: what is wrong with it. */
						__( 'Row %1$d: %2$s', 'game-library' ),
						$row['line'],
						$row['error']
					)
					: null,
			);
		}

		return $items;
	}

	/**
	 * Write item rows in multi-row batches.
	 *
	 * A batched INSERT rather than one `$wpdb->insert()` per row: a 10,000-row
	 * upload is 100 statements instead of 10,000. `$wpdb->prepare()` cannot
	 * bind a real `NULL` — it casts one to `0` for `%d` and `''` for `%s` — so
	 * an absent appid, id, payload, or note contributes the literal keyword to
	 * the tuple instead of a placeholder. Which columns those are is decided
	 * here, in code; no caller-supplied value ever reaches the SQL string.
	 *
	 * @param int     $import_id Import the rows belong to.
	 * @param array[] $items     Item records.
	 * @return int Rows written.
	 */
	private static function insert_items( $import_id, array $items ) {
		global $wpdb;

		$table   = GameLib_Schema::table( self::ITEMS_TABLE );
		$written = 0;

		foreach ( array_chunk( $items, self::INSERT_BATCH ) as $chunk ) {
			$placeholders = array();
			$values       = array();

			foreach ( $chunk as $item ) {
				$tuple    = array( '%d', '%s' );
				$values[] = $import_id;
				$values[] = isset( $item['source_name'] ) ? (string) $item['source_name'] : '';

				foreach ( array( 'steam_appid', 'igdb_id' ) as $column ) {
					$id = isset( $item[ $column ] ) ? absint( $item[ $column ] ) : 0;

					if ( $id > 0 ) {
						$tuple[]  = '%d';
						$values[] = $id;
					} else {
						$tuple[] = 'NULL';
					}
				}

				$tuple[]  = '%s';
				$values[] = self::sanitize_bucket( isset( $item['bucket'] ) ? $item['bucket'] : '' );

				foreach ( array( 'candidates', 'note' ) as $column ) {
					$value = isset( $item[ $column ] ) ? (string) $item[ $column ] : '';

					if ( '' === $value ) {
						$tuple[] = 'NULL';
					} else {
						$tuple[]  = '%s';
						$values[] = ( 'note' === $column ) ? self::note( $value ) : $value;
					}
				}

				$placeholders[] = '(' . implode( ', ', $tuple ) . ')';
			}

			$rows = implode( ', ', $placeholders );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $rows is a generated list of placeholder tuples bound by prepare() below; $table comes from GameLib_Schema::table(). A write is never cached.
			$affected = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
					"INSERT INTO {$table} (import_id, source_name, steam_appid, igdb_id, bucket, candidates, note) VALUES {$rows}",
					$values
				)
			);

			if ( is_int( $affected ) && $affected > 0 ) {
				$written += $affected;
			}
		}

		return $written;
	}

	/**
	 * A per-row note, trimmed to the column's width.
	 *
	 * @param string $note Note text.
	 * @return string Note, at most 255 characters.
	 */
	private static function note( $note ) {
		$note = sanitize_text_field( (string) $note );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $note, 0, 255 );
		}

		return substr( $note, 0, 255 );
	}

	/**
	 * The note stamped on the rows of an abandoned chunk (AC-043c).
	 *
	 * @param WP_Error $error Failure that ended the chunk.
	 * @return string Member-facing note.
	 */
	private static function failure_note( WP_Error $error ) {
		if ( in_array( (string) $error->get_error_code(), self::RETRYABLE, true ) ) {
			return __( 'Game data was unavailable after three attempts. Import this row again later.', 'game-library' );
		}

		return __( 'Game data could not be reached for this row.', 'game-library' );
	}

	/**
	 * Display label for one of the four library statuses.
	 *
	 * @param string $status Library status.
	 * @return string Translated label, or the raw value for anything unknown.
	 */
	private static function status_label( $status ) {
		$labels = array(
			'playing'  => _x( 'Playing', 'library status', 'game-library' ),
			'finished' => _x( 'Finished', 'library status', 'game-library' ),
			'backlog'  => _x( 'Backlog', 'library status', 'game-library' ),
			'wishlist' => _x( 'Wishlist', 'library status', 'game-library' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * The counts shape, with every key present and zeroed.
	 *
	 * @param int $total Rows the import holds.
	 * @return array<string, int> Counts.
	 */
	private static function empty_counts( $total ) {
		$counts = array( 'total' => (int) $total );

		foreach ( self::BUCKETS as $bucket ) {
			$counts[ $bucket ] = 0;
		}

		$counts[ self::BUCKET_PENDING ] = (int) $total;
		$counts['duplicates']           = 0;

		return $counts;
	}

	/**
	 * Normalize a decoded counts column: every key present, every value an int.
	 *
	 * @param array $counts Decoded counts.
	 * @return array<string, int> Counts.
	 */
	private static function normalize_counts( array $counts ) {
		$normalized = self::empty_counts( 0 );

		foreach ( array_keys( $normalized ) as $key ) {
			$normalized[ $key ] = ( isset( $counts[ $key ] ) && is_numeric( $counts[ $key ] ) )
				? (int) $counts[ $key ]
				: 0;
		}

		return $normalized;
	}

	/**
	 * "That import could not be found."
	 *
	 * @return WP_Error Refusal.
	 */
	private static function not_found_error() {
		return new WP_Error(
			self::ERROR_NOT_FOUND,
			__( 'That import could not be found.', 'game-library' ),
			array(
				'status' => 404,
				'state'  => 'not_found',
			)
		);
	}

	/**
	 * "That row could not be found." — the same refusal for a row that never
	 * existed and a row on somebody else's import (AC-NFR-001).
	 *
	 * @return WP_Error Refusal.
	 */
	private static function item_error() {
		return new WP_Error(
			self::ERROR_ITEM,
			__( 'That import row could not be found.', 'game-library' ),
			array(
				'status' => 404,
				'state'  => 'item_not_found',
			)
		);
	}

	/**
	 * The refusal for a row whose decision has already been made (AC-042).
	 *
	 * @return WP_Error Refusal.
	 */
	private static function decided_error() {
		return new WP_Error(
			self::ERROR_STATE,
			__( 'That row has already been dealt with.', 'game-library' ),
			array(
				'status' => 409,
				'state'  => 'already_decided',
			)
		);
	}

	/**
	 * The size refusal, which names the cap (AC-038c).
	 *
	 * Public because the REST layer needs the very same refusal for the case
	 * this class never sees: a body over `post_max_size`, which PHP discards
	 * whole, leaving no upload to inspect.
	 *
	 * @return WP_Error Refusal.
	 */
	public static function too_large_error() {
		return new WP_Error(
			self::ERROR_TOO_LARGE,
			sprintf(
				/* translators: %s: maximum upload size, already formatted (e.g. "2 MB"). */
				__( 'Import files must be %s or smaller.', 'game-library' ),
				size_format( GAMELIB_IMPORT_MAX_BYTES )
			),
			array(
				'status' => 400,
				'state'  => 'too_large',
			)
		);
	}

	/**
	 * A parse refusal (AC-038a).
	 *
	 * @param string $message What is wrong with the file.
	 * @return WP_Error Refusal.
	 */
	private static function format_error( $message ) {
		return new WP_Error(
			self::ERROR_FORMAT,
			$message,
			array(
				'status' => 400,
				'state'  => 'invalid_format',
			)
		);
	}

	/**
	 * A list of ids, validated, de-duplicated, and re-indexed.
	 *
	 * @param array $values Candidate ids in any scalar form.
	 * @return int[] Unique positive integers.
	 */
	private static function positive_ints( array $values ) {
		$ids = array();

		foreach ( $values as $value ) {
			$id = is_scalar( $value ) ? (int) $value : 0;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
