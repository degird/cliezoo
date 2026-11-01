<?php
/**
 * The single permission decision point.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Permissions;

use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\EntityRegistry;
use Cliezoo\Core\Data\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides every permission (Blueprint §8.7): role matrix + object access
 * (general access mode, memberships, inheritance from the project) + client
 * company scoping. Lists use scope(); single records use can().
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * Table names come from entity definitions; values are placeholders.
 */
final class Policy {

	public const VIEW    = 'view';
	public const CREATE  = 'create';
	public const UPDATE  = 'update';
	public const DELETE  = 'delete';
	public const ARCHIVE = 'archive';
	public const PURGE   = 'purge';
	public const SHARE   = 'share';

	/**
	 * Object roles in descending power.
	 */
	public const OBJECT_ROLES = array( 'admin', 'manager', 'creator', 'commenter', 'uploader', 'downloader', 'viewer' );

	/**
	 * Entity registry.
	 *
	 * @var EntityRegistry
	 */
	private EntityRegistry $entities;

	/**
	 * Constructor.
	 *
	 * @param EntityRegistry $entities Registry.
	 */
	public function __construct( EntityRegistry $entities ) {
		$this->entities = $entities;
	}

	/**
	 * Whether the actor may perform an action on an entity (and optionally a row).
	 *
	 * @param Actor                     $actor  Actor.
	 * @param string                    $action One of the class constants or a custom action.
	 * @param Entity                    $entity Entity.
	 * @param array<string, mixed>|null $row    Row (null for create / type-level checks).
	 * @return bool
	 */
	public function can( Actor $actor, string $action, Entity $entity, ?array $row = null ): bool {
		$decision = $this->decide( $actor, $action, $entity, $row );
		/**
		 * Filters a permission decision. Extensions may only narrow it:
		 * returning true where the core said false is ignored.
		 *
		 * @param bool       $decision Decision.
		 * @param Actor      $actor    Actor.
		 * @param string     $action   Action.
		 * @param Entity     $entity   Entity.
		 * @param array|null $row      Row.
		 */
		$filtered = (bool) apply_filters( 'cliezoo_policy_can', $decision, $actor, $action, $entity, $row );
		return $decision && $filtered;
	}

	/**
	 * Core decision without filters.
	 *
	 * @param Actor                     $actor  Actor.
	 * @param string                    $action Action.
	 * @param Entity                    $entity Entity.
	 * @param array<string, mixed>|null $row    Row.
	 * @return bool
	 */
	private function decide( Actor $actor, string $action, Entity $entity, ?array $row ): bool {
		if ( $actor->is_system() ) {
			return true;
		}
		if ( $actor->is_guest() ) {
			return false;
		}
		$area = '' !== $entity->permission ? $entity->permission : $entity->name;

		$special = $this->special_decision( $actor, $action, $entity, $row, $area );
		if ( null !== $special ) {
			return $special;
		}
		$scope = $actor->scope( $area );

		if ( $actor->is_client() ) {
			if ( self::VIEW !== $action && ! $this->client_may_write( $actor, $action, $entity ) ) {
				return false;
			}
			return null === $row ? true : $this->row_in_scope( $actor, $entity, $row );
		}

		if ( 'none' === $scope ) {
			return false;
		}
		if ( self::VIEW !== $action && ! $actor->can_write() ) {
			return false;
		}
		if ( self::PURGE === $action ) {
			return $actor->is_admin();
		}
		if ( self::CREATE === $action ) {
			return '' === $entity->create_toggle || $actor->allows( $entity->create_toggle ) || $actor->is_admin();
		}
		if ( self::DELETE === $action && '' !== $entity->delete_toggle && ! $actor->allows( $entity->delete_toggle ) && ! $actor->is_admin() ) {
			return false;
		}
		if ( null === $row ) {
			return true;
		}
		if ( ! $this->row_in_scope( $actor, $entity, $row ) ) {
			return false;
		}
		// A child row is changed by whoever may change the row it belongs
		// to. Reading is already covered — the scope itself now walks up —
		// but writing is not: without this, somebody who may only look at a
		// board could rename its lists, and renaming the list a board calls
		// "done" moves every card that lands in it.
		if ( self::VIEW !== $action && ! $this->parent_allows( $actor, $action, $entity, $row ) ) {
			return false;
		}
		if ( self::VIEW === $action || $actor->is_admin() || $actor->allows( 'resources_admin' ) ) {
			return true;
		}
		// Writes on shared objects need an object role that allows editing.
		// Somebody given a role on a record has that role, whatever their
		// area lets them do elsewhere: shared with them "to look at" is not
		// shared with them to change. With no role, the area decides.
		if ( $entity->shareable ) {
			$role = $this->object_role( $actor, $entity, $row );
			if ( null === $role ) {
				return true;
			}
			return in_array( $role, array( 'admin', 'manager', 'creator' ), true ) || $this->is_owner_of( $actor, $entity, $row );
		}
		return true;
	}

