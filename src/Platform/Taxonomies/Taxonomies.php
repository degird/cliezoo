<?php
/**
 * Editable lists (statuses, sources, types, industries…).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Taxonomies;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * One manager for every dropdown (cliezoo.md §6.38 "Editable lists").
 * Modules declare their lists with seed values through `cliezoo_lists`.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class Taxonomies {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Request cache: list => values.
	 *
	 * @var array<string, array<int, array<string, mixed>>>|null
	 */
	private ?array $cache = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Declared lists: key => [label, seeds[[value, label, color?]]].
	 *
	 * @return array<string, array{label: string, seeds: array<int, array{0:string,1:string,2?:string}>}>
	 */
	public function definitions(): array {
		/**
		 * Declare editable lists and their seed values.
		 *
		 * @param array $lists key => ['label' => string, 'seeds' => [[value, label, color?], …]].
		 */
		return (array) apply_filters( 'cliezoo_lists', array() );
	}

	/**
	 * Seeds lists that have no values yet (install, upgrade, new modules).
	 *
	 * @return void
	 */
	public function seed(): void {
		global $wpdb;
		$table = esc_sql( $this->table() );
		foreach ( $this->definitions() as $key => $definition ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE list = %s", $key ) );
			if ( $count > 0 ) {
				continue;
			}
			$repo = $this->plugin->service( 'taxonomy_terms' )->repository();
			foreach ( array_values( $definition['seeds'] ) as $position => $seed ) {
				$repo->insert(
					array(
						'list'       => $key,
						'value'      => $seed[0],
						'label'      => $seed[1],
						'color'      => $seed[2] ?? null,
						'position'   => $position,
						'is_default' => 0 === $position ? 1 : 0,
					)
				);
			}
		}
		$this->flush();
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->plugin->entities()->get( 'taxonomy_terms' )->table();
	}

	/**
	 * All lists with values (cached).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		$cached = wp_cache_get( 'lists', 'cliezoo' );
		if ( is_array( $cached ) ) {
			$this->cache = $cached;
			return $cached;
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT uid, list, value, label, color, position, is_default FROM {$this->table()} ORDER BY list, position, id", ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['list'] ][] = array(
				'uid'        => (string) $row['uid'],
				'value'      => (string) $row['value'],
				'label'      => (string) $row['label'],
				'color'      => $row['color'],
				'position'   => (int) $row['position'],
				'is_default' => (bool) $row['is_default'],
			);
		}
		wp_cache_set( 'lists', $out, 'cliezoo' );
		$this->cache = $out;
		return $out;
	}

	/**
	 * Values of one list.
	 *
	 * @param string $key List key.
	 * @return array<int, array<string, mixed>>
	 */
	public function values( string $key ): array {
		return $this->all()[ $key ] ?? array();
	}

	/**
	 * Default value of a list (first flagged, else first).
	 *
	 * @param string $key List key.
	 * @return string|null
	 */
	public function default_value( string $key ): ?string {
		$values = $this->values( $key );
		foreach ( $values as $value ) {
			if ( $value['is_default'] ) {
				return $value['value'];
			}
		}
		return $values[0]['value'] ?? null;
	}

	/**
	 * Whether a value exists in a list.
	 *
	 * @param string $key   List key.
	 * @param string $value Value.
	 * @return bool
	 */
	public function has( string $key, string $value ): bool {
		foreach ( $this->values( $key ) as $item ) {
			if ( $item['value'] === $value ) {
				return true;
			}
		}
		// Lists that are not seeded (e.g. declared later) accept any value.
		return ! isset( $this->all()[ $key ] );
	}

	/**
	 * Clears caches.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->cache = null;
		wp_cache_delete( 'lists', 'cliezoo' );
	}

	/**
	 * Reorders a list.
	 *
	 * @param string   $key  List key.
	 * @param string[] $uids Uids in the new order.
	 * @return array<int, array<string, mixed>>
	 */
	public function reorder( string $key, array $uids ): array {
		global $wpdb;
		foreach ( array_values( $uids ) as $position => $uid ) {
			$wpdb->update(
				$this->table(),
				array( 'position' => $position ),
				array(
					'list' => $key,
					'uid'  => $uid,
				)
			);
		}
		$this->flush();
		return $this->values( $key );
	}

	/**
	 * Removes a value, moving records that use it to another value.
	 *
	 * @param string      $uid     Term uid.
	 * @param string|null $replace Replacement value (null = clear).
	 * @return array{removed: bool, moved: int}
	 * @throws ActionError When the replacement is invalid.
	 */
	public function remove( string $uid, ?string $replace ): array {
		global $wpdb;
		$repo = $this->plugin->service( 'taxonomy_terms' )->repository();
		$term = $repo->find_by_uid( $uid );
		if ( ! $term ) {
			throw ActionError::not_found();
		}
		$list  = (string) $term['list'];
		$value = (string) $term['value'];
		if ( null !== $replace && ( $replace === $value || ! $this->has( $list, $replace ) ) ) {
			throw ActionError::validation( __( 'Choose another value to move existing records to.', 'cliezoo' ) );
		}
		$moved = 0;
		foreach ( $this->plugin->entities()->all() as $entity ) {
			foreach ( $entity->fields as $column => $field ) {
				if ( $field->list !== $list ) {
					continue;
				}
				$moved += (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET %i = %s WHERE %i = %s', $entity->table(), $column, $replace, $column, $value ) );
			}
		}
		$repo->delete( (int) $term['id'] );
		$this->flush();
		return array(
			'removed' => true,
			'moved'   => $moved,
		);
	}
}
