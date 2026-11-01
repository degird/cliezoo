<?php
/**
 * OpenAPI description of the REST API.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Rest;

use Cliezoo\Core\Actions\Action;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /openapi` builds an OpenAPI 3.1 document from the same Action
 * definitions the server runs, so the documentation cannot drift from the
 * API (Blueprint §10.6). It describes the whole API, not one person's
 * permissions; every call is still checked when it is made.
 */
final class OpenApi {

	/**
	 * Registers the action.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	public static function register( ActionRegistry $registry, Plugin $plugin ): void {
		$registry->add(
			Op::make( 'api.openapi', __( 'API description', 'cliezoo' ) )
				->describe( __( 'The OpenAPI document for this workspace, generated from the live action definitions.', 'cliezoo' ) )
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run( static fn(): array => self::document( $plugin ) )
				->rest( 'GET', '/openapi' )
				->readonly()
				->with( array( 'ability' => false ) )
		);
	}

	/**
	 * The document.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array<string, mixed>
	 */
	public static function document( Plugin $plugin ): array {
		$paths = array();
		foreach ( $plugin->actions()->all() as $action ) {
			$meta = $action->resolved_meta();
			// The app's own administrative routes answer only to a signed-in
			// browser, so they are no part of the API a key can use.
			if ( ! $meta['rest'] || in_array( $action->name(), \Cliezoo\Platform\Auth\ApiAccess::IN_APP_ONLY, true ) ) {
				continue;
			}
			list( $method, $route ) = $meta['rest'];
			$path                   = self::path( $route );
			$operation              = self::operation( $action, (string) $method );
			$parameters             = self::path_parameters( $route );
			if ( $parameters ) {
				$operation['parameters'] = array_merge( $parameters, (array) ( $operation['parameters'] ?? array() ) );
			}
			$paths[ $path ][ strtolower( (string) $method ) ] = $operation;
		}
		// Hand-written routes (uploads, downloads, auth) are part of the API too.
		do_action( 'cliezoo_register_routes', $plugin->routes() );
		foreach ( $plugin->routes()->all() as list( $route, $args ) ) {
			$path = self::path( $route );
			foreach ( self::methods( $args ) as $method ) {
				if ( isset( $paths[ $path ][ strtolower( $method ) ] ) ) {
					continue;
				}
				$operation  = array(
					'operationId' => strtolower( $method ) . '_' . trim( (string) preg_replace( '/[^a-z0-9]+/', '_', $path ), '_' ),
					'summary'     => $path,
					'tags'        => array( strtok( ltrim( $path, '/' ), '/' ) ),
					'responses'   => array(
						'200' => array( 'description' => __( 'Success.', 'cliezoo' ) ),
						'4XX' => array(
							'description' => __( 'The call was refused.', 'cliezoo' ),
							'content'     => array(
								'application/json' => array(
									'schema' => array( '$ref' => '#/components/schemas/Error' ),
								),
							),
						),
					),
				);
				$parameters = self::path_parameters( $route );
				if ( $parameters ) {
					$operation['parameters'] = $parameters;
				}
				$paths[ $path ][ strtolower( $method ) ] = $operation;
			}
		}
		ksort( $paths );
		$limits = ApiRateLimit::describe();
		return array(
			'openapi'              => '3.1.0',
			'info'                 => array(
				'title'       => __( 'Cliezoo API', 'cliezoo' ),
				'version'     => CLIEZOO_VERSION,
				'description' => __( 'Everything the app does, the same way the app does it. Sign in with a WordPress application password or a logged-in session with a REST nonce.', 'cliezoo' )
					. "\n\n" . self::limits_text( $limits['limit'], $limits['window'] ),
			),
			// The same numbers the server enforces, for tools to read.
			'x-cliezoo-rate-limit' => $limits,
			'x-cliezoo-changelog'  => ApiChangelog::entries(),
			'servers'              => array(
				array( 'url' => rest_url( RestServer::NAMESPACE ) ),
			),
			'security'             => array( array( 'basicAuth' => array() ) ),
			'components'           => array(
				'securitySchemes' => array(
					'basicAuth' => array(
						'type'        => 'http',
						'scheme'      => 'basic',
						'description' => __( 'A WordPress user name and application password.', 'cliezoo' ),
					),
				),
				'schemas'         => array(
					'Error' => array(
						'type'       => 'object',
						'properties' => array(
							'code'    => array( 'type' => 'string' ),
							'message' => array( 'type' => 'string' ),
							'data'    => array( 'type' => 'object' ),
						),
					),
				),
			),
			'paths'                => $paths,
		);
	}

	/**
	 * The rate limits, said plainly.
	 *
	 * @param int $limit  Calls per window (0 = off).
	 * @param int $window Window in seconds.
	 * @return string
	 */
	public static function limits_text( int $limit, int $window ): string {
		$said = 0 === $limit
			? __( 'Calls made with an application password are not limited on this site.', 'cliezoo' )
			/* translators: 1: number of calls, 2: seconds. */
			: sprintf( __( 'Calls made with an application password are limited to %1$d per user every %2$d seconds. Every such response carries X-RateLimit-Limit, X-RateLimit-Remaining and X-RateLimit-Reset (a Unix time); over the limit the answer is 429 with Retry-After.', 'cliezoo' ), $limit, $window );
		return $said . ' ' . __( 'The app in a browser is not counted. Sign-in, magic links, public links, uploads and AI have their own, stricter limits, answered with 429 and Retry-After.', 'cliezoo' );
	}

