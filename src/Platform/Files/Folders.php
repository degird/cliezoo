<?php
/**
 * Folders: the tree, where each one sits in it, and what a new client or
 * project starts with.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Files;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Data\ListQuery;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * A folder tree that behaves like one: it knows where it is, it refuses to
 * be put inside itself, it does not go so deep that nobody can find
 * anything, and it will not quietly swallow what is inside it.
 *
 * `path` and `depth` are written down rather than walked, because a
 * breadcrumb on every row of a file list is otherwise one query per level
 * per row (cliezoo.md §6.15).
 */
final class Folders {

	/**
	 * How deep a tree may go. Deeper than this is a filing system nobody
	 * can navigate, and every level costs a join somewhere.
	 */
	public const MAX_DEPTH = 10;

	/**
	 * How many folders one place shows. Past this, a person is looking for
	 * something rather than browsing, and the library is where that is done.
	 */
	public const PAGE = 200;

	/**
	 * How many pages of folders one listing will read through looking for
	 * the ones this person may see. Two hundred at a time, so a studio
	 * with five thousand folders still answers, and a run away with a
	 * broken filter still stops.
	 */
	public const PAGES = 25;

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
	 * Works out where a folder sits, and refuses the impossible places.
	 *
	 * @param array<string, mixed>      $data     Data about to be saved.
	 * @param array<string, mixed>|null $existing Current row, on update.
	 * @return array<string, mixed>
	 * @throws ActionError When the folder would be inside itself or too deep.
	 */
	public function fill( array $data, ?array $existing ): array {
		$parent_id = array_key_exists( 'parent_id', $data )
			? (int) $data['parent_id']
			: (int) ( $existing['parent_id'] ?? 0 );
		$parent    = $parent_id ? $this->repository()->find( $parent_id ) : null;
		if ( $parent_id && ! $parent ) {
			throw ActionError::conflict( __( 'That folder has gone.', 'cliezoo' ) );
		}

		if ( $existing && $parent ) {
			$inside = $this->descendants( (int) $existing['id'] );
			if ( (int) $parent['id'] === (int) $existing['id'] || in_array( (int) $parent['id'], $inside, true ) ) {
				throw ActionError::conflict( __( 'A folder cannot be put inside itself.', 'cliezoo' ) );
			}
		}

		// A folder belongs where its parent belongs; only a folder at the top
		// says so for itself.
		if ( $parent ) {
			$data['client_id']  = $parent['client_id'] ?? null;
			$data['project_id'] = $parent['project_id'] ?? null;
		}
		// A new folder inside another is shared as that one is; its people
		// follow once it exists (FilesModule, on cliezoo_folders_created).
		if ( ! $existing && $parent ) {
			$data['access']      = (string) ( $parent['access'] ?? 'inherit' );
			$data['access_role'] = (string) ( $parent['access_role'] ?? 'viewer' );
		}

		$depth = $parent ? (int) $parent['depth'] + 1 : 0;
		// Moving a branch moves everything under it, so what matters is
		// where the deepest thing in it would land, not where this folder
		// lands. Past the cap the tree stops being walkable and paths below
		// it quietly stop being kept up to date.
		$below = $existing ? $this->height( (int) $existing['id'] ) : 0;
		if ( $depth + $below >= self::MAX_DEPTH ) {
			throw ActionError::conflict(
				sprintf(
					/* translators: %d: how many levels deep folders may go. */
					__( 'Folders can be %d deep. Anything more and nobody finds anything.', 'cliezoo' ),
					self::MAX_DEPTH
				)
			);
		}

		$name          = (string) ( $data['name'] ?? $existing['name'] ?? '' );
		$data['depth'] = $depth;
		$data['path']  = $this->path_for( $parent, $name );
		return $data;
	}

