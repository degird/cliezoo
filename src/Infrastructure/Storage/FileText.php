<?php
/**
 * The words inside a file: plain text, PDF, Word, Excel and PowerPoint.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read on this site with nothing sent anywhere and no library to install:
 * a PDF's text layer (compressed streams, object streams and the fonts'
 * ToUnicode maps, which is how browsers and office programs write PDFs),
 * and the XML inside Word, Excel and PowerPoint files. A scan or a photo
 * has no text layer; reading those needs OCR, which is not done here.
 */
final class FileText {

	/**
	 * The types it reads.
	 */
	public const READS = array(
		'text/plain',
		'text/markdown',
		'text/csv',
		'application/json',
		'application/pdf',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	);

	/**
	 * Largest file read, in bytes.
	 */
	public const MOST_BYTES = 20 * 1024 * 1024;

	/**
	 * Most characters returned.
	 */
	public const MOST_CHARS = 200000;

	/**
	 * The most one compressed part may grow to when opened, and the most a
	 * whole file may, in bytes: a small file can be made to open into
	 * gigabytes (a "decompression bomb").
	 */
	public const MOST_OPENED      = 8 * 1024 * 1024;
	public const MOST_OPENED_FILE = 64 * 1024 * 1024;

	/**
	 * What the file being read has opened so far.
	 *
	 * @var int
	 */
	private static int $opened = 0;

	/**
	 * What the last file read opened, in bytes.
	 *
	 * @return int
	 */
	public static function opened(): int {
		return self::$opened;
	}

