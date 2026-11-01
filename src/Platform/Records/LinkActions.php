<?php
/**
 * Public link actions and the public resolve route.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Records;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\Rest\RouteRegistry;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET|POST /links`, `POST /links/{uid}/revoke|regenerate` and the public
 * `POST /public/links/resolve` used by token pages.
 */
final class LinkActions {

	/**
	 * Hooks.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return void
	 */
	public static function hooks( Plugin $plugin ): void {
		$plugin->container()->set( PublicLinks::class, static fn(): PublicLinks => new PublicLinks( $plugin ) );
		add_action( 'cliezoo_register_actions', static fn( ActionRegistry $registry ) => self::actions( $registry, $plugin ) );
		add_action( 'cliezoo_register_routes', static fn( RouteRegistry $routes ) => self::routes( $routes, $plugin ) );
	}

	/**
	 * Actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	public static function actions( ActionRegistry $registry, Plugin $plugin ): void {
		$links  = static fn(): PublicLinks => $plugin->get( PublicLinks::class );
		$object = array(
			'object_type' => array(
				'type'     => 'string',
				'pattern'  => '^[a-z_]{2,40}$',
				'required' => true,
			),
			'uid'         => Op::uid(),
		);
		$registry->add(
			Op::make( 'links.list', __( 'Public links of a record', 'cliezoo' ) )
				->input( $object )
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run( static fn( Actor $a, array $in ): array => $links()->for_record( $a, (string) $in['object_type'], (string) $in['uid'] ) )
				->rest( 'GET', '/links' )
				->readonly()
		);
		$registry->add(
			Op::make( 'links.create', __( 'Create a public link', 'cliezoo' ) )
				->input(
					$object + array(
						'purpose'        => array(
							'type'    => 'string',
							'pattern' => '^[a-z_]{2,30}$',
							'default' => 'share',
						),
						'expires_at'     => array(
							'type'   => array( 'string', 'null' ),
							'format' => 'date-time',
						),
						'passcode'       => Op::str( 100 ),
						'allow_download' => array( 'type' => 'boolean' ),
						'require_email'  => array( 'type' => 'boolean' ),
					)
				)
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run( static fn( Actor $a, array $in ): array => $links()->create( $a, $in ) )
				->rest( 'POST', '/links' )
		);
		foreach ( array( 'revoke', 'regenerate' ) as $verb ) {
			$registry->add(
				Op::make( 'links.' . $verb, 'revoke' === $verb ? __( 'Turn off a public link', 'cliezoo' ) : __( 'Replace a public link', 'cliezoo' ) )
					->input( array( 'uid' => Op::uid() ) )
					->allow( static fn( Actor $a ): bool => $a->is_staff() )
					->run( static fn( Actor $a, array $in ): array => $links()->$verb( $a, (string) $in['uid'] ) )
					->rest( 'POST', '/links/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/' . $verb )
			);
		}
	}

	/**
	 * Public resolve route (the token is the credential; rate-limited inside).
	 *
	 * @param RouteRegistry $routes Routes.
	 * @param Plugin        $plugin Plugin.
	 * @return void
	 */
	public static function routes( RouteRegistry $routes, Plugin $plugin ): void {
		$routes->add(
			'/public/links/resolve',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // Public endpoint: the link token is the credential.
				'callback'            => static function ( \WP_REST_Request $request ) use ( $plugin ) {
					$in = (array) $request->get_json_params() + (array) $request->get_body_params();
					try {
						$result = $plugin->get( PublicLinks::class )->resolve( (string) ( $in['token'] ?? '' ), (string) ( $in['passcode'] ?? '' ), (string) ( $in['purpose'] ?? '' ) );
						unset( $result['object_id'] );
						return new \WP_REST_Response( array( 'data' => $result ), 200 );
					} catch ( ActionError $error ) {
						return $error->to_wp_error();
					}
				},
			)
		);
	}
}
