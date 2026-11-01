<?php
/**
 * Record types and date fields that can have reminders.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Reminders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ReminderTargetRegistry (Blueprint §19.1). Modules register targets:
 *
 *     $targets->add( 'invoices', [
 *         'label'       => 'Invoice',
 *         'dates'       => [ 'due_date' => 'Due date', 'issue_date' => 'Issue date' ],
 *         'stop'        => [ 'paid', 'void' ],            // statuses that stop reminders
 *         'status'      => 'status',                      // status column
 *         'recipients'  => fn( array $row ): array => [ 'members' => [..], 'emails' => [ email => name ] ],
 *         'roles'       => [ 'owner' => 'Owner', 'client_contact' => 'Client contact', … ],
 *         'context'     => fn( array $row ): array => [ …template variables… ],
 *         'timezone'    => fn( array $row ): ?string,     // recipient zone for date-only fields
 *         'email'       => 'invoice_reminder',            // default email template
 *     ] );
 */
final class ReminderTargets {

	/**
	 * The keys a target may have: the ones the engine reads.
	 */
	public const KEYS = array( 'label', 'dates', 'stop', 'status', 'recipients', 'roles', 'context', 'timezone', 'email' );

	/**
	 * Targets.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $targets = null;

	/**
	 * All targets.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( null === $this->targets ) {
			$this->targets = array();
			/**
			 * Register reminder targets.
			 *
			 * @param ReminderTargets $targets Registry.
			 */
			do_action( 'cliezoo_register_reminder_targets', $this );
		}
		return $this->targets;
	}

	/**
	 * Adds a target.
	 *
	 * @param string               $entity Entity name.
	 * @param array<string, mixed> $target Definition.
	 * @return void
	 */
	public function add( string $entity, array $target ): void {
		// A key nothing reads is a rule that silently does not apply: the
		// meetings target once said `unless` where it meant `stop`, and a
		// cancelled meeting went on being reminded about.
		$unknown = array_diff( array_keys( $target ), self::KEYS );
		if ( $unknown ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'Reminder target "%s" has keys nothing reads: %s.', $entity, implode( ', ', $unknown ) ) ),
				'1.0.0'
			);
		}
		$this->targets[ $entity ] = $target + array(
			'dates'      => array(),
			'stop'       => array(),
			'status'     => 'status',
			'recipients' => null,
			'roles'      => array(),
			'context'    => null,
			'timezone'   => null,
			'email'      => 'reminder',
		);
	}

	/**
	 * One target.
	 *
	 * @param string $entity Entity name.
	 * @return array<string, mixed>|null
	 */
	public function get( string $entity ): ?array {
		return $this->all()[ $entity ] ?? null;
	}
}
