<?php
/**
 * Numbering settings over REST.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Numbering;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * `GET /numbering` and `PATCH /numbering/{series}`: the prefix, length,
 * next number, date tokens and yearly reset of every numbered record type
 * this workspace has (cliezoo.md §4.4, §6.38), each with a preview.
 */
final class NumberingActions {

	/**
	 * Registers the actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	public static function register( ActionRegistry $registry, Plugin $plugin ): void {
		$manage = static fn( Actor $a ): bool => $a->is_admin() || $a->allows( 'settings_general' );
		$registry->add(
			Op::make( 'numbering.list', __( 'Record numbering', 'cliezoo' ) )
				->allow( $manage )
				->run(
					static function () use ( $plugin ): array {
						$out = array();
						foreach ( self::series( $plugin ) as $series => $label ) {
							$out[] = self::present( $plugin, $series, $label );
						}
						return $out;
					}
				)
				->rest( 'GET', '/numbering' )
				->readonly()
		);
		$registry->add(
			Op::make( 'numbering.update', __( 'Change record numbering', 'cliezoo' ) )
				->describe( __( 'Changes how new numbers of one record type look. Numbers already given out stay as they are, and the counter never goes back below a number already used.', 'cliezoo' ) )
				->input(
					array(
						'series'       => array(
							'type'     => 'string',
							'pattern'  => '^[A-Z]{2,10}$',
							'required' => true,
						),
						'prefix'       => array(
							'type'      => 'string',
							'maxLength' => 40,
						),
						'pattern'      => array(
							'type'      => 'string',
							'maxLength' => 80,
						),
						'next_value'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 999999999,
						),
						'yearly_reset' => array( 'type' => 'boolean' ),
					)
				)
				->allow( $manage )
				->run(
					static function ( Actor $a, array $in ) use ( $plugin ): array {
						$series = (string) $in['series'];
						$known  = self::series( $plugin );
						if ( ! isset( $known[ $series ] ) ) {
							throw ActionError::not_found();
						}
						if ( isset( $in['pattern'] ) && ! Numbering::valid_pattern( (string) $in['pattern'] ) ) {
							throw ActionError::validation(
								__( 'Some settings are not valid.', 'cliezoo' ),
								/* translators: {#}, {PREFIX}, {YYYY}, {YY}, {MM} and {DD} are the literal codes somebody types into the field; leave them exactly as they are. */
								array( 'pattern' => __( 'Use {#} for the number (one # per digit) and only {PREFIX}, {YYYY}, {YY}, {MM} and {DD} besides.', 'cliezoo' ) )
							);
						}
						if ( isset( $in['prefix'] ) && preg_match( '/[{}]/', (string) $in['prefix'] ) ) {
							throw ActionError::validation(
								__( 'Some settings are not valid.', 'cliezoo' ),
								array( 'prefix' => __( 'A prefix cannot contain { or }.', 'cliezoo' ) )
							);
						}
						$numbering = $plugin->numbering();
						$numbering->configure( $series, array_intersect_key( $in, array_flip( array( 'prefix', 'pattern', 'next_value', 'yearly_reset' ) ) ) );
						// A lower next number would hand out a number that is
						// already on a record. Moving past the highest one
						// used keeps every number unique.
						$table = self::table_for( $plugin, $series );
						if ( '' !== $table ) {
							$numbering->repair( $series, $table );
						}
						do_action( 'cliezoo_audit', 'numbering_changed', array( 'series' => $series ) );
						return self::present( $plugin, $series, $known[ $series ] );
					}
				)
				->rest( 'PATCH', '/numbering/(?P<series>[A-Z]{2,10})' )
		);
	}

	/**
	 * The numbered record types this workspace has: series => label.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array<string, string>
	 */
	private static function series( Plugin $plugin ): array {
		$out = array();
		foreach ( $plugin->entities()->all() as $entity ) {
			if ( '' !== $entity->series ) {
				$out[ $entity->series ] = '' !== $entity->plural_label ? $entity->plural_label : $entity->name;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The table whose `number` column a series fills.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param string $series Series.
	 * @return string
	 */
	private static function table_for( Plugin $plugin, string $series ): string {
		foreach ( $plugin->entities()->all() as $entity ) {
			if ( $series === $entity->series && $entity->has_column( 'number' ) ) {
				return $entity->table();
			}
		}
		return '';
	}

	/**
	 * One series as the settings screen shows it.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param string $series Series.
	 * @param string $label  Record type name.
	 * @return array<string, mixed>
	 */
	private static function present( Plugin $plugin, string $series, string $label ): array {
		$numbering = $plugin->numbering();
		return $numbering->get( $series ) + array(
			'label'   => $label,
			'preview' => $numbering->preview( $series ),
		);
	}
}
