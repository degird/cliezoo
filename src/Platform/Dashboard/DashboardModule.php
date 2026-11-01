<?php
/**
 * Home and the dashboard.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Dashboard;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\App\WidgetRegistry;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Field;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Home is where a person starts: a greeting, what they opened lately,
 * what is on them. The dashboard is where they look at the business:
 * cards of numbers and lists, over a period, compared with the period
 * before, each number opening the records behind it (cliezoo.md §6.3).
 *
 * Everyone can have as many dashboards as they like, each their own;
 * sharing them and setting one for a role is not offered.
 */
final class DashboardModule extends BaseModule {

	/**
	 * Widgets a dashboard can hold, and the sizes they take.
	 */
	private const WIDGETS = array(
		'counters'       => 'lg',
		'revenue'        => 'lg',
		'ar-ageing'      => 'md',
		'pipeline'       => 'md',
		'waiting'        => 'sm',
		'hours'          => 'md',
		'my-work'        => 'md',
		'tasks'          => 'md',
		'overdue-tasks'  => 'sm',
		'month-on-month' => 'md',
		'health'         => 'sm',
		'open-requests'  => 'sm',
		'unread'         => 'sm',
		'recent-items'   => 'md',
		'activity'       => 'md',
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'dashboard';
	}

	/**
	 * {@inheritDoc}
	 */
	public function entities(): array {
		return array(
			( new Entity(
				'dashboards',
				array(
					'member_id'   => Field::int()->readonly(),
					'name'        => Field::text( 120 )->required()->label( __( 'Name', 'cliezoo' ) ),
					'position'    => Field::int()->default( 0 )->sortable(),
					// The cards, in order: widget, size, and what each one shows.
					'layout'      => Field::json(),
					// The period it looks at, and whether it compares.
					'period'      => Field::text( 20 )->default( '30d' ),
					'period_from' => Field::date(),
					'period_to'   => Field::date(),
					'compare'     => Field::bool(),
				)
			) )->with(
				array(
					'label'        => __( 'Dashboard', 'cliezoo' ),
					'plural_label' => __( 'Dashboards', 'cliezoo' ),
					'soft_delete'  => false,
					'activity'     => false,
					'permission'   => 'self',
					'default_sort' => 'position',
					'indexes'      => array( 'member' => array( 'member_id', 'position' ) ),
				)
			),
		);
	}

	/**
	 * Registers the services, the widgets and the actions.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( Metrics::class, static fn(): Metrics => new Metrics( $plugin ) );
		$plugin->container()->set( Health::class, static fn(): Health => new Health( $plugin ) );
		$plugin->container()->set( Recents::class, static fn(): Recents => new Recents( $plugin ) );

		// Each dashboard is its owner's, and holds only what a dashboard can.
		add_filter(
			'cliezoo_dashboards_before_create',
			static function ( array $data, Actor $actor ): array {
				// Dashboards are the team's; a client's home is the portal.
				// How many one keeps is not limited (cliezoo.md §6.3).
				if ( ! $actor->is_staff() || ! $actor->member_id ) {
					throw ActionError::forbidden();
				}
				$data['member_id'] = $actor->member_id;
				return self::clean( $data );
			},
			10,
			2
		);
		add_filter(
			'cliezoo_dashboards_before_update',
			static fn( array $data ): array => self::clean( $data ),
			10,
			1
		);

		add_action(
			'cliezoo_record_read',
			static function ( Entity $entity, array $row, Actor $actor ) use ( $plugin ): void {
				$plugin->get( Recents::class )->opened( $entity, $row, $actor );
			},
			10,
			3
		);

		add_action(
			'cliezoo_register_widgets',
			static function ( WidgetRegistry $registry ) use ( $plugin ): void {
				foreach ( self::widgets( $plugin ) as $id => $widget ) {
					$registry->add( $id, $widget );
				}
			}
		);

		add_action(
			'cliezoo_register_actions',
			static function ( ActionRegistry $registry ) use ( $plugin ): void {
				self::actions( $registry, $plugin );
			}
		);
	}

	/**
	 * The widgets, with who may have them.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array<string, array<string, mixed>>
	 */
	private static function widgets( Plugin $plugin ): array {
		$on     = static fn( string $module ): bool => $plugin->module_active( $module );
		$titles = array(
			'counters'       => __( 'Key numbers', 'cliezoo' ),
			'revenue'        => __( 'Money', 'cliezoo' ),
			'ar-ageing'      => __( 'How old the debt is', 'cliezoo' ),
			'pipeline'       => __( 'In the pipeline', 'cliezoo' ),
			'waiting'        => __( 'Waiting on somebody', 'cliezoo' ),
			'hours'          => __( 'Hours', 'cliezoo' ),
			'my-work'        => __( 'My work', 'cliezoo' ),
			'tasks'          => __( 'Tasks', 'cliezoo' ),
			'overdue-tasks'  => __( 'Overdue tasks', 'cliezoo' ),
			'month-on-month' => __( 'This month and last', 'cliezoo' ),
			'health'         => __( 'Business health', 'cliezoo' ),
			'open-requests'  => __( 'Open requests', 'cliezoo' ),
			'unread'         => __( 'Unread conversations', 'cliezoo' ),
			'recent-items'   => __( 'Recently opened', 'cliezoo' ),
			'activity'       => __( 'Recent activity', 'cliezoo' ),
		);
		$needs  = array(
			'tasks'         => 'boards',
			'overdue-tasks' => 'boards',
			'open-requests' => 'requests',
			'unread'        => 'conversations',
			'revenue'       => 'invoices',
			'ar-ageing'     => 'invoices',
			'pipeline'      => 'deals',
			'waiting'       => 'documents',
			'hours'         => 'time',
			// The score is made of overdue invoices, margin, overdue tasks and
			// pipeline cover, none of which this plugin measures.
			'health'        => 'invoices',
		);
		$out    = array();
		$order  = 10;
		foreach ( self::WIDGETS as $id => $size ) {
			if ( isset( $needs[ $id ] ) && ! $on( $needs[ $id ] ) ) {
				continue;
			}
			$out[ $id ] = array(
				'title'    => $titles[ $id ],
				'size'     => $size,
				'order'    => $order,
				'audience' => 'staff',
				'place'    => 'dashboard',
			);
			$order     += 10;
		}
		return $out;
	}

