<?php
/**
 * Administrator overrides on the WordPress user-edit screen.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * The AC-007 override section: three controls an administrator holding
 * `gamelib_admin_override` gets on any member's user-edit screen.
 *
 * - **(a) Force members-only.** Sets the member's visibility to members-only.
 *   That governs logged-out exposure and nothing else: every logged-in member
 *   still sees the full profile, library, and activity afterwards (DD-014,
 *   AC-028b). The write goes through {@see GameLib_Visibility::set()} so the
 *   AC-029 side effects — the generation bump and the page-cache purge — happen
 *   with it rather than after it.
 * - **(b) Disable invites.** A binary gate on `gamelib_invites_disabled`,
 *   checked and enforced by
 *   {@see GameLib_Capabilities::filter_user_has_cap()} at the capability layer,
 *   which is what makes it block issuance outright regardless of how much of
 *   the site-wide quota the member has left. It is never a numeric per-member
 *   allowance (Q-SPEC-2, AC-007b).
 * - **(c) Follow edges.** Both directions, up to 50 each, every one with its
 *   own nonce-protected Remove action. Removing an edge changes what the
 *   follower's feed contains and nothing else — profile and library access is
 *   not a function of follow status anywhere in this plugin (DD-008).
 *
 * All three are immediate `admin-post.php` actions rather than fields saved
 * with the user form: an override takes effect when it is clicked, and each
 * one carries a nonce bound to the exact member — and, for a follow edge, to
 * the exact pair — that it acts on. Every one of them verifies that nonce and
 * then `gamelib_admin_override` before touching anything (AC-NFR-001s).
 */
final class GameLib_Admin_User {

	/**
	 * `admin-post.php` action behind every control in this section.
	 *
	 * @var string
	 */
	const OVERRIDE_ACTION = 'gamelib_user_override';

	/**
	 * Override key: force the member's profile to members-only (AC-007a).
	 *
	 * @var string
	 */
	const OVERRIDE_MEMBERS_ONLY = 'members_only';

	/**
	 * Override key: switch the member's invite issuance off (AC-007b).
	 *
	 * @var string
	 */
	const OVERRIDE_INVITES_DISABLE = 'invites_disable';

	/**
	 * Override key: switch the member's invite issuance back on (AC-007b).
	 *
	 * @var string
	 */
	const OVERRIDE_INVITES_ENABLE = 'invites_enable';

	/**
	 * Override key: delete one follow edge (AC-007c).
	 *
	 * @var string
	 */
	const OVERRIDE_UNFOLLOW = 'unfollow';

	/**
	 * Every override this class accepts — the whitelist a submitted key is
	 * matched against.
	 *
	 * @var string[]
	 */
	const OVERRIDES = array(
		self::OVERRIDE_MEMBERS_ONLY,
		self::OVERRIDE_INVITES_DISABLE,
		self::OVERRIDE_INVITES_ENABLE,
		self::OVERRIDE_UNFOLLOW,
	);

	/**
	 * Query arg naming which override ran.
	 *
	 * @var string
	 */
	const ARG_OVERRIDE = 'gamelib_override';

	/**
	 * Query arg carrying that override's outcome.
	 *
	 * @var string
	 */
	const ARG_RESULT = 'gamelib_override_result';

	/**
	 * Outcome value of an applied override.
	 *
	 * @var string
	 */
	const RESULT_OK = 'ok';

	/**
	 * Outcome value of an override that changed nothing.
	 *
	 * @var string
	 */
	const RESULT_FAILED = 'failed';

	/**
	 * Follow edges listed per direction (AC-007c).
	 *
	 * @var int
	 */
	const EDGE_LIMIT = 50;