	/**
	 * Decisions for platform areas that are not in the role matrix, or null to continue.
	 *
	 * - members: everyone may see the roster (scoped for clients); writes need "Members and roles".
	 * - settings: lists/values are readable by members; writes need settings permissions.
	 * - self: rows owned by the member (favourites, saved views).
	 * - templates: staff read; "Create and manage templates" writes.
	 * - object-attached rows (notes, comments, to-dos): follow the parent record.
	 *
	 * @param Actor                     $actor  Actor.
	 * @param string                    $action Action.
	 * @param Entity                    $entity Entity.
	 * @param array<string, mixed>|null $row    Row.
	 * @param string                    $area   Area.
	 * @return bool|null
	 */
	private function special_decision( Actor $actor, string $action, Entity $entity, ?array $row, string $area ): ?bool {
		$view = self::VIEW === $action;
		if ( $entity->object_attached ) {
			if ( null === $row ) {
				return $view || $actor->can_write() || $actor->is_client();
			}
			$parent = $this->parent_row( (string) $row['object_type'], (int) $row['object_id'] );
			if ( ! $parent ) {
				return false;
			}
			if ( ! $this->can( $actor, self::VIEW, $parent[0], $parent[1] ) ) {
				return false;
			}
			if ( $actor->is_client() && ! empty( $row['internal'] ) ) {
				return false;
			}
			if ( $view ) {
				return true;
			}
			// Authors edit their own; admins and editors of the parent edit any.
			return (int) ( $row['created_by'] ?? 0 ) === $actor->member_id || $actor->is_admin() || $this->can( $actor, self::UPDATE, $parent[0], $parent[1] );
		}
		switch ( $area ) {
			case 'members':
				if ( $view ) {
					return null === $row || $this->row_in_scope( $actor, $entity, $row );
				}
				if ( null !== $row && (int) $row['id'] === $actor->member_id && self::UPDATE === $action ) {
					return true;
				}
				return $actor->is_admin() || $actor->allows( 'settings_members' );
			case 'settings':
				if ( $view ) {
					return true;
				}
				return $actor->is_admin() || $actor->allows( 'settings_general' ) || $actor->allows( 'settings_custom_fields' );
			case 'self':
				return null === $row || (int) ( $row['member_id'] ?? 0 ) === $actor->member_id;
			case 'templates':
				if ( $actor->is_client() ) {
					return false;
				}
				return $view ? true : ( $actor->can_write() && ( $actor->is_admin() || $actor->allows( 'templates_manage' ) ) );
			case 'reminders':
				if ( ! $actor->is_staff() || ( ! $view && ! $actor->can_write() ) ) {
					return false;
				}
				return null === $row || $this->reminder_allowed( $actor, $row, $view );
			case 'email_log':
			case 'activity':
			case 'audit':
				return $actor->is_staff() && $view;
		}
		return null;
	}

	/**
	 * Loads the parent record of an object-attached row.
	 *
	 * @param string $type Entity name.
	 * @param int    $id   Row id.
	 * @return array{0: Entity, 1: array<string, mixed>}|null
	 */
	public function parent_row( string $type, int $id ): ?array {
		if ( ! $this->entities->has( $type ) ) {
			return null;
		}
		$entity = $this->entities->get( $type );
		$row    = ( new \Cliezoo\Core\Data\Repository( $entity, $this->entities ) )->find( $id );
		return $row ? array( $entity, $row ) : null;
	}

