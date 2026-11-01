<?php
/**
 * Contact actions: the portal, and bringing a list of people in.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Contacts;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Members\Invitations;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Inviting a person to the portal, cancelling that, switching their access
 * off, and importing a list of people from a spreadsheet (cliezoo.md §6.6).
 *
 * Everything here that affects who can sign in needs `clients_invite`, and
 * none of it is offered to external agents.
 */
final class ContactActions {

	/**
	 * The route path for one contact.
	 */
	private const ONE = '/contacts/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})';

	/**
	 * How many rows one import may carry.
	 */
	private const IMPORT_LIMIT = 500;

	/**
	 * Registers the actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	public static function register( ActionRegistry $registry, Plugin $plugin ): void {
		$may_invite = static fn( Actor $actor ): bool => $actor->is_staff() && ( $actor->is_admin() || $actor->allows( 'clients_invite' ) );
		$contacts   = static fn(): Contacts => $plugin->get( Contacts::class );

		$registry->add(
			Op::make( 'contacts.invite', __( 'Invite a contact to the portal', 'cliezoo' ) )
				->describe( __( 'Gives this person a way in. With "notify" off the account is made quietly and nothing is sent, so you can tell them yourself.', 'cliezoo' ) )
				->input(
					array(
						'uid'     => Op::uid(),
						'role'    => array(
							'type' => 'string',
							'enum' => array( 'client_admin', 'client_member', 'client_viewer' ),
						),
						'notify'  => array( 'type' => 'boolean' ),
						'message' => Op::str( 1000 ),
						'subject' => Op::str( 200 ),
					)
				)
				->allow( $may_invite )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin, $contacts ): array {
						$row = $plugin->service( 'contacts' )->load( $actor, (string) $in['uid'], Policy::UPDATE );
						return $contacts()->invite( $actor, $row, $in );
					}
				)
				->rest( 'POST', self::ONE . '/invite' )
		);

		$registry->add(
			Op::make( 'contacts.invite_resend', __( 'Send the invitation again', 'cliezoo' ) )
				->input( array( 'uid' => Op::uid() ) )
				->allow( $may_invite )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin, $contacts ): array {
						$row    = $plugin->service( 'contacts' )->load( $actor, (string) $in['uid'], Policy::UPDATE );
						$member = $contacts()->member_of( $row );
						if ( ! $member || 'invited' !== (string) $member['status'] ) {
							throw ActionError::conflict( __( 'There is no invitation waiting for this person.', 'cliezoo' ) );
						}
						$plugin->get( Invitations::class )->resend( $actor, (string) $member['uid'] );
						// A new link: the contact's clock on it starts again too.
						$plugin->service( 'contacts' )->repository()->update( (int) $row['id'], array( 'invited_at' => gmdate( 'Y-m-d H:i:s' ) ) );
						return array( 'sent' => true );
					}
				)
				->rest( 'POST', self::ONE . '/invite/resend' )
		);

		$registry->add(
			Op::make( 'contacts.invite_cancel', __( 'Cancel the invitation', 'cliezoo' ) )
				->describe( __( 'Withdraws an invitation nobody has accepted. The contact stays; only the way in goes.', 'cliezoo' ) )
				->input( array( 'uid' => Op::uid() ) )
				->allow( $may_invite )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin, $contacts ): array {
						$row = $plugin->service( 'contacts' )->load( $actor, (string) $in['uid'], Policy::UPDATE );
						return $contacts()->cancel_invite( $actor, $row );
					}
				)
				->rest( 'POST', self::ONE . '/invite/cancel' )
				->with( array( 'destructive' => true ) )
		);

		$registry->add(
			Op::make( 'contacts.portal', __( 'Turn one person\'s portal access on or off', 'cliezoo' ) )
				->describe( __( 'Off signs them out everywhere and stops them signing in again. Nothing is deleted.', 'cliezoo' ) )
				->input(
					array(
						'uid'    => Op::uid(),
						'active' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					)
				)
				->allow( $may_invite )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin, $contacts ): array {
						$row = $plugin->service( 'contacts' )->load( $actor, (string) $in['uid'], Policy::UPDATE );
						return $contacts()->set_portal( $actor, $row, (bool) $in['active'] );
					}
				)
				->rest( 'POST', self::ONE . '/portal' )
		);

		$registry->add(
			Op::make( 'contacts.import', __( 'Import contacts', 'cliezoo' ) )
				->describe( __( 'Takes rows from a spreadsheet. Nobody is invited and nothing is sent: it makes records, and you invite whoever you want afterwards.', 'cliezoo' ) )
				->input(
					array(
						'client' => Op::uid( false ),
						'rows'   => array(
							'type'     => 'array',
							'required' => true,
							'maxItems' => self::IMPORT_LIMIT,
							'items'    => array( 'type' => 'object' ),
						),
						'update' => array( 'type' => 'boolean' ),
					)
				)
				->allow( static fn( Actor $actor ): bool => $actor->is_staff() && ( $actor->is_admin() || $actor->allows( 'clients_edit' ) ) )
				->run(
					static fn( Actor $actor, array $in ): array => self::import( $plugin, $actor, $in )
				)
				->rest( 'POST', '/contacts/import' )
		);
	}

	/**
	 * Creates (or corrects) one row per person.
	 *
	 * An import that stops on the first bad row is useless with real data, so
	 * every row is tried and the answer says exactly which ones did not work
	 * and why. Nothing is sent to anybody: an import is not an invitation.
	 *
	 * @param Plugin               $plugin Plugin.
	 * @param Actor                $actor  Who is importing.
	 * @param array<string, mixed> $in     client, rows, update.
	 * @return array<string, mixed>
	 * @throws ActionError When the named client cannot be used.
	 */
	private static function import( Plugin $plugin, Actor $actor, array $in ): array {
		$service = $plugin->service( 'contacts' );
		$client  = '' !== (string) ( $in['client'] ?? '' )
			? $plugin->service( 'clients' )->load( $actor, (string) $in['client'], Policy::UPDATE )
			: null;

		$created = 0;
		$updated = 0;
		$skipped = array();
		foreach ( array_values( (array) $in['rows'] ) as $index => $row ) {
			$row = is_array( $row ) ? $row : array();
			if ( $client ) {
				$row['client'] = (string) $client['uid'];
			}
			$email = sanitize_email( (string) ( $row['email'] ?? '' ) );
			if ( '' === trim( (string) ( $row['name'] ?? '' ) ) || ! is_email( $email ) ) {
				$skipped[] = array(
					'row'    => $index + 1,
					'reason' => __( 'A name and a valid email address are needed.', 'cliezoo' ),
				);
				continue;
			}
			$existing = $service->repository()->first( array( 'email' => $email ) );
			try {
				if ( $existing && ! empty( $in['update'] ) ) {
					$service->update( $actor, (string) $existing['uid'], $row );
					++$updated;
				} elseif ( $existing ) {
					$skipped[] = array(
						'row'    => $index + 1,
						'reason' => __( 'Somebody already has this email address.', 'cliezoo' ),
					);
				} else {
					$service->create( $actor, $row );
					++$created;
				}
			} catch ( ActionError $error ) {
				$skipped[] = array(
					'row'    => $index + 1,
					'reason' => $error->getMessage(),
				);
			}
		}

		do_action(
			'cliezoo_audit',
			'contacts_imported',
			array(
				'created' => $created,
				'updated' => $updated,
				'skipped' => count( $skipped ),
			)
		);

		return array(
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
		);
	}
}
