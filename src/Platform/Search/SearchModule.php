<?php
/**
 * Global search.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Search;

use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Field;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `cliezoo_search_index` (FULLTEXT on title + body, LIKE fallback), kept up to
 * date by record events; results are permission-checked per type.
 */
final class SearchModule extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'search';
	}

	/**
	 * {@inheritDoc}
	 */
	public function switchable(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function entities(): array {
		return array(
			( new Entity(
				'search_index',
				array(
					'object_type' => Field::text( 40 )->required(),
					'object_id'   => Field::int()->required(),
					'client_id'   => Field::int(),
					'project_id'  => Field::int(),
					'title'       => Field::text( 255 ),
					'body'        => Field::longtext(),
					'keywords'    => Field::text( 255 ),
					// Text only staff may find a record by: internal notes
					// and custom fields kept from clients. Its own FULLTEXT
					// index, so a client's search never reads it.
					'staff_body'  => Field::longtext(),
					// Who wrote the record or its messages, as "|12|40|",
					// for the `from:` operator.
					'authors'     => Field::text( 255 ),
					'status'      => Field::text( 40 ),
					'archived'    => Field::bool(),
				)
			) )->with(
				array(
					'public'      => false,
					'soft_delete' => false,
					'activity'    => false,
					'unique'      => array( 'object' => array( 'object_type', 'object_id' ) ),
					'indexes'     => array(
						'client'  => array( 'client_id' ),
						'updated' => array( 'updated_at' ),
					),
					'fulltext'    => array(
						'content' => array( 'title', 'body', 'keywords' ),
						'staff'   => array( 'staff_body' ),
					),
				)
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function migrations(): array {
		return array(
			// The index learnt message text, doc content, custom fields and
			// authors: rows written before then are read again, in batches.
			'2026_09_reindex_text' => static function (): void {
				if ( function_exists( 'cliezoo' ) && cliezoo()->container()->has( \Cliezoo\Infrastructure\Jobs\Jobs::class ) ) {
					cliezoo()->get( \Cliezoo\Infrastructure\Jobs\Jobs::class )->queue( 'search_reindex', array( 'entity' => '' ) );
				}
			},
		);
	}

	/**
	 * Registers hooks and actions.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( Search::class, static fn(): Search => new Search( $plugin ) );
		$search = static fn(): Search => $plugin->get( Search::class );

		add_action(
			'cliezoo_record_created',
			static function ( Entity $entity, array $row ) use ( $search ): void {
				$search()->index( $entity, $row );
			},
			20,
			2
		);
		add_action(
			'cliezoo_record_updated',
			static function ( Entity $entity, array $row ) use ( $search ): void {
				$search()->index( $entity, $row );
			},
			20,
			2
		);
		foreach ( array( 'archived', 'unarchived', 'restored' ) as $event ) {
			add_action(
				'cliezoo_record_' . $event,
				static function ( Entity $entity, array $row ) use ( $search ): void {
					$search()->index( $entity, $row );
				},
				20,
				2
			);
		}
		foreach ( array( 'trashed', 'purging' ) as $event ) {
			add_action(
				'cliezoo_record_' . $event,
				static function ( Entity $entity, array $row ) use ( $search ): void {
					$search()->remove( $entity->name, (int) $row['id'] );
				},
				20,
				2
			);
		}
		// A message said, edited, deleted or restored changes what its
		// thread is found by.
		foreach ( array( 'created', 'updated', 'trashed', 'restored' ) as $event ) {
			add_action(
				'cliezoo_record_' . $event,
				static function ( Entity $entity, array $row ) use ( $search ): void {
					if ( 'comments' === $entity->name ) {
						$search()->index_message( $row );
					}
				},
				21,
				2
			);
		}
		add_action( 'cliezoo_job_search_reindex', static fn( array $args ) => $search()->reindex_batch( (string) ( $args['entity'] ?? '' ), (int) ( $args['after'] ?? 0 ) ) );

		add_action(
			'cliezoo_register_actions',
			static function ( ActionRegistry $registry ) use ( $search ): void {
				$registry->add(
					Op::make( 'search', __( 'Search everything', 'cliezoo' ) )
						->describe( __( 'Searches every record type you can see. Operators: type:invoice, status:overdue, client:"Bluedot", message:text, before:2026-01-31, after:2026-01-01.', 'cliezoo' ) )
						->input(
							array(
								'q'     => Op::str( 200, true ),
								'type'  => array(
									'type'    => 'string',
									'pattern' => '^[a-z_]{2,40}$',
								),
								'limit' => array(
									'type'    => 'integer',
									'minimum' => 1,
									'maximum' => 50,
									'default' => 20,
								),
							)
						)
						->allow( static fn( Actor $a ): bool => ! $a->is_guest() )
						->run( static fn( Actor $a, array $in ) => $search()->search( $a, (string) $in['q'], (string) ( $in['type'] ?? '' ), (int) ( $in['limit'] ?? 20 ) ) )
						->rest( 'GET', '/search' )
						->readonly()
						->with( array( 'mcp' => 'read' ) )
				);
				$registry->add(
					Op::make( 'search.recent', __( 'Recent searches', 'cliezoo' ) )
						->allow( static fn( Actor $a ): bool => ! $a->is_guest() )
						->run( static fn( Actor $a ) => $search()->recent( $a ) )
						->rest( 'GET', '/search/recent' )
						->readonly()
				);
			}
		);
	}
}
