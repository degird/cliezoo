<?php
/**
 * A storage that can write one of its objects to a local file itself.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * For storage with no links of its own (Google Drive keeps private files
 * behind the account's token): `LocalCopy` asks it to fetch the object into
 * a file, instead of following a link.
 */
interface FetchesToFile {

	/**
	 * Writes an object to a local file.
	 *
	 * @param string $key  Object key.
	 * @param string $path Local file to write.
	 * @return bool Whether it was written.
	 */
	public function fetch( string $key, string $path ): bool;
}
