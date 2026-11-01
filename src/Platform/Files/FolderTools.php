<?php
/**
 * What a folder can do beyond holding files: its own rules, copies of
 * itself, and templates of its shape.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Files;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Members\Members;
use Cliezoo\Platform\Permissions\AccessService;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * A folder's switches — comments off, approvals off — hold for everything
 * inside it, however deep: a client folder closed to discussion is closed
 * all the way down, or it is not closed.
 *
 * A template is a folder's shape (names, colours, icons, switches and the
 * folders inside), kept among the workspace's templates as type "folders".
 * On its own it is a plan and remembers no folder. Kept "with files" or
 * "with sharing", it remembers the folders it was taken from, so a folder
 * made from it can bring their files or their people along — and only for
 * somebody who could see those files, or share those folders, themselves.
 */
final class FolderTools {

	/**
	 * The most folders one template holds.
	 */
	private const MOST = 200;

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
	 * Whether a switch is on for a folder or any folder it is inside.
	 *
	 * @param int    $folder_id The folder (0: not in one).
	 * @param string $flag      comments_off or approvals_off.
	 * @return bool
	 */
	public function switched_off( int $folder_id, string $flag ): bool {
		$repo = $this->plugin->service( 'folders' )->repository();
		for ( $depth = 0; $folder_id && $depth <= 64; $depth++ ) {
			$row = $repo->find( $folder_id );
			if ( ! $row ) {
				return false;
			}
			if ( ! empty( $row[ $flag ] ) ) {
				return true;
			}
			$folder_id = (int) ( $row['parent_id'] ?? 0 );
		}
		return false;
	}

	/**
	 * Copies a folder beside itself, with the folders inside it and, when
	 * asked, the files.
	 *
	 * @param Actor  $actor   Who is copying.
	 * @param string $uid     The folder.
	 * @param bool   $files   Whether the files come too.
	 * @param bool   $sharing Whether the people it is shared with come too.
	 * @return array<string, mixed> The copy, presented.
	 * @throws ActionError When it cannot be copied.
	 */
	public function duplicate( Actor $actor, string $uid, bool $files, bool $sharing = false ): array {
		$service = $this->plugin->service( 'folders' );
		$source  = $service->load( $actor, $uid );
		$this->may_copy_sharing( $actor, $source, $sharing );
		$tree = $this->shape( $actor, $source );
		$made = $this->build(
			$actor,
			array(
				sprintf(
					/* translators: %s: a folder's name. */
					__( '%s (copy)', 'cliezoo' ),
					(string) $source['name']
				) => $tree,
			),
			array(
				'parent_id'  => $source['parent_id'] ?? null,
				'client_id'  => $source['client_id'] ?? null,
				'project_id' => $source['project_id'] ?? null,
			),
			$files,
			$sharing
		);
		return $service->present( $actor, (array) $service->repository()->find( $made ) );
	}

	/**
	 * Copies a project's folders — and, when asked, their files — into
	 * another project, for a project copied or made from a template.
	 *
	 * @param Actor                $actor  Who is copying.
	 * @param array<string, mixed> $source The project copied.
	 * @param array<string, mixed> $target The new project.
	 * @param bool                 $files  Whether the files come too.
	 * @return int How many top folders were copied.
	 */
	public function copy_project( Actor $actor, array $source, array $target, bool $files ): int {
		$service = $this->plugin->service( 'folders' );
		$policy  = $this->plugin->get( Policy::class );
		$done    = 0;
		foreach ( $service->repository()->where( array( 'project_id' => (int) $source['id'] ), 'name' ) as $row ) {
			if ( ! empty( $row['parent_id'] ) || ! empty( $row['deleted_at'] ) || ! $policy->can( $actor, Policy::VIEW, $service->entity(), $row ) ) {
				continue;
			}
			$this->build(
				$actor,
				array( (string) $row['name'] => $this->shape( $actor, $row ) ),
				array(
					'parent_id'  => null,
					'client_id'  => $target['client_id'] ?? null,
					'project_id' => (int) $target['id'],
				),
				$files
			);
			++$done;
		}
		return $done;
	}

