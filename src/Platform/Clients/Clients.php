<?php
/**
 * What a client record does for itself.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Clients;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names come from the entity definitions; every value is a placeholder.

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * The small decisions a client record makes without being asked: what its
 * initials are, which currency and terms it inherits from the workspace,
 * that exactly one address and one billing profile is the default, and what
 * happens when two records turn out to be the same company.
 */
final class Clients {

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
	 * Fills in what the workspace already knows, on create and on update.
	 *
	 * Defaults are copied rather than referenced: changing the workspace
	 * currency next year must not silently re-price last year's client.
	 *
	 * @param array<string, mixed>      $data     Column data about to be saved.
	 * @param array<string, mixed>|null $existing The current row, on update.
	 * @return array<string, mixed>
	 * @throws ActionError When a company is put inside itself, or a group
	 *                     is asked to go two levels deep.
	 */
	public function fill( array $data, ?array $existing ): array {
		$settings = $this->plugin->settings();
		$name     = (string) ( $data['name'] ?? ( $existing['name'] ?? '' ) );

		if ( '' === (string) ( $data['initials'] ?? ( $existing['initials'] ?? '' ) ) && '' !== $name ) {
			$data['initials'] = self::initials( $name );
		}

		// A company cannot be part of itself, and a group is one level
		// deep: a client that already has clients under it cannot then
		// be filed under a third.
		if ( array_key_exists( 'parent_id', $data ) && ! empty( $data['parent_id'] ) ) {
			$parent = (int) $data['parent_id'];
			if ( $existing && $parent === (int) $existing['id'] ) {
				throw ActionError::validation( __( 'A company cannot be part of itself.', 'cliezoo' ) );
			}
			$above = $this->plugin->service( 'clients' )->repository()->find( $parent );
			if ( $above && ! empty( $above['parent_id'] ) ) {
				throw ActionError::validation( __( 'That company is already part of another one.', 'cliezoo' ) );
			}
			if ( $existing && $this->plugin->service( 'clients' )->repository()->where( array( 'parent_id' => (int) $existing['id'] ), '', 1 ) ) {
				throw ActionError::validation( __( 'This company has clients of its own, so it cannot be part of another.', 'cliezoo' ) );
			}
		}
		// The assigned team is people on the team: uids of staff members,
		// each once, and nobody else.
		if ( array_key_exists( 'team', $data ) ) {
			$wanted = is_array( $data['team'] ) ? $data['team'] : (array) json_decode( (string) $data['team'], true );
			$keep   = array();
			foreach ( array_slice( array_unique( array_map( 'strval', $wanted ) ), 0, 50 ) as $uid ) {
				$member = $this->plugin->service( 'members' )->repository()->find_by_uid( $uid );
				if ( $member && 'staff' === (string) $member['kind'] ) {
					$keep[] = $uid;
				}
			}
			$data['team'] = wp_json_encode( $keep );
		}
		if ( null === $existing ) {
			foreach ( array(
				'currency'      => (string) $settings->get( 'workspace', 'currency', 'USD' ),
				'language'      => (string) $settings->get( 'workspace', 'language', '' ),
				'timezone'      => (string) $settings->get( 'workspace', 'timezone', '' ),
				'payment_terms' => (int) $settings->get( 'invoices', 'payment_terms', 14 ),
			) as $column => $default ) {
				if ( empty( $data[ $column ] ) && '' !== (string) $default ) {
					$data[ $column ] = $default;
				}
			}
		}
		return $data;
	}

	/**
	 * Initials for the avatar: two letters, from the first two words.
	 *
	 * @param string $name Client name.
	 * @return string
	 */
	public static function initials( string $name ): string {
		$words = preg_split( '/\s+/u', trim( $name ) );
		$parts = array_values( array_filter( is_array( $words ) ? $words : array() ) );
		if ( count( $parts ) >= 2 ) {
			return mb_strtoupper( mb_substr( (string) $parts[0], 0, 1 ) . mb_substr( (string) $parts[1], 0, 1 ) );
		}
		// One word gets two of its own letters: a single letter in a circle
		// is not a mark, it is a typo.
		return mb_strtoupper( mb_substr( '' !== $name ? (string) ( $parts[0] ?? $name ) : $name, 0, 2 ) );
	}

