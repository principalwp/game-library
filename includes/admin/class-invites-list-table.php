<?php
/**
 * Admin invite list screen — every invite across every member (AC-031).
 *
 * @package Game_Library
 */

namespace Game_Library\Admin;

use Game_Library\Data\Invite_Repository;
use Game_Library\Dates;
use Game_Library\Invites\Invite_Service;
use WP_List_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WP_List_Table lives in an admin-only core file that is never autoloaded on
// the front end. Plugin::__construct() only ever instantiates this class
// behind an is_admin() guard, but this require stays self-contained (and
// idempotent via class_exists()) so the file never depends on load order
// elsewhere.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Invites_List_Table.
 *
 * Registers the "Invites" submenu under the top-level "Game Library" menu
 * (`manage_options`) and lists every invite across every inviter with the
 * eight AC-031 columns, a per-status filter, and Resend/Revoke row actions,
 * paginated at 50 rows.
 *
 * Every row is read through `Invite_Repository::get_page()`/`count_all()`
 * with an explicit limit and offset — never a direct `$wpdb` query and never
 * an unbounded `SELECT`. Resend and Revoke are each a separate
 * `admin-post.php` action carrying a per-row nonce (`{action}_{id}`) so one
 * row's link can never be replayed against another row; both handlers
 * re-check `current_user_can( 'manage_options' )` independently of the
 * submenu's own capability gate, matching `Settings_Page::
 * handle_test_connection()`'s pattern.
 *
 * `STATUSES` duplicates `Invite_Repository::STATUSES` (D40) because that
 * repository constant is private and this task's Files list does not extend
 * to adding a public accessor for it — the same "no shared constants class
 * exists yet, so a small allowlist is duplicated with a docblock note"
 * pattern `Invite_Service::DEFAULTS` already uses for
 * `Settings_Page::DEFAULTS`.
 */
final class Invites_List_Table extends WP_List_Table {

	/**
	 * Top-level parent menu slug, registered by `Settings_Page::register_menu()`.
	 *
	 * @var string
	 */
	private const PARENT_SLUG = 'game-library';

	/**
	 * This submenu's own page slug.
	 *
	 * @var string
	 */
	private const MENU_SLUG = 'game-library-invites';

	/**
	 * Rows per page (AC-031).
	 *
	 * @var int
	 */
	private const PER_PAGE = 50;

	/**
	 * The four-state invite status allowlist (D40) — mirrors
	 * `Invite_Repository::STATUSES`; see the class docblock for why it is
	 * duplicated here rather than read from that repository.
	 *
	 * @var string[]
	 */
	private const STATUSES = array( 'pending', 'redeemed', 'expired', 'revoked' );

	/**
	 * `admin-post.php` action name for the "Resend" row action.
	 *
	 * @var string
	 */
	private const RESEND_ACTION = 'gl_invite_resend';

	/**
	 * `admin-post.php` action name for the "Revoke" row action.
	 *
	 * @var string
	 */
	private const REVOKE_ACTION = 'gl_invite_revoke';

	/**
	 * Invite data access.
	 *
	 * @var Invite_Repository
	 */
	private $repository;

	/**
	 * Invite delivery, used only to re-send an existing pending invite.
	 *
	 * @var Invite_Service
	 */
	private $service;

	/**
	 * Whether `ensure_table_initialized()` has already run `parent::
	 * __construct()`.
	 *
	 * @var bool
	 */
	private $table_initialized = false;

	/**
	 * Constructor.
	 *
	 * Deliberately does not call `parent::__construct()` — `WP_List_Table`'s
	 * own constructor calls `convert_to_screen()`, which lives in
	 * `wp-admin/includes/template.php`. `Plugin::__construct()` instantiates
	 * this class at plugin-bootstrap time (during `plugins_loaded`), well
	 * before `wp-admin/admin.php` requires that file — calling
	 * `parent::__construct()` here fatals with "Call to undefined function
	 * convert_to_screen()" the moment the plugin loads. `ensure_table_
	 * initialized()` defers that call until `render_page()`, which only ever
	 * runs as the `add_submenu_page()` callback — i.e. only once
	 * `wp-admin/includes/admin.php` (and therefore `template.php`) has
	 * already loaded. Confirmed at runtime against the shared Playground
	 * instance.
	 *
	 * @param Invite_Repository|null $repository Invite data access. Defaults
	 *                                            to a new instance.
	 * @param Invite_Service|null    $service    Invite delivery. Defaults to
	 *                                            a new instance built from
	 *                                            $repository.
	 */
	public function __construct( ?Invite_Repository $repository = null, ?Invite_Service $service = null ) {
		$this->repository = $repository ?: new Invite_Repository();
		$this->service    = $service ?: new Invite_Service( $this->repository );
	}

	/**
	 * Runs `WP_List_Table::__construct()` exactly once, on first use — see
	 * the class constructor's docblock for why this cannot happen eagerly.
	 *
	 * @return void
	 */
	private function ensure_table_initialized() {
		if ( $this->table_initialized ) {
			return;
		}

		parent::__construct(
			array(
				'singular' => 'invite',
				'plural'   => 'invites',
				'ajax'     => false,
			)
		);

		$this->table_initialized = true;
	}

	/**
	 * Registers this service's hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::RESEND_ACTION, array( $this, 'handle_resend' ) );
		add_action( 'admin_post_' . self::REVOKE_ACTION, array( $this, 'handle_revoke' ) );
	}

	/**
	 * Registers the "Invites" submenu under the top-level "Game Library" menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Invites', 'game-library' ),
			__( 'Invites', 'game-library' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the full admin screen: heading, flash notice, total-count
	 * summary, status filter views, and the list table itself.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'game-library' ), '', array( 'response' => 403 ) );
		}

		$this->ensure_table_initialized();
		$this->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Invites', 'game-library' ) . '</h1>';

		$this->render_flash_notice();

		echo '<p>' . esc_html( $this->total_items_label() ) . '</p>';

		$this->views();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		$this->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Reads the sanitized, paginated, optionally status-filtered set of
	 * invites through `Invite_Repository` only.
	 *
	 * PB-3 (cycle-8): `$this->get_pagenum()` is clamped against the real
	 * total BEFORE `$offset` is computed — core's own `WP_List_Table::
	 * get_pagenum()` only clamps against `total_pages` once
	 * `$this->_pagination_args` is populated, which `set_pagination_args()`
	 * below did not do until after this method's own query already ran, so
	 * the first render of an out-of-range page reached
	 * `Invite_Repository::get_page()` with an unclamped offset — its own
	 * per-offset `wp_cache_set()` (see that method's own docblock) then
	 * wrote a dead cache entry for every out-of-range page number an
	 * administrator (or a crawler) requested.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$status = $this->current_status_filter();

		$total_items  = $this->repository->count_all( $status );
		$total_pages  = max( 1, (int) ceil( $total_items / self::PER_PAGE ) );
		$current_page = min( $this->get_pagenum(), $total_pages );
		$offset       = ( $current_page - 1 ) * self::PER_PAGE;

		$this->items = $this->repository->get_page( $status, self::PER_PAGE, $offset );

		// PB-7: primes every distinct inviter_id/redeemed_user_id on this
		// page's own user object cache in one call — without this,
		// user_display_name() below issued one get_userdata() lookup per
		// row per column (Inviter, Redeemed by), up to 100 lookups for one
		// 50-row page. Every other list surface in this plugin already does
		// this (templates/members.php, templates/game-single.php,
		// Activity_Repository::prime_actors(), Social_Controller::get_members()).
		$user_ids = array_filter(
			array_map(
				'absint',
				array_merge(
					wp_list_pluck( $this->items, 'inviter_id' ),
					wp_list_pluck( $this->items, 'redeemed_user_id' )
				)
			)
		);

		if ( ! empty( $user_ids ) ) {
			cache_users( array_values( array_unique( $user_ids ) ) );
		}

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * The eight AC-031 columns, keyed to a `column_{key}()` method each.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'code'         => __( 'Code', 'game-library' ),
			'inviter'      => __( 'Inviter', 'game-library' ),
			'email'        => __( 'Recipient email', 'game-library' ),
			'channel'      => __( 'Channel', 'game-library' ),
			'status'       => __( 'Status', 'game-library' ),
			'date_created' => __( 'Created', 'game-library' ),
			'date_expires' => __( 'Expires', 'game-library' ),
			'redeemed_by'  => __( 'Redeemed by', 'game-library' ),
		);
	}

	/**
	 * The status filter views ("All" plus one per D40 status), each carrying
	 * its own cached count from `Invite_Repository::count_all()`.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$labels = array(
			''         => __( 'All', 'game-library' ),
			'pending'  => __( 'Pending', 'game-library' ),
			'redeemed' => __( 'Redeemed', 'game-library' ),
			'expired'  => __( 'Expired', 'game-library' ),
			'revoked'  => __( 'Revoked', 'game-library' ),
		);

		$current = $this->current_status_filter();
		$views   = array();

		foreach ( $labels as $status => $label ) {
			$count    = $this->repository->count_all( $status );
			$url_args = array( 'page' => self::MENU_SLUG );

			if ( '' !== $status ) {
				$url_args['invite_status'] = $status;
			}

			$url = add_query_arg( $url_args, admin_url( 'admin.php' ) );
			$key = '' === $status ? 'all' : $status;

			$views[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $url ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/**
	 * "Code" cell — the primary column; carries the Resend/Revoke row
	 * actions via `handle_row_actions()` below.
	 *
	 * @param array<string,mixed> $item One hydrated invite row.
	 * @return string
	 */
	public function column_code( array $item ) {
		return esc_html( $item['code'] );
	}

	/**
	 * "Inviter" cell.
	 *
	 * @param array<string,mixed> $item One hydrated invite row.
	 * @return string
	 */
	public function column_inviter( array $item ) {
		return esc_html( $this->user_display_name( $item['inviter_id'] ) );
	}

	/**
	 * "Recipient email" cell.
	 *
	 * @param array<string,mixed> $item One hydrated invite row.
	 * @return string
	 */
	public function column_email( array $item ) {
		return empty( $item['email'] ) ? esc_html( '—' ) : esc_html( $item['email'] );
	}

	/**
	 * "Channel" cell.
	 *
	 * @param array<string,mixed> $item One hydrated invite row.
	 * @return string
	 */
	public function column_channel( array $item ) {
		$labels = array(
			'email' => __( 'Email', 'game-library' ),
			'link'  => __( 'Link', 'game-library' ),
		);

		return isset( $labels[ $item['channel'] ] ) ? esc_html( $labels[ $item['channel'] ] ) : esc_html( $item['channel'] );
	}

	/**
	 * "Status" cell.
	 *
	 * @param array<string,mixed> $item One hydrated invite row.
	 * @return string
	 */
	public function column_status( array $item ) {
		$labels = array(
			'pending'  => __( 'Pending', 'game-library' ),
			'redeemed' => __( 'Redeemed', 'game-library' ),
			'expired'  => __( 'Expired', 'game-library' ),
			'revoked'  => __( 'Revoked', 'game-library' ),
		);

		return isset( $labels[ $item['status'] ] ) ? esc_html( $labels[ $item['status'] ] ) : esc_html( $item['status'] );
	}

	/**
	 * "Created" cell.
	 *
	 * @param array<string,mixed> $item One hydrated invite row.
	 * @return string
	 */
	public function column_date_created( array $item ) {
		return esc_html( Dates::format( $item['date_created'] ) );
	}

	/**
	 * "Expires" cell.
	 *
	 * @param array<string,mixed> $item One hydrated invite row.
	 * @return string
	 */
	public function column_date_expires( array $item ) {
		return esc_html( Dates::format( $item['date_expires'] ) );
	}

	/**
	 * "Redeemed by" cell.
	 *
	 * @param array<string,mixed> $item One hydrated invite row.
	 * @return string
	 */
	public function column_redeemed_by( array $item ) {
		if ( ! $item['redeemed_user_id'] ) {
			return esc_html( '—' );
		}

		return esc_html( $this->user_display_name( $item['redeemed_user_id'] ) );
	}

	/**
	 * Attaches the Resend/Revoke row actions to the primary ("code") column
	 * only, per the parent class's per-row-action contract.
	 *
	 * @param array<string,mixed> $item        One hydrated invite row.
	 * @param string               $column_name Current column name.
	 * @param string               $primary     The primary column name.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $column_name !== $primary ) {
			return '';
		}

		$actions = $this->build_row_actions( $item );

		return $actions ? $this->row_actions( $actions ) : '';
	}

	/**
	 * Builds the Resend/Revoke row action links (AC-031 (i)-(j)): Resend only
	 * for a `pending` invite with an email, Revoke only for a `pending`
	 * invite.
	 *
	 * @param array<string,mixed> $item One hydrated invite row.
	 * @return array<string,string>
	 */
	private function build_row_actions( array $item ) {
		$actions = array();

		if ( 'pending' !== $item['status'] ) {
			return $actions;
		}

		if ( ! empty( $item['email'] ) ) {
			$actions['resend'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $this->build_action_url( self::RESEND_ACTION, $item['id'] ) ),
				esc_html__( 'Resend', 'game-library' )
			);
		}

		$actions['revoke'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $this->build_action_url( self::REVOKE_ACTION, $item['id'] ) ),
			esc_html__( 'Revoke', 'game-library' )
		);

		return $actions;
	}

	/**
	 * Handles the "Resend" row action: re-checks capability and the per-row
	 * nonce, re-sends the invite through `Invite_Service::resend_invite()`,
	 * and redirects back with a flash outcome.
	 *
	 * @return void
	 */
	public function handle_resend() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'game-library' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['invite_id'] ) ? absint( wp_unslash( $_GET['invite_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() below.

		check_admin_referer( self::RESEND_ACTION . '_' . $id );

		$result = $this->service->resend_invite( $id );

		if ( is_wp_error( $result ) ) {
			$notice = 'resend_error';
		} else {
			$notice = $result['mail_sent'] ? 'resend_success' : 'resend_mail_failed';
		}

		$this->redirect_with_notice( $notice );
	}

	/**
	 * Handles the "Revoke" row action: re-checks capability and the per-row
	 * nonce, revokes the invite through `Invite_Repository::revoke()`, and
	 * redirects back with a flash outcome. Revoking never deletes the row.
	 *
	 * @return void
	 */
	public function handle_revoke() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'game-library' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['invite_id'] ) ? absint( wp_unslash( $_GET['invite_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() below.

		check_admin_referer( self::REVOKE_ACTION . '_' . $id );

		$revoked = $this->repository->revoke( $id );

		$this->redirect_with_notice( $revoked ? 'revoke_success' : 'revoke_error' );
	}

	/**
	 * Redirects back to this screen carrying a flash-notice query arg.
	 *
	 * @param string $notice One of the keys `render_flash_notice()` recognises.
	 * @return void
	 */
	private function redirect_with_notice( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::MENU_SLUG,
					'gl_invite_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the flash result of a just-completed Resend/Revoke action, read
	 * from the query string `redirect_with_notice()` redirected with.
	 *
	 * @return void
	 */
	private function render_flash_notice() {
		if ( ! isset( $_GET['gl_invite_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash flag, not a state-changing action; the actions themselves are nonce-verified in handle_resend()/handle_revoke().
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['gl_invite_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.

		$messages = array(
			'resend_success'     => array( 'success', __( 'Invite resent.', 'game-library' ) ),
			'resend_mail_failed' => array( 'error', __( 'The invite could not be emailed. Try again.', 'game-library' ) ),
			'resend_error'       => array( 'error', __( 'That invite could not be resent.', 'game-library' ) ),
			'revoke_success'     => array( 'success', __( 'Invite revoked.', 'game-library' ) ),
			'revoke_error'       => array( 'error', __( 'That invite could not be revoked.', 'game-library' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		list( $type, $text ) = $messages[ $notice ];

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}

	/**
	 * The "N invites" total-count summary shown above the filter views.
	 *
	 * @return string
	 */
	private function total_items_label() {
		$total = (int) $this->get_pagination_arg( 'total_items' );

		return sprintf(
			/* translators: %d: number of invites. */
			_n( '%d invite', '%d invites', $total, 'game-library' ),
			$total
		);
	}

	/**
	 * The sanitized status filter, validated against the D40 allowlist —
	 * any value outside `STATUSES` (including an absent or empty request
	 * value) resolves to '' (no filter, i.e. "All").
	 *
	 * @return string
	 */
	private function current_status_filter() {
		if ( ! isset( $_GET['invite_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter param, not a state-changing action.
			return '';
		}

		$status = sanitize_key( wp_unslash( $_GET['invite_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.

		return in_array( $status, self::STATUSES, true ) ? $status : '';
	}

	/**
	 * Builds one nonced `admin-post.php` row-action URL. The nonce action
	 * string embeds the invite id (`{action}_{id}`) so one row's link can
	 * never be replayed against another row.
	 *
	 * @param string $action Either RESEND_ACTION or REVOKE_ACTION.
	 * @param int    $id     Invite id.
	 * @return string
	 */
	private function build_action_url( $action, $id ) {
		$id = absint( $id );

		$url = add_query_arg(
			array(
				'action'    => $action,
				'invite_id' => $id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, $action . '_' . $id );
	}

	/**
	 * A user's display name, or a placeholder when the account no longer
	 * exists.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private function user_display_name( $user_id ) {
		$user = get_userdata( absint( $user_id ) );

		return $user ? $user->display_name : __( '(deleted user)', 'game-library' );
	}
}
