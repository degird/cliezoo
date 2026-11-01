<?php
/**
 * Chunked, resumable uploads.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Storage;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\ListQuery;
use Cliezoo\Core\Data\ObjectRef;
use Cliezoo\Core\Data\Ulid;
use Cliezoo\Core\Logger;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Jobs\Jobs;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Upload pipeline: session → 5 MB chunks (bypasses upload_max_filesize) →
 * assemble → validate → checksum → thumbnails → ready (Blueprint §14.2).
 * Interrupted uploads resume from the parts already received (§31.5).
 */
final class Uploads {

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
	 * Drivers.
	 *
	 * @return StorageDriverRegistry
	 */
	private function drivers(): StorageDriverRegistry {
		return $this->plugin->get( StorageDriverRegistry::class );
	}

	/**
	 * Resolves where a file goes and checks the actor may add files there.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    folder/client/project/object_type+object/purpose.
	 * @return array<string, mixed> Column values.
	 * @throws ActionError When not allowed.
	 */
	private function placement( Actor $actor, array $in ): array {
		$data = array( 'purpose' => (string) ( $in['purpose'] ?? 'file' ) );
		if ( ! empty( $in['folder'] ) && $this->plugin->entities()->has( 'folders' ) ) {
			list( , $folder )   = ObjectRef::resolve( $this->plugin, $actor, 'folders', (string) $in['folder'] );
			$data['folder_id']  = (int) $folder['id'];
			$data['client_id']  = $folder['client_id'] ?? null;
			$data['project_id'] = $folder['project_id'] ?? null;
		}
		if ( ! empty( $in['project'] ) && $this->plugin->entities()->has( 'projects' ) ) {
			$data['project_id'] = (int) ObjectRef::resolve( $this->plugin, $actor, 'projects', (string) $in['project'] )[1]['id'];
		}
		if ( ! empty( $in['client'] ) && $this->plugin->entities()->has( 'clients' ) ) {
			$data['client_id'] = (int) ObjectRef::resolve( $this->plugin, $actor, 'clients', (string) $in['client'] )[1]['id'];
		}
		if ( ! empty( $in['object_type'] ) && ! empty( $in['object'] ) ) {
			list( $entity, $row ) = ObjectRef::resolve( $this->plugin, $actor, (string) $in['object_type'], (string) $in['object'] );
			$data['object_type']  = $entity->name;
			$data['object_id']    = (int) $row['id'];
			if ( ! isset( $data['client_id'] ) && '' !== $entity->client_field ) {
				$data['client_id'] = $row[ $entity->client_field ] ?? null;
			}
			if ( ! isset( $data['project_id'] ) && '' !== $entity->project_field ) {
				$data['project_id'] = $row[ $entity->project_field ] ?? null;
			}
		}
		/**
		 * Whether the actor may add a file at this place (folder upload permissions,
		 * client "Uploader" role, disabled uploads…). Throw ActionError to refuse.
		 *
		 * @param bool  $allowed Default: staff with write access, or a client with access to the target.
		 * @param array $data    Placement.
		 * @param Actor $actor   Actor.
		 */
		// A client adds a file where their own portal says they may: a
		// section set to "view" is not a section to add to, and a switch
		// the screen honours and the server does not is decoration.
		$client_may = $actor->is_client()
			&& 'client_viewer' !== $actor->role
			&& ( isset( $data['object_type'] ) || isset( $data['folder_id'] ) )
			&& (bool) apply_filters( 'cliezoo_client_may_add_files', true, $data, $actor );
		$allowed    = apply_filters( 'cliezoo_file_upload_allowed', $actor->is_staff() ? $actor->can_write() : $client_may, $data, $actor );
		if ( ! $allowed ) {
			throw ActionError::forbidden();
		}
		return $data;
	}

	/**
	 * Maximum allowed size in bytes for the actor (0 = only the server's disk).
	 *
	 * @param Actor $actor Actor.
	 * @return int
	 */
	private function max_bytes( Actor $actor ): int {
		$settings = $this->plugin->settings();
		$mb       = (int) $settings->get( 'storage', $actor->is_client() ? 'max_file_mb_client' : 'max_file_mb', 0 );
		return $mb > 0 ? $mb * 1048576 : 0;
	}

