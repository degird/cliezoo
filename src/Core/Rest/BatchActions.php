<?php
/**
 * One request, several actions.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Rest;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /batch`: runs up to 25 actions in order and answers with one result
 * per step, so a screen that saves several things needs one round trip
 * (Blueprint §10.4). Every step goes through the same registry, so
 * validation, permissions and the action gate all still apply; a step that
 * fails does not stop the ones after it unless `stop_on_error` is set.
 */
final class BatchActions {

	private const MAX = 25;

	/**
	 * Registers the action.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	public static function register( ActionRegistry $registry, Plugin $plugin ): void {
		$registry->add(
			Op::make( 'batch.run', __( 'Run several actions', 'cliezoo' ) )
				->describe( __( 'Runs a list of actions in order and returns one result per step. Each step is checked on its own, exactly as if it had been called by itself.', 'cliezoo' ) )
				->input(
					array(
						'operations'    => array(
							'type'     => 'array',
							'required' => true,
							'minItems' => 1,
							'maxItems' => self::MAX,
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'id'    => array(
										'type'      => 'string',
										'maxLength' => 60,
									),
									'op'    => array(
										'type'     => 'string',
										'pattern'  => '^[a-z0-9_]+\.[a-z0-9_]+$',
										'required' => true,
									),
									'input' => array( 'type' => 'object' ),
								),
							),
						),
						'stop_on_error' => array( 'type' => 'boolean' ),
					)
				)
				->allow( static fn( Actor $a ): bool => ! $a->is_guest() )
				->run( static fn( Actor $a, array $in ) => self::run( $plugin, $a, $in ) )
				->rest( 'POST', '/batch' )
				->with( array( 'ability' => false ) )
		);
	}

	/**
	 * Runs the steps.
	 *
	 * @param Plugin               $plugin Plugin.
	 * @param Actor                $actor  Actor.
	 * @param array<string, mixed> $in     operations, stop_on_error.
	 * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
	 */
	private static function run( Plugin $plugin, Actor $actor, array $in ): array {
		$out    = array();
		$failed = 0;
		$stop   = ! empty( $in['stop_on_error'] );
		foreach ( (array) $in['operations'] as $index => $operation ) {
			$operation = (array) $operation;
			$name      = (string) ( $operation['op'] ?? '' );
			$id        = (string) ( $operation['id'] ?? (string) $index );
			if ( 'batch.run' === $name ) {
				$error = ActionError::validation( __( 'A batch cannot contain another batch.', 'cliezoo' ) );
				$out[] = self::failure( $id, $name, $error );
				++$failed;
				if ( $stop ) {
					break;
				}
				continue;
			}
			try {
				$result = $plugin->actions()->run( $name, $actor, (array) ( $operation['input'] ?? array() ) );
				$out[]  = array(
					'id'     => $id,
					'op'     => $name,
					'status' => 200,
					'data'   => is_array( $result ) && isset( $result['data'], $result['meta'] ) ? $result['data'] : $result,
				);
			} catch ( ActionError $error ) {
				$out[] = self::failure( $id, $name, $error );
				++$failed;
				if ( $stop ) {
					break;
				}
			}
		}
		return array(
			'data' => $out,
			'meta' => array(
				'ran'    => count( $out ),
				'failed' => $failed,
			),
		);
	}

	/**
	 * One failed step.
	 *
	 * @param string      $id    Step id.
	 * @param string      $name  Action name.
	 * @param ActionError $error Error.
	 * @return array<string, mixed>
	 */
	private static function failure( string $id, string $name, ActionError $error ): array {
		$wp = $error->to_wp_error();
		return array(
			'id'     => $id,
			'op'     => $name,
			'status' => (int) ( $wp->get_error_data()['status'] ?? 400 ),
			'error'  => array(
				'code'    => $wp->get_error_code(),
				'message' => $wp->get_error_message(),
				'fields'  => (array) ( $wp->get_error_data()['fields'] ?? array() ),
			),
		);
	}
}
