<?php
/**
 * How a client relationship is doing, as one number.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Clients;

use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names come from the entity definitions; every value is a placeholder.

/**
 * The health score is a weighted average of a few parts, each scored out of
 * a hundred, and it always shows its working: a number nobody can explain
 * is a number nobody acts on (cliezoo.md §6.5).
 *
 * The formula is fixed — the weights below — but which parts apply depends
 * on what the workspace uses. A module contributes its part through
 * `cliezoo_client_health_parts` as it lands: invoices score payment
 * behaviour, tasks score overdue work, conversations score how quickly
 * messages are answered. Parts that cannot be measured are left out and the
 * remaining weights are shared out, so a workspace without invoices still
 * gets an honest score rather than a penalty for a feature it does not use.
 */
final class HealthScore {

	/**
	 * Weights, by part id. Parts registered elsewhere name themselves here.
	 */
	public const WEIGHTS = array(
		'payments'       => 35,
		'work'           => 20,
		'responsiveness' => 15,
		'satisfaction'   => 10,
		'activity'       => 20,
	);

	/**
	 * How many days of silence count as none at all.
	 */
	private const QUIET_DAYS = 60;

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
	 * The score for one client, with the parts it is made of.
	 *
	 * @param int $client_id Client id.
	 * @return array{score: int|null, parts: array<int, array<string, mixed>>}
	 */
	public function for_client( int $client_id ): array {
		$parts = array( $this->activity_part( $client_id ) );
		/**
		 * Parts of the client health score.
		 *
		 * Each part is `['id' => string, 'label' => string, 'score' => 0..100,
		 * 'detail' => string]`. Return nothing for a client you cannot
		 * measure; its weight goes to the parts that can be.
		 *
		 * @param array  $parts     Parts so far.
		 * @param int    $client_id Client id.
		 * @param Plugin $plugin    Plugin.
		 */
		$parts = (array) apply_filters( 'cliezoo_client_health_parts', $parts, $client_id, $this->plugin );

		$total  = 0;
		$weight = 0;
		$out    = array();
		foreach ( $parts as $part ) {
			$id = (string) ( $part['id'] ?? '' );
			if ( ! isset( self::WEIGHTS[ $id ] ) || ! isset( $part['score'] ) ) {
				continue;
			}
			$score   = max( 0, min( 100, (int) round( (float) $part['score'] ) ) );
			$each    = self::WEIGHTS[ $id ];
			$total  += $score * $each;
			$weight += $each;
			$out[]   = array(
				'id'     => $id,
				'label'  => (string) ( $part['label'] ?? $id ),
				'score'  => $score,
				'weight' => $each,
				'detail' => (string) ( $part['detail'] ?? '' ),
			);
		}

		return array(
			'score' => $weight > 0 ? (int) round( $total / $weight ) : null,
			'parts' => $out,
		);
	}

	/**
	 * Works out the score and writes it to the client row.
	 *
	 * @param int $client_id Client id.
	 * @return array{score: int|null, parts: array<int, array<string, mixed>>}
	 */
	public function refresh( int $client_id ): array {
		$result = $this->for_client( $client_id );
		$before = $this->plugin->service( 'clients' )->repository()->find( $client_id );
		$was    = is_array( $before ) && null !== ( $before['health_at'] ?? null ) ? (int) $before['health_score'] : null;
		$this->plugin->service( 'clients' )->repository()->update(
			$client_id,
			array(
				'health_score' => null === $result['score'] ? 0 : $result['score'],
				'health_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( null !== $result['score'] && $was !== (int) $result['score'] ) {
			/**
			 * A client's health score changed.
			 *
			 * @param int      $client_id Client id.
			 * @param int|null $was       The score before (null: never scored).
			 * @param int      $score     The score now.
			 */
			do_action( 'cliezoo_client_health_changed', $client_id, $was, (int) $result['score'] );
		}
		return $result;
	}

	/**
	 * Refreshes every active client, in batches, once a day.
	 *
	 * @return int How many were scored.
	 */
	public function refresh_all(): int {
		$repository = $this->plugin->service( 'clients' )->repository();
		$after      = 0;
		$done       = 0;
		while ( true ) {
			$rows = $repository->batch( $after, 200 );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$after = (int) $row['id'];
				if ( 'former' === (string) $row['status'] ) {
					continue;
				}
				$this->refresh( $after );
				++$done;
			}
		}
		return $done;
	}

	/**
	 * How alive the relationship looks: how recently anything happened, and
	 * how much happened over the last three months.
	 *
	 * @param int $client_id Client id.
	 * @return array<string, mixed>
	 */
	private function activity_part( int $client_id ): array {
		global $wpdb;
		$table   = esc_sql( $this->plugin->entities()->get( 'activity' )->table() );
		$row     = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Table name from the entity definition.
			$wpdb->prepare(
				"SELECT COUNT(*) AS events, MAX(created_at) AS last_at FROM {$table} WHERE client_id = %d AND created_at > %s",
				$client_id,
				gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		$events  = (int) ( $row['events'] ?? 0 );
		$last_at = (string) ( $row['last_at'] ?? '' );

		if ( 0 === $events ) {
			return array(
				'id'     => 'activity',
				'label'  => __( 'Recent activity', 'cliezoo' ),
				'score'  => 0,
				'detail' => __( 'Nothing has happened here in three months.', 'cliezoo' ),
			);
		}

		$days   = (int) floor( ( time() - (int) strtotime( $last_at . ' UTC' ) ) / DAY_IN_SECONDS );
		$recent = max( 0, 100 - (int) round( $days / self::QUIET_DAYS * 100 ) );
		// Twenty events in three months is as busy as this part measures.
		$volume = min( 100, (int) round( $events / 20 * 100 ) );

		return array(
			'id'     => 'activity',
			'label'  => __( 'Recent activity', 'cliezoo' ),
			'score'  => (int) round( $recent * 0.7 + $volume * 0.3 ),
			'detail' => 0 === $days
				? sprintf(
					/* translators: %d: number of events. */
					_n( '%d thing happened today.', '%d things happened in the last three months, the most recent today.', $events, 'cliezoo' ),
					$events
				)
				: sprintf(
					/* translators: 1: number of events, 2: number of days. */
					__( '%1$d things in three months; the last was %2$d days ago.', 'cliezoo' ),
					$events,
					$days
				),
		);
	}
}
