<?php
/**
 * System email templates (defaults + saved edits).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Mail;

use Cliezoo\Core\Data\Html;
use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every system email is a structured template (ClientManager's six parts +
 * Kitchen's separate team/client subjects): subject, subject_client,
 * greeting, body, closing, button_label, button_url, card, hero_image.
 * Modules register defaults with `cliezoo_email_templates`; edits are stored
 * in the templates table (type "email") and can be restored to default.
 */
final class EmailTemplates {

	public const PARTS = array( 'subject', 'subject_client', 'greeting', 'body', 'closing', 'button_label', 'button_url', 'hero_image', 'card' );

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Defaults cache.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $defaults = null;

	/**
	 * The locale the cached defaults were written in: an email rendered in
	 * a document's language needs its wording in that language, not in the
	 * one the first email of the request happened to use.
	 *
	 * @var string
	 */
	private string $defaults_for = '';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registered templates with defaults.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function defaults(): array {
		$locale = determine_locale();
		if ( null === $this->defaults || $this->defaults_for !== $locale ) {
			$this->defaults_for = $locale;
			$base               = array(
				'notification'           => array(
					'group'        => __( 'Messaging', 'cliezoo' ),
					'label'        => __( 'Notification', 'cliezoo' ),
					'subject'      => '{{title}}',
					/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
					'greeting'     => __( 'Hi {{recipient_name}},', 'cliezoo' ),
					'body'         => '<p>{{title}}</p>{{#if body}}<p>{{body}}</p>{{/if}}',
					'button_label' => __( 'Open', 'cliezoo' ),
					'button_url'   => '{{url}}',
					'variables'    => array( 'recipient_name', 'title', 'body', 'url' ),
				),
				'mention'                => array(
					'group'        => __( 'Messaging', 'cliezoo' ),
					'label'        => __( 'Somebody mentioned you', 'cliezoo' ),
					/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
					'subject'      => __( 'You were mentioned: {{title}}', 'cliezoo' ),
					/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
					'greeting'     => __( 'Hi {{recipient_name}},', 'cliezoo' ),
					// Somebody who was named is being asked something, which is
					// not the same as being copied in.
					/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
					'body'         => '<p>' . __( 'Somebody mentioned you by name, so this one is probably waiting on you.', 'cliezoo' ) . '</p>{{#if body}}<blockquote>{{body}}</blockquote>{{/if}}',
					'button_label' => __( 'Have a look', 'cliezoo' ),
					'button_url'   => '{{url}}',
					'variables'    => array( 'recipient_name', 'title', 'body', 'url' ),
				),
				'file_comment'           => array(
					'group'        => __( 'Tasks and files', 'cliezoo' ),
					'label'        => __( 'Comment on a file', 'cliezoo' ),
					/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
					'subject'      => __( 'A comment on {{title}}', 'cliezoo' ),
					/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
					'greeting'     => __( 'Hi {{recipient_name}},', 'cliezoo' ),
					// The comment itself is in the body, because a
					// notification that makes somebody open a browser to
					// find out what was said is a notification that gets
					// turned off.
					/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
					'body'         => '{{#if body}}<p>{{body}}</p>{{/if}}<p>' . __( 'On: {{title}}', 'cliezoo' ) . '</p>',
					'button_label' => __( 'Open the file', 'cliezoo' ),
					'button_url'   => '{{url}}',
					'variables'    => array( 'recipient_name', 'title', 'body', 'url' ),
				),
				'file_approved'          => array(
					'group'        => __( 'Tasks and files', 'cliezoo' ),
					'label'        => __( 'File approved', 'cliezoo' ),
					/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
					'subject'      => __( 'Approved: {{title}}', 'cliezoo' ),
					/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
					'greeting'     => __( 'Hi {{recipient_name}},', 'cliezoo' ),
					/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
					'body'         => '<p>' . __( '{{title}} has been approved.', 'cliezoo' ) . '</p>{{#if body}}<p>{{body}}</p>{{/if}}',
					'button_label' => __( 'Open the file', 'cliezoo' ),
					'button_url'   => '{{url}}',
					'variables'    => array( 'recipient_name', 'title', 'body', 'url' ),
				),
				'notification_digest'    => array(
					'group'        => __( 'Messaging', 'cliezoo' ),
					'label'        => __( 'Notification digest', 'cliezoo' ),
					/* translators: %s: count placeholder. */
					'subject'      => sprintf( __( 'Your update: %s new notifications', 'cliezoo' ), '{{count}}' ),
					/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
					'greeting'     => __( 'Hi {{recipient_name}},', 'cliezoo' ),
					'body'         => '{{#if summary}}<p>{{summary}}</p>{{/if}}<p>' . __( 'Here is what happened while you were away:', 'cliezoo' ) . '</p><ul>{{#each items}}<li><a href="{{url}}">{{title}}</a>{{#if body}} — {{body}}{{/if}}</li>{{/each}}</ul>',
					'button_label' => __( 'Open inbox', 'cliezoo' ),
					'button_url'   => '{{workspace.url}}inbox',
					'variables'    => array( 'recipient_name', 'count', 'items' ),
				),

				// Booking and form emails. Every one of these keys was already
				// being sent by live code with no template behind it, so the
				// person who booked a meeting got an empty email from the
				// studio. An unknown key returns an empty merge and the mailer
				// sends it anyway, which is why nothing complained.
			);
			/**
			 * Register system email templates: key => [group, label, subject, subject_client?, greeting, body, closing?, button_label?, button_url?, card?, variables[]].
			 *
			 * @param array $templates Templates.
			 */
			$this->defaults = (array) apply_filters( 'cliezoo_email_templates', $base );
		}
		return $this->defaults;
	}

