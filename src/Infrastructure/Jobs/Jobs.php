<?php
/**
 * Background jobs on Action Scheduler.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Jobs;

use Cliezoo\Core\Logger;
use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queues work in the `cliezoo` group, runs a per-minute tick and a daily
 * maintenance job, and records heartbeats for Setup Health (Blueprint §16.1).
 * Jobs must be idempotent and handle at most 50 items per run.
 */
final class Jobs {

	public const GROUP  = 'cliezoo';
	public const TICK   = 'cliezoo_tick';
	public const DAILY  = 'cliezoo_daily';
	public const HOURLY = 'cliezoo_hourly';

	/**
	 * A gap between two minute ticks this long means cron was asleep.
	 */
	public const LATE_AFTER = 2 * HOUR_IN_SECONDS;

	/**
	 * Hooks the recurring jobs.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( self::TICK, array( $this, 'tick' ) );
		add_action( self::HOURLY, array( $this, 'hourly' ) );
		add_action( self::DAILY, array( $this, 'daily' ) );
		add_action( 'cliezoo_migrated', array( $this, 'schedule_recurring' ) );
		add_action( 'cliezoo_run_job', array( $this, 'run_job' ), 10, 2 );
	}

	/**
	 * Makes sure this site has Action Scheduler's tables.
	 *
	 * A site made on a network where Cliezoo is network-active is installed
	 * before Action Scheduler has made that site's tables, and a job queued
	 * into a table that is not there fails quietly — so a new site's
	 * background work, and anything its install queued, never ran. The check
	 * is Action Scheduler's own and cheap: it compares a version kept per site.
	 *
	 * @return void
	 */
	public static function ensure_tables(): void {
		foreach ( array( 'ActionScheduler_StoreSchema', 'ActionScheduler_LoggerSchema' ) as $schema ) {
			if ( class_exists( $schema ) ) {
				( new $schema() )->register_tables();
			}
		}
	}

