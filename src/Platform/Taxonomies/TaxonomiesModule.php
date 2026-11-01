<?php
/**
 * Editable lists module.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Taxonomies;

use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks list validation, seeding and the lists API.
 */
final class TaxonomiesModule extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'lists';
	}

	/**
	 * {@inheritDoc}
	 */
	public function switchable(): bool {
		return false;
	}

	/**
	 * Registers hooks and actions.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( Taxonomies::class, static fn(): Taxonomies => new Taxonomies( $plugin ) );
		$lists = static fn(): Taxonomies => $plugin->get( Taxonomies::class );

		add_filter(
			'cliezoo_list_value_valid',
			static fn( bool $valid, string $list_key, string $value ): bool => $valid && $lists()->has( $list_key, $value ),
			10,
			3
		);
		// New modules or upgrades may declare new lists: seed after every migration run (idempotent).
		add_action( 'cliezoo_migrated', static fn() => $lists()->seed() );
		foreach ( array( 'created', 'updated' ) as $event ) {
			add_action(
				'cliezoo_taxonomy_terms_' . $event,
				static fn() => $lists()->flush()
			);
		}
		// A list value travels with the words for it. Without this the app
		// would have to turn "mid_market" into English itself, which is
		// exactly the kind of label no translator ever sees.
		add_filter(
			'cliezoo_present',
			static function ( array $out, Entity $entity ) use ( $lists ): array {
				$listed = array();
				foreach ( $entity->fields as $column => $field ) {
					if ( '' !== $field->list ) {
						$listed[ $entity->input_key( $column ) ] = $field->list;
					}
				}
				if ( ! $listed ) {
					return $out;
				}
				foreach ( $out as $index => $record ) {
					foreach ( $listed as $key => $list ) {
						$value = (string) ( $record[ $key ] ?? '' );
						if ( '' === $value ) {
							continue;
						}
						foreach ( $lists()->values( $list ) as $option ) {
							if ( $option['value'] === $value ) {
								$out[ $index ][ $key . '_label' ] = (string) $option['label'];
								if ( ! empty( $option['color'] ) ) {
									$out[ $index ][ $key . '_color' ] = (string) $option['color'];
								}
								break;
							}
						}
					}
				}
				return $out;
			},
			10,
			2
		);
		add_filter(
			'cliezoo_entity_verbs',
			static fn( array $verbs, Entity $entity ): array => 'taxonomy_terms' === $entity->name ? array( 'get', 'create', 'update' ) : $verbs,
			10,
			2
		);
		add_action(
			'cliezoo_register_actions',
			static function ( ActionRegistry $registry ) use ( $lists ): void {
				$registry->add(
					Op::make( 'lists.all', __( 'All editable lists', 'cliezoo' ) )
						->allow( static fn( Actor $a ): bool => ! $a->is_guest() )
						->run(
							static function () use ( $lists ): array {
								$out = array();
								foreach ( $lists()->definitions() as $key => $definition ) {
									$out[ $key ] = array(
										'label'  => $definition['label'],
										'values' => $lists()->values( $key ),
									);
								}
								return $out;
							}
						)
						->rest( 'GET', '/lists' )
						->readonly()
				);
				$registry->add(
					Op::make( 'lists.reorder', __( 'Reorder a list', 'cliezoo' ) )
						->input(
							array(
								'list' => array(
									'type'     => 'string',
									'pattern'  => '^[a-z_]{2,60}$',
									'required' => true,
								),
								'uids' => array(
									'type'     => 'array',
									'items'    => Op::uid(),
									'required' => true,
									'maxItems' => 500,
								),
							)
						)
						->allow( static fn( Actor $a ): bool => $a->is_admin() || $a->allows( 'settings_custom_fields' ) || $a->allows( 'settings_general' ) )
						->run( static fn( Actor $a, array $in ) => $lists()->reorder( (string) $in['list'], (array) $in['uids'] ) )
						->rest( 'POST', '/lists/(?P<list>[a-z_]{2,60})/order' )
				);
				$registry->add(
					Op::make( 'lists.remove', __( 'Remove a list value', 'cliezoo' ) )
						->describe( __( 'Deletes a value from an editable list and moves records that use it to another value (or clears it).', 'cliezoo' ) )
						->input(
							array(
								'uid'     => Op::uid(),
								'replace' => array(
									'type'      => array( 'string', 'null' ),
									'maxLength' => 60,
								),
							)
						)
						->allow( static fn( Actor $a ): bool => $a->is_admin() || $a->allows( 'settings_custom_fields' ) || $a->allows( 'settings_general' ) )
						->run( static fn( Actor $a, array $in ) => $lists()->remove( (string) $in['uid'], isset( $in['replace'] ) ? (string) $in['replace'] : null ) )
						->rest( 'POST', '/lists/terms/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/remove' )
						->with( array( 'destructive' => true ) )
				);
			}
		);
	}
}
