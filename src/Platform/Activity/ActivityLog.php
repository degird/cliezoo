<?php
/**
 * Per-record activity timeline.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Activity;

use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\ListQuery;
use Cliezoo\Core\Data\Repository;
use Cliezoo\Core\Data\Serializer;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes and reads activity: created/updated (old → new)/status changes/
 * archive/trash/restore plus module events (viewed, signed, paid…).
 */
final class ActivityLog {

	private const IGNORED = array( 'updated_at', 'created_at', 'archived_at', 'deleted_at' );

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
	 * Hooks record events.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return void
	 */
	public static function listen( Plugin $plugin ): void {
		$log = static fn(): ActivityLog => $plugin->get( self::class );
		add_action(
			'cliezoo_record_created',
			static function ( Entity $entity, array $row, Actor $actor ) use ( $log ): void {
				$log()->record( $entity, $row, 'created', $actor );
			},
			10,
			3
		);
		add_action(
			'cliezoo_record_updated',
			static function ( Entity $entity, array $row, array $old, array $changes, Actor $actor ) use ( $log ): void {
				$log()->record_changes( $entity, $row, $changes, $actor );
			},
			10,
			5
		);
		foreach ( array( 'trashed', 'restored', 'archived', 'unarchived' ) as $event ) {
			add_action(
				'cliezoo_record_' . $event,
				static function ( Entity $entity, array $row, Actor $actor ) use ( $log, $event ): void {
					$log()->record( $entity, $row, $event, $actor );
				},
				10,
				3
			);
		}
		add_action(
			'cliezoo_record_purging',
			static function ( Entity $entity, array $row ) use ( $plugin ): void {
				// Timeline rows of a permanently deleted record go with it (no orphans).
				$plugin->service( 'activity' )->repository()->delete_where(
					array(
						'object_type' => $entity->name,
						'object_id'   => (int) $row['id'],
					)
				);
			},
			10,
			2
		);
	}

	/**
	 * Records a simple event.
	 *
	 * @param Entity               $entity  Entity.
	 * @param array<string, mixed> $row     Row.
	 * @param string               $action  Action key (created, viewed, signed…).
	 * @param Actor                $actor   Actor.
	 * @param array<string, mixed> $meta    Extra data.
	 * @param bool                 $client  Visible to the client in the portal.
	 * @param string               $summary Optional human summary.
	 * @return void
	 */
	public function record( Entity $entity, array $row, string $action, Actor $actor, array $meta = array(), bool $client = false, string $summary = '' ): void {
		if ( ! $entity->activity ) {
			return;
		}
		/**
		 * Whether a record's history stays out of the activity feed: a
		 * direct message is between its people, and the feed is read by
		 * people who are not them.
		 *
		 * @param bool   $skip   Default false.
		 * @param Entity $entity Entity.
		 * @param array  $row    Row.
		 */
		if ( apply_filters( 'cliezoo_activity_skip', false, $entity, $row ) ) {
			return;
		}
		$this->insert( $entity, $row, $action, $actor, null, $meta, $client, $summary );
	}

	/**
	 * Records field changes with display values.
	 *
	 * @param Entity                                $entity  Entity.
	 * @param array<string, mixed>                  $row     Updated row.
	 * @param array<string, array{0:mixed,1:mixed}> $changes Column => [old, new].
	 * @param Actor                                 $actor   Actor.
	 * @return void
	 */
	public function record_changes( Entity $entity, array $row, array $changes, Actor $actor ): void {
		if ( ! $entity->activity ) {
			return;
		}
		/**
		 * Whether a record's history stays out of the activity feed: a
		 * direct message is between its people, and the feed is read by
		 * people who are not them.
		 *
		 * @param bool   $skip   Default false.
		 * @param Entity $entity Entity.
		 * @param array  $row    Row.
		 */
		if ( apply_filters( 'cliezoo_activity_skip', false, $entity, $row ) ) {
			return;
		}
		$diff = array();
		foreach ( $changes as $column => $pair ) {
			if ( in_array( $column, self::IGNORED, true ) ) {
				continue;
			}
			$field = $entity->field( (string) $column );
			if ( $field && $field->hidden ) {
				continue;
			}
			$diff[ $entity->input_key( (string) $column ) ] = array(
				'from'    => $this->display( $entity, (string) $column, $pair[0] ),
				'to'      => $this->display( $entity, (string) $column, $pair[1] ),
				'finance' => $field ? $field->finance : false,
			);
		}
		if ( ! $diff ) {
			return;
		}
		$action = isset( $changes['status'] ) || isset( $changes['stage_id'] ) || isset( $changes['list_id'] ) ? 'status_changed' : 'updated';
		$this->insert( $entity, $row, $action, $actor, $diff );
	}

