<?php
/**
 * Registry of built-in and third-party modules.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds modules, resolves dependencies and module switches.
 */
final class ModuleRegistry {

	/**
	 * Registered modules keyed by id.
	 *
	 * @var array<string, Module>
	 */
	private array $modules = array();

	/**
	 * Module ids disabled because of an error at boot.
	 *
	 * @var array<string, string>
	 */
	private array $failed = array();

	/**
	 * Adds a module.
	 *
	 * @param Module $module Module.
	 * @return void
	 */
	public function add( Module $module ): void {
		$this->modules[ $module->id() ] = $module;
	}

	/**
	 * Every registered module.
	 *
	 * @return array<string, Module>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * A module by id.
	 *
	 * @param string $id Module id.
	 * @return Module|null
	 */
	public function get( string $id ): ?Module {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * Modules that are switched on and whose dependencies are all active.
	 *
	 * @param string[] $switched_off Module ids the user turned off.
	 * @return array<string, Module>
	 */
	public function active( array $switched_off ): array {
		$active = array();
		foreach ( $this->modules as $id => $module ) {
			if ( isset( $this->failed[ $id ] ) ) {
				continue;
			}
			if ( $module->switchable() && in_array( $id, $switched_off, true ) ) {
				continue;
			}
			$active[ $id ] = $module;
		}
		// Drop modules whose dependencies are missing, repeating until stable.
		do {
			$changed = false;
			foreach ( $active as $id => $module ) {
				foreach ( $module->dependencies() as $dependency ) {
					if ( ! isset( $active[ $dependency ] ) ) {
						unset( $active[ $id ] );
						$changed = true;
						break;
					}
				}
			}
		} while ( $changed );
		return $active;
	}

	/**
	 * Marks a module as failed so it is skipped for the rest of the request.
	 *
	 * @param string $id     Module id.
	 * @param string $reason Log-safe reason.
	 * @return void
	 */
	public function fail( string $id, string $reason ): void {
		$this->failed[ $id ] = $reason;
	}

	/**
	 * Modules that failed to boot.
	 *
	 * @return array<string, string>
	 */
	public function failed(): array {
		return $this->failed;
	}
}
