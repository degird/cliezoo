<?php
/**
 * Resolves "any record" references (type + uid) with a permission check.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Data;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notes, to-dos, comments, reminders, favourites, sharing and activity point
 * at any record by (object_type, uid). This is the single place that turns
 * such a pointer into a row the actor is allowed to see.
 */
final class ObjectRef {

	/**
	 * JSON schema for an object reference input pair.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		return array(
			'object_type' => array(
				'type'     => 'string',
				'pattern'  => '^[a-z_]{2,40}$',
				'required' => true,
			),
			'object'      => array(
				'type'     => 'string',
				'pattern'  => '^[0-9A-HJKMNP-TV-Z]{26}$',
				'required' => true,
			),
		);
	}

	/**
	 * Loads a record the actor may act on.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param Actor  $actor  Actor.
	 * @param string $type   Entity name.
	 * @param string $uid    Record uid.
	 * @param string $action Policy action (view by default).
	 * @return array{0: Entity, 1: array<string, mixed>}
	 * @throws ActionError When unknown, missing or not allowed.
	 */
	public static function resolve( Plugin $plugin, Actor $actor, string $type, string $uid, string $action = Policy::VIEW ): array {
		if ( ! $plugin->entities()->has( $type ) ) {
			throw ActionError::not_found();
		}
		$entity = $plugin->entities()->get( $type );
		if ( ! $entity->public || $entity->object_attached ) {
			throw ActionError::not_found();
		}
		$row = $plugin->service( $type )->load( $actor, $uid, $action );
		return array( $entity, $row );
	}

	/**
	 * Uid of a row by entity + id (for presenting pointers).
	 *
	 * @param Plugin $plugin Plugin.
	 * @param string $type   Entity.
	 * @param int[]  $ids    Ids.
	 * @return array<int, array{uid: string, label: string, number: ?string}>
	 */
	public static function refs( Plugin $plugin, string $type, array $ids ): array {
		if ( ! $plugin->entities()->has( $type ) ) {
			return array();
		}
		return ( new Repository( $plugin->entities()->get( $type ), $plugin->entities() ) )->refs( $ids );
	}
}