	/**
	 * Moves the whole branch when a folder is renamed or moved.
	 *
	 * @param array<string, mixed> $row    The folder now.
	 * @param array<string, mixed> $before The folder before.
	 * @return void
	 */
	public function after_update( array $row, array $before ): void {
		$was = (string) ( $before['path'] ?? '' );
		$now = (string) ( $row['path'] ?? '' );
		if ( $was === $now && (int) $before['depth'] === (int) $row['depth'] ) {
			return;
		}
		$shift = (int) $row['depth'] - (int) $before['depth'];
		foreach ( $this->descendants( (int) $row['id'] ) as $id ) {
			$child = $this->repository()->find( $id );
			if ( ! $child ) {
				continue;
			}
			$this->repository()->update(
				$id,
				array(
					'path'       => $now . substr( (string) $child['path'], strlen( $was ) ),
					'depth'      => (int) $child['depth'] + $shift,
					'client_id'  => $row['client_id'] ?? null,
					'project_id' => $row['project_id'] ?? null,
				)
			);
		}
	}

	/**
	 * Whether a folder may go in the trash, and what it would take with it.
	 *
	 * @param true|string          $allowed What has been decided so far.
	 * @param array<string, mixed> $row     The folder.
	 * @return true|string
	 */
	public function may_trash( $allowed, array $row ) {
		if ( true !== $allowed ) {
			return $allowed;
		}
		if ( ! empty( $row['is_default'] ) ) {
			return __( 'This folder comes with every client and project, so it stays. You can empty it instead.', 'cliezoo' );
		}
		$counts = $this->contents( (int) $row['id'] );
		$holds  = array();
		if ( $counts['files'] ) {
			$holds[] = sprintf(
				/* translators: %d: number of files. */
				_n( '%d file', '%d files', $counts['files'], 'cliezoo' ),
				$counts['files']
			);
		}
		if ( $counts['folders'] ) {
			$holds[] = sprintf(
				/* translators: %d: number of folders. */
				_n( '%d folder', '%d folders', $counts['folders'], 'cliezoo' ),
				$counts['folders']
			);
		}
		if ( $holds ) {
			return sprintf(
				/* translators: %s: what is still in the folder, e.g. "3 files and 1 folder". */
				__( 'This folder still holds %s. Move or trash them first.', 'cliezoo' ),
				implode( __( ' and ', 'cliezoo' ), $holds )
			);
		}
		return true;
	}

	/**
	 * What is directly inside a folder.
	 *
	 * @param int $folder_id Folder id.
	 * @return array{files: int, folders: int}
	 */
	public function contents( int $folder_id ): array {
		// Only what finished: an upload that failed halfway is a row in the
		// table, and counting it would make a folder claim something it
		// cannot show you.
		// What is actually in there to open: not a half-finished upload, and
		// not something archived, which the lists below do not show either.
		$files   = array_filter(
			$this->plugin->service( 'files' )->repository()->where(
				array(
					'folder_id' => $folder_id,
					'status'    => 'ready',
				)
			),
			static fn( array $row ): bool => empty( $row['archived_at'] )
		);
		$folders = array_filter(
			$this->repository()->where( array( 'parent_id' => $folder_id ) ),
			static fn( array $row ): bool => empty( $row['archived_at'] )
		);
		return array(
			'files'   => count( $files ),
			'folders' => count( $folders ),
		);
	}

	/**
	 * Every folder below one, by id.
	 *
	 * Walked rather than queried by path, because a path is text a person
	 * can rename into anything and ids cannot.
	 *
	 * @param int $folder_id Folder id.
	 * @return int[]
	 */
	public function descendants( int $folder_id ): array {
		$out   = array();
		$level = array( $folder_id );
		for ( $depth = 0; $depth < self::MAX_DEPTH && $level; $depth++ ) {
			$next = array();
			foreach ( $level as $id ) {
				foreach ( $this->repository()->where( array( 'parent_id' => $id ) ) as $child ) {
					$out[]  = (int) $child['id'];
					$next[] = (int) $child['id'];
				}
			}
			$level = $next;
		}
		return $out;
	}

