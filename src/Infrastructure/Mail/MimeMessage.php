<?php
/**
 * A received email, read from its raw form.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Mail;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses an RFC 5322 message: headers (unfolded, RFC 2047 words decoded),
 * nested multiparts, base64 and quoted-printable, and charsets. Keeps the
 * plain text (or text drawn from the HTML when there is none) and the
 * attachments' names, types and sizes — not their bytes, which nothing
 * here needs. Bounded: message size, part count and nesting depth.
 */
final class MimeMessage {

	/**
	 * The largest message read.
	 */
	public const MOST_BYTES = 10 * 1024 * 1024;

	/**
	 * Most parts, and deepest nesting.
	 */
	private const MOST_PARTS = 100;
	private const DEEPEST    = 8;

	/**
	 * Headers, names lower-cased; repeated headers keep every value.
	 *
	 * @var array<string, array<int, string>>
	 */
	public array $headers = array();

	/**
	 * The plain-text body.
	 *
	 * @var string
	 */
	public string $text = '';

	/**
	 * The HTML body, if any.
	 *
	 * @var string
	 */
	public string $html = '';

	/**
	 * Attachments: name, type, size.
	 *
	 * @var array<int, array{name: string, type: string, size: int}>
	 */
	public array $attachments = array();

	/**
	 * Parts seen.
	 *
	 * @var int
	 */
	private int $parts = 0;

	/**
	 * Reads a raw message.
	 *
	 * @param string $raw The message.
	 * @return self|null Null when it is not a message or too large.
	 */
	public static function parse( string $raw ): ?self {
		if ( '' === trim( $raw ) || strlen( $raw ) > self::MOST_BYTES ) {
			return null;
		}
		$raw                 = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		list( $head, $body ) = self::split( $raw );
		$message             = new self();
		$message->headers    = self::headers( $head );
		if ( ! $message->headers ) {
			return null;
		}
		$message->part( $message->headers, $body, 0 );
		if ( '' === trim( $message->text ) && '' !== $message->html ) {
			$message->text = self::text_of( $message->html );
		}
		return $message;
	}

	/**
	 * A header's first value, decoded ('' when absent).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public function header( string $name ): string {
		return (string) ( $this->headers[ strtolower( $name ) ][0] ?? '' );
	}

	/**
	 * The sender's address (lower case), from From.
	 *
	 * @return string
	 */
	public function from(): string {
		return self::address( $this->header( 'from' ) );
	}

	/**
	 * Every address in To and Cc (and Delivered-To / X-Original-To, where a
	 * forward kept the address it was sent to), lower case.
	 *
	 * @return array<int, string>
	 */
	public function recipients(): array {
		$out = array();
		foreach ( array( 'to', 'cc', 'delivered-to', 'x-original-to' ) as $name ) {
			foreach ( (array) ( $this->headers[ $name ] ?? array() ) as $value ) {
				foreach ( explode( ',', $value ) as $one ) {
					$address = self::address( $one );
					if ( '' !== $address ) {
						$out[ $address ] = true;
					}
				}
			}
		}
		return array_keys( $out );
	}

