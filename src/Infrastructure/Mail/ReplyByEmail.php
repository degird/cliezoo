<?php
/**
 * Replies to notification emails, back into their thread.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Mail;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Crypto\Vault;
use Cliezoo\Platform\Members\Members;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A notification email goes out with a Message-ID that names it and is
 * signed, so a reply to it (In-Reply-To / References) can be traced back
 * to the record it was about. The reply becomes a comment there — on a
 * task, a file, a project, or in a conversation, whose messages are its
 * comments — written as the person, with exactly what their role allows.
 *
 * Its allowlist is one address: whoever the notification was sent to. A
 * forwarded notification answered by somebody else is refused, because the
 * comment would be written in the recipient's name. The quoted email under
 * the reply is cut off, so the thread reads as a conversation.
 */
final class ReplyByEmail {

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
	 * Hooks it in.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter(
			'cliezoo_inbound_handlers',
			function ( array $handlers ): array {
				$handlers['reply'] = array( $this, 'handle' );
				return $handlers;
			}
		);
	}

	/**
	 * The Message-ID of a notification's email.
	 *
	 * @param string $uid The notification.
	 * @return string
	 */
	public function message_id( string $uid ): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return 'cz.n.' . $uid . '.' . $this->signature( $uid ) . '@' . ( '' !== $host ? $host : 'localhost' );
	}

	/**
	 * A short signature of a notification uid.
	 *
	 * @param string $uid Uid.
	 * @return string
	 */
	private function signature( string $uid ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9]/', '', $this->plugin->get( Vault::class )->sign( 'reply|' . $uid ) ), 0, 20 );
	}

	/**
	 * Takes a reply to one of our notifications, or passes.
	 *
	 * @param MimeMessage $message The email.
	 * @return array<string, mixed>|null
	 */
	public function handle( MimeMessage $message ): ?array {
		$notification = null;
		foreach ( $message->answers() as $id ) {
			if ( 1 === preg_match( '/^cz\.n\.([0-9A-HJKMNP-TV-Z]{26})\.([A-Za-z0-9]{20})@/', $id, $m ) && hash_equals( $this->signature( $m[1] ), $m[2] ) ) {
				$notification = $this->plugin->service( 'notifications' )->repository()->first( array( 'uid' => $m[1] ) );
				break;
			}
		}
		if ( ! is_array( $notification ) ) {
			return null;
		}
		if ( ! $this->plugin->settings()->get( 'email', 'reply_by_email', true ) ) {
			return $this->refused( 'replies-off' );
		}
		$member = $this->plugin->service( 'members' )->repository()->find( (int) $notification['member_id'] );
		if ( ! is_array( $member ) || strtolower( (string) $member['email'] ) !== $message->from() ) {
			return $this->refused( 'not-the-recipient' );
		}
		$type = (string) ( $notification['object_type'] ?? '' );
		$id   = (int) ( $notification['object_id'] ?? 0 );
		if ( '' === $type || $id <= 0 || ! $this->plugin->entities()->has( $type ) ) {
			return $this->refused( 'no-thread' );
		}
		$record = $this->plugin->service( $type )->repository()->find( $id );
		$text   = self::reply_text( $message->text );
		if ( ! is_array( $record ) ) {
			return $this->refused( 'no-thread' );
		}
		if ( '' === $text ) {
			return $this->refused( 'empty' );
		}
		$actor = $this->plugin->get( Members::class )->actor_for( (int) $member['user_id'] );
		try {
			// The thread must be one they may still read, checked as the
			// REST route checks it: a notification sent last month is not
			// access granted today.
			$this->plugin->service( $type )->load( $actor, (string) $record['uid'], \Cliezoo\Platform\Permissions\Policy::VIEW );
			$this->plugin->service( 'comments' )->create(
				$actor,
				array(
					'object_type' => $type,
					'object_id'   => $id,
					'body'        => self::html( $text ),
				)
			);
		} catch ( ActionError $error ) {
			// What refused it, in the log: "not allowed" alone sends an
			// admin looking in the wrong place.
			return $this->refused( 'not-allowed: ' . $error->getMessage() );
		}
		return array(
			'status'      => 'accepted',
			'reason'      => 'reply',
			'object_type' => $type,
			'object_id'   => $id,
		);
	}

	/**
	 * A refusal.
	 *
	 * @param string $why Why.
	 * @return array<string, string>
	 */
	private function refused( string $why ): array {
		return array(
			'status' => 'rejected',
			'reason' => $why,
		);
	}

	/**
	 * What the person wrote, without the email they were answering.
	 *
	 * @param string $text The plain text.
	 * @return string
	 */
	public static function reply_text( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$kept = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$trimmed = trim( $line );
			if ( str_starts_with( $trimmed, '>' )
				|| 1 === preg_match( '/^(On|Le|Am|El|Il|Op)\s.{3,300}(wrote|a écrit|schrieb|escribió|ha scritto|schreef)\s?:$/iu', $trimmed )
				|| 1 === preg_match( '/^-{2,}\s*(Original Message|Forwarded message)/i', $trimmed )
				|| 1 === preg_match( '/^_{10,}$/', $trimmed )
				|| ( 1 === preg_match( '/^From:\s.+/i', $trimmed ) && $kept )
				|| '--' === rtrim( $line ) ) {
				break;
			}
			$kept[] = $line;
		}
		return mb_substr( trim( implode( "\n", $kept ) ), 0, 20000 );
	}

	/**
	 * Plain text as a comment's HTML: paragraphs and line breaks, nothing
	 * else.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function html( string $text ): string {
		$out = '';
		foreach ( (array) preg_split( "/\n{2,}/", $text ) as $paragraph ) {
			$out .= '<p>' . nl2br( esc_html( trim( $paragraph ) ), false ) . '</p>';
		}
		return $out;
	}
}
