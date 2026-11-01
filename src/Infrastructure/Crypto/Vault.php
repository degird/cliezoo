<?php
/**
 * Encrypted secrets (libsodium).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts secrets at rest with XChaCha20-Poly1305 (AEAD). The key comes from
 * CLIEZOO_ENCRYPTION_KEY (recommended) or is derived with HKDF from the
 * WordPress AUTH_KEY + SECURE_AUTH_KEY salts (Blueprint §8.8). Each secret is
 * bound to a context string ("gateway:12:secret_key") so a ciphertext cannot
 * be moved to another record. Plaintext is never logged or cached.
 */
final class Vault {

	private const PREFIX = 'cz1';

	/**
	 * Key override (re-key tool, tests).
	 *
	 * @var string|null
	 */
	private ?string $key = null;

	/**
	 * Where the key comes from: constant | salts.
	 *
	 * @return string
	 */
	public static function key_source(): string {
		return self::configured_key() ? 'constant' : 'salts';
	}

	/**
	 * The CLIEZOO_ENCRYPTION_KEY value (constant or environment), or ''.
	 *
	 * @return string
	 */
	private static function configured_key(): string {
		if ( defined( 'CLIEZOO_ENCRYPTION_KEY' ) && '' !== (string) CLIEZOO_ENCRYPTION_KEY ) {
			return (string) CLIEZOO_ENCRYPTION_KEY;
		}
		$env = getenv( 'CLIEZOO_ENCRYPTION_KEY' );
		return is_string( $env ) ? $env : '';
	}

