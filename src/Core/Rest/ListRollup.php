<?php
/**
 * A list's status roll-up.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Rest;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\EntityAction;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;
use Cliezoo\Platform\Taxonomies\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * `GET /rollup`: how many records of a list are in each status — the
 * "4 active · 2 on hold · 1 planned" line above a list (cliezoo.md §6.2).
 *
 * It counts exactly what the list shows: the same filter, search and
 * state, inside the same Policy scope, after the same list-query hooks. So
 * it can never count a record the person could not see in the list itself,
 * and one GROUP BY is all it costs.
 */
final class ListRollup {

	/**
	 * Registers the action.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	public static function register( ActionRegistry $registry, Plugin $plugin ): void {
		$registry->add(
			Op::make( 'lists.rollup', __( 'Count a list by status', 'cliezoo' ) )
				->describe( __( 'Counts the records a list would show, per value of one field such as status or stage.', 'cliezoo' ) )
				->input(
					array(
						'entity' => array(
							'type'     => 'string',
							'pattern'  => '^[a-z_]{2,40}$',
							'required' => true,
						),
						'by'     => array(
							'type'      => 'string',
							'maxLength' => 60,
							'default'   => 'status',
						),
						'filter' => array( 'type' => array( 'object', 'string' ) ),
						'q'      => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'state'  => array(
							'type' => 'string',
							'enum' => array( 'active', 'archived', 'trash', 'all' ),
						),
					)
				)
				->allow( static fn( Actor $actor ): bool => ! $actor->is_guest() )
				->run( static fn( Actor $actor, array $in ): array => self::run( $plugin, $actor, $in ) )
				->rest( 'GET', '/rollup' )
				->readonly()
		);
	}

	/**
	 * Counts.
	 *
	 * @param Plugin               $plugin Plugin.
	 * @param Actor                $actor  Actor.
	 * @param array<string, mixed> $in     Input.
	 * @return array{by: string, total: int, values: array<int, array<string, mixed>>}
	 * @throws ActionError When the list or the field cannot be counted.
	 */
	private static function run( Plugin $plugin, Actor $actor, array $in ): array {
		$name = (string) $in['entity'];
		if ( ! $plugin->entities()->has( $name ) ) {
			throw ActionError::not_found();
		}
		$entity = $plugin->entities()->get( $name );
		if ( ! $entity->public || $entity->object_attached || 'self' === $entity->permission || ! $plugin->policy()->can( $actor, Policy::VIEW, $entity ) ) {
			throw ActionError::not_found();
		}
		$by     = (string) ( $in['by'] ?? 'status' );
		$column = $entity->column_for_key( $by );
		$field  = $column ? $entity->field( $column ) : null;
		// Only a field that sorts records into a few kinds, that the list
		// may filter on anyway, and that says nothing this person may not
		// read.
		if ( ! $column || ! $field || $field->hidden || ! $field->filterable || ! in_array( $field->type, array( 'enum', 'bool' ), true ) || ( $field->finance && ! $actor->sees_finance() ) ) {
			throw ActionError::validation( __( 'This list cannot be counted by that field.', 'cliezoo' ) );
		}
		$query = EntityAction::list_query( $in );
		if ( 'trash' === $query->state && ! $actor->is_staff() ) {
			throw ActionError::forbidden();
		}
		/** This action is documented in src/Core/Data/EntityService.php */
		do_action( 'cliezoo_' . $entity->name . '_list_query', $query, $actor );
		$counts = $plugin->service( $name )->repository()->rollup( $query, $plugin->policy()->scope( $actor, $entity ), $column );

		// In the order the field gives its values, then anything else.
		$labels = self::labels( $plugin, $field->list );
		$order  = '' !== $field->list ? array_keys( $labels ) : ( 'bool' === $field->type ? array( '1', '0' ) : $field->options );
		$values = array();
		$total  = 0;
		foreach ( array_merge( $order, array_diff( array_keys( $counts ), $order ) ) as $value ) {
			$value = (string) $value;
			if ( empty( $counts[ $value ]['count'] ) ) {
				continue;
			}
			$count    = (int) $counts[ $value ]['count'];
			$total   += $count;
			$values[] = array(
				'value' => $value,
				'label' => $labels[ $value ]['label'] ?? null,
				'color' => $labels[ $value ]['color'] ?? null,
				'count' => $count,
			);
		}
		return array(
			'by'     => $entity->input_key( $column ),
			'total'  => $total,
			'values' => $values,
		);
	}

	/**
	 * The workspace's words and colours for an editable list's values.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param string $key    List key ('' for a fixed enum).
	 * @return array<string, array{label: string, color: ?string}>
	 */
	private static function labels( Plugin $plugin, string $key ): array {
		if ( '' === $key || ! $plugin->container()->has( Taxonomies::class ) ) {
			return array();
		}
		$out = array();
		foreach ( $plugin->get( Taxonomies::class )->values( $key ) as $option ) {
			$out[ (string) $option['value'] ] = array(
				'label' => (string) $option['label'],
				'color' => empty( $option['color'] ) ? null : (string) $option['color'],
			);
		}
		return $out;
	}
}
