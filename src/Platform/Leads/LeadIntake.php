<?php
/**
 * Leads sent in from elsewhere: a webhook, Zapier, a site's own form.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Leads;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\RateLimiter;
use Cliezoo\Core\Request;
use Cliezoo\Core\Rest\RouteRegistry;
use Cliezoo\Platform\Permissions\Actor;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One address anything can post a lead to, with a key.
 *
 * The key is made here, shown once and kept only as a hash: whoever holds
 * it can add leads and nothing else, so it goes into a Zapier step or a
 * webhook setting rather than being an account's password. A lead that
 * arrives this way goes through the same service as one typed in — the same
 * checks, the same duplicate rule — and says it came in through the API.
 * Only a lead's plain fields are taken; nothing else about the workspace can
 * be reached through this door.
 */
final class LeadIntake {

	/** Where the key's hash is kept. */
	private const OPTION = 'cliezoo_lead_intake_key';

	/** Posts a minute from one address, and an hour on one key. */
	private const PER_ADDRESS = 30;
	private const PER_KEY     = 600;

	/** What a posted lead may say, and what each becomes. */
	private const TAKES = array(
		'name'    => 'name',
		'email'   => 'email',
		'phone'   => 'phone',
		'company' => 'business_name',
		'website' => 'website',
		'notes'   => 'notes',
		'source'  => 'source',
	);

	/**
	 * The plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Holds the plugin it reads its services from.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Whether anything can post leads in.
	 *
	 * @return bool
	 */
	public function is_on(): bool {
		return '' !== (string) get_option( self::OPTION, '' );
	}

	/**
	 * Makes a new key — which stops the old one — and hands it back once.
	 *
	 * @return array{key: string, url: string}
	 */
	public function new_key(): array {
		$key = 'czl_' . wp_generate_password( 40, false );
		update_option( self::OPTION, hash( 'sha256', $key ), false );
		do_action( 'cliezoo_audit', 'lead_intake_key_made', array() );
		return array(
			'key' => $key,
			'url' => rest_url( 'cliezoo/v1/public/leads' ),
		);
	}

	/**
	 * Stops taking leads in.
	 *
	 * @return array{on: bool}
	 */
	public function turn_off(): array {
		delete_option( self::OPTION );
		do_action( 'cliezoo_audit', 'lead_intake_key_removed', array() );
		return array( 'on' => false );
	}

	/**
	 * The public route.
	 *
	 * @param RouteRegistry $routes Routes.
	 * @return void
	 */
	public function routes( RouteRegistry $routes ): void {
		$routes->add(
			'/public/leads',
			array(
				'methods'             => 'POST',
				// The key is the credential, checked in take().
				'permission_callback' => '__return_true',
				'callback'            => function ( \WP_REST_Request $request ) {
					try {
						// From the header, or the body — never the address,
						// where it would be written into every log it passes.
						$key = (string) $request->get_header( 'x_cliezoo_key' );
						if ( '' === $key ) {
							$body = (array) ( $request->get_json_params() ?? array() ) + (array) $request->get_body_params();
							$key  = is_scalar( $body['key'] ?? null ) ? (string) $body['key'] : '';
						}
						return new \WP_REST_Response( array( 'data' => $this->take( $key, (array) $request->get_params() ) ), 201 );
					} catch ( ActionError $error ) {
						return $error->to_wp_error();
					}
				},
			)
		);
	}

	/**
	 * Takes one lead in.
	 *
	 * @param string               $key   The key it was sent with.
	 * @param array<string, mixed> $given What was sent.
	 * @return array{uid: string}
	 * @throws ActionError When the key is wrong, too much has been sent, or the lead will not do.
	 */
	public function take( string $key, array $given ): array {
		$address = 'lead-intake:' . Request::ip();
		if ( RateLimiter::hit( $address, self::PER_ADDRESS, MINUTE_IN_SECONDS ) ) {
			throw ActionError::rate_limited( __( 'Too many leads from one place at once. Wait a minute.', 'cliezoo' ), max( 1, RateLimiter::retry_after( $address ) ) );
		}
		$kept = (string) get_option( self::OPTION, '' );
		// The same answer for no key, a wrong key and intake turned off:
		// nothing here says which it was.
		if ( '' === $kept || '' === $key || ! hash_equals( $kept, hash( 'sha256', $key ) ) ) {
			throw ActionError::forbidden();
		}
		if ( RateLimiter::hit( 'lead-intake-key', self::PER_KEY, HOUR_IN_SECONDS ) ) {
			throw ActionError::rate_limited( __( 'That is as many leads as this key takes in an hour.', 'cliezoo' ), max( 1, RateLimiter::retry_after( 'lead-intake-key' ) ) );
		}

		$input = array( 'origin' => 'api' );
		foreach ( self::TAKES as $from => $to ) {
			if ( isset( $given[ $from ] ) && is_scalar( $given[ $from ] ) && '' !== trim( (string) $given[ $from ] ) ) {
				$input[ $to ] = mb_substr( trim( (string) $given[ $from ] ), 0, 'notes' === $to ? 5000 : 190 );
			}
		}
		if ( empty( $input['name'] ) && empty( $input['email'] ) ) {
			throw ActionError::validation( __( 'A lead needs a name or an email address.', 'cliezoo' ) );
		}
		if ( empty( $input['name'] ) ) {
			$input['name'] = (string) $input['email'];
		}

		// Through the service, as the workspace itself: the same checks and
		// the same duplicate rule as a lead typed in.
		$made = (array) $this->plugin->service( 'leads' )->create( Actor::system(), $input );
		return array( 'uid' => (string) ( $made['uid'] ?? '' ) );
	}
}
