<?php
/**
 * The numbers a dashboard can show.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Dashboard;

use Cliezoo\Core\Data\ListQuery;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every number on a dashboard is a metric: a label, how to count it for a
 * person over a period, and the list it opens (drill-down). Modules add
 * their own through `cliezoo_dashboard_metrics` — invoices bring revenue
 * and ageing, time tracking brings hours — so a dashboard shows what the
 * workspace has and nothing it does not.
 *
 * Every count is of what the person asking may see: the same scope their
 * lists have. A dashboard never shows a number made of records they could
 * not open.
 */
final class Metrics {

	/**
	 * The periods a dashboard can look at.
	 */
	public const RANGES = array( 'today', '7d', '30d', '90d', 'month', 'last_month', 'quarter', 'year', 'custom' );

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Why the last count could not be made, in one sentence.
	 *
	 * A number nobody was allowed to count, or that the database refused,
	 * is not nought: it is unmeasured, and the screen has to be able to
	 * say which. `count()` writes the sentence as it returns null and
	 * `values()` reads it straight afterwards.
	 *
	 * @var string
	 */
	private string $unmeasured = '';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Every metric this workspace has.
	 *
	 * @return array<string, array<string, mixed>> key => label, group, count (callable), ranged, format, drill.
	 */
	public function catalog(): array {
		$metrics = array();
		$today   = static fn(): string => wp_date( 'Y-m-d' );
		if ( $this->plugin->entities()->has( 'clients' ) ) {
			$metrics['clients.active'] = array(
				'label' => __( 'Active clients', 'cliezoo' ),
				'group' => __( 'Clients', 'cliezoo' ),
				'count' => fn( Actor $actor ): ?int => $this->count( $actor, 'clients', "t.status = 'active'" ),
				'drill' => array(
					'path'   => '/clients',
					'filter' => array(
						'field' => 'status',
						'op'    => 'eq',
						'value' => 'active',
					),
				),
			);
			$metrics['clients.new']    = array(
				'label'  => __( 'New clients', 'cliezoo' ),
				'group'  => __( 'Clients', 'cliezoo' ),
				'ranged' => true,
				'count'  => fn( Actor $actor, string $from, string $to ): ?int => $this->count( $actor, 'clients', 't.created_at >= %s AND t.created_at < %s', array( $from, $to ) ),
				'drill'  => array(
					'path'   => '/clients',
					'filter' => array(
						'and' => array(
							array(
								'field' => 'created_at',
								'op'    => 'gte',
								'value' => '@from',
							),
							array(
								'field' => 'created_at',
								'op'    => 'lt',
								'value' => '@to',
							),
						),
					),
				),
			);
		}
		if ( $this->plugin->entities()->has( 'projects' ) ) {
			$metrics['projects.active']  = array(
				'label' => __( 'Active projects', 'cliezoo' ),
				'group' => __( 'Projects', 'cliezoo' ),
				'count' => fn( Actor $actor ): ?int => $this->count( $actor, 'projects', "t.status = 'active'" ),
				'drill' => array(
					'path'   => '/projects',
					'filter' => array(
						'field' => 'status',
						'op'    => 'eq',
						'value' => 'active',
					),
				),
			);
			$metrics['projects.started'] = array(
				'label'  => __( 'Projects started', 'cliezoo' ),
				'group'  => __( 'Projects', 'cliezoo' ),
				'ranged' => true,
				'count'  => fn( Actor $actor, string $from, string $to ): ?int => $this->count( $actor, 'projects', 't.created_at >= %s AND t.created_at < %s', array( $from, $to ) ),
				'drill'  => array(
					'path'   => '/projects',
					'filter' => array(
						'and' => array(
							array(
								'field' => 'created_at',
								'op'    => 'gte',
								'value' => '@from',
							),
							array(
								'field' => 'created_at',
								'op'    => 'lt',
								'value' => '@to',
							),
						),
					),
				),
			);
		}
		// The team: how many people work here, and how many joined. Counted
		// for whoever may see the team list; for anybody else it is nothing.
		$metrics['members.team']   = array(
			'label' => __( 'Team members', 'cliezoo' ),
			'group' => __( 'Team', 'cliezoo' ),
			'count' => fn( Actor $actor ): ?int => $this->count( $actor, 'members', "t.kind = 'staff' AND t.status = 'active'" ),
			'drill' => array( 'path' => '/settings/team' ),
		);
		$metrics['members.joined'] = array(
			'label'  => __( 'Team members joined', 'cliezoo' ),
			'group'  => __( 'Team', 'cliezoo' ),
			'ranged' => true,
			'count'  => fn( Actor $actor, string $from, string $to ): ?int => $this->count( $actor, 'members', "t.kind = 'staff' AND t.status <> 'invited' AND t.created_at >= %s AND t.created_at < %s", array( $from, $to ) ),
			'drill'  => array( 'path' => '/settings/team' ),
		);
		if ( $this->plugin->entities()->has( 'tasks' ) ) {
			$open                     = 't.completed_at IS NULL AND t.parent_id IS NULL AND t.archived_at IS NULL';
			$metrics['tasks.open']    = array(
				'label' => __( 'Open tasks', 'cliezoo' ),
				'group' => __( 'Tasks', 'cliezoo' ),
				'count' => fn( Actor $actor ): ?int => $this->count( $actor, 'tasks', $open ),
				'drill' => array(
					'path'   => '/tasks',
					'filter' => array(
						'and' => array(
							array(
								'field' => 'completed_at',
								'op'    => 'empty',
							),
						),
					),
				),
			);
			$metrics['tasks.overdue'] = array(
				'label' => __( 'Overdue tasks', 'cliezoo' ),
				'group' => __( 'Tasks', 'cliezoo' ),
				'tone'  => 'bad-when-up',
				'count' => fn( Actor $actor ): ?int => $this->count( $actor, 'tasks', $open . ' AND t.due_date < %s', array( $today() ) ),
				'drill' => array(
					'path'   => '/tasks',
					'filter' => array(
						'and' => array(
							array(
								'field' => 'due_date',
								'op'    => 'lt',
								'value' => '@today',
							),
							array(
								'field' => 'completed_at',
								'op'    => 'empty',
							),
						),
					),
				),
			);
			$metrics['tasks.done']    = array(
				'label'  => __( 'Tasks completed', 'cliezoo' ),
				'group'  => __( 'Tasks', 'cliezoo' ),
				'ranged' => true,
				'count'  => fn( Actor $actor, string $from, string $to ): ?int => $this->count( $actor, 'tasks', 't.parent_id IS NULL AND t.completed_at >= %s AND t.completed_at < %s', array( $from, $to ) ),
				'drill'  => array(
					'path'   => '/tasks',
					'filter' => array(
						'and' => array(
							array(
								'field' => 'completed_at',
								'op'    => 'gte',
								'value' => '@from',
							),
							array(
								'field' => 'completed_at',
								'op'    => 'lt',
								'value' => '@to',
							),
						),
					),
				),
			);
			$metrics['tasks.created'] = array(
				'label'  => __( 'Tasks added', 'cliezoo' ),
				'group'  => __( 'Tasks', 'cliezoo' ),
				'ranged' => true,
				'count'  => fn( Actor $actor, string $from, string $to ): ?int => $this->count( $actor, 'tasks', 't.parent_id IS NULL AND t.created_at >= %s AND t.created_at < %s', array( $from, $to ) ),
				'drill'  => array(
					'path'   => '/tasks',
					'filter' => array(
						'and' => array(
							array(
								'field' => 'created_at',
								'op'    => 'gte',
								'value' => '@from',
							),
							array(
								'field' => 'created_at',
								'op'    => 'lt',
								'value' => '@to',
							),
						),
					),
				),
			);
		}
		if ( $this->plugin->entities()->has( 'requests' ) ) {
			$metrics['requests.open'] = array(
				'label' => __( 'Open requests', 'cliezoo' ),
				'group' => __( 'Requests', 'cliezoo' ),
				'count' => fn( Actor $actor ): ?int => $this->count( $actor, 'requests', "t.status NOT IN ('done','rejected')" ),
				'drill' => array( 'path' => '/requests' ),
			);
			$metrics['requests.new']  = array(
				'label'  => __( 'Requests received', 'cliezoo' ),
				'group'  => __( 'Requests', 'cliezoo' ),
				'ranged' => true,
				'count'  => fn( Actor $actor, string $from, string $to ): ?int => $this->count( $actor, 'requests', 't.created_at >= %s AND t.created_at < %s', array( $from, $to ) ),
				'drill'  => array( 'path' => '/requests' ),
			);
		}
		if ( $this->plugin->entities()->has( 'conversations' ) && class_exists( \Cliezoo\Platform\Conversations\Conversations::class ) ) {
			$metrics['messages.unread'] = array(
				'label' => __( 'Unread conversations', 'cliezoo' ),
				'group' => __( 'Messages', 'cliezoo' ),
				'count' => fn( Actor $actor ): int => (int) ( $this->plugin->get( \Cliezoo\Platform\Conversations\Conversations::class )->counts( $actor )['unread'] ?? 0 ),
				'drill' => array( 'path' => '/inbox' ),
			);
		}
		/**
		 * The metrics a dashboard can show. Each: label, group, count
		 * (callable: Actor, from, to → number — of what that person may
		 * see), ranged (whether the period applies), format (number, money,
		 * hours, percent), tone ('bad-when-up' for numbers that should
		 * fall), drill (path and filter of the list it opens).
		 *
		 * @param array<string, array<string, mixed>> $metrics Metrics so far.
		 * @param Plugin                              $plugin  Plugin.
		 */
		return (array) apply_filters( 'cliezoo_dashboard_metrics', $metrics, $this->plugin );
	}

