<?php
/**
 * Outbound HTTP with an SSRF guard.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every outbound request goes through here (Blueprint §17.2 SSRF):
 * - http/https only; https required except for allowlisted local hosts
 * - the host is resolved and private, loopback, link-local, CGNAT and cloud
 *   metadata addresses are blocked (IPv4 + IPv6)
 * - the connection is pinned to the checked IP (no DNS rebinding)
 * - redirects are off by default (keys are never resent to a redirect target)
 * - wp_safe_remote_request() with sensible timeouts
 * - safe retries with back-off for temporary failures (network, 429, 502–504)
 */
final class SafeHttp {

	/**
	 * Performs a request.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     URL.
	 * @param array<string, mixed> $args    wp_remote args + `allow_local` (bool).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function request( string $method, string $url, array $args = array() ) {
		$allow_local = ! empty( $args['allow_local'] );
		unset( $args['allow_local'] );
		$check = self::check( $url, $allow_local );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		list( $host, $port, $ip )   = $check;
		$args                       = array_merge(
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'user-agent'  => 'Cliezoo/' . ( defined( 'CLIEZOO_VERSION' ) ? CLIEZOO_VERSION : '1' ) . '; ' . home_url(),
			),
			$args
		);
		$args['method']             = strtoupper( $method );
		$args['reject_unsafe_urls'] = ! $allow_local;
		// Set after the merge, not before: a caller cannot ask to follow a
		// redirect, because the second address was never checked and never
		// pinned.
		$args['redirection'] = 0;

		// An answer that arrives in pieces (AI streaming): cURL hands each
		// piece to the caller as it lands, so nothing waits for the whole
		// body. Without cURL the request still works, it just arrives at
		// the end.
		$on_chunk = $args['stream_callback'] ?? null;
		unset( $args['stream_callback'] );

		$pin = static function ( $handle ) use ( $host, $port, $ip, $on_chunk ): void {
			if ( defined( 'CURLOPT_RESOLVE' ) ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . ( str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Pins the resolved IP (DNS rebinding defence) on the WordPress HTTP API handle.
			}
			if ( is_callable( $on_chunk ) ) {
				curl_setopt( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Streaming needs the raw handle; the WordPress HTTP API has no callback of its own.
					$handle,
					CURLOPT_WRITEFUNCTION,
					static function ( $ignored, string $chunk ) use ( $on_chunk ): int {
						unset( $ignored );
						$on_chunk( $chunk );
						// cURL stops the transfer unless every byte is accounted for.
						return strlen( $chunk );
					}
				);
			}
		};
		add_action( 'http_api_curl', $pin );
		try {
			return $allow_local ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );
		} finally {
			// Always: a callback left on the hook would pin the next
			// request in this process to this request's address.
			remove_action( 'http_api_curl', $pin );
		}
	}

	/**
	 * GET with retries (idempotent calls only): up to 3 tries with back-off on
	 * network errors, 429 and 5xx. A Retry-After of a few seconds is honoured;
	 * longer waits are left to the caller (usually a background job).
	 *
	 * @param string               $url  URL.
	 * @param array<string, mixed> $args Args.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get( string $url, array $args = array() ) {
		$waited = 0;
		for ( $attempt = 1; ; $attempt++ ) {
			$response = self::request( 'GET', $url, $args );
			if ( is_wp_error( $response ) && 'cliezoo_blocked_url' === $response->get_error_code() ) {
				return $response;
			}
			$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( ( $status > 0 && $status < 500 && 429 !== $status ) || $attempt >= 3 ) {
				return $response;
			}
			$after = $status ? (int) wp_remote_retrieve_header( $response, 'retry-after' ) : 0;
			$delay = $after > 0 ? $after * 1000 : 250 * $attempt;
			if ( $waited + $delay > 3000 ) {
				return $response;
			}
			$waited += $delay;
			/**
			 * Short-circuits the wait between retries (tests).
			 *
			 * @param bool $skip  Skip sleeping.
			 * @param int  $delay Milliseconds.
			 */
			if ( ! apply_filters( 'cliezoo_http_retry_skip_wait', false, $delay ) ) {
				usleep( $delay * 1000 );
			}
		}
	}

	/**
	 * Validates a URL and resolves its host.
	 *
	 * @param string $url         URL.
	 * @param bool   $allow_local Allow private/loopback hosts (explicitly allowlisted local AI).
	 * @return array{0:string,1:int,2:string}|\WP_Error [host, port, ip].
	 */
	public static function check( string $url, bool $allow_local = false ) {
		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'cliezoo_blocked_url', __( 'Only http and https addresses are allowed.', 'cliezoo' ) );
		}
		$local = $allow_local || self::allowlisted( $host );
		if ( 'https' !== $scheme && ! $local ) {
			return new \WP_Error( 'cliezoo_blocked_url', __( 'A secure (https) address is required.', 'cliezoo' ) );
		}
		$port = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
		$ips  = self::resolve( trim( $host, '[]' ) );
		if ( ! $ips ) {
			return new \WP_Error( 'cliezoo_blocked_url', __( 'The address could not be resolved.', 'cliezoo' ) );
		}
		foreach ( $ips as $ip ) {
			if ( ! $local && self::is_private( $ip ) ) {
				return new \WP_Error( 'cliezoo_blocked_url', __( 'Requests to private or internal network addresses are blocked.', 'cliezoo' ) );
			}
		}
		return array( trim( $host, '[]' ), $port, $ips[0] );
	}

	/**
	 * Resolves A and AAAA records (or returns the literal IP).
	 *
	 * @param string $host Host.
	 * @return string[]
	 */
	public static function resolve( string $host ): array {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}
		$ips = gethostbynamel( $host );
		$ips = is_array( $ips ) ? $ips : array();
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failures are handled by the empty result.
			foreach ( is_array( $records ) ? $records : array() as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = (string) $record['ipv6'];
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}

	/**
	 * Whether an IP is private, loopback, link-local, reserved, CGNAT or metadata.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_private( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}
		if ( str_contains( $ip, ':' ) ) {
			$lower = strtolower( $ip );
			// Unique local (fc00::/7), link-local (fe80::/10), IPv4-mapped and loopback.
			return str_starts_with( $lower, 'fc' ) || str_starts_with( $lower, 'fd' ) || str_starts_with( $lower, 'fe8' ) || str_starts_with( $lower, 'fe9' ) || str_starts_with( $lower, 'fea' ) || str_starts_with( $lower, 'feb' ) || str_starts_with( $lower, '::ffff:' ) || '::1' === $lower;
		}
		$long = ip2long( $ip );
		// 100.64.0.0/10 (CGNAT) and 169.254.0.0/16 (link-local incl. cloud metadata).
		return ( $long >= ip2long( '100.64.0.0' ) && $long <= ip2long( '100.127.255.255' ) ) || ( $long >= ip2long( '169.254.0.0' ) && $long <= ip2long( '169.254.255.255' ) );
	}

	/**
	 * Hosts the admin explicitly allowed for plain http / private addresses (e.g. a local model).
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function allowlisted( string $host ): bool {
		$list = get_option( 'cliezoo_http_allowlist', array() );
		/**
		 * Hosts allowed to be local/private (e.g. localhost for Ollama).
		 *
		 * @param string[] $list Hosts.
		 */
		$list = (array) apply_filters( 'cliezoo_http_allowlist', is_array( $list ) ? $list : array() );
		return in_array( strtolower( $host ), array_map( 'strtolower', $list ), true );
	}
}
