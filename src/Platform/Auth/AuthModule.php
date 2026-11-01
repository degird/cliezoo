<?php
/**
 * Authentication module.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Auth;

use Cliezoo\Core\Actions\Action;
use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Field;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\Rest\RouteRegistry;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * App login, magic links (consumed only by a POST from the Continue page),
 * password reset, TOTP 2FA + recovery codes, passkeys, sessions, login
 * history, new-login alerts, password policy and required 2FA per role.
 */
final class AuthModule extends BaseModule {

	/**
	 * Actions a person may still use while their role's required 2FA is not
	 * set up. Named one by one on purpose: a prefix would quietly exempt
	 * anything added later under the same name.
	 */
	private const ENROLL_ALLOWED = array(
		'auth.2fa_disable',
		'auth.2fa_enable',
		'auth.2fa_setup',
		'auth.change_password',
		'auth.logout',
		'auth.recovery_codes',
		'auth.security',
		'passkeys.delete',
		'passkeys.list',
		'passkeys.register',
		'passkeys.register_options',
		'preferences.get',
		'preferences.update',
		'session.get',
		'sessions.list',
		'sessions.revoke',
		'sessions.revoke_others',
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'auth';
	}

	/**
	 * {@inheritDoc}
	 */
	public function switchable(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function entities(): array {
		return array(
			( new Entity(
				'auth_tokens',
				array(
					'user_id'    => Field::int()->required(),
					'type'       => Field::enum( array( 'magic', 'reset', 'invite', '2fa_challenge', 'passkey_challenge', 'email_verify', 'email_otp' ) )->required(),
					'token_hash' => Field::text( 64 )->required(),
					'expires_at' => Field::datetime()->required(),
					'used_at'    => Field::datetime(),
					'attempts'   => Field::int()->default( 0 ),
					'meta'       => Field::json(),
				)
			) )->with(
				array(
					'public'      => false,
					'soft_delete' => false,
					'activity'    => false,
					'unique'      => array( 'token_hash' => array( 'token_hash' ) ),
					'indexes'     => array(
						'user_type' => array( 'user_id', 'type' ),
						'expires'   => array( 'expires_at' ),
					),
				)
			),
			( new Entity(
				'two_factor',
				array(
					'user_id'        => Field::int()->required(),
					'totp_secret'    => Field::text( 255 ),
					'totp_enabled'   => Field::bool(),
					'last_step'      => Field::bigint()->default( 0 ),
					'recovery_codes' => Field::json(),
					'passkeys'       => Field::json(),
					'enabled_at'     => Field::datetime(),
				)
			) )->with(
				array(
					'label'        => __( 'Two-step verification', 'cliezoo' ),
					'plural_label' => __( 'Two-step verification', 'cliezoo' ),
					'public'       => false,
					'soft_delete'  => false,
					'activity'     => false,
					'unique'       => array( 'user' => array( 'user_id' ) ),
				)
			),
		);
	}

	/**
	 * Registers services, hooks and routes.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( AuthService::class, static fn(): AuthService => new AuthService( $plugin ) );
		$plugin->container()->set( Sessions::class, static fn(): Sessions => new Sessions() );
		$plugin->container()->set( Passkeys::class, static fn(): Passkeys => new Passkeys( $plugin ) );
		$auth = static fn(): AuthService => $plugin->get( AuthService::class );

		add_action( 'cliezoo_register_settings', array( $this, 'settings' ) );
		add_action(
			'cliezoo_member_email_changed',
			static function ( int $user_id, string $old_email = '', string $new_email = '' ) use ( $plugin, $auth ): void {
				$plugin->get( Sessions::class )->destroy_all( $user_id );
				$auth()->email_changed( $user_id, $old_email, $new_email );
			},
			10,
			3
		);
		// API access is its own permission (cliezoo.md §6.39), checked on
		// every Cliezoo route before anything runs.
		add_filter( 'cliezoo_action_gate', array( ApiAccess::class, 'gate' ), 20, 3 );
		add_filter( 'cliezoo_route_allowed', array( ApiAccess::class, 'route_gate' ), 20, 1 );
		add_filter( 'cliezoo_role_matrices', array( ApiAccess::class, 'delegate' ), 5 );
		$plugin->container()->set( ApiKeys::class, static fn(): ApiKeys => new ApiKeys() );
		$plugin->container()->set( ApiSandbox::class, static fn(): ApiSandbox => new ApiSandbox() );
		$plugin->get( ApiSandbox::class )->hooks();
		add_action(
			'cliezoo_settings_updated',
			static function ( string $module ) use ( $plugin ): void {
				if ( 'security' === $module ) {
					\Cliezoo\Platform\Permissions\Roles::flush();
					$plugin->get( \Cliezoo\Platform\Members\Members::class )->flush();
				}
			}
		);
		// The authenticator secrets are the one secret this module keeps; the
		// re-key tool (`wp cliezoo rekey`) finds them here.
		add_filter(
			'cliezoo_secret_locations',
			static function ( array $locations ) use ( $plugin ): array {
				$locations[] = array(
					$plugin->entities()->get( 'two_factor' )->table(),
					'totp_secret',
					static fn( array $row ): string => 'totp:' . (int) $row['user_id'],
				);
				return $locations;
			}
		);
		add_action(
			'cliezoo_members_updated',
			static function ( array $row, array $old ) use ( $plugin ): void {
				if ( 'active' === $old['status'] && 'active' !== $row['status'] ) {
					// Deactivation logs the person out on every device (cliezoo.md §6.5).
					$plugin->get( Sessions::class )->destroy_all( (int) $row['user_id'] );
				}
			},
			10,
			2
		);
		add_action(
			'cliezoo_every_day',
			static function () use ( $auth ): void {
				$auth()->purge_expired_tokens();
			}
		);
		add_filter( 'cliezoo_action_gate', array( $this, 'require_two_factor' ), 10, 4 );
		add_filter( 'cliezoo_route_allowed', array( $this, 'route_two_factor' ), 10, 1 );
		add_filter( 'auth_cookie_expiration', array( $this, 'session_length' ), 20, 3 );
		add_filter( 'cliezoo_email_templates', array( $this, 'email_templates' ) );
		add_action( 'admin_init', array( $this, 'keep_clients_out_of_admin' ) );
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar_for_clients' ) );
		add_action( 'cliezoo_register_routes', static fn( RouteRegistry $routes ) => self::routes( $routes, $plugin ) );
		add_action( 'cliezoo_register_actions', static fn( ActionRegistry $registry ) => self::actions( $registry, $plugin ) );
	}

	/**
	 * Enforces "require 2FA" per role on the server (every channel): until the
	 * person sets up an authenticator app or a passkey, only sign-in, account
	 * security and preference actions run.
	 *
	 * @param ActionError|null     $refusal Refusal so far.
	 * @param Action               $action  Action.
	 * @param Actor                $actor   Actor.
	 * @param array<string, mixed> $input   What was asked for.
	 * @return ActionError|null
	 */
	public function require_two_factor( $refusal, Action $action, Actor $actor, array $input = array() ) {
		if ( $refusal || $actor->is_system() || $actor->is_guest() ) {
			return $refusal;
		}
		if ( in_array( $action->name(), self::ENROLL_ALLOWED, true ) ) {
			return null;
		}
		// Reading their own profile, on the page they are sent to: somebody
		// told to set two-step up in their account, arriving to "your
		// profile could not be loaded", has been sent to a broken page. Only
		// reading, and only their own. Changing it waits: an update can
		// carry a role, and a role without the requirement is a way out of
		// it for anybody holding just the password.
		if ( 'members.get' === $action->name() && $actor->member_id > 0 ) {
			$own = cliezoo()->service( 'members' )->repository()->find( $actor->member_id );
			if ( is_array( $own ) && (string) ( $input['uid'] ?? '' ) === (string) $own['uid'] ) {
				return null;
			}
		}
		if ( ! cliezoo()->get( AuthService::class )->must_enroll( $actor ) ) {
			return null;
		}
		return new ActionError( 'cliezoo_2fa_required', __( 'Your role requires two-step verification. Set it up in your account to continue.', 'cliezoo' ), 403 );
	}

	/**
	 * The same gate for hand-written routes (file downloads, uploads): they
	 * do not go through the Action registry.
	 *
	 * @param mixed $allowed Decision so far.
	 * @return mixed True, or a WP_Error.
	 */
	public function route_two_factor( $allowed ) {
		$actor = cliezoo()->actor();
		if ( true !== $allowed || $actor->is_system() || $actor->is_guest() ) {
			return $allowed;
		}
		if ( ! cliezoo()->get( AuthService::class )->must_enroll( $actor ) ) {
			return $allowed;
		}
		return ( new ActionError( 'cliezoo_2fa_required', __( 'Your role requires two-step verification. Set it up in your account to continue.', 'cliezoo' ), 403 ) )->to_wp_error();
	}

	/**
	 * Login length from Settings → Security ("Keep me signed in" lasts
	 * `session_days`; a normal login never lasts longer than that either).
	 *
	 * @param int  $length   Seconds WordPress would use.
	 * @param int  $user_id  User.
	 * @param bool $remember Keep me signed in.
	 * @return int
	 */
	public function session_length( $length, $user_id, $remember ): int {
		$member = cliezoo()->service( 'members' )->repository()->first( array( 'user_id' => (int) $user_id ) );
		if ( ! $member ) {
			return (int) $length;
		}
		$limit = max( 1, (int) cliezoo()->settings()->get( 'security', 'session_days', 14 ) ) * DAY_IN_SECONDS;
		return $remember ? $limit : min( (int) $length, $limit );
	}

	/**
	 * Security settings.
	 *
	 * @param Settings $settings Settings.
	 * @return void
	 */
	public function settings( Settings $settings ): void {
		$settings->register(
			'security',
			array(
				'password_min_length'  => array(
					'type'    => 'integer',
					'minimum' => 8,
					'maximum' => 128,
					'default' => 10,
				),
				'password_require_mix' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'require_2fa_roles'    => array(
					'type'    => 'array',
					'items'   => array( 'type' => 'string' ),
					'default' => array(),
				),
				'new_login_alerts'     => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'magic_links'          => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'session_days'         => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 365,
					'default' => 14,
				),
				// Roles that may use the API from outside the app (Application
				// Passwords). Owners and admins always may.
				'api_roles'            => array(
					'type'    => 'array',
					'items'   => array(
						'type'    => 'string',
						'pattern' => '^[a-z_]{2,40}$',
					),
					'default' => array(),
				),
			)
		);
	}

