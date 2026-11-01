<?php
/**
 * A decision is made by whoever may make it, however it is asked for.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Permissions;

use Cliezoo\Core\Actions\ActionError;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML (decision record 0002).

/**
 * Time and costs are approved through actions gated on a permission of their
 * own. Those actions write the status directly; the ordinary create and edit
 * of a record go through here, so that writing the status field is not a
 * second, ungated way of making the same decision.
 */
final class Approvals {

	/**
	 * Statuses anybody who may edit the record can write.
	 */
	private const OPEN = array( 'draft', 'submitted' );

	/**
	 * Refuses a status written past the permission that decides it.
	 *
	 * Without the permission: a new record is a draft or submitted, and one
	 * already approved, invoiced or paid back is not moved anywhere by
	 * editing its status — that is a decision too.
	 *
	 * @param array<string, mixed>      $data       What is being written.
	 * @param array<string, mixed>|null $existing   The row as it is (null on create).
	 * @param mixed                     $actor      Who is writing it.
	 * @param string                    $permission The permission that decides.
	 * @return void
	 * @throws ActionError When the status is not theirs to write.
	 */
	public static function must_not_decide( array $data, ?array $existing, $actor, string $permission ): void {
		if ( ! array_key_exists( 'status', $data ) ) {
			return;
		}
		$to  = (string) $data['status'];
		$was = null === $existing ? null : (string) ( $existing['status'] ?? '' );
		if ( $to === $was ) {
			return;
		}
		// The system itself (a job, an importer's own flow) has no member.
		if ( ! $actor instanceof Actor || $actor->allows( $permission ) ) {
			return;
		}
		$from_open = null === $was || in_array( $was, array_merge( self::OPEN, array( 'rejected' ) ), true );
		if ( in_array( $to, self::OPEN, true ) && $from_open ) {
			return;
		}
		throw new ActionError(
			'cliezoo_forbidden',
			__( 'Approving this is a decision for somebody who may approve it. Submit it instead.', 'cliezoo' ),
			403
		);
	}
}
