<?php
/**
 * List query parameters.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters, search, sort and cursor for a list request.
 */
final class ListQuery {

	/**
	 * Filter tree: {"and":[{field,op,value}|{"or":[…]}]} (fields are columns).
	 *
	 * @var array<string, mixed>
	 */
	public array $filter = array();

	/**
	 * Free-text search.
	 *
	 * @var string
	 */
	public string $search = '';

	/**
	 * Sort field; "-" prefix = descending.
	 *
	 * @var string
	 */
	public string $sort = '';

	/**
	 * Opaque cursor from the previous page.
	 *
	 * @var string
	 */
	public string $cursor = '';

	/**
	 * Page size (1–200).
	 *
	 * @var int
	 */
	public int $limit = 50;

	/**
	 * Which rows: active | archived | trash | all (non-trashed incl. archived).
	 *
	 * @var string
	 */
	public string $state = 'active';

	/**
	 * Whether to compute the total count (cached; costs one query).
	 *
	 * @var bool
	 */
	public bool $with_total = false;

	/**
	 * Extra WHERE fragments added by code (never from input): [sql, args].
	 *
	 * @var array<int, array{0:string,1:array<int, mixed>}>
	 */
	public array $where = array();

	/**
	 * Adds a code-level condition.
	 *
	 * @param string            $sql  SQL fragment with placeholders.
	 * @param array<int, mixed> $args Placeholder values.
	 * @return self
	 */
	public function where( string $sql, array $args = array() ): self {
		$this->where[] = array( $sql, $args );
		return $this;
	}

	/**
	 * Adds an equality filter on a column.
	 *
	 * @param string $field Column.
	 * @param mixed  $value Value.
	 * @return self
	 */
	public function eq( string $field, $value ): self {
		$this->filter['and']   = $this->filter['and'] ?? array();
		$this->filter['and'][] = array(
			'field' => $field,
			'op'    => 'eq',
			'value' => $value,
		);
		return $this;
	}
}
