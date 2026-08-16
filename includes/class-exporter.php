<?php
/**
 * Library export documents: CSV and JSON.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serializes a member's own library into the two download formats of AC-037.
 *
 * Three rules define the format, and both consumers — the `GET /export` route
 * and {@see GameLib_Importer}, which has to read these files back — depend on
 * all three:
 *
 * 1. **Exactly four fields per row**: `igdb_id`, `title`, `status`,
 *    `date_added`, in that order and no others. No summary, no cover URL, no
 *    rating — an export of a member's library is a list of what they own, not a
 *    re-publication of IGDB's catalogue.
 * 2. **Every CSV cell is quoted, and formula-neutralized first.** A field
 *    beginning `=`, `+`, `-`, or `@` is what a spreadsheet reads as a formula,
 *    so a game genuinely titled `=SUM(A1)` gets a leading apostrophe before it
 *    is quoted (AC-037c). Quoting is separate and unconditional: it is what
 *    makes a title carrying a comma or a quote survive the round trip.
 * 3. **The document is built in memory and returned as a string.** Nothing here
 *    opens, writes, or streams a file — the plugin performs no filesystem
 *    writes at all (Never Do #3). It is built one batch at a time, though
 *    ({@see document()}): a library has no row cap, so materializing every row
 *    as an array and *then* serializing it held two full copies of an unbounded
 *    dataset at once (PB-8).
 *
 * The class is deliberately free of REST, request, and user-session concerns:
 * {@see GameLib_REST_Account::export()} owns authorization, the response
 * headers, and the raw-body short-circuit, and calls in here for the bytes.
 * See principal/adr/013-export-generation-ships-with-its-route.md for why the
 * route shipped with a private copy of this logic one task before this class
 * existed.
 */
final class GameLib_Exporter {

	/**
	 * Export format: comma-separated values (AC-037a).
	 *
	 * @var string
	 */
	const FORMAT_CSV = 'csv';

	/**
	 * Export format: JSON (AC-037b).
	 *
	 * @var string
	 */
	const FORMAT_JSON = 'json';

	/**
	 * The two export formats — the whitelist every caller validates against.
	 *
	 * @var string[]
	 */
	const FORMATS = array( self::FORMAT_CSV, self::FORMAT_JSON );

	/**
	 * The exported columns, in order: exactly the four fields AC-037 allows.
	 *
	 * Also the CSV header row, and therefore the header
	 * {@see GameLib_Importer} recognizes when the file comes back.
	 *
	 * @var string[]
	 */
	const FIELDS = array( 'igdb_id', 'title', 'status', 'date_added' );

	/**
	 * The leading characters a spreadsheet reads as the start of a formula
	 * (AC-037c). A field beginning with any of them is prefixed with `'`.
	 *
	 * @var string[]
	 */
	const CSV_FORMULA_PREFIXES = array( '=', '+', '-', '@' );

	/**
	 * Line ending of the CSV document: CRLF, which is what the format's
	 * consumers expect from a downloaded file.
	 *
	 * @var string
	 */
	const CSV_EOL = "\r\n";

	/**
	 * A requested format, or the default.
	 *
	 * @param mixed $format Raw value from a request.
	 * @return string One of {@see GameLib_Exporter::FORMATS}.
	 */
	public static function sanitize_format( $format ) {
		$format = is_scalar( $format ) ? sanitize_key( (string) $format ) : '';

		return in_array( $format, self::FORMATS, true ) ? $format : self::FORMAT_CSV;
	}

	/**
	 * A member's whole library as one export document (AC-037 a,b).
	 *
	 * Assembled from {@see GameLib_Library::export_rows()}'s batches rather than
	 * from a materialized row list (PB-8): each batch is appended to the growing
	 * document and dropped, so the only thing that scales with library size is
	 * the string being built, not that string *plus* an array of every row.
	 *
	 * The bytes are identical to serializing the whole set in one pass — a CSV
	 * header and CRLF-terminated lines, or one JSON array of four-key objects.
	 *
	 * @param string $format  One of {@see GameLib_Exporter::FORMATS}.
	 * @param int    $user_id Library owner.
	 * @return string File contents.
	 */
	public static function document( $format, $user_id ) {
		$format  = self::sanitize_format( $format );
		$opening = self::open( $format );
		$body    = $opening;

		GameLib_Library::export_rows(
			$user_id,
			/*
			 * "Has anything been written yet" is read off the document rather
			 * than off a row counter, so a batch that serialized nothing cannot
			 * make the next one emit JSON's separator with no object before it.
			 */
			static function ( array $batch ) use ( &$body, $opening, $format ) {
				$body .= self::chunk( $format, $batch, $body === $opening );
			}
		);

		return $body . self::close( $format );
	}

