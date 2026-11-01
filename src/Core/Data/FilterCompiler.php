<?php
/**
 * Compiles filter trees to prepared SQL.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Turns the advanced filter builder's JSON (AND/OR groups, operators,
 * relative date ranges) into a WHERE fragment. Column names come only from
 * the entity definition; values are always placeholders.
 */
final class FilterCompiler {

	public const OPERATORS = array( 'eq', 'neq', 'in', 'nin', 'contains', 'ncontains', 'starts', 'gt', 'gte', 'lt', 'lte', 'between', 'empty', 'nempty', 'within' );

	private const MAX_DEPTH = 4;

	private const MAX_CONDITIONS = 50;

	/**
	 * Entity being filtered.
	 *
	 * @var Entity
	 */
	private Entity $entity;

	/**
	 * Maps (entity, uid[]) to internal ids.
	 *
	 * @var callable
	 */
	private $resolver;

	/**
	 * Custom-field condition builder: (field_key, op, value) → [sql, args]|null.
	 *
	 * @var callable|null
	 */
	private $custom;

	/**
	 * Conditions compiled so far (abuse guard).
	 *
	 * @var int
	 */
	private int $count = 0;

	/**
	 * Constructor.
	 *
	 * @param Entity        $entity   Entity.
	 * @param callable      $resolver fn( string $entity, string[] $uids ): int[].
	 * @param callable|null $custom   Custom field condition builder.
	 */
	public function __construct( Entity $entity, callable $resolver, ?callable $custom = null ) {
		$this->entity   = $entity;
		$this->resolver = $resolver;
		$this->custom   = $custom;
	}

	/**
	 * Compiles a filter tree.
	 *
	 * @param array<string, mixed> $tree Filter tree.
	 * @return array{0:string,1:array<int, mixed>} SQL and args ("" when empty).
	 * @throws QueryException On invalid filters.
	 */
	public function compile( array $tree ): array {
		if ( ! $tree ) {
			return array( '', array() );
		}
		return $this->group( $tree, 0 );
	}