	/**
	 * The effective template for a key and language (saved edits over defaults).
	 *
	 * @param string $key    Template key.
	 * @param string $locale Language ('' = default version).
	 * @return array<string, mixed>
	 */
	public function get( string $key, string $locale = '' ): array {
		$default = $this->defaults()[ $key ] ?? array();
		$saved   = $this->saved( $key, $locale );
		if ( ! $saved && '' !== $locale ) {
			$saved = $this->saved( $key, '' );
		}
		return array_merge( $default, $saved );
	}

	/**
	 * Whether the studio has written its own wording for a template, in any
	 * language.
	 *
	 * @param string $key Template key.
	 * @return bool
	 */
	public function is_edited( string $key ): bool {
		foreach ( $this->plugin->service( 'templates' )->repository()->where(
			array(
				'type'        => 'email',
				'builtin_key' => $key,
			),
			'id',
			20
		) as $row ) {
			if ( empty( $row['deleted_at'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Saved parts for a key.
	 *
	 * @param string $key    Key.
	 * @param string $locale Locale.
	 * @return array<string, mixed>
	 */
	private function saved( string $key, string $locale ): array {
		$row = $this->plugin->service( 'templates' )->repository()->first(
			array(
				'type'        => 'email',
				'builtin_key' => $key,
				'locale'      => '' !== $locale ? $locale : null,
			)
		);
		if ( ! $row || ! empty( $row['deleted_at'] ) ) {
			return array();
		}
		$content = json_decode( (string) $row['content'], true );
		return is_array( $content ) ? array_intersect_key( $content, array_flip( self::PARTS ) ) : array();
	}

	/**
	 * Saves edited parts (sanitised; limits from ClientManager: subject 200, body 5,000, button 100).
	 *
	 * @param string               $key    Template key.
	 * @param array<string, mixed> $parts  Parts.
	 * @param string               $locale Locale ('' = default).
	 * @return array<string, mixed> Effective template.
	 */
	public function save( string $key, array $parts, string $locale = '' ): array {
		$clean = array();
		foreach ( self::PARTS as $part ) {
			if ( ! array_key_exists( $part, $parts ) ) {
				continue;
			}
			switch ( $part ) {
				case 'subject':
				case 'subject_client':
					$clean[ $part ] = mb_substr( sanitize_text_field( (string) $parts[ $part ] ), 0, 200 );
					break;
				case 'button_label':
					$clean[ $part ] = mb_substr( sanitize_text_field( (string) $parts[ $part ] ), 0, 100 );
					break;
				case 'body':
					$clean[ $part ] = mb_substr( Html::clean( (string) $parts[ $part ] ), 0, 5000 );
					break;
				case 'card':
					$clean[ $part ] = array_values(
						array_map(
							static fn( $row ): array => array(
								'label' => mb_substr( sanitize_text_field( (string) ( $row['label'] ?? '' ) ), 0, 100 ),
								'value' => mb_substr( sanitize_text_field( (string) ( $row['value'] ?? '' ) ), 0, 200 ),
							),
							(array) $parts[ $part ]
						)
					);
					break;
				case 'hero_image':
				case 'button_url':
					$clean[ $part ] = mb_substr( sanitize_text_field( (string) $parts[ $part ] ), 0, 500 );
					break;
				default:
					$clean[ $part ] = mb_substr( Html::clean( (string) $parts[ $part ] ), 0, 1000 );
			}
		}
		$repo  = $this->plugin->service( 'templates' )->repository();
		$where = array(
			'type'        => 'email',
			'builtin_key' => $key,
			'locale'      => '' !== $locale ? $locale : null,
		);
		$row   = $repo->first( $where );
		$data  = array(
			'name'    => (string) ( $this->defaults()[ $key ]['label'] ?? $key ),
			'content' => wp_json_encode( array_merge( $row ? (array) json_decode( (string) $row['content'], true ) : array(), $clean ) ),
		);
		if ( $row ) {
			$repo->update( (int) $row['id'], $data );
		} else {
			$repo->insert( $data + $where + array( 'is_builtin' => 0 ) );
		}
		return $this->get( $key, $locale );
	}

	/**
	 * Restores the default (removes saved edits).
	 *
	 * @param string $key    Template key.
	 * @param string $locale Locale.
	 * @return array<string, mixed>
	 */
	public function restore( string $key, string $locale = '' ): array {
		$repo = $this->plugin->service( 'templates' )->repository();
		$row  = $repo->first(
			array(
				'type'        => 'email',
				'builtin_key' => $key,
				'locale'      => '' !== $locale ? $locale : null,
			)
		);
		if ( $row ) {
			$repo->delete( (int) $row['id'] );
		}
		return $this->get( $key, $locale );
	}
}
