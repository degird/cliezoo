<?php
/**
 * What a contact does for itself, and how it becomes a portal account.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Contacts;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Auth\Sessions;
use Cliezoo\Platform\Members\Invitations;
use Cliezoo\Platform\Members\Members;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * The contact record and the portal account are two rows, and this is the
 * only place that ties them together: inviting someone, cancelling that,
 * switching their access off and on, and keeping the two in step when one
 * of them changes (cliezoo.md §6.6).
 */
final class Contacts {

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
	 * Fills in what can be worked out: a mention handle, and the client's
	 * own language and time zone for a person who has not said otherwise.
	 *
	 * @param array<string, mixed>      $data     Column data about to be saved.
	 * @param array<string, mixed>|null $existing The current row, on update.
	 * @return array<string, mixed>
	 */
	public function fill( array $data, ?array $existing ): array {
		$name = (string) ( $data['name'] ?? ( $existing['name'] ?? '' ) );
		if ( '' === (string) ( $data['handle'] ?? ( $existing['handle'] ?? '' ) ) && '' !== $name ) {
			$data['handle'] = $this->unique_handle( $name, (int) ( $existing['id'] ?? 0 ) );
		}
		$client_id = (int) ( $data['client_id'] ?? ( $existing['client_id'] ?? 0 ) );
		if ( null === $existing && $client_id > 0 ) {
			$client = $this->plugin->service( 'clients' )->repository()->find( $client_id );
			foreach ( array(
				'locale'   => 'language',
				'timezone' => 'timezone',
			) as $column => $from ) {
				if ( empty( $data[ $column ] ) && ! empty( $client[ $from ] ) ) {
					$data[ $column ] = $client[ $from ];
				}
			}
		}
		return $data;
	}

	/**
	 * A handle nobody else is using.
	 *
	 * @param string $name   The person's name.
	 * @param int    $ignore Contact id to ignore (itself, on update).
	 * @return string
	 */
	private function unique_handle( string $name, int $ignore = 0 ): string {
		$base = sanitize_title( $name );
		$base = '' !== $base ? $base : 'contact';
		$try  = $base;
		for ( $suffix = 2; $suffix < 100; $suffix++ ) {
			$found = $this->plugin->service( 'contacts' )->repository()->first( array( 'handle' => $try ) );
			if ( ! $found || (int) $found['id'] === $ignore ) {
				return $try;
			}
			$try = $base . '-' . $suffix;
		}
		return $base . '-' . wp_generate_password( 4, false );
	}

	/**
	 * Exactly one primary contact per client.
	 *
	 * @param array<string, mixed> $row The contact that was saved.
	 * @return void
	 */
	public function keep_one_primary( array $row ): void {
		$client_id = (int) ( $row['client_id'] ?? 0 );
		if ( 0 === $client_id || 1 !== (int) ( $row['is_primary'] ?? 0 ) ) {
			return;
		}
		$repository = $this->plugin->service( 'contacts' )->repository();
		foreach ( $repository->where( array( 'client_id' => $client_id ) ) as $other ) {
			if ( (int) $other['id'] !== (int) $row['id'] && 1 === (int) $other['is_primary'] ) {
				$repository->update( (int) $other['id'], array( 'is_primary' => 0 ) );
			}
		}
	}

