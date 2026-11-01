<?php
/**
 * Built-in roles and the permission matrix.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The permission matrix (cliezoo.md §6.31) and the built-in role presets.
 *
 * Record areas carry a scope: none | own | team | all. Toggles are booleans.
 * Custom roles (from add-ons) add matrices through the `cliezoo_role_matrices` filter.
 */
final class Roles {

	public const STAFF_ROLES  = array( 'owner', 'admin', 'member', 'project_manager', 'sales', 'finance', 'contractor', 'readonly', 'ai_agent' );
	public const CLIENT_ROLES = array( 'client_admin', 'client_member', 'client_viewer' );

	/**
	 * Record areas with a scope.
	 */
	public const AREAS = array( 'leads', 'deals', 'clients', 'contacts', 'catalogue', 'documents', 'projects', 'tasks', 'files', 'conversations', 'requests', 'docs', 'time', 'expenses', 'invoices', 'payments', 'calendar', 'forms', 'reports' );

	/**
	 * Boolean permissions, grouped as in the matrix UI.
	 */
	public const TOGGLES = array(
		'resources' => array( 'create_folders', 'create_boards', 'create_conversations', 'create_docs', 'create_embeds', 'create_links', 'create_milestones', 'create_invoices', 'create_quotes', 'create_proposals', 'resources_admin' ),
		'clients'   => array( 'clients_invite', 'clients_edit' ),
		'settings'  => array( 'settings_general', 'settings_members', 'settings_payments', 'settings_custom_fields', 'settings_forms', 'settings_apps', 'settings_api', 'settings_billing' ),
		'other'     => array( 'templates_manage' ),
		'sales'     => array( 'pipelines_manage', 'catalogue_manage', 'contracts_esign' ),
		'work'      => array( 'projects_create', 'projects_edit', 'projects_delete', 'time_log_others', 'time_approve', 'time_edit_locked', 'expenses_approve', 'kb_manage' ),
		'finance'   => array( 'finance_see_rates', 'finance_record_payments', 'finance_credit_notes', 'reports_financial' ),
		'data'      => array( 'automations_manage', 'data_import', 'data_export', 'audit_view' ),
		'ai'        => array( 'ai_use', 'ai_assistant_actions', 'ai_manage', 'ai_auto_run', 'ai_usage' ),
		'email'     => array( 'email_send', 'email_bulk', 'email_sequences', 'email_templates' ),
	);

	/**
	 * Resolved matrices for this request.
	 *
	 * @var array<string, array{scopes: array<string, string>, toggles: array<string, bool>, write: bool}>|null
	 */
	private static ?array $cache = null;

	/**
	 * Human labels for roles.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'owner'           => __( 'Owner', 'cliezoo' ),
			'admin'           => __( 'Admin', 'cliezoo' ),
			'member'          => __( 'Member', 'cliezoo' ),
			'project_manager' => __( 'Project Manager', 'cliezoo' ),
			'sales'           => __( 'Sales', 'cliezoo' ),
			'finance'         => __( 'Finance', 'cliezoo' ),
			'contractor'      => __( 'Contractor', 'cliezoo' ),
			'readonly'        => __( 'Read-only', 'cliezoo' ),
			'ai_agent'        => __( 'AI Agent', 'cliezoo' ),
			'client_admin'    => __( 'Client Admin', 'cliezoo' ),
			'client_member'   => __( 'Client Member', 'cliezoo' ),
			'client_viewer'   => __( 'Client Viewer', 'cliezoo' ),
		);
	}

	/**
	 * Every toggle name.
	 *
	 * @return string[]
	 */
	public static function all_toggles(): array {
		return array_merge( ...array_values( self::TOGGLES ) );
	}

	/**
	 * Matrix for a role: ['scopes' => area => scope, 'toggles' => name => bool, 'write' => bool].
	 *
	 * @param string $role Role key.
	 * @return array{scopes: array<string, string>, toggles: array<string, bool>, write: bool}
	 */
	public static function matrix( string $role ): array {
		$matrices = self::matrices();
		return $matrices[ $role ] ?? self::empty_matrix();
	}