	/**
	 * Starts an upload session.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    name, size, mime + placement.
	 * @return array<string, mixed>
	 * @throws ActionError On limits.
	 */
	public function start( Actor $actor, array $in ): array {
		$data = $this->placement( $actor, $in );
		// Uploaded into a folder, it is shared as the folder is — the same
		// rule a file filed there any other way follows.
		$folder = empty( $data['folder_id'] ) ? null : $this->plugin->service( 'folders' )->repository()->find( (int) $data['folder_id'] );
		if ( $folder ) {
			$data['access']      = (string) ( $folder['access'] ?? 'inherit' );
			$data['access_role'] = (string) ( $folder['access_role'] ?? 'viewer' );
		}
		$size = (int) $in['size'];
		$max  = $this->max_bytes( $actor );
		if ( $max > 0 && $size > $max ) {
			/* translators: %s: size limit in MB. */
			throw ActionError::validation( sprintf( __( 'Files can be up to %s MB.', 'cliezoo' ), number_format_i18n( $max / 1048576 ) ) );
		}
		$this->assert_room( $size );
		$name   = FileValidator::display_name( (string) $in['name'] );
		$ext    = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$driver = $this->driver_for( $data );
		$most   = (int) ( $driver->capabilities()['max_object_size'] ?? 0 );
		if ( $most > 0 && $size > $most ) {
			// What the storage itself takes, whatever the site allows.
			/* translators: %s: size limit in MB. */
			throw ActionError::validation( sprintf( __( 'Where files are kept takes files up to %s MB.', 'cliezoo' ), number_format_i18n( $most / 1048576 ) ) );
		}
		$key  = gmdate( 'Y/m' ) . '/' . strtolower( Ulid::generate() );
		$name = $this->unique_name( $name, $data );
		// Straight to the storage only when the uploader can and the
		// storage allows it; the file is still checked here on complete.
		$direct  = ! empty( $in['direct'] ) && ! empty( $driver->capabilities()['presigned_upload'] );
		$session = $driver->create_upload_session(
			$key,
			array(
				'name'   => $name,
				'size'   => $size,
				'direct' => $direct,
			)
		);
		$parts   = max( 1, (int) ceil( $size / max( 1, (int) $session['chunk_size'] ) ) );
		$row     = $this->plugin->service( 'files' )->repository()->insert(
			$data + array(
				'name'           => $name,
				'kind'           => 'upload',
				'extension'      => $ext,
				'mime'           => mb_substr( sanitize_mime_type( (string) ( $in['mime'] ?? '' ) ), 0, 120 ),
				'size'           => $size,
				'storage_driver' => $driver->id(),
				'object_key'     => $key,
				'status'         => 'uploading',
				'upload_parts'   => $parts,
			),
			$actor->member_id ? $actor->member_id : null
		);
		return array(
			'uid'        => (string) $row['uid'],
			'name'       => $name,
			'mode'       => $session['mode'],
			'chunk_size' => (int) $session['chunk_size'],
			'parts'      => $parts,
			'direct'     => $session['direct'] ?? null,
		);
	}

	/**
	 * Driver for a placement (an add-on can route uploads to external storage).
	 *
	 * @param array<string, mixed> $data Placement.
	 * @return StorageDriver
	 * @throws ActionError When new files are set to go to storage that is not connected.
	 */
	private function driver_for( array $data ): StorageDriver {
		/**
		 * Storage driver id for a new file.
		 *
		 * @param string $driver Driver id.
		 * @param array  $data   Placement (client_id, project_id, folder_id, purpose).
		 */
		$id     = (string) apply_filters( 'cliezoo_storage_driver_for', (string) $this->plugin->settings()->get( 'storage', 'default_driver', 'local' ), $data );
		$driver = $this->drivers()->get( $id );
		if ( $driver instanceof UnavailableDriver ) {
			// Never quietly kept here instead of where the business chose.
			throw ActionError::conflict( __( 'Files are set to be kept in storage that is not connected right now, so nothing can be uploaded. Whoever looks after this site can reconnect it or choose where files go in Settings.', 'cliezoo' ) );
		}
		return $driver;
	}

