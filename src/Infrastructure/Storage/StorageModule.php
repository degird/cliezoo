<?php
/**
 * Storage module: the files table, uploads and downloads.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Storage;

use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Field;
use Cliezoo\Core\Data\ObjectRef;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\Rest\RouteRegistry;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every stored or linked file (uploads, attachments, logos, receipts,
 * generated PDFs) is a `files` row pointing at a storage driver object.
 */
final class StorageModule extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'storage';
	}

	/**
	 * {@inheritDoc}
	 */
	public function switchable(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function entities(): array {
		return array(
			( new Entity(
				'files',
				array(
					'name'                  => Field::text( 200 )->required()->searchable()->indexed(),
					'kind'                  => Field::enum( array( 'upload', 'link', 'embed' ) )->default( 'upload' )->readonly()->filterable(),
					'url'                   => Field::url(),
					'description'           => Field::longtext()->searchable(),
					'extension'             => Field::text( 12 )->readonly()->filterable(),
					'mime'                  => Field::text( 120 )->readonly()->filterable(),
					'size'                  => Field::bigint()->readonly()->sortable(),
					'checksum_sha256'       => Field::text( 64 )->hidden(),
					'storage_driver'        => Field::text( 20 )->default( 'local' )->readonly(),
					'storage_connection_id' => Field::int()->hidden(),
					'object_key'            => Field::text( 255 )->hidden(),
					'thumbs'                => Field::json()->hidden(),
					'width'                 => Field::int()->readonly(),
					'height'                => Field::int()->readonly(),
					'duration'              => Field::int()->readonly(),
					'status'                => Field::enum( array( 'uploading', 'ready', 'failed' ) )->default( 'uploading' )->readonly()->filterable(),
					'purpose'               => Field::text( 30 )->default( 'file' )->filterable(),
					'folder_id'             => Field::ref( 'folders' )->filterable(),
					'client_id'             => Field::ref( 'clients' )->filterable(),
					'project_id'            => Field::ref( 'projects' )->filterable(),
					'object_type'           => Field::text( 40 ),
					'object_id'             => Field::int(),
					'color'                 => Field::color(),
					'locked'                => Field::bool(),
					'approved_by'           => Field::user()->readonly(),
					'approved_at'           => Field::datetime()->readonly()->filterable(),
					// The other answer to a review: not approved, and why.
					'changes_by'            => Field::user()->readonly()->label( __( 'Changes asked for by', 'cliezoo' ) ),
					'changes_at'            => Field::datetime()->readonly()->filterable()->label( __( 'Changes asked for', 'cliezoo' ) ),
					'version'               => Field::int()->default( 1 )->readonly(),
					'upload_parts'          => Field::int()->hidden(),
					'download_count'        => Field::int()->default( 0 )->readonly(),
				)
			) )->with(
				array(
					'shareable'     => true,
					'searchable'    => true,
					'custom_fields' => true,
					'permission'    => 'files',
					'client_field'  => 'client_id',
					'project_field' => 'project_id',
					'title_field'   => 'name',
					'path'          => 'files?file={uid}',
					// A folder is the thing you share: its people see what is in it.
					'member_via'    => array( 'folders', 'folder_id' ),
					'icon'          => 'file',
					'label'         => __( 'File', 'cliezoo' ),
					'plural_label'  => __( 'Files', 'cliezoo' ),
					'indexes'       => array(
						'folder'  => array( 'folder_id', 'deleted_at', 'name' ),
						'object'  => array( 'object_type', 'object_id' ),
						'status'  => array( 'status', 'created_at' ),
						'project' => array( 'project_id', 'deleted_at' ),
						'client'  => array( 'client_id', 'deleted_at' ),
					),
				)
			),
		);
	}

	/**
	 * Registers services and routes.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( StorageDriverRegistry::class, static fn(): StorageDriverRegistry => new StorageDriverRegistry() );
		$plugin->container()->set( Uploads::class, static fn(): Uploads => new Uploads( $plugin ) );
		$uploads = static fn(): Uploads => $plugin->get( Uploads::class );

		// Embeds made while the Embeds module was on stay as they were when
		// it is off: hidden from clients, and their address unchanged, since
		// nothing is there to check a new one.
		add_filter(
			'cliezoo_policy_scope_extra',
			static function ( $extra, Actor $actor, Entity $entity ) use ( $plugin ) {
				if ( 'files' !== $entity->name || ! $actor->is_client() || $plugin->module_active( 'embeds' ) ) {
					return $extra;
				}
				$mine = "t.kind <> 'embed'";
				return is_array( $extra ) && '' !== (string) ( $extra[0] ?? '' )
					? array( '(' . $extra[0] . ') AND ' . $mine, (array) $extra[1] )
					: array( $mine, array() );
			},
			6,
			3
		);
		add_filter(
			'cliezoo_files_before_update',
			static function ( array $data, array $row ) use ( $plugin ): array {
				if ( 'embed' === (string) ( $row['kind'] ?? '' ) && ! $plugin->module_active( 'embeds' ) ) {
					unset( $data['url'] );
				}
				return $data;
			},
			8,
			2
		);

		add_action(
			'cliezoo_register_settings',
			static function ( Settings $settings ): void {
				$settings->register(
					'storage',
					array(
						'default_driver'     => array(
							'type'    => 'string',
							'default' => 'local',
						),
						'max_file_mb'        => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
						'max_file_mb_client' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
						'quota_gb'           => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
						'x_sendfile'         => array(
							'type'    => 'string',
							'enum'    => array( '', 'x-sendfile', 'x-accel-redirect' ),
							'default' => '',
						),
						// nginx's X-Accel-Redirect takes an address, not a
						// path: the `internal` location the host maps to the
						// private folder, e.g. /cliezoo-private-internal/.
						// Without one, files stream through PHP as usual.
						'x_accel_location'   => array(
							'type'    => 'string',
							'default' => '',
						),
					)
				);
			}
		);
		add_action(
			'cliezoo_every_day',
			static function () use ( $uploads ): void {
				$uploads()->cleanup_stale();
			}
		);
		add_action(
			'cliezoo_record_purging',
			static function ( Entity $entity, array $row ) use ( $uploads ): void {
				if ( 'files' === $entity->name ) {
					$uploads()->delete_object( $row );
				}
			},
			10,
			2
		);
		// A delete the storage refused, tried again (Uploads::delete_keys()).
		add_action(
			'cliezoo_job_storage_delete',
			static function ( $args ) use ( $uploads ): void {
				$args = (array) $args;
				$uploads()->delete_keys( (string) ( $args['driver'] ?? '' ), array_map( 'strval', (array) ( $args['keys'] ?? array() ) ), (int) ( $args['attempt'] ?? 1 ) );
			}
		);
		add_filter( 'rest_pre_serve_request', array( Downloads::class, 'serve' ), 10, 4 );
		add_filter(
			'cliezoo_entity_verbs',
			static fn( array $verbs, Entity $entity ): array => 'files' === $entity->name ? array_values( array_diff( $verbs, array( 'create' ) ) ) : $verbs,
			10,
			2
		);
		add_action(
			'cliezoo_register_routes',
			static function ( RouteRegistry $routes ) use ( $uploads, $plugin ): void {
				$uid = '(?P<uid>[0-9A-HJKMNP-TV-Z]{26})';
				$routes->add(
					'/files/' . $uid . '/chunks/(?P<part>\d{1,5})',
					array(
						'methods'             => 'PUT',
						'callback'            => static fn( \WP_REST_Request $request ) => $uploads()->rest_chunk( $request ),
						'permission_callback' => static fn(): bool => ! $plugin->actor()->is_guest(),
					)
				);
				$routes->add(
					'/files/' . $uid . '/download',
					array(
						'methods'             => 'GET',
						'callback'            => static fn( \WP_REST_Request $request ) => ( new Downloads( $plugin ) )->rest_download( $request ),
						'permission_callback' => static fn(): bool => ! $plugin->actor()->is_guest(),
					)
				);
				$routes->add(
					'/files/' . $uid . '/thumb/(?P<size>small|medium|large)',
					array(
						'methods'             => 'GET',
						'callback'            => static fn( \WP_REST_Request $request ) => ( new Downloads( $plugin ) )->rest_thumb( $request ),
						'permission_callback' => static fn(): bool => ! $plugin->actor()->is_guest(),
					)
				);
			}
		);
		add_action(
			'cliezoo_register_actions',
			static function ( ActionRegistry $registry ) use ( $uploads ): void {
				$target = array(
					'folder'      => Op::uid( false ),
					'client'      => Op::uid( false ),
					'project'     => Op::uid( false ),
					'object_type' => array(
						'type'    => 'string',
						'pattern' => '^[a-z_]{2,40}$',
					),
					'object'      => Op::uid( false ),
					'purpose'     => array(
						'type'    => 'string',
						'pattern' => '^[a-z_]{2,30}$',
						'default' => 'file',
					),
				);
				$registry->add(
					Op::make( 'files.upload_session', __( 'Start an upload', 'cliezoo' ) )
						->describe( __( 'Starts a chunked, resumable upload. Send 5 MB chunks to PUT /files/{uid}/chunks/{n}, then POST /files/{uid}/complete.', 'cliezoo' ) )
						->input(
							$target + array(
								'name'   => Op::str( 255, true ),
								'size'   => array(
									'type'     => 'integer',
									'minimum'  => 0,
									'required' => true,
								),
								'mime'   => Op::str( 120 ),
								// The uploader can send the parts straight to
								// the storage, when the storage allows it.
								'direct' => array( 'type' => 'boolean' ),
							)
						)
						->allow( static fn( Actor $a ): bool => ! $a->is_guest() )
						->run( static fn( Actor $a, array $in ) => $uploads()->start( $a, $in ) )
						->rest( 'POST', '/files/upload-session' )
				);
				$registry->add(
					Op::make( 'files.upload_status', __( 'Upload progress', 'cliezoo' ) )
						->input( array( 'uid' => Op::uid() ) )
						->allow( static fn( Actor $a ): bool => ! $a->is_guest() )
						->run( static fn( Actor $a, array $in ) => $uploads()->status( $a, (string) $in['uid'] ) )
						->rest( 'GET', '/files/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/upload-session' )
						->readonly()
				);
				$registry->add(
					Op::make( 'files.complete', __( 'Finish an upload', 'cliezoo' ) )
						->input( array( 'uid' => Op::uid() ) )
						->allow( static fn( Actor $a ): bool => ! $a->is_guest() )
						->run( static fn( Actor $a, array $in ) => $uploads()->complete( $a, (string) $in['uid'] ) )
						->rest( 'POST', '/files/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/complete' )
				);
				$registry->add(
					Op::make( 'files.add_link', __( 'Add a file by link', 'cliezoo' ) )
						->describe( __( 'Adds a Figma, Drive or other link as a file that uses no storage.', 'cliezoo' ) )
						->input(
							$target + array(
								'url'  => array(
									'type'     => 'string',
									'format'   => 'uri',
									'required' => true,
								),
								'name' => Op::str( 200 ),
							)
						)
						->allow( static fn( Actor $a ): bool => ! $a->is_guest() )
						->run( static fn( Actor $a, array $in ) => $uploads()->add_link( $a, $in ) )
						->rest( 'POST', '/files/links' )
				);
				// What is attached to a record. Uploading against one has
				// worked since the upload session was written — object_type
				// and object are in its input — and there was no way to ask
				// what came of it, so every module that wanted an
				// attachments panel had to go without one.
				$registry->add(
					Op::make( 'files.attached', __( 'Files on a record', 'cliezoo' ) )
						->describe( __( 'The files and links attached to one record: a task, a deal, a document.', 'cliezoo' ) )
						->input( ObjectRef::schema() + Op::paging( 100 ) )
						->allow( static fn( Actor $a ): bool => ! $a->is_guest() )
						->run( static fn( Actor $a, array $in ) => $uploads()->attached( $a, $in ) )
						->rest( 'GET', '/attachments' )
						->readonly()
						->with( array( 'mcp' => 'read' ) )
				);
				$registry->add(
					Op::make( 'storage.usage', __( 'Storage usage', 'cliezoo' ) )
						->allow( static fn( Actor $a ): bool => $a->is_staff() )
						->run( static fn() => $uploads()->usage() )
						->rest( 'GET', '/storage/usage' )
						->readonly()
				);
			}
		);
	}
}