	/**
	 * What a new person's dashboard starts with. Cards for a part that is
	 * off are left out when it is shown, not here.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function starter(): array {
		return array(
			array(
				'widget'   => 'counters',
				'size'     => 'lg',
				'settings' => array( 'metrics' => array( 'clients.active', 'clients.new', 'leads.new', 'leads.worth', 'members.team' ) ),
			),
			array(
				'widget' => 'revenue',
				'size'   => 'lg',
			),
			array(
				'widget' => 'ar-ageing',
				'size'   => 'md',
			),
			array(
				'widget' => 'pipeline',
				'size'   => 'md',
			),
			array(
				'widget' => 'hours',
				'size'   => 'md',
			),
			array(
				'widget' => 'waiting',
				'size'   => 'sm',
			),
			array(
				'widget' => 'my-work',
				'size'   => 'md',
			),
			array(
				'widget' => 'health',
				'size'   => 'sm',
			),
			array(
				'widget' => 'month-on-month',
				'size'   => 'md',
			),
			array(
				'widget' => 'overdue-tasks',
				'size'   => 'sm',
			),
			array(
				'widget' => 'activity',
				'size'   => 'lg',
			),
		);
	}

	/**
	 * A dashboard as it may be kept: known sizes and periods, at most forty
	 * cards, and settings that are lists of short words.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return array<string, mixed>
	 */
	public static function clean( array $data ): array {
		if ( array_key_exists( 'layout', $data ) ) {
			$given = is_string( $data['layout'] ) ? json_decode( $data['layout'], true ) : $data['layout'];
			$cards = array();
			foreach ( is_array( $given ) ? array_slice( $given, 0, 40 ) : array() as $card ) {
				if ( ! is_array( $card ) || ! is_string( $card['widget'] ?? null ) || ! preg_match( '/^[a-z0-9][a-z0-9.-]{0,63}$/', $card['widget'] ) ) {
					continue;
				}
				$one      = array(
					'widget' => $card['widget'],
					'size'   => in_array( $card['size'] ?? '', array( 'sm', 'md', 'lg' ), true ) ? $card['size'] : 'sm',
				);
				$settings = array();
				foreach ( is_array( $card['settings'] ?? null ) ? array_slice( $card['settings'], 0, 10, true ) : array() as $key => $value ) {
					if ( ! is_string( $key ) || ! preg_match( '/^[a-z_]{1,30}$/', $key ) ) {
						continue;
					}
					if ( is_array( $value ) ) {
						$settings[ $key ] = array_values( array_filter( array_slice( $value, 0, 20 ), static fn( $item ): bool => is_string( $item ) && (bool) preg_match( '/^[a-z0-9_.-]{1,64}$/', $item ) ) );
					} elseif ( is_string( $value ) && preg_match( '/^[a-z0-9_.-]{0,64}$/', $value ) ) {
						$settings[ $key ] = $value;
					} elseif ( is_bool( $value ) ) {
						$settings[ $key ] = $value;
					}
				}
				if ( $settings ) {
					$one['settings'] = $settings;
				}
				$cards[] = $one;
			}
			$data['layout'] = (string) wp_json_encode( $cards );
		}
		if ( array_key_exists( 'period', $data ) && ! in_array( $data['period'], Metrics::RANGES, true ) ) {
			$data['period'] = '30d';
		}
		return $data;
	}

