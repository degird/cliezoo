<?php
/**
 * Login, magic links, password reset and 2FA.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Auth;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Data\Serializer;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\RateLimiter;
use Cliezoo\Core\Request;
use Cliezoo\Infrastructure\Crypto\Vault;
use Cliezoo\Infrastructure\Mail\Mailer;
use Cliezoo\Platform\Members\Members;
use Cliezoo\Platform\Notifications\Notifier;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Authentication flows on top of WordPress users and auth cookies
 * (Blueprint §8.6, §17.2, §31.3). Errors are generic so accounts cannot be
 * enumerated; every attempt is rate-limited per IP and per account.
 */
final class AuthService {

	private const MAX_IP_FAILURES      = 20;
	private const MAX_ACCOUNT_FAILURES = 5;
	private const WINDOW               = 900;

	/**
	 * User meta holding a new email address that has not been confirmed yet.
	 */
	public const UNVERIFIED = '_cliezoo_email_unconfirmed';

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
	 * Vault.
	 *
	 * @return Vault
	 */
	private function vault(): Vault {
		return $this->plugin->get( Vault::class );
	}

	/**
	 * The generic failure (never tells which part was wrong).
	 *
	 * @return ActionError
	 */
	private function invalid(): ActionError {
		return new ActionError( 'cliezoo_login_failed', __( 'The email or password is not correct.', 'cliezoo' ), 401 );
	}

	/**
	 * Refuses when too many failures happened recently.
	 *
	 * @param string $login Login name/email.
	 * @return void
	 * @throws ActionError When locked out.
	 */
	private function guard( string $login ): void {
		$ip = Request::ip();
		if ( RateLimiter::blocked( 'login-ip:' . $ip, self::MAX_IP_FAILURES ) || RateLimiter::blocked( 'login-acct:' . strtolower( $login ), self::MAX_ACCOUNT_FAILURES ) ) {
			$wait = max( RateLimiter::retry_after( 'login-ip:' . $ip ), RateLimiter::retry_after( 'login-acct:' . strtolower( $login ) ) );
			/* translators: %d: minutes. */
			throw ActionError::rate_limited( sprintf( __( 'Too many attempts. Please try again in %d minutes.', 'cliezoo' ), max( 1, (int) ceil( $wait / 60 ) ) ), $wait );
		}
	}

	/**
	 * Records a failure (per IP and per account).
	 *
	 * @param string $login Login.
	 * @return void
	 */
	private function failed( string $login ): void {
		RateLimiter::hit( 'login-ip:' . Request::ip(), self::MAX_IP_FAILURES, self::WINDOW );
		$account = 'login-acct:' . strtolower( $login );
		RateLimiter::hit( $account, self::MAX_ACCOUNT_FAILURES, self::WINDOW );
		// guard() refuses from the MAX-th failure on, so that failure starts the lockout.
		if ( RateLimiter::count( $account ) === self::MAX_ACCOUNT_FAILURES ) {
			// Each new lockout within a day doubles the wait: 15 min, 30 min, 1 h … up to 24 h (§31.3).
			RateLimiter::hit( 'login-lockouts:' . strtolower( $login ), PHP_INT_MAX, DAY_IN_SECONDS );
			$lockouts = RateLimiter::count( 'login-lockouts:' . strtolower( $login ) );
			$seconds  = (int) min( DAY_IN_SECONDS, self::WINDOW * ( 2 ** min( 7, $lockouts - 1 ) ) );
			RateLimiter::lock( $account, self::MAX_ACCOUNT_FAILURES, $seconds );
			do_action(
				'cliezoo_audit',
				'login_locked',
				array(
					'login'   => mb_substr( $login, 0, 60 ),
					'minutes' => (int) ceil( $seconds / 60 ),
				),
				'failure'
			);
			$this->lockout_alert( $login, $seconds );
		}
	}

	/**
	 * Password login. Returns either a session or a 2FA challenge.
	 *
	 * @param string $login    Email or username.
	 * @param string $password Password.
	 * @param bool   $remember Keep me logged in.
	 * @return array<string, mixed>
	 * @throws ActionError On failure.
	 */
	public function login( string $login, string $password, bool $remember ): array {
		$login = trim( sanitize_text_field( $login ) );
		$this->guard( $login );
		$user = wp_authenticate( $login, $password );
		if ( is_wp_error( $user ) ) {
			$this->failed( $login );
			throw $this->invalid();
		}
		$member = $this->plugin->get( Members::class )->member_for( (int) $user->ID );
		if ( ! $member || 'active' !== $member['status'] ) {
			$this->failed( $login );
			throw $this->invalid();
		}
		RateLimiter::clear( 'login-acct:' . strtolower( $login ) );
		if ( $this->two_factor_enabled( (int) $user->ID ) ) {
			$challenge = $this->issue_token( (int) $user->ID, '2fa_challenge', 300, array( 'remember' => $remember ) );
			return array(
				'two_factor_required' => true,
				'challenge'           => $challenge,
				'passkeys'            => $this->plugin->get( Passkeys::class )->count( (int) $user->ID ) > 0,
			);
		}
		return $this->start_session( (int) $user->ID, $remember );
	}

