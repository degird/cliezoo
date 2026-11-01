<?php
/**
 * Custom fields on every object.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\CustomFields;

use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Field;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Definitions (`custom_fields`) + typed values (`custom_field_values`,
 * indexed text/number/date columns so every field is filterable).
 */
final class CustomFieldsModule extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'custom_fields';
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
				'custom_fields',
				array(
					'entity'             => Field::text( 60 )->required()->filterable(),
					'field_key'          => Field::text( 60 ),
					'label'              => Field::text()->required()->searchable(),
					'type'               => Field::text( 30 )->required()->filterable(),
					'options'            => Field::json(),
					'required'           => Field::bool(),
					'default_value'      => Field::text( 500 ),
					'help'               => Field::text( 500 ),
					'visible_to_clients' => Field::bool()->filterable(),
					'show_icon'          => Field::bool(),
					'icon'               => Field::text( 60 ),
					'color'              => Field::color(),
					'scope_type'         => Field::enum( array( 'global', 'board', 'task' ) )->default( 'global' ),
					'scope_id'           => Field::int()->hidden(),
					// The board or task a field is kept for, by uid; empty for all.
					'scope_of'           => Field::text( 26 )->label( __( 'Kept for', 'cliezoo' ) ),
					'position'           => Field::int()->default( 0 )->sortable(),
				)
			) )->with(
				array(
					'unique'       => array( 'entity_key' => array( 'entity', 'field_key', 'scope_type', 'scope_id' ) ),
					'indexes'      => array( 'entity_position' => array( 'entity', 'position' ) ),
					'permission'   => 'settings',
					'title_field'  => 'label',
					'default_sort' => 'position',
					'label'        => __( 'Custom field', 'cliezoo' ),
					'plural_label' => __( 'Custom fields', 'cliezoo' ),
				)
			),
			( new Entity(
				'custom_field_values',
				array(
					'object_type'  => Field::text( 60 )->required(),
					'object_id'    => Field::int()->required(),
					'field_id'     => Field::int()->required(),
					'value_text'   => Field::longtext(),
					'value_short'  => Field::text( 190 ),
					'value_number' => Field::decimal( 6 ),
					'value_date'   => Field::datetime(),
				)
			) )->with(
				array(
					'public'      => false,
					'soft_delete' => false,
					'activity'    => false,
					'unique'      => array( 'field_object' => array( 'field_id', 'object_id' ) ),
					'indexes'     => array(
						'object'       => array( 'object_type', 'object_id' ),
						'field_short'  => array( 'field_id', 'value_short' ),
						'field_number' => array( 'field_id', 'value_number' ),
						'field_date'   => array( 'field_id', 'value_date' ),
					),
				)
			),
		);
	}

	/**
	 * Registers hooks.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( FieldTypeRegistry::class, static fn(): FieldTypeRegistry => new FieldTypeRegistry() );
		$plugin->container()->set( CustomFields::class, static fn(): CustomFields => new CustomFields( $plugin ) );
		$service = static fn(): CustomFields => $plugin->get( CustomFields::class );

		add_filter(
			'cliezoo_record_before_save',
			static function ( array $data, Entity $entity, array $input, ?array $row, Actor $actor ) use ( $service ): array {
				if ( $entity->custom_fields ) {
					$service()->validate( $entity, $input, $row, $actor );
				}
				return $data;
			},
			10,
			5
		);
		add_action(
			'cliezoo_record_saved',
			static function ( Entity $entity, array $row, array $input, Actor $actor ) use ( $service ): void {
				if ( $entity->custom_fields && isset( $input['custom'] ) && is_array( $input['custom'] ) ) {
					$service()->save( $entity, $row, $input['custom'], $actor );
				}
			},
			10,
			4
		);
		add_filter(
			'cliezoo_present',
			static function ( array $out, Entity $entity, array $rows, Actor $actor ) use ( $service ): array {
				return $entity->custom_fields ? $service()->present( $out, $entity, $rows, $actor ) : $out;
			},
			10,
			4
		);
		add_filter(
			'cliezoo_custom_filter_builder',
			static function ( $builder, string $entity ) use ( $service, $plugin ) {
				if ( ! $plugin->entities()->get( $entity )->custom_fields ) {
					return $builder;
				}
				return static fn( string $key, string $op, $value ) => $service()->filter( $entity, $key, $op, $value );
			},
			10,
			2
		);
		add_action(
			'cliezoo_record_purging',
			static function ( Entity $entity, array $row ) use ( $plugin ): void {
				if ( $entity->custom_fields ) {
					$plugin->service( 'custom_field_values' )->repository()->delete_where(
						array(
							'object_type' => $entity->name,
							'object_id'   => (int) $row['id'],
						)
					);
				}
			},
			10,
			2
		);
		add_filter(
			'cliezoo_custom_fields_before_create',
			static fn( array $data ): array => $service()->prepare_definition( $data ),
			10,
			1
		);
		add_filter(
			'cliezoo_custom_fields_before_update',
			static fn( array $data, array $row ): array => $service()->prepare_definition( $data, $row ),
			10,
			2
		);
		add_action(
			'cliezoo_register_actions',
			static function ( \Cliezoo\Core\Actions\ActionRegistry $registry ) use ( $plugin ): void {
				$registry->add(
					\Cliezoo\Core\Actions\Op::make( 'custom_fields.types', __( 'Custom field types', 'cliezoo' ) )
						->allow( static fn( Actor $a ): bool => $a->is_staff() )
						->run(
							static function () use ( $plugin ): array {
								$out = array();
								foreach ( $plugin->get( FieldTypeRegistry::class )->labels() as $id => $label ) {
									$out[] = array(
										'id'      => $id,
										'label'   => $label,
										'choices' => in_array( $id, array( 'list', 'multilist' ), true ),
									);
								}
								return $out;
							}
						)
						->rest( 'GET', '/custom-fields/types' )
						->readonly()
				);
			}
		);
		add_action( 'cliezoo_custom_fields_created', static fn() => $service()->flush() );
		add_action( 'cliezoo_custom_fields_updated', static fn() => $service()->flush() );
		add_action( 'cliezoo_custom_fields_trashed', static fn() => $service()->flush() );
		add_action( 'cliezoo_custom_fields_restored', static fn() => $service()->flush() );
	}
}
