<?php
/**
 * Universal reminder engine module.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Reminders;

use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Field;
use Cliezoo\Core\Data\ObjectRef;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rules (`reminder_rules`) at workspace, client, project, record and template
 * level; concrete occurrences (`reminders`) with a UTC `due_at`, processed by
 * one per-minute job (cliezoo.md §6.46, Blueprint §16.2).
 */
final class RemindersModule extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'reminders';
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
				'reminder_rules',
				array(
					'target_type'    => Field::text( 40 )->required()->filterable(),
					'date_field'     => Field::text( 60 )->required(),
					'scope_type'     => Field::enum( array( 'workspace', 'client', 'project', 'record', 'template' ) )->default( 'workspace' )->filterable(),
					'scope_id'       => Field::int(),
					'schedule_name'  => Field::text( 120 ),
					'offset_minutes' => Field::int()->default( 0 ),
					// minutes|hours|days|weeks|months: how the offset was
					// chosen. Months move by the calendar, the rest by time.
					'offset_unit'    => Field::text( 10 ),
					'anchor'         => Field::enum( array( 'before', 'after', 'on' ) )->default( 'before' ),
					'at_time'        => Field::text( 5 ),
					'repeat_minutes' => Field::int()->default( 0 ),
					'max_count'      => Field::int()->default( 0 ),
					'stop_statuses'  => Field::json(),
					'recipients'     => Field::json(),
					'channels'       => Field::json(),
					'email_template' => Field::text( 60 ),
					'subject'        => Field::text( 200 ),
					'message'        => Field::longtext(),
					'escalate'       => Field::bool(),
					'active'         => Field::bool()->default( true )->filterable(),
					'position'       => Field::int()->default( 0 ),
				)
			) )->with(
				array(
					'indexes'      => array( 'target_scope' => array( 'target_type', 'scope_type', 'scope_id', 'active' ) ),
					'permission'   => 'reminders',
					'title_field'  => 'schedule_name',
					'default_sort' => 'position',
					'label'        => __( 'Reminder rule', 'cliezoo' ),
					'plural_label' => __( 'Reminder rules', 'cliezoo' ),
				)
			),
			( new Entity(
				'reminders',
				array(
					'rule_id'     => Field::int(),
					'object_type' => Field::text( 40 )->required(),
					'object_id'   => Field::int()->required(),
					'member_id'   => Field::int(),
					'occurrence'  => Field::int()->default( 1 ),
					'due_at'      => Field::datetime()->required()->sortable(),
					'status'      => Field::enum( array( 'pending', 'sent', 'skipped', 'paused', 'cancelled', 'failed' ) )->default( 'pending' )->filterable(),
					'sent_at'     => Field::datetime(),
					'message'     => Field::text( 500 ),
					'result'      => Field::json(),
				)
			) )->with(
				array(
					'soft_delete' => false,
					'activity'    => false,
					'unique'      => array( 'occurrence' => array( 'rule_id', 'object_type', 'object_id', 'occurrence', 'member_id' ) ),
					'indexes'     => array(
						'due'    => array( 'status', 'due_at' ),
						'object' => array( 'object_type', 'object_id', 'due_at' ),
						'member' => array( 'member_id', 'status', 'due_at' ),
					),
					'permission'  => 'reminders',
				)
			),
		);
	}

	/**
	 * Registers the engine.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( ReminderTargets::class, static fn(): ReminderTargets => new ReminderTargets() );
		$plugin->container()->set( Reminders::class, static fn(): Reminders => new Reminders( $plugin ) );
		$engine = static fn(): Reminders => $plugin->get( Reminders::class );

		add_action(
			'cliezoo_every_minute',
			static function () use ( $engine ): void {
				$engine()->process_due();
			}
		);
		foreach ( array( 'created', 'updated', 'restored', 'unarchived' ) as $event ) {
			add_action(
				'cliezoo_record_' . $event,
				static function ( Entity $entity, array $row ) use ( $engine ): void {
					$engine()->plan( $entity->name, $row );
				},
				30,
				2
			);
		}
		foreach ( array( 'trashed', 'archived', 'purging' ) as $event ) {
			add_action(
				'cliezoo_record_' . $event,
				static function ( Entity $entity, array $row ) use ( $engine ): void {
					$engine()->cancel_for( $entity->name, (int) $row['id'] );
				},
				30,
				2
			);
		}
		add_action( 'cliezoo_reminder_rules_created', static fn( array $rule ) => $engine()->replan_rule( $rule ) );
		add_action( 'cliezoo_reminder_rules_updated', static fn( array $rule ) => $engine()->replan_rule( $rule ) );
		add_filter( 'cliezoo_reminder_rules_before_create', static fn( array $data, Actor $actor, array $input ) => $engine()->prepare_rule( $data, $actor, $input, null ), 10, 3 );
		add_filter( 'cliezoo_reminder_rules_before_update', static fn( array $data, array $row, Actor $actor, array $input ) => $engine()->prepare_rule( $data, $actor, $input, $row ), 10, 4 );
		add_filter( 'cliezoo_entity_verbs', static fn( array $verbs, Entity $entity ): array => 'reminders' === $entity->name ? array() : $verbs, 10, 2 );
		add_filter(
			'cliezoo_email_templates',
			static function ( array $templates ): array {
				$templates['reminder'] = array(
					'group'        => __( 'Reminders', 'cliezoo' ),
					'label'        => __( 'Reminder', 'cliezoo' ),
					/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
					'subject'      => __( 'Reminder: {{record.title}}', 'cliezoo' ),
					/* translators: the {{…}} are placeholders the email fills in; leave them exactly as they are, and move them if the sentence needs it. */
					'greeting'     => __( 'Hi {{recipient_name}},', 'cliezoo' ),
					// The default is supplied by Reminders::send() rather
					// than by "{{message|…}}": a translation of it would
					// otherwise sit inside a Mustache expression, where a
					// "}}" or a "|" is syntax.
					'body'         => '<p>{{message}}</p>',
					'button_label' => __( 'Open', 'cliezoo' ),
					'button_url'   => '{{url}}',
					'variables'    => array( 'recipient_name', 'message', 'record.title', 'record.date', 'url' ),
				);
				return $templates;
			}
		);
		add_action(
			'cliezoo_register_settings',
			static function ( \Cliezoo\Platform\Settings\Settings $settings ): void {
				// People outside the team (a client contact with no portal
				// account) have no notification settings of their own, so a
				// reminder email to them waits for these working hours in
				// their time zone rather than arriving at night.
				$time = array(
					'type'    => 'string',
					'pattern' => '^([01]\d|2[0-3]):[0-5]\d$',
				);
				$settings->register(
					'reminders',
					array(
						'external_hold'       => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'external_work_start' => $time + array( 'default' => '08:00' ),
						'external_work_end'   => $time + array( 'default' => '18:00' ),
						'external_work_days'  => array(
							'type'    => 'array',
							'items'   => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 7,
							),
							'default' => array( 1, 2, 3, 4, 5 ),
						),
					)
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
		$engine = static fn(): Reminders => $plugin->get( Reminders::class );
		$member = static fn( Actor $a ): bool => $a->is_staff();

		$registry->add(
			Op::make( 'reminders.targets', __( 'What can have reminders', 'cliezoo' ) )
				->allow( $member )
				->run( static fn() => $engine()->targets_for_ui() )
				->rest( 'GET', '/reminders/targets' )
				->readonly()
		);
		$registry->add(
			Op::make( 'reminders.for', __( 'Reminders of a record', 'cliezoo' ) )
				->describe( __( 'Upcoming and sent reminders of a record, with the rules that apply to it.', 'cliezoo' ) )
				->input( ObjectRef::schema() )
				->allow( $member )
				->run(
					static function ( Actor $a, array $in ) use ( $plugin, $engine ): array {
						list( $entity, $row ) = ObjectRef::resolve( $plugin, $a, (string) $in['object_type'], (string) $in['object'] );
						return $engine()->for_record( $a, $entity->name, $row );
					}
				)
				->rest( 'GET', '/reminders' )
				->readonly()
		);
		$registry->add(
			Op::make( 'reminders.mine', __( 'My reminders', 'cliezoo' ) )
				->input( Op::paging( 50 ) )
				->allow( static fn( Actor $a ): bool => $a->member_id > 0 )
				->run( static fn( Actor $a, array $in ) => $engine()->mine( $a, $in ) )
				->rest( 'GET', '/reminders/mine' )
				->readonly()
		);
		$registry->add(
			Op::make( 'reminders.set', __( 'Set a personal reminder', 'cliezoo' ) )
				->describe( __( 'Reminds you about any record at a date and time, e.g. "Remind me about this client on Monday at 10:00".', 'cliezoo' ) )
				->input(
					ObjectRef::schema() + array(
						'at'      => array(
							'type'     => 'string',
							'format'   => 'date-time',
							'required' => true,
						),
						'message' => Op::str( 500 ),
					)
				)
				->allow( static fn( Actor $a ): bool => $a->member_id > 0 )
				->run(
					static function ( Actor $a, array $in ) use ( $plugin, $engine ): array {
						list( $entity, $row ) = ObjectRef::resolve( $plugin, $a, (string) $in['object_type'], (string) $in['object'] );
						return $engine()->personal( $a, $entity->name, $row, (string) $in['at'], (string) ( $in['message'] ?? '' ) );
					}
				)
				->rest( 'POST', '/reminders/personal' )
				->with( array( 'mcp' => 'safe' ) )
		);
		foreach ( array( 'pause', 'resume', 'skip', 'send' ) as $verb ) {
			$registry->add(
				Op::make( 'reminders.' . $verb, ucfirst( $verb ) . ' ' . __( 'reminder', 'cliezoo' ) )
					->input( array( 'uid' => Op::uid() ) )
					->allow( $member )
					->run( static fn( Actor $a, array $in ) => $engine()->control( $a, (string) $in['uid'], $verb ) )
					->rest( 'POST', '/reminders/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/' . $verb )
					->with( array( 'destructive' => 'send' === $verb ) )
			);
		}
		$registry->add(
			Op::make( 'reminders.log', __( 'Reminder log', 'cliezoo' ) )
				->input( Op::paging( 50 ) )
				->allow( static fn( Actor $a ): bool => $a->is_admin() )
				->run( static fn( Actor $a, array $in ) => $engine()->log( $a, $in ) )
				->rest( 'GET', '/reminders/log' )
				->readonly()
		);
		$registry->add(
			Op::make( 'reminders.apply_template', __( 'Apply a reminder template', 'cliezoo' ) )
				->describe( __( 'Copies a saved reminder schedule (e.g. "Standard dunning") to the workspace, a client, a project or one record.', 'cliezoo' ) )
				->input(
					array(
						'schedule'    => Op::str( 120, true ),
						'scope_type'  => array(
							'type'     => 'string',
							'enum'     => array( 'workspace', 'client', 'project', 'record' ),
							'required' => true,
						),
						'object_type' => array(
							'type'    => 'string',
							'pattern' => '^[a-z_]{2,40}$',
						),
						'object'      => Op::uid( false ),
					)
				)
				->allow( $member )
				->run( static fn( Actor $a, array $in ) => $engine()->apply_template( $a, $in ) )
				->rest( 'POST', '/reminders/apply-template' )
		);
		$registry->add(
			Op::make( 'reminders.save_template', __( 'Save reminders as a template', 'cliezoo' ) )
				->describe( __( 'Saves the workspace reminders of a record type as a named schedule (e.g. "Gentle reminders") to apply again later. A template of the same name is replaced.', 'cliezoo' ) )
				->input(
					array(
						'target_type' => array(
							'type'     => 'string',
							'pattern'  => '^[a-z_]{2,40}$',
							'required' => true,
						),
						'name'        => Op::str( 120, true ),
					)
				)
				->allow( $member )
				->run( static fn( Actor $a, array $in ) => $engine()->save_template( $a, (string) $in['target_type'], (string) $in['name'] ) )
				->rest( 'POST', '/reminders/templates' )
		);
	}
}