	/**
	 * Keeps a folder's shape as a template.
	 *
	 * @param Actor  $actor    Who is keeping it.
	 * @param string $uid      The folder.
	 * @param string $name     What the template is called.
	 * @param string $category Its category.
	 * @param bool   $files    Whether it brings the files, when used.
	 * @param bool   $sharing  Whether it brings the people, when used.
	 * @return array<string, mixed> The template, presented.
	 * @throws ActionError When the folder cannot be read.
	 */
	public function save_template( Actor $actor, string $uid, string $name, string $category = '', bool $files = false, bool $sharing = false ): array {
		$source = $this->plugin->service( 'folders' )->load( $actor, $uid );
		$this->may_copy_sharing( $actor, $source, $sharing );
		$shape = $this->shape( $actor, $source );
		$keep  = $files || $sharing;
		$top   = $this->node( $source );
		if ( $keep ) {
			$top['from'] = (string) $source['uid'];
		}
		return $this->plugin->service( 'templates' )->create(
			$actor,
			array(
				'type'        => 'folders',
				'name'        => '' !== trim( $name ) ? $name : (string) $source['name'],
				'description' => (string) ( $source['description'] ?? '' ),
				'category'    => $category,
				'content'     => array(
					'folder'   => $top,
					'children' => $this->plan( $shape['children'], $keep ),
					'files'    => $files,
					'sharing'  => $sharing,
				),
			)
		);
	}

	/**
	 * Makes a folder from a template, where the person says.
	 *
	 * @param Actor                $actor    Who is making it.
	 * @param string               $template The template.
	 * @param string               $name     What the new folder is called.
	 * @param array<string, mixed> $where    parent (folder uid) or client / project uids.
	 * @param bool                 $files    Bring the files, if the template kept them.
	 * @param bool                 $sharing  Bring the people, if the template kept them.
	 * @return array<string, mixed> The folder, presented.
	 * @throws ActionError When the template or the place cannot be used.
	 */
	public function from_template( Actor $actor, string $template, string $name, array $where, bool $files = false, bool $sharing = false ): array {
		$row = $this->plugin->service( 'templates' )->load( $actor, $template );
		if ( 'folders' !== (string) $row['type'] ) {
			throw ActionError::not_found();
		}
		$content = is_array( $row['content'] ) ? $row['content'] : (array) json_decode( (string) $row['content'], true );
		$top     = (array) ( $content['folder'] ?? array() );
		$place   = $this->place( $actor, $where );
		$files   = $files && ! empty( $content['files'] );
		$sharing = $sharing && ! empty( $content['sharing'] );
		$shapes  = $this->sources(
			array(
				( '' !== trim( $name ) ? $name : (string) ( $top['name'] ?? $row['name'] ) ) => array(
					'node'     => $top,
					'children' => (array) ( $content['children'] ?? array() ),
				),
			),
			$files || $sharing ? $this->author_of( $row ) : null,
			$sharing
		);
		$made    = $this->build( $actor, $shapes, $place, $files, $sharing );
		$service = $this->plugin->service( 'folders' );
		return $service->present( $actor, (array) $service->repository()->find( $made ) );
	}

	/**
	 * Where a new folder goes: inside a folder the person may add to, or at
	 * the top of a client's or a project's files, or of the workspace.
	 *
	 * @param Actor                $actor Who is asking.
	 * @param array<string, mixed> $where parent / client / project uids.
	 * @return array<string, mixed> Column values.
	 * @throws ActionError When the place cannot be used.
	 */
	private function place( Actor $actor, array $where ): array {
		if ( ! empty( $where['parent'] ) ) {
			$parent = $this->plugin->service( 'folders' )->load( $actor, (string) $where['parent'], Policy::UPDATE );
			return array(
				'parent_id'  => (int) $parent['id'],
				'client_id'  => $parent['client_id'] ?? null,
				'project_id' => $parent['project_id'] ?? null,
			);
		}
		$out = array(
			'parent_id'  => null,
			'client_id'  => null,
			'project_id' => null,
		);
		if ( ! empty( $where['project'] ) ) {
			$project           = $this->plugin->service( 'projects' )->load( $actor, (string) $where['project'] );
			$out['project_id'] = (int) $project['id'];
			$out['client_id']  = $project['client_id'] ?? null;
		} elseif ( ! empty( $where['client'] ) ) {
			$client           = $this->plugin->service( 'clients' )->load( $actor, (string) $where['client'] );
			$out['client_id'] = (int) $client['id'];
		}
		return $out;
	}

