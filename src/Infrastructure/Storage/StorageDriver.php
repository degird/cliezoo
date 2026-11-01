<?php
/**
 * Storage driver contract.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One storage backend (Blueprint §14.1). This plugin ships `local`; add-ons may register
 * Google Drive, S3 and R2. Objects are addressed by an opaque key.
 */
interface StorageDriver {

	/**
	 * Driver id ('local', 'gdrive', 's3', 'r2').
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * What the driver supports: presigned_upload, multipart, presigned_download, server_copy, max_object_size.
	 *
	 * @return array{presigned_upload: bool, multipart: bool, presigned_download: bool, server_copy: bool, max_object_size: int}
	 */
	public function capabilities(): array;

	/**
	 * Starts a (direct or proxied) upload for an object key.
	 *
	 * @param string               $key  Object key.
	 * @param array<string, mixed> $meta name, size, mime.
	 * @return array<string, mixed> Session data: mode (proxy|direct), chunk_size, and for direct uploads the URLs.
	 */
	public function create_upload_session( string $key, array $meta ): array;

	/**
	 * Stores one proxied chunk.
	 *
	 * @param array<string, mixed> $session Session.
	 * @param int                  $part    Part number (0-based).
	 * @param string               $data    Bytes.
	 * @return void
	 */
	public function upload_part( array $session, int $part, string $data ): void;

	/**
	 * Finishes an upload and returns the stored object's size.
	 *
	 * @param array<string, mixed> $session Session.
	 * @param int                  $parts   Number of parts.
	 * @return array{size: int, path: string|null} Local path of the assembled file when available (for validation).
	 */
	public function complete_upload( array $session, int $parts ): array;

	/**
	 * Writes a server-side file (PDFs, exports).
	 *
	 * @param string $key  Object key.
	 * @param string $path Local source file.
	 * @return int Size.
	 */
	public function put( string $key, string $path ): int;

	/**
	 * A short-lived direct URL when the driver supports presigned downloads, else null.
	 *
	 * @param string $key         Object key.
	 * @param int    $ttl         Seconds.
	 * @param string $disposition inline|attachment.
	 * @param string $filename    Download name.
	 * @return string|null
	 */
	public function read_url( string $key, int $ttl, string $disposition, string $filename ): ?string;

	/**
	 * Streams an object (optionally a byte range) to the output.
	 *
	 * @param string                  $key   Object key.
	 * @param array{0:int,1:int}|null $range Byte range [start, end] inclusive.
	 * @return void
	 */
	public function stream( string $key, ?array $range ): void;

	/**
	 * Local path of an object when it is on this server (null for remote drivers).
	 *
	 * @param string $key Object key.
	 * @return string|null
	 */
	public function local_path( string $key ): ?string;

	/**
	 * Deletes an object.
	 *
	 * @param string $key Object key.
	 * @return void
	 */
	public function delete( string $key ): void;

	/**
	 * Copies an object.
	 *
	 * @param string $key     Source key.
	 * @param string $new_key Target key.
	 * @return void
	 */
	public function copy( string $key, string $new_key ): void;

	/**
	 * Object info, or null when missing.
	 *
	 * @param string $key Object key.
	 * @return array{size: int}|null
	 */
	public function head( string $key ): ?array;

	/**
	 * Connection health.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function health_check(): array;
}