	/**
	 * The catalog as the browser sees it: no callables.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function describe(): array {
		$out = array();
		foreach ( $this->catalog() as $key => $metric ) {
			$out[] = array(
				'key'    => (string) $key,
				'label'  => (string) ( $metric['label'] ?? $key ),
				'group'  => (string) ( $metric['group'] ?? '' ),
				'ranged' => ! empty( $metric['ranged'] ),
				'format' => (string) ( $metric['format'] ?? 'number' ),
			);
		}
		return $out;
	}

	/**
	 * Counts rows of an entity the person may see, or null when there is
	 * no honest count to give: the entity is not here, their role scopes
	 * them out of it, or the query itself failed. Nought means "none of
	 * them"; null means "we do not know", and the two must not be drawn
	 * the same way.
	 *
	 * @param Actor             $actor  Who is asking.
	 * @param string            $entity Entity name.
	 * @param string            $where  Extra condition (t. columns, placeholders).
	 * @param array<int, mixed> $args   Its values.
	 * @return int|null
	 */
	public function count( Actor $actor, string $entity, string $where, array $args = array() ): ?int {
		if ( ! $this->plugin->entities()->has( $entity ) ) {
			$this->unmeasured = __( 'This is not switched on here.', 'cliezoo' );
			return null;
		}
		$definition = $this->plugin->entities()->get( $entity );
		if ( ! $this->plugin->policy()->can( $actor, 'view', $definition ) ) {
			$this->unmeasured = __( 'Your role does not include these records.', 'cliezoo' );
			return null;
		}
		$query = new ListQuery();
		$query->where( $where, $args );
		try {
			return $this->plugin->service( $entity )->repository()->count( $query, $this->plugin->policy()->scope( $actor, $definition ) );
		} catch ( \Throwable $e ) {
			$this->unmeasured = __( 'This could not be counted just now.', 'cliezoo' );
			return null;
		}
	}