	/**
	 * A shape without the ids of the folders it was taken from: a template
	 * is kept long after they have gone, and shared with whoever uses it.
	 * Kept with files or sharing, each folder remembers its source by uid.
	 *
	 * @param array<string, mixed> $children name => {id, node, children}.
	 * @param bool                 $keep     Remember where each came from.
	 * @return array<string, mixed>
	 */
	private function plan( array $children, bool $keep = false ): array {
		$out = array();
		foreach ( $children as $key => $child ) {
			$node = (array) ( $child['node'] ?? array() );
			if ( $keep && ! empty( $child['id'] ) ) {
				$node['from'] = (string) $this->uid_of( (int) $child['id'] );
			}
			$out[ $key ] = array(
				'node'     => $node,
				'children' => $this->plan( (array) ( $child['children'] ?? array() ), $keep ),
			);
		}
		return $out;
	}

	/**
	 * Turns a template's remembered sources back into folder ids, dropping
	 * any that have gone since.
	 *
	 * A template's content can be edited by anybody who manages templates,
	 * so a source is honoured only while the person who kept the template
	 * could still see it — and, for its people, still share it. Somebody
	 * writing another folder's uid into a template gets nothing from it.
	 *
	 * @param array<string, mixed> $shapes  name => {node, children}.
	 * @param Actor|null           $author  Who kept the template (null: no sources wanted).
	 * @param bool                 $sharing Whether the people are wanted.
	 * @return array<string, mixed>
	 */
	private function sources( array $shapes, ?Actor $author, bool $sharing ): array {
		$service = $this->plugin->service( 'folders' );
		$policy  = $this->plugin->get( Policy::class );
		$access  = $this->plugin->get( AccessService::class );
		foreach ( $shapes as $key => $shape ) {
			$from = (string) ( $shape['node']['from'] ?? '' );
			unset( $shapes[ $key ]['node']['from'] );
			if ( $author && '' !== $from ) {
				$row = $service->repository()->find_by_uid( $from );
				if ( $row && empty( $row['deleted_at'] ) && $policy->can( $author, Policy::VIEW, $service->entity(), $row )
					&& ( ! $sharing || $access->can_share( $author, $service->entity(), $row ) ) ) {
					$shapes[ $key ]['id'] = (int) $row['id'];
				}
			}
			$shapes[ $key ]['children'] = $this->sources( (array) ( $shape['children'] ?? array() ), $author, $sharing );
		}
		return $shapes;
	}

	/**
	 * The person who kept a template, as they are now.
	 *
	 * @param array<string, mixed> $template The template row.
	 * @return Actor|null Null when they are gone.
	 */
	private function author_of( array $template ): ?Actor {
		$member = empty( $template['created_by'] ) ? null : $this->plugin->service( 'members' )->repository()->find( (int) $template['created_by'] );
		if ( ! $member || empty( $member['user_id'] ) || 'active' !== (string) ( $member['status'] ?? '' ) ) {
			return null;
		}
		return $this->plugin->get( Members::class )->actor_for( (int) $member['user_id'] );
	}