	/**
	 * Readable value for the diff (references → labels, JSON shortened).
	 *
	 * @param Entity $entity Entity.
	 * @param string $column Column.
	 * @param mixed  $value  Raw value.
	 * @return mixed
	 */
	private function display( Entity $entity, string $column, $value ) {
		$field = $entity->field( $column );
		if ( ! $field || null === $value ) {
			return $value;
		}
		if ( in_array( $field->type, array( 'ref', 'user' ), true ) && $this->plugin->entities()->has( $field->ref ) ) {
			$repo = new Repository( $this->plugin->entities()->get( $field->ref ), $this->plugin->entities() );
			$refs = $repo->refs( array( (int) $value ) );
			return isset( $refs[ (int) $value ] ) ? $refs[ (int) $value ]['label'] : null;
		}
		if ( in_array( $field->type, array( 'json', 'html', 'longtext' ), true ) ) {
			return null;
		}
		if ( 'bool' === $field->type ) {
			return (bool) $value;
		}
		if ( 'money' === $field->type || 'int' === $field->type ) {
			return (int) $value;
		}
		return is_string( $value ) ? mb_substr( $value, 0, 200 ) : $value;
	}

	/**
	 * Inserts an activity row.
	 *
	 * @param Entity                    $entity  Entity.
	 * @param array<string, mixed>      $row     Row.
	 * @param string                    $action  Action.
	 * @param Actor                     $actor   Actor.
	 * @param array<string, mixed>|null $changes Diff.
	 * @param array<string, mixed>      $meta    Meta.
	 * @param bool                      $client  Client-visible.
	 * @param string                    $summary Summary.
	 * @return void
	 */
	private function insert( Entity $entity, array $row, string $action, Actor $actor, ?array $changes, array $meta = array(), bool $client = false, string $summary = '' ): void {
		$client_id  = 'clients' === $entity->name ? (int) $row['id'] : ( '' !== $entity->client_field ? (int) ( $row[ $entity->client_field ] ?? 0 ) : 0 );
		$project_id = 'projects' === $entity->name ? (int) $row['id'] : ( '' !== $entity->project_field ? (int) ( $row[ $entity->project_field ] ?? 0 ) : 0 );
		$title      = $entity->has_column( $entity->title_field ) ? (string) ( $row[ $entity->title_field ] ?? '' ) : '';
		$this->plugin->service( 'activity' )->repository()->insert(
			array(
				'object_type'       => $entity->name,
				'object_id'         => (int) $row['id'],
				'client_id'         => $client_id ? $client_id : null,
				'project_id'        => $project_id ? $project_id : null,
				'action'            => $action,
				'member_id'         => $actor->member_id ? $actor->member_id : null,
				'channel'           => $actor->channel,
				'summary'           => '' !== $summary ? mb_substr( $summary, 0, 255 ) : mb_substr( wp_strip_all_tags( $title ), 0, 255 ),
				'changes'           => null === $changes ? null : wp_json_encode( $changes ),
				'meta'              => $meta ? wp_json_encode( $meta ) : null,
				'visible_to_client' => $client ? 1 : 0,
			)
		);
	}

