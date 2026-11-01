<?php
/**
 * Hands a deleted member's work to somebody else.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Members;

use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Logger;
use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When a WordPress user is deleted and WordPress is told who gets their
 * content, the same person takes over what the member looked after in
 * Cliezoo: the records they owned and the tasks and to-dos they were doing
 * (Blueprint §31.3). With nobody named, nothing moves: the member is only
 * deactivated and the records keep their "(former member)" name, because
 * deactivation, not deletion, is the default.
 *
 * Who did something in the past (created, closed, approved, completed) is
 * history and is never rewritten; only who is responsible now changes.
 */
final class Reassign {

	/**
	 * Person columns that say who is responsible for a record now. An
	 * entity's own owner field counts too, whatever it is called.
	 */
	private const COLUMNS = array( 'owner_id', 'assignee_id' );

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
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		// Wrapped: reassign() says how many rows it moved, which callers and
		// tests use, and an action hands nothing back.
		add_action(
			'cliezoo_member_reassign',
			function ( int $from, $to = null ): void {
				$this->reassign( $from, $to );
			},
			10,
			2
		);
	}

	/**
	 * Moves a member's records to another member.
	 *
	 * @param int      $from Member leaving.
	 * @param int|null $to   Member taking over, or null for nobody.
	 * @return int How many rows changed.
	 */
	public function reassign( int $from, $to = null ): int {
		$to = (int) $to;
		if ( $from <= 0 || $to <= 0 || $to === $from ) {
			return 0;
		}
		$moved = 0;
		foreach ( $this->plugin->entities()->all() as $entity ) {
			if ( 'members' === $entity->name ) {
				continue;
			}
			$columns = $this->columns( $entity );
			if ( ! $columns ) {
				continue;
			}
			$repository = $this->plugin->service( $entity->name )->repository();
			foreach ( $columns as $column ) {
				$moved += $repository->update_where( array( $column => $to ), array( $column => $from ) );
			}
		}
		$moved += $this->move_assignees( $from, $to );
		if ( $moved ) {
			Logger::debug(
				'Records reassigned from a deleted member',
				array(
					'from'  => $from,
					'to'    => $to,
					'moved' => $moved,
				)
			);
		}
		return $moved;
	}

	/**
	 * The responsibility columns an entity has.
	 *
	 * @param Entity $entity Entity.
	 * @return string[]
	 */
	private function columns( Entity $entity ): array {
		$names = self::COLUMNS;
		if ( '' !== $entity->owner_field ) {
			$names[] = $entity->owner_field;
		}
		$columns = array();
		foreach ( array_unique( $names ) as $name ) {
			$field = $entity->field( $name );
			if ( $field && 'user' === $field->type ) {
				$columns[] = $name;
			}
		}
		/**
		 * Person columns handed over when a member is deleted with somebody
		 * named to take over. Add a column that says who is responsible now;
		 * leave out the ones that record who did something.
		 *
		 * @param string[] $columns Column names.
		 * @param Entity   $entity  Entity.
		 */
		return array_values( (array) apply_filters( 'cliezoo_member_reassign_columns', $columns, $entity ) );
	}

	/**
	 * Tasks the member was on move to the new person. Somebody already on
	 * the task keeps their place, and the leaver's now-duplicate row goes,
	 * because a person is on a task once.
	 *
	 * @param int $from Member leaving.
	 * @param int $to   Member taking over.
	 * @return int Rows changed.
	 */
	private function move_assignees( int $from, int $to ): int {
		if ( ! $this->plugin->entities()->has( 'task_assignees' ) ) {
			return 0;
		}
		$repository = $this->plugin->service( 'task_assignees' )->repository();
		$moved      = 0;
		foreach ( $repository->where(
			array(
				'member_id' => $from,
				'role'      => 'assignee',
			)
		) as $row ) {
			$already = $repository->first(
				array(
					'task_id'   => (int) $row['task_id'],
					'member_id' => $to,
					'role'      => 'assignee',
				)
			);
			if ( $already ) {
				$repository->delete( (int) $row['id'] );
			} else {
				$repository->update( (int) $row['id'], array( 'member_id' => $to ) );
			}
			++$moved;
		}
		return $moved;
	}
}
