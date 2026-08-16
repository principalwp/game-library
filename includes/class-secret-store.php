<?php
/**
 * Encrypted-at-rest store for the IGDB/Twitch Client Secret.
 *
 * @package Game_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule-#6 deviation (accepted, mitigated — AC-015): the Client Secret is entered
 * through wp-admin because the plugin must run on managed hosting where the dev
 * cannot edit wp-config.php. It is therefore never written to wp_options in
 * plaintext. Instead it is sealed with sodium_crypto_secretbox under a key
 * derived from the WP salts (which live in wp-config.php, not the database), so a
 * DB-only dump cannot decrypt it.
 *
 * Writes (store/delete) are the user-facing surface and are only reached from the
 * settings save handler, which is nonce + manage_options gated. reveal() is
 * internal server-side plumbing used solely by the IGDB client for the token
 * exchange; the plaintext secret is never returned to any client, echoed to any
 * screen, or exposed through any REST/AJAX response.
 *
 * The low-level seal()/open() primitives are also reused by the IGDB client to
 * encrypt the cached Twitch access token at rest (SE-1), so the token the secret
 * mints is protected the same way the secret itself is.
 */
final class Game_Library_Secret_Store {

	/**
	 * Option key holding the sealed secret (base64 of nonce || ciphertext).
	 */
	const OPTION = 'game_library_igdb_client_secret';

	/**
	 * Whether libsodium secretbox is available in this PHP runtime.
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return function_exists( 'sodium_crypto_secretbox' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
	}

	/**
	 * Whether an encrypted secret is currently stored.
	 *
	 * @return bool
	 */
	public static function has_secret() {
		$stored = get_option( self::OPTION, '' );
		return is_string( $stored ) && '' !== $stored;
	}

	/**
	 * Encrypt and persist the secret. Called only from the manage_options + nonce
	 * gated settings handler.
	 *
	 * @param string $plaintext The Client Secret in the clear.
	 * @return bool True on success.
	 */
	public static function store( $plaintext ) {
		if ( ! self::is_supported() ) {
			return false;
		}
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return false;
		}

		$sealed = self::seal( $plaintext );
		sodium_memzero( $plaintext );
		if ( null === $sealed ) {
			return false;
		}

		return (bool) update_option( self::OPTION, $sealed, false );
	}

	/**
	 * Decrypt the stored secret for internal token-exchange use only.
	 *
	 * @return string|null Plaintext secret, or null when absent/unreadable.
	 */
	public static function reveal() {
		$sealed = get_option( self::OPTION, '' );
		if ( ! is_string( $sealed ) || '' === $sealed ) {
			return null;
		}

		return self::open( $sealed );
	}

	/**
	 * Delete the stored secret.
	 *
	 * @return void
	 */
	public static function delete() {
		delete_option( self::OPTION );
	}

	/**
	 * Seal an arbitrary plaintext string with secretbox under the salt-derived key.
	 *
	 * Shared primitive: used for the Client Secret (store()) and, by the IGDB
	 * client, for the cached Twitch access token. Each call uses a fresh random
	 * nonce, so reusing the key across distinct plaintexts is safe.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string|null Base64 of (nonce || ciphertext), or null when unsupported/empty.
	 */
	public static function seal( $plaintext ) {
		if ( ! self::is_supported() ) {
			return null;
		}
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return null;
		}

		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );

		return base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Storing binary ciphertext in a text option, not obfuscation.
	}

	/**
	 * Open a value produced by seal().
	 *
	 * @param string $sealed Base64 of (nonce || ciphertext) from seal().
	 * @return string|null Plaintext, or null when unsupported/absent/undecryptable.
	 */
	public static function open( $sealed ) {
		if ( ! self::is_supported() ) {
			return null;
		}
		if ( ! is_string( $sealed ) || '' === $sealed ) {
			return null;
		}

		$raw = base64_decode( $sealed, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding stored binary ciphertext.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, self::key() );
		if ( false === $plaintext ) {
			return null;
		}

		return $plaintext;
	}

	/**
	 * Derive a 32-byte symmetric key from the WP salts (held in wp-config.php).
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function key() {
		$ikm = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );

		if ( function_exists( 'hash_hkdf' ) ) {
			return hash_hkdf( 'sha256', $ikm, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'game-library-igdb-client-secret' );
		}

		// Fallback: a raw sha256 digest is exactly 32 bytes.
		return hash( 'sha256', 'game-library-igdb-client-secret|' . $ikm, true );
	}
}