	/**
	 * One operation.
	 *
	 * @param Action $action Action.
	 * @param string $method HTTP method.
	 * @return array<string, mixed>
	 */
	private static function operation( Action $action, string $method ): array {
		$meta       = $action->resolved_meta();
		$schema     = $action->input_schema();
		$properties = (array) ( $schema['properties'] ?? array() );
		$operation  = array(
			'operationId' => str_replace( '.', '_', $action->name() ),
			'summary'     => $action->label(),
			'description' => $action->description(),
			'tags'        => array( strtok( $action->name(), '.' ) ),
			'responses'   => array(
				'200' => array(
					'description' => __( 'The result, wrapped in a data envelope.', 'cliezoo' ),
					'content'     => array(
						'application/json' => array(
							'schema' => array(
								'type'       => 'object',
								'properties' => array(
									'data' => $action->output_schema() ? $action->output_schema() : array( 'type' => array( 'object', 'array' ) ),
									'meta' => array( 'type' => 'object' ),
								),
							),
						),
					),
				),
				'4XX' => array(
					'description' => __( 'The call was refused: it was not valid, not allowed, not found, or asked for too often.', 'cliezoo' ),
					'content'     => array(
						'application/json' => array(
							'schema' => array( '$ref' => '#/components/schemas/Error' ),
						),
					),
				),
			),
		);
		if ( $meta['destructive'] ) {
			$operation['description'] = trim( $operation['description'] . ' ' . __( 'This one destroys data.', 'cliezoo' ) );
		}
		if ( 'GET' === $method ) {
			$operation['parameters'] = array();
			foreach ( $properties as $name => $property ) {
				$property                  = (array) $property;
				$operation['parameters'][] = array(
					'name'        => (string) $name,
					'in'          => 'query',
					'required'    => ! empty( $property['required'] ),
					'description' => (string) ( $property['description'] ?? '' ),
					'schema'      => self::schema( $property ),
				);
			}
			if ( RestServer::expands( $action ) ) {
				$operation['parameters'][] = array(
					'name'        => 'expand',
					'in'          => 'query',
					'required'    => false,
					/* translators: %d: how many related records may be expanded at once. */
					'description' => sprintf( __( 'Related records to include in full instead of a {uid, label} chip, comma-separated, at most %d, one level deep. A related record you may not open stays a chip.', 'cliezoo' ), Expand::MAX_FIELDS ),
					'schema'      => array( 'type' => 'string' ),
				);
			}
		} elseif ( $properties ) {
			$operation['requestBody'] = array(
				'required' => true,
				'content'  => array(
					'application/json' => array(
						'schema' => self::schema( $schema ),
					),
				),
			);
		}
		return $operation;
	}

	/**
	 * A JSON schema fragment without WordPress's own `required` flag on
	 * properties (OpenAPI lists required names on the object instead).
	 *
	 * @param array<string, mixed> $schema Schema.
	 * @return array<string, mixed>
	 */
	private static function schema( array $schema ): array {
		// A schema may already carry an OpenAPI-style list; keep it and add
		// the names WordPress marks on the properties themselves.
		$required = is_array( $schema['required'] ?? null ) ? array_map( 'strval', $schema['required'] ) : array();
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $name => $property ) {
				if ( is_array( $property ) && ! empty( $property['required'] ) ) {
					$required[] = (string) $name;
				}
				$schema['properties'][ $name ] = is_array( $property ) ? self::schema( $property ) : $property;
			}
		}
		unset( $schema['required'] );
		if ( $required ) {
			$schema['required'] = array_values( array_unique( $required ) );
		}
		if ( ! isset( $schema['type'] ) && isset( $schema['properties'] ) ) {
			$schema['type'] = 'object';
		}
		return $schema;
	}

	/**
	 * The HTTP methods of a hand-written route's args.
	 *
	 * @param array<string, mixed> $args Route args (one endpoint or a list).
	 * @return string[]
	 */
	private static function methods( array $args ): array {
		$endpoints = isset( $args['methods'] ) ? array( $args ) : $args;
		$out       = array();
		foreach ( $endpoints as $endpoint ) {
			foreach ( explode( ',', (string) ( ( (array) $endpoint )['methods'] ?? '' ) ) as $method ) {
				$method = strtoupper( trim( $method ) );
				if ( in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
					$out[] = $method;
				}
			}
		}
		return array_unique( $out );
	}

	/**
	 * Path parameters from a route regex (`(?P<uid>…)`).
	 *
	 * @param string $route Route.
	 * @return array<int, array<string, mixed>>
	 */
	private static function path_parameters( string $route ): array {
		if ( ! preg_match_all( '/\(\?P<([a-z_]+)>/', $route, $matches ) ) {
			return array();
		}
		$out = array();
		foreach ( $matches[1] as $name ) {
			$out[] = array(
				'name'     => $name,
				'in'       => 'path',
				'required' => true,
				'schema'   => array( 'type' => 'string' ),
			);
		}
		return $out;
	}

	/**
	 * `/files/(?P<uid>…)/download` becomes `/files/{uid}/download`.
	 *
	 * @param string $route Route.
	 * @return string
	 */
	private static function path( string $route ): string {
		return (string) preg_replace( '/\(\?P<([a-z_]+)>[^)]*\)/', '{$1}', $route );
	}
}