	/**
	 * The message ids this one answers (In-Reply-To, then References).
	 *
	 * @return array<int, string>
	 */
	public function answers(): array {
		preg_match_all( '/<([^<>\s]{1,250})>/', $this->header( 'in-reply-to' ) . ' ' . $this->header( 'references' ), $m );
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * Header block and body.
	 *
	 * @param string $raw Text with \n line ends.
	 * @return array{0: string, 1: string}
	 */
	private static function split( string $raw ): array {
		$at = strpos( $raw, "\n\n" );
		return false === $at ? array( $raw, '' ) : array( substr( $raw, 0, $at ), substr( $raw, $at + 2 ) );
	}

	/**
	 * Unfolds and decodes a header block.
	 *
	 * @param string $head Header block.
	 * @return array<string, array<int, string>>
	 */
	private static function headers( string $head ): array {
		$head = (string) preg_replace( "/\n[ \t]+/", ' ', $head );
		$out  = array();
		foreach ( explode( "\n", $head ) as $line ) {
			if ( 1 !== preg_match( '/^([!-9;-~]{1,76}):\s?(.*)$/', $line, $m ) ) {
				continue;
			}
			$name = strtolower( $m[1] );
			if ( count( $out[ $name ] ?? array() ) < 50 ) {
				$out[ $name ][] = self::words( trim( $m[2] ) );
			}
		}
		return $out;
	}

	/**
	 * Decodes RFC 2047 encoded words.
	 *
	 * @param string $value Header value.
	 * @return string
	 */
	private static function words( string $value ): string {
		$value = (string) preg_replace( '/\?=\s+=\?/', '?==?', $value );
		return (string) preg_replace_callback(
			'/=\?([^?]{1,40})\?([BbQq])\?([^?]*)\?=/',
			static function ( array $m ): string {
				$bytes = 'b' === strtolower( $m[2] )
					? (string) base64_decode( $m[3] ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- RFC 2047 encoded word.
					: quoted_printable_decode( str_replace( '_', ' ', $m[3] ) );
				return self::to_utf8( $bytes, $m[1] );
			},
			$value
		);
	}

	/**
	 * Reads one part (and its children).
	 *
	 * @param array<string, array<int, string>> $headers Its headers.
	 * @param string                            $body    Its body.
	 * @param int                               $depth   Nesting.
	 * @return void
	 */
	private function part( array $headers, string $body, int $depth ): void {
		if ( ++$this->parts > self::MOST_PARTS || $depth > self::DEEPEST ) {
			return;
		}
		$type     = strtolower( (string) ( $headers['content-type'][0] ?? 'text/plain' ) );
		$mime     = trim( (string) strtok( $type, ';' ) );
		$params   = self::params( (string) ( $headers['content-type'][0] ?? '' ) );
		$encoding = strtolower( trim( (string) ( $headers['content-transfer-encoding'][0] ?? '' ) ) );
		$position = strtolower( (string) ( $headers['content-disposition'][0] ?? '' ) );

		if ( str_starts_with( $mime, 'multipart/' ) && '' !== ( $params['boundary'] ?? '' ) ) {
			$boundary = "\n--" . $params['boundary'];
			$chunks   = explode( $boundary, "\n" . $body );
			array_shift( $chunks );
			foreach ( $chunks as $chunk ) {
				if ( str_starts_with( $chunk, '--' ) ) {
					break;
				}
				list( $head, $inner ) = self::split( ltrim( $chunk, " \t\n" ) );
				$this->part( self::headers( $head ), $inner, $depth + 1 );
			}
			return;
		}

		$bytes = 'base64' === $encoding
			? (string) base64_decode( (string) preg_replace( '/\s+/', '', $body ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A MIME body.
			: ( 'quoted-printable' === $encoding ? quoted_printable_decode( $body ) : $body );

		$disposition = self::params( $position );
		$name        = (string) ( $disposition['filename'] ?? ( $params['name'] ?? '' ) );
		if ( str_starts_with( $position, 'attachment' ) || ( '' !== $name && ! str_starts_with( $mime, 'text/' ) ) ) {
			$this->attachments[] = array(
				'name' => self::words( $name ),
				'type' => $mime,
				'size' => strlen( $bytes ),
			);
			return;
		}
		$text = self::to_utf8( $bytes, (string) ( $params['charset'] ?? 'utf-8' ) );
		if ( 'text/plain' === $mime && '' === $this->text ) {
			$this->text = $text;
		} elseif ( 'text/html' === $mime && '' === $this->html ) {
			$this->html = $text;
		} elseif ( 'message/rfc822' === $mime ) {
			// A forwarded message: its text is part of what was said.
			$inner = self::parse( $bytes );
			if ( $inner && '' === $this->text ) {
				$this->text = $inner->text;
			}
		}
	}

	/**
	 * A header's parameters (boundary, charset, name, filename).
	 *
	 * @param string $value Header value.
	 * @return array<string, string>
	 */
	private static function params( string $value ): array {
		$out = array();
		preg_match_all( '/;\s*([a-z0-9*_-]+)\s*=\s*("([^"]*)"|[^;\s]+)/i', $value, $m, PREG_SET_ORDER );
		foreach ( $m as $one ) {
			$name = strtolower( rtrim( $one[1], '*' ) );
			$val  = '' !== ( $one[3] ?? '' ) ? $one[3] : trim( $one[2], '"' );
			if ( str_ends_with( $one[1], '*' ) && preg_match( "/^([^']*)'[^']*'(.*)$/", $val, $ext ) ) {
				$val = self::to_utf8( rawurldecode( $ext[2] ), '' !== $ext[1] ? $ext[1] : 'utf-8' );
			}
			$out[ $name ] = $val;
		}
		return $out;
	}

	/**
	 * Bytes in a charset as UTF-8 (invalid sequences replaced).
	 *
	 * @param string $bytes   Bytes.
	 * @param string $charset Charset.
	 * @return string
	 */
	private static function to_utf8( string $bytes, string $charset ): string {
		$charset = strtolower( trim( $charset ) );
		if ( '' !== $charset && ! in_array( $charset, array( 'utf-8', 'utf8', 'us-ascii' ), true ) && function_exists( 'mb_convert_encoding' ) ) {
			$converted = @mb_convert_encoding( $bytes, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unknown charset falls back below.
			if ( is_string( $converted ) ) {
				$bytes = $converted;
			}
		}
		return wp_check_invalid_utf8( $bytes, true );
	}

	/**
	 * Plain text from HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function text_of( string $html ): string {
		$html = (string) preg_replace( '#<(script|style|head)[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#<(br|/p|/div|/li|/h[1-6]|/tr)[^>]*>#i', "\n", $html );
		return trim( html_entity_decode( wp_strip_all_tags( $html, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * The address in "Name <address>", lower case, or ''.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function address( string $value ): string {
		$value = preg_match( '/<([^<>]+)>/', $value, $m ) ? $m[1] : $value;
		$value = strtolower( trim( $value, " \t\"'" ) );
		return is_email( $value ) ? $value : '';
	}
}