	/**
	 * Adds up a plain number for the rows this person may see.
	 *
	 * Hours, not money: no currency to keep apart and no finance permission to
	 * ask about, because how long something took is not what it was worth.
	 *
	 * @param Actor             $actor  Who is asking.
	 * @param string            $entity Which records.
	 * @param string            $column The column.
	 * @param string            $where  SQL fragment.
	 * @param array<int, mixed> $args   Its arguments.
	 * @return int|null Null when it could not be measured; `why()` says why.
	 */
	public function total( Actor $actor, string $entity, string $column, string $where, array $args = array() ): ?int {
		if ( ! $this->plugin->entities()->has( $entity ) ) {
			$this->unmeasured = __( 'This is not switched on here.', 'cliezoo' );
			return null;
		}
		$definition = $this->plugin->entities()->get( $entity );
		if ( ! $this->plugin->policy()->can( $actor, 'view', $definition ) ) {
			$this->unmeasured = __( 'Your role does not include these records.', 'cliezoo' );
			return null;
		}
		$query = new ListQuery();
		$query->where( $where, $args );
		try {
			return $this->plugin->service( $entity )->repository()->total(
				$query,
				$this->plugin->policy()->scope( $actor, $definition ),
				$column
			);
		} catch ( \Throwable $e ) {
			unset( $e );
			$this->unmeasured = __( 'This could not be added up just now.', 'cliezoo' );
			return null;
		}
	}

