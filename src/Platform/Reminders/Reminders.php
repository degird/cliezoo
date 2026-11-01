<?php
/**
 * Reminder planning and delivery.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Reminders;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Data\ListQuery;
use Cliezoo\Core\Data\ObjectRef;
use Cliezoo\Core\Data\Repository;
use Cliezoo\Core\Data\Serializer;
use Cliezoo\Core\Logger;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Jobs\Jobs;
use Cliezoo\Infrastructure\Mail\Mailer;
use Cliezoo\Platform\Notifications\ChannelRegistry;
use Cliezoo\Platform\Notifications\Notifier;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Plans concrete occurrences when a record's date or status changes, and
 * sends due ones every minute. Times are stored in UTC and calculated in the
 * record's recipient time zone with DST-aware DateTime (§31.2).
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class Reminders {

	private const BATCH = 50;

	/**
	 * Minutes in a week and in a "month" as stored in offset_minutes. A
	 * month is kept as 30 days so a list can still sort by it, and moved as
	 * a calendar month (offset_unit "months") when it is applied.
	 */
	public const WEEK  = 10080;
	public const MONTH = 43200;

	/**
	 * Recipients every record type has, beside its own (assignee, owner…).
	 */
	public const COMMON_RECIPIENTS = array( 'watchers', 'client_contact' );

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * The time of the run being delivered (tests pass one in).
	 *
	 * @var int|null
	 */
	private ?int $now = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		add_action( 'cliezoo_job_reminders_replan', array( $this, 'replan_batch' ) );
	}

	/**
	 * Targets registry.
	 *
	 * @return ReminderTargets
	 */
	private function targets(): ReminderTargets {
		return $this->plugin->get( ReminderTargets::class );
	}

	/**
	 * Occurrence repository.
	 *
	 * @return Repository
	 */
	private function occurrences(): Repository {
		return $this->plugin->service( 'reminders' )->repository();
	}

	/**
	 * Rules repository.
	 *
	 * @return Repository
	 */
	private function rules(): Repository {
		return $this->plugin->service( 'reminder_rules' )->repository();
	}

	/**
	 * Targets and channels for the settings UI.
	 *
	 * @return array{targets: array<int, array<string, mixed>>, channels: array<string, string>}
	 */
	public function targets_for_ui(): array {
		$out = array();
		foreach ( $this->targets()->all() as $entity => $target ) {
			$out[] = array(
				'type'  => $entity,
				'label' => (string) ( $target['label'] ?? $entity ),
				'dates' => $target['dates'],
				'stop'  => $target['stop'],
				'roles' => $this->roles( $entity, $target ),
				'email' => $target['email'],
			);
		}
		$roles = array();
		foreach ( \Cliezoo\Platform\Permissions\Roles::labels() as $key => $label ) {
			if ( ! in_array( $key, \Cliezoo\Platform\Permissions\Roles::CLIENT_ROLES, true ) && 'ai_agent' !== $key ) {
				$roles[ $key ] = $label;
			}
		}
		return array(
			'targets'  => $out,
			'channels' => $this->plugin->get( ChannelRegistry::class )->labels(),
			// "A role" in the Who list: everybody active with that role.
			'roles'    => $roles,
			/**
			 * Whether team recipients can be chosen (teams, from add-ons).
			 *
			 * @param bool $teams Default false.
			 */
			'teams'    => (bool) apply_filters( 'cliezoo_reminder_teams', false ),
		);
	}

	/**
	 * The "Who" choices of a record type: its own, then watchers and the
	 * client contact where the record has them.
	 *
	 * @param string               $type   Entity.
	 * @param array<string, mixed> $target Target.
	 * @return array<string, string>
	 */
	private function roles( string $type, array $target ): array {
		$roles = (array) $target['roles'];
		if ( ! isset( $roles['watchers'] ) ) {
			$roles['watchers'] = __( 'Whoever watches it', 'cliezoo' );
		}
		$entity = $this->plugin->entities()->has( $type ) ? $this->plugin->entities()->get( $type ) : null;
		if ( ! isset( $roles['client_contact'] ) && $entity && ( '' !== $entity->client_field || 'clients' === $type ) && $this->plugin->entities()->has( 'contacts' ) ) {
			$roles['client_contact'] = __( 'The client\'s main contact', 'cliezoo' );
		}
		return $roles;
	}

	/**
	 * Validates and normalises a rule before saving.
	 *
	 * @param array<string, mixed>      $data  Column data.
	 * @param Actor                     $actor Actor.
	 * @param array<string, mixed>      $input Input (may contain scope_object uid).
	 * @param array<string, mixed>|null $row   Current rule on update.
	 * @return array<string, mixed>
	 * @throws ActionError On invalid rules.
	 */
	public function prepare_rule( array $data, Actor $actor, array $input, ?array $row ): array {
		$merged = array_merge( (array) $row, $data );
		$target = $this->targets()->get( (string) ( $merged['target_type'] ?? '' ) );
		if ( ! $target ) {
			throw ActionError::validation( __( 'Reminders are not available for this record type.', 'cliezoo' ) );
		}
		$date = (string) ( $merged['date_field'] ?? '' );
		if ( ! isset( $target['dates'][ $date ] ) && ! str_starts_with( $date, 'custom.' ) ) {
			throw ActionError::validation( __( 'Choose a date field of this record type.', 'cliezoo' ), array( 'date_field' => __( 'Unknown date field.', 'cliezoo' ) ) );
		}
		$scope = (string) ( $merged['scope_type'] ?? 'workspace' );
		if ( in_array( $scope, array( 'workspace', 'template' ), true ) ) {
			if ( ! $actor->is_admin() && ! $actor->allows( 'settings_general' ) ) {
				throw ActionError::forbidden();
			}
			$data['scope_id'] = null;
		} else {
			$type = array(
				'client'  => 'clients',
				'project' => 'projects',
				'record'  => (string) $merged['target_type'],
			)[ $scope ] ?? '';
			if ( '' === $type || ! $this->plugin->entities()->has( $type ) ) {
				throw ActionError::validation( __( 'Choose what this reminder applies to.', 'cliezoo' ), array( 'scope_type' => __( 'Unknown scope.', 'cliezoo' ) ) );
			}
			if ( isset( $input['scope_object'] ) || null === $row ) {
				$record           = ObjectRef::resolve( $this->plugin, $actor, $type, (string) ( $input['scope_object'] ?? '' ), Policy::UPDATE )[1];
				$data['scope_id'] = (int) $record['id'];
			} else {
				// Editing a rule that stays on the same record: the actor must
				// still be allowed to change that record.
				$entity = $this->plugin->entities()->get( $type );
				$record = $this->plugin->service( $type )->repository()->find( (int) ( $merged['scope_id'] ?? 0 ) );
				if ( ! $record || ! $this->plugin->policy()->can( $actor, Policy::UPDATE, $entity, $record ) ) {
					throw ActionError::forbidden();
				}
			}
		}
		if ( isset( $data['offset_minutes'] ) ) {
			$data['offset_minutes'] = abs( (int) $data['offset_minutes'] );
		}
		// The unit the person chose. Only months change what the number
		// means (a calendar month, not 30 days); the others are kept so the
		// form shows "2 weeks" rather than "14 days".
		if ( array_key_exists( 'offset_unit', $data ) ) {
			$unit                = (string) $data['offset_unit'];
			$data['offset_unit'] = in_array( $unit, array( 'minutes', 'hours', 'days', 'weeks', 'months' ), true ) ? $unit : null;
		}
		$unit = (string) ( $data['offset_unit'] ?? ( $row['offset_unit'] ?? '' ) );
		if ( 'months' === $unit ) {
			$minutes                = (int) ( $data['offset_minutes'] ?? ( $row['offset_minutes'] ?? 0 ) );
			$data['offset_minutes'] = max( 1, (int) round( $minutes / self::MONTH ) ) * self::MONTH;
		}
		if ( isset( $data['repeat_minutes'] ) && (int) $data['repeat_minutes'] > 0 && (int) $data['repeat_minutes'] < 15 ) {
			$data['repeat_minutes'] = 15;
		}
		if ( ! empty( $data['at_time'] ) && 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $data['at_time'] ) ) {
			throw ActionError::validation( __( 'Use a time like 09:00.', 'cliezoo' ), array( 'at_time' => __( 'Invalid time.', 'cliezoo' ) ) );
		}
		if ( isset( $input['channels'] ) ) {
			$allowed          = $this->plugin->get( ChannelRegistry::class )->ids();
			$data['channels'] = wp_json_encode( array_values( array_intersect( (array) $input['channels'], $allowed ) ) );
		}
		if ( isset( $input['recipients'] ) ) {
			$roles = array_keys( $this->roles( (string) $merged['target_type'], $target ) );
			$clean = array();
			foreach ( (array) $input['recipients'] as $recipient ) {
				$recipient = (string) $recipient;
				if ( in_array( $recipient, $roles, true ) || preg_match( '/^(member|team):[0-9A-HJKMNP-TV-Z]{26}$/', $recipient ) || preg_match( '/^role:[a-z_]{2,40}$/', $recipient ) ) {
					$clean[] = $recipient;
				}
			}
			$data['recipients'] = wp_json_encode( $clean );
		}
		return $data;
	}

	/**
	 * Rules that apply to a record: the most specific level wins per date field.
	 *
	 * @param string               $type Entity.
	 * @param array<string, mixed> $row  Row.
	 * @return array<int, array<string, mixed>>
	 */
	public function rules_for( string $type, array $row ): array {
		$entity = $this->plugin->entities()->get( $type );
		$levels = array(
			'record'  => (int) $row['id'],
			'project' => '' !== $entity->project_field ? (int) ( $row[ $entity->project_field ] ?? 0 ) : ( 'projects' === $type ? (int) $row['id'] : 0 ),
			'client'  => '' !== $entity->client_field ? (int) ( $row[ $entity->client_field ] ?? 0 ) : ( 'clients' === $type ? (int) $row['id'] : 0 ),
		);
		$all    = $this->rules()->where(
			array(
				'target_type' => $type,
				'active'      => 1,
			),
			'position'
		);
		$by     = array();
		foreach ( $all as $rule ) {
			if ( 'template' === $rule['scope_type'] ) {
				continue;
			}
			if ( 'workspace' !== $rule['scope_type'] && ( $levels[ $rule['scope_type'] ] ?? -1 ) !== (int) $rule['scope_id'] ) {
				continue;
			}
			$by[ (string) $rule['date_field'] ][ (string) $rule['scope_type'] ][] = $rule;
		}
		$out = array();
		foreach ( $by as $levels_found ) {
			foreach ( array( 'record', 'project', 'client', 'workspace' ) as $level ) {
				if ( ! empty( $levels_found[ $level ] ) ) {
					$out = array_merge( $out, $levels_found[ $level ] );
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Re-plans pending occurrences for a record (called on every change).
	 *
	 * @param string               $type Entity.
	 * @param array<string, mixed> $row  Row.
	 * @param int|null             $now  Unix time (tests).
	 * @return int Occurrences planned.
	 */
	public function plan( string $type, array $row, ?int $now = null ): int {
		$target = $this->targets()->get( $type );
		if ( ! $target ) {
			return 0;
		}
		$now = $now ?? time();
		$this->cancel_rules_for( $type, (int) $row['id'] );
		if ( ! empty( $row['deleted_at'] ) || ! empty( $row['archived_at'] ) ) {
			return 0;
		}
		$planned = 0;
		foreach ( $this->rules_for( $type, $row ) as $rule ) {
			if ( $this->stopped( $target, $rule, $row ) ) {
				continue;
			}
			$due = $this->first_due( $type, $target, $rule, $row, $now );
			if ( null === $due ) {
				continue;
			}
			$this->occurrences()->insert(
				array(
					'rule_id'     => (int) $rule['id'],
					'object_type' => $type,
					'object_id'   => (int) $row['id'],
					'occurrence'  => $due[1],
					'due_at'      => gmdate( 'Y-m-d H:i:s', $due[0] ),
					'status'      => 'pending',
				)
			);
			++$planned;
		}
		return $planned;
	}

	/**
	 * First future due time for a rule on a record, with its occurrence number.
	 *
	 * @param string               $type   Entity.
	 * @param array<string, mixed> $target Target.
	 * @param array<string, mixed> $rule   Rule.
	 * @param array<string, mixed> $row    Row.
	 * @param int                  $now    Unix time.
	 * @return array{0:int,1:int}|null
	 */
	private function first_due( string $type, array $target, array $rule, array $row, int $now ): ?array {
		$base = $this->base_time( $type, $target, $rule, $row );
		if ( null === $base ) {
			return null;
		}
		$due        = $this->apply_offset( $base, $rule );
		$occurrence = 1;
		$repeat     = (int) $rule['repeat_minutes'] * MINUTE_IN_SECONDS;
		$max        = (int) $rule['max_count'];
		// Missed times: repeat rules catch up to the next future slot; one-off reminders in the past are dropped.
		if ( $due < $now - HOUR_IN_SECONDS ) {
			if ( $repeat <= 0 ) {
				return null;
			}
			$steps       = (int) ceil( ( $now - $due ) / $repeat );
			$due        += $steps * $repeat;
			$occurrence += $steps;
			if ( $max > 0 && $occurrence > $max ) {
				return null;
			}
		}
		return array( $due, $occurrence );
	}

	/**
	 * The anchor moment of a rule: date-only fields at the rule time (default 09:00) in the recipient zone.
	 *
	 * @param string               $type   Entity.
	 * @param array<string, mixed> $target Target.
	 * @param array<string, mixed> $rule   Rule.
	 * @param array<string, mixed> $row    Row.
	 * @return \DateTimeImmutable|null
	 */
	public function base_time( string $type, array $target, array $rule, array $row ): ?\DateTimeImmutable {
		$field = (string) $rule['date_field'];
		$value = $row[ $field ] ?? null;
		if ( str_starts_with( $field, 'custom.' ) ) {
			$values = $this->plugin->get( \Cliezoo\Platform\CustomFields\CustomFields::class )->values_for( $this->plugin->entities()->get( $type ), array( (int) $row['id'] ) );
			$value  = $values[ (int) $row['id'] ][ substr( $field, 7 ) ] ?? null;
			$value  = is_string( $value ) ? str_replace( array( 'T', 'Z' ), array( ' ', '' ), $value ) : null;
		}
		if ( empty( $value ) || '0000-00-00' === substr( (string) $value, 0, 10 ) ) {
			return null;
		}
		$value     = (string) $value;
		$date_only = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) || ( str_ends_with( $value, ' 00:00:00' ) && str_starts_with( $field, 'custom.' ) );
		if ( ! $date_only ) {
			$moment = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
			if ( 'on' === $rule['anchor'] && ! empty( $rule['at_time'] ) ) {
				$zone   = $this->zone( $target, $row );
				$local  = $moment->setTimezone( $zone );
				$moment = $local->setTime( (int) substr( (string) $rule['at_time'], 0, 2 ), (int) substr( (string) $rule['at_time'], 3, 2 ) );
			}
			return $moment;
		}
		$zone = $this->zone( $target, $row );
		$time = ! empty( $rule['at_time'] ) ? (string) $rule['at_time'] : '09:00';
		return new \DateTimeImmutable( substr( $value, 0, 10 ) . ' ' . $time . ':00', $zone );
	}

	/**
	 * Applies before/after offsets (calendar-aware: "1 month" and "1 day" keep local wall time across DST).
	 *
	 * @param \DateTimeImmutable   $base Anchor.
	 * @param array<string, mixed> $rule Rule.
	 * @return int Unix time.
	 */
	public function apply_offset( \DateTimeImmutable $base, array $rule ): int {
		$minutes = (int) $rule['offset_minutes'];
		if ( 'on' === $rule['anchor'] || 0 === $minutes ) {
			return $base->getTimestamp();
		}
		$sign = 'before' === $rule['anchor'] ? '-' : '+';
		if ( 'months' === ( $rule['offset_unit'] ?? '' ) ) {
			// "1 month before 31 March" is 28 or 29 February, not 1 March:
			// PHP's "-1 month" overflows, so step to the first of the month,
			// move, and keep the day where the month has it.
			$months = max( 1, (int) round( $minutes / self::MONTH ) );
			$day    = (int) $base->format( 'j' );
			$moved  = $base->setDate( (int) $base->format( 'Y' ), (int) $base->format( 'n' ), 1 )->modify( $sign . $months . ' months' );
			return $moved->setDate( (int) $moved->format( 'Y' ), (int) $moved->format( 'n' ), min( $day, (int) $moved->format( 't' ) ) )->getTimestamp();
		}
		if ( 0 === $minutes % 1440 ) {
			// Whole days: move the calendar date so 09:00 stays 09:00 across DST changes.
			return $base->modify( $sign . intdiv( $minutes, 1440 ) . ' days' )->getTimestamp();
		}
		return $base->modify( $sign . $minutes . ' minutes' )->getTimestamp();
	}

	/**
	 * Recipient time zone for a record (target callback → workspace).
	 *
	 * @param array<string, mixed> $target Target.
	 * @param array<string, mixed> $row    Row.
	 * @return \DateTimeZone
	 */
	private function zone( array $target, array $row ): \DateTimeZone {
		$zone = is_callable( $target['timezone'] ) ? (string) call_user_func( $target['timezone'], $row ) : '';
		if ( '' === $zone ) {
			$zone = (string) $this->plugin->settings()->get( 'workspace', 'timezone', '' );
		}
		try {
			return '' !== $zone ? new \DateTimeZone( $zone ) : wp_timezone();
		} catch ( \Exception $e ) {
			return wp_timezone();
		}
	}

	/**
	 * Whether a stop condition is met (status in the rule's or target's stop list).
	 *
	 * @param array<string, mixed> $target Target.
	 * @param array<string, mixed> $rule   Rule.
	 * @param array<string, mixed> $row    Row.
	 * @return bool
	 */
	private function stopped( array $target, array $rule, array $row ): bool {
		$stop   = null === $rule['stop_statuses'] ? (array) $target['stop'] : (array) json_decode( (string) $rule['stop_statuses'], true );
		$column = (string) $target['status'];
		return '' !== $column && isset( $row[ $column ] ) && in_array( (string) $row[ $column ], array_map( 'strval', $stop ), true );
	}

	/**
	 * Cancels pending rule occurrences of a record (personal reminders stay).
	 *
	 * @param string $type Entity.
	 * @param int    $id   Row id.
	 * @return void
	 */
	private function cancel_rules_for( string $type, int $id ): void {
		global $wpdb;
		$table = esc_sql( $this->plugin->entities()->get( 'reminders' )->table() );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE object_type = %s AND object_id = %d AND rule_id IS NOT NULL AND status IN ('pending','paused')", $type, $id ) );
	}

	/**
	 * Cancels every pending occurrence of a record (trashed, archived, deleted).
	 *
	 * @param string $type Entity.
	 * @param int    $id   Row id.
	 * @return void
	 */
	public function cancel_for( string $type, int $id ): void {
		global $wpdb;
		$table = esc_sql( $this->plugin->entities()->get( 'reminders' )->table() );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'cancelled' WHERE object_type = %s AND object_id = %d AND status IN ('pending','paused')", $type, $id ) );
	}

	/**
	 * Sends due reminders (every minute, batch of 50).
	 *
	 * @param int|null $now Unix time (tests).
	 * @return int Sent.
	 */
	public function process_due( ?int $now = null ): int {
		global $wpdb;
		$now   = $now ?? time();
		$table = esc_sql( $this->plugin->entities()->get( 'reminders' )->table() );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'pending' AND due_at <= %s ORDER BY due_at LIMIT %d", gmdate( 'Y-m-d H:i:s', $now ), self::BATCH ) );
		$sent  = 0;
		foreach ( (array) $ids as $id ) {
			$sent += $this->deliver( (int) $id, $now ) ? 1 : 0;
		}
		return $sent;
	}

	/**
	 * Delivers one occurrence and schedules the next repeat.
	 *
	 * @param int  $id    Occurrence id.
	 * @param int  $now   Unix time.
	 * @param bool $force Send even when not due or paused ("Send now").
	 * @return bool
	 */
	public function deliver( int $id, int $now, bool $force = false ): bool {
		$repo       = $this->occurrences();
		$occurrence = $repo->find( $id );
		if ( ! $occurrence ) {
			return false;
		}
		$this->now = $now;
		$claimable = $force ? array( 'pending', 'paused' ) : array( 'pending' );
		if ( ! in_array( $occurrence['status'], $claimable, true ) ) {
			return false;
		}
		// Claim (unique transition) so two runners never send the same reminder.
		if ( 1 !== $repo->update_where(
			array(
				'status'  => 'sent',
				'sent_at' => gmdate( 'Y-m-d H:i:s', $now ),
			),
			array(
				'id'     => $id,
				'status' => $occurrence['status'],
			)
		) ) {
			return false;
		}
		$type = (string) $occurrence['object_type'];
		if ( ! $this->plugin->entities()->has( $type ) ) {
			$repo->update( $id, array( 'status' => 'cancelled' ) );
			return false;
		}
		$entity = $this->plugin->entities()->get( $type );
		$row    = $this->plugin->service( $type )->repository()->find( (int) $occurrence['object_id'] );
		if ( ! $row || ( $entity->soft_delete && ( ! empty( $row['deleted_at'] ) || ! empty( $row['archived_at'] ) ) ) ) {
			$repo->update( $id, array( 'status' => 'cancelled' ) );
			return false;
		}
		$path  = '' !== $entity->path ? str_replace( '{uid}', (string) $row['uid'], $entity->path ) : '';
		$title = $entity->has_column( $entity->title_field ) ? wp_strip_all_tags( (string) $row[ $entity->title_field ] ) : '';

		if ( null === $occurrence['rule_id'] ) {
			// Personal reminder.
			$this->plugin->get( Notifier::class )->notify(
				'reminder',
				array( (int) $occurrence['member_id'] ),
				array(
					/* translators: %s: record title. */
					'title'  => sprintf( __( 'Reminder: %s', 'cliezoo' ), $title ),
					'body'   => (string) $occurrence['message'],
					'entity' => $entity,
					'row'    => $row,
				)
			);
			$repo->update( $id, array( 'result' => wp_json_encode( array( 'members' => 1 ) ) ) );
			return true;
		}

		$rule   = $this->rules()->find( (int) $occurrence['rule_id'] );
		$target = $this->targets()->get( $type );
		if ( ! $rule || ! $target || ! (int) $rule['active'] ) {
			$repo->update( $id, array( 'status' => 'cancelled' ) );
			return false;
		}
		if ( $this->stopped( $target, $rule, $row ) ) {
			$repo->update( $id, array( 'status' => 'skipped' ) );
			return false;
		}
		$result = $this->send( $entity->name, $target, $rule, $row, $title, $path, (int) $occurrence['occurrence'] );
		$repo->update( $id, array( 'result' => wp_json_encode( $result ) ) );

		$repeat = (int) $rule['repeat_minutes'];
		$max    = (int) $rule['max_count'];
		$next   = (int) $occurrence['occurrence'] + 1;
		if ( $repeat > 0 && ( 0 === $max || $next <= $max ) ) {
			$due = strtotime( (string) $occurrence['due_at'] . ' UTC' ) + $repeat * MINUTE_IN_SECONDS;
			while ( $due <= $now ) {
				$due += $repeat * MINUTE_IN_SECONDS;
				++$next;
			}
			if ( 0 === $max || $next <= $max ) {
				$repo->insert(
					array(
						'rule_id'     => (int) $rule['id'],
						'object_type' => $type,
						'object_id'   => (int) $row['id'],
						'occurrence'  => $next,
						'due_at'      => gmdate( 'Y-m-d H:i:s', $due ),
						'status'      => 'pending',
					)
				);
			}
		}
		/**
		 * A reminder was sent (activity, escalation).
		 *
		 * @param array $rule   Rule.
		 * @param array $row    Record row.
		 * @param array $result Delivery result.
		 */
		do_action( 'cliezoo_reminder_sent', $rule, $row, $result );
		return true;
	}

	/**
	 * Resolves recipients and sends through the rule's channels.
	 *
	 * @param string               $type       Entity.
	 * @param array<string, mixed> $target     Target.
	 * @param array<string, mixed> $rule       Rule.
	 * @param array<string, mixed> $row        Row.
	 * @param string               $title      Record title.
	 * @param string               $path       App path.
	 * @param int                  $occurrence Occurrence number.
	 * @return array<string, int>
	 */
	private function send( string $type, array $target, array $rule, array $row, string $title, string $path, int $occurrence ): array {
		$wanted   = null === $rule['recipients'] ? array() : (array) json_decode( (string) $rule['recipients'], true );
		$resolved = is_callable( $target['recipients'] ) ? (array) call_user_func( $target['recipients'], $row, $wanted ) : array();
		$members  = array_map( 'intval', (array) ( $resolved['members'] ?? array() ) );
		$emails   = (array) ( $resolved['emails'] ?? array() );
		// A target that knows where its people are says so, so the reminder
		// reads in their own time.
		$zones = (array) ( $resolved['zones'] ?? array() );
		foreach ( $wanted as $recipient ) {
			if ( 'watchers' === $recipient ) {
				$members = array_merge( $members, $this->plugin->get( Notifier::class )->watchers( $type, (int) $row['id'] ) );
			} elseif ( 'client_contact' === $recipient ) {
				$contact = $this->client_contact( $type, $row );
				if ( $contact && ! empty( $contact['member'] ) ) {
					$members[] = (int) $contact['member'];
				} elseif ( $contact ) {
					$emails[ $contact['email'] ] = $contact['name'];
					$zones[ $contact['email'] ]  = $contact['timezone'];
				}
			} elseif ( str_starts_with( (string) $recipient, 'member:' ) ) {
				$member = $this->plugin->service( 'members' )->repository()->find_by_uid( substr( (string) $recipient, 7 ) );
				if ( $member ) {
					$members[] = (int) $member['id'];
				}
			} elseif ( str_starts_with( (string) $recipient, 'role:' ) ) {
				foreach ( $this->plugin->service( 'members' )->repository()->where(
					array(
						'role'   => substr( (string) $recipient, 5 ),
						'status' => 'active',
					)
				) as $member ) {
					$members[] = (int) $member['id'];
				}
			}
		}
		/**
		 * Resolve team recipients (teams, from add-ons): returns member ids.
		 *
		 * @param int[] $members Members.
		 * @param array $wanted  Wanted recipients.
		 */
		$members  = array_values( array_unique( (array) apply_filters( 'cliezoo_reminder_members', $members, $wanted ) ) );
		$channels = null === $rule['channels'] ? array( 'inapp', 'email' ) : (array) json_decode( (string) $rule['channels'], true );
		$context  = is_callable( $target['context'] ) ? (array) call_user_func( $target['context'], $row ) : $this->default_context( $type, $row );
		$message  = (string) $rule['message'];
		// A rule with nothing written on it still says something. The
		// default lives here rather than in the template's "{{message|…}}",
		// where translating it would mean translating into the middle of a
		// Mustache expression.
		$message = '' !== $message
			? \Cliezoo\Infrastructure\Mail\TemplateEngine::render( $message, $context + array( 'occurrence' => $occurrence ), false )
			: __( 'This is a friendly reminder.', 'cliezoo' );
		/**
		 * Rewrites the reminder message (for add-ons).
		 *
		 * @param string $message    Message.
		 * @param array  $rule       Rule.
		 * @param array  $row        Record.
		 * @param int    $occurrence Occurrence number.
		 * @param array  $context    The values its words may use.
		 */
		$message = (string) apply_filters( 'cliezoo_reminder_message', $message, $rule, $row, $occurrence, $context );
		$result  = array(
			'members' => 0,
			'emails'  => 0,
		);
		if ( $members && array_intersect( $channels, array( 'inapp', 'email' ) ) ) {
			$result['members'] = $this->plugin->get( Notifier::class )->notify(
				'reminder',
				$members,
				array(
					/* translators: %s: record title. */
					'title'  => '' !== (string) $rule['subject'] ? (string) $rule['subject'] : sprintf( __( 'Reminder: %s', 'cliezoo' ), $title ),
					'body'   => $message,
					'path'   => $path,
					'entity' => $this->plugin->entities()->get( $type ),
					'row'    => $row,
				)
			);
		}
		if ( $emails && in_array( 'email', $channels, true ) ) {
			$template = '' !== (string) $rule['email_template'] ? (string) $rule['email_template'] : (string) $target['email'];
			$mailer   = $this->plugin->get( Mailer::class );
			foreach ( $emails as $email => $name ) {
				$data = $context + array(
					'recipient_name' => (string) $name,
					'message'        => $message,
					'is_client'      => true,
					'record'         => array( 'title' => $title ),
				);
				$meta = array(
					'object_type' => $type,
					'object_id'   => (int) $row['id'],
					'client_id'   => $row['client_id'] ?? null,
					'category'    => 'reminder',
				);
				// Somebody outside the team has no notification settings, so
				// the workspace's working hours stand in for theirs, in their
				// own time zone (cliezoo.md §6.46, "Quiet hours").
				$zone = $zones[ $email ] ?? '';
				$hold = $this->external_hold( '' !== $zone ? $zone : $this->zone( $target, $row )->getName() );
				// Never held past the moment it is a reminder of: an hour
				// before an eight o'clock call, held for nine, arrives after
				// the call. Sent now instead, early rather than useless.
				if ( $hold > 0 && 'before' === (string) ( $rule['anchor'] ?? '' ) ) {
					$moment = (string) ( $row[ (string) ( $rule['date_field'] ?? '' ) ] ?? '' );
					$at     = '' !== $moment ? strtotime( $moment . ( str_contains( $moment, ':' ) ? ' UTC' : ' 23:59:59 UTC' ) ) : false;
					if ( $at && ( $this->now ?? time() ) + $hold >= $at ) {
						$hold = 0;
					}
				}
				// Without a job runner there is nothing to hold it with, and a
				// reminder sent at night beats one never sent.
				if ( $hold > 0 && $mailer->queue( $template, (string) $email, $data, $meta, $hold ) > 0 ) {
					$result['held'] = ( $result['held'] ?? 0 ) + 1;
					continue;
				}
				$sent              = $mailer->send_template( $template, (string) $email, $data, $meta );
				$result['emails'] += $sent ? 1 : 0;
			}
		}
		/**
		 * Extra channels (from add-ons).
		 *
		 * @param array  $result   Result counters.
		 * @param array  $channels Channels.
		 * @param array  $rule     Rule.
		 * @param array  $row      Row.
		 * @param string $message  Message.
		 */
		return (array) apply_filters( 'cliezoo_reminder_channels', $result, $channels, $rule, $row, $message );
	}

	/**
	 * Seconds to hold an email to somebody outside the team, so it arrives
	 * in their working hours (0 = send now).
	 *
	 * @param string $zone Recipient time zone.
	 * @return int
	 */
	public function external_hold( string $zone ): int {
		$settings = $this->plugin->settings();
		if ( ! $settings->get( 'reminders', 'external_hold', true ) ) {
			return 0;
		}
		try {
			$tz = new \DateTimeZone( '' !== $zone ? $zone : wp_timezone_string() );
		} catch ( \Exception $e ) {
			$tz = wp_timezone();
		}
		$now     = $this->now ?? time();
		$local   = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		$release = Notifier::work_release(
			$local,
			array(
				'work_start' => (string) $settings->get( 'reminders', 'external_work_start', '08:00' ),
				'work_end'   => (string) $settings->get( 'reminders', 'external_work_end', '18:00' ),
				'work_days'  => (array) $settings->get( 'reminders', 'external_work_days', array( 1, 2, 3, 4, 5 ) ),
			)
		);
		return $release ? max( 0, $release->getTimestamp() - $now ) : 0;
	}

	/**
	 * The client's main contact for a record: a portal member when they
	 * have a live portal account, otherwise their email address.
	 *
	 * @param string               $type Entity.
	 * @param array<string, mixed> $row  Record.
	 * @return array{member: int, email: string, name: string, timezone: string}|null
	 */
	private function client_contact( string $type, array $row ): ?array {
		if ( ! $this->plugin->entities()->has( 'contacts' ) || ! $this->plugin->entities()->has( $type ) ) {
			return null;
		}
		$entity = $this->plugin->entities()->get( $type );
		$client = 'clients' === $type ? (int) $row['id'] : ( '' !== $entity->client_field ? (int) ( $row[ $entity->client_field ] ?? 0 ) : 0 );
		if ( ! $client ) {
			return null;
		}
		$repo    = $this->plugin->service( 'contacts' )->repository();
		$contact = $repo->first(
			array(
				'client_id'  => $client,
				'is_primary' => 1,
			)
		);
		$contact = $contact ? $contact : ( $repo->where( array( 'client_id' => $client ), 'id', 1 )[0] ?? null );
		if ( ! $contact || empty( $contact['email'] ) ) {
			return null;
		}
		return array(
			'member'   => 'active' === (string) ( $contact['portal_status'] ?? '' ) ? (int) ( $contact['member_id'] ?? 0 ) : 0,
			'email'    => (string) $contact['email'],
			'name'     => (string) ( '' !== (string) ( $contact['preferred_name'] ?? '' ) ? $contact['preferred_name'] : $contact['name'] ),
			'timezone' => (string) ( $contact['timezone'] ?? '' ),
		);
	}

	/**
	 * Copies the workspace schedule of a record type into a named template
	 * ("Standard dunning"), replacing a template of the same name.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $type  Record type.
	 * @param string $name  Template name.
	 * @return array{saved: int}
	 * @throws ActionError When there is nothing to save or it may not be saved.
	 */
	public function save_template( Actor $actor, string $type, string $name ): array {
		if ( ! $actor->is_admin() && ! $actor->allows( 'settings_general' ) ) {
			throw ActionError::forbidden();
		}
		$name  = trim( sanitize_text_field( $name ) );
		$rules = $this->rules()->where(
			array(
				'target_type' => $type,
				'scope_type'  => 'workspace',
			),
			'position'
		);
		if ( '' === $name || ! $rules ) {
			throw ActionError::validation( __( 'Add at least one reminder and give the template a name.', 'cliezoo' ) );
		}
		$this->rules()->delete_where(
			array(
				'target_type'   => $type,
				'scope_type'    => 'template',
				'schedule_name' => $name,
			)
		);
		$service = $this->plugin->service( 'reminder_rules' );
		foreach ( $rules as $rule ) {
			$input = array(
				'target_type'    => $rule['target_type'],
				'date_field'     => $rule['date_field'],
				'scope_type'     => 'template',
				'schedule_name'  => $name,
				'offset_minutes' => (int) $rule['offset_minutes'],
				'offset_unit'    => $rule['offset_unit'] ?? null,
				'anchor'         => $rule['anchor'],
				'at_time'        => $rule['at_time'],
				'repeat_minutes' => (int) $rule['repeat_minutes'],
				'max_count'      => (int) $rule['max_count'],
				'email_template' => $rule['email_template'],
				'subject'        => $rule['subject'],
				'message'        => $rule['message'],
				'recipients'     => null === $rule['recipients'] ? array() : json_decode( (string) $rule['recipients'], true ),
				'channels'       => null === $rule['channels'] ? array() : json_decode( (string) $rule['channels'], true ),
				'active'         => (bool) $rule['active'],
				'position'       => (int) $rule['position'],
			);
			if ( null !== $rule['stop_statuses'] ) {
				$input['stop_statuses'] = json_decode( (string) $rule['stop_statuses'], true );
			}
			$service->create_row( $actor, $input );
		}
		return array( 'saved' => count( $rules ) );
	}

	/**
	 * Template variables from the record itself (visible, non-finance fields).
	 *
	 * @param string               $type Entity.
	 * @param array<string, mixed> $row  Row.
	 * @return array<string, mixed>
	 */
	private function default_context( string $type, array $row ): array {
		$entity  = $this->plugin->entities()->get( $type );
		$context = array( 'number' => $row['number'] ?? null );
		foreach ( $entity->fields as $column => $field ) {
			if ( $field->hidden || $field->finance || in_array( $field->type, array( 'json', 'ref', 'user' ), true ) ) {
				continue;
			}
			$context[ $entity->input_key( $column ) ] = $row[ $column ] ?? null;
		}
		return $context;
	}

	/**
	 * Re-plans records after a rule changed (queued in batches of 200).
	 *
	 * @param array<string, mixed> $rule Rule row.
	 * @return void
	 */
	public function replan_rule( array $rule ): void {
		if ( 'template' === $rule['scope_type'] ) {
			return;
		}
		$this->plugin->get( Jobs::class )->queue(
			'reminders_replan',
			array(
				'type'  => (string) $rule['target_type'],
				'after' => 0,
			),
			0,
			true
		);
	}

	/**
	 * One replan batch.
	 *
	 * @param array<string, mixed> $args type, after.
	 * @return void
	 */
	public function replan_batch( array $args ): void {
		global $wpdb;
		$type = (string) ( $args['type'] ?? '' );
		if ( ! $this->plugin->entities()->has( $type ) ) {
			return;
		}
		$entity = $this->plugin->entities()->get( $type );
		$where  = $entity->soft_delete ? ' AND deleted_at IS NULL AND archived_at IS NULL' : '';
		$ids    = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$entity->table()} WHERE id > %d{$where} ORDER BY id LIMIT 200", (int) ( $args['after'] ?? 0 ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		foreach ( $this->plugin->service( $type )->repository()->find_many( $ids ) as $row ) {
			$this->plan( $type, $row );
		}
		if ( 200 === count( $ids ) ) {
			$this->plugin->get( Jobs::class )->queue(
				'reminders_replan',
				array(
					'type'  => $type,
					'after' => (int) end( $ids ),
				)
			);
		}
	}

	/**
	 * Reminders panel for a record.
	 *
	 * @param Actor                $actor Actor.
	 * @param string               $type  Entity.
	 * @param array<string, mixed> $row   Row.
	 * @return array<string, mixed>
	 */
	public function for_record( Actor $actor, string $type, array $row ): array {
		$items = $this->occurrences()->where(
			array(
				'object_type' => $type,
				'object_id'   => (int) $row['id'],
			),
			'-due_at',
			100
		);
		$out   = array();
		foreach ( $items as $item ) {
			if ( null === $item['rule_id'] && (int) $item['member_id'] !== $actor->member_id ) {
				continue;
			}
			$out[] = $this->present_occurrence( $item );
		}
		$rules = array();
		foreach ( $this->rules_for( $type, $row ) as $rule ) {
			$rules[] = $this->plugin->service( 'reminder_rules' )->present( $actor, $rule );
		}
		return array(
			'reminders' => $out,
			'rules'     => $rules,
		);
	}

	/**
	 * Presents one occurrence.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return array<string, mixed>
	 */
	private function present_occurrence( array $item ): array {
		return array(
			'uid'        => (string) $item['uid'],
			'personal'   => null === $item['rule_id'],
			'occurrence' => (int) $item['occurrence'],
			'due_at'     => Serializer::iso( $item['due_at'] ),
			'status'     => (string) $item['status'],
			'sent_at'    => Serializer::iso( $item['sent_at'] ),
			'message'    => $item['message'],
			'result'     => null === $item['result'] ? null : json_decode( (string) $item['result'], true ),
		);
	}

	/**
	 * Presents occurrences with a chip for the record each one is about, in
	 * one query per record type.
	 *
	 * @param array<int, array<string, mixed>> $rows Occurrence rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function with_records( array $rows ): array {
		$ids = array();
		foreach ( $rows as $row ) {
			$ids[ (string) $row['object_type'] ][] = (int) $row['object_id'];
		}
		$refs = array();
		foreach ( $ids as $type => $list ) {
			$refs[ $type ] = ObjectRef::refs( $this->plugin, $type, $list );
		}
		$out = array();
		foreach ( $rows as $row ) {
			$ref   = $refs[ (string) $row['object_type'] ][ (int) $row['object_id'] ] ?? null;
			$out[] = $this->present_occurrence( $row ) + array( 'record' => $ref ? array( 'type' => (string) $row['object_type'] ) + $ref : null );
		}
		return $out;
	}

	/**
	 * The actor's personal reminders (My reminders).
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    Paging.
	 * @return array<string, mixed>
	 */
	public function mine( Actor $actor, array $in ): array {
		$query         = new ListQuery();
		$query->limit  = (int) ( $in['limit'] ?? 50 );
		$query->cursor = (string) ( $in['cursor'] ?? '' );
		$query->sort   = 'due_at';
		$query->where( 't.member_id = %d AND t.status IN (%s, %s)', array( $actor->member_id, 'pending', 'paused' ) );
		$result = $this->occurrences()->list( $query, null );
		return array(
			'data' => $this->with_records( $result['rows'] ),
			'meta' => array(
				'next_cursor' => $result['next_cursor'],
				'has_more'    => $result['has_more'],
			),
		);
	}

	/**
	 * Creates a personal reminder.
	 *
	 * @param Actor                $actor   Actor.
	 * @param string               $type    Entity.
	 * @param array<string, mixed> $row     Record.
	 * @param string               $at      ISO date-time.
	 * @param string               $message Message.
	 * @return array<string, mixed>
	 * @throws ActionError When the time is invalid.
	 */
	public function personal( Actor $actor, string $type, array $row, string $at, string $message ): array {
		$time = strtotime( $at );
		if ( false === $time ) {
			throw ActionError::validation( __( 'Please choose a date and time.', 'cliezoo' ) );
		}
		$created = $this->occurrences()->insert(
			array(
				'object_type' => $type,
				'object_id'   => (int) $row['id'],
				'member_id'   => $actor->member_id,
				'occurrence'  => 1,
				'due_at'      => gmdate( 'Y-m-d H:i:s', $time ),
				'status'      => 'pending',
				'message'     => mb_substr( sanitize_text_field( $message ), 0, 500 ),
			),
			$actor->member_id
		);
		return $this->present_occurrence( $created );
	}

	/**
	 * Pause / resume / skip / send now.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $uid   Occurrence uid.
	 * @param string $verb  pause|resume|skip|send.
	 * @return array<string, mixed>
	 * @throws ActionError When not allowed.
	 */
	public function control( Actor $actor, string $uid, string $verb ): array {
		$repo = $this->occurrences();
		$item = $repo->find_by_uid( $uid );
		if ( ! $item ) {
			throw ActionError::not_found();
		}
		if ( null === $item['rule_id'] ) {
			if ( (int) $item['member_id'] !== $actor->member_id ) {
				throw ActionError::not_found();
			}
		} else {
			$parent = $this->plugin->policy()->parent_row( (string) $item['object_type'], (int) $item['object_id'] );
			if ( ! $parent || ! $this->plugin->policy()->can( $actor, Policy::UPDATE, $parent[0], $parent[1] ) ) {
				throw ActionError::forbidden();
			}
		}
		switch ( $verb ) {
			case 'pause':
				$repo->update_where(
					array( 'status' => 'paused' ),
					array(
						'id'     => (int) $item['id'],
						'status' => 'pending',
					)
				);
				break;
			case 'resume':
				$repo->update_where(
					array( 'status' => 'pending' ),
					array(
						'id'     => (int) $item['id'],
						'status' => 'paused',
					)
				);
				break;
			case 'skip':
				$repo->update( (int) $item['id'], array( 'status' => 'skipped' ) );
				break;
			default:
				if ( ! $this->deliver( (int) $item['id'], time(), true ) ) {
					throw ActionError::conflict( __( 'This reminder could not be sent (it may already be sent or the record is closed).', 'cliezoo' ) );
				}
		}
		return $this->present_occurrence( (array) $repo->find( (int) $item['id'] ) );
	}

	/**
	 * Log of sent reminders (admins).
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    Paging.
	 * @return array<string, mixed>
	 */
	public function log( Actor $actor, array $in ): array {
		$query         = new ListQuery();
		$query->limit  = (int) ( $in['limit'] ?? 50 );
		$query->cursor = (string) ( $in['cursor'] ?? '' );
		$query->sort   = '-created_at';
		$query->where( "t.status IN ('sent','skipped','failed')" );
		$result = $this->occurrences()->list( $query, null );
		return array(
			'data' => $this->with_records( $result['rows'] ),
			'meta' => array(
				'next_cursor' => $result['next_cursor'],
				'has_more'    => $result['has_more'],
			),
		);
	}

	/**
	 * Copies a saved schedule ("Standard dunning") to a scope.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    schedule, scope_type, object_type, object.
	 * @return array{created: int}
	 * @throws ActionError When the template is unknown.
	 */
	public function apply_template( Actor $actor, array $in ): array {
		$template = $this->rules()->where(
			array(
				'scope_type'    => 'template',
				'schedule_name' => (string) $in['schedule'],
			),
			'position'
		);
		if ( ! $template ) {
			throw ActionError::not_found();
		}
		$service = $this->plugin->service( 'reminder_rules' );
		$count   = 0;
		foreach ( $template as $rule ) {
			$input = array(
				'target_type'    => $rule['target_type'],
				'date_field'     => $rule['date_field'],
				'scope_type'     => (string) $in['scope_type'],
				'schedule_name'  => $rule['schedule_name'],
				'offset_minutes' => (int) $rule['offset_minutes'],
				'offset_unit'    => $rule['offset_unit'] ?? null,
				'anchor'         => $rule['anchor'],
				'at_time'        => $rule['at_time'],
				'repeat_minutes' => (int) $rule['repeat_minutes'],
				'max_count'      => (int) $rule['max_count'],
				'email_template' => $rule['email_template'],
				'subject'        => $rule['subject'],
				'message'        => $rule['message'],
				'recipients'     => null === $rule['recipients'] ? array() : json_decode( (string) $rule['recipients'], true ),
				'channels'       => null === $rule['channels'] ? array() : json_decode( (string) $rule['channels'], true ),
				'position'       => (int) $rule['position'],
			);
			if ( null !== $rule['stop_statuses'] ) {
				$input['stop_statuses'] = json_decode( (string) $rule['stop_statuses'], true );
			}
			if ( 'workspace' !== $in['scope_type'] ) {
				$input['scope_object'] = (string) ( $in['object'] ?? '' );
			}
			$service->create_row( $actor, $input );
			++$count;
		}
		Logger::debug(
			'Reminder template applied',
			array(
				'schedule' => $in['schedule'],
				'rules'    => $count,
			)
		);
		return array( 'created' => $count );
	}
}