	/**
	 * Render the override section on another member's user-edit screen.
	 *
	 * Hooked to `edit_user_profile`, which fires inside core's user form — so
	 * every control here is a link to `admin-post.php`, never a nested form.
	 *
	 * @param WP_User $user Member being edited.
	 * @return void
	 */
	public static function render_section( $user ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$user_id = (int) $user->ID;

		if ( $user_id < 1 || ! self::may_override( $user_id ) ) {
			return;
		}

		?>
		<h2 id="gamelib-overrides"><?php esc_html_e( 'Game Library', 'game-library' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<?php
				self::render_visibility_row( $user_id );
				self::render_invites_row( $user_id );
				self::render_follows_row( $user, $user_id );
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Apply one override (AC-007, AC-NFR-001s).
	 *
	 * Nonce first, capability second, then the write. The nonce action carries
	 * the override key and the member id — and, for a follow edge, both ends of
	 * that edge — so a URL minted for one member or one edge cannot be replayed
	 * against another.
	 *
	 * @return void
	 */
	public static function handle_override() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce bound to exactly these values is verified below; they have to be read to name the action it is bound to.
		$override = isset( $_REQUEST['override'] ) ? sanitize_key( wp_unslash( $_REQUEST['override'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$user_id = isset( $_REQUEST['user'] ) ? absint( wp_unslash( $_REQUEST['user'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$follower_id = isset( $_REQUEST['follower'] ) ? absint( wp_unslash( $_REQUEST['follower'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$followed_id = isset( $_REQUEST['followed'] ) ? absint( wp_unslash( $_REQUEST['followed'] ) ) : 0;

		check_admin_referer( self::nonce_action( $override, $user_id, $follower_id, $followed_id ) );

		if ( ! self::may_override( $user_id ) ) {
			wp_die(
				esc_html__( 'You are not allowed to change this member’s Game Library settings.', 'game-library' ),
				esc_html__( 'Game Library', 'game-library' ),
				array( 'response' => 403 )
			);
		}

		if ( ! in_array( $override, self::OVERRIDES, true ) ) {
			wp_safe_redirect( self::user_url( $user_id ) );
			exit;
		}

		$applied = self::apply( $override, $user_id, $follower_id, $followed_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					self::ARG_OVERRIDE => rawurlencode( $override ),
					self::ARG_RESULT   => $applied ? self::RESULT_OK : self::RESULT_FAILED,
				),
				self::user_url( $user_id )
			)
		);
		exit;
	}

	/**
	 * Answer a logged-out submission with 401.
	 *
	 * `admin-post.php` routes an unauthenticated request to
	 * `admin_post_nopriv_{action}`; with nothing listening there it would end as
	 * a silent, empty 200 that reads like a successful override.
	 *
	 * @return void
	 */
	public static function reject_anonymous() {
		wp_die(
			esc_html__( 'Sign in as an administrator to perform this action.', 'game-library' ),
			esc_html__( 'Game Library', 'game-library' ),
			array( 'response' => 401 )
		);
	}

	/**
	 * Report the outcome of an override on the screen it came from.
	 *
	 * @return void
	 */
	public static function render_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof WP_Screen || 'user-edit' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flags on a redirect target; the write they report was nonce- and capability-checked in handle_override(), and both values are matched against fixed lists before use.
		$override = isset( $_GET[ self::ARG_OVERRIDE ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG_OVERRIDE ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$result = isset( $_GET[ self::ARG_RESULT ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG_RESULT ] ) ) : '';

		if ( ! in_array( $override, self::OVERRIDES, true ) ) {
			return;
		}

		if ( self::RESULT_OK !== $result && self::RESULT_FAILED !== $result ) {
			return;
		}

		$success = ( self::RESULT_OK === $result );

		wp_admin_notice(
			esc_html( self::outcome_message( $override, $success ) ),
			array(
				'type'        => $success ? 'success' : 'error',
				'dismissible' => true,
			)
		);
	}

	/**
	 * Perform one validated override.
	 *
	 * @param string $override    One of {@see GameLib_Admin_User::OVERRIDES}.
	 * @param int    $user_id     Member being overridden.
	 * @param int    $follower_id Follow edge's follower, for the unfollow override.
	 * @param int    $followed_id Follow edge's target, for the unfollow override.
	 * @return bool True when the override left the member in the requested state.
	 */
	private static function apply( $override, $user_id, $follower_id, $followed_id ) {
		switch ( $override ) {
			case self::OVERRIDE_MEMBERS_ONLY:
				return GameLib_Visibility::set( $user_id, GameLib_Visibility::MEMBERS_ONLY );

			case self::OVERRIDE_INVITES_DISABLE:
				update_user_meta( $user_id, GameLib_Capabilities::INVITES_DISABLED_META, '1' );

				// The meta write returns false when the value was already
				// stored, so the state itself — not the write — is the outcome.
				return GameLib_Capabilities::invites_disabled( $user_id );

			case self::OVERRIDE_INVITES_ENABLE:
				delete_user_meta( $user_id, GameLib_Capabilities::INVITES_DISABLED_META );

				return ! GameLib_Capabilities::invites_disabled( $user_id );

			case self::OVERRIDE_UNFOLLOW:
				if ( $follower_id < 1 || $followed_id < 1 ) {
					return false;
				}

				if ( $user_id !== $follower_id && $user_id !== $followed_id ) {
					// An edge this member is not part of is not this screen's
					// to remove.
					return false;
				}

				return ! is_wp_error( GameLib_Follows::unfollow( $follower_id, $followed_id ) );
		}

		return false;
	}

	/**
	 * The visibility row: current state and the force-members-only action
	 * (AC-007a).
	 *
	 * @param int $user_id Member being edited.
	 * @return void
	 */
	private static function render_visibility_row( $user_id ) {
		$is_public = GameLib_Visibility::is_public( $user_id );

		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Profile visibility', 'game-library' ); ?></th>
			<td>
				<p>
					<strong><?php echo esc_html( GameLib_Visibility::visibility_label( GameLib_Visibility::get( $user_id ) ) ); ?></strong>
				</p>
				<?php if ( $is_public ) : ?>
					<p>
						<a
							class="button"
							href="<?php echo esc_url( self::action_url( self::OVERRIDE_MEMBERS_ONLY, $user_id ) ); ?>"
							data-gamelib-action="admin.force-members-only"
						><?php esc_html_e( 'Force members-only', 'game-library' ); ?></a>
					</p>
				<?php endif; ?>
				<p class="description">
					<?php
					esc_html_e(
						'Members-only governs signed-out visitors: their profile, library, and activity return a 404 to anyone who is not signed in. Every signed-in member sees the full profile either way, and follow status never affects access.',
						'game-library'
					);
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * The invites row: the binary disable gate and the member's allowance
	 * (AC-007b).
	 *
	 * @param int $user_id Member being edited.
	 * @return void
	 */
	private static function render_invites_row( $user_id ) {
		$allowance = GameLib_Invites::allowance( $user_id );
		$disabled  = (bool) $allowance['disabled'];

		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Invites', 'game-library' ); ?></th>
			<td>
				<p>
					<strong>
						<?php
						echo esc_html(
							$disabled
								? __( 'Disabled', 'game-library' )
								: __( 'Enabled', 'game-library' )
						);
						?>
					</strong>
				</p>
				<p>
					<a
						class="button"
						href="<?php echo esc_url( self::action_url( $disabled ? self::OVERRIDE_INVITES_ENABLE : self::OVERRIDE_INVITES_DISABLE, $user_id ) ); ?>"
						data-gamelib-action="<?php echo esc_attr( $disabled ? 'admin.enable-invites' : 'admin.disable-invites' ); ?>"
					>
						<?php
						echo esc_html(
							$disabled
								? __( 'Enable invites', 'game-library' )
								: __( 'Disable invites', 'game-library' )
						);
						?>
					</a>
				</p>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of invites this member has issued. */
							_n(
								'This member has issued %s invite.',
								'This member has issued %s invites.',
								(int) $allowance['issued'],
								'game-library'
							),
							number_format_i18n( (int) $allowance['issued'] )
						)
					);
					echo ' ';
					echo esc_html(
						$allowance['unlimited']
							? __( 'The site-wide quota is unlimited.', 'game-library' )
							: sprintf(
								/* translators: %s: number of invites this member has left under the site-wide quota. */
								_n(
									'They have %s invite left under the site-wide quota.',
									'They have %s invites left under the site-wide quota.',
									(int) $allowance['remaining'],
									'game-library'
								),
								number_format_i18n( (int) $allowance['remaining'] )
							)
					);
					echo ' ';
					esc_html_e(
						'Disabling invites blocks this member’s issuance outright, whatever the quota leaves them. It is a switch, not an allowance.',
						'game-library'
					);
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * The follow-edge row: both directions, each edge removable (AC-007c).
	 *
	 * Every member named in either list is loaded in one query before the
	 * lists render (WPP-05).
	 *
	 * @param WP_User $user    Member being edited.
	 * @param int     $user_id That member's id.
	 * @return void
	 */
	private static function render_follows_row( WP_User $user, $user_id ) {
		$following = GameLib_Follows::following( $user_id, self::EDGE_LIMIT );
		$followers = GameLib_Follows::followers( $user_id, self::EDGE_LIMIT );
		$others    = array_unique( array_merge( $following, $followers ) );

		if ( ! empty( $others ) ) {
			cache_users( $others );
		}

		$name = self::display_name( $user_id, $user );

		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Follows', 'game-library' ); ?></th>
			<td>
				<h3><?php esc_html_e( 'Following', 'game-library' ); ?></h3>
				<?php
				self::render_edge_list(
					$following,
					GameLib_Follows::following_count( $user_id ),
					$user_id,
					$name,
					true,
					__( 'This member follows nobody yet.', 'game-library' )
				);
				?>

				<h3><?php esc_html_e( 'Followers', 'game-library' ); ?></h3>
				<?php
				self::render_edge_list(
					$followers,
					GameLib_Follows::follower_count( $user_id ),
					$user_id,
					$name,
					false,
					__( 'Nobody follows this member yet.', 'game-library' )
				);
				?>

				<p class="description">
					<?php
					esc_html_e(
						'Removing an edge changes whose activity appears in whose feed. It does not affect who can see this member’s profile or library — every signed-in member can, whether they follow them or not.',
						'game-library'
					);
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * One direction of the follow list.
	 *
	 * @param int[]  $user_ids  Members at the other end of each edge.
	 * @param int    $total     How many edges exist in this direction.
	 * @param int    $user_id   Member being edited.
	 * @param string $name      That member's display name.
	 * @param bool   $outgoing  True for edges this member owns (they follow the others).
	 * @param string $empty     Copy for an empty list.
	 * @return void
	 */
	private static function render_edge_list( array $user_ids, $total, $user_id, $name, $outgoing, $empty ) {
		if ( empty( $user_ids ) ) {
			echo '<p>' . esc_html( $empty ) . '</p>';

			return;
		}

		echo '<ul class="gamelib-follow-edges">';

		foreach ( $user_ids as $other_id ) {
			$other_name  = self::display_name( $other_id );
			$follower_id = $outgoing ? $user_id : $other_id;
			$followed_id = $outgoing ? $other_id : $user_id;

			printf(
				'<li>%1$s <a href="%2$s" class="gamelib-follow-edges__remove" data-gamelib-action="admin.remove-follow" aria-label="%3$s">%4$s</a></li>',
				wp_kses(
					self::member_link( $other_id, $other_name ),
					array(
						'a' => array( 'href' => array() ),
					)
				),
				esc_url(
					self::action_url(
						self::OVERRIDE_UNFOLLOW,
						$user_id,
						array(
							'follower' => $follower_id,
							'followed' => $followed_id,
						)
					)
				),
				esc_attr(
					sprintf(
						/* translators: 1: member who follows, 2: member being followed. */
						__( 'Remove the follow from %1$s to %2$s', 'game-library' ),
						$outgoing ? $name : $other_name,
						$outgoing ? $other_name : $name
					)
				),
				esc_html__( 'Remove', 'game-library' )
			);
		}

		echo '</ul>';

		if ( $total > count( $user_ids ) ) {
			echo '<p class="description">';
			/*
			 * Pluralised on the *total* (CO-5): the sentence is about the whole
			 * set of follow edges, and the first number is how much of that set
			 * is on screen rather than a count of its own. AC-NFR-008 forbids
			 * `__()` around a sentence carrying a count.
			 */
			echo esc_html(
				sprintf(
					/* translators: 1: number of edges shown, 2: total number of edges. */
					_n(
						'Showing the %1$s most recent of %2$s.',
						'Showing the %1$s most recent of %2$s.',
						$total,
						'game-library'
					),
					number_format_i18n( count( $user_ids ) ),
					number_format_i18n( $total )
				)
			);
			echo '</p>';
		}
	}

	/**
	 * One member's name, linked to their own user-edit screen when the current
	 * administrator may open it.
	 *
	 * @param int    $user_id Member id.
	 * @param string $name    Display name.
	 * @return string Markup with the name escaped.
	 */
	private static function member_link( $user_id, $name ) {
		$link = (string) get_edit_user_link( (int) $user_id );

		if ( '' === $link ) {
			return esc_html( $name );
		}

		return '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>';
	}

	/**
	 * A member's display name, falling back to their id when the account is
	 * gone.
	 *
	 * @param int          $user_id Member id.
	 * @param WP_User|null $user    Optional. Already-loaded user object.
	 * @return string Display name, unescaped.
	 */
	private static function display_name( $user_id, $user = null ) {
		$user_id = absint( $user_id );

		if ( ! $user instanceof WP_User ) {
			$user = get_userdata( $user_id );
		}

		if ( ! $user instanceof WP_User ) {
			return sprintf(
				/* translators: %s: user id of a member who no longer exists. */
				__( 'Deleted member (#%s)', 'game-library' ),
				number_format_i18n( $user_id )
			);
		}

		return ( '' !== (string) $user->display_name ) ? (string) $user->display_name : (string) $user->user_login;
	}

	/**
	 * The nonce-protected URL of one override control.
	 *
	 * @param string             $override One of {@see GameLib_Admin_User::OVERRIDES}.
	 * @param int                $user_id  Member being overridden.
	 * @param array<string, int> $extra    Optional. Additional query arguments.
	 * @return string Absolute `admin-post.php` URL.
	 */
	private static function action_url( $override, $user_id, array $extra = array() ) {
		$args = array_merge(
			array(
				'action'   => self::OVERRIDE_ACTION,
				'override' => $override,
				'user'     => absint( $user_id ),
			),
			array_map( 'absint', $extra )
		);

		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin-post.php' ) ),
			self::nonce_action(
				$override,
				$user_id,
				isset( $extra['follower'] ) ? $extra['follower'] : 0,
				isset( $extra['followed'] ) ? $extra['followed'] : 0
			)
		);
	}

	/**
	 * The nonce action one override is bound to.
	 *
	 * The follow-edge case binds both ends, so a Remove URL minted for one edge
	 * cannot delete another.
	 *
	 * @param string $override    Override key, as submitted.
	 * @param int    $user_id     Member being overridden.
	 * @param int    $follower_id Follow edge's follower.
	 * @param int    $followed_id Follow edge's target.
	 * @return string Nonce action.
	 */
	private static function nonce_action( $override, $user_id, $follower_id = 0, $followed_id = 0 ) {
		if ( self::OVERRIDE_UNFOLLOW === $override ) {
			return self::OVERRIDE_ACTION . '_' . $override . '_' . absint( $follower_id ) . '_' . absint( $followed_id );
		}

		return self::OVERRIDE_ACTION . '_' . $override . '_' . absint( $user_id );
	}

	/**
	 * May the current user override this member?
	 *
	 * Two gates, both required: the plugin's admin capability (AC-007) and
	 * core's own permission to edit the user in question.
	 *
	 * @param int $user_id Member being overridden.
	 * @return bool True when the override may proceed.
	 */
	private static function may_override( $user_id ) {
		$user_id = absint( $user_id );

		if ( $user_id < 1 ) {
			return false;
		}

		return current_user_can( GameLib_Capabilities::CAP_ADMIN_OVERRIDE ) && current_user_can( 'edit_user', $user_id );
	}

	/**
	 * The user-edit screen of one member.
	 *
	 * @param int $user_id Member id.
	 * @return string Admin URL.
	 */
	private static function user_url( $user_id ) {
		$link = (string) get_edit_user_link( absint( $user_id ) );

		return ( '' === $link ) ? admin_url( 'users.php' ) : $link;
	}

	/**
	 * The sentence one override's outcome produces.
	 *
	 * @param string $override One of {@see GameLib_Admin_User::OVERRIDES}.
	 * @param bool   $success  Whether the override applied.
	 * @return string Translated message.
	 */
	private static function outcome_message( $override, $success ) {
		switch ( $override ) {
			case self::OVERRIDE_MEMBERS_ONLY:
				return $success
					? __( 'This member’s profile is now members-only. Signed-out visitors get a 404; signed-in members are unaffected.', 'game-library' )
					: __( 'That visibility change could not be saved. Please try again.', 'game-library' );

			case self::OVERRIDE_INVITES_DISABLE:
				return $success
					? __( 'Invites are switched off for this member. They cannot issue any, whatever the site-wide quota leaves them.', 'game-library' )
					: __( 'Invites could not be switched off for this member. Please try again.', 'game-library' );

			case self::OVERRIDE_INVITES_ENABLE:
				return $success
					? __( 'Invites are switched back on for this member, subject to the site-wide quota.', 'game-library' )
					: __( 'Invites could not be switched back on for this member. Please try again.', 'game-library' );

			case self::OVERRIDE_UNFOLLOW:
				return $success
					? __( 'Follow removed. That member’s activity no longer appears in the follower’s feed; profile and library access is unchanged.', 'game-library' )
					: __( 'That follow could not be removed. Please try again.', 'game-library' );
		}

		return __( 'Nothing was changed.', 'game-library' );
	}
}
