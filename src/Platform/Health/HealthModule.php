<?php
/**
 * Setup health module.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Health;

use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\Rest\RouteRegistry;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /health` for the Setup Health screen, `GET /ping` so the site (and
 * uptime monitors) can prove the REST API is reachable, and a daily
 * re-check so problems are noticed without anyone looking.
 */
final class HealthModule extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'health';
	}

	/**
	 * {@inheritDoc}
	 */
	public function switchable(): bool {
		return false;
	}

	/**
	 * Registers hooks.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( Health::class, static fn(): Health => new Health( $plugin ) );
		$health = static fn(): Health => $plugin->get( Health::class );
		( new OptimiserExclusions() )->hooks();

		add_action(
			'cliezoo_every_day',
			static function () use ( $health ): void {
				$health()->refresh();
			}
		);
		add_action(
			'cliezoo_register_routes',
			static function ( RouteRegistry $routes ): void {
				$routes->add(
					'/ping',
					array(
						// POST too: Setup health sends it a formatted sentence
						// to see whether a firewall refuses HTML. Nothing sent
						// is read.
						'methods'             => 'GET, POST',
						'callback'            => static fn(): \WP_REST_Response => new \WP_REST_Response(
							array(
								'data' => array(
									// Public, so it says only that the route answers.
									'ok'   => true,
									'time' => gmdate( 'c' ),
								),
							),
							200
						),
						// Public: /ping answers only "ok" and the time.
						'permission_callback' => '__return_true',
					)
				);
			}
		);
		add_action(
			'cliezoo_register_actions',
			static function ( ActionRegistry $registry ) use ( $health ): void {
				$registry->add(
					Op::make( 'health.get', __( 'Setup health', 'cliezoo' ) )
						->describe( __( 'Checks that scheduled jobs run, the REST API is reachable, the app address is free, nothing is caching the app, the encryption key will survive, email goes out and uploads have room.', 'cliezoo' ) )
						->input( array( 'refresh' => array( 'type' => 'boolean' ) ) )
						->allow( static fn( Actor $a ): bool => $a->is_admin() )
						->run( static fn( Actor $a, array $in ): array => empty( $in['refresh'] ) ? $health()->last() : $health()->refresh() )
						->rest( 'GET', '/health' )
						->readonly()
				);
			}
		);
	}
}
