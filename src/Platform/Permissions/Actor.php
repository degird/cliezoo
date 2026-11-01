<?php
/**
 * The person (or system) performing an operation.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable view of who is acting: WordPress user, Cliezoo member, role,
 * client companies (for portal users) and the resolved permission matrix.
 */
final class Actor {

	/**
	 * WordPress user id (0 for guests and the system).
	 *
	 * @var int
	 */
	public int $user_id;

	/**
	 * Cliezoo member id (0 when not a member).
	 *
	 * @var int
	 */
	public int $member_id;

	/**
	 * Role key.
	 *
	 * @var string
	 */
	public string $role;

	/**
	 * Client company ids this user belongs to (portal users).
	 *
	 * @var int[]
	 */
	public array $client_ids;

	/**
	 * Selected client company (portal company switcher).
	 *
	 * @var int
	 */
	public int $active_client_id;

	/**
	 * Team ids (teams, from add-ons).
	 *
	 * @var int[]
	 */
	public array $team_ids;

	/**
	 * Channel the action came from: app, rest, ability, mcp, ai, automation, cli, system, public.
	 *
	 * @var string
	 */
	public string $channel;

	/**
	 * Resolved matrix.
	 *
	 * @var array{scopes: array<string, string>, toggles: array<string, bool>, write: bool}
	 */
	private array $matrix;

	/**
	 * Whether this is the trusted system actor (jobs, migrations).
	 *
	 * @var bool
	 */
	private bool $system;

	/**
	 * Constructor.
	 *
	 * @param int    $user_id          WP user id.
	 * @param int    $member_id        Member id.
	 * @param string $role             Role key.
	 * @param int[]  $client_ids       Client ids.
	 * @param int    $active_client_id Selected client.
	 * @param int[]  $team_ids         Team ids.
	 * @param string $channel          Channel.
	 * @param bool   $system           System actor.
	 */
	public function __construct( int $user_id, int $member_id, string $role, array $client_ids = array(), int $active_client_id = 0, array $team_ids = array(), string $channel = 'app', bool $system = false ) {
		$this->user_id          = $user_id;
		$this->member_id        = $member_id;
		$this->role             = $role;
		$this->client_ids       = array_values( array_map( 'intval', $client_ids ) );
		$this->active_client_id = $active_client_id ? $active_client_id : (int) ( $this->client_ids[0] ?? 0 );
		$this->team_ids         = array_values( array_map( 'intval', $team_ids ) );
		$this->channel          = $channel;
		$this->system           = $system;
		$this->matrix           = $system ? Roles::matrix( 'owner' ) : Roles::matrix( $role );
	}

	/**
	 * The system actor (background jobs, installers). Bypasses scoping.
	 *
	 * @param string $channel Channel label.
	 * @return self
	 */
	public static function system( string $channel = 'system' ): self {
		return new self( 0, 0, 'owner', array(), 0, array(), $channel, true );
	}

	/**
	 * A guest (public pages).
	 *
	 * @return self
	 */
	public static function guest(): self {
		return new self( 0, 0, 'guest', array(), 0, array(), 'public' );
	}

	/**
	 * Copy with another channel.
	 *
	 * @param string $channel Channel.
	 * @return self
	 */
	public function via( string $channel ): self {
		$copy          = clone $this;
		$copy->channel = $channel;
		return $copy;
	}

	/**
	 * Trusted system actor.
	 *
	 * @return bool
	 */
	public function is_system(): bool {
		return $this->system;
	}

	/**
	 * Workspace owner (or a system actor).
	 *
	 * @return bool
	 */
	public function is_owner(): bool {
		return $this->system || 'owner' === $this->role;
	}

	/**
	 * Owner or admin.
	 *
	 * @return bool
	 */
	public function is_admin(): bool {
		return $this->system || in_array( $this->role, array( 'owner', 'admin' ), true );
	}

	/**
	 * Internal staff (any staff role).
	 *
	 * @return bool
	 */
	public function is_staff(): bool {
		// Custom roles (from add-ons) are staff roles too, so anything that is not a client role counts.
		return $this->system || ( $this->member_id > 0 && ! $this->is_client() && isset( Roles::matrices()[ $this->role ] ) );
	}

	/**
	 * Client portal user.
	 *
	 * @return bool
	 */
	public function is_client(): bool {
		return in_array( $this->role, Roles::CLIENT_ROLES, true );
	}

	/**
	 * Logged-out visitor.
	 *
	 * @return bool
	 */
	public function is_guest(): bool {
		return ! $this->system && 0 === $this->member_id;
	}

	/**
	 * Record scope for an area: none | own | team | all.
	 *
	 * @param string $area Area key.
	 * @return string
	 */
	public function scope( string $area ): string {
		if ( $this->system ) {
			return 'all';
		}
		return $this->matrix['scopes'][ $area ] ?? 'none';
	}

	/**
	 * Whether a toggle permission is on.
	 *
	 * @param string $toggle Toggle name.
	 * @return bool
	 */
	public function allows( string $toggle ): bool {
		return $this->system || ! empty( $this->matrix['toggles'][ $toggle ] );
	}

	/**
	 * Whether the role may write at all (read-only role cannot).
	 *
	 * @return bool
	 */
	public function can_write(): bool {
		return $this->system || $this->matrix['write'];
	}

	/**
	 * Sees financial fields (rates, costs, margins).
	 *
	 * @return bool
	 */
	public function sees_finance(): bool {
		return $this->system || $this->allows( 'finance_see_rates' );
	}

	/**
	 * The resolved matrix (for the app's boot data; the server always re-checks).
	 *
	 * @return array{scopes: array<string, string>, toggles: array<string, bool>, write: bool}
	 */
	public function permissions(): array {
		return $this->matrix;
	}

	/**
	 * Client admin of their company.
	 *
	 * @return bool
	 */
	public function is_client_admin(): bool {
		return 'client_admin' === $this->role;
	}
}
