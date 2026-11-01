<?php
/**
 * ULID generator (public ids).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 26-character, time-sortable, non-enumerable identifiers (Crockford base32).
 */
final class Ulid {

	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * Last timestamp used, for monotonic ids within one millisecond.
	 *
	 * @var int
	 */
	private static int $last_time = 0;

	/**
	 * Last random part (10 bytes as 16 base32 chars), for monotonic increments.
	 *
	 * @var int[]
	 */
	private static array $last_random = array();

	/**
	 * Generates a new ULID.
	 *
	 * @return string
	 */
	public static function generate(): string {
		$time = (int) floor( microtime( true ) * 1000 );
		if ( $time === self::$last_time && self::$last_random ) {
			$random = self::increment( self::$last_random );
		} else {
			$random = array();
			$bytes  = random_bytes( 16 );
			for ( $i = 0; $i < 16; $i++ ) {
				$random[] = ord( $bytes[ $i ] ) % 32;
			}
		}
		self::$last_time   = $time;
		self::$last_random = $random;

		$time_part = '';
		for ( $i = 9; $i >= 0; $i-- ) {
			$time_part = self::ALPHABET[ $time % 32 ] . $time_part;
			$time      = intdiv( $time, 32 );
		}
		$random_part = '';
		foreach ( $random as $value ) {
			$random_part .= self::ALPHABET[ $value ];
		}
		return $time_part . $random_part;
	}

	/**
	 * Whether a string is a well-formed ULID.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function valid( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $value );
	}

	/**
	 * Increments a base32 digit array by one.
	 *
	 * @param int[] $digits Digits.
	 * @return int[]
	 */
	private static function increment( array $digits ): array {
		for ( $i = count( $digits ) - 1; $i >= 0; $i-- ) {
			if ( $digits[ $i ] < 31 ) {
				++$digits[ $i ];
				return $digits;
			}
			$digits[ $i ] = 0;
		}
		return $digits;
	}
}
