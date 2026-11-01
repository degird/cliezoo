<?php
/**
 * Clients (accounts).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Clients;

use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Field;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A client account: the company (or person) the work is for, with its
 * addresses, its billing profiles and everything the rest of the product
 * hangs off (cliezoo.md §6.5).
 *
 * Two things are deliberate here. Addresses are their own rows with their
 * own columns, never one free-text box, because an invoice has to print
 * them and a report has to group by country. And a client can have several
 * billing profiles, because one company often pays through two entities.
 */
final class ClientsModule extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'clients';
	}

	/**
	 * {@inheritDoc}
	 */
	public function switchable(): bool {
		return false;
	}

	/**
	 * Address columns, shared by a location and a billing profile.
	 *
	 * @return array<string, Field>
	 */
	private function address(): array {
		return array(
			'street'      => Field::text( 200 ),
			'street2'     => Field::text( 200 ),
			'city'        => Field::text( 120 )->filterable(),
			'region'      => Field::text( 120 ),
			'postal_code' => Field::text( 30 ),
			'country'     => Field::text( 2 )->filterable(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function entities(): array {
		return array(
			( new Entity(
				'clients',
				array(
					'name'             => Field::text( 200 )->required()->searchable()->sortable()->label( __( 'Client name', 'cliezoo' ) ),
					'legal_name'       => Field::text( 200 )->searchable()->label( __( 'Legal name', 'cliezoo' ) ),
					'logo_file_id'     => Field::ref( 'files' )->label( __( 'Logo', 'cliezoo' ) ),
					'initials'         => Field::text( 4 ),
					'head_count'       => Field::int()->label( __( 'Head count', 'cliezoo' ) ),
					'website'          => Field::url(),
					'annual_revenue'   => Field::money()->finance()->label( __( 'Annual revenue', 'cliezoo' ) ),
					'tax_number'       => Field::text( 60 )->label( __( 'VAT or tax number', 'cliezoo' ) ),
					'email'            => Field::email()->searchable(),
					'phone'            => Field::phone()->searchable(),
					// A group of companies is a client with clients under it:
					// the parent keeps the brand, the logo and the initials,
					// and the work still belongs to the company that ordered
					// it. One level, because two is an org chart.
					'parent_id'        => Field::ref( 'clients' )->filterable()->label( __( 'Part of', 'cliezoo' ) ),
					'type'             => Field::listed( 'client_type' )->filterable()->label( __( 'Client type', 'cliezoo' ) ),
					'sub_type'         => Field::text( 80 )->label( __( 'Sub-type', 'cliezoo' ) ),
					'industry'         => Field::listed( 'industry' )->filterable(),
					'territory'        => Field::listed( 'territory' )->filterable(),
					'tags'             => Field::json(),
					// The people on the team who look after this client.
					'team'             => Field::json()->label( __( 'Assigned team', 'cliezoo' ) ),
					'stage'            => Field::listed( 'client_stage' )->filterable()->label( __( 'Lifecycle stage', 'cliezoo' ) ),
					'status'           => Field::enum( array( 'active', 'paused', 'former' ) )->default( 'active' )->filterable(),
					'owner_id'         => Field::user()->filterable()->label( __( 'Account owner', 'cliezoo' ) ),
					'currency'         => Field::currency()->label( __( 'Default currency', 'cliezoo' ) ),
					'payment_terms'    => Field::int()->label( __( 'Payment terms (days)', 'cliezoo' ) ),
					'tax_rate'         => Field::decimal( 2 )->label( __( 'Default tax rate', 'cliezoo' ) ),
					'hourly_rate'      => Field::money()->finance()->label( __( 'Default hourly rate', 'cliezoo' ) ),
					'timezone'         => Field::text( 60 ),
					'language'         => Field::text( 10 ),
					'description'      => Field::longtext()->searchable(),
					// Worked out from what the workspace already knows, and
					// written down so a list can sort by it.
					'health_score'     => Field::int()->readonly()->sortable()->label( __( 'Health score', 'cliezoo' ) ),
					'health_at'        => Field::datetime()->readonly(),
					// Read-only as a field: it is changed through clients.portal,
					// which checks a different permission, signs the client's
					// people out and writes an audit entry.
					'portal_active'    => Field::bool()->default( true )->readonly()->filterable()->label( __( 'Portal access', 'cliezoo' ) ),
					'last_activity_at' => Field::datetime()->readonly()->sortable(),
				)
			) )->with(
				array(
					'series'        => 'CLI',
					'permission'    => 'clients',
					'owner_field'   => 'owner_id',
					// A client's own people see their own record.
					'client_field'  => 'id',
					'shareable'     => true,
					'custom_fields' => true,
					'searchable'    => true,
					'title_field'   => 'name',
					'default_sort'  => 'name',
					'indexes'       => array(
						'status' => array( 'status', 'stage' ),
						'owner'  => array( 'owner_id', 'status' ),
					),
					'fulltext'      => array( 'search' => array( 'name', 'legal_name', 'email', 'description' ) ),
					'label'         => __( 'Client', 'cliezoo' ),
					'plural_label'  => __( 'Clients', 'cliezoo' ),
					'create_toggle' => 'clients_edit',
				)
			),
			( new Entity(
				'client_locations',
				array_merge(
					array(
						'client_id' => Field::ref( 'clients' )->required()->filterable(),
						'type'      => Field::enum( array( 'billing', 'shipping', 'office', 'other' ) )->default( 'office' )->filterable(),
						'label'     => Field::text( 120 ),
					),
					$this->address(),
					array(
						'is_default' => Field::bool(),
						'position'   => Field::int()->default( 0 ),
					)
				)
			) )->with(
				array(
					'permission'    => 'clients',
					'client_field'  => 'client_id',
					'parent'        => array( 'clients', 'client_id' ),
					'title_field'   => 'label',
					'default_sort'  => 'position',
					'soft_delete'   => false,
					'indexes'       => array( 'client' => array( 'client_id', 'type' ) ),
					'label'         => __( 'Location', 'cliezoo' ),
					'plural_label'  => __( 'Locations', 'cliezoo' ),
					'create_toggle' => 'clients_edit',
				)
			),
			( new Entity(
				'billing_profiles',
				array_merge(
					array(
						'client_id'     => Field::ref( 'clients' )->required()->filterable(),
						'name'          => Field::text( 120 )->required(),
						'bill_to'       => Field::text( 200 )->label( __( 'Bill to', 'cliezoo' ) ),
						'business_name' => Field::text( 200 ),
						'tax_number'    => Field::text( 60 ),
						'email'         => Field::email(),
						'phone'         => Field::phone(),
					),
					$this->address(),
					array(
						'currency'      => Field::currency(),
						'payment_terms' => Field::int(),
						'tax_rate'      => Field::decimal( 2 ),
						'language'      => Field::text( 10 ),
						'is_default'    => Field::bool()->filterable(),
						'position'      => Field::int()->default( 0 ),
					)
				)
			) )->with(
				array(
					'permission'    => 'clients',
					'client_field'  => 'client_id',
					'parent'        => array( 'clients', 'client_id' ),
					'title_field'   => 'name',
					'default_sort'  => 'position',
					'soft_delete'   => false,
					'indexes'       => array( 'client' => array( 'client_id', 'is_default' ) ),
					'label'         => __( 'Billing profile', 'cliezoo' ),
					'plural_label'  => __( 'Billing profiles', 'cliezoo' ),
					'create_toggle' => 'clients_edit',
				)
			),
		);
	}

	/**
	 * Registers the lists, the defaults and the client actions.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( Clients::class, static fn(): Clients => new Clients( $plugin ) );
		$plugin->container()->set( ClientTools::class, static fn(): ClientTools => new ClientTools( $plugin ) );
		$plugin->container()->set( HealthScore::class, static fn(): HealthScore => new HealthScore( $plugin ) );

		add_filter( 'cliezoo_lists', array( Lists::class, 'declare_lists' ) );

		// The health score is the team's view of the relationship, made of
		// things a client does not see (late internal work, how fast the
		// team answers); a client reading their own record does not get it.
		add_filter(
			'cliezoo_present_clients',
			static function ( array $out, Entity $entity, array $rows, Actor $actor ): array {
				if ( $actor->is_staff() ) {
					return $out;
				}
				foreach ( $out as $i => $one ) {
					unset( $out[ $i ]['health_score'], $out[ $i ]['health_at'] );
				}
				return $out;
			},
			10,
			4
		);

		// What an external agent may do with a client: read them, and create
		// or correct one. Never delete, and never touch the portal — that is
		// a decision about people's access (MCP plan §4).
		add_filter(
			'cliezoo_entity_action_meta',
			static function ( array $extra, Entity $entity, string $verb ): array {
				if ( ! in_array( $entity->name, array( 'clients', 'client_locations', 'billing_profiles' ), true ) ) {
					return $extra;
				}
				if ( in_array( $verb, array( 'list', 'get' ), true ) ) {
					$extra['mcp'] = 'read';
				} elseif ( in_array( $verb, array( 'create', 'update' ), true ) ) {
					$extra['mcp'] = 'safe';
				}
				return $extra;
			},
			10,
			3
		);

		// Initials and the workspace's own defaults are worked out when a
		// client is saved rather than asked for.
		add_filter(
			'cliezoo_clients_before_create',
			static fn( array $data ): array => $plugin->get( Clients::class )->fill( $data, null )
		);
		add_filter(
			'cliezoo_clients_before_update',
			static fn( array $data, array $row ): array => $plugin->get( Clients::class )->fill( $data, $row ),
			10,
			2
		);
		add_action(
			'cliezoo_clients_created',
			static function ( array $row ) use ( $plugin ): void {
				$plugin->get( Clients::class )->after_create( $row );
			}
		);

		// The logo as a picture, and the assigned team as people: for whoever
		// may see them.
		add_filter(
			'cliezoo_present_clients',
			static function ( array $out, Entity $entity, array $rows, Actor $actor ) use ( $plugin ): array {
				$files   = $plugin->service( 'files' );
				$members = $plugin->service( 'members' )->repository();
				foreach ( $rows as $i => $row ) {
					if ( ! isset( $out[ $i ] ) ) {
						continue;
					}
					$out[ $i ]['logo_url'] = null;
					$logo                  = empty( $row['logo_file_id'] ) ? null : $files->repository()->find( (int) $row['logo_file_id'] );
					if ( $logo && empty( $logo['deleted_at'] ) && \Cliezoo\Infrastructure\Storage\Downloads::is_image( (string) $logo['mime'] ) && $plugin->policy()->can( $actor, Policy::VIEW, $files->entity(), $logo ) ) {
						$out[ $i ]['logo_url'] = \Cliezoo\Infrastructure\Storage\Downloads::signed_url( (string) $logo['uid'], DAY_IN_SECONDS, 'avatar' );
					}
					$people = array();
					foreach ( (array) json_decode( (string) ( $row['team'] ?? '' ), true ) as $uid ) {
						$member = $members->find_by_uid( (string) $uid );
						if ( $member && 'staff' === (string) $member['kind'] ) {
							$people[] = array(
								'uid'   => (string) $member['uid'],
								'label' => (string) $member['name'],
								'title' => (string) ( $member['title'] ?? '' ),
							);
						}
					}
					$out[ $i ]['team_people'] = $actor->is_staff() ? $people : array();
					// Who looks after them is the team's own business.
					if ( ! $actor->is_staff() ) {
						unset( $out[ $i ]['team'] );
					}
				}
				return $out;
			},
			10,
			4
		);
		// One default location and one default billing profile, always.
		foreach ( array( 'client_locations', 'billing_profiles' ) as $entity ) {
			foreach ( array( 'created', 'updated' ) as $event ) {
				add_action(
					'cliezoo_' . $entity . '_' . $event,
					static function ( array $row ) use ( $plugin, $entity ): void {
						$plugin->get( Clients::class )->keep_one_default( $entity, $row );
					}
				);
			}
		}

		add_action(
			'cliezoo_register_actions',
			static function ( ActionRegistry $registry ) use ( $plugin ): void {
				ClientActions::register( $registry, $plugin );
			}
		);

		// Health scores are recalculated overnight, so a list can sort by a
		// column without computing anything per row.
		add_action(
			'cliezoo_every_day',
			static function () use ( $plugin ): void {
				$plugin->get( HealthScore::class )->refresh_all();
			}
		);
	}
}