	/**
	 * Completes login with a TOTP or recovery code.
	 *
	 * @param string $challenge Challenge token.
	 * @param string $code      TOTP code.
	 * @param string $recovery  Recovery code.
	 * @return array<string, mixed>
	 * @throws ActionError On failure.
	 */
	public function complete_two_factor( string $challenge, string $code, string $recovery ): array {
		$token = $this->find_token( $challenge, '2fa_challenge' );
		if ( ! $token ) {
			throw new ActionError( 'cliezoo_challenge_expired', __( 'Your login expired. Please start again.', 'cliezoo' ), 401 );
		}
		$user_id = (int) $token['user_id'];
		$this->guard( 'uid:' . $user_id );
		$ok = '' !== $recovery ? $this->use_recovery_code( $user_id, $recovery ) : $this->verify_totp( $user_id, $code );
		if ( ! $ok ) {
			$this->failed( 'uid:' . $user_id );
			$this->plugin->service( 'auth_tokens' )->repository()->update( (int) $token['id'], array( 'attempts' => (int) $token['attempts'] + 1 ) );
			throw new ActionError( 'cliezoo_bad_code', __( 'That code is not correct.', 'cliezoo' ), 401 );
		}
		$this->mark_used( $token );
		$meta = (array) json_decode( (string) $token['meta'], true );
		return $this->start_session( $user_id, ! empty( $meta['remember'] ) );
	}

