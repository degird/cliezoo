<?php
/**
 * Upload validation and SVG sanitising.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Storage;

use Cliezoo\Core\Actions\ActionError;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Extension allowlist + content sniffing (finfo) + script-type blocking + an
 * allowlist SVG sanitiser + the `cliezoo_file_scan` virus-scan hook
 * (Blueprint §14.4, §31.5).
 *
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM API properties.
 */
final class FileValidator {

	/**
	 * Allowed extensions → expected MIME prefix ('' = any non-script type).
	 */
	private const ALLOWED = array(
		'jpg'     => 'image/',
		'jpeg'    => 'image/',
		'png'     => 'image/',
		'gif'     => 'image/',
		'webp'    => 'image/',
		'avif'    => 'image/',
		'heic'    => '',
		'bmp'     => 'image/',
		'tif'     => 'image/',
		'tiff'    => 'image/',
		'ico'     => 'image/',
		'svg'     => '',
		'pdf'     => 'application/pdf',
		'doc'     => '',
		'docx'    => '',
		'xls'     => '',
		'xlsx'    => '',
		'ppt'     => '',
		'pptx'    => '',
		'odt'     => '',
		'ods'     => '',
		'odp'     => '',
		'rtf'     => '',
		'txt'     => 'text/',
		'md'      => 'text/',
		'csv'     => '',
		'json'    => '',
		'xml'     => '',
		'zip'     => '',
		'rar'     => '',
		'7z'      => '',
		'gz'      => '',
		'tar'     => '',
		'mp4'     => '',
		'mov'     => '',
		'webm'    => '',
		'm4v'     => '',
		'avi'     => '',
		'mkv'     => '',
		'mp3'     => '',
		'wav'     => '',
		'm4a'     => '',
		'ogg'     => '',
		'oga'     => '',
		'aac'     => '',
		'flac'    => '',
		'psd'     => '',
		'ai'      => '',
		'eps'     => '',
		'xd'      => '',
		'fig'     => '',
		'sketch'  => '',
		'indd'    => '',
		'ttf'     => '',
		'otf'     => '',
		'woff'    => '',
		'woff2'   => '',
		'key'     => '',
		'pages'   => '',
		'numbers' => '',
		'vtt'     => 'text/',
		'srt'     => '',
	);

	/**
	 * MIME types that are never accepted, whatever the extension says.
	 */
	private const BLOCKED_MIME = array( 'text/html', 'application/xhtml+xml', 'application/x-httpd-php', 'text/x-php', 'application/x-php', 'text/javascript', 'application/javascript', 'application/x-sh', 'text/x-shellscript', 'application/x-msdownload', 'application/x-dosexec', 'application/java-archive' );

	/**
	 * Validates a stored upload. Sanitises SVG in place.
	 *
	 * @param string $path Local path of the assembled file.
	 * @param string $name Original file name.
	 * @return array{mime: string, extension: string}
	 * @throws ActionError When the file is not allowed.
	 */
	public static function validate( string $path, string $name ): array {
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		/**
		 * Allowed upload extensions.
		 *
		 * @param array $allowed Extension => expected MIME prefix.
		 */
		$allowed = (array) apply_filters( 'cliezoo_allowed_extensions', self::ALLOWED );
		if ( '' === $extension || ! array_key_exists( $extension, $allowed ) ) {
			throw ActionError::validation( __( 'This type of file is not allowed.', 'cliezoo' ) );
		}
		$mime = self::sniff( $path );
		if ( in_array( $mime, self::BLOCKED_MIME, true ) && 'svg' !== $extension ) {
			throw ActionError::validation( __( 'This file looks like a script and cannot be uploaded.', 'cliezoo' ) );
		}
		$expected = (string) $allowed[ $extension ];
		if ( '' !== $expected && ! str_starts_with( $mime, $expected ) ) {
			throw ActionError::validation( __( 'The file content does not match its extension.', 'cliezoo' ) );
		}
		if ( 'svg' === $extension ) {
			self::sanitize_svg_file( $path );
			$mime = 'image/svg+xml';
		}
		if ( self::looks_like_php( $path ) && ! in_array( $extension, array( 'txt', 'md', 'csv', 'json', 'xml' ), true ) ) {
			throw ActionError::validation( __( 'This file contains code and cannot be uploaded.', 'cliezoo' ) );
		}
		/**
		 * Virus-scan hook (e.g. ClamAV). Return false to reject.
		 *
		 * @param bool   $clean True when clean.
		 * @param string $path  File path.
		 * @param string $name  Original name.
		 */
		if ( ! apply_filters( 'cliezoo_file_scan', true, $path, $name ) ) {
			throw ActionError::validation( __( 'The file did not pass the security scan.', 'cliezoo' ) );
		}
		return array(
			'mime'      => $mime,
			'extension' => $extension,
		);
	}

