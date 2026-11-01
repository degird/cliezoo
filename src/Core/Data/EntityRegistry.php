<?php
/**
 * Registry of entity definitions.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Data;

use Cliezoo\Core\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every entity known to Cliezoo (built in and from add-ons). Definitions are
 * built lazily on first use, so requests that never touch data pay nothing.
 */
final class EntityRegistry {

	/**
	 * Entities keyed by name.
	 *
	 * @var array<string, Entity>
	 */
	private array $entities = array();

	/**
	 * Modules whose entities are not built yet.
	 *
	 * @var Module[]
	 */
	private array $pending = array();

	/**
	 * Queues a module's entities.
	 *
	 * @param Module $module Module.
	 * @return void
	 */
	public function provide( Module $module ): void {
		$this->pending[] = $module;
	}

	/**
	 * Adds an entity directly.
	 *
	 * @param Entity $entity Entity.
	 * @return void
	 */
	public function add( Entity $entity ): void {
		$this->entities[ $entity->name ] = $entity->finalize();
	}

	/**
	 * Builds queued definitions.
	 *
	 * @return void
	 */
	private function build(): void {
		while ( $this->pending ) {
			$module = array_shift( $this->pending );
			foreach ( $module->entities() as $entity ) {
				$entity->module = $module->id();
				$this->add( $entity );
			}
		}
	}

	/**
	 * An entity by name.
	 *
	 * @param string $name Entity name.
	 * @return Entity
	 * @throws \InvalidArgumentException When unknown.
	 */
	public function get( string $name ): Entity {
		$this->build();
		if ( ! isset( $this->entities[ $name ] ) ) {
			throw new \InvalidArgumentException( esc_html( "Unknown entity {$name}" ) );
		}
		return $this->entities[ $name ];
	}

	/**
	 * Whether an entity exists.
	 *
	 * @param string $name Entity name.
	 * @return bool
	 */
	public function has( string $name ): bool {
		$this->build();
		return isset( $this->entities[ $name ] );
	}

	/**
	 * All entities.
	 *
	 * @return array<string, Entity>
	 */
	public function all(): array {
		$this->build();
		return $this->entities;
	}

	/**
	 * Entities owned by a module.
	 *
	 * @param string $module Module id.
	 * @return array<string, Entity>
	 */
	public function for_module( string $module ): array {
		return array_filter( $this->all(), static fn( Entity $entity ): bool => $entity->module === $module );
	}
}
