<?php
/**
 * Generic repository for entity tables.
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
 * Prepared-SQL access to one entity table: reads, writes, keyset lists.
 *
 * Every list goes through a Policy scope fragment passed in by the caller,
 * so there is no unscoped list path (Blueprint §8.7).
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * Table and column names come from entity definitions in code; all values use placeholders.
 */
final class Repository {

	/**
	 * How long one list query may take before it is worth saying so,
	 * in milliseconds. A page is allowed a second; one query is not.
	 */
	public const SLOW_QUERY_MS = 300;

	/**
	 * How many fields one list may be sorted by ("status,-due_date,name").
	 * Past three, the order says nothing a person can follow, and every
	 * level is another term in an ORDER BY the index cannot help with.
	 */
	public const SORT_LEVELS = 3;

	/**
	 * Entity.
	 *
	 * @var Entity
	 */
	private Entity $entity;

	/**
	 * Registry, for resolving references.
	 *
	 * @var EntityRegistry
	 */
	private EntityRegistry $registry;

	/**
	 * Custom-field filter builder.
	 *
	 * @var callable|null
	 */
	private $custom_filter;

	/**
	 * Constructor.
	 *
	 * @param Entity         $entity        Entity.
	 * @param EntityRegistry $registry      Registry.
	 * @param callable|null  $custom_filter Custom field condition builder for FilterCompiler.
	 */
	public function __construct( Entity $entity, EntityRegistry $registry, ?callable $custom_filter = null ) {
		$this->entity        = $entity;
		$this->registry      = $registry;
		$this->custom_filter = $custom_filter;
	}

	/**
	 * The entity.
	 *
	 * @return Entity
	 */
	public function entity(): Entity {
		return $this->entity;
	}

	/**
	 * Comma-separated column list with the table alias.
	 *
	 * @return string
	 */
	private function select(): string {
		return implode( ', ', array_map( static fn( string $c ): string => 't.' . $c, $this->entity->columns() ) );
	}

