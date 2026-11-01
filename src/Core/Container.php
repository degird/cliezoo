<?php
/**
 * Lazy service container.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates services on first use so a request only builds what it needs.
 */
final class Container {

	/**
	 * Service factories keyed by id.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Built service instances keyed by id.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Registers a shared service factory.
	 *
	 * @param string   $id      Service id (usually the class name).
	 * @param callable $factory Receives the container, returns the service.
	 * @return void
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || isset( $this->instances[ $id ] );
	}

	/**
	 * Returns a service, building it on first use. Unregistered classes
	 * without constructor arguments are built directly.
	 *
	 * @param string $id Service id.
	 * @return object
	 * @throws \RuntimeException When the service is unknown.
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}
		if ( isset( $this->factories[ $id ] ) ) {
			$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
			return $this->instances[ $id ];
		}
		if ( class_exists( $id ) ) {
			$this->instances[ $id ] = new $id();
			return $this->instances[ $id ];
		}
		throw new \RuntimeException( sprintf( 'Unknown service "%s".', esc_html( $id ) ) );
	}

	/**
	 * Replaces a built instance (tests and extensions).
	 *
	 * @param string $id       Service id.
	 * @param object $instance Service instance.
	 * @return void
	 */
	public function instance( string $id, object $instance ): void {
		$this->instances[ $id ] = $instance;
	}
}
