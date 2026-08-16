<?php
/**
 * Invite code generation, quota enforcement, and delivery.
 *
 * @package Game_Library
 */

namespace Game_Library\Invites;

use Game_Library\Data\Invite_Repository;
use Game_Library\Router;
use Game_Library\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Invite_Service.
 *
 * Generates invite codes, enforces the rolling-30-day quota (delegated to
 * `Invite_Repository::insert_pending()` per DD-010), and sends the invite
 * email through `wp_mail()` — a `false` return is surfaced to the caller as
 * a delivery warning and never changes the invite's status (AC-026).
 *
 * Redemption itself (the atomic claim and the post-account-creation
 * `redeemed_user_id` stamp) is not exposed here: `Invite_Repository::
 * claim_by_code()` and `::set_redeemed_user()` are the primitives
 * `Registration` (Task 8) calls directly, per this task's own
 * verify-before-implementing note naming the repository (not this class) as
 * what redemption depends on. This class's role in redemption is limited to
 * generating the code redeemed later and building the redemption URL both
 * `create_invite()` and `resend_invite()` return.
 */
final class Invite_Service {

	/**
	 * Maximum invite-code generation attempts before giving up on a
	 * unique-key collision (the constraint's "at most three attempts").
	 *
	 * @var int
	 */
	private const MAX_CODE_ATTEMPTS = 3;

	/**
	 * Length, in seconds, of the rolling quota window — mirrors
	 * `Invite_Repository::QUOTA_WINDOW`, used here only to format the
	 * `quota_status()` reset date the repository's raw usage figures imply.
	 *
	 * @var int
	 */
	private const QUOTA_WINDOW = 30 * DAY_IN_SECONDS;

	/**
	 * Minimum time between two `resend_invite()` calls for the same invite
	 * (SE-1) — `POST /invites/{id}/resend` was reachable by any Subscriber
	 * owning the invite (every Subscriber, via `gl_issue_invites`) with no
	 * cooldown, no counter, and no state write, so the owner could replay it
	 * indefinitely, sending unbounded email to an address of their choosing
	 * from the site's mail server.
	 *
	 * @var int
	 */
	private const RESEND_COOLDOWN = 15 * MINUTE_IN_SECONDS;

	/**
	 * Maximum `resend_invite()` calls for one invite within `QUOTA_WINDOW`
	 * (SE-1) — the cooldown alone still allows an unbounded total over time;
	 * this caps the lifetime total per invite.
	 *
	 * @var int
	 */
	private const RESEND_LIMIT = 3;

	/**
	 * Invite data access.
	 *
	 * @var Invite_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Invite_Repository|null $repository Invite data access. Defaults
	 *                                            to a new instance.
	 */
	public function __construct( ?Invite_Repository $repository = null ) {
		$this->repository = $repository ?: new Invite_Repository();
	}