	/**
	 * The same document, echoed batch by batch instead of returned (VIP-6).
	 *
	 * {@see document()} drops each batch of rows but concatenates every
	 * serialized chunk into one return value, so peak memory is still
	 * proportional to library size rather than to batch size — and the caller
	 * then echoes that whole string. Writing each chunk straight to the output
	 * buffer as the walk produces it means nothing larger than one
	 * `GameLib_Library::READ_BATCH` of serialized rows is ever resident.
	 *
	 * Only a caller that owns the response body may use this — in this plugin
	 * that is `GameLib_REST_Account::serve_export()`, a `rest_pre_serve_request`
	 * filter that returns true. Everything else wants {@see document()}.
	 *
	 * @param string $format  One of {@see GameLib_Exporter::FORMATS}.
	 * @param int    $user_id Library owner.
	 * @return int Rows written.
	 */
	public static function stream( $format, $user_id ) {
		$format  = self::sanitize_format( $format );
		$written = false;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated CSV/JSON download bytes, not markup: HTML-escaping them would corrupt the file (Never Do #14). Values are neutralized against formula injection and quoted by chunk()/csv_cell(), or JSON-encoded by wp_json_encode().
		echo self::open( $format );

		$rows = GameLib_Library::export_rows(
			$user_id,
			/*
			 * "Has anything been written yet" decides JSON's separator, exactly
			 * as it does in document() — a batch that serialized nothing must not
			 * make the next one emit a comma with no object before it.
			 */
			static function ( array $batch ) use ( $format, &$written ) {
				$chunk = self::chunk( $format, $batch, ! $written );

				if ( '' === $chunk ) {
					return;
				}

				$written = true;

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See above: download bytes, not markup.
				echo $chunk;
			}
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See above: download bytes, not markup.
		echo self::close( $format );

		return (int) $rows;
	}

	/**
	 * The download's `Content-Type`.
	 *
	 * @param string $format One of {@see GameLib_Exporter::FORMATS}.
	 * @return string MIME type with its charset.
	 */
	public static function content_type( $format ) {
		return ( self::FORMAT_JSON === self::sanitize_format( $format ) )
			? 'application/json; charset=utf-8'
			: 'text/csv; charset=utf-8';
	}

	/**
	 * The download's filename.
	 *
	 * Carries the member's nicename and the UTC date, so two exports taken on
	 * different days do not overwrite each other in a downloads folder.
	 *
	 * @param int    $user_id Library owner.
	 * @param string $format  One of {@see GameLib_Exporter::FORMATS}.
	 * @return string Sanitized filename.
	 */
	public static function filename( $user_id, $format ) {
		$user     = get_userdata( absint( $user_id ) );
		$nicename = ( $user instanceof WP_User ) ? (string) $user->user_nicename : '';
		$parts    = array( 'game-library' );

		if ( '' !== $nicename ) {
			$parts[] = $nicename;
		}

		$parts[] = gmdate( 'Y-m-d' );

		return sanitize_file_name( implode( '-', $parts ) . '.' . self::sanitize_format( $format ) );
	}

	/**
	 * One export row reduced to exactly the four permitted fields, in order.
	 *
	 * @param array $row Row from {@see GameLib_Library::export_rows()}.
	 * @return array{igdb_id:int,title:string,status:string,date_added:string} Ordered field map.
	 */
	public static function row( array $row ) {
		return array(
			'igdb_id'    => isset( $row['igdb_id'] ) ? (int) $row['igdb_id'] : 0,
			'title'      => isset( $row['title'] ) ? (string) $row['title'] : '',
			'status'     => isset( $row['status'] ) ? (string) $row['status'] : '',
			'date_added' => isset( $row['date_added'] ) ? (string) $row['date_added'] : '',
		);
	}

	/**
	 * Everything a document carries before its first row.
	 *
	 * The CSV header row — which is also the header {@see GameLib_Importer}
	 * recognizes when the file comes back — or JSON's opening bracket.
	 *
	 * @param string $format Sanitized format.
	 * @return string Opening bytes.
	 */
	private static function open( $format ) {
		return ( self::FORMAT_JSON === $format )
			? '['
			: implode( ',', self::FIELDS ) . self::CSV_EOL;
	}

	/**
	 * One batch of rows, serialized in place.
	 *
	 * @param string  $format Sanitized format.
	 * @param array[] $rows   Export rows.
	 * @param bool    $first  Whether these are the document's first rows — JSON
	 *                        needs to know, because the separator goes *between*
	 *                        objects and a batch boundary is not one.
	 * @return string Serialized rows.
	 */
	private static function chunk( $format, array $rows, $first ) {
		$json  = ( self::FORMAT_JSON === $format );
		$parts = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( $json ) {
				$parts[] = (string) wp_json_encode( self::row( $row ) );

				continue;
			}

			$cells = array();

			foreach ( self::row( $row ) as $value ) {
				$cells[] = self::csv_cell( $value );
			}

			$parts[] = implode( ',', $cells ) . self::CSV_EOL;
		}

		if ( ! $json ) {
			return implode( '', $parts );
		}

		$separator = ( $first || empty( $parts ) ) ? '' : ',';

		return $separator . implode( ',', $parts );
	}

	/**
	 * Everything a document carries after its last row.
	 *
	 * @param string $format Sanitized format.
	 * @return string Closing bytes.
	 */
	private static function close( $format ) {
		return ( self::FORMAT_JSON === $format ) ? ']' : '';
	}

	/**
	 * One CSV cell: formula-neutralized, then quoted (AC-037c).
	 *
	 * @param string|int $value Field value.
	 * @return string Quoted cell.
	 */
	private static function csv_cell( $value ) {
		$value = (string) $value;

		if ( '' !== $value && in_array( substr( $value, 0, 1 ), self::CSV_FORMULA_PREFIXES, true ) ) {
			$value = "'" . $value;
		}

		return '"' . str_replace( '"', '""', $value ) . '"';
	}
}
