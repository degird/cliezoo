<?php
/**
 * Invitation and ownership actions and routes.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Members;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\Rest\RouteRegistry;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /members/invite`, `/members/{uid}/invite/resend|revoke`,
 * `POST /members/transfer-ownership`, and the public
 * `POST /auth/invite/info|accept` used by the accept page.
 */
final class TeamActions {

	/**
	 * Hooks.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return void
	 */
	public static function hooks( Plugin $plugin ): void {
		$plugin->container()->set( Invitations::class, static fn(): Invitations => new Invitations( $plugin ) );
		add_action( 'cliezoo_register_actions', static fn( ActionRegistry $registry ) => self::actions( $registry, $plugin ) );
		add_action( 'cliezoo_register_routes', static fn( RouteRegistry $routes ) => self::routes( $routes, $plugin ) );
		add_filter( 'cliezoo_email_templates', array( self::class, 'email_templates' ) );
	}

	/**
	 * Actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	public static function actions( ActionRegistry $registry, Plugin $plugin ): void {
		$invites = static fn(): Invitations => $plugin->get( Invitations::class );
		$registry->add(
			Op::make( 'members.invite', __( 'Invite a person', 'cliezoo' ) )
				->input(
					array(
						'email'   => array(
							'type'      => 'string',
							'format'    => 'email',
							'maxLength' => 190,
							'required'  => true,
						),
						'name'    => Op::str( 190 ),
						'role'    => array(
							'type' => 'string',
							'enum' => array_values( array_merge( array_diff( Members::staff_roles(), array( 'owner' ) ), Roles::CLIENT_ROLES ) ),
						),
						'kind'    => array(
							'type'    => 'string',
							'enum'    => array( 'staff', 'client' ),
							'default' => 'staff',
						),
						'message' => Op::str( 1000 ),
					)
				)
				->allow( static fn( Actor $a, array $in ): bool => Invitations::can_invite( $a, (string) ( $in['kind'] ?? 'staff' ) ) )
				->run( static fn( Actor $a, array $in ): array => $invites()->invite( $a, $in ) )
				->rest( 'POST', '/members/invite' )
		);
		$registry->add(
			Op::make( 'members.invite_resend', __( 'Send an invitation again', 'cliezoo' ) )
				->input( array( 'uid' => Op::uid() ) )
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run( static fn( Actor $a, array $in ): array => $invites()->resend( $a, (string) $in['uid'] ) )
				->rest( 'POST', '/members/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/invite/resend' )
		);
		$registry->add(
			Op::make( 'members.invite_revoke', __( 'Cancel an invitation', 'cliezoo' ) )
				->input( array( 'uid' => Op::uid() ) )
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run( static fn( Actor $a, array $in ): array => $invites()->revoke( $a, (string) $in['uid'] ) )
				->rest( 'POST', '/members/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/invite/revoke' )
				->with( array( 'destructive' => true ) )
		);
		$registry->add(
			Op::make( 'members.transfer_ownership', __( 'Transfer workspace ownership', 'cliezoo' ) )
				->input(
					array(
						'uid'      => Op::uid(),
						'password' => Op::str( 200, true ),
					)
				)
				->allow( static fn( Actor $a ): bool => 'owner' === $a->role )
				->run( static fn( Actor $a, array $in ): array => $invites()->transfer_ownership( $a, (string) $in['uid'], (string) $in['password'] ) )
				->rest( 'POST', '/members/transfer-ownership' )
				->with( array( 'destructive' => true ) )
		);
	}

	/**
	 * Public routes for the accept page (token checked and rate-limited inside).
	 *
	 * @param RouteRegistry $routes Routes.
	 * @param Plugin        $plugin Plugin.
	 * @return void
	 */
	public static function routes( RouteRegistry $routes, Plugin $plugin ): void {
		$invites = static fn(): Invitations => $plugin->get( Invitations::class );
		$public  = array(
			'/auth/invite/info'   => static fn( array $in ): array => $invites()->info( (string) ( $in['token'] ?? '' ) ),
			'/auth/invite/accept' => static fn( array $in ): array => $invites()->accept( (string) ( $in['token'] ?? '' ), (string) ( $in['name'] ?? '' ), (string) ( $in['password'] ?? '' ) ),
		);
		foreach ( $public as $path => $callback ) {
			$routes->add(
				$path,
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true', // Public endpoint: the invite token is the credential.
					'callback'            => static function ( \WP_REST_Request $request ) use ( $callback ) {
						try {
							return new \WP_REST_Response( array( 'data' => $callback( (array) $request->get_json_params() + (array) $request->get_body_params() ) ), 200 );
						} catch ( ActionError $error ) {
							return $error->to_wp_error();
						}
					},
				)
			);
		}
	}

	/**
	 * Invitation email.
	 *
	 * @param array<string, array<string, mixed>> $templates Templates.
	 * @return array<string, array<string, mixed>>
	 */
	public static function email_templates( array $templates ): array {
		$templates['member_invite'] = array(
			'group'        => __( 'Portal and account', 'cliezoo' ),
			'label'        => __( 'Invitation', 'cliezoo' ),
			/* translators: %s: workspace name placeholder. */
			'subject'      => sprintf( __( 'You are invited to %s', 'cliezoo' ), '{{workspace.name}}' ),
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'greeting'     => __( 'Hi {{recipient_name}},', 'cliezoo' ),
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'body'         => '<p>{{#if inviter_name}}' . __( '{{inviter_name}} invited you to join {{workspace.name}}.', 'cliezoo' ) . '{{else}}' . __( 'You are invited to join {{workspace.name}}.', 'cliezoo' ) . '{{/if}}</p>{{#if message}}<p>{{message}}</p>{{/if}}<p>' . __( 'The invitation link works once and expires in 7 days.', 'cliezoo' ) . '</p>',
			'button_label' => __( 'Accept invitation', 'cliezoo' ),
			'button_url'   => '{{url}}',
			'variables'    => array( 'recipient_name', 'inviter_name', 'message', 'url', 'workspace.name' ),
		);
		// A client is not joining the studio; they are being given a way in to
		// their own work. One template for both audiences meant a studio
		// rewording "join our workspace" for a client and breaking it for the
		// team in the same edit.
		$templates['client_invite'] = array(
			'group'        => __( 'Portal and account', 'cliezoo' ),
			'label'        => __( 'Client invitation', 'cliezoo' ),
			/* translators: %s: workspace name placeholder. */
			'subject'      => sprintf( __( 'Your access to %s', 'cliezoo' ), '{{workspace.name}}' ),
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'greeting'     => __( 'Hi {{recipient_name}},', 'cliezoo' ),
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'body'         => '<p>{{#if inviter_name}}' . __( '{{inviter_name}} has set up a place where you can follow your work with {{workspace.name}} — files, invoices, messages and what is happening next, all in one place.', 'cliezoo' ) . '{{else}}' . __( 'There is now a place where you can follow your work with {{workspace.name}} — files, invoices, messages and what is happening next, all in one place.', 'cliezoo' ) . '{{/if}}</p>{{#if message}}<p>{{message}}</p>{{/if}}<p>' . __( 'The link works once and expires in 7 days.', 'cliezoo' ) . '</p>',
			'button_label' => __( 'Set up your access', 'cliezoo' ),
			'button_url'   => '{{url}}',
			'variables'    => array( 'recipient_name', 'inviter_name', 'message', 'url', 'workspace.name' ),
		);

		// And once they are in. Nothing said so before: an invitation was
		// accepted and the next thing anybody heard from Cliezoo was a
		// notification about something else.
		$templates['welcome'] = array(
			'group'        => __( 'Portal and account', 'cliezoo' ),
			'label'        => __( 'Welcome', 'cliezoo' ),
			/* translators: %s: workspace name placeholder. */
			'subject'      => sprintf( __( 'Welcome to %s', 'cliezoo' ), '{{workspace.name}}' ),
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'greeting'     => __( 'Hi {{recipient_name}},', 'cliezoo' ),
			// One thing to do rather than a tour. Somebody who has just set a
			// password wants to know they are in and where to start.
			/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
			'body'         => '<p>' . __( 'You are all set. This is the address to come back to, so it is worth keeping.', 'cliezoo' ) . '</p>{{#if is_client}}<p>' . __( 'Your files, invoices and messages are all in there, and you can ask for something new whenever you need to.', 'cliezoo' ) . '</p>{{else}}<p>' . __( 'Your work, the clients you look after and what is due are all in there.', 'cliezoo' ) . '</p>{{/if}}',
			'button_label' => __( 'Open it', 'cliezoo' ),
			'button_url'   => '{{url}}',
			'variables'    => array( 'recipient_name', 'url', 'is_client', 'workspace.name' ),
		);
		return $templates;
	}
}