	/**
	 * Adds "(2)" when the same name exists in the same place (§31.5).
	 *
	 * @param string               $name Name.
	 * @param array<string, mixed> $data Placement.
	 * @return string
	 */
	private function unique_name( string $name, array $data ): string {
		$repo  = $this->plugin->service( 'files' )->repository();
		$where = array(
			'folder_id'   => $data['folder_id'] ?? null,
			'object_type' => $data['object_type'] ?? null,
			'object_id'   => $data['object_id'] ?? null,
			'project_id'  => $data['project_id'] ?? null,
			'client_id'   => $data['client_id'] ?? null,
		);
		$base  = pathinfo( $name, PATHINFO_FILENAME );
		$ext   = pathinfo( $name, PATHINFO_EXTENSION );
		$try   = $name;
		for ( $i = 2; $i < 100; $i++ ) {
			if ( ! $repo->first( $where + array( 'name' => $try ) ) ) {
				return $try;
			}
			$try = $base . ' (' . $i . ')' . ( '' !== $ext ? '.' . $ext : '' );
		}
		return $try;
	}

	/**
	 * Loads an upload the actor started.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $uid   Uid.
	 * @return array<string, mixed>
	 * @throws ActionError When missing or not the uploader.
	 */
	private function own_upload( Actor $actor, string $uid ): array {
		$row = $this->plugin->service( 'files' )->repository()->find_by_uid( $uid );
		if ( ! $row || 'uploading' !== $row['status'] || (int) $row['created_by'] !== $actor->member_id ) {
			throw ActionError::not_found();
		}
		return $row;
	}

	/**
	 * Session data of a file row.
	 *
	 * @param array<string, mixed> $row File row.
	 * @return array<string, mixed>
	 */
	private function session( array $row ): array {
		return array(
			'key' => (string) $row['object_key'],
			'tmp' => 'tmp/' . md5( (string) $row['object_key'] ),
		);
	}

	/**
	 * REST handler for one raw chunk.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws ActionError Caught internally and returned as WP_Error.
	 */
	public function rest_chunk( \WP_REST_Request $request ) {
		try {
			$actor = $this->plugin->actor();
			$row   = $this->own_upload( $actor, (string) $request['uid'] );
			$part  = (int) $request['part'];
			$body  = (string) $request->get_body();
			if ( $part < 0 || $part >= (int) $row['upload_parts'] || strlen( $body ) > LocalDriver::CHUNK ) {
				throw ActionError::validation( __( 'Invalid upload part.', 'cliezoo' ) );
			}
			$this->drivers()->get( (string) $row['storage_driver'] )->upload_part( $this->session( $row ), $part, $body );
			return new \WP_REST_Response( array( 'data' => array( 'received' => $part ) ), 200 );
		} catch ( ActionError $error ) {
			return $error->to_wp_error();
		}
	}

	/**
	 * Parts received so far (resume support).
	 *
	 * @param Actor  $actor Actor.
	 * @param string $uid   Uid.
	 * @return array<string, mixed>
	 */
	public function status( Actor $actor, string $uid ): array {
		$row    = $this->own_upload( $actor, $uid );
		$driver = $this->drivers()->get( (string) $row['storage_driver'] );
		return array(
			'uid'      => $uid,
			'parts'    => (int) $row['upload_parts'],
			'received' => $driver instanceof LocalDriver || $driver instanceof StagedDriver ? $driver->received_parts( $this->session( $row ) ) : array(),
		);
	}

