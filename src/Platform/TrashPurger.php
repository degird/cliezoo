<?php
/**
 * Empties old trash (archive → trash → purge).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Logger;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily job: permanently deletes records that have been in the trash longer
 * than the retention period (default 30 days), in small batches. Records a
 * module refuses to delete (for example finalized invoices) are skipped.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class TrashPurger {

	private const BATCH = 50;

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
	 * Runs one batch per entity.
	 *
	 * @return int Rows purged.
	 */
	public function run(): int {
		global $wpdb;
		$days   = max( 1, (int) $this->plugin->settings()->get( 'workspace', 'trash_days', 30 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$actor  = Actor::system( 'system' );
		$total  = 0;
		foreach ( $this->plugin->entities()->all() as $entity ) {
			if ( ! $entity->soft_delete ) {
				continue;
			}
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE deleted_at IS NOT NULL AND deleted_at < %s ORDER BY id LIMIT %d', $entity->table(), $cutoff, self::BATCH ) );
			if ( ! $ids ) {
				continue;
			}
			$service = $this->plugin->service( $entity->name );
			foreach ( $service->repository()->find_many( array_map( 'intval', $ids ) ) as $row ) {
				try {
					$service->purge_row( $actor, $row );
					++$total;
				} catch ( ActionError $error ) {
					Logger::debug(
						'Trash purge skipped a row',
						array(
							'entity' => $entity->name,
							'reason' => $error->getMessage(),
						)
					);
				}
			}
		}
		return $total;
	}
}
