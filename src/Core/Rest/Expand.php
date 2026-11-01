<?php
/**
 * `expand` for related records on REST reads.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Rest;

use Cliezoo\Core\Actions\Action;
use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\EntityAction;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * `GET /clients?expand=owner` or `GET /projects/{uid}?expand=client,manager`
 * turns the `{uid, label}` chip of a reference into the whole related
 * record, as its own read would present it.
 *
 * Bounded and checked: only a record type's own reference fields, at most
 * {@see self::MAX_FIELDS} of them, one level deep, one batch per field; each
 * related record is shown only when the caller may open it, and one they may
 * not stays the chip it was. Nothing here is a way round the Policy.
 */
final class Expand {

	/** References one call may expand. */
	public const MAX_FIELDS = 5;

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
	 * The fields asked for, checked against the record type.
	 *
	 * @param Action $action The action being run.
	 * @param mixed  $asked  The `expand` parameter.
	 * @return array{0: Entity, 1: array<string, string>}|null Entity and input key => target entity; null when nothing to do.
	 * @throws ActionError When a field cannot be expanded.
	 */
	public function plan( Action $action, $asked ): ?array {
		$asked = is_array( $asked ) ? implode( ',', array_map( 'strval', $asked ) ) : (string) $asked;
		$keys  = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $asked ) ) ) ) );
		if ( ! $keys ) {
			return null;
		}
		$parts = explode( '.', $action->name() );
		if ( ! $action instanceof EntityAction || 2 !== count( $parts ) || ! in_array( $parts[1], array( 'list', 'get' ), true ) || ! $this->plugin->entities()->has( $parts[0] ) ) {
			throw ActionError::validation( __( 'Related records can only be expanded when reading records.', 'cliezoo' ), array( 'expand' => __( 'Not available here.', 'cliezoo' ) ) );
		}
		if ( count( $keys ) > self::MAX_FIELDS ) {
			/* translators: %d: how many references may be expanded at once. */
			throw ActionError::validation( sprintf( __( 'Expand at most %d related records at once.', 'cliezoo' ), self::MAX_FIELDS ), array( 'expand' => __( 'Too many.', 'cliezoo' ) ) );
		}
		$entity = $this->plugin->entities()->get( $parts[0] );
		$refs   = $this->expandable( $entity );
		$plan   = array();
		foreach ( $keys as $key ) {
			if ( ! isset( $refs[ $key ] ) ) {
				/* translators: %s: the field name that was asked for. */
				throw ActionError::validation( sprintf( __( '"%s" is not a related record that can be expanded.', 'cliezoo' ), $key ), array( 'expand' => __( 'Unknown reference.', 'cliezoo' ) ) );
			}
			$plan[ $key ] = $refs[ $key ];
		}
		return array( $entity, $plan );
	}

	/**
	 * A record type's expandable references: input key => target entity.
	 *
	 * @param Entity $entity Entity.
	 * @return array<string, string>
	 */
	public function expandable( Entity $entity ): array {
		$out = array( 'created_by' => 'members' );
		foreach ( $entity->fields as $column => $field ) {
			if ( $field->hidden || ! in_array( $field->type, array( 'ref', 'user' ), true ) || '' === (string) $field->ref ) {
				continue;
			}
			if ( $this->plugin->entities()->has( (string) $field->ref ) && $this->plugin->entities()->get( (string) $field->ref )->public ) {
				$out[ $entity->input_key( $column ) ] = (string) $field->ref;
			}
		}
		return $out;
	}

	/**
	 * Replaces chips with the related records the actor may see.
	 *
	 * @param array<string, mixed>  $body  Response body (`data` is a record or a list).
	 * @param array<string, string> $plan  Input key => target entity.
	 * @param Actor                 $actor Actor.
	 * @return array<string, mixed>
	 */
	public function apply( array $body, array $plan, Actor $actor ): array {
		if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return $body;
		}
		$single = ! array_is_list( $body['data'] );
		$rows   = $single ? array( $body['data'] ) : $body['data'];
		foreach ( $plan as $key => $target ) {
			$uids = array();
			foreach ( $rows as $row ) {
				$chip = is_array( $row ) ? ( $row[ $key ] ?? null ) : null;
				if ( is_array( $chip ) && ! empty( $chip['uid'] ) ) {
					$uids[] = (string) $chip['uid'];
				}
			}
			$found = $this->records( $target, array_values( array_unique( $uids ) ), $actor );
			foreach ( $rows as $i => $row ) {
				$chip = is_array( $row ) ? ( $row[ $key ] ?? null ) : null;
				if ( is_array( $chip ) && isset( $found[ (string) ( $chip['uid'] ?? '' ) ] ) ) {
					$rows[ $i ][ $key ] = $found[ (string) $chip['uid'] ];
				}
			}
		}
		$body['data']             = $single ? $rows[0] : $rows;
		$body['meta']             = isset( $body['meta'] ) && is_array( $body['meta'] ) ? $body['meta'] : array();
		$body['meta']['expanded'] = array_keys( $plan );
		return $body;
	}

	/**
	 * Presented records of one type the actor may open, by uid.
	 *
	 * @param string   $target Entity name.
	 * @param string[] $uids   Uids (at most one page's worth).
	 * @param Actor    $actor  Actor.
	 * @return array<string, array<string, mixed>>
	 */
	private function records( string $target, array $uids, Actor $actor ): array {
		if ( ! $uids || ! $this->plugin->entities()->has( $target ) ) {
			return array();
		}
		$entity = $this->plugin->entities()->get( $target );
		$policy = $this->plugin->policy();
		if ( ! $policy->can( $actor, Policy::VIEW, $entity ) ) {
			return array();
		}
		$rows    = $this->plugin->service( $target )->repository()->where( array( 'uid' => array_slice( $uids, 0, 200 ) ) );
		$visible = array();
		foreach ( $rows as $row ) {
			if ( ( ! $entity->soft_delete || empty( $row['deleted_at'] ) ) && $policy->can( $actor, Policy::VIEW, $entity, $row ) ) {
				$visible[] = $row;
			}
		}
		$out = array();
		foreach ( $this->plugin->serializer()->present_many( $entity, $visible, $actor ) as $record ) {
			$out[ (string) $record['uid'] ] = $record;
		}
		return $out;
	}
}