	/**
	 * Whether a client portal user may perform a non-view action.
	 * Module-level client rules (upload files, comment, submit requests…) come in through the filter.
	 *
	 * @param Actor  $actor  Actor.
	 * @param string $action Action.
	 * @param Entity $entity Entity.
	 * @return bool
	 */
	private function client_may_write( Actor $actor, string $action, Entity $entity ): bool {
		if ( 'client_viewer' === $actor->role ) {
			return false;
		}
		/**
		 * Which entities/actions client portal users may perform (beyond viewing).
		 *
		 * @param bool   $allowed Default false.
		 * @param string $action  Action.
		 * @param Entity $entity  Entity.
		 * @param Actor  $actor   Actor.
		 */
		return (bool) apply_filters( 'cliezoo_client_may', false, $action, $entity, $actor );
	}

	/**
	 * A reminder rule follows whatever it is attached to.
	 *
	 * Workspace and template rules are workspace configuration: any staff
	 * member reads them, only people who may change settings edit them. A
	 * rule attached to a client, a project or one record needs the same
	 * rights as that record, so a rule cannot become a way to read or edit
	 * around a scope.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $row   Rule row.
	 * @param bool                 $view  Reading rather than writing.
	 * @return bool
	 */
	private function reminder_allowed( Actor $actor, array $row, bool $view ): bool {
		$scope = (string) ( $row['scope_type'] ?? 'workspace' );
		if ( in_array( $scope, array( 'workspace', 'template' ), true ) ) {
			return $view || $actor->is_admin() || $actor->allows( 'settings_general' );
		}
		$names  = array(
			'client'  => 'clients',
			'project' => 'projects',
			'record'  => (string) ( $row['target_type'] ?? '' ),
		);
		$target = $names[ $scope ] ?? '';
		if ( '' === $target || ! $this->entities->has( $target ) ) {
			return $actor->is_admin();
		}
		$entity = $this->entities->get( $target );
		$record = ( new Repository( $entity, $this->entities ) )->find( (int) ( $row['scope_id'] ?? 0 ) );
		if ( ! $record ) {
			return $actor->is_admin();
		}
		return $this->can( $actor, $view ? self::VIEW : self::UPDATE, $entity, $record );
	}

	/**
	 * Whether the row a child belongs to allows this action.
	 *
	 * @param Actor                $actor  Actor.
	 * @param string               $action Action.
	 * @param Entity               $entity The child entity.
	 * @param array<string, mixed> $row    The child row.
	 * @return bool
	 */
	private function parent_allows( Actor $actor, string $action, Entity $entity, array $row ): bool {
		$parent = $this->parent_of( $entity );
		if ( ! $parent ) {
			return true;
		}
		$id = (int) ( $row[ $parent[1] ] ?? 0 );
		if ( ! $id ) {
			return true;
		}
		$record = ( new Repository( $parent[0], $this->entities ) )->find( $id );
		if ( ! $record ) {
			return true;
		}
		// Deleting a child is changing its parent, not deleting one.
		$wanted = in_array( $action, array( self::DELETE, self::PURGE, self::ARCHIVE ), true )
			? self::UPDATE
			: $action;
		return $this->can( $actor, $wanted, $parent[0], $record );
	}

	/**
	 * The entity and column a child hangs off, when it hangs off one.
	 *
	 * @param Entity $entity Entity.
	 * @return array{0: Entity, 1: string}|null
	 */
	private function parent_of( Entity $entity ): ?array {
		$name   = (string) ( $entity->parent[0] ?? '' );
		$column = (string) ( $entity->parent[1] ?? '' );
		if ( '' === $name || '' === $column || ! $this->entities->has( $name ) ) {
			return null;
		}
		return array( $this->entities->get( $name ), $column );
	}