	/**
	 * Copying who a folder is shared with is sharing it: only for somebody
	 * who may share the folder it comes from.
	 *
	 * @param Actor                $actor   Who is copying.
	 * @param array<string, mixed> $source  The folder.
	 * @param bool                 $sharing Whether sharing is to be copied.
	 * @return void
	 * @throws ActionError When they may not.
	 */
	private function may_copy_sharing( Actor $actor, array $source, bool $sharing ): void {
		if ( $sharing && ! $this->plugin->get( AccessService::class )->can_share( $actor, $this->plugin->service( 'folders' )->entity(), $source ) ) {
			throw ActionError::forbidden();
		}
	}

	/**
	 * Gives a new folder the sharing of the one it was made from.
	 *
	 * @param Actor $actor Who is copying.
	 * @param int   $from  The source folder.
	 * @param int   $to    The new folder.
	 * @return void
	 */
	private function copy_sharing( Actor $actor, int $from, int $to ): void {
		$service = $this->plugin->service( 'folders' );
		$source  = $service->repository()->find( $from );
		$target  = $service->repository()->find( $to );
		$access  = $this->plugin->get( AccessService::class );
		if ( ! $source || ! $target || ! $access->can_share( $actor, $service->entity(), $source ) ) {
			return;
		}
		// One client's people never come along into another client's folder:
		// the team's do, a client's contacts stay with their client.
		$same = (int) ( $source['client_id'] ?? 0 ) === (int) ( $target['client_id'] ?? 0 );
		$service->repository()->update(
			$to,
			array(
				'access'      => $same || 'clients' !== (string) $source['access'] ? (string) $source['access'] : 'private',
				'access_role' => (string) $source['access_role'],
			)
		);
		$access->copy( 'folders', $from, $to, $actor->member_id, ! $same );
	}

	/**
	 * What a folder is, as a template keeps it.
	 *
	 * @param array<string, mixed> $row A folder.
	 * @return array<string, mixed>
	 */
	private function node( array $row ): array {
		return array(
			'name'          => (string) $row['name'],
			'color'         => $row['color'] ?? null,
			'icon'          => (string) ( $row['icon'] ?? '' ),
			'description'   => (string) ( $row['description'] ?? '' ),
			'comments_off'  => ! empty( $row['comments_off'] ),
			'approvals_off' => ! empty( $row['approvals_off'] ),
		);
	}

	/**
	 * A folder and what is inside it, as far as this person may see.
	 *
	 * @param Actor                $actor Who is looking.
	 * @param array<string, mixed> $root  The folder.
	 * @return array{node: array<string, mixed>, children: array<string, mixed>, id: int}
	 */
	private function shape( Actor $actor, array $root ): array {
		$service = $this->plugin->service( 'folders' );
		$policy  = $this->plugin->get( Policy::class );
		$count   = 0;
		$walk    = function ( array $row ) use ( &$walk, &$count, $service, $policy, $actor ): array {
			$children = array();
			foreach ( $service->repository()->where( array( 'parent_id' => (int) $row['id'] ), 'name' ) as $child ) {
				if ( ++$count > self::MOST || ! empty( $child['deleted_at'] ) || ! $policy->can( $actor, Policy::VIEW, $service->entity(), $child ) ) {
					continue;
				}
				$children[ (string) $child['name'] . '#' . (int) $child['id'] ] = $walk( $child );
			}
			return array(
				'id'       => (int) $row['id'],
				'node'     => $this->node( $row ),
				'children' => $children,
			);
		};
		return $walk( $root );
	}

