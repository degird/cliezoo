<?php
/**
 * Client actions beyond create, read, update and delete.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Clients;

use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\Data\ListQuery;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Merging two records that are the same company, the health score and its
 * working, everything the workspace holds about one client as data, and
 * turning the portal off without losing anything (cliezoo.md §6.5).
 *
 * As everywhere else in the action layer, `allow` answers "may this kind of
 * person do this at all" and the work itself loads each record through the
 * policy — so a record somebody cannot see fails as a missing record rather
 * than as an error inside a permission check.
 */
final class ClientActions {

	/**
	 * The route path for one client.
	 */
	private const ONE = '/clients/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})';

	/**
	 * Registers the actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	public static function register( ActionRegistry $registry, Plugin $plugin ): void {
		self::reading( $registry, $plugin );

		$tools = static fn(): ClientTools => $plugin->get( ClientTools::class );
		$edits = static fn( Actor $actor ): bool => $actor->is_staff() && ( $actor->is_admin() || $actor->allows( 'clients_edit' ) );

		$registry->add(
			Op::make( 'clients.related', __( 'What belongs to a client', 'cliezoo' ) )
				->describe( __( 'How many contacts, projects, files and so on belong to a client, before it is deleted.', 'cliezoo' ) )
				->input( array( 'uid' => Op::uid() ) )
				->allow( static fn( Actor $actor ): bool => $actor->is_staff() )
				->run( static fn( Actor $actor, array $in ): array => $tools()->related( $actor, (string) $in['uid'] ) )
				->rest( 'GET', self::ONE . '/related' )
				->readonly()
				->with( array( 'mcp' => 'read' ) )
		);

		$registry->add(
			Op::make( 'clients.trash_with', __( 'Delete a client, and what belongs to it', 'cliezoo' ) )
				->describe( __( 'Puts a client in the trash, and with it the kinds of what belongs to it that you choose. All of it can be brought back from the trash.', 'cliezoo' ) )
				->input(
					array(
						'uid'  => Op::uid(),
						'with' => array(
							'type'     => 'array',
							'items'    => array(
								'type'      => 'string',
								'maxLength' => 40,
							),
							'maxItems' => 20,
							'default'  => array(),
						),
					)
				)
				->allow( static fn( Actor $actor ): bool => $actor->is_staff() )
				->run( static fn( Actor $actor, array $in ): array => $tools()->trash( $actor, (string) $in['uid'], (array) ( $in['with'] ?? array() ) ) )
				->rest( 'POST', self::ONE . '/trash' )
				->with( array( 'destructive' => true ) )
		);

		$registry->add(
			Op::make( 'clients.import', __( 'Import clients', 'cliezoo' ) )
				->describe( __( 'Takes rows from a spreadsheet and makes one client per row; a client with the same name is skipped, or corrected when asked.', 'cliezoo' ) )
				->input(
					array(
						'rows'   => array(
							'type'     => 'array',
							'required' => true,
							'maxItems' => ClientTools::IMPORT_LIMIT,
							'items'    => array( 'type' => 'object' ),
						),
						'update' => array( 'type' => 'boolean' ),
					)
				)
				->allow( $edits )
				->run( static fn( Actor $actor, array $in ): array => $tools()->import( $actor, (array) $in['rows'], ! empty( $in['update'] ) ) )
				->rest( 'POST', '/clients/import' )
		);

		$registry->add(
			Op::make( 'clients.merge', __( 'Merge two clients', 'cliezoo' ) )
				->describe( __( 'Moves everything from one client to another and trashes the one that was merged away.', 'cliezoo' ) )
				->input(
					array(
						'uid'  => Op::uid(),
						'into' => Op::uid(),
					)
				)
				->allow( static fn( Actor $actor ): bool => $actor->is_staff() && ( $actor->is_admin() || $actor->allows( 'clients_edit' ) ) )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin ): array {
						// Both sides are checked as what they are: the record
						// that survives is being edited, the one that does not
						// is being deleted.
						$plugin->service( 'clients' )->load( $actor, (string) $in['uid'], Policy::DELETE );
						$plugin->service( 'clients' )->load( $actor, (string) $in['into'], Policy::UPDATE );
						return $plugin->get( Clients::class )->merge( $actor, (string) $in['uid'], (string) $in['into'] );
					}
				)
				->rest( 'POST', self::ONE . '/merge' )
				// Not an ability: merging is irreversible enough that it is a
				// person's decision, not an agent's.
				->with(
					array(
						'destructive' => true,
						'ability'     => false,
					)
				)
		);

		$registry->add(
			Op::make( 'clients.health', __( 'Client health score', 'cliezoo' ) )
				->describe( __( 'The score and each part it is made of.', 'cliezoo' ) )
				->input( array( 'uid' => Op::uid() ) )
				->allow( static fn( Actor $actor ): bool => $actor->is_staff() )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin ): array {
						$row    = $plugin->service( 'clients' )->load( $actor, (string) $in['uid'] );
						$health = $plugin->get( HealthScore::class );
						return $health->for_client( (int) $row['id'] ) + array( 'weights' => HealthScore::WEIGHTS );
					}
				)
				->rest( 'GET', self::ONE . '/health' )
				->readonly()
				->with( array( 'mcp' => 'read' ) )
		);

		$registry->add(
			Op::make( 'clients.health_refresh', __( 'Work out the health score again', 'cliezoo' ) )
				->describe( __( 'Recalculates the score and writes it down, so lists can sort by it.', 'cliezoo' ) )
				->input( array( 'uid' => Op::uid() ) )
				->allow( static fn( Actor $actor ): bool => $actor->is_staff() )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin ): array {
						// Writing the score down is an edit of the client.
						$row = $plugin->service( 'clients' )->load( $actor, (string) $in['uid'], Policy::UPDATE );
						return $plugin->get( HealthScore::class )->refresh( (int) $row['id'] ) + array( 'weights' => HealthScore::WEIGHTS );
					}
				)
				->rest( 'POST', self::ONE . '/health/refresh' )
				->with( array( 'idempotent' => true ) )
		);

		$registry->add(
			Op::make( 'clients.portal', __( 'Turn portal access on or off', 'cliezoo' ) )
				->describe( __( 'Switching it off signs this client\'s people out everywhere and stops them signing in. Nothing is deleted.', 'cliezoo' ) )
				->input(
					array(
						'uid'    => Op::uid(),
						'active' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					)
				)
				->allow( static fn( Actor $actor ): bool => $actor->is_staff() && ( $actor->is_admin() || $actor->allows( 'clients_invite' ) ) )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin ): array {
						$row    = $plugin->service( 'clients' )->load( $actor, (string) $in['uid'], Policy::UPDATE );
						$active = (bool) $in['active'];
						$plugin->service( 'clients' )->repository()->update( (int) $row['id'], array( 'portal_active' => $active ? 1 : 0 ) );
						/**
						 * A client's portal access was turned on or off.
						 *
						 * Contacts (M1-02) end their people's sessions on this.
						 *
						 * @param int   $client_id Client id.
						 * @param bool  $active    Whether the portal is on.
						 * @param Actor $actor     Who changed it.
						 */
						do_action( 'cliezoo_client_portal_changed', (int) $row['id'], $active, $actor );
						do_action( 'cliezoo_audit', $active ? 'client_portal_enabled' : 'client_portal_disabled', array( 'client' => (string) $row['uid'] ) );
						return array( 'portal_active' => $active );
					}
				)
				->rest( 'POST', self::ONE . '/portal' )
				// Who may sign in is never an agent's decision.
				->with(
					array(
						'destructive' => true,
						'ability'     => false,
					)
				)
		);

		$registry->add(
			Op::make( 'clients.export', __( 'Export everything about a client', 'cliezoo' ) )
				->describe( __( 'The client, its addresses, billing profiles and activity, as data. Also what a GDPR request asks for.', 'cliezoo' ) )
				->input( array( 'uid' => Op::uid() ) )
				->allow( static fn( Actor $actor ): bool => $actor->is_staff() && ( $actor->is_admin() || $actor->allows( 'data_export' ) ) )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin ): array {
						$row = $plugin->service( 'clients' )->load( $actor, (string) $in['uid'] );
						do_action( 'cliezoo_audit', 'client_exported', array( 'client' => (string) $row['uid'] ) );
						return self::export( $plugin, $actor, $row );
					}
				)
				->rest( 'GET', self::ONE . '/export' )
				->readonly()
				// Not an ability: this is everything about one client in one
				// answer, which is a person's decision to ask for.
				->with( array( 'ability' => false ) )
		);
	}

	/**
	 * Registers the reading actions an agent is most likely to want.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	private static function reading( ActionRegistry $registry, Plugin $plugin ): void {
		$registry->add(
			Op::make( 'clients.summary', __( 'Catch me up on a client', 'cliezoo' ) )
				->describe( __( 'One client in a paragraph: who owns it, how it is doing, and what is attached to it.', 'cliezoo' ) )
				->input( array( 'uid' => Op::uid() ) )
				->allow( static fn( Actor $actor ): bool => $actor->is_staff() )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin ): array {
						$row = $plugin->service( 'clients' )->load( $actor, (string) $in['uid'] );
						return self::summary( $plugin, $actor, $row );
					}
				)
				->rest( 'GET', self::ONE . '/summary' )
				->readonly()
				->with( array( 'mcp' => 'read' ) )
		);

		$registry->add(
			Op::make( 'clients.duplicates', __( 'Clients that look like this one', 'cliezoo' ) )
				->describe( __( 'Finds clients with the same name, email or website, so the same company is not added twice.', 'cliezoo' ) )
				->input(
					array(
						'name'    => Op::str( 200 ),
						'email'   => Op::str( 190 ),
						'website' => Op::str( 190 ),
						// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- An input field of this REST route (the client being edited), not a WP_Query argument.
						'exclude' => Op::uid( false ),
					)
				)
				->allow( static fn( Actor $actor ): bool => $actor->is_staff() )
				->run(
					static fn( Actor $actor, array $in ): array => self::duplicates( $plugin, $actor, $in )
				)
				->rest( 'GET', '/clients/duplicates' )
				->readonly()
				->with( array( 'mcp' => 'read' ) )
		);
	}

	/**
	 * A client in a paragraph, with the counts behind it.
	 *
	 * Modules add their own lines as they land — open projects, unpaid
	 * invoices, the last message — through `cliezoo_client_summary`.
	 *
	 * @param Plugin               $plugin Plugin.
	 * @param Actor                $actor  Who asked.
	 * @param array<string, mixed> $row    The client.
	 * @return array<string, mixed>
	 */
	private static function summary( Plugin $plugin, Actor $actor, array $row ): array {
		$health = $plugin->get( HealthScore::class )->for_client( (int) $row['id'] );
		$out    = array(
			'client' => $plugin->service( 'clients' )->present( $actor, $row ),
			'health' => $health,
			'counts' => array(
				'addresses'        => count( $plugin->service( 'client_locations' )->repository()->where( array( 'client_id' => (int) $row['id'] ) ) ),
				'billing_profiles' => count( $plugin->service( 'billing_profiles' )->repository()->where( array( 'client_id' => (int) $row['id'] ) ) ),
				// How many clients are part of this one, for a group that
				// keeps the brand while its companies keep the work.
				'clients'          => count( $plugin->service( 'clients' )->repository()->where( array( 'parent_id' => (int) $row['id'] ) ) ),
			),
			'lines'  => array(),
		);
		$out['lines'][] = sprintf(
			/* translators: 1: client name, 2: lifecycle stage or status. */
			__( '%1$s is %2$s.', 'cliezoo' ),
			(string) $row['name'],
			(string) ( $row['stage'] ?? $row['status'] )
		);
		if ( null !== $health['score'] ) {
			$out['lines'][] = sprintf(
				/* translators: %d: health score out of a hundred. */
				__( 'Health is %d out of 100.', 'cliezoo' ),
				(int) $health['score']
			);
		}
		/**
		 * Adds to a client summary (open projects, unpaid invoices, the last
		 * message). Each module adds its own line and counts.
		 *
		 * @param array $out   The summary so far.
		 * @param array $row   The client row.
		 * @param Actor $actor Who asked.
		 */
		return (array) apply_filters( 'cliezoo_client_summary', $out, $row, $actor );
	}

	/**
	 * Clients that look like the one being described.
	 *
	 * Name matching is deliberately loose — "Bluedot", "Bluedot Ltd" and
	 * "bluedot studio" are the same company to a person — and the answer is
	 * a suggestion, never a refusal: the workspace decides.
	 *
	 * @param Plugin               $plugin Plugin.
	 * @param Actor                $actor  Who asked.
	 * @param array<string, mixed> $in     name, email, website, exclude.
	 * @return array<string, mixed>
	 */
	private static function duplicates( Plugin $plugin, Actor $actor, array $in ): array {
		global $wpdb;
		$entity = $plugin->entities()->get( 'clients' );
		$table  = $entity->table();
		$name   = trim( (string) ( $in['name'] ?? '' ) );
		$email  = trim( (string) ( $in['email'] ?? '' ) );
		$site   = trim( (string) ( $in['website'] ?? '' ) );
		if ( '' === $name && '' === $email && '' === $site ) {
			return array( 'matches' => array() );
		}

		$where = array();
		$args  = array();
		if ( '' !== $name ) {
			// The first word is usually the company; the rest is "Ltd", "GmbH".
			$stem    = preg_split( '/\s+/u', $name )[0] ?? $name;
			$where[] = 't.name LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $stem ) . '%';
		}
		if ( '' !== $email ) {
			$where[] = 't.email = %s';
			$args[]  = $email;
		}
		if ( '' !== $site ) {
			$host    = (string) ( wp_parse_url( $site, PHP_URL_HOST ) ?? $site );
			$where[] = 't.website LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( (string) preg_replace( '/^www\./i', '', $host ) ) . '%';
		}

		$sql = "SELECT t.id FROM {$table} t WHERE t.deleted_at IS NULL AND ( " . implode( ' OR ', $where ) . ' ) LIMIT 20';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The clauses are built from fixed strings above; every value is a placeholder.
		$ids  = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
		$out  = array();
		$skip = (string) ( $in['exclude'] ?? '' );
		foreach ( $plugin->service( 'clients' )->repository()->find_many( $ids ) as $row ) {
			if ( $skip === (string) $row['uid'] ) {
				continue;
			}
			// Through the policy, so this never reveals a client the person
			// is not allowed to see.
			if ( ! $plugin->policy()->can( $actor, Policy::VIEW, $entity, $row ) ) {
				continue;
			}
			$out[] = array(
				'uid'    => (string) $row['uid'],
				'name'   => (string) $row['name'],
				'email'  => (string) ( $row['email'] ?? '' ),
				'number' => (string) ( $row['number'] ?? '' ),
			);
		}
		return array( 'matches' => $out );
	}

	/**
	 * Everything the workspace holds about one client.
	 *
	 * Built from the entity definitions rather than a list kept by hand, so
	 * a module added later is included without anyone remembering to.
	 *
	 * @param Plugin               $plugin Plugin.
	 * @param Actor                $actor  Who asked.
	 * @param array<string, mixed> $client The client row.
	 * @return array<string, mixed>
	 */
	private static function export( Plugin $plugin, Actor $actor, array $client ): array {
		$out    = array(
			'exported_at' => gmdate( 'c' ),
			'client'      => $plugin->service( 'clients' )->present( $actor, $client ),
			'related'     => array(),
		);
		$policy = $plugin->get( \Cliezoo\Platform\Permissions\Policy::class );
		foreach ( $plugin->entities()->all() as $name => $entity ) {
			if ( 'clients' === $name || ! $entity->public ) {
				continue;
			}
			// A kind of record this person may not read at all is left out
			// rather than refused: asking the service for it would throw,
			// and one table they cannot see would take the whole export
			// down. Which is what happened the day invoices arrived — a
			// salesperson could export a client until there was an invoice
			// entity they had no right to list.
			if ( ! $policy->can( $actor, \Cliezoo\Platform\Permissions\Policy::VIEW, $entity ) ) {
				continue;
			}
			$columns = array();
			foreach ( $entity->fields as $column => $field ) {
				if ( ( 'clients' === $field->ref || 'client_id' === $column ) && $field->filterable ) {
					$columns[] = $entity->input_key( $column );
				}
			}
			foreach ( array_unique( $columns ) as $column ) {
				// Through the service, never the repository: an export must
				// contain exactly what this person could read one row at a
				// time — the same scope, the same hidden columns, the same
				// financial fields withheld.
				$query         = new ListQuery();
				$query->filter = array(
					'and' => array(
						array(
							'field' => $column,
							'op'    => 'eq',
							'value' => (string) $client['uid'],
						),
					),
				);
				$query->limit  = 500;
				$page          = $plugin->service( $name )->list( $actor, $query );
				$rows          = (array) ( $page['data'] ?? array() );
				if ( $rows ) {
					$out['related'][ $name ] = array_merge( $out['related'][ $name ] ?? array(), $rows );
				}
			}
		}
		/**
		 * Adds to a client export (a module that stores something elsewhere).
		 *
		 * @param array $out    The export so far.
		 * @param array $client The client row.
		 * @param Actor $actor  Who asked.
		 */
		return (array) apply_filters( 'cliezoo_client_export', $out, $client, $actor );
	}
}