	/**
	 * Derives the 32-byte key.
	 *
	 * @return string
	 */
	public function key(): string {
		if ( null !== $this->key ) {
			return $this->key;
		}
		$configured = self::configured_key();
		if ( '' !== $configured ) {
			$material = str_starts_with( $configured, 'base64:' ) ? (string) base64_decode( substr( $configured, 7 ), true ) : $configured; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Key encoding.
			return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES );
		}
		$salts = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );
		return hash_hkdf( 'sha256', $salts, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 'cliezoo-vault-v1', 'cliezoo' );
	}

	/**
	 * Uses a specific key (re-keying, tests).
	 *
	 * @param string|null $key 32-byte key or null to reset.
	 * @return void
	 */
	public function use_key( ?string $key ): void {
		$this->key = $key;
	}

	/**
	 * Short fingerprint of the current key (stored with each ciphertext).
	 *
	 * @return string
	 */
	public function key_id(): string {
		return substr( bin2hex( sodium_crypto_generichash( $this->key(), '', 16 ) ), 0, 8 );
	}

	/**
	 * Encrypts a secret for a context.
	 *
	 * @param string $plaintext Secret.
	 * @param string $context   Binding context.
	 * @return string "cz1:{keyid}:{base64(nonce|ciphertext)}"
	 */
	public function encrypt( string $plaintext, string $context ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, $context, $nonce, $this->key() );
		return self::PREFIX . ':' . $this->key_id() . ':' . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Ciphertext encoding.
	}

	/**
	 * Decrypts a secret. Returns null when the key changed or the data was tampered with.
	 *
	 * @param string|null $blob    Stored value.
	 * @param string      $context Binding context.
	 * @return string|null
	 */
	public function decrypt( ?string $blob, string $context ): ?string {
		if ( null === $blob || '' === $blob ) {
			return null;
		}
		$parts = explode( ':', $blob, 3 );
		if ( 3 !== count( $parts ) || self::PREFIX !== $parts[0] ) {
			return null;
		}
		$raw = base64_decode( $parts[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Ciphertext encoding.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		try {
			$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $cipher, $context, $nonce, $this->key() );
		} catch ( \SodiumException $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}

	/**
	 * Whether a stored value was encrypted with another key (salts changed after a migration).
	 *
	 * @param string|null $blob Stored value.
	 * @return bool
	 */
	public function needs_rekey( ?string $blob ): bool {
		if ( null === $blob || '' === $blob ) {
			return false;
		}
		$parts = explode( ':', $blob, 3 );
		return 3 === count( $parts ) && $parts[1] !== $this->key_id();
	}

	/**
	 * Masked display form ("••••••••1234"). Never returns the full secret.
	 *
	 * @param string|null $secret Plaintext.
	 * @return string
	 */
	public static function mask( ?string $secret ): string {
		if ( null === $secret || '' === $secret ) {
			return '';
		}
		$tail = strlen( $secret ) > 8 ? substr( $secret, -4 ) : '';
		return str_repeat( '•', 8 ) . $tail;
	}

	/**
	 * Re-encrypts every registered secret from an old key to the current one.
	 *
	 * Secrets already under the current key are left alone, so running it
	 * twice — or after a partial run — is safe. A secret that neither key
	 * opens is counted as failed and left as it is: it was encrypted with
	 * a third key, or damaged, and overwriting it would lose it for good.
	 *
	 * @param string $old_key Previous 32-byte key.
	 * @param bool   $dry     Count only, change nothing.
	 * @return array{rekeyed: int, failed: int, current: int}
	 */
	public function rekey( string $old_key, bool $dry = false ): array {
		global $wpdb;
		$current = $this->key();
		$result  = array(
			'rekeyed' => 0,
			'failed'  => 0,
			'current' => 0,
		);
		$move    = function ( string $blob, string $context ) use ( $old_key, $current, &$result ): ?string {
			if ( ! $this->needs_rekey( $blob ) ) {
				++$result['current'];
				return null;
			}
			$this->use_key( $old_key );
			$plain = $this->decrypt( $blob, $context );
			$this->use_key( $current );
			if ( null === $plain ) {
				++$result['failed'];
				return null;
			}
			++$result['rekeyed'];
			return $this->encrypt( $plain, $context );
		};
		foreach ( self::locations() as $location ) {
			list( $table, $column, $context ) = $location;
			$rows                             = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE %i IS NOT NULL AND %i <> ''", $table, $column, $column ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Re-encrypting stored secrets in place.
			foreach ( $rows as $row ) {
				$fresh = $move( (string) $row[ $column ], (string) call_user_func( $context, $row ) );
				if ( null !== $fresh && ! $dry ) {
					$wpdb->update( $table, array( $column => $fresh ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
		}
		foreach ( self::option_locations() as $location ) {
			list( $option, $key, $context ) = $location;
			$values                         = get_option( $option );
			if ( ! is_array( $values ) || empty( $values[ $key ] ) || ! is_string( $values[ $key ] ) ) {
				continue;
			}
			$fresh = $move( $values[ $key ], (string) $context );
			if ( null !== $fresh && ! $dry ) {
				$values[ $key ] = $fresh;
				update_option( $option, $values, false );
				wp_cache_delete( 'settings_' . substr( $option, strlen( 'cliezoo_settings_' ) ), 'cliezoo' );
			}
		}
		$this->use_key( null );
		return $result;
	}

	/**
	 * Tables holding secrets: [table, column, fn( array $row ): context].
	 *
	 * @return array<int, array{0: string, 1: string, 2: callable}>
	 */
	public static function locations(): array {
		/**
		 * Locations of encrypted values: list of [table, column, context callback fn( array $row ): string].
		 *
		 * @param array $locations Locations.
		 */
		return (array) apply_filters( 'cliezoo_secret_locations', array() );
	}

	/**
	 * Settings holding secrets: [option, key, context].
	 *
	 * The AI provider key is kept in the AI settings (encrypted as it is
	 * saved, context "ai_key"), which is why it is listed here by default.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string}>
	 */
	public static function option_locations(): array {
		/**
		 * Settings holding encrypted values: list of [option name, key, context].
		 *
		 * @param array $locations Locations.
		 */
		return (array) apply_filters( 'cliezoo_secret_options', array( array( 'cliezoo_settings_ai', 'revoye_key', 'ai_key' ) ) );
	}

	/**
	 * The key the salts gave before they changed, for re-keying.
	 *
	 * @param string $auth_key        Old AUTH_KEY.
	 * @param string $secure_auth_key Old SECURE_AUTH_KEY.
	 * @return string
	 */
	public static function key_from_salts( string $auth_key, string $secure_auth_key ): string {
		return hash_hkdf( 'sha256', $auth_key . $secure_auth_key, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 'cliezoo-vault-v1', 'cliezoo' );
	}

	/**
	 * The key an old CLIEZOO_ENCRYPTION_KEY value gave, for re-keying.
	 *
	 * @param string $configured The old constant's value ("base64:…" or raw).
	 * @return string
	 */
	public static function key_from_constant( string $configured ): string {
		$material = str_starts_with( $configured, 'base64:' ) ? (string) base64_decode( substr( $configured, 7 ), true ) : $configured; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Key encoding.
		return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES );
	}

	/**
	 * Derives the key the salts would give (for re-keying after adding a constant).
	 *
	 * @return string
	 */
	public static function salts_key(): string {
		$salts = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );
		return hash_hkdf( 'sha256', $salts, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 'cliezoo-vault-v1', 'cliezoo' );
	}

	/**
	 * Random token (URL-safe) and its HMAC-SHA256 hash for storage.
	 *
	 * @param int $bytes Random bytes (16 = 128 bits, 32 = 256 bits).
	 * @return array{token: string, hash: string}
	 */
	public function token( int $bytes = 16 ): array {
		$token = rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Token encoding.
		return array(
			'token' => $token,
			'hash'  => $this->hash_token( $token ),
		);
	}

	/**
	 * HMAC hash of a token (tokens are never stored in plain text).
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public function hash_token( string $token ): string {
		return hash_hmac( 'sha256', $token, $this->key() . 'tokens' );
	}

	/**
	 * Signs data for expiring URLs (HMAC-SHA256).
	 *
	 * @param string $data Data.
	 * @return string
	 */
	public function sign( string $data ): string {
		return rtrim( strtr( base64_encode( hash_hmac( 'sha256', $data, $this->key() . 'urls', true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Signature encoding.
	}

	/**
	 * Verifies a signature in constant time.
	 *
	 * @param string $data      Data.
	 * @param string $signature Signature.
	 * @return bool
	 */
	public function verify( string $data, string $signature ): bool {
		return hash_equals( $this->sign( $data ), $signature );
	}
}
