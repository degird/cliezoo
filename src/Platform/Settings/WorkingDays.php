<?php
/**
 * Which days a studio works, and counting in them.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Settings;

use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The working week and the holidays set in Settings → Localization, as a
 * calendar can count them.
 *
 * Both were stored, edited and read by nothing: a quote promising delivery in
 * ten working days had to be counted by hand, across the bank holiday the
 * studio had already entered. A holiday marked recurring falls on the same
 * day every year.
 */
final class WorkingDays {

	/**
	 * How far a count may go looking for working days before it gives up —
	 * a week with no working day in it at all would otherwise never end.
	 */
	private const LONGEST = 3660;

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
	 * Whether a day is one the studio works.
	 *
	 * @param string $day Y-m-d.
	 * @return bool
	 */
	public function is_working( string $day ): bool {
		$at = strtotime( $day . ' 12:00:00 UTC' );
		if ( false === $at ) {
			return false;
		}
		$week = array_map( 'intval', (array) $this->plugin->settings()->get( 'workspace', 'working_days', array( 1, 2, 3, 4, 5 ) ) );
		if ( ! in_array( (int) gmdate( 'w', $at ), $week, true ) ) {
			return false;
		}
		foreach ( (array) $this->plugin->settings()->get( 'workspace', 'holidays', array() ) as $holiday ) {
			$date = (string) ( $holiday['date'] ?? '' );
			if ( '' === $date ) {
				continue;
			}
			if ( $date === $day || ( ! empty( $holiday['recurring'] ) && substr( $date, 5 ) === substr( $day, 5 ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The day a number of working days after another.
	 *
	 * Counted from the day after: ten working days from a Monday with no
	 * holidays is the Monday a fortnight on. Nought is the day itself.
	 *
	 * @param string $from Y-m-d.
	 * @param int    $days How many working days.
	 * @return string Y-m-d.
	 */
	public function add( string $from, int $days ): string {
		$at   = (int) strtotime( $from . ' 12:00:00 UTC' );
		$left = max( 0, $days );
		$step = 0;
		while ( $left > 0 && $step < self::LONGEST ) {
			$at += DAY_IN_SECONDS;
			++$step;
			if ( $this->is_working( gmdate( 'Y-m-d', $at ) ) ) {
				--$left;
			}
		}
		return gmdate( 'Y-m-d', $at );
	}
}
