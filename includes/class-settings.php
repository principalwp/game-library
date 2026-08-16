<?php
/**
 * Settings screen, IGDB credential resolution, secret encryption, admin notices.
 *
 * @package Game_Library
 */

namespace Game_Library;

defined( 'ABSPATH' ) || exit;

/**
 * IGDB credentials (constant > option), secret-at-rest encryption, admin notices.
 */
class Settings {

	const OPTION_GROUP  = 'gl_settings';
	const OPTION_ID     = 'gl_igdb_client_id';
	const OPTION_SECRET = 'gl_igdb_client_secret_enc';
	const OPTION_QUOTA  = 'gl_invite_quota';
	const PAGE_SLUG     = 'game-library';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Add the Settings -> Game Library screen.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Game Library', 'game-library-3' ),
			__( 'Game Library', 'game-library-3' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the two settings with sanitize callbacks.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_ID,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_client_id' ),
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			self::OPTION_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_secret' ),
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			self::OPTION_QUOTA,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_quota' ),
				'default'           => 5,
			)
		);
	}

	/**
	 * Sanitize the monthly invite quota (non-negative integer).
	 *
	 * @param mixed $value Raw posted value.
	 * @return int
	 */
	public function sanitize_quota( $value ) {
		return max( 0, absint( $value ) );
	}

	/**
	 * Sanitize the client id; the constant wins so the option is ignored when set.
	 *
	 * @param mixed $value Raw posted value.
	 * @return string
	 */
	public function sanitize_client_id( $value ) {
		if ( defined( 'GL_IGDB_CLIENT_ID' ) ) {
			return (string) get_option( self::OPTION_ID, '' );
		}
		return sanitize_text_field( is_string( $value ) ? $value : '' );
	}

	/**
	 * Sanitize (and encrypt) the client secret. Empty input leaves the stored
	 * secret unchanged; the constant path stores nothing. The value stored is
	 * always the encrypted blob, never plaintext.
	 *
	 * @param mixed $value Raw posted plaintext (already unslashed by options.php).
	 * @return string Encrypted blob, or the existing one unchanged.
	 */
	public function sanitize_secret( $value ) {
		$existing = (string) get_option( self::OPTION_SECRET, '' );

		if ( defined( 'GL_IGDB_CLIENT_SECRET' ) ) {
			return $existing;
		}

		$plaintext = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $plaintext ) {
			return $existing;
		}

		if ( ! self::sodium_available() ) {
			add_settings_error(
				self::OPTION_SECRET,
				'gl_no_sodium',
				__( 'The Sodium extension is unavailable, so the IGDB client secret cannot be encrypted at rest. Define GL_IGDB_CLIENT_SECRET in wp-config.php instead.', 'game-library-3' ),
				'error'
			);
			return $existing;
		}

		$encrypted = self::encrypt( $plaintext );
		return ( null === $encrypted ) ? $existing : $encrypted;
	}

	/**
	 * The resolved IGDB client id (constant first, option second).
	 *
	 * @return string
	 */
	public function get_client_id() {
		if ( defined( 'GL_IGDB_CLIENT_ID' ) ) {
			return (string) GL_IGDB_CLIENT_ID;
		}
		return (string) get_option( self::OPTION_ID, '' );
	}

	/**
	 * The resolved IGDB client secret (constant first, decrypted option second).
	 * Only ever called by manage_options handlers or the server-side client.
	 *
	 * @return string
	 */
	public function get_secret() {
		if ( defined( 'GL_IGDB_CLIENT_SECRET' ) ) {
			return (string) GL_IGDB_CLIENT_SECRET;
		}
		$stored = (string) get_option( self::OPTION_SECRET, '' );
		if ( '' === $stored || ! self::sodium_available() ) {
			return '';
		}
		$plain = self::decrypt( $stored );
		return ( null === $plain ) ? '' : $plain;
	}

	/**
	 * Whether both credentials resolve to a non-empty value.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== $this->get_client_id() && '' !== $this->get_secret();
	}

	/**
	 * Whether a saved (encrypted) secret exists in the option store.
	 *
	 * @return bool
	 */
	public function has_saved_secret() {
		return '' !== (string) get_option( self::OPTION_SECRET, '' );
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$id_from_constant     = defined( 'GL_IGDB_CLIENT_ID' );
		$secret_from_constant = defined( 'GL_IGDB_CLIENT_SECRET' );
		$sodium_ok            = self::sodium_available();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Game Library — IGDB Credentials', 'game-library-3' ); ?></h1>

			<?php if ( ! $sodium_ok && ! $secret_from_constant ) : ?>
				<div class="notice notice-error">
					<p><?php echo esc_html__( 'The Sodium extension is unavailable, so the client secret cannot be encrypted at rest. Define GL_IGDB_CLIENT_SECRET (and GL_IGDB_CLIENT_ID) in wp-config.php instead of using the field below.', 'game-library-3' ); ?></p>
				</div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="gl_igdb_client_id"><?php echo esc_html__( 'IGDB (Twitch) Client ID', 'game-library-3' ); ?></label>
						</th>
						<td>
							<input
								name="<?php echo esc_attr( self::OPTION_ID ); ?>"
								id="gl_igdb_client_id"
								type="text"
								class="regular-text"
								value="<?php echo esc_attr( $this->get_client_id() ); ?>"
								<?php echo $id_from_constant ? 'readonly' : ''; ?>
							/>
							<?php if ( $id_from_constant ) : ?>
								<p class="notice notice-info inline" style="padding:6px 12px;"><?php echo esc_html__( 'Defined in wp-config.php via GL_IGDB_CLIENT_ID; the field is read-only.', 'game-library-3' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gl_igdb_client_secret"><?php echo esc_html__( 'IGDB (Twitch) Client Secret', 'game-library-3' ); ?></label>
						</th>
						<td>
							<input
								name="<?php echo esc_attr( self::OPTION_SECRET ); ?>"
								id="gl_igdb_client_secret"
								type="password"
								class="regular-text"
								value=""
								autocomplete="new-password"
								<?php echo ( $secret_from_constant || ! $sodium_ok ) ? 'readonly' : ''; ?>
							/>
							<?php if ( $secret_from_constant ) : ?>
								<p class="notice notice-info inline" style="padding:6px 12px;"><?php echo esc_html__( 'Defined in wp-config.php via GL_IGDB_CLIENT_SECRET; the field is read-only and the value is never shown.', 'game-library-3' ); ?></p>
							<?php elseif ( $this->has_saved_secret() ) : ?>
								<p class="description"><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php echo esc_html__( 'A secret is saved (encrypted). Leave blank to keep it; enter a new value to replace it.', 'game-library-3' ); ?></p>
							<?php else : ?>
								<p class="description"><?php echo esc_html__( 'The secret is encrypted before storage and never shown again.', 'game-library-3' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gl_invite_quota"><?php echo esc_html__( 'Invites per member per month', 'game-library-3' ); ?></label>
						</th>
						<td>
							<input
								name="<?php echo esc_attr( self::OPTION_QUOTA ); ?>"
								id="gl_invite_quota"
								type="number"
								min="0"
								step="1"
								class="small-text"
								value="<?php echo esc_attr( (string) get_option( self::OPTION_QUOTA, 5 ) ); ?>"
							/>
							<p class="description"><?php echo esc_html__( 'How many invites each member may create per calendar month. Resets on the 1st.', 'game-library-3' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Admin notices: pretty-permalink requirement and IGDB failure summary.
	 *
	 * @return void
	 */
	public function admin_notices() {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$url = admin_url( 'options-permalink.php' );
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: URL of the Permalinks settings screen. */
				wp_kses_post( __( 'Game Library requires pretty permalinks to be enabled. Please set a permalink structure other than "Plain" on the <a href="%s">Permalink Settings</a> screen.', 'game-library-3' ) ),
				esc_url( $url )
			);
			echo '</p></div>';
		}

		if ( current_user_can( 'manage_options' ) ) {
			$error = get_transient( 'gl_igdb_last_error' );
			if ( is_array( $error ) && ! empty( $error['message'] ) ) {
				echo '<div class="notice notice-error"><p>';
				echo esc_html(
					sprintf(
						/* translators: 1: error summary, 2: timestamp. */
						__( 'Game Library: the last IGDB request failed — %1$s (at %2$s).', 'game-library-3' ),
						(string) $error['message'],
						isset( $error['time'] ) ? (string) $error['time'] : ''
					)
				);
				echo '</p></div>';
			}
		}
	}

	/**
	 * Whether Sodium secretbox is available.
	 *
	 * @return bool
	 */
	public static function sodium_available() {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
	}

	/**
	 * Derive the 32-byte secretbox key from the site salts (not the DB).
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );
		if ( '' === $material ) {
			$material = wp_salt( 'auth' );
		}
		return hash_hkdf( 'sha256', $material, 32, 'gl-igdb' );
	}

	/**
	 * Encrypt a plaintext secret. Returns base64(nonce . ciphertext).
	 *
	 * @param string $plaintext Secret.
	 * @return string|null
	 */
	private static function encrypt( $plaintext ) {
		if ( ! self::sodium_available() ) {
			return null;
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
		return base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding of ciphertext, not obfuscation.
	}

	/**
	 * Decrypt a stored secret blob.
	 *
	 * @param string $stored base64(nonce . ciphertext).
	 * @return string|null
	 */
	private static function decrypt( $stored ) {
		if ( ! self::sodium_available() ) {
			return null;
		}
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding our own ciphertext.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		return ( false === $plain ) ? null : $plain;
	}
}
