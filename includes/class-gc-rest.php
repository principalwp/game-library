<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GCOLLECTOR_REST {
	const NAMESPACE = 'game-collector/v1';

	private $igdb;
	private $statuses = array( 'playing', 'finished', 'backlog', 'wishlist' );

	public function __construct( GCOLLECTOR_IGDB $igdb ) {
		$this->igdb = $igdb;
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			self::NAMESPACE,
			'/games/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_games' ),
				'permission_callback' => array( $this, 'logged_in' ),
				'args'                => array(
					'q' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/library/(?P<user_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_library' ),
					'permission_callback' => array( $this, 'logged_in' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/library',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_game' ),
				'permission_callback' => array( $this, 'logged_in' ),
				'args'                => array(
					'igdb_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'status'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/library/(?P<igdb_id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_game' ),
					'permission_callback' => array( $this, 'logged_in' ),
					'args'                => array(
						'status' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_game' ),
					'permission_callback' => array( $this, 'logged_in' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/feed',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_feed' ),
				'permission_callback' => array( $this, 'logged_in' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/members',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_members' ),
				'permission_callback' => array( $this, 'logged_in' ),
				'args'                => array(
					'q' => array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/follows/(?P<user_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'follow' ),
					'permission_callback' => array( $this, 'logged_in' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unfollow' ),
					'permission_callback' => array( $this, 'logged_in' ),
				),
			)
		);
	}

	public function logged_in() {
		return is_user_logged_in()
			? true
			: new WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'game-collector' ), array( 'status' => 401 ) );
	}

	public function search_games( WP_REST_Request $request ) {
		return $this->igdb->search( $request->get_param( 'q' ) );
	}

	public function get_library( WP_REST_Request $request ) {
		global $wpdb;

		$user_id = absint( $request['user_id'] );
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', __( 'Collector not found.', 'game-collector' ), array( 'status' => 404 ) );
		}

		$items = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT g.igdb_id, g.name, g.slug, g.cover_url, g.release_date, g.platforms, g.summary,
				l.status, l.created_at, l.updated_at
				FROM ' . GCOLLECTOR_Database::table( 'library' ) . ' l
				INNER JOIN ' . GCOLLECTOR_Database::table( 'games' ) . ' g ON g.id = l.game_id
				WHERE l.user_id = %d ORDER BY l.updated_at DESC',
				$user_id
			),
			ARRAY_A
		);

		foreach ( $items as &$item ) {
			$item['igdb_id']   = absint( $item['igdb_id'] );
			$item['platforms'] = json_decode( $item['platforms'], true );
			if ( ! is_array( $item['platforms'] ) ) {
				$item['platforms'] = array();
			}
		}

		$current_id = get_current_user_id();
		return array(
			'user'      => $this->user_data( $user ),
			'is_current' => $current_id === $user_id,
			'following'  => $current_id === $user_id ? false : $this->is_following( $current_id, $user_id ),
			'items'      => $items,
		);
	}

	public function add_game( WP_REST_Request $request ) {
		global $wpdb;

		$status = sanitize_key( $request->get_param( 'status' ) );
		if ( ! $this->valid_status( $status ) ) {
			return new WP_Error( 'invalid_status', __( 'Choose a valid status.', 'game-collector' ), array( 'status' => 400 ) );
		}

		$game = $this->igdb->get_game( $request->get_param( 'igdb_id' ) );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$game_id = $this->store_game( $game );
		if ( is_wp_error( $game_id ) ) {
			return $game_id;
		}

		$user_id = get_current_user_id();
		$exists  = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . GCOLLECTOR_Database::table( 'library' ) . ' WHERE user_id = %d AND game_id = %d',
				$user_id,
				$game_id
			)
		);
		if ( $exists ) {
			return new WP_Error( 'already_in_library', __( 'That game is already in your library.', 'game-collector' ), array( 'status' => 409 ) );
		}

		$now      = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			GCOLLECTOR_Database::table( 'library' ),
			array(
				'user_id'    => $user_id,
				'game_id'    => $game_id,
				'status'     => $status,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'library_write_failed', __( 'The game could not be added.', 'game-collector' ), array( 'status' => 500 ) );
		}

		$this->activity( $user_id, 'added', $game_id, 0, array( 'status' => $status ) );
		return new WP_REST_Response( array( 'message' => __( 'Game added.', 'game-collector' ) ), 201 );
	}

	public function update_game( WP_REST_Request $request ) {
		global $wpdb;

		$status = sanitize_key( $request->get_param( 'status' ) );
		if ( ! $this->valid_status( $status ) ) {
			return new WP_Error( 'invalid_status', __( 'Choose a valid status.', 'game-collector' ), array( 'status' => 400 ) );
		}

		$row = $this->get_library_row( get_current_user_id(), absint( $request['igdb_id'] ) );
		if ( ! $row ) {
			return new WP_Error( 'library_item_not_found', __( 'That game is not in your library.', 'game-collector' ), array( 'status' => 404 ) );
		}
		if ( $row->status === $status ) {
			return array( 'message' => __( 'Status unchanged.', 'game-collector' ) );
		}

		$updated = $wpdb->update(
			GCOLLECTOR_Database::table( 'library' ),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $row->id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'library_write_failed', __( 'The status could not be updated.', 'game-collector' ), array( 'status' => 500 ) );
		}

		$this->activity(
			get_current_user_id(),
			'status_changed',
			absint( $row->game_id ),
			0,
			array( 'from' => $row->status, 'to' => $status )
		);

		return array( 'message' => __( 'Status updated.', 'game-collector' ) );
	}

	public function remove_game( WP_REST_Request $request ) {
		global $wpdb;

		$row = $this->get_library_row( get_current_user_id(), absint( $request['igdb_id'] ) );
		if ( ! $row ) {
			return new WP_Error( 'library_item_not_found', __( 'That game is not in your library.', 'game-collector' ), array( 'status' => 404 ) );
		}

		$wpdb->delete( GCOLLECTOR_Database::table( 'library' ), array( 'id' => absint( $row->id ) ), array( '%d' ) );
		$this->activity( get_current_user_id(), 'removed', absint( $row->game_id ), 0, array() );

		return array( 'message' => __( 'Game removed.', 'game-collector' ) );
	}

	public function follow( WP_REST_Request $request ) {
		global $wpdb;

		$follower_id = get_current_user_id();
		$followed_id = absint( $request['user_id'] );
		if ( $follower_id === $followed_id ) {
			return new WP_Error( 'cannot_follow_self', __( 'You cannot follow yourself.', 'game-collector' ), array( 'status' => 400 ) );
		}
		if ( ! get_userdata( $followed_id ) ) {
			return new WP_Error( 'user_not_found', __( 'Collector not found.', 'game-collector' ), array( 'status' => 404 ) );
		}

		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . GCOLLECTOR_Database::table( 'follows' ) . ' (follower_id, followed_id, created_at) VALUES (%d, %d, %s)',
				$follower_id,
				$followed_id,
				current_time( 'mysql', true )
			)
		);
		if ( $inserted ) {
			$this->activity( $follower_id, 'followed', 0, $followed_id, array() );
		}

		return array( 'following' => true );
	}

	public function unfollow( WP_REST_Request $request ) {
		global $wpdb;

		$wpdb->delete(
			GCOLLECTOR_Database::table( 'follows' ),
			array(
				'follower_id' => get_current_user_id(),
				'followed_id' => absint( $request['user_id'] ),
			),
			array( '%d', '%d' )
		);

		return array( 'following' => false );
	}

	public function get_feed() {
		global $wpdb;

		$user_id = get_current_user_id();
		$actors  = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT followed_id FROM ' . GCOLLECTOR_Database::table( 'follows' ) . ' WHERE follower_id = %d',
				$user_id
			)
		);
		$actors[] = $user_id;
		$actors   = array_values( array_unique( array_map( 'absint', $actors ) ) );
		$marks    = implode( ',', array_fill( 0, count( $actors ), '%d' ) );

		$sql = 'SELECT a.id, a.actor_id, a.verb, a.meta, a.created_at, u.display_name,
			g.igdb_id, g.name AS game_name, g.cover_url, target.display_name AS target_name
			FROM ' . GCOLLECTOR_Database::table( 'activities' ) . ' a
			INNER JOIN ' . $wpdb->users . ' u ON u.ID = a.actor_id
			LEFT JOIN ' . GCOLLECTOR_Database::table( 'games' ) . ' g ON g.id = a.game_id
			LEFT JOIN ' . $wpdb->users . ' target ON target.ID = a.target_user_id
			WHERE a.actor_id IN (' . $marks . ')
			ORDER BY a.created_at DESC LIMIT 50';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $actors ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $rows as &$row ) {
			$row['id']       = absint( $row['id'] );
			$row['actor_id'] = absint( $row['actor_id'] );
			$row['igdb_id']  = absint( $row['igdb_id'] );
			$row['meta']     = json_decode( $row['meta'], true );
			if ( ! is_array( $row['meta'] ) ) {
				$row['meta'] = array();
			}
			$row['avatar_url'] = get_avatar_url( $row['actor_id'], array( 'size' => 80 ) );
			$row['created_at'] = mysql_to_rfc3339( $row['created_at'] );
		}

		return $rows;
	}

	public function get_members( WP_REST_Request $request ) {
		global $wpdb;

		$args = array(
			'number'  => 30,
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'exclude' => array( get_current_user_id() ),
			'fields'  => array( 'ID', 'display_name' ),
		);
		$query = trim( (string) $request->get_param( 'q' ) );
		if ( '' !== $query ) {
			$args['search']         = '*' . $query . '*';
			$args['search_columns'] = array( 'display_name', 'user_login' );
		}

		$users = ( new WP_User_Query( $args ) )->get_results();
		if ( empty( $users ) ) {
			return array();
		}

		$ids       = array_map( 'absint', wp_list_pluck( $users, 'ID' ) );
		$marks     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$count_sql = 'SELECT user_id, COUNT(*) AS game_count FROM ' . GCOLLECTOR_Database::table( 'library' ) . ' WHERE user_id IN (' . $marks . ') GROUP BY user_id';
		$counts    = $wpdb->get_results( $wpdb->prepare( $count_sql, $ids ), OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$following = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT followed_id FROM ' . GCOLLECTOR_Database::table( 'follows' ) . ' WHERE follower_id = %d',
				get_current_user_id()
			)
		);
		$following = array_map( 'absint', $following );

		$data = array();
		foreach ( $users as $user ) {
			$id     = absint( $user->ID );
			$data[] = array(
				'id'          => $id,
				'display_name' => $user->display_name,
				'avatar_url'   => get_avatar_url( $id, array( 'size' => 128 ) ),
				'game_count'   => isset( $counts[ $id ] ) ? absint( $counts[ $id ]->game_count ) : 0,
				'following'    => in_array( $id, $following, true ),
			);
		}

		return $data;
	}

	private function store_game( $game ) {
		global $wpdb;

		$table    = GCOLLECTOR_Database::table( 'games' );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE igdb_id = %d", absint( $game['igdb_id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data     = array(
			'igdb_id'     => absint( $game['igdb_id'] ),
			'name'        => sanitize_text_field( $game['name'] ),
			'slug'        => sanitize_title( $game['slug'] ),
			'cover_url'   => esc_url_raw( $game['cover_url'] ),
			'release_date'=> $game['release_date'] ? $game['release_date'] . ' 00:00:00' : null,
			'platforms'   => wp_json_encode( $game['platforms'] ),
			'summary'     => sanitize_textarea_field( $game['summary'] ),
			'updated_at'  => current_time( 'mysql', true ),
		);

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => absint( $existing ) ) );
			return absint( $existing );
		}

		$data['created_at'] = current_time( 'mysql', true );
		$inserted           = $wpdb->insert( $table, $data );
		if ( ! $inserted ) {
			$race_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE igdb_id = %d", absint( $game['igdb_id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $race_id ? absint( $race_id ) : new WP_Error( 'game_write_failed', __( 'The game could not be saved.', 'game-collector' ), array( 'status' => 500 ) );
		}

		return absint( $wpdb->insert_id );
	}

	private function get_library_row( $user_id, $igdb_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT l.* FROM ' . GCOLLECTOR_Database::table( 'library' ) . ' l
				INNER JOIN ' . GCOLLECTOR_Database::table( 'games' ) . ' g ON g.id = l.game_id
				WHERE l.user_id = %d AND g.igdb_id = %d LIMIT 1',
				absint( $user_id ),
				absint( $igdb_id )
			)
		);
	}

	private function valid_status( $status ) {
		return in_array( $status, $this->statuses, true );
	}

	private function is_following( $follower_id, $followed_id ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . GCOLLECTOR_Database::table( 'follows' ) . ' WHERE follower_id = %d AND followed_id = %d',
				absint( $follower_id ),
				absint( $followed_id )
			)
		);
	}

	private function activity( $actor_id, $verb, $game_id, $target_user_id, $meta ) {
		global $wpdb;

		$wpdb->insert(
			GCOLLECTOR_Database::table( 'activities' ),
			array(
				'actor_id'      => absint( $actor_id ),
				'verb'          => sanitize_key( $verb ),
				'game_id'       => $game_id ? absint( $game_id ) : null,
				'target_user_id'=> $target_user_id ? absint( $target_user_id ) : null,
				'meta'          => wp_json_encode( $meta ),
				'created_at'    => current_time( 'mysql', true ),
			)
		);
	}

	private function user_data( $user ) {
		return array(
			'id'           => absint( $user->ID ),
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 160 ) ),
		);
	}
}
