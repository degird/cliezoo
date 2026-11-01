<?php
/**
 * Compact Action definition with callbacks.
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
 * An Action built fluently, for operations whose logic lives in a service:
 *
 *     Op::make( 'notes.for', __( 'List notes of a record', 'cliezoo' ) )
 *         ->input( $properties )->allow( $can )->run( $fn )->rest( 'GET', '/notes' )->readonly();
 */
final class Op extends Action {

	/**
	 * Name.
	 *
	 * @var string
	 */
	private string $op_name;

	/**
	 * Label.
	 *
	 * @var string
	 */
	private string $op_label;

	/**
	 * Description.
	 *
	 * @var string
	 */
	private string $op_description = '';

	/**
	 * Input properties.
	 *
	 * @var array<string, mixed>
	 */
	private array $properties = array();

	/**
	 * Permission callback.
	 *
	 * @var callable|null
	 */
	private $allow = null;

	/**
	 * Execute callback.
	 *
	 * @var callable|null
	 */
	private $run = null;

	/**
	 * Metadata.
	 *
	 * @var array<string, mixed>
	 */
	private array $op_meta = array();

	/**
	 * Constructor.
	 *
	 * @param string $name  Name.
	 * @param string $label Label.
	 */
	private function __construct( string $name, string $label ) {
		$this->op_name  = $name;
		$this->op_label = $label;
	}

	/**
	 * Starts a definition.
	 *
	 * @param string $name  Action name.
	 * @param string $label Translated label.
	 * @return self
	 */
	public static function make( string $name, string $label ): self {
		return new self( $name, $label );
	}

	/**
	 * Description for people and AI agents.
	 *
	 * @param string $text Text.
	 * @return self
	 */
	public function describe( string $text ): self {
		$this->op_description = $text;
		return $this;
	}

	/**
	 * Input properties (JSON schema "properties").
	 *
	 * @param array<string, mixed> $properties Properties.
	 * @return self
	 */
	public function input( array $properties ): self {
		$this->properties = $properties;
		return $this;
	}

	/**
	 * Permission callback fn( Actor, array ): bool.
	 *
	 * @param callable $allow Callback.
	 * @return self
	 */
	public function allow( callable $allow ): self {
		$this->allow = $allow;
		return $this;
	}

	/**
	 * Execute callback fn( Actor, array ): mixed.
	 *
	 * @param callable $run Callback.
	 * @return self
	 */
	public function run( callable $run ): self {
		$this->run = $run;
		return $this;
	}

	/**
	 * REST route.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below the namespace.
	 * @return self
	 */
	public function rest( string $method, string $route ): self {
		$this->op_meta['rest'] = array( $method, $route );
		return $this;
	}

	/**
	 * Marks as read-only (and idempotent).
	 *
	 * @return self
	 */
	public function readonly(): self {
		$this->op_meta['readonly']   = true;
		$this->op_meta['idempotent'] = true;
		return $this;
	}

	/**
	 * Sets metadata keys.
	 *
	 * @param array<string, mixed> $meta Meta.
	 * @return self
	 */
	public function with( array $meta ): self {
		$this->op_meta = array_merge( $this->op_meta, $meta );
		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return $this->op_name;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return $this->op_label;
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return '' !== $this->op_description ? $this->op_description : $this->op_label;
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => $this->properties,
		);
	}

	/**
	 * Permission.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $input Input.
	 * @return bool
	 */
	public function permission( Actor $actor, array $input ): bool {
		return null === $this->allow ? ! $actor->is_guest() : (bool) call_user_func( $this->allow, $actor, $input );
	}

	/**
	 * Execute.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $input Input.
	 * @return mixed
	 * @throws ActionError Without a run callback.
	 */
	public function execute( Actor $actor, array $input ) {
		if ( null === $this->run ) {
			throw ActionError::not_found();
		}
		return call_user_func( $this->run, $actor, $input );
	}

	/**
	 * {@inheritDoc}
	 */
	public function meta(): array {
		return $this->op_meta;
	}

	/**
	 * Common schema: a uid string.
	 *
	 * @param bool $required Required.
	 * @return array<string, mixed>
	 */
	public static function uid( bool $required = true ): array {
		return array(
			'type'     => 'string',
			'pattern'  => '^[0-9A-HJKMNP-TV-Z]{26}$',
			'required' => $required,
		);
	}

	/**
	 * Common schema: bounded string.
	 *
	 * @param int  $max      Max length.
	 * @param bool $required Required.
	 * @return array<string, mixed>
	 */
	public static function str( int $max = 190, bool $required = false ): array {
		return array(
			'type'      => 'string',
			'maxLength' => $max,
			'required'  => $required,
		);
	}

	/**
	 * Common schema: cursor + limit.
	 *
	 * @param int $size Default page size.
	 * @return array<string, mixed>
	 */
	public static function paging( int $size = 50 ): array {
		return array(
			'cursor' => self::str( 500 ),
			'limit'  => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 200,
				'default' => $size,
			),
		);
	}
}
