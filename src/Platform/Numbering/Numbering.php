<?php
/**
 * Readable record numbers (INV-2026-0001).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Numbering;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic sequences with prefix, padding, next number, date tokens
 * ({YYYY}, {YY}, {MM}) and an optional yearly reset (cliezoo.md §4.4).
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * Table name from code; values are placeholders.
 */
final class Numbering {

	/**
	 * Default prefixes per series.
	 */
	public const DEFAULTS = array(
		'LEAD' => 'LEAD-',
		'CLI'  => 'CLI-',
		'CON'  => 'CON-',
		'DEAL' => 'DEAL-',
		'QUO'  => 'QUO-',
		'PRP'  => 'PRP-',
		'CTR'  => 'CTR-',
		'SOW'  => 'SOW-',
		'PRJ'  => 'PRJ-',
		'TSK'  => 'TSK-',
		'INV'  => 'INV-',
		'CN'   => 'CN-',
		'PAY'  => 'PAY-',
		'EXP'  => 'EXP-',
		'REQ'  => 'REQ-',
	);

	/**
	 * Sequences table.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cliezoo_sequences';
	}

	/**
	 * Series settings (creates the row with defaults on first use).
	 *
	 * @param string $series Series key.
	 * @return array{series: string, prefix: string, pattern: string, next_value: int, yearly_reset: bool, period: string}
	 */
	public function get( string $series ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT series, prefix, pattern, next_value, yearly_reset, period FROM {$this->table()} WHERE series = %s", $series ), ARRAY_A );
		if ( ! $row ) {
			$now = gmdate( 'Y-m-d H:i:s' );
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$this->table()} (series, prefix, pattern, next_value, yearly_reset, period, created_at, updated_at) VALUES (%s, %s, %s, 1, 0, '', %s, %s)",
					$series,
					self::DEFAULTS[ $series ] ?? $series . '-',
					'{PREFIX}{#####}',
					$now,
					$now
				)
			);
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT series, prefix, pattern, next_value, yearly_reset, period FROM {$this->table()} WHERE series = %s", $series ), ARRAY_A );
		}
		return array(
			'series'       => (string) $row['series'],
			'prefix'       => (string) $row['prefix'],
			'pattern'      => (string) $row['pattern'],
			'next_value'   => (int) $row['next_value'],
			'yearly_reset' => (bool) $row['yearly_reset'],
			'period'       => (string) $row['period'],
		);
	}

	/**
	 * Saves series settings.
	 *
	 * @param string               $series Series.
	 * @param array<string, mixed> $values prefix, pattern, next_value, yearly_reset.
	 * @return array<string, mixed>
	 */
	public function configure( string $series, array $values ): array {
		global $wpdb;
		$this->get( $series );
		$data = array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		if ( isset( $values['prefix'] ) ) {
			$data['prefix'] = mb_substr( sanitize_text_field( (string) $values['prefix'] ), 0, 40 );
		}
		if ( isset( $values['pattern'] ) ) {
			$data['pattern'] = mb_substr( sanitize_text_field( (string) $values['pattern'] ), 0, 80 );
		}
		if ( isset( $values['next_value'] ) ) {
			$data['next_value'] = max( 1, (int) $values['next_value'] );
		}
		if ( isset( $values['yearly_reset'] ) ) {
			$data['yearly_reset'] = $values['yearly_reset'] ? 1 : 0;
		}
		// A next number chosen now is this year's. Without the period the
		// first number after turning on the yearly reset would count as a
		// new year and start again from 1, ignoring what was just set.
		if ( isset( $values['next_value'] ) || ! empty( $values['yearly_reset'] ) ) {
			$data['period'] = wp_date( 'Y' );
		}
		$wpdb->update( $this->table(), $data, array( 'series' => $series ) );
		return $this->get( $series );
	}

	/**
	 * Whether a pattern makes numbers: exactly one run of {#…} for the
	 * counter (1 to 12 digits), and no token this class does not fill in.
	 * An unknown token would be printed as it stands on every record.
	 *
	 * @param string $pattern Pattern, e.g. "{PREFIX}{YYYY}-{####}".
	 * @return bool
	 */
	public static function valid_pattern( string $pattern ): bool {
		if ( 1 !== preg_match_all( '/\{#{1,12}\}/', $pattern ) ) {
			return false;
		}
		$rest = (string) preg_replace( '/\{(#{1,12}|PREFIX|YYYY|YY|MM|DD)\}/', '', $pattern );
		return ! preg_match( '/[{}]/', $rest );
	}

	/**
	 * Allocates the next number atomically.
	 *
	 * @param string   $series Series key.
	 * @param int|null $now    Unix time (tests).
	 * @return string
	 */
	public function next( string $series, ?int $now = null ): string {
		global $wpdb;
		$config = $this->get( $series );
		$period = wp_date( 'Y', $now ?? time() );
		if ( $config['yearly_reset'] && $config['period'] !== $period ) {
			// Only the first request of the new year resets (guarded by the period value).
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET next_value = 1, period = %s WHERE series = %s AND period <> %s", $period, $series, $period ) );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET next_value = LAST_INSERT_ID(next_value + 1) WHERE series = %s", $series ) );
		$value = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ) - 1;
		return $this->format( $config, $value, $now );
	}

	/**
	 * Formats a number (also used for the live preview).
	 *
	 * @param array<string, mixed> $config Series config.
	 * @param int                  $value  Counter value.
	 * @param int|null             $now    Unix time.
	 * @return string
	 */
	public function format( array $config, int $value, ?int $now = null ): string {
		$pattern = (string) $config['pattern'];
		$time    = $now ?? time();
		$out     = strtr(
			$pattern,
			array(
				'{PREFIX}' => (string) $config['prefix'],
				'{YYYY}'   => wp_date( 'Y', $time ),
				'{YY}'     => wp_date( 'y', $time ),
				'{MM}'     => wp_date( 'm', $time ),
				'{DD}'     => wp_date( 'd', $time ),
			)
		);
		return (string) preg_replace_callback(
			'/\{(#+)\}/',
			static fn( array $m ): string => str_pad( (string) $value, strlen( $m[1] ), '0', STR_PAD_LEFT ),
			$out
		);
	}

	/**
	 * Preview of the next number without allocating it.
	 *
	 * @param string $series Series.
	 * @return string
	 */
	public function preview( string $series ): string {
		$config = $this->get( $series );
		return $this->format( $config, $config['next_value'] );
	}

	/**
	 * Repairs every numbered record type (runs after install and upgrade).
	 *
	 * A site restored from an older database backup — or moved with a copy
	 * of the tables but not of the sequences — would hand out numbers that
	 * already exist. One MAX() per numbered table, only when migrations ran,
	 * is cheap enough to do every time rather than hope somebody remembers.
	 *
	 * @param \Cliezoo\Core\Data\EntityRegistry $entities Entities.
	 * Two record types may share a series; each table is checked, and the
	 * counter only ever moves forward, so it ends past the highest of both.
	 *
	 * @return int Tables checked.
	 */
	public function repair_all( \Cliezoo\Core\Data\EntityRegistry $entities ): int {
		global $wpdb;
		$checked = 0;
		foreach ( $entities->all() as $entity ) {
			if ( '' === $entity->series || ! $entity->has_column( 'number' ) ) {
				continue;
			}
			$table = $entity->table();
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				continue;
			}
			$this->repair( $entity->series, $table );
			++$checked;
		}
		return $checked;
	}

	/**
	 * After a restore from an old backup, moves counters past the highest used number (§31.4).
	 *
	 * @param string $series Series.
	 * @param string $table  Table holding `number`.
	 * @return void
	 */
	public function repair( string $series, string $table ): void {
		global $wpdb;
		$config = $this->get( $series );
		$prefix = $this->format( $config, 0 );
		$prefix = rtrim( $prefix, '0' );
		$like   = $wpdb->esc_like( $prefix ) . '%';
		$max    = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(CAST(SUBSTRING(number, %d) AS UNSIGNED)) FROM %i WHERE number LIKE %s', strlen( $prefix ) + 1, $table, $like ) );
		if ( null !== $max && (int) $max >= $config['next_value'] ) {
			$wpdb->update( $this->table(), array( 'next_value' => (int) $max + 1 ), array( 'series' => $series ) );
		}
	}
}
