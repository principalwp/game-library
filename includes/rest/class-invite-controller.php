<?php
/**
 * Invite create/revoke/resend (`/invites`, `/invites/{id}`,
 * `/invites/{id}/resend`).
 *
 * @package Game_Library
 */

namespace Game_Library\Rest;

use Game_Library\Data\Invite_Repository;
use Game_Library\Invites\Invite_Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Invite_Controller.
 *
 * Registers every REST route the `/invites/` screen (this task's own
 * templates/invites.php + assets/js/invites.js) needs:
 *
 * - `POST /invites` — create an invite (AC-024, AC-025, AC-026), gated to
 *   `gl_issue_invites`. All quota/code-collision/delivery logic lives in
 *   `Invite_Service::create_invite()`/`Invite_Repository::insert_pending()`
 *   (Task 7/8) — this class only translates the result to a REST response.
 * - `DELETE /invites/{id}` — revoke an invite the caller owns (or can
 *   `manage_options`). Never deletes the row (D40) — delegates entirely to
 *   `Invite_Repository::revoke()`'s own `status = 'pending'` guard.
 * - `POST /invites/{id}/resend` — re-send a still-pending email invite the
 *   caller owns (or can `manage_options`), via
 *   `Invite_Service::resend_invite()`. Not surfaced by this task's own
 *   `invites.php` (the Front-End Views table lists no resend element for
 *   `/invites/` — only the admin screen, Task 9, offers one), but the route
 *   itself is part of this task's own Description and Files list regardless.
 *
 * Every error this class's handlers can produce carries an explicit HTTP
 * status via `map_invite_error()` below — a small, locally-scoped
 * code=>status map, deliberately not added to the shared
 * `REST_Controller::ERROR_STATUS_MAP` (that file is outside this task's Files
 * list; see the class's own boundary comment). `gl_invite_quota_exceeded`
 * maps to 429 (AC-024); `gl_invite_row_limit` (CO-4, cycle-8) is the
 * separate row-creation ceiling `Invite_Service::create_invite()` also
 * enforces and maps to 429 too, but carries its own undated message rather
 * than reusing the quota's date-naming one — see that check's own comment.
 * Every other mapped code is a 4xx caused by bad input or a
 * permission/state mismatch the permission_callback did not already catch.
 * Any unmapped code (a genuine database failure) falls through to
 * `WP_REST_Server`'s own 500 default, same as every other controller here.
 */
final class Invite_Controller extends REST_Controller {

	/**
	 * REST base — nominal only; every route below is registered at its own
	 * literal path, matching `Social_Controller`'s own convention.
	 *
	 * @var string
	 */
	protected $rest_base = 'invites';

	/**
	 * `WP_Error` code => HTTP status for the errors `Invite_Service`/
	 * `Invite_Repository` can produce. See the class docblock for why this is
	 * scoped locally rather than added to `REST_Controller::ERROR_STATUS_MAP`.
	 *
	 * @var array<string,int>
	 */
	private const ERROR_STATUS_MAP = array(
		'gl_invalid_invite'         => 400,
		'gl_invalid_invite_email'   => 400,
		'gl_invite_quota_exceeded'  => 429,
		// CO-4 (cycle-8): the row-creation ceiling's own distinct code —
		// see the class docblock and Invite_Service::create_invite()'s own
		// comment for why it is not gl_invite_quota_exceeded.
		'gl_invite_row_limit'       => 429,
		'gl_invite_not_found'       => 404,
		'gl_invite_not_pending'     => 400,
		'gl_invite_no_email'        => 400,
		// SE-1: resend_invite()'s own per-invite cooldown/limit throttle.
		'gl_invite_resend_cooldown' => 429,
		'gl_invite_resend_limit'    => 429,
	);

	/**
	 * Invite data access — used directly for the revoke route and to look up
	 * an invite's owner in `ownership_permissions_check()`.
	 *
	 * @var Invite_Repository
	 */
	private $repository;

	/**
	 * Code generation, quota enforcement, and delivery.
	 *
	 * @var Invite_Service
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param Invite_Repository|null $repository Invite data access. Defaults
	 *                                            to a new instance.
	 * @param Invite_Service|null    $service    Invite delivery. Defaults to
	 *                                            a new instance built from
	 *                                            $repository.
	 */
	public function __construct( ?Invite_Repository $repository = null, ?Invite_Service $service = null ) {
		$this->repository = $repository ?: new Invite_Repository();
		$this->service     = $service ?: new Invite_Service( $this->repository );
	}

	/**
	 * Registers this controller's routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/invites',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_invite' ),
					'permission_callback' => array( $this, 'create_permissions_check' ),
					'args'                => array(
						'email' => array(
							'description'       => __( 'Recipient email for this invite. Omit for a link-only invite.', 'game-library' ),
							'type'              => 'string',
							'format'            => 'email',
							'sanitize_callback' => 'sanitize_email',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/invites/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke_invite' ),
					'permission_callback' => array( $this, 'ownership_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'Invite id to revoke.', 'game-library' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/invites/(?P<id>[\d]+)/resend',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'resend_invite' ),
					'permission_callback' => array( $this, 'ownership_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'Invite id to resend.', 'game-library' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission callback for `POST /invites` — `gl_issue_invites` is the
	 * only gate (AC-024's "any member with gl_issue_invites").
	 *
	 * @return true|WP_Error
	 */
	public function create_permissions_check() {
		return $this->require_capability( 'gl_issue_invites' );
	}

	/**
	 * Permission callback for `DELETE /invites/{id}` and
	 * `POST /invites/{id}/resend` — the caller must own the invite (be its
	 * `inviter_id`) or hold `manage_options` (this task's own constraint).
	 *
	 * SE-3 (cycle-3, CWE-204): the informative 404 (an unknown id) is
	 * returned only to a caller who holds `manage_options` — an
	 * administrator can already read the whole `gl_invites` table on the
	 * Invites screen, so it discloses nothing new there. Every other
	 * logged-in caller gets the identical 403 whether the id belongs to
	 * someone else or does not exist at all: distinguishing the two
	 * previously let any authenticated member walk ids and enumerate the
	 * site's total invite volume and where the auto-increment sequence
	 * currently sits, which "resolves the 404 case up front so neither
	 * handler needs to re-fetch the row" (this method's own prior
	 * rationale) was a convenience, not a requirement, to expose. DO NOT
	 * simplify this back to one shared branch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function ownership_permissions_check( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return $this->require_login();
		}

		$id     = absint( $request->get_param( 'id' ) );
		$invite = $this->repository->get_by_id( $id );

		if ( current_user_can( 'manage_options' ) ) {
			return $invite ? true : $this->not_found( __( 'That invite could not be found.', 'game-library' ) );
		}

		if ( $invite && get_current_user_id() === (int) $invite['inviter_id'] ) {
			return true;
		}

		return $this->forbidden( __( 'You are not allowed to manage that invite.', 'game-library' ) );
	}

	/**
	 * Handles `POST /invites` (AC-024, AC-025, AC-026). Returns the code and
	 * full redemption URL, plus the caller's refreshed quota figures, on
	 * every success regardless of `mail_sent` — the client renders the
	 * delivery warning itself when `mail_sent` is `false` (this task's own
	 * constraint: "the response renders the code and the full redemption URL
	 * ... regardless of wp_mail() success").
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_invite( WP_REST_Request $request ) {
		$email  = $request->get_param( 'email' );
		$email  = is_string( $email ) ? $email : '';
		$result = $this->service->create_invite( get_current_user_id(), $email );

		if ( is_wp_error( $result ) ) {
			return $this->map_invite_error( $result );
		}

		$response = rest_ensure_response( $this->format_delivery( $result ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Handles `DELETE /invites/{id}`. `ownership_permissions_check()` has
	 * already confirmed the invite exists and is owned by the caller (or the
	 * caller can `manage_options`) — a `revoke()` failure here can only mean
	 * the invite is no longer `pending` (already redeemed, expired, or
	 * revoked).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revoke_invite( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		if ( $this->repository->revoke( $id ) ) {
			return rest_ensure_response(
				array(
					'id'     => $id,
					'status' => 'revoked',
				)
			);
		}

		return $this->map_invite_error(
			new WP_Error( 'gl_invite_not_pending', __( 'Only a pending invite can be revoked.', 'game-library' ) )
		);
	}

	/**
	 * Handles `POST /invites/{id}/resend`. Not called by this task's own
	 * `invites.js` — see the class docblock — but exercised directly at the
	 * REST layer per this task's own Description.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resend_invite( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$result = $this->service->resend_invite( $id );

		if ( is_wp_error( $result ) ) {
			return $this->map_invite_error( $result );
		}

		return rest_ensure_response( $this->format_delivery( $result ) );
	}

	/**
	 * Shapes an `Invite_Service::create_invite()`/`resend_invite()` result
	 * into the payload `invites.js` renders a row from, plus the caller's
	 * refreshed quota figures (`quota_status()`) so the client can update its
	 * "N invites remaining" display without a full page reload.
	 *
	 * @param array{invite:array<string,mixed>,redemption_url:string,mail_sent:?bool} $result Service result.
	 * @return array<string,mixed>
	 */
	private function format_delivery( array $result ) {
		$invite = $result['invite'];

		return array(
			'id'             => $invite['id'],
			'code'           => $invite['code'],
			'redemption_url' => $result['redemption_url'],
			'channel'        => $invite['channel'],
			'email'          => $invite['email'],
			'status'         => $invite['status'],
			'date_created'   => $invite['date_created'],
			'date_expires'   => $invite['date_expires'],
			'mail_sent'      => $result['mail_sent'],
			'quota'          => $this->service->quota_status( $invite['inviter_id'] ),
		);
	}

	/**
	 * Maps an invite-taxonomy `WP_Error` onto the HTTP status this route
	 * commits to for that code — see the class docblock. Any other error code
	 * is returned unchanged, falling through to `WP_REST_Server`'s own 500
	 * default.
	 *
	 * @param WP_Error $error Error to map.
	 * @return WP_Error
	 */
	private function map_invite_error( WP_Error $error ) {
		$code = $error->get_error_code();

		if ( ! isset( self::ERROR_STATUS_MAP[ $code ] ) ) {
			return $error;
		}

		$data            = $error->get_error_data( $code );
		$data            = is_array( $data ) ? $data : array();
		$data['status']  = self::ERROR_STATUS_MAP[ $code ];

		return new WP_Error( $code, $error->get_error_message(), $data );
	}
}
