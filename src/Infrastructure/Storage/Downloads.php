<?php
/**
 * Protected file downloads.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Storage;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Crypto\Vault;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves private files only after a Policy check or a valid HMAC-signed,
 * expiring URL: Range support, RFC 5987 file names, `nosniff`, SVG and
 * active types forced to download, optional X-Sendfile/X-Accel-Redirect.
 */
final class Downloads {

	private const STREAM_MARKER = '__cliezoo_stream';

	/**
	 * Types that may render inline.
	 */
	private const INLINE = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'application/pdf', 'video/mp4', 'video/webm', 'video/quicktime', 'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/webm', 'text/plain' );

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
	 * `GET /files/{uid}/download` (inline=1 for previews).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws ActionError Caught internally and returned as WP_Error.
	 */
	public function rest_download( \WP_REST_Request $request ) {
		try {
			$actor = $this->plugin->actor();
			$row   = $this->plugin->service( 'files' )->load( $actor, (string) $request['uid'] );
			// A type we cannot show in the browser falls back to serving the
			// whole file, so it needs download rights, not preview rights.
			$inline = ! empty( $request['inline'] ) && in_array( (string) $row['mime'], self::INLINE, true );
			if ( 'ready' !== $row['status'] ) {
				throw ActionError::not_found();
			}
			if ( ! $inline && ! $this->plugin->get( Uploads::class )->can_download( $actor, $row ) ) {
				throw ActionError::forbidden();
			}
			if ( 'upload' !== $row['kind'] ) {
				return new \WP_REST_Response( array( 'data' => array( 'url' => (string) $row['url'] ) ), 200 );
			}
			if ( ! $inline ) {
				$this->plugin->service( 'files' )->repository()->update( (int) $row['id'], array( 'download_count' => (int) $row['download_count'] + 1 ) );
				/**
				 * A file was downloaded (activity, notifications).
				 *
				 * @param array $row   File row.
				 * @param mixed $actor Actor.
				 */
				do_action( 'cliezoo_file_downloaded', $row, $actor );
			}
			return self::stream_response( $row, (string) $row['object_key'], (string) $row['mime'], $inline );
		} catch ( ActionError $error ) {
			return $error->to_wp_error();
		}
	}

	/**
	 * `GET /files/{uid}/thumb/{size}`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws ActionError Caught internally and returned as WP_Error.
	 */
	public function rest_thumb( \WP_REST_Request $request ) {
		try {
			$row    = $this->plugin->service( 'files' )->load( $this->plugin->actor(), (string) $request['uid'] );
			$thumbs = (array) json_decode( (string) $row['thumbs'], true );
			$thumb  = $thumbs[ (string) $request['size'] ] ?? null;
			if ( ! $thumb ) {
				throw ActionError::not_found();
			}
			return self::stream_response( $row, (string) $thumb['key'], (string) $thumb['mime'], true, 'private, max-age=600, no-store' );
		} catch ( ActionError $error ) {
			return $error->to_wp_error();
		}
	}

	/**
	 * A REST response that `serve()` turns into a file stream.
	 *
	 * @param array<string, mixed> $row    File row.
	 * @param string               $key    Object key.
	 * @param string               $mime   MIME type.
	 * @param bool                 $inline Inline display.
	 * @param string               $cache  Cache-Control value.
	 * @return \WP_REST_Response
	 */
	public static function stream_response( array $row, string $key, string $mime, bool $inline, string $cache = 'private, no-store' ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				self::STREAM_MARKER => array(
					'driver' => (string) $row['storage_driver'],
					'key'    => $key,
					'mime'   => $mime,
					'name'   => (string) $row['name'],
					'inline' => $inline,
					'cache'  => $cache,
				),
			),
			200
		);
	}

	/**
	 * `rest_pre_serve_request` filter: streams file responses.
	 *
	 * @param bool                    $served  Whether already served.
	 * @param \WP_HTTP_Response|mixed $result  Result.
	 * @param \WP_REST_Request|mixed  $request Request.
	 * @param \WP_REST_Server|mixed   $server  Server.
	 * @return bool
	 */
	public static function serve( $served, $result, $request, $server ): bool {
		unset( $request, $server );
		if ( $served || ! $result instanceof \WP_HTTP_Response ) {
			return (bool) $served;
		}
		$data = $result->get_data();
		if ( ! is_array( $data ) || ! isset( $data[ self::STREAM_MARKER ] ) ) {
			return false;
		}
		self::output( (array) $data[ self::STREAM_MARKER ] );
		return true;
	}

	/**
	 * Whether the server shows this kind of file in the browser rather than
	 * handing it over to be saved: pictures, PDF, video, audio, plain text.
	 * SVG and HTML are deliberately absent: they can carry script, and would
	 * run on our own origin.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public static function shown( string $mime ): bool {
		return in_array( $mime, self::INLINE, true );
	}

	/**
	 * Whether a MIME type is an image this server will show in the browser.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public static function is_image( string $mime ): bool {
		return str_starts_with( $mime, 'image/' ) && in_array( $mime, self::INLINE, true );
	}

	/**
	 * Sends headers and the (partial) body.
	 *
	 * @param array<string, mixed> $file driver, key, mime, name, inline, cache.
	 * @return void
	 */
	public static function output( array $file ): void {
		$driver = cliezoo()->get( StorageDriverRegistry::class )->get( (string) $file['driver'] );
		if ( $driver instanceof UnavailableDriver ) {
			// Not missing: kept somewhere this site cannot reach right now.
			status_header( 503 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Retry-After: 300' );
			echo esc_html__( 'This file is kept in storage this site cannot reach right now. It has not been lost; tell whoever looks after this site.', 'cliezoo' );
			return;
		}
		$head = $driver->head( (string) $file['key'] );
		if ( null === $head ) {
			// The row says there is a file and the storage says there is
			// not. A blank 404 sends somebody looking for the mistake they
			// made; this says whose mistake it is, and leaves a trace an
			// administrator can find afterwards.
			/**
			 * A file exists as a record but not in storage.
			 *
			 * @param string $key    Object key.
			 * @param string $driver Storage driver.
			 */
			do_action( 'cliezoo_file_missing', (string) $file['key'], (string) $file['driver'] );
			do_action(
				'cliezoo_audit',
				'file_missing',
				array(
					'object_type' => 'files',
					'key'         => (string) $file['key'],
					'driver'      => (string) $file['driver'],
				)
			);
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );
			echo esc_html__( 'This file is listed here, but it is not in storage any more. Nothing you did caused this; tell whoever looks after this site.', 'cliezoo' );
			return;
		}
		$mime   = (string) $file['mime'];
		$inline = ! empty( $file['inline'] ) && in_array( $mime, self::INLINE, true );
		if ( ! $inline ) {
			$direct = $driver->read_url( (string) $file['key'], 300, 'attachment', (string) $file['name'] );
			if ( null !== $direct ) {
				header( 'Location: ' . esc_url_raw( $direct ), true, 302 );
				return;
			}
		}
		$size  = (int) $head['size'];
		$range = self::range( $size );
		header( 'Content-Type: ' . ( $inline ? $mime : 'application/octet-stream' ) );
		header( 'Content-Disposition: ' . self::disposition( (string) $file['name'], $inline ) );
		header( 'X-Content-Type-Options: nosniff' );
		// Nothing a file holds may run on this site. A PDF is the one
		// exception to "sandbox": browsers refuse to show a PDF in a
		// sandboxed document, and their PDF viewers run apart from the page.
		header(
			'application/pdf' === $mime && $inline
				? "Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; object-src 'self'"
				: "Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox"
		);
		header( 'Cache-Control: ' . (string) $file['cache'] );
		header( 'Accept-Ranges: bytes' );
		if ( $range ) {
			status_header( 206 );
			header( 'Content-Range: bytes ' . $range[0] . '-' . $range[1] . '/' . $size );
			header( 'Content-Length: ' . ( $range[1] - $range[0] + 1 ) );
		} else {
			status_header( 200 );
			header( 'Content-Length: ' . $size );
		}
		$handoff = null === $range ? self::sendfile_header( $driver, (string) $file['key'] ) : null;
		if ( null !== $handoff ) {
			header( $handoff );
			return;
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$driver->stream( (string) $file['key'], $range );
	}

	/**
	 * The header that hands a local file to the web server, if one is set up.
	 *
	 * X-Sendfile (Apache, LiteSpeed) takes the file's path. X-Accel-Redirect
	 * (nginx) takes an address inside an `internal` location the host maps
	 * to the private folder; it is never worked out from where WordPress
	 * lives, which is wrong for subdirectory installs, a moved wp-content or
	 * CLIEZOO_PRIVATE_DIR. With no location set, the file streams through PHP.
	 *
	 * @param StorageDriver $driver Driver.
	 * @param string        $key    Object key.
	 * @return string|null
	 */
	private static function sendfile_header( StorageDriver $driver, string $key ): ?string {
		$mode  = (string) cliezoo()->settings()->get( 'storage', 'x_sendfile', '' );
		$local = '' === $mode ? null : $driver->local_path( $key );
		if ( null === $local ) {
			return null;
		}
		if ( 'x-sendfile' === $mode ) {
			return 'X-Sendfile: ' . $local;
		}
		if ( 'x-accel-redirect' !== $mode ) {
			return null;
		}
		/**
		 * The nginx `internal` location that serves the private folder.
		 *
		 * @param string $location Address prefix, e.g. /cliezoo-private-internal/.
		 */
		$location = (string) apply_filters( 'cliezoo_x_accel_location', (string) cliezoo()->settings()->get( 'storage', 'x_accel_location', '' ) );
		$location = trim( $location, " \t\n\r\0\x0B/" );
		if ( '' === $location || ! preg_match( '#^[A-Za-z0-9/_\-.]+$#', $location ) || str_contains( $location, '..' ) ) {
			return null;
		}
		// The location maps the private folder; a file anywhere else has no
		// address under it. Keys are made by Cliezoo and checked by the driver.
		$base  = trailingslashit( wp_normalize_path( LocalDriver::base() ) );
		$local = wp_normalize_path( $local );
		if ( ! str_starts_with( $local, $base ) ) {
			return null;
		}
		$relative = substr( $local, strlen( $base ) );
		return 'X-Accel-Redirect: /' . $location . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
	}

	/**
	 * Parses a Range header.
	 *
	 * @param int $size File size.
	 * @return array{0:int,1:int}|null
	 */
	private static function range( int $size ): ?array {
		$header = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', $header, $m ) || $size <= 0 ) {
			return null;
		}
		if ( '' === $m[1] ) {
			$start = max( 0, $size - (int) $m[2] );
			$end   = $size - 1;
		} else {
			$start = (int) $m[1];
			$end   = '' === $m[2] ? $size - 1 : min( (int) $m[2], $size - 1 );
		}
		return $start <= $end ? array( $start, $end ) : null;
	}

	/**
	 * RFC 5987 Content-Disposition value.
	 *
	 * @param string $name   File name.
	 * @param bool   $inline Inline.
	 * @return string
	 */
	public static function disposition( string $name, bool $inline ): string {
		$ascii = (string) preg_replace( '/[^\x20-\x7E]|["\\\\]/', '_', $name );
		return ( $inline ? 'inline' : 'attachment' ) . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $name );
	}

	/**
	 * An expiring HMAC-signed URL for a file (public shares, email links).
	 *
	 * The purpose is signed too, so a link made for one job (an avatar, say)
	 * cannot be replayed for another.
	 *
	 * @param string $uid     File uid.
	 * @param int    $ttl     Seconds.
	 * @param string $purpose What the link is for (`file`, `avatar`…).
	 * @return string
	 */
	public static function signed_url( string $uid, int $ttl = 3600, string $purpose = 'file' ): string {
		// Rounded up to the next five minutes, so the same file asked for
		// twice gives the same address: a page that refreshes would
		// otherwise reload every picture and restart every recording.
		$expires = (int) ( ceil( ( time() + $ttl ) / 300 ) * 300 );
		$sig     = cliezoo()->get( Vault::class )->sign( 'file|' . $purpose . '|' . $uid . '|' . $expires );
		/**
		 * The public base URL (e.g. https://site.com/cz/).
		 *
		 * @param string $url URL.
		 */
		$base = (string) apply_filters( 'cliezoo_public_url', home_url( '/cz/' ) );
		return add_query_arg(
			array(
				'e' => $expires,
				'p' => $purpose,
				's' => $sig,
			),
			trailingslashit( $base ) . 'f/' . $uid
		);
	}

	/**
	 * An address for one file of a public share.
	 *
	 * Signed against the link as well as the file, so that serving it can
	 * look the link up again and refuse a link that has since been taken
	 * back. A plain signed URL cannot do that: it says only "somebody was
	 * allowed to ask for this file at some point", which is not enough for
	 * an address handed to a stranger.
	 *
	 * @param string $uid    File uid.
	 * @param string $link   Public link uid.
	 * @param int    $ttl    Seconds.
	 * @param string $prefix Which public route serves it: 'sf' for a file of a
	 *                       share, 'df' for the file behind a document. The
	 *                       signature is the same either way, and each route
	 *                       checks the kind of link it is willing to serve.
	 * @return string
	 */
	public static function shared_url( string $uid, string $link, int $ttl = 300, string $prefix = 'sf' ): string {
		$expires = time() + $ttl;
		$sig     = cliezoo()->get( Vault::class )->sign( 'file|link:' . $link . '|' . $uid . '|' . $expires );
		$base    = (string) apply_filters( 'cliezoo_public_url', home_url( '/cz/' ) );
		return add_query_arg(
			array(
				'e' => $expires,
				's' => $sig,
				'l' => $link,
			),
			trailingslashit( $base ) . $prefix . '/' . $uid
		);
	}

	/**
	 * Verifies one of those.
	 *
	 * @param string $uid     File uid.
	 * @param string $link    Public link uid.
	 * @param int    $expires Expiry.
	 * @param string $sig     Signature.
	 * @return bool
	 */
	public static function verify_shared( string $uid, string $link, int $expires, string $sig ): bool {
		return $expires >= time() && cliezoo()->get( Vault::class )->verify( 'file|link:' . $link . '|' . $uid . '|' . $expires, $sig );
	}

	/**
	 * Verifies a signed file URL.
	 *
	 * @param string $uid     File uid.
	 * @param int    $expires Expiry.
	 * @param string $sig     Signature.
	 * @param string $purpose What the link is for.
	 * @return bool
	 */
	public static function verify_signed( string $uid, int $expires, string $sig, string $purpose = 'file' ): bool {
		return $expires >= time() && cliezoo()->get( Vault::class )->verify( 'file|' . $purpose . '|' . $uid . '|' . $expires, $sig );
	}
}