	/**
	 * Adds up a money column for the rows this person may see.
	 *
	 * The same shape and the same scope as `count()`, because a number made of
	 * records somebody could not open is a number they should not be shown —
	 * and that is as true of a total as of a tally.
	 *
	 * Minor units throughout, and one currency at a time. A workspace billing in
	 * two currencies has two answers and adding them would invent a third:
	 * pounds and euros do not add up, and a dashboard that pretends they do is
	 * worse than one that says which.
	 *
	 * @param Actor             $actor    Who is asking.
	 * @param string            $entity   Which records.
	 * @param string            $column   The money column.
	 * @param string            $where    SQL fragment.
	 * @param array<int, mixed> $args     Its arguments.
	 * @param string            $currency Which currency, or '' for the workspace's own.
	 * @return int|null Null when it could not be measured; `why()` says why.
	 */
	public function sum( Actor $actor, string $entity, string $column, string $where, array $args = array(), string $currency = '' ): ?int {
		if ( ! $this->plugin->entities()->has( $entity ) ) {
			$this->unmeasured = __( 'This is not switched on here.', 'cliezoo' );
			return null;
		}
		$definition = $this->plugin->entities()->get( $entity );
		if ( ! $this->plugin->policy()->can( $actor, 'view', $definition ) ) {
			$this->unmeasured = __( 'Your role does not include these records.', 'cliezoo' );
			return null;
		}
		// Money is the finance half of a role's permissions, not the record half:
		// somebody may open an invoice and still not be shown what the workspace
		// is owed in total.
		if ( ! $actor->sees_finance() ) {
			$this->unmeasured = __( 'Your role does not include the money.', 'cliezoo' );
			return null;
		}

		$wanted = '' !== $currency
			? $currency
			: (string) $this->plugin->settings()->get( 'workspace', 'currency', 'USD' );

		$query = new ListQuery();
		$query->where( $where, $args );
		$query->where( 't.currency = %s', array( $wanted ) );
		try {
			$rolled = $this->plugin->service( $entity )->repository()->rollup(
				$query,
				$this->plugin->policy()->scope( $actor, $definition ),
				'currency',
				$column
			);
			return (int) ( $rolled[ $wanted ]['sum'] ?? 0 );
		} catch ( \Throwable $e ) {
			unset( $e );
			$this->unmeasured = __( 'This could not be added up just now.', 'cliezoo' );
			return null;
		}
	}

