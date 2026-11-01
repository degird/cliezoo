<?php
/**
 * What a workspace has got done: the badges a team earns as they put
 * Cliezoo to work.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Setup;

use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Badges (cliezoo.md §6.4, "🏅").
 *
 * Every badge is read from the workspace itself, the same rule the
 * checklist follows: a badge cannot claim something that did not happen.
 * What is kept is only the day each was first earned, so a badge does not
 * come and go when a record is put in the trash, and so the screen can
 * say which one is new.
 */
final class Achievements {

	/**
	 * Where the days earned are kept, inside the setup option.
	 */
	public const KEY = 'achievements';

	/**
	 * Every badge, with what earns it.
	 *
	 * @param Plugin               $plugin Plugin.
	 * @param Actor                $actor  Who is asking.
	 * @param array<string, mixed> $state  Where setup stands, so it is
	 *                                     worked out once per request.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( Plugin $plugin, Actor $actor, array $state = array() ): array {
		$counted = static function ( string $entity ) use ( $plugin ): int {
			if ( ! $plugin->entities()->has( $entity ) ) {
				return 0;
			}
			return count( $plugin->service( $entity )->repository()->where( array(), 'id', 2 ) );
		};
		$state   = $state ? $state : SetupModule::state( $plugin, $actor );
		$badges  = array(
			array(
				'id'    => 'set_up',
				'label' => __( 'Up and running', 'cliezoo' ),
				'about' => __( 'Everything on the setup list is done.', 'cliezoo' ),
				'done'  => 0 === (int) ( $state['remaining'] ?? 1 ),
			),
			array(
				'id'    => 'first_client',
				'label' => __( 'First client', 'cliezoo' ),
				'about' => __( 'A client of your own is on the books.', 'cliezoo' ),
				'done'  => $counted( 'clients' ) > 0,
			),
			array(
				'id'    => 'first_project',
				'label' => __( 'Work under way', 'cliezoo' ),
				'about' => __( 'A project has been started.', 'cliezoo' ),
				'done'  => $counted( 'projects' ) > 0,
			),
			array(
				'id'    => 'first_task_done',
				'label' => __( 'Something finished', 'cliezoo' ),
				'about' => __( 'A task has been ticked off.', 'cliezoo' ),
				'done'  => self::any_completed_task( $plugin ),
			),
			array(
				'id'    => 'team',
				'label' => __( 'Not on your own', 'cliezoo' ),
				'about' => __( 'Somebody else from your side has joined.', 'cliezoo' ),
				'done'  => count( $plugin->service( 'members' )->repository()->where( array( 'kind' => 'staff' ), 'id', 5 ) ) > 1,
			),
			array(
				'id'    => 'portal',
				'label' => __( 'Doors open', 'cliezoo' ),
				'about' => __( 'A client has been let into their portal.', 'cliezoo' ),
				'done'  => self::any_portal_person( $plugin ),
			),
		);

		/**
		 * Filters the badges (modules add their own as they land).
		 *
		 * @param array $badges Badges, each with id, label, about and done.
		 * @param Actor $actor  Who is asking.
		 */
		return array_values( (array) apply_filters( 'cliezoo_achievements', $badges, $actor ) );
	}

	/**
	 * The badges with the day each was first earned, writing down any that
	 * have just been earned.
	 *
	 * @param Plugin               $plugin Plugin.
	 * @param Actor                $actor  Who is asking.
	 * @param array<string, mixed> $saved  What is already written down.
	 * @param array<string, mixed> $state  Where setup stands.
	 * @return array{badges: array<int, array<string, mixed>>, earned: array<string, string>, fresh: string[]}
	 */
	public static function settle( Plugin $plugin, Actor $actor, array $saved, array $state = array() ): array {
		$earned = array();
		foreach ( (array) ( $saved[ self::KEY ] ?? array() ) as $id => $day ) {
			if ( is_string( $id ) && is_string( $day ) ) {
				$earned[ $id ] = $day;
			}
		}
		$fresh  = array();
		$badges = array();
		foreach ( self::all( $plugin, $actor, $state ) as $badge ) {
			$id   = (string) ( $badge['id'] ?? '' );
			$done = ! empty( $badge['done'] ) || isset( $earned[ $id ] );
			if ( $done && ! isset( $earned[ $id ] ) ) {
				$earned[ $id ] = gmdate( 'c' );
				$fresh[]       = $id;
			}
			$badges[] = array(
				'id'        => $id,
				'label'     => (string) ( $badge['label'] ?? $id ),
				'about'     => (string) ( $badge['about'] ?? '' ),
				'done'      => $done,
				'earned_at' => $earned[ $id ] ?? '',
			);
		}
		return array(
			'badges' => $badges,
			'earned' => $earned,
			'fresh'  => $fresh,
		);
	}

	/**
	 * Whether any task has ever been ticked off.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return bool
	 */
	private static function any_completed_task( Plugin $plugin ): bool {
		if ( ! $plugin->entities()->has( 'tasks' ) ) {
			return false;
		}
		global $wpdb;
		$table = esc_sql( $plugin->entities()->get( 'tasks' )->table() );
		$found = $wpdb->get_var( "SELECT id FROM {$table} WHERE completed_at IS NOT NULL LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from the entity definition, no user input.
		return null !== $found;
	}

	/**
	 * Whether anybody from a client has an account they can sign in with.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return bool
	 */
	private static function any_portal_person( Plugin $plugin ): bool {
		if ( ! $plugin->entities()->has( 'contacts' ) ) {
			return false;
		}
		$people = $plugin->service( 'contacts' )->repository()->where( array( 'portal_status' => 'active' ), 'id', 1 );
		return count( $people ) > 0;
	}
}