	/**
	 * Lists activity for the actor.
	 *
	 * @param Actor                $actor  Actor.
	 * @param array<string, mixed> $filter object_type/object_id, client_id, project_id, action.
	 * @param string               $cursor Cursor.
	 * @param int                  $limit  Page size.
	 * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
	 */
	public function list( Actor $actor, array $filter, string $cursor = '', int $limit = 30 ): array {
		$entity        = $this->plugin->entities()->get( 'activity' );
		$query         = new ListQuery();
		$query->cursor = $cursor;
		$query->limit  = $limit;
		$query->sort   = '-created_at';
		foreach ( array( 'object_type', 'object_id', 'client_id', 'project_id', 'action', 'member_id' ) as $key ) {
			if ( isset( $filter[ $key ] ) ) {
				$query->where( "t.{$key} = " . ( is_int( $filter[ $key ] ) ? '%d' : '%s' ), array( $filter[ $key ] ) );
			}
		}
		// Only a single-record feed skips area scoping: the caller resolved
		// that record first. A client or project feed still spans areas the
		// actor may have no access to, so it stays restricted.
		$one_record = isset( $filter['object_type'], $filter['object_id'] );
		if ( ! $one_record || $actor->is_client() ) {
			$this->restrict( $query, $actor );
		}
		$repo   = new Repository( $entity, $this->plugin->entities() );
		$result = $repo->list( $query, null );
		return array(
			'data' => $this->present( $this->readable( $result['rows'], $actor ), $actor ),
			'meta' => array(
				'next_cursor' => $result['next_cursor'],
				'has_more'    => $result['has_more'],
			),
		);
	}

	/**
	 * Limits a query to activity this person may see: their own client for
	 * client users, and for staff their own actions, the projects they
	 * belong to and the areas they can see in full. Admins see everything.
	 *
	 * @param ListQuery $query Query.
	 * @param Actor     $actor Actor.
	 * @return void
	 */
	public function restrict( ListQuery $query, Actor $actor ): void {
		if ( $actor->is_client() ) {
			$query->where( 't.visible_to_client = 1 AND t.client_id = %d', array( $actor->active_client_id ) );
			return;
		}
		if ( ! $actor->is_admin() ) {
			$this->restrict_feed( $query, $actor );
		}
	}

	/**
	 * Workspace feed for non-admin staff: their own actions, projects they belong to,
	 * and areas they can see in full.
	 *
	 * @param ListQuery $query Query.
	 * @param Actor     $actor Actor.
	 * @return void
	 */
	private function restrict_feed( ListQuery $query, Actor $actor ): void {
		global $wpdb;
		$access = $wpdb->prefix . 'cliezoo_access';
		$parts  = array( 't.member_id = %d', "t.project_id IN (SELECT object_id FROM {$access} WHERE object_type = 'projects' AND principal_type = 'user' AND principal_id = %d)" );
		$args   = array( $actor->member_id, $actor->member_id );
		$types  = array();
		foreach ( Roles::AREAS as $area ) {
			if ( 'all' === $actor->scope( $area ) ) {
				foreach ( $this->plugin->entities()->all() as $entity ) {
					if ( ( '' !== $entity->permission ? $entity->permission : $entity->name ) === $area && ! $entity->shareable ) {
						$types[] = $entity->name;
					}
				}
			}
		}
		if ( $types ) {
			$parts[] = 't.object_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$args    = array_merge( $args, $types );
		}
		$query->where( implode( ' OR ', $parts ), $args );
	}