	/**
	 * "The row this one belongs to is in scope."
	 *
	 * A child table — a board's lists, a task's checklist items, the people
	 * on a task — is exactly as reachable as its parent. That was written
	 * down in the entity definition from the start and only ever used for
	 * cascade deletion, which meant the generic list route handed out every
	 * checklist item in the workspace to anybody who could see any task.
	 *
	 * @param Actor  $actor  Actor.
	 * @param Entity $entity Entity.
	 * @return array{0:string,1:array<int, mixed>}|null
	 */
	private function parent_scope( Actor $actor, Entity $entity ): ?array {
		$parent = $this->parent_of( $entity );
		if ( ! $parent ) {
			return null;
		}
		$inner = $this->scope( $actor, $parent[0] );
		$table = $parent[0]->table();
		$where = "czp.id = t.{$parent[1]}";
		$args  = array();
		if ( null !== $inner ) {
			if ( '1=0' === $inner[0] ) {
				return array( '1=0', array() );
			}
			// The parent's own scope is written against `t`; here it is the
			// row being joined to, so it is read against `czp` instead.
			$where .= ' AND (' . str_replace( 't.', 'czp.', $inner[0] ) . ')';
			$args   = $inner[1];
		}
		if ( $parent[0]->soft_delete ) {
			$where .= ' AND czp.deleted_at IS NULL';
		}
		// A row that names no parent has none to inherit from — a contact
		// who is not at a company yet — and is left to the ordinary rules.
		return array(
			"( t.{$parent[1]} IS NULL OR EXISTS (SELECT 1 FROM {$table} czp WHERE {$where}) )",
			$args,
		);
	}