	/**
	 * Invites a contact into the portal, or creates their account silently.
	 *
	 * @param Actor                $actor   Who is inviting.
	 * @param array<string, mixed> $contact The contact row.
	 * @param array<string, mixed> $in      role, notify, message, subject.
	 * @param bool                 $checked The caller has decided this person may invite (a Client Admin).
	 * @return array<string, mixed> The contact, as the API sends it.
	 * @throws ActionError When the contact cannot be invited.
	 */
	public function invite( Actor $actor, array $contact, array $in, bool $checked = false ): array {
		if ( empty( $contact['email'] ) ) {
			throw ActionError::validation( __( 'This contact needs an email address before they can be invited.', 'cliezoo' ) );
		}
		if ( empty( $contact['client_id'] ) ) {
			throw ActionError::validation( __( 'Give this contact a client first: portal access is always to one client\'s work.', 'cliezoo' ) );
		}
		$client = $this->plugin->service( 'clients' )->repository()->find( (int) $contact['client_id'] );
		if ( ! $client || ! (int) $client['portal_active'] ) {
			throw ActionError::conflict( __( 'This client\'s portal is switched off. Turn it on first.', 'cliezoo' ) );
		}

		$role       = (string) ( $in['role'] ?? ( $contact['portal_role'] ?? 'client_member' ) );
		$member     = $this->plugin->get( Invitations::class )->invite(
			$actor,
			array(
				'email'   => (string) $contact['email'],
				'name'    => (string) $contact['name'],
				'kind'    => 'client',
				'role'    => $role,
				'message' => (string) ( $in['message'] ?? '' ),
				'subject' => (string) ( $in['subject'] ?? '' ),
				'notify'  => ! array_key_exists( 'notify', $in ) || ! empty( $in['notify'] ),
			),
			$checked
		);
		$member_row = $this->plugin->service( 'members' )->repository()->find_by_uid( (string) $member['uid'] );
		if ( ! $member_row ) {
			throw ActionError::conflict( __( 'The invitation could not be created.', 'cliezoo' ) );
		}

		$this->plugin->service( 'contacts' )->repository()->update(
			(int) $contact['id'],
			array(
				'member_id'     => (int) $member_row['id'],
				'portal_role'   => $role,
				'portal_status' => 'accepted' === ( $member_row['status'] ?? '' ) ? 'active' : 'invited',
				'invited_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		// A preset chosen before they had an account takes effect now.
		if ( ! empty( $contact['notification_preset'] ) && isset( $this->plugin->active_modules()['notifications'] ) ) {
			$this->plugin->get( \Cliezoo\Platform\Notifications\Notifier::class )->save_prefs( $member_row, array( 'preset' => (string) $contact['notification_preset'] ) );
		}
		$this->plugin->get( Members::class )->flush();
		do_action(
			'cliezoo_audit',
			'contact_invited',
			array(
				'object_type' => 'contacts',
				'object_id'   => (int) $contact['id'],
			)
		);

		return $this->present( (int) $contact['id'], $actor );
	}

	/**
	 * Where a contact's way in stands: none, requested, invited, expired
	 * (invited, and the link has run out), active, deactivated.
	 *
	 * @param array<string, mixed> $contact The contact row.
	 * @return string
	 */
	public function portal_state( array $contact ): string {
		$status = (string) ( $contact['portal_status'] ?? 'none' );
		if ( 'invited' === $status && ! empty( $contact['invited_at'] ) && strtotime( (string) $contact['invited_at'] . ' UTC' ) + Invitations::TTL < time() ) {
			return 'expired';
		}
		return $status;
	}

	/**
	 * When an invitation's link runs out, as ISO 8601, or null.
	 *
	 * @param array<string, mixed> $contact The contact row.
	 * @return string|null
	 */
	public function invite_expires( array $contact ): ?string {
		if ( 'invited' !== (string) ( $contact['portal_status'] ?? '' ) || empty( $contact['invited_at'] ) ) {
			return null;
		}
		return gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( (string) $contact['invited_at'] . ' UTC' ) + Invitations::TTL );
	}

	/**
	 * Cancels an invitation that has not been accepted.
	 *
	 * @param Actor                $actor   Who is cancelling.
	 * @param array<string, mixed> $contact The contact row.
	 * @return array<string, mixed>
	 * @throws ActionError When there is nothing to cancel.
	 */
	public function cancel_invite( Actor $actor, array $contact ): array {
		$member = $this->member_of( $contact );
		if ( ! $member || 'invited' !== (string) $member['status'] ) {
			throw ActionError::conflict( __( 'There is no invitation to cancel.', 'cliezoo' ) );
		}
		$this->plugin->get( Invitations::class )->revoke( $actor, (string) $member['uid'] );
		$this->plugin->service( 'contacts' )->repository()->update(
			(int) $contact['id'],
			array(
				'member_id'     => null,
				'portal_status' => 'none',
				'invited_at'    => null,
			)
		);
		$this->plugin->get( Members::class )->flush();
		return $this->present( (int) $contact['id'], $actor );
	}

	/**
	 * Turns one contact's portal access on or off.
	 *
	 * Off means: signed out everywhere, cannot sign in again, and nothing is
	 * deleted — which is the difference between deactivating and deleting
	 * that people get wrong elsewhere.
	 *
	 * @param Actor                $actor   Who is changing it.
	 * @param array<string, mixed> $contact The contact row.
	 * @param bool                 $active  Whether access is on.
	 * @return array<string, mixed>
	 * @throws ActionError When the contact has no account.
	 */
	public function set_portal( Actor $actor, array $contact, bool $active ): array {
		$member = $this->member_of( $contact );
		if ( ! $member ) {
			throw ActionError::conflict( __( 'This contact has no portal account yet.', 'cliezoo' ) );
		}
		$this->plugin->service( 'members' )->repository()->update(
			(int) $member['id'],
			array(
				'status'         => $active ? 'active' : 'deactivated',
				'deactivated_at' => $active ? null : gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$this->plugin->service( 'contacts' )->repository()->update(
			(int) $contact['id'],
			array( 'portal_status' => $active ? 'active' : 'deactivated' )
		);
		if ( ! $active ) {
			$this->plugin->get( Sessions::class )->destroy_all( (int) $member['user_id'] );
		}
		$this->plugin->get( Members::class )->flush();
		do_action(
			'cliezoo_audit',
			$active ? 'contact_portal_enabled' : 'contact_portal_disabled',
			array(
				'object_type' => 'contacts',
				'object_id'   => (int) $contact['id'],
			)
		);
		return $this->present( (int) $contact['id'], $actor );
	}

	/**
	 * Switches every contact of a client on or off, when the client's own
	 * portal access changes.
	 *
	 * @param int  $client_id Client id.
	 * @param bool $active    Whether the client's portal is on.
	 * @return void
	 */
	public function set_client_portal( int $client_id, bool $active ): void {
		$repository = $this->plugin->service( 'contacts' )->repository();
		$sessions   = $this->plugin->get( Sessions::class );
		foreach ( $repository->where( array( 'client_id' => $client_id ) ) as $contact ) {
			$member = $this->member_of( $contact );
			$status = (string) $contact['portal_status'];
			if ( ! $member ) {
				continue;
			}
			// Turning the client back on reinstates only the people this
			// switch turned off. Anybody deactivated on their own stays that
			// way, which is why "off because the client is off" is its own
			// status rather than the same one.
			if ( $active && 'client_off' !== $status ) {
				continue;
			}
			if ( ! $active && ! in_array( $status, array( 'active', 'invited' ), true ) ) {
				continue;
			}
			$repository->update( (int) $contact['id'], array( 'portal_status' => $active ? 'active' : 'client_off' ) );
			$this->plugin->service( 'members' )->repository()->update(
				(int) $member['id'],
				array(
					'status'         => $active ? 'active' : 'deactivated',
					'deactivated_at' => $active ? null : gmdate( 'Y-m-d H:i:s' ),
				)
			);
			if ( ! $active ) {
				$sessions->destroy_all( (int) $member['user_id'] );
			}
		}
		$this->plugin->get( Members::class )->flush();
	}

	/**
	 * Copies something that happened to an account onto the contact row.
	 *
	 * The contact keeps its own copy of "last signed in" and "accepted"
	 * because lists sort and filter on them, and a list cannot sort by a
	 * value that lives in another table.
	 *
	 * @param array<string, mixed> $member The member row.
	 * @param array<string, mixed> $values What to write.
	 * @return void
	 */
	public function mirror_member( array $member, array $values ): void {
		if ( 'client' !== (string) ( $member['kind'] ?? '' ) ) {
			return;
		}
		$repository = $this->plugin->service( 'contacts' )->repository();
		foreach ( $repository->where( array( 'member_id' => (int) $member['id'] ) ) as $contact ) {
			$repository->update( (int) $contact['id'], $values );
		}
	}

	/**
	 * The portal account behind a contact, if there is one.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 * @return array<string, mixed>|null
	 */
	public function member_of( array $contact ): ?array {
		$member_id = (int) ( $contact['member_id'] ?? 0 );
		return $member_id > 0 ? $this->plugin->service( 'members' )->repository()->find( $member_id ) : null;
	}

	/**
	 * The contact as the API sends it, freshly read.
	 *
	 * @param int   $id    Contact id.
	 * @param Actor $actor Who asked.
	 * @return array<string, mixed>
	 * @throws ActionError When it has gone.
	 */
	private function present( int $id, Actor $actor ): array {
		$row = $this->plugin->service( 'contacts' )->repository()->find( $id );
		if ( ! $row ) {
			throw ActionError::not_found();
		}
		return $this->plugin->service( 'contacts' )->present( $actor, $row );
	}
}
