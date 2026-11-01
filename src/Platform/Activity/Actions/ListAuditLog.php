<?php
/**
 * List the workspace audit log.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Activity\Actions;

use Cliezoo\Core\Actions\Action;
use Cliezoo\Core\Data\ObjectRef;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Activity\AuditLog;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /audit-log` — security events, for owners, admins and roles with "audit log".
 */
final class ListAuditLog extends Action {

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
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'audit.list';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'List the audit log', 'cliezoo' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'event'   => array(
					'type'      => 'string',
					'maxLength' => 40,
				),
				'channel' => array(
					'type'      => 'string',
					'maxLength' => 20,
				),
				'member'  => array(
					'type'    => 'string',
					'pattern' => '^[0-9A-HJKMNP-TV-Z]{26}$',
				),
				'from'    => array(
					'type'   => 'string',
					'format' => 'date',
				),
				'to'      => array(
					'type'   => 'string',
					'format' => 'date',
				),
				'cursor'  => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'limit'   => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 200,
					'default' => 50,
				),
			),
		);
	}

	/**
	 * Owners, admins and roles with the audit log permission.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $input Input.
	 * @return bool
	 */
	public function permission( Actor $actor, array $input ): bool {
		return $actor->is_admin() || $actor->allows( 'audit_view' );
	}

	/**
	 * Lists events.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( Actor $actor, array $input ) {
		$filter = array_intersect_key( $input, array_flip( array( 'event', 'channel', 'from', 'to' ) ) );
		if ( ! empty( $input['member'] ) ) {
			$filter['member_id'] = (int) ObjectRef::resolve( $this->plugin, $actor, 'members', (string) $input['member'] )[1]['id'];
		}
		return $this->plugin->get( AuditLog::class )->list( $filter, (string) ( $input['cursor'] ?? '' ), (int) ( $input['limit'] ?? 50 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function meta(): array {
		return array(
			'readonly'   => true,
			'idempotent' => true,
			'rest'       => array( 'GET', '/audit-log' ),
		);
	}
}
