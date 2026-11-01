<?php
/**
 * Sandbox API keys: real answers, nothing kept.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Auth;

use Cliezoo\Core\Rest\RestServer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A request signed with a sandbox key runs exactly as a real one — the
 * same checks, the same validation, the same answer — inside a database
 * transaction that is rolled back when it ends. While it runs, no email is
 * sent and no request leaves the server, and statements that would end
 * the transaction early (COMMIT, or DDL, which commits implicitly) are not
 * run. Reads are real reads. The answer says it was a sandbox run.
 *
 * Uploading file bytes is refused with a sandbox key: files land on a disk
 * or in a bucket, which a database rollback cannot take back.
 */
final class ApiSandbox {

	/**
	 * Whether a sandbox write is running.
	 *
	 * @var bool
	 */
	private bool $open = false;

	/**
	 * Whether the run is a savepoint inside a transaction already open.
	 *
	 * @var bool
	 */
	private bool $nested = false;

	/**
	 * Emails that were not sent.
	 *
	 * @var int
	 */
	private int $held = 0;

	/**
	 * Statements that were not run.
	 *
	 * @var int
	 */
	private int $skipped = 0;

	/**
	 * Hooks it in.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'begin' ), 0, 3 );
		// Inside dispatch, so internal requests (rest_do_request) close too;
		// post_dispatch and shutdown are backstops.
		add_filter( 'rest_request_after_callbacks', array( $this, 'end' ), 1000, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'end' ), 1000, 3 );
		add_action( 'shutdown', array( $this, 'roll_back' ), 0 );
	}

	/**
	 * Whether this request is signed with a sandbox key.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		$uuid = (string) ( $GLOBALS['wp_rest_application_password_uuid'] ?? '' );
		return '' !== $uuid && get_current_user_id() > 0 && ApiKeys::is_sandbox( get_current_user_id(), $uuid );
	}

	/**
	 * `rest_pre_dispatch`: opens the transaction for a sandbox write.
	 *
	 * @param mixed            $result  Result so far.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function begin( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || $this->open || ! $request instanceof \WP_REST_Request
			|| ! str_starts_with( (string) $request->get_route(), '/' . RestServer::NAMESPACE . '/' )
			|| ! self::active() ) {
			return $result;
		}
		if ( in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $result;
		}
		if ( $request->get_file_params() || 1 === preg_match( '#/(chunks|upload)#', (string) $request->get_route() ) ) {
			return new \WP_Error( 'cliezoo_sandbox_files', __( 'A sandbox key cannot upload files: a file cannot be taken back the way a database change can. Use a live key on a test site.', 'cliezoo' ), array( 'status' => 403 ) );
		}
		global $wpdb;
		$prefix = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$other  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s AND ENGINE IS NOT NULL AND ENGINE <> %s', $prefix, 'InnoDB' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Checks the storage engine; nothing to cache.
		if ( $other > 0 ) {
			return new \WP_Error( 'cliezoo_sandbox_engine', __( 'This site has tables that cannot roll a change back, so sandbox keys can only read here.', 'cliezoo' ), array( 'status' => 409 ) );
		}
		// With autocommit off a transaction is already open (a caller's own,
		// or a test's): a savepoint inside it, so starting one does not commit
		// what came before.
		$this->nested = '0' === (string) $wpdb->get_var( 'SELECT @@autocommit' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reads the session's mode.
		if ( $this->nested ) {
			$wpdb->query( 'SAVEPOINT cliezoo_sandbox' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The sandbox's own savepoint.
		} else {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The sandbox's own transaction.
		}
		$this->open    = true;
		$this->held    = 0;
		$this->skipped = 0;
		add_filter( 'pre_wp_mail', array( $this, 'hold_mail' ), PHP_INT_MAX );
		add_filter( 'pre_http_request', array( $this, 'hold_http' ), PHP_INT_MAX );
		add_filter( 'query', array( $this, 'guard' ), PHP_INT_MAX );
		return $result;
	}

	/**
	 * `rest_request_after_callbacks` / `rest_post_dispatch`: rolls back and
	 * says so.
	 *
	 * @param mixed $response Response.
	 * @param mixed $handler  Handler, or the server.
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public function end( $response, $handler, $request ) {
		unset( $handler, $request );
		if ( ! $this->open ) {
			// Both hooks see a real request: the second must not turn the
			// first's "rolled-back" into "read".
			if ( self::active() && $response instanceof \WP_HTTP_Response && ! isset( $response->get_headers()['X-Cliezoo-Sandbox'] ) ) {
				$response->header( 'X-Cliezoo-Sandbox', 'read' );
			}
			return $response;
		}
		$this->roll_back();
		if ( $response instanceof \WP_HTTP_Response ) {
			$response->header( 'X-Cliezoo-Sandbox', 'rolled-back' );
			$response->header( 'X-Cliezoo-Sandbox-Emails-Held', (string) $this->held );
			if ( $this->skipped > 0 ) {
				$response->header( 'X-Cliezoo-Sandbox-Statements-Skipped', (string) $this->skipped );
			}
		}
		return $response;
	}

	/**
	 * Rolls the transaction back (also at shutdown, should a request end
	 * without its response).
	 *
	 * @return void
	 */
	public function roll_back(): void {
		if ( ! $this->open ) {
			return;
		}
		remove_filter( 'query', array( $this, 'guard' ), PHP_INT_MAX );
		global $wpdb;
		if ( $this->nested ) {
			$wpdb->query( 'ROLLBACK TO SAVEPOINT cliezoo_sandbox' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Undoing the sandbox's own savepoint.
		} else {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Ending the sandbox's own transaction.
		}
		$this->open = false;
		remove_filter( 'pre_wp_mail', array( $this, 'hold_mail' ), PHP_INT_MAX );
		remove_filter( 'pre_http_request', array( $this, 'hold_http' ), PHP_INT_MAX );
		// What was cached during the run described rows that no longer exist.
		wp_cache_flush();
	}

	/**
	 * `pre_wp_mail`: nothing is sent from a sandbox run.
	 *
	 * @return bool
	 */
	public function hold_mail(): bool {
		++$this->held;
		return true;
	}

	/**
	 * `pre_http_request`: nothing leaves the server from a sandbox run.
	 *
	 * @return \WP_Error
	 */
	public function hold_http(): \WP_Error {
		return new \WP_Error( 'cliezoo_sandbox', __( 'Not sent: this ran with a sandbox key.', 'cliezoo' ) );
	}

	/**
	 * `query`: statements that would end the transaction are not run.
	 *
	 * @param string $sql Statement.
	 * @return string
	 */
	public function guard( $sql ) {
		if ( 1 === preg_match( '/^\s*(COMMIT|BEGIN|START\s+TRANSACTION|SET\s+autocommit|CREATE|ALTER|DROP|TRUNCATE|RENAME|LOCK\s+TABLES|UNLOCK\s+TABLES)\b/i', (string) $sql ) ) {
			++$this->skipped;
			return 'SELECT 1';
		}
		return $sql;
	}
}
