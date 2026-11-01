<?php
/**
 * Passkeys (WebAuthn).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Auth;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Logger;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\RateLimiter;
use Cliezoo\Core\Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Registration and login with passkeys (spike S12: lbuchs/WebAuthn, renamed into this plugin’s own namespace, on the
 * server, @simplewebauthn/browser in the app). Credentials are stored in the
 * user's two_factor row; challenges are single-use tokens.
 *
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Library result properties.
 */
final class Passkeys {

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
	 * WebAuthn server for this site.
	 *
	 * @return \Cliezoo\Vendor\lbuchs\WebAuthn\WebAuthn
	 */
	private function server(): \Cliezoo\Vendor\lbuchs\WebAuthn\WebAuthn {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return new \Cliezoo\Vendor\lbuchs\WebAuthn\WebAuthn( (string) $this->plugin->settings()->get( 'workspace', 'name', get_bloginfo( 'name' ) ), $host, array( 'none', 'packed', 'fido-u2f', 'apple', 'android-key', 'android-safetynet', 'tpm' ), true );
	}

	/**
	 * Stored credentials of a user.
	 *
	 * @param int $user_id User.
	 * @return array<int, array<string, mixed>>
	 */
	private function credentials( int $user_id ): array {
		$row = $this->plugin->service( 'two_factor' )->repository()->first( array( 'user_id' => $user_id ) );
		return $row && $row['passkeys'] ? (array) json_decode( (string) $row['passkeys'], true ) : array();
	}

	/**
	 * Saves credentials.
	 *
	 * @param int                              $user_id     User.
	 * @param array<int, array<string, mixed>> $credentials Credentials.
	 * @return void
	 */
	private function save( int $user_id, array $credentials ): void {
		$repo = $this->plugin->service( 'two_factor' )->repository();
		$row  = $repo->first( array( 'user_id' => $user_id ) );
		$data = array( 'passkeys' => wp_json_encode( array_values( $credentials ) ) );
		if ( $row ) {
			$repo->update( (int) $row['id'], $data );
		} else {
			$repo->insert( $data + array( 'user_id' => $user_id ) );
		}
	}

	/**
	 * Number of passkeys.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public function count( int $user_id ): int {
		return count( $this->credentials( $user_id ) );
	}

	/**
	 * Passkeys for the account page (no key material).
	 *
	 * @param int $user_id User.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( int $user_id ): array {
		return array_map(
			static fn( array $c ): array => array(
				'id'        => (string) $c['id'],
				'name'      => (string) $c['name'],
				'created'   => (string) $c['created'],
				'last_used' => $c['last_used'] ?? null,
			),
			$this->credentials( $user_id )
		);
	}

	/**
	 * Creation options for the browser.
	 *
	 * @param int $user_id User.
	 * @return array<string, mixed>
	 */
	public function register_options( int $user_id ): array {
		$user    = get_userdata( $user_id );
		$server  = $this->server();
		$exclude = array_map( static fn( array $c ): string => (string) self::b64d( (string) $c['id'] ), $this->credentials( $user_id ) );
		// User verification is required, not preferred: a passkey stands in
		// for a second step, and it only does that if the device checked who
		// was holding it.
		$args  = $server->getCreateArgs( (string) $user_id, $user ? $user->user_login : 'user', $user ? $user->display_name : 'User', 240, false, 'required', null, $exclude );
		$token = $this->plugin->get( AuthService::class )->issue_token( $user_id, 'passkey_challenge', 300, array( 'challenge' => self::b64( $server->getChallenge()->getBinaryString() ) ) );
		return array(
			'options'   => json_decode( (string) wp_json_encode( $args ), true ),
			'challenge' => $token,
		);
	}

	/**
	 * Verifies an attestation and stores the new passkey.
	 *
	 * @param int                  $user_id User.
	 * @param array<string, mixed> $in      clientDataJSON, attestationObject (base64url), challenge token, name.
	 * @return array<int, array<string, mixed>>
	 * @throws ActionError When verification fails.
	 */
	public function register( int $user_id, array $in ): array {
		$auth  = $this->plugin->get( AuthService::class );
		$token = $auth->find_token( (string) $in['challenge'], 'passkey_challenge' );
		if ( ! $token || (int) $token['user_id'] !== $user_id ) {
			throw ActionError::validation( __( 'The passkey request expired. Please try again.', 'cliezoo' ) );
		}
		$auth->mark_used( $token );
		$meta = (array) json_decode( (string) $token['meta'], true );
		try {
			$data = $this->server()->processCreate( self::b64d( (string) $in['clientDataJSON'] ), self::b64d( (string) $in['attestationObject'] ), self::b64d( (string) $meta['challenge'] ), true, true, false );
		} catch ( \Throwable $e ) {
			Logger::debug( 'Passkey registration failed', array( 'error' => $e->getMessage() ) );
			throw ActionError::validation( __( 'This passkey could not be verified.', 'cliezoo' ) );
		}
		$credentials   = $this->credentials( $user_id );
		$credentials[] = array(
			'id'         => self::b64( (string) $data->credentialId ),
			'public_key' => (string) $data->credentialPublicKey,
			'counter'    => (int) $data->signatureCounter,
			'name'       => '' !== (string) ( $in['name'] ?? '' ) ? mb_substr( sanitize_text_field( (string) $in['name'] ), 0, 60 ) : Request::device( Request::user_agent() ),
			'created'    => gmdate( 'c' ),
		);
		$this->save( $user_id, $credentials );
		do_action( 'cliezoo_audit', 'passkey_added' );
		return $this->list( $user_id );
	}

