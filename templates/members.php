<?php
/**
 * /members/ — the member directory (logged-in members only).
 *
 * @package Game_Library
 *
 * @var \Game_Library\Templates $this    Template renderer.
 * @var array<string, mixed>    $context Route context.
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

$gl_viewer  = get_current_user_id();
$gl_plugin  = Plugin::instance();
$gl_vis     = $gl_plugin->visibility();
$gl_library = $gl_plugin->library();
$gl_follows = $gl_plugin->follows();

$gl_page   = $this->current_page();
$gl_per    = self::MEMBERS_PER_PAGE;
$gl_offset = ( $gl_page - 1 ) * $gl_per;

$gl_users = get_users(
	array(
		'number'  => $gl_per,
		'offset'  => $gl_offset,
		'orderby' => 'display_name',
		'order'   => 'ASC',
		'fields'  => array( 'ID', 'user_nicename', 'display_name' ),
	)
);

$gl_user_counts = count_users();
$gl_total       = isset( $gl_user_counts['total_users'] ) ? (int) $gl_user_counts['total_users'] : count( $gl_users );
$gl_pages       = (int) ceil( $gl_total / $gl_per );
$gl_base        = home_url( '/members/' );

$gl_ids      = wp_list_pluck( $gl_users, 'ID' );
// Prime the page's user set (users AND usermeta) so the per-row get_avatar()
// and Visibility::is_public() reads hit cache instead of one query each.
cache_users( array_map( 'intval', $gl_ids ) );
$gl_counts   = $gl_library->game_counts_for_users( array_map( 'intval', $gl_ids ) );
$gl_followed = array_flip( $gl_follows->followed_ids( $gl_viewer ) );
?>
<div class="gl-container">
	<header class="gl-page-head">
		<span class="gl-page-head__eyebrow"><?php esc_html_e( 'Community', 'game-library-3' ); ?></span>
		<h1><?php esc_html_e( 'Members', 'game-library-3' ); ?></h1>
	</header>

	<?php if ( empty( $gl_users ) ) : ?>
		<?php
		$this->output(
			$this->empty_state(
				__( 'No members yet', 'game-library-3' ),
				__( 'Invite someone to start the community.', 'game-library-3' )
			)
		);
		?>
	<?php else : ?>
		<ul class="gl-member-list">
			<?php
			foreach ( $gl_users as $gl_u ) {
				$gl_uid = (int) $gl_u->ID;
				$this->partial(
					'member-row',
					array(
						'user'         => $gl_u,
						'game_count'   => isset( $gl_counts[ $gl_uid ] ) ? (int) $gl_counts[ $gl_uid ] : 0,
						'is_public'    => $gl_vis->is_public( $gl_uid ),
						'is_self'      => ( $gl_uid === $gl_viewer ),
						'viewer'       => $gl_viewer,
						'is_following' => isset( $gl_followed[ $gl_uid ] ),
					)
				);
			}
			?>
		</ul>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pagination().
		echo $this->pagination( $gl_base, $gl_page, $gl_pages );
		?>
	<?php endif; ?>
</div>
