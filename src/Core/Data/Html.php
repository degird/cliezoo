<?php
/**
 * Rich-text sanitiser.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Cliezoo HTML allowlist (Blueprint §17.2): formatting, links, lists,
 * tables, images and code. No scripts, iframes, forms, styles or event handlers.
 */
final class Html {

	/**
	 * Allowed tags and attributes.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed(): array {
		$common = array(
			'class'         => true,
			'dir'           => true,
			'data-type'     => true,
			'data-id'       => true,
			'data-mention'  => true,
			'data-checked'  => true,
			'data-color'    => true,
			'data-language' => true,
		);
		$tags   = array( 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'sup', 'sub', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'span', 'div', 'mark', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'details', 'summary', 'figure', 'figcaption', 'label', 'input' );
		$out    = array();
		foreach ( $tags as $tag ) {
			$out[ $tag ] = $common;
		}
		$out['a']     = array_merge(
			$common,
			array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			)
		);
		$out['img']   = array_merge(
			$common,
			array(
				'src'    => true,
				'alt'    => true,
				'width'  => true,
				'height' => true,
				'title'  => true,
			)
		);
		$out['td']    = array_merge(
			$common,
			array(
				'colspan' => true,
				'rowspan' => true,
			)
		);
		$out['th']    = $out['td'];
		$out['ol']    = array_merge( $common, array( 'start' => true ) );
		$out['input'] = array(
			'type'     => true,
			'checked'  => true,
			'disabled' => true,
		);
		$out['span']  = array_merge( $common, array( 'style' => true ) );
		$out['mark']  = array_merge( $common, array( 'style' => true ) );
		return $out;
	}

	/**
	 * Cleans rich text.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function clean( string $html ): string {
		$clean = wp_kses( $html, self::allowed(), array( 'http', 'https', 'mailto', 'tel' ) );
		// Only checkbox inputs survive (task lists).
		$clean = (string) preg_replace( '/<input(?![^>]*type="checkbox")[^>]*>/i', '', $clean );
		return $clean;
	}
}
