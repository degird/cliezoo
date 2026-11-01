<?php
/**
 * Entity metadata for generic screens (lists, filters, forms).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Rest;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Field;
use Cliezoo\Core\Data\FieldLabels;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\CustomFields\CustomFields;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /meta/entities/{entity}`: the fields a person may see on an entity
 * (labels, types, options, sort/filter/search flags), its custom fields and
 * what they may do with it. Hidden and, without finance access, financial
 * fields are never listed. It only shapes the UI; every call is re-checked.
 */
final class MetaActions {

	/**
	 * Registers the action.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	public static function register( ActionRegistry $registry, Plugin $plugin ): void {
		$registry->add(
			Op::make( 'entity.meta', __( 'Describe a record type', 'cliezoo' ) )
				->input(
					array(
						'entity' => array(
							'type'     => 'string',
							'pattern'  => '^[a-z_]{2,40}$',
							'required' => true,
						),
					)
				)
				->allow( static fn( Actor $a ): bool => ! $a->is_guest() )
				->run( static fn( Actor $a, array $in ): array => self::describe( $plugin, $a, (string) $in['entity'] ) )
				->rest( 'GET', '/meta/entities/(?P<entity>[a-z_]{2,40})' )
				->readonly()
		);
		$registry->add(
			Op::make( 'entities.list', __( 'Record types', 'cliezoo' ) )
				->input(
					array(
						'custom_fields' => array( 'type' => 'boolean' ),
					)
				)
				->allow( static fn( Actor $a ): bool => ! $a->is_guest() )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin ): array {
						$out = array();
						foreach ( $plugin->entities()->all() as $name => $entity ) {
							if ( ! $entity->public || ! $plugin->policy()->can( $actor, Policy::VIEW, $entity ) ) {
								continue;
							}
							if ( ! empty( $in['custom_fields'] ) && ! $entity->custom_fields ) {
								continue;
							}
							$out[] = array(
								'name'          => $name,
								'label'         => '' !== $entity->label ? $entity->label : $name,
								'plural_label'  => '' !== $entity->plural_label ? $entity->plural_label : $name,
								'custom_fields' => $entity->custom_fields,
								'path'          => $entity->path,
								'icon'          => $entity->icon,
							);
						}
						usort( $out, static fn( array $a, array $b ): int => strcmp( (string) $a['plural_label'], (string) $b['plural_label'] ) );
						return $out;
					}
				)
				->rest( 'GET', '/meta/entities' )
				->readonly()
		);
	}

	/**
	 * Metadata for one entity.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param Actor  $actor  Actor.
	 * @param string $name   Entity name.
	 * @return array<string, mixed>
	 * @throws ActionError When unknown or not visible.
	 */
	public static function describe( Plugin $plugin, Actor $actor, string $name ): array {
		if ( ! $plugin->entities()->has( $name ) ) {
			throw ActionError::not_found();
		}
		$entity = $plugin->entities()->get( $name );
		$policy = $plugin->policy();
		if ( ! $entity->public || ! $policy->can( $actor, Policy::VIEW, $entity ) ) {
			throw ActionError::not_found();
		}
		$fields = array();
		// An entity with a series is given its own number column rather
		// than declaring one, so it has to be described here or the number
		// — the thing a client and an accountant both identify the
		// document by — cannot be shown as a column, sorted or searched.
		// It cannot be filtered: there is no field behind it to compile a
		// filter against, and offering one the server would refuse is
		// worse than not offering it.
		if ( '' !== $entity->series ) {
			$fields[] = array(
				'key'        => 'number',
				'label'      => __( 'Number', 'cliezoo' ),
				'type'       => 'text',
				'filterable' => false,
				'sortable'   => true,
				'searchable' => true,
				'readonly'   => true,
				'required'   => false,
			);
		}
		foreach ( $entity->fields as $column => $field ) {
			if ( $field->hidden || ( $field->finance && ! $actor->sees_finance() ) ) {
				continue;
			}
			$fields[] = self::field( $column, $field );
		}
		foreach ( array( 'created_at', 'updated_at' ) as $system ) {
			$fields[] = array(
				'key'        => $system,
				'label'      => 'created_at' === $system ? __( 'Created', 'cliezoo' ) : __( 'Updated', 'cliezoo' ),
				'type'       => 'datetime',
				'filterable' => true,
				'sortable'   => true,
				'searchable' => false,
				'readonly'   => true,
				'required'   => false,
				'system'     => true,
			);
		}
		$custom = array();
		if ( $plugin->container()->has( CustomFields::class ) ) {
			foreach ( $plugin->get( CustomFields::class )->definitions( $name ) as $key => $definition ) {
				if ( $actor->is_client() && empty( $definition['visible_to_clients'] ) ) {
					continue;
				}
				// A field kept for one board or task is only described to
				// somebody who can see that board or task.
				$scope_of = self::scope_uid( $plugin, $actor, (string) ( $definition['scope_type'] ?? 'global' ), (int) ( $definition['scope_id'] ?? 0 ) );
				if ( null === $scope_of ) {
					continue;
				}
				$custom[] = array(
					'key'      => 'custom.' . $key,
					'label'    => (string) $definition['label'],
					'type'     => (string) $definition['type'],
					'options'  => (array) $definition['options'],
					'required' => ! empty( $definition['required'] ),
					'help'     => (string) ( $definition['help'] ?? '' ),
					// Shown beside the value where the field asks for it.
					'icon'     => ! empty( $definition['show_icon'] ) ? (string) ( $definition['icon'] ?? '' ) : '',
					'color'    => ! empty( $definition['show_icon'] ) ? (string) ( $definition['color'] ?? '' ) : '',
					// Where it applies: everywhere, one board, or one task.
					'scope'    => (string) ( $definition['scope_type'] ?? 'global' ),
					'scope_of' => $scope_of,
				);
			}
		}
		$verbs = (array) apply_filters( 'cliezoo_entity_verbs', array( 'list', 'get', 'create', 'update', 'trash', 'archive', 'unarchive', 'restore', 'purge', 'bulk' ), $entity );
		return array(
			'name'          => $name,
			'label'         => '' !== $entity->label ? $entity->label : $name,
			'plural_label'  => '' !== $entity->plural_label ? $entity->plural_label : $name,
			'title_field'   => $entity->title_field,
			'default_sort'  => $entity->default_sort,
			'searchable'    => $entity->searchable,
			'soft_delete'   => $entity->soft_delete,
			'numbered'      => '' !== $entity->series,
			'custom_fields' => $entity->custom_fields,
			'path'          => $entity->path,
			'icon'          => $entity->icon,
			'fields'        => $fields,
			'custom'        => $custom,
			'can'           => array(
				'create'  => in_array( 'create', $verbs, true ) && $policy->can( $actor, Policy::CREATE, $entity ),
				'update'  => in_array( 'update', $verbs, true ) && $policy->can( $actor, Policy::UPDATE, $entity ),
				'delete'  => in_array( 'trash', $verbs, true ) && $policy->can( $actor, Policy::DELETE, $entity ),
				'archive' => $entity->soft_delete && in_array( 'archive', $verbs, true ) && $policy->can( $actor, Policy::ARCHIVE, $entity ),
				'bulk'    => in_array( 'bulk', $verbs, true ),
				// Deleting for good is an administrator's, and the trash is
				// not worth showing a button nobody may press.
				'purge'   => $entity->soft_delete && in_array( 'purge', $verbs, true ) && $policy->can( $actor, Policy::PURGE, $entity ),
			),
		);
	}

