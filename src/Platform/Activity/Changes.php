<?php
/**
 * What changed since a moment ago.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Activity;

use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\Data\ListQuery;
use Cliezoo\Core\Data\Repository;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /changes?since=` is the one call an open screen makes on a timer: it
 * says which record types changed for this person since they last asked, so
 * the app can refresh only those lists instead of polling each of them
 * (Blueprint §11.6). It answers from the activity feed, so it obeys the
 * same scoping: nobody is told that something they cannot see has changed.
 */
final class Changes {

	private const MAX_WINDOW = 3600;

	/**
	 * Registers the action.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	public static function register( ActionRegistry $registry, Plugin $plugin ): void {
		$registry->add(
			Op::make( 'changes.since', __( 'What changed', 'cliezoo' ) )
				->describe( __( 'Record types that changed since a moment you name, so an open screen can refresh just those.', 'cliezoo' ) )
				->input(
					array(
						'since' => array(
							'type'     => 'string',
							'format'   => 'date-time',
							'required' => true,
						),
					)
				)
				->allow( static fn( Actor $a ): bool => ! $a->is_guest() )
				->run( static fn( Actor $a, array $in ): array => self::since( $plugin, $a, (string) $in['since'] ) )
				->rest( 'GET', '/changes' )
				->readonly()
				->with( array( 'ability' => false ) )
		);
	}

	/**
	 * Changes since a moment, per record type.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param Actor  $actor  Actor.
	 * @param string $since  ISO 8601 moment.
	 * @return array<string, mixed>
	 */
	private static function since( Plugin $plugin, Actor $actor, string $since ): array {
		$now  = time();
		$from = strtotime( $since );
		// A client that was asleep (or has a wrong clock) never asks for more
		// than the window: it should reload rather than replay an hour.
		$from         = ( ! $from || $from < $now - self::MAX_WINDOW ) ? $now - self::MAX_WINDOW : min( $from, $now );
		$log          = $plugin->get( ActivityLog::class );
		$query        = new ListQuery();
		$query->limit = 200;
		$query->sort  = '-created_at';
		$query->where( 't.created_at > %s', array( gmdate( 'Y-m-d H:i:s', $from ) ) );
		$log->restrict( $query, $actor );

		$entity = $plugin->entities()->get( 'activity' );
		$found  = ( new Repository( $entity, $plugin->entities() ) )->list( $query, null )['rows'];
		// The feed's own SQL says where a row belongs, not whether the
		// record behind it may be opened. A count is still something told,
		// so the rows go through the same reading the feed itself gets.
		$rows  = $log->readable( $found, $actor );
		$types = array();
		foreach ( $rows as $row ) {
			$type           = (string) $row['object_type'];
			$types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
		}
		arsort( $types );
		return array(
			'now'     => gmdate( 'c', $now ),
			'types'   => $types,
			'total'   => array_sum( $types ),
			// Whether the window was full, which is about the window and
			// not about what is in it.
			'dropped' => count( $found ) >= 200,
		);
	}
}
