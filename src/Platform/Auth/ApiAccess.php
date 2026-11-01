<?php
/**
 * Who may use the API from outside the app.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Auth;

use Cliezoo\Core\Actions\Action;
use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * The app and the public API are the same REST routes (Blueprint §10.4):
 * the app signs its requests with the WordPress REST nonce of a browser
 * session, and anything else — a script, Zapier, a phone app — signs in
 * with a WordPress Application Password. Using the API that way is a
 * permission of its own, "API and webhooks" (`settings_api`), and it can
 * be given to any role without giving that role anything else (cliezoo.md
 * §6.39, the fix for Kitchen, where only a role with every other
 * permission can have it).
 *
 * In Free the built-in roles are fixed, so the permission is delegated by
 * naming roles in Settings → Security (`security.api_roles`); an add-on's
 * custom roles carry the toggle in their own matrix. Either way the check
 * is the toggle, on every Cliezoo route, before anything runs.
 */
final class ApiAccess {

	/**
	 * Operations that administer the site, kept to the app.
	 *
	 * The API is for a business's own data — leads, clients, contacts, files.
	 * Nothing outside the app (a script, Zapier, a phone app, an AI agent)
	 * may sign anybody in, change how anybody signs in, add or remove
	 * people, change roles or who can reach what, or change the site's
	 * settings: those run only for a person signed in to the app in their
	 * browser, where WordPress checks the REST nonce of their session.
	 * Changing a member's role or status is held back the same way, in
	 * PermissionsModule::guard_member_update().
	 *
	 * Written out, like Careful's lists, so a new administrative operation
	 * is added beside its kin rather than remembered in its own file.
	 */
	public const IN_APP_ONLY = array(
		// How somebody signs in.
		'auth.change_password',
		'auth.2fa_setup',
		'auth.2fa_enable',
		'auth.2fa_disable',
		'auth.2fa_reset',
		'auth.recovery_codes',
		'passkeys.register_options',
		'passkeys.register',
		'passkeys.delete',
		'sessions.revoke',
		'sessions.revoke_others',
		'api_keys.create',
		'api_keys.revoke',
		// Who is on the team and in the portal.
		'members.invite',
		'members.invite_resend',
		'members.invite_revoke',
		'members.transfer_ownership',
		'contacts.invite',
		'contacts.invite_resend',
		'contacts.invite_cancel',
		'contacts.portal',
		'clients.portal',
		// Who can reach which records.
		'access.set_mode',
		'access.grant',
		'access.revoke',
		'access.bulk_revoke',
		// The site's settings and setup.
		'settings.update',
		'safe_mode.update',
		'modules.switch',
		'numbering.update',
		'app_urls.update',
		'portal_domain.update',
		'portal_domain.verify',
		'email.smtp_check',
		'email_templates.save',
		'email_templates.restore',
		'leads.intake_key',
		'leads.intake_off',
		'setup.finish',
		'setup.industry',
		'setup.pretty_permalinks',
		'setup.sample_data',
		'setup.test_email',
	);

	/**
	 * Guards against settings being read while the matrices are built.
	 *
	 * @var bool
	 */
	private static bool $busy = false;

	/**
	 * Whether this request came from outside the app.
	 *
	 * WordPress sets `$wp_rest_application_password_uuid` when a request
	 * was signed in with an Application Password. Sites that add another
	 * way in (OAuth, JWT) say so through the filter.
	 *
	 * @return bool
	 */
	public static function is_api_request(): bool {
		$application_password = ! empty( $GLOBALS['wp_rest_application_password_uuid'] );
		/**
		 * Whether the current request is an API request from outside the
		 * app (subject to the "API and webhooks" permission).
		 *
		 * @param bool $api Default: signed in with an Application Password.
		 */
		return (bool) apply_filters( 'cliezoo_is_api_request', $application_password );
	}

	/**
	 * Whether this actor arrived from outside the app: an Application
	 * Password (or another way in a site adds), or the WordPress Abilities
	 * API that AI integrations call.
	 *
	 * @param Actor $actor Actor.
	 * @return bool
	 */
	public static function is_remote( Actor $actor ): bool {
		return 'ability' === $actor->channel || self::is_api_request();
	}

	/**
	 * The refusal for an administrative operation asked for from outside the app.
	 *
	 * @return ActionError
	 */
	public static function in_app_refusal(): ActionError {
		return new ActionError( 'cliezoo_in_app_only', __( 'This can only be done in the Cliezoo app, signed in — not through the API.', 'cliezoo' ), 403 );
	}

	/**
	 * Whether an actor may use the API.
	 *
	 * @param Actor $actor Actor.
	 * @return bool
	 */
	public static function allowed( Actor $actor ): bool {
		return $actor->is_system() || $actor->allows( 'settings_api' );
	}

	/**
	 * The refusal for somebody whose role may not use the API.
	 *
	 * @return ActionError
	 */
	public static function refusal(): ActionError {
		return new ActionError( 'cliezoo_api_not_allowed', __( 'Your role may not use the Cliezoo API. An owner or admin can allow it in Settings → Security.', 'cliezoo' ), 403 );
	}

	/**
	 * `cliezoo_action_gate`: refuses administrative operations from outside
	 * the app, and API requests from roles without the permission.
	 *
	 * Every channel runs through here (REST, POST /batch, Abilities), so
	 * this is the one place the rule is kept.
	 *
	 * @param ActionError|null $refusal Refusal so far.
	 * @param Action           $action  Action.
	 * @param Actor            $actor   Actor.
	 * @return ActionError|null
	 */
	public static function gate( $refusal, Action $action, Actor $actor ) {
		if ( $refusal || $actor->is_system() || ! self::is_remote( $actor ) ) {
			return $refusal;
		}
		if ( in_array( $action->name(), self::IN_APP_ONLY, true ) ) {
			return self::in_app_refusal();
		}
		if ( $actor->is_guest() || ! self::is_api_request() || self::allowed( $actor ) ) {
			return $refusal;
		}
		return self::refusal();
	}

	/**
	 * `cliezoo_route_allowed`: the same for hand-written routes (downloads, uploads).
	 *
	 * @param mixed $allowed Decision so far.
	 * @return mixed True, or a WP_Error.
	 */
	public static function route_gate( $allowed ) {
		if ( true !== $allowed || ! self::is_api_request() ) {
			return $allowed;
		}
		$actor = cliezoo()->actor();
		if ( $actor->is_guest() || self::allowed( $actor ) ) {
			return $allowed;
		}
		return self::refusal()->to_wp_error();
	}

	/**
	 * `cliezoo_role_matrices`: gives the roles named in Settings → Security
	 * the API permission, and nothing else.
	 *
	 * @param array<string, array<string, mixed>> $matrices Role key => matrix.
	 * @return array<string, array<string, mixed>>
	 */
	public static function delegate( array $matrices ): array {
		if ( self::$busy ) {
			return $matrices;
		}
		self::$busy = true;
		try {
			$roles = (array) cliezoo()->settings()->get( 'security', 'api_roles', array() );
		} finally {
			self::$busy = false;
		}
		foreach ( $roles as $role ) {
			$role = (string) $role;
			// Client roles are the portal: the API is for the team.
			if ( isset( $matrices[ $role ]['toggles'] ) && ! in_array( $role, Roles::CLIENT_ROLES, true ) ) {
				$matrices[ $role ]['toggles']['settings_api'] = true;
			}
		}
		return $matrices;
	}
}
