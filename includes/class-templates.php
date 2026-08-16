<?php
/**
 * Template rendering: the document shell, route templates, partials and the
 * shared escaping / markup helpers used by both templates and the REST layer.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * Renders plugin-owned documents and reusable markup fragments.
 */
class Templates {

	/**
	 * Per-page sizes.
	 */
	const LIBRARY_PER_PAGE = 24;
	const CATALOG_PER_PAGE = 24;
	const MEMBERS_PER_PAGE = 20;
	const FEED_PER_PAGE    = 20;

	/**
	 * Render a full plugin document for a route.
	 *
	 * @param string               $route   Route slug.
	 * @param array<string, mixed> $context Route context.
	 * @return void
	 */
	public function render( $route, $context ) {
		$file = $this->template_file( $route );
		if ( ! is_readable( $file ) ) {
			return;
		}
		// $route, $context and $file are in scope inside layout.php; so is $this.
		require GL_PATH . 'templates/layout.php';
	}

	/**
	 * Map a route slug to its template file.
	 *
	 * @param string $route Route slug.
	 * @return string
	 */
	private function template_file( $route ) {
		$map  = array(
			'my-library'     => 'my-library.php',
			'member-library' => 'member-library.php',
			'activity'       => 'activity.php',
			'members'        => 'members.php',
			'invites'        => 'invites.php',
			'join'           => 'join.php',
			'games'          => 'games.php',
			'game-single'    => 'games-single.php',
		);
		$name = isset( $map[ $route ] ) ? $map[ $route ] : '';
		return GL_PATH . 'templates/' . $name;
	}

	/**
	 * Include a partial with a single $args array in scope.
	 *
	 * @param string               $name Partial name (no extension).
	 * @param array<string, mixed> $args Data for the partial.
	 * @return void
	 */
	public function partial( $name, array $args = array() ) {
		$file = GL_PATH . 'templates/partials/' . $name . '.php';
		if ( is_readable( $file ) ) {
			include $file;
		}
	}

	/**
	 * Buffer a partial to a string.
	 *
	 * @param string               $name Partial name.
	 * @param array<string, mixed> $args Data.
	 * @return string
	 */
	public function partial_to_string( $name, array $args = array() ) {
		ob_start();
		$this->partial( $name, $args );
		return (string) ob_get_clean();
	}

	/**
	 * Echo pre-escaped markup built by this class's helper methods.
	 *
	 * Every string passed here is assembled from esc_* calls inside the helper
	 * that produced it; this is the single, audited escaping boundary for that
	 * generated markup.
	 *
	 * @param string $html Pre-escaped markup.
	 * @return void
	 */
	public function output( $html ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup pre-escaped by the producing helper.
	}

	/**
	 * Render an owner (editable) game card.
	 *
	 * @param object $entry Library entry joined to game data.
	 * @return string
	 */
	public function render_owner_card( $entry ) {
		return $this->partial_to_string(
			'game-card',
			array(
				'entry' => $entry,
				'owner' => true,
				'index' => 1,
			)
		);
	}

	/**
	 * Render a status badge.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public function render_status_badge( $status ) {
		return sprintf(
			'<span class="gl-status-badge gl-status-badge--%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( $this->status_label( $status ) )
		);
	}

	/**
	 * Render an invite row.
	 *
	 * @param object $invite Invite row.
	 * @return string
	 */
	public function render_invite_row( $invite ) {
		return $this->partial_to_string( 'invite-row', array( 'invite' => $invite ) );
	}


	/**
	 * The human label for a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public function status_label( $status ) {
		switch ( $status ) {
			case 'playing':
				return __( 'Playing', 'game-library-3' );
			case 'finished':
				return __( 'Finished', 'game-library-3' );
			case 'backlog':
				return __( 'Backlog', 'game-library-3' );
			case 'wishlist':
				return __( 'Wishlist', 'game-library-3' );
			default:
				return ucfirst( (string) $status );
		}
	}

	/**
	 * A cover <img> tag, or '' when there is no cover image.
	 *
	 * @param string|null $cover_image_id Cover image id.
	 * @param string      $name           Game name (for alt).
	 * @param bool        $high_priority  First-row / hero cover.
	 * @return string
	 */
	public function cover_img_tag( $cover_image_id, $name, $high_priority = false ) {
		if ( empty( $cover_image_id ) ) {
			return '';
		}
		$url = Plugin::instance()->igdb()->cover_url( $cover_image_id );
		if ( '' === $url ) {
			return '';
		}

		$loading = $high_priority ? ' fetchpriority="high"' : ' loading="lazy"';
		return sprintf(
			'<img src="%1$s" alt="%2$s"%3$s>',
			esc_url( $url ),
			esc_attr( sprintf( /* translators: %s: game title. */ __( '%s cover art', 'game-library-3' ), $name ) ),
			$loading // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string.
		);
	}

