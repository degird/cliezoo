<?php
/**
 * Sends branded email through wp_mail() and logs it.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Mail;

use Cliezoo\Core\Logger;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Jobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All email goes through wp_mail() so any SMTP plugin applies (Blueprint §8.8).
 * Every send is logged (sent / failed / held in Safe mode) — `wp_mail` fails
 * silently on many hosts, so the log is the source of truth (§31.4).
 */
final class Mailer {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Last wp_mail error (captured from the wp_mail_failed hook).
	 *
	 * @var string
	 */
	private string $last_error = '';

	/**
	 * Plain-text alternative for the message being sent.
	 *
	 * @var string
	 */
	private string $alt_body = '';

	/**
	 * The Message-ID for the email being sent, when it has one of its own
	 * (a notification a reply can be matched to).
	 *
	 * @var string
	 */
	private string $message_id = '';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks the queue job.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action(
			'cliezoo_job_send_email',
			function ( array $args ): void {
				$this->send_template( (string) $args['template'], (string) $args['to'], (array) $args['data'], (array) ( $args['meta'] ?? array() ) );
			}
		);
		add_action( 'cliezoo_safe_mode_released', array( $this, 'release_held' ) );
	}

	/**
	 * Queues a templated email (sent by the job runner, not in the request).
	 *
	 * @param string               $template Template key.
	 * @param string               $to       Recipient.
	 * @param array<string, mixed> $data     Variables (no secrets).
	 * @param array<string, mixed> $meta     Log meta (object_type, object_id, client_id, member_id, category, cc, attachments).
	 * @param int                  $delay    Delay in seconds (scheduled send).
	 * @return int Job id.
	 */
	public function queue( string $template, string $to, array $data, array $meta = array(), int $delay = 0 ): int {
		return $this->plugin->get( Jobs::class )->queue(
			'send_email',
			array(
				'template' => $template,
				'to'       => $to,
				'data'     => $data,
				'meta'     => $meta,
			),
			$delay
		);
	}

	/**
	 * Renders a template for a recipient.
	 *
	 * @param string               $key    Template key.
	 * @param array<string, mixed> $data   Variables.
	 * @param array<string, mixed> $meta   Meta (locale, subject/message overrides from the per-send composer).
	 * @return array{subject: string, html: string, text: string}
	 */
	public function render( string $key, array $data, array $meta = array() ): array {
		// Written in the language it is going out in — a document's own, say —
		// rather than the site's: the wording comes from the translations of
		// that locale while it is rendered, and the site's comes back after.
		$locale   = (string) ( $meta['locale'] ?? '' );
		$switched = '' !== $locale && determine_locale() !== $locale && in_array( $locale, array_merge( array( 'en_US' ), get_available_languages() ), true )
			&& switch_to_locale( $locale );
		try {
			return $this->render_in( $key, $data, $meta );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Renders a template in whatever locale is current.
	 *
	 * @param string               $key  Template key.
	 * @param array<string, mixed> $data Variables.
	 * @param array<string, mixed> $meta Meta.
	 * @return array{subject: string, html: string, text: string}
	 */
	private function render_in( string $key, array $data, array $meta ): array {
		$template = $this->plugin->get( EmailTemplates::class )->get( $key, (string) ( $meta['locale'] ?? '' ) );
		$context  = $this->context( $data );
		foreach ( array( 'subject', 'body', 'greeting', 'closing' ) as $part ) {
			if ( isset( $meta[ 'override_' . $part ] ) && '' !== $meta[ 'override_' . $part ] ) {
				$template[ $part ] = $meta[ 'override_' . $part ];
			}
		}
		$is_client = ! empty( $context['isClient'] );
		$subject   = $is_client && ! empty( $template['subject_client'] ) ? (string) $template['subject_client'] : (string) ( $template['subject'] ?? '' );
		$subject   = wp_strip_all_tags( TemplateEngine::render( $subject, $context, false ) );

		$parts = array(
			'greeting'     => TemplateEngine::render( (string) ( $template['greeting'] ?? '' ), $context ),
			'body'         => TemplateEngine::render( (string) ( $template['body'] ?? '' ), $context ),
			'closing'      => TemplateEngine::render( (string) ( $template['closing'] ?? '' ), $context ),
			'button_label' => TemplateEngine::render( (string) ( $template['button_label'] ?? '' ), $context, false ),
			'button_url'   => TemplateEngine::render( (string) ( $template['button_url'] ?? '' ), $context, false ),
			'hero_image'   => TemplateEngine::render( (string) ( $template['hero_image'] ?? '' ), $context, false ),
			'card'         => array_map(
				static fn( array $row ): array => array(
					'label' => TemplateEngine::render( (string) $row['label'], $context, false ),
					'value' => TemplateEngine::render( (string) $row['value'], $context, false ),
				),
				(array) ( $template['card'] ?? array() )
			),
		);
		$html  = EmailLayout::render( $parts, $context['branding'], $subject );
		$text  = EmailLayout::text( $parts );
		return compact( 'subject', 'html', 'text' );
	}

	/**
	 * Variables available to every template.
	 *
	 * @param array<string, mixed> $data Template data.
	 * @return array<string, mixed>
	 */
	public function context( array $data ): array {
		$settings  = $this->plugin->settings();
		$workspace = $settings->all( 'workspace' );
		$branding  = $settings->all( 'branding' );
		$is_client = ! empty( $data['is_client'] );
		/**
		 * The app base URL.
		 *
		 * @param string $url URL.
		 * @param string $for Who the link is for: 'client', or '' for anyone.
		 */
		$app_url = (string) apply_filters( 'cliezoo_app_url', home_url( '/dash/' ), $is_client ? 'client' : '' );
		return array_merge(
			array(
				'workspace'    => array(
					'name'     => (string) ( $workspace['name'] ?? get_bloginfo( 'name' ) ),
					'url'      => trailingslashit( $app_url ),
					'currency' => (string) ( $workspace['currency'] ?? 'USD' ),
				),
				'branding'     => array(
					'logo'              => (string) ( $branding['email_logo'] ?? $branding['logo_light'] ?? '' ),
					'accent'            => (string) ( $branding['accent'] ?? '#0458FB' ),
					'text_color'        => (string) ( $branding['email_text_color'] ?? '#0F172A' ),
					'signature_enabled' => (bool) ( $branding['email_signature_enabled'] ?? true ),
					'signature'         => (string) ( $branding['email_signature'] ?? '' ),
					'rounded'           => (bool) ( $branding['rounded'] ?? true ),
					'powered_by'        => (bool) ( $workspace['powered_by'] ?? false ),
					'workspace_name'    => (string) ( $workspace['name'] ?? get_bloginfo( 'name' ) ),
				),
				'isClient'     => $is_client,
				'isTeamMember' => ! $is_client,
				'today'        => wp_date( get_option( 'date_format' ) ),
			),
			$data
		);
	}

	/**
	 * Renders and sends a template.
	 *
	 * @param string               $key  Template key.
	 * @param string               $to   Recipient.
	 * @param array<string, mixed> $data Variables.
	 * @param array<string, mixed> $meta Log meta + overrides.
	 * @return bool
	 */
	public function send_template( string $key, string $to, array $data, array $meta = array() ): bool {
		$message = $this->render( $key, $data, $meta );
		return $this->send( $to, $message['subject'], $message['html'], $message['text'], $meta + array( 'template' => $key ) );
	}

	/**
	 * Sends one email and logs it.
	 *
	 * @param string               $to      Recipient.
	 * @param string               $subject Subject.
	 * @param string               $html    HTML body.
	 * @param string               $text    Plain-text body.
	 * @param array<string, mixed> $meta    cc[], bcc[], attachments[] (local paths), reply_to, from_name, log fields.
	 * @return bool
	 */
	public function send( string $to, string $subject, string $html, string $text, array $meta = array() ): bool {
		$to  = sanitize_email( $to );
		$log = $this->plugin->service( 'email_log' )->repository();
		$row = $log->insert(
			array(
				'to_email'    => $to,
				'subject'     => mb_substr( $subject, 0, 255 ),
				'template'    => isset( $meta['template'] ) ? (string) $meta['template'] : null,
				'category'    => isset( $meta['category'] ) ? (string) $meta['category'] : 'system',
				'status'      => 'queued',
				'object_type' => $meta['object_type'] ?? null,
				'object_id'   => $meta['object_id'] ?? null,
				'client_id'   => $meta['client_id'] ?? null,
				'member_id'   => $meta['member_id'] ?? null,
				'body_text'   => mb_substr( $text, 0, 20000 ),
			),
			isset( $meta['sender_member_id'] ) ? (int) $meta['sender_member_id'] : null
		);
		if ( ! is_email( $to ) ) {
			$log->update(
				(int) $row['id'],
				array(
					'status' => 'failed',
					'error'  => 'Invalid recipient address.',
				)
			);
			/** This filter is documented below. */
			do_action(
				'cliezoo_email_failed',
				$to,
				array( 'error' => __( 'That is not an email address.', 'cliezoo' ) ) + $meta
			);
			return false;
		}
		if ( $this->plugin->get( Jobs::class )->safe_mode() ) {
			// A cloned/staging site must not email real clients (§31.4).
			$log->update( (int) $row['id'], array( 'status' => 'held' ) );
			return false;
		}
		/**
		 * Whether an address may receive automated email (bounced/unsubscribed checks, §31.6).
		 *
		 * @param bool   $allowed Default true.
		 * @param string $to      Address.
		 * @param array  $meta    Meta (category tells transactional from marketing).
		 */
		if ( ! apply_filters( 'cliezoo_email_allowed', true, $to, $meta ) ) {
			$log->update( (int) $row['id'], array( 'status' => 'suppressed' ) );
			return false;
		}

		$headers   = array( 'Content-Type: text/html; charset=UTF-8' );
		$email     = $this->plugin->settings()->all( 'email' );
		$from_name = (string) ( $email['sender_name'] ?? '' );
		// A name for this one email — the person sending it — over the
		// workspace's own address: who it is from changes, the domain that
		// vouches for it does not.
		$named = trim( str_replace( array( '"', "\r", "\n", '<', '>' ), '', (string) ( $meta['from_name'] ?? '' ) ) );
		if ( '' !== $named ) {
			$from_name = mb_substr( $named, 0, 100 );
		}
		$from = (string) ( $email['from_address'] ?? '' );
		if ( '' !== $from && is_email( $from ) ) {
			$headers[] = 'From: ' . ( '' !== $from_name ? '"' . str_replace( '"', '', $from_name ) . '" ' : '' ) . '<' . $from . '>';
		} elseif ( '' !== $from_name ) {
			add_filter( 'wp_mail_from_name', array( $this, 'from_name' ) );
		}
		$reply_to = (string) ( $meta['reply_to'] ?? ( $email['reply_to'] ?? '' ) );
		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		foreach ( array( 'cc', 'bcc' ) as $kind ) {
			foreach ( (array) ( $meta[ $kind ] ?? array() ) as $address ) {
				if ( is_email( $address ) ) {
					$headers[] = ucfirst( $kind ) . ': ' . sanitize_email( $address );
				}
			}
		}
		$this->last_error = '';
		$this->alt_body   = $text;
		$this->message_id = 1 === preg_match( '/^[A-Za-z0-9._-]{1,200}@[A-Za-z0-9.-]{1,200}$/', (string) ( $meta['message_id'] ?? '' ) ) ? (string) $meta['message_id'] : '';
		add_action( 'wp_mail_failed', array( $this, 'capture_error' ) );
		add_action( 'phpmailer_init', array( $this, 'add_alt_body' ) );
		/**
		 * Files made for this one email — a PDF of the invoice or receipt it
		 * is about, made as it goes so it says what is true now. Removed
		 * once it has gone (cliezoo_email_made_attachments_done).
		 *
		 * @param string[] $paths Local paths.
		 * @param array    $meta  The email's meta (its `pdf` says what to make).
		 */
		$made = array_values( array_filter( (array) apply_filters( 'cliezoo_email_made_attachments', array(), $meta ), 'is_string' ) );
		/**
		 * The headers of one email (List-Unsubscribe for marketing).
		 *
		 * @param string[] $headers Headers.
		 * @param string   $to      Recipient.
		 * @param array    $meta    Meta.
		 */
		$headers = array_values( array_filter( (array) apply_filters( 'cliezoo_email_headers', $headers, $to, $meta ), 'is_string' ) );
		/**
		 * The HTML body of one email, as it goes (marketing's way out).
		 *
		 * @param string $html Body.
		 * @param string $to   Recipient.
		 * @param array  $meta Meta.
		 */
		$html = (string) apply_filters( 'cliezoo_email_html', $html, $to, $meta );
		$sent = wp_mail( $to, $subject, $html, $headers, array_values( array_filter( array_merge( (array) ( $meta['attachments'] ?? array() ), $made ), 'is_readable' ) ) );
		/**
		 * The files made for an email can go.
		 *
		 * @param string[] $paths Local paths.
		 */
		do_action( 'cliezoo_email_made_attachments_done', $made );
		remove_action( 'wp_mail_failed', array( $this, 'capture_error' ) );
		remove_action( 'phpmailer_init', array( $this, 'add_alt_body' ) );
		remove_filter( 'wp_mail_from_name', array( $this, 'from_name' ) );

		$log->update(
			(int) $row['id'],
			array(
				'status'  => $sent ? 'sent' : 'failed',
				'error'   => $sent ? null : mb_substr( $this->last_error, 0, 255 ),
				'sent_at' => $sent ? gmdate( 'Y-m-d H:i:s' ) : null,
			)
		);
		if ( ! $sent ) {
			Logger::warning(
				'Email failed',
				array(
					'template' => $meta['template'] ?? '',
					'error'    => $this->last_error,
				)
			);
			/**
			 * An email could not be sent (bounce/failure bookkeeping, Health warning).
			 *
			 * @param string $to   Recipient.
			 * @param array  $meta Meta.
			 */
			do_action( 'cliezoo_email_failed', $to, array( 'error' => $this->last_error ) + $meta );
		}
		if ( $sent ) {
			/**
			 * An email went. Proof that an address works, for anything that
			 * has been remembering that it did not.
			 *
			 * @param string $to   Recipient.
			 * @param array  $meta Meta.
			 */
			do_action( 'cliezoo_email_sent', $to, $meta );
		}
		return (bool) $sent;
	}

	/**
	 * From name filter.
	 *
	 * @param string $name Default name.
	 * @return string
	 */
	public function from_name( string $name ): string {
		$configured = (string) $this->plugin->settings()->get( 'email', 'sender_name', '' );
		return '' !== $configured ? $configured : $name;
	}

	/**
	 * Captures wp_mail errors.
	 *
	 * @param \WP_Error $error Error.
	 * @return void
	 */
	public function capture_error( \WP_Error $error ): void {
		$this->last_error = $error->get_error_message();
	}

	/**
	 * Adds the plain-text alternative.
	 *
	 * @param object $phpmailer PHPMailer instance.
	 * @return void
	 */
	public function add_alt_body( $phpmailer ): void {
		if ( '' !== $this->alt_body && property_exists( $phpmailer, 'AltBody' ) ) {
			$phpmailer->AltBody = $this->alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		}
		if ( '' !== $this->message_id && property_exists( $phpmailer, 'MessageID' ) ) {
			$phpmailer->MessageID = '<' . $this->message_id . '>'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		}
	}

	/**
	 * When the admin confirms the live site, emails held during Safe mode are
	 * discarded, not sent: they were produced while the copy was unconfirmed and
	 * are likely stale or duplicates. Reminders and digests regenerate normally.
	 *
	 * @return void
	 */
	public function release_held(): void {
		$log = $this->plugin->service( 'email_log' )->repository();
		$log->update_where( array( 'status' => 'discarded' ), array( 'status' => 'held' ) );
	}
}
