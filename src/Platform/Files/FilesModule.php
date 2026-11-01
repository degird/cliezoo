<?php
/**
 * The Files area: folders, and everything you do to a file once it is here.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Files;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Field;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\Rest\RouteRegistry;
use Cliezoo\Infrastructure\Storage\Downloads;
use Cliezoo\Infrastructure\Storage\FileText;
use Cliezoo\Infrastructure\Storage\FileValidator;
use Cliezoo\Infrastructure\Storage\LocalCopy;
use Cliezoo\Infrastructure\Storage\StorageDriverRegistry;
use Cliezoo\Platform\Permissions\AccessService;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;
use Cliezoo\Platform\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uploading a file is the platform's job and was done in M0; this module is
 * what makes a pile of uploads into somewhere you can work (cliezoo.md
 * §6.15). Folders that behave like folders, a place every client and every
 * project starts with, review rounds you can point at, approvals that mean
 * something, and a link you can send to somebody who has no account.
 *
 * The one thing it deliberately does not do is invent a second commenting
 * system: an annotation is a comment on a file with an anchor, so it is
 * searched, notified, resolved and exported by the machinery that already
 * does that for every other record.
 */
final class FilesModule extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'files';
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependencies(): array {
		return array( 'clients' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function switchable(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function entities(): array {
		return array(
			( new Entity(
				'folders',
				array(
					'name'          => Field::text( 190 )->required()->searchable()->sortable()->label( __( 'Folder name', 'cliezoo' ) ),
					'parent_id'     => Field::ref( 'folders' )->filterable()->label( __( 'Inside', 'cliezoo' ) ),
					'client_id'     => Field::ref( 'clients' )->filterable()->label( __( 'Client', 'cliezoo' ) ),
					'project_id'    => Field::ref( 'projects' )->filterable()->label( __( 'Project', 'cliezoo' ) ),
					'description'   => Field::longtext()->searchable(),
					'color'         => Field::color(),
					'icon'          => Field::text( 40 ),
					// A banner across the top of the folder: a picture, or
					// its colour when there is none.
					'cover_file_id' => Field::ref( 'files' )->label( __( 'Cover', 'cliezoo' ) ),
					// Switches that hold for everything inside, however deep.
					'comments_off'  => Field::bool()->label( __( 'Comments off', 'cliezoo' ) ),
					'approvals_off' => Field::bool()->label( __( 'Approvals off', 'cliezoo' ) ),
					// Worked out from the tree, so breadcrumbs and sorting do
					// not cost one query per level.
					'path'          => Field::text( 255 )->readonly()->indexed()->label( __( 'Where it is', 'cliezoo' ) ),
					'depth'         => Field::int()->readonly(),
					// A folder every client or project starts with, remembered
					// by key so it is never seeded twice.
					'is_default'    => Field::bool()->readonly(),
					'default_key'   => Field::text( 40 )->hidden(),
					// What a client sees of a folder, and what they may do in
					// it, is the platform's own sharing — `access` and
					// `access_role`, which `shareable` adds below. A second
					// switch beside it would be one more thing to disagree
					// with the first.
				)
			) )->with(
				array(
					'permission'    => 'files',
					'client_field'  => 'client_id',
					'project_field' => 'project_id',
					'shareable'     => true,
					'searchable'    => true,
					'title_field'   => 'name',
					'default_sort'  => 'name',
					'path'          => 'files?folder={uid}',
					'icon'          => 'folder',
					'indexes'       => array(
						'parent'  => array( 'parent_id', 'deleted_at', 'name' ),
						'client'  => array( 'client_id', 'deleted_at' ),
						'project' => array( 'project_id', 'deleted_at' ),
					),
					'label'         => __( 'Folder', 'cliezoo' ),
					'plural_label'  => __( 'Folders', 'cliezoo' ),
					'create_toggle' => 'create_folders',
				)
			),
		);
	}

	/**
	 * Registers the services, the rules and the actions.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( Folders::class, static fn(): Folders => new Folders( $plugin ) );
		$plugin->container()->set( Files::class, static fn(): Files => new Files( $plugin ) );
		$plugin->container()->set( Zip::class, static fn(): Zip => new Zip( $plugin ) );
		$plugin->container()->set( FolderTools::class, static fn(): FolderTools => new FolderTools( $plugin ) );
		// A client's or project's files, when it is deleted for good.
		( new OwnerGone( $plugin ) )->hooks();

		// A folder with comments off is closed to discussion all the way
		// down: on the folder, and on every file in it or below it.
		add_filter(
			'cliezoo_comments_allowed',
			static function ( bool $allowed, Entity $entity, array $row ) use ( $plugin ): bool {
				if ( ! $allowed ) {
					return false;
				}
				$tools = $plugin->get( FolderTools::class );
				if ( 'folders' === $entity->name ) {
					return ! $tools->switched_off( (int) $row['id'], 'comments_off' );
				}
				// Files, and the boards, docs and conversations kept in a
				// folder beside them.
				return ! $tools->switched_off( (int) ( $row['folder_id'] ?? 0 ), 'comments_off' );
			},
			10,
			3
		);

		// Boards, docs and conversations can live in a folder too, beside
		// the files: a folder is where a piece of work is kept, whatever it is.
		add_filter(
			'cliezoo_entity_fields',
			static function ( array $fields, Entity $entity ): array {
				if ( in_array( $entity->name, array( 'boards', 'docs', 'conversations' ), true ) && ! isset( $fields['folder_id'] ) ) {
					$fields['folder_id'] = Field::ref( 'folders' )->filterable()->label( __( 'Folder', 'cliezoo' ) );
				}
				return $fields;
			},
			10,
			2
		);

		// Where a file lives, when it is opened by itself: the folders it is
		// in, or the record it came with (a task's comment, a message) —
		// the one thing that is always asked about a file somebody found.
		add_filter(
			'cliezoo_files_read',
			static function ( array $out, Actor $actor, array $row ) use ( $plugin ): array {
				$folder          = empty( $row['folder_id'] ) ? null : $plugin->service( 'folders' )->repository()->find( (int) $row['folder_id'] );
				$out['location'] = array(
					'folders'  => $folder ? $plugin->get( Folders::class )->trail( $folder, $actor ) : array(),
					'attached' => null,
				);
				// Whether its folders let anybody discuss or review it.
				$tools                = $plugin->get( FolderTools::class );
				$out['comments_off']  = $tools->switched_off( (int) ( $row['folder_id'] ?? 0 ), 'comments_off' );
				$out['approvals_off'] = $tools->switched_off( (int) ( $row['folder_id'] ?? 0 ), 'approvals_off' );
				$type                 = (string) ( $row['object_type'] ?? '' );
				$id                   = (int) ( $row['object_id'] ?? 0 );
				if ( 'comments' === $type && $id ) {
					// A file sent in a message lives with what the message is on.
					$comment = $plugin->service( 'comments' )->repository()->find( $id );
					$type    = $comment ? (string) $comment['object_type'] : '';
					$id      = $comment ? (int) $comment['object_id'] : 0;
				}
				if ( '' !== $type && $id && $plugin->entities()->has( $type ) ) {
					$record = $plugin->service( $type )->repository()->find( $id );
					if ( $record && $plugin->policy()->can( $actor, 'view', $plugin->entities()->get( $type ), $record ) ) {
						$ref                         = \Cliezoo\Core\Data\ObjectRef::refs( $plugin, $type, array( $id ) );
						$out['location']['attached'] = isset( $ref[ $id ] ) ? array( 'type' => $type ) + $ref[ $id ] : null;
					}
				}
				return $out;
			},
			10,
			3
		);

		// A folder's cover, as a picture the browser can show — to somebody
		// who may see that picture, which is not everybody who may see the
		// folder: a cover can be any image, from anywhere.
		add_filter(
			'cliezoo_present_folders',
			static function ( array $out, Entity $entity, array $rows, Actor $actor ) use ( $plugin ): array {
				$files = $plugin->service( 'files' );
				foreach ( $rows as $i => $row ) {
					if ( ! isset( $out[ $i ] ) ) {
						continue;
					}
					$out[ $i ]['cover_url'] = null;
					if ( empty( $row['cover_file_id'] ) ) {
						continue;
					}
					$cover = $files->repository()->find( (int) $row['cover_file_id'] );
					if ( $cover && empty( $cover['deleted_at'] ) && 'ready' === ( $cover['status'] ?? '' ) && Downloads::is_image( (string) $cover['mime'] )
						&& $plugin->policy()->can( $actor, Policy::VIEW, $files->entity(), $cover ) ) {
						$out[ $i ]['cover_url'] = Downloads::signed_url( (string) $cover['uid'], DAY_IN_SECONDS, 'cover' );
					}
				}
				return $out;
			},
			10,
			4
		);

		// Each file in a list carries a signed link to its thumbnail, so a
		// grid of pictures is pictures, not one request per tile. A signed
		// link, because an <img> cannot send the API's nonce.
		add_filter(
			'cliezoo_present_files',
			static function ( array $out, Entity $entity, array $rows ): array {
				foreach ( $rows as $i => $row ) {
					$thumbs = (array) json_decode( (string) ( $row['thumbs'] ?? '' ), true );
					if ( ! isset( $out[ $i ] ) ) {
						continue;
					}
					$out[ $i ]['thumb']  = isset( $thumbs['medium'] ) && 'ready' === ( $row['status'] ?? '' )
						? Downloads::signed_url( (string) $row['uid'], HOUR_IN_SECONDS, 'thumb_medium' )
						: null;
					$out[ $i ]['viewed'] = Downloads::shown( (string) ( $row['mime'] ?? '' ) );
				}
				return $out;
			},
			10,
			3
		);

		// A whole folder as one download: a real response with a real body,
		// so it is streamed past the JSON serialiser like every other file.
		add_filter( 'rest_pre_serve_request', array( Zip::class, 'stream' ), 10, 4 );
		add_action(
			'cliezoo_serve_signed_folder',
			static function ( string $uid ) use ( $plugin ): void {
				$plugin->get( Zip::class )->serve_signed( $uid );
			}
		);
		$plugin->container()->set( PublicShare::class, static fn(): PublicShare => new PublicShare( $plugin ) );
		add_action(
			'cliezoo_serve_shared_file',
			static function ( string $uid ) use ( $plugin ): void {
				$plugin->get( PublicShare::class )->serve_shared( $uid );
			}
		);

		add_action(
			'cliezoo_register_routes',
			static function ( RouteRegistry $routes ) use ( $plugin ): void {
				$plugin->get( PublicShare::class )->routes( $routes );
				$zip = static fn(): Zip => $plugin->get( Zip::class );
				$routes->add(
					'/folders/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/zip',
					array(
						'methods'             => 'GET',
						'callback'            => static fn( \WP_REST_Request $request ) => $zip()->rest_download( $request ),
						'permission_callback' => static fn( \WP_REST_Request $request ): bool => $zip()->may_download( $request ),
					)
				);
			}
		);

		add_action(
			'cliezoo_register_settings',
			static function ( Settings $settings ): void {
				$settings->register(
					'files',
					array(
						// What a new client or project starts with. Empty means
						// "no folders unless somebody makes one", which is a
						// legitimate way to work.
						'client_folders'  => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array( 'Documents', 'Media', 'Designs' ),
						),
						'project_folders' => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array( 'Brief', 'Drafts', 'Review rounds', 'Final delivery' ),
						),
					)
				);
			}
		);

		// A name is a name, not a path. `display_name()` runs on upload but
		// not on a plain update, so a file could be renamed to
		// "../../evil.sh" and carry that into a zip entry, a header or a
		// disk write. Both names go through it on every write.
		$as_a_name = static function ( array $data ): array {
			if ( isset( $data['name'] ) ) {
				$data['name'] = FileValidator::display_name( (string) $data['name'] );
			}
			return $data;
		};
		foreach ( array( 'files', 'folders' ) as $entity ) {
			add_filter( 'cliezoo_' . $entity . '_before_create', $as_a_name );
			add_filter( 'cliezoo_' . $entity . '_before_update', $as_a_name );
		}

		// Locked means locked, wherever the change comes from: a rule that
		// only one action honours is a rule people learn not to trust, and
		// the generic update and delete routes went round it.
		add_filter(
			'cliezoo_files_before_update',
			static function ( array $data, array $row, Actor $actor ): array {
				if ( $actor->is_client() ) {
					unset( $data['access'], $data['access_role'], $data['client_id'], $data['project_id'], $data['folder_id'], $data['object_type'], $data['object_id'] );
				}
				return $data;
			},
			4,
			3
		);
		add_filter(
			'cliezoo_files_before_update',
			static function ( array $data, array $row ): array {
				if ( empty( $row['locked'] ) ) {
					return $data;
				}
				foreach ( array( 'name', 'folder_id', 'client_id', 'project_id', 'url' ) as $held ) {
					unset( $data[ $held ] );
				}
				return $data;
			},
			5,
			2
		);
		add_filter(
			'cliezoo_files_before_delete',
			static fn( $allowed, array $row ) => empty( $row['locked'] )
				? $allowed
				: __( 'This file is locked. Unlock it first.', 'cliezoo' ),
			10,
			2
		);

		// A folder is the thing you share, so what is in it is shared with
		// it. A loose file keeps its own sharing, which is what the share
		// dialog on a file is for.
		$with_its_folder = static function ( array $data ) use ( $plugin ): array {
			if ( empty( $data['folder_id'] ) ) {
				return $data;
			}
			$folder = $plugin->service( 'folders' )->repository()->find( (int) $data['folder_id'] );
			if ( ! $folder ) {
				return $data;
			}
			// Filing something in a folder gives it that folder's sharing,
			// which is a way of sharing it: it takes the right to change
			// the folder, not merely to see it. Otherwise somebody who may
			// edit one file could put it in a folder the client can open.
			$actor = cliezoo()->actor();
			if ( ! $plugin->policy()->can( $actor, Policy::UPDATE, $plugin->entities()->get( 'folders' ), $folder ) ) {
				throw ActionError::forbidden();
			}
			return $plugin->get( Files::class )->sharing_from( $folder ) + $data;
		};
		add_filter( 'cliezoo_files_before_create', $with_its_folder );
		add_filter( 'cliezoo_files_before_update', $with_its_folder );

		add_action(
			'cliezoo_folders_updated',
			static function ( array $row, array $before ) use ( $plugin ): void {
				if ( (string) $row['access'] === (string) $before['access']
					&& (string) $row['access_role'] === (string) $before['access_role'] ) {
					return;
				}
				$plugin->get( Files::class )->reshare( $row );
			},
			10,
			2
		);
		add_action(
			'cliezoo_access_mode_changed',
			static function ( Entity $entity, array $row ) use ( $plugin ): void {
				if ( 'folders' === $entity->name ) {
					$plugin->get( Files::class )->reshare( $row );
				}
			},
			10,
			2
		);

		// Sharing a folder with somebody shares the folders inside it with
		// them too, and taking it away takes those away (the files follow
		// through member_via, which looks at the folder each file is in).
		add_action(
			'cliezoo_access_granted',
			static function ( Entity $entity, array $row, string $principal_type, int $principal_id, string $role ) use ( $plugin ): void {
				if ( 'folders' !== $entity->name ) {
					return;
				}
				$access = $plugin->get( AccessService::class );
				foreach ( $plugin->get( Folders::class )->descendants( (int) $row['id'] ) as $id ) {
					$access->put( 'folders', $id, $principal_type, $principal_id, $role );
				}
			},
			10,
			5
		);
		add_action(
			'cliezoo_access_revoked',
			static function ( Entity $entity, array $row, string $principal_type, int $principal_id ) use ( $plugin ): void {
				if ( 'folders' !== $entity->name ) {
					return;
				}
				$access = $plugin->get( AccessService::class );
				foreach ( $plugin->get( Folders::class )->descendants( (int) $row['id'] ) as $id ) {
					$access->remove( 'folders', $id, $principal_type, $principal_id );
				}
			},
			10,
			4
		);
		add_action(
			'cliezoo_folders_created',
			static function ( array $row ) use ( $plugin ): void {
				if ( ! empty( $row['parent_id'] ) ) {
					$plugin->get( AccessService::class )->copy( 'folders', (int) $row['parent_id'], (int) $row['id'] );
				}
			}
		);

		// A folder knows where it is before it is saved, and after it moves.
		add_filter(
			'cliezoo_folders_before_create',
			static fn( array $data ): array => $plugin->get( Folders::class )->fill( $data, null )
		);
		add_filter(
			'cliezoo_folders_before_update',
			static fn( array $data, array $row ): array => $plugin->get( Folders::class )->fill( $data, $row ),
			10,
			2
		);
		add_action(
			'cliezoo_folders_updated',
			static function ( array $row, array $before ) use ( $plugin ): void {
				$plugin->get( Folders::class )->after_update( $row, $before );
			},
			10,
			2
		);

		// Somewhere to put things, from the moment there is something to put.
		add_action(
			'cliezoo_clients_created',
			static function ( array $row ) use ( $plugin ): void {
				$plugin->get( Folders::class )->seed_for( 'client', $row );
			}
		);
		add_action(
			'cliezoo_projects_created',
			static function ( array $row ) use ( $plugin ): void {
				$plugin->get( Folders::class )->seed_for( 'project', $row );
			}
		);

		// Trashing a folder takes what is inside it with it, so it says what
		// that is and waits to be told again.
		add_filter(
			'cliezoo_folders_before_delete',
			static fn( $allowed, array $row ) => $plugin->get( Folders::class )->may_trash( $allowed, $row ),
			10,
			2
		);

		// What a client and a project say about themselves.
		add_filter(
			'cliezoo_client_summary',
			static fn( array $out, array $client, Actor $actor ): array => $plugin->get( Files::class )->client_summary( $out, $client, $actor ),
			10,
			3
		);
		add_filter(
			'cliezoo_portal_modules',
			static function ( array $modules ): array {
				$modules[] = 'files';
				return $modules;
			}
		);
		add_filter(
			'cliezoo_project_parts',
			static function ( array $parts ): array {
				$parts[] = array(
					'id'    => 'folders',
					'label' => __( 'The folders, empty', 'cliezoo' ),
				);
				$parts[] = array(
					'id'    => 'files',
					'label' => __( 'The folders with their files', 'cliezoo' ),
				);
				return $parts;
			}
		);
		add_action(
			'cliezoo_project_duplicated',
			static function ( array $row, array $source, array $parts_wanted, Actor $actor ) use ( $plugin ): void {
				// With their files, or empty: one copy of the folders either way.
				if ( in_array( 'files', $parts_wanted, true ) ) {
					$plugin->get( FolderTools::class )->copy_project( $actor, $source, $row, true );
				} elseif ( in_array( 'folders', $parts_wanted, true ) ) {
					$plugin->get( Folders::class )->copy_tree( $actor, $source, $row );
				}
			},
			10,
			4
		);

		// Reading and filing is something an agent may do; deleting is not.
		add_filter(
			'cliezoo_entity_action_meta',
			static function ( array $extra, Entity $entity, string $verb ): array {
				if ( ! in_array( $entity->name, array( 'folders', 'files' ), true ) ) {
					return $extra;
				}
				if ( in_array( $verb, array( 'list', 'get' ), true ) ) {
					$extra['mcp'] = 'read';
				} elseif ( in_array( $verb, array( 'create', 'update' ), true ) ) {
					$extra['mcp'] = 'safe';
				}
				return $extra;
			},
			10,
			3
		);

		add_action(
			'cliezoo_register_actions',
			static function ( ActionRegistry $registry ) use ( $plugin ): void {
				FileActions::register( $registry, $plugin );
			}
		);
	}
}
