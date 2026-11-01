<?php
/**
 * File and folder actions: browsing, filing, approving, sharing, taking a
 * whole folder away at once.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Files;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Storage\Downloads;
use Cliezoo\Infrastructure\Storage\Uploads;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Everything about a file or folder that is not a field (cliezoo.md §6.15).
 */
final class FileActions {

	/**
	 * The route for one folder.
	 */
	private const FOLDER = '/folders/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})';

	/**
	 * The route for one file.
	 */
	private const FILE = '/files/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})';

	/**
	 * Registers the actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	public static function register( ActionRegistry $registry, Plugin $plugin ): void {
		$folders  = static fn(): Folders => $plugin->get( Folders::class );
		$files    = static fn(): Files => $plugin->get( Files::class );
		$may_file = static fn( Actor $actor ): bool => ! $actor->is_guest() && $actor->can_write();

		$registry->add(
			Op::make( 'files.browse', __( 'Open a folder', 'cliezoo' ) )
				->describe( __( 'What is in one folder: the folders inside it, the files, and the trail back to the top.', 'cliezoo' ) )
				->input(
					array(
						'folder'  => Op::uid( false ),
						'client'  => Op::uid( false ),
						'project' => Op::uid( false ),
					)
				)
				->allow( static fn( Actor $actor ): bool => ! $actor->is_guest() )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin, $folders ): array {
						$folder = null;
						$where  = array( 'parent_id' => null );
						if ( ! empty( $in['folder'] ) ) {
							$folder = $plugin->service( 'folders' )->load( $actor, (string) $in['folder'] );
							// Opening a folder is opening a record, and belongs
							// in the strip of what somebody had open lately as
							// much as a client or a project does.
							do_action( 'cliezoo_record_read', $plugin->service( 'folders' )->entity(), $folder, $actor );
							$where = array( 'parent_id' => (int) $folder['id'] );
						} elseif ( ! empty( $in['client'] ) ) {
							$client             = $plugin->service( 'clients' )->load( $actor, (string) $in['client'] );
							$where['client_id'] = (int) $client['id'];
						} elseif ( ! empty( $in['project'] ) ) {
							$project             = $plugin->service( 'projects' )->load( $actor, (string) $in['project'] );
							$where['project_id'] = (int) $project['id'];
						}

						return array(
							'folder'  => $folder ? $plugin->service( 'folders' )->present( $actor, $folder ) : null,
							'trail'   => $folder ? $folders()->trail( $folder, $actor ) : array(),
							'folders' => $folders()->children( $actor, $where ),
							'items'   => $folder ? $folders()->items( $actor, (int) $folder['id'] ) : array(),
						);
					}
				)
				->rest( 'GET', '/files/browse' )
				->readonly()
				->with( array( 'mcp' => 'read' ) )
		);

		$registry->add(
			Op::make( 'folders.download', __( 'Take a folder away', 'cliezoo' ) )
				->describe( __( 'Everything in a folder and below it, as one zip. Says how big it would be first.', 'cliezoo' ) )
				->input( array( 'uid' => Op::uid() ) )
				->allow( static fn( Actor $actor ): bool => ! $actor->is_guest() )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin, $files ): array {
						$folder = $plugin->service( 'folders' )->load( $actor, (string) $in['uid'] );
						return $files()->zip_estimate( $actor, $folder ) + array(
							'folder' => (string) $folder['name'],
						);
					}
				)
				->rest( 'GET', self::FOLDER . '/download' )
				->readonly()
		);

		$registry->add(
			Op::make( 'files.link', __( 'A link to this file', 'cliezoo' ) )
				->describe( __( 'A link that lasts a few minutes and carries its own permission, so the app can hand one to the browser without handing over a credential.', 'cliezoo' ) )
				->input(
					array(
						'uid' => Op::uid(),
						// "view" shows it in the page (what a preview needs);
						// "download" hands it over, which needs download rights.
						'for' => array(
							'type'    => 'string',
							'enum'    => array( 'download', 'view' ),
							'default' => 'download',
						),
					)
				)
				->allow( static fn( Actor $actor ): bool => ! $actor->is_guest() )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin ): array {
						$row  = $plugin->service( 'files' )->load( $actor, (string) $in['uid'] );
						$view = 'view' === ( $in['for'] ?? 'download' ) && Downloads::shown( (string) $row['mime'] );
						if ( ! $view && ! $plugin->get( Uploads::class )->can_download( $actor, $row ) ) {
							throw ActionError::forbidden();
						}
						return array( 'url' => Downloads::signed_url( (string) $row['uid'], 300, $view ? 'view' : 'file' ) );
					}
				)
				->rest( 'GET', self::FILE . '/link' )
				->readonly()
		);

		$registry->add(
			Op::make( 'folders.link', __( 'A link to this whole folder', 'cliezoo' ) )
				->input( array( 'uid' => Op::uid() ) )
				->allow( static fn( Actor $actor ): bool => ! $actor->is_guest() )
				->run(
					static fn( Actor $actor, array $in ): array => array(
						'url' => $plugin->get( Zip::class )->link( $actor, (string) $in['uid'] ),
					)
				)
				->rest( 'GET', self::FOLDER . '/link' )
				->readonly()
		);

		$tools = static fn(): FolderTools => $plugin->get( FolderTools::class );
		$where = array(
			'parent'  => Op::uid( false ),
			'client'  => Op::uid( false ),
			'project' => Op::uid( false ),
		);

		$registry->add(
			Op::make( 'folders.duplicate', __( 'Copy a folder', 'cliezoo' ) )
				->describe( __( 'Copies a folder beside itself, with the folders inside it and, when asked, the files.', 'cliezoo' ) )
				->input(
					array(
						'uid'     => Op::uid(),
						'files'   => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'sharing' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					)
				)
				->allow( static fn( Actor $actor ): bool => $actor->is_staff() && $actor->can_write() )
				->run( static fn( Actor $actor, array $in ): array => $tools()->duplicate( $actor, (string) $in['uid'], ! empty( $in['files'] ), ! empty( $in['sharing'] ) ) )
				->rest( 'POST', self::FOLDER . '/duplicate' )
		);

		$registry->add(
			Op::make( 'folders.save_template', __( 'Keep a folder as a template', 'cliezoo' ) )
				->describe( __( 'Keeps a folder\'s shape — the folders inside it, their colours, icons and switches — as a template, and with files or sharing when asked.', 'cliezoo' ) )
				->input(
					array(
						'uid'      => Op::uid(),
						'name'     => Op::str( 190 ),
						'category' => Op::str( 80 ),
						'files'    => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'sharing'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
					)
				)
				->allow( static fn( Actor $actor ): bool => $actor->is_staff() && ( $actor->is_admin() || $actor->allows( 'templates_manage' ) ) )
				->run( static fn( Actor $actor, array $in ): array => $tools()->save_template( $actor, (string) $in['uid'], (string) ( $in['name'] ?? '' ), (string) ( $in['category'] ?? '' ), ! empty( $in['files'] ), ! empty( $in['sharing'] ) ) )
				->rest( 'POST', self::FOLDER . '/template' )
		);

		$registry->add(
			Op::make( 'folders.from_template', __( 'Make a folder from a template', 'cliezoo' ) )
				->describe( __( 'Makes a folder, and the folders inside it, from a folder template, where you say.', 'cliezoo' ) )
				->input(
					$where + array(
						'template' => Op::uid(),
						'name'     => Op::str( 190 ),
						'files'    => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'sharing'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
					)
				)
				->allow( static fn( Actor $actor ): bool => $actor->is_staff() && $actor->can_write() )
				->run( static fn( Actor $actor, array $in ): array => $tools()->from_template( $actor, (string) $in['template'], (string) ( $in['name'] ?? '' ), $in, ! empty( $in['files'] ), ! empty( $in['sharing'] ) ) )
				->rest( 'POST', '/folders/from-template' )
		);

		$registry->add(
			Op::make( 'files.request_changes', __( 'Ask for changes to a file', 'cliezoo' ) )
				->describe( __( 'The other answer to a review: not approved yet, and what to change, said in the file\'s conversation.', 'cliezoo' ) )
				->input(
					array(
						'uid'  => Op::uid(),
						'note' => Op::str( 5000 ),
					)
				)
				->allow( static fn( Actor $actor ): bool => ! $actor->is_guest() && $actor->can_write() )
				->run( static fn( Actor $actor, array $in ): array => $files()->request_changes( $actor, (string) $in['uid'], (string) ( $in['note'] ?? '' ) ) )
				->rest( 'POST', self::FILE . '/changes' )
		);

		$registry->add(
			Op::make( 'files.approve', __( 'Approve a file', 'cliezoo' ) )
				->describe( __( 'Says who approved it and when. Taking it back is a decision too, and both are written down.', 'cliezoo' ) )
				->input(
					array(
						'uid'      => Op::uid(),
						'approved' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					)
				)
				->allow( static fn( Actor $actor ): bool => ! $actor->is_guest() && $actor->can_write() )
				->run(
					static fn( Actor $actor, array $in ): array => $files()->approve(
						$actor,
						(string) $in['uid'],
						! array_key_exists( 'approved', $in ) || (bool) $in['approved']
					)
				)
				->rest( 'POST', self::FILE . '/approve' )
		);

		$registry->add(
			Op::make( 'files.lock', __( 'Lock a file', 'cliezoo' ) )
				->describe( __( 'A locked file cannot be renamed, moved, trashed or approved until it is unlocked.', 'cliezoo' ) )
				->input(
					array(
						'uid'    => Op::uid(),
						'locked' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					)
				)
				->allow( $may_file )
				->run(
					static fn( Actor $actor, array $in ): array => $files()->lock(
						$actor,
						(string) $in['uid'],
						! array_key_exists( 'locked', $in ) || (bool) $in['locked']
					)
				)
				->rest( 'POST', self::FILE . '/lock' )
		);

		$registry->add(
			Op::make( 'files.duplicate', __( 'Copy a file', 'cliezoo' ) )
				->input(
					array(
						'uid'    => Op::uid(),
						'name'   => Op::str( 200 ),
						'folder' => Op::uid( false ),
					)
				)
				->allow( $may_file )
				->run( static fn( Actor $actor, array $in ): array => $files()->duplicate( $actor, (string) $in['uid'], $in ) )
				->rest( 'POST', self::FILE . '/duplicate' )
		);

		$registry->add(
			Op::make( 'files.move', __( 'File things away', 'cliezoo' ) )
				->describe( __( 'Moves files into a folder. Anything locked is left where it is, and named.', 'cliezoo' ) )
				->input(
					array(
						'files'  => array(
							'type'     => 'array',
							'required' => true,
							'maxItems' => 200,
							'items'    => array( 'type' => 'string' ),
						),
						'folder' => Op::uid( false ),
					)
				)
				->allow( $may_file )
				->run(
					static fn( Actor $actor, array $in ): array => $files()->move(
						$actor,
						array_map( 'strval', (array) $in['files'] ),
						(string) ( $in['folder'] ?? '' )
					)
				)
				->rest( 'POST', '/files/move' )
				->with( array( 'mcp' => 'safe' ) )
		);

		$registry->add(
			Op::make( 'files.kinds', __( 'How files are grouped', 'cliezoo' ) )
				->describe( __( 'The groups the library offers: images, video, documents and the rest.', 'cliezoo' ) )
				->allow( static fn( Actor $actor ): bool => ! $actor->is_guest() )
				->run( static fn(): array => array( 'kinds' => $files()->kinds() ) )
				->rest( 'GET', '/files/kinds' )
				->readonly()
		);

		$registry->add(
			Op::make( 'folders.seed', __( 'Add the usual folders', 'cliezoo' ) )
				->describe( __( 'Gives a client or a project the folders the workspace starts everything with. Never makes one twice.', 'cliezoo' ) )
				->input(
					array(
						'client'  => Op::uid( false ),
						'project' => Op::uid( false ),
					)
				)
				->allow( $may_file )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin, $folders ): array {
						if ( ! empty( $in['project'] ) ) {
							$row  = $plugin->service( 'projects' )->load( $actor, (string) $in['project'], Policy::UPDATE );
							$kind = 'project';
						} elseif ( ! empty( $in['client'] ) ) {
							$row  = $plugin->service( 'clients' )->load( $actor, (string) $in['client'], Policy::UPDATE );
							$kind = 'client';
						} else {
							throw ActionError::validation( __( 'Say which client or project the folders are for.', 'cliezoo' ) );
						}
						return array( 'made' => $folders()->seed_for( $kind, $row ) );
					}
				)
				->rest( 'POST', '/folders/seed' )
		);
	}
}
