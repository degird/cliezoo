<?php
/**
 * Debug logger with secret redaction.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes to the PHP error log only when WP_DEBUG_LOG or the Cliezoo debug
 * setting is on. Redacts keys, tokens, passwords and email addresses.
 */
final class Logger {

	private const SECRET_KEYS = '/(key|secret|token|password|passcode|authorization|signature|otp|code_verifier|cookie|nonce)/i';

	/**
	 * Logs an error.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	public static function error( string $message, array $context = array() ): void {
		self::write( 'error', $message, $context );
	}

	/**
	 * Logs a warning.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::write( 'warning', $message, $context );
	}

	/**
	 * Logs debug information.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	public static function debug( string $message, array $context = array() ): void {
		self::write( 'debug', $message, $context );
	}

	/**
	 * Whether logging is on.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) || (bool) get_option( 'cliezoo_debug_log', false );
	}

	/**
	 * Redacts secrets in a context array.
	 *
	 * @param mixed  $value Value.
	 * @param string $key   Key of the value.
	 * @return mixed
	 */
	public static function redact( $value, string $key = '' ) {
		if ( '' !== $key && preg_match( self::SECRET_KEYS, $key ) ) {
			return '[redacted]';
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::redact( $v, (string) $k );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			$value = (string) preg_replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $value );
			$value = (string) preg_replace( '/\b(sk|pk|rk|whsec|Bearer)[_ -][A-Za-z0-9_\-]{8,}/', '[redacted]', $value );
		}
		return $value;
	}

	/**
	 * Writes one line.
	 *
	 * @param string               $level   Level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	private static function write( string $level, string $message, array $context ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$line = '[cliezoo][' . $level . '] ' . self::redact( $message );
		if ( $context ) {
			$line .= ' ' . wp_json_encode( self::redact( $context ) );
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The debug logger, gated by WP_DEBUG_LOG / setting.
	}
}
