<?php
/**
 * What happens to an email that arrives.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Mail;

use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one door every received email goes through, whichever connector
 * fetched it (IMAP polling or a mail provider's webhook).
 * In order:
 *
 * 1. Loops and bounces are dropped: anything an autoresponder, a list or a
 *    mail server sent (Auto-Submitted, X-Autoreply, Precedence, an empty
 *    return path, X-Failed-Recipients) — answering one starts a loop.
 * 2. Spam is dropped on the verdict the receiving server already gave
 *    (X-Spam-Flag, X-Spam-Status, the common providers' own headers), and a
 *    message whose From failed DMARC is refused: its sender is not who it
 *    says, and every check after this one trusts the sender.
 * 3. The handlers are asked in turn (`cliezoo_inbound_handlers`); the
 *    first that takes it decides. Each handler checks its own allowlist —
 *    a reply only from the person it was sent to, an intake address only
 *    from the senders its owner allowed (sender_allowed()).
 *
 * Every message is written down with what happened and why, so "my email
 * never arrived" has an answer.
 */
final class Inbound {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Takes in one raw message.
	 *
	 * @param string $raw    The message (RFC 5322).
	 * @param string $source Which connector brought it.
	 * @return array{status: string, reason: string, handler: string}
	 */
	public function receive( string $raw, string $source = '' ): array {
		$message = MimeMessage::parse( $raw );
		if ( ! $message ) {
			return $this->log( null, 'rejected', 'unreadable', '', $source );
		}
		$why = self::automatic( $message );
		if ( '' !== $why ) {
			return $this->log( $message, 'ignored', $why, '', $source );
		}
		$why = self::spam( $message );
		if ( '' !== $why ) {
			return $this->log( $message, 'rejected', $why, '', $source );
		}
		if ( '' === $message->from() ) {
			return $this->log( $message, 'rejected', 'no-sender', '', $source );
		}
		/**
		 * The handlers of received email, in the order they are asked. Each
		 * is fn( MimeMessage $message ): ?array — null to pass, or
		 * ['status' => accepted|rejected, 'reason' => …, 'object_type' => …,
		 * 'object_id' => …] to decide.
		 *
		 * @param array<string, callable> $handlers Name => handler.
		 */
		$handlers = (array) apply_filters( 'cliezoo_inbound_handlers', array() );
		foreach ( $handlers as $name => $handler ) {
			if ( ! is_callable( $handler ) ) {
				continue;
			}
			$said = $handler( $message );
			if ( is_array( $said ) ) {
				return $this->log( $message, (string) ( $said['status'] ?? 'accepted' ), (string) ( $said['reason'] ?? '' ), (string) $name, $source, $said );
			}
		}
		return $this->log( $message, 'ignored', 'nobody-takes-it', '', $source );
	}

	/**
	 * Why a message was sent by software rather than a person, or ''.
	 *
	 * @param MimeMessage $message Message.
	 * @return string
	 */
	public static function automatic( MimeMessage $message ): string {
		$auto = strtolower( $message->header( 'auto-submitted' ) );
		if ( '' !== $auto && 'no' !== $auto ) {
			return 'auto-submitted';
		}
		foreach ( array( 'x-autoreply', 'x-autorespond', 'x-auto-response-suppress', 'x-failed-recipients' ) as $name ) {
			if ( '' !== $message->header( $name ) && ! ( 'x-auto-response-suppress' === $name && 'none' === strtolower( $message->header( $name ) ) ) ) {
				return 'x-failed-recipients' === $name ? 'bounce' : 'auto-reply';
			}
		}
		if ( in_array( strtolower( $message->header( 'precedence' ) ), array( 'bulk', 'junk', 'list', 'auto_reply' ), true ) ) {
			return 'bulk';
		}
		if ( '<>' === trim( $message->header( 'return-path' ) ) || 1 === preg_match( '/^(mailer-daemon|postmaster)@/i', $message->from() ) ) {
			return 'bounce';
		}
		if ( '' !== $message->header( 'list-id' ) || '' !== $message->header( 'list-unsubscribe' ) ) {
			return 'list';
		}
		return '';
	}

