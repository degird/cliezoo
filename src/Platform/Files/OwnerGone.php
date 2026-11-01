<?php
/**
 * What happens to a client's or a project's files when it is deleted for
 * good.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Files;

use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ledger G-0310. Files were left pointing at a client that no longer
 * existed, reachable from nowhere and never cleaned up. Now, when a client
 * or project is deleted for good, whoever deletes it chooses (`files` on the
 * purge): **keep** them in the library, unfiled (the default: nothing is
 * deleted that was not asked for); **archive** them, unfiled; or **trash**
 * them — the trash keeps each stored object until the trash is emptied,
 * which is when it leaves the storage. Folders go the same way.
 */
final class OwnerGone {

	/**
	 * The choices.
	 */
	public const CHOICES = array( 'keep', 'archive', 'trash' );

	/**
	 * The choice of the purge under way.
	 *
	 * @var string
	 */
	private static string $choice = 'keep';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Runs a purge with a choice for the files, then forgets it.
	 *
	 * @template T
	 * @param string        $choice keep, archive or trash.
	 * @param callable(): T $purge  The purge.
	 * @return T
	 */
	public static function with( string $choice, callable $purge ) {
		$before       = self::$choice;
		self::$choice = in_array( $choice, self::CHOICES, true ) ? $choice : 'keep';
		try {
			return $purge();
		} finally {
			self::$choice = $before;
		}
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action(
			'cliezoo_record_purging',
			function ( Entity $entity, array $row ): void {
				$column = array(
					'clients'  => 'client_id',
					'projects' => 'project_id',
				)[ $entity->name ] ?? '';
				if ( '' !== $column ) {
					$this->release( $column, (int) $row['id'] );
				}
			},
			10,
			2
		);
	}

	/**
	 * Unfiles the files and folders of a record going for good, archiving or
	 * trashing them as chosen.
	 *
	 * @param string $column client_id or project_id.
	 * @param int    $id     The record.
	 * @return void
	 */
	private function release( string $column, int $id ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( array( 'files', 'folders' ) as $type ) {
			if ( ! $this->plugin->entities()->has( $type ) ) {
				continue;
			}
			$table = esc_sql( $this->plugin->entities()->get( $type )->table() );
			$set   = "{$column} = NULL";
			if ( 'archive' === self::$choice ) {
				$set .= $wpdb->prepare( ', archived_at = COALESCE(archived_at, %s)', $now );
			}
			if ( 'trash' === self::$choice ) {
				$set .= $wpdb->prepare( ', deleted_at = COALESCE(deleted_at, %s)', $now );
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$set} WHERE {$column} = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column from code; values prepared.
		}
		do_action(
			'cliezoo_audit',
			'owner_files_released',
			array(
				'column' => $column,
				'id'     => $id,
				'choice' => self::$choice,
			)
		);
	}
}
