<?php
/**
 * Who has asked not to get marketing email.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Mail;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Crypto\Vault;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * A list of addresses, not a flag on a record: somebody who unsubscribed
 * stays unsubscribed whichever contact, client or lead their address is on,
 * and however many there are.
 *
 * Checked when each email is sent, not when it is queued, so an
 * unsubscribe that lands between the two still counts. Only marketing is
 * stopped (category `marketing`): an invoice, a document to sign, a
 * receipt or a password reset is something the person is owed, and goes.
 *
 * Every marketing email carries a signed unsubscribe address: in the
 * List-Unsubscribe header with one-click unsubscribe (RFC 8058), and as a
 * line at its foot that opens a page saying it is done.
 */
final class MarketingConsent {

	/**
	 * The category this governs.
	 */
	public const CATEGORY = 'marketing';

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
	 * Hooks it into sending.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'cliezoo_email_allowed', array( $this, 'allowed' ), 5, 3 );
		add_filter( 'cliezoo_email_headers', array( $this, 'headers' ), 10, 3 );
		add_filter( 'cliezoo_email_html', array( $this, 'footer' ), 10, 3 );
	}

	/**
	 * Whether an address unsubscribed.
	 *
	 * @param string $email Address.
	 * @return array<string, mixed>|null The record of it, or null.
	 */
	public function unsubscribed( string $email ): ?array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return null;
		}
		// The latest entry decides: the list keeps its history, so an address
		// that came back and left again has both on record.
		$rows = $this->plugin->service( 'email_unsubscribes' )->repository()->where( array( 'email' => $email ), '-id', 1 );
		$row  = $rows[0] ?? null;
		return is_array( $row ) && empty( $row['resubscribed_at'] ) ? $row : null;
	}

	/**
	 * `cliezoo_email_allowed`: marketing to an unsubscribed address is not sent.
	 *
	 * @param bool                 $allowed So far.
	 * @param string               $to      Address.
	 * @param array<string, mixed> $meta    Meta.
	 * @return bool
	 */
	public function allowed( $allowed, $to, $meta = array() ): bool {
		if ( ! $allowed ) {
			return false;
		}
		if ( self::CATEGORY !== (string) ( $meta['category'] ?? '' ) ) {
			return true;
		}
		return null === $this->unsubscribed( (string) $to );
	}

	/**
	 * `cliezoo_email_headers`: marketing carries its way out.
	 *
	 * @param array<int, string>   $headers Headers.
	 * @param string               $to      Address.
	 * @param array<string, mixed> $meta    Meta.
	 * @return array<int, string>
	 */
	public function headers( $headers, $to, $meta = array() ): array {
		$headers = (array) $headers;
		if ( self::CATEGORY !== (string) ( $meta['category'] ?? '' ) ) {
			return $headers;
		}
		$headers[] = 'List-Unsubscribe: <' . $this->one_click_url( (string) $to ) . '>';
		$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		return $headers;
	}

	/**
	 * `cliezoo_email_html`: marketing ends with a way out a person can click.
	 *
	 * @param string               $html Body.
	 * @param string               $to   Address.
	 * @param array<string, mixed> $meta Meta.
	 * @return string
	 */
	public function footer( $html, $to, $meta = array() ): string {
		$html = (string) $html;
		if ( self::CATEGORY !== (string) ( $meta['category'] ?? '' ) ) {
			return $html;
		}
		$line = '<p style="margin:16px 0 0;font-size:12px;color:#64748B;text-align:center;">'
			. esc_html__( 'You are getting this because you are on our list.', 'cliezoo' ) . ' '
			. '<a href="' . esc_url( $this->page_url( (string) $to ) ) . '" style="color:#64748B;">' . esc_html__( 'Unsubscribe', 'cliezoo' ) . '</a></p>';
		$at   = strripos( $html, '</body>' );
		return false === $at ? $html . $line : substr( $html, 0, $at ) . $line . substr( $html, $at );
	}

	/**
	 * The signature of an address.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	private function sign( string $email ): string {
		return $this->plugin->get( Vault::class )->sign( 'unsubscribe|' . strtolower( trim( $email ) ) );
	}

	/**
	 * The address mail clients POST to for one-click unsubscribe.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	public function one_click_url( string $email ): string {
		return add_query_arg(
			array(
				'e' => rawurlencode( strtolower( trim( $email ) ) ),
				's' => $this->sign( $email ),
			),
			rest_url( 'cliezoo/v1/public/unsubscribe' )
		);
	}

	/**
	 * The page a person opens from the email's foot.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	public function page_url( string $email ): string {
		return add_query_arg(
			array(
				'e' => rawurlencode( strtolower( trim( $email ) ) ),
				's' => $this->sign( $email ),
			),
			home_url( '/cz/unsubscribe/' )
		);
	}

	/**
	 * Unsubscribes the address a signed link names.
	 *
	 * @param string $email     Address, from the link.
	 * @param string $signature Signature, from the link.
	 * @param string $how       link or one-click.
	 * @return array<string, mixed>
	 * @throws ActionError When the link was not one this site made.
	 */
	public function from_link( string $email, string $signature, string $how = 'link' ): array {
		$email = strtolower( trim( rawurldecode( $email ) ) );
		if ( ! is_email( $email ) || ! $this->plugin->get( Vault::class )->verify( 'unsubscribe|' . $email, $signature ) ) {
			throw new ActionError( 'cliezoo_link_unavailable', __( 'This link is not available. It may have been copied wrongly.', 'cliezoo' ), 404 );
		}
		$this->record( $email, 'one-click' === $how ? 'one-click' : 'link' );
		return array(
			'unsubscribed' => true,
			'email'        => $email,
		);
	}

	/**
	 * Unsubscribes an address because they asked a person (by phone, by
	 * letter): written down by somebody on the team.
	 *
	 * @param Actor  $actor Who wrote it down.
	 * @param string $email Address.
	 * @return array<string, mixed>
	 * @throws ActionError When it is not an address.
	 */
	public function by_staff( Actor $actor, string $email ): array {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			throw ActionError::validation( __( 'That is not an email address.', 'cliezoo' ) );
		}
		$this->record( $email, 'staff', $actor );
		return array(
			'unsubscribed' => true,
			'email'        => $email,
		);
	}

	/**
	 * Takes an address off the list, because they asked to be back on it.
	 * The entry stays, with when, who and how they asked, because consent is
	 * a thing one may be asked to show.
	 *
	 * @param Actor  $actor  Who.
	 * @param string $email  Address.
	 * @param string $reason How they asked.
	 * @return array<string, mixed>
	 * @throws ActionError When no reason is given or the address is not listed.
	 */
	public function resubscribe( Actor $actor, string $email, string $reason ): array {
		$reason = trim( sanitize_text_field( $reason ) );
		if ( '' === $reason ) {
			throw ActionError::validation( __( 'Say how they asked to be back on the list: consent is something you may be asked to show.', 'cliezoo' ) );
		}
		$row = $this->unsubscribed( $email );
		if ( ! $row ) {
			throw ActionError::not_found();
		}
		// Kept, not deleted: when they left and when and why they came back
		// are both part of the record of their consent.
		$this->plugin->service( 'email_unsubscribes' )->repository()->update(
			(int) $row['id'],
			array(
				'resubscribed_at'    => gmdate( 'Y-m-d H:i:s' ),
				'resubscribed_by'    => $actor->member_id > 0 ? $actor->member_id : null,
				'resubscribe_reason' => mb_substr( $reason, 0, 255 ),
			)
		);
		/**
		 * An address asked to get marketing email again.
		 *
		 * @param string $email  Address.
		 * @param string $reason How they asked.
		 * @param Actor  $actor  Who wrote it down.
		 */
		do_action( 'cliezoo_marketing_resubscribed', strtolower( trim( $email ) ), $reason, $actor );
		return array( 'unsubscribed' => false );
	}

	/**
	 * Writes the address down, once.
	 *
	 * @param string     $email  Address (lower case).
	 * @param string     $source link, one-click or staff.
	 * @param Actor|null $actor  Who, when a person.
	 * @return void
	 */
	private function record( string $email, string $source, ?Actor $actor = null ): void {
		if ( $this->unsubscribed( $email ) ) {
			return;
		}
		$this->plugin->service( 'email_unsubscribes' )->repository()->insert(
			array(
				'email'  => $email,
				'source' => $source,
			),
			$actor && $actor->member_id ? $actor->member_id : null
		);
		/**
		 * An address asked not to get marketing email.
		 *
		 * @param string $email  Address.
		 * @param string $source link, one-click or staff.
		 */
		do_action( 'cliezoo_marketing_unsubscribed', $email, $source );
	}
}