	/**
	 * The words of a local file, or ''.
	 *
	 * @param string $path File.
	 * @param string $mime Its type.
	 * @return string
	 */
	public static function of( string $path, string $mime ): string {
		if ( ! is_readable( $path ) || (int) filesize( $path ) > self::MOST_BYTES ) {
			return '';
		}
		self::$opened = 0;
		switch ( $mime ) {
			case 'application/pdf':
				$text = self::pdf( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file, size checked.
				break;
			case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
				$text = self::office(
					$path,
					'#^word/document\.xml$#',
					array(
						'</w:p>'   => "\n",
						'<w:tab/>' => "\t",
					)
				);
				break;
			case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
				$text = self::office( $path, '#^xl/sharedStrings\.xml$#', array( '</si>' => "\n" ) );
				break;
			case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
				$text = self::office( $path, '#^ppt/slides/slide\d+\.xml$#', array( '</a:p>' => "\n" ) );
				break;
			default:
				$text = in_array( $mime, self::READS, true ) ? (string) file_get_contents( $path, false, null, 0, self::MOST_CHARS * 4 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file, size checked.
		}
		$text = (string) wp_check_invalid_utf8( $text, true );
		$text = trim( (string) preg_replace( "/[ \t]+/u", ' ', (string) preg_replace( "/\n{3,}/", "\n\n", str_replace( "\r", '', $text ) ) ) );
		return mb_substr( $text, 0, self::MOST_CHARS );
	}

	/**
	 * The text of the XML parts of an Office file.
	 *
	 * @param string                $path   File.
	 * @param string                $parts  Which parts (a pattern).
	 * @param array<string, string> $breaks Tags that end a line.
	 * @return string
	 */
	private static function office( string $path, string $parts, array $breaks ): string {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return '';
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return '';
		}
		$found = array();
		$count = min( 2000, (int) $zip->count() );
		for ( $i = 0; $i < $count; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( preg_match( $parts, $name ) ) {
				$found[] = $name;
			}
		}
		natsort( $found );
		$out = '';
		foreach ( $found as $name ) {
			$size = (int) ( $zip->statName( $name )['size'] ?? 0 );
			// Enough text, or too much opened: stop.
			if ( mb_strlen( $out ) > 4 * self::MOST_CHARS || self::$opened + min( $size, self::MOST_OPENED ) > self::MOST_OPENED_FILE ) {
				break;
			}
			$xml           = (string) $zip->getFromName( $name, self::MOST_OPENED );
			self::$opened += strlen( $xml );
			$out          .= html_entity_decode( wp_strip_all_tags( strtr( $xml, $breaks ) ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) . "\n";
		}
		$zip->close();
		return $out;
	}

	/**
	 * A PDF's text layer, page by page.
	 *
	 * @param string $pdf The file's bytes.
	 * @return string
	 */
	public static function pdf( string $pdf ): string {
		$objects = self::objects( $pdf );
		$pages   = array();
		$root    = preg_match( '#/Root\s+(\d+)\s+\d+\s+R#', $pdf, $found ) ? (int) $found[1] : 0;
		if ( $root && isset( $objects[ $root ] ) && preg_match( '#/Pages\s+(\d+)\s+\d+\s+R#', $objects[ $root ]['dict'], $tree ) ) {
			self::pages( $objects, (int) $tree[1], $pages, 0 );
		}
		if ( ! $pages ) {
			// No usable page tree: every page object, in the order written.
			foreach ( $objects as $id => $object ) {
				if ( preg_match( '#/Type\s*/Page\b(?!s)#', $object['dict'] ) ) {
					$pages[] = $id;
				}
			}
		}
		$out = array();
		foreach ( $pages as $page ) {
			$fonts   = self::fonts( $objects, $page );
			$content = '';
			foreach ( self::refs( self::value( $objects[ $page ]['dict'], 'Contents' ) ) as $ref ) {
				$content .= self::stream( $objects[ $ref ] ?? null ) . "\n";
			}
			$out[] = self::show( $content, $fonts );
			if ( strlen( implode( "\n", $out ) ) > self::MOST_CHARS * 2 ) {
				break;
			}
		}
		return implode( "\n\n", array_filter( array_map( 'trim', $out ) ) );
	}

	/**
	 * Every object: number => dictionary and raw stream; objects packed in
	 * object streams included.
	 *
	 * @param string $pdf The file's bytes.
	 * @return array<int, array{dict: string, stream: string|null}>
	 */
	private static function objects( string $pdf ): array {
		$objects = array();
		preg_match_all( '#(\d+)\s+(\d+)\s+obj\b(.*?)\bendobj#s', $pdf, $found, PREG_SET_ORDER );
		foreach ( $found as $one ) {
			$body   = $one[3];
			$stream = null;
			if ( preg_match( '#^(.*?)\bstream\r?\n(.*)$#s', $body, $split ) ) {
				$body   = $split[1];
				$stream = (string) preg_replace( '#\r?\n?endstream\s*$#s', '', rtrim( $split[2] ) );
			}
			$objects[ (int) $one[1] ] = array(
				'dict'   => $body,
				'stream' => $stream,
			);
		}
		// Objects kept inside object streams (PDF 1.5 and later).
		foreach ( $objects as $object ) {
			if ( null === $object['stream'] || ! preg_match( '#/Type\s*/ObjStm#', $object['dict'] ) ) {
				continue;
			}
			$data  = self::stream( $object );
			$first = (int) self::value( $object['dict'], 'First' );
			$count = (int) self::value( $object['dict'], 'N' );
			$pairs = preg_split( '/\s+/', trim( substr( $data, 0, $first ) ) );
			for ( $i = 0; $i < $count && isset( $pairs[ 2 * $i + 1 ] ); $i++ ) {
				$start = $first + (int) $pairs[ 2 * $i + 1 ];
				$end   = isset( $pairs[ 2 * $i + 3 ] ) ? $first + (int) $pairs[ 2 * $i + 3 ] : strlen( $data );
				$id    = (int) $pairs[ 2 * $i ];
				if ( ! isset( $objects[ $id ] ) ) {
					$objects[ $id ] = array(
						'dict'   => substr( $data, $start, $end - $start ),
						'stream' => null,
					);
				}
			}
		}
		return $objects;
	}

	/**
	 * The pages under a node of the page tree, in order.
	 *
	 * @param array<int, array{dict: string, stream: string|null}> $objects Objects.
	 * @param int                                                  $node    Node.
	 * @param int[]                                                $pages   Pages found (appended to).
	 * @param int                                                  $depth   Depth (a loop guard).
	 * @return void
	 */
	private static function pages( array $objects, int $node, array &$pages, int $depth ): void {
		if ( $depth > 30 || ! isset( $objects[ $node ] ) || count( $pages ) > 2000 ) {
			return;
		}
		$dict = $objects[ $node ]['dict'];
		if ( preg_match( '#/Kids\s*\[([^\]]*)\]#', $dict, $kids ) ) {
			foreach ( self::refs( $kids[1] ) as $kid ) {
				self::pages( $objects, $kid, $pages, $depth + 1 );
			}
			return;
		}
		$pages[] = $node;
	}

	/**
	 * A key's value in a dictionary: a number, a name, a reference or an
	 * array, as written.
	 *
	 * @param string $dict Dictionary.
	 * @param string $key  Key.
	 * @return string
	 */
	private static function value( string $dict, string $key ): string {
		if ( ! preg_match( '#/' . preg_quote( $key, '#' ) . '(?![^\s/<>\[\]()])\s*#', $dict, $found, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}
		$at = (int) $found[0][1] + strlen( $found[0][0] );
		if ( '<<' === substr( $dict, $at, 2 ) || '[' === substr( $dict, $at, 1 ) ) {
			// A dictionary or an array, which may hold others: to its own end.
			$depth = 0;
			for ( $i = $at, $n = strlen( $dict ); $i < $n; $i++ ) {
				$two = substr( $dict, $i, 2 );
				if ( '<<' === $two || '[' === $dict[ $i ] ) {
					++$depth;
					$i += '<<' === $two ? 1 : 0;
				} elseif ( '>>' === $two || ']' === $dict[ $i ] ) {
					--$depth;
					$i += '>>' === $two ? 1 : 0;
					if ( 0 === $depth ) {
						return substr( $dict, $at, $i - $at + 1 );
					}
				}
			}
			return substr( $dict, $at );
		}
		return preg_match( '#^(\d+\s+\d+\s+R|/[^\s/<>\[\]()]+|-?[\d.]+)#', substr( $dict, $at ), $value ) ? $value[1] : '';
	}

	/**
	 * The object numbers referred to in a value ("12 0 R", "[4 0 R 7 0 R]").
	 *
	 * @param string $value Value.
	 * @return int[]
	 */
	private static function refs( string $value ): array {
		preg_match_all( '#(\d+)\s+\d+\s+R#', $value, $found );
		return array_map( 'intval', $found[1] );
	}

	/**
	 * An object's stream, decompressed; '' for one it cannot read (images
	 * and filters other than Flate).
	 *
	 * @param array{dict: string, stream: string|null}|null $item Object.
	 * @return string
	 */
	private static function stream( ?array $item ): string {
		if ( null === $item || null === $item['stream'] ) {
			return '';
		}
		$filter = self::value( $item['dict'], 'Filter' );
		if ( '' === $filter ) {
			return $item['stream'];
		}
		if ( ! in_array( trim( $filter, '[] ' ), array( '/FlateDecode', '/Fl' ), true ) ) {
			return '';
		}
		$room = min( self::MOST_OPENED, self::MOST_OPENED_FILE - self::$opened );
		if ( $room <= 0 ) {
			return '';
		}
		// Opened only so far: a stream that would grow past it is skipped.
		$data = self::inflate( $item['stream'], ZLIB_ENCODING_DEFLATE, $room );
		if ( null === $data ) {
			$data = self::inflate( substr( $item['stream'], 2 ), ZLIB_ENCODING_RAW, $room );
		}
		if ( null === $data ) {
			return '';
		}
		self::$opened += strlen( $data );
		return $data;
	}

	/**
	 * Opens compressed bytes a little at a time, giving up as soon as they
	 * grow past the room there is.
	 *
	 * @param string $bytes    Compressed bytes.
	 * @param int    $encoding ZLIB_ENCODING_DEFLATE (zlib) or ZLIB_ENCODING_RAW.
	 * @param int    $room     Most it may grow to.
	 * @return string|null Null when damaged or too big.
	 */
	private static function inflate( string $bytes, int $encoding, int $room ): ?string {
		$stream = @inflate_init( $encoding ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unavailable means unread.
		if ( false === $stream ) {
			return null;
		}
		$out = '';
		// A few kilobytes in can only come out a few megabytes larger.
		foreach ( str_split( $bytes, 4096 ) as $piece ) {
			$more = @inflate_add( $stream, $piece, ZLIB_SYNC_FLUSH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A damaged stream is skipped.
			if ( false === $more ) {
				return '' !== $out ? $out : null;
			}
			$out .= $more;
			if ( strlen( $out ) > $room ) {
				return null;
			}
			if ( ZLIB_STREAM_END === inflate_get_status( $stream ) ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A page's fonts: resource name => code => text, from each font's
	 * ToUnicode map ('' for a font without one, read as WinAnsi).
	 *
	 * @param array<int, array{dict: string, stream: string|null}> $objects Objects.
	 * @param int                                                  $page    Page.
	 * @return array<string, array<string, string>|null>
	 */
	private static function fonts( array $objects, int $page ): array {
		$resources = self::value( $objects[ $page ]['dict'], 'Resources' );
		if ( preg_match( '#^(\d+)\s+\d+\s+R$#', $resources, $ref ) ) {
			$resources = $objects[ (int) $ref[1] ]['dict'] ?? '';
		}
		// Resources inherited from the page tree.
		if ( '' === $resources && preg_match( '#/Parent\s+(\d+)\s+\d+\s+R#', $objects[ $page ]['dict'], $parent ) && isset( $objects[ (int) $parent[1] ] ) ) {
			$resources = self::value( $objects[ (int) $parent[1] ]['dict'], 'Resources' );
			if ( preg_match( '#^(\d+)\s+\d+\s+R$#', $resources, $ref ) ) {
				$resources = $objects[ (int) $ref[1] ]['dict'] ?? '';
			}
		}
		$list = self::value( $resources, 'Font' );
		if ( preg_match( '#^(\d+)\s+\d+\s+R$#', $list, $ref ) ) {
			$list = $objects[ (int) $ref[1] ]['dict'] ?? '';
		}
		$fonts = array();
		preg_match_all( '#/([^\s/<>\[\]()]+)\s+(\d+)\s+\d+\s+R#', $list, $found, PREG_SET_ORDER );
		foreach ( $found as $one ) {
			$font             = $objects[ (int) $one[2] ]['dict'] ?? '';
			$map              = self::refs( self::value( $font, 'ToUnicode' ) );
			$fonts[ $one[1] ] = $map ? self::cmap( self::stream( $objects[ $map[0] ] ?? null ) ) : null;
		}
		return $fonts;
	}

	/**
	 * A ToUnicode map: hex code => text.
	 *
	 * @param string $cmap The map.
	 * @return array<string, string>
	 */
	private static function cmap( string $cmap ): array {
		$map = array();
		preg_match_all( '#beginbfchar(.*?)endbfchar#s', $cmap, $blocks );
		foreach ( $blocks[1] as $block ) {
			preg_match_all( '#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>#', $block, $pairs, PREG_SET_ORDER );
			foreach ( $pairs as $pair ) {
				$map[ strtoupper( $pair[1] ) ] = self::utf16( $pair[2] );
			}
		}
		preg_match_all( '#beginbfrange(.*?)endbfrange#s', $cmap, $blocks );
		foreach ( $blocks[1] as $block ) {
			preg_match_all( '#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]+>|\[[^\]]*\])#', $block, $ranges, PREG_SET_ORDER );
			foreach ( $ranges as $range ) {
				$from  = hexdec( $range[1] );
				$to    = min( hexdec( $range[2] ), $from + 5000 );
				$width = strlen( $range[1] );
				if ( '[' === $range[3][0] ) {
					preg_match_all( '#<([0-9A-Fa-f]+)>#', $range[3], $each );
					foreach ( $each[1] as $i => $hex ) {
						$map[ strtoupper( str_pad( dechex( (int) $from + $i ), $width, '0', STR_PAD_LEFT ) ) ] = self::utf16( $hex );
					}
					continue;
				}
				$start = hexdec( trim( $range[3], '<>' ) );
				for ( $code = $from; $code <= $to; $code++ ) {
					$map[ strtoupper( str_pad( dechex( (int) $code ), $width, '0', STR_PAD_LEFT ) ) ] = self::utf16( dechex( (int) ( $start + $code - $from ) ) );
				}
			}
		}
		return $map;
	}

	/**
	 * UTF-16BE hex as UTF-8 text.
	 *
	 * @param string $hex Hex.
	 * @return string
	 */
	private static function utf16( string $hex ): string {
		$hex = str_pad( $hex, (int) ( ceil( strlen( $hex ) / 4 ) * 4 ), '0', STR_PAD_LEFT );
		return (string) mb_convert_encoding( (string) hex2bin( $hex ), 'UTF-8', 'UTF-16BE' );
	}

	/**
	 * The text a page's content shows.
	 *
	 * @param string                                    $content Content stream.
	 * @param array<string, array<string, string>|null> $fonts   Fonts.
	 * @return string
	 */
	private static function show( string $content, array $fonts ): string {
		$out  = '';
		$font = null;
		// Strings, names, numbers and operators, in order.
		preg_match_all( '#\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]*>|/[^\s/<>\[\]()]+|\[|\]|-?\d*\.?\d+|[A-Za-z\'"*]+#s', $content, $tokens );
		$stack = array();
		foreach ( $tokens[0] as $token ) {
			$first = $token[0];
			if ( '(' === $first || ( '<' === $first && '<<' !== substr( $token, 0, 2 ) ) || '[' === $first || ']' === $first || '/' === $first || is_numeric( $token ) ) {
				$stack[] = $token;
				continue;
			}
			switch ( $token ) {
				case 'Tf':
					$name = ltrim( (string) ( $stack[ count( $stack ) - 2 ] ?? '' ), '/' );
					$font = $fonts[ $name ] ?? null;
					break;
				case 'Tj':
				case "'":
				case '"':
					$out .= ( 'Tj' === $token ? '' : "\n" ) . self::decode( (string) end( $stack ), $font );
					break;
				case 'TJ':
					$start = array_search( '[', array_reverse( $stack, true ), true );
					foreach ( array_slice( $stack, false === $start ? 0 : (int) $start + 1 ) as $part ) {
						if ( is_numeric( $part ) ) {
							// A wide gap between pieces is a space.
							$out .= (float) $part < -200 ? ' ' : '';
						} elseif ( ']' !== $part ) {
							$out .= self::decode( (string) $part, $font );
						}
					}
					break;
				case 'Td':
				case 'TD':
					// A move down is a new line; a move along carries on
					// the line (browsers write their spaces as characters).
					$out .= (float) ( $stack[ count( $stack ) - 1 ] ?? 0 ) !== 0.0 ? "\n" : '';
					break;
				case 'T*':
				case 'ET':
					$out .= "\n";
					break;
				case 'Tm':
					$out .= "\n";
					break;
			}
			$stack = array();
		}
		return $out;
	}

	/**
	 * A string operand as text.
	 *
	 * @param string                     $token The operand.
	 * @param array<string, string>|null $font  The font's map, if any.
	 * @return string
	 */
	private static function decode( string $token, ?array $font ): string {
		if ( '<' === $token[0] ) {
			$hex = strtoupper( (string) preg_replace( '/\s+/', '', trim( $token, '<>' ) ) );
			if ( null === $font ) {
				return self::ansi( (string) hex2bin( strlen( $hex ) % 2 ? $hex . '0' : $hex ) );
			}
			$width  = isset( $font[ substr( $hex, 0, 4 ) ] ) || ! isset( $font[ substr( $hex, 0, 2 ) ] ) ? 4 : 2;
			$out    = '';
			$length = strlen( $hex );
			for ( $i = 0; $i < $length; $i += $width ) {
				$out .= $font[ substr( $hex, $i, $width ) ] ?? '';
			}
			return $out;
		}
		$raw = (string) preg_replace_callback(
			'#\\\\([nrtbf()\\\\]|[0-7]{1,3}|\r?\n)#',
			static function ( array $m ): string {
				$map = array(
					'n'  => "\n",
					'r'  => "\r",
					't'  => "\t",
					'b'  => '',
					'f'  => '',
					'('  => '(',
					')'  => ')',
					'\\' => '\\',
				);
				if ( isset( $map[ $m[1] ] ) ) {
					return $map[ $m[1] ];
				}
				return ctype_digit( $m[1] ) ? chr( (int) octdec( $m[1] ) & 255 ) : '';
			},
			substr( $token, 1, -1 )
		);
		if ( null !== $font ) {
			$out    = '';
			$length = strlen( $raw );
			for ( $i = 0; $i < $length; $i++ ) {
				$out .= $font[ strtoupper( bin2hex( $raw[ $i ] ) ) ] ?? $raw[ $i ];
			}
			return $out;
		}
		return self::ansi( $raw );
	}

	/**
	 * WinAnsi bytes as UTF-8.
	 *
	 * @param string $bytes Bytes.
	 * @return string
	 */
	private static function ansi( string $bytes ): string {
		return (string) mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
	}
}
