<?php
/**
 * Notes, to-dos, comments, favourites and saved views on any record.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Records;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\ListQuery;
use Cliezoo\Core\Data\ObjectRef;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Operations on the generic attached records defined by the core module.
 */
final class RecordsModule extends BaseModule {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'records';
	}

	/**
	 * {@inheritDoc}
	 */
	public function switchable(): bool {
		return false;
	}

	/**
	 * Registers hooks and actions.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		$plugin->container()->set( Comments::class, static fn(): Comments => new Comments( $plugin ) );
		add_filter( 'cliezoo_entity_verbs', array( $this, 'verbs' ), 10, 2 );
		add_filter(
			'cliezoo_present_comments',
			static fn( array $out, Entity $entity, array $rows, Actor $actor ): array => $plugin->get( Comments::class )->present( $out, $rows, $actor ),
			10,
			4
		);
		// Saying something is telling somebody: the people it names, and
		// the people who follow the record it is said on.
		add_action(
			'cliezoo_comment_added',
			static function ( array $comment, Entity $entity, array $row, Actor $actor ) use ( $plugin ): void {
				$plugin->get( Comments::class )->tell( $comment, $entity, $row, $actor );
			},
			10,
			4
		);
		// An edit that names somebody new tells them, and only them.
		add_action(
			'cliezoo_comments_updated',
			static function ( array $updated, array $before, array $changes, Actor $actor ) use ( $plugin ): void {
				if ( ! isset( $changes['body'] ) || ! $plugin->entities()->has( (string) $updated['object_type'] ) ) {
					return;
				}
				$comments = $plugin->get( Comments::class );
				$before   = $comments->mentioned( (string) $before['body'] );
				$added    = array();
				foreach ( $comments->mentioned( (string) $updated['body'] ) as $member_id ) {
					if ( ! in_array( $member_id, $before, true ) ) {
						$added[] = $member_id;
					}
				}
				$parent = $plugin->entities()->get( (string) $updated['object_type'] );
				$row    = $plugin->service( $parent->name )->repository()->find( (int) $updated['object_id'] );
				if ( $added && $row && empty( $updated['send_at'] ) ) {
					$comments->tell( $updated, $parent, $row, $actor, $added );
				}
			},
			10,
			4
		);
		// A file attached in the composer is for the team until the message
		// carrying it is sent (Comments::share). Discarded, or sent in an
		// internal note, it never reached anybody else.
		add_action(
			'cliezoo_file_uploaded',
			static function ( array $file ) use ( $plugin ): void {
				if ( 'message' === (string) ( $file['purpose'] ?? '' ) ) {
					$plugin->service( 'files' )->repository()->update( (int) $file['id'], array( 'access' => 'internal' ) );
				}
			}
		);
		add_action( 'cliezoo_register_actions', array( $this, 'actions' ) );
		add_filter( 'cliezoo_saved_views_before_create', array( $this, 'own_row' ), 10, 2 );
		add_action( 'cliezoo_saved_views_created', array( $this, 'one_default' ) );
		add_action( 'cliezoo_saved_views_updated', array( $this, 'one_default' ) );
		add_filter( 'cliezoo_todos_before_update', array( $this, 'todo_done' ), 10, 2 );
		add_filter( 'cliezoo_comments_before_update', array( $this, 'comment_edited' ), 10, 2 );
		// What a note, a to-do or a comment is attached to is decided when it
		// is written, by the route that checked the record. An edit that could
		// change it would let anybody who may edit their own comment put it on
		// any record at all — another client's thread, a private message —
		// past every check that writing there makes.
		foreach ( array( 'notes', 'todos', 'comments' ) as $attached ) {
			add_filter(
				'cliezoo_' . $attached . '_before_update',
				static function ( array $data ): array {
					unset( $data['object_type'], $data['object_id'], $data['parent_id'] );
					return $data;
				},
				5
			);
		}
		add_filter(
			'cliezoo_comments_before_update',
			static function ( array $data, array $row, Actor $actor ) use ( $plugin ): array {
				// Only the team decides what is a note, and a note becomes
				// something the client reads only by the hand that wrote it.
				if ( array_key_exists( 'internal', $data ) && (bool) $data['internal'] !== (bool) $row['internal'] ) {
					$author = (int) $row['created_by'] === $actor->member_id;
					if ( ! $actor->is_staff() || ( ! empty( $row['internal'] ) && ! $author ) ) {
						unset( $data['internal'] );
					}
				}
				if ( isset( $data['body'] ) ) {
					$data['body'] = $plugin->get( Comments::class )->tidy( (string) $data['body'] );
				}
				return $data;
			},
			6,
			3
		);
		add_action( 'cliezoo_record_purging', array( $this, 'purge_attached' ), 10, 2 );
		LinkActions::hooks( $plugin );
	}

	/**
	 * Attached records are listed per parent; favourites are toggled.
	 *
	 * @param string[] $verbs  Verbs.
	 * @param Entity   $entity Entity.
	 * @return string[]
	 */
	public function verbs( array $verbs, Entity $entity ): array {
		if ( in_array( $entity->name, array( 'notes', 'todos', 'comments' ), true ) ) {
			return array( 'get', 'update', 'trash', 'restore' );
		}
		if ( 'favourites' === $entity->name || 'public_links' === $entity->name ) {
			return array();
		}
		// A view that is no longer wanted is deleted by its owner; there is
		// no trash for them, so DELETE removes it at once.
		if ( 'saved_views' === $entity->name ) {
			return array( 'list', 'get', 'create', 'update', 'trash' );
		}
		return $verbs;
	}

	/**
	 * Saved views belong to their creator.
	 *
	 * @param array<string, mixed> $data  Data.
	 * @param Actor                $actor Actor.
	 * @return array<string, mixed>
	 */
	public function own_row( array $data, Actor $actor ): array {
		$data['member_id'] = $actor->member_id;
		return $data;
	}

	/**
	 * A person has one default view per list: the list opens on it. Making
	 * a view the default quietly stops the one before it being one, rather
	 * than leaving two that disagree about which the list opens on.
	 *
	 * @param array<string, mixed> $row The view as saved.
	 * @return void
	 */
	public function one_default( array $row ): void {
		if ( empty( $row['is_default'] ) ) {
			return;
		}
		$repo   = $this->plugin->service( 'saved_views' )->repository();
		$others = $repo->where(
			array(
				'member_id'  => (int) $row['member_id'],
				'entity'     => (string) $row['entity'],
				'is_default' => 1,
			)
		);
		foreach ( $others as $other ) {
			if ( (int) $other['id'] !== (int) $row['id'] ) {
				$repo->update( (int) $other['id'], array( 'is_default' => 0 ) );
			}
		}
	}

	/**
	 * Stamps done_at when a to-do is completed (cleared when reopened).
	 *
	 * @param array<string, mixed> $data Changes.
	 * @param array<string, mixed> $row  Current row.
	 * @return array<string, mixed>
	 */
	public function todo_done( array $data, array $row ): array {
		if ( array_key_exists( 'done', $data ) && (int) $data['done'] !== (int) $row['done'] ) {
			$data['done_at'] = $data['done'] ? gmdate( 'Y-m-d H:i:s' ) : null;
		}
		return $data;
	}

	/**
	 * Marks edited comments.
	 *
	 * @param array<string, mixed> $data Changes.
	 * @param array<string, mixed> $row  Current row.
	 * @return array<string, mixed>
	 */
	public function comment_edited( array $data, array $row ): array {
		if ( isset( $data['body'] ) && $data['body'] !== $row['body'] ) {
			$data['edited_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		return $data;
	}

	/**
	 * Deletes attached rows of a permanently deleted record (no orphans).
	 *
	 * @param Entity               $entity Entity.
	 * @param array<string, mixed> $row    Row.
	 * @return void
	 */
	public function purge_attached( Entity $entity, array $row ): void {
		foreach ( array( 'notes', 'todos', 'comments', 'favourites', 'public_links' ) as $attached ) {
			$this->plugin->service( $attached )->repository()->delete_where(
				array(
					'object_type' => $entity->name,
					'object_id'   => (int) $row['id'],
				)
			);
		}
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'cliezoo_access',
			array(
				'object_type' => $entity->name,
				'object_id'   => (int) $row['id'],
			)
		);
	}

	/**
	 * Registers actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @return void
	 */
	public function actions( ActionRegistry $registry ): void {
		$ref = ObjectRef::schema();
		foreach ( array(
			'notes'    => __( 'notes', 'cliezoo' ),
			'todos'    => __( 'to-dos', 'cliezoo' ),
			'comments' => __( 'comments', 'cliezoo' ),
		) as $type => $plural ) {
			$registry->add(
				Op::make( $type . '.for', sprintf( /* translators: %s: plural record type. */ __( 'List %s of a record', 'cliezoo' ), $plural ) )
					->input( $ref + Op::paging( 100 ) + array( 'newest' => array( 'type' => 'boolean' ) ) )
					->run( fn( Actor $actor, array $in ) => $this->attached( $type, $actor, $in ) )
					->rest( 'GET', '/' . $type )
					->readonly()
					->with( array( 'mcp' => 'notes' === $type ? 'read' : null ) )
			);
		}

		$registry->add(
			Op::make( 'notes.add', __( 'Add a note to a record', 'cliezoo' ) )
				->describe( __( 'Adds an internal note to any record (client, project, deal, task…). Notes are never visible to clients.', 'cliezoo' ) )
				->input(
					$ref + array(
						'body'   => Op::str( 20000, true ),
						'pinned' => array( 'type' => 'boolean' ),
					)
				)
				->run( fn( Actor $actor, array $in ) => $this->add( 'notes', $actor, $in, array( 'body', 'pinned' ) ) )
				->rest( 'POST', '/notes' )
				->with( array( 'mcp' => 'safe' ) )
		);
		$registry->add(
			Op::make( 'todos.add', __( 'Add a to-do to a record', 'cliezoo' ) )
				->describe( __( 'Adds a quick to-do to any record (client, lead, deal, activity item), optionally with a due date and assignee.', 'cliezoo' ) )
				->input(
					$ref + array(
						'title'    => Op::str( 190, true ),
						'due_date' => array(
							'type'   => 'string',
							'format' => 'date',
						),
						'assignee' => Op::uid( false ),
						'activity' => Op::uid( false ),
					)
				)
				->run( fn( Actor $actor, array $in ) => $this->add( 'todos', $actor, $in, array( 'title', 'due_date', 'assignee' ) ) )
				->rest( 'POST', '/todos' )
				->with( array( 'mcp' => 'safe' ) )
		);
		$registry->add(
			Op::make( 'todos.mine', __( 'My to-dos', 'cliezoo' ) )
				->input(
					array(
						'done' => array( 'type' => 'boolean' ),
					) + Op::paging( 100 )
				)
				->run( fn( Actor $actor, array $in ) => $this->my_todos( $actor, $in ) )
				->rest( 'GET', '/todos/mine' )
				->readonly()
		);
		$registry->add(
			Op::make( 'comments.add', __( 'Comment on a record', 'cliezoo' ) )
				->input(
					$ref + array(
						'body'     => Op::str( 20000, true ),
						'parent'   => Op::uid( false ),
						'internal' => array( 'type' => 'boolean' ),
						'anchor'   => array( 'type' => 'object' ),
						'files'    => array(
							'type'     => 'array',
							'items'    => Op::uid(),
							'maxItems' => Comments::FILES,
						),
					)
				)
				->run( fn( Actor $actor, array $in ) => $this->add_comment( $actor, $in ) )
				->rest( 'POST', '/comments' )
		);
		foreach ( array( 'resolve', 'reopen' ) as $verb ) {
			$registry->add(
				Op::make( 'comments.' . $verb, 'resolve' === $verb ? __( 'Resolve a comment', 'cliezoo' ) : __( 'Reopen a comment', 'cliezoo' ) )
					->input( array( 'uid' => Op::uid() ) )
					->run( fn( Actor $actor, array $in ) => $this->resolve( $actor, (string) $in['uid'], 'resolve' === $verb ) )
					->rest( 'POST', '/comments/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/' . $verb )
			);
		}
		$registry->add(
			Op::make( 'favourites.list', __( 'My favourites', 'cliezoo' ) )
				->run( fn( Actor $actor ) => $this->favourites( $actor ) )
				->rest( 'GET', '/favourites' )
				->readonly()
		);
		$registry->add(
			Op::make( 'favourites.toggle', __( 'Add or remove a favourite', 'cliezoo' ) )
				->input( $ref + array( 'favourite' => array( 'type' => 'boolean' ) ) )
				->run( fn( Actor $actor, array $in ) => $this->toggle_favourite( $actor, $in ) )
				->rest( 'POST', '/favourites' )
		);
		$registry->add(
			Op::make( 'records.refs', __( 'Describe records', 'cliezoo' ) )
				->describe( __( 'Turns a list of "type:uid" references into chips (title, number), skipping anything the person may not see.', 'cliezoo' ) )
				->input(
					array(
						'refs' => array(
							'type'     => 'array',
							'required' => true,
							'maxItems' => 50,
							'items'    => array(
								'type'    => 'string',
								'pattern' => '^[a-z_]{2,40}:[0-9A-HJKMNP-TV-Z]{26}$',
							),
						),
					)
				)
				->run( fn( Actor $actor, array $in ) => $this->refs( $actor, (array) $in['refs'] ) )
				->rest( 'GET', '/records/refs' )
				->readonly()
		);
	}

	/**
	 * Chips for `type:uid` references, in the order they were asked for.
	 *
	 * @param Actor             $actor Actor.
	 * @param array<int, mixed> $refs  References.
	 * @return array<int, array<string, mixed>>
	 */
	private function refs( Actor $actor, array $refs ): array {
		$wanted = array();
		foreach ( $refs as $ref ) {
			list( $type, $uid ) = explode( ':', (string) $ref, 2 );
			if ( $this->plugin->entities()->has( $type ) ) {
				$wanted[ $type ][] = $uid;
			}
		}
		$found = array();
		foreach ( $wanted as $type => $uids ) {
			$entity = $this->plugin->entities()->get( $type );
			if ( ! $entity->public ) {
				continue;
			}
			$repo = $this->plugin->service( $type )->repository();
			foreach ( $uids as $uid ) {
				$row = $repo->find_by_uid( (string) $uid );
				if ( ! $row || ( $entity->soft_delete && ! empty( $row['deleted_at'] ) ) || ! $this->plugin->policy()->can( $actor, Policy::VIEW, $entity, $row ) ) {
					continue;
				}
				$found[ $type . ':' . $uid ] = array(
					'type'   => $type,
					'uid'    => (string) $row['uid'],
					'label'  => $entity->has_column( $entity->title_field ) ? (string) $row[ $entity->title_field ] : '',
					'number' => '' !== $entity->series ? (string) ( $row['number'] ?? '' ) : null,
				);
			}
		}
		$out = array();
		foreach ( $refs as $ref ) {
			if ( isset( $found[ (string) $ref ] ) ) {
				$out[] = $found[ (string) $ref ];
			}
		}
		return $out;
	}

	/**
	 * Lists attached rows of a parent the actor can see.
	 *
	 * @param string               $type  notes|todos|comments.
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    Input.
	 * @return array<string, mixed>
	 */
	private function attached( string $type, Actor $actor, array $in ): array {
		list( $parent, $row ) = ObjectRef::resolve( $this->plugin, $actor, (string) $in['object_type'], (string) $in['object'] );
		$service              = $this->plugin->service( $type );
		$query                = new ListQuery();
		$query->limit         = (int) ( $in['limit'] ?? 100 );
		$query->cursor        = (string) ( $in['cursor'] ?? '' );
		// A conversation reads oldest first; a long one is fetched newest
		// first, a page at a time, so the latest message is always there.
		$query->sort = 'comments' === $type && empty( $in['newest'] ) ? 'created_at' : '-created_at';
		$query->where( 't.object_type = %s AND t.object_id = %d', array( $parent->name, (int) $row['id'] ) );
		if ( 'notes' === $type && $actor->is_client() ) {
			// Notes are internal.
			$query->where( '1=0' );
		}
		if ( 'comments' === $type && $actor->is_client() ) {
			$query->where( 't.internal = 0' );
		}
		// A message held for later is its author's until it goes.
		if ( 'comments' === $type && $service->entity()->has_column( 'send_at' ) ) {
			$query->where( '(t.send_at IS NULL OR t.created_by = %d)', array( $actor->member_id ) );
		}
		/** This action is documented in src/Core/Data/EntityService.php */
		do_action( "cliezoo_{$type}_list_query", $query, $actor );
		$result = $service->repository()->list( $query, null );
		return array(
			'data' => $this->plugin->serializer()->present_many( $service->entity(), $result['rows'], $actor ),
			'meta' => array(
				'next_cursor' => $result['next_cursor'],
				'has_more'    => $result['has_more'],
			),
		);
	}

	/**
	 * Adds an attached row after checking the parent.
	 *
	 * @param string               $type   Entity.
	 * @param Actor                $actor  Actor.
	 * @param array<string, mixed> $in     Input.
	 * @param string[]             $fields Allowed input keys.
	 * @return array<string, mixed>
	 * @throws ActionError When the parent is not accessible.
	 */
	private function add( string $type, Actor $actor, array $in, array $fields ): array {
		list( $parent, $row ) = ObjectRef::resolve( $this->plugin, $actor, (string) $in['object_type'], (string) $in['object'] );
		if ( 'notes' === $type && ! $actor->is_staff() ) {
			throw ActionError::forbidden();
		}
		$input   = array_intersect_key( $in, array_flip( $fields ) );
		$data    = array(
			'object_type' => $parent->name,
			'object_id'   => (int) $row['id'],
		);
		$service = $this->plugin->service( $type );
		$created = $service->create_row( $actor, array_merge( $input, $data ) );
		do_action( 'cliezoo_' . $type . '_added', $created, $parent, $row, $actor );
		return $service->present( $actor, $created );
	}

	/**
	 * Adds a comment (clients may comment where the parent allows it; internal notes are staff-only).
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    Input.
	 * @return array<string, mixed>
	 * @throws ActionError When not allowed.
	 */
	private function add_comment( Actor $actor, array $in ): array {
		list( $parent, $row ) = ObjectRef::resolve( $this->plugin, $actor, (string) $in['object_type'], (string) $in['object'] );
		// Clients are allowed to comment on what they can see; resolving the
		// parent is the check that they can.
		$created = $this->plugin->get( Comments::class )->post( $actor, $parent, $row, $in );
		return $this->plugin->service( 'comments' )->present( $actor, $created );
	}

	/**
	 * Resolves or reopens a comment thread.
	 *
	 * @param Actor  $actor   Actor.
	 * @param string $uid     Comment uid.
	 * @param bool   $resolve Resolve (true) or reopen.
	 * @return array<string, mixed>
	 */
	private function resolve( Actor $actor, string $uid, bool $resolve ): array {
		$service = $this->plugin->service( 'comments' );
		// Closing or reopening a thread changes it, so it needs write rights
		// on the comment, not just the right to read it.
		$row = $service->load( $actor, $uid, Policy::UPDATE );
		$service->repository()->update(
			(int) $row['id'],
			array(
				'resolved'    => $resolve ? 1 : 0,
				'resolved_by' => $resolve ? $actor->member_id : null,
				'resolved_at' => $resolve ? gmdate( 'Y-m-d H:i:s' ) : null,
			)
		);
		$updated = (array) $service->repository()->find( (int) $row['id'] );
		do_action( 'cliezoo_comment_' . ( $resolve ? 'resolved' : 'reopened' ), $updated, $actor );
		return $service->present( $actor, $updated );
	}

	/**
	 * To-dos assigned to (or created by) the actor across records.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    Input.
	 * @return array<string, mixed>
	 */
	private function my_todos( Actor $actor, array $in ): array {
		$service       = $this->plugin->service( 'todos' );
		$query         = new ListQuery();
		$query->limit  = (int) ( $in['limit'] ?? 100 );
		$query->cursor = (string) ( $in['cursor'] ?? '' );
		$query->sort   = '-created_at';
		$query->where( '(t.assignee_id = %d OR (t.assignee_id IS NULL AND t.created_by = %d))', array( $actor->member_id, $actor->member_id ) );
		if ( isset( $in['done'] ) ) {
			$query->where( 't.done = %d', array( $in['done'] ? 1 : 0 ) );
		}
		$result = $service->repository()->list( $query, null );
		$data   = $this->plugin->serializer()->present_many( $service->entity(), $result['rows'], $actor );
		return array(
			'data' => $this->with_parents( $data, $result['rows'], $actor ),
			'meta' => array(
				'next_cursor' => $result['next_cursor'],
				'has_more'    => $result['has_more'],
			),
		);
	}

	/**
	 * Adds a `record` chip (type + uid + label) for each attached row's parent.
	 *
	 * @param array<int, array<string, mixed>> $data Presented rows.
	 * @param array<int, array<string, mixed>> $rows  Raw rows (same order).
	 * @param Actor                            $actor Who is reading: a record they may not see is not named to them.
	 * @return array<int, array<string, mixed>>
	 */
	private function with_parents( array $data, array $rows, Actor $actor ): array {
		$ids = array();
		foreach ( $rows as $row ) {
			$ids[ (string) $row['object_type'] ][] = (int) $row['object_id'];
		}
		$refs = array();
		foreach ( $ids as $type => $list ) {
			if ( ! $this->plugin->entities()->has( $type ) ) {
				continue;
			}
			// Only the records this person may open: a to-do given to them on
			// one they cannot see says nothing about it.
			$entity  = $this->plugin->entities()->get( $type );
			$visible = array();
			foreach ( $this->plugin->service( $type )->repository()->find_many( $list ) as $parent ) {
				if ( empty( $parent['deleted_at'] ) && $this->plugin->policy()->can( $actor, Policy::VIEW, $entity, $parent ) ) {
					$visible[] = (int) $parent['id'];
				}
			}
			$refs[ $type ] = $visible ? ObjectRef::refs( $this->plugin, $type, $visible ) : array();
		}
		foreach ( $rows as $i => $row ) {
			$ref                  = $refs[ (string) $row['object_type'] ][ (int) $row['object_id'] ] ?? null;
			$data[ $i ]['record'] = $ref ? array( 'type' => (string) $row['object_type'] ) + $ref : null;
		}
		return $data;
	}

	/**
	 * The actor's favourites with record chips (records no longer visible are skipped).
	 *
	 * @param Actor $actor Actor.
	 * @return array<int, array<string, mixed>>
	 */
	private function favourites( Actor $actor ): array {
		$rows = $this->plugin->service( 'favourites' )->repository()->where( array( 'member_id' => $actor->member_id ), 'position' );
		$ids  = array();
		foreach ( $rows as $row ) {
			$ids[ (string) $row['object_type'] ][] = (int) $row['object_id'];
		}
		$out = array();
		foreach ( $ids as $type => $list ) {
			if ( ! $this->plugin->entities()->has( $type ) ) {
				continue;
			}
			$entity = $this->plugin->entities()->get( $type );
			$repo   = $this->plugin->service( $type )->repository();
			foreach ( $repo->find_many( $list ) as $row ) {
				if ( ( $entity->soft_delete && ! empty( $row['deleted_at'] ) ) || ! $this->plugin->policy()->can( $actor, Policy::VIEW, $entity, $row ) ) {
					continue;
				}
				$out[] = array(
					'type'  => $type,
					'uid'   => (string) $row['uid'],
					'label' => $entity->has_column( $entity->title_field ) ? (string) $row[ $entity->title_field ] : '',
					'meta'  => array_filter(
						array(
							'color'  => $row['color'] ?? null,
							'icon'   => $row['icon'] ?? null,
							// A starred view opens its list, so the list
							// it belongs to is said.
							'entity' => 'saved_views' === $type ? ( $row['entity'] ?? null ) : null,
						)
					),
				);
			}
		}
		return $out;
	}

	/**
	 * Adds or removes a favourite.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    Input.
	 * @return array{favourite: bool}
	 * @throws \Throwable When it could not be saved for any reason but a press that got there first.
	 */
	private function toggle_favourite( Actor $actor, array $in ): array {
		list( $entity, $row ) = ObjectRef::resolve( $this->plugin, $actor, (string) $in['object_type'], (string) $in['object'] );
		$repo                 = $this->plugin->service( 'favourites' )->repository();
		$where                = array(
			'member_id'   => $actor->member_id,
			'object_type' => $entity->name,
			'object_id'   => (int) $row['id'],
		);
		$existing             = $repo->first( $where );
		$want                 = isset( $in['favourite'] ) ? (bool) $in['favourite'] : ! $existing;
		if ( $want && ! $existing ) {
			try {
				$repo->insert( $where + array( 'position' => 0 ), $actor->member_id );
			} catch ( \Throwable $failed ) {
				// Two presses at once: the other one made it first, and a
				// favourite that exists is what was asked for.
				if ( ! $repo->first( $where ) ) {
					throw $failed;
				}
			}
		} elseif ( ! $want && $existing ) {
			$repo->delete( (int) $existing['id'] );
		}
		return array( 'favourite' => $want );
	}
}
