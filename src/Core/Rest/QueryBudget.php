<?php
/**
 * Counts the database queries each API call makes.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The performance budget says a REST call makes at most 15 queries, and
 * more than 25 is a bug to find (Blueprint §22). With CLIEZOO_QUERY_BUDGET
 * set to true in wp-config.php, every `cliezoo/v1` answer says how many
 * queries it took in `X-Cliezoo-Queries`, and a call over budget is
 * written to the PHP error log — as a notice over 15, as an alert over
 * 25 — with its route, so the call that grew is found while it is being
 * written rather than on a slow site. It is for Cliezoo's own developers:
 * without the constant nothing is added, on any site, so a site owner's
 * debug log only ever holds real problems.
 */
final class QueryBudget {

	/** Queries a call should stay within. */
	public const BUDGET = 15;

	/** Queries that mean something is wrong. */
	public const ALERT = 25;

	/**
	 * `$wpdb->num_queries` when the call started.
	 *
	 * @var int|null
	 */
	private ?int $start = null;

	/**
	 * Hooks, only where the numbers are wanted.
	 *
	 * @return void
	 */
	public function hooks(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'rest_pre_dispatch', array( $this, 'begin' ), 1, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'finish' ), 999, 3 );
	}

	/**
	 * Whether this site counts: only when CLIEZOO_QUERY_BUDGET asks.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return defined( 'CLIEZOO_QUERY_BUDGET' ) && (bool) CLIEZOO_QUERY_BUDGET;
	}

	/**
	 * Notes where the count stands as a call starts.
	 *
	 * @param mixed            $result  Result so far.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function begin( $result, $server, $request ) {
		global $wpdb;
		$this->start = $request instanceof \WP_REST_Request && str_starts_with( (string) $request->get_route(), '/' . RestServer::NAMESPACE . '/' ) ? (int) $wpdb->num_queries : null;
		return $result;
	}

	/**
	 * Says how many queries the call took, and logs one over budget.
	 *
	 * @param \WP_HTTP_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_HTTP_Response
	 */
	public function finish( $response, $server, $request ) {
		global $wpdb;
		if ( null === $this->start || ! $response instanceof \WP_HTTP_Response ) {
			return $response;
		}
		$used        = max( 0, (int) $wpdb->num_queries - $this->start );
		$this->start = null;
		$response->header( 'X-Cliezoo-Queries', (string) $used );
		$level = self::level( $used );
		if ( '' !== $level && $request instanceof \WP_REST_Request ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only with CLIEZOO_QUERY_BUDGET; says which call grew.
			error_log( sprintf( '[cliezoo] %s: %s %s made %d queries (budget %d).', $level, $request->get_method(), $request->get_route(), $used, self::BUDGET ) );
		}
		return $response;
	}

	/**
	 * How serious a count is: '' within budget, 'notice' over it, 'alert'
	 * over the alert line.
	 *
	 * @param int $used Queries.
	 * @return string
	 */
	public static function level( int $used ): string {
		if ( $used > self::ALERT ) {
			return 'alert';
		}
		return $used > self::BUDGET ? 'notice' : '';
	}
}