	/**
	 * Creates a new invite for `$inviter_id`, subject to the invite quota
	 * (AC-024) and, separately (SE-3, cycle-7), a row-creation ceiling that
	 * survives a revoke — see the ceiling check's own inline comment for why
	 * it is a distinct control from the quota, not a reinterpretation of it.
	 * For an `email` channel invite, also sends it through `wp_mail()`
	 * (AC-026).
	 *
	 * @param int    $inviter_id Issuing member.
	 * @param string $email      Recipient email, or '' for a link invite.
	 * @return array{invite:array<string,mixed>,redemption_url:string,mail_sent:?bool}|WP_Error
	 *               On success: the created invite record (AC-025), its
	 *               redemption URL, and whether `wp_mail()` succeeded (null
	 *               when the invite has no email to send). On failure:
	 *               `gl_invalid_invite`/`gl_invalid_invite_email` for bad
	 *               input, `gl_invite_quota_exceeded` when the quota itself
	 *               is exhausted (names the date it resets, AC-024),
	 *               `gl_invite_row_limit` when the separate row-creation
	 *               ceiling is hit (CO-4, cycle-8 — a distinct code from the
	 *               quota so AC-024's "the message contains a date" verify
	 *               text stays exact for `gl_invite_quota_exceeded`), or
	 *               `gl_invite_create_failed` after `MAX_CODE_ATTEMPTS` code
	 *               collisions.
	 */
	public function create_invite( $inviter_id, $email = '' ) {
		$inviter_id = absint( $inviter_id );

		if ( ! $inviter_id ) {
			return new WP_Error( 'gl_invalid_invite', __( 'An invite requires an inviting member.', 'game-library' ) );
		}

		$email = is_string( $email ) ? sanitize_email( $email ) : '';

		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'gl_invalid_invite_email', __( 'Enter a valid email address.', 'game-library' ) );
		}

		$channel  = '' !== $email ? 'email' : 'link';
		$settings = $this->get_settings();

		// SE-3 (cycle-7): a SEPARATE ceiling from the invite quota
		// enforce_quota()/insert_pending() apply below — that quota
		// deliberately refunds a slot on revoke (AC-024, human ruling 3),
		// which SE-1's mail-budget fix neutralised for the mail leg but
		// left wide open for a create->revoke->create loop that never
		// touches email at all (a link invite has no mail_budget() ceiling
		// to hit), since nothing else on POST /invites bounds how many rows
		// get inserted. invite_row_count_in_window() counts ALL rows
		// (including revoked, unlike the quota's own count), so a
		// revoke-and-reissue that stays within the real allowance still
		// works; only a loop that keeps growing gl_invites without bound
		// is stopped. Same ceiling shape mail_budget() already uses so an
		// honest inviter's real allowance (quota creates plus up to
		// RESEND_LIMIT resends of each) is never the limiting factor.
		$row_ceiling = max( 1, absint( $settings['invite_quota'] ) ) * ( 1 + self::RESEND_LIMIT );

		if ( $this->repository->invite_row_count_in_window( $inviter_id ) >= $row_ceiling ) {
			// CO-4 (cycle-8): a distinct code from gl_invite_quota_exceeded
			// — this ceiling is a SEPARATE control from the invite quota
			// (see this check's own comment above), and AC-024's verify
			// text requires gl_invite_quota_exceeded's own message to name
			// the date the quota next frees up (enforce_quota() below does
			// that). This ceiling has no single reset date to name (it is a
			// rolling 30-day row count, not a per-cycle allowance), so it
			// gets its own code and message rather than reusing one whose
			// contract this branch would then not satisfy.
			return new WP_Error( 'gl_invite_row_limit', __( 'You have created too many invites recently. Try again later.', 'game-library' ) );
		}

		$invite = null;

		for ( $attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; $attempt++ ) {
			$code   = $this->generate_code();
			$result = $this->repository->insert_pending(
				$code,
				$inviter_id,
				'' === $email ? null : $email,
				$channel,
				$settings['invite_expiry_days'],
				$settings['invite_quota']
			);

			if ( ! is_wp_error( $result ) ) {
				$invite = $result;
				break;
			}

			if ( 'gl_invite_code_collision' !== $result->get_error_code() ) {
				// Quota breach or a genuine database failure (CO-5,
				// cycle-5: insert_pending() now distinguishes the two by
				// $wpdb->last_error rather than assuming every insert
				// failure is a collision) — retrying with a new code would
				// not help either one.
				return $result;
			}
		}

		if ( null === $invite ) {
			// CO-5 (cycle-5): always the generic message, never the last
			// collision error verbatim — three genuine code collisions in a
			// row is astronomically unlikely with a 20-character random
			// code, and even then "That invite code is already in use"
			// mis-attributes a system-generated code's collision to the
			// member, who never chose it. "Could not be created, try
			// again" is the correct message for "we tried three times and
			// none of them worked," regardless of which of the three
			// attempts is responsible.
			return new WP_Error( 'gl_invite_create_failed', __( 'The invite could not be created. Try again.', 'game-library' ) );
		}

		return $this->deliver( $invite, $inviter_id );
	}

	/**
	 * Re-sends a still-`pending` email invite (AC-031(i), the `/invites/`
	 * screen's resend control) without changing the invite row. Throttled
	 * per invite by a `RESEND_COOLDOWN`-second cooldown (SE-1) and a
	 * `RESEND_LIMIT`-per-invite lifetime cap (SE-1, made durable by MR-4,
	 * cycle-7) — both keyed on the invite id, which
	 * `ownership_permissions_check()` has already confirmed the caller owns
	 * (or can `manage_options`) before this is ever called, so the throttle
	 * is effectively per-invite regardless of caller.
	 *
	 * VIP-6/MR-4 (cycle-7): only the cooldown is a transient now, and it is
	 * genuinely best-effort/fail-open (a request slipping through on an
	 * evicted or never-persisted — this project's cache-less `portable`
	 * target — cooldown transient gets one resend earlier than intended,
	 * bounded by the durable cap below). The lifetime cap is no longer a
	 * transient counter: `Invite_Repository::consume_resend_slot()` claims
	 * `gl_invites.resend_count` with an atomic conditional `UPDATE`, so it
	 * cannot be bypassed by cache eviction or a lost race the way a
	 * `get_transient()`/`set_transient()` pair could. The DB-backed,
	 * authoritative limits are AC-024's invite quota, SE-3's row-creation
	 * ceiling (`create_invite()`), and, since cycle-5 (SE-1), `deliver()`'s
	 * own `mail_budget_available()` check — the latter reads rows in
	 * `gl_invites` directly (`Invite_Repository::
	 * email_invite_count_in_window()`), not an object-cache counter. This
	 * cooldown is defence in depth on top of those, not the sole control.
	 *
	 * @param int $id Invite id.
	 * @return array{invite:array<string,mixed>,redemption_url:string,mail_sent:bool}|WP_Error
	 *               `gl_invite_not_found` for an unknown id,
	 *               `gl_invite_not_pending` when the invite is no longer
	 *               `pending`, `gl_invite_no_email` for a link invite,
	 *               `gl_invite_resend_cooldown`/`gl_invite_resend_limit` when
	 *               throttled.
	 */
	public function resend_invite( $id ) {
		$id     = absint( $id );
		$invite = $this->repository->get_by_id( $id );

		if ( ! $invite ) {
			return new WP_Error( 'gl_invite_not_found', __( 'That invite could not be found.', 'game-library' ) );
		}

		if ( 'pending' !== $invite['status'] ) {
			return new WP_Error( 'gl_invite_not_pending', __( 'Only a pending invite can be resent.', 'game-library' ) );
		}

		if ( empty( $invite['email'] ) ) {
			return new WP_Error( 'gl_invite_no_email', __( 'This invite has no recipient email to resend to.', 'game-library' ) );
		}

		$cooldown_key = 'gl_invite_resend_' . $id;

		if ( false !== get_transient( $cooldown_key ) ) {
			return new WP_Error( 'gl_invite_resend_cooldown', __( 'Please wait a few minutes before resending this invite.', 'game-library' ) );
		}

		// MR-4 (cycle-7): the lifetime-total cap is now a durable,
		// atomically-claimed gl_invites.resend_count slot
		// (Invite_Repository::consume_resend_slot()), not a
		// get_transient()/set_transient() pair — that pair had no atomic
		// increment, so N concurrent resends for the same invite could all
		// read $sends = 0 and all pass, and (VIP-6's own docblock paragraph
		// above already flagged this) a transient is not guaranteed to
		// survive to the next request at all on this project's cache-less
		// portable target. consume_resend_slot() returns false both when
		// the limit is already claimed AND under a lost race, which is the
		// correct refusal either way. The 15-minute cooldown above stays a
		// transient — it is defence in depth, not the authoritative limit,
		// and a false-open cooldown (an evicted transient serving one extra
		// resend before the next one is throttled) is bounded by the
		// durable resend_count ceiling regardless.
		if ( ! $this->repository->consume_resend_slot( $id, self::RESEND_LIMIT ) ) {
			return new WP_Error( 'gl_invite_resend_limit', __( 'This invite has already been resent as many times as allowed.', 'game-library' ) );
		}

		// SE-1: write the cooldown transient BEFORE calling deliver(), not
		// after. deliver() sends through wp_mail(), which is not instant —
		// writing it only after deliver() returns left a window where two
		// concurrent resend requests for the same invite could both read
		// get_transient( $cooldown_key ) as absent and both pass the
		// throttle check before either one wrote it.
		set_transient( $cooldown_key, 1, self::RESEND_COOLDOWN );

		return $this->deliver( $invite, $invite['inviter_id'] );
	}

	/**
	 * The quota figures for one inviter, for display on the `/invites/`
	 * screen (Task 14): the configured quota, invites used within the
	 * rolling window, invites remaining, and — when at least one invite
	 * counts toward the window — the date the oldest one ages out of it.
	 *
	 * @param int $inviter_id Inviting member.
	 * @return array{quota:int,used:int,remaining:int,reset_date:?string}
	 */
	public function quota_status( $inviter_id ) {
		$quota = $this->get_settings()['invite_quota'];
		$usage = $this->repository->quota_usage( absint( $inviter_id ) );

		$reset_date = null;

		if ( null !== $usage['oldest_date_created'] ) {
			// wp_date(), not date_i18n() (CO-5) — see
			// Invite_Repository::enforce_quota()'s own comment on the
			// identical fix for the same reason.
			$reset_date = wp_date(
				get_option( 'date_format' ),
				strtotime( $usage['oldest_date_created'] . ' UTC' ) + self::QUOTA_WINDOW
			);
		}

		return array(
			'quota'      => $quota,
			'used'       => $usage['count'],
			'remaining'  => max( 0, $quota - $usage['count'] ),
			'reset_date' => $reset_date,
		);
	}

	/**
	 * Builds the redemption URL and, for an `email` channel invite, sends it
	 * through `wp_mail()` — shared by `create_invite()` and `resend_invite()`
	 * (AC-026).
	 *
	 * @param array<string,mixed> $invite     Invite record.
	 * @param int                 $inviter_id Id used to look up the
	 *                                        inviter's display name for the
	 *                                        email body.
	 * @return array{invite:array<string,mixed>,redemption_url:string,mail_sent:?bool}
	 */
	private function deliver( array $invite, $inviter_id ) {
		$redemption_url = $this->redemption_url( $invite['code'] );
		$mail_sent      = null;

		if ( 'email' === $invite['channel'] && ! empty( $invite['email'] ) ) {
			$mail_sent = $this->send_invite_email_within_budget( $invite, $redemption_url, absint( $inviter_id ) );
		}

		return array(
			'invite'         => $invite,
			'redemption_url' => $redemption_url,
			'mail_sent'      => $mail_sent,
		);
	}

	/**
	 * Checks the inviter's mail budget and sends the invite email when there
	 * is room — or, when the budget is already exhausted, returns `false`
	 * without calling `wp_mail()` at all (SE-1). A budget refusal is reported
	 * back as an ordinary delivery failure (`mail_sent: false`), never a
	 * request failure — `deliver()`'s caller still gets the invite and its
	 * redemption URL either way (AC-026).
	 *
	 * MR-4 (cycle-7): renamed from `send_invite_email_within_budget()`'s
	 * call to `consume_mail_budget()` — that name (and this docblock, and
	 * `mail_budget_available()`'s own, both corrected in the same pass)
	 * described a spend that never actually happens. Nothing here
	 * increments or writes anything: `mail_budget_available()` is a pure
	 * `<=` comparison against `Invite_Repository::email_invite_count_in_window()`,
	 * a live `COUNT(*)` over `gl_invites`. The row that authorizes each send
	 * is the `gl_invites` row `insert_pending()` already committed before
	 * `deliver()` (and therefore this check) ever runs — THAT commit is
	 * what "spends" a unit of the budget, by making the very next count one
	 * higher; there is no separate spend step here to rename correctly. The
	 * SE-1 CRITICAL this fixed remains fixed regardless of the naming: the
	 * previous read-compare-then-write-on-success shape was a CWE-367
	 * TOCTOU, and counting a row that already exists on disk before this
	 * check runs has no equivalent race.
	 *
	 * @param array<string,mixed> $invite         Invite record.
	 * @param string               $redemption_url Redemption URL.
	 * @param int                  $inviter_id     Inviting member.
	 * @return bool
	 */
	private function send_invite_email_within_budget( array $invite, $redemption_url, $inviter_id ) {
		if ( ! $this->mail_budget_available( $inviter_id ) ) {
			return false;
		}

		return $this->send_invite_email( $invite, $redemption_url, $inviter_id );
	}

	/**
	 * Reports whether the inviter's outbound-mail budget for the current
	 * `QUOTA_WINDOW` still has room (SE-1, cycle-5). A pure read: counts
	 * real rows already committed in `gl_invites` —
	 * `Invite_Repository::email_invite_count_in_window()` — rather than an
	 * object-cache counter, and compares that count against `mail_budget()`.
	 * Nothing is spent, incremented, or written here. The row that
	 * authorizes each send is committed by `insert_pending()` before
	 * `deliver()` (and therefore this check) ever runs, so the count this
	 * compares against is durable and correct on first read, with no
	 * separate increment step and no read-then-write window for two
	 * concurrent requests to race.
	 *
	 * MR-4 (cycle-7): renamed from `consume_mail_budget()` — that name
	 * described a spend action this method never performed even before the
	 * rename; the authorizing `gl_invites` row committed by
	 * `insert_pending()` is what "spends" a unit of the budget, simply by
	 * existing the next time this count runs. `resend_invite()` sends mail
	 * without inserting a `gl_invites` row at all, so a resend is bounded
	 * separately, by `RESEND_COOLDOWN`/`RESEND_LIMIT` and
	 * `Invite_Repository::consume_resend_slot()` — never by this check.
	 *
	 * This replaces a cycle-3/cycle-4 object-cache increment
	 * (`wp_cache_add()` + `wp_cache_incr()`) that assumed a persistent,
	 * cross-request cache. This project's deployment target is `portable`
	 * with core's stock, non-persistent object cache (human ruling 1,
	 * `interrupts/review:conflict-pending-resolution.md`) — that cache is
	 * empty again at the start of every request, so the counter read `0`
	 * every time, `wp_cache_incr()` always returned `1`, `1 <= mail_budget()`
	 * was always true, and the cap was a no-op: any Subscriber could relay
	 * unbounded email via `POST /invites` (see `decisions/coder.md` for the
	 * substrate decision this fix is built on). A transient counter (tried
	 * and reverted between cycle-3 and cycle-4) has the same fail-open
	 * failure mode plus a CWE-367 TOCTOU on its own read-then-write pair.
	 * Counting `gl_invites` rows directly needs neither a cache substrate
	 * nor a lock and cannot go cold.
	 *
	 * @param int $inviter_id Inviting member.
	 * @return bool True when the send may proceed, false when the budget for
	 *              this window is already exhausted (the row committed by
	 *              insert_pending() already brought the count to the
	 *              ceiling or past it).
	 */
	private function mail_budget_available( $inviter_id ) {
		return $this->repository->email_invite_count_in_window( $inviter_id ) <= $this->mail_budget();
	}

	/**
	 * The outbound-mail-budget ceiling for one inviter within `QUOTA_WINDOW`
	 * (CO-1, cycle-3). Derived from the admin-editable `invite_quota` setting
	 * rather than a fixed constant: the plugin's own legitimate ceiling for
	 * one window is `invite_quota` creates plus up to `RESEND_LIMIT` resends
	 * of each, so a fixed budget below that figure (the previous
	 * `MAIL_BUDGET = 20`) started rejecting genuine sends for any inviter who
	 * used a meaningful fraction of their real allowance, and never tracked
	 * an administrator raising `invite_quota` for a launch push. The abuse
	 * vector this budget exists to close (SE-1, cycle-2: a create -> revoke
	 * -> create replay past `RESEND_LIMIT`) is already bounded by the same
	 * `invite_quota`-sized window via `Invite_Repository`'s own quota query,
	 * so deriving the ceiling from that same window's real maximum closes the
	 * vector without capping honest use.
	 *
	 * @return int
	 */
	private function mail_budget() {
		$quota = max( 1, absint( $this->get_settings()['invite_quota'] ) );

		return $quota * ( 1 + self::RESEND_LIMIT );
	}

	/**
	 * Sends the invite email. A `false` return from `wp_mail()` is passed
	 * straight back to the caller — it never touches the invite row's status
	 * (AC-026).
	 *
	 * @param array<string,mixed> $invite         Invite record.
	 * @param string               $redemption_url Redemption URL.
	 * @param int                  $inviter_id     Inviting member.
	 * @return bool
	 */
	private function send_invite_email( array $invite, $redemption_url, $inviter_id ) {
		$inviter      = get_userdata( $inviter_id );
		$inviter_name = $inviter ? $inviter->display_name : __( 'A member', 'game-library' );

		$subject = sprintf(
			/* translators: %s: inviting member's display name. */
			__( '%s invited you to Game Library', 'game-library' ),
			$inviter_name
		);

		$message = sprintf(
			/* translators: 1: inviting member's display name, 2: redemption URL. */
			__(
				"%1\$s has invited you to join Game Library.\n\nUse this link to create your account:\n%2\$s\n\nIf you did not expect this invite, you can ignore this email.",
				'game-library'
			),
			$inviter_name,
			$redemption_url
		);

		return (bool) wp_mail( $invite['email'], $subject, $message ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- a single transactional invite email per call (AC-026, spec-required), never bulk mail.
	}

	/**
	 * Generates one candidate invite code: 20 characters from
	 * `wp_generate_password( 20, false, false )`, upper-cased.
	 *
	 * @return string
	 */
	private function generate_code() {
		return strtoupper( wp_generate_password( 20, false, false ) );
	}

	/**
	 * The full redemption URL for a code (DD-008's `/join/{code}/` route).
	 * Invite codes are alphanumeric only (`wp_generate_password( 20, false,
	 * false )`'s charset has no character `esc_url()`/`rawurlencode()` would
	 * need to encode), so no encoding step is needed here — the template
	 * that renders this URL escapes it once, with `esc_url()`, at output.
	 *
	 * @param string $code Invite code.
	 * @return string
	 */
	private function redemption_url( $code ) {
		return Router::join_url( $code );
	}

	/**
	 * Reads `game_library_settings`, backfilling the two keys this class
	 * uses with their documented defaults when the stored option (or the
	 * option itself) is absent.
	 *
	 * @return array<string,int>
	 */
	private function get_settings() {
		$settings = Settings::all();

		return array(
			'invite_quota'       => absint( $settings['invite_quota'] ),
			'invite_expiry_days' => absint( $settings['invite_expiry_days'] ),
		);
	}
}
