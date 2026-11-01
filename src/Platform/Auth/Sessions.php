<?php
/**
 * Active sessions.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Auth;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Session list and revocation on top of WordPress session tokens
 * (Blueprint §8.6). Sessions are identified to the browser by a short hash
 * of the verifier, never by the token itself.
 */
final class Sessions {

	/**
	 * Raw sessions keyed by verifier.
	 *
	 * @param int $user_id User.
	 * @return array<string, array<string, mixed>>
	 */
	private function raw( int $user_id ): array {
		$sessions = get_user_meta( $user_id, 'session_tokens', true );
		return is_array( $sessions ) ? $sessions : array();
	}

	/**
	 * Verifier of the current request's session.
	 *
	 * @return string
	 */
	private function current_verifier(): string {
		$token = wp_get_session_token();
		return '' !== $token ? hash( 'sha256', $token ) : '';
	}

	/**
	 * Lists sessions for display.
	 *
	 * @param int $user_id User.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( int $user_id ): array {
		$current = $this->current_verifier();
		$out     = array();
		foreach ( $this->raw( $user_id ) as $verifier => $session ) {
			if ( (int) ( $session['expiration'] ?? 0 ) < time() ) {
				continue;
			}
			$out[] = array(
				'id'         => substr( hash( 'sha256', (string) $verifier . wp_salt( 'auth' ) ), 0, 24 ),
				'current'    => $verifier === $current,
				'device'     => Request::device( (string) ( $session['ua'] ?? '' ) ),
				'ip'         => (string) ( $session['ip'] ?? '' ),
				'login'      => gmdate( 'c', (int) ( $session['login'] ?? 0 ) ),
				'expiration' => gmdate( 'c', (int) ( $session['expiration'] ?? 0 ) ),
			);
		}
		usort( $out, static fn( array $a, array $b ): int => strcmp( $b['login'], $a['login'] ) );
		return $out;
	}

	/**
	 * Revokes one session by its display id.
	 *
	 * @param int    $user_id User.
	 * @param string $id      Display id.
	 * @return array<int, array<string, mixed>>
	 * @throws ActionError When unknown or the current session.
	 */
	public function revoke( int $user_id, string $id ): array {
		$sessions = $this->raw( $user_id );
		foreach ( array_keys( $sessions ) as $verifier ) {
			if ( hash_equals( substr( hash( 'sha256', (string) $verifier . wp_salt( 'auth' ) ), 0, 24 ), $id ) ) {
				if ( $verifier === $this->current_verifier() ) {
					throw ActionError::conflict( __( 'Use Log out to end this session.', 'cliezoo' ) );
				}
				unset( $sessions[ $verifier ] );
				update_user_meta( $user_id, 'session_tokens', $sessions );
				do_action( 'cliezoo_audit', 'session_revoked' );
				return $this->list( $user_id );
			}
		}
		throw ActionError::not_found();
	}

	/**
	 * Logs out every other session ("log out everywhere").
	 *
	 * @param int $user_id User.
	 * @return array<int, array<string, mixed>>
	 */
	public function revoke_others( int $user_id ): array {
		$manager = \WP_Session_Tokens::get_instance( $user_id );
		$token   = wp_get_session_token();
		if ( '' !== $token ) {
			$manager->destroy_others( $token );
		} else {
			$manager->destroy_all();
		}
		do_action( 'cliezoo_audit', 'sessions_revoked_others' );
		return $this->list( $user_id );
	}

	/**
	 * Ends every session of a user (deactivation, email change, password reset).
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	public function destroy_all( int $user_id ): void {
		\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
	}
}
