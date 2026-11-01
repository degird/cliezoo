<?php
/**
 * Generic record operations for an entity.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Data;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Create / read / update / archive / trash / restore / purge / list with
 * Policy checks, reference resolution, defaults, numbering, optimistic
 * locking, activity diffs and domain events.
 *
 * Module-specific rules plug in through filters and actions named after the
 * entity: `cliezoo_{entity}_before_create`, `cliezoo_{entity}_before_update`,
 * `cliezoo_{entity}_before_delete`, `cliezoo_{entity}_created`, `…_updated`,
 * `…_trashed`, `…_archived`, `…_restored`, `…_purged`, `…_status_changed`.
 */
final class EntityService {

	/**
	 * Entity.
	 *
	 * @var Entity
	 */
	private Entity $entity;

	/**
	 * Repository.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Registry.
	 *
	 * @var EntityRegistry
	 */
	private EntityRegistry $entities;

	/**
	 * Policy.
	 *
	 * @var Policy
	 */
	private Policy $policy;

	/**
	 * Serializer.
	 *
	 * @var Serializer
	 */
	private Serializer $serializer;

	/**
	 * Constructor.
	 *
	 * @param Entity         $entity     Entity.
	 * @param EntityRegistry $entities   Registry.
	 * @param Policy         $policy     Policy.
	 * @param Serializer     $serializer Serializer.
	 * @param callable|null  $custom     Custom-field filter builder.
	 */
	public function __construct( Entity $entity, EntityRegistry $entities, Policy $policy, Serializer $serializer, ?callable $custom = null ) {
		$this->entity     = $entity;
		$this->entities   = $entities;
		$this->policy     = $policy;
		$this->serializer = $serializer;
		$this->repository = new Repository( $entity, $entities, $custom );
	}

