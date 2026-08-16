<?php
/**
 * Library page (own or another member's).
 *
 * Vars: $member (WP_User), $is_own (bool), $status_filter (string),
 *       $entries (object[]), $counts (array).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$labels    = GC_Library::status_labels();
$base_url  = $is_own ? GC_Frontend::my_library_url() : GC_Frontend::library_url( $member );
$following = ! $is_own && GC_Follows::is_following( get_current_user_id(), $member->ID );
?>
<div class="gc-wrap">

	<header class="gc-profile">
		<div class="gc-profile-avatar"><?php echo get_avatar( $member->ID, 72 ); ?></div>
		<div class="gc-profile-info">
			<h1 class="gc-profile-name">
				<?php
				if ( $is_own ) {
					esc_html_e( 'My Library', 'game-collector' );
				} else {
					/* translators: %s: member display name */
					printf( esc_html__( '%s’s Library', 'game-collector' ), esc_html( $member->display_name ) );
				}
				?>
			</h1>
			<p class="gc-profile-meta">
				<span><?php printf( esc_html( _n( '%s game', '%s games', $counts['all'], 'game-collector' ) ), esc_html( number_format_i18n( $counts['all'] ) ) ); ?></span>
				<span>&middot;</span>
				<span><?php printf( esc_html__( '%s followers', 'game-collector' ), esc_html( number_format_i18n( GC_Follows::count_followers( $member->ID ) ) ) ); ?></span>
				<span>&middot;</span>
				<span><?php printf( esc_html__( '%s following', 'game-collector' ), esc_html( number_format_i18n( GC_Follows::count_following( $member->ID ) ) ) ); ?></span>
			</p>
		</div>
		<div class="gc-profile-actions">
			<?php if ( $is_own ) : ?>
				<a class="gc-btn gc-btn-secondary" href="<?php echo esc_url( GC_Frontend::activity_url() ); ?>"><?php esc_html_e( 'Activity Feed', 'game-collector' ); ?></a>
				<button type="button" class="gc-btn gc-btn-primary" id="gc-open-search"><?php esc_html_e( '+ Add Games', 'game-collector' ); ?></button>
			<?php else : ?>
				<a class="gc-btn gc-btn-secondary" href="<?php echo esc_url( GC_Frontend::my_library_url() ); ?>"><?php esc_html_e( 'My Library', 'game-collector' ); ?></a>
				<button type="button"
					class="gc-btn <?php echo $following ? 'gc-btn-secondary' : 'gc-btn-primary'; ?> gc-follow-btn"
					data-user-id="<?php echo esc_attr( $member->ID ); ?>"
					data-following="<?php echo $following ? '1' : '0'; ?>">
					<?php echo $following ? esc_html__( 'Unfollow', 'game-collector' ) : esc_html__( 'Follow', 'game-collector' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</header>

	<?php if ( $is_own ) : ?>
	<section class="gc-search-panel" id="gc-search-panel" hidden>
		<div class="gc-search-bar">
			<input type="search" id="gc-search-input" placeholder="<?php esc_attr_e( 'Search IGDB for a game…', 'game-collector' ); ?>" autocomplete="off" />
			<button type="button" class="gc-btn gc-btn-secondary" id="gc-close-search"><?php esc_html_e( 'Close', 'game-collector' ); ?></button>
		</div>
		<div class="gc-search-results" id="gc-search-results"></div>
	</section>
	<?php endif; ?>

	<nav class="gc-tabs">
		<a href="<?php echo esc_url( $base_url ); ?>" class="gc-tab <?php echo '' === $status_filter ? 'is-active' : ''; ?>">
			<?php esc_html_e( 'All', 'game-collector' ); ?> <span class="gc-count"><?php echo esc_html( number_format_i18n( $counts['all'] ) ); ?></span>
		</a>
		<?php foreach ( $labels as $status_key => $status_label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'status', $status_key, $base_url ) ); ?>" class="gc-tab <?php echo $status_filter === $status_key ? 'is-active' : ''; ?>">
				<?php echo esc_html( $status_label ); ?> <span class="gc-count"><?php echo esc_html( number_format_i18n( $counts[ $status_key ] ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( ! $entries ) : ?>
		<div class="gc-empty">
			<?php if ( $is_own ) : ?>
				<p><?php esc_html_e( 'No games here yet. Hit “Add Games” to start building your collection.', 'game-collector' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'No games here yet.', 'game-collector' ); ?></p>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<ul class="gc-grid">
			<?php foreach ( $entries as $entry ) : ?>
				<li class="gc-card" data-game-id="<?php echo esc_attr( $entry->game_id ); ?>">
					<div class="gc-card-cover">
						<?php if ( $entry->cover_image_id ) : ?>
							<img src="<?php echo esc_url( GC_IGDB::cover_url( $entry->cover_image_id ) ); ?>" alt="" loading="lazy" />
						<?php else : ?>
							<span class="gc-card-noart" aria-hidden="true">🎮</span>
						<?php endif; ?>
					</div>
					<div class="gc-card-body">
						<h3 class="gc-card-title"><?php echo esc_html( $entry->name ); ?></h3>
						<p class="gc-card-meta">
							<?php echo esc_html( $entry->release_year ? $entry->release_year : '' ); ?>
							<?php if ( $entry->release_year && $entry->platforms ) : ?> &middot; <?php endif; ?>
							<?php echo esc_html( $entry->platforms ); ?>
						</p>
						<?php if ( $is_own ) : ?>
							<div class="gc-card-controls">
								<select class="gc-status-select" aria-label="<?php esc_attr_e( 'Status', 'game-collector' ); ?>">
									<?php foreach ( $labels as $status_key => $status_label ) : ?>
										<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $entry->status, $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="button" class="gc-remove-btn" title="<?php esc_attr_e( 'Remove from library', 'game-collector' ); ?>">&times;</button>
							</div>
						<?php else : ?>
							<span class="gc-badge gc-badge-<?php echo esc_attr( $entry->status ); ?>"><?php echo esc_html( $labels[ $entry->status ] ); ?></span>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

</div>
