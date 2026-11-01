<?php
/**
 * Whether the sending address's domain is set up for email to arrive.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Mail;

use Cliezoo\Core\Actions\ActionError;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Reads the domain's own DNS — SPF, DMARC, and DKIM at a selector the
 * studio names — and says in plain words what is missing. Only when an
 * admin asks: it is a DNS lookup through this server's own resolver, and
 * nothing is sent anywhere.
 *
 * It does not claim to prove delivery: SPF's includes and DKIM's keys are
 * the mail provider's to evaluate. It catches the common, costly mistakes —
 * no SPF, two SPF records, "+all", no DMARC, a DKIM selector with no key.
 */
final class EmailDomain {

	/**
	 * Reads one name's TXT records; replaceable in tests.
	 *
	 * @var callable(string): array<int, string>
	 */
	private $txt;

	/**
	 * Constructor.
	 *
	 * @param callable|null $txt fn( string $name ): string[] — TXT records.
	 */
	public function __construct( ?callable $txt = null ) {
		$this->txt = $txt ?? static function ( string $name ): array {
			if ( ! function_exists( 'dns_get_record' ) ) {
				return array();
			}
			$out = array();
			foreach ( (array) @dns_get_record( $name, DNS_TXT ) as $record ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A name with no records is an answer.
				$out[] = isset( $record['entries'] ) ? implode( '', (array) $record['entries'] ) : (string) ( $record['txt'] ?? '' );
			}
			return $out;
		};
	}

	/**
	 * Checks the domain of an address.
	 *
	 * @param string $from     The sending address.
	 * @param string $selector A DKIM selector, if the provider gave one.
	 * @return array<string, mixed>
	 * @throws ActionError When there is no address to check.
	 */
	public function check( string $from, string $selector = '' ): array {
		$from = strtolower( trim( $from ) );
		if ( ! is_email( $from ) ) {
			throw ActionError::validation( __( 'Set the address email is sent from first: its domain is what gets checked.', 'cliezoo' ) );
		}
		$domain   = (string) substr( strrchr( $from, '@' ), 1 );
		$selector = strtolower( trim( $selector ) );
		if ( '' !== $selector && 1 !== preg_match( '/^[a-z0-9]([a-z0-9._-]{0,62})$/', $selector ) ) {
			throw ActionError::validation( __( 'A DKIM selector is letters, numbers, dots and dashes — for example "google" or "s1".', 'cliezoo' ) );
		}
		$checks = array(
			'spf'   => $this->spf( $domain ),
			'dmarc' => $this->dmarc( $domain ),
			'dkim'  => '' === $selector ? array(
				'state' => 'unknown',
				'say'   => __( 'Give the DKIM selector your mail provider uses to check it (they list it with the DKIM record they asked you to add).', 'cliezoo' ),
			) : $this->dkim( $domain, $selector ),
		);
		$worst  = 'ok';
		foreach ( $checks as $one ) {
			if ( 'problem' === $one['state'] ) {
				$worst = 'problem';
			} elseif ( 'ok' === $worst && 'ok' !== $one['state'] ) {
				$worst = 'warning';
			}
		}
		return array(
			'domain'     => $domain,
			'state'      => $worst,
			'checks'     => $checks,
			'checked_at' => gmdate( 'c' ),
		);
	}

	/**
	 * SPF: exactly one record, and not one that lets anybody send.
	 *
	 * @param string $domain Domain.
	 * @return array<string, string>
	 */
	private function spf( string $domain ): array {
		$records = array_values( array_filter( ( $this->txt )( $domain ), static fn( string $r ): bool => 1 === preg_match( '/^v=spf1(\s|$)/i', trim( $r ) ) ) );
		if ( ! $records ) {
			return array(
				'state' => 'problem',
				'say'   => __( 'No SPF record. Receiving servers cannot tell this domain\'s real mail from forgeries, and many put it in spam. Add the TXT record your mail provider gives you.', 'cliezoo' ),
			);
		}
		if ( count( $records ) > 1 ) {
			return array(
				'state'  => 'problem',
				'record' => implode( ' | ', $records ),
				'say'    => __( 'More than one SPF record. That makes SPF fail everywhere: merge them into one.', 'cliezoo' ),
			);
		}
		$record = trim( $records[0] );
		if ( 1 === preg_match( '/(^|\s)\+?all(\s|$)/i', $record ) ) {
			return array(
				'state'  => 'problem',
				'record' => $record,
				'say'    => __( 'The SPF record ends in "+all", which says anybody may send as this domain. Use "~all" or "-all".', 'cliezoo' ),
			);
		}
		if ( 1 === preg_match( '/(^|\s)\?all(\s|$)/i', $record ) || 1 !== preg_match( '/(^|\s)[~-]all(\s|$)/i', $record ) ) {
			return array(
				'state'  => 'warning',
				'record' => $record,
				'say'    => __( 'The SPF record does not say what to do with other senders. End it with "~all" or "-all".', 'cliezoo' ),
			);
		}
		return array(
			'state'  => 'ok',
			'record' => $record,
			'say'    => __( 'SPF is set. Make sure it includes the service this site sends through.', 'cliezoo' ),
		);
	}

	/**
	 * DMARC: present, and how strict.
	 *
	 * @param string $domain Domain.
	 * @return array<string, string>
	 */
	private function dmarc( string $domain ): array {
		$records = array_values( array_filter( ( $this->txt )( '_dmarc.' . $domain ), static fn( string $r ): bool => 1 === preg_match( '/^v=DMARC1(\s*;|$)/i', trim( $r ) ) ) );
		if ( ! $records ) {
			return array(
				'state' => 'problem',
				'say'   => __( 'No DMARC record. Gmail and Yahoo require one from anybody who sends much mail. Start with "v=DMARC1; p=none" at _dmarc.', 'cliezoo' ) . $domain,
			);
		}
		$record = trim( $records[0] );
		$policy = preg_match( '/(^|;)\s*p\s*=\s*(none|quarantine|reject)/i', $record, $m ) ? strtolower( $m[2] ) : '';
		if ( '' === $policy ) {
			return array(
				'state'  => 'problem',
				'record' => $record,
				'say'    => __( 'The DMARC record has no policy (p=). Add p=none to start.', 'cliezoo' ),
			);
		}
		return array(
			'state'  => 'none' === $policy ? 'warning' : 'ok',
			'record' => $record,
			'say'    => 'none' === $policy
				? __( 'DMARC is set to watch only (p=none). Once reports show your mail passing, move to quarantine.', 'cliezoo' )
				/* translators: %s: quarantine or reject. */
				: sprintf( __( 'DMARC is set, with policy %s.', 'cliezoo' ), $policy ),
		);
	}

	/**
	 * DKIM: a key at the selector.
	 *
	 * @param string $domain   Domain.
	 * @param string $selector Selector.
	 * @return array<string, string>
	 */
	private function dkim( string $domain, string $selector ): array {
		$records = array_values( array_filter( ( $this->txt )( $selector . '._domainkey.' . $domain ), static fn( string $r ): bool => 1 === preg_match( '/(^|;)\s*p\s*=\s*[A-Za-z0-9+\/=]{20,}/', $r ) ) );
		if ( ! $records ) {
			return array(
				'state' => 'problem',
				/* translators: %s: a DNS name. */
				'say'   => sprintf( __( 'No DKIM key at %s. Check the selector, or add the record your provider gave you.', 'cliezoo' ), $selector . '._domainkey.' . $domain ),
			);
		}
		return array(
			'state' => 'ok',
			'say'   => __( 'A DKIM key is published at that selector.', 'cliezoo' ),
		);
	}
}
