<?php
/**
 * Every template in the workspace, in one list.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Settings;

use Cliezoo\Core\Data\ListQuery;
use Cliezoo\Core\Data\Serializer;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Mail\EmailTemplates;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Templates grew up beside the things they make: a doc template is kept
 * with docs, a board's list template with boards, a saved reply with
 * conversations. That is right for the person making one — it is offered
 * where it is wanted — and wrong for the person who has to look after
 * them, who has no idea how many there are or where.
 *
 * So this reads them all, without moving any of them: one list, filtered
 * by kind, each row saying where it lives so it can be opened and changed
 * where it was made. Nothing is created or deleted here; the modules keep
 * that, along with the rules about who may.
 *
 * A module adds its own kind through `cliezoo_template_kinds`.
 */
final class TemplateIndex {

	/**
	 * Most of any one kind. Past that the list says so.
	 */
	public const LIMIT = 200;

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
	 * The kinds of template there are, and how to read each one.
	 *
	 * @return array<string, array{label: string, where: string}>
	 */
	public function kinds(): array {
		$kinds = array(
			'folders' => array(
				'label' => __( 'Folders', 'cliezoo' ),
				'where' => 'files',
			),
			'email'   => array(
				'label' => __( 'Emails', 'cliezoo' ),
				'where' => 'settings/email',
			),
		);
		/**
		 * The kinds of template the one list shows.
		 *
		 * @param array  $kinds  Kinds, by id.
		 * @param Plugin $plugin Plugin.
		 */
		return (array) apply_filters( 'cliezoo_template_kinds', $kinds, $this->plugin );
	}

	/**
	 * Every template the reader may see.
	 *
	 * @param Actor  $actor Who is looking.
	 * @param string $kind  One kind, or '' for all of them.
	 * @param string $query What to search for.
	 * @return array{kinds: array<int, array<string, string>>, templates: array<int, array<string, mixed>>, more: bool}
	 */
	public function all( Actor $actor, string $kind = '', string $query = '' ): array {
		$kinds = $this->kinds();
		$rows  = array();
		$more  = false;
		foreach ( $kinds as $id => $about ) {
			if ( '' !== $kind && $kind !== $id ) {
				continue;
			}
			$found = $this->of_kind( $actor, (string) $id, $query );
			$more  = $more || count( $found ) >= self::LIMIT;
			$rows  = array_merge( $rows, $found );
		}
		usort(
			$rows,
			static fn( array $a, array $b ): int => strcasecmp( (string) $a['name'], (string) $b['name'] )
		);
		return array(
			'kinds'     => array_map(
				static fn( string $id, array $about ): array => array(
					'id'    => $id,
					'label' => (string) $about['label'],
				),
				array_keys( $kinds ),
				array_values( $kinds )
			),
			'templates' => $rows,
			'more'      => $more,
		);
	}