	/**
	 * All role matrices (built-in + filtered).
	 *
	 * @return array<string, array{scopes: array<string, string>, toggles: array<string, bool>, write: bool}>
	 */
	public static function matrices(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$all   = array_fill_keys( self::AREAS, 'all' );
		$every = array_fill_keys( self::all_toggles(), true );
		$none  = array_fill_keys( self::all_toggles(), false );

		$built_in = array(
			'owner'           => array(
				'scopes'  => $all,
				'toggles' => $every,
				'write'   => true,
			),
			'admin'           => array(
				'scopes'  => $all,
				'toggles' => array_merge( $every, array( 'settings_billing' => false ) ),
				'write'   => true,
			),
			'member'          => array(
				'scopes'  => array_merge(
					$all,
					array(
						'invoices' => 'none',
						'payments' => 'none',
						'reports'  => 'none',
						'time'     => 'own',
						'expenses' => 'own',
					)
				),
				'toggles' => array_merge(
					$none,
					self::on( array( 'create_folders', 'create_boards', 'create_conversations', 'create_docs', 'create_embeds', 'create_links', 'create_milestones', 'create_quotes', 'create_proposals', 'clients_invite', 'projects_create', 'projects_edit', 'ai_use', 'email_send' ) )
				),
				'write'   => true,
			),
			'project_manager' => array(
				'scopes'  => array_merge(
					$all,
					array(
						'invoices' => 'none',
						'payments' => 'none',
						'reports'  => 'all',
					)
				),
				'toggles' => array_merge(
					$none,
					self::on( array( 'create_folders', 'create_boards', 'create_conversations', 'create_docs', 'create_embeds', 'create_links', 'create_milestones', 'create_quotes', 'create_proposals', 'clients_invite', 'clients_edit', 'templates_manage', 'projects_create', 'projects_edit', 'projects_delete', 'time_log_others', 'time_approve', 'expenses_approve', 'kb_manage', 'ai_use', 'ai_assistant_actions', 'email_send', 'data_export' ) )
				),
				'write'   => true,
			),
			'sales'           => array(
				'scopes'  => array_merge(
					$all,
					array(
						'projects' => 'own',
						'tasks'    => 'own',
						'time'     => 'own',
						'expenses' => 'own',
						'invoices' => 'none',
						'payments' => 'none',
					)
				),
				'toggles' => array_merge(
					$none,
					self::on( array( 'create_conversations', 'create_docs', 'create_quotes', 'create_proposals', 'clients_invite', 'clients_edit', 'pipelines_manage', 'catalogue_manage', 'contracts_esign', 'ai_use', 'ai_assistant_actions', 'email_send', 'data_export' ) )
				),
				'write'   => true,
			),
			'finance'         => array(
				'scopes'  => array_merge(
					$all,
					array(
						'projects' => 'all',
						'tasks'    => 'own',
					)
				),
				'toggles' => array_merge(
					$none,
					self::on( array( 'create_invoices', 'create_quotes', 'clients_edit', 'settings_payments', 'templates_manage', 'catalogue_manage', 'time_log_others', 'time_approve', 'time_edit_locked', 'expenses_approve', 'finance_see_rates', 'finance_record_payments', 'finance_credit_notes', 'reports_financial', 'data_export', 'ai_use', 'ai_usage', 'email_send' ) )
				),
				'write'   => true,
			),
			'contractor'      => array(
				'scopes'  => array_merge(
					array_fill_keys( self::AREAS, 'none' ),
					array(
						'projects'      => 'own',
						'tasks'         => 'own',
						'files'         => 'own',
						'conversations' => 'own',
						'docs'          => 'own',
						'time'          => 'own',
						'expenses'      => 'own',
						'calendar'      => 'own',
					)
				),
				'toggles' => array_merge( $none, self::on( array( 'ai_use' ) ) ),
				'write'   => true,
			),
			'readonly'        => array(
				'scopes'  => array_merge(
					$all,
					array(
						'invoices' => 'none',
						'payments' => 'none',
						'reports'  => 'none',
					)
				),
				'toggles' => $none,
				'write'   => false,
			),
			'ai_agent'        => array(
				'scopes'  => array_merge(
					$all,
					array(
						'payments' => 'none',
						'reports'  => 'all',
					)
				),
				'toggles' => array_merge(
					$none,
					self::on( array( 'create_boards', 'create_conversations', 'create_docs', 'create_milestones', 'create_invoices', 'create_quotes', 'create_proposals', 'projects_create', 'projects_edit', 'email_send', 'time_log_others' ) )
				),
				'write'   => true,
			),
		);

		/**
		 * Filters the role matrices (add-on custom roles register here).
		 *
		 * @param array $built_in Role key => matrix.
		 */
		self::$cache = (array) apply_filters( 'cliezoo_role_matrices', $built_in );
		return self::$cache;
	}

	/**
	 * Resets the matrix cache (tests, role changes).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Empty matrix (no access).
	 *
	 * @return array{scopes: array<string, string>, toggles: array<string, bool>, write: bool}
	 */
	public static function empty_matrix(): array {
		return array(
			'scopes'  => array_fill_keys( self::AREAS, 'none' ),
			'toggles' => array_fill_keys( self::all_toggles(), false ),
			'write'   => false,
		);
	}

	/**
	 * Turns a list of toggles on.
	 *
	 * @param string[] $names Toggle names.
	 * @return array<string, bool>
	 */
	private static function on( array $names ): array {
		return array_fill_keys( $names, true );
	}

	/**
	 * Whether a role can see financial fields (rates, costs, margins). Free fixed rule.
	 *
	 * @param string $role Role key.
	 * @return bool
	 */
	public static function sees_finance( string $role ): bool {
		return ! empty( self::matrix( $role )['toggles']['finance_see_rates'] );
	}
}
