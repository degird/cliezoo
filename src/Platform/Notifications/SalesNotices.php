<?php
/**
 * Turning what happened in the sales modules into notifications.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Notifications;

use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bridge between the domain hooks and the notification engine.
 *
 * The event catalogue had entries for all of this from the start, so the
 * channel × event matrix offered a studio switches for "quote accepted",
 * "document signed", "invoice overdue" and twenty more — and nothing fired any
 * of them. A switch that can never produce a notification is worse than not
 * offering it: somebody turns it on, trusts it, and hears nothing on the day it
 * mattered.
 *
 * It is one bridge rather than a `do_action` scattered through seven modules,
 * because the modules already announce what happened in their own words. What
 * was missing was somebody listening. Adding a listener here also means the
 * decision about *who* to tell is made once, and the modules go on knowing
 * nothing about notifications.
 *
 * Who gets told, in every case: whoever owns the record, plus anybody watching
 * it. The engine drops the person who caused it, so nobody is told about their
 * own action.
 */
final class SalesNotices {

	/**
	 * The plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Holds the plugin.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Listens to what the modules already say.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action(
			'cliezoo_document_viewed',
			function ( array $row ): void {
				// First open only. A client who reads a quote four times is
				// thinking about it, not doing something the studio needs
				// telling about four times.
				//
				// The hook carries the row as it was before the count went up,
				// so "never opened before" is an empty viewed_at rather than a
				// number to compare.
				if ( ! empty( $row['viewed_at'] ) ) {
					return;
				}
				$this->about( 'document.viewed', 'documents', $row, $this->kind_of( $row ) . ' ' . __( 'was opened', 'cliezoo' ) );
			}
		);

		add_action(
			'cliezoo_document_answered',
			function ( array $row, string $said ): void {
				$event = 'declined' === $said ? 'document.declined' : 'document.accepted';
				$what  = 'declined' === $said
					? __( 'was declined', 'cliezoo' )
					: __( 'was accepted', 'cliezoo' );
				$this->about( $event, 'documents', $row, $this->kind_of( $row ) . ' ' . $what );
			},
			10,
			2
		);

		add_action(
			'cliezoo_invoice_overdue',
			function ( array $row ): void {
				$this->invoice_overdue( $row );
			}
		);

		add_action(
			'cliezoo_document_question',
			function ( array $row ): void {
				$this->about( 'document.question', 'documents', $row, __( 'The client asked a question', 'cliezoo' ) );
			}
		);

		add_action(
			'cliezoo_document_signed',
			function ( array $row ): void {
				$this->about( 'document.signed', 'documents', $row, $this->kind_of( $row ) . ' ' . __( 'has been signed by everybody', 'cliezoo' ) );
			}
		);

		// A record being written is already announced generically, so these
		// listen to that rather than asking five modules to learn a new hook
		// each. A lead, a meeting or a form answer arriving is a create; a deal
		// changing hands is an update, where the old row is in hand too.
		add_action(
			'cliezoo_record_created',
			function ( $entity, array $row ): void {
				if ( ! is_object( $entity ) || ! isset( $entity->name ) ) {
					return;
				}
				if ( 'leads' === $entity->name ) {
					$this->about( 'lead.created', 'leads', $row, __( 'A new enquiry came in', 'cliezoo' ) );
				}
				if ( 'meetings' === $entity->name && 'booked' === (string) ( $row['status'] ?? '' ) ) {
					$this->about( 'meeting.booked', 'meetings', $row, __( 'A meeting was booked', 'cliezoo' ) );
				}
			},
			10,
			2
		);

		// Moved or called off by whoever booked it: the same setting as a
		// booking, which the catalogue names for all three.
		add_action(
			'cliezoo_meeting_changed',
			function ( array $row, string $what ): void {
				$this->about(
					'meeting.booked',
					'meetings',
					$row,
					'cancelled' === $what ? __( 'A meeting was called off', 'cliezoo' ) : __( 'A meeting was moved', 'cliezoo' )
				);
			},
			10,
			2
		);

		add_action(
			'cliezoo_record_updated',
			function ( $entity, array $row, array $before ): void {
				if ( ! is_object( $entity ) || ! isset( $entity->name ) || 'deals' !== $entity->name ) {
					return;
				}
				$now = (string) ( $row['status'] ?? 'open' );
				$was = (string) ( $before['status'] ?? 'open' );
				if ( $now !== $was && 'won' === $now ) {
					$this->about( 'deal.won', 'deals', $row, __( 'A deal was won', 'cliezoo' ) );
					return;
				}
				if ( $now !== $was && in_array( $now, array( 'lost', 'abandoned' ), true ) ) {
					$this->about( 'deal.lost', 'deals', $row, __( 'A deal was lost', 'cliezoo' ) );
					return;
				}
				// Only when it really moved. A deal saved with the same stage
				// is somebody correcting a typo, not news.
				if ( (int) ( $row['stage_id'] ?? 0 ) !== (int) ( $before['stage_id'] ?? 0 ) ) {
					$this->about( 'deal.stage_changed', 'deals', $row, __( 'A deal moved on', 'cliezoo' ) );
				}
			},
			10,
			3
		);
	}

	/**
	 * Tells the studio an answer came in.
	 *
	 * Called from the forms module, which knows the form and the answer; the
	 * answer itself is written by a stranger with no session, so the generic
	 * create hook does not carry an actor to drop.
	 *
	 * @param array<string, mixed> $form The form.
	 * @return void
	 */
	public function form_answered( array $form ): void {
		// Marked important in the catalogue, and rightly: an enquiry nobody
		// hears about is a client who went elsewhere.
		$this->about( 'form.submitted', 'forms', $form, __( 'Somebody answered a form', 'cliezoo' ) );
	}

