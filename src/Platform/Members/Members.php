<?php
/**
 * Cliezoo members (WordPress users with a Cliezoo role).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Members;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Data\Repository;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Maps WordPress users to Cliezoo members and builds the Actor.
 *
 * Site administrators are Cliezoo Owners automatically (Blueprint §8.6).
 * The portal is chosen by the Cliezoo role in context, not by WordPress
 * capabilities alone (§31.3).
 */
final class Members {

	public const CAP_ACCESS = 'cliezoo_access_app';
	public const CAP_ADMIN  = 'cliezoo_manage_settings';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Actor cache per user for this request.
	 *
	 * @var array<int, Actor>
	 */
	private array $cache = array();

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
		add_action( 'profile_update', array( $this, 'sync_user' ), 10, 1 );
		add_action( 'set_user_role', array( $this, 'sync_user' ), 10, 1 );
		add_action( 'delete_user', array( $this, 'user_deleted' ), 10, 2 );
		( new Reassign( $this->plugin ) )->hooks();
	}

	/**
	 * Repository.
	 *
	 * @return Repository
	 */
	private function repository(): Repository {
		return $this->plugin->service( 'members' )->repository();
	}

	/**
	 * Member row for a WordPress user (creates one for administrators).
	 *
	 * @param int $user_id User id.
	 * @return array<string, mixed>|null
	 */
	public function member_for( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}
		$row = $this->repository()->first( array( 'user_id' => $user_id ) );
		if ( ! $row && user_can( $user_id, 'manage_options' ) ) {
			$row = $this->create_for_user( $user_id, 'owner', 'staff' );
		}
		return $row;
	}

	/**
	 * The address a member is written to.
	 *
	 * A record's owner is a member, not a WordPress user, and the two ids
	 * are different numbers: reading one as the other wrote to whichever
	 * account happened to have that number, or to nobody.
	 *
	 * @param int $member_id The member.
	 * @return string '' when there is no such member or no address.
	 */
	public function email_of( int $member_id ): string {
		if ( $member_id <= 0 ) {
			return '';
		}
		$member = $this->plugin->service( 'members' )->repository()->find( $member_id );
		$user   = is_array( $member ) ? get_userdata( (int) $member['user_id'] ) : false;
		return $user ? (string) $user->user_email : '';
	}

	/**
	 * Builds the Actor for a WordPress user.
	 *
	 * @param int $user_id User id (0 = guest).
	 * @return Actor
	 */
	public function actor_for( int $user_id ): Actor {
		if ( isset( $this->cache[ $user_id ] ) ) {
			return $this->cache[ $user_id ];
		}
		$member = $this->member_for( $user_id );
		if ( ! $member || 'active' !== $member['status'] ) {
			$actor = Actor::guest();
		} else {
			$role = (string) $member['role'];
			if ( user_can( $user_id, 'manage_options' ) && 'staff' === $member['kind'] ) {
				$role = 'owner';
			}
			$clients = 'client' === $member['kind'] ? $this->client_ids( (int) $member['id'] ) : array();
			$active  = (int) get_user_meta( $user_id, '_cliezoo_active_client', true );
			if ( ! in_array( $active, $clients, true ) ) {
				$active = (int) ( $clients[0] ?? 0 );
			}
			// Somebody who is a contact at two companies can be an admin at
			// one and a viewer at the other: the role is the one their
			// contact at the company they are looking at has.
			if ( $active ) {
				$role = $this->client_role( (int) $member['id'], $active, $role );
			}
			/**
			 * Team ids of a member (teams, from add-ons).
			 *
			 * @param int[] $teams     Team ids.
			 * @param int   $member_id Member id.
			 */
			$teams = (array) apply_filters( 'cliezoo_member_team_ids', array(), (int) $member['id'] );
			$actor = new Actor( $user_id, (int) $member['id'], $role, $clients, $active, $teams );
		}
		$this->cache[ $user_id ] = $actor;
		return $actor;
	}

	/**
	 * Clears the actor cache (after role changes, in tests).
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * A portal member's role at one company: their contact's portal role
	 * there, or the fallback when there is none.
	 *
	 * @param int    $member_id Member id.
	 * @param int    $client_id Client id.
	 * @param string $fallback  Role to use when the contact names none.
	 * @return string
	 */
	private function client_role( int $member_id, int $client_id, string $fallback ): string {
		global $wpdb;
		if ( ! $this->plugin->entities()->has( 'contacts' ) ) {
			return $fallback;
		}
		$table = esc_sql( $this->plugin->entities()->get( 'contacts' )->table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from code.
		$role = (string) $wpdb->get_var( $wpdb->prepare( "SELECT portal_role FROM {$table} WHERE member_id = %d AND client_id = %d AND portal_status = 'active' AND deleted_at IS NULL ORDER BY id LIMIT 1", $member_id, $client_id ) );
		return in_array( $role, Roles::CLIENT_ROLES, true ) ? $role : $fallback;
	}

	/**
	 * Client company ids a portal member belongs to (active portal access).
	 *
	 * @param int $member_id Member id.
	 * @return int[]
	 */
	public function client_ids( int $member_id ): array {
		global $wpdb;
		if ( ! $this->plugin->entities()->has( 'contacts' ) ) {
			return array();
		}
		$table   = esc_sql( $this->plugin->entities()->get( 'contacts' )->table() );
		$clients = esc_sql( $this->plugin->entities()->get( 'clients' )->table() );
		// A client in the trash is nobody's company to look at any more.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from code.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT c.client_id FROM {$table} c JOIN {$clients} k ON k.id = c.client_id AND k.deleted_at IS NULL WHERE c.member_id = %d AND c.portal_status = 'active' AND c.deleted_at IS NULL AND c.client_id IS NOT NULL", $member_id ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Creates a member row for a user.
	 *
	 * @param int    $user_id User id.
	 * @param string $role    Role key.
	 * @param string $kind    staff|client.
	 * @param string $status  Status.
	 * @return array<string, mixed>
	 */
	public function create_for_user( int $user_id, string $role, string $kind, string $status = 'active' ): array {
		$user = get_userdata( $user_id );
		$data = array(
			'user_id' => $user_id,
			'kind'    => $kind,
			'role'    => $role,
			'status'  => $status,
			'name'    => $user ? ( '' !== $user->display_name ? $user->display_name : $user->user_login ) : 'User ' . $user_id,
			'email'   => $user ? $user->user_email : null,
			'handle'  => $user ? sanitize_title( $user->user_login ) : null,
		);
		if ( 'invited' === $status ) {
			$data['invited_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		$row = $this->repository()->insert( $data );
		$this->grant_caps( $user_id, $kind, $role );
		unset( $this->cache[ $user_id ] );
		return $row;
	}

	/**
	 * Grants the WordPress capabilities that match a Cliezoo role.
	 *
	 * @param int    $user_id User id.
	 * @param string $kind    staff|client.
	 * @param string $role    Role.
	 * @return void
	 */
	public function grant_caps( int $user_id, string $kind, string $role ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$user->add_cap( self::CAP_ACCESS );
		if ( 'staff' === $kind && in_array( $role, array( 'owner', 'admin' ), true ) ) {
			$user->add_cap( self::CAP_ADMIN );
		} else {
			$user->remove_cap( self::CAP_ADMIN );
		}
	}

	/**
	 * Keeps the member's name/email in step with the WordPress user.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function sync_user( int $user_id ): void {
		$row  = $this->repository()->first( array( 'user_id' => $user_id ) );
		$user = get_userdata( $user_id );
		if ( ! $row || ! $user ) {
			return;
		}
		$changes = array();
		if ( $row['email'] !== $user->user_email ) {
			$changes['email'] = $user->user_email;
			/**
			 * The member's email changed: sessions end and magic links become invalid (§31.3).
			 *
			 * @param int    $user_id  User.
			 * @param string $old      Old email.
			 * @param string $new      New email.
			 */
			do_action( 'cliezoo_member_email_changed', $user_id, (string) $row['email'], $user->user_email );
		}
		if ( '' !== $user->display_name && $row['name'] !== $user->display_name ) {
			$changes['name'] = $user->display_name;
		}
		if ( $changes ) {
			$this->repository()->update( (int) $row['id'], $changes );
		}
		unset( $this->cache[ $user_id ] );
	}

	/**
	 * A WordPress user is being deleted: keep records with a "Former member" name.
	 *
	 * @param int      $user_id  User id.
	 * @param int|null $reassign User receiving the content.
	 * @return void
	 */
	public function user_deleted( int $user_id, $reassign = null ): void {
		$row = $this->repository()->first( array( 'user_id' => $user_id ) );
		if ( ! $row ) {
			return;
		}
		/**
		 * Reassign a deleted member's records (tasks, deals, owners…) to another member.
		 *
		 * @param int      $member_id    Deleted member.
		 * @param int|null $new_member   Member receiving the records (or null).
		 */
		$new_member = null;
		if ( $reassign ) {
			// member_for() also makes the row for an administrator who has
			// not opened Cliezoo yet. Only an active team member can take the
			// work over: a client contact or somebody deactivated would leave
			// it with nobody who can act on it, so it stays where it was.
			$target     = $this->member_for( (int) $reassign );
			$new_member = $target && 'active' === $target['status'] && 'staff' === $target['kind'] ? (int) $target['id'] : null;
		}
		do_action( 'cliezoo_member_reassign', (int) $row['id'], $new_member );
		$this->repository()->update(
			(int) $row['id'],
			array(
				'status'         => 'deactivated',
				/* translators: %s: former member name. */
				'name'           => sprintf( __( '%s (former member)', 'cliezoo' ), (string) $row['name'] ),
				'email'          => null,
				'deactivated_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Whether a language is one this site can speak: empty (the site's own),
	 * English, or one that is installed.
	 *
	 * @param string $locale Locale ("de_DE"), or empty.
	 * @return bool
	 */
	public static function known_locale( string $locale ): bool {
		return '' === $locale || 'en_US' === $locale || in_array( $locale, get_available_languages(), true );
	}

	/**
	 * Refuses a language the site does not have, rather than keeping one
	 * that the app would show and WordPress would ignore.
	 *
	 * @param array<string, mixed> $data Values being saved.
	 * @return array<string, mixed>
	 * @throws ActionError When the language is not installed.
	 */
	public static function check_locale( array $data ): array {
		if ( array_key_exists( 'locale', $data ) && ! self::known_locale( (string) $data['locale'] ) ) {
			throw ActionError::validation( __( 'That language is not installed on this site.', 'cliezoo' ), array( 'locale' => __( 'That language is not installed on this site.', 'cliezoo' ) ) );
		}
		return $data;
	}

	/**
	 * Keeps somebody's language where WordPress keeps a user's, so the app,
	 * the portal and wp-admin all speak it. Empty goes back to the site's.
	 *
	 * @param int    $user_id WordPress user.
	 * @param string $locale  Locale ("de_DE"), or empty.
	 * @return void
	 */
	public static function keep_locale( int $user_id, string $locale ): void {
		if ( ! self::known_locale( $locale ) ) {
			return;
		}
		if ( ! $user_id ) {
			return;
		}
		if ( '' === $locale ) {
			delete_user_meta( $user_id, 'locale' );
		} elseif ( 'en_US' === $locale || in_array( $locale, get_available_languages(), true ) ) {
			update_user_meta( $user_id, 'locale', $locale );
		}
	}

	/**
	 * Valid staff role keys (built-in + custom).
	 *
	 * @return string[]
	 */
	public static function staff_roles(): array {
		return array_values( array_diff( array_keys( Roles::matrices() ), Roles::CLIENT_ROLES ) );
	}
}
