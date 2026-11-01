<?php
/**
 * Request helpers (client IP, user agent).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads client details safely. Proxy headers are trusted only when the site
 * opts in with the CLIEZOO_TRUSTED_PROXY_HEADER constant, because they can be spoofed.
 */
final class Request {

	/**
	 * Client IP address (validated), or "0.0.0.0".
	 *
	 * @return string
	 */
	public static function ip(): string {
		$candidates = array();
		if ( defined( 'CLIEZOO_TRUSTED_PROXY_HEADER' ) ) {
			$header = 'HTTP_' . strtoupper( str_replace( '-', '_', (string) CLIEZOO_TRUSTED_PROXY_HEADER ) );
			if ( isset( $_SERVER[ $header ] ) ) {
				$parts        = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				$candidates[] = trim( $parts[0] );
			}
		}
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		foreach ( $candidates as $candidate ) {
			if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}
		return '0.0.0.0';
	}

	/**
	 * User agent (trimmed).
	 *
	 * @return string
	 */
	public static function user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
	}

	/**
	 * Short device description from the user agent ("Chrome on Windows").
	 *
	 * @param string $agent User agent.
	 * @return string
	 */
	public static function device( string $agent ): string {
		$browser = 'Browser';
		foreach ( array(
			'Edg/'     => 'Edge',
			'OPR/'     => 'Opera',
			'Firefox/' => 'Firefox',
			'Chrome/'  => 'Chrome',
			'Safari/'  => 'Safari',
		) as $needle => $name ) {
			if ( str_contains( $agent, $needle ) ) {
				$browser = $name;
				break;
			}
		}
		$os = '';
		foreach ( array(
			'Windows'  => 'Windows',
			'Android'  => 'Android',
			'iPhone'   => 'iOS',
			'iPad'     => 'iPadOS',
			'Mac OS X' => 'macOS',
			'Linux'    => 'Linux',
		) as $needle => $name ) {
			if ( str_contains( $agent, $needle ) ) {
				$os = $name;
				break;
			}
		}
		/* translators: 1: browser name, 2: operating system. */
		return '' === $os ? $browser : sprintf( __( '%1$s on %2$s', 'cliezoo' ), $browser, $os );
	}
}
