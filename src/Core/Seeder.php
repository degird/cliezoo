<?php
/**
 * Example records for `wp cliezoo seed`.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A small, believable workspace to try Cliezoo on or to develop against:
 * a few clients with contacts, a project each, a board and a handful of
 * tasks with due dates (Blueprint §16.3, "a demo-data seed").
 *
 * Everything is created through the same actions the app uses, as the
 * workspace owner, so it has owners, numbers, activity and search entries
 * like anything a person made. What it made is written down, and
 * `remove()` deletes exactly that and nothing else.
 */
final class Seeder {

	/**
	 * Where the seeded record uids are kept, per type.
	 */
	public const OPTION = 'cliezoo_seeded';

	/**
	 * Types in the order they are removed (children before parents).
	 */
	private const REMOVE_ORDER = array( 'tasks', 'boards', 'projects', 'contacts', 'clients' );

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Who the records are made by.
	 *
	 * @var Actor
	 */
	private Actor $actor;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param Actor  $actor  Who makes the records.
	 */
	public function __construct( Plugin $plugin, Actor $actor ) {
		$this->plugin = $plugin;
		$this->actor  = $actor;
	}

	/**
	 * Adds the example records.
	 *
	 * @param int $clients How many clients (1–20).
	 * @return array<string, int> Created, per type.
	 */
	public function add( int $clients = 3 ): array {
		$made    = array_fill_keys( self::REMOVE_ORDER, 0 );
		$seeded  = $this->seeded();
		$names   = array( 'Bluebird Studio', 'Northwind Bakery', 'Harbour & Pine Architects', 'Lumen Dental', 'Fieldnote Coffee', 'Orbit Cycles' );
		$people  = array( array( 'Maya Okafor', 'Tom Brennan' ), array( 'Lena Fischer', 'Sam Ortiz' ), array( 'Priya Raman', 'Jonas Berg' ) );
		$work    = array( 'Brand refresh', 'Website rebuild', 'Spring campaign', 'Booking system' );
		$tasks   = array( 'Kick-off call', 'Collect brand assets', 'First draft', 'Client review', 'Final delivery' );
		$clients = max( 1, min( 20, $clients ) );
		$today   = time();
		for ( $i = 0; $i < $clients; $i++ ) {
			$name   = $names[ $i % count( $names ) ] . ( $i >= count( $names ) ? ' ' . ( intdiv( $i, count( $names ) ) + 1 ) : '' );
			$client = $this->make( 'clients', array( 'name' => $name ) );
			if ( ! $client ) {
				continue;
			}
			$seeded['clients'][] = (string) $client['uid'];
			++$made['clients'];
			foreach ( $people[ $i % count( $people ) ] as $n => $person ) {
				$contact = $this->make(
					'contacts',
					array(
						'client' => $client['uid'],
						'name'   => $person,
						// example.com is reserved for examples: nothing is
						// ever delivered to it (RFC 2606).
						'email'  => sanitize_title( $person ) . '+' . strtolower( substr( (string) $client['uid'], -6 ) ) . '@example.com',
					) + ( 0 === $n ? array( 'is_primary' => true ) : array() )
				);
				if ( $contact ) {
					$seeded['contacts'][] = (string) $contact['uid'];
					++$made['contacts'];
				}
			}
			$project = $this->make(
				'projects',
				array(
					'name'   => $work[ $i % count( $work ) ],
					'client' => $client['uid'],
				)
			);
			if ( ! $project ) {
				continue;
			}
			$seeded['projects'][] = (string) $project['uid'];
			++$made['projects'];
			$board = $this->plugin->entities()->has( 'boards' ) ? $this->make(
				'boards',
				array(
					'name'    => $work[ $i % count( $work ) ],
					'project' => $project['uid'],
				)
			) : null;
			if ( ! $board ) {
				continue;
			}
			$seeded['boards'][] = (string) $board['uid'];
			++$made['boards'];
			foreach ( $tasks as $t => $title ) {
				$task = $this->make(
					'tasks',
					array(
						'title'    => $title,
						'board'    => $board['uid'],
						'due_date' => gmdate( 'Y-m-d', $today + ( $t * 3 + $i ) * DAY_IN_SECONDS ),
					)
				);
				if ( $task ) {
					$seeded['tasks'][] = (string) $task['uid'];
					++$made['tasks'];
				}
			}
		}
		update_option( self::OPTION, $seeded, false );
		return $made;
	}

	/**
	 * Removes what add() made.
	 *
	 * @return array<string, int> Removed, per type.
	 */
	public function remove(): array {
		$seeded  = $this->seeded();
		$removed = array_fill_keys( self::REMOVE_ORDER, 0 );
		$system  = Actor::system( 'cli' );
		foreach ( self::REMOVE_ORDER as $type ) {
			if ( ! $this->plugin->entities()->has( $type ) ) {
				continue;
			}
			$service = $this->plugin->service( $type );
			foreach ( (array) ( $seeded[ $type ] ?? array() ) as $uid ) {
				$row = $service->repository()->find_by_uid( (string) $uid );
				if ( ! $row ) {
					continue;
				}
				try {
					$service->purge_row( $system, $row );
					++$removed[ $type ];
				} catch ( ActionError $error ) {
					unset( $error );
				}
			}
		}
		delete_option( self::OPTION );
		return $removed;
	}

	/**
	 * Creates one record through its action; null when it could not be made.
	 *
	 * @param string               $type  Entity.
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|null
	 */
	private function make( string $type, array $input ): ?array {
		if ( ! $this->plugin->entities()->has( $type ) ) {
			return null;
		}
		try {
			$row = $this->plugin->actions()->run( $type . '.create', $this->actor, $input );
		} catch ( ActionError $error ) {
			Logger::debug(
				'Seed record skipped',
				array(
					'type'  => $type,
					'error' => $error->getMessage(),
				)
			);
			return null;
		}
		return is_array( $row ) && ! empty( $row['uid'] ) ? $row : null;
	}

	/**
	 * What was seeded so far.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function seeded(): array {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}
}
