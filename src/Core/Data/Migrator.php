<?php
/**
 * Schema installer and migration runner.
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
 * Creates/alters tables with dbDelta and runs numbered, forward-only,
 * idempotent data migrations. State lives in the `cliezoo_db_versions` option.
 */
final class Migrator {

	public const OPTION = 'cliezoo_db_versions';

	/**
	 * Brings every module's tables and migrations up to date.
	 *
	 * @param Module[] $modules Modules (all registered, so switching one on later works).
	 * @return array{created: string[], migrated: string[]}
	 */
	public function run( array $modules ): array {
		$state  = get_option( self::OPTION, array() );
		$state  = is_array( $state ) ? $state : array();
		$state += array(
			'schema'     => array(),
			'migrations' => array(),
		);
		$report = array(
			'created'  => array(),
			'migrated' => array(),
		);

		foreach ( $modules as $module ) {
			$id       = $module->id();
			$entities = array_map( static fn( Entity $entity ): Entity => $entity->finalize(), $module->entities() );
			if ( $entities ) {
				$hash = $this->schema_hash( $entities );
				if ( ( $state['schema'][ $id ] ?? '' ) !== $hash ) {
					foreach ( $entities as $entity ) {
						$this->install_table( $entity );
						$report['created'][] = $entity->table();
					}
					$state['schema'][ $id ] = $hash;
				}
			}

			$applied = $state['migrations'][ $id ] ?? array();
			foreach ( $module->migrations() as $migration_id => $callback ) {
				if ( in_array( $migration_id, $applied, true ) ) {
					continue;
				}
				call_user_func( $callback );
				$applied[]            = $migration_id;
				$report['migrated'][] = $id . '/' . $migration_id;
			}
			$state['migrations'][ $id ] = $applied;
		}

		update_option( self::OPTION, $state, false );
		return $report;
	}

	/**
	 * Runs dbDelta for one entity.
	 *
	 * @param Entity $entity Entity.
	 * @return void
	 */
	public function install_table( Entity $entity ): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $entity->create_sql( $wpdb->get_charset_collate() ) );
	}

	/**
	 * Verifies that an entity's table exists with every expected column.
	 *
	 * @param Entity $entity Entity.
	 * @return string[] Missing column names (empty when correct); ["<table>"] when the table is missing.
	 */
	public function verify( Entity $entity ): array {
		global $wpdb;
		$table  = esc_sql( $entity->table() );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $exists !== $table ) {
			return array( $table );
		}
		$columns = $wpdb->get_col( 'DESCRIBE ' . $table, 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Table name comes from code.
		return array_values( array_diff( $entity->columns(), $columns ) );
	}

	/**
	 * Drops the tables of the given entities (uninstall with data deletion only).
	 *
	 * @param Entity[] $entities Entities.
	 * @return void
	 */
	public function drop( array $entities ): void {
		global $wpdb;
		foreach ( $entities as $entity ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $entity->table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own tables on uninstall.
		}
	}

	/**
	 * Hash of a module's CREATE statements, to detect schema changes.
	 *
	 * @param Entity[] $entities Entities.
	 * @return string
	 */
	private function schema_hash( array $entities ): string {
		$sql = '';
		foreach ( $entities as $entity ) {
			$sql .= $entity->create_sql( '' );
		}
		return md5( $sql );
	}
}
