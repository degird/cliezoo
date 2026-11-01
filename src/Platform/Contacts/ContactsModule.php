<?php
/**
 * Contacts: the people you talk to, and the people who log in to the portal.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Contacts;

use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Field;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Members\Members;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A contact is a person at a client — and, when they are invited, the same
 * person is a portal member. The two are deliberately separate rows: the
 * contact is a business record the workspace owns (title, role, source,
 * owner), the member is an account with a password, sessions and a role.
 * Deleting one must never silently delete the other (cliezoo.md §6.6).
 */
final class ContactsModule extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'contacts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependencies(): array {
		return array( 'clients' );
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
				'contacts',
				array(
					'client_id'           => Field::ref( 'clients' )->filterable()->label( __( 'Client', 'cliezoo' ) ),
					// The portal account, once there is one. Hidden: it is a
					// link between rows, not something to show or set.
					'member_id'           => Field::int()->hidden(),
					'name'                => Field::text( 190 )->required()->searchable()->sortable()->label( __( 'Full name', 'cliezoo' ) ),
					// What they like to be called: "Bill" for William.
					'preferred_name'      => Field::text( 120 )->searchable()->label( __( 'Preferred name', 'cliezoo' ) ),
					'handle'              => Field::text( 60 )->searchable()->label( __( 'Mention handle', 'cliezoo' ) ),
					'title'               => Field::text( 120 )->label( __( 'Job title', 'cliezoo' ) ),
					'email'               => Field::email()->required()->searchable(),
					'emails'              => Field::json()->label( __( 'Other email addresses', 'cliezoo' ) ),
					'phone'               => Field::phone()->searchable(),
					'avatar_file_id'      => Field::ref( 'files' )->label( __( 'Avatar', 'cliezoo' ) ),
					'status'              => Field::listed( 'contact_status' )->filterable(),
					'source'              => Field::listed( 'contact_source' )->filterable(),
					'type'                => Field::listed( 'contact_type' )->filterable(),
					'role'                => Field::text( 120 )->label( __( 'Role at the client', 'cliezoo' ) ),
					'is_primary'          => Field::bool()->filterable()->label( __( 'Primary contact', 'cliezoo' ) ),
					'is_decision_maker'   => Field::bool()->label( __( 'Decision maker', 'cliezoo' ) ),
					'is_billing'          => Field::bool()->filterable()->label( __( 'Billing contact', 'cliezoo' ) ),
					'is_technical'        => Field::bool()->label( __( 'Technical contact', 'cliezoo' ) ),
					'owner_id'            => Field::user()->filterable()->label( __( 'Owner', 'cliezoo' ) ),
					'tags'                => Field::json(),
					'locale'              => Field::text( 20 )->label( __( 'Language', 'cliezoo' ) ),
					'timezone'            => Field::text( 60 ),
					'date_format'         => Field::text( 20 ),
					'time_format'         => Field::enum( array( '12', '24' ) ),
					'week_start'          => Field::int()->range( 0, 6 ),
					// Everything about the portal is written here and mirrored
					// on the member row, because permission queries read this
					// table and must not join to find out who may sign in.
					'portal_status'       => Field::enum( array( 'none', 'requested', 'invited', 'active', 'deactivated', 'client_off' ) )->default( 'none' )->filterable()->label( __( 'Portal access', 'cliezoo' ) ),
					'portal_role'         => Field::enum( array( 'client_admin', 'client_member', 'client_viewer' ) )->default( 'client_member' )->filterable()->label( __( 'Portal role', 'cliezoo' ) ),
					'notification_preset' => Field::enum( array( 'default', 'everything', 'important', 'messages_mentions', 'nothing' ) )->label( __( 'Email notifications', 'cliezoo' ) ),
					'invited_at'          => Field::datetime()->readonly(),
					'accepted_at'         => Field::datetime()->readonly(),
					'last_login_at'       => Field::datetime()->readonly()->sortable(),
					// When email to this address last failed, and what the
					// mail server said. Written by the software rather than
					// typed, and shown on the screens so a studio waiting on
					// a reply finds out why there is not one.
					'email_problem_at'    => Field::datetime()->readonly()->filterable()->label( __( 'Email trouble', 'cliezoo' ) ),
					'email_problem'       => Field::text( 190 )->readonly()->label( __( 'What went wrong', 'cliezoo' ) ),
				)
			) )->with(
				array(
					'series'        => 'CON',
					'permission'    => 'contacts',
					'owner_field'   => 'owner_id',
					'client_field'  => 'client_id',
					'parent'        => array( 'clients', 'client_id' ),
					'custom_fields' => true,
					'searchable'    => true,
					'title_field'   => 'name',
					'default_sort'  => 'name',
					'indexes'       => array(
						'client' => array( 'client_id', 'portal_status' ),
						'member' => array( 'member_id' ),
						'email'  => array( 'email' ),
					),
					'fulltext'      => array( 'search' => array( 'name', 'email', 'title' ) ),
					'label'         => __( 'Contact', 'cliezoo' ),
					'plural_label'  => __( 'Contacts', 'cliezoo' ),
					'create_toggle' => 'clients_edit',
				)
			),
		);
	}

	/**
	 * Registers the lists, the defaults, the portal rules and the actions.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( Contacts::class, static fn(): Contacts => new Contacts( $plugin ) );
		$plugin->container()->set( EmailTrouble::class, static fn(): EmailTrouble => new EmailTrouble( $plugin ) );

		add_filter( 'cliezoo_lists', array( Lists::class, 'declare_lists' ) );

		// An address that cannot be reached is written on the person it
		// belongs to, and the software stops writing to it by itself. A
		// studio waiting six weeks for a reply to an email that never
		// arrived is the quietest way this kind of software loses money.
		add_action(
			'cliezoo_email_failed',
			static function ( string $to, array $meta = array() ) use ( $plugin ): void {
				$plugin->get( EmailTrouble::class )->failed( $to, $meta );
			},
			10,
			2
		);
		add_action(
			'cliezoo_email_sent',
			static function ( string $to ) use ( $plugin ): void {
				$plugin->get( EmailTrouble::class )->worked( $to );
			}
		);
		add_filter(
			'cliezoo_email_allowed',
			static function ( bool $allowed, string $to, array $meta = array() ) use ( $plugin ): bool {
				return $plugin->get( EmailTrouble::class )->allowed( $allowed, $to, $meta );
			},
			10,
			3
		);

		// A corrected address is a new chance, so the trouble does not follow
		// it. Cleared on the way in rather than afterwards, because the row
		// that is written is the row the screens then read.
		add_filter(
			'cliezoo_contacts_before_update',
			static function ( array $data, array $row ): array {
				if ( ! array_key_exists( 'email', $data ) ) {
					return $data;
				}
				if ( strtolower( (string) $data['email'] ) === strtolower( (string) ( $row['email'] ?? '' ) ) ) {
					return $data;
				}
				$data['email_problem_at'] = null;
				$data['email_problem']    = null;
				return $data;
			},
			20,
			2
		);

		foreach ( array( 'create', 'update' ) as $when ) {
			add_filter( 'cliezoo_contacts_before_' . $when, array( Members::class, 'check_locale' ), 5 );
		}

		// A handle is how people are mentioned, so every contact gets one.
		add_filter(
			'cliezoo_contacts_before_create',
			static fn( array $data ): array => $plugin->get( Contacts::class )->fill( $data, null )
		);
		add_filter(
			'cliezoo_contacts_before_update',
			static fn( array $data, array $row ): array => $plugin->get( Contacts::class )->fill( $data, $row ),
			10,
			2
		);

		// One primary contact per client, the same way one address is the
		// default: the one just marked wins.
		foreach ( array( 'created', 'updated' ) as $event ) {
			add_action(
				'cliezoo_contacts_' . $event,
				static function ( array $row ) use ( $plugin ): void {
					$plugin->get( Contacts::class )->keep_one_primary( $row );
				}
			);
		}

		// Which accounts a client's people may read: their own, and their
		// colleagues at the company they are looking at. Without it a client
		// could not even open their own profile.
		add_filter(
			'cliezoo_client_scope',
			static function ( $scope, Actor $actor, Entity $entity ) use ( $plugin ) {
				if ( 'members' !== $entity->name ) {
					return $scope;
				}
				$contacts = $plugin->entities()->get( 'contacts' )->table();
				return array(
					"( t.id = %d OR t.id IN ( SELECT c.member_id FROM {$contacts} c WHERE c.client_id = %d AND c.member_id IS NOT NULL AND c.deleted_at IS NULL ) )",
					array( $actor->member_id, (int) $actor->active_client_id ),
				);
			},
			10,
			3
		);

		// A contact's portal role is their account's role: changing one
		// changes the other, so what the team sees is what the person gets.
		add_action(
			'cliezoo_contacts_updated',
			static function ( array $row, array $before ) use ( $plugin ): void {
				if ( empty( $row['member_id'] ) || (string) ( $before['portal_role'] ?? '' ) === (string) $row['portal_role'] ) {
					return;
				}
				$members = $plugin->service( 'members' )->repository();
				$member  = $members->find( (int) $row['member_id'] );
				if ( ! $member || 'client' !== ( $member['kind'] ?? '' ) ) {
					return;
				}
				$members->update( (int) $member['id'], array( 'role' => (string) $row['portal_role'] ) );
				$plugin->get( Members::class )->flush();
				do_action(
					'cliezoo_audit',
					'contact_role_changed',
					array(
						'object_type' => 'contacts',
						'object_id'   => (int) $row['id'],
						'from'        => (string) ( $before['portal_role'] ?? '' ),
						'to'          => (string) $row['portal_role'],
					)
				);
			},
			10,
			2
		);

		// The language, clock, first day of the week and time zone set on a
		// contact are the ones their portal speaks and shows.
		add_action(
			'cliezoo_contacts_updated',
			static function ( array $row, array $before ) use ( $plugin ): void {
				if ( empty( $row['member_id'] ) ) {
					return;
				}
				$changed = array();
				// Emails they are sent follow the preset chosen for them.
				if ( (string) ( $row['notification_preset'] ?? '' ) !== (string) ( $before['notification_preset'] ?? '' ) && '' !== (string) ( $row['notification_preset'] ?? '' ) && isset( $plugin->active_modules()['notifications'] ) ) {
					$member = $plugin->service( 'members' )->repository()->find( (int) $row['member_id'] );
					if ( $member && 'client' === (string) $member['kind'] ) {
						$plugin->get( \Cliezoo\Platform\Notifications\Notifier::class )->save_prefs( $member, array( 'preset' => (string) $row['notification_preset'] ) );
					}
				}
				foreach ( array( 'locale', 'time_format', 'week_start', 'timezone' ) as $column ) {
					if ( (string) ( $row[ $column ] ?? '' ) !== (string) ( $before[ $column ] ?? '' ) ) {
						$changed[ $column ] = $row[ $column ] ?? null;
					}
				}
				$members = $plugin->service( 'members' )->repository();
				$member  = $changed ? $members->find( (int) $row['member_id'] ) : null;
				if ( ! $member || 'client' !== ( $member['kind'] ?? '' ) ) {
					return;
				}
				$members->update( (int) $member['id'], $changed );
				if ( array_key_exists( 'locale', $changed ) ) {
					Members::keep_locale( (int) $member['user_id'], (string) $changed['locale'] );
				}
				$plugin->get( Members::class )->flush();
			},
			10,
			2
		);

		// Turning a client's portal off turns off its people, and signs them
		// out wherever they are.
		add_action(
			'cliezoo_client_portal_changed',
			static function ( int $client_id, bool $active ) use ( $plugin ): void {
				$plugin->get( Contacts::class )->set_client_portal( $client_id, $active );
			},
			10,
			2
		);

		// A contact's row mirrors what happens to their account, so the list
		// can sort by "last signed in" and show who has accepted.
		add_action(
			'cliezoo_member_logged_in',
			static function ( array $member ) use ( $plugin ): void {
				$plugin->get( Contacts::class )->mirror_member( $member, array( 'last_login_at' => gmdate( 'Y-m-d H:i:s' ) ) );
			}
		);
		add_action(
			'cliezoo_invite_accepted',
			static function ( array $member ) use ( $plugin ): void {
				$plugin->get( Contacts::class )->mirror_member(
					$member,
					array(
						'accepted_at'   => gmdate( 'Y-m-d H:i:s' ),
						'portal_status' => 'active',
					)
				);
			}
		);

		// Where each contact's way in stands, including an invitation that
		// ran out — which the stored status cannot say, because nothing
		// happens when it does.
		add_filter(
			'cliezoo_present_contacts',
			static function ( array $out, Entity $entity, array $rows, Actor $actor ) use ( $plugin ): array {
				$contacts = $plugin->get( Contacts::class );
				$files    = $plugin->service( 'files' );
				foreach ( $rows as $i => $row ) {
					if ( isset( $out[ $i ] ) ) {
						$out[ $i ]['portal_state']      = $contacts->portal_state( $row );
						$out[ $i ]['invite_expires_at'] = $contacts->invite_expires( $row );
						// Their account, for the team's screens that show how
						// they sign in and what they can open.
						$out[ $i ]['member'] = null;
						if ( ! empty( $row['member_id'] ) && $actor->is_staff() ) {
							$member              = $plugin->service( 'members' )->repository()->find( (int) $row['member_id'] );
							$out[ $i ]['member'] = $member ? array( 'uid' => (string) $member['uid'] ) : null;
						}
						$out[ $i ]['avatar_url'] = null;
						$avatar                  = empty( $row['avatar_file_id'] ) ? null : $files->repository()->find( (int) $row['avatar_file_id'] );
						if ( $avatar && empty( $avatar['deleted_at'] ) && \Cliezoo\Infrastructure\Storage\Downloads::is_image( (string) $avatar['mime'] ) && $plugin->policy()->can( $actor, \Cliezoo\Platform\Permissions\Policy::VIEW, $files->entity(), $avatar ) ) {
							$out[ $i ]['avatar_url'] = \Cliezoo\Infrastructure\Storage\Downloads::signed_url( (string) $avatar['uid'], DAY_IN_SECONDS, 'avatar' );
						}
					}
				}
				return $out;
			},
			10,
			4
		);

		// What an external agent may do with contacts: read and correct them.
		// Inviting somebody into the workspace is never an agent's decision.
		add_filter(
			'cliezoo_entity_action_meta',
			static function ( array $extra, Entity $entity, string $verb ): array {
				if ( 'contacts' !== $entity->name ) {
					return $extra;
				}
				if ( in_array( $verb, array( 'list', 'get' ), true ) ) {
					$extra['mcp'] = 'read';
				} elseif ( in_array( $verb, array( 'create', 'update' ), true ) ) {
					$extra['mcp'] = 'safe';
				}
				return $extra;
			},
			10,
			3
		);

		// A client's page counts its people.
		add_filter(
			'cliezoo_client_summary',
			static function ( array $out, array $client, Actor $actor ) use ( $plugin ): array {
				unset( $actor );
				$out['counts']['contacts'] = count(
					$plugin->service( 'contacts' )->repository()->where( array( 'client_id' => (int) $client['id'] ) )
				);
				return $out;
			},
			10,
			3
		);

		add_action(
			'cliezoo_register_actions',
			static function ( ActionRegistry $registry ) use ( $plugin ): void {
				ContactActions::register( $registry, $plugin );
			}
		);
	}
}
