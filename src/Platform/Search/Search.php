<?php
/**
 * Search index and queries.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Search;

use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\CustomFields\CustomFields;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Indexes searchable entities and answers global search with operators.
 * Candidates come from the index in one query, then each record type is
 * verified with one Policy-scoped query, so results never leak.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
 */
final class Search {

	private const OPERATORS = array( 'type', 'status', 'client', 'message', 'from', 'before', 'after' );

	/**
	 * Messages read into one thread's index row. The newest are kept: a
	 * thread of thousands is found by what it is about now, and one read of
	 * a bounded list keeps a busy inbox's writes quick.
	 */
	private const MESSAGES = 200;

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
	 * Index table.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->plugin->entities()->get( 'search_index' )->table();
	}

	/**
	 * Adds or refreshes a record in the index.
	 *
	 * @param Entity               $entity Entity.
	 * @param array<string, mixed> $row    Row.
	 * @return void
	 */
	public function index( Entity $entity, array $row ): void {
		global $wpdb;
		if ( ! $entity->searchable ) {
			return;
		}
		if ( $entity->soft_delete && ! empty( $row['deleted_at'] ) ) {
			$this->remove( $entity->name, (int) $row['id'] );
			return;
		}
		$body     = array();
		$keywords = array();
		foreach ( $entity->fields as $column => $field ) {
			if ( ! $field->searchable || $column === $entity->title_field || empty( $row[ $column ] ) ) {
				continue;
			}
			if ( in_array( $field->type, array( 'email', 'phone', 'url' ), true ) ) {
				$keywords[] = (string) $row[ $column ];
			} else {
				$body[] = wp_strip_all_tags( (string) $row[ $column ] );
			}
		}
		if ( '' !== $entity->series && ! empty( $row['number'] ) ) {
			$keywords[] = (string) $row['number'];
		}
		$staff   = array();
		$authors = array();
		if ( ! empty( $row['created_by'] ) ) {
			$authors[] = (int) $row['created_by'];
		}
		// A doc's words live in its blocks; `body_text` is the plain text
		// the doc keeps of them, so a doc is found by what it says.
		if ( $entity->has_column( 'body_text' ) && ! empty( $row['body_text'] ) ) {
			$body[] = (string) $row['body_text'];
		}
		$this->custom_text( $entity, $row, $body, $staff );
		if ( 'conversations' === $entity->name ) {
			$this->message_text( (int) $row['id'], $body, $staff, $authors );
		}
		/**
		 * Extra searchable text for a record (custom fields, line items…).
		 *
		 * @param string[] $body   Text chunks.
		 * @param Entity   $entity Entity.
		 * @param array    $row    Row.
		 */
		$body  = (array) apply_filters( 'cliezoo_search_text', $body, $entity, $row );
		$title = $entity->has_column( $entity->title_field ) ? wp_strip_all_tags( (string) $row[ $entity->title_field ] ) : '';
		$now   = gmdate( 'Y-m-d H:i:s' );
		$wpdb->replace(
			$this->table(),
			array(
				'object_type' => $entity->name,
				'object_id'   => (int) $row['id'],
				'client_id'   => 'clients' === $entity->name ? (int) $row['id'] : ( '' !== $entity->client_field ? ( $row[ $entity->client_field ] ?? null ) : null ),
				'project_id'  => 'projects' === $entity->name ? (int) $row['id'] : ( '' !== $entity->project_field ? ( $row[ $entity->project_field ] ?? null ) : null ),
				'title'       => mb_substr( $title, 0, 255 ),
				'body'        => mb_substr( implode( "\n", $body ), 0, 60000 ),
				'staff_body'  => $staff ? mb_substr( implode( "\n", $staff ), 0, 60000 ) : null,
				'authors'     => $authors ? mb_substr( '|' . implode( '|', array_unique( $authors ) ) . '|', 0, 255 ) : null,
				'keywords'    => mb_substr( implode( ' ', $keywords ), 0, 255 ),
				'status'      => isset( $row['status'] ) ? mb_substr( (string) $row['status'], 0, 40 ) : null,
				'archived'    => ! empty( $row['archived_at'] ) ? 1 : 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
	}

	/**
	 * Custom-field values as searchable text. A value clients may see goes
	 * with the record's words; one kept from clients goes to the staff-only
	 * text, so a client can never find a record by a value hidden from them.
	 *
	 * @param Entity               $entity Entity.
	 * @param array<string, mixed> $row    Row.
	 * @param string[]             $body   Everyone's text (added to).
	 * @param string[]             $staff  Staff-only text (added to).
	 * @return void
	 */
	private function custom_text( Entity $entity, array $row, array &$body, array &$staff ): void {
		if ( ! $entity->custom_fields || ! $this->plugin->container()->has( CustomFields::class ) ) {
			return;
		}
		$fields      = $this->plugin->get( CustomFields::class );
		$definitions = $fields->definitions( $entity->name );
		if ( ! $definitions ) {
			return;
		}
		$values = $fields->values_for( $entity, array( (int) $row['id'] ) )[ (int) $row['id'] ] ?? array();
		foreach ( $values as $key => $value ) {
			$text = self::flatten( $value );
			if ( '' === $text || ! isset( $definitions[ $key ] ) ) {
				continue;
			}
			if ( ! empty( $definitions[ $key ]['visible_to_clients'] ) ) {
				$body[] = $text;
			} else {
				$staff[] = $text;
			}
		}
	}

	/**
	 * A presented custom value as plain words (lists joined, people by name).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function flatten( $value ): string {
		if ( is_array( $value ) ) {
			if ( isset( $value['label'] ) && is_scalar( $value['label'] ) ) {
				return (string) $value['label'];
			}
			return trim( implode( ' ', array_filter( array_map( static fn( $one ): string => self::flatten( $one ), $value ) ) ) );
		}
		if ( is_bool( $value ) || null === $value ) {
			return '';
		}
		return is_scalar( $value ) ? wp_strip_all_tags( (string) $value ) : '';
	}

	/**
	 * A thread's messages as searchable text. What clients may read goes
	 * with the thread's words; an internal note goes to the staff-only
	 * text. A held message is not sent yet, so it is not found yet. The
	 * authors of what clients may read are listed for `from:`.
	 *
	 * @param int      $conversation_id Thread id.
	 * @param string[] $body            Everyone's text (added to).
	 * @param string[] $staff           Staff-only text (added to).
	 * @param int[]    $authors         Authors (added to).
	 * @return void
	 */
	private function message_text( int $conversation_id, array &$body, array &$staff, array &$authors ): void {
		global $wpdb;
		if ( ! $this->plugin->entities()->has( 'comments' ) ) {
			return;
		}
		$table = esc_sql( $this->plugin->entities()->get( 'comments' )->table() );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT body, internal, created_by FROM {$table} WHERE object_type = 'conversations' AND object_id = %d AND deleted_at IS NULL AND send_at IS NULL ORDER BY created_at DESC, id DESC LIMIT %d", $conversation_id, self::MESSAGES ), ARRAY_A );
		foreach ( $rows as $message ) {
			$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( str_replace( '<', ' <', (string) $message['body'] ) ) ) );
			if ( ! empty( $message['internal'] ) ) {
				$staff[] = $text;
				continue;
			}
			$body[] = $text;
			if ( ! empty( $message['created_by'] ) ) {
				$authors[] = (int) $message['created_by'];
			}
		}
	}

	/**
	 * Refreshes the thread a message belongs to, so its words are found.
	 *
	 * @param array<string, mixed> $comment Comment row.
	 * @return void
	 */
	public function index_message( array $comment ): void {
		if ( 'conversations' !== (string) ( $comment['object_type'] ?? '' ) || empty( $comment['object_id'] ) || ! $this->plugin->entities()->has( 'conversations' ) ) {
			return;
		}
		$thread = $this->plugin->service( 'conversations' )->repository()->find( (int) $comment['object_id'] );
		if ( $thread ) {
			$this->index( $this->plugin->entities()->get( 'conversations' ), $thread );
		}
	}

	/**
	 * Removes a record from the index.
	 *
	 * @param string $type Entity name.
	 * @param int    $id   Row id.
	 * @return void
	 */
	public function remove( string $type, int $id ): void {
		global $wpdb;
		$wpdb->delete(
			$this->table(),
			array(
				'object_type' => $type,
				'object_id'   => $id,
			)
		);
	}

	/**
	 * Re-indexes one entity in batches of 200 (queues the next batch).
	 *
	 * @param string $name  Entity name ('' = start with every searchable entity).
	 * @param int    $after Last id done.
	 * @return void
	 */
	public function reindex_batch( string $name, int $after ): void {
		global $wpdb;
		$jobs = $this->plugin->get( \Cliezoo\Infrastructure\Jobs\Jobs::class );
		if ( '' === $name ) {
			foreach ( $this->plugin->entities()->all() as $entity ) {
				if ( $entity->searchable ) {
					$jobs->queue( 'search_reindex', array( 'entity' => $entity->name ) );
				}
			}
			return;
		}
		$entity = $this->plugin->entities()->get( $name );
		$ids    = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE id > %d ORDER BY id LIMIT 200', $entity->table(), $after ) ) );
		foreach ( $this->plugin->service( $name )->repository()->find_many( $ids ) as $row ) {
			$this->index( $entity, $row );
		}
		if ( 200 === count( $ids ) ) {
			$jobs->queue(
				'search_reindex',
				array(
					'entity' => $name,
					'after'  => (int) end( $ids ),
				)
			);
		}
	}

	/**
	 * Parses operators out of a query.
	 *
	 * @param string $q Query.
	 * @return array{text: string, ops: array<string, string>}
	 */
	public static function parse( string $q ): array {
		$ops = array();
		$q   = (string) preg_replace_callback(
			'/\b(' . implode( '|', self::OPERATORS ) . '):("([^"]*)"|(\S+))/i',
			static function ( array $m ) use ( &$ops ): string {
				$ops[ strtolower( $m[1] ) ] = '' !== ( $m[3] ?? '' ) ? $m[3] : ( $m[4] ?? '' );
				return ' ';
			},
			$q
		);
		return array(
			'text' => trim( (string) preg_replace( '/\s+/', ' ', $q ) ),
			'ops'  => $ops,
		);
	}

	/**
	 * Runs a search for the actor.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $q     Query.
	 * @param string $type  Restrict to an entity.
	 * @param int    $limit Max results.
	 * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
	 */
	public function search( Actor $actor, string $q, string $type = '', int $limit = 20 ): array {
		global $wpdb;
		$parsed = self::parse( $q );
		$text   = $parsed['text'];
		$ops    = $parsed['ops'];
		// Messages live in conversations: `message:` finds the threads that
		// say it (their index rows carry the text of their messages).
		if ( isset( $ops['message'] ) ) {
			$type = 'conversations';
			$text = trim( $text . ' ' . $ops['message'] );
		}
		if ( isset( $ops['type'] ) ) {
			$type = $this->type_from_alias( $ops['type'] );
		}
		$where = array( 's.archived IN (0,1)' );
		$args  = array();
		if ( '' !== $type ) {
			$where[] = 's.object_type = %s';
			$args[]  = $type;
		}
		if ( isset( $ops['status'] ) ) {
			$where[] = 's.status = %s';
			$args[]  = $ops['status'];
		}
		if ( isset( $ops['before'] ) && false !== strtotime( $ops['before'] ) ) {
			$where[] = 's.updated_at < %s';
			$args[]  = gmdate( 'Y-m-d 00:00:00', (int) strtotime( $ops['before'] ) );
		}
		if ( isset( $ops['after'] ) && false !== strtotime( $ops['after'] ) ) {
			$where[] = 's.updated_at > %s';
			$args[]  = gmdate( 'Y-m-d 23:59:59', (int) strtotime( $ops['after'] ) );
		}
		if ( isset( $ops['client'] ) && $this->plugin->entities()->has( 'clients' ) ) {
			$clients = $this->plugin->entities()->get( 'clients' )->table();
			$where[] = "s.client_id IN (SELECT id FROM {$clients} WHERE name LIKE %s)";
			$args[]  = '%' . $wpdb->esc_like( $ops['client'] ) . '%';
		}
		if ( isset( $ops['from'] ) ) {
			$authors = $this->authors( $actor, $ops['from'] );
			if ( ! $authors ) {
				$where[] = '1=0';
			} else {
				$where[] = '(' . implode( ' OR ', array_fill( 0, count( $authors ), 's.authors LIKE %s' ) ) . ')';
				foreach ( $authors as $author ) {
					$args[] = '%|' . $author . '|%';
				}
			}
		}
		if ( $actor->is_client() ) {
			$where[] = 's.client_id = %d';
			$args[]  = $actor->active_client_id;
		}
		// Staff also match the text kept from clients: internal notes and
		// custom fields clients are not shown. A client never does.
		$staff      = ! $actor->is_client();
		$candidates = array();
		if ( mb_strlen( $text ) >= 3 ) {
			$boolean = implode( ' ', array_map( static fn( string $w ): string => '+' . preg_replace( '/[+\-><()~*"@]/', '', $w ) . '*', array_filter( explode( ' ', $text ) ) ) );
			$match   = 'MATCH (s.title, s.body, s.keywords) AGAINST (%s IN BOOLEAN MODE)';
			$terms   = array( $boolean );
			if ( $staff ) {
				$match = "({$match} + MATCH (s.staff_body) AGAINST (%s IN BOOLEAN MODE))";
				$terms = array( $boolean, $boolean );
			}
			$sql        = "SELECT s.object_type, s.object_id FROM {$this->table()} s WHERE " . implode( ' AND ', array_merge( $where, array( "{$match} > 0" ) ) ) . " ORDER BY {$match} DESC, s.updated_at DESC LIMIT %d";
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from the entity registry; every value is a placeholder passed to prepare().
			$candidates = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, $terms, $terms, array( $limit * 3 ) ) ), ARRAY_A );
		}
		if ( ! $candidates ) {
			// LIKE fallback: short queries, stopwords, rows FULLTEXT has not seen yet, hosts without FULLTEXT.
			if ( '' !== $text ) {
				$like    = '%' . $wpdb->esc_like( $text ) . '%';
				$where[] = $staff ? '(s.title LIKE %s OR s.keywords LIKE %s OR s.body LIKE %s OR s.staff_body LIKE %s)' : '(s.title LIKE %s OR s.keywords LIKE %s OR s.body LIKE %s)';
				$args    = array_merge( $args, array_fill( 0, $staff ? 4 : 3, $like ) );
			}
			$sql        = "SELECT s.object_type, s.object_id FROM {$this->table()} s WHERE " . implode( ' AND ', $where ) . ' ORDER BY s.updated_at DESC LIMIT %d';
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from the entity registry; every value is a placeholder passed to prepare().
			$candidates = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $limit * 3 ) ) ), ARRAY_A );
		}

		$by_type = array();
		foreach ( $candidates as $candidate ) {
			$by_type[ (string) $candidate['object_type'] ][] = (int) $candidate['object_id'];
		}
		$visible = array();
		foreach ( $by_type as $name => $ids ) {
			$visible[ $name ] = $this->visible( $actor, $name, $ids );
		}
		$results = array();
		foreach ( $candidates as $candidate ) {
			$item = $visible[ (string) $candidate['object_type'] ][ (int) $candidate['object_id'] ] ?? null;
			if ( $item ) {
				$results[] = $item;
			}
			if ( count( $results ) >= $limit ) {
				break;
			}
		}
		if ( '' !== trim( $q ) && ! $actor->is_guest() ) {
			$this->remember( $actor, $q );
		}
		return array(
			'data' => $results,
			'meta' => array(
				'query'     => $text,
				'operators' => $ops,
			),
		);
	}

	/**
	 * Visible records of one type among candidate ids, with display data.
	 * Public so that another index over the same records (an add-on's
	 * search by meaning) shows only what this person may see, by the same
	 * rule this one does.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $name  Entity.
	 * @param int[]  $ids   Ids.
	 * @return array<int, array<string, mixed>>
	 */
	public function visible( Actor $actor, string $name, array $ids ): array {
		global $wpdb;
		if ( ! $this->plugin->entities()->has( $name ) ) {
			return array();
		}
		$entity = $this->plugin->entities()->get( $name );
		$scope  = $this->plugin->policy()->scope( $actor, $entity );
		if ( null !== $scope && '1=0' === $scope[0] ) {
			return array();
		}
		$title  = $entity->has_column( $entity->title_field ) ? 't.' . $entity->title_field : "''";
		$number = '' !== $entity->series ? 't.number' : 'NULL';
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$where  = "t.id IN ({$in})" . ( $entity->soft_delete ? ' AND t.deleted_at IS NULL' : '' );
		$args   = $ids;
		if ( null !== $scope ) {
			$where .= ' AND (' . $scope[0] . ')';
			$args   = array_merge( $args, $scope[1] );
		}
		$archived = $entity->soft_delete ? 't.archived_at' : 'NULL';
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names come from the entity registry; every value is a placeholder passed to prepare().
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.id, t.uid, {$title} AS label, {$number} AS number, {$archived} AS archived_at FROM {$entity->table()} t WHERE {$where}", $args ), ARRAY_A );
		$out      = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['id'] ] = array(
				'type'       => $name,
				'type_label' => '' !== $entity->label ? $entity->label : $name,
				'icon'       => $entity->icon,
				'uid'        => (string) $row['uid'],
				'label'      => wp_strip_all_tags( (string) $row['label'] ),
				'number'     => $row['number'],
				'archived'   => ! empty( $row['archived_at'] ),
				'path'       => '' !== $entity->path ? str_replace( '{uid}', (string) $row['uid'], $entity->path ) : null,
			);
		}
		return $out;
	}

	/**
	 * Who `from:` names: "me", or people whose name, handle or email holds
	 * the words. Bounded, so a one-letter name cannot build a huge query.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $who   What was typed.
	 * @return int[] Member ids.
	 */
	private function authors( Actor $actor, string $who ): array {
		global $wpdb;
		$who = trim( ltrim( $who, '@' ) );
		if ( '' === $who ) {
			return array();
		}
		if ( 'me' === strtolower( $who ) ) {
			return $actor->member_id ? array( (int) $actor->member_id ) : array();
		}
		// Naming somebody is a question about the people here, and the
		// client portal has no list of them. Answered, it would say whether
		// an address or a name belongs to anybody at this workspace — one
		// guess at a time, from outside. A client can still say "from:me".
		if ( $actor->is_client() ) {
			return array();
		}
		$members = esc_sql( $this->plugin->entities()->get( 'members' )->table() );
		$like    = '%' . $wpdb->esc_like( $who ) . '%';
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$members} WHERE name LIKE %s OR handle LIKE %s OR email LIKE %s ORDER BY id LIMIT 20", $like, $like, $like ) ) );
	}

	/**
	 * Maps "type:" aliases (singular, plural, labels) to entity names.
	 *
	 * @param string $alias Alias.
	 * @return string
	 */
	private function type_from_alias( string $alias ): string {
		$alias = strtolower( $alias );
		foreach ( $this->plugin->entities()->all() as $entity ) {
			if ( ! $entity->searchable ) {
				continue;
			}
			$names = array( $entity->name, rtrim( $entity->name, 's' ), strtolower( $entity->label ), strtolower( $entity->plural_label ) );
			if ( in_array( $alias, $names, true ) ) {
				return $entity->name;
			}
		}
		return $alias;
	}

	/**
	 * Stores a recent search (last 10 per user).
	 *
	 * @param Actor  $actor Actor.
	 * @param string $q     Query.
	 * @return void
	 */
	private function remember( Actor $actor, string $q ): void {
		$recent = $this->recent( $actor );
		$recent = array_values( array_diff( $recent, array( $q ) ) );
		array_unshift( $recent, mb_substr( $q, 0, 200 ) );
		update_user_meta( $actor->user_id, '_cliezoo_recent_searches', array_slice( $recent, 0, 10 ) );
	}

	/**
	 * Recent searches.
	 *
	 * @param Actor $actor Actor.
	 * @return string[]
	 */
	public function recent( Actor $actor ): array {
		$recent = get_user_meta( $actor->user_id, '_cliezoo_recent_searches', true );
		return is_array( $recent ) ? array_values( array_map( 'strval', $recent ) ) : array();
	}
}