	/**
	 * The actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	private static function actions( ActionRegistry $registry, Plugin $plugin ): void {
		$staff  = static fn( Actor $actor ): bool => $actor->is_staff() && $actor->member_id > 0;
		$member = static fn( Actor $actor ): bool => ! $actor->is_guest() && $actor->member_id > 0;
		$period = array(
			'range'   => array(
				'type' => 'string',
				'enum' => Metrics::RANGES,
			),
			'from'    => array(
				'type'   => 'string',
				'format' => 'date',
			),
			'to'      => array(
				'type'   => 'string',
				'format' => 'date',
			),
			'compare' => array( 'type' => 'boolean' ),
		);

		$registry->add(
			Op::make( 'dashboard.metrics', __( 'Dashboard numbers', 'cliezoo' ) )
				->describe( __( 'Numbers for a period — open tasks, active clients, tasks completed and so on — with the period before when asked, each counting only what the reader may see.', 'cliezoo' ) )
				->input(
					$period + array(
						'keys' => array(
							'type'     => 'string',
							'required' => true,
						),
					)
				)
				->allow( $staff )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin ): array {
						$range  = (string) ( $in['range'] ?? '30d' );
						$period = Metrics::period( $range, (string) ( $in['from'] ?? '' ), (string) ( $in['to'] ?? '' ) );
						return array(
							'period'  => array(
								'range' => $range,
								'first' => $period['first'],
								'last'  => $period['last'],
							),
							'metrics' => $plugin->get( Metrics::class )->values( $actor, array_filter( array_map( 'trim', explode( ',', (string) $in['keys'] ) ) ), $period, ! empty( $in['compare'] ) ),
						);
					}
				)
				->rest( 'GET', '/dashboard/metrics' )
				->readonly()
				->with( array( 'mcp' => 'read' ) )
		);

		$registry->add(
			Op::make( 'dashboard.catalog', __( 'What a dashboard can show', 'cliezoo' ) )
				->allow( $staff )
				->run(
					static fn( Actor $actor ): array => array(
						'metrics' => $plugin->get( Metrics::class )->describe(),
						/**
						 * What a person with no dashboard of their own starts
						 * with: an add-on may give each role its own.
						 *
						 * @param array<int, array<string, mixed>> $starter The cards.
						 * @param Actor                            $actor   Who it is for.
						 */
						'starter' => (array) json_decode( (string) self::clean( array( 'layout' => (array) apply_filters( 'cliezoo_dashboard_starter', self::starter(), $actor ) ) )['layout'], true ),
					)
				)
				->rest( 'GET', '/dashboard/catalog' )
				->readonly()
		);

		$registry->add(
			Op::make( 'dashboard.health', __( 'Business health', 'cliezoo' ) )
				->describe( __( 'The business health score, part by part, each explained; parts that cannot be measured yet say what would measure them.', 'cliezoo' ) )
				->allow( $staff )
				->run( static fn( Actor $actor ): array => $plugin->get( Health::class )->business( $actor ) )
				->rest( 'GET', '/dashboard/health' )
				->readonly()
				->with( array( 'mcp' => 'read' ) )
		);

		$registry->add(
			Op::make( 'me.recents', __( 'What I opened lately', 'cliezoo' ) )
				->input(
					array(
						'types' => Op::str( 200 ),
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 12,
						),
					)
				)
				->allow( $member )
				->run(
					static fn( Actor $actor, array $in ): array => $plugin->get( Recents::class )->list(
						$actor,
						array_filter( array_map( 'trim', explode( ',', (string) ( $in['types'] ?? '' ) ) ) ),
						(int) ( $in['limit'] ?? 12 )
					)
				)
				->rest( 'GET', '/me/recents' )
				->readonly()
		);
	}
}
