<?php
/**
 * Activity timeline and workspace audit log.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Activity;

use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Field;
use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records every change (old → new) on records and security events
 * (logins, permission changes, exports, API/MCP calls, impersonation).
 */
final class ActivityModule extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'activity';
	}

	/**
	 * {@inheritDoc}
	 */
	public function switchable(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function entities(): array {
		return array(
			( new Entity(
				'activity',
				array(
					'object_type'       => Field::text( 40 )->required()->filterable(),
					'object_id'         => Field::int()->required(),
					'client_id'         => Field::int()->filterable(),
					'project_id'        => Field::int()->filterable(),
					'action'            => Field::text( 40 )->required()->filterable(),
					'member_id'         => Field::int(),
					'channel'           => Field::text( 20 )->default( 'app' )->filterable(),
					'summary'           => Field::text( 255 ),
					'changes'           => Field::json(),
					'meta'              => Field::json(),
					'visible_to_client' => Field::bool()->filterable(),
				)
			) )->with(
				array(
					'label'        => __( 'Activity', 'cliezoo' ),
					'plural_label' => __( 'Activity', 'cliezoo' ),
					'soft_delete'  => false,
					'activity'     => false,
					'indexes'      => array(
						'object'  => array( 'object_type', 'object_id', 'created_at' ),
						'client'  => array( 'client_id', 'created_at' ),
						'project' => array( 'project_id', 'created_at' ),
						'member'  => array( 'member_id', 'created_at' ),
					),
					'permission'   => 'activity',
				)
			),
			( new Entity(
				'audit_log',
				array(
					'event'       => Field::text( 40 )->required()->filterable(),
					'member_id'   => Field::int(),
					'user_id'     => Field::int(),
					'channel'     => Field::text( 20 )->default( 'app' )->filterable(),
					'object_type' => Field::text( 40 ),
					'object_id'   => Field::int(),
					'status'      => Field::enum( array( 'success', 'failure' ) )->default( 'success' )->filterable(),
					'ip'          => Field::text( 45 ),
					'user_agent'  => Field::text( 255 ),
					'meta'        => Field::json(),
				)
			) )->with(
				array(
					'label'        => __( 'Audit entry', 'cliezoo' ),
					'plural_label' => __( 'Audit log', 'cliezoo' ),
					'soft_delete'  => false,
					'activity'     => false,
					'indexes'      => array(
						'event'   => array( 'event', 'created_at' ),
						'member'  => array( 'member_id', 'created_at' ),
						'channel' => array( 'channel', 'created_at' ),
					),
					'permission'   => 'audit',
				)
			),
		);
	}

	/**
	 * Registers the listeners and actions.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( ActivityLog::class, static fn(): ActivityLog => new ActivityLog( $plugin ) );
		$plugin->container()->set( AuditLog::class, static fn(): AuditLog => new AuditLog( $plugin ) );
		ActivityLog::listen( $plugin );
		AuditLog::listen( $plugin );
		add_filter( 'cliezoo_entity_verbs', array( $this, 'verbs' ), 10, 2 );
		add_action(
			'cliezoo_register_actions',
			static function ( ActionRegistry $registry ) use ( $plugin ): void {
				$registry->add( new Actions\ListActivity( $plugin ) );
				$registry->add( new Actions\ListAuditLog( $plugin ) );
				Changes::register( $registry, $plugin );
			}
		);
	}

	/**
	 * Activity and audit rows are append-only: no generic routes.
	 *
	 * @param string[] $verbs  Verbs.
	 * @param Entity   $entity Entity.
	 * @return string[]
	 */
	public function verbs( array $verbs, Entity $entity ): array {
		return in_array( $entity->name, array( 'activity', 'audit_log' ), true ) ? array() : $verbs;
	}
}
