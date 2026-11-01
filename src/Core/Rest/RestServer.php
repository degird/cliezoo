<?php
/**
 * REST API server for cliezoo/v1.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Rest;

use Cliezoo\Core\Actions\Action;
use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps Actions to REST routes: JSON-schema args, a permission_callback that
 * calls the Policy, stable error codes, ETag/If-Match, Idempotency-Key and
 * the `{data, meta}` envelope (Blueprint §10.1).
 */
final class RestServer {

	public const NAMESPACE = 'cliezoo/v1';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'retry_after' ), 10, 3 );
		( new ApiRateLimit() )->hooks();
		( new QueryBudget() )->hooks();
	}

	/**
	 * Adds Retry-After to rate-limited Cliezoo responses.
	 *
	 * @param \WP_HTTP_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_HTTP_Response
	 */
	public function retry_after( $response, $server, $request ) {
		if ( ! $response instanceof \WP_HTTP_Response || 429 !== $response->get_status() || ! str_starts_with( (string) $request->get_route(), '/cliezoo/' ) ) {
			return $response;
		}
		$data  = (array) $response->get_data();
		$after = (int) ( $data['data']['retry_after'] ?? 60 );
		$response->header( 'Retry-After', (string) max( 1, $after ) );
		return $response;
	}

	/**
	 * Registers every action route and extra route.
	 *
	 * @return void
	 */
	public function register(): void {
		$grouped = array();
		foreach ( $this->plugin->actions()->all() as $action ) {
			$rest = $action->resolved_meta()['rest'];
			if ( ! $rest ) {
				continue;
			}
			$grouped[ $rest[1] ][] = array( $rest[0], $action );
		}
		foreach ( $grouped as $path => $endpoints ) {
			$args = array();
			foreach ( $endpoints as list( $method, $action ) ) {
				$args[] = array(
					'methods'             => $method,
					'callback'            => fn( \WP_REST_Request $request ) => $this->handle( $action, $request ),
					'permission_callback' => fn( \WP_REST_Request $request ) => $this->permit( $action, $request ),
					'args'                => $this->args_for( $action, $method ),
				);
			}
			register_rest_route( self::NAMESPACE, $path, $args );
		}
		/**
		 * Lets modules add their non-action routes.
		 *
		 * @param RouteRegistry $routes Routes.
		 */
		do_action( 'cliezoo_register_routes', $this->plugin->routes() );
		foreach ( $this->plugin->routes()->all() as list( $path, $args ) ) {
			register_rest_route( self::NAMESPACE, $path, $args );
		}
	}

	/**
	 * Permission callback: the Policy decides (type-level; row-level inside the action).
	 *
	 * @param Action           $action  Action.
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	private function permit( Action $action, \WP_REST_Request $request ) {
		$actor = $this->plugin->actor()->via( 'rest' );
		if ( $actor->is_guest() ) {
			return new \WP_Error( 'cliezoo_unauthorized', __( 'Please log in.', 'cliezoo' ), array( 'status' => 401 ) );
		}
		if ( ! $action->permission( $actor, $this->input( $request ) ) ) {
			return ActionError::forbidden()->to_wp_error();
		}
		return true;
	}

	/**
	 * Runs an action for a request.
	 *
	 * @param Action           $action  Action.
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function handle( Action $action, \WP_REST_Request $request ) {
		$actor = $this->plugin->actor()->via( 'rest' );
		$input = $this->input( $request );
		if ( $request->get_header( 'if_match' ) ) {
			$input['if_match'] = (string) $request->get_header( 'if_match' );
		}

		// `expand` is a way of presenting the answer, not input to the
		// action: it is checked here and applied to what the action returns.
		$expand = null;
		if ( 'GET' === $request->get_method() && null !== $request->get_param( 'expand' ) ) {
			try {
				$expand = ( new Expand( $this->plugin ) )->plan( $action, $request->get_param( 'expand' ) );
			} catch ( ActionError $error ) {
				return $error->to_wp_error();
			}
		}

		$idempotency = $this->idempotency_key( $request, $actor->user_id );
		if ( $idempotency ) {
			$cached = get_transient( $idempotency );
			if ( is_array( $cached ) ) {
				$response = new \WP_REST_Response( $cached['body'], (int) $cached['status'] );
				$response->header( 'Idempotent-Replayed', 'true' );
				return $response;
			}
		}

		try {
			$result = $this->plugin->actions()->run( $action->name(), $actor, $input );
		} catch ( ActionError $error ) {
			return $error->to_wp_error();
		}

		$is_list = is_array( $result ) && isset( $result['data'], $result['meta'] ) && array_is_list( $result['data'] );
		$body    = $is_list ? $result : array( 'data' => $result );
		if ( null !== $expand ) {
			$body = ( new Expand( $this->plugin ) )->apply( $body, $expand[1], $actor );
		}
		$status = 'POST' === $request->get_method() && str_ends_with( $action->name(), '.create' ) ? 201 : 200;

		$response = new \WP_REST_Response( $body, $status );
		if ( is_array( $result ) && isset( $result['etag'] ) ) {
			$response->header( 'ETag', '"' . $result['etag'] . '"' );
		}
		if ( $idempotency ) {
			set_transient(
				$idempotency,
				array(
					'body'   => $body,
					'status' => $status,
				),
				DAY_IN_SECONDS
			);
		}
		return $response;
	}

	/**
	 * Transient key for an Idempotency-Key header on POST (null when absent).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param int              $user_id User.
	 * @return string|null
	 */
	private function idempotency_key( \WP_REST_Request $request, int $user_id ): ?string {
		$key = (string) $request->get_header( 'idempotency_key' );
		if ( 'POST' !== $request->get_method() || '' === $key || strlen( $key ) > 200 ) {
			return null;
		}
		return 'cliezoo_idem_' . md5( $user_id . '|' . $request->get_route() . '|' . $key );
	}

	/**
	 * Merges URL params, query params and the JSON body into action input.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function input( \WP_REST_Request $request ): array {
		$input = array_merge(
			(array) $request->get_query_params(),
			(array) $request->get_json_params(),
			(array) $request->get_body_params(),
			(array) $request->get_url_params()
		);
		unset( $input['_locale'], $input['_wpnonce'], $input['_fields'], $input['_envelope'], $input['_method'] );
		if ( 'GET' === $request->get_method() ) {
			unset( $input['expand'] );
		}
		if ( isset( $input['limit'] ) && is_numeric( $input['limit'] ) ) {
			$input['limit'] = (int) $input['limit'];
		}
		if ( isset( $input['with_total'] ) ) {
			$input['with_total'] = in_array( $input['with_total'], array( true, 1, '1', 'true' ), true );
		}
		return $input;
	}

	/**
	 * Whether an action is a record read that takes `expand`.
	 *
	 * @param Action $action Action.
	 * @return bool
	 */
	public static function expands( Action $action ): bool {
		$parts = explode( '.', $action->name() );
		return $action instanceof \Cliezoo\Core\Actions\EntityAction && 2 === count( $parts ) && in_array( $parts[1], array( 'list', 'get' ), true );
	}

	/**
	 * REST args. Validation happens in the ActionRegistry against the full
	 * JSON schema, so args only document parameters for discovery.
	 *
	 * @param Action $action Action.
	 * @param string $method HTTP method.
	 * @return array<string, mixed>
	 */
	private function args_for( Action $action, string $method ): array {
		if ( 'GET' !== $method ) {
			return array();
		}
		$args = array();
		foreach ( (array) ( $action->input_schema()['properties'] ?? array() ) as $name => $schema ) {
			$args[ $name ] = array_intersect_key( (array) $schema, array_flip( array( 'type', 'description', 'enum', 'default' ) ) );
		}
		if ( self::expands( $action ) ) {
			$args['expand'] = array(
				'type'        => 'string',
				'description' => __( 'Related records to include in full, comma-separated (for example "client,owner").', 'cliezoo' ),
			);
		}
		return $args;
	}
}