	/**
	 * Removes a passkey.
	 *
	 * @param int    $user_id User.
	 * @param string $id      Credential id (base64url).
	 * @return array<int, array<string, mixed>>
	 */
	public function delete( int $user_id, string $id ): array {
		$credentials = array_filter( $this->credentials( $user_id ), static fn( array $c ): bool => (string) $c['id'] !== $id );
		$this->save( $user_id, $credentials );
		do_action( 'cliezoo_audit', 'passkey_removed' );
		return $this->list( $user_id );
	}

	/**
	 * Login options (usernameless when no login is given).
	 *
	 * @param string $login Optional email/username.
	 * @return array<string, mixed>
	 * @throws ActionError When asked for too often.
	 */
	public function login_options( string $login ): array {
		// Unauthenticated: capped per address so it cannot be used to probe
		// which accounts have passkeys, or to fill the token table.
		if ( RateLimiter::hit( 'passkey-options-ip:' . Request::ip(), 20, 900 ) ) {
			throw ActionError::rate_limited( __( 'Too many attempts. Please try again later.', 'cliezoo' ), 900 );
		}
		$user_id = 0;
		$allow   = array();
		if ( '' !== $login ) {
			$user = get_user_by( 'email', sanitize_email( $login ) );
			$user = $user ? $user : get_user_by( 'login', sanitize_user( $login ) );
			if ( $user ) {
				$user_id = (int) $user->ID;
				$allow   = array_map( static fn( array $c ): string => (string) self::b64d( (string) $c['id'] ), $this->credentials( $user_id ) );
			}
		}
		$server = $this->server();
		$args   = $server->getGetArgs( $allow, 240, true, true, true, true, true, 'required' );
		$token  = $this->plugin->get( AuthService::class )->issue_token( $user_id, 'passkey_challenge', 300, array( 'challenge' => self::b64( $server->getChallenge()->getBinaryString() ) ) );
		return array(
			'options'   => json_decode( (string) wp_json_encode( $args ), true ),
			'challenge' => $token,
		);
	}

	/**
	 * Verifies an assertion and logs in. A passkey stands in for the second
	 * step because the device verified the person holding it — which is why
	 * both the options and this check insist on that.
	 *
	 * @param array<string, mixed> $in id, clientDataJSON, authenticatorData, signature, userHandle, challenge, remember.
	 * @return array<string, mixed>
	 * @throws ActionError When verification fails.
	 */
	public function login( array $in ): array {
		if ( RateLimiter::hit( 'passkey-ip:' . Request::ip(), 30, 900 ) ) {
			throw ActionError::rate_limited( __( 'Too many attempts. Please try again later.', 'cliezoo' ), 900 );
		}
		$auth  = $this->plugin->get( AuthService::class );
		$token = $auth->find_token( (string) ( $in['challenge'] ?? '' ), 'passkey_challenge' );
		if ( ! $token ) {
			throw new ActionError( 'cliezoo_challenge_expired', __( 'The passkey request expired. Please try again.', 'cliezoo' ), 401 );
		}
		$auth->mark_used( $token );
		$meta    = (array) json_decode( (string) $token['meta'], true );
		$user_id = (int) $token['user_id'];
		if ( 0 === $user_id ) {
			$user_id = (int) self::b64d( (string) ( $in['userHandle'] ?? '' ) );
		}
		$credentials = $this->credentials( $user_id );
		foreach ( $credentials as $i => $credential ) {
			if ( ! hash_equals( (string) $credential['id'], (string) ( $in['id'] ?? '' ) ) ) {
				continue;
			}
			// One instance: the counter is read back off the object that
			// verified the assertion, otherwise clone detection is dead.
			$server = $this->server();
			try {
				$server->processGet( self::b64d( (string) $in['clientDataJSON'] ), self::b64d( (string) $in['authenticatorData'] ), self::b64d( (string) $in['signature'] ), (string) $credential['public_key'], self::b64d( (string) $meta['challenge'] ), (int) $credential['counter'], true, true );
			} catch ( \Throwable $e ) {
				break;
			}
			$credentials[ $i ]['last_used'] = gmdate( 'c' );
			$credentials[ $i ]['counter']   = max( (int) $credential['counter'], (int) $server->getSignatureCounter() );
			$this->save( $user_id, $credentials );
			$member = $this->plugin->get( \Cliezoo\Platform\Members\Members::class )->member_for( $user_id );
			if ( ! $member || 'active' !== $member['status'] ) {
				break;
			}
			return $auth->start_session( $user_id, ! empty( $in['remember'] ) );
		}
		throw new ActionError( 'cliezoo_login_failed', __( 'This passkey could not be used to log in.', 'cliezoo' ), 401 );
	}

	/**
	 * Base64url encode.
	 *
	 * @param string $binary Binary.
	 * @return string
	 */
	private static function b64( string $binary ): string {
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WebAuthn wire format.
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function b64d( string $text ): string {
		return (string) base64_decode( strtr( $text, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $text ) % 4 ) % 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- WebAuthn wire format.
	}
}
