<?php
/**
 * The invites list table (Games → Invites).
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The `WP_List_Table` behind AC-006.
 *
 * One row per invite, newest first, with the six columns the AC enumerates —
 * code (a), issuer (b), status (c), redeemer (d), and both timestamps (e) —
 * and a Revoke row action that appears only while the invite is outstanding
 * (f).
 *
 * Three things are worth knowing about this file:
 *
 * - **It is loaded lazily.** `WP_List_Table` exists only once
 *   `wp-admin/includes` has loaded, so this file is required from
 *   {@see GameLib_Admin_Settings::render_invites_page()} rather than at plugin
 *   load; requiring it on a front-end request would be a fatal error.
 * - **It reads through the domain, never the table.** Every query goes through
 *   {@see GameLib_Invites}, which owns `gamelib_invites`, caches its pages
 *   under the shared invite generation scope, and prepares every statement.
 *   One page is 20 rows and there is no unbounded read (Always Do #4).
 * - **Column methods return markup, and core echoes it.**
 *   `WP_List_Table::single_row_columns()` prints whatever a `column_*()` method
 *   returns without escaping it, so each method escapes its own output.
 */
final class GameLib_Invites_Table extends WP_List_Table {

	/**
	 * Rows per page. The domain's own default page size, so the admin table and
	 * the member-facing invite list read the table in identical slices.
	 *
	 * @var int
	 */
	const PER_PAGE = GameLib_Invites::LIST_LIMIT;

	/**
	 * Build the table.
	 *
	 * @param array $args Optional. Arguments forwarded to `WP_List_Table`.
	 */
	public function __construct( $args = array() ) {
		parent::__construct(
			array_merge(
				array(
					'singular' => 'gamelib_invite',
					'plural'   => 'gamelib_invites',
					'ajax'     => false,
				),
				is_array( $args ) ? $args : array()
			)
		);
	}

	/**
	 * The six columns of AC-006 (a)–(e), in reading order.
	 *
	 * @return array<string, string> Column key => heading.
	 */
	public function get_columns() {
		return array(
			'code'        => __( 'Code', 'game-library' ),
			'issuer'      => __( 'Issued by', 'game-library' ),
			'status'      => __( 'Status', 'game-library' ),
			'redeemer'    => __( 'Redeemed by', 'game-library' ),
			'created_at'  => __( 'Created', 'game-library' ),
			'redeemed_at' => __( 'Redeemed', 'game-library' ),
		);
	}

	/**
	 * Load one page of invites.
	 *
	 * The page number is clamped to the number of pages that actually exist, so
	 * a hand-edited `paged` argument reads the last page rather than issuing a
	 * query for an offset past the end of the table.
	 *
	 * `$_column_headers` has to be assigned here: left unset,
	 * `WP_List_Table::get_column_info()` falls back to
	 * `get_column_headers( $this->screen )`, which resolves columns from the
	 * `manage_{$screen->id}_columns` filter — a filter nothing registers this
	 * table against — and the table renders an empty `<thead>` with
	 * `colspan="0"` (observed on the shared Playground before this line
	 * existed). None of the six columns is sortable or hideable, so the
	 * remaining three members are empty/derived.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			array(),
			$this->get_default_primary_column_name(),
		);

		$per_page    = max( 1, (int) self::PER_PAGE );
		$total       = GameLib_Invites::total_count();
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( max( 1, (int) $this->get_pagenum() ), $total_pages );

		$this->items = GameLib_Invites::list_all( $per_page, ( $page - 1 ) * $per_page );

		$this->prime_users();

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => $total_pages,
			)
		);
	}

	/**
	 * The empty state.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No invites have been issued yet.', 'game-library' );
	}

	/**
	 * The code (AC-006a).
	 *
	 * The row actions belong to {@see handle_row_actions()}, which core calls
	 * for the primary column right after this method: calling
	 * `row_actions()` here as well would emit the "Show more details" toggle
	 * twice, because both helpers append one (observed on the shared
	 * Playground).
	 *
	 * @param array $item Invite row.
	 * @return string Escaped markup.
	 */
	public function column_code( $item ) {
		$code = isset( $item['code'] ) ? (string) $item['code'] : '';

		if ( '' === $code ) {
			return $this->none();
		}

		return '<code>' . esc_html( $code ) . '</code>';
	}

	/**
	 * The issuing member, linked to their profile-edit screen (AC-006b).
	 *
	 * @param array $item Invite row.
	 * @return string Escaped markup.
	 */
	public function column_issuer( $item ) {
		return $this->user_cell( isset( $item['issuer_id'] ) ? (int) $item['issuer_id'] : 0 );
	}

	/**
	 * The invite's status (AC-006c).
	 *
	 * @param array $item Invite row.
	 * @return string Escaped markup.
	 */
	public function column_status( $item ) {
		$status = isset( $item['status'] ) ? (string) $item['status'] : '';

		return sprintf(
			'<span class="gamelib-invite-status" data-gamelib-invite-status="%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( self::status_label( $status ) )
		);
	}

	/**
	 * The member who redeemed the invite, when one has (AC-006d).
	 *
	 * @param array $item Invite row.
	 * @return string Escaped markup.
	 */
	public function column_redeemer( $item ) {
		return $this->user_cell( isset( $item['redeemer_id'] ) ? (int) $item['redeemer_id'] : 0 );
	}

	/**
	 * When the invite was created (AC-006e).
	 *
	 * @param array $item Invite row.
	 * @return string Escaped markup.
	 */
	public function column_created_at( $item ) {
		return $this->date_cell( isset( $item['created_at'] ) ? (string) $item['created_at'] : '' );
	}