	/**
	 * The templates of one kind.
	 *
	 * @param Actor  $actor Who is looking.
	 * @param string $kind  The kind.
	 * @param string $query What to search for.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_kind( Actor $actor, string $kind, string $query ): array {
		if ( 'email' === $kind ) {
			return $this->emails( $query );
		}
		/**
		 * The templates of a kind a module keeps its own way, as the reader
		 * may see them: each with uid, name, description, uses and
		 * updated_at. Null leaves the kind to be read here.
		 *
		 * @param array|null $rows  Rows, or null.
		 * @param string     $kind  The kind.
		 * @param string     $query What to search for.
		 * @param Actor      $actor Who is looking.
		 */
		$own = apply_filters( 'cliezoo_template_rows', null, $kind, $query, $actor );
		if ( is_array( $own ) ) {
			return array_map(
				fn( $row ): array => $this->row(
					$kind,
					(string) ( $row['uid'] ?? '' ),
					(string) ( $row['name'] ?? '' ),
					(string) ( $row['description'] ?? '' ),
					(int) ( $row['uses'] ?? 0 ),
					(string) ( $row['updated_at'] ?? '' )
				),
				array_slice( array_values( array_filter( $own, 'is_array' ) ), 0, self::LIMIT )
			);
		}
		$entity = array(
			'project' => 'project_templates',
			'task'    => 'task_templates',
			'list'    => 'list_templates',
		)[ $kind ] ?? '';
		if ( '' !== $entity ) {
			return $this->from_entity( $actor, $kind, $entity, $query );
		}
		return $this->from_shared( $kind, $query );
	}

	/**
	 * Templates that have a table of their own.
	 *
	 * @param Actor  $actor  Who is looking.
	 * @param string $kind   The kind.
	 * @param string $entity Entity name.
	 * @param string $query  What to search for.
	 * @return array<int, array<string, mixed>>
	 */
	private function from_entity( Actor $actor, string $kind, string $entity, string $query ): array {
		if ( ! $this->plugin->entities()->has( $entity ) ) {
			return array();
		}
		$ask         = new ListQuery();
		$ask->search = $query;
		$ask->limit  = self::LIMIT;
		$ask->sort   = 'name';
		$found       = $this->plugin->service( $entity )->list( $actor, $ask );
		return array_map(
			fn( array $row ): array => $this->row(
				$kind,
				(string) $row['uid'],
				(string) ( $row['name'] ?? '' ),
				(string) ( $row['description'] ?? '' ),
				(int) ( $row['uses'] ?? 0 ),
				(string) ( $row['updated_at'] ?? $row['created_at'] ?? '' )
			),
			(array) ( $found['data'] ?? array() )
		);
	}

	/**
	 * Templates kept in the shared `templates` table, told apart by type.
	 *
	 * @param string $kind  The kind, which is the type column.
	 * @param string $query What to search for.
	 * @return array<int, array<string, mixed>>
	 */
	private function from_shared( string $kind, string $query ): array {
		global $wpdb;
		$table = esc_sql( $this->plugin->entities()->get( 'templates' )->table() );
		$like  = '%' . $wpdb->esc_like( $query ) . '%';
		$rows  = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read across one small table; the name comes from code.
			$wpdb->prepare(
				"SELECT uid, name, description, updated_at, created_at FROM {$table} WHERE type = %s AND deleted_at IS NULL AND (%s = '' OR name LIKE %s OR description LIKE %s) ORDER BY name LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from the entity registry.
				$kind,
				$query,
				$like,
				$like,
				self::LIMIT
			),
			ARRAY_A
		);
		return array_map(
			fn( array $row ): array => $this->row(
				$kind,
				(string) $row['uid'],
				(string) $row['name'],
				(string) ( $row['description'] ?? '' ),
				0,
				(string) ( $row['updated_at'] ?? $row['created_at'] ?? '' )
			),
			$rows
		);
	}

	/**
	 * The system email templates, which are a fixed set rather than rows:
	 * every one of them exists whether or not it has been changed.
	 *
	 * @param string $query What to search for.
	 * @return array<int, array<string, mixed>>
	 */
	private function emails( string $query ): array {
		$out    = array();
		$needle = strtolower( $query );
		foreach ( $this->plugin->get( EmailTemplates::class )->defaults() as $key => $template ) {
			$label = (string) ( $template['label'] ?? $key );
			if ( '' !== $needle && ! str_contains( strtolower( $label . ' ' . (string) $key ), $needle ) ) {
				continue;
			}
			$out[] = $this->row(
				'email',
				(string) $key,
				$label,
				(string) ( $template['group'] ?? '' ),
				0,
				''
			);
		}
		return $out;
	}

	/**
	 * One row, the same shape whatever it came from.
	 *
	 * @param string $kind        Kind id.
	 * @param string $uid         Its id (a key, for an email template).
	 * @param string $name        What it is called.
	 * @param string $description What it says about itself.
	 * @param int    $uses        How many times it has been used.
	 * @param string $changed     When it last changed.
	 * @return array<string, mixed>
	 */
	private function row( string $kind, string $uid, string $name, string $description, int $uses, string $changed ): array {
		$kinds = $this->kinds();
		return array(
			'kind'        => $kind,
			'kind_label'  => (string) ( $kinds[ $kind ]['label'] ?? $kind ),
			'uid'         => $uid,
			'name'        => $name,
			'description' => $description,
			'uses'        => $uses,
			'updated_at'  => '' === $changed ? null : Serializer::iso( $changed ),
			// Where it is looked after, so a row can be opened rather than
			// hunted for.
			'where'       => (string) ( $kinds[ $kind ]['where'] ?? '' ),
		);
	}
}
