<?php
/**
 * Business operation contract.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Actions;

use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One business operation, defined once and exposed through REST, WordPress
 * Abilities, MCP, the AI assistant, automations and WP-CLI (Blueprint §8.4).
 */
abstract class Action {

	/**
	 * Unique name, "{module}.{verb}" (e.g. "invoices.finalize").
	 *
	 * @return string
	 */
	abstract public function name(): string;

	/**
	 * Short translated label.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Description written for people and AI agents (what, when, example).
	 *
	 * @return string
	 */
	public function description(): string {
		return $this->label();
	}

	/**
	 * JSON schema of the input.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	/**
	 * JSON schema of the output.
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * Permission check (the Policy is consulted inside).
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $input Validated input.
	 * @return bool
	 */
	abstract public function permission( Actor $actor, array $input ): bool;

	/**
	 * Runs the operation.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $input Validated input.
	 * @return mixed JSON-serialisable result.
	 * @throws ActionError On business errors.
	 */
	abstract public function execute( Actor $actor, array $input );

	/**
	 * Metadata used by every surface.
	 *
	 * - readonly: no side effects (MCP readOnlyHint)
	 * - destructive: deletes/sends/changes money or permissions (MCP destructiveHint, AI confirmation)
	 * - idempotent: safe to repeat
	 * - touches_money / client_visible: extra confirmation in AI and automations
	 * - mcp: null | 'read' | 'safe' | 'sensitive' (catalogue class, MCP plan §4)
	 * - ability: register as a WordPress Ability
	 * - rest: [method, route] or null when the action has no REST route of its own
	 *
	 * @return array<string, mixed>
	 */
	public function meta(): array {
		return array();
	}

	/**
	 * Metadata with defaults applied.
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool, touches_money: bool, client_visible: bool, mcp: ?string, ability: bool, rest: ?array<int, string>}
	 */
	final public function resolved_meta(): array {
		return array_merge(
			array(
				'readonly'       => false,
				'destructive'    => false,
				'idempotent'     => false,
				'touches_money'  => false,
				'client_visible' => false,
				// Whether the answer contains words a client wrote. An agent
				// reading them has to be told they are information and not
				// instructions, and the telling belongs with the operation
				// rather than in one hand-written payload.
				'untrusted'      => false,
				'mcp'            => null,
				'ability'        => true,
				'rest'           => null,
			),
			$this->meta(),
			// What reaches a client or moves money, whatever the operation's
			// own file says (Careful).
			Careful::flags( $this->name() )
		);
	}
}
