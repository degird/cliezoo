<?php
/**
 * Module contract.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A feature area (clients, leads…). Every module implements it.
 */
interface Module {

	/**
	 * Unique module id, e.g. "invoices".
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Ids of modules this one needs.
	 *
	 * @return string[]
	 */
	public function dependencies(): array;

	/**
	 * Whether the user can switch this module off in Settings → Modules.
	 *
	 * @return bool
	 */
	public function switchable(): bool;

	/**
	 * Entity definitions owned by this module (tables, REST, abilities).
	 *
	 * @return \Cliezoo\Core\Data\Entity[]
	 */
	public function entities(): array;

	/**
	 * Numbered data migrations, keyed by migration id ("0002_backfill_x").
	 * Table structure comes from entities() through dbDelta.
	 *
	 * @return array<string, callable>
	 */
	public function migrations(): array;

	/**
	 * Registers hooks, actions, routes, jobs, reminders, notifications, AI tasks.
	 * Runs on every request for enabled modules, so it must stay cheap.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void;
}
