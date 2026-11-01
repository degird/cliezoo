<?php
/**
 * Team invitations and ownership transfer.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Members;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\RateLimiter;
use Cliezoo\Core\Request;
use Cliezoo\Infrastructure\Mail\Mailer;
use Cliezoo\Platform\Auth\AuthService;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Invites people by email (a WordPress user is created when needed, with a
 * Cliezoo role that has no wp-admin powers), lets them accept with their
 * name and a password, and transfers workspace ownership.
 *
 * Invite links are single-use tokens valid for 7 days. Accepting never
 * reveals whether other accounts exist; info and accept are rate-limited.
 */
final class Invitations {

	public const TTL = 7 * DAY_IN_SECONDS;

	private const NEW_USER_META = '_cliezoo_invited_new';

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
	 * Members service.
	 *
	 * @return Members
	 */
	private function members(): Members {
		return $this->plugin->get( Members::class );
	}

	/**
	 * Whether the actor may invite people of a kind.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $kind  staff|client.
	 * @return bool
	 */
	public static function can_invite( Actor $actor, string $kind ): bool {
		if ( $actor->is_admin() ) {
			return true;
		}
		return 'client' === $kind ? $actor->allows( 'clients_invite' ) : $actor->allows( 'settings_members' );
	}

	/**
	 * Invites a person (or re-sends a pending invitation).
	 *
	 * @param Actor                $actor   Actor.
	 * @param array<string, mixed> $in      email, name, role, kind, message, subject, and notify (default true; false makes the account without sending anything).
	 * @param bool                 $checked The caller has already decided this person may invite (a Client Admin bringing in a colleague).
	 * @return array<string, mixed> The member (uid, email, name, role, status).
	 * @throws ActionError On invalid input or when the person is already a member.
	 */
	public function invite( Actor $actor, array $in, bool $checked = false ): array {
		$kind  = 'client' === ( $in['kind'] ?? 'staff' ) ? 'client' : 'staff';
		$email = sanitize_email( (string) ( $in['email'] ?? '' ) );
		$name  = trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
		if ( ! $checked && ! self::can_invite( $actor, $kind ) ) {
			throw ActionError::forbidden();
		}
		if ( ! is_email( $email ) ) {
			throw ActionError::validation( __( 'Please enter a valid email address.', 'cliezoo' ), array( 'email' => __( 'Please enter a valid email address.', 'cliezoo' ) ) );
		}
		$user    = get_user_by( 'email', $email );
		$member  = $user ? $this->plugin->service( 'members' )->repository()->first( array( 'user_id' => (int) $user->ID ) ) : null;
		$default = $member ? (string) $member['role'] : ( 'client' === $kind ? 'client_member' : 'member' );
		$role    = '' !== (string) ( $in['role'] ?? '' ) ? (string) $in['role'] : $default;
		$roles   = 'client' === $kind ? Roles::CLIENT_ROLES : array_diff( Members::staff_roles(), array( 'owner' ) );
		if ( ! in_array( $role, $roles, true ) ) {
			throw ActionError::validation( __( 'Unknown role.', 'cliezoo' ), array( 'role' => __( 'Unknown role.', 'cliezoo' ) ) );
		}
		if ( 'admin' === $role && ! $actor->is_admin() ) {
			throw ActionError::forbidden();
		}

		// An invitation someone else asked for never touches an account that
		// exists already — not a pending colleague's, and never the team's:
		// those are the agency's to invite, change or send again.
		if ( $checked && $user ) {
			throw ActionError::conflict( __( 'This person cannot be invited from here. Your team will take care of it.', 'cliezoo' ), 'cliezoo_invite_elsewhere' );
		}
		// A pending team invitation is not a client's to take over, nor a
		// client's the team's: an account is one kind of thing.
		if ( $member && (string) $member['kind'] !== $kind ) {
			throw ActionError::conflict( __( 'This email address belongs to an account that cannot be invited this way.', 'cliezoo' ), 'cliezoo_other_kind' );
		}
		if ( $member && 'invited' !== $member['status'] ) {
			throw ActionError::conflict( __( 'This person is already a member of the workspace.', 'cliezoo' ), 'cliezoo_already_member' );
		}
		if ( $user && ! $member && user_can( $user, 'manage_options' ) && 'client' === $kind ) {
			throw ActionError::validation( __( 'Site administrators cannot be invited as clients.', 'cliezoo' ) );
		}
		if ( ! $user ) {
			$user_id = wp_insert_user(
				array(
					'user_login'   => $this->unique_login( $email ),
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 32, true, true ),
					'display_name' => '' !== $name ? $name : strstr( $email, '@', true ),
					'first_name'   => '' !== $name ? strtok( $name, ' ' ) : '',
					'role'         => 'client' === $kind ? 'cliezoo_client' : 'cliezoo_member',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				throw ActionError::validation( $user_id->get_error_message() );
			}
			update_user_meta( (int) $user_id, self::NEW_USER_META, 1 );
			$user = get_userdata( (int) $user_id );
		}
		if ( ! $member ) {
			$member = $this->members()->create_for_user( (int) $user->ID, $role, $kind, 'invited' );
		} elseif ( $member['role'] !== $role ) {
			$this->plugin->service( 'members' )->repository()->update( (int) $member['id'], array( 'role' => $role ) );
			$member['role'] = $role;
		}
		// "Send notification" off: the account is created and the person is
		// told another way. Resend sends it whenever they are ready.
		if ( ! array_key_exists( 'notify', $in ) || ! empty( $in['notify'] ) ) {
			$this->send( $member, $actor, mb_substr( sanitize_textarea_field( (string) ( $in['message'] ?? '' ) ), 0, 1000 ), mb_substr( sanitize_text_field( (string) ( $in['subject'] ?? '' ) ), 0, 200 ) );
		} else {
			$this->plugin->service( 'members' )->repository()->update( (int) $member['id'], array( 'invited_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		}
		do_action(
			'cliezoo_audit',
			'member_invited',
			array(
				'object_type' => 'members',
				'object_id'   => (int) $member['id'],
				'role'        => $role,
				'kind'        => $kind,
			)
		);
		return $this->summary( $this->plugin->service( 'members' )->repository()->find( (int) $member['id'] ) ?? $member );
	}

	/**
	 * Re-sends a pending invitation.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $uid   Member uid.
	 * @return array<string, mixed>
	 * @throws ActionError When not pending.
	 */
	public function resend( Actor $actor, string $uid ): array {
		$member = $this->pending( $uid );
		if ( ! self::can_invite( $actor, (string) $member['kind'] ) ) {
			throw ActionError::forbidden();
		}
		$this->send( $member, $actor, '' );
		return $this->summary( $member );
	}

	/**
	 * Cancels a pending invitation. A WordPress user created only for the
	 * invitation is removed as well.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $uid   Member uid.
	 * @return array{revoked: bool}
	 * @throws ActionError When not pending.
	 */
	public function revoke( Actor $actor, string $uid ): array {
		$member = $this->pending( $uid );
		if ( ! self::can_invite( $actor, (string) $member['kind'] ) ) {
			throw ActionError::forbidden();
		}
		$user_id = (int) $member['user_id'];
		$this->drop_tokens( $user_id );
		$this->plugin->service( 'members' )->repository()->delete( (int) $member['id'] );
		if ( get_user_meta( $user_id, self::NEW_USER_META, true ) && ! user_can( $user_id, 'manage_options' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			if ( ! is_multisite() ) {
				wp_delete_user( $user_id );
			} elseif ( ! is_super_admin( $user_id ) ) {
				// On a network wp_delete_user() only takes them off this site,
				// leaving an account nobody asked for. It goes from the network
				// only when it belongs to no other site.
				$others = array_diff( array_keys( get_blogs_of_user( $user_id ) ), array( get_current_blog_id() ) );
				if ( array() === $others ) {
					require_once ABSPATH . 'wp-admin/includes/ms.php';
					wpmu_delete_user( $user_id );
				} else {
					remove_user_from_blog( $user_id, get_current_blog_id() );
				}
			}
		}
		$this->members()->flush();
		do_action(
			'cliezoo_audit',
			'invite_revoked',
			array(
				'object_type' => 'members',
				'object_id'   => (int) $member['id'],
			)
		);
		return array( 'revoked' => true );
	}

	/**
	 * What the accept page shows (no account details beyond the invitee's own).
	 *
	 * @param string $token Invite token.
	 * @return array<string, mixed>
	 * @throws ActionError When the link is invalid.
	 */
	public function info( string $token ): array {
		list( , $member ) = $this->resolve( $token );
		return array(
			'workspace'      => (string) $this->plugin->settings()->get( 'workspace', 'name', get_bloginfo( 'name' ) ),
			'email'          => (string) $member['email'],
			'name'           => (string) $member['name'],
			'needs_password' => (bool) get_user_meta( (int) $member['user_id'], self::NEW_USER_META, true ),
			'kind'           => (string) $member['kind'],
		);
	}

	/**
	 * Accepts an invitation and signs the person in.
	 *
	 * @param string $token    Invite token.
	 * @param string $name     Display name.
	 * @param string $password Password (people new to the site only; ignored otherwise).
	 * @return array<string, mixed> Session result.
	 * @throws ActionError When the link or password is invalid.
	 */
	public function accept( string $token, string $name, string $password ): array {
		list( $row, $member ) = $this->resolve( $token );
		$user_id              = (int) $member['user_id'];
		$new                  = (bool) get_user_meta( $user_id, self::NEW_USER_META, true );
		$auth                 = $this->plugin->get( AuthService::class );
		// Only an account made for this invitation gets its first password
		// here. Somebody who already had one on the site keeps it: an
		// invitation link is not a way to change it.
		if ( $new ) {
			$auth->check_password_policy( $password );
			wp_set_password( $password, $user_id );
		}
		$name = trim( sanitize_text_field( $name ) );
		if ( '' !== $name ) {
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => $name,
				)
			);
		}
		$auth->mark_used( $row );
		$this->drop_tokens( $user_id );
		delete_user_meta( $user_id, self::NEW_USER_META );
		$this->plugin->service( 'members' )->repository()->update(
			(int) $member['id'],
			array_filter(
				array(
					'status'      => 'active',
					'accepted_at' => gmdate( 'Y-m-d H:i:s' ),
					'name'        => '' !== $name ? $name : null,
				)
			)
		);
		$this->members()->flush();
		/**
		 * An invitation was accepted.
		 *
		 * @param array $member The member row, now active.
		 */
		do_action( 'cliezoo_invite_accepted', $member );
		$this->welcome( (array) $this->plugin->service( 'members' )->repository()->find( (int) $member['id'] ) );
		do_action(
			'cliezoo_audit',
			'invite_accepted',
			array(
				'object_type' => 'members',
				'object_id'   => (int) $member['id'],
			)
		);
		return $auth->start_session( $user_id, false );
	}

	/**
	 * Makes another active admin the workspace owner; the current owner becomes an admin.
	 *
	 * WordPress site administrators always keep full access in Cliezoo.
	 *
	 * @param Actor  $actor    The current owner.
	 * @param string $uid      New owner's member uid.
	 * @param string $password Current owner's password (confirmation).
	 * @return array{owner: string}
	 * @throws ActionError When not allowed.
	 */
	public function transfer_ownership( Actor $actor, string $uid, string $password ): array {
		if ( 'owner' !== $actor->role || $actor->member_id <= 0 ) {
			throw ActionError::forbidden();
		}
		$user = get_userdata( $actor->user_id );
		if ( ! $user || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			throw ActionError::validation( __( 'The password is not correct.', 'cliezoo' ), array( 'password' => __( 'The password is not correct.', 'cliezoo' ) ) );
		}
		$repo   = $this->plugin->service( 'members' )->repository();
		$target = $repo->find_by_uid( $uid );
		if ( ! $target || 'staff' !== $target['kind'] || 'active' !== $target['status'] || (int) $target['id'] === $actor->member_id ) {
			throw ActionError::validation( __( 'Choose an active team member.', 'cliezoo' ) );
		}
		if ( 'admin' !== $target['role'] ) {
			throw ActionError::validation( __( 'Make this person an admin first, then transfer ownership.', 'cliezoo' ) );
		}
		$repo->update( (int) $target['id'], array( 'role' => 'owner' ) );
		$repo->update( $actor->member_id, array( 'role' => 'admin' ) );
		$this->members()->grant_caps( (int) $target['user_id'], 'staff', 'owner' );
		$this->members()->grant_caps( $actor->user_id, 'staff', 'admin' );
		$this->members()->flush();
		do_action(
			'cliezoo_audit',
			'ownership_transferred',
			array(
				'object_type' => 'members',
				'object_id'   => (int) $target['id'],
				'from'        => $actor->member_id,
			)
		);
		return array( 'owner' => (string) $target['uid'] );
	}

	/**
	 * Says they are in, once they are.
	 *
	 * An invitation is not a welcome: one asks somebody to set a password, and
	 * the next thing they heard from Cliezoo was a notification about something
	 * else. This is the address to come back to, which is the one thing worth
	 * saying at that moment.
	 *
	 * It never stops somebody getting in. Accepting an invitation ends with a
	 * session, and a mail server that is down or misconfigured must not turn
	 * that into a failure — so this is queued, and a queue that cannot run
	 * leaves a person signed in rather than locked out.
	 *
	 * @param array<string, mixed> $member The member row, now active.
	 * @return void
	 */
	private function welcome( array $member ): void {
		if ( empty( $member['email'] ) ) {
			return;
		}
		$this->plugin->get( Mailer::class )->queue(
			'welcome',
			(string) $member['email'],
			array(
				'recipient_name' => (string) $member['name'],
				'is_client'      => 'client' === (string) $member['kind'],
				'url'            => trailingslashit(
					(string) apply_filters(
						'cliezoo_app_url',
						home_url( '/dash/' ),
						'client' === (string) $member['kind'] ? 'client' : ''
					)
				),
			),
			array(
				'member_id' => (int) $member['id'],
				'category'  => 'account',
			)
		);
	}

	/**
	 * Invitation email.
	 *
	 * @param array<string, mixed> $member  Member row.
	 * @param Actor                $inviter Inviter.
	 * @param string               $message Personal note.
	 * @param string               $subject A subject of their own, or empty for the template's.
	 * @return void
	 */
	private function send( array $member, Actor $inviter, string $message, string $subject = '' ): void {
		$this->drop_tokens( (int) $member['user_id'] );
		$token = $this->plugin->get( AuthService::class )->issue_token( (int) $member['user_id'], 'invite', self::TTL, array( 'member' => (int) $member['id'] ) );
		$from  = $inviter->member_id ? $this->plugin->service( 'members' )->repository()->find( $inviter->member_id ) : null;
		// A client is invited to the portal, which may be on a domain
		// of the studio's own; the team is invited to this site.
		$app_url = trailingslashit( (string) apply_filters( 'cliezoo_app_url', home_url( '/dash/' ), 'client' === $member['kind'] ? 'client' : '' ) );
		$mailer  = $this->plugin->get( Mailer::class );
		$meta    = array(
			'member_id' => (int) $member['id'],
			'category'  => 'account',
		);
		// A client is invited to their portal and the team is invited to the
		// workspace. Two different things to say, so two templates a studio can
		// word separately.
		$which = 'client' === $member['kind'] ? 'client_invite' : 'member_invite';
		$mail  = $mailer->render(
			$which,
			array(
				'recipient_name' => (string) $member['name'],
				'inviter_name'   => $from ? (string) $from['name'] : '',
				'message'        => $message,
				'url'            => $app_url . 'auth/invite/' . $token,
				'is_client'      => 'client' === $member['kind'],
			),
			$meta
		);
		// The subject is the template's unless the person inviting wrote
		// their own — which is what an invitation from a person looks like.
		$mailer->send( (string) $member['email'], ( '' !== trim( $subject ) ) ? $subject : (string) $mail['subject'], (string) $mail['html'], (string) $mail['text'], $meta + array( 'template' => $which ) );
		$this->plugin->service( 'members' )->repository()->update( (int) $member['id'], array( 'invited_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * A pending member by uid.
	 *
	 * @param string $uid Uid.
	 * @return array<string, mixed>
	 * @throws ActionError When not pending.
	 */
	private function pending( string $uid ): array {
		$member = $this->plugin->service( 'members' )->repository()->find_by_uid( $uid );
		if ( ! $member ) {
			throw ActionError::not_found();
		}
		if ( 'invited' !== $member['status'] ) {
			throw ActionError::conflict( __( 'This invitation was already accepted.', 'cliezoo' ) );
		}
		return $member;
	}

	/**
	 * Token row and pending member for an invite token.
	 *
	 * @param string $token Token.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 * @throws ActionError When invalid.
	 */
	private function resolve( string $token ): array {
		if ( RateLimiter::hit( 'invite-ip:' . Request::ip(), 30, 900 ) ) {
			throw ActionError::rate_limited( __( 'Too many attempts. Please try again later.', 'cliezoo' ), 900 );
		}
		$invalid = new ActionError( 'cliezoo_link_expired', __( 'This invitation has expired or was already used. Ask for a new one.', 'cliezoo' ), 401 );
		$row     = $this->plugin->get( AuthService::class )->find_token( $token, 'invite' );
		if ( ! $row ) {
			throw $invalid;
		}
		$member = $this->plugin->service( 'members' )->repository()->first( array( 'user_id' => (int) $row['user_id'] ) );
		if ( ! $member || 'invited' !== $member['status'] ) {
			throw $invalid;
		}
		return array( $row, $member );
	}

	/**
	 * Removes a user's open invitation tokens.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	private function drop_tokens( int $user_id ): void {
		$this->plugin->service( 'auth_tokens' )->repository()->delete_where(
			array(
				'user_id' => $user_id,
				'type'    => 'invite',
			)
		);
	}

	/**
	 * A free user_login derived from an email.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private function unique_login( string $email ): string {
		$base  = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base  = '' !== $base ? mb_substr( $base, 0, 50 ) : 'member';
		$login = $base;
		$i     = 2;
		while ( username_exists( $login ) ) {
			$login = $base . $i;
			++$i;
		}
		return $login;
	}

	/**
	 * Public summary of a member.
	 *
	 * @param array<string, mixed> $member Row.
	 * @return array<string, mixed>
	 */
	private function summary( array $member ): array {
		return array(
			'uid'    => (string) $member['uid'],
			'email'  => (string) $member['email'],
			'name'   => (string) $member['name'],
			'role'   => (string) $member['role'],
			'kind'   => (string) $member['kind'],
			'status' => (string) $member['status'],
		);
	}
}
