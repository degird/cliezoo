<?php
/**
 * Object sharing: general access, memberships, explanations, inventory.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Permissions;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\ObjectRef;
use Cliezoo\Core\Data\Repository;
use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * The share dialog's backend (cliezoo.md §6.31): four general access modes,
 * members (user / client company / team) with object roles, inheritance from
 * the project, "who has access and why", access inventory and bulk revoke.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class AccessService {

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
	 * Access table.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cliezoo_access';
	}

	/**
	 * Whether the actor may change sharing of a row.
	 *
	 * @param Actor                $actor  Actor.
	 * @param Entity               $entity Entity.
	 * @param array<string, mixed> $row    Row.
	 * @return bool
	 */
	public function can_share( Actor $actor, Entity $entity, array $row ): bool {
		if ( $actor->is_admin() || $actor->allows( 'resources_admin' ) ) {
			return true;
		}
		if ( ! $actor->is_staff() || ! $actor->can_write() ) {
			return false;
		}
		$role = $this->plugin->policy()->object_role( $actor, $entity, $row );
		if ( in_array( $role, array( 'admin', 'manager' ), true ) ) {
			return true;
		}
		return (int) ( $row['created_by'] ?? 0 ) === $actor->member_id || ( '' !== $entity->owner_field && (int) ( $row[ $entity->owner_field ] ?? 0 ) === $actor->member_id );
	}

	/**
	 * Share state of a record for the dialog.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $type  Entity.
	 * @param string $uid   Uid.
	 * @return array<string, mixed>
	 * @throws ActionError When not shareable or not allowed.
	 */
	public function describe( Actor $actor, string $type, string $uid ): array {
		list( $entity, $row ) = ObjectRef::resolve( $this->plugin, $actor, $type, $uid );
		if ( ! $actor->is_staff() ) {
			throw ActionError::forbidden();
		}
		$members = $this->members_of( $entity->name, (int) $row['id'], 'direct' );
		if ( '' !== $entity->project_field && ! empty( $row[ $entity->project_field ] ) && ( ! $entity->shareable || 'inherit' === ( $row['access'] ?? 'inherit' ) ) ) {
			$members = array_merge( $members, $this->members_of( 'projects', (int) $row[ $entity->project_field ], 'project' ) );
		}
		return array(
			'mode'         => $entity->shareable ? (string) $row['access'] : 'internal',
			'default_role' => $entity->shareable ? (string) $row['access_role'] : 'viewer',
			'shareable'    => $entity->shareable,
			'can_share'    => $this->can_share( $actor, $entity, $row ),
			'members'      => $members,
			'explanation'  => $this->explain( $entity, $row, $members ),
		);
	}

	/**
	 * Members of an object with display chips.
	 *
	 * @param string $type   Entity name.
	 * @param int    $id     Row id.
	 * @param string $source direct|project.
	 * @return array<int, array<string, mixed>>
	 */
	public function members_of( string $type, int $id, string $source ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT principal_type, principal_id, role FROM {$this->table()} WHERE object_type = %s AND object_id = %d ORDER BY id", $type, $id ), ARRAY_A );
		$ids  = array(
			'user'   => array(),
			'client' => array(),
			'team'   => array(),
		);
		foreach ( $rows as $row ) {
			$ids[ (string) $row['principal_type'] ][] = (int) $row['principal_id'];
		}
		$refs   = array(
			'user'   => ObjectRef::refs( $this->plugin, 'members', $ids['user'] ),
			'client' => ObjectRef::refs( $this->plugin, 'clients', $ids['client'] ),
			'team'   => ObjectRef::refs( $this->plugin, 'teams', $ids['team'] ),
		);
		$emails = $this->emails( $ids['user'] );
		$out    = array();
		foreach ( $rows as $row ) {
			$ref = $refs[ (string) $row['principal_type'] ][ (int) $row['principal_id'] ] ?? null;
			if ( ! $ref ) {
				continue;
			}
			$out[] = array(
				'principal_type' => (string) $row['principal_type'],
				'principal'      => $ref,
				'email'          => 'user' === $row['principal_type'] ? ( $emails[ (int) $row['principal_id'] ]['email'] ?? null ) : null,
				'kind'           => 'user' === $row['principal_type'] ? ( $emails[ (int) $row['principal_id'] ]['kind'] ?? 'staff' ) : (string) $row['principal_type'],
				// The contact behind a client's person, so the dialog can
				// offer their page, "view as" and a second invitation.
				'contact'        => 'user' === $row['principal_type'] ? ( $emails[ (int) $row['principal_id'] ]['contact'] ?? null ) : null,
				'pending'        => 'user' === $row['principal_type'] && ! empty( $emails[ (int) $row['principal_id'] ]['pending'] ),
				'role'           => (string) $row['role'],
				'source'         => $source,
			);
		}
		return $out;
	}

	/**
	 * Emails and kinds of members (one query).
	 *
	 * @param int[] $member_ids Member ids.
	 * @return array<int, array{email: ?string, kind: string}>
	 */
	private function emails( array $member_ids ): array {
		global $wpdb;
		$member_ids = array_values( array_unique( array_filter( $member_ids ) ) );
		if ( ! $member_ids ) {
			return array();
		}
		$table = esc_sql( $this->plugin->entities()->get( 'members' )->table() );
		$in    = implode( ',', array_fill( 0, count( $member_ids ), '%d' ) );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, email, kind FROM {$table} WHERE id IN ({$in})", $member_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Prepared above; table from the entity.
		$out   = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['id'] ] = array(
				'email' => $row['email'],
				'kind'  => (string) $row['kind'],
			);
		}
		// Somebody from a client's side is a contact, and a contact has a
		// page: the share dialog is where people end up asking "who is
		// that?", and a name with nowhere to go is no answer. Asked for
		// the people on this list only — never the whole address book.
		if ( $this->plugin->entities()->has( 'contacts' ) ) {
			$contacts = esc_sql( $this->plugin->entities()->get( 'contacts' )->table() );
			$rows     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT uid, member_id, portal_status FROM {$contacts} WHERE member_id IN ({$in}) AND deleted_at IS NULL", $member_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Prepared above; table from the entity.
			foreach ( $rows as $contact ) {
				$member = (int) $contact['member_id'];
				if ( isset( $out[ $member ] ) ) {
					$out[ $member ]['contact'] = (string) $contact['uid'];
					// "invited" is where an invitation waits until it is
					// accepted, which is when sending it again helps.
					$out[ $member ]['pending'] = 'invited' === (string) $contact['portal_status'];
				}
			}
		}
		return $out;
	}

	/**
	 * Plain-language "who has access and why".
	 *
	 * @param Entity                           $entity  Entity.
	 * @param array<string, mixed>             $row     Row.
	 * @param array<int, array<string, mixed>> $members Members.
	 * @return string[]
	 */
	private function explain( Entity $entity, array $row, array $members ): array {
		$lines   = array( __( 'Owners and admins can open everything.', 'cliezoo' ) );
		$mode    = $entity->shareable ? (string) $row['access'] : 'internal';
		$project = '' !== $entity->project_field && ! empty( $row[ $entity->project_field ] );
		switch ( $mode ) {
			case 'private':
				$lines[] = __( 'Private: only the people and companies listed below.', 'cliezoo' );
				break;
			case 'clients':
				$lines[] = __( 'Shared with clients: the listed people, the team with the right role, and the client company this belongs to.', 'cliezoo' );
				break;
			case 'internal':
				/* translators: %s: object role. */
				$lines[] = sprintf( __( 'Internal: everyone on the team whose role covers this area, with the default role "%s".', 'cliezoo' ), (string) ( $row['access_role'] ?? 'viewer' ) );
				break;
			case 'public':
				$lines[] = __( 'Public link: anyone with the link can view and download.', 'cliezoo' );
				break;
			default:
				$lines[] = $project ? __( 'Follows the project it belongs to.', 'cliezoo' ) : __( 'Visible to the team whose role covers this area.', 'cliezoo' );
		}
		foreach ( $members as $member ) {
			$lines[] = 'project' === $member['source']
				/* translators: 1: person or company, 2: object role. */
				? sprintf( __( '%1$s has access as a project member (%2$s).', 'cliezoo' ), $member['principal']['label'], $member['role'] )
				/* translators: 1: person or company, 2: object role. */
				: sprintf( __( '%1$s was added directly (%2$s).', 'cliezoo' ), $member['principal']['label'], $member['role'] );
		}
		return $lines;
	}

	/**
	 * Sets the general access mode.
	 *
	 * @param Actor  $actor        Actor.
	 * @param string $type         Entity.
	 * @param string $uid          Uid.
	 * @param string $mode         inherit|private|clients|internal|public.
	 * @param string $default_role Default role for "internal".
	 * @return array<string, mixed>
	 * @throws ActionError When not allowed or not shareable.
	 */
	public function set_mode( Actor $actor, string $type, string $uid, string $mode, string $default_role ): array {
		list( $entity, $row ) = ObjectRef::resolve( $this->plugin, $actor, $type, $uid );
		if ( ! $entity->shareable ) {
			throw ActionError::validation( __( 'This item follows the access of its parent.', 'cliezoo' ) );
		}
		if ( ! $this->can_share( $actor, $entity, $row ) ) {
			throw ActionError::forbidden();
		}
		$this->plugin->service( $entity->name )->repository()->update(
			(int) $row['id'],
			array(
				'access'      => $mode,
				'access_role' => $default_role,
			)
		);
		do_action(
			'cliezoo_audit',
			'access_mode_changed',
			array(
				'object_type' => $entity->name,
				'object_id'   => (int) $row['id'],
				'mode'        => $mode,
			)
		);
		/**
		 * How a record is shared has changed.
		 *
		 * Anything that holds other records — a folder holds files — passes
		 * the change on from here, so sharing one thing shares what is in it.
		 *
		 * @param Entity $entity The entity.
		 * @param array  $row    The record, as it is now.
		 * @param string $mode   The new mode.
		 * @param Actor  $actor  Who changed it.
		 */
		do_action(
			'cliezoo_access_mode_changed',
			$entity,
			(array) $this->plugin->service( $entity->name )->repository()->find( (int) $row['id'] ),
			$mode,
			$actor
		);
		return $this->describe( $actor, $type, $uid );
	}

	/**
	 * Adds or updates a member.
	 *
	 * @param Actor  $actor          Actor.
	 * @param string $type           Entity.
	 * @param string $uid            Uid.
	 * @param string $principal_type user|client|team.
	 * @param string $principal_uid  Principal uid.
	 * @param string $role           Object role.
	 * @return array<string, mixed>
	 * @throws ActionError When not allowed.
	 */
	public function grant( Actor $actor, string $type, string $uid, string $principal_type, string $principal_uid, string $role ): array {
		list( $entity, $row ) = ObjectRef::resolve( $this->plugin, $actor, $type, $uid );
		if ( ! $this->can_share( $actor, $entity, $row ) ) {
			throw ActionError::forbidden();
		}
		$principal_id = $this->principal_id( $actor, $principal_type, $principal_uid );
		$this->put( $entity->name, (int) $row['id'], $principal_type, $principal_id, $role, $actor->member_id );
		/**
		 * Someone was given access to a record (notifications, invitations).
		 *
		 * @param Entity $entity         Entity.
		 * @param array  $row            Row.
		 * @param string $principal_type Principal type.
		 * @param int    $principal_id   Principal id.
		 * @param string $role           Role.
		 * @param Actor  $actor          Actor.
		 */
		do_action( 'cliezoo_access_granted', $entity, $row, $principal_type, $principal_id, $role, $actor );
		do_action(
			'cliezoo_audit',
			'access_granted',
			array(
				'object_type'    => $entity->name,
				'object_id'      => (int) $row['id'],
				'principal_type' => $principal_type,
				'principal_id'   => $principal_id,
				'role'           => $role,
			)
		);
		return $this->describe( $actor, $type, $uid );
	}

	/**
	 * Removes a member.
	 *
	 * @param Actor  $actor          Actor.
	 * @param string $type           Entity.
	 * @param string $uid            Uid.
	 * @param string $principal_type user|client|team.
	 * @param string $principal_uid  Principal uid.
	 * @return array<string, mixed>
	 * @throws ActionError When not allowed.
	 */
	public function revoke( Actor $actor, string $type, string $uid, string $principal_type, string $principal_uid ): array {
		list( $entity, $row ) = ObjectRef::resolve( $this->plugin, $actor, $type, $uid );
		if ( ! $this->can_share( $actor, $entity, $row ) ) {
			throw ActionError::forbidden();
		}
		$principal_id = $this->principal_id( $actor, $principal_type, $principal_uid );
		$this->remove( $entity->name, (int) $row['id'], $principal_type, $principal_id );
		do_action(
			'cliezoo_audit',
			'access_revoked',
			array(
				'object_type'    => $entity->name,
				'object_id'      => (int) $row['id'],
				'principal_type' => $principal_type,
				'principal_id'   => $principal_id,
			)
		);
		/**
		 * Someone's access to a record was taken away.
		 *
		 * @param Entity $entity         Entity.
		 * @param array  $row            Row.
		 * @param string $principal_type Principal type.
		 * @param int    $principal_id   Principal id.
		 * @param Actor  $actor          Actor.
		 */
		do_action( 'cliezoo_access_revoked', $entity, $row, $principal_type, $principal_id, $actor );
		return $this->describe( $actor, $type, $uid );
	}

	/**
	 * Low-level upsert of a membership (also used by projects' team management).
	 *
	 * @param string $type           Entity.
	 * @param int    $id             Row id.
	 * @param string $principal_type Principal type.
	 * @param int    $principal_id   Principal id.
	 * @param string $role           Role.
	 * @param int    $by             Member who granted.
	 * @return void
	 */
	public function put( string $type, int $id, string $principal_type, int $principal_id, string $role, int $by = 0 ): void {
		global $wpdb;
		if ( ! in_array( $role, Policy::OBJECT_ROLES, true ) ) {
			$role = 'viewer';
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->table()} (object_type, object_id, principal_type, principal_id, role, invited_by, created_by, created_at, updated_at) VALUES (%s, %d, %s, %d, %s, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE role = VALUES(role), updated_at = VALUES(updated_at)",
				$type,
				$id,
				$principal_type,
				$principal_id,
				$role,
				$by,
				$by,
				$now,
				$now
			)
		);
	}

	/**
	 * Gives one record the same people as another, with the same roles.
	 *
	 * Low level, like put(): whoever calls it has checked that the person
	 * may share both.
	 *
	 * @param string $type       Entity.
	 * @param int    $from       The record whose people are copied.
	 * @param int    $to         The record that gets them.
	 * @param int    $by         Member who copied them (keeps their own role on the copy).
	 * @param bool   $staff_only Only the team: no clients, no client contacts.
	 * @return int How many were given access.
	 */
	public function copy( string $type, int $from, int $to, int $by = 0, bool $staff_only = false ): int {
		global $wpdb;
		$rows    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT principal_type, principal_id, role FROM {$this->table()} WHERE object_type = %s AND object_id = %d", $type, $from ), ARRAY_A );
		$members = $this->plugin->service( 'members' )->repository();
		$copied  = 0;
		foreach ( $rows as $row ) {
			$kind = (string) $row['principal_type'];
			$id   = (int) $row['principal_id'];
			if ( 'user' === $kind && $id === $by ) {
				continue;
			}
			if ( $staff_only ) {
				$member = 'user' === $kind ? $members->find( $id ) : null;
				if ( 'client' === $kind || ( 'user' === $kind && ( ! $member || 'staff' !== (string) $member['kind'] ) ) ) {
					continue;
				}
			}
			$this->put( $type, $to, $kind, $id, (string) $row['role'], $by );
			++$copied;
		}
		do_action(
			'cliezoo_audit',
			'access_copied',
			array(
				'object_type' => $type,
				'object_id'   => $to,
				'from'        => $from,
				'count'       => $copied,
			)
		);
		return $copied;
	}

	/**
	 * Low-level removal of a membership.
	 *
	 * @param string $type           Entity.
	 * @param int    $id             Row id.
	 * @param string $principal_type Principal type.
	 * @param int    $principal_id   Principal id.
	 * @return void
	 */
	public function remove( string $type, int $id, string $principal_type, int $principal_id ): void {
		global $wpdb;
		$wpdb->delete(
			$this->table(),
			array(
				'object_type'    => $type,
				'object_id'      => $id,
				'principal_type' => $principal_type,
				'principal_id'   => $principal_id,
			)
		);
	}

	/**
	 * Everything a client company or a member has explicit access to, with levels.
	 *
	 * @param Actor  $actor          Actor (owner/admin or members permission).
	 * @param string $principal_type client|user.
	 * @param string $principal_uid  Uid.
	 * @return array<int, array<string, mixed>>
	 * @throws ActionError When not allowed.
	 */
	public function inventory( Actor $actor, string $principal_type, string $principal_uid ): array {
		if ( ! $actor->is_admin() && ! $actor->allows( 'settings_members' ) && ! $actor->allows( 'clients_edit' ) ) {
			throw ActionError::forbidden();
		}
		global $wpdb;
		$principal_id = $this->principal_id( $actor, $principal_type, $principal_uid );
		$rows         = (array) $wpdb->get_results( $wpdb->prepare( "SELECT object_type, object_id, role FROM {$this->table()} WHERE principal_type = %s AND principal_id = %d ORDER BY object_type, object_id", $principal_type, $principal_id ), ARRAY_A );
		$ids          = array();
		foreach ( $rows as $row ) {
			$ids[ (string) $row['object_type'] ][] = (int) $row['object_id'];
		}
		$refs = array();
		foreach ( $ids as $type => $list ) {
			$refs[ $type ] = ObjectRef::refs( $this->plugin, $type, $list );
		}
		// Somebody who runs access for the whole workspace sees all of it;
		// anybody else (client editors) sees only what they may share: a
		// private record's name is not theirs to read.
		$everything = $actor->is_admin() || $actor->allows( 'settings_members' );
		$out        = array();
		foreach ( $rows as $row ) {
			$ref = $refs[ (string) $row['object_type'] ][ (int) $row['object_id'] ] ?? null;
			if ( $ref && ! $everything && ! $this->may_share_id( $actor, (string) $row['object_type'], (int) $row['object_id'] ) ) {
				continue;
			}
			if ( $ref ) {
				$out[] = array(
					'type' => (string) $row['object_type'],
					'role' => (string) $row['role'],
				) + $ref;
			}
		}
		return $out;
	}

	/**
	 * Whether this person may share a record, by its id.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $type  Entity.
	 * @param int    $id    Record id.
	 * @return bool
	 */
	private function may_share_id( Actor $actor, string $type, int $id ): bool {
		if ( ! $this->plugin->entities()->has( $type ) ) {
			return false;
		}
		$entity = $this->plugin->entities()->get( $type );
		$row    = ( new Repository( $entity, $this->plugin->entities() ) )->find( $id );
		return $row && $this->can_share( $actor, $entity, $row );
	}

	/**
	 * Revokes several items of an inventory at once.
	 *
	 * @param Actor                            $actor          Actor.
	 * @param string                           $principal_type client|user.
	 * @param string                           $principal_uid  Uid.
	 * @param array<int, array<string, mixed>> $items          [{type, uid}].
	 * @return array{revoked: int}
	 * @throws ActionError When not allowed.
	 */
	public function bulk_revoke( Actor $actor, string $principal_type, string $principal_uid, array $items ): array {
		if ( ! $actor->is_admin() && ! $actor->allows( 'settings_members' ) && ! $actor->allows( 'clients_edit' ) ) {
			throw ActionError::forbidden();
		}
		$principal_id = $this->principal_id( $actor, $principal_type, $principal_uid );
		$everything   = $actor->is_admin() || $actor->allows( 'settings_members' );
		$count        = 0;
		foreach ( $items as $item ) {
			$type = (string) ( $item['type'] ?? '' );
			if ( ! $this->plugin->entities()->has( $type ) ) {
				continue;
			}
			$row = ( new Repository( $this->plugin->entities()->get( $type ), $this->plugin->entities() ) )->find_by_uid( (string) ( $item['uid'] ?? '' ) );
			// Only access to what this person may share is theirs to take away.
			if ( $row && ! $everything && ! $this->can_share( $actor, $this->plugin->entities()->get( $type ), $row ) ) {
				continue;
			}
			if ( $row ) {
				$this->remove( $type, (int) $row['id'], $principal_type, $principal_id );
				++$count;
			}
		}
		do_action(
			'cliezoo_audit',
			'access_bulk_revoked',
			array(
				'principal_type' => $principal_type,
				'principal_id'   => $principal_id,
				'count'          => $count,
			)
		);
		return array( 'revoked' => $count );
	}

	/**
	 * Whether a given member can see a record, and why ("View as").
	 *
	 * @param Actor  $actor      Actor (staff with share rights).
	 * @param string $type       Entity.
	 * @param string $uid        Uid.
	 * @param string $member_uid Member to check.
	 * @return array{can_view: bool, can_edit: bool}
	 * @throws ActionError When not allowed.
	 */
	public function check( Actor $actor, string $type, string $uid, string $member_uid ): array {
		list( $entity, $row ) = ObjectRef::resolve( $this->plugin, $actor, $type, $uid );
		if ( ! $this->can_share( $actor, $entity, $row ) ) {
			throw ActionError::forbidden();
		}
		$member = $this->plugin->service( 'members' )->repository()->find_by_uid( $member_uid );
		if ( ! $member ) {
			throw ActionError::not_found();
		}
		$other = $this->plugin->get( \Cliezoo\Platform\Members\Members::class )->actor_for( (int) $member['user_id'] );
		return array(
			'can_view' => $this->plugin->policy()->can( $other, Policy::VIEW, $entity, $row ),
			'can_edit' => $this->plugin->policy()->can( $other, Policy::UPDATE, $entity, $row ),
		);
	}

	/**
	 * Resolves a principal uid.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $type  user|client|team.
	 * @param string $uid   Uid.
	 * @return int
	 * @throws ActionError When unknown.
	 */
	private function principal_id( Actor $actor, string $type, string $uid ): int {
		$entity = array(
			'user'   => 'members',
			'client' => 'clients',
			'team'   => 'teams',
		)[ $type ] ?? '';
		if ( '' === $entity || ! $this->plugin->entities()->has( $entity ) ) {
			throw ActionError::validation( __( 'Unknown person, company or team.', 'cliezoo' ) );
		}
		$row = $this->plugin->service( $entity )->load( $actor, $uid );
		return (int) $row['id'];
	}
}