	/**
	 * Makes folders from a shape.
	 *
	 * @param Actor                $actor  Who is making them.
	 * @param array<string, mixed> $shapes name => {node, children, id?}.
	 * @param array<string, mixed> $place   parent_id, client_id, project_id.
	 * @param bool                 $files   Whether each source's files come too.
	 * @param bool                 $sharing Whether each source's sharing comes too.
	 * @return int The id of the first folder made.
	 */
	private function build( Actor $actor, array $shapes, array $place, bool $files, bool $sharing = false ): int {
		$service = $this->plugin->service( 'folders' );
		$first   = 0;
		foreach ( $shapes as $key => $shape ) {
			$node = (array) ( $shape['node'] ?? array() );
			$name = preg_replace( '/#\d+$/', '', (string) $key );
			$made = $service->create(
				$actor,
				array(
					'name'          => mb_substr( (string) ( '' !== (string) $name ? $name : ( $node['name'] ?? __( 'Folder', 'cliezoo' ) ) ), 0, 190 ),
					'parent'        => $place['parent_id'] ? $this->uid_of( (int) $place['parent_id'] ) : null,
					'client'        => ! $place['parent_id'] && $place['client_id'] ? $this->uid_of( (int) $place['client_id'], 'clients' ) : null,
					'project'       => ! $place['parent_id'] && $place['project_id'] ? $this->uid_of( (int) $place['project_id'], 'projects' ) : null,
					'color'         => $node['color'] ?? null,
					'icon'          => (string) ( $node['icon'] ?? '' ),
					'description'   => (string) ( $node['description'] ?? '' ),
					'comments_off'  => ! empty( $node['comments_off'] ),
					'approvals_off' => ! empty( $node['approvals_off'] ),
				)
			);
			$row  = (array) $service->repository()->find_by_uid( (string) $made['uid'] );
			$id   = (int) $row['id'];
			if ( ! $first ) {
				$first = $id;
			}
			// Sharing first, so the files copied in are shared as it says.
			if ( $sharing && ! empty( $shape['id'] ) ) {
				$this->copy_sharing( $actor, (int) $shape['id'], $id );
			} elseif ( ! empty( $shape['id'] ) ) {
				// A copy without its people is not shared wider than what
				// it came from: a folder kept to a few stays kept to its maker.
				$source = $service->repository()->find( (int) $shape['id'] );
				if ( $source && 'inherit' !== (string) ( $source['access'] ?? 'inherit' ) ) {
					$service->repository()->update( $id, array( 'access' => 'private' ) );
				}
			}
			if ( $files && ! empty( $shape['id'] ) ) {
				$this->copy_files( $actor, (int) $shape['id'], (string) $made['uid'] );
			}
			if ( $sharing && ! empty( $shape['id'] ) ) {
				$this->plugin->get( Files::class )->reshare( (array) $service->repository()->find( $id ) );
			}
			if ( ! empty( $shape['children'] ) ) {
				$this->build(
					$actor,
					(array) $shape['children'],
					array(
						'parent_id'  => $id,
						'client_id'  => $row['client_id'] ?? null,
						'project_id' => $row['project_id'] ?? null,
					),
					$files,
					$sharing
				);
			}
		}
		return $first;
	}

	/**
	 * Copies the files of one folder into another.
	 *
	 * @param Actor  $actor  Who is copying.
	 * @param int    $from   The folder they are in.
	 * @param string $into   The new folder's uid.
	 * @return void
	 */
	private function copy_files( Actor $actor, int $from, string $into ): void {
		$files  = $this->plugin->get( Files::class );
		$policy = $this->plugin->get( Policy::class );
		$entity = $this->plugin->service( 'files' )->entity();
		foreach ( $this->plugin->service( 'files' )->repository()->where( array( 'folder_id' => $from ) ) as $file ) {
			if ( ! empty( $file['deleted_at'] ) || 'ready' !== ( $file['status'] ?? '' ) || ! $policy->can( $actor, Policy::VIEW, $entity, $file ) ) {
				continue;
			}
			// A copy of a folder has the same files, by the same names —
			// those this person may take a copy of. One they may only look
			// at stays behind rather than stopping the copy half-way.
			try {
				$files->duplicate(
					$actor,
					(string) $file['uid'],
					array(
						'folder' => $into,
						'name'   => (string) $file['name'],
					)
				);
			} catch ( ActionError $error ) {
				continue;
			}
		}
	}

	/**
	 * A record's uid from its id.
	 *
	 * @param int    $id   Id.
	 * @param string $type Entity.
	 * @return string|null
	 */
	private function uid_of( int $id, string $type = 'folders' ): ?string {
		$row = $this->plugin->service( $type )->repository()->find( $id );
		return $row ? (string) $row['uid'] : null;
	}
}
