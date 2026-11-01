<?php
/**
 * Dashboard widgets declared on the server.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\App;

use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which cards a person may have on their home screen (Blueprint §19.1).
 * The server decides availability — module on or off, role, permission —
 * and the browser decides what each card looks like, through the matching
 * `registerWidget()` in the app bundle.
 */
final class WidgetRegistry {

	/**
	 * Widgets by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $widgets = array();

	/**
	 * Declares a widget.
	 *
	 * @param string               $id     Widget id (matches registerWidget in JS).
	 * @param array<string, mixed> $widget title, size (sm|md|lg), order, audience, allow (callable),
	 *                                     place (home or dashboard).
	 * @return void
	 */
	public function add( string $id, array $widget ): void {
		$this->widgets[ $id ] = $widget + array(
			'title'    => $id,
			'size'     => 'sm',
			'order'    => 50,
			'audience' => 'staff',
			'allow'    => null,
			'place'    => 'home',
		);
	}

	/**
	 * The widgets this person may have, in order.
	 *
	 * @param Actor $actor Actor.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_actor( Actor $actor ): array {
		$out = array();
		foreach ( $this->widgets as $id => $widget ) {
			$audience = (string) $widget['audience'];
			if ( 'staff' === $audience && ! $actor->is_staff() ) {
				continue;
			}
			if ( 'client' === $audience && ! $actor->is_client() ) {
				continue;
			}
			$allow = $widget['allow'];
			if ( is_callable( $allow ) && ! $allow( $actor ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'title' => (string) $widget['title'],
				'size'  => (string) $widget['size'],
				'order' => (int) $widget['order'],
				'place' => (string) $widget['place'],
			);
		}
		usort( $out, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );
		return $out;
	}
}
