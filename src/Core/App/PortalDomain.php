<?php
/**
 * The portal on the studio's own domain (clients.youragency.com).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A studio can put the app on a domain of its own, so that clients open
 * clients.youragency.com rather than a path on the marketing site. The
 * domain is the studio's: they point it here at their DNS, and their host
 * issues the certificate for it. A WordPress plugin cannot obtain one — it
 * has no access to the web server, and asking for the credentials that
 * would give it any is not something a plugin should do — so Cliezoo does
 * the part that is its own: it checks the domain really reaches this site,
 * over HTTPS with a certificate a browser accepts, and refuses to switch
 * to one that does not. Until that check passes nothing moves, so a
 * mistyped domain cannot take the portal off the air.
 *
 * The check is a token this site will say aloud at any host it answers on:
 * fetching it at the new domain and getting the same token back proves the
 * domain resolves here and that TLS works. The token identifies nothing
 * and grants nothing; it is rotated whenever the domain is set.
 */
final class PortalDomain {

	/**
	 * Where the domain, its token and its last check are kept.
	 */
	public const OPTION = 'cliezoo_portal_domain';

	/**
	 * The query that asks a host to say its token.
	 */
	public const CHECK = 'cliezoo-domain-check';

	/**
	 * How long to wait for the domain to answer.
	 */
	private const TIMEOUT = 10;

	/**
	 * Everything kept about it.
	 *
	 * @return array{host: string, token: string, verified: bool, checked_at: string, problem: string}
	 */
	public static function all(): array {
		$kept = get_option( self::OPTION, array() );
		$kept = is_array( $kept ) ? $kept : array();
		return array(
			'host'       => (string) ( $kept['host'] ?? '' ),
			'token'      => (string) ( $kept['token'] ?? '' ),
			'verified'   => ! empty( $kept['verified'] ),
			'checked_at' => (string) ( $kept['checked_at'] ?? '' ),
			'problem'    => (string) ( $kept['problem'] ?? '' ),
		);
	}

	/**
	 * The domain the portal is served on, or '' while there is none that
	 * has been checked. A domain that has not passed the check is not used
	 * for anything: a link built on it would not open.
	 *
	 * @return string
	 */
	public static function host(): string {
		$all = self::all();
		return $all['verified'] ? $all['host'] : '';
	}

	/**
	 * Whether this request arrived on the portal domain.
	 *
	 * @return bool
	 */
	public static function on_it(): bool {
		$host = self::host();
		if ( '' === $host ) {
			return false;
		}
		$asked = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		return $host === preg_replace( '/:\d+$/', '', $asked );
	}

	/**
	 * An address on the portal domain.
	 *
	 * @param string $path Path inside the app.
	 * @return string
	 */
	public static function url( string $path = '' ): string {
		return 'https://' . self::host() . '/' . ltrim( $path, '/' );
	}

	/**
	 * Why a domain cannot be used (empty when it can).
	 *
	 * @param string $host The domain.
	 * @return string[]
	 */
	public static function problems( string $host ): array {
		$problems = array();
		$host     = self::tidy( $host );
		if ( '' === $host ) {
			return $problems;
		}
		if ( ! preg_match( '/^(?!-)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.(?!-)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $host ) ) {
			$problems[] = __( 'Use a domain name on its own, e.g. clients.youragency.com — no https://, no path.', 'cliezoo' );
			return $problems;
		}
		$own = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $host === $own ) {
			$problems[] = __( 'That is already this site&#8217;s own address.', 'cliezoo' );
		}
		return $problems;
	}

	/**
	 * A domain as it is kept: lower case, no scheme, no path, no port.
	 *
	 * @param string $host What was typed.
	 * @return string
	 */
	public static function tidy( string $host ): string {
		$host = strtolower( trim( $host ) );
		$host = (string) preg_replace( '#^[a-z]+://#', '', $host );
		$host = (string) preg_replace( '#[/?].*$#', '', $host );
		$host = (string) preg_replace( '/:\d+$/', '', $host );
		return trim( $host, '.' );
	}

	/**
	 * Sets the domain, and forgets whatever was checked about the last one.
	 *
	 * Setting it does not switch the portal over: the check does. Clearing
	 * it ('') takes the portal back to this site at once.
	 *
	 * @param string $host The domain, or '' for none.
	 * @return string[] Problems (empty on success).
	 */
	public static function set( string $host ): array {
		$host     = self::tidy( $host );
		$problems = self::problems( $host );
		if ( $problems ) {
			return $problems;
		}
		if ( '' === $host ) {
			delete_option( self::OPTION );
			return array();
		}
		update_option(
			self::OPTION,
			array(
				'host'       => $host,
				'token'      => bin2hex( random_bytes( 16 ) ),
				'verified'   => false,
				'checked_at' => '',
				'problem'    => '',
			),
			true
		);
		return array();
	}

	/**
	 * Asks the domain whether it reaches this site over HTTPS.
	 *
	 * @return array{verified: bool, problem: string}
	 */
	public static function verify(): array {
		$all = self::all();
		if ( '' === $all['host'] || '' === $all['token'] ) {
			return array(
				'verified' => false,
				'problem'  => __( 'There is no domain to check.', 'cliezoo' ),
			);
		}
		$answer  = wp_remote_get(
			'https://' . $all['host'] . '/?' . self::CHECK . '=1',
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array( 'Accept' => 'text/plain' ),
			)
		);
		$problem = '';
		if ( is_wp_error( $answer ) ) {
			$problem = sprintf(
				/* translators: %s: what the request said went wrong. */
				__( 'The domain could not be reached over HTTPS: %s. Point it at this site at your DNS, and have your host issue a certificate for it.', 'cliezoo' ),
				$answer->get_error_message()
			);
		} elseif ( ! hash_equals( $all['token'], trim( (string) wp_remote_retrieve_body( $answer ) ) ) ) {
			$problem = __( 'That domain answers, but it is not this site. Check the DNS record points here, and that no cache or proxy is in the way.', 'cliezoo' );
		}
		$all['verified']   = '' === $problem;
		$all['problem']    = $problem;
		$all['checked_at'] = current_time( 'mysql', true );
		update_option( self::OPTION, $all, true );
		return array(
			'verified' => '' === $problem,
			'problem'  => $problem,
		);
	}

	/**
	 * Says this site's token, at whatever host the request came in on.
	 *
	 * Answered as early as possible and as nothing but the token: it is
	 * read by a machine, and anything a theme might add around it would
	 * only make the comparison fail.
	 *
	 * @return void
	 */
	public static function answer_check(): void {
		if ( ! isset( $_GET[ self::CHECK ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, and the answer is a public identifier.
			return;
		}
		$all = self::all();
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( $all['token'] );
		exit;
	}
}
