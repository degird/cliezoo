<?php
/**
 * Relative date ranges ("last 30 days", "this month").
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Resolves relative ranges in the workspace time zone, returning bounds in
 * the column's storage form (DATE as-is, DATETIME in UTC).
 */
final class DateRange {

	public const PRESETS = array( 'today', 'yesterday', 'tomorrow', 'this_week', 'last_week', 'next_week', 'this_month', 'last_month', 'next_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'overdue', 'upcoming' );

	/**
	 * Resolves a range spec.
	 *
	 * Spec: a preset string, or {"last": n, "unit": "day|week|month|year"},
	 * or {"next": n, "unit": …}, or {"from": "Y-m-d", "to": "Y-m-d"}.
	 *
	 * @param mixed    $spec      Range spec.
	 * @param bool     $date_only Column is DATE (no time zone conversion).
	 * @param int|null $now       Unix time (tests).
	 * @return array{0:string,1:string}
	 * @throws QueryException On an invalid spec.
	 */
	public static function resolve( $spec, bool $date_only, ?int $now = null ): array {
		$tz         = wp_timezone();
		$today      = ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( $tz )->setTime( 0, 0 );
		$week_start = (int) get_option( 'start_of_week', 1 );

		if ( is_string( $spec ) ) {
			switch ( $spec ) {
				case 'today':
					$from = $today;
					$to   = $today;
					break;
				case 'yesterday':
					$from = $today->modify( '-1 day' );
					$to   = $from;
					break;
				case 'tomorrow':
					$from = $today->modify( '+1 day' );
					$to   = $from;
					break;
				case 'this_week':
				case 'last_week':
				case 'next_week':
					$offset = ( (int) $today->format( 'w' ) - $week_start + 7 ) % 7;
					$from   = $today->modify( "-{$offset} days" );
					if ( 'last_week' === $spec ) {
						$from = $from->modify( '-7 days' );
					} elseif ( 'next_week' === $spec ) {
						$from = $from->modify( '+7 days' );
					}
					$to = $from->modify( '+6 days' );
					break;
				case 'this_month':
				case 'last_month':
				case 'next_month':
					$from = $today->modify( 'first day of this month' );
					if ( 'last_month' === $spec ) {
						$from = $from->modify( '-1 month' );
					} elseif ( 'next_month' === $spec ) {
						$from = $from->modify( '+1 month' );
					}
					$to = $from->modify( 'last day of this month' );
					break;
				case 'this_quarter':
				case 'last_quarter':
					$quarter = intdiv( (int) $today->format( 'n' ) - 1, 3 );
					$from    = $today->setDate( (int) $today->format( 'Y' ), $quarter * 3 + 1, 1 );
					if ( 'last_quarter' === $spec ) {
						$from = $from->modify( '-3 months' );
					}
					$to = $from->modify( '+2 months' )->modify( 'last day of this month' );
					break;
				case 'this_year':
				case 'last_year':
					$year = (int) $today->format( 'Y' ) - ( 'last_year' === $spec ? 1 : 0 );
					$from = $today->setDate( $year, 1, 1 );
					$to   = $today->setDate( $year, 12, 31 );
					break;
				case 'overdue':
					$from = $today->setDate( 1970, 1, 1 );
					$to   = $today->modify( '-1 day' );
					break;
				case 'upcoming':
					$from = $today;
					$to   = $today->setDate( 2999, 12, 31 );
					break;
				default:
					throw new QueryException( __( 'Unknown date range.', 'cliezoo' ) );
			}
		} elseif ( is_array( $spec ) && isset( $spec['from'], $spec['to'] ) ) {
			$from = self::parse( (string) $spec['from'], $tz );
			$to   = self::parse( (string) $spec['to'], $tz );
		} elseif ( is_array( $spec ) && ( isset( $spec['last'] ) || isset( $spec['next'] ) ) ) {
			$amount = max( 0, min( 3650, (int) ( $spec['last'] ?? $spec['next'] ) ) );
			$unit   = (string) ( $spec['unit'] ?? 'day' );
			if ( ! in_array( $unit, array( 'day', 'week', 'month', 'year' ), true ) ) {
				throw new QueryException( __( 'Unknown date unit.', 'cliezoo' ) );
			}
			if ( isset( $spec['last'] ) ) {
				$from = $today->modify( "-{$amount} {$unit}" );
				$to   = $today;
			} else {
				$from = $today;
				$to   = $today->modify( "+{$amount} {$unit}" );
			}
		} else {
			throw new QueryException( __( 'Invalid date range.', 'cliezoo' ) );
		}

		if ( $date_only ) {
			return array( $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ) );
		}
		$utc = new \DateTimeZone( 'UTC' );
		return array(
			$from->setTime( 0, 0 )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$to->setTime( 23, 59, 59 )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Parses a Y-m-d date in the workspace zone.
	 *
	 * @param string        $value Date.
	 * @param \DateTimeZone $tz    Zone.
	 * @return \DateTimeImmutable
	 * @throws QueryException On bad input.
	 */
	private static function parse( string $value, \DateTimeZone $tz ): \DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $tz );
		if ( ! $date ) {
			throw new QueryException( __( 'Invalid date.', 'cliezoo' ) );
		}
		return $date;
	}
}
