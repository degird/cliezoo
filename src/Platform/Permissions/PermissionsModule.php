<?php
/**
 * Roles, sharing and access actions.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Permissions;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Auth\ApiAccess;
use Cliezoo\Platform\Members\Members;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Share dialog, access inventory, role list and member role safety.
 */
final class PermissionsModule extends BaseModule {

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
		return 'permissions';
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
		$plugin->container()->set( AccessService::class, static fn(): AccessService => new AccessService( $plugin ) );
		add_action( 'cliezoo_register_actions', array( $this, 'actions' ) );
		add_filter( 'cliezoo_members_before_update', array( $this, 'guard_member_update' ), 10, 3 );
	}

	/**
	 * Only people who manage members may change roles and status; nobody but
	 * the owner assigns "owner" (use transfer ownership for that).
	 *
	 * @param array<string, mixed> $data  Changes.
	 * @param array<string, mixed> $row   Current row.
	 * @param Actor                $actor Actor.
	 * @return array<string, mixed>
	 * @throws ActionError When not allowed.
	 */
	public function guard_member_update( array $data, array $row, Actor $actor ): array {
		$sensitive = array_intersect( array_keys( $data ), array( 'role', 'status' ) );
		$changes   = array_filter( $sensitive, static fn( string $key ): bool => (string) $data[ $key ] !== (string) $row[ $key ] );
		if ( ! $changes ) {
			return $data;
		}
		if ( ! $actor->is_admin() && ! $actor->allows( 'settings_members' ) ) {
			throw ActionError::forbidden();
		}
		// Roles and who may sign in are administered in the app only.
		if ( ! $actor->is_system() && ApiAccess::is_remote( $actor ) ) {
			throw ApiAccess::in_app_refusal();
		}
		if ( isset( $data['role'] ) ) {
			$valid = 'client' === $row['kind'] ? Roles::CLIENT_ROLES : Members::staff_roles();
			if ( ! in_array( (string) $data['role'], $valid, true ) ) {
				throw ActionError::validation( __( 'Unknown role.', 'cliezoo' ), array( 'role' => __( 'Unknown role.', 'cliezoo' ) ) );
			}
			if ( ( 'owner' === $data['role'] || 'owner' === $row['role'] ) && ! $actor->is_owner() ) {
				throw ActionError::forbidden();
			}
		}
		if ( (int) $row['id'] === $actor->member_id && isset( $data['status'] ) && 'active' !== $data['status'] ) {
			throw ActionError::conflict( __( 'You cannot deactivate yourself.', 'cliezoo' ) );
		}
		do_action(
			'cliezoo_audit',
			'member_changed',
			array(
				'object_type' => 'members',
				'object_id'   => (int) $row['id'],
				'changes'     => array_intersect_key( $data, array_flip( $changes ) ),
			)
		);
		return $data;
	}

	/**
	 * Registers actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @return void
	 */
	public function actions( ActionRegistry $registry ): void {
		$access    = fn(): AccessService => $this->plugin->get( AccessService::class );
		$object    = '/access/(?P<object_type>[a-z_]{2,40})/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})';
		$type      = array(
			'type'     => 'string',
			'pattern'  => '^[a-z_]{2,40}$',
			'required' => true,
		);
		$principal = array(
			'principal_type' => array(
				'type'     => 'string',
				'enum'     => array( 'user', 'client', 'team' ),
				'required' => true,
			),
			'principal'      => Op::uid(),
		);

		$registry->add(
			Op::make( 'access.get', __( 'Who has access', 'cliezoo' ) )
				->input(
					array(
						'object_type' => $type,
						'uid'         => Op::uid(),
					)
				)
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run( static fn( Actor $a, array $in ) => $access()->describe( $a, (string) $in['object_type'], (string) $in['uid'] ) )
				->rest( 'GET', $object )
				->readonly()
		);
		$registry->add(
			Op::make( 'access.set_mode', __( 'Change general access', 'cliezoo' ) )
				->input(
					array(
						'object_type'  => $type,
						'uid'          => Op::uid(),
						'mode'         => array(
							'type'     => 'string',
							'enum'     => array( 'inherit', 'private', 'clients', 'internal', 'public' ),
							'required' => true,
						),
						'default_role' => array(
							'type'    => 'string',
							'enum'    => Policy::OBJECT_ROLES,
							'default' => 'viewer',
						),
					)
				)
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run( static fn( Actor $a, array $in ) => $access()->set_mode( $a, (string) $in['object_type'], (string) $in['uid'], (string) $in['mode'], (string) ( $in['default_role'] ?? 'viewer' ) ) )
				->rest( 'POST', $object . '/mode' )
				->with( array( 'destructive' => true ) )
		);
		$registry->add(
			Op::make( 'access.grant', __( 'Share with a person, company or team', 'cliezoo' ) )
				->input(
					array(
						'object_type' => $type,
						'uid'         => Op::uid(),
						'role'        => array(
							'type'    => 'string',
							'enum'    => Policy::OBJECT_ROLES,
							'default' => 'viewer',
						),
					) + $principal
				)
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run( static fn( Actor $a, array $in ) => $access()->grant( $a, (string) $in['object_type'], (string) $in['uid'], (string) $in['principal_type'], (string) $in['principal'], (string) ( $in['role'] ?? 'viewer' ) ) )
				->rest( 'POST', $object . '/members' )
				->with( array( 'destructive' => true ) )
		);
		$registry->add(
			Op::make( 'access.revoke', __( 'Remove access', 'cliezoo' ) )
				->input(
					array(
						'object_type' => $type,
						'uid'         => Op::uid(),
					) + $principal
				)
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run( static fn( Actor $a, array $in ) => $access()->revoke( $a, (string) $in['object_type'], (string) $in['uid'], (string) $in['principal_type'], (string) $in['principal'] ) )
				->rest( 'DELETE', $object . '/members' )
				->with( array( 'destructive' => true ) )
		);
		$registry->add(
			Op::make( 'access.check', __( 'View as a member', 'cliezoo' ) )
				->input(
					array(
						'object_type' => $type,
						'uid'         => Op::uid(),
						'member'      => Op::uid(),
					)
				)
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run( static fn( Actor $a, array $in ) => $access()->check( $a, (string) $in['object_type'], (string) $in['uid'], (string) $in['member'] ) )
				->rest( 'GET', $object . '/check' )
				->readonly()
		);
		$registry->add(
			Op::make( 'access.inventory', __( 'Access inventory', 'cliezoo' ) )
				->input( $principal )
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run( static fn( Actor $a, array $in ) => $access()->inventory( $a, (string) $in['principal_type'], (string) $in['principal'] ) )
				->rest( 'GET', '/access/inventory' )
				->readonly()
		);
		$registry->add(
			Op::make( 'access.bulk_revoke', __( 'Revoke access in bulk', 'cliezoo' ) )
				->input(
					$principal + array(
						'items' => array(
							'type'     => 'array',
							'required' => true,
							'maxItems' => 500,
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'type' => array(
										'type'    => 'string',
										'pattern' => '^[a-z_]{2,40}$',
									),
									'uid'  => Op::uid(),
								),
							),
						),
					)
				)
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run( static fn( Actor $a, array $in ) => $access()->bulk_revoke( $a, (string) $in['principal_type'], (string) $in['principal'], (array) $in['items'] ) )
				->rest( 'POST', '/access/inventory/revoke' )
				->with( array( 'destructive' => true ) )
		);
		$registry->add(
			Op::make( 'roles.list', __( 'Roles and permissions', 'cliezoo' ) )
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run(
					static function (): array {
						// Roles for work this plugin does not do are not offered.
						$hidden = array_merge( array( 'project_manager', 'finance', 'ai_agent' ), Roles::CLIENT_ROLES );
						$out    = array();
						foreach ( Roles::matrices() as $key => $matrix ) {
							if ( in_array( $key, $hidden, true ) ) {
								continue;
							}
							$out[] = array(
								'key'     => $key,
								'label'   => Roles::labels()[ $key ] ?? $key,
								'client'  => in_array( $key, Roles::CLIENT_ROLES, true ),
								'builtin' => in_array( $key, array_merge( Roles::STAFF_ROLES, Roles::CLIENT_ROLES ), true ),
								'matrix'  => $matrix,
							);
						}
						return array(
							'roles'   => $out,
							'areas'   => array( 'leads', 'clients', 'contacts', 'files' ),
							'toggles' => array(
								'resources' => array( 'create_folders', 'create_links', 'resources_admin' ),
								'clients'   => array( 'clients_edit' ),
								'settings'  => array( 'settings_general', 'settings_members', 'settings_custom_fields', 'settings_api' ),
								'other'     => array( 'templates_manage' ),
								'data'      => array( 'data_export', 'audit_view' ),
								'email'     => array( 'email_templates' ),
							),
						);
					}
				)
				->rest( 'GET', '/roles' )
				->readonly()
		);
	}
}