	/**
	 * A period: where it starts and ends, and the one before it, as UTC
	 * datetimes (the end excluded), worked out in the site's timezone.
	 *
	 * @param string $range One of RANGES.
	 * @param string $from  For "custom": the first day (Y-m-d).
	 * @param string $to    For "custom": the last day (Y-m-d).
	 * @param string $today The day it is (Y-m-d); today in the site's timezone when empty.
	 * @return array{from: string, to: string, prev_from: string, prev_to: string, first: string, last: string}
	 */
	public static function period( string $range, string $from = '', string $to = '', string $today = '' ): array {
		$zone  = wp_timezone();
		$given = '' !== $today ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $today, $zone ) : false;
		$today = $given ? $given : new \DateTimeImmutable( 'today', $zone );
		switch ( $range ) {
			case 'today':
				$start = $today;
				$end   = $today->modify( '+1 day' );
				break;
			case '7d':
				$start = $today->modify( '-6 days' );
				$end   = $today->modify( '+1 day' );
				break;
			case '90d':
				$start = $today->modify( '-89 days' );
				$end   = $today->modify( '+1 day' );
				break;
			case 'month':
				$start = $today->modify( 'first day of this month' );
				$end   = $today->modify( '+1 day' );
				break;
			case 'last_month':
				$start = $today->modify( 'first day of last month' );
				$end   = $start->modify( '+1 month' );
				break;
			case 'quarter':
				$month = (int) $today->format( 'n' );
				$start = $today->setDate( (int) $today->format( 'Y' ), (int) ( floor( ( $month - 1 ) / 3 ) * 3 + 1 ), 1 );
				$end   = $today->modify( '+1 day' );
				break;
			case 'year':
				$start = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
				$end   = $today->modify( '+1 day' );
				break;
			case 'custom':
				$first = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from, $zone );
				$last  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to, $zone );
				if ( $first && $last && $first <= $last && $first->diff( $last )->days <= 3660 ) {
					$start = $first;
					$end   = $last->modify( '+1 day' );
					break;
				}
				// A period that cannot be read is the last thirty days.
				$start = $today->modify( '-29 days' );
				$end   = $today->modify( '+1 day' );
				break;
			default:
				$start = $today->modify( '-29 days' );
				$end   = $today->modify( '+1 day' );
		}
		// The one before is the same length, ending where this one starts.
		// Calendar periods start where the calendar says; one still running
		// (this month so far) is compared with the same stretch of the one
		// before (the same days of last month), not all of it.
		$prev_end = $start;
		if ( in_array( $range, array( 'month', 'last_month' ), true ) ) {
			$prev_start = $start->modify( '-1 month' );
		} elseif ( 'quarter' === $range ) {
			$prev_start = $start->modify( '-3 months' );
		} elseif ( 'year' === $range ) {
			$prev_start = $start->modify( '-1 year' );
		} else {
			// In days, not as the interval diff() gives: from 1 to 30
			// September that reads "one month", and a month back from
			// 1 September is all 31 days of August.
			$prev_start = $start->modify( '-' . (int) $start->diff( $end )->days . ' days' );
		}
		if ( in_array( $range, array( 'month', 'quarter', 'year' ), true ) ) {
			$prev_end = min( $start, $prev_start->modify( '+' . (int) $start->diff( $end )->days . ' days' ) );
		}
		$utc = new \DateTimeZone( 'UTC' );
		$out = static fn( \DateTimeImmutable $at ): string => $at->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		return array(
			'from'      => $out( $start ),
			'to'        => $out( $end ),
			'prev_from' => $out( $prev_start ),
			'prev_to'   => $out( $prev_end ),
			'first'     => $start->format( 'Y-m-d' ),
			'last'      => $end->modify( '-1 day' )->format( 'Y-m-d' ),
		);
	}

	/**
	 * A drill-down with the period written in: "@from" and "@to" in its
	 * filter become where the period starts and ends.
	 *
	 * @param array<string, mixed>  $drill  Path and filter.
	 * @param array<string, string> $period The period.
	 * @return array<string, mixed>
	 */
	private static function drill( array $drill, array $period ): array {
		array_walk_recursive(
			$drill,
			static function ( &$value ) use ( $period ): void {
				if ( '@from' === $value ) {
					$value = $period['from'];
				} elseif ( '@to' === $value ) {
					$value = $period['to'];
				}
			}
		);
		return $drill;
	}

	/**
	 * Metrics for a person over a period, and — when asked — the period
	 * before, for comparison.
	 *
	 * @param Actor                                                                                            $actor   Who is asking.
	 * @param array<int, string>                                                                               $keys    Which metrics.
	 * @param array{from: string, to: string, prev_from: string, prev_to: string, first: string, last: string} $period The period.
	 * @param bool                                                                                             $compare Whether to count the period before too.
	 * @return array<string, array<string, mixed>>
	 */
	public function values( Actor $actor, array $keys, array $period, bool $compare ): array {
		$catalog = $this->catalog();
		$out     = array();
		foreach ( array_slice( array_unique( $keys ), 0, 40 ) as $key ) {
			if ( ! isset( $catalog[ $key ] ) || ! is_callable( $catalog[ $key ]['count'] ?? null ) ) {
				continue;
			}
			$metric = $catalog[ $key ];
			$ranged = ! empty( $metric['ranged'] );
			// The reason stands ready before the count is asked for: a
			// metric of its own making that never calls count() still owes
			// the screen a sentence when it comes back with nothing.
			$this->unmeasured = __( 'This could not be counted.', 'cliezoo' );
			$value            = $metric['count']( $actor, $period['from'], $period['to'] );
			$measured         = is_numeric( $value );
			$reason           = $measured ? '' : $this->unmeasured;
			// A count of how things stand now has nothing to compare with,
			// and neither has one that was never made.
			$before      = $measured && $compare && $ranged ? $metric['count']( $actor, $period['prev_from'], $period['prev_to'] ) : null;
			$out[ $key ] = array(
				'label'    => (string) ( $metric['label'] ?? $key ),
				// A number nobody could count is sent as null with the
				// reason beside it, never as a confident nought.
				'measured' => $measured,
				'value'    => $measured ? $value + 0 : null,
				'reason'   => $reason,
				'previous' => is_numeric( $before ) ? $before + 0 : null,
				'ranged'   => $ranged,
				'format'   => (string) ( $metric['format'] ?? 'number' ),
				'tone'     => (string) ( $metric['tone'] ?? '' ),
				'drill'    => isset( $metric['drill'] ) ? self::drill( (array) $metric['drill'], $period ) : null,
			);
		}
		return $out;
	}
}
