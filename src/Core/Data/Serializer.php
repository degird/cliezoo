<?php
/**
 * Converts between database rows and the public API shape.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Data;

use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output: uids instead of ids, `{amount, currency}` money, ISO-8601 UTC
 * datetimes, references as `{uid, label}` chips (batch-loaded, no N+1),
 * finance fields hidden from roles without finance access.
 * Input: sanitises by field type and maps keys to columns.
 */
final class Serializer {

	/**
	 * Registry.
	 *
	 * @var EntityRegistry
	 */
	private EntityRegistry $entities;

	/**
	 * Constructor.
	 *
	 * @param EntityRegistry $entities Registry.
	 */
	public function __construct( EntityRegistry $entities ) {
		$this->entities = $entities;
	}

	/**
	 * Presents rows.
	 *
	 * @param Entity                           $entity Entity.
	 * @param array<int, array<string, mixed>> $rows   Rows.
	 * @param Actor                            $actor  Actor.
	 * @param string[]                         $fields Sparse fieldset (empty = all).
	 * @return array<int, array<string, mixed>>
	 */
	public function present_many( Entity $entity, array $rows, Actor $actor, array $fields = array() ): array {
		if ( ! $rows ) {
			return array();
		}
		$refs = $this->load_refs( $entity, $rows );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = $this->present_row( $entity, $row, $actor, $refs, $fields );
		}
		/**
		 * Filters presented records (custom fields, computed values, counts).
		 *
		 * @param array  $out    Presented records (same order as rows).
		 * @param Entity $entity Entity.
		 * @param array  $rows   Raw rows.
		 * @param Actor  $actor  Actor.
		 */
		return (array) apply_filters( 'cliezoo_present_' . $entity->name, apply_filters( 'cliezoo_present', $out, $entity, $rows, $actor ), $entity, $rows, $actor );
	}

	/**
	 * Presents one row.
	 *
	 * @param Entity               $entity Entity.
	 * @param array<string, mixed> $row    Row.
	 * @param Actor                $actor  Actor.
	 * @return array<string, mixed>
	 */
	public function present( Entity $entity, array $row, Actor $actor ): array {
		return $this->present_many( $entity, array( $row ), $actor )[0];
	}

	/**
	 * Batch-loads reference chips for all ref/user fields.
	 *
	 * @param Entity                           $entity Entity.
	 * @param array<int, array<string, mixed>> $rows   Rows.
	 * @return array<string, array<int, array{uid: string, label: string, number: ?string}>>
	 */
	private function load_refs( Entity $entity, array $rows ): array {
		$wanted = array();
		foreach ( $entity->fields as $column => $field ) {
			if ( ! in_array( $field->type, array( 'ref', 'user' ), true ) ) {
				continue;
			}
			foreach ( $rows as $row ) {
				if ( ! empty( $row[ $column ] ) ) {
					$wanted[ $field->ref ][] = (int) $row[ $column ];
				}
			}
		}
		foreach ( $rows as $row ) {
			if ( ! empty( $row['created_by'] ) ) {
				$wanted['members'][] = (int) $row['created_by'];
			}
		}
		$refs = array();
		foreach ( $wanted as $target => $ids ) {
			if ( ! $this->entities->has( $target ) ) {
				continue;
			}
			$repository      = new Repository( $this->entities->get( $target ), $this->entities );
			$refs[ $target ] = $repository->refs( $ids );
		}
		return $refs;
	}

	/**
	 * Presents a row with preloaded references.
	 *
	 * @param Entity                                                                        $entity Entity.
	 * @param array<string, mixed>                                                          $row    Row.
	 * @param Actor                                                                         $actor  Actor.
	 * @param array<string, array<int, array{uid: string, label: string, number: ?string}>> $refs   References.
	 * @param string[]                                                                      $only   Sparse fields.
	 * @return array<string, mixed>
	 */
	private function present_row( Entity $entity, array $row, Actor $actor, array $refs, array $only ): array {
		$out = array();
		if ( $entity->public ) {
			$out['uid'] = (string) $row['uid'];
		}
		if ( '' !== $entity->series ) {
			$out['number'] = $row['number'] ?? null;
		}
		$sees_finance = $actor->sees_finance();
		foreach ( $entity->fields as $column => $field ) {
			if ( $field->hidden || ( $field->finance && ! $sees_finance ) ) {
				continue;
			}
			$key = $entity->input_key( $column );
			if ( $only && ! in_array( $key, $only, true ) ) {
				continue;
			}
			$out[ $key ] = $this->output_value( $field, $row, $refs );
		}
		$out['created_by'] = ! empty( $row['created_by'] ) ? ( $refs['members'][ (int) $row['created_by'] ] ?? null ) : null;
		$out['created_at'] = self::iso( $row['created_at'] ?? null );
		$out['updated_at'] = self::iso( $row['updated_at'] ?? null );
		if ( $entity->soft_delete ) {
			$out['archived_at'] = self::iso( $row['archived_at'] ?? null );
			$out['deleted_at']  = self::iso( $row['deleted_at'] ?? null );
		}
		return $out;
	}

	/**
	 * Output value for one field.
	 *
	 * @param Field                                                                         $field Field.
	 * @param array<string, mixed>                                                          $row   Row.
	 * @param array<string, array<int, array{uid: string, label: string, number: ?string}>> $refs  References.
	 * @return mixed
	 */
	private function output_value( Field $field, array $row, array $refs ) {
		$value = $row[ $field->name ] ?? null;
		switch ( $field->type ) {
			case 'ref':
			case 'user':
				return $value ? ( $refs[ $field->ref ][ (int) $value ] ?? null ) : null;
			case 'money':
				return array(
					'amount'   => (int) $value,
					'currency' => (string) ( $row[ $field->currency_field ] ?? '' ),
				);
			case 'int':
			case 'bigint':
				return null === $value ? null : (int) $value;
			case 'decimal':
				return null === $value ? null : (float) $value;
			case 'bool':
				return (bool) $value;
			case 'json':
				return null === $value || '' === $value ? null : json_decode( (string) $value, true );
			case 'datetime':
				return self::iso( $value );
			default:
				return $value;
		}
	}

	/**
	 * UTC MySQL datetime → ISO 8601 with Z.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	public static function iso( $value ): ?string {
		if ( empty( $value ) || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		return str_replace( ' ', 'T', (string) $value ) . 'Z';
	}

	/**
	 * ISO 8601 (any offset) → UTC MySQL datetime.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	public static function to_mysql( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$time = strtotime( (string) $value );
		return false === $time ? null : gmdate( 'Y-m-d H:i:s', $time );
	}

	/**
	 * Converts validated input to column values (references stay as uids for the caller to resolve).
	 *
	 * @param Entity               $entity Entity.
	 * @param array<string, mixed> $input  Input keyed by REST keys.
	 * @param Actor|null           $actor  Writer (financial fields need finance access).
	 * @return array<string, mixed> Column => value.
	 */
	public function to_columns( Entity $entity, array $input, ?Actor $actor = null ): array {
		$data = array();
		foreach ( $input as $key => $value ) {
			$column = $entity->column_for_key( (string) $key );
			if ( null === $column ) {
				continue;
			}
			$field = $entity->field( $column );
			// Read-only and hidden columns are never written from input; financial
			// columns only by people who may see them.
			if ( ! $field || $field->readonly || $field->hidden || ( $field->finance && $actor && ! $actor->sees_finance() ) ) {
				continue;
			}
			$data[ $column ] = self::sanitize( $field, $value );
		}
		return $data;
	}

	/**
	 * Sanitises a value by field type.
	 *
	 * @param Field $field Field.
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function sanitize( Field $field, $value ) {
		// Nothing chosen on an enum means what the field starts as, not a
		// value outside its own list.
		if ( 'enum' === $field->type && ( null === $value || '' === $value ) && null !== $field->default ) {
			return (string) $field->default;
		}
		if ( null === $value ) {
			// Nothing said about a switch is not the same as switching it
			// off: a form sends null for every field nobody touched, and a
			// null that became 0 turned "leave it alone" into "turn it
			// off". The repository leaves the column out instead, so it
			// keeps its default on the way in and its value on the way
			// back. An amount is different — no money really is none.
			return 'money' === $field->type ? 0 : null;
		}
		switch ( $field->type ) {
			case 'enum':
				return mb_substr( sanitize_text_field( (string) $value ), 0, $field->max );
			case 'text':
			case 'phone':
				return mb_substr( sanitize_text_field( (string) $value ), 0, $field->max );
			case 'currency':
				return strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $value ), 0, 3 ) );
			case 'longtext':
				return sanitize_textarea_field( (string) $value );
			case 'html':
				return Html::clean( (string) $value );
			case 'email':
				return sanitize_email( (string) $value );
			case 'url':
				return esc_url_raw( (string) $value, array( 'http', 'https' ) );
			case 'color':
				$color = sanitize_hex_color( (string) $value );
				return $color ? $color : null;
			case 'int':
			case 'bigint':
				return (int) $value;
			case 'money':
				return is_array( $value ) ? (int) ( $value['amount'] ?? 0 ) : (int) $value;
			case 'decimal':
				return round( (float) $value, $field->scale );
			case 'bool':
				return $value ? 1 : 0;
			case 'date':
				return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? (string) $value : null;
			case 'datetime':
				return self::to_mysql( $value );
			case 'json':
				return wp_json_encode( $value );
			case 'ref':
			case 'user':
				return is_array( $value ) ? (string) ( $value['uid'] ?? '' ) : (string) $value;
		}
		return $value;
	}
}