	/**
	 * A row by internal id.
	 *
	 * @param int $id Id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from the entity registry; every value is a placeholder passed to prepare().
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$this->select()} FROM {$this->entity->table()} t WHERE t.id = %d", $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * A row by public uid.
	 *
	 * @param string $uid Uid.
	 * @return array<string, mixed>|null
	 */
	public function find_by_uid( string $uid ): ?array {
		global $wpdb;
		if ( ! $this->entity->public || ! Ulid::valid( $uid ) ) {
			return null;
		}
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from the entity registry; every value is a placeholder passed to prepare().
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$this->select()} FROM {$this->entity->table()} t WHERE t.uid = %s", $uid ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Rows by ids (one query), keyed by id.
	 *
	 * @param int[] $ids Ids.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_many( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names come from the entity registry; every value is a placeholder passed to prepare().
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT {$this->select()} FROM {$this->entity->table()} t WHERE t.id IN ({$placeholders})", $ids ), ARRAY_A );
		$out          = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * Rows matching simple column equality conditions (code use only).
	 *
	 * @param array<string, mixed> $where   Column => value (columns must exist).
	 * @param string               $order   Column to order by, "-column" for descending.
	 * @param int                  $limit   Max rows (0 = no limit).
	 * @param bool                 $trashed Include trashed rows.
	 * @param int                  $offset  Rows to skip, for a later page.
	 * @return array<int, array<string, mixed>>
	 */
	public function where( array $where, string $order = 'id', int $limit = 0, bool $trashed = false, int $offset = 0 ): array {
		global $wpdb;
		list( $sql, $args ) = $this->equality( $where );
		if ( ! $trashed && $this->entity->soft_delete ) {
			$sql .= ' AND t.deleted_at IS NULL';
		}
		// The direction is read off what the caller wrote, before the "-"
		// is taken off to name the column. Reading it afterwards means
		// every "-position" quietly came back ascending, which is how
		// "the card after the last one" became "the card after the first".
		$dir   = str_starts_with( $order, '-' ) ? 'DESC' : 'ASC';
		$order = $this->entity->has_column( ltrim( $order, '-' ) ) ? ltrim( $order, '-' ) : 'id';
		$query = "SELECT {$this->select()} FROM {$this->entity->table()} t WHERE {$sql} ORDER BY t.{$order} {$dir}, t.id ASC";
		if ( $limit > 0 ) {
			$query .= ' LIMIT ' . (int) $limit;
			if ( $offset > 0 ) {
				$query .= ' OFFSET ' . (int) $offset;
			}
		}
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from the entity registry; every value is a placeholder passed to prepare().
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $query, $args ), ARRAY_A ) : $wpdb->get_results( $query, ARRAY_A );
		return (array) $rows;
	}

	/**
	 * One batch of rows in id order, for walking a whole table without
	 * holding it in memory: export, reindex, migration.
	 *
	 * @param int  $after   Last id already handled (0 to start).
	 * @param int  $limit   Batch size.
	 * @param bool $trashed Include trashed rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function batch( int $after = 0, int $limit = 500, bool $trashed = false ): array {
		global $wpdb;
		$sql = 't.id > %d';
		if ( ! $trashed && $this->entity->soft_delete ) {
			$sql .= ' AND t.deleted_at IS NULL';
		}
		$query = "SELECT {$this->select()} FROM {$this->entity->table()} t WHERE {$sql} ORDER BY t.id ASC LIMIT %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $query, $after, max( 1, $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and columns come from the entity definition.
		return (array) $rows;
	}

	/**
	 * First row matching conditions.
	 *
	 * @param array<string, mixed> $where Column => value.
	 * @return array<string, mixed>|null
	 */
	public function first( array $where ): ?array {
		$rows = $this->where( $where, 'id', 1 );
		return $rows ? $rows[0] : null;
	}

	/**
	 * Builds an equality WHERE clause.
	 *
	 * @param array<string, mixed> $where Column => value (null → IS NULL, array → IN).
	 * @return array{0:string,1:array<int, mixed>}
	 * @throws \InvalidArgumentException On unknown columns.
	 */
	private function equality( array $where ): array {
		$parts = array( '1=1' );
		$args  = array();
		foreach ( $where as $column => $value ) {
			if ( ! $this->entity->has_column( $column ) ) {
				throw new \InvalidArgumentException( esc_html( "Unknown column {$column}" ) );
			}
			if ( null === $value ) {
				$parts[] = "t.{$column} IS NULL";
			} elseif ( is_array( $value ) ) {
				if ( ! $value ) {
					$parts[] = '1=0';
					continue;
				}
				$parts[] = "t.{$column} IN (" . implode( ',', array_fill( 0, count( $value ), is_int( reset( $value ) ) ? '%d' : '%s' ) ) . ')';
				$args    = array_merge( $args, array_values( $value ) );
			} else {
				$parts[] = "t.{$column} = " . ( is_int( $value ) || is_bool( $value ) ? '%d' : '%s' );
				$args[]  = is_bool( $value ) ? (int) $value : $value;
			}
		}
		return array( implode( ' AND ', $parts ), $args );
	}

	/**
	 * Inserts a row. Adds uid and timestamps.
	 *
	 * @param array<string, mixed> $data       Column => value (already validated).
	 * @param int|null             $created_by Member id.
	 * @return array<string, mixed> The inserted row.
	 * @throws \RuntimeException On database errors.
	 */
	public function insert( array $data, ?int $created_by = null ): array {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( $this->entity->public && empty( $data['uid'] ) ) {
			$data['uid'] = Ulid::generate();
		}
		$data['created_at'] = $data['created_at'] ?? $now;
		$data['updated_at'] = $now;
		if ( null !== $created_by ) {
			$data['created_by'] = $created_by;
		}
		$data = $this->only_columns( $data );
		if ( false === $wpdb->insert( $this->entity->table(), $data, $this->formats( $data ) ) ) {
			throw new \RuntimeException( 'Could not save the record.' );
		}
		$data['id'] = (int) $wpdb->insert_id;
		return (array) $this->find( $data['id'] );
	}

	/**
	 * Updates a row.
	 *
	 * @param int                  $id   Id.
	 * @param array<string, mixed> $data Column => value.
	 * @return void
	 * @throws \RuntimeException On database errors.
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$data = $this->only_columns( $data );
		unset( $data['id'], $data['uid'], $data['created_at'] );
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		if ( false === $wpdb->update( $this->entity->table(), $data, array( 'id' => $id ), $this->formats( $data ), array( '%d' ) ) ) {
			throw new \RuntimeException( 'Could not update the record.' );
		}
	}

	/**
	 * Updates rows matching conditions (code use only).
	 *
	 * @param array<string, mixed> $data  Column => value.
	 * @param array<string, mixed> $where Column => value.
	 * @return int Affected rows.
	 */
	public function update_where( array $data, array $where ): int {
		global $wpdb;
		$data               = $this->only_columns( $data );
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		return (int) $wpdb->update( $this->entity->table(), $data, $where, $this->formats( $data ), $this->formats( $where ) );
	}

	/**
	 * Permanently deletes a row.
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->entity->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Permanently deletes rows matching conditions (code use only: cascades).
	 *
	 * @param array<string, mixed> $where Column => value.
	 * @return int Affected rows.
	 */
	public function delete_where( array $where ): int {
		global $wpdb;
		return (int) $wpdb->delete( $this->entity->table(), $where, $this->formats( $where ) );
	}

	/**
	 * Internal ids for public uids.
	 *
	 * @param string[] $uids Uids.
	 * @return int[]
	 */
	public function ids_for( array $uids ): array {
		global $wpdb;
		$uids = array_values( array_filter( $uids, array( Ulid::class, 'valid' ) ) );
		if ( ! $uids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $uids ), '%s' ) );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names come from the entity registry; every value is a placeholder passed to prepare().
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->entity->table()} WHERE uid IN ({$placeholders})", $uids ) ) );
	}

	/**
	 * Uid + label for ids (for reference chips), one query.
	 *
	 * @param int[] $ids Ids.
	 * @return array<int, array{uid: string, label: string, number: ?string}>
	 */
	public function refs( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids || ! $this->entity->public ) {
			return array();
		}
		$title  = $this->entity->has_column( $this->entity->title_field ) ? 't.' . $this->entity->title_field : "''";
		$number = '' !== $this->entity->series ? 't.number' : 'NULL';
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names come from the entity registry; every value is a placeholder passed to prepare().
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT t.id, t.uid, {$title} AS label, {$number} AS number FROM {$this->entity->table()} t WHERE t.id IN ({$in})", $ids ), ARRAY_A );
		$out    = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = array(
				'uid'    => (string) $row['uid'],
				'label'  => (string) $row['label'],
				'number' => null === $row['number'] ? null : (string) $row['number'],
			);
		}
		return $out;
	}

	/**
	 * Lists rows with filters, search, sort and cursor, inside a Policy scope.
	 *
	 * @param ListQuery                                $query Query.
	 * @param array{0:string,1:array<int, mixed>}|null $scope Policy scope fragment; null only for trusted system code.
	 * @return array{rows: array<int, array<string, mixed>>, next_cursor: ?string, has_more: bool, total: ?int}
	 * @throws QueryException On invalid input.
	 */
	public function list( ListQuery $query, ?array $scope ): array {
		global $wpdb;
		list( $where_sql, $args ) = $this->where_clause( $query, $scope );

		$specs                          = $this->sort_specs( $query->sort );
		list( $column, $desc, $keyset ) = $specs[0];
		// A cursor remembers one value; sorted by several fields, the next
		// page is found by its place instead.
		if ( count( $specs ) > 1 ) {
			$keyset = false;
		}
		$limit       = max( 1, min( 200, $query->limit ) );
		$offset      = 0;
		$cursor_sql  = '';
		$cursor_args = array();
		if ( '' !== $query->cursor ) {
			$cursor = $this->decode_cursor( $query->cursor );
			if ( ! $keyset || isset( $cursor['o'] ) ) {
				$offset = max( 0, (int) ( $cursor['o'] ?? 0 ) );
			} else {
				$cmp         = $desc ? '<' : '>';
				$cursor_sql  = " AND (t.{$column} {$cmp} %s OR (t.{$column} = %s AND t.id {$cmp} %d))";
				$cursor_args = array( (string) $cursor['v'], (string) $cursor['v'], (int) $cursor['i'] );
			}
		}
		$dir = $desc ? 'DESC' : 'ASC';
		// Sorted by a date that some rows do not have: the ones without it
		// go last. "Soonest first" is what somebody asking for that order
		// means, and MySQL's own answer — every row with no date, first —
		// is the opposite of it. Only ascending needs saying; descending
		// already puts them at the end. Nullable columns page by offset, so
		// this cannot disagree with a cursor.
		// Each level of a multi-level sort follows the same rule.
		$order = array();
		foreach ( $specs as $spec ) {
			list( $by, $down, $not_null ) = $spec;
			if ( ! $not_null && ! $down ) {
				$order[] = "t.{$by} IS NULL";
			}
			$order[] = "t.{$by} " . ( $down ? 'DESC' : 'ASC' );
		}
		$order_sql = implode( ', ', $order );
		$sql       = "SELECT {$this->select()} FROM {$this->entity->table()} t WHERE {$where_sql}{$cursor_sql} ORDER BY {$order_sql}, t.id {$dir} LIMIT %d OFFSET %d";
		$started   = microtime( true );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from the entity registry; every value is a placeholder passed to prepare().
		$rows      = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, $cursor_args, array( $limit + 1, $offset ) ) ), ARRAY_A );
		$this->watch_the_clock( $sql, $started );
		$more = count( $rows ) > $limit;
		$rows = array_slice( $rows, 0, $limit );
		$next = null;
		if ( $more && $rows ) {
			$last = end( $rows );
			$next = $keyset
				? $this->encode_cursor(
					array(
						'v' => $last[ $column ],
						'i' => (int) $last['id'],
					)
				)
				: $this->encode_cursor( array( 'o' => $offset + $limit ) );
		}
		$total = null;
		if ( $query->with_total ) {
			$total = $this->count_where( $where_sql, $args );
		}
		return array(
			'rows'        => $rows,
			'next_cursor' => $next,
			'has_more'    => $more,
			'total'       => $total,
		);
	}

	/**
	 * Counts rows matching a query and scope.
	 *
	 * @param ListQuery                                $query Query.
	 * @param array{0:string,1:array<int, mixed>}|null $scope Scope.
	 * @return int
	 */
	public function count( ListQuery $query, ?array $scope ): int {
		list( $where_sql, $args ) = $this->where_clause( $query, $scope );
		return $this->count_where( $where_sql, $args );
	}

	/**
	 * Adds up one column over the rows a query and scope reach.
	 *
	 * `rollup()` does this already but only grouped by something, which suits
	 * money — it has to be kept apart by currency — and not a plain number like
	 * minutes, where grouping is exactly what you do not want.
	 *
	 * @param ListQuery                                $query  Query.
	 * @param array{0:string,1:array<int, mixed>}|null $scope  Scope.
	 * @param string                                   $column The column to add up.
	 * @return int
	 * @throws QueryException On an unknown column.
	 */
	public function total( ListQuery $query, ?array $scope, string $column ): int {
		global $wpdb;
		if ( ! $this->entity->has_column( $column ) ) {
			throw new QueryException( __( 'Unknown column to add up.', 'cliezoo' ) );
		}
		list( $where_sql, $args ) = $this->where_clause( $query, $scope );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table and the column come from the entity definition; every value is a placeholder.
		$sql = "SELECT COALESCE(SUM(t.{$column}), 0) FROM {$this->entity->table()} t WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- As above.
		return (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $sql ) );
	}

	/**
	 * Sums a column for rows matching a query and scope, grouped by another column.
	 *
	 * @param ListQuery                                $query    Query.
	 * @param array{0:string,1:array<int, mixed>}|null $scope    Scope.
	 * @param string                                   $group_by Column to group by.
	 * @param string                                   $sum      Column to sum ('' = count only).
	 * @return array<string, array{count:int, sum:int}>
	 * @throws QueryException On unknown columns.
	 */
	public function rollup( ListQuery $query, ?array $scope, string $group_by, string $sum = '' ): array {
		global $wpdb;
		if ( ! $this->entity->has_column( $group_by ) || ( '' !== $sum && ! $this->entity->has_column( $sum ) ) ) {
			throw new QueryException( __( 'Unknown roll-up column.', 'cliezoo' ) );
		}
		list( $where_sql, $args ) = $this->where_clause( $query, $scope );
		$sum_sql                  = '' !== $sum ? "SUM(t.{$sum})" : '0';
		$sql                      = "SELECT t.{$group_by} AS g, COUNT(*) AS c, {$sum_sql} AS s FROM {$this->entity->table()} t WHERE {$where_sql} GROUP BY t.{$group_by}";
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from the entity registry; every value is a placeholder passed to prepare().
		$rows                     = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		$out                      = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['g'] ] = array(
				'count' => (int) $row['c'],
				'sum'   => (int) $row['s'],
			);
		}
		return $out;
	}

	/**
	 * WHERE clause for a query + scope.
	 *
	 * @param ListQuery                                $query Query.
	 * @param array{0:string,1:array<int, mixed>}|null $scope Scope.
	 * @return array{0:string,1:array<int, mixed>}
	 * @throws QueryException On invalid input.
	 */
	public function where_clause( ListQuery $query, ?array $scope ): array {
		global $wpdb;
		$parts = array( '1=1' );
		$args  = array();

		if ( $this->entity->soft_delete ) {
			switch ( $query->state ) {
				case 'archived':
					$parts[] = 't.deleted_at IS NULL AND t.archived_at IS NOT NULL';
					break;
				case 'trash':
					$parts[] = 't.deleted_at IS NOT NULL';
					break;
				case 'all':
					$parts[] = 't.deleted_at IS NULL';
					break;
				default:
					$parts[] = 't.deleted_at IS NULL AND t.archived_at IS NULL';
			}
		}

		if ( null !== $scope && '' !== $scope[0] ) {
			$parts[] = '(' . $scope[0] . ')';
			$args    = array_merge( $args, $scope[1] );
		}

		$compiler             = new FilterCompiler( $this->entity, array( $this, 'resolve_refs' ), $this->custom_filter );
		list( $fsql, $fargs ) = $compiler->compile( $query->filter );
		if ( '' !== $fsql ) {
			$parts[] = $fsql;
			$args    = array_merge( $args, $fargs );
		}

		$search = trim( $query->search );
		if ( '' !== $search ) {
			$columns = array();
			foreach ( $this->entity->fields as $column => $field ) {
				if ( $field->searchable ) {
					$columns[] = $column;
				}
			}
			if ( '' !== $this->entity->series ) {
				$columns[] = 'number';
			}
			if ( $columns ) {
				$like = '%' . $wpdb->esc_like( mb_substr( $search, 0, 100 ) ) . '%';
				$ors  = array();
				foreach ( $columns as $column ) {
					$ors[]  = "t.{$column} LIKE %s";
					$args[] = $like;
				}
				$parts[] = '(' . implode( ' OR ', $ors ) . ')';
			}
		}

		foreach ( $query->where as $condition ) {
			$parts[] = '(' . $condition[0] . ')';
			$args    = array_merge( $args, $condition[1] );
		}

		return array( implode( ' AND ', $parts ), $args );
	}

	/**
	 * Uid → id resolver used by filters (public so FilterCompiler can call it).
	 *
	 * @param string   $entity Entity name.
	 * @param string[] $uids   Uids.
	 * @return int[]
	 */
	public function resolve_refs( string $entity, array $uids ): array {
		if ( ! $this->registry->has( $entity ) ) {
			return array();
		}
		$repository = new self( $this->registry->get( $entity ), $this->registry );
		return $repository->ids_for( $uids );
	}

	/**
	 * The levels of a sort: "status,-due_date" is by status, then by due
	 * date newest first. Every level is checked like a single sort, so a
	 * field that may not be sorted cannot ride in behind one that may.
	 *
	 * @param string $sort Comma-separated sort keys.
	 * @return array<int, array{0:string,1:bool,2:bool}>
	 * @throws QueryException On too many levels or an unknown field.
	 */
	private function sort_specs( string $sort ): array {
		$parts = array_values( array_filter( array_map( 'trim', explode( ',', $sort ) ), static fn( string $part ): bool => '' !== $part ) );
		if ( ! $parts ) {
			return array( $this->sort_spec( '' ) );
		}
		if ( count( $parts ) > self::SORT_LEVELS ) {
			/* translators: %d: the most fields a list can be sorted by. */
			throw new QueryException( sprintf( __( 'A list can be sorted by at most %d fields.', 'cliezoo' ), self::SORT_LEVELS ) );
		}
		$specs = array();
		foreach ( $parts as $part ) {
			$spec = $this->sort_spec( $part );
			// The same field twice: the first says what was meant.
			if ( ! in_array( $spec[0], array_column( $specs, 0 ), true ) ) {
				$specs[] = $spec;
			}
		}
		return $specs;
	}

	/**
	 * Validates the sort parameter.
	 *
	 * @param string $sort Sort ("-due_date").
	 * @return array{0:string,1:bool,2:bool} Column, descending, keyset-capable.
	 * @throws QueryException On an unknown sort field.
	 */
	private function sort_spec( string $sort ): array {
		$sort = '' !== $sort ? $sort : $this->entity->default_sort;
		$desc = str_starts_with( $sort, '-' );
		$key  = ltrim( $sort, '-' );
		if ( in_array( $key, array( 'created_at', 'updated_at', 'id' ), true ) ) {
			return array( $key, $desc, true );
		}
		if ( 'number' === $key && '' !== $this->entity->series ) {
			return array( 'number', $desc, false );
		}
		$column = $this->entity->column_for_key( $key );
		$field  = $column ? $this->entity->field( $column ) : null;
		// The entity's own default sort is always allowed.
		$is_default = ltrim( $this->entity->default_sort, '-' ) === $key;
		if ( ! $field || ( ! $field->sortable && ! $is_default ) ) {
			throw new QueryException( __( 'This field cannot be sorted.', 'cliezoo' ) );
		}
		// Keyset pagination needs a NOT NULL column; nullable columns page by offset.
		$not_null = null !== $field->default || in_array( $field->type, array( 'bool', 'money' ), true );
		return array( $column, $desc, $not_null );
	}

	/**
	 * Encodes a cursor.
	 *
	 * @param array<string, mixed> $data Cursor data.
	 * @return string
	 */
	private function encode_cursor( array $data ): string {
		return rtrim( strtr( base64_encode( (string) wp_json_encode( $data ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Opaque pagination cursor.
	}

	/**
	 * Decodes a cursor.
	 *
	 * @param string $cursor Cursor.
	 * @return array<string, mixed>
	 * @throws QueryException On a malformed cursor.
	 */
	private function decode_cursor( string $cursor ): array {
		$json = base64_decode( strtr( $cursor, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Opaque pagination cursor.
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || ( ! isset( $data['o'] ) && ! isset( $data['v'], $data['i'] ) ) ) {
			throw new QueryException( __( 'Invalid cursor.', 'cliezoo' ) );
		}
		return $data;
	}

	/**
	 * COUNT(*) for a WHERE clause.
	 *
	 * @param string            $where_sql WHERE.
	 * @param array<int, mixed> $args      Args.
	 * @return int
	 */
	private function count_where( string $where_sql, array $args ): int {
		global $wpdb;
		$sql = "SELECT COUNT(*) FROM {$this->entity->table()} t WHERE {$where_sql}";
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from the entity registry; every value is a placeholder passed to prepare().
		return (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $sql ) );
	}

	/**
	 * Keeps only known columns.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return array<string, mixed>
	 */
	private function only_columns( array $data ): array {
		$data = array_intersect_key( $data, array_flip( $this->entity->columns() ) );
		// A null for a column that cannot hold one is a write the database
		// refuses outright — a 500 where the person typed nothing wrong. A
		// form that leaves a number empty means "whatever it was", so the
		// column keeps its default on the way in and its value on the way
		// back.
		foreach ( $data as $column => $value ) {
			$field = $this->entity->field( (string) $column );
			if ( null === $value && $field && $field->not_null() ) {
				unset( $data[ $column ] );
			}
		}
		return $data;
	}

	/**
	 * Formats for wpdb.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return string[]
	 */
	private function formats( array $data ): array {
		$formats = array();
		foreach ( $data as $column => $value ) {
			$field = $this->entity->field( (string) $column );
			if ( null === $value ) {
				$formats[] = '%s';
			} elseif ( in_array( $column, array( 'id', 'created_by' ), true ) || ( $field && in_array( $field->type, array( 'int', 'bigint', 'money', 'bool', 'ref', 'user' ), true ) ) ) {
				$formats[] = '%d';
			} elseif ( $field && 'decimal' === $field->type ) {
				$formats[] = '%s';
			} else {
				$formats[] = '%s';
			}
		}
		return $formats;
	}

	/**
	 * Says so when a list query takes longer than it should.
	 *
	 * The product promises a page in under a second, and the thing that
	 * takes a page over it is nearly always one query against a table
	 * that has grown past its index. A budget in the service layer means
	 * the day that happens is the day somebody hears about it, rather
	 * than the day a customer writes in.
	 *
	 * @param string $sql     The query, for the record.
	 * @param float  $started When it began (microtime).
	 * @return void
	 */
	private function watch_the_clock( string $sql, float $started ): void {
		$took = ( microtime( true ) - $started ) * 1000;
		/**
		 * How many milliseconds one list query may take.
		 *
		 * @param int $budget Milliseconds.
		 */
		$budget = (int) apply_filters( 'cliezoo_slow_query_ms', self::SLOW_QUERY_MS );
		if ( $took < $budget ) {
			return;
		}
		/**
		 * A list query went over its budget.
		 *
		 * @param string $entity The kind of record.
		 * @param float  $took   Milliseconds it took.
		 * @param string $sql    The query.
		 */
		do_action( 'cliezoo_slow_query', $this->entity->name, $took, $sql );
	}
}