	/**
	 * Gives a new client the address and billing profile it will need.
	 *
	 * Empty rows would be noise, so this only creates a billing profile,
	 * which every invoice needs a shape for; addresses are added when there
	 * is one to add.
	 *
	 * @param array<string, mixed> $row The new client.
	 * @return void
	 */
	public function after_create( array $row ): void {
		$this->plugin->service( 'billing_profiles' )->repository()->insert(
			array(
				'client_id'     => (int) $row['id'],
				'name'          => __( 'Default', 'cliezoo' ),
				'bill_to'       => (string) $row['name'],
				'business_name' => (string) ( $row['legal_name'] ?? '' ) !== '' ? (string) $row['legal_name'] : (string) $row['name'],
				'tax_number'    => (string) ( $row['tax_number'] ?? '' ),
				'email'         => (string) ( $row['email'] ?? '' ),
				'phone'         => (string) ( $row['phone'] ?? '' ),
				'currency'      => (string) ( $row['currency'] ?? '' ),
				'payment_terms' => (int) ( $row['payment_terms'] ?? 0 ),
				'language'      => (string) ( $row['language'] ?? '' ),
				'is_default'    => 1,
			)
		);
		// A score from the first day, so the column means something before
		// the overnight job has ever run.
		$this->plugin->get( HealthScore::class )->refresh( (int) $row['id'] );
	}

	/**
	 * Keeps "default" meaning one thing.
	 *
	 * The row that was just marked default wins; any other row of the same
	 * kind for the same client loses the mark. When a client has rows but
	 * none is marked, the first one is.
	 *
	 * @param string               $entity `client_locations` or `billing_profiles`.
	 * @param array<string, mixed> $row    The row that was saved.
	 * @return void
	 */
	public function keep_one_default( string $entity, array $row ): void {
		$client_id  = (int) ( $row['client_id'] ?? 0 );
		$repository = $this->plugin->service( $entity )->repository();
		if ( 0 === $client_id ) {
			return;
		}
		$rows = $repository->where( array( 'client_id' => $client_id ) );
		if ( ! $rows ) {
			return;
		}
		$chosen = (int) ( $row['is_default'] ?? 0 ) === 1 ? (int) $row['id'] : 0;
		if ( 0 === $chosen ) {
			foreach ( $rows as $candidate ) {
				if ( 1 === (int) $candidate['is_default'] ) {
					return; // Somebody is already the default.
				}
			}
			$chosen = (int) $rows[0]['id'];
		}
		foreach ( $rows as $candidate ) {
			$should = (int) $candidate['id'] === $chosen ? 1 : 0;
			if ( (int) $candidate['is_default'] !== $should ) {
				$repository->update( (int) $candidate['id'], array( 'is_default' => $should ) );
			}
		}
	}

