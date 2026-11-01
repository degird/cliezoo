<?php
/**
 * What a person opened lately.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Dashboard;

use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Serializer;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Home's strip of recent clients and projects, and its table of recent
 * items (cliezoo.md §6.3): each record a person opens is remembered —
 * the last fifty, per person, per site — and read back only if they may
 * still open it.
 */
final class Recents {

	/**
	 * How many are kept.
	 */
	private const KEEP = 50;

	/**
	 * Where they are kept (a user option: per person, per site).
	 */
	private const OPTION = 'cliezoo_recents';

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
	 * Remembers that somebody opened a record.
	 *
	 * @param Entity               $entity The kind of record.
	 * @param array<string, mixed> $row    The record.
	 * @param Actor                $actor  Who opened it.
	 * @return void
	 */
	public function opened( Entity $entity, array $row, Actor $actor ): void {
		// Only what a person opened in the app: an agent reading on their
		// behalf, or an admin looking as them, is not them opening it.
		if ( ! in_array( $actor->channel, array( 'rest', 'app' ), true ) ) {
			return;
		}
		if ( ! $actor->user_id || ! $actor->member_id || ! $entity->public || $entity->object_attached || 'self' === $entity->permission || empty( $row['id'] ) ) {
			return;
		}
		$list = $this->stored( $actor );
		$key  = $entity->name . ':' . (int) $row['id'];
		// Opening the same record again moves it to the front; the write is
		// skipped when it is already there.
		if ( isset( $list[0] ) && $list[0]['k'] === $key ) {
			return;
		}
		$list = array_values( array_filter( $list, static fn( array $one ): bool => $one['k'] !== $key ) );
		array_unshift(
			$list,
			array(
				'k' => $key,
				't' => time(),
			)
		);
		update_user_option( $actor->user_id, self::OPTION, array_slice( $list, 0, self::KEEP ) );
	}

	/**
	 * What is kept for somebody.
	 *
	 * @param Actor $actor Who.
	 * @return array<int, array{k: string, t: int}>
	 */
	private function stored( Actor $actor ): array {
		$list = get_user_option( self::OPTION, $actor->user_id );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $one ) {
			if ( is_array( $one ) && is_string( $one['k'] ?? null ) && preg_match( '/^[a-z_]+:\d+$/', $one['k'] ) ) {
				$out[] = array(
					'k' => $one['k'],
					't' => (int) ( $one['t'] ?? 0 ),
				);
			}
		}
		return $out;
	}

	/**
	 * The records somebody opened lately that they may still open.
	 *
	 * @param Actor              $actor Who.
	 * @param array<int, string> $types Only these kinds (empty: any).
	 * @param int                $limit How many.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( Actor $actor, array $types, int $limit ): array {
		$out      = array();
		$entities = $this->plugin->entities();
		$policy   = $this->plugin->policy();
		foreach ( $this->stored( $actor ) as $one ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			list( $type, $id ) = explode( ':', $one['k'] );
			if ( ( $types && ! in_array( $type, $types, true ) ) || ! $entities->has( $type ) ) {
				continue;
			}
			$entity = $entities->get( $type );
			$row    = $this->plugin->service( $type )->repository()->find( (int) $id );
			if ( ! $row || ! empty( $row['deleted_at'] ) || ! $policy->can( $actor, Policy::VIEW, $entity, $row ) ) {
				continue;
			}
			$title = '' !== $entity->title_field ? (string) ( $row[ $entity->title_field ] ?? '' ) : '';
			$out[] = array(
				'type'       => $type,
				'kind'       => '' !== $entity->label ? $entity->label : $type,
				'icon'       => $entity->icon,
				'uid'        => (string) $row['uid'],
				'label'      => wp_strip_all_tags( $title ),
				'number'     => $row['number'] ?? null,
				'color'      => $row['color'] ?? null,
				'visibility' => $entity->shareable ? (string) ( $row['access'] ?? 'inherit' ) : '',
				'created_at' => Serializer::iso( (string) ( $row['created_at'] ?? '' ) ),
				'opened_at'  => gmdate( 'c', $one['t'] ),
				'count'      => $this->count( $actor, $type, (int) $row['id'] ),
			);
		}
		return $out;
	}

	/**
	 * What a client or a project holds, for the strip: its active projects,
	 * its open tasks.
	 *
	 * @param Actor  $actor Who is asking.
	 * @param string $type  The kind of record.
	 * @param int    $id    The record.
	 * @return int|null
	 */
	private function count( Actor $actor, string $type, int $id ): ?int {
		$metrics = $this->plugin->get( Metrics::class );
		if ( 'clients' === $type ) {
			return $metrics->count( $actor, 'projects', "t.client_id = %d AND t.status = 'active'", array( $id ) );
		}
		if ( 'projects' === $type ) {
			return $metrics->count( $actor, 'tasks', 't.project_id = %d AND t.completed_at IS NULL AND t.parent_id IS NULL AND t.archived_at IS NULL', array( $id ) );
		}
		return null;
	}
}
