<?php
/**
 * What goes with a client: the records that belong to it, taken to the
 * trash with it when asked, and clients brought in from a spreadsheet.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Clients;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Deleting a client asks first what should happen to what belongs to it
 * (cliezoo.md §6.5): the answer is either "keep it" — the records stay,
 * no longer tied to anybody — or "put it in the trash too", which can be
 * undone from the one trash like anything else.
 */
final class ClientTools {

	/**
	 * The most rows one import takes.
	 */
	public const IMPORT_LIMIT = 1000;

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
	 * The kinds of record that belong to a client and can go to the trash
	 * with it, by entity, with their names.
	 *
	 * @return array<string, string>
	 */
	public function kinds(): array {
		$kinds = array(
			'contacts'      => __( 'Contacts', 'cliezoo' ),
			'projects'      => __( 'Projects', 'cliezoo' ),
			'boards'        => __( 'Boards', 'cliezoo' ),
			'folders'       => __( 'Folders', 'cliezoo' ),
			'files'         => __( 'Files', 'cliezoo' ),
			'conversations' => __( 'Conversations', 'cliezoo' ),
			'requests'      => __( 'Requests', 'cliezoo' ),
			'docs'          => __( 'Documents', 'cliezoo' ),
			'milestones'    => __( 'Milestones', 'cliezoo' ),
		);
		/**
		 * The kinds of record that belong to a client (invoices and quotes
		 * join as their modules arrive).
		 *
		 * @param array $kinds entity => label.
		 */
		$kinds = (array) apply_filters( 'cliezoo_client_related_kinds', $kinds );
		$have  = $this->plugin->entities();
		return array_filter(
			$kinds,
			static function ( $label, $entity ) use ( $have ): bool {
				if ( ! $have->has( (string) $entity ) ) {
					return false;
				}
				$one = $have->get( (string) $entity );
				return $one->soft_delete && '' !== $one->client_field;
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * How many of each kind belong to a client, as far as this person may see.
	 *
	 * @param Actor  $actor Who is asking.
	 * @param string $uid   The client.
	 * @return array<int, array{kind: string, label: string, count: int}>
	 * @throws ActionError When the client cannot be deleted by them.
	 */
	public function related( Actor $actor, string $uid ): array {
		$client = $this->plugin->service( 'clients' )->load( $actor, $uid, Policy::DELETE );
		$out    = array();
		foreach ( $this->kinds() as $entity => $label ) {
			$count = count( $this->rows_of( $actor, $entity, (int) $client['id'] ) );
			if ( $count > 0 ) {
				$out[] = array(
					'kind'  => $entity,
					'label' => $label,
					'count' => $count,
				);
			}
		}
		return $out;
	}

	/**
	 * Puts a client in the trash, with the kinds of what belongs to it that
	 * the person chose.
	 *
	 * @param Actor    $actor Who is deleting.
	 * @param string   $uid   The client.
	 * @param string[] $with  Kinds to trash too.
	 * @return array{trashed: array<string, int>, kept: array<string, int>}
	 * @throws ActionError When they may not delete the client.
	 */
	public function trash( Actor $actor, string $uid, array $with ): array {
		$service = $this->plugin->service( 'clients' );
		$client  = $service->load( $actor, $uid, Policy::DELETE );
		$kinds   = $this->kinds();
		$trashed = array();
		$kept    = array();
		// What is inside goes before what holds it: files before folders,
		// and the deepest folders first, or a folder would still be full
		// when its turn came and stay behind.
		$chosen = array_values( array_intersect( array_keys( $kinds ), array_map( 'strval', $with ) ) );
		usort( $chosen, static fn( string $a, string $b ): int => ( 'folders' === $a ? 1 : 0 ) <=> ( 'folders' === $b ? 1 : 0 ) );
		foreach ( $chosen as $entity ) {
			$other = $this->plugin->service( $entity );
			$rows  = $this->rows_of( $actor, $entity, (int) $client['id'] );
			if ( 'folders' === $entity ) {
				usort( $rows, static fn( array $a, array $b ): int => (int) ( $b['depth'] ?? 0 ) <=> (int) ( $a['depth'] ?? 0 ) );
			}
			foreach ( $rows as $row ) {
				try {
					// Through the checked path: the same delete permission as
					// trashing it on its own screen.
					$other->trash( $actor, (string) $row['uid'] );
					$trashed[ $entity ] = ( $trashed[ $entity ] ?? 0 ) + 1;
				} catch ( ActionError $error ) {
					// One they may not delete stays where it is; the rest go.
					$kept[ $entity ] = ( $kept[ $entity ] ?? 0 ) + 1;
				}
			}
		}
		$service->trash( $actor, $uid );
		do_action(
			'cliezoo_audit',
			'client_trashed_with_related',
			array(
				'object_type' => 'clients',
				'object_id'   => (int) $client['id'],
				'trashed'     => $trashed,
			)
		);
		return array(
			'trashed' => $trashed,
			'kept'    => $kept,
		);
	}

	/**
	 * Creates (or corrects) one client per row of a spreadsheet.
	 *
	 * Every row is tried and the answer says which did not work and why; a
	 * client with the same name is skipped unless asked to update it.
	 *
	 * @param Actor                            $actor  Who is importing.
	 * @param array<int, array<string, mixed>> $rows   Rows of column => value.
	 * @param bool                             $update Correct clients with the same name.
	 * @return array{created: int, updated: int, skipped: array<int, array{row: int, reason: string}>}
	 */
	public function import( Actor $actor, array $rows, bool $update ): array {
		$service = $this->plugin->service( 'clients' );
		$created = 0;
		$updated = 0;
		$skipped = array();
		$rows    = array_values( $rows );
		// Rows past the limit used to be dropped in silence: somebody who
		// imported 1,400 clients was told "1,000 created" and never heard
		// about the other 400. Each one is named as skipped instead.
		foreach ( array_slice( $rows, self::IMPORT_LIMIT ) as $over => $ignored ) {
			$skipped[] = array(
				'row'    => self::IMPORT_LIMIT + $over + 1,
				'reason' => sprintf(
					/* translators: %d: how many rows one import takes. */
					__( 'One import takes %d rows. Split the file and import the rest.', 'cliezoo' ),
					self::IMPORT_LIMIT
				),
			);
		}
		foreach ( array_slice( $rows, 0, self::IMPORT_LIMIT ) as $index => $row ) {
			$row = is_array( $row ) ? $row : array();
			// A sheet has words and numbers in its cells, nothing else.
			if ( array_filter( $row, static fn( $value ): bool => ! is_scalar( $value ) && null !== $value ) ) {
				$skipped[] = array(
					'row'    => $index + 1,
					'reason' => __( 'This row has something in it that is not text or a number.', 'cliezoo' ),
				);
				continue;
			}
			$row  = array_map( static fn( $value ): string => trim( (string) $value ), $row );
			$name = (string) ( $row['name'] ?? '' );
			if ( '' === $name ) {
				$skipped[] = array(
					'row'    => $index + 1,
					'reason' => __( 'A client needs a name.', 'cliezoo' ),
				);
				continue;
			}
			// Only the columns a client has; anything else in the sheet is
			// not ours to guess at.
			$values   = array_intersect_key( $row, array_flip( array( 'name', 'legal_name', 'email', 'phone', 'website', 'industry', 'type', 'territory', 'tax_number', 'currency', 'timezone', 'language', 'description' ) ) );
			$existing = $service->repository()->first( array( 'name' => $name ) );
			try {
				if ( $existing && empty( $existing['deleted_at'] ) && $update ) {
					$service->update( $actor, (string) $existing['uid'], $values );
					++$updated;
				} elseif ( $existing && empty( $existing['deleted_at'] ) ) {
					$skipped[] = array(
						'row'    => $index + 1,
						'reason' => __( 'There is already a client with this name.', 'cliezoo' ),
					);
				} else {
					$service->create( $actor, $values );
					++$created;
				}
			} catch ( ActionError $error ) {
				$skipped[] = array(
					'row'    => $index + 1,
					'reason' => $error->getMessage(),
				);
			}
		}
		do_action(
			'cliezoo_audit',
			'clients_imported',
			array(
				'created' => $created,
				'updated' => $updated,
				'skipped' => count( $skipped ),
			)
		);
		return array(
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
		);
	}

	/**
	 * The rows of one kind that belong to a client and this person may see.
	 *
	 * @param Actor  $actor     Who is asking.
	 * @param string $entity    Entity.
	 * @param int    $client_id Client id.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_of( Actor $actor, string $entity, int $client_id ): array {
		$definition = $this->plugin->entities()->get( $entity );
		$policy     = $this->plugin->get( Policy::class );
		$out        = array();
		foreach ( $this->plugin->service( $entity )->repository()->where( array( $definition->client_field => $client_id ) ) as $row ) {
			if ( empty( $row['deleted_at'] ) && $policy->can( $actor, Policy::VIEW, $definition, $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}
}