	/**
	 * Tells the studio somebody booked a meeting from a booking page.
	 *
	 * Called from the booking itself, which writes the meeting without the
	 * generic create hook: the person booking has no session to carry.
	 *
	 * @param array<string, mixed> $meeting The meeting.
	 * @return void
	 */
	public function meeting_booked( array $meeting ): void {
		$this->about( 'meeting.booked', 'meetings', $meeting, __( 'A meeting was booked', 'cliezoo' ) );
	}

	/**
	 * Tells the studio an invoice has gone past its date.
	 *
	 * Called when the daily sweep (`Invoices::mark_overdue`) finds it late.
	 *
	 * @param array<string, mixed> $row The invoice.
	 * @return void
	 */
	public function invoice_overdue( array $row ): void {
		$this->about( 'invoice.overdue', 'invoices', $row, __( 'An invoice is overdue', 'cliezoo' ) );
	}

	/**
	 * What to call a document in a sentence.
	 *
	 * "Your quote was accepted" reads as something that happened; "Document
	 * 14 status changed to accepted" reads as a database.
	 *
	 * @param array<string, mixed> $row The document row.
	 * @return string
	 */
	private function kind_of( array $row ): string {
		$words = array(
			'quote'    => __( 'A quote', 'cliezoo' ),
			'proposal' => __( 'A proposal', 'cliezoo' ),
			'contract' => __( 'A contract', 'cliezoo' ),
			'sow'      => __( 'A statement of work', 'cliezoo' ),
		);
		return (string) ( $words[ (string) ( $row['kind'] ?? '' ) ] ?? __( 'A document', 'cliezoo' ) );
	}

	/**
	 * Tells whoever owns the record and whoever is watching it.
	 *
	 * @param string               $event  The catalogue event.
	 * @param string               $entity Which entity the row belongs to.
	 * @param array<string, mixed> $row    The row.
	 * @param string               $title  What the notification says.
	 * @return void
	 */
	private function about( string $event, string $entity, array $row, string $title ): void {
		if ( ! $this->plugin->entities()->has( $entity ) || empty( $row['id'] ) ) {
			return;
		}
		$notifier = $this->plugin->get( Notifier::class );

		$members = $notifier->watchers( $entity, (int) $row['id'] );
		$owner   = (int) ( $row['owner_id'] ?? 0 );
		if ( $owner > 0 ) {
			$members[] = $owner;
		}
		// Nobody owns it and nobody is watching: the people who look after the
		// workspace would rather hear it than nobody hear it.
		if ( ! $members ) {
			$members = $notifier->admins();
		}

		$name = (string) ( $row['name'] ?? ( $row['number'] ?? '' ) );

		do_action(
			'cliezoo_notify',
			$event,
			$members,
			array(
				'title'  => '' !== $name ? $title . ': ' . $name : $title,
				'entity' => $this->plugin->entities()->get( $entity ),
				'row'    => $row,
			)
		);
	}
}