	/**
	 * Whether a folder is one folder, or somewhere below it.
	 *
	 * Walking up is cheaper than listing everything below, and it is the
	 * question being asked. There is one of these on purpose: two ways of
	 * answering "is this inside what was shared?" is two ways of getting it
	 * wrong.
	 *
	 * @param int                  $root_id The folder to be inside.
	 * @param array<string, mixed> $folder  Candidate.
	 * @return bool
	 */
	public function is_inside( int $root_id, array $folder ): bool {
		$at = $folder;
		for ( $step = 0; $step <= self::MAX_DEPTH; $step++ ) {
			if ( (int) $at['id'] === $root_id ) {
				return true;
			}
			if ( empty( $at['parent_id'] ) ) {
				return false;
			}
			$parent = $this->repository()->find( (int) $at['parent_id'] );
			if ( ! $parent ) {
				return false;
			}
			$at = $parent;
		}
		return false;
	}

	/**
	 * How many levels there are below one folder.
	 *
	 * @param int $folder_id Folder id.
	 * @return int
	 */
	public function height( int $folder_id ): int {
		$deepest = 0;
		$level   = array( $folder_id );
		for ( $depth = 1; $depth <= self::MAX_DEPTH && $level; $depth++ ) {
			$next = array();
			foreach ( $level as $id ) {
				foreach ( $this->repository()->where( array( 'parent_id' => $id ) ) as $child ) {
					$next[] = (int) $child['id'];
				}
			}
			if ( $next ) {
				$deepest = $depth;
			}
			$level = $next;
		}
		return $deepest;
	}

	/**
	 * The folders above one, outermost first, for a breadcrumb.
	 *
	 * With a viewer, it stops at the first folder they may not see: a
	 * client given one folder deep inside does not learn what the folders
	 * above it are called.
	 *
	 * @param array<string, mixed> $folder The folder.
	 * @param Actor|null           $viewer Whose breadcrumb, if anybody's.
	 * @return array<int, array<string, string>>
	 */
	public function trail( array $folder, ?Actor $viewer = null ): array {
		$out    = array();
		$at     = $folder;
		$seen   = 0;
		$policy = $this->plugin->get( Policy::class );
		$entity = $this->plugin->service( 'folders' )->entity();
		while ( $at && $seen < self::MAX_DEPTH ) {
			if ( $viewer && ! $policy->can( $viewer, Policy::VIEW, $entity, $at ) ) {
				break;
			}
			array_unshift(
				$out,
				array(
					'uid'  => (string) $at['uid'],
					'name' => (string) $at['name'],
				)
			);
			$parent = empty( $at['parent_id'] ) ? null : $this->repository()->find( (int) $at['parent_id'] );
			$at     = $parent;
			++$seen;
		}
		return $out;
	}

	/**
	 * The folders one person may see under a parent (or at the top of a
	 * client or project).
	 *
	 * @param Actor                $actor Who is asking.
	 * @param array<string, mixed> $where parent, client, project.
	 * @return array<int, array<string, mixed>>
	 */
	public function children( Actor $actor, array $where ): array {
		$service = $this->plugin->service( 'folders' );
		$policy  = $this->plugin->get( Policy::class );
		$out     = array();
		// Read in pages, and stop when there are enough to show or there
		// are no more to read.
		//
		// Each folder costs a policy check and two counts, so this cannot
		// read a whole workspace: that is a few hundred queries anybody
		// signed in could ask for as often as they liked. But the cap
		// belongs on the reading, not on the answer. Capping first and
		// filtering afterwards meant a client whose folder sorted past the
		// first page was told nothing had been shared with them at all —
		// which happens to every client as soon as a studio has more
		// folders than a page.
		for ( $page = 0; $page < self::PAGES; $page++ ) {
			$rows = $this->repository()->where( $where, 'name', self::PAGE, false, $page * self::PAGE );
			foreach ( $rows as $row ) {
				// The policy already knows what a client may see, because a
				// folder is shared the same way everything else is.
				if ( ! $policy->can( $actor, Policy::VIEW, $service->entity(), $row ) ) {
					continue;
				}
				if ( ! empty( $row['archived_at'] ) ) {
					continue;
				}
				$out[] = $service->present( $actor, $row ) + $this->contents( (int) $row['id'] ) + array(
					// At the top of the whole area every client's "Documents"
					// looks like every other one, so each says whose it is.
					'belongs_to' => $this->belongs_to( $row ),
				);
			}
			if ( count( $rows ) < self::PAGE || count( $out ) >= self::PAGE ) {
				break;
			}
		}
		usort( $out, static fn( array $a, array $b ): int => strcasecmp( (string) $a['name'], (string) $b['name'] ) );
		return array_slice( $out, 0, self::PAGE );
	}

