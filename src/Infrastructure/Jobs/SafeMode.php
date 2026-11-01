<?php
/**
 * Safe mode: pause outbound work on copied or moved sites.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Jobs;

use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When the site address changes (a staging copy, a clone, a migration),
 * Cliezoo turns Safe mode on by itself: emails are held, webhooks,
 * automations, AI runs, payment calls and recurring invoices pause, so a
 * copy never emails real clients or charges real cards (Blueprint §31.4).
 * An admin then chooses "this is the live site now" (the address is
 * accepted; emails held meanwhile are discarded as stale) or keeps the copy
 * in Safe mode.
 */
final class SafeMode {

	public const OPTION = 'cliezoo_safe_mode';
	public const REASON = 'cliezoo_safe_mode_reason';
	public const SITE   = 'cliezoo_site_url';
	public const MANUAL = 'manual';
	public const MOVED  = 'site_moved';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_init', array( $this, 'check' ) );
		add_action( 'cliezoo_every_day', array( $this, 'check' ) );
	}

	/**
	 * Hook callback for detect().
	 *
	 * @return void
	 */
	public function check(): void {
		$this->detect();
	}

	/**
	 * The site address in a comparable form (scheme ignored).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalise( string $url ): string {
		$parts = wp_parse_url( $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$host  = str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		$port  = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $host . $port . untrailingslashit( (string) ( $parts['path'] ?? '' ) );
	}

	/**
	 * Turns Safe mode on when the address differs from the recorded one.
	 *
	 * @return bool Whether a move was detected now.
	 */
	public function detect(): bool {
		$known = (string) get_option( self::SITE, '' );
		if ( '' === $known ) {
			update_option( self::SITE, home_url(), false );
			return false;
		}
		if ( self::normalise( $known ) === self::normalise( home_url() ) || $this->active() ) {
			return false;
		}
		$this->enable(
			self::MOVED,
			array(
				'from' => $known,
				'to'   => home_url(),
			)
		);
		return true;
	}

	/**
	 * Whether Safe mode is on.
	 *
	 * @return bool
	 */
	public function active(): bool {
		return (bool) get_option( self::OPTION, false );
	}

	/**
	 * Status for Setup Health and the app banner.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$reason = get_option( self::REASON, array() );
		$reason = is_array( $reason ) ? $reason : array();
		return array(
			'active'   => $this->active(),
			'reason'   => (string) ( $reason['reason'] ?? '' ),
			'from'     => (string) ( $reason['from'] ?? '' ),
			'to'       => (string) ( $reason['to'] ?? '' ),
			'since'    => (string) ( $reason['since'] ?? '' ),
			'site_url' => home_url(),
		);
	}

	/**
	 * Turns Safe mode on.
	 *
	 * @param string               $reason  Reason (manual|site_moved).
	 * @param array<string, mixed> $context Details.
	 * @return void
	 */
	public function enable( string $reason, array $context = array() ): void {
		update_option( self::OPTION, true, true );
		update_option(
			self::REASON,
			array_merge(
				$context,
				array(
					'reason' => $reason,
					'since'  => gmdate( 'c' ),
				)
			),
			false
		);
		do_action( 'cliezoo_audit', 'safe_mode_on', array_merge( $context, array( 'reason' => $reason ) ) );
		/**
		 * Safe mode was turned on.
		 *
		 * @param string $reason Reason.
		 */
		do_action( 'cliezoo_safe_mode_enabled', $reason );
	}

	/**
	 * Turns Safe mode off. The current address becomes the known one.
	 *
	 * @param Actor $actor Actor.
	 * @return void
	 */
	public function disable( Actor $actor ): void {
		update_option( self::SITE, home_url(), false );
		update_option( self::OPTION, false, true );
		delete_option( self::REASON );
		do_action(
			'cliezoo_audit',
			'safe_mode_off',
			array(
				'site'   => home_url(),
				'member' => $actor->member_id,
			)
		);
		/**
		 * Safe mode was turned off (held emails are discarded as stale).
		 */
		do_action( 'cliezoo_safe_mode_released' );
	}
}
