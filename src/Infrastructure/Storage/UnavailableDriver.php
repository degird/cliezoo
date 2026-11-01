<?php
/**
 * Storage that is not here: a driver whose add-on is off or whose
 * connection was removed.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stands in for a driver id nothing registered, so that a file kept in a
 * bucket is reported as out of reach rather than looked for — or, worse,
 * written — in this site's own storage instead. Nothing is read or written.
 */
final class UnavailableDriver implements StorageDriver {

	/**
	 * The id asked for.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Constructor.
	 *
	 * @param string $id The id asked for.
	 */
	public function __construct( string $id ) {
		$this->id = $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Storage that is not connected', 'cliezoo' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function capabilities(): array {
		return array(
			'presigned_upload'   => false,
			'multipart'          => false,
			'presigned_download' => false,
			'server_copy'        => false,
			'max_object_size'    => 0,
		);
	}

	/**
	 * The one error every operation ends in.
	 *
	 * @return \RuntimeException
	 */
	private function out_of_reach(): \RuntimeException {
		return new \RuntimeException( 'Storage "' . $this->id . '" is not connected.' );
	}

	/**
	 * Refuses.
	 *
	 * @return void
	 * @throws \RuntimeException Always.
	 */
	private function refuse(): void {
		throw $this->out_of_reach();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException Always.
	 *
	 * @param string               $key  Object key.
	 * @param array<string, mixed> $meta Upload metadata.
	 * @return array<string, mixed>
	 */
	public function create_upload_session( string $key, array $meta ): array {
		$this->refuse();
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException Always.
	 *
	 * @param array<string, mixed> $session Upload session.
	 * @param int                  $part    Part number.
	 * @param string               $data    Part bytes.
	 */
	public function upload_part( array $session, int $part, string $data ): void {
		throw $this->out_of_reach();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException Always.
	 *
	 * @param array<string, mixed> $session Upload session.
	 * @param int                  $parts   Number of parts.
	 * @return array<string, mixed>
	 */
	public function complete_upload( array $session, int $parts ): array {
		$this->refuse();
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException Always.
	 *
	 * @param string $key  Object key.
	 * @param string $path Local file.
	 * @return int
	 */
	public function put( string $key, string $path ): int {
		$this->refuse();
		return 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key         Object key.
	 * @param int    $ttl         Seconds it lasts.
	 * @param string $disposition attachment or inline.
	 * @param string $filename    File name.
	 * @return string|null
	 */
	public function read_url( string $key, int $ttl, string $disposition, string $filename ): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException Always.
	 *
	 * @param string               $key   Object key.
	 * @param array<int, int>|null $range Byte range, or null.
	 */
	public function stream( string $key, ?array $range ): void {
		throw $this->out_of_reach();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Object key.
	 * @return string|null
	 */
	public function local_path( string $key ): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException Always: an object out of reach is not
	 *                           "deleted".
	 *
	 * @param string $key Object key.
	 */
	public function delete( string $key ): void {
		throw $this->out_of_reach();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException Always.
	 *
	 * @param string $key     Object key.
	 * @param string $new_key New object key.
	 */
	public function copy( string $key, string $new_key ): void {
		throw $this->out_of_reach();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Object key.
	 * @return array<string, mixed>|null
	 */
	public function head( string $key ): ?array {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function health_check(): array {
		return array(
			'ok'      => false,
			'message' => __( 'This storage is not connected: its add-on is off or its connection was removed.', 'cliezoo' ),
		);
	}
}