	/**
	 * The boards, docs and conversations kept in a folder, as far as this
	 * person may see them.
	 *
	 * @param Actor $actor     Who is looking.
	 * @param int   $folder_id The folder.
	 * @return array<int, array<string, mixed>>
	 */
	public function items( Actor $actor, int $folder_id ): array {
		$out = array();
		foreach ( array( 'boards', 'docs', 'conversations' ) as $type ) {
			if ( ! $this->plugin->entities()->has( $type ) ) {
				continue;
			}
			$entity = $this->plugin->entities()->get( $type );
			if ( ! $entity->has_column( 'folder_id' ) || ! $this->plugin->get( Policy::class )->can( $actor, Policy::VIEW, $entity ) ) {
				continue;
			}
			$query        = new ListQuery();
			$query->limit = self::PAGE;
			$query->where( 't.folder_id = %d', array( $folder_id ) );
			$rows = $this->plugin->service( $type )->list( $actor, $query );
			foreach ( (array) ( $rows['data'] ?? array() ) as $row ) {
				$out[] = array(
					'type'       => $type,
					'kind'       => '' !== $entity->label ? $entity->label : $type,
					'uid'        => (string) $row['uid'],
					'label'      => wp_strip_all_tags( (string) ( $row[ $entity->title_field ] ?? '' ) ),
					'icon'       => $entity->icon,
					'updated_at' => $row['updated_at'] ?? null,
				);
			}
		}
		return $out;
	}

	/**
	 * Whose folder this is, for a list that mixes several clients.
	 *
	 * @param array<string, mixed> $row Folder row.
	 * @return string
	 */
	private function belongs_to( array $row ): string {
		if ( ! empty( $row['project_id'] ) ) {
			$project = $this->plugin->service( 'projects' )->repository()->find( (int) $row['project_id'] );
			if ( $project ) {
				return (string) $project['name'];
			}
		}
		if ( ! empty( $row['client_id'] ) ) {
			$client = $this->plugin->service( 'clients' )->repository()->find( (int) $row['client_id'] );
			if ( $client ) {
				return (string) $client['name'];
			}
		}
		return '';
	}

	/**
	 * Gives a new client or project the folders the workspace says it
	 * should start with.
	 *
	 * @param string               $kind client|project.
	 * @param array<string, mixed> $row  The client or project.
	 * @return int How many were made.
	 */
	public function seed_for( string $kind, array $row ): int {
		$names = (array) $this->plugin->settings()->get(
			'files',
			'client' === $kind ? 'client_folders' : 'project_folders',
			array()
		);
		$made  = 0;
		foreach ( array_values( $names ) as $position => $name ) {
			$name = trim( (string) $name );
			if ( '' === $name || $position >= 20 ) {
				continue;
			}
			$key    = $kind . ':' . sanitize_key( $name );
			$column = 'client' === $kind ? 'client_id' : 'project_id';
			if ( $this->repository()->where(
				array(
					'default_key' => $key,
					$column       => (int) $row['id'],
				)
			) ) {
				continue;
			}
			$this->repository()->insert(
				array(
					'name'        => $name,
					'client_id'   => 'client' === $kind ? (int) $row['id'] : ( $row['client_id'] ?? null ),
					'project_id'  => 'project' === $kind ? (int) $row['id'] : null,
					'is_default'  => 1,
					'default_key' => $key,
					'depth'       => 0,
					'path'        => $this->path_for( null, $name ),
				)
			);
			++$made;
		}
		return $made;
	}

