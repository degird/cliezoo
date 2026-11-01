<?php
/**
 * A folder, as one download.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Files;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\RateLimiter;
use Cliezoo\Core\Request;
use Cliezoo\Infrastructure\Crypto\Vault;
use Cliezoo\Infrastructure\Storage\LocalDriver;
use Cliezoo\Infrastructure\Storage\StorageDriverRegistry;
use Cliezoo\Infrastructure\Storage\Uploads;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;
use Cliezoo\Platform\Records\PublicLinks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Taking a review round away as one file is the thing people leave a tool
 * for, so it is built rather than promised: the folder's shape is kept
 * inside the zip, what somebody may not see is not in it, and a server
 * that cannot make zips says so instead of sending something broken.
 */
final class Zip {

	/**
	 * The header a response carries when its body is a zip on disk. It is
	 * removed before anything is sent.
	 */
	private const STREAM_HEADER = 'X-Cliezoo-Zip';

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
	 * Whether this server can make one at all.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( '\ZipArchive' );
	}

	/**
	 * Streams a folder as a zip.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws ActionError Turned into a WP_Error before it leaves here.
	 */
	public function rest_download( \WP_REST_Request $request ) {
		try {
			$actor  = $this->plugin->actor();
			$folder = $this->plugin->service( 'folders' )->load( $actor, (string) $request['uid'] );
			$files  = $this->plugin->get( Files::class );
			$rows   = $files->tree_files( $actor, $folder );

			$this->assert_can_build( $rows );

			$path = $this->build( $folder, $rows );
			do_action(
				'cliezoo_audit',
				'folder_downloaded',
				array(
					'object_type' => 'folders',
					'object_id'   => (int) $folder['id'],
					'files'       => count( $rows ),
				)
			);
			return $this->serve( $path, (string) $folder['name'] );
		} catch ( ActionError $error ) {
			return $error->to_wp_error();
		}
	}

	/**
	 * A link to this folder that carries its own permission and expires.
	 *
	 * The app navigates the browser to this, so it must not carry the REST
	 * nonce: that nonce is the write credential for the whole API and would
	 * land in history and in every proxy log on the way.
	 *
	 * @param Actor  $actor Who asked.
	 * @param string $uid   Folder uid.
	 * @param int    $ttl   Seconds it lasts.
	 * @return string
	 */
	public function link( Actor $actor, string $uid, int $ttl = 300 ): string {
		// Checked here, because after this the signature is the permission.
		$this->plugin->service( 'folders' )->load( $actor, $uid );
		$expires = time() + $ttl;
		$sig     = $this->plugin->get( Vault::class )->sign( 'folder|' . $uid . '|' . $expires . '|' . $actor->member_id );
		$base    = (string) apply_filters( 'cliezoo_public_url', home_url( '/cz/' ) );
		return add_query_arg(
			array(
				'e' => $expires,
				's' => $sig,
				'm' => $actor->member_id,
			),
			trailingslashit( $base ) . 'z/' . $uid
		);
	}

	/**
	 * The same thing for somebody with no account: signed against the public
	 * link instead of a member, so it stops working the moment that link is
	 * taken back.
	 *
	 * @param string $uid  Folder uid.
	 * @param string $link Public link uid.
	 * @param int    $ttl  Seconds it lasts.
	 * @return string
	 */
	public function public_link( string $uid, string $link, int $ttl = 300 ): string {
		$expires = time() + $ttl;
		$sig     = $this->plugin->get( Vault::class )->sign( 'folder|' . $uid . '|' . $expires . '|link:' . $link );
		$base    = (string) apply_filters( 'cliezoo_public_url', home_url( '/cz/' ) );
		return add_query_arg(
			array(
				'e' => $expires,
				's' => $sig,
				'l' => $link,
			),
			trailingslashit( $base ) . 'z/' . $uid
		);
	}

	/**
	 * Serves a signed folder link, or says nothing and lets the shell 404.
	 *
	 * @param string $uid Folder uid.
	 * @return void
	 */
	public function serve_signed( string $uid ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A signed URL is the credential.
		$expires = isset( $_GET['e'] ) ? (int) $_GET['e'] : 0;
		$sig     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$member  = isset( $_GET['m'] ) ? (int) $_GET['m'] : 0;
		$link    = isset( $_GET['l'] ) ? sanitize_text_field( wp_unslash( $_GET['l'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' !== $link ) {
			$this->serve_public( $uid, $expires, $sig, $link );
			return;
		}
		if ( $expires < time() || ! $this->plugin->get( Vault::class )->verify( 'folder|' . $uid . '|' . $expires . '|' . $member, $sig ) ) {
			status_header( 403 );
			exit;
		}

		$actor = $this->plugin->actor();
		if ( $actor->is_guest() || $actor->member_id !== $member ) {
			// The link is for the person it was made for, signed in.
			status_header( 403 );
			exit;
		}

		try {
			$folder = $this->plugin->service( 'folders' )->load( $actor, $uid );
			$rows   = $this->plugin->get( Files::class )->tree_files( $actor, $folder );
			$this->assert_can_build( $rows );
			$path = $this->build( $folder, $rows );
		} catch ( ActionError $error ) {
			status_header( $error->status );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( $error->getMessage() );
			exit;
		}

		$this->deliver( $folder, $path, count( $rows ) );
	}

	/**
	 * Serves a zip to somebody with no account, on a link somebody made for
	 * them.
	 *
	 * The signature was issued only after the page resolved the token — and
	 * its passcode, if it has one — so it is a short-lived receipt for that,
	 * not a second way in. Everything else is checked again here, because
	 * the link may have been taken back in the meantime.
	 *
	 * @param string $uid     Folder uid.
	 * @param int    $expires Expiry.
	 * @param string $sig     Signature.
	 * @param string $link    Public link uid.
	 * @return void
	 */
	private function serve_public( string $uid, int $expires, string $sig, string $link ): void {
		if ( $expires < time() || ! $this->plugin->get( Vault::class )->verify( 'folder|' . $uid . '|' . $expires . '|link:' . $link, $sig ) ) {
			status_header( 403 );
			exit;
		}
		// Building a zip is the most expensive thing a stranger can ask for,
		// so it is limited per address and per link: one popular link must
		// not be a way to keep a server busy all day.
		if ( RateLimiter::hit( 'zip-ip:' . Request::ip(), 20, 300 ) || RateLimiter::hit( 'zip-link:' . $link, 10, 300 ) ) {
			status_header( 429 );
			exit;
		}

		$share   = $this->plugin->get( PublicShare::class );
		$folders = $this->plugin->service( 'folders' )->repository();
		$row     = $share->live_link( $link );
		$folder  = $folders->find_by_uid( $uid );
		$shared  = $row ? $folders->find( (int) $row['object_id'] ) : null;
		if ( ! $row || ! $folder || ! $shared
			|| 'folders' !== (string) $row['object_type']
			|| empty( $row['allow_download'] )
			|| ! empty( $folder['deleted_at'] )
			|| ! empty( $shared['deleted_at'] )
			// Only the folder that was shared, or something below it.
			|| ! $this->plugin->get( Folders::class )->is_inside( (int) $shared['id'], $folder )
		) {
			status_header( 404 );
			exit;
		}

		// The link sees with the eyes of whoever made it, and no further.
		$sharer = $this->plugin->get( PublicLinks::class )->sharer( $link );
		if ( $sharer->is_guest() ) {
			status_header( 404 );
			exit;
		}

		try {
			$rows = $this->plugin->get( Files::class )->branch_files( (int) $folder['id'], true, $sharer );
			$this->assert_can_build( $rows );
			$path = $this->build( $folder, $rows );
		} catch ( ActionError $error ) {
			// A visitor is told that it did not work, not how this server is
			// put together.
			unset( $error );
			status_header( 409 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html__( 'This folder could not be prepared for download. Try the files one at a time, or ask whoever sent you the link.', 'cliezoo' );
			exit;
		}

		do_action(
			'cliezoo_audit',
			'public_link_downloaded',
			array(
				'object_type' => 'folders',
				'object_id'   => (int) $folder['id'],
				'link'        => $link,
				'files'       => count( $rows ),
				'ip'          => Request::ip(),
			)
		);
		$this->deliver( $folder, $path, count( $rows ) );
	}

	/**
	 * Sends a zip this request built, and deletes it.
	 *
	 * @param array<string, mixed> $folder The folder.
	 * @param string               $path   Temporary zip.
	 * @param int                  $files  How many files went in.
	 * @return void
	 */
	private function deliver( array $folder, string $path, int $files ): void {
		do_action(
			'cliezoo_audit',
			'folder_downloaded',
			array(
				'object_type' => 'folders',
				'object_id'   => (int) $folder['id'],
				'files'       => $files,
			)
		);
		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( (string) $folder['name'] ) . '.zip"' );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile,WordPress.WP.AlternativeFunctions.file_system_read_readfile -- Streaming a file this request just built.
		wp_delete_file( $path );
		exit;
	}

	/**
	 * Refuses what this server or this download cannot carry.
	 *
	 * @param array<int, array<string, mixed>> $rows The files.
	 * @return void
	 * @throws ActionError When it cannot be built.
	 */
	private function assert_can_build( array $rows ): void {
		if ( ! self::available() ) {
			throw ActionError::conflict( __( 'This server cannot make zip files. Download them one at a time, or ask your host to enable the zip extension.', 'cliezoo' ) );
		}
		if ( ! $rows ) {
			throw ActionError::conflict( __( 'There is nothing in this folder to download.', 'cliezoo' ) );
		}
		$size = array_sum( array_map( static fn( array $row ): int => (int) $row['size'], $rows ) );
		if ( $size > Files::ZIP_LIMIT ) {
			throw ActionError::conflict(
				sprintf(
					/* translators: %s: a size, e.g. "500 MB". */
					__( 'This folder is bigger than %s, which is more than one download can carry. Take it a folder at a time.', 'cliezoo' ),
					size_format( Files::ZIP_LIMIT )
				)
			);
		}
	}

	/**
	 * Builds the zip on disk.
	 *
	 * @param array<string, mixed>             $folder The folder.
	 * @param array<int, array<string, mixed>> $rows   Its files.
	 * @return string Path to the zip.
	 * @throws ActionError When it cannot be built.
	 */
	private function build( array $folder, array $rows ): string {
		$path = Uploads::temp_file( 'folder' );
		// However this request ends — an error, or a browser that gives up
		// halfway through a 400 MB download — the file goes with it. Without
		// this, anybody could fill the disk by starting downloads and
		// walking away.
		register_shutdown_function(
			static function () use ( $path ): void {
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
		);
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $path );
			throw ActionError::conflict( __( 'The download could not be put together.', 'cliezoo' ) );
		}

		$drivers = $this->plugin->get( StorageDriverRegistry::class );
		$base    = (string) $folder['path'];
		$used    = array();
		$added   = 0;
		foreach ( $rows as $row ) {
			$local = $drivers->get( (string) $row['storage_driver'] )->local_path( (string) $row['object_key'] );
			if ( ! $local || ! is_readable( $local ) ) {
				continue;
			}
			$inside = $this->inside( $row, $base );
			$name   = $this->unique( self::inside_the_zip( $inside, (string) $row['name'] ), $used );
			if ( $zip->addFile( $local, $name ) ) {
				++$added;
			}
		}
		$zip->close();

		if ( ! $added ) {
			wp_delete_file( $path );
			throw ActionError::conflict( __( 'None of these files are stored on this server, so there is nothing to put in a zip.', 'cliezoo' ) );
		}
		unset( $folder );
		return $path;
	}

	/**
	 * Where a file sits inside the zip, relative to the folder asked for.
	 *
	 * @param array<string, mixed> $row  File row.
	 * @param string               $base The folder's own path.
	 * @return string
	 */
	private function inside( array $row, string $base ): string {
		$folder = empty( $row['folder_id'] ) ? null : $this->plugin->service( 'folders' )->repository()->find( (int) $row['folder_id'] );
		if ( ! $folder ) {
			return '';
		}
		$path = (string) $folder['path'];
		if ( ! str_starts_with( $path, $base ) ) {
			// The file is filed somewhere above or beside the folder being
			// taken: it goes in at the top rather than carrying the name of
			// a folder this person may never have seen.
			return '';
		}
		return $path === $base ? '' : ltrim( substr( $path, strlen( $base ) ), '/' );
	}

	/**
	 * Where one file sits inside the archive, as segments a zip may hold.
	 *
	 * Folder and file names are text a person typed. They are cleaned on
	 * the way in, but an archive is read by somebody else's computer, so
	 * this refuses "..", "." and anything with a separator in it a second
	 * time: an entry that escapes the folder it is unpacked into is how a
	 * download becomes a way to write to somebody's home directory.
	 *
	 * @param string $inside Folder path below the one being taken.
	 * @param string $name   File name.
	 * @return string
	 */
	private static function inside_the_zip( string $inside, string $name ): string {
		$segments = array();
		foreach ( explode( '/', $inside . '/' . $name ) as $segment ) {
			$clean = sanitize_file_name( $segment );
			if ( '' === $clean || '.' === $clean || '..' === $clean ) {
				continue;
			}
			$segments[] = $clean;
		}
		if ( ! $segments ) {
			$segments[] = 'file';
		}
		return implode( '/', array_slice( $segments, 0, Folders::MAX_DEPTH + 1 ) );
	}

	/**
	 * A name nothing else in the zip already has.
	 *
	 * @param string   $name Wanted name.
	 * @param string[] $used Names taken, by reference.
	 * @return string
	 */
	private function unique( string $name, array &$used ): string {
		$try  = $name;
		$stem = pathinfo( $name, PATHINFO_FILENAME );
		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		$dir  = ltrim( (string) pathinfo( $name, PATHINFO_DIRNAME ), '.' );
		$dir  = '' === $dir ? '' : rtrim( $dir, '/' ) . '/';
		$n    = 2;
		while ( isset( $used[ strtolower( $try ) ] ) && $n < 500 ) {
			$try = $dir . $stem . ' (' . $n . ')' . ( '' === $ext ? '' : '.' . $ext );
			++$n;
		}
		$used[ strtolower( $try ) ] = $try;
		return $try;
	}

	/**
	 * Sends the zip and takes it off the disk.
	 *
	 * @param string $path Zip path.
	 * @param string $name Folder name.
	 * @return \WP_REST_Response
	 */
	private function serve( string $path, string $name ): \WP_REST_Response {
		$response = new \WP_REST_Response();
		$response->set_headers(
			array(
				'Content-Type'           => 'application/zip',
				'Content-Length'         => (string) filesize( $path ),
				'Content-Disposition'    => 'attachment; filename="' . sanitize_file_name( $name ) . '.zip"',
				'Cache-Control'          => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			)
		);
		// The path travels in a header rather than the body: if the stream
		// filter does not fire, the caller gets an empty response instead of
		// this server's temp directory.
		$response->header( self::STREAM_HEADER, $path );
		$response->set_data( array() );
		return $response;
	}

	/**
	 * `rest_pre_serve_request`: sends the built zip in place of a JSON body
	 * and takes it off the disk afterwards.
	 *
	 * @param bool                    $served  Whether something already answered.
	 * @param \WP_HTTP_Response|mixed $result  Result.
	 * @param \WP_REST_Request|mixed  $request Request.
	 * @param \WP_REST_Server|mixed   $server  Server.
	 * @return bool
	 */
	public static function stream( $served, $result, $request, $server ): bool {
		unset( $request, $server );
		if ( $served || ! $result instanceof \WP_HTTP_Response ) {
			return (bool) $served;
		}
		$headers = $result->get_headers();
		$path    = (string) ( $headers[ self::STREAM_HEADER ] ?? '' );
		// Only ever a zip this request built, in the temp directory: the
		// value arrives from a response body-adjacent place, and this is one
		// readfile() away from being able to send any file on the disk.
		$expected = trailingslashit( LocalDriver::base() ) . 'tmp/cliezoo-folder-';
		if ( '' === $path || ! str_starts_with( $path, $expected ) || ! is_readable( $path ) ) {
			return false;
		}
		unset( $headers[ self::STREAM_HEADER ] );
		foreach ( $headers as $header => $value ) {
			header( $header . ': ' . $value );
		}
		status_header( 200 );
		// Nothing buffered: a 400 MB readfile() through an open output
		// buffer is a memory limit rather than a download.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile,WordPress.WP.AlternativeFunctions.file_system_read_readfile -- Streaming a file this request just built.
		wp_delete_file( $path );
		return true;
	}

	/**
	 * Whether a folder may be downloaded whole by this person.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function may_download( \WP_REST_Request $request ): bool {
		$actor = $this->plugin->actor();
		if ( $actor->is_guest() ) {
			return false;
		}
		$row = $this->plugin->service( 'folders' )->repository()->find_by_uid( (string) $request['uid'] );
		return $row && $this->plugin->get( Policy::class )->can( $actor, Policy::VIEW, $this->plugin->service( 'folders' )->entity(), $row );
	}
}