	/**
	 * Compiles a group node.
	 *
	 * @param array<string, mixed> $node  Node.
	 * @param int                  $depth Depth.
	 * @return array{0:string,1:array<int, mixed>}
	 * @throws QueryException On invalid filters.
	 */
	private function group( array $node, int $depth ): array {
		if ( $depth > self::MAX_DEPTH ) {
			throw new QueryException( __( 'Filter nesting is too deep.', 'cliezoo' ) );
		}
		if ( isset( $node['field'] ) ) {
			return $this->condition( $node );
		}
		$glue  = isset( $node['or'] ) ? ' OR ' : ' AND ';
		$items = $node['or'] ?? $node['and'] ?? null;
		if ( ! is_array( $items ) ) {
			throw new QueryException( __( 'Filter groups need "and" or "or".', 'cliezoo' ) );
		}
		$parts = array();
		$args  = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				throw new QueryException( __( 'Invalid filter item.', 'cliezoo' ) );
			}
			list( $sql, $item_args ) = $this->group( $item, $depth + 1 );
			if ( '' !== $sql ) {
				$parts[] = $sql;
				$args    = array_merge( $args, $item_args );
			}
		}
		if ( ! $parts ) {
			return array( '', array() );
		}
		return array( '(' . implode( $glue, $parts ) . ')', $args );
	}

	/**
	 * Compiles one condition.
	 *
	 * @param array<string, mixed> $node Condition.
	 * @return array{0:string,1:array<int, mixed>}
	 * @throws QueryException On invalid filters.
	 */
	private function condition( array $node ): array {
		if ( ++$this->count > self::MAX_CONDITIONS ) {
			throw new QueryException( __( 'Too many filter conditions.', 'cliezoo' ) );
		}
		$key   = (string) $node['field'];
		$op    = (string) ( $node['op'] ?? 'eq' );
		$value = $node['value'] ?? null;
		if ( ! in_array( $op, self::OPERATORS, true ) ) {
			throw new QueryException( __( 'Unknown filter operator.', 'cliezoo' ) );
		}

		if ( str_starts_with( $key, 'custom.' ) ) {
			$result = $this->custom ? call_user_func( $this->custom, substr( $key, 7 ), $op, $value ) : null;
			if ( ! $result ) {
				throw new QueryException( __( 'Unknown custom field.', 'cliezoo' ) );
			}
			return $result;
		}

		$column = $this->entity->column_for_key( $key );
		$system = in_array( $key, array( 'created_at', 'updated_at', 'number', 'archived_at', 'deleted_at', 'created_by' ), true );
		if ( $system && $this->entity->has_column( $key ) ) {
			$column = $key;
		}
		if ( null === $column ) {
			throw new QueryException( __( 'Unknown filter field.', 'cliezoo' ) );
		}
		$field = $this->entity->field( $column );
		if ( $field && ! $field->filterable ) {
			throw new QueryException( __( 'This field cannot be filtered.', 'cliezoo' ) );
		}
		$type = $field ? $field->type : ( 'created_by' === $column ? 'user' : ( in_array( $column, array( 'number' ), true ) ? 'text' : 'datetime' ) );
		$col  = 't.' . $column;

		if ( in_array( $type, array( 'ref', 'user' ), true ) && ! in_array( $op, array( 'empty', 'nempty' ), true ) ) {
			$value = $this->resolve_refs( $field ? $field->ref : 'members', $value );
		}
		if ( 'bool' === $type && ! in_array( $op, array( 'empty', 'nempty' ), true ) ) {
			$value = ( true === $value || 1 === $value || '1' === $value || 'true' === $value ) ? 1 : 0;
		}

		switch ( $op ) {
			case 'eq':
				return array( "{$col} = " . $this->ph( $value ), array( $value ) );
			case 'neq':
				return array( "({$col} <> " . $this->ph( $value ) . " OR {$col} IS NULL)", array( $value ) );
			case 'in':
			case 'nin':
				$values = array_values( (array) $value );
				if ( ! $values ) {
					return array( 'in' === $op ? '1=0' : '', array() );
				}
				if ( count( $values ) > 200 ) {
					throw new QueryException( __( 'Too many filter values.', 'cliezoo' ) );
				}
				$placeholders = implode( ',', array_map( array( $this, 'ph' ), $values ) );
				$sql          = 'in' === $op ? "{$col} IN ({$placeholders})" : "({$col} NOT IN ({$placeholders}) OR {$col} IS NULL)";
				return array( $sql, $values );
			case 'contains':
			case 'ncontains':
			case 'starts':
				global $wpdb;
				$like = $wpdb->esc_like( (string) $value );
				$like = 'starts' === $op ? $like . '%' : '%' . $like . '%';
				return array( 'ncontains' === $op ? "({$col} NOT LIKE %s OR {$col} IS NULL)" : "{$col} LIKE %s", array( $like ) );
			case 'gt':
				return array( "{$col} > " . $this->ph( $value ), array( $this->date_value( $type, $value, false ) ) );
			case 'gte':
				return array( "{$col} >= " . $this->ph( $value ), array( $this->date_value( $type, $value, false ) ) );
			case 'lt':
				return array( "{$col} < " . $this->ph( $value ), array( $this->date_value( $type, $value, true ) ) );
			case 'lte':
				return array( "{$col} <= " . $this->ph( $value ), array( $this->date_value( $type, $value, true ) ) );
			case 'between':
				if ( ! is_array( $value ) || 2 !== count( $value ) ) {
					throw new QueryException( __( '"between" needs two values.', 'cliezoo' ) );
				}
				$value = array_values( $value );
				return array(
					"{$col} BETWEEN " . $this->ph( $value[0] ) . ' AND ' . $this->ph( $value[1] ),
					array( $this->date_value( $type, $value[0], false ), $this->date_value( $type, $value[1], true ) ),
				);
			case 'empty':
				return array( in_array( $type, array( 'text', 'longtext', 'email', 'url', 'phone', 'enum' ), true ) ? "({$col} IS NULL OR {$col} = '')" : "{$col} IS NULL", array() );
			case 'nempty':
				return array( in_array( $type, array( 'text', 'longtext', 'email', 'url', 'phone', 'enum' ), true ) ? "({$col} IS NOT NULL AND {$col} <> '')" : "{$col} IS NOT NULL", array() );
			default: // "within" (operators were validated above).
				list( $from, $to ) = DateRange::resolve( $value, 'date' === $type );
				return array( "{$col} BETWEEN %s AND %s", array( $from, $to ) );
		}
	}

	/**
	 * Placeholder for a value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function ph( $value ): string {
		if ( is_int( $value ) || is_bool( $value ) ) {
			return '%d';
		}
		if ( is_float( $value ) ) {
			return '%f';
		}
		return '%s';
	}

	/**
	 * Normalises date filter values (a date on a datetime column covers the whole day).
	 *
	 * @param string $type    Column type.
	 * @param mixed  $value   Value.
	 * @param bool   $end_of  Use the end of the day.
	 * @return mixed
	 */
	private function date_value( string $type, $value, bool $end_of ) {
		if ( 'datetime' === $type && is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value . ( $end_of ? ' 23:59:59' : ' 00:00:00' );
		}
		if ( 'datetime' === $type && is_string( $value ) && false !== strtotime( $value ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) strtotime( $value ) );
		}
		return $value;
	}

	/**
	 * Converts uid filter values to internal ids.
	 *
	 * @param string $entity Referenced entity.
	 * @param mixed  $value  Uid or list of uids.
	 * @return int|int[]
	 * @throws QueryException On malformed uids.
	 */
	private function resolve_refs( string $entity, $value ) {
		$list = is_array( $value );
		$uids = array_values( (array) $value );
		foreach ( $uids as $uid ) {
			if ( ! Ulid::valid( $uid ) ) {
				throw new QueryException( __( 'Invalid record reference in filter.', 'cliezoo' ) );
			}
		}
		$ids = call_user_func( $this->resolver, $entity, $uids );
		if ( $list ) {
			return $ids ? $ids : array( 0 );
		}
		return $ids ? (int) $ids[0] : 0;
	}
}