	/**
	 * The repository.
	 *
	 * @return Repository
	 */
	public function repository(): Repository {
		return $this->repository;
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
	 * Lists records the actor may see.
	 *
	 * @param Actor     $actor Actor.
	 * @param ListQuery $query Query.
	 * @param string[]  $fields Sparse fields.
	 * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
	 * @throws ActionError When the actor may not list this entity.
	 */
	public function list( Actor $actor, ListQuery $query, array $fields = array() ): array {
		if ( ! $this->policy->can( $actor, Policy::VIEW, $this->entity ) ) {
			throw ActionError::forbidden();
		}
		if ( 'trash' === $query->state && ! $actor->is_admin() && ! $actor->is_staff() ) {
			throw ActionError::forbidden();
		}
		/**
		 * Adjusts a list before it runs — a field some readers may not
		 * sort by, say, because the order would tell them what it holds.
		 *
		 * @param ListQuery $query Query (changed in place).
		 * @param Actor     $actor Actor.
		 */
		do_action( 'cliezoo_' . $this->entity->name . '_list_query', $query, $actor );
		$result = $this->repository->list( $query, $this->policy->scope( $actor, $this->entity ) );
		return array(
			'data' => $this->serializer->present_many( $this->entity, $result['rows'], $actor, $fields ),
			'meta' => array_filter(
				array(
					'next_cursor' => $result['next_cursor'],
					'has_more'    => $result['has_more'],
					'total'       => $result['total'],
				),
				static fn( $value ): bool => null !== $value
			),
		);
	}

	/**
	 * Loads a row the actor may see, or fails with "not found".
	 *
	 * @param Actor  $actor   Actor.
	 * @param string $uid     Uid.
	 * @param string $action  Policy action to check.
	 * @param bool   $trashed Allow trashed rows.
	 * @return array<string, mixed>
	 * @throws ActionError When missing or not visible.
	 */
	public function load( Actor $actor, string $uid, string $action = Policy::VIEW, bool $trashed = false ): array {
		$row = $this->repository->find_by_uid( $uid );
		if ( ! $row || ( ! $trashed && $this->entity->soft_delete && ! empty( $row['deleted_at'] ) ) ) {
			throw ActionError::not_found();
		}
		if ( ! $this->policy->can( $actor, Policy::VIEW, $this->entity, $row ) ) {
			throw ActionError::not_found();
		}
		if ( Policy::VIEW !== $action && ! $this->policy->can( $actor, $action, $this->entity, $row ) ) {
			throw ActionError::forbidden();
		}
		return $row;
	}

	/**
	 * Presented record.
	 *
	 * @param Actor  $actor  Actor.
	 * @param string $uid    Uid.
	 * @param bool   $opened Whether somebody opened it by itself (its own
	 *                       read action), rather than code reading it back
	 *                       after a change.
	 * @return array<string, mixed>
	 */
	public function get( Actor $actor, string $uid, bool $opened = false ): array {
		$row = $this->load( $actor, $uid, Policy::VIEW, true );

		/**
		 * Somebody opened a record by itself (recents, "last seen").
		 *
		 * @param Entity               $entity The kind of record.
		 * @param array<string, mixed> $row    The record.
		 * @param Actor                $actor  Who opened it.
		 */
		if ( $opened ) {
			do_action( 'cliezoo_record_read', $this->entity, $row, $actor );
		}

		/**
		 * Extra detail for a record read on its own.
		 *
		 * Deliberately not fired for lists. A record opened by itself can
		 * afford a few more queries — who is on it, what is attached — and
		 * a list of two hundred cannot afford them two hundred times.
		 *
		 * @param array<string, mixed> $out   The presented record.
		 * @param Actor                $actor Who is asking.
		 * @param array<string, mixed> $row   The stored row.
		 */
		return (array) apply_filters( 'cliezoo_' . $this->entity->name . '_read', $this->present( $actor, $row ), $actor, $row );
	}

	/**
	 * Presents a row.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $row   Row.
	 * @return array<string, mixed>
	 */
	public function present( Actor $actor, array $row ): array {
		$out         = $this->serializer->present( $this->entity, $row, $actor );
		$out['etag'] = self::etag( $row, $this->entity->name );
		return $out;
	}

	/**
	 * ETag of a row version: what the row holds, not when it was saved —
	 * a time kept to the second would give two saves in one second the
	 * same tag, and the second would overwrite the first unseen.
	 *
	 * @param array<string, mixed> $row    Row.
	 * @param string               $entity Entity name, for the columns it keeps outside the version.
	 * @return string
	 */
	public static function etag( array $row, string $entity = '' ): string {
		/**
		 * Columns that are bookkeeping rather than content (a position, a
		 * count, who was told), which change without anyone editing.
		 *
		 * @param string[] $ignore Column names.
		 * @param string   $entity Entity name.
		 */
		$ignore = '' !== $entity ? (array) apply_filters( 'cliezoo_etag_ignore', array(), $entity ) : array();
		// Who may open a record is not its content: changing it while
		// somebody writes must not make their next save look like
		// somebody else's edit.
		$ignore = array_merge( $ignore, array( 'updated_at', 'access', 'access_role' ) );
		$kept   = array_map(
			static fn( $value ) => null === $value || is_array( $value ) ? $value : (string) $value,
			array_diff_key( $row, array_flip( $ignore ) )
		);
		ksort( $kept );
		return substr( sha1( (string) wp_json_encode( $kept ) ), 0, 16 );
	}

	/**
	 * Creates a record.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $input Validated input (REST keys).
	 * @return array<string, mixed> Presented record.
	 * @throws ActionError On validation or permission errors.
	 */
	public function create( Actor $actor, array $input ): array {
		$row = $this->create_row( $actor, $input );
		return $this->present( $actor, $row );
	}

	/**
	 * Creates a record and returns the raw row (for code that needs ids).
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $input Input (REST keys).
	 * @return array<string, mixed>
	 * @throws ActionError On validation or permission errors.
	 */
	public function create_row( Actor $actor, array $input ): array {
		$this->policy->authorize( $actor, Policy::CREATE, $this->entity );
		$data = $this->serializer->to_columns( $this->entity, $input, $actor );
		$data = $this->resolve_references( $actor, $data );
		// Now that the row says where it belongs: creating a child is
		// changing its parent, and until the references are resolved
		// there is no parent to ask about.
		$this->policy->authorize_parent( $actor, $this->entity, $data );
		foreach ( $this->entity->fields as $column => $field ) {
			if ( ! array_key_exists( $column, $data ) && null !== $field->default ) {
				$data[ $column ] = is_bool( $field->default ) ? (int) $field->default : $field->default;
			}
		}
		$this->check_required( $data );
		$this->check_lists( $data );

		/**
		 * Filters data before a record is created. Throw ActionError to reject.
		 *
		 * @param array $data  Column => value.
		 * @param Actor $actor Actor.
		 * @param array $input Original input.
		 */
		$data = (array) apply_filters( 'cliezoo_' . $this->entity->name . '_before_create', $data, $actor, $input );
		/**
		 * Generic validation of extra input (custom fields…) before saving. Throw ActionError to reject.
		 *
		 * @param array      $data   Column data.
		 * @param Entity     $entity Entity.
		 * @param array      $input  Original input.
		 * @param array|null $row    Current row (null on create).
		 * @param Actor      $actor  Actor.
		 */
		$data = (array) apply_filters( 'cliezoo_record_before_save', $data, $this->entity, $input, null, $actor );

		if ( '' !== $this->entity->series && $this->entity->number_on_create && empty( $data['number'] ) ) {
			$data['number'] = cliezoo()->numbering()->next( $this->entity->series );
		}
		$row = $this->repository->insert( $data, $actor->member_id ? $actor->member_id : null );
		/**
		 * Saves extra input that lives outside the row (custom field values…).
		 *
		 * @param Entity $entity Entity.
		 * @param array  $row    Saved row.
		 * @param array  $input  Original input.
		 * @param Actor  $actor  Actor.
		 */
		do_action( 'cliezoo_record_saved', $this->entity, $row, $input, $actor );

		/**
		 * Fires after a record is created.
		 *
		 * @param array  $row    Row.
		 * @param Actor  $actor  Actor.
		 * @param array  $input  Original input.
		 * @param Entity $entity Entity.
		 */
		do_action( 'cliezoo_' . $this->entity->name . '_created', $row, $actor, $input, $this->entity );
		do_action( 'cliezoo_record_created', $this->entity, $row, $actor, $input );
		return $this->repository->find( (int) $row['id'] ) ?? $row;
	}

	/**
	 * Whether a record would be accepted, without writing it.
	 *
	 * Everything a create does except the insert: the permission, the
	 * references, the defaults, the required fields, the editable lists, and
	 * every filter a module hangs on the way in. It throws what a create would
	 * throw, so a caller asking "would this work" is told in the same words it
	 * would be refused in.
	 *
	 * The alternative — writing it and rolling back — cannot be done safely:
	 * a transaction opened inside somebody else's has no rollback of its own,
	 * and rolling back takes their work with it.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $input Input (REST keys).
	 * @return void
	 * @throws ActionError When it would be refused.
	 */
	public function would_accept( Actor $actor, array $input ): void {
		$this->policy->authorize( $actor, Policy::CREATE, $this->entity );
		$data = $this->serializer->to_columns( $this->entity, $input, $actor );
		$data = $this->resolve_references( $actor, $data );
		$this->policy->authorize_parent( $actor, $this->entity, $data );
		foreach ( $this->entity->fields as $column => $field ) {
			if ( ! array_key_exists( $column, $data ) && null !== $field->default ) {
				$data[ $column ] = is_bool( $field->default ) ? (int) $field->default : $field->default;
			}
		}
		$this->check_required( $data );
		$this->check_lists( $data );

		/** This filter is documented in create_row(). */
		$data = (array) apply_filters( 'cliezoo_' . $this->entity->name . '_before_create', $data, $actor, $input );
		/** This filter is documented in create_row(). */
		apply_filters( 'cliezoo_record_before_save', $data, $this->entity, $input, null, $actor );
	}

	/**
	 * Updates a record.
	 *
	 * @param Actor                $actor    Actor.
	 * @param string               $uid      Uid.
	 * @param array<string, mixed> $input    Validated input.
	 * @param string               $if_match Expected ETag ("" = no check).
	 * @return array<string, mixed> Presented record.
	 * @throws ActionError On conflicts or errors.
	 */
	public function update( Actor $actor, string $uid, array $input, string $if_match = '' ): array {
		$row = $this->load( $actor, $uid, Policy::UPDATE );
		return $this->present( $actor, $this->update_row( $actor, $row, $input, $if_match ) );
	}

	/**
	 * Updates a loaded row (permission already checked by the caller).
	 *
	 * @param Actor                $actor    Actor.
	 * @param array<string, mixed> $row      Current row.
	 * @param array<string, mixed> $input    Input (REST keys).
	 * @param string               $if_match Expected ETag.
	 * @return array<string, mixed> Updated row.
	 * @throws ActionError On conflicts or errors.
	 */
	public function update_row( Actor $actor, array $row, array $input, string $if_match = '' ): array {
		if ( '' !== $if_match && trim( $if_match, '"' ) !== self::etag( $row, $this->entity->name ) ) {
			throw ActionError::conflict( __( 'This item was changed by someone else. Review the latest version, then save again.', 'cliezoo' ), 'cliezoo_stale' );
		}
		$data = $this->serializer->to_columns( $this->entity, $input, $actor );
		$data = $this->resolve_references( $actor, $data );
		return $this->write_columns( $actor, $row, $data, $input );
	}

	/**
	 * Puts stored values back on a loaded row — what a column held before,
	 * as an undo does — through every check, filter and hook an edit goes
	 * through, so the history, search and anything listening hear of it.
	 * Permission is the caller's to check, as for update_row().
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $row   Current row.
	 * @param array<string, mixed> $data  Column => stored value; unknown and read-only columns are left out.
	 * @return array<string, mixed> Updated row.
	 * @throws ActionError When a value is not allowed.
	 */
	public function update_columns( Actor $actor, array $row, array $data ): array {
		$allowed = array();
		foreach ( $data as $column => $value ) {
			$field = $this->entity->field( (string) $column );
			if ( null !== $field && ! $field->readonly && array_key_exists( $column, $row ) ) {
				$allowed[ (string) $column ] = $value;
			}
		}
		return $allowed ? $this->write_columns( $actor, $row, $allowed, array() ) : $row;
	}

	/**
	 * Writes changed columns, with the checks, filters and hooks.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $row   Current row.
	 * @param array<string, mixed> $data  Columns.
	 * @param array<string, mixed> $input Original input.
	 * @return array<string, mixed> Updated row.
	 * @throws ActionError On errors.
	 */
	private function write_columns( Actor $actor, array $row, array $data, array $input ): array {
		$this->check_required( array_merge( $row, $data ), array_keys( $data ) );
		$this->check_lists( $data );

		/**
		 * Filters changes before a record is updated. Throw ActionError to reject.
		 *
		 * @param array $data  Changed columns.
		 * @param array $row   Current row.
		 * @param Actor $actor Actor.
		 * @param array $input Original input.
		 */
		$data = (array) apply_filters( 'cliezoo_' . $this->entity->name . '_before_update', $data, $row, $actor, $input );
		$data = (array) apply_filters( 'cliezoo_record_before_save', $data, $this->entity, $input, $row, $actor );
		do_action( 'cliezoo_record_saved', $this->entity, $row, $input, $actor );

		$changes = array();
		foreach ( $data as $column => $value ) {
			if ( ! array_key_exists( $column, $row ) ) {
				continue;
			}
			if ( (string) $row[ $column ] !== (string) $value ) {
				$changes[ $column ] = array( $row[ $column ], $value );
			}
		}
		if ( ! $changes ) {
			return $row;
		}
		$this->repository->update( (int) $row['id'], array_intersect_key( $data, $changes ) );
		$updated = (array) $this->repository->find( (int) $row['id'] );

		do_action( 'cliezoo_' . $this->entity->name . '_updated', $updated, $row, $changes, $actor );
		do_action( 'cliezoo_record_updated', $this->entity, $updated, $row, $changes, $actor );
		foreach ( array( 'status', 'stage_id', 'list_id' ) as $state_column ) {
			if ( isset( $changes[ $state_column ] ) ) {
				do_action( 'cliezoo_' . $this->entity->name . '_status_changed', $updated, $changes[ $state_column ][0], $changes[ $state_column ][1], $actor, $state_column );
			}
		}
		return $updated;
	}

	/**
	 * Moves a record to the trash.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $uid   Uid.
	 * @return array<string, mixed>
	 */
	public function trash( Actor $actor, string $uid ): array {
		$row = $this->load( $actor, $uid, Policy::DELETE );
		return $this->present( $actor, $this->trash_row( $actor, $row ) );
	}

	/**
	 * Trashes a loaded row.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $row   Row.
	 * @return array<string, mixed>
	 * @throws ActionError When blocked.
	 */
	public function trash_row( Actor $actor, array $row ): array {
		if ( ! $this->entity->soft_delete ) {
			// Nowhere to put it, so it goes at once. The same before_delete
			// filter and the same listeners run either way.
			$this->purge_row( $actor, $row );
			return $row;
		}
		/**
		 * Filters whether a record may be deleted. Return a WP_Error or message string to block.
		 *
		 * @param true|string $allowed True, or a translated reason.
		 * @param array       $row     Row.
		 * @param Actor       $actor   Actor.
		 */
		$allowed = apply_filters( 'cliezoo_' . $this->entity->name . '_before_delete', true, $row, $actor );
		if ( true !== $allowed ) {
			throw ActionError::conflict( is_string( $allowed ) ? $allowed : __( 'This item cannot be deleted.', 'cliezoo' ) );
		}
		$this->repository->update( (int) $row['id'], array( 'deleted_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		$updated = (array) $this->repository->find( (int) $row['id'] );
		do_action( 'cliezoo_' . $this->entity->name . '_trashed', $updated, $actor );
		do_action( 'cliezoo_record_trashed', $this->entity, $updated, $actor );
		return $updated;
	}

	/**
	 * Archives or unarchives a record.
	 *
	 * @param Actor  $actor   Actor.
	 * @param string $uid     Uid.
	 * @param bool   $archive True to archive, false to unarchive.
	 * @return array<string, mixed>
	 */
	public function archive( Actor $actor, string $uid, bool $archive = true ): array {
		$row = $this->load( $actor, $uid, Policy::UPDATE );
		$this->repository->update( (int) $row['id'], array( 'archived_at' => $archive ? gmdate( 'Y-m-d H:i:s' ) : null ) );
		$updated = (array) $this->repository->find( (int) $row['id'] );
		do_action( 'cliezoo_' . $this->entity->name . ( $archive ? '_archived' : '_unarchived' ), $updated, $actor );
		do_action( 'cliezoo_record_' . ( $archive ? 'archived' : 'unarchived' ), $this->entity, $updated, $actor );
		return $this->present( $actor, $updated );
	}

	/**
	 * Restores a trashed record.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $uid   Uid.
	 * @return array<string, mixed>
	 */
	public function restore( Actor $actor, string $uid ): array {
		$row = $this->load( $actor, $uid, Policy::DELETE, true );
		$this->repository->update( (int) $row['id'], array( 'deleted_at' => null ) );
		$updated = (array) $this->repository->find( (int) $row['id'] );
		do_action( 'cliezoo_' . $this->entity->name . '_restored', $updated, $actor );
		do_action( 'cliezoo_record_restored', $this->entity, $updated, $actor );
		return $this->present( $actor, $updated );
	}

	/**
	 * Permanently deletes a trashed record (admins; children are cleaned up by listeners).
	 *
	 * @param Actor  $actor Actor.
	 * @param string $uid   Uid.
	 * @return array{uid: string, purged: bool}
	 * @throws ActionError When not in the trash.
	 */
	public function purge( Actor $actor, string $uid ): array {
		$row = $this->load( $actor, $uid, Policy::PURGE, true );
		if ( $this->entity->soft_delete && empty( $row['deleted_at'] ) ) {
			throw ActionError::conflict( __( 'Move the item to the trash before deleting it permanently.', 'cliezoo' ) );
		}
		$this->purge_row( $actor, $row );
		return array(
			'uid'    => $uid,
			'purged' => true,
		);
	}

	/**
	 * Empties the trash of this entity, as far as one request should go.
	 *
	 * A trash you cannot empty is a promise of deletion that was never kept:
	 * somebody who asked for a record to be gone is entitled to have it
	 * gone. It is bounded on purpose — a workspace can have tens of
	 * thousands of trashed rows and one request should not try to delete
	 * them all — and it says how many are left so the caller can ask again
	 * rather than guess.
	 *
	 * Every row still goes through the same per-row check `purge()` does, so
	 * a row somebody may not delete is left where it is and counted.
	 *
	 * @param Actor $actor Who asked.
	 * @param int   $limit How many to delete this time.
	 * @return array{purged: int, left: int}
	 * @throws ActionError When this entity has no trash, or the actor may not empty it.
	 */
	public function empty_trash( Actor $actor, int $limit = 200 ): array {
		if ( ! $this->entity->soft_delete ) {
			throw ActionError::conflict( __( 'This kind of record has no trash.', 'cliezoo' ) );
		}
		if ( ! $actor->is_admin() && ! $actor->is_system() ) {
			throw ActionError::forbidden();
		}
		$purged = 0;
		$left   = 0;
		$after  = 0;
		while ( true ) {
			$rows = $this->repository->batch( $after, 100, true );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$after = (int) $row['id'];
				if ( empty( $row['deleted_at'] ) ) {
					continue;
				}
				if ( $purged >= $limit || ! $this->policy->can( $actor, Policy::PURGE, $this->entity, $row ) ) {
					++$left;
					continue;
				}
				try {
					$this->purge_row( $actor, $row );
					++$purged;
				} catch ( ActionError $error ) {
					unset( $error );
					++$left;
				}
			}
		}
		if ( $purged > 0 ) {
			do_action(
				'cliezoo_audit',
				'trash_emptied',
				array(
					'object_type' => $this->entity->name,
					'purged'      => $purged,
					'left'        => $left,
				)
			);
		}
		return array(
			'purged' => $purged,
			'left'   => $left,
		);
	}

	/**
	 * Hard-deletes a row after listeners clean up dependants.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $row   Row.
	 * @return void
	 * @throws ActionError When blocked.
	 */
	public function purge_row( Actor $actor, array $row ): void {
		$allowed = apply_filters( 'cliezoo_' . $this->entity->name . '_before_delete', true, $row, $actor );
		if ( true !== $allowed ) {
			throw ActionError::conflict( is_string( $allowed ) ? $allowed : __( 'This item cannot be deleted.', 'cliezoo' ) );
		}
		/**
		 * Fires before a row is permanently deleted: delete or detach dependants here.
		 *
		 * @param array  $row    Row.
		 * @param Actor  $actor  Actor.
		 * @param Entity $entity Entity.
		 */
		do_action( 'cliezoo_' . $this->entity->name . '_purging', $row, $actor, $this->entity );
		do_action( 'cliezoo_record_purging', $this->entity, $row, $actor );
		$this->repository->delete( (int) $row['id'] );
		do_action( 'cliezoo_record_purged', $this->entity, $row, $actor );
	}

	/**
	 * Resolves reference uids to ids; the actor must be able to see each referenced record.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $data  Column data with uids in ref columns.
	 * @return array<string, mixed>
	 * @throws ActionError On unknown or inaccessible references.
	 */
	private function resolve_references( Actor $actor, array $data ): array {
		foreach ( $data as $column => $value ) {
			$field = $this->entity->field( $column );
			if ( ! $field || ! in_array( $field->type, array( 'ref', 'user' ), true ) ) {
				continue;
			}
			if ( null === $value || '' === $value ) {
				$data[ $column ] = null;
				continue;
			}
			if ( ! $this->entities->has( $field->ref ) ) {
				throw ActionError::validation( __( 'Unknown reference.', 'cliezoo' ) );
			}
			$target = $this->entities->get( $field->ref );
			$repo   = new Repository( $target, $this->entities );
			$ref    = $repo->find_by_uid( (string) $value );
			$key    = $this->entity->input_key( $column );
			if ( ! $ref || ( $target->soft_delete && ! empty( $ref['deleted_at'] ) ) || ( 'members' !== $target->name && ! $this->policy->can( $actor, Policy::VIEW, $target, $ref ) ) ) {
				/* translators: %s: field name. */
				throw ActionError::validation( sprintf( __( 'The selected %s was not found.', 'cliezoo' ), $key ), array( $key => __( 'Not found or not accessible.', 'cliezoo' ) ) );
			}
			$data[ $column ] = (int) $ref['id'];
		}
		return $data;
	}

	/**
	 * Ensures required fields are present.
	 *
	 * @param array<string, mixed> $data    Data.
	 * @param string[]|null        $changed Columns being changed (null = all).
	 * @return void
	 * @throws ActionError When a required field is empty.
	 */
	private function check_required( array $data, ?array $changed = null ): void {
		$errors = array();
		foreach ( $this->entity->fields as $column => $field ) {
			if ( ! $field->required || ( null !== $changed && ! in_array( $column, $changed, true ) ) ) {
				continue;
			}
			if ( ! isset( $data[ $column ] ) || '' === $data[ $column ] ) {
				$errors[ $this->entity->input_key( $column ) ] = __( 'This field is required.', 'cliezoo' );
			}
		}
		if ( $errors ) {
			throw ActionError::validation( __( 'Please fill in the required fields.', 'cliezoo' ), $errors );
		}
	}

	/**
	 * Validates values of editable-list fields.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return void
	 * @throws ActionError On unknown list values.
	 */
	private function check_lists( array $data ): void {
		foreach ( $data as $column => $value ) {
			$field = $this->entity->field( $column );
			if ( ! $field || '' === $field->list || null === $value || '' === $value ) {
				continue;
			}
			/**
			 * Whether a value exists in an editable list.
			 *
			 * @param bool   $valid Default true.
			 * @param string $list  List key.
			 * @param string $value Value.
			 */
			if ( ! apply_filters( 'cliezoo_list_value_valid', true, $field->list, (string) $value ) ) {
				$key = $this->entity->input_key( $column );
				throw ActionError::validation( __( 'Please choose a value from the list.', 'cliezoo' ), array( $key => __( 'Unknown value.', 'cliezoo' ) ) );
			}
		}
	}
}
