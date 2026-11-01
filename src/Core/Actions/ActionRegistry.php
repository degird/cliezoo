<?php
/**
 * Registry of business operations.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Actions;

use Cliezoo\Core\Data\QueryException;
use Cliezoo\Core\Logger;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Forbidden;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Holds every Action and runs them with validation and permission checks, so
 * REST, Abilities, MCP, AI, automations and CLI share one code path.
 */
final class ActionRegistry {

	/**
	 * Actions keyed by name.
	 *
	 * @var array<string, Action>
	 */
	private array $actions = array();

	/**
	 * The channel the actor of the operation now running came in on, or ''
	 * when nothing is running.
	 *
	 * @var string
	 */
	private string $channel = '';

	/**
	 * How the operation now running arrived: 'rest', 'ability', 'cli' and so
	 * on, or '' when none is.
	 *
	 * The audit log asks this, because "what has the agent done" cannot be
	 * answered from the transport alone: the same agent reaches the same
	 * ability over WP-CLI and over HTTP with an application password, and the
	 * two have to be filed together or the question has no answer.
	 *
	 * @return string
	 */
	public function channel(): string {
		return $this->channel;
	}

	/**
	 * Adds an action.
	 *
	 * @param Action $action Action.
	 * @return void
	 */
	public function add( Action $action ): void {
		// Two actions under one name is one of them gone, with its route:
		// the notification catalogue's "events.list" once replaced the
		// calendar's own, and GET /events stopped existing without a word.
		// Replacing an action on purpose is \`replace()\`.
		if ( isset( $this->actions[ $action->name() ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'An action named "%s" is already registered; use replace() to swap one out on purpose.', $action->name() ) ),
				'1.0.0'
			);
		}
		$this->actions[ $action->name() ] = $action;
	}

	/**
	 * Swaps an action for another of the same name, on purpose (an
	 * add-on extending a built-in one, say).
	 *
	 * @param Action $action Action.
	 * @return void
	 */
	public function replace( Action $action ): void {
		$this->actions[ $action->name() ] = $action;
	}

	/**
	 * An action by name.
	 *
	 * @param string $name Name.
	 * @return Action|null
	 */
	public function get( string $name ): ?Action {
		return $this->actions[ $name ] ?? null;
	}

	/**
	 * All actions.
	 *
	 * @return array<string, Action>
	 */
	public function all(): array {
		return $this->actions;
	}

	/**
	 * Validates, authorises and executes an action.
	 *
	 * @param string               $name  Action name.
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $input Raw input.
	 * @return mixed
	 * @throws ActionError On any expected failure.
	 */
	public function run( string $name, Actor $actor, array $input ) {
		$action = $this->get( $name );
		if ( ! $action ) {
			throw new ActionError( 'cliezoo_unknown_action', __( 'Unknown operation.', 'cliezoo' ), 404 );
		}
		$input = $this->validate( $action, $input );
		if ( ! $action->permission( $actor, $input ) ) {
			throw ActionError::forbidden();
		}
		/**
		 * Lets a policy refuse an action for this actor on every channel (REST,
		 * Abilities, MCP), e.g. while a role's required 2FA is not set up.
		 * Return an ActionError to refuse, null to allow.
		 *
		 * @param ActionError|null $refusal Refusal.
		 * @param Action           $action  Action.
		 * @param Actor            $actor   Actor.
		 * @param array            $input   What was asked for, validated.
		 */
		$refusal = apply_filters( 'cliezoo_action_gate', null, $action, $actor, $input );
		if ( $refusal instanceof ActionError ) {
			throw $refusal;
		}
		// An action may run another, so the channel is put back rather than
		// cleared: the outer operation has not finished arriving.
		$outer         = $this->channel;
		$this->channel = $actor->channel;
		try {
			/**
			 * Fires before an action runs.
			 *
			 * @param string $name  Action name.
			 * @param Actor  $actor Actor.
			 * @param array  $input Input.
			 */
			do_action( 'cliezoo_before_action', $name, $actor, $input );
			$result = $action->execute( $actor, $input );
			/**
			 * Fires after an action ran successfully.
			 *
			 * @param string $name   Action name.
			 * @param Actor  $actor  Actor.
			 * @param array  $input  Input.
			 * @param mixed  $result Result.
			 */
			do_action( 'cliezoo_after_action', $name, $actor, $input, $result );
			return $result;
		} catch ( ActionError $error ) {
			throw $error;
		} catch ( Forbidden $error ) {
			throw ActionError::forbidden();
		} catch ( QueryException $error ) {
			throw ActionError::validation( $error->getMessage() );
		} catch ( \Throwable $error ) {
			Logger::error(
				'Action failed',
				array(
					'action' => $name,
					'error'  => $error->getMessage(),
					'file'   => basename( $error->getFile() ) . ':' . $error->getLine(),
				)
			);
			throw new ActionError( 'cliezoo_error', __( 'Something went wrong. Please try again.', 'cliezoo' ), 500 );
		} finally {
			$this->channel = $outer;
		}
	}

	/**
	 * Validates and sanitises input against the action's JSON schema.
	 *
	 * @param Action               $action Action.
	 * @param array<string, mixed> $input  Input.
	 * @return array<string, mixed>
	 * @throws ActionError On invalid input.
	 */
	public function validate( Action $action, array $input ): array {
		$schema = $action->input_schema();
		$valid  = rest_validate_value_from_schema( $input, $schema, 'input' );
		if ( is_wp_error( $valid ) ) {
			throw ActionError::validation( self::error_message( $valid ), self::field_errors( $valid ) );
		}
		$clean = rest_sanitize_value_from_schema( $input, $schema, 'input' );
		if ( is_wp_error( $clean ) ) {
			throw ActionError::validation( self::error_message( $clean ) );
		}
		return is_array( $clean ) ? $clean : array();
	}

	/**
	 * Readable message from a validation WP_Error.
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	private static function error_message( \WP_Error $error ): string {
		return $error->get_error_message() ? $error->get_error_message() : __( 'The input is not valid.', 'cliezoo' );
	}

	/**
	 * Field map from a validation error ("input[name]" → message).
	 *
	 * @param \WP_Error $error Error.
	 * @return array<string, string>
	 */
	private static function field_errors( \WP_Error $error ): array {
		$fields = array();
		$data   = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['param'] ) ) {
			$fields[ (string) preg_replace( '/^input\[([^\]]+)\].*$/', '$1', (string) $data['param'] ) ] = $error->get_error_message();
		} elseif ( preg_match( '/^input\[([^\]]+)\]/', $error->get_error_message(), $m ) ) {
			$fields[ $m[1] ] = $error->get_error_message();
		}
		return $fields;
	}
}
