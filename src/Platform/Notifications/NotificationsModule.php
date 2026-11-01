<?php
/**
 * Notifications module.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Notifications;

use Cliezoo\Core\Actions\ActionError;
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
 * In-app bell + inbox rows, email delivery, preference matrix, presets,
 * digests, quiet hours and watch/unwatch (cliezoo.md §6.32).
 */
final class NotificationsModule extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'notifications';
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
				'notifications',
				array(
					'member_id'     => Field::int()->required(),
					'event'         => Field::text( 60 )->required()->filterable(),
					'tab'           => Field::text( 20 )->default( 'updates' )->filterable(),
					'title'         => Field::text( 255 )->required(),
					'body'          => Field::text( 500 ),
					'object_type'   => Field::text( 40 ),
					'object_id'     => Field::int(),
					'path'          => Field::text( 255 ),
					'actor_id'      => Field::int(),
					'read_at'       => Field::datetime(),
					'archived'      => Field::bool(),
					'snoozed_until' => Field::datetime(),
					// A person's own "not now", kept apart from quiet hours, which
					// hold back an email and never hide anything from the inbox.
					'hidden_until'  => Field::datetime(),
					'emailed'       => Field::enum( array( 'no', 'pending', 'sent', 'digest', 'skipped' ) )->default( 'no' ),
					// What makes a notice one of a kind ("task.due:12:2026-09-22"),
					// so a job that runs every hour says it once.
					'dedupe'        => Field::text( 120 )->hidden(),
				)
			) )->with(
				array(
					'label'        => __( 'Notification', 'cliezoo' ),
					'plural_label' => __( 'Notifications', 'cliezoo' ),
					'soft_delete'  => false,
					'activity'     => false,
					'indexes'      => array(
						'member_tab'  => array( 'member_id', 'archived', 'tab', 'created_at' ),
						'member_read' => array( 'member_id', 'read_at' ),
						'emailed'     => array( 'emailed', 'member_id' ),
						'dedupe'      => array( 'member_id', 'dedupe' ),
					),
					'permission'   => 'self',
				)
			),
			( new Entity(
				'notification_prefs',
				array(
					'member_id'      => Field::int()->required(),
					'preset'         => Field::text( 30 )->default( 'default' ),
					'matrix'         => Field::json(),
					'digest'         => Field::enum( array( 'instant', 'hourly', 'daily', 'weekly' ) )->default( 'instant' ),
					'digest_hour'    => Field::int()->default( 8 ),
					'quiet_start'    => Field::text( 5 ),
					'quiet_end'      => Field::text( 5 ),
					'quiet_weekends' => Field::bool(),
					// Working hours: emails wait for the start of the next
					// working day rather than arriving at nine at night.
					'work_start'     => Field::text( 5 ),
					'work_end'       => Field::text( 5 ),
					'work_days'      => Field::json(),
					'last_digest_at' => Field::datetime(),
				)
			) )->with(
				array(
					'public'      => false,
					'soft_delete' => false,
					'activity'    => false,
					'unique'      => array( 'member' => array( 'member_id' ) ),
				)
			),
			( new Entity(
				'watches',
				array(
					'member_id'   => Field::int()->required(),
					'object_type' => Field::text( 40 )->required(),
					'object_id'   => Field::int()->required(),
					'watching'    => Field::bool()->default( true ),
				)
			) )->with(
				array(
					'public'      => false,
					'soft_delete' => false,
					'activity'    => false,
					'unique'      => array( 'member_object' => array( 'member_id', 'object_type', 'object_id' ) ),
					'indexes'     => array( 'object' => array( 'object_type', 'object_id' ) ),
				)
			),
		);
	}

	/**
	 * Registers services, jobs and actions.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( EventCatalog::class, static fn(): EventCatalog => new EventCatalog() );
		$plugin->container()->set( ChannelRegistry::class, static fn(): ChannelRegistry => new ChannelRegistry( $plugin ) );
		$plugin->container()->set( Notifier::class, static fn(): Notifier => new Notifier( $plugin ) );
		$notifier = static fn(): Notifier => $plugin->get( Notifier::class );

		add_filter(
			'cliezoo_entity_verbs',
			static fn( array $verbs, Entity $entity ): array => 'notifications' === $entity->name ? array() : $verbs,
			10,
			2
		);
		add_action(
			'cliezoo_notify',
			static function ( string $event, array $members, array $payload = array() ) use ( $notifier ): void {
				$notifier()->notify( $event, $members, $payload );
			},
			10,
			3
		);
		add_action(
			'cliezoo_every_minute',
			static function () use ( $notifier ): void {
				$notifier()->release_quiet();
			}
		);
		add_action(
			'cliezoo_every_hour',
			static function () use ( $notifier ): void {
				$notifier()->send_digests();
			}
		);
		add_action(
			'cliezoo_access_granted',
			static function ( Entity $entity, array $row, string $type, int $id, string $role, Actor $actor ) use ( $notifier ): void {
				if ( 'user' === $type && $id !== $actor->member_id ) {
					$notifier()->notify(
						'access.granted',
						array( $id ),
						array(
							/* translators: 1: person, 2: record title. */
							'title'  => sprintf( __( '%1$s shared "%2$s" with you', 'cliezoo' ), $notifier()->member_name( $actor->member_id ), (string) ( $row[ $entity->title_field ] ?? '' ) ),
							'entity' => $entity,
							'row'    => $row,
							'actor'  => $actor->member_id,
						)
					);
				}
			},
			10,
			6
		);
		$plugin->container()->set( DueNotices::class, static fn(): DueNotices => new DueNotices( $plugin ) );
		$plugin->container()->set( FileNotices::class, static fn(): FileNotices => new FileNotices( $plugin ) );
		add_action(
			'cliezoo_every_hour',
			static function () use ( $plugin ): void {
				$plugin->get( DueNotices::class )->run();
			}
		);
		FileNotices::listen( $plugin );
		add_action(
			'cliezoo_jobs_late',
			static function ( int $seconds, string $kind = 'tick' ) use ( $notifier ): void {
				$notifier()->jobs_late( $seconds, $kind );
			},
			10,
			2
		);
		add_action( 'cliezoo_register_actions', static fn( ActionRegistry $registry ) => self::actions( $registry, $plugin ) );

		// The sales modules announce what happened; this is what listens. Until
		// it existed, the channel x event matrix offered a studio switches for
		// "quote accepted", "document signed" and "invoice overdue" that could
		// never produce a notification.
		$plugin->container()->set( SalesNotices::class, static fn(): SalesNotices => new SalesNotices( $plugin ) );
		$plugin->get( SalesNotices::class )->hooks();
	}

	/**
	 * Registers actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	private static function actions( ActionRegistry $registry, Plugin $plugin ): void {
		$notifier = static fn(): Notifier => $plugin->get( Notifier::class );
		$member   = static fn( Actor $a ): bool => $a->member_id > 0;

		$registry->add(
			Op::make( 'notifications.list', __( 'Notifications and inbox', 'cliezoo' ) )
				->input(
					array(
						'tab'      => array(
							'type' => 'string',
							'enum' => array( 'all', 'chats', 'tasks', 'files', 'updates', 'mentions', 'requests', 'approvals', 'messages' ),
						),
						'unread'   => array( 'type' => 'boolean' ),
						'archived' => array( 'type' => 'boolean' ),
					) + Op::paging( 30 )
				)
				->allow( $member )
				->run( static fn( Actor $a, array $in ) => $notifier()->list( $a, $in ) )
				->rest( 'GET', '/notifications' )
				->readonly()
		);
		$registry->add(
			Op::make( 'notifications.counts', __( 'Unread counts', 'cliezoo' ) )
				->allow( $member )
				->run( static fn( Actor $a ) => $notifier()->counts( $a ) )
				->rest( 'GET', '/notifications/counts' )
				->readonly()
		);
		$registry->add(
			Op::make( 'notifications.mark', __( 'Mark notifications', 'cliezoo' ) )
				->input(
					array(
						'uids'         => array(
							'type'     => 'array',
							'items'    => Op::uid(),
							'maxItems' => 500,
						),
						'all'          => array( 'type' => 'boolean' ),
						'read'         => array( 'type' => 'boolean' ),
						'archive'      => array( 'type' => 'boolean' ),
						'snooze_until' => array(
							'type'   => array( 'string', 'null' ),
							'format' => 'date-time',
						),
					)
				)
				->allow( $member )
				->run( static fn( Actor $a, array $in ) => $notifier()->mark( $a, $in ) )
				->rest( 'POST', '/notifications/mark' )
		);
		$registry->add(
			Op::make( 'notifications.prefs', __( 'Notification settings', 'cliezoo' ) )
				->input( array( 'member' => Op::uid( false ) ) )
				->allow( $member )
				->run( static fn( Actor $a, array $in ) => $notifier()->prefs_for_ui( $notifier()->prefs_target( $a, (string) ( $in['member'] ?? '' ) ) ) )
				->rest( 'GET', '/notifications/prefs' )
				->readonly()
		);
		$registry->add(
			Op::make( 'notifications.save_prefs', __( 'Save notification settings', 'cliezoo' ) )
				->describe( __( 'Saves the channel × event matrix, preset, digest frequency and quiet hours. Staff with client permissions can set defaults for a client contact.', 'cliezoo' ) )
				->input(
					array(
						'member'         => Op::uid( false ),
						'preset'         => array(
							'type' => 'string',
							'enum' => array( 'default', 'everything', 'messages_mentions', 'important', 'nothing', 'custom' ),
						),
						'matrix'         => array( 'type' => 'object' ),
						'digest'         => array(
							'type' => 'string',
							'enum' => array( 'instant', 'hourly', 'daily', 'weekly' ),
						),
						'digest_hour'    => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 23,
						),
						'quiet_start'    => array(
							'type'    => array( 'string', 'null' ),
							'pattern' => '^([01]\d|2[0-3]):[0-5]\d$',
						),
						'quiet_end'      => array(
							'type'    => array( 'string', 'null' ),
							'pattern' => '^([01]\d|2[0-3]):[0-5]\d$',
						),
						'quiet_weekends' => array( 'type' => 'boolean' ),
						'work_start'     => array(
							'type'    => array( 'string', 'null' ),
							'pattern' => '^([01]\d|2[0-3]):[0-5]\d$',
						),
						'work_end'       => array(
							'type'    => array( 'string', 'null' ),
							'pattern' => '^([01]\d|2[0-3]):[0-5]\d$',
						),
						'work_days'      => array(
							'type'     => array( 'array', 'null' ),
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 7,
							),
							'maxItems' => 7,
						),
					)
				)
				->allow( $member )
				->run(
					static function ( Actor $a, array $in ) use ( $notifier ): array {
						$target = $notifier()->prefs_target( $a, (string) ( $in['member'] ?? '' ) );
						unset( $in['member'] );
						$notifier()->save_prefs( $target, $in );
						return $notifier()->prefs_for_ui( $target );
					}
				)
				->rest( 'PUT', '/notifications/prefs' )
		);
		$registry->add(
			Op::make( 'watch.toggle', __( 'Watch or unwatch a record', 'cliezoo' ) )
				->input( ObjectRef::schema() + array( 'watching' => array( 'type' => 'boolean' ) ) )
				->allow( $member )
				->run(
					static function ( Actor $a, array $in ) use ( $plugin, $notifier ): array {
						list( $entity, $row ) = ObjectRef::resolve( $plugin, $a, (string) $in['object_type'], (string) $in['object'] );
						$watching             = isset( $in['watching'] ) ? (bool) $in['watching'] : ! $notifier()->is_watching( $a->member_id, $entity->name, (int) $row['id'] );
						$notifier()->watch( $a->member_id, $entity->name, (int) $row['id'], $watching );
						return array( 'watching' => $watching );
					}
				)
				->rest( 'POST', '/watch' )
		);
		// The bell on a record's header asks this first, so it shows what is
		// true rather than guessing: watching, muted (unwatched on purpose,
		// so only mentions get through), or neither.
		$registry->add(
			Op::make( 'watch.get', __( 'Whether you watch a record', 'cliezoo' ) )
				->input( ObjectRef::schema() )
				->allow( $member )
				->run(
					static function ( Actor $a, array $in ) use ( $plugin ): array {
						list( $entity, $row ) = ObjectRef::resolve( $plugin, $a, (string) $in['object_type'], (string) $in['object'] );
						$state                = $plugin->service( 'watches' )->repository()->first(
							array(
								'member_id'   => $a->member_id,
								'object_type' => $entity->name,
								'object_id'   => (int) $row['id'],
							)
						);
						return array(
							'watching' => $state && (int) $state['watching'],
							'muted'    => $state && ! (int) $state['watching'],
						);
					}
				)
				->rest( 'GET', '/watch' )
				->readonly()
		);
		$registry->add(
			Op::make( 'notifications.events', __( 'Notification events', 'cliezoo' ) )
				->allow( $member )
				->run(
					static function ( Actor $a ) use ( $plugin ): array {
						$out = array();
						foreach ( $plugin->get( EventCatalog::class )->all() as $id => $event ) {
							if ( $a->is_client() && 'team' === $event['audience'] ) {
								continue;
							}
							$out[] = array( 'id' => $id ) + array_intersect_key( $event, array_flip( array( 'group', 'label', 'tab', 'important' ) ) );
						}
						return array(
							'events'   => $out,
							'channels' => $plugin->get( ChannelRegistry::class )->labels(),
						);
					}
				)
				->rest( 'GET', '/notifications/events' )
				->readonly()
		);
	}
}
