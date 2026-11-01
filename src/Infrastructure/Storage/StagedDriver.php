<?php
/**
 * A storage driver that receives an upload on this site first.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * For storage somewhere else (a bucket, a drive): the parts arrive here as
 * they do for local storage, `complete_upload()` puts them together in a
 * local file, and only once Cliezoo has checked that file is it sent on with
 * `commit()`. A file that fails the checks never leaves the site.
 */
interface StagedDriver extends StorageDriver {

	/**
	 * Parts received so far (resuming an upload).
	 *
	 * @param array<string, mixed> $session Session (key, tmp).
	 * @return int[]
	 */
	public function received_parts( array $session ): array;

	/**
	 * Sends the checked file to the storage and removes the local copy.
	 *
	 * @param string $key  Object key.
	 * @param string $path The local file `complete_upload()` made.
	 * @return void
	 * @throws \RuntimeException When the storage refuses it or cannot be reached.
	 */
	public function commit( string $key, string $path ): void;

	/**
	 * Removes what an upload left on this site.
	 *
	 * @param array<string, mixed> $session Session (key, tmp).
	 * @return void
	 */
	public function cleanup( array $session ): void;
}