	/**
	 * One field.
	 *
	 * @param string $column Column.
	 * @param Field  $field  Field.
	 * @return array<string, mixed>
	 */
	private static function field( string $column, Field $field ): array {
		$is_ref = in_array( $field->type, array( 'ref', 'user' ), true );
		$key    = $is_ref && str_ends_with( $column, '_id' ) ? substr( $column, 0, -3 ) : $column;
		return array_filter(
			array(
				'key'        => $key,
				'label'      => '' !== $field->label ? $field->label : FieldLabels::for_column( $key ),
				'type'       => $field->type,
				'options'    => $field->options ? array_values( $field->options ) : null,
				'list'       => '' !== $field->list ? $field->list : null,
				'ref'        => $is_ref ? $field->ref : null,
				'currency'   => 'money' === $field->type ? $field->currency_field : null,
				'filterable' => $field->filterable,
				'sortable'   => $field->sortable,
				'searchable' => $field->searchable,
				'readonly'   => $field->readonly,
				'required'   => $field->required,
				'finance'    => $field->finance,
			),
			static fn( $value ): bool => null !== $value
		);
	}

	/**
	 * The uid of the board or task a custom field is kept for, or '' when
	 * it is for everything; null when the reader may not see that board or
	 * task, and so is not told about the field at all.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param Actor  $actor  Who is asking.
	 * @param string $scope  global|board|task.
	 * @param int    $id     The board's or task's id.
	 * @return string|null
	 */
	private static function scope_uid( Plugin $plugin, Actor $actor, string $scope, int $id ): ?string {
		$entity = array(
			'board' => 'boards',
			'task'  => 'tasks',
		)[ $scope ] ?? '';
		if ( '' === $entity ) {
			return '';
		}
		if ( ! $id || ! $plugin->entities()->has( $entity ) ) {
			return null;
		}
		$row = $plugin->service( $entity )->repository()->find( $id );
		if ( ! $row || ! empty( $row['deleted_at'] ) || ! $plugin->policy()->can( $actor, Policy::VIEW, $plugin->entities()->get( $entity ), $row ) ) {
			return null;
		}
		return (string) $row['uid'];
	}
}