	/**
	 * Sets auth cookies and records the login.
	 *
	 * @param int  $user_id  User.
	 * @param bool $remember Remember.
	 * @return array<string, mixed>
	 */
	public function start_session( int $user_id, bool $remember ): array {
		$user = get_userdata( $user_id );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, $remember, is_ssl() );
		if ( $user ) {
			// Core login hook, so security and SSO plugins see logins made through the app.
			do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Firing the WordPress core hook on purpose.
		}
		$this->after_login( $user_id );
		$this->plugin->get( Members::class )->flush();
		$actor = $this->plugin->actor();
		return array(
			'logged_in'         => true,
			'two_factor_enroll' => $this->must_enroll( $actor ),
			'redirect'          => (string) apply_filters( 'cliezoo_app_url', home_url( '/dash/' ) ),
		);
	}

	/**
	 * Last login time and new-device alert.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	private function after_login( int $user_id ): void {
		$member = $this->plugin->get( Members::class )->member_for( $user_id );
		if ( ! $member ) {
			return;
		}
		$this->plugin->service( 'members' )->repository()->update( (int) $member['id'], array( 'last_login_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		/**
		 * Somebody signed in. Contacts mirror the time onto their own row so
		 * a list can sort by it.
		 *
		 * @param array $member The member row.
		 */
		do_action( 'cliezoo_member_logged_in', $member );
		$device = Request::device( Request::user_agent() );
		// No devices yet reads as '', and (array) '' is one empty entry: the
		// first sign-in would then look like a new device on a known account.
		$seen        = get_user_meta( $user_id, '_cliezoo_known_devices', true );
		$seen        = is_array( $seen ) ? $seen : array();
		$fingerprint = md5( $device . '|' . Request::ip() );
		if ( ! in_array( $fingerprint, $seen, true ) ) {
			if ( $seen && $this->plugin->settings()->get( 'security', 'new_login_alerts', true ) && ! empty( $member['email'] ) ) {
				$this->plugin->get( Mailer::class )->queue(
					'new_login',
					(string) $member['email'],
					array(
						'recipient_name' => (string) $member['name'],
						'device'         => $device,
						'ip'             => Request::ip(),
						'time'           => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
						'is_client'      => 'client' === $member['kind'],
					),
					array(
						'member_id' => (int) $member['id'],
						'category'  => 'security',
					)
				);
			}
			$seen[] = $fingerprint;
			update_user_meta( $user_id, '_cliezoo_known_devices', array_slice( $seen, -20 ) );
		}
	}

	/**
	 * Whether the actor's role requires 2FA and it is not set up yet.
	 *
	 * @param Actor $actor Actor.
	 * @return bool
	 */
	public function must_enroll( Actor $actor ): bool {
		$roles = (array) $this->plugin->settings()->get( 'security', 'require_2fa_roles', array() );
		return in_array( $actor->role, $roles, true ) && ! $this->two_factor_enabled( $actor->user_id ) && 0 === $this->plugin->get( Passkeys::class )->count( $actor->user_id );
	}

	/**
	 * Logs out.
	 *
	 * @return array{logged_out: bool}
	 */
	public function logout(): array {
		wp_logout();
		return array( 'logged_out' => true );
	}

	/**
	 * Emails a magic login link. Always answers the same way (no account enumeration).
	 *
	 * @param string $email    Email.
	 * @param string $redirect App path to open after login.
	 * @return array{sent: bool}
	 */
	public function send_magic_link( string $email, string $redirect = '' ): array {
		$email = sanitize_email( $email );
		if ( RateLimiter::hit( 'magic-ip:' . Request::ip(), 10, self::WINDOW ) || RateLimiter::hit( 'magic-email:' . strtolower( $email ), 3, self::WINDOW ) ) {
			return array( 'sent' => true );
		}
		if ( ! $this->plugin->settings()->get( 'security', 'magic_links', true ) ) {
			return array( 'sent' => true );
		}
		$user   = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		$member = $user ? $this->plugin->get( Members::class )->member_for( (int) $user->ID ) : null;
		if ( $user && $member && 'active' === $member['status'] ) {
			$token = $this->issue_token( (int) $user->ID, 'magic', 15 * MINUTE_IN_SECONDS, array( 'redirect' => mb_substr( sanitize_text_field( $redirect ), 0, 200 ) ) );
			$this->plugin->get( Mailer::class )->send_template(
				'magic_link',
				$email,
				array(
					'recipient_name' => (string) $member['name'],
					'url'            => trailingslashit( (string) apply_filters( 'cliezoo_app_url', home_url( '/dash/' ), 'client' === $member['kind'] ? 'client' : '' ) ) . 'auth/magic/' . $token,
					'is_client'      => 'client' === $member['kind'],
				),
				array(
					'member_id' => (int) $member['id'],
					'category'  => 'security',
				)
			);
		}
		return array( 'sent' => true );
	}

	/**
	 * Consumes a magic link (only from the Continue page's POST, §31.3).
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>
	 * @throws ActionError When invalid.
	 */
	public function consume_magic_link( string $token ): array {
		if ( RateLimiter::hit( 'magic-consume:' . Request::ip(), 20, self::WINDOW ) ) {
			throw ActionError::rate_limited( __( 'Too many attempts. Please try again later.', 'cliezoo' ), self::WINDOW );
		}
		$row = $this->find_token( $token, 'magic' );
		if ( ! $row ) {
			throw new ActionError( 'cliezoo_link_expired', __( 'This login link has expired or was already used. Ask for a new one.', 'cliezoo' ), 401 );
		}
		$this->mark_used( $row );
		$meta           = (array) json_decode( (string) $row['meta'], true );
		$result         = $this->two_factor_enabled( (int) $row['user_id'] )
			? array(
				'two_factor_required' => true,
				'challenge'           => $this->issue_token( (int) $row['user_id'], '2fa_challenge', 300, array( 'remember' => false ) ),
			)
			: $this->start_session( (int) $row['user_id'], false );
		$result['next'] = (string) ( $meta['redirect'] ?? '' );
		return $result;
	}

	/**
	 * Emails a password reset link (generic answer).
	 *
	 * @param string $email Email.
	 * @return array{sent: bool}
	 */
	public function send_reset( string $email ): array {
		$email = sanitize_email( $email );
		if ( RateLimiter::hit( 'reset-ip:' . Request::ip(), 10, self::WINDOW ) || RateLimiter::hit( 'reset-email:' . strtolower( $email ), 3, self::WINDOW ) ) {
			return array( 'sent' => true );
		}
		$user   = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		$member = $user ? $this->plugin->get( Members::class )->member_for( (int) $user->ID ) : null;
		if ( $user && $member && 'active' === $member['status'] ) {
			$token = $this->issue_token( (int) $user->ID, 'reset', HOUR_IN_SECONDS );
			$this->plugin->get( Mailer::class )->send_template(
				'password_reset',
				$email,
				array(
					'recipient_name' => (string) $member['name'],
					'url'            => trailingslashit( (string) apply_filters( 'cliezoo_app_url', home_url( '/dash/' ), 'client' === $member['kind'] ? 'client' : '' ) ) . 'auth/reset/' . $token,
					'is_client'      => 'client' === $member['kind'],
				),
				array(
					'member_id' => (int) $member['id'],
					'category'  => 'security',
				)
			);
		}
		return array( 'sent' => true );
	}

	/**
	 * Sets a new password from a reset link.
	 *
	 * @param string $token    Token.
	 * @param string $password New password.
	 * @return array<string, mixed>
	 * @throws ActionError When invalid.
	 */
	public function confirm_reset( string $token, string $password ): array {
		$row = $this->find_token( $token, 'reset' );
		if ( ! $row ) {
			throw new ActionError( 'cliezoo_link_expired', __( 'This reset link has expired or was already used.', 'cliezoo' ), 401 );
		}
		$this->check_password_policy( $password );
		$this->mark_used( $row );
		$user = get_userdata( (int) $row['user_id'] );
		if ( ! $user ) {
			throw ActionError::not_found();
		}
		reset_password( $user, $password );
		$this->plugin->get( Sessions::class )->destroy_all( (int) $user->ID );
		do_action( 'cliezoo_audit', 'password_reset', array(), 'success', (int) $user->ID );
		return array( 'reset' => true );
	}

	/**
	 * Changes the password (current password required).
	 *
	 * @param Actor  $actor    Actor.
	 * @param string $current  Current password.
	 * @param string $password New password.
	 * @return array{changed: bool}
	 * @throws ActionError When the current password is wrong or the new one is weak.
	 */
	public function change_password( Actor $actor, string $current, string $password ): array {
		$user = get_userdata( $actor->user_id );
		if ( ! $user || ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			throw ActionError::validation( __( 'Your current password is not correct.', 'cliezoo' ), array( 'current' => __( 'Not correct.', 'cliezoo' ) ) );
		}
		$this->check_password_policy( $password );
		wp_set_password( $password, $user->ID );
		// wp_set_password() ends sessions; keep this one logged in.
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		do_action( 'cliezoo_audit', 'password_changed' );
		return array( 'changed' => true );
	}

	/**
	 * Enforces the password policy.
	 *
	 * @param string $password Password.
	 * @return void
	 * @throws ActionError When too weak.
	 */
	public function check_password_policy( string $password ): void {
		$min = (int) $this->plugin->settings()->get( 'security', 'password_min_length', 10 );
		if ( mb_strlen( $password ) < $min ) {
			/* translators: %d: minimum length. */
			throw ActionError::validation( sprintf( __( 'Use at least %d characters.', 'cliezoo' ), $min ), array( 'password' => __( 'Too short.', 'cliezoo' ) ) );
		}
		if ( $this->plugin->settings()->get( 'security', 'password_require_mix', false ) && ! ( preg_match( '/[a-z]/', $password ) && preg_match( '/[A-Z]/', $password ) && preg_match( '/\d/', $password ) ) ) {
			throw ActionError::validation( __( 'Use upper- and lower-case letters and a number.', 'cliezoo' ), array( 'password' => __( 'Too simple.', 'cliezoo' ) ) );
		}
	}

	/**
	 * Two-factor row of a user.
	 *
	 * @param int $user_id User.
	 * @return array<string, mixed>|null
	 */
	private function two_factor_row( int $user_id ): ?array {
		return $this->plugin->service( 'two_factor' )->repository()->first( array( 'user_id' => $user_id ) );
	}

	/**
	 * Whether TOTP is on for a user.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public function two_factor_enabled( int $user_id ): bool {
		$row = $this->two_factor_row( $user_id );
		return $row && (int) $row['totp_enabled'];
	}

	/**
	 * Verifies a TOTP code with replay protection.
	 *
	 * @param int    $user_id User.
	 * @param string $code    Code.
	 * @return bool
	 */
	private function verify_totp( int $user_id, string $code ): bool {
		$row = $this->two_factor_row( $user_id );
		if ( ! $row || ! (int) $row['totp_enabled'] ) {
			return false;
		}
		$secret = $this->vault()->decrypt( (string) $row['totp_secret'], 'totp:' . $user_id );
		if ( null === $secret ) {
			return false;
		}
		$step = Totp::verify( $secret, $code, (int) $row['last_step'] );
		if ( null === $step ) {
			return false;
		}
		$this->plugin->service( 'two_factor' )->repository()->update( (int) $row['id'], array( 'last_step' => $step ) );
		return true;
	}

	/**
	 * Uses a one-time recovery code.
	 *
	 * @param int    $user_id User.
	 * @param string $code    Code.
	 * @return bool
	 */
	private function use_recovery_code( int $user_id, string $code ): bool {
		$row = $this->two_factor_row( $user_id );
		if ( ! $row ) {
			return false;
		}
		$codes = (array) json_decode( (string) $row['recovery_codes'], true );
		$hash  = $this->vault()->hash_token( strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $code ) ) );
		foreach ( $codes as $i => $stored ) {
			if ( hash_equals( (string) $stored, $hash ) ) {
				unset( $codes[ $i ] );
				$this->plugin->service( 'two_factor' )->repository()->update( (int) $row['id'], array( 'recovery_codes' => wp_json_encode( array_values( $codes ) ) ) );
				do_action( 'cliezoo_audit', 'recovery_code_used', array(), 'success', $user_id );
				return true;
			}
		}
		return false;
	}

	/**
	 * Starts TOTP enrolment (secret stored encrypted, not yet active).
	 *
	 * @param Actor $actor Actor.
	 * @return array{secret: string, uri: string}
	 * @throws ActionError When 2FA is already on.
	 */
	public function setup_two_factor( Actor $actor ): array {
		$secret = Totp::secret();
		$repo   = $this->plugin->service( 'two_factor' )->repository();
		$row    = $this->two_factor_row( $actor->user_id );
		$data   = array( 'totp_secret' => $this->vault()->encrypt( $secret, 'totp:' . $actor->user_id ) );
		if ( $row && (int) $row['totp_enabled'] ) {
			throw ActionError::conflict( __( 'Two-factor authentication is already on.', 'cliezoo' ) );
		}
		if ( $row ) {
			$repo->update( (int) $row['id'], $data );
		} else {
			$repo->insert( $data + array( 'user_id' => $actor->user_id ) );
		}
		$user = get_userdata( $actor->user_id );
		return array(
			'secret' => $secret,
			'uri'    => Totp::uri( $secret, $user ? $user->user_email : 'user', (string) $this->plugin->settings()->get( 'workspace', 'name', get_bloginfo( 'name' ) ) ),
		);
	}

	/**
	 * Confirms enrolment with a first code and returns recovery codes (shown once).
	 *
	 * @param Actor  $actor Actor.
	 * @param string $code  Code.
	 * @return array{enabled: bool, recovery_codes: string[]}
	 * @throws ActionError When the code is wrong.
	 */
	public function enable_two_factor( Actor $actor, string $code ): array {
		$row = $this->two_factor_row( $actor->user_id );
		if ( ! $row || empty( $row['totp_secret'] ) ) {
			throw ActionError::conflict( __( 'Start the setup first.', 'cliezoo' ) );
		}
		$secret = $this->vault()->decrypt( (string) $row['totp_secret'], 'totp:' . $actor->user_id );
		$step   = null === $secret ? null : Totp::verify( $secret, $code );
		if ( null === $step ) {
			throw ActionError::validation( __( 'That code is not correct. Check the time on your phone and try again.', 'cliezoo' ), array( 'code' => __( 'Not correct.', 'cliezoo' ) ) );
		}
		$codes = $this->new_recovery_codes();
		$this->plugin->service( 'two_factor' )->repository()->update(
			(int) $row['id'],
			array(
				'totp_enabled'   => 1,
				'last_step'      => $step,
				'enabled_at'     => gmdate( 'Y-m-d H:i:s' ),
				'recovery_codes' => wp_json_encode( $codes['hashes'] ),
			)
		);
		do_action( 'cliezoo_audit', '2fa_enabled' );
		return array(
			'enabled'        => true,
			'recovery_codes' => $codes['codes'],
		);
	}

	/**
	 * Turns TOTP off (password confirmation).
	 *
	 * @param Actor  $actor    Actor.
	 * @param string $password Password.
	 * @return array{enabled: bool}
	 * @throws ActionError When the password is wrong or 2FA is required.
	 */
	public function disable_two_factor( Actor $actor, string $password ): array {
		$user = get_userdata( $actor->user_id );
		if ( ! $user || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			throw ActionError::validation( __( 'Your password is not correct.', 'cliezoo' ) );
		}
		if ( in_array( $actor->role, (array) $this->plugin->settings()->get( 'security', 'require_2fa_roles', array() ), true ) && 0 === $this->plugin->get( Passkeys::class )->count( $actor->user_id ) ) {
			throw ActionError::conflict( __( 'Your role requires two-factor authentication.', 'cliezoo' ) );
		}
		$row = $this->two_factor_row( $actor->user_id );
		if ( $row ) {
			$this->plugin->service( 'two_factor' )->repository()->update(
				(int) $row['id'],
				array(
					'totp_enabled'   => 0,
					'totp_secret'    => null,
					'recovery_codes' => null,
				)
			);
		}
		do_action( 'cliezoo_audit', '2fa_disabled' );
		return array( 'enabled' => false );
	}

	/**
	 * Replaces recovery codes.
	 *
	 * @param Actor  $actor    Actor.
	 * @param string $password Password.
	 * @return array{recovery_codes: string[]}
	 * @throws ActionError When the password is wrong.
	 */
	public function regenerate_recovery_codes( Actor $actor, string $password ): array {
		$user = get_userdata( $actor->user_id );
		$row  = $this->two_factor_row( $actor->user_id );
		if ( ! $user || ! $row || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			throw ActionError::validation( __( 'Your password is not correct.', 'cliezoo' ) );
		}
		$codes = $this->new_recovery_codes();
		$this->plugin->service( 'two_factor' )->repository()->update( (int) $row['id'], array( 'recovery_codes' => wp_json_encode( $codes['hashes'] ) ) );
		return array( 'recovery_codes' => $codes['codes'] );
	}

	/**
	 * Admin resets a member's 2FA (audited, §31.3).
	 *
	 * @param Actor  $actor      Admin.
	 * @param string $member_uid Member uid.
	 * @return array{reset: bool}
	 * @throws ActionError When the member is unknown.
	 */
	public function admin_reset_two_factor( Actor $actor, string $member_uid ): array {
		$member = $this->plugin->service( 'members' )->repository()->find_by_uid( $member_uid );
		if ( ! $member ) {
			throw ActionError::not_found();
		}
		$this->plugin->service( 'two_factor' )->repository()->delete_where( array( 'user_id' => (int) $member['user_id'] ) );
		$this->plugin->get( Sessions::class )->destroy_all( (int) $member['user_id'] );
		do_action(
			'cliezoo_audit',
			'2fa_reset_by_admin',
			array(
				'object_type' => 'members',
				'object_id'   => (int) $member['id'],
			)
		);
		unset( $actor );
		return array( 'reset' => true );
	}

	/**
	 * Ten recovery codes (plain, shown once) and their hashes.
	 *
	 * @return array{codes: string[], hashes: string[]}
	 */
	private function new_recovery_codes(): array {
		$codes  = array();
		$hashes = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$raw      = strtoupper( substr( bin2hex( random_bytes( 5 ) ), 0, 10 ) );
			$codes[]  = substr( $raw, 0, 5 ) . '-' . substr( $raw, 5 );
			$hashes[] = $this->vault()->hash_token( $raw );
		}
		return array(
			'codes'  => $codes,
			'hashes' => $hashes,
		);
	}

	/**
	 * Security overview for the account page.
	 *
	 * @param Actor $actor Actor.
	 * @return array<string, mixed>
	 */
	public function security_summary( Actor $actor ): array {
		$row = $this->two_factor_row( $actor->user_id );
		return array(
			'two_factor'          => $row && (int) $row['totp_enabled'],
			'recovery_codes_left' => $row ? count( (array) json_decode( (string) $row['recovery_codes'], true ) ) : 0,
			'passkeys'            => $this->plugin->get( Passkeys::class )->count( $actor->user_id ),
			'must_enroll'         => $this->must_enroll( $actor ),
			'password_min_length' => (int) $this->plugin->settings()->get( 'security', 'password_min_length', 10 ),
			'email_unconfirmed'   => '' !== (string) get_user_meta( $actor->user_id, self::UNVERIFIED, true ),
		);
	}

	/**
	 * Somebody's email address changed (cliezoo.md §31.3: "changing the
	 * email re-verifies it and ends all that user's sessions").
	 *
	 * Sessions are ended by the Sessions service. Here: every sign-in link
	 * still on its way to the old address stops working, the new address is
	 * marked unconfirmed and sent a confirmation link, and the old address
	 * is told — if the change was not theirs, that is how they find out.
	 *
	 * @param int    $user_id   User.
	 * @param string $old_email Old address.
	 * @param string $new_email New address.
	 * @return void
	 */
	public function email_changed( int $user_id, string $old_email, string $new_email ): void {
		global $wpdb;
		$tokens = esc_sql( $this->plugin->entities()->get( 'auth_tokens' )->table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from code.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tokens} WHERE user_id = %d AND type IN ('magic','reset','email_otp','2fa_challenge','email_verify') AND used_at IS NULL", $user_id ) );
		$new_email = sanitize_email( $new_email );
		if ( '' === $new_email ) {
			return;
		}
		update_user_meta( $user_id, self::UNVERIFIED, $new_email );
		$member = $this->plugin->get( Members::class )->member_for( $user_id );
		$name   = $member ? (string) $member['name'] : '';
		$client = $member && 'client' === $member['kind'];
		$token  = $this->issue_token( $user_id, 'email_verify', 7 * DAY_IN_SECONDS, array( 'email' => $new_email ) );
		$mailer = $this->plugin->get( Mailer::class );
		$meta   = array(
			'member_id' => $member ? (int) $member['id'] : null,
			'category'  => 'security',
		);
		$mailer->queue(
			'email_verify',
			$new_email,
			array(
				'recipient_name' => $name,
				'url'            => add_query_arg( 'token', rawurlencode( $token ), rest_url( 'cliezoo/v1/auth/verify-email' ) ),
				'is_client'      => $client,
			),
			$meta
		);
		if ( '' !== sanitize_email( $old_email ) && strtolower( $old_email ) !== strtolower( $new_email ) ) {
			$mailer->queue(
				'email_changed',
				sanitize_email( $old_email ),
				array(
					'recipient_name' => $name,
					'new_email'      => $new_email,
					'is_client'      => $client,
				),
				$meta
			);
		}
		do_action(
			'cliezoo_audit',
			'email_changed',
			array(
				'object_type' => 'members',
				'object_id'   => $member ? (int) $member['id'] : 0,
			),
			'success'
		);
	}

	/**
	 * Confirms a new email address from the link sent to it.
	 *
	 * @param string $token Token from the link.
	 * @return bool Whether the address is now confirmed.
	 */
	public function verify_email( string $token ): bool {
		if ( RateLimiter::hit( 'verify-email:' . Request::ip(), 20, self::WINDOW ) ) {
			return false;
		}
		$row = $this->find_token( $token, 'email_verify' );
		if ( ! $row ) {
			return false;
		}
		$user  = get_userdata( (int) $row['user_id'] );
		$meta  = (array) json_decode( (string) $row['meta'], true );
		$email = (string) ( $meta['email'] ?? '' );
		// A link for an address the account no longer has proves nothing.
		if ( ! $user || '' === $email || strtolower( $user->user_email ) !== strtolower( $email ) ) {
			return false;
		}
		try {
			$this->mark_used( $row );
		} catch ( ActionError $error ) {
			unset( $error );
			return false;
		}
		delete_user_meta( (int) $user->ID, self::UNVERIFIED );
		do_action( 'cliezoo_audit', 'email_verified', array(), 'success', (int) $user->ID );
		return true;
	}

	/**
	 * Tells the account holder, and the workspace's owners and admins, that
	 * sign-in to an account was paused after repeated wrong passwords
	 * (Blueprint §31.3, "lockout alerts").
	 *
	 * @param string $login   What was typed (email or username).
	 * @param int    $seconds How long the pause is.
	 * @return void
	 */
	private function lockout_alert( string $login, int $seconds ): void {
		$user = str_starts_with( $login, 'uid:' ) ? get_userdata( (int) substr( $login, 4 ) ) : get_user_by( 'email', $login );
		$user = $user ? $user : get_user_by( 'login', $login );
		// Guessing at an account that does not exist is somebody else's
		// problem; telling every admin about each guess would be a way to
		// flood their inbox.
		if ( ! $user ) {
			return;
		}
		$member = $this->plugin->service( 'members' )->repository()->first( array( 'user_id' => (int) $user->ID ) );
		if ( ! $member ) {
			return;
		}
		$minutes = max( 1, (int) ceil( $seconds / 60 ) );
		if ( ! empty( $member['email'] ) ) {
			$this->plugin->get( Mailer::class )->queue(
				'account_locked',
				(string) $member['email'],
				array(
					'recipient_name' => (string) $member['name'],
					'ip'             => Request::ip(),
					'time'           => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
					'minutes'        => $minutes,
					'is_client'      => 'client' === $member['kind'],
				),
				array(
					'member_id' => (int) $member['id'],
					'category'  => 'security',
				)
			);
		}
		$notifier = $this->plugin->get( Notifier::class );
		$notifier->notify(
			'security.account_locked',
			array_diff( $notifier->admins(), array( (int) $member['id'] ) ),
			array(
				/* translators: 1: a person, 2: number of minutes. */
				'title' => sprintf( __( 'Sign-in for %1$s was paused for %2$d minutes after repeated wrong passwords', 'cliezoo' ), (string) $member['name'], $minutes ),
				/* translators: %s: an IP address. */
				'body'  => sprintf( __( 'The last attempt came from IP %s.', 'cliezoo' ), Request::ip() ),
				'path'  => 'settings/members',
			)
		);
	}

	/**
	 * How somebody else signs in: two-step verification, passkeys, how many
	 * places they are signed in, and when they last did. People-management
	 * rights only, like their sign-in history.
	 *
	 * @param Actor  $actor Who is asking.
	 * @param string $uid   The member.
	 * @return array<string, mixed>
	 * @throws ActionError When not allowed, or there is nobody.
	 */
	public function member_security( Actor $actor, string $uid ): array {
		if ( ! $actor->is_admin() && ! $actor->allows( 'settings_members' ) ) {
			throw ActionError::forbidden();
		}
		$member = $this->plugin->service( 'members' )->repository()->find_by_uid( $uid );
		if ( ! $member || empty( $member['user_id'] ) ) {
			throw ActionError::not_found();
		}
		$user_id = (int) $member['user_id'];
		$row     = $this->two_factor_row( $user_id );
		return array(
			'two_factor'    => $row && (int) $row['totp_enabled'],
			'passkeys'      => $this->plugin->get( Passkeys::class )->count( $user_id ),
			'sessions'      => count( $this->plugin->get( Sessions::class )->list( $user_id ) ),
			'last_login_at' => Serializer::iso( $member['last_login_at'] ?? null ),
		);
	}

	/**
	 * Login history (own, or a member's for admins).
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    member, paging.
	 * @return array<string, mixed>
	 * @throws ActionError When not allowed.
	 */
	public function login_history( Actor $actor, array $in ): array {
		$user_id = $actor->user_id;
		if ( ! empty( $in['member'] ) ) {
			// Someone else's sign-in IPs and devices: people-management rights
			// only, never a client-editing toggle.
			if ( ! $actor->is_admin() && ! $actor->allows( 'settings_members' ) ) {
				throw ActionError::forbidden();
			}
			$member = $this->plugin->service( 'members' )->repository()->find_by_uid( (string) $in['member'] );
			if ( ! $member ) {
				throw ActionError::not_found();
			}
			$user_id = (int) $member['user_id'];
		}
		global $wpdb;
		$table = esc_sql( $this->plugin->entities()->get( 'audit_log' )->table() );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT event, status, ip, user_agent, created_at FROM {$table} WHERE user_id = %d AND event IN ('login','login_failed','logout') ORDER BY id DESC LIMIT %d", $user_id, (int) ( $in['limit'] ?? 20 ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from code.
		return array_map(
			static fn( array $row ): array => array(
				'event'      => (string) $row['event'],
				'status'     => (string) $row['status'],
				'ip'         => (string) $row['ip'],
				'device'     => Request::device( (string) $row['user_agent'] ),
				'created_at' => Serializer::iso( $row['created_at'] ),
			),
			$rows
		);
	}

	/**
	 * Issues a single-use token (stored as an HMAC hash).
	 *
	 * @param int                  $user_id User.
	 * @param string               $type    Type.
	 * @param int                  $ttl     Seconds.
	 * @param array<string, mixed> $meta    Meta.
	 * @return string Plain token (sent to the user, never stored).
	 */
	public function issue_token( int $user_id, string $type, int $ttl, array $meta = array() ): string {
		$token = $this->vault()->token( 32 );
		$this->plugin->service( 'auth_tokens' )->repository()->insert(
			array(
				'user_id'    => $user_id,
				'type'       => $type,
				'token_hash' => $token['hash'],
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
				'meta'       => $meta ? wp_json_encode( $meta ) : null,
			)
		);
		return $token['token'];
	}

	/**
	 * A valid, unused, unexpired token row.
	 *
	 * @param string $token Plain token.
	 * @param string $type  Type.
	 * @return array<string, mixed>|null
	 */
	public function find_token( string $token, string $type ): ?array {
		if ( '' === $token || strlen( $token ) > 200 ) {
			return null;
		}
		$row = $this->plugin->service( 'auth_tokens' )->repository()->first(
			array(
				'token_hash' => $this->vault()->hash_token( $token ),
				'type'       => $type,
			)
		);
		if ( ! $row || null !== $row['used_at'] || strtotime( $row['expires_at'] . ' UTC' ) < time() || (int) $row['attempts'] >= 5 ) {
			return null;
		}
		return $row;
	}

	/**
	 * Marks a token used.
	 *
	 * @param array<string, mixed> $row Token row.
	 * @return void
	 * @throws ActionError When already used concurrently.
	 */
	public function mark_used( array $row ): void {
		$updated = $this->plugin->service( 'auth_tokens' )->repository()->update_where(
			array( 'used_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array(
				'id'      => (int) $row['id'],
				'used_at' => null,
			)
		);
		if ( 1 !== $updated ) {
			throw new ActionError( 'cliezoo_link_expired', __( 'This link was already used.', 'cliezoo' ), 401 );
		}
	}

	/**
	 * Deletes expired tokens (daily).
	 *
	 * @return void
	 */
	public function purge_expired_tokens(): void {
		global $wpdb;
		$table = esc_sql( $this->plugin->entities()->get( 'auth_tokens' )->table() );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from code.
	}
}
