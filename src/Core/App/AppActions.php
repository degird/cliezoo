<?php
/**
 * Session, personal UI preferences and app URL actions.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\App;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * `GET /session` (boot data refresh; guests get theirs from the page, and the
 * app reloads after login so the REST nonce matches the new session),
 * `GET|PATCH /me/preferences` (theme, sidebar layout, quick access) and
 * `GET|POST /app-urls` (change `/dash` or `/cz` after a conflict check).
 */
final class AppActions {

	/**
	 * Registers the actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	public static function register( ActionRegistry $registry, Plugin $plugin ): void {
		$registry->add(
			Op::make( 'session.get', __( 'Current session', 'cliezoo' ) )
				->input(
					array(
						'context' => array(
							'type'    => 'string',
							'enum'    => array( 'app', 'public' ),
							'default' => 'app',
						),
					)
				)
				->run( static fn( Actor $a, array $in ): array => ( new Boot( $plugin ) )->data( (string) ( $in['context'] ?? 'app' ) ) )
				->rest( 'GET', '/session' )
				->readonly()
		);
		$registry->add(
			Op::make( 'preferences.get', __( 'My app preferences', 'cliezoo' ) )
				->run( static fn( Actor $a ): array => Preferences::get( $a->user_id ) )
				->rest( 'GET', '/me/preferences' )
				->readonly()
		);
		$registry->add(
			Op::make( 'preferences.update', __( 'Save my app preferences', 'cliezoo' ) )
				->input( Preferences::schema() )
				->run( static fn( Actor $a, array $in ): array => Preferences::update( $a->user_id, $in ) )
				->rest( 'PATCH', '/me/preferences' )
		);
		$base = array(
			'type'     => 'string',
			'pattern'  => '^[a-z0-9][a-z0-9\-]{1,39}$',
			'required' => true,
		);
		$kind = array(
			'type'     => 'string',
			'enum'     => array( 'app', 'public' ),
			'required' => true,
		);
		$registry->add(
			Op::make( 'app_urls.get', __( 'App addresses', 'cliezoo' ) )
				->allow( static fn( Actor $a ): bool => $a->is_admin() )
				->run(
					static fn(): array => AppRouter::bases() + array(
						'appUrl'    => AppRouter::app_url(),
						'publicUrl' => AppRouter::public_url(),
						'pretty'    => AppRouter::pretty(),
						'problems'  => array(
							'app'    => AppRouter::conflicts( AppRouter::bases()['app'], 'app' ),
							'public' => AppRouter::conflicts( AppRouter::bases()['public'], 'public' ),
						),
					)
				)
				->rest( 'GET', '/app-urls' )
				->readonly()
		);
		$registry->add(
			Op::make( 'sync.poll', __( 'What has changed', 'cliezoo' ) )
				->describe( __( 'When each kind of record last changed, so a screen can refetch only what moved.', 'cliezoo' ) )
				->allow( static fn( Actor $a ): bool => ! $a->is_guest() && $a->member_id > 0 )
				->run( static fn(): array => SyncClock::state() )
				->rest( 'GET', '/sync/poll' )
				->readonly()
		);

		$registry->add(
			Op::make( 'portal_domain.get', __( 'Portal domain', 'cliezoo' ) )
				->describe( __( 'The domain the client portal is served on, and whether it has been checked.', 'cliezoo' ) )
				->allow( static fn( Actor $a ): bool => $a->is_admin() )
				->run( static fn(): array => self::domain_state() )
				->rest( 'GET', '/portal-domain' )
				->readonly()
		);
		$registry->add(
			Op::make( 'portal_domain.update', __( 'Set the portal domain', 'cliezoo' ) )
				->input(
					array(
						'host' => array(
							'type'      => 'string',
							'maxLength' => 253,
						),
					)
				)
				->allow( static fn( Actor $a ): bool => $a->is_admin() )
				->run(
					static function ( Actor $a, array $in ): array {
						$problems = PortalDomain::set( (string) ( $in['host'] ?? '' ) );
						if ( $problems ) {
							$message = implode( ' ', $problems );
							throw ActionError::validation( $message, array( 'host' => $message ) );
						}
						do_action(
							'cliezoo_audit',
							'portal_domain_changed',
							array( 'host' => PortalDomain::all()['host'] )
						);
						return self::domain_state();
					}
				)
				->rest( 'POST', '/portal-domain' )
		);
		$registry->add(
			Op::make( 'portal_domain.verify', __( 'Check the portal domain', 'cliezoo' ) )
				->describe( __( 'Asks the domain whether it reaches this site over HTTPS. Nothing moves until it does.', 'cliezoo' ) )
				->allow( static fn( Actor $a ): bool => $a->is_admin() )
				->run(
					static function (): array {
						PortalDomain::verify();
						return self::domain_state();
					}
				)
				->rest( 'POST', '/portal-domain/check' )
		);
		$registry->add(
			Op::make( 'app_urls.check', __( 'Check an app address', 'cliezoo' ) )
				->input(
					array(
						'kind' => $kind,
						'base' => $base,
					)
				)
				->allow( static fn( Actor $a ): bool => $a->is_admin() )
				->run( static fn( Actor $a, array $in ): array => array( 'problems' => AppRouter::conflicts( (string) $in['base'], (string) $in['kind'] ) ) )
				->rest( 'GET', '/app-urls/check' )
				->readonly()
		);
		$registry->add(
			Op::make( 'app_urls.update', __( 'Change an app address', 'cliezoo' ) )
				->input(
					array(
						'kind' => $kind,
						'base' => $base,
					)
				)
				->allow( static fn( Actor $a ): bool => $a->is_admin() )
				->run(
					static function ( Actor $a, array $in ): array {
						$problems = AppRouter::change_base( (string) $in['kind'], (string) $in['base'] );
						if ( $problems ) {
							$message = implode( ' ', $problems );
							throw ActionError::validation( $message, array( 'base' => $message ) );
						}
						do_action(
							'cliezoo_audit',
							'app_url_changed',
							array(
								'kind' => (string) $in['kind'],
								'base' => (string) $in['base'],
							)
						);
						return AppRouter::bases();
					}
				)
				->rest( 'POST', '/app-urls' )
		);
	}

	/**
	 * The portal domain as the settings screen shows it: the domain, what
	 * a browser would open, and what the last check found. The token is
	 * not among them — it is a public identifier, but nothing here needs
	 * it, and what is not sent cannot be mistaken for a secret.
	 *
	 * @return array<string, mixed>
	 */
	private static function domain_state(): array {
		$all = PortalDomain::all();
		unset( $all['token'] );
		$all['url'] = '' === $all['host'] ? '' : 'https://' . $all['host'] . '/';
		return $all;
	}
}