	/**
	 * Assembles, validates and finalises an upload.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $uid   Uid.
	 * @return array<string, mixed> Presented file.
	 * @throws ActionError When validation fails.
	 */
	public function complete( Actor $actor, string $uid ): array {
		$row     = $this->own_upload( $actor, $uid );
		$repo    = $this->plugin->service( 'files' )->repository();
		$driver  = $this->drivers()->get( (string) $row['storage_driver'] );
		$session = $this->session( $row );
		try {
			$result = $driver->complete_upload( $session, (int) $row['upload_parts'] );
		} catch ( \RuntimeException $e ) {
			throw ActionError::validation( __( 'The upload is incomplete. Please try again.', 'cliezoo' ) );
		}
		$path = (string) ( $result['path'] ?? $driver->local_path( (string) $row['object_key'] ) );
		try {
			$check = FileValidator::validate( $path, (string) $row['name'] );
		} catch ( ActionError $error ) {
			$driver->delete( (string) $row['object_key'] );
			$repo->update( (int) $row['id'], array( 'status' => 'failed' ) );
			throw $error;
		}
		$update = array(
			'status'          => 'ready',
			'size'            => (int) filesize( $path ),
			'mime'            => $check['mime'],
			'extension'       => $check['extension'],
			'checksum_sha256' => (string) hash_file( 'sha256', $path ),
		);
		$info   = $this->media_info( $path, $check['mime'], $driver, (string) $row['object_key'] );
		if ( $driver instanceof StagedDriver ) {
			// Checked here; only now sent on to where it is kept.
			try {
				$driver->commit( (string) $row['object_key'], $path );
			} catch ( \RuntimeException $e ) {
				$driver->cleanup( $session );
				foreach ( (array) json_decode( (string) ( $info['thumbs'] ?? '' ), true ) as $thumb ) {
					try {
						$driver->delete( (string) $thumb['key'] );
					} catch ( \RuntimeException $ignored ) {
						unset( $ignored );
					}
				}
				$repo->update( (int) $row['id'], array( 'status' => 'failed' ) );
				throw ActionError::conflict( __( 'The file could not be sent to where files are kept. Please try again in a moment.', 'cliezoo' ) );
			}
		}
		$update += $info;
		$repo->update( (int) $row['id'], $update );
		$row    = (array) $repo->find( (int) $row['id'] );
		$entity = $this->plugin->entities()->get( 'files' );
		/**
		 * A file finished uploading.
		 *
		 * @param array $row   File row.
		 * @param Actor $actor Actor.
		 */
		do_action( 'cliezoo_file_uploaded', $row, $actor );
		do_action( 'cliezoo_record_created', $entity, $row, $actor, array() );
		return $this->plugin->service( 'files' )->present( $actor, $row );
	}