	/**
	 * Detects the real MIME type.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function sniff( string $path ): string {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = (string) finfo_file( $finfo, $path );
				finfo_close( $finfo );
				return $mime;
			}
		}
		return 'application/octet-stream';
	}

	/**
	 * Detects PHP open tags near the start of a file (polyglot uploads).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function looks_like_php( string $path ): bool {
		$head = (string) file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file header check.
		return 1 === preg_match( '/<\?php|<\?=|<script\s+language\s*=\s*["\']?php/i', $head );
	}

	/**
	 * Sanitises an SVG file in place.
	 *
	 * @param string $path Path.
	 * @return void
	 * @throws ActionError When the SVG cannot be parsed.
	 */
	public static function sanitize_svg_file( string $path ): void {
		$clean = self::sanitize_svg( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( null === $clean ) {
			throw ActionError::validation( __( 'The SVG file could not be read safely.', 'cliezoo' ) );
		}
		file_put_contents( $path, $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Rewrites the sanitised upload in the private folder.
	}

	/**
	 * Allowlist SVG sanitiser: removes scripts, event handlers, foreign
	 * objects, external references and javascript: URLs.
	 *
	 * @param string $svg SVG markup.
	 * @return string|null Clean markup, or null when unparsable.
	 */
	public static function sanitize_svg( string $svg ): ?string {
		$elements   = array( 'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'textpath', 'defs', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'title', 'desc', 'symbol', 'use', 'marker', 'filter', 'fegaussianblur', 'feoffset', 'feblend', 'fecolormatrix', 'femerge', 'femergenode', 'feflood', 'fecomposite', 'metadata' );
		$attributes = array( 'id', 'class', 'd', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'width', 'height', 'viewbox', 'preserveaspectratio', 'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity', 'transform', 'points', 'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform', 'spreadmethod', 'fx', 'fy', 'clip-path', 'clip-rule', 'mask', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor', 'dominant-baseline', 'letter-spacing', 'xmlns', 'xmlns:xlink', 'version', 'href', 'xlink:href', 'patternunits', 'patterncontentunits', 'markerwidth', 'markerheight', 'refx', 'refy', 'orient', 'stddeviation', 'dx', 'dy', 'in', 'in2', 'result', 'mode', 'values', 'type', 'operator', 'flood-color', 'flood-opacity', 'style', 'display', 'visibility', 'color' );
		if ( ! class_exists( '\DOMDocument' ) || '' === trim( $svg ) || str_contains( strtolower( $svg ), '<!entity' ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$loaded   = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOBLANKS );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $dom->documentElement || 'svg' !== strtolower( $dom->documentElement->localName ) ) {
			return null;
		}
		$remove = array();
		foreach ( $dom->getElementsByTagName( '*' ) as $node ) {
			if ( ! in_array( strtolower( $node->localName ), $elements, true ) ) {
				$remove[] = $node;
				continue;
			}
			$drop = array();
			foreach ( $node->attributes as $attribute ) {
				$name  = strtolower( $attribute->nodeName );
				$value = strtolower( preg_replace( '/[\s\x00-\x1f]+/', '', (string) $attribute->nodeValue ) );
				$bad   = ! in_array( $name, $attributes, true )
					|| str_starts_with( $name, 'on' )
					|| str_contains( $value, 'javascript:' )
					|| str_contains( $value, 'expression(' )
					|| ( in_array( $name, array( 'href', 'xlink:href' ), true ) && ! str_starts_with( $value, '#' ) )
					|| ( 'style' === $name && ( str_contains( $value, 'url(' ) || str_contains( $value, '@import' ) ) );
				if ( $bad ) {
					$drop[] = $attribute->nodeName;
				}
			}
			foreach ( $drop as $name ) {
				$node->removeAttribute( $name );
			}
		}
		foreach ( $remove as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
		$out = $dom->saveXML( $dom->documentElement );
		return false === $out ? null : $out;
	}

	/**
	 * Safe display name: no paths, control characters or dangerous suffixes (§31.5).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function display_name( string $name ): string {
		$name = wp_basename( str_replace( '\\', '/', $name ) );
		$name = (string) preg_replace( '/[\x00-\x1f\x7f]/u', '', $name );
		$name = trim( sanitize_text_field( $name ) );
		// "." and ".." are not names, they are places. Left alone they walk
		// into a stored path, a zip entry and a Content-Disposition.
		if ( '' === $name || '.' === $name || '..' === $name ) {
			$name = 'file';
		}
		return mb_substr( $name, 0, 200 );
	}
}
