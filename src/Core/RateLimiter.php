<?php
/**
 * Simple fixed-window rate limiter.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts hits per key in a time window (transients, so an object cache makes
 * it shared across PHP workers). Used for logins, magic links, public
 * tokens, form spam and MCP abuse limits.
 */
final class RateLimiter {

	/**
	 * Records a hit and reports whether the limit is exceeded.
	 *
	 * @param string $key    Bucket key (hashed).
	 * @param int    $max    Allowed hits per window.
	 * @param int    $window Window in seconds.
	 * @return bool True when the request must be refused.
	 */
	public static function hit( string $key, int $max, int $window ): bool {
		$name  = 'cliezoo_rl_' . md5( $key );
		$state = get_transient( $name );
		$now   = time();
		if ( ! is_array( $state ) || (int) $state['until'] < $now ) {
			$state = array(
				'count' => 0,
				'until' => $now + $window,
			);
		}
		++$state['count'];
		set_transient( $name, $state, max( 1, (int) $state['until'] - $now ) );
		return $state['count'] > $max;
	}

	/**
	 * Whether a key is currently over its limit (without counting a hit).
	 *
	 * @param string $key Bucket key.
	 * @param int    $max Allowed hits.
	 * @return bool
	 */
	public static function blocked( string $key, int $max ): bool {
		$state = get_transient( 'cliezoo_rl_' . md5( $key ) );
		return is_array( $state ) && (int) $state['until'] >= time() && (int) $state['count'] >= $max;
	}

	/**
	 * Seconds until a key's window resets.
	 *
	 * @param string $key Bucket key.
	 * @return int
	 */
	public static function retry_after( string $key ): int {
		$state = get_transient( 'cliezoo_rl_' . md5( $key ) );
		return is_array( $state ) ? max( 0, (int) $state['until'] - time() ) : 0;
	}

	/**
	 * Hits in the current window.
	 *
	 * @param string $key Bucket key.
	 * @return int
	 */
	public static function count( string $key ): int {
		$state = get_transient( 'cliezoo_rl_' . md5( $key ) );
		return is_array( $state ) && (int) $state['until'] >= time() ? (int) $state['count'] : 0;
	}

	/**
	 * Holds a key over its limit for a given time (lockout back-off).
	 *
	 * @param string $key     Bucket key.
	 * @param int    $max     The key's limit.
	 * @param int    $seconds Lock length.
	 * @return void
	 */
	public static function lock( string $key, int $max, int $seconds ): void {
		$seconds = max( 1, $seconds );
		set_transient(
			'cliezoo_rl_' . md5( $key ),
			array(
				'count' => $max + 1,
				'until' => time() + $seconds,
			),
			$seconds
		);
	}

	/**
	 * Clears a key (after a successful login).
	 *
	 * @param string $key Bucket key.
	 * @return void
	 */
	public static function clear( string $key ): void {
		delete_transient( 'cliezoo_rl_' . md5( $key ) );
	}
}
