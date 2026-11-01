<?php
/**
 * Defaults for modules.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sensible defaults so modules only override what they use.
 */
abstract class BaseModule implements Module {

	/**
	 * {@inheritDoc}
	 */
	public function dependencies(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function switchable(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function entities(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function migrations(): array {
		return array();
	}

	/**
	 * Registers hooks, services and extension points.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
	}
}