	/**
	 * Account emails.
	 *
	 * @param array<string, array<string, mixed>> $templates Templates.
	 * @return array<string, array<string, mixed>>
	 */
	public function email_templates( array $templates ): array {
		$group                       = __( 'Portal and account', 'cliezoo' );
		$templates['magic_link']     = array(
			'group'        => $group,
			'label'        => __( 'Magic login link', 'cliezoo' ),
			/* translators: %s: workspace name placeholder. */
			'subject'      => sprintf( __( 'Your login link for %s', 'cliezoo' ), '{{workspace.name}}' ),
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'greeting'     => __( 'Hi {{recipient_name}},', 'cliezoo' ),
			'body'         => '<p>' . __( 'Use the button below to log in. The link works once and expires in 15 minutes.', 'cliezoo' ) . '</p><p>' . __( 'If you did not ask for it, you can ignore this email.', 'cliezoo' ) . '</p>',
			'button_label' => __( 'Log in', 'cliezoo' ),
			'button_url'   => '{{url}}',
			'variables'    => array( 'recipient_name', 'url', 'workspace.name' ),
		);
		$templates['password_reset'] = array(
			'group'        => $group,
			'label'        => __( 'Password reset', 'cliezoo' ),
			'subject'      => __( 'Reset your password', 'cliezoo' ),
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'greeting'     => __( 'Hi {{recipient_name}},', 'cliezoo' ),
			'body'         => '<p>' . __( 'Someone asked to reset the password for your account. The link expires in one hour.', 'cliezoo' ) . '</p>',
			'button_label' => __( 'Choose a new password', 'cliezoo' ),
			'button_url'   => '{{url}}',
			'variables'    => array( 'recipient_name', 'url' ),
		);
		$templates['new_login']      = array(
			'group'     => $group,
			'label'     => __( 'New login alert', 'cliezoo' ),
			'subject'   => __( 'New login to your account', 'cliezoo' ),
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'greeting'  => __( 'Hi {{recipient_name}},', 'cliezoo' ),
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'body'      => '<p>' . __( 'Your account was just used to log in from {{device}} (IP {{ip}}) on {{time}}.', 'cliezoo' ) . '</p><p>' . __( 'If this was not you, change your password and log out of all sessions from your account page.', 'cliezoo' ) . '</p>',
			'variables' => array( 'recipient_name', 'device', 'ip', 'time' ),
		);
		$templates['email_verify']   = array(
			'group'        => $group,
			'label'        => __( 'Confirm a new email address', 'cliezoo' ),
			'subject'      => __( 'Confirm your new email address', 'cliezoo' ),
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'greeting'     => __( 'Hi {{recipient_name}},', 'cliezoo' ),
			'body'         => '<p>' . __( 'The email address of your account was changed to this one. Please confirm it is yours. The link works for 7 days.', 'cliezoo' ) . '</p><p>' . __( 'For your safety you were signed out everywhere; sign in again with this address.', 'cliezoo' ) . '</p>',
			'button_label' => __( 'Confirm my email address', 'cliezoo' ),
			'button_url'   => '{{url}}',
			'variables'    => array( 'recipient_name', 'url' ),
		);
		$templates['email_changed']  = array(
			'group'     => $group,
			'label'     => __( 'Email address changed (sent to the old address)', 'cliezoo' ),
			'subject'   => __( 'The email address of your account was changed', 'cliezoo' ),
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'greeting'  => __( 'Hi {{recipient_name}},', 'cliezoo' ),
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'body'      => '<p>' . __( 'The email address of your account was changed from this address to {{new_email}}, and every session was signed out.', 'cliezoo' ) . '</p><p>' . __( 'If you did not ask for this, contact the workspace owner straight away.', 'cliezoo' ) . '</p>',
			'variables' => array( 'recipient_name', 'new_email' ),
		);
		$templates['account_locked'] = array(
			'group'     => $group,
			'label'     => __( 'Account locked after failed sign-ins', 'cliezoo' ),
			'subject'   => __( 'Sign-in to your account was paused', 'cliezoo' ),
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'greeting'  => __( 'Hi {{recipient_name}},', 'cliezoo' ),
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'body'      => '<p>' . __( 'There were several wrong passwords for your account, the last from IP {{ip}} on {{time}}, so signing in is paused for {{minutes}} minutes.', 'cliezoo' ) . '</p><p>' . __( 'If this was you, wait and try again, or reset your password. If it was not, your password kept them out; consider turning on two-step verification.', 'cliezoo' ) . '</p>',
			'variables' => array( 'recipient_name', 'ip', 'time', 'minutes' ),
		);
		// No "2FA code" template. Two-step verification here is an authenticator
		// app, so there is no code to email — and an editable template for an
		// email nothing sends is a promise on a screen: somebody rewords it,
		// saves it, and waits for a message no code path produces. A code sent
		// by email is a second factor of its own and is A-0154; the wording
		// comes back with the thing that sends it.
		return $templates;
	}

