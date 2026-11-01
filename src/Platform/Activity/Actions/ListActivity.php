<?php
/**
 * List activity for a record, a client, a project or the workspace feed.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Activity\Actions;

use Cliezoo\Core\Actions\Action;
use Cliezoo\Core\Data\ObjectRef;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Activity\ActivityLog;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /activity` — the activity timeline.
 */
final class ListActivity extends Action {

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
		return 'activity.list';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'List activity', 'cliezoo' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Recent changes and events. Pass object_type + object for one record, or client / project for everything about them. Without filters returns the workspace feed.', 'cliezoo' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$uid = array(
			'type'    => 'string',
			'pattern' => '^[0-9A-HJKMNP-TV-Z]{26}$',
		);
		return array(
			'type'       => 'object',
			'properties' => array(
				'object_type' => array(
					'type'    => 'string',
					'pattern' => '^[a-z_]{2,40}$',
				),
				'object'      => $uid,
				'client'      => $uid,
				'project'     => $uid,
				'member'      => $uid,
				'action'      => array(
					'type'      => 'string',
					'maxLength' => 40,
				),
				'cursor'      => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'limit'       => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 30,
				),
			),
		);
	}

	/**
	 * Any logged-in member may read activity they can see (checked per scope).
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $input Input.
	 * @return bool
	 */
	public function permission( Actor $actor, array $input ): bool {
		return ! $actor->is_guest();
	}

	/**
	 * Lists activity.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( Actor $actor, array $input ) {
		$filter = array();
		if ( ! empty( $input['object_type'] ) && ! empty( $input['object'] ) ) {
			list( $entity, $row )  = ObjectRef::resolve( $this->plugin, $actor, (string) $input['object_type'], (string) $input['object'] );
			$filter['object_type'] = $entity->name;
			$filter['object_id']   = (int) $row['id'];
		} elseif ( ! empty( $input['object_type'] ) && $this->plugin->entities()->has( (string) $input['object_type'] ) ) {
			// One kind of thing, inside a client's or a project's feed.
			$filter['object_type'] = (string) $input['object_type'];
		}
		if ( ! empty( $input['member'] ) ) {
			// What one person did.
			$member              = $this->plugin->service( 'members' )->repository()->find_by_uid( (string) $input['member'] );
			$filter['member_id'] = $member ? (int) $member['id'] : 0;
		}
		if ( ! empty( $input['client'] ) ) {
			$row                 = ObjectRef::resolve( $this->plugin, $actor, 'clients', (string) $input['client'] )[1];
			$filter['client_id'] = (int) $row['id'];
		}
		if ( ! empty( $input['project'] ) ) {
			$row                  = ObjectRef::resolve( $this->plugin, $actor, 'projects', (string) $input['project'] )[1];
			$filter['project_id'] = (int) $row['id'];
		}
		if ( ! empty( $input['action'] ) ) {
			$filter['action'] = (string) $input['action'];
		}
		return $this->plugin->get( ActivityLog::class )->list( $actor, $filter, (string) ( $input['cursor'] ?? '' ), (int) ( $input['limit'] ?? 30 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function meta(): array {
		return array(
			'readonly'   => true,
			'idempotent' => true,
			'rest'       => array( 'GET', '/activity' ),
		);
	}
}
