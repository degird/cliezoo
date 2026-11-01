<?php
/**
 * An address that cannot be reached, remembered on the person it belongs to.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Contacts;

use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What happens after an email to a client does not arrive.
 *
 * A typo in an address is the quietest failure in this kind of software. The
 * invoice says "sent", the studio waits, the client never saw it, and six
 * weeks later somebody rings to ask about the money. Meanwhile the reminder
 * schedule keeps sending to the same wrong address every week, which is how
 * a studio's domain gets a reputation with the receiving mail server.
 *
 * So a failure is written on the person, not only in the log: the contact
 * says there is a problem with their address and what the mail server said
 * about it, the screens show it, and automated email to that address stops
 * until somebody deals with it.
 *
 * Deals with it means one of two things, and both clear the flag: the
 * address is corrected — a new address is a new chance — or somebody writes
 * to them by hand and it goes through, which proves the address works.
 * A person writing by hand is never stopped: they can see the problem on the
 * screen, and deciding to try anyway is a decision they are allowed to make.
 *
 * What this cannot do is hear about a bounce that arrives minutes later, in
 * a reply to a mailbox nobody here reads. That needs somewhere to receive
 * it, which is the Cliezoo Connect relay (STATUS Q4); until then what is
 * caught is every failure the sending machine itself reports, which is where
 * a wrong address usually shows up.
 */
final class EmailTrouble {

	/**
	 * Categories that are the software's own doing rather than a person's.
	 *
	 * A personal email is somebody choosing to write to this address now,
	 * with the problem on the screen in front of them.
	 */
	private const AUTOMATIC = array( 'system', 'reminder', 'invoice', 'marketing', 'notification' );

	/**
	 * The plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Holds the plugin it reads its services from.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Writes the trouble on whoever owns that address.
	 *
	 * @param string               $to   The address.
	 * @param array<string, mixed> $meta What the mailer was told.
	 * @return void
	 */
	public function failed( string $to, array $meta = array() ): void {
		$rows = $this->contacts( $to );
		if ( ! $rows ) {
			return;
		}
		$said = trim( (string) ( $meta['error'] ?? '' ) );
		if ( '' === $said ) {
			$said = __( 'The mail server would not take it.', 'cliezoo' );
		}
		foreach ( $rows as $row ) {
			$this->plugin->service( 'contacts' )->repository()->update(
				(int) $row['id'],
				array(
					'email_problem_at' => gmdate( 'Y-m-d H:i:s' ),
					'email_problem'    => mb_substr( $said, 0, 190 ),
				)
			);
		}
	}

	/**
	 * Forgets the trouble, because the address has just been proved to work.
	 *
	 * @param string $to The address.
	 * @return void
	 */
	public function worked( string $to ): void {
		foreach ( $this->contacts( $to ) as $row ) {
			if ( empty( $row['email_problem_at'] ) ) {
				continue;
			}
			$this->plugin->service( 'contacts' )->repository()->update(
				(int) $row['id'],
				array(
					'email_problem_at' => null,
					'email_problem'    => null,
				)
			);
		}
	}

	/**
	 * Whether the software may write to this address by itself.
	 *
	 * @param bool                 $allowed What was decided so far.
	 * @param string               $to      The address.
	 * @param array<string, mixed> $meta    What the mailer was told.
	 * @return bool
	 */
	public function allowed( bool $allowed, string $to, array $meta = array() ): bool {
		if ( ! $allowed ) {
			return false;
		}
		$category = (string) ( $meta['category'] ?? 'system' );
		if ( ! in_array( $category, self::AUTOMATIC, true ) ) {
			return true;
		}
		foreach ( $this->contacts( $to ) as $row ) {
			if ( ! empty( $row['email_problem_at'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The contacts holding an address, dead ones included.
	 *
	 * Matched on the address rather than on the contact the email named,
	 * because the same address on two contacts has the same problem twice.
	 *
	 * @param string $to The address.
	 * @return array<int, array<string, mixed>>
	 */
	private function contacts( string $to ): array {
		// Sanitised where that leaves something, and as typed where it does
		// not: the address most likely to fail is the one with a typo in it,
		// and tidying "ada@@bluedot" into something else would look for a
		// contact nobody has.
		$clean = strtolower( sanitize_email( $to ) );
		$to    = '' !== $clean ? $clean : strtolower( trim( $to ) );
		if ( '' === $to || ! $this->plugin->entities()->has( 'contacts' ) ) {
			return array();
		}
		return $this->plugin->service( 'contacts' )->repository()->where(
			array( 'email' => $to ),
			'id',
			20
		);
	}
}
