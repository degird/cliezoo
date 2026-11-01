<?php
/**
 * "This task is due today": the catalogue's task.due, raised by a job.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Notifications;

use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Records\Comments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The catalogue has listed "Task due" since the notification matrix was
 * built, and everybody's preferences had a switch for it that nothing ever
 * raised: a due date reached people only if somebody had set up a
 * reminder rule. This is the plain notice — once per task per due date, to
 * the people on it and whoever is accountable for it, through their own
 * notification settings (quiet hours, working hours, digests and all).
 *
 * It runs every hour and looks back two days as well as at today, so a
 * site whose cron missed a morning still says it, once, and says "overdue"
 * rather than pretending it is still due today.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class DueNotices {

	/**
	 * Tasks looked at per run: the job runs every hour, so what is left
	 * waits an hour rather than making one run slow.
	 */
	private const BATCH = 200;

	/**
	 * The notice is not sent before this local hour, so a task due today
	 * is not announced at one minute past midnight.
	 */
	private const FROM_HOUR = 7;

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Raises task.due for tasks due today (and the two days before, for a
	 * run that was missed).
	 *
	 * @param int|null $now Unix time (tests).
	 * @return int Tasks told about.
	 */
	public function run( ?int $now = null ): int {
		global $wpdb;
		if ( ! $this->plugin->entities()->has( 'tasks' ) ) {
			return 0;
		}
		$now      = $now ?? time();
		$notifier = $this->plugin->get( Notifier::class );
		$local    = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $notifier->member_timezone( array() ) );
		if ( (int) $local->format( 'G' ) < self::FROM_HOUR ) {
			return 0;
		}
		$today  = $local->format( 'Y-m-d' );
		$oldest = $local->modify( '-2 days' )->format( 'Y-m-d' );
		$entity = $this->plugin->entities()->get( 'tasks' );
		$table  = esc_sql( $entity->table() );
		$ids    = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE due_date BETWEEN %s AND %s AND completed_at IS NULL AND deleted_at IS NULL AND archived_at IS NULL ORDER BY due_date DESC, id ASC LIMIT %d",
					$oldest,
					$today,
					self::BATCH
				)
			)
		);
		if ( ! $ids ) {
			return 0;
		}
		$told = 0;
		foreach ( $this->plugin->service( 'tasks' )->repository()->find_many( $ids ) as $row ) {
			$due     = substr( (string) $row['due_date'], 0, 10 );
			$members = $this->people( $row );
			if ( ! $members ) {
				continue;
			}
			$title   = wp_strip_all_tags( (string) $row['title'] );
			$created = $notifier->notify(
				'task.due',
				$members,
				array(
					'title'  => $due === $today
						/* translators: %s: the task's title. */
						? sprintf( __( '"%s" is due today', 'cliezoo' ), $title )
						/* translators: %s: the task's title. */
						: sprintf( __( '"%s" is overdue', 'cliezoo' ), $title ),
					'entity' => $entity,
					'row'    => $row,
					'path'   => '',
					// One notice per task per due date: moving the date
					// makes it a new date, and a new notice.
					'dedupe' => 'task.due:' . (int) $row['id'] . ':' . $due,
				)
			);
			$told   += $created > 0 ? 1 : 0;
		}
		return $told;
	}

	/**
	 * Who is told: the people on the task and whoever is accountable, as
	 * long as they may still see it.
	 *
	 * @param array<string, mixed> $row The task.
	 * @return int[]
	 */
	private function people( array $row ): array {
		$members = array();
		foreach ( $this->plugin->service( 'task_assignees' )->repository()->where(
			array(
				'task_id' => (int) $row['id'],
				'role'    => 'assignee',
			),
			'id',
			50
		) as $one ) {
			$members[] = (int) $one['member_id'];
		}
		if ( ! empty( $row['owner_id'] ) ) {
			$members[] = (int) $row['owner_id'];
		}
		$members = array_values( array_unique( $members ) );
		if ( ! $members ) {
			return array();
		}
		return $this->plugin->get( Comments::class )->who_may_see( $members, $this->plugin->entities()->get( 'tasks' ), $row, false );
	}
}