	/**
	 * Only the rows about records the reader may open.
	 *
	 * The feed's own SQL is about where a row belongs — this member, this
	 * project, these areas — and being on a project is not permission to
	 * read a private record filed under it. Rather than paraphrase the
	 * Policy in a second place and have the two drift, each record on the
	 * page is asked about: one query per kind, using the same scope a
	 * list of that kind would use.
	 *
	 * Anything that reads the log for somebody — the feed, and the
	 * "what changed" poll — filters through here, so a count cannot say
	 * that something invisible moved.
	 *
	 * @param array<int, array<string, mixed>> $rows  Rows.
	 * @param Actor                            $actor Actor.
	 * @return array<int, array<string, mixed>>
	 */
	public function readable( array $rows, Actor $actor ): array {
		if ( ! $rows || $actor->is_system() || $actor->is_admin() ) {
			return $rows;
		}
		global $wpdb;
		$policy = $this->plugin->policy();
		$ids    = array();
		foreach ( $rows as $row ) {
			$ids[ (string) $row['object_type'] ][ (int) $row['object_id'] ] = true;
		}
		$allowed = array();
		foreach ( $ids as $type => $wanted ) {
			if ( ! $this->plugin->entities()->has( $type ) ) {
				// Nothing to ask about: a row whose kind is gone says only
				// what it already said in its own summary.
				$allowed[ $type ] = $wanted;
				continue;
			}
			$entity = $this->plugin->entities()->get( $type );
			$scope  = $policy->scope( $actor, $entity );
			if ( null === $scope ) {
				$allowed[ $type ] = $wanted;
				continue;
			}
			$list             = array_keys( $wanted );
			$holes            = implode( ',', array_fill( 0, count( $list ), '%d' ) );
			$sql              = "SELECT t.id FROM {$entity->table()} t WHERE t.id IN ({$holes}) AND ( {$scope[0]} )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from the entity; the scope is the Policy's own SQL with its own placeholders.
			$found            = (array) $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $list, $scope[1] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared on this line.
			$allowed[ $type ] = array_fill_keys( array_map( 'intval', $found ), true );
		}
		return array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => isset( $allowed[ (string) $row['object_type'] ][ (int) $row['object_id'] ] )
			)
		);
	}

	/**
	 * Presents activity rows (object chips, actor chips, finance-safe diffs).
	 *
	 * @param array<int, array<string, mixed>> $rows  Rows.
	 * @param Actor                            $actor Actor.
	 * @return array<int, array<string, mixed>>
	 */
	public function present( array $rows, Actor $actor ): array {
		$members = array();
		$objects = array();
		foreach ( $rows as $row ) {
			if ( $row['member_id'] ) {
				$members[] = (int) $row['member_id'];
			}
			$objects[ (string) $row['object_type'] ][] = (int) $row['object_id'];
		}
		$member_refs = ( new Repository( $this->plugin->entities()->get( 'members' ), $this->plugin->entities() ) )->refs( $members );
		$object_refs = array();
		foreach ( $objects as $type => $ids ) {
			if ( $this->plugin->entities()->has( $type ) ) {
				$object_refs[ $type ] = ( new Repository( $this->plugin->entities()->get( $type ), $this->plugin->entities() ) )->refs( $ids );
			}
		}
		$out = array();
		foreach ( $rows as $row ) {
			$changes = null === $row['changes'] ? null : json_decode( (string) $row['changes'], true );
			if ( is_array( $changes ) ) {
				foreach ( $changes as $key => $change ) {
					if ( ! empty( $change['finance'] ) && ! $actor->sees_finance() ) {
						unset( $changes[ $key ] );
						continue;
					}
					unset( $changes[ $key ]['finance'] );
				}
			}
			$out[] = array(
				'uid'        => (string) $row['uid'],
				'action'     => (string) $row['action'],
				'summary'    => (string) $row['summary'],
				'channel'    => (string) $row['channel'],
				'actor'      => $row['member_id'] ? ( $member_refs[ (int) $row['member_id'] ] ?? null ) : null,
				'object'     => array_merge(
					array( 'type' => (string) $row['object_type'] ),
					$object_refs[ (string) $row['object_type'] ][ (int) $row['object_id'] ] ?? array(
						'uid'   => null,
						'label' => (string) $row['summary'],
					)
				),
				'changes'    => $changes,
				'meta'       => null === $row['meta'] ? null : json_decode( (string) $row['meta'], true ),
				'created_at' => Serializer::iso( $row['created_at'] ),
			);
		}
		return $out;
	}
}
