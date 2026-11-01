<?php
/**
 * The business health score, with a fixed formula that says how it got
 * there.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Dashboard;

use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A score is a weighted average of parts, each scored 0–100 and each
 * explained in a sentence. The parts and their weights are fixed in Free
 * (cliezoo.md §6.3): the business is judged on overdue receivables,
 * margin, overdue tasks and pipeline cover. (A client's score is the
 * clients module's HealthScore.)
 *
 * A part that cannot be measured yet — there are no invoices before the
 * invoicing module, no pipeline before deals — is shown as such, with
 * what would measure it, and leaves the score rather than counting as
 * zero. Modules supply their parts through `cliezoo_business_health`.
 */
final class Health {

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
	 * The business's health, as the person asking can see it.
	 *
	 * @param Actor $actor Who is asking.
	 * @return array<string, mixed>
	 */
	public function business( Actor $actor ): array {
		$metrics = $this->plugin->get( Metrics::class );
		$parts   = array(
			'receivables' => array(
				'label'  => __( 'Overdue invoices', 'cliezoo' ),
				'weight' => 30,
				'needs'  => __( 'Measured once invoicing is on.', 'cliezoo' ),
			),
			'margin'      => array(
				'label'  => __( 'Margin', 'cliezoo' ),
				'weight' => 25,
				'needs'  => __( 'Measured once invoices and costs are recorded.', 'cliezoo' ),
			),
			'tasks'       => array(
				'label'   => __( 'Overdue tasks', 'cliezoo' ),
				'weight'  => 25,
				'needs'   => __( 'Measured once there are open tasks to be late.', 'cliezoo' ),
				'measure' => function () use ( $metrics, $actor ): ?array {
					// Without tasks, or without sight of them, the part
					// cannot be measured; it is not a perfect score.
					$tasks = $this->plugin->entities()->has( 'tasks' ) ? $this->plugin->entities()->get( 'tasks' ) : null;
					if ( ! $tasks || ! $this->plugin->policy()->can( $actor, 'view', $tasks ) ) {
						return null;
					}
					$open    = $metrics->count( $actor, 'tasks', 't.completed_at IS NULL AND t.parent_id IS NULL AND t.archived_at IS NULL' );
					$overdue = $metrics->count( $actor, 'tasks', 't.completed_at IS NULL AND t.parent_id IS NULL AND t.archived_at IS NULL AND t.due_date < %s', array( wp_date( 'Y-m-d' ) ) );
					// Nothing to be late is nothing to score. A workspace on
					// its first morning is not an A; it is unmeasured, and
					// the part says what would measure it.
					if ( null === $open || null === $overdue || 0 === $open ) {
						return null;
					}
					$share = $overdue / $open;
					return array(
						'score'   => (int) round( 100 * ( 1 - min( 1, $share * 2 ) ) ),
						'value'   => sprintf(
							/* translators: 1: overdue tasks, 2: open tasks. */
							__( '%1$d of %2$d', 'cliezoo' ),
							$overdue,
							$open
						),
						'explain' => __( 'Full marks with nothing late; none once half the open tasks are past their due date.', 'cliezoo' ),
					);
				},
			),
			'pipeline'    => array(
				'label'  => __( 'Pipeline cover', 'cliezoo' ),
				'weight' => 20,
				'needs'  => __( 'Measured once deals are on.', 'cliezoo' ),
			),
		);
		/**
		 * The parts of the business health score. Each: label, weight,
		 * measure (callable → score 0–100, value, explain; null when it
		 * cannot be measured) or needs (what would measure it).
		 *
		 * @param array<string, array<string, mixed>> $parts Parts.
		 * @param Actor                               $actor Who is asking.
		 */
		return self::score( (array) apply_filters( 'cliezoo_business_health', $parts, $actor ) );
	}

	/**
	 * Scores parts: each one measured or said to be unmeasured, and their
	 * weighted average over the measured ones.
	 *
	 * @param array<string, array<string, mixed>> $parts Parts.
	 * @return array<string, mixed>
	 */
	public static function score( array $parts ): array {
		$out    = array();
		$total  = 0;
		$weight = 0;
		foreach ( $parts as $key => $part ) {
			$found = isset( $part['measure'] ) && is_callable( $part['measure'] ) ? ( $part['measure'] )() : null;
			$w     = max( 0, (int) ( $part['weight'] ?? 0 ) );
			if ( is_array( $found ) ) {
				$score   = max( 0, min( 100, (int) ( $found['score'] ?? 0 ) ) );
				$total  += $score * $w;
				$weight += $w;
			}
			$out[] = array(
				'key'      => (string) $key,
				'label'    => (string) ( $part['label'] ?? $key ),
				'weight'   => $w,
				'measured' => is_array( $found ),
				'score'    => is_array( $found ) ? max( 0, min( 100, (int) ( $found['score'] ?? 0 ) ) ) : null,
				'value'    => is_array( $found ) ? (string) ( $found['value'] ?? '' ) : '',
				'explain'  => is_array( $found ) ? (string) ( $found['explain'] ?? '' ) : (string) ( $part['needs'] ?? '' ),
			);
		}
		$score = $weight ? (int) round( $total / $weight ) : null;
		return array(
			'score'  => $score,
			'grade'  => null === $score ? '' : ( $score >= 75 ? 'good' : ( $score >= 50 ? 'fair' : 'poor' ) ),
			'letter' => self::letter( $score ),
			'parts'  => $out,
		);
	}

	/**
	 * The score as a letter, the way a report card reads: A from 90, B from
	 * 80, C from 70, D from 60, and F below that.
	 *
	 * @param int|null $score The score, or null when nothing was measured.
	 * @return string
	 */
	public static function letter( ?int $score ): string {
		if ( null === $score ) {
			return '';
		}
		foreach ( array(
			'A' => 90,
			'B' => 80,
			'C' => 70,
			'D' => 60,
		) as $letter => $from ) {
			if ( $score >= $from ) {
				return $letter;
			}
		}
		return 'F';
	}
}
