<?php
/**
 * Custom field values service.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\CustomFields;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Validates, stores, presents and filters custom field values.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class CustomFields {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Definitions per entity (request cache).
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $definitions = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Types registry.
	 *
	 * @return FieldTypeRegistry
	 */
	private function types(): FieldTypeRegistry {
		return $this->plugin->get( FieldTypeRegistry::class );
	}

	/**
	 * Active definitions for an entity, keyed by field key (global scope + optional scope).
	 *
	 * @param string $entity Entity.
	 * @return array<string, array<string, mixed>>
	 */
	public function definitions( string $entity ): array {
		if ( isset( $this->definitions[ $entity ] ) ) {
			return $this->definitions[ $entity ];
		}
		$cached = wp_cache_get( 'cf_' . $entity, 'cliezoo' );
		if ( ! is_array( $cached ) ) {
			$rows   = $this->plugin->service( 'custom_fields' )->repository()->where( array( 'entity' => $entity ), 'position' );
			$cached = array();
			foreach ( $rows as $row ) {
				$row['options']                       = null === $row['options'] ? array() : (array) json_decode( (string) $row['options'], true );
				$cached[ (string) $row['field_key'] ] = $row;
			}
			wp_cache_set( 'cf_' . $entity, $cached, 'cliezoo' );
		}
		$this->definitions[ $entity ] = $cached;
		return $cached;
	}

	/**
	 * Whether a field applies to a record: a field for every record always
	 * does; one kept for a board applies to the tasks on it, and one kept
	 * for a task to that task.
	 *
	 * @param array<string, mixed>      $definition Definition.
	 * @param array<string, mixed>|null $row        The record, or null while it is made.
	 * @param array<string, mixed>      $input      What was sent, while it is made.
	 * @return bool
	 */
	public function applies( array $definition, ?array $row, array $input = array() ): bool {
		$scope = (string) ( $definition['scope_type'] ?? 'global' );
		if ( 'board' === $scope ) {
			// A task being moved is judged by the board it is going to.
			$board = (string) ( $input['board'] ?? '' );
			if ( null !== $row && '' === $board ) {
				return (int) ( $row['board_id'] ?? 0 ) === (int) ( $definition['scope_id'] ?? 0 );
			}
			return '' !== $board && (string) ( $definition['scope_of'] ?? '' ) === $board;
		}
		if ( 'task' === $scope ) {
			return null !== $row && (int) ( $definition['scope_id'] ?? 0 ) === (int) $row['id'];
		}
		return true;
	}

	/**
	 * Clears definition caches.
	 *
	 * @return void
	 */
	public function flush(): void {
		foreach ( array_keys( $this->plugin->entities()->all() ) as $name ) {
			wp_cache_delete( 'cf_' . $name, 'cliezoo' );
		}
		$this->definitions = array();
	}

	/**
	 * Normalises a definition before saving (key from label, known type, clean options).
	 *
	 * @param array<string, mixed>      $data Column data.
	 * @param array<string, mixed>|null $row  Current row on update.
	 * @return array<string, mixed>
	 * @throws ActionError On unknown types or entities.
	 */
	public function prepare_definition( array $data, ?array $row = null ): array {
		// A field for one board or one task names it by uid; the id is
		// worked out here, and a field for everything names nothing.
		// Saved again as it was, a field keeps its scope even when the board
		// or task it is kept for has since gone to the trash.
		$same = null !== $row
			&& (string) ( $data['scope_type'] ?? $row['scope_type'] ?? 'global' ) === (string) ( $row['scope_type'] ?? 'global' )
			&& (string) ( $data['scope_of'] ?? $row['scope_of'] ?? '' ) === (string) ( $row['scope_of'] ?? '' );
		if ( $same ) {
			unset( $data['scope_type'], $data['scope_of'] );
		}
		if ( array_key_exists( 'scope_type', $data ) || array_key_exists( 'scope_of', $data ) ) {
			$scope  = (string) ( $data['scope_type'] ?? ( $row['scope_type'] ?? 'global' ) );
			$target = array(
				'board' => 'boards',
				'task'  => 'tasks',
			)[ $scope ] ?? '';
			if ( '' === $target ) {
				$data['scope_type'] = 'global';
				$data['scope_id']   = null;
				$data['scope_of']   = '';
			} else {
				// Only a task's fields can be kept for one board or one task.
				if ( 'tasks' !== (string) ( $data['entity'] ?? ( $row['entity'] ?? '' ) ) ) {
					throw ActionError::validation( __( 'Only task fields can be kept for one board or task.', 'cliezoo' ), array( 'scope_type' => __( 'Only for tasks.', 'cliezoo' ) ) );
				}
				$found = $this->plugin->service( $target )->repository()->find_by_uid( (string) ( $data['scope_of'] ?? ( $row['scope_of'] ?? '' ) ) );
				if ( ! $found ) {
					throw ActionError::validation( __( 'Choose the board or task this field is for.', 'cliezoo' ), array( 'scope_of' => __( 'Choose one.', 'cliezoo' ) ) );
				}
				$data['scope_id'] = (int) $found['id'];
				$data['scope_of'] = (string) $found['uid'];
			}
		}
		$type = (string) ( $data['type'] ?? ( $row['type'] ?? '' ) );
		if ( null === $this->types()->get( $type ) ) {
			throw ActionError::validation( __( 'Unknown field type.', 'cliezoo' ), array( 'type' => __( 'Unknown field type.', 'cliezoo' ) ) );
		}
		$entity = (string) ( $data['entity'] ?? ( $row['entity'] ?? '' ) );
		if ( ! $this->plugin->entities()->has( $entity ) || ! $this->plugin->entities()->get( $entity )->custom_fields ) {
			throw ActionError::validation( __( 'Custom fields are not available for this record type.', 'cliezoo' ) );
		}
		$made = null === $row && empty( $data['field_key'] );
		if ( $made ) {
			$data['field_key'] = substr( sanitize_key( str_replace( ' ', '_', (string) ( $data['label'] ?? 'field' ) ) ), 0, 50 );
		}
		if ( null !== $row && isset( $data['field_key'] ) && (string) $data['field_key'] === (string) $row['field_key'] ) {
			unset( $data['field_key'] );
		}
		if ( isset( $data['field_key'] ) ) {
			$data['field_key'] = substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $data['field_key'] ) ), 0, 60 );
			// A key names one field for a record type, whatever it is kept
			// for: two fields with one key would share one value.
			$key   = (string) $data['field_key'];
			$taken = function ( string $key ) use ( $entity, $row ): bool {
				foreach ( $this->plugin->service( 'custom_fields' )->repository()->where(
					array(
						'entity'    => $entity,
						'field_key' => $key,
					),
					'id',
					0,
					true
				) as $other ) {
					if ( ! $row || (int) $other['id'] !== (int) $row['id'] ) {
						return true;
					}
				}
				return false;
			};
			if ( $taken( $key ) ) {
				if ( ! $made ) {
					throw ActionError::validation( __( 'Another field already uses that key.', 'cliezoo' ), array( 'field_key' => __( 'Choose another key.', 'cliezoo' ) ) );
				}
				$n = 2;
				while ( $taken( $key . '_' . $n ) ) {
					++$n;
				}
				$data['field_key'] = $key . '_' . $n;
			}
		}
		if ( null !== $row && isset( $data['type'] ) && $data['type'] !== $row['type'] ) {
			throw ActionError::conflict( __( 'The type of an existing field cannot change. Create a new field instead.', 'cliezoo' ) );
		}
		// Options are cleaned by the type that reads them: a list's
		// choices and colours, a number's format, a currency field's
		// currency. A field made without options still gets its type's
		// defaults, so a currency field always names its currency.
		if ( array_key_exists( 'options', $data ) || null === $row ) {
			$given           = $data['options'] ?? null;
			$given           = is_string( $given ) ? json_decode( $given, true ) : $given;
			$clean           = $this->types()->clean_options( $type, is_array( $given ) ? $given : array() );
			$data['options'] = $clean ? (string) wp_json_encode( $clean ) : null;
		}
		return $data;
	}

	/**
	 * Validates custom input (and required fields on create).
	 *
	 * @param Entity                    $entity Entity.
	 * @param array<string, mixed>      $input  Input.
	 * @param array<string, mixed>|null $row    Current row.
	 * @param Actor                     $actor  Actor.
	 * @return void
	 * @throws ActionError On invalid values.
	 */
	public function validate( Entity $entity, array $input, ?array $row, Actor $actor ): void {
		$custom = isset( $input['custom'] ) && is_array( $input['custom'] ) ? $input['custom'] : array();
		$errors = array();
		foreach ( $this->definitions( $entity->name ) as $key => $definition ) {
			$present = array_key_exists( $key, $custom );
			// A field kept for another board or task is not this record's:
			// it is neither required here nor settable.
			if ( ! $this->applies( $definition, $row, $input ) ) {
				if ( $present && null !== $custom[ $key ] && '' !== $custom[ $key ] && array() !== $custom[ $key ] ) {
					$errors[ 'custom.' . $key ] = __( 'This field is not used here.', 'cliezoo' );
				}
				continue;
			}
			if ( ! $present ) {
				if ( null === $row && ! empty( $definition['required'] ) && '' === (string) $definition['default_value'] ) {
					$errors[ 'custom.' . $key ] = __( 'This field is required.', 'cliezoo' );
				}
				continue;
			}
			if ( $actor->is_client() && empty( $definition['visible_to_clients'] ) ) {
				$errors[ 'custom.' . $key ] = __( 'This field cannot be changed.', 'cliezoo' );
				continue;
			}
			$value = $custom[ $key ];
			if ( null === $value || '' === $value || array() === $value ) {
				if ( ! empty( $definition['required'] ) ) {
					$errors[ 'custom.' . $key ] = __( 'This field is required.', 'cliezoo' );
				}
				continue;
			}
			try {
				$type = (array) $this->types()->get( (string) $definition['type'] );
				call_user_func( $type['normalize'], $value, $definition );
			} catch ( ActionError $error ) {
				$errors[ 'custom.' . $key ] = $error->getMessage();
			}
		}
		foreach ( array_keys( $custom ) as $key ) {
			if ( ! isset( $this->definitions( $entity->name )[ $key ] ) ) {
				$errors[ 'custom.' . $key ] = __( 'Unknown field.', 'cliezoo' );
			}
		}
		if ( $errors ) {
			throw ActionError::validation( __( 'Some fields are not valid.', 'cliezoo' ), $errors );
		}
	}

	/**
	 * Saves values (validated before) and records changes in the activity log.
	 *
	 * @param Entity               $entity Entity.
	 * @param array<string, mixed> $row    Row.
	 * @param array<string, mixed> $custom Values keyed by field key.
	 * @param Actor                $actor  Actor.
	 * @return void
	 */
	public function save( Entity $entity, array $row, array $custom, Actor $actor ): void {
		global $wpdb;
		$table       = $this->plugin->entities()->get( 'custom_field_values' )->table();
		$definitions = $this->definitions( $entity->name );
		$old         = $this->values_for( $entity, array( (int) $row['id'] ) )[ (int) $row['id'] ] ?? array();
		$diff        = array();
		foreach ( $custom as $key => $value ) {
			$definition = $definitions[ $key ] ?? null;
			if ( ! $definition || ! $this->applies( $definition, $row ) ) {
				continue;
			}
			$field_id = (int) $definition['id'];
			if ( null === $value || '' === $value || array() === $value ) {
				$wpdb->delete(
					$table,
					array(
						'field_id'  => $field_id,
						'object_id' => (int) $row['id'],
					)
				);
				$new = null;
			} else {
				$type    = (array) $this->types()->get( (string) $definition['type'] );
				$clean   = call_user_func( $type['normalize'], $value, $definition );
				$columns = array(
					'value_text'   => null,
					'value_short'  => null,
					'value_number' => null,
					'value_date'   => null,
				);
				switch ( $type['column'] ) {
					case 'number':
						$columns['value_number'] = $clean;
						break;
					case 'date':
						$columns['value_date'] = $clean;
						break;
					default:
						$text                   = is_array( $clean ) ? (string) wp_json_encode( $clean ) : (string) $clean;
						$columns['value_text']  = $text;
						$columns['value_short'] = mb_substr( $text, 0, 190 );
				}
				$now = gmdate( 'Y-m-d H:i:s' );
				// REPLACE on the (field_id, object_id) unique key; wpdb writes real NULLs.
				$wpdb->replace(
					$table,
					array(
						'object_type'  => $entity->name,
						'object_id'    => (int) $row['id'],
						'field_id'     => $field_id,
						'value_text'   => $columns['value_text'],
						'value_short'  => $columns['value_short'],
						'value_number' => $columns['value_number'],
						'value_date'   => $columns['value_date'],
						'created_by'   => $actor->member_id,
						'created_at'   => $now,
						'updated_at'   => $now,
					)
				);
				$new = $clean;
			}
			$before = $old[ $key ] ?? null;
			if ( wp_json_encode( $before ) !== wp_json_encode( $new ) ) {
				$diff[ $key ] = array( $before, $new );
			}
		}
		if ( $diff ) {
			$changes = array();
			foreach ( $diff as $key => $pair ) {
				$changes[ 'custom.' . $key ] = array( $pair[0], $pair[1] );
			}
			do_action( 'cliezoo_record_updated', $entity, $row, $row, $changes, $actor );
		}
	}

	/**
	 * Values for rows: row id => field key => value.
	 *
	 * @param Entity $entity Entity.
	 * @param int[]  $ids    Row ids.
	 * @return array<int, array<string, mixed>>
	 */
	public function values_for( Entity $entity, array $ids ): array {
		global $wpdb;
		$ids         = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$definitions = $this->definitions( $entity->name );
		if ( ! $ids || ! $definitions ) {
			return array();
		}
		$by_id = array();
		foreach ( $definitions as $key => $definition ) {
			$by_id[ (int) $definition['id'] ] = $key;
		}
		$table = esc_sql( $this->plugin->entities()->get( 'custom_field_values' )->table() );
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT object_id, field_id, value_text, value_number, value_date FROM {$table} WHERE object_type = %s AND object_id IN ({$in})", array_merge( array( $entity->name ), $ids ) ), ARRAY_A );
		$out   = array();
		$refs  = array();
		foreach ( $rows as $row ) {
			$key = $by_id[ (int) $row['field_id'] ] ?? null;
			if ( null === $key ) {
				continue;
			}
			$definition = $definitions[ $key ];
			$type       = (array) $this->types()->get( (string) $definition['type'] );
			$stored     = array(
				'text'   => $row['value_text'],
				'number' => $row['value_number'],
				'date'   => $row['value_date'],
			);
			if ( ! empty( $type['refs'] ) ) {
				$refs[ (string) $type['refs'] ][] = (string) $row['value_text'];
			}
			$out[ (int) $row['object_id'] ][ $key ] = is_callable( $type['present'] ) ? call_user_func( $type['present'], $stored, $definition ) : $stored['text'];
		}
		if ( isset( $refs['members'] ) ) {
			$members = array();
			foreach ( $this->plugin->service( 'members' )->repository()->where( array( 'uid' => array_values( array_unique( $refs['members'] ) ) ) ) as $member ) {
				$members[ (string) $member['uid'] ] = array(
					'uid'   => (string) $member['uid'],
					'label' => (string) $member['name'],
				);
			}
			foreach ( $out as $id => $values ) {
				foreach ( $values as $key => $value ) {
					if ( 'person' === $definitions[ $key ]['type'] ) {
						$out[ $id ][ $key ] = $members[ (string) $value ] ?? null;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * One record's custom values as words, for {{client.custom.po_number}}
	 * in an email or a document.
	 *
	 * Every field the record has, empty ones as empty, so a condition on a
	 * field nobody filled in reads as false rather than as a missing name.
	 *
	 * @param string $entity Entity name.
	 * @param int    $id     Row id.
	 * @return array<string, string> Field key => words.
	 */
	public function words( string $entity, int $id ): array {
		if ( $id <= 0 || ! $this->plugin->entities()->has( $entity ) ) {
			return array();
		}
		$definitions = $this->definitions( $entity );
		if ( ! $definitions ) {
			return array();
		}
		$values = $this->values_for( $this->plugin->entities()->get( $entity ), array( $id ) )[ $id ] ?? array();
		$out    = array();
		foreach ( array_keys( $definitions ) as $key ) {
			$out[ (string) $key ] = self::as_words( $values[ $key ] ?? null );
		}
		return $out;
	}

	/**
	 * A stored value as words.
	 *
	 * @param mixed $value The value.
	 * @return string
	 */
	private static function as_words( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'cliezoo' ) : __( 'No', 'cliezoo' );
		}
		if ( is_array( $value ) ) {
			if ( isset( $value['label'] ) ) {
				return (string) $value['label'];
			}
			return implode( ', ', array_map( static fn( $one ): string => self::as_words( $one ), $value ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Adds `custom` to presented records (client-hidden fields removed for clients).
	 *
	 * @param array<int, array<string, mixed>> $out    Presented.
	 * @param Entity                           $entity Entity.
	 * @param array<int, array<string, mixed>> $rows   Rows.
	 * @param Actor                            $actor  Actor.
	 * @return array<int, array<string, mixed>>
	 */
	public function present( array $out, Entity $entity, array $rows, Actor $actor ): array {
		$definitions = $this->definitions( $entity->name );
		if ( ! $definitions ) {
			foreach ( $out as $i => $item ) {
				$out[ $i ]['custom'] = new \stdClass();
			}
			return $out;
		}
		$values = $this->values_for( $entity, array_column( $rows, 'id' ) );
		foreach ( $rows as $i => $row ) {
			$custom = array();
			foreach ( $definitions as $key => $definition ) {
				if ( $actor->is_client() && empty( $definition['visible_to_clients'] ) ) {
					continue;
				}
				if ( ! $this->applies( $definition, $row ) ) {
					continue;
				}
				$custom[ $key ] = $values[ (int) $row['id'] ][ $key ] ?? null;
			}
			$out[ $i ]['custom'] = $custom ? $custom : new \stdClass();
		}
		return $out;
	}

	/**
	 * Filter condition on a custom field for the FilterCompiler.
	 *
	 * @param string $entity Entity.
	 * @param string $key    Field key.
	 * @param string $op     Operator.
	 * @param mixed  $value  Value.
	 * @return array{0:string,1:array<int, mixed>}|null
	 */
	public function filter( string $entity, string $key, string $op, $value ): ?array {
		global $wpdb;
		$definition = $this->definitions( $entity )[ $key ] ?? null;
		if ( ! $definition ) {
			return null;
		}
		$type   = (array) $this->types()->get( (string) $definition['type'] );
		$column = 'number' === $type['column'] ? 'v.value_number' : ( 'date' === $type['column'] ? 'v.value_date' : 'v.value_short' );
		$table  = $this->plugin->entities()->get( 'custom_field_values' )->table();
		$base   = "SELECT 1 FROM {$table} v WHERE v.object_type = %s AND v.object_id = t.id AND v.field_id = %d";
		$args   = array( $entity, (int) $definition['id'] );
		$ph     = 'number' === $type['column'] ? '%f' : '%s';
		switch ( $op ) {
			case 'empty':
				return array( "NOT EXISTS ({$base})", $args );
			case 'nempty':
				return array( "EXISTS ({$base})", $args );
			case 'eq':
				return array( "EXISTS ({$base} AND {$column} = {$ph})", array_merge( $args, array( $value ) ) );
			case 'neq':
				return array( "NOT EXISTS ({$base} AND {$column} = {$ph})", array_merge( $args, array( $value ) ) );
			case 'in':
				$values = array_values( (array) $value );
				if ( ! $values ) {
					return array( '1=0', array() );
				}
				return array( "EXISTS ({$base} AND {$column} IN (" . implode( ',', array_fill( 0, count( $values ), $ph ) ) . '))', array_merge( $args, $values ) );
			case 'contains':
				return array( "EXISTS ({$base} AND v.value_text LIKE %s)", array_merge( $args, array( '%' . $wpdb->esc_like( (string) $value ) . '%' ) ) );
			case 'gt':
			case 'gte':
			case 'lt':
			case 'lte':
				$sign = array(
					'gt'  => '>',
					'gte' => '>=',
					'lt'  => '<',
					'lte' => '<=',
				)[ $op ];
				return array( "EXISTS ({$base} AND {$column} {$sign} {$ph})", array_merge( $args, array( $value ) ) );
			case 'between':
				$pair = array_values( (array) $value );
				return array( "EXISTS ({$base} AND {$column} BETWEEN {$ph} AND {$ph})", array_merge( $args, array( $pair[0] ?? null, $pair[1] ?? null ) ) );
		}
		return null;
	}
}
