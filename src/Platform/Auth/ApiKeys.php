<?php
/**
 * A person's own API keys.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Auth;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * A key is a WordPress application password made for Cliezoo: WordPress
 * stores only its hash and checks it on every request, and its secret is
 * shown once, when it is made. Cliezoo keeps beside it whether it is a
 * sandbox key (ApiSandbox) and remembers which keys are its own, so a
 * person's other application passwords are neither listed nor revoked here.
 *
 * Keys are made and revoked in the app, signed in — never by a request
 * that is itself signed with a key.
 */
final class ApiKeys {

	/**
	 * User meta: uuid => {sandbox, made}.
	 */
	public const META = 'cliezoo_api_keys';

	/**
	 * The most keys one person keeps.
	 */
	private const MOST = 20;

	/**
	 * The keys of the person asking.
	 *
	 * @param Actor $actor Who.
	 * @return array<string, mixed>
	 */
	public function list( Actor $actor ): array {
		$this->may( $actor, false );
		$mine = $this->mine( $actor->user_id );
		$out  = array();
		foreach ( \WP_Application_Passwords::get_user_application_passwords( $actor->user_id ) as $item ) {
			$uuid = (string) $item['uuid'];
			if ( ! isset( $mine[ $uuid ] ) ) {
				continue;
			}
			$out[] = array(
				'uuid'      => $uuid,
				'name'      => (string) $item['name'],
				'sandbox'   => ! empty( $mine[ $uuid ]['sandbox'] ),
				'created'   => gmdate( 'c', (int) $item['created'] ),
				'last_used' => $item['last_used'] ? gmdate( 'c', (int) $item['last_used'] ) : null,
				'last_ip'   => (string) ( $item['last_ip'] ?? '' ),
			);
		}
		return array(
			'keys'      => $out,
			'available' => wp_is_application_passwords_available_for_user( get_userdata( $actor->user_id ) ),
			'username'  => (string) ( get_userdata( $actor->user_id )->user_login ?? '' ),
			'root'      => rest_url( 'cliezoo/v1' ),
		);
	}

	/**
	 * Makes a key, and gives its secret this once.
	 *
	 * @param Actor  $actor   Who.
	 * @param string $name    What it is for.
	 * @param bool   $sandbox Whether it is a sandbox key.
	 * @return array<string, mixed> The key, with `secret`.
	 * @throws ActionError When it may not be made.
	 */
	public function create( Actor $actor, string $name, bool $sandbox ): array {
		$this->may( $actor, true );
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			throw ActionError::validation( __( 'Say what the key is for, so you know which to revoke later.', 'cliezoo' ) );
		}
		$user = get_userdata( $actor->user_id );
		if ( ! $user || ! wp_is_application_passwords_available_for_user( $user ) ) {
			throw ActionError::validation( __( 'This site does not allow application passwords, so no key can be made. A site administrator can allow them (they need HTTPS).', 'cliezoo' ) );
		}
		$mine = $this->mine( $actor->user_id );
		if ( count( $mine ) >= self::MOST ) {
			throw ActionError::validation( __( 'You have as many keys as one person may keep. Revoke one you no longer use first.', 'cliezoo' ) );
		}
		$made = \WP_Application_Passwords::create_new_application_password(
			$actor->user_id,
			array(
				'name'   => 'Cliezoo: ' . mb_substr( $name, 0, 80 ),
				'app_id' => '',
			)
		);
		if ( is_wp_error( $made ) ) {
			throw ActionError::validation( $made->get_error_message() );
		}
		list( $secret, $item )          = $made;
		$mine[ (string) $item['uuid'] ] = array(
			'sandbox' => $sandbox,
			'made'    => gmdate( 'c' ),
		);
		update_user_meta( $actor->user_id, self::META, $mine );

		return array(
			'uuid'    => (string) $item['uuid'],
			'name'    => (string) $item['name'],
			'sandbox' => $sandbox,
			// Shown once. WordPress keeps only its hash.
			'secret'  => \WP_Application_Passwords::chunk_password( $secret ),
		);
	}

	/**
	 * Revokes one of the person's keys.
	 *
	 * @param Actor  $actor Who.
	 * @param string $uuid  The key.
	 * @return array<string, mixed>
	 * @throws ActionError When it is not theirs.
	 */
	public function revoke( Actor $actor, string $uuid ): array {
		$this->may( $actor, true, false );
		$mine = $this->mine( $actor->user_id );
		if ( ! isset( $mine[ $uuid ] ) ) {
			throw ActionError::not_found();
		}
		\WP_Application_Passwords::delete_application_password( $actor->user_id, $uuid );
		unset( $mine[ $uuid ] );
		update_user_meta( $actor->user_id, self::META, $mine );
		return array( 'revoked' => $uuid );
	}

	/**
	 * Whether the key a request was signed with is a sandbox key.
	 *
	 * @param int    $user_id Who signed in.
	 * @param string $uuid    The application password used.
	 * @return bool
	 */
	public static function is_sandbox( int $user_id, string $uuid ): bool {
		$mine = get_user_meta( $user_id, self::META, true );
		return is_array( $mine ) && ! empty( $mine[ $uuid ]['sandbox'] );
	}

	/**
	 * The person's Cliezoo keys, without any WordPress has since lost.
	 *
	 * @param int $user_id Who.
	 * @return array<string, array<string, mixed>>
	 */
	private function mine( int $user_id ): array {
		$mine = get_user_meta( $user_id, self::META, true );
		$mine = is_array( $mine ) ? $mine : array();
		$live = array();
		foreach ( \WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			$live[ (string) $item['uuid'] ] = true;
		}
		return array_intersect_key( $mine, $live );
	}

	/**
	 * Refuses whoever may not have keys, and any change made with a key.
	 *
	 * @param Actor $actor  Who.
	 * @param bool  $change Whether keys are being made or revoked.
	 * @param bool  $api    Whether the API permission is needed (revoking
	 *                      one's own key is always allowed, even after the
	 *                      permission was taken away).
	 * @return void
	 * @throws ActionError When not allowed.
	 */
	private function may( Actor $actor, bool $change, bool $api = true ): void {
		if ( $actor->member_id <= 0 || ! $actor->is_staff() ) {
			throw ActionError::forbidden();
		}
		if ( $api && ! ApiAccess::allowed( $actor ) ) {
			$refusal = ApiAccess::refusal();
			throw $refusal;
		}
		if ( $change && ApiAccess::is_api_request() ) {
			throw new ActionError( 'cliezoo_keys_in_app', __( 'API keys are made and revoked in the app, signed in — not with another key.', 'cliezoo' ), 403 );
		}
	}
}
