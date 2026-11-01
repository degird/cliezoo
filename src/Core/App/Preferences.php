<?php
/**
 * Per-user app preferences.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme, density, sidebar layout (collapsed, hidden and ordered items) and
 * the quick-access panel, stored in one user meta row and returned in the
 * boot data (Blueprint §12.2, §12.4).
 */
final class Preferences {

	public const META = 'cliezoo_prefs';

	/**
	 * How many recent searches and records are kept.
	 */
	private const RECENT = 10;

	/**
	 * Sidebar items that cannot be hidden.
	 */
	public const ALWAYS_SHOWN = array( 'home', 'inbox' );

	/**
	 * Input schema (every key optional; PATCH merges).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		$ids = array(
			'type'     => 'array',
			'maxItems' => 100,
			'items'    => array(
				'type'    => 'string',
				'pattern' => '^[a-z0-9_.\-]{1,60}$',
			),
		);
		return array(
			'theme'             => array(
				'type' => 'string',
				'enum' => array( 'light', 'dark', 'system' ),
			),
			'density'           => array(
				'type' => 'string',
				'enum' => array( 'comfortable', 'compact' ),
			),
			'sidebar_collapsed' => array( 'type' => 'boolean' ),
			'quick_access_open' => array( 'type' => 'boolean' ),
			'nav_hidden'        => $ids,
			'nav_order'         => $ids,
			'reduce_motion'     => array( 'type' => 'boolean' ),
			'tour_done'         => array( 'type' => 'boolean' ),
			'recent_searches'   => array(
				'type'     => 'array',
				'maxItems' => self::RECENT,
				'items'    => array(
					'type'      => 'string',
					'maxLength' => 80,
				),
			),
			'recent_records'    => array(
				'type'     => 'array',
				'maxItems' => self::RECENT,
				'items'    => array(
					'type'    => 'string',
					'pattern' => '^[a-z_]{2,40}:[0-9A-HJKMNP-TV-Z]{26}$',
				),
			),
		);
	}

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'theme'             => 'system',
			'density'           => 'comfortable',
			'sidebar_collapsed' => false,
			'quick_access_open' => true,
			'nav_hidden'        => array(),
			'nav_order'         => array(),
			'reduce_motion'     => false,
			'tour_done'         => false,
			'recent_searches'   => array(),
			'recent_records'    => array(),
		);
	}

	/**
	 * Preferences of a user.
	 *
	 * @param int $user_id User id.
	 * @return array<string, mixed>
	 */
	public static function get( int $user_id ): array {
		$saved = $user_id ? get_user_meta( $user_id, self::META, true ) : array();
		return array_merge( self::defaults(), array_intersect_key( is_array( $saved ) ? $saved : array(), self::defaults() ) );
	}

	/**
	 * Merges validated values.
	 *
	 * @param int                  $user_id User id.
	 * @param array<string, mixed> $values  Validated input.
	 * @return array<string, mixed>
	 */
	public static function update( int $user_id, array $values ): array {
		$prefs = array_merge( self::get( $user_id ), array_intersect_key( $values, self::defaults() ) );
		foreach ( array( 'nav_hidden', 'nav_order' ) as $list ) {
			$prefs[ $list ] = array_values( array_unique( array_map( 'strval', (array) $prefs[ $list ] ) ) );
		}
		// Home and Inbox are how a person finds their way back and hears
		// about their work; they can be moved, never hidden (cliezoo.md §6.1).
		$prefs['nav_hidden'] = array_values( array_diff( $prefs['nav_hidden'], self::ALWAYS_SHOWN ) );
		// Recents are newest first, de-duplicated and capped.
		foreach ( array( 'recent_searches', 'recent_records' ) as $list ) {
			$clean          = array_map( static fn( $value ): string => mb_substr( sanitize_text_field( (string) $value ), 0, 80 ), (array) $prefs[ $list ] );
			$prefs[ $list ] = array_slice( array_values( array_unique( array_filter( $clean ) ) ), 0, self::RECENT );
		}
		update_user_meta( $user_id, self::META, $prefs );
		return $prefs;
	}
}