	/**
	 * Image dimensions and thumbnails (small 160, medium 480, large 1280).
	 *
	 * @param string        $path   Local path.
	 * @param string        $mime   MIME.
	 * @param StorageDriver $driver Driver.
	 * @param string        $key    Object key.
	 * @return array<string, mixed>
	 */
	private function media_info( string $path, string $mime, StorageDriver $driver, string $key ): array {
		if ( ! str_starts_with( $mime, 'image/' ) || 'image/svg+xml' === $mime ) {
			return array();
		}
		$size   = wp_getimagesize( $path );
		$info   = $size ? array(
			'width'  => (int) $size[0],
			'height' => (int) $size[1],
		) : array();
		$thumbs = array();
		foreach ( array(
			'small'  => 160,
			'medium' => 480,
			'large'  => 1280,
		) as $name => $width ) {
			// The stored object has no extension, so WordPress cannot tell
			// the format from the path: without the type it picks an editor
			// that may not read this format at all (Imagick built without
			// PNG, say) and every thumbnail silently fails.
			$editor = wp_get_image_editor( $path, array( 'mime_type' => $mime ) );
			if ( is_wp_error( $editor ) ) {
				break;
			}
			$editor->resize( $width, $width, false );
			$tmp   = self::temp_file( 'thumb' );
			$saved = $editor->save( $tmp, 'image/webp' );
			if ( is_wp_error( $saved ) ) {
				$saved = $editor->save( $tmp, 'image/jpeg' );
			}
			if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ) {
				$thumb_key = $key . '-' . $name;
				try {
					$driver->put( $thumb_key, (string) $saved['path'] );
					$thumbs[ $name ] = array(
						'key'  => $thumb_key,
						'mime' => (string) $saved['mime-type'],
					);
				} catch ( \RuntimeException $e ) {
					// A picture without a preview is still the picture.
					Logger::warning( 'Could not store a thumbnail', array( 'error' => $e->getMessage() ) );
				}
				wp_delete_file( (string) $saved['path'] );
			}
			wp_delete_file( $tmp );
		}
		if ( $thumbs ) {
			$info['thumbs'] = wp_json_encode( $thumbs );
		}
		return $info;
	}

	/**
	 * Adds a link-type file (no storage used).
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    url, name + placement.
	 * @return array<string, mixed>
	 */
	public function add_link( Actor $actor, array $in ): array {
		return $this->add_reference( $actor, $in, 'link' );
	}

	/**
	 * Adds a file that is an address rather than bytes — a link, an embed —
	 * at a place the actor may add files to.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    url, name, description + placement.
	 * @param string               $kind  The kind of file.
	 * @param array<string, mixed> $extra Columns the kind keeps.
	 * @return array<string, mixed>
	 */
	public function add_reference( Actor $actor, array $in, string $kind, array $extra = array() ): array {
		$data = $this->placement( $actor, $in );
		$url  = esc_url_raw( (string) $in['url'], array( 'http', 'https' ) );
		$name = '' !== (string) ( $in['name'] ?? '' ) ? FileValidator::display_name( (string) $in['name'] ) : (string) wp_parse_url( $url, PHP_URL_HOST );
		$row  = $this->plugin->service( 'files' )->repository()->insert(
			$extra + $data + array(
				'name'        => $name,
				'kind'        => $kind,
				'url'         => $url,
				'description' => sanitize_textarea_field( (string) ( $in['description'] ?? '' ) ),
				'status'      => 'ready',
				'size'        => 0,
			),
			$actor->member_id ? $actor->member_id : null
		);
		do_action( 'cliezoo_record_created', $this->plugin->entities()->get( 'files' ), $row, $actor, $in );
		return $this->plugin->service( 'files' )->present( $actor, $row );
	}

	/**
	 * Stores a server-generated file (PDF, export) and returns its row.
	 *
	 * @param string               $path Local source path.
	 * @param string               $name Display name.
	 * @param array<string, mixed> $data Placement columns (purpose, object_type, object_id…).
	 * @return array<string, mixed>
	 */
	public function store_generated( string $path, string $name, array $data ): array {
		$driver = $this->driver_for( $data );
		$key    = gmdate( 'Y/m' ) . '/' . strtolower( Ulid::generate() );
		$size   = $driver->put( $key, $path );
		return $this->plugin->service( 'files' )->repository()->insert(
			$data + array(
				'name'            => FileValidator::display_name( $name ),
				'kind'            => 'upload',
				'extension'       => strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ),
				'mime'            => FileValidator::sniff( $path ),
				'size'            => $size,
				'checksum_sha256' => (string) hash_file( 'sha256', $path ),
				'storage_driver'  => $driver->id(),
				'object_key'      => $key,
				'status'          => 'ready',
			)
		);
	}

	/**
	 * Refuses a file the site has no room for.
	 *
	 * @param int $size How many more bytes it would hold.
	 * @return void
	 * @throws ActionError When the site is full.
	 */
	public function assert_room( int $size ): void {
		$quota = (int) $this->plugin->settings()->get( 'storage', 'quota_gb', 0 );
		if ( $quota > 0 && $this->usage()['total'] + $size > $quota * 1073741824 ) {
			throw ActionError::validation( __( 'The storage limit set for this site has been reached.', 'cliezoo' ) );
		}
	}

	/**
	 * Copies a stored file to a new one, bytes and all.
	 *
	 * The object is copied rather than shared, because a copy whose bytes
	 * vanish when the original is deleted is not a copy.
	 *
	 * @param Actor                $actor   Who is copying.
	 * @param array<string, mixed> $row     The file being copied.
	 * @param array<string, mixed> $changes name, folder_id, client_id, project_id.
	 * @return array<string, mixed> The new file row.
	 * @throws ActionError When there is no room for it.
	 */
	public function copy( Actor $actor, array $row, array $changes ): array {
		$data = array(
			'name'        => FileValidator::display_name( (string) ( $changes['name'] ?? $row['name'] ) ),
			'kind'        => (string) $row['kind'],
			'url'         => (string) ( $row['url'] ?? '' ),
			'description' => (string) ( $row['description'] ?? '' ),
			'folder_id'   => $changes['folder_id'] ?? null,
			'client_id'   => $changes['client_id'] ?? null,
			'project_id'  => $changes['project_id'] ?? null,
			'purpose'     => (string) ( $row['purpose'] ?? 'file' ),
			'status'      => 'ready',
			// A copy is shared exactly as far as the original was. Falling
			// to the default would quietly widen "private" to "inherit",
			// which in a client-visible project means the client sees it.
			'access'      => (string) ( $row['access'] ?? 'inherit' ),
			'access_role' => (string) ( $row['access_role'] ?? 'viewer' ),
		);

		if ( 'upload' !== (string) $row['kind'] ) {
			// A link or an embed weighs nothing, so there is nothing to copy
			// but the row — with whatever columns its kind keeps.
			/**
			 * Columns a file of this kind keeps, copied with it.
			 *
			 * @param string[]             $columns Column names.
			 * @param array<string, mixed> $row     The file.
			 */
			$keep = (array) apply_filters( 'cliezoo_file_copy_columns', array(), $row );
			return $this->plugin->service( 'files' )->repository()->insert( $data + array_intersect_key( $row, array_flip( $keep ) ), $actor->member_id ? $actor->member_id : null );
		}

		$this->assert_room( (int) $row['size'] );
		$driver = $this->drivers()->get( (string) $row['storage_driver'] );
		$key    = gmdate( 'Y/m' ) . '/' . strtolower( Ulid::generate() );
		try {
			$driver->copy( (string) $row['object_key'], $key );
		} catch ( \RuntimeException $e ) {
			throw ActionError::conflict( __( 'The file is kept in storage that cannot be reached right now, so it could not be copied. Please try again in a moment.', 'cliezoo' ) );
		}

		return $this->plugin->service( 'files' )->repository()->insert(
			$data + array(
				'extension'       => (string) $row['extension'],
				'mime'            => (string) $row['mime'],
				'size'            => (int) $row['size'],
				'checksum_sha256' => (string) ( $row['checksum_sha256'] ?? '' ),
				'storage_driver'  => (string) $row['storage_driver'],
				'object_key'      => $key,
				'width'           => $row['width'] ?? null,
				'height'          => $row['height'] ?? null,
				'duration'        => $row['duration'] ?? null,
			),
			$actor->member_id ? $actor->member_id : null
		);
	}

	/**
	 * A path for a temporary file, on any request.
	 *
	 * `wp_tempnam()` is an admin-only function: it lives in
	 * wp-admin/includes/file.php, which a front-end REST request never
	 * loads. The test bootstrap does load it, which is how a call to it can
	 * pass every test and fatal for every real upload.
	 *
	 * @param string $what What it is for, for a readable name.
	 * @return string
	 */
	public static function temp_file( string $what ): string {
		// Not `get_temp_dir()`: on a host whose system temp directory is not
		// writable, WordPress falls back to wp-content, and a half-built zip
		// of somebody's files would sit in the document root with six random
		// characters in front of it. The plugin's own private directory is
		// the one place known to be refused by the web server.
		$dir = untrailingslashit( LocalDriver::base() ) . '/tmp';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		LocalDriver::protect( $dir );
		$name = (string) tempnam( $dir, 'cliezoo-' . $what . '-' );
		return '' === $name ? $dir . '/cliezoo-' . $what . '-' . wp_generate_password( 8, false ) : $name;
	}

	/**
	 * Deletes a file's stored objects (purge).
	 *
	 * @param array<string, mixed> $row File row.
	 * @return void
	 */
	public function delete_object( array $row ): void {
		if ( 'upload' !== $row['kind'] || empty( $row['object_key'] ) ) {
			return;
		}
		$keys = array( (string) $row['object_key'] );
		foreach ( (array) json_decode( (string) ( $row['thumbs'] ?? '' ), true ) as $thumb ) {
			if ( is_array( $thumb ) && ! empty( $thumb['key'] ) ) {
				$keys[] = (string) $thumb['key'];
			}
		}
		$this->delete_keys( (string) $row['storage_driver'], $keys, 0 );
	}

	/**
	 * Deletes stored objects; what the storage would not delete now is tried
	 * again later, six times over about five hours, and then reported.
	 *
	 * @param string   $driver  Driver id.
	 * @param string[] $keys    Object keys.
	 * @param int      $attempt Attempts so far.
	 * @return void
	 */
	public function delete_keys( string $driver, array $keys, int $attempt ): void {
		$storage = $this->drivers()->get( $driver );
		$left    = array();
		foreach ( $keys as $key ) {
			try {
				$storage->delete( (string) $key );
			} catch ( \Throwable $e ) {
				$left[] = (string) $key;
				$error  = $e->getMessage();
			}
		}
		if ( ! $left ) {
			return;
		}
		if ( $attempt >= 6 ) {
			Logger::error(
				'Stored objects could not be deleted',
				array(
					'driver' => $driver,
					'keys'   => $left,
					'error'  => $error ?? '',
				)
			);
			do_action(
				'cliezoo_audit',
				'storage_delete_failed',
				array(
					'driver' => $driver,
					'keys'   => $left,
				)
			);
			return;
		}
		$this->plugin->get( Jobs::class )->queue(
			'storage_delete',
			array(
				'driver'  => $driver,
				'keys'    => $left,
				'attempt' => $attempt + 1,
			),
			300 * ( 2 ** $attempt )
		);
	}

	/**
	 * Removes uploads abandoned for more than a day.
	 *
	 * @return int Removed.
	 */
	public function cleanup_stale(): int {
		global $wpdb;
		$table = esc_sql( $this->plugin->entities()->get( 'files' )->table() );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, object_key, storage_driver FROM {$table} WHERE status IN ('uploading','failed') AND created_at < %s LIMIT 50", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from code.
		foreach ( $rows as $row ) {
			$driver = $this->drivers()->get( (string) $row['storage_driver'] );
			if ( $driver instanceof LocalDriver || $driver instanceof StagedDriver ) {
				$driver->cleanup( $this->session( $row ) );
			}
			try {
				$driver->delete( (string) $row['object_key'] );
			} catch ( \RuntimeException $e ) {
				// Out of reach now: the row goes, and the next sweep of the
				// storage itself finds what is left.
				unset( $e );
			}
			$this->plugin->service( 'files' )->repository()->delete( (int) $row['id'] );
		}
		return count( $rows );
	}

	/**
	 * The files and links attached to one record.
	 *
	 * The parent is loaded first, so somebody who cannot open a task cannot
	 * read what is on it; the files are then asked the ordinary question as
	 * well, because a file kept private stays private wherever it is shown.
	 *
	 * @param Actor                $actor Who is asking.
	 * @param array<string, mixed> $in    object_type, object, paging.
	 * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
	 */
	public function attached( Actor $actor, array $in ): array {
		list( $parent, $row ) = ObjectRef::resolve( $this->plugin, $actor, (string) $in['object_type'], (string) $in['object'] );
		$service              = $this->plugin->service( 'files' );
		$query                = new ListQuery();
		$query->limit         = (int) ( $in['limit'] ?? 100 );
		$query->cursor        = (string) ( $in['cursor'] ?? '' );
		$query->sort          = '-created_at';
		$query->where( 't.object_type = %s AND t.object_id = %d', array( $parent->name, (int) $row['id'] ) );
		$result = $service->repository()->list( $query, $this->plugin->policy()->scope( $actor, $service->entity() ) );
		return array(
			'data' => $this->plugin->serializer()->present_many( $service->entity(), $result['rows'], $actor ),
			'meta' => array(
				'next_cursor' => $result['next_cursor'],
				'has_more'    => $result['has_more'],
			),
		);
	}

	/**
	 * Usage meter per driver.
	 *
	 * @return array{total: int, drivers: array<string, int>}
	 */
	public function usage(): array {
		global $wpdb;
		$table = esc_sql( $this->plugin->entities()->get( 'files' )->table() );
		$rows  = (array) $wpdb->get_results( "SELECT storage_driver, COALESCE(SUM(size), 0) AS bytes FROM {$table} WHERE status = 'ready' GROUP BY storage_driver", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from code.
		$out   = array(
			'total'   => 0,
			// What the site was given, in bytes; 0 means nobody set a limit,
			// and a meter with no limit behind it is decoration.
			'quota'   => (int) $this->plugin->settings()->get( 'storage', 'quota_gb', 0 ) * 1073741824,
			'files'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'ready'" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from code.
			'drivers' => array(),
		);
		foreach ( $rows as $row ) {
			$out['drivers'][ (string) $row['storage_driver'] ] = (int) $row['bytes'];
			$out['total']                                     += (int) $row['bytes'];
		}
		return $out;
	}

	/**
	 * Whether the actor can download (not just view) a file.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $row   File row.
	 * @return bool
	 */
	public function can_download( Actor $actor, array $row ): bool {
		$entity = $this->plugin->entities()->get( 'files' );
		if ( ! $this->plugin->policy()->can( $actor, Policy::VIEW, $entity, $row ) ) {
			return false;
		}
		/**
		 * Whether downloading is allowed (object role Viewer cannot download; folder download switches).
		 *
		 * @param bool   $allowed Default true for anyone who can view.
		 * @param array  $row     File row.
		 * @param Actor  $actor   Actor.
		 * @param Entity $entity  Entity.
		 */
		return (bool) apply_filters( 'cliezoo_file_download_allowed', true, $row, $actor, $entity );
	}
}
