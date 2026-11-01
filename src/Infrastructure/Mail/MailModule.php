<?php
/**
 * Mail infrastructure module (email log, sender settings, templates API).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Mail;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Field;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email log per client/document, sender name/from-address, and the system
 * email template API (list, read, save, preview, test send, restore default).
 */
final class MailModule extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'mail';
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
				'email_log',
				array(
					'to_email'    => Field::email()->filterable(),
					'subject'     => Field::text( 255 )->searchable(),
					'template'    => Field::text( 60 )->filterable(),
					'category'    => Field::text( 30 )->default( 'system' )->filterable(),
					'status'      => Field::enum( array( 'queued', 'sent', 'failed', 'held', 'suppressed', 'discarded', 'scheduled' ) )->default( 'queued' )->filterable(),
					'error'       => Field::text( 255 ),
					'object_type' => Field::text( 40 ),
					'object_id'   => Field::int(),
					'client_id'   => Field::int(),
					'member_id'   => Field::int(),
					'body_text'   => Field::longtext(),
					// A scheduled email's formatted body, cleaned, until it goes.
					'body_html'   => Field::longtext()->hidden(),
					'sent_at'     => Field::datetime()->sortable(),
					'opened_at'   => Field::datetime(),
					'clicked_at'  => Field::datetime(),
					'bounced_at'  => Field::datetime(),
				)
			) )->with(
				array(
					'soft_delete' => false,
					'activity'    => false,
					'indexes'     => array(
						'object' => array( 'object_type', 'object_id', 'created_at' ),
						'client' => array( 'client_id', 'created_at' ),
						'status' => array( 'status', 'created_at' ),
					),
					'permission'  => 'email_log',
				)
			),
			// Every email that arrived, and what became of it (Inbound).
			( new Entity(
				'email_inbound',
				array(
					'from_email'  => Field::text( 190 )->filterable(),
					'subject'     => Field::text( 255 ),
					'message_id'  => Field::text( 255 ),
					'status'      => Field::enum( array( 'accepted', 'ignored', 'rejected' ) )->default( 'ignored' )->filterable(),
					'reason'      => Field::text( 60 ),
					'handler'     => Field::text( 40 ),
					'source'      => Field::text( 40 ),
					'object_type' => Field::text( 40 ),
					'object_id'   => Field::int(),
				)
			) )->with(
				array(
					'soft_delete' => false,
					'activity'    => false,
					'indexes'     => array( 'status' => array( 'status', 'created_at' ) ),
					'permission'  => 'email_log',
				)
			),
			// Addresses that asked not to get marketing email, with how, and
			// when and why any came back (MarketingConsent).
			( new Entity(
				'email_unsubscribes',
				array(
					'email'              => Field::email()->filterable(),
					'source'             => Field::enum( array( 'link', 'one-click', 'staff' ) )->default( 'link' ),
					'resubscribed_at'    => Field::datetime(),
					'resubscribed_by'    => Field::int(),
					'resubscribe_reason' => Field::text( 255 ),
				)
			) )->with(
				array(
					'soft_delete' => false,
					'activity'    => false,
					'indexes'     => array(
						'email' => array( 'email' ),
					),
					'permission'  => 'email_log',
				)
			),
		);
	}

	/**
	 * Registers services and actions.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( EmailTemplates::class, static fn(): EmailTemplates => new EmailTemplates( $plugin ) );
		$plugin->container()->set( Mailer::class, static fn(): Mailer => new Mailer( $plugin ) );
		$plugin->container()->set( Smtp::class, static fn(): Smtp => new Smtp( $plugin ) );
		$plugin->get( Mailer::class )->hooks();
		$plugin->container()->set( MarketingConsent::class, static fn(): MarketingConsent => new MarketingConsent( $plugin ) );
		$plugin->get( MarketingConsent::class )->hooks();
		$plugin->container()->set( Inbound::class, static fn(): Inbound => new Inbound( $plugin ) );
		$plugin->container()->set( ReplyByEmail::class, static fn(): ReplyByEmail => new ReplyByEmail( $plugin ) );
		$plugin->get( ReplyByEmail::class )->hooks();
		add_action(
			'cliezoo_register_routes',
			static function ( \Cliezoo\Core\Rest\RouteRegistry $routes ) use ( $plugin ): void {
				$routes->add(
					'/public/unsubscribe',
					array(
						'methods'             => 'POST',
						// The signed address is the credential: it can only ever
						// unsubscribe the address it names.
						'permission_callback' => '__return_true',
						'callback'            => static function ( \WP_REST_Request $request ) use ( $plugin ) {
							try {
								$how = 'One-Click' === (string) $request->get_param( 'List-Unsubscribe' ) ? 'one-click' : 'link';
								return new \WP_REST_Response( array( 'data' => $plugin->get( MarketingConsent::class )->from_link( (string) $request->get_param( 'e' ), (string) $request->get_param( 's' ), $how ) ), 200 );
							} catch ( ActionError $error ) {
								return $error->to_wp_error();
							}
						},
					)
				);
			}
		);
		// Every email WordPress sends while this is on, not only Cliezoo's:
		// somebody who filled in their own mail server meant their mail.
		add_action( 'phpmailer_init', static fn( $mailer ) => $plugin->get( Smtp::class )->configure( $mailer ) );
		// The password is a secret like any other: encrypted on the way in,
		// never returned, and re-keyed with the rest when the salts change.
		add_filter(
			'cliezoo_settings_before_save',
			static function ( array $values, string $module ) use ( $plugin ): array {
				if ( 'email' !== $module || empty( $values['smtp_pass'] ) ) {
					return $values;
				}
				$values['smtp_pass'] = $plugin->get( \Cliezoo\Infrastructure\Crypto\Vault::class )->encrypt( (string) $values['smtp_pass'], 'smtp_pass' );
				return $values;
			},
			10,
			2
		);
		add_filter(
			'cliezoo_secret_options',
			static function ( array $places ): array {
				$places[] = array( 'cliezoo_settings_email', 'smtp_pass', 'smtp_pass' );
				return $places;
			}
		);
		add_filter( 'cliezoo_entity_verbs', static fn( array $verbs, Entity $entity ): array => in_array( $entity->name, array( 'email_log', 'email_unsubscribes', 'email_inbound' ), true ) ? array() : $verbs, 10, 2 );
		add_action(
			'cliezoo_register_settings',
			static function ( Settings $settings ): void {
				$settings->register(
					'email',
					array(
						'sender_name'    => array(
							'type'      => 'string',
							'maxLength' => 120,
							'default'   => get_bloginfo( 'name' ),
						),
						'from_address'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'reply_to'       => array(
							'type'    => 'string',
							'default' => '',
						),
						// Whether a reply to a notification email becomes a
						// comment in its thread, once mail can reach the site.
						'reply_by_email' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					) + Smtp::schema()
				);
			}
		);
		add_action( 'cliezoo_register_actions', static fn( ActionRegistry $registry ) => self::actions( $registry, $plugin ) );
	}

	/**
	 * Registers actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	private static function actions( ActionRegistry $registry, Plugin $plugin ): void {
		$templates = static fn(): EmailTemplates => $plugin->get( EmailTemplates::class );
		$manage    = static fn( Actor $a ): bool => $a->is_admin() || $a->allows( 'email_templates' );
		$key       = array(
			'type'     => 'string',
			'pattern'  => '^[a-z0-9_]{2,60}$',
			'required' => true,
		);
		$locale    = array(
			'type'    => 'string',
			'pattern' => '^([a-z]{2,3}(_[A-Z]{2})?)?$',
			'default' => '',
		);

		$consent = static fn(): MarketingConsent => $plugin->get( MarketingConsent::class );
		$staff   = static fn( Actor $a ): bool => $a->is_staff() && $a->can_write();
		$address = array(
			'type'      => 'string',
			'format'    => 'email',
			'maxLength' => 190,
			'required'  => true,
		);
		$registry->add(
			Op::make( 'email.marketing_status', __( 'Whether an address gets marketing email', 'cliezoo' ) )
				->input( array( 'email' => $address ) )
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run(
					static function ( Actor $a, array $in ) use ( $consent ): array {
						$row = $consent()->unsubscribed( (string) $in['email'] );
						return array(
							'unsubscribed' => null !== $row,
							'since'        => $row ? (string) $row['created_at'] : null,
							'how'          => $row ? (string) $row['source'] : null,
						);
					}
				)
				->rest( 'GET', '/email/marketing' )
				->readonly()
		);
		$registry->add(
			Op::make( 'email.marketing_unsubscribe', __( 'Stop marketing email to an address', 'cliezoo' ) )
				->describe( __( 'Because they asked a person rather than clicking the link. Invoices, documents and receipts still go.', 'cliezoo' ) )
				->input( array( 'email' => $address ) )
				->allow( $staff )
				->run( static fn( Actor $a, array $in ): array => $consent()->by_staff( $a, (string) $in['email'] ) )
				->rest( 'POST', '/email/marketing/unsubscribe' )
		);
		$registry->add(
			Op::make( 'email.marketing_resubscribe', __( 'Put an address back on the marketing list', 'cliezoo' ) )
				->describe( __( 'Only because they asked to be: how they asked is kept with the record of when they left.', 'cliezoo' ) )
				->input(
					array(
						'email'  => $address,
						'reason' => Op::str( 255, true ),
					)
				)
				->allow( $staff )
				->run( static fn( Actor $a, array $in ): array => $consent()->resubscribe( $a, (string) $in['email'], (string) $in['reason'] ) )
				->rest( 'POST', '/email/marketing/resubscribe' )
				->with( array( 'ability' => false ) )
		);

		$registry->add(
			Op::make( 'email.inbound', __( 'Email that arrived', 'cliezoo' ) )
				->describe( __( 'Every email the site received, newest first, and what became of it: taken into a thread, ignored as an auto-reply or a list, or refused as spam or from somebody not allowed.', 'cliezoo' ) )
				->input( Op::paging( 50 ) )
				->allow( static fn( Actor $a ): bool => $a->is_admin() )
				->run(
					static fn( Actor $a, array $in ): array => $plugin->service( 'email_inbound' )->repository()->where( array(), '-id', max( 1, min( 200, (int) ( $in['limit'] ?? 50 ) ) ) )
				)
				->rest( 'GET', '/email/inbound' )
				->readonly()
		);

		$registry->add(
			Op::make( 'email.domain_check', __( 'Check the sending domain', 'cliezoo' ) )
				->describe( __( 'Reads the SPF, DMARC and DKIM records of the domain email is sent from, and says what is missing. A DNS lookup only; nothing is sent.', 'cliezoo' ) )
				->input( array( 'selector' => Op::str( 63 ) ) )
				->allow( static fn( Actor $a ): bool => $a->is_admin() )
				->run(
					static fn( Actor $a, array $in ): array => ( new EmailDomain() )->check(
						(string) $plugin->settings()->get( 'email', 'from_address', '' ),
						(string) ( $in['selector'] ?? '' )
					)
				)
				->rest( 'POST', '/settings/email/domain/check' )
				->readonly()
		);

		$registry->add(
			Op::make( 'email.smtp_check', __( 'Try the mail server', 'cliezoo' ) )
				->describe( __( 'Connects to the workspace\'s own mail server and signs in, without sending anything to anybody.', 'cliezoo' ) )
				->allow( static fn( Actor $a ): bool => $a->is_admin() )
				->run( static fn(): array => $plugin->get( Smtp::class )->check() )
				->rest( 'POST', '/settings/email/smtp/check' )
		);

		$registry->add(
			Op::make( 'email_templates.list', __( 'System email templates', 'cliezoo' ) )
				->allow( $manage )
				->run(
					static function () use ( $templates ): array {
						$out = array();
						foreach ( $templates()->defaults() as $id => $template ) {
							$out[] = array(
								'key'       => $id,
								'group'     => (string) ( $template['group'] ?? '' ),
								'label'     => (string) ( $template['label'] ?? $id ),
								'variables' => (array) ( $template['variables'] ?? array() ),
							);
						}
						return $out;
					}
				)
				->rest( 'GET', '/email-templates' )
				->readonly()
		);
		$registry->add(
			Op::make( 'email_templates.get', __( 'Read an email template', 'cliezoo' ) )
				->input(
					array(
						'key'    => $key,
						'locale' => $locale,
					)
				)
				->allow( $manage )
				->run(
					static function ( Actor $a, array $in ) use ( $templates ): array {
						if ( ! isset( $templates()->defaults()[ $in['key'] ] ) ) {
							throw ActionError::not_found();
						}
						$template = $templates()->get( (string) $in['key'], (string) ( $in['locale'] ?? '' ) );
						return array_intersect_key( $template, array_flip( array_merge( EmailTemplates::PARTS, array( 'label', 'group', 'variables' ) ) ) ) + array( 'key' => $in['key'] );
					}
				)
				->rest( 'GET', '/email-templates/(?P<key>[a-z0-9_]{2,60})' )
				->readonly()
		);
		$registry->add(
			Op::make( 'email_templates.save', __( 'Save an email template', 'cliezoo' ) )
				->input(
					array(
						'key'    => $key,
						'locale' => $locale,
						'parts'  => array(
							'type'     => 'object',
							'required' => true,
						),
					)
				)
				->allow( $manage )
				->run(
					static function ( Actor $a, array $in ) use ( $templates ): array {
						if ( ! isset( $templates()->defaults()[ $in['key'] ] ) ) {
							throw ActionError::not_found();
						}
						return $templates()->save( (string) $in['key'], (array) $in['parts'], (string) ( $in['locale'] ?? '' ) );
					}
				)
				->rest( 'PUT', '/email-templates/(?P<key>[a-z0-9_]{2,60})' )
		);
		$registry->add(
			Op::make( 'email_templates.restore', __( 'Restore the default email', 'cliezoo' ) )
				->input(
					array(
						'key'    => $key,
						'locale' => $locale,
					)
				)
				->allow( $manage )
				->run( static fn( Actor $a, array $in ) => $templates()->restore( (string) $in['key'], (string) ( $in['locale'] ?? '' ) ) )
				->rest( 'POST', '/email-templates/(?P<key>[a-z0-9_]{2,60})/restore' )
		);
		$registry->add(
			Op::make( 'email_templates.preview', __( 'Preview an email template', 'cliezoo' ) )
				->input(
					array(
						'key'       => $key,
						'locale'    => $locale,
						'parts'     => array( 'type' => 'object' ),
						'as_client' => array( 'type' => 'boolean' ),
						'send_test' => array( 'type' => 'boolean' ),
					)
				)
				->allow( $manage )
				->run(
					static function ( Actor $a, array $in ) use ( $plugin, $templates ): array {
						$sample              = self::sample_data( $templates()->get( (string) $in['key'] ) );
						$sample['is_client'] = ! empty( $in['as_client'] );
						$meta                = array( 'locale' => (string) ( $in['locale'] ?? '' ) );
						foreach ( (array) ( $in['parts'] ?? array() ) as $part => $value ) {
							if ( in_array( $part, array( 'subject', 'body', 'greeting', 'closing' ), true ) ) {
								$meta[ 'override_' . $part ] = (string) $value;
							}
						}
						$message = $plugin->get( Mailer::class )->render( (string) $in['key'], $sample, $meta );
						if ( ! empty( $in['send_test'] ) ) {
							$member               = $plugin->service( 'members' )->repository()->find( $a->member_id );
							$sent                 = $member && ! empty( $member['email'] ) && $plugin->get( Mailer::class )->send( (string) $member['email'], '[' . __( 'Test', 'cliezoo' ) . '] ' . $message['subject'], $message['html'], $message['text'], array( 'category' => 'test' ) );
							$message['test_sent'] = $sent;
						}
						return $message;
					}
				)
				->rest( 'POST', '/email-templates/(?P<key>[a-z0-9_]{2,60})/preview' )
		);
		$registry->add(
			Op::make( 'email_log.list', __( 'Email log', 'cliezoo' ) )
				->input(
					array(
						'object_type' => array(
							'type'    => 'string',
							'pattern' => '^[a-z_]{2,40}$',
						),
						'object'      => Op::uid( false ),
						'client'      => Op::uid( false ),
						'status'      => Op::str( 20 ),
					) + Op::paging( 50 )
				)
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run( static fn( Actor $a, array $in ) => ( new EmailLog( $plugin ) )->list( $a, $in ) )
				->rest( 'GET', '/email-log' )
				->readonly()
		);
	}

	/**
	 * Sample values for previews: every declared variable gets a readable example.
	 *
	 * @param array<string, mixed> $template Template.
	 * @return array<string, mixed>
	 */
	private static function sample_data( array $template ): array {
		$sample = array(
			'recipient_name' => __( 'Alex Morgan', 'cliezoo' ),
			'sender_name'    => wp_get_current_user()->display_name,
			'sender_email'   => wp_get_current_user()->user_email,
			'title'          => __( 'Example notification', 'cliezoo' ),
			'body'           => __( 'This is how the message will look.', 'cliezoo' ),
			'url'            => home_url( '/dash/' ),
			'count'          => 3,
			'items'          => array(
				array(
					'title' => __( 'Example item', 'cliezoo' ),
					'url'   => home_url( '/dash/' ),
				),
			),
		);
		foreach ( (array) ( $template['variables'] ?? array() ) as $variable ) {
			if ( ! isset( $sample[ $variable ] ) && false === strpos( (string) $variable, '.' ) ) {
				$sample[ $variable ] = '[' . $variable . ']';
			}
		}
		/**
		 * Sample data for email previews (modules add invoice/document samples).
		 *
		 * @param array $sample   Sample values.
		 * @param array $template Template.
		 */
		return (array) apply_filters( 'cliezoo_email_sample_data', $sample, $template );
	}
}