	/**
	 * A member's display name linked to their library.
	 *
	 * @param int|object $user User id or WP_User.
	 * @return string
	 */
	public function member_link( $user ) {
		$user = is_object( $user ) ? $user : get_userdata( (int) $user );
		if ( ! $user ) {
			return esc_html__( 'Former member', 'game-library-3' );
		}
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $this->member_url( $user->user_nicename ) ),
			esc_html( $user->display_name )
		);
	}

	/**
	 * The public library URL for a nicename.
	 *
	 * @param string $nicename User nicename.
	 * @return string
	 */
	public function member_url( $nicename ) {
		return home_url( '/library/' . rawurlencode( $nicename ) . '/' );
	}

	/**
	 * The absolute join URL for a code.
	 *
	 * @param string $code Invite code.
	 * @return string
	 */
	public function join_url( $code ) {
		return home_url( '/join/' . rawurlencode( $code ) . '/' );
	}

	/**
	 * Render the five underlined mono filter tabs.
	 *
	 * @param string             $base_url Route base URL.
	 * @param array<string, int> $counts   Per-status counts.
	 * @param int                $total    Total count (All).
	 * @param string             $active   Active status ('' = All).
	 * @return string
	 */
	public function status_filters( $base_url, array $counts, $total, $active ) {
		$tabs = array( '' => __( 'All', 'game-library-3' ) );
		foreach ( Library_Repository::STATUSES as $status ) {
			$tabs[ $status ] = $this->status_label( $status );
		}

		$out = '<ul id="gl-status-filters" class="gl-status-filters">';
		foreach ( $tabs as $status => $label ) {
			$count   = ( '' === $status ) ? (int) $total : (int) ( isset( $counts[ $status ] ) ? $counts[ $status ] : 0 );
			$url     = ( '' === $status ) ? $base_url : add_query_arg( 'gl_status', $status, $base_url );
			$current = ( $status === $active ) ? ' aria-current="true"' : '';
			$aria    = sprintf(
				/* translators: 1: tab label, 2: game count. */
				_n( '%1$s, %2$d game', '%1$s, %2$d games', $count, 'game-library-3' ),
				$label,
				$count
			);
			$out .= sprintf(
				'<li><a href="%1$s"%2$s aria-label="%3$s">%4$s <span class="gl-status-filters__count">%5$s</span></a></li>',
				esc_url( $url ),
				$current, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute.
				esc_attr( $aria ),
				esc_html( $label ),
				esc_html( (string) number_format_i18n( $count ) )
			);
		}
		$out .= '</ul>';
		return $out;
	}

	/**
	 * Render pagination (prev / status / next), or '' for a single page.
	 *
	 * @param string               $base_url     Route base URL.
	 * @param int                  $current_page Current page (1-based).
	 * @param int                  $total_pages  Total pages.
	 * @param array<string, mixed> $extra        Extra query args to preserve.
	 * @return string
	 */
	public function pagination( $base_url, $current_page, $total_pages, array $extra = array() ) {
		if ( $total_pages <= 1 ) {
			return '';
		}

		$page_url = function ( $page ) use ( $base_url, $extra ) {
			$args = $extra;
			if ( $page > 1 ) {
				$args['gl_page'] = $page;
			}
			return empty( $args ) ? $base_url : add_query_arg( $args, $base_url );
		};

		$out = '<nav class="gl-pagination" aria-label="' . esc_attr__( 'Pagination', 'game-library-3' ) . '">';

		if ( $current_page > 1 ) {
			$out .= '<a class="gl-pagination__link" href="' . esc_url( $page_url( $current_page - 1 ) ) . '">' . esc_html__( '← Newer', 'game-library-3' ) . '</a>';
		} else {
			$out .= '<span class="gl-pagination__link" aria-disabled="true">' . esc_html__( '← Newer', 'game-library-3' ) . '</span>';
		}

		$out .= '<span class="gl-pagination__status">' . esc_html(
			sprintf(
				/* translators: 1: current page, 2: total pages. */
				__( 'Page %1$d of %2$d', 'game-library-3' ),
				(int) $current_page,
				(int) $total_pages
			)
		) . '</span>';

		if ( $current_page < $total_pages ) {
			$out .= '<a class="gl-pagination__link" href="' . esc_url( $page_url( $current_page + 1 ) ) . '">' . esc_html__( 'Older →', 'game-library-3' ) . '</a>';
		} else {
			$out .= '<span class="gl-pagination__link" aria-disabled="true">' . esc_html__( 'Older →', 'game-library-3' ) . '</span>';
		}

		$out .= '</nav>';
		return $out;
	}

	/**
	 * Render an empty state block.
	 *
	 * @param string $eyebrow Eyebrow text.
	 * @param string $line    Main line.
	 * @param string $cta     Optional CTA HTML.
	 * @return string
	 */
	public function empty_state( $eyebrow, $line, $cta = '' ) {
		return sprintf(
			'<div class="gl-empty-state"><span class="gl-empty-state__eyebrow">%1$s</span><p class="gl-empty-state__line">%2$s</p>%3$s</div>',
			esc_html( $eyebrow ),
			esc_html( $line ),
			$cta // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-built, escaped markup.
		);
	}

	/**
	 * The current 1-based page from the request.
	 *
	 * @return int
	 */
	public function current_page() {
		$page = absint( get_query_var( 'gl_page' ) );
		return max( 1, $page );
	}

	/**
	 * The IGDB attribution line.
	 *
	 * @return string
	 */
	public function attribution() {
		return '<p class="gl-attribution">' . sprintf(
			/* translators: %s: IGDB.com link. */
			wp_kses_post( __( 'Game data via %s', 'game-library-3' ) ),
			'<a href="https://www.igdb.com/" rel="noopener nofollow">IGDB.com</a>'
		) . '</p>';
	}

	/**
	 * A relative "x ago" time for an activity row.
	 *
	 * @param string $mysql_utc UTC datetime.
	 * @return string
	 */
	public function relative_time( $mysql_utc ) {
		$ts = strtotime( $mysql_utc . ' UTC' );
		if ( ! $ts ) {
			return '';
		}
		return sprintf(
			/* translators: %s: human time difference, e.g. "2 hours". */
			__( '%s ago', 'game-library-3' ),
			human_time_diff( $ts, time() )
		);
	}
}
