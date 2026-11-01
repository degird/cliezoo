<?php
/**
 * Time-based one-time passwords (RFC 6238).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TOTP with SHA-1, 30-second steps and 6 digits (the authenticator-app
 * default), ±1 step tolerance for clock drift (§31.3) and replay protection
 * through the last accepted step.
 */
final class Totp {

	private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/**
	 * New random base32 secret (160 bits).
	 *
	 * @return string
	 */
	public static function secret(): string {
		$bytes = random_bytes( 20 );
		$bits  = '';
		foreach ( str_split( $bytes ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::ALPHABET[ bindec( str_pad( $chunk, 5, '0' ) ) ];
		}
		return $out;
	}

	/**
	 * Decodes base32.
	 *
	 * @param string $secret Base32 secret.
	 * @return string
	 */
	private static function decode( string $secret ): string {
		$secret = strtoupper( (string) preg_replace( '/[^A-Za-z2-7]/', '', $secret ) );
		$bits   = '';
		foreach ( str_split( $secret ) as $char ) {
			$bits .= str_pad( decbin( (int) strpos( self::ALPHABET, $char ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( (int) bindec( $byte ) );
			}
		}
		return $out;
	}

	/**
	 * The code for a time step.
	 *
	 * @param string $secret Base32 secret.
	 * @param int    $step   Time step (unix time / 30).
	 * @return string
	 */
	public static function code( string $secret, int $step ): string {
		$hash   = hash_hmac( 'sha1', pack( 'J', $step ), self::decode( $secret ), true );
		$offset = ord( $hash[19] ) & 0x0f;
		$value  = ( ( ord( $hash[ $offset ] ) & 0x7f ) << 24 ) | ( ( ord( $hash[ $offset + 1 ] ) & 0xff ) << 16 ) | ( ( ord( $hash[ $offset + 2 ] ) & 0xff ) << 8 ) | ( ord( $hash[ $offset + 3 ] ) & 0xff );
		return str_pad( (string) ( $value % 1000000 ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Verifies a code; returns the matched step (for replay protection) or null.
	 *
	 * @param string   $secret    Base32 secret.
	 * @param string   $code      User code.
	 * @param int      $last_step Last accepted step (codes at or before it are rejected).
	 * @param int|null $now       Unix time (tests).
	 * @return int|null
	 */
	public static function verify( string $secret, string $code, int $last_step = 0, ?int $now = null ): ?int {
		$code = (string) preg_replace( '/\D/', '', $code );
		if ( 6 !== strlen( $code ) ) {
			return null;
		}
		$step = intdiv( $now ?? time(), 30 );
		foreach ( array( 0, -1, 1 ) as $drift ) {
			$candidate = $step + $drift;
			if ( $candidate > $last_step && hash_equals( self::code( $secret, $candidate ), $code ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * The otpauth:// URI for authenticator apps.
	 *
	 * @param string $secret  Secret.
	 * @param string $account Account label (email).
	 * @param string $issuer  Issuer (workspace name).
	 * @return string
	 */
	public static function uri( string $secret, string $account, string $issuer ): string {
		return 'otpauth://totp/' . rawurlencode( $issuer . ':' . $account ) . '?secret=' . $secret . '&issuer=' . rawurlencode( $issuer ) . '&algorithm=SHA1&digits=6&period=30';
	}
}
