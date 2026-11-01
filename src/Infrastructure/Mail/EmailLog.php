<?php
/**
 * Email log listing.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Mail;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Data\ListQuery;
use Cliezoo\Core\Data\ObjectRef;
use Cliezoo\Core\Data\Serializer;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email log per record or client (visible to staff who can see the record);
 * the whole log needs an admin.
 */
final class EmailLog {

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
	 * Lists log rows.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    Input.
	 * @return array<string, mixed>
	 * @throws ActionError When the whole log is requested by a non-admin.
	 */
	public function list( Actor $actor, array $in ): array {
		$query         = new ListQuery();
		$query->limit  = (int) ( $in['limit'] ?? 50 );
		$query->cursor = (string) ( $in['cursor'] ?? '' );
		$query->sort   = '-created_at';
		if ( ! empty( $in['object_type'] ) && ! empty( $in['object'] ) ) {
			list( $entity, $row ) = ObjectRef::resolve( $this->plugin, $actor, (string) $in['object_type'], (string) $in['object'] );
			$query->where( 't.object_type = %s AND t.object_id = %d', array( $entity->name, (int) $row['id'] ) );
		} elseif ( ! empty( $in['client'] ) ) {
			$row = ObjectRef::resolve( $this->plugin, $actor, 'clients', (string) $in['client'] )[1];
			$query->where( 't.client_id = %d', array( (int) $row['id'] ) );
		} elseif ( ! $actor->is_admin() ) {
			throw ActionError::forbidden();
		}
		if ( ! empty( $in['status'] ) ) {
			$query->where( 't.status = %s', array( (string) $in['status'] ) );
		}
		$result = $this->plugin->service( 'email_log' )->repository()->list( $query, null );
		$data   = array();
		foreach ( $result['rows'] as $row ) {
			$data[] = array(
				'uid'        => (string) $row['uid'],
				'to'         => (string) $row['to_email'],
				'subject'    => (string) $row['subject'],
				'template'   => $row['template'],
				'category'   => (string) $row['category'],
				'status'     => (string) $row['status'],
				'error'      => $row['error'],
				'sent_at'    => Serializer::iso( $row['sent_at'] ),
				'opened_at'  => Serializer::iso( $row['opened_at'] ),
				'created_at' => Serializer::iso( $row['created_at'] ),
			);
		}
		return array(
			'data' => $data,
			'meta' => array(
				'next_cursor' => $result['next_cursor'],
				'has_more'    => $result['has_more'],
			),
		);
	}
}
