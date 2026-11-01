<?php
/**
 * A stored file, on this site's disk for as long as it is needed.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Storage;

use Cliezoo\Core\Data\Ulid;
use Cliezoo\Infrastructure\Http\SafeHttp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anything that has to read a file whole (zipping, moving it to another
 * storage, reading its text) asks for it here: a file kept on this site is
 * read where it is; one kept elsewhere is fetched through the storage's own
 * short-lived link into a temporary file, which the caller releases.
 */
final class LocalCopy {

	/**
	 * The local file.
	 *
	 * @var string
	 */
	public string $path;

	/**
	 * Whether it is a temporary copy (released) or the stored file itself.
	 *
	 * @var bool
	 */
	private bool $temporary;

	/**
	 * Constructor.
	 *
	 * @param string $path      File.
	 * @param bool   $temporary Whether to delete it on release.
	 */
	private function __construct( string $path, bool $temporary ) {
		$this->path      = $path;
		$this->temporary = $temporary;
	}

	/**
	 * A stored object as a local file, or null when it cannot be had (not
	 * there, out of reach, or larger than asked for).
	 *
	 * @param StorageDriver $driver Where it is kept.
	 * @param string        $key    Object key.
	 * @param int           $most   Largest size wanted, in bytes (0: any).
	 * @return self|null
	 */
	public static function of( StorageDriver $driver, string $key, int $most = 0 ): ?self {
		$local = $driver->local_path( $key );
		if ( null !== $local ) {
			return 0 === $most || (int) filesize( $local ) <= $most ? new self( $local, false ) : null;
		}
		try {
			$head = $driver->head( $key );
		} catch ( \RuntimeException $e ) {
			return null;
		}
		if ( null === $head || ( $most > 0 && (int) $head['size'] > $most ) ) {
			return null;
		}
		if ( $driver instanceof FetchesToFile ) {
			$path = self::temp();
			if ( ! $driver->fetch( $key, $path ) || ! file_exists( $path ) ) {
				wp_delete_file( $path );
				return null;
			}
			return new self( $path, true );
		}
		$link = $driver->read_url( $key, 300, 'attachment', 'file' );
		if ( null === $link ) {
			return null;
		}
		$path = self::temp();
		$got  = SafeHttp::request(
			'GET',
			$link,
			array(
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $path,
			)
		);
		if ( is_wp_error( $got ) || 200 !== (int) wp_remote_retrieve_response_code( $got ) || ! file_exists( $path ) ) {
			wp_delete_file( $path );
			return null;
		}
		return new self( $path, true );
	}

	/**
	 * A temporary file name in this site's private storage.
	 *
	 * @return string
	 */
	private static function temp(): string {
		$dir = LocalDriver::base() . '/tmp/copies';
		wp_mkdir_p( $dir );
		return $dir . '/' . strtolower( Ulid::generate() );
	}

	/**
	 * Lets go of it: a temporary copy is deleted.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( $this->temporary && file_exists( $this->path ) ) {
			wp_delete_file( $this->path );
		}
	}
}
