<?php
/**
 * The operations that reach a client or move money.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The flags `client_visible` and `touches_money` ask AI and automations for
 * a person's yes before an operation runs — and almost no operation set
 * them, so sending an invoice or an email to a client, inviting somebody to
 * the portal or refunding a payment went unflagged. The list is kept here,
 * in one place, so a new sending or paying operation is added beside its
 * kin rather than remembered in its own file.
 */
final class Careful {

	/**
	 * Operations that send something to a client or show them something new.
	 */
	public const CLIENT_VISIBLE = array(
		'clients.portal',
		// A comment can be for the client (not internal).
		'comments.add',
		'contacts.invite',
		'contacts.invite_resend',
		'contacts.portal',
		'conversations.schedule',
		'conversations.send',
		'credit_notes.send',
		'docs.publish',
		'documents.ask_to_sign',
		'documents.send',
		// Signs, with a person's saved signature.
		'documents.sign_as_me',
		'emails.send',
		'forms.send',
		'invoices.remind',
		'invoices.send',
		'portal.invite_colleague',
		'portal.link',
		'projects.portal',
	);

	/**
	 * Operations that move money or fix an amount owed.
	 */
	public const TOUCHES_MONEY = array(
		// Recorded as a payment against the invoice.
		'credit_notes.apply',
		'credit_notes.refund',
		'invoices.cancel',
		'invoices.finalize',
		'invoices.record_external',
		'invoices.send',
		'payments.confirm_reported',
		'payments.record',
		'payments.refund',
	);

	/**
	 * The flags an operation carries by its name.
	 *
	 * @param string $name Operation.
	 * @return array<string, bool>
	 */
	public static function flags( string $name ): array {
		$out = array();
		if ( in_array( $name, self::CLIENT_VISIBLE, true ) ) {
			$out['client_visible'] = true;
		}
		if ( in_array( $name, self::TOUCHES_MONEY, true ) ) {
			$out['touches_money'] = true;
		}
		return $out;
	}
}