	/**
	 * Copies a project's folders, without what is in them.
	 *
	 * A copy of a project is a plan, not an archive: the shape comes with
	 * it and the work does not.
	 *
	 * @param Actor                $actor  Who is copying.
	 * @param array<string, mixed> $source The project being copied.
	 * @param array<string, mixed> $target The new project.
	 * @return int How many folders it made.
	 */
	public function copy_tree( Actor $actor, array $source, array $target ): int {
		$service = $this->plugin->service( 'folders' );
		$policy  = $this->plugin->get( Policy::class );
		// Only the folders this person can see. Rights on a project are not
		// rights on every folder somebody filed under it.
		$rows = array_values(
			array_filter(
				$this->repository()->where( array( 'project_id' => (int) $source['id'] ) ),
				static fn( array $row ): bool => $policy->can( $actor, Policy::VIEW, $service->entity(), $row )
			)
		);
		usort( $rows, static fn( array $a, array $b ): int => (int) $a['depth'] <=> (int) $b['depth'] );

		// A new project is given the usual folders the moment it is created,
		// so copying the source's would make a second Brief beside the first.
		$already = array();
		foreach ( $this->repository()->where( array( 'project_id' => (int) $target['id'] ) ) as $there ) {
			$already[ strtolower( (string) $there['name'] ) . ':' . (int) $there['depth'] ] = $there;
		}

		$made = array();
		foreach ( $rows as $row ) {
			$parent = empty( $row['parent_id'] ) ? null : ( $made[ (int) $row['parent_id'] ] ?? null );
			if ( ! empty( $row['parent_id'] ) && ! $parent ) {
				continue;
			}
			$here = $already[ strtolower( (string) $row['name'] ) . ':' . (int) $row['depth'] ] ?? null;
			if ( $here && (int) $here['depth'] === (int) $row['depth'] && empty( $row['parent_id'] ) ) {
				$made[ (int) $row['id'] ] = $here;
				continue;
			}
			$new                      = $this->repository()->insert(
				array(
					'name'        => (string) $row['name'],
					'parent_id'   => $parent ? (int) $parent['id'] : null,
					'client_id'   => $target['client_id'] ?? null,
					'project_id'  => (int) $target['id'],
					'description' => (string) ( $row['description'] ?? '' ),
					'color'       => $row['color'] ?? null,
					'icon'        => (string) ( $row['icon'] ?? '' ),
					'is_default'  => (int) ( $row['is_default'] ?? 0 ),
					'default_key' => (string) ( $row['default_key'] ?? '' ),
					'depth'       => (int) $row['depth'],
					'path'        => $this->path_for( $parent, (string) $row['name'] ),
				)
			);
			$made[ (int) $row['id'] ] = $new;
		}
		return count( $made );
	}

	/**
	 * Loads a folder the actor may change.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $uid   Folder uid.
	 * @return array<string, mixed>
	 */
	public function editable( Actor $actor, string $uid ): array {
		return $this->plugin->service( 'folders' )->load( $actor, $uid, Policy::UPDATE );
	}

	/**
	 * The stored path for a folder under another one.
	 *
	 * @param array<string, mixed>|null $inside The folder it sits in.
	 * @param string                    $name   Folder name.
	 * @return string
	 */
	private function path_for( ?array $inside, string $name ): string {
		$prefix = $inside ? (string) $inside['path'] . '/' : '';
		return mb_substr( $prefix . $name, 0, 255 );
	}

	/**
	 * The folders repository.
	 *
	 * @return \Cliezoo\Core\Data\Repository
	 */
	private function repository(): \Cliezoo\Core\Data\Repository {
		return $this->plugin->service( 'folders' )->repository();
	}
}
