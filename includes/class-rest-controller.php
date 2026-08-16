<?php
/**
 * REST API: every game-library/v1 route, permission + nonce + ownership checks.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles all state-changing endpoints plus IGDB search.
 */
class Rest_Controller {

	const NAMESPACE = 'game-library/v1';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register every route.
	 *
	 * @return void
	 */
	public function register_routes() {
		$permission = array( $this, 'require_member' );

		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => $permission,
				'args'                => array(
					'term' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_string' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/library',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_to_library' ),
				'permission_callback' => $permission,
				'args'                => $this->library_add_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/library/(?P<id>\d+)/status',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'change_status' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id'     => $this->id_arg(),
					'status' => $this->status_arg(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/library/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'remove_from_library' ),
				'permission_callback' => $permission,
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/follow',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'follow' ),
					'permission_callback' => $permission,
					'args'                => array( 'user_id' => $this->id_arg() ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unfollow' ),
					'permission_callback' => $permission,
					'args'                => array( 'user_id' => $this->id_arg() ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/visibility',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_visibility' ),
				'permission_callback' => $permission,
				'args'                => array(
					'value' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_visibility' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/invites',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_invite' ),
				'permission_callback' => $permission,
				'args'                => array(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/invites/(?P<id>\d+)/revoke',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'revoke_invite' ),
				'permission_callback' => $permission,
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);
	}

	/**
	 * Permission callback: logged-in members only.
	 *
	 * @return bool
	 */
	public function require_member() {
		return is_user_logged_in() && current_user_can( 'read' );
	}

	/**
	 * Verify the wp_rest nonce in addition to the capability check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	private function require_nonce( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'gl_bad_nonce', __( 'Your session token is missing or expired. Please reload and try again.', 'game-library-3' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * A required positive-integer argument schema.
	 *
	 * @return array<string, mixed>
	 */
	private function id_arg() {
		return array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => array( $this, 'validate_positive_int' ),
		);
	}

	/**
	 * A required status argument schema (allowlist-validated).
	 *
	 * @return array<string, mixed>
	 */
	private function status_arg() {
		return array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => array( $this, 'validate_status' ),
		);
	}

	/**
	 * Args for adding a game to the library.
	 *
	 * @return array<string, mixed>
	 */
	private function library_add_args() {
		return array(
			'igdb_id'            => $this->id_arg(),
			'status'             => $this->status_arg(),
			'name'               => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_string' ),
			),
			'cover_image_id'     => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_optional_string' ),
			),
			'first_release_date' => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
				'validate_callback' => array( $this, 'validate_optional_int' ),
			),
			'genres'             => array(
				'type'              => 'array',
				'required'          => false,
				'sanitize_callback' => array( $this, 'sanitize_string_array' ),
				'validate_callback' => array( $this, 'validate_array' ),
			),
			'platforms'          => array(
				'type'              => 'array',
				'required'          => false,
				'sanitize_callback' => array( $this, 'sanitize_string_array' ),
				'validate_callback' => array( $this, 'validate_array' ),
			),
			'summary'            => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => array( $this, 'validate_optional_string' ),
			),
		);
	}

	/**
	 * Validate a non-empty string.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate_string( $value ) {
		return is_string( $value ) && '' !== trim( $value );
	}

	/**
	 * Validate a positive integer.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate_positive_int( $value ) {
		return is_numeric( $value ) && (int) $value > 0;
	}

	/**
	 * Validate an array.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate_array( $value ) {
		return is_array( $value );
	}

	/**
	 * Permissive validator for an optional string field (accepts the empty string).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate_optional_string( $value ) {
		return is_string( $value );
	}

	/**
	 * Permissive validator for an optional integer field (accepts 0 / numeric strings).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate_optional_int( $value ) {
		return is_numeric( $value );
	}

	/**
	 * Sanitize every element of an array of strings through sanitize_text_field.
	 *
	 * @param mixed $value Value.
	 * @return array<int, string>
	 */
	public function sanitize_string_array( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_map(
			static function ( $element ) {
				return sanitize_text_field( (string) $element );
			},
			$value
		);
	}

	/**
	 * Validate a status against the allowlist.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate_status( $value ) {
		return Library_Repository::is_status( $value );
	}

	/**
	 * Validate a visibility value.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate_visibility( $value ) {
		return in_array( $value, Visibility::VALUES, true );
	}

	/**
	 * GET /search.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function search( $request ) {
		$nonce = $this->require_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$term   = (string) $request->get_param( 'term' );
		$result = Plugin::instance()->igdb()->search( $term );

		return rest_ensure_response(
			array(
				'state' => $result['state'],
				'games' => $result['games'],
			)
		);
	}

	/**
	 * POST /library.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_to_library( $request ) {
		$nonce = $this->require_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$user_id = get_current_user_id();
		$status  = (string) $request->get_param( 'status' );

		$plugin  = Plugin::instance();
		$game_id = $plugin->games()->upsert_from_igdb(
			array(
				'igdb_id'            => $request->get_param( 'igdb_id' ),
				'name'               => $request->get_param( 'name' ),
				'cover_image_id'     => $request->get_param( 'cover_image_id' ),
				'first_release_date' => $request->get_param( 'first_release_date' ),
				'genres'             => (array) $request->get_param( 'genres' ),
				'platforms'          => (array) $request->get_param( 'platforms' ),
				'summary'            => $request->get_param( 'summary' ),
			)
		);

		if ( $game_id <= 0 ) {
			return new \WP_Error( 'gl_bad_game', __( 'That game could not be saved.', 'game-library-3' ), array( 'status' => 400 ) );
		}

		$result = $plugin->library()->add( $user_id, $game_id, $status );

		if ( ! empty( $result['duplicate'] ) ) {
			return rest_ensure_response(
				array(
					'duplicate' => true,
					'message'   => __( 'That game is already in your library.', 'game-library-3' ),
				)
			);
		}

		$plugin->activity()->log( $user_id, 'game-added', $game_id, null, $status );

		$entry = $plugin->library()->get_entry( $user_id, $game_id );

		return rest_ensure_response(
			array(
				'duplicate' => false,
				'status'    => $status,
				'card_html' => $plugin->templates()->render_owner_card( $entry ),
			)
		);
	}

	/**
	 * POST /library/{id}/status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function change_status( $request ) {
		$nonce = $this->require_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$id      = absint( $request->get_param( 'id' ) );
		$status  = (string) $request->get_param( 'status' );
		$user_id = get_current_user_id();

		$plugin = Plugin::instance();
		$row    = $plugin->library()->get_row( $id );
		if ( ! $row ) {
			return new \WP_Error( 'gl_not_found', __( 'Library entry not found.', 'game-library-3' ), array( 'status' => 404 ) );
		}
		if ( (int) $row->user_id !== $user_id ) {
			return new \WP_Error( 'gl_forbidden', __( 'You can only change your own games.', 'game-library-3' ), array( 'status' => 403 ) );
		}

		$change = $plugin->library()->change_status( $id, $user_id, $status );
		if ( null === $change ) {
			return new \WP_Error( 'gl_forbidden', __( 'You can only change your own games.', 'game-library-3' ), array( 'status' => 403 ) );
		}

		$plugin->activity()->log( $user_id, 'status-changed', $change['game_id'], $change['from'], $change['to'] );

		return rest_ensure_response(
			array(
				'status'     => $change['to'],
				'badge_html' => $plugin->templates()->render_status_badge( $change['to'] ),
			)
		);
	}

	/**
	 * DELETE /library/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_from_library( $request ) {
		$nonce = $this->require_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$id      = absint( $request->get_param( 'id' ) );
		$user_id = get_current_user_id();

		$plugin = Plugin::instance();
		$row    = $plugin->library()->get_row( $id );
		if ( ! $row ) {
			return new \WP_Error( 'gl_not_found', __( 'Library entry not found.', 'game-library-3' ), array( 'status' => 404 ) );
		}
		if ( (int) $row->user_id !== $user_id ) {
			return new \WP_Error( 'gl_forbidden', __( 'You can only remove your own games.', 'game-library-3' ), array( 'status' => 403 ) );
		}

		$plugin->library()->remove( $id, $user_id );

		return rest_ensure_response( array( 'removed' => true ) );
	}

	/**
	 * POST /follow.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function follow( $request ) {
		$nonce = $this->require_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$target  = absint( $request->get_param( 'user_id' ) );
		$user_id = get_current_user_id();

		if ( $target === $user_id ) {
			return new \WP_Error( 'gl_self_follow', __( 'You cannot follow yourself.', 'game-library-3' ), array( 'status' => 400 ) );
		}
		if ( ! get_userdata( $target ) ) {
			return new \WP_Error( 'gl_not_found', __( 'That member does not exist.', 'game-library-3' ), array( 'status' => 404 ) );
		}

		Plugin::instance()->follows()->follow( $user_id, $target );

		return rest_ensure_response( array( 'following' => true ) );
	}

	/**
	 * DELETE /follow.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function unfollow( $request ) {
		$nonce = $this->require_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$target  = absint( $request->get_param( 'user_id' ) );
		$user_id = get_current_user_id();

		Plugin::instance()->follows()->unfollow( $user_id, $target );

		return rest_ensure_response( array( 'following' => false ) );
	}

	/**
	 * POST /visibility.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_visibility( $request ) {
		$nonce = $this->require_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$value   = (string) $request->get_param( 'value' );
		$user_id = get_current_user_id();

		$stored = Plugin::instance()->visibility()->update( $user_id, $value );
		if ( null === $stored ) {
			return new \WP_Error( 'gl_bad_value', __( 'Invalid visibility value.', 'game-library-3' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'visibility' => $stored ) );
	}

	/**
	 * POST /invites — create one invite, quota enforced server-side.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_invite( $request ) {
		$nonce = $this->require_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$user_id = get_current_user_id();
		$plugin  = Plugin::instance();
		$invites = $plugin->invites();

		$quota = $invites->quota();
		$used  = $invites->used_this_month( $user_id );

		if ( $used >= $quota ) {
			return rest_ensure_response(
				array(
					'created'   => false,
					'reason'    => 'quota',
					'message'   => __( 'You have used all of your invites for this month.', 'game-library-3' ),
					'remaining' => 0,
				)
			);
		}

		$invite = $invites->create( $user_id );
		if ( ! $invite ) {
			return new \WP_Error( 'gl_invite_failed', __( 'The invite could not be created. Please try again.', 'game-library-3' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'created'   => true,
				'row_html'  => $plugin->templates()->render_invite_row( $invite ),
				'remaining' => $invites->remaining_this_month( $user_id ),
			)
		);
	}

	/**
	 * POST /invites/{id}/revoke.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke_invite( $request ) {
		$nonce = $this->require_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$id      = absint( $request->get_param( 'id' ) );
		$user_id = get_current_user_id();

		$plugin = Plugin::instance();
		$invite = $plugin->invites()->get( $id );
		if ( ! $invite ) {
			return new \WP_Error( 'gl_not_found', __( 'Invite not found.', 'game-library-3' ), array( 'status' => 404 ) );
		}
		if ( (int) $invite->inviter_id !== $user_id ) {
			return new \WP_Error( 'gl_forbidden', __( 'You can only revoke your own invites.', 'game-library-3' ), array( 'status' => 403 ) );
		}

		$revoked = $plugin->invites()->revoke( $id, $user_id );

		return rest_ensure_response(
			array(
				'revoked'   => (bool) $revoked,
				'remaining' => $plugin->invites()->remaining_this_month( $user_id ),
			)
		);
	}
}