	/**
	 * Why the receiving server thought it was spam, or ''.
	 *
	 * @param MimeMessage $message Message.
	 * @return string
	 */
	public static function spam( MimeMessage $message ): string {
		if ( 'yes' === strtolower( trim( $message->header( 'x-spam-flag' ) ) ) || 1 === preg_match( '/^\s*yes\b/i', $message->header( 'x-spam-status' ) ) ) {
			return 'spam';
		}
		foreach ( array(
			'x-mailgun-sflag'     => '/^yes/i',
			'x-ses-spam-verdict'  => '/^fail/i',
			'x-forwardemail-spam' => '/^true/i',
		) as $name => $pattern ) {
			if ( 1 === preg_match( $pattern, trim( $message->header( $name ) ) ) ) {
				return 'spam';
			}
		}
		foreach ( (array) ( $message->headers['authentication-results'] ?? array() ) as $result ) {
			if ( 1 === preg_match( '/\bdmarc=fail\b/i', $result ) ) {
				return 'dmarc-fail';
			}
		}
		return '';
	}

	/**
	 * Whether a sender is on an allowlist of addresses and domains
	 * ("ada@studio.test", "studio.test", "@studio.test", "*.studio.test").
	 * An empty list allows nobody.
	 *
	 * @param string             $sender Address.
	 * @param array<int, string> $allow  The list.
	 * @return bool
	 */
	public static function sender_allowed( string $sender, array $allow ): bool {
		$sender = strtolower( trim( $sender ) );
		$domain = (string) substr( (string) strrchr( $sender, '@' ), 1 );
		if ( '' === $domain ) {
			return false;
		}
		foreach ( $allow as $entry ) {
			$entry = strtolower( trim( (string) $entry ) );
			if ( '' === $entry ) {
				continue;
			}
			if ( str_contains( $entry, '@' ) && ! str_starts_with( $entry, '@' ) ) {
				if ( $entry === $sender ) {
					return true;
				}
				continue;
			}
			$entry = ltrim( $entry, '@' );
			if ( str_starts_with( $entry, '*.' ) ) {
				if ( str_ends_with( $domain, substr( $entry, 1 ) ) ) {
					return true;
				}
				continue;
			}
			if ( $entry === $domain ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Writes down what happened.
	 *
	 * @param MimeMessage|null     $message Message.
	 * @param string               $status  accepted, ignored or rejected.
	 * @param string               $reason  Why.
	 * @param string               $handler Which handler decided.
	 * @param string               $source  Connector.
	 * @param array<string, mixed> $said    What the handler said.
	 * @return array{status: string, reason: string, handler: string}
	 */
	private function log( ?MimeMessage $message, string $status, string $reason, string $handler, string $source, array $said = array() ): array {
		$status = in_array( $status, array( 'accepted', 'ignored', 'rejected' ), true ) ? $status : 'rejected';
		$this->plugin->service( 'email_inbound' )->repository()->insert(
			array(
				'from_email'  => $message ? mb_substr( $message->from(), 0, 190 ) : '',
				'subject'     => $message ? mb_substr( $message->header( 'subject' ), 0, 255 ) : '',
				'message_id'  => $message ? mb_substr( trim( $message->header( 'message-id' ), '<> ' ), 0, 255 ) : '',
				'status'      => $status,
				'reason'      => mb_substr( $reason, 0, 60 ),
				'handler'     => mb_substr( $handler, 0, 40 ),
				'source'      => mb_substr( $source, 0, 40 ),
				'object_type' => isset( $said['object_type'] ) ? (string) $said['object_type'] : null,
				'object_id'   => isset( $said['object_id'] ) ? (int) $said['object_id'] : null,
			)
		);
		return array(
			'status'  => $status,
			'reason'  => $reason,
			'handler' => $handler,
		);
	}
}