	/**
	 * Merges one client into another.
	 *
	 * Everything that points at the loser is moved to the winner — every
	 * column in every entity that references clients, found from the
	 * definitions rather than a list kept by hand, so a module added later
	 * cannot be forgotten. The loser is then trashed, not deleted: a merge
	 * that turns out to be wrong has to be recoverable.
	 *
	 * @param Actor  $actor  Who is merging.
	 * @param string $loser  Uid of the client to merge away.
	 * @param string $winner Uid of the client to keep.
	 * @return array<string, mixed> What moved.
	 * @throws ActionError When either client is missing, or they are the same one.
	 */
	public function merge( Actor $actor, string $loser, string $winner ): array {
		$service = $this->plugin->service( 'clients' );
		$from    = $service->load( $actor, $loser );
		$to      = $service->load( $actor, $winner );
		if ( (int) $from['id'] === (int) $to['id'] ) {
			throw ActionError::validation( __( 'Choose two different clients.', 'cliezoo' ) );
		}

		$moved = array();
		foreach ( $this->plugin->entities()->all() as $name => $entity ) {
			if ( 'clients' === $name ) {
				continue;
			}
			foreach ( $entity->fields as $column => $field ) {
				// Either a declared reference to a client, or a plain
				// `client_id` column — the activity timeline uses the second
				// kind, and a merge that left the history behind would lose
				// the very thing the health score reads.
				$points_at_client = ( 'ref' === $field->type && 'clients' === $field->ref ) || 'client_id' === $column;
				if ( ! $points_at_client ) {
					continue;
				}
				// A row that may only exist once per client — the portal's
				// settings, say — cannot be moved onto a client that already
				// has one. The survivor's own is the one that stays, and the
				// other is dropped rather than failing the merge.
				if ( $this->one_per_client( $entity, $column ) ) {
					$this->plugin->service( $name )->repository()->delete_where( array( $column => (int) $from['id'] ) );
					continue;
				}
				$count = $this->move( $entity->table(), $column, (int) $from['id'], (int) $to['id'] );
				if ( $count > 0 ) {
					$moved[ $name ] = ( $moved[ $name ] ?? 0 ) + $count;
				}
			}
		}

		// What the winner does not have, it inherits: a merge should never
		// lose a phone number nobody had typed twice.
		$fill = array();
		foreach ( array( 'legal_name', 'website', 'email', 'phone', 'tax_number', 'industry', 'territory', 'head_count', 'annual_revenue', 'description', 'timezone', 'language' ) as $column ) {
			if ( empty( $to[ $column ] ) && ! empty( $from[ $column ] ) ) {
				$fill[ $column ] = $from[ $column ];
			}
		}
		if ( $fill ) {
			$this->plugin->service( 'clients' )->repository()->update( (int) $to['id'], $fill );
		}

		// Two clients' addresses and billing profiles are now one client's,
		// so exactly one of each is the default again.
		foreach ( array( 'client_locations', 'billing_profiles' ) as $child ) {
			$rows = $this->plugin->service( $child )->repository()->where( array( 'client_id' => (int) $to['id'] ) );
			if ( $rows ) {
				$this->keep_one_default( $child, $rows[0] + array( 'is_default' => 0 ) );
			}
		}

		$service->trash( $actor, $loser );
		do_action( 'cliezoo_clients_merged', (int) $from['id'], (int) $to['id'], $moved, $actor );

		$winner = $this->plugin->service( 'clients' )->repository()->find( (int) $to['id'] );
		return array(
			// Presented, not raw: the answer must hide what a read of the same
			// record would hide, financial fields included.
			'client' => $winner ? $this->plugin->service( 'clients' )->present( $actor, $winner ) : null,
			'moved'  => $moved,
			'filled' => array_keys( $fill ),
		);
	}

	/**
	 * Whether an entity may only have one row per client.
	 *
	 * @param \Cliezoo\Core\Data\Entity $entity Entity.
	 * @param string                    $column Column pointing at the client.
	 * @return bool
	 */
	private function one_per_client( \Cliezoo\Core\Data\Entity $entity, string $column ): bool {
		foreach ( $entity->unique as $columns ) {
			if ( array( $column ) === array_values( $columns ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Repoints one column at the surviving client.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column.
	 * @param int    $from   Old client id.
	 * @param int    $to     New client id.
	 * @return int Rows moved.
	 */
	private function move( string $table, string $column, int $from, int $to ): int {
		global $wpdb;
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Moving a merged client's records.
			$wpdb->prepare( 'UPDATE %i SET %i = %d WHERE %i = %d', $table, $column, $to, $column, $from )
		);
	}
}