	/**
	 * Client users never see wp-admin (they are sent to the app).
	 *
	 * @return void
	 */
	public function keep_clients_out_of_admin(): void {
		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}
		$actor = cliezoo()->actor();
		if ( $actor->is_client() && ! current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( (string) apply_filters( 'cliezoo_app_url', home_url( '/dash/' ) ) );
			exit;
		}
	}

	/**
	 * Hides the admin bar for client users.
	 *
	 * @param bool $show Show.
	 * @return bool
	 */
	public function hide_admin_bar_for_clients( bool $show ): bool {
		return is_user_logged_in() && cliezoo()->actor()->is_client() ? false : $show;
	}

	/**
	 * Public auth routes (token checks and rate limits inside).
	 *
	 * @param RouteRegistry $routes Routes.
	 * @param Plugin        $plugin Plugin.
	 * @return void
	 */
	private static function routes( RouteRegistry $routes, Plugin $plugin ): void {
		$auth   = static fn(): AuthService => $plugin->get( AuthService::class );
		$handle = static function ( callable $callback ): callable {
			return static function ( \WP_REST_Request $request ) use ( $callback ) {
				try {
					return new \WP_REST_Response( array( 'data' => $callback( (array) $request->get_json_params() + (array) $request->get_body_params() ) ), 200 );
				} catch ( ActionError $error ) {
					return $error->to_wp_error();
				}
			};
		};
		$public = array(
			'/auth/login'                  => static fn( array $in ) => $auth()->login( (string) ( $in['login'] ?? '' ), (string) ( $in['password'] ?? '' ), ! empty( $in['remember'] ) ),
			'/auth/2fa'                    => static fn( array $in ) => $auth()->complete_two_factor( (string) ( $in['challenge'] ?? '' ), (string) ( $in['code'] ?? '' ), (string) ( $in['recovery_code'] ?? '' ) ),
			'/auth/magic'                  => static fn( array $in ) => $auth()->send_magic_link( (string) ( $in['email'] ?? '' ), (string) ( $in['redirect'] ?? '' ) ),
			'/auth/magic/consume'          => static fn( array $in ) => $auth()->consume_magic_link( (string) ( $in['token'] ?? '' ) ),
			'/auth/password-reset'         => static fn( array $in ) => $auth()->send_reset( (string) ( $in['email'] ?? '' ) ),
			'/auth/password-reset/confirm' => static fn( array $in ) => $auth()->confirm_reset( (string) ( $in['token'] ?? '' ), (string) ( $in['password'] ?? '' ) ),
			'/auth/passkey/login-options'  => static fn( array $in ) => $plugin->get( Passkeys::class )->login_options( (string) ( $in['login'] ?? '' ) ),
			'/auth/passkey/login'          => static fn( array $in ) => $plugin->get( Passkeys::class )->login( $in ),
		);
		foreach ( $public as $path => $callback ) {
			$routes->add(
				$path,
				array(
					'methods'             => 'POST',
					'callback'            => $handle( $callback ),
					'permission_callback' => '__return_true', // Public endpoint.
				)
			);
		}
		// The link in the "confirm your new email address" email. It is a
		// GET because it is opened from an email; what it does is only to
		// confirm what the link itself proves (that this mailbox got it),
		// so a mail scanner that opens it early does no harm.
		$routes->add(
			'/auth/verify-email',
			array(
				'methods'             => 'GET',
				'callback'            => static function ( \WP_REST_Request $request ) use ( $auth ) {
					$verified = $auth()->verify_email( (string) $request->get_param( 'token' ) );
					$base     = (string) apply_filters( 'cliezoo_app_url', home_url( '/dash/' ) );
					$response = new \WP_REST_Response( null, 302 );
					$response->header( 'Location', add_query_arg( 'email_verified', $verified ? '1' : '0', $base ) );
					return $response;
				},
				'permission_callback' => '__return_true', // Public endpoint: the token is the proof.
			)
		);
	}

	/**
	 * Authenticated account actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	private static function actions( ActionRegistry $registry, Plugin $plugin ): void {
		$auth     = static fn(): AuthService => $plugin->get( AuthService::class );
		$sessions = static fn(): Sessions => $plugin->get( Sessions::class );
		$passkeys = static fn(): Passkeys => $plugin->get( Passkeys::class );
		$member   = static fn( Actor $a ): bool => $a->member_id > 0;
		$ops      = array(
			Op::make( 'auth.logout', __( 'Log out', 'cliezoo' ) )->run( static fn() => $auth()->logout() )->rest( 'POST', '/auth/logout' ),
			Op::make( 'auth.2fa_setup', __( 'Start two-factor setup', 'cliezoo' ) )->run( static fn( Actor $a ) => $auth()->setup_two_factor( $a ) )->rest( 'POST', '/auth/2fa/setup' ),
			Op::make( 'auth.2fa_enable', __( 'Turn on two-factor authentication', 'cliezoo' ) )
				->input( array( 'code' => Op::str( 10, true ) ) )
				->run( static fn( Actor $a, array $in ) => $auth()->enable_two_factor( $a, (string) $in['code'] ) )
				->rest( 'POST', '/auth/2fa/enable' ),
			Op::make( 'auth.2fa_disable', __( 'Turn off two-factor authentication', 'cliezoo' ) )
				->input( array( 'password' => Op::str( 200, true ) ) )
				->run( static fn( Actor $a, array $in ) => $auth()->disable_two_factor( $a, (string) $in['password'] ) )
				->rest( 'POST', '/auth/2fa/disable' )
				->with( array( 'destructive' => true ) ),
			Op::make( 'auth.recovery_codes', __( 'New recovery codes', 'cliezoo' ) )
				->input( array( 'password' => Op::str( 200, true ) ) )
				->run( static fn( Actor $a, array $in ) => $auth()->regenerate_recovery_codes( $a, (string) $in['password'] ) )
				->rest( 'POST', '/auth/2fa/recovery-codes' ),
			Op::make( 'auth.2fa_reset', __( 'Reset a member\'s two-factor authentication', 'cliezoo' ) )
				->input( array( 'member' => Op::uid() ) )
				->allow( static fn( Actor $a ): bool => $a->is_admin() )
				->run( static fn( Actor $a, array $in ) => $auth()->admin_reset_two_factor( $a, (string) $in['member'] ) )
				->rest( 'POST', '/auth/2fa/reset' )
				->with( array( 'destructive' => true ) ),
			Op::make( 'auth.change_password', __( 'Change password', 'cliezoo' ) )
				->input(
					array(
						'current'  => Op::str( 200, true ),
						'password' => Op::str( 200, true ),
					)
				)
				->run( static fn( Actor $a, array $in ) => $auth()->change_password( $a, (string) $in['current'], (string) $in['password'] ) )
				->rest( 'POST', '/auth/password' ),
			Op::make( 'auth.security', __( 'My security', 'cliezoo' ) )->run( static fn( Actor $a ) => $auth()->security_summary( $a ) )->rest( 'GET', '/auth/security' )->readonly(),
			Op::make( 'auth.login_history', __( 'Login history', 'cliezoo' ) )
				->input( array( 'member' => Op::uid( false ) ) + Op::paging( 20 ) )
				->run( static fn( Actor $a, array $in ) => $auth()->login_history( $a, $in ) )
				->rest( 'GET', '/auth/login-history' )
				->readonly(),
			Op::make( 'members.security', __( 'How somebody signs in', 'cliezoo' ) )
				->input( array( 'uid' => Op::uid() ) )
				->run( static fn( Actor $a, array $in ) => $auth()->member_security( $a, (string) $in['uid'] ) )
				->rest( 'GET', '/members/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/security' )
				->readonly(),
			Op::make( 'sessions.list', __( 'Active sessions', 'cliezoo' ) )->run( static fn( Actor $a ) => $sessions()->list( $a->user_id ) )->rest( 'GET', '/auth/sessions' )->readonly(),
			Op::make( 'sessions.revoke', __( 'Log out a session', 'cliezoo' ) )
				->input( array( 'id' => Op::str( 64, true ) ) )
				->run( static fn( Actor $a, array $in ) => $sessions()->revoke( $a->user_id, (string) $in['id'] ) )
				->rest( 'POST', '/auth/sessions/revoke' ),
			Op::make( 'sessions.revoke_others', __( 'Log out everywhere else', 'cliezoo' ) )->run( static fn( Actor $a ) => $sessions()->revoke_others( $a->user_id ) )->rest( 'POST', '/auth/sessions/revoke-others' ),
			Op::make( 'passkeys.list', __( 'My passkeys', 'cliezoo' ) )->run( static fn( Actor $a ) => $passkeys()->list( $a->user_id ) )->rest( 'GET', '/auth/passkeys' )->readonly(),
			Op::make( 'passkeys.register_options', __( 'Start adding a passkey', 'cliezoo' ) )->run( static fn( Actor $a ) => $passkeys()->register_options( $a->user_id ) )->rest( 'POST', '/auth/passkeys/options' ),
			Op::make( 'passkeys.register', __( 'Add a passkey', 'cliezoo' ) )
				->input(
					array(
						'name'              => Op::str( 60 ),
						'clientDataJSON'    => Op::str( 20000, true ),
						'attestationObject' => Op::str( 20000, true ),
						'challenge'         => Op::str( 200, true ),
					)
				)
				->run( static fn( Actor $a, array $in ) => $passkeys()->register( $a->user_id, $in ) )
				->rest( 'POST', '/auth/passkeys' ),
			Op::make( 'passkeys.delete', __( 'Remove a passkey', 'cliezoo' ) )
				->input( array( 'id' => Op::str( 400, true ) ) )
				->run( static fn( Actor $a, array $in ) => $passkeys()->delete( $a->user_id, (string) $in['id'] ) )
				->rest( 'POST', '/auth/passkeys/delete' )
				->with( array( 'destructive' => true ) ),
		);
		$keys     = static fn(): ApiKeys => $plugin->get( ApiKeys::class );
		$staff    = static fn( Actor $a ): bool => $a->member_id > 0 && $a->is_staff();
		$registry->add(
			Op::make( 'api_keys.list', __( 'My API keys', 'cliezoo' ) )
				->describe( __( 'The keys you use the Cliezoo API with, when each was last used and from where. Their secrets are never shown again.', 'cliezoo' ) )
				->allow( $staff )
				->run( static fn( Actor $a ): array => $keys()->list( $a ) )
				->rest( 'GET', '/auth/api-keys' )
				->readonly()
				->with( array( 'ability' => false ) )
		);
		$registry->add(
			Op::make( 'api_keys.create', __( 'Make an API key', 'cliezoo' ) )
				->describe( __( 'A key for a script, Zapier or another app. Its secret is shown once. A sandbox key gets real answers but nothing it changes is kept.', 'cliezoo' ) )
				->input(
					array(
						'name'    => Op::str( 80, true ),
						'sandbox' => array( 'type' => 'boolean' ),
					)
				)
				->allow( $staff )
				->run( static fn( Actor $a, array $in ): array => $keys()->create( $a, (string) $in['name'], ! empty( $in['sandbox'] ) ) )
				->rest( 'POST', '/auth/api-keys' )
				->with( array( 'ability' => false ) )
		);
		$registry->add(
			Op::make( 'api_keys.revoke', __( 'Revoke an API key', 'cliezoo' ) )
				->input( array( 'uuid' => Op::str( 40, true ) ) )
				->allow( $staff )
				->run( static fn( Actor $a, array $in ): array => $keys()->revoke( $a, (string) $in['uuid'] ) )
				->rest( 'POST', '/auth/api-keys/revoke' )
				->with(
					array(
						'ability'     => false,
						'destructive' => true,
					)
				)
		);
		foreach ( $ops as $op ) {
			$registry->add( $op->allow( $op->name() === 'auth.2fa_reset' ? static fn( Actor $a ): bool => $a->is_admin() : $member )->with( array( 'ability' => false ) ) );
		}
	}
}
