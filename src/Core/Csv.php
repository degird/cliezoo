<?php
/**
 * A CSV line a spreadsheet opens safely.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cells quoted, and anything a spreadsheet would run made into words.
 *
 * A cell beginning with =, +, -, @, a tab or a carriage return is prefixed
 * with a single quote: spreadsheets treat all of those as the start of a
 * formula, and a name somebody typed into a form on a website is not a
 * formula this workspace should run on whoever opens the export.
 */
final class Csv {

	/**
	 * One line.
	 *
	 * @param array<int, mixed> $cells The cells.
	 * @return string
	 */
	public static function line( array $cells ): string {
		$out = array();
		foreach ( $cells as $cell ) {
			$cell  = is_scalar( $cell ) || null === $cell ? (string) $cell : (string) wp_json_encode( $cell );
			$first = substr( $cell, 0, 1 );
			if ( in_array( $first, array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
				$cell = "'" . $cell;
			}
			$out[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}
		return implode( ',', $out );
	}
}