	/**
	 * Checks one row against the actor's scope with a single indexed query.
	 *
	 * @param Actor                $actor  Actor.
	 * @param Entity               $entity Entity.
	 * @param array<string, mixed> $row    Row (needs id).
	 * @return bool
	 */
	public function row_in_scope( Actor $actor, Entity $entity, array $row ): bool {
		global $wpdb;
		$scope = $this->scope( $actor, $entity );
		if ( null === $scope ) {
			return true;
		}
		if ( '1=0' === $scope[0] ) {
			return false;
		}
		$sql = "SELECT 1 FROM {$entity->table()} t WHERE t.id = %d AND ({$scope[0]})";
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from the entity registry; every value is a placeholder passed to prepare().
		return (bool) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( (int) $row['id'] ), $scope[1] ) ) );
	}

	/**
	 * SQL scope fragment for lists (alias `t`), or null for no restriction.
	 *
	 * @param Actor  $actor  Actor.
	 * @param Entity $entity Entity.
	 * @return array{0:string,1:array<int, mixed>}|null
	 */
	public function scope( Actor $actor, Entity $entity ): ?array {
		if ( $actor->is_system() ) {
			return null;
		}
		if ( $actor->is_guest() ) {
			return array( '1=0', array() );
		}
		$area = '' !== $entity->permission ? $entity->permission : $entity->name;
		if ( 'self' === $area ) {
			return array( 't.member_id = %d', array( $actor->member_id ) );
		}
		if ( 'settings' === $area || ( 'templates' === $area && $actor->is_staff() ) ) {
			return null;
		}
		if ( 'reminders' === $area ) {
			if ( ! $actor->is_staff() ) {
				return array( '1=0', array() );
			}
			// Occurrences have no generic list verb; they are read per record.
			if ( 'reminder_rules' !== $entity->name || $actor->is_admin() || $actor->allows( 'settings_general' ) ) {
				return null;
			}
			// Rules attached to a client, project or record are read through
			// that record (`GET /reminders`), which checks it first.
			return array( "( t.scope_type IN ('workspace','template') OR t.created_by = %d )", array( $actor->member_id ) );
		}
		if ( $entity->object_attached ) {
			// Lists of attached rows are always fetched per parent after a parent check.
			return array( 't.created_by = %d', array( $actor->member_id ) );
		}
		if ( 'members' === $area ) {
			$scope = $actor->is_client() ? $this->client_scope( $actor, $entity ) : null;
		} else {
			$scope = $actor->is_client() ? $this->client_scope( $actor, $entity ) : $this->staff_scope( $actor, $entity );
		}
		$inherited = $this->parent_scope( $actor, $entity );
		if ( null !== $inherited ) {
			$scope = null === $scope
				? $inherited
				: array( '(' . $scope[0] . ') AND (' . $inherited[0] . ')', array_merge( $scope[1], $inherited[1] ) );
		}
		/**
		 * Filters a list scope. Extensions may add restrictions (AND) only.
		 *
		 * @param array|null $scope  [sql, args] or null.
		 * @param Actor      $actor  Actor.
		 * @param Entity     $entity Entity.
		 */
		$extra = apply_filters( 'cliezoo_policy_scope_extra', null, $actor, $entity );
		if ( is_array( $extra ) && isset( $extra[0] ) && '' !== $extra[0] ) {
			if ( null === $scope ) {
				return array( (string) $extra[0], (array) $extra[1] );
			}
			return array( '(' . $scope[0] . ') AND (' . $extra[0] . ')', array_merge( $scope[1], (array) $extra[1] ) );
		}
		return $scope;
	}

	/**
	 * Staff scope.
	 *
	 * @param Actor  $actor  Actor.
	 * @param Entity $entity Entity.
	 * @return array{0:string,1:array<int, mixed>}|null
	 */
	private function staff_scope( Actor $actor, Entity $entity ): ?array {
		$area  = '' !== $entity->permission ? $entity->permission : $entity->name;
		$scope = $actor->scope( $area );
		if ( 'none' === $scope ) {
			return array( '1=0', array() );
		}
		$privileged = $actor->is_admin() || $actor->allows( 'resources_admin' );
		if ( 'all' === $scope ) {
			if ( ! $entity->shareable || $privileged ) {
				return null;
			}
			// Everything except private objects the actor is not a member of.
			list( $member_sql, $member_args ) = $this->membership_sql( $actor, $entity );
			list( $mode_sql, $mode_args )     = $this->internal_mode_sql( $entity );
			return array( "({$mode_sql}) OR ({$member_sql})", array_merge( $mode_args, $member_args ) );
		}
		// Own (and team): records they own or created, plus shared memberships.
		$parts  = array();
		$args   = array();
		$people = array( $actor->member_id );
		if ( 'team' === $scope ) {
			/**
			 * Member ids in the actor's teams (teams, from add-ons).
			 *
			 * @param int[] $ids   Member ids.
			 * @param Actor $actor Actor.
			 */
			$people = array_values( array_unique( array_map( 'intval', (array) apply_filters( 'cliezoo_team_member_ids', $people, $actor ) ) ) );
		}
		$in = implode( ',', array_fill( 0, count( $people ), '%d' ) );
		if ( '' !== $entity->owner_field ) {
			$parts[] = "t.{$entity->owner_field} IN ({$in})";
			$args    = array_merge( $args, $people );
		}
		$parts[] = "t.created_by IN ({$in})";
		$args    = array_merge( $args, $people );
		if ( $entity->shareable || '' !== $entity->project_field ) {
			list( $member_sql, $member_args ) = $this->membership_sql( $actor, $entity );
			$parts[]                          = $member_sql;
			$args                             = array_merge( $args, $member_args );
		}
		/**
		 * Extra "own" conditions per entity (e.g. tasks assigned to me).
		 *
		 * @param array  $conditions List of [sql, args].
		 * @param Actor  $actor      Actor.
		 * @param Entity $entity     Entity.
		 */
		foreach ( (array) apply_filters( 'cliezoo_policy_own_conditions', array(), $actor, $entity ) as $condition ) {
			$parts[] = (string) $condition[0];
			$args    = array_merge( $args, (array) $condition[1] );
		}
		return array( implode( ' OR ', $parts ), $args );
	}

	/**
	 * Client portal scope: only their selected company's rows that are shared with clients.
	 *
	 * @param Actor  $actor  Actor.
	 * @param Entity $entity Entity.
	 * @return array{0:string,1:array<int, mixed>}
	 */
	private function client_scope( Actor $actor, Entity $entity ): array {
		/**
		 * Lets an entity define its own client scope (e.g. clients, contacts, members).
		 *
		 * @param array|null $scope  [sql, args] or null to use the default.
		 * @param Actor      $actor  Actor.
		 * @param Entity     $entity Entity.
		 */
		$custom = apply_filters( 'cliezoo_client_scope', null, $actor, $entity );
		if ( is_array( $custom ) ) {
			return array( (string) $custom[0], (array) $custom[1] );
		}
		if ( '' === $entity->client_field || ! $actor->active_client_id ) {
			return array( '1=0', array() );
		}
		$parts = array( "t.{$entity->client_field} = %d" );
		$args  = array( $actor->active_client_id );
		if ( '' !== $entity->client_visible_field ) {
			$parts[] = "t.{$entity->client_visible_field} = 1";
		}
		// The record that *is* the client: its own people always see it,
		// whatever its sharing mode says. "Inherit" means "follow the
		// project", and a client does not have one.
		if ( $entity->shareable && 'id' !== $entity->client_field ) {
			global $wpdb;
			$access = $wpdb->prefix . 'cliezoo_access';
			$mode   = "t.access IN ('clients','public')";
			if ( '' !== $entity->project_field && $this->entities->has( 'projects' ) ) {
				$projects = $this->entities->get( 'projects' )->table();
				$mode    .= " OR (t.access = 'inherit' AND EXISTS (SELECT 1 FROM {$projects} p WHERE p.id = t.{$entity->project_field} AND p.access IN ('clients','public')))";
			}
			$parts[] = "({$mode} OR EXISTS (SELECT 1 FROM {$access} a WHERE a.object_type = %s AND a.object_id = t.id AND a.principal_type = 'client' AND a.principal_id = %d))";
			$args[]  = $entity->name;
			$args[]  = $actor->active_client_id;
		}
		return array( implode( ' AND ', $parts ), $args );
	}

	/**
	 * "Actor is a member of this object (or of its project when inheriting)".
	 *
	 * @param Actor  $actor  Actor.
	 * @param Entity $entity Entity.
	 * @return array{0:string,1:array<int, mixed>}
	 */
	private function membership_sql( Actor $actor, Entity $entity ): array {
		global $wpdb;
		$access    = $wpdb->prefix . 'cliezoo_access';
		$principal = "(a.principal_type = 'user' AND a.principal_id = %d)";
		$args      = array( $actor->member_id );
		if ( $actor->team_ids ) {
			$principal .= " OR (a.principal_type = 'team' AND a.principal_id IN (" . implode( ',', array_fill( 0, count( $actor->team_ids ), '%d' ) ) . '))';
			$args       = array_merge( $args, $actor->team_ids );
		}
		$own_sql  = "EXISTS (SELECT 1 FROM {$access} a WHERE a.object_type = %s AND a.object_id = t.id AND ({$principal}))";
		$own_args = array_merge( array( $entity->name ), $args );
		// Shared with whoever the record it sits in is shared with: a file
		// with the people of its folder.
		$via = $this->member_via( $entity );
		if ( $via ) {
			$own_sql  = "({$own_sql} OR EXISTS (SELECT 1 FROM {$access} a WHERE a.object_type = %s AND a.object_id = t.{$via[1]} AND ({$principal})))";
			$own_args = array_merge( $own_args, array( $via[0] ), $args );
		}
		if ( '' === $entity->project_field ) {
			return array( $own_sql, $own_args );
		}
		$inherit_guard = $entity->shareable ? "t.access = 'inherit' AND " : '';
		$project_sql   = "{$inherit_guard}EXISTS (SELECT 1 FROM {$access} a WHERE a.object_type = 'projects' AND a.object_id = t.{$entity->project_field} AND ({$principal}))";
		return array( "({$own_sql} OR {$project_sql})", array_merge( $own_args, $args ) );
	}

	/**
	 * The record whose people count as this one's, as [entity, column].
	 *
	 * @param Entity $entity Entity.
	 * @return array{0: string, 1: string}|null
	 */
	private function member_via( Entity $entity ): ?array {
		$name   = (string) ( $entity->member_via[0] ?? '' );
		$column = (string) ( $entity->member_via[1] ?? '' );
		if ( '' === $name || '' === $column || ! $this->entities->has( $name ) || 1 !== preg_match( '/^[a-z_]+$/', $column ) ) {
			return null;
		}
		return array( $name, $column );
	}

	/**
	 * "Object is visible to the whole team" (internal/public, or inheriting a non-private project).
	 *
	 * @param Entity $entity Entity.
	 * @return array{0:string,1:array<int, mixed>}
	 */
	private function internal_mode_sql( Entity $entity ): array {
		$modes = "'internal','public','clients'";
		if ( 'projects' === $entity->name || '' === $entity->project_field || ! $this->entities->has( 'projects' ) ) {
			return array( "t.access IN ({$modes},'inherit')", array() );
		}
		$projects = $this->entities->get( 'projects' )->table();
		return array( "t.access IN ({$modes}) OR (t.access = 'inherit' AND (t.{$entity->project_field} IS NULL OR EXISTS (SELECT 1 FROM {$projects} p WHERE p.id = t.{$entity->project_field} AND p.access IN ({$modes},'inherit'))))", array() );
	}

	/**
	 * The actor's object role on a row, or null: the most specific share
	 * wins — the row's own, then the folder it sits in, then its project —
	 * and within one, the strongest of the person's own and their teams'.
	 *
	 * @param Actor                $actor  Actor.
	 * @param Entity               $entity Entity.
	 * @param array<string, mixed> $row    Row.
	 * @return string|null
	 */
	public function object_role( Actor $actor, Entity $entity, array $row ): ?string {
		$role = $this->role_on( $actor, $entity->name, (int) $row['id'] );
		$via  = $this->member_via( $entity );
		if ( null === $role && $via && ! empty( $row[ $via[1] ] ) ) {
			$role = $this->role_on( $actor, $via[0], (int) $row[ $via[1] ] );
		}
		if ( null === $role && '' !== $entity->project_field && ! empty( $row[ $entity->project_field ] ) ) {
			$role = $this->role_on( $actor, 'projects', (int) $row[ $entity->project_field ] );
		}
		return $role;
	}

	/**
	 * The strongest role a person has on one record, themselves or through
	 * a team, or null when it is not shared with them at all.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $type  Entity.
	 * @param int    $id    Row id.
	 * @return string|null
	 */
	private function role_on( Actor $actor, string $type, int $id ): ?string {
		global $wpdb;
		$access = esc_sql( $wpdb->prefix . 'cliezoo_access' );
		$who    = "( principal_type = 'user' AND principal_id = %d )";
		$args   = array( $type, $id, $actor->member_id );
		if ( $actor->team_ids ) {
			$who .= " OR ( principal_type = 'team' AND principal_id IN (" . implode( ',', array_fill( 0, count( $actor->team_ids ), '%d' ) ) . ') )';
			$args = array_merge( $args, array_map( 'intval', $actor->team_ids ) );
		}
		$roles = (array) $wpdb->get_col( $wpdb->prepare( "SELECT role FROM {$access} WHERE object_type = %s AND object_id = %d AND ( {$who} )", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table from the prefix; every value is a placeholder, passed as one array.
		$best  = null;
		foreach ( $roles as $role ) {
			$rank = array_search( (string) $role, self::OBJECT_ROLES, true );
			if ( false !== $rank && ( null === $best || $rank < $best ) ) {
				$best = $rank;
			}
		}
		return null === $best ? null : self::OBJECT_ROLES[ $best ];
	}

	/**
	 * Whether the actor owns or created a row.
	 *
	 * @param Actor                $actor  Actor.
	 * @param Entity               $entity Entity.
	 * @param array<string, mixed> $row    Row.
	 * @return bool
	 */
	private function is_owner_of( Actor $actor, Entity $entity, array $row ): bool {
		if ( '' !== $entity->owner_field && (int) ( $row[ $entity->owner_field ] ?? 0 ) === $actor->member_id ) {
			return true;
		}
		return (int) ( $row['created_by'] ?? 0 ) === $actor->member_id;
	}

	/**
	 * Refuses a new child row when its parent is not the actor's to change.
	 *
	 * Making a child row is changing the record it belongs to — every
	 * other verb asks that question, and CREATE could not, because until
	 * the references are resolved there is no parent to ask about. Only
	 * for a parent that is shared: a saved view belongs to a person, not
	 * to a record, and asking whether somebody may "change" themselves
	 * would refuse them their own list.
	 *
	 * @param Actor                $actor  Who is asking.
	 * @param Entity               $entity What is being made.
	 * @param array<string, mixed> $row    The row as it will be written.
	 * @return void
	 * @throws Forbidden When the parent is not theirs to change.
	 */
	public function authorize_parent( Actor $actor, Entity $entity, array $row ): void {
		$parent = $this->parent_of( $entity );
		if ( null === $parent || ! $parent[0]->shareable ) {
			return;
		}
		if ( ! $this->parent_allows( $actor, self::UPDATE, $entity, $row ) ) {
			throw new Forbidden();
		}
	}

	/**
	 * Throws unless allowed.
	 *
	 * @param Actor                     $actor  Actor.
	 * @param string                    $action Action.
	 * @param Entity                    $entity Entity.
	 * @param array<string, mixed>|null $row    Row.
	 * @return void
	 * @throws Forbidden When denied.
	 */
	public function authorize( Actor $actor, string $action, Entity $entity, ?array $row = null ): void {
		if ( ! $this->can( $actor, $action, $entity, $row ) ) {
			throw new Forbidden();
		}
	}
}
