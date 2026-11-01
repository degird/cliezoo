<?php
/**
 * Registry of extra REST routes in cliezoo/v1.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes that are not generated from Actions (auth, public token pages,
 * webhooks, uploads). Add-ons register here so there is one API
 * namespace.
 */
final class RouteRegistry {

	/**
	 * Routes: [path, args] pairs as for register_rest_route().
	 *
	 * @var array<int, array{0:string,1:array<string, mixed>}>
	 */
	private array $routes = array();

	/**
	 * Adds a route. `permission_callback` is mandatory.
	 *
	 * @param string               $path Route path (regex allowed).
	 * @param array<string, mixed> $args register_rest_route() args.
	 * @return void
	 * @throws \InvalidArgumentException Without a permission callback.
	 */
	public function add( string $path, array $args ): void {
		if ( ! isset( $args['permission_callback'] ) ) {
			throw new \InvalidArgumentException( 'Every Cliezoo route needs a permission_callback.' );
		}
		$own                         = $args['permission_callback'];
		$args['permission_callback'] = static function ( $request ) use ( $own, $path ) {
			$allowed = $own( $request );
			if ( true !== $allowed ) {
				return $allowed;
			}
			/**
			 * Lets platform gates (required two-step verification, Safe mode…)
			 * refuse a hand-written route, the way they refuse Actions.
			 *
			 * @param true|\WP_Error $allowed Decision so far.
			 * @param string         $path    Route path.
			 * @param mixed          $request Request.
			 */
			return apply_filters( 'cliezoo_route_allowed', true, $path, $request );
		};
		$this->routes[]              = array( $path, $args );
	}

	/**
	 * All routes.
	 *
	 * @return array<int, array{0:string,1:array<string, mixed>}>
	 */
	public function all(): array {
		return $this->routes;
	}
}
