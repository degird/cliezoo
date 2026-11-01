<?php
/**
 * Registry of storage drivers.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The StorageDriverRegistry (Blueprint §19.1). Add-ons may add external drivers
 * through `cliezoo_register_storage_drivers`.
 */
final class StorageDriverRegistry {

	/**
	 * Drivers keyed by id.
	 *
	 * @var array<string, StorageDriver>|null
	 */
	private ?array $drivers = null;

	/**
	 * All drivers.
	 *
	 * @return array<string, StorageDriver>
	 */
	public function all(): array {
		if ( null === $this->drivers ) {
			$this->drivers = array( 'local' => new LocalDriver() );
			/**
			 * Register storage drivers.
			 *
			 * @param StorageDriverRegistry $registry Registry.
			 */
			do_action( 'cliezoo_register_storage_drivers', $this );
		}
		return $this->drivers;
	}

	/**
	 * Adds a driver.
	 *
	 * @param StorageDriver $driver Driver.
	 * @return void
	 */
	public function add( StorageDriver $driver ): void {
		$this->all();
		$this->drivers[ $driver->id() ] = $driver;
	}

	/**
	 * A driver by id. One nothing registered (its add-on is off, its
	 * connection removed) is out of reach, never this site's own storage in
	 * its place: a file kept in a bucket is not looked for, or written, here.
	 *
	 * @param string $id Driver id.
	 * @return StorageDriver
	 */
	public function get( string $id ): StorageDriver {
		$all = $this->all();
		return $all[ '' === $id ? 'local' : $id ] ?? new UnavailableDriver( $id );
	}

	/**
	 * Removes a driver (a connection that was removed). This site's own
	 * storage stays.
	 *
	 * @param string $id Driver id.
	 * @return void
	 */
	public function remove( string $id ): void {
		$this->all();
		if ( 'local' !== $id ) {
			unset( $this->drivers[ $id ] );
		}
	}

	/**
	 * Whether a driver exists.
	 *
	 * @param string $id Driver id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->all()[ $id ] );
	}
}