	/**
	 * Schedules the recurring jobs once (after install/upgrade, not per request).
	 *
	 * @return void
	 */
	public function schedule_recurring(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		self::ensure_tables();
		$recurring = array(
			self::TICK   => MINUTE_IN_SECONDS,
			self::HOURLY => HOUR_IN_SECONDS,
			self::DAILY  => DAY_IN_SECONDS,
		);
		foreach ( $recurring as $hook => $interval ) {
			if ( ! as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 60, $interval, $hook, array(), self::GROUP, true );
			}
		}
	}

	/**
	 * Queues a job now (or after a delay). Unique jobs are skipped when one is already pending.
	 *
	 * @param string               $job    Job name (a `cliezoo_job_{name}` action runs it).
	 * @param array<string, mixed> $args   Arguments (JSON-serialisable, no secrets).
	 * @param int                  $delay  Seconds to wait.
	 * @param bool                 $unique Skip when the same job+args is pending.
	 * @return int Action id (0 when skipped or unavailable).
	 */
	public function queue( string $job, array $args = array(), int $delay = 0, bool $unique = false ): int {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}
		$payload = array(
			'job'  => $job,
			'args' => $args,
		);
		if ( $unique && as_has_scheduled_action( 'cliezoo_run_job', array( $payload['job'], $payload['args'] ), self::GROUP ) ) {
			return 0;
		}
		if ( $delay > 0 ) {
			return (int) as_schedule_single_action( time() + $delay, 'cliezoo_run_job', array( $payload['job'], $payload['args'] ), self::GROUP );
		}
		return (int) as_enqueue_async_action( 'cliezoo_run_job', array( $payload['job'], $payload['args'] ), self::GROUP );
	}

	/**
	 * Runs a queued job by name.
	 *
	 * @param string               $job  Job name.
	 * @param array<string, mixed> $args Arguments.
	 * @return void
	 * @throws \Throwable Re-thrown so Action Scheduler records the failure and retries.
	 */
	public function run_job( string $job, array $args = array() ): void {
		if ( $this->paused( $job ) ) {
			// Safe mode: re-queue later instead of sending anything from a cloned site.
			$this->queue( $job, $args, HOUR_IN_SECONDS );
			return;
		}
		try {
			/**
			 * Runs a Cliezoo background job.
			 *
			 * @param array $args Arguments.
			 */
			do_action( 'cliezoo_job_' . sanitize_key( $job ), $args );
		} catch ( \Throwable $error ) {
			Logger::error(
				'Job failed',
				array(
					'job'   => $job,
					'error' => $error->getMessage(),
				)
			);
			throw $error;
		}
	}

	/**
	 * Per-minute tick: due reminders, scheduled sends, digests.
	 *
	 * @param int|null $now Unix time (tests).
	 * @return void
	 */
	public function tick( ?int $now = null ): void {
		$now  = $now ?? time();
		$last = (int) get_option( 'cliezoo_heartbeat_tick', 0 );
		update_option( 'cliezoo_heartbeat_tick', $now, false );
		if ( $this->safe_mode() ) {
			return;
		}
		// The minute job is the first to notice that the site's cron was
		// asleep: what was due in the meantime is sent now, late, and the
		// people who run the workspace are told it was.
		if ( $last > 0 && $now - $last >= self::LATE_AFTER ) {
			/**
			 * Scheduled work ran late (cron did not run for a while).
			 *
			 * @param int    $seconds How long nothing ran.
			 * @param string $kind    Which job noticed.
			 */
			do_action( 'cliezoo_jobs_late', $now - $last, 'tick' );
		}
		/**
		 * Runs every minute (keep each listener small and idempotent).
		 */
		do_action( 'cliezoo_every_minute' );
	}

	/**
	 * Hourly tick, then one `cliezoo_hour_period` per hour that was missed.
	 *
	 * @param int|null $now Unix time (tests).
	 * @return void
	 */
	public function hourly( ?int $now = null ): void {
		$now = $now ?? time();
		/**
		 * Runs every hour.
		 */
		do_action( 'cliezoo_every_hour' );
		$this->catch_up( 'hour', gmdate( 'Y-m-d H', $now ), 'Y-m-d H', '+1 hour', 48 );
	}

	/**
	 * Daily maintenance: trash purge, token cleanup, health re-checks; then
	 * one `cliezoo_day_period` per day that was missed.
	 *
	 * @param int|null $now Unix time (tests).
	 * @return void
	 */
	public function daily( ?int $now = null ): void {
		$now = $now ?? time();
		update_option( 'cliezoo_heartbeat_daily', $now, false );
		/**
		 * Runs once a day.
		 */
		do_action( 'cliezoo_every_day' );
		$this->catch_up( 'day', wp_date( 'Y-m-d', $now ), 'Y-m-d', '+1 day', 31 );
	}

	/**
	 * Runs each period since the last one that ran, once, oldest first.
	 *
	 * The sweeping jobs (`cliezoo_every_day`: empty the trash, re-check
	 * health) catch up by themselves, because they look at everything that
	 * is due. Work that belongs to a period — the invoice of a month, the
	 * summary of a day — does not: if cron did not run for three days, one
	 * run must produce three periods, each exactly once, never three
	 * duplicates of today (Blueprint §31, "cron didn't run for 3 days").
	 * The period is the idempotency key: it is written before its listeners
	 * run, so a second runner never repeats it.
	 *
	 * @param string $kind   hour|day.
	 * @param string $today  Current period ("2026-09-22").
	 * @param string $format Period format.
	 * @param string $step   Step between periods.
	 * @param int    $most   Periods caught up at most in one run.
	 * @return string[] Periods run.
	 */
	public function catch_up( string $kind, string $today, string $format, string $step, int $most ): array {
		$option = 'cliezoo_period_' . $kind;
		$last   = (string) get_option( $option, '' );
		if ( '' === $last ) {
			// First run: nothing to catch up on, only a place to start.
			update_option( $option, $today, false );
			return array();
		}
		if ( $last >= $today || ! $this->lock( $kind ) ) {
			return array();
		}
		$periods = array();
		$cursor  = \DateTimeImmutable::createFromFormat( '!' . $format, $last, new \DateTimeZone( 'UTC' ) );
		$found   = 0;
		while ( $cursor && $found < $most ) {
			$cursor = $cursor->modify( $step );
			$period = $cursor->format( $format );
			if ( $period > $today ) {
				break;
			}
			$periods[] = $period;
			++$found;
		}
		foreach ( $periods as $period ) {
			update_option( $option, $period, false );
			/**
			 * One missed (or the current) period, run exactly once.
			 *
			 * `cliezoo_hour_period` receives "Y-m-d H" (UTC);
			 * `cliezoo_day_period` receives "Y-m-d" (site time).
			 *
			 * @param string $period The period.
			 * @param bool   $late   Whether this period's time has passed.
			 */
			do_action( 'cliezoo_' . $kind . '_period', $period, $period !== $today );
		}
		// Anything more than the period just ended means runs were missed.
		if ( count( $periods ) > 1 ) {
			/** This action is documented in src/Infrastructure/Jobs/Jobs.php */
			do_action( 'cliezoo_jobs_late', ( count( $periods ) - 1 ) * ( 'day' === $kind ? DAY_IN_SECONDS : HOUR_IN_SECONDS ), $kind );
		}
		delete_option( 'cliezoo_period_lock_' . $kind );
		return $periods;
	}

	/**
	 * Takes the catch-up lock for a kind of period (an option insert is
	 * atomic: only one runner gets it). A lock older than ten minutes is a
	 * runner that died, and is taken over.
	 *
	 * @param string $kind hour|day.
	 * @return bool
	 */
	private function lock( string $kind ): bool {
		$name = 'cliezoo_period_lock_' . $kind;
		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}
		if ( time() - (int) get_option( $name, 0 ) > 10 * MINUTE_IN_SECONDS ) {
			update_option( $name, time(), false );
			return true;
		}
		return false;
	}

	/**
	 * Whether Safe mode pauses outbound work (Blueprint §31.4).
	 *
	 * @return bool
	 */
	public function safe_mode(): bool {
		return (bool) get_option( 'cliezoo_safe_mode', false );
	}

	/**
	 * Whether a job kind is paused by Safe mode.
	 *
	 * @param string $job Job name.
	 * @return bool
	 */
	private function paused( string $job ): bool {
		if ( ! $this->safe_mode() ) {
			return false;
		}
		/**
		 * Job names that contact the outside world and pause in Safe mode.
		 *
		 * @param string[] $jobs Job names.
		 */
		$outbound = (array) apply_filters( 'cliezoo_outbound_jobs', array( 'send_email', 'send_digest', 'webhook', 'automation', 'ai_run', 'gateway', 'recurring_invoice' ) );
		return in_array( $job, $outbound, true );
	}

	/**
	 * Last heartbeats for Setup Health.
	 *
	 * @return array{tick: int, daily: int, cron_disabled: bool}
	 */
	public function heartbeat(): array {
		return array(
			'tick'          => (int) get_option( 'cliezoo_heartbeat_tick', 0 ),
			'daily'         => (int) get_option( 'cliezoo_heartbeat_daily', 0 ),
			'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}
}
