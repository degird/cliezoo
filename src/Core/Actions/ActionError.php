<?php
/**
 * Business error with a stable code.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An expected failure: validation, not found, conflict. Messages are safe to show.
 */
class ActionError extends \RuntimeException {

	/**
	 * Stable error code (cliezoo_validation, cliezoo_not_found…).
	 *
	 * @var string
	 */
	public string $error_code;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public int $status;

	/**
	 * Per-field messages for forms.
	 *
	 * @var array<string, string>
	 */
	public array $fields;

	/**
	 * Seconds until a rate-limited call may be retried (sent as Retry-After).
	 *
	 * @var int
	 */
	public int $retry_after = 0;

	/**
	 * Constructor.
	 *
	 * @param string                $error_code Code.
	 * @param string                $message    Translated, safe message.
	 * @param int                   $status     HTTP status.
	 * @param array<string, string> $fields     Field errors.
	 */
	public function __construct( string $error_code, string $message, int $status = 400, array $fields = array() ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
		$this->status     = $status;
		$this->fields     = $fields;
	}

	/**
	 * Not found (also used when the actor may not know the record exists).
	 *
	 * @return self
	 */
	public static function not_found(): self {
		return new self( 'cliezoo_not_found', __( 'This item was not found or you do not have access to it.', 'cliezoo' ), 404 );
	}

	/**
	 * Permission denied.
	 *
	 * @return self
	 */
	public static function forbidden(): self {
		return new self( 'cliezoo_forbidden', __( 'You do not have permission to do this.', 'cliezoo' ), 403 );
	}

	/**
	 * Validation failure.
	 *
	 * @param string                $message Summary.
	 * @param array<string, string> $fields  Field errors.
	 * @return self
	 */
	public static function validation( string $message, array $fields = array() ): self {
		return new self( 'cliezoo_validation', $message, 400, $fields );
	}

	/**
	 * Too many requests.
	 *
	 * @param string $message     Message.
	 * @param int    $retry_after Seconds until a retry may succeed.
	 * @return self
	 */
	public static function rate_limited( string $message, int $retry_after = 60 ): self {
		$error              = new self( 'cliezoo_rate_limited', $message, 429 );
		$error->retry_after = max( 1, $retry_after );
		return $error;
	}

	/**
	 * Conflict (stale edit, duplicate, state machine violation).
	 *
	 * @param string $message Message.
	 * @param string $code    Code.
	 * @return self
	 */
	public static function conflict( string $message, string $code = 'cliezoo_conflict' ): self {
		return new self( $code, $message, 409 );
	}

	/**
	 * Converts to WP_Error for REST.
	 *
	 * @return \WP_Error
	 */
	public function to_wp_error(): \WP_Error {
		$data = array( 'status' => $this->status );
		if ( $this->fields ) {
			$data['fields'] = $this->fields;
		}
		if ( 429 === $this->status ) {
			$data['retry_after'] = max( 1, $this->retry_after ? $this->retry_after : 60 );
		}
		return new \WP_Error( $this->error_code, $this->getMessage(), $data );
	}
}
