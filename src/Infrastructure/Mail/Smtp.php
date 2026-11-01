<?php
/**
 * Sending through a mail server of the workspace's own.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Mail;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Crypto\Vault;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * WordPress sends mail with whatever the server offers, which on a lot of
 * hosts is nothing anybody trusts. A studio with its own mailbox — Google
 * Workspace, Fastmail, Amazon SES, Mailgun — wants its mail to go out
 * through that, from its own domain, so it arrives.
 *
 * This is the smallest thing that does it: the settings a mail server
 * needs, the password kept encrypted like every other secret, and one
 * hook into the PHPMailer WordPress already has. Nothing here replaces
 * `wp_mail()`; a site that already uses an SMTP plugin can leave this off
 * and nothing changes.
 */
final class Smtp {

	/**
	 * How long to wait for the server.
	 */
	private const TIMEOUT = 15;

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
	 * The settings a mail server needs.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		return array(
			'smtp_enabled' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'smtp_host'    => array(
				'type'      => 'string',
				'maxLength' => 190,
				'default'   => '',
			),
			'smtp_port'    => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 65535,
				'default' => 587,
			),
			// "tls" is STARTTLS on 587, "ssl" is implicit TLS on 465, and
			// "" is a plain connection, which is offered because a server
			// on localhost sometimes is one and never because it is a good
			// idea anywhere else.
			'smtp_secure'  => array(
				'type'    => 'string',
				'enum'    => array( 'tls', 'ssl', '' ),
				'default' => 'tls',
			),
			'smtp_user'    => array(
				'type'      => 'string',
				'maxLength' => 190,
				'default'   => '',
			),
			'smtp_pass'    => array(
				'type'      => 'string',
				'maxLength' => 500,
				'default'   => '',
				'secret'    => true,
			),
		);
	}

	/**
	 * Whether mail should go through the workspace's own server.
	 *
	 * @return bool
	 */
	public function on(): bool {
		$email = $this->plugin->settings()->all( 'email' );
		return ! empty( $email['smtp_enabled'] ) && '' !== (string) ( $email['smtp_host'] ?? '' );
	}

	/**
	 * Points PHPMailer at the workspace's server.
	 *
	 * Called on `phpmailer_init`, so it applies to every email WordPress
	 * sends while it is on — Cliezoo's and the site's alike, which is what
	 * somebody who filled this in meant.
	 *
	 * @param object $mailer The PHPMailer instance WordPress is about to use.
	 * @return void
	 */
	public function configure( $mailer ): void {
		if ( ! $this->on() || ! method_exists( $mailer, 'isSMTP' ) ) {
			return;
		}
		$email    = $this->plugin->settings()->all( 'email' );
		$password = $this->password();
		$mailer->isSMTP();
		$mailer->Host        = (string) $email['smtp_host']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property.
		$mailer->Port        = (int) $email['smtp_port']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property.
		$mailer->SMTPAuth    = '' !== (string) $email['smtp_user']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property.
		$mailer->Username    = (string) $email['smtp_user']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property.
		$mailer->Password    = $password; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property.
		$mailer->SMTPSecure  = (string) $email['smtp_secure']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property.
		$mailer->SMTPAutoTLS = '' !== (string) $email['smtp_secure']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property.
		$mailer->Timeout     = self::TIMEOUT; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property.
	}

	/**
	 * Tries the server, without sending anything to anybody.
	 *
	 * A connection and a greeting is the whole of it: it says whether the
	 * host, the port, the encryption and the password are right, which is
	 * every way this is normally got wrong, and it does it without a test
	 * message landing in somebody's inbox.
	 *
	 * @return array{ok: bool, message: string}
	 * @throws ActionError When there is nothing to connect to.
	 */
	public function check(): array {
		$email = $this->plugin->settings()->all( 'email' );
		$host  = (string) ( $email['smtp_host'] ?? '' );
		if ( '' === $host ) {
			throw ActionError::validation( __( 'Fill in the mail server first.', 'cliezoo' ) );
		}
		if ( ! class_exists( \PHPMailer\PHPMailer\SMTP::class ) ) {
			require_once ABSPATH . 'wp-includes/PHPMailer/SMTP.php';
		}
		$smtp           = new \PHPMailer\PHPMailer\SMTP();
		$smtp->do_debug = 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property.
		$secure         = (string) ( $email['smtp_secure'] ?? '' );
		$port           = (int) ( $email['smtp_port'] ?? 587 );
		$where          = ( 'ssl' === $secure ? 'ssl://' : '' ) . $host;
		try {
			if ( ! $smtp->connect( $where, $port, self::TIMEOUT ) ) {
				return array(
					'ok'      => false,
					'message' => __( 'The server did not answer. Check the address, the port and whether your host allows outgoing mail.', 'cliezoo' ),
				);
			}
			if ( ! $smtp->hello( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
				return array(
					'ok'      => false,
					'message' => __( 'The server answered but would not say hello.', 'cliezoo' ),
				);
			}
			if ( 'tls' === $secure && ! $smtp->startTLS() ) {
				return array(
					'ok'      => false,
					'message' => __( 'The server would not start an encrypted connection. Try SSL on port 465, or no encryption if the server is on this machine.', 'cliezoo' ),
				);
			}
			$user = (string) ( $email['smtp_user'] ?? '' );
			if ( '' !== $user && ! $smtp->authenticate( $user, $this->password() ) ) {
				return array(
					'ok'      => false,
					'message' => __( 'The server refused the username and password.', 'cliezoo' ),
				);
			}
			return array(
				'ok'      => true,
				'message' => __( 'The mail server answered and accepted the sign-in.', 'cliezoo' ),
			);
		} catch ( \Throwable $problem ) {
			return array(
				'ok'      => false,
				'message' => $problem->getMessage(),
			);
		} finally {
			$smtp->quit();
			$smtp->close();
		}
	}

	/**
	 * The stored password, in the clear, for the one moment it is needed.
	 *
	 * @return string
	 */
	private function password(): string {
		$kept = (string) ( $this->plugin->settings()->all( 'email' )['smtp_pass'] ?? '' );
		if ( '' === $kept ) {
			return '';
		}
		return (string) ( $this->plugin->get( Vault::class )->decrypt( $kept, 'smtp_pass' ) ?? '' );
	}
}
