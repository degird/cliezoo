<?php
/**
 * Keeps script and style optimisers away from Cliezoo's files.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Health;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minify, combine and "delay JavaScript" features of optimisation plugins
 * break the app: its bundles load in order, lazily, with a CSP nonce, and a
 * combined or delayed copy runs out of order or not at all. App pages set
 * DONOTMINIFY and DONOTROCKETOPTIMIZE already; these filters, which the
 * plugins themselves document, also keep Cliezoo's files out wherever they
 * load (the portal, an embed, a block). Each filter only adds to the list
 * it is given and does nothing when its plugin is not there to call it.
 */
final class OptimiserExclusions {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		// WP Rocket: path patterns.
		foreach ( array( 'rocket_exclude_js', 'rocket_exclude_defer_js', 'rocket_delay_js_exclusions', 'rocket_exclude_css' ) as $hook ) {
			add_filter( $hook, array( self::class, 'add_paths' ) );
		}
		// LiteSpeed Cache: substrings of the file address.
		foreach ( array( 'litespeed_optimize_js_excludes', 'litespeed_optm_js_defer_exc', 'litespeed_optm_gm_js_exc', 'litespeed_optimize_css_excludes' ) as $hook ) {
			add_filter( $hook, array( self::class, 'add_fragments' ) );
		}
		// SiteGround Speed Optimizer: script and style handles.
		foreach ( array( 'sgo_js_minify_exclude', 'sgo_javascript_combine_exclude', 'sgo_js_async_exclude', 'sgo_css_minify_exclude', 'sgo_css_combine_exclude' ) as $hook ) {
			add_filter( $hook, array( self::class, 'add_handles' ) );
		}
		// Autoptimize: a comma-separated list of substrings.
		add_filter( 'autoptimize_filter_js_exclude', array( self::class, 'add_to_list' ) );
		add_filter( 'autoptimize_filter_css_exclude', array( self::class, 'add_to_list' ) );
		// Hummingbird: asked per resource.
		add_filter( 'wphb_minify_resource', array( self::class, 'leave_ours' ), 10, 4 );
		add_filter( 'wphb_combine_resource', array( self::class, 'leave_ours' ), 10, 4 );
	}

	/**
	 * The part of the address every Cliezoo file shares.
	 *
	 * @return string
	 */
	public static function fragment(): string {
		$path = (string) wp_parse_url( plugins_url( 'build/', CLIEZOO_FILE ), PHP_URL_PATH );
		return '' !== $path ? $path : '/cliezoo/build/';
	}

	/**
	 * Adds a path pattern for WP Rocket.
	 *
	 * @param mixed $given Patterns so far.
	 * @return array<int, string>
	 */
	public static function add_paths( $given ): array {
		$given   = is_array( $given ) ? $given : array();
		$given[] = self::fragment() . '(.*)';
		return array_values( array_unique( array_map( 'strval', $given ) ) );
	}

	/**
	 * Adds the address fragment for LiteSpeed Cache.
	 *
	 * @param mixed $given Fragments so far.
	 * @return array<int, string>
	 */
	public static function add_fragments( $given ): array {
		$given   = is_array( $given ) ? $given : ( is_string( $given ) && '' !== $given ? preg_split( '/[\r\n]+/', $given ) : array() );
		$given   = (array) $given;
		$given[] = self::fragment();
		return array_values( array_unique( array_map( 'strval', $given ) ) );
	}

	/**
	 * Adds every registered Cliezoo handle, for SiteGround.
	 *
	 * @param mixed $given Handles so far.
	 * @return array<int, string>
	 */
	public static function add_handles( $given ): array {
		$given = is_array( $given ) ? $given : array();
		return array_values( array_unique( array_merge( array_map( 'strval', $given ), self::handles() ) ) );
	}

	/**
	 * Adds the address fragment to Autoptimize's comma list.
	 *
	 * @param mixed $given The list so far.
	 * @return string
	 */
	public static function add_to_list( $given ): string {
		$given = trim( (string) $given );
		if ( str_contains( $given, self::fragment() ) ) {
			return $given;
		}
		return '' === $given ? self::fragment() : $given . ', ' . self::fragment();
	}

	/**
	 * Leaves a Cliezoo file alone (Hummingbird).
	 *
	 * @param mixed  $action Whether to process it.
	 * @param string $handle Handle.
	 * @param string $type   scripts|styles.
	 * @param string $url    Address.
	 * @return mixed
	 */
	public static function leave_ours( $action, $handle = '', $type = '', $url = '' ) {
		unset( $type );
		if ( str_starts_with( (string) $handle, 'cliezoo' ) || str_contains( (string) $url, self::fragment() ) ) {
			return false;
		}
		return $action;
	}

	/**
	 * Registered script and style handles that are Cliezoo's.
	 *
	 * @return string[]
	 */
	public static function handles(): array {
		$out = array();
		foreach ( array( wp_scripts(), wp_styles() ) as $registry ) {
			foreach ( array_keys( (array) $registry->registered ) as $handle ) {
				if ( str_starts_with( (string) $handle, 'cliezoo' ) ) {
					$out[] = (string) $handle;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}
}
