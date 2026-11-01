<?php
/**
 * Request limit for API clients, with the headers that say where they stand.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Rest;

use Cliezoo\Core\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts `cliezoo/v1` calls made with a WordPress application password, per
 * user, in a fixed one-minute window, and answers every such call with
 * `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset`
 * (Unix seconds). Over the limit, a call is refused with 429 and
 * `Retry-After`.
 *
 * Only API clients are counted. The app in a browser signs in with a cookie
 * and a REST nonce and is not limited here: it is the person themselves,
 * and counting it would write a transient on every click. The limits that
 * protect sign-in, magic links, public links, uploads and AI stay where they
 * are, whoever calls. The numbers are the same ones the API docs show, so
 * what is documented is what happens.
 */
final class ApiRateLimit {

	/** Default calls per window, per user. */
	public const LIMIT = 300;

	/** Window in seconds. */
	public const WINDOW = 60;

	/**
	 * Where the current request stands: limit, remaining, reset. Null when
	 * the request is not counted.
	 *
	 * @var array{limit: int, remaining: int, reset: int}|null
	 */
	private ?array $state = null;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'count' ), 5, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'headers' ), 10, 3 );
	}

	/**
	 * The limit per window (filterable; 0 turns it off).
	 *
	 * @return int
	 */
	public static function limit(): int {
		/**
		 * Calls an API client (application password) may make per minute.
		 * 0 turns the limit off.
		 *
		 * @param int $limit Calls per minute, per user.
		 */
		return max( 0, (int) apply_filters( 'cliezoo_api_rate_limit', self::LIMIT ) );
	}

	/**
	 * Whether this request came in with an application password.
	 *
	 * @return bool
	 */
	private static function is_api_client(): bool {
		return did_action( 'application_password_did_authenticate' ) > 0 && get_current_user_id() > 0;
	}

	/**
	 * Counts the call and refuses it when over the limit.
	 *
	 * @param mixed            $result  Result so far.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function count( $result, $server, $request ) {
		$this->state = null;
		if ( null !== $result || ! $request instanceof \WP_REST_Request || ! str_starts_with( (string) $request->get_route(), '/' . RestServer::NAMESPACE . '/' ) ) {
			return $result;
		}
		$limit = self::limit();
		if ( 0 === $limit || ! self::is_api_client() ) {
			return $result;
		}
		$key   = 'api-user:' . get_current_user_id();
		$over  = RateLimiter::hit( $key, $limit, self::WINDOW );
		$left  = max( 1, RateLimiter::retry_after( $key ) );
		$count = RateLimiter::count( $key );

		$this->state = array(
			'limit'     => $limit,
			'remaining' => max( 0, $limit - $count ),
			'reset'     => time() + $left,
		);
		if ( ! $over ) {
			return $result;
		}
		return new \WP_Error(
			'cliezoo_rate_limited',
			__( 'Too many API calls. Wait a moment and try again.', 'cliezoo' ),
			array(
				'status'      => 429,
				'retry_after' => $left,
			)
		);
	}

	/**
	 * Adds the headers to a counted call's response.
	 *
	 * @param \WP_HTTP_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_HTTP_Response
	 */
	public function headers( $response, $server, $request ) {
		unset( $server, $request );
		if ( null === $this->state || ! $response instanceof \WP_HTTP_Response ) {
			return $response;
		}
		$response->header( 'X-RateLimit-Limit', (string) $this->state['limit'] );
		$response->header( 'X-RateLimit-Remaining', (string) $this->state['remaining'] );
		$response->header( 'X-RateLimit-Reset', (string) $this->state['reset'] );
		return $response;
	}

	/**
	 * What the docs say, from the same numbers.
	 *
	 * @return array{limit: int, window: int, applies_to: string}
	 */
	public static function describe(): array {
		return array(
			'limit'      => self::limit(),
			'window'     => self::WINDOW,
			'applies_to' => 'application_password',
		);
	}
}