	/**
	 * When the invite was redeemed, if it was (AC-006e).
	 *
	 * @param array $item Invite row.
	 * @return string Escaped markup.
	 */
	public function column_redeemed_at( $item ) {
		return $this->date_cell( isset( $item['redeemed_at'] ) ? (string) $item['redeemed_at'] : '' );
	}

	/**
	 * Fallback for a column no method above claims.
	 *
	 * @param array  $item        Invite row.
	 * @param string $column_name Column key.
	 * @return string Empty string — every declared column has its own method.
	 */
	protected function column_default( $item, $column_name ) {
		unset( $item, $column_name );

		return '';
	}

	/**
	 * The row actions rendered under the primary column (AC-006f).
	 *
	 * Core calls this once per cell and expects markup only for the primary
	 * column, which is where it also emits the responsive "Show more details"
	 * toggle.
	 *
	 * @param array  $item        Invite row.
	 * @param string $column_name Column being rendered.
	 * @param string $primary     Primary column key.
	 * @return string Escaped markup, or '' for a non-primary column.
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $column_name !== $primary ) {
			return '';
		}

		return $this->row_actions( $this->invite_row_actions( is_array( $item ) ? $item : array() ) );
	}

	/**
	 * The row actions one invite offers (AC-006f).
	 *
	 * Revoke, and only on an outstanding invite: a redeemed invite is the
	 * registration trail behind an existing account, and a revoked one has
	 * nothing left to withdraw. The nonce is bound to the individual invite id.
	 *
	 * @param array $item Invite row.
	 * @return array<string, string> Action key => escaped anchor markup.
	 */
	private function invite_row_actions( array $item ) {
		$status    = isset( $item['status'] ) ? (string) $item['status'] : '';
		$invite_id = isset( $item['id'] ) ? (int) $item['id'] : 0;

		if ( GameLib_Invites::STATUS_OUTSTANDING !== $status || $invite_id < 1 ) {
			return array();
		}

		if ( ! current_user_can( GameLib_Capabilities::CAP_ADMIN_OVERRIDE ) ) {
			return array();
		}

		$url = GameLib_Admin_Settings::revoke_url( $invite_id );

		if ( '' === $url ) {
			return array();
		}

		return array(
			'revoke' => sprintf(
				'<a href="%1$s" class="submitdelete" data-gamelib-action="invite.revoke" aria-label="%2$s">%3$s</a>',
				esc_url( $url ),
				esc_attr(
					sprintf(
						/* translators: %s: invite code. */
						__( 'Revoke the invite with code %s', 'game-library' ),
						isset( $item['code'] ) ? (string) $item['code'] : ''
					)
				),
				esc_html__( 'Revoke', 'game-library' )
			),
		);
	}

	/**
	 * One member cell: their display name, linked to their user-edit screen.
	 *
	 * A member who has since been deleted still leaves an id on the row — the
	 * cell names it rather than pretending the invite was never issued.
	 *
	 * @param int $user_id Member id; 0 when the column is empty for this row.
	 * @return string Escaped markup.
	 */
	private function user_cell( $user_id ) {
		$user_id = absint( $user_id );

		if ( $user_id < 1 ) {
			return $this->none();
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return esc_html(
				sprintf(
					/* translators: %s: user id of a member who no longer exists. */
					__( 'Deleted member (#%s)', 'game-library' ),
					number_format_i18n( $user_id )
				)
			);
		}

		$name = ( '' !== (string) $user->display_name ) ? (string) $user->display_name : (string) $user->user_login;
		$link = (string) get_edit_user_link( $user_id );

		if ( '' === $link ) {
			return esc_html( $name );
		}

		return '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>';
	}

	/**
	 * One stored UTC datetime, rendered in the site's timezone and format.
	 *
	 * @param string $value Stored `Y-m-d H:i:s` UTC value, or ''.
	 * @return string Escaped markup.
	 */
	private function date_cell( $value ) {
		$timestamp = ( '' === $value ) ? false : strtotime( $value . ' UTC' );

		if ( false === $timestamp ) {
			return $this->none();
		}

		return sprintf(
			'<time datetime="%1$s">%2$s</time>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_html(
				wp_date(
					(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ),
					$timestamp
				)
			)
		);
	}

	/**
	 * The cell an absent value renders: a dash for sighted readers, a word for
	 * everyone else.
	 *
	 * @return string Escaped markup.
	 */
	private function none() {
		return '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">'
			. esc_html__( 'None', 'game-library' )
			. '</span>';
	}

	/**
	 * Load every member named on this page in one query.
	 *
	 * Without this the two user columns would issue up to 40 lookups per page
	 * (WPP-05); `cache_users()` primes them all at once.
	 *
	 * @return void
	 */
	private function prime_users() {
		$user_ids = array();

		foreach ( (array) $this->items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			foreach ( array( 'issuer_id', 'redeemer_id' ) as $key ) {
				$user_id = isset( $item[ $key ] ) ? absint( $item[ $key ] ) : 0;

				if ( $user_id > 0 ) {
					$user_ids[] = $user_id;
				}
			}
		}

		if ( ! empty( $user_ids ) ) {
			cache_users( array_unique( $user_ids ) );
		}
	}

	/**
	 * Display label for one invite status (AC-006c).
	 *
	 * @param string $status Stored status.
	 * @return string Translated label.
	 */
	private static function status_label( $status ) {
		switch ( $status ) {
			case GameLib_Invites::STATUS_OUTSTANDING:
				return _x( 'Outstanding', 'invite status', 'game-library' );

			case GameLib_Invites::STATUS_REDEEMED:
				return _x( 'Redeemed', 'invite status', 'game-library' );

			case GameLib_Invites::STATUS_REVOKED:
				return _x( 'Revoked', 'invite status', 'game-library' );

			default:
				return _x( 'Unknown', 'invite status', 'game-library' );
		}
	}
}
