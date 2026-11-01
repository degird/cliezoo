<?php
/**
 * What you do to a file once it is here: file it, lock it, copy it,
 * approve it, take a folder away as one download.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Files;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Data\ListQuery;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Storage\Uploads;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * An approval that means something is the point of this module. In the
 * tools people leave, "approved" is a tick somebody can set and unset with
 * nothing written down, on a file that has since been replaced — so nobody
 * trusts it and everyone goes back to email (cliezoo.md §6.15).
 *
 * Here it names who approved it and when, it is undone by a person rather
 * than by a new version appearing, and both halves are in the audit log.
 */
final class Files {

	/**
	 * What a zip may weigh before it is refused. The server holds it while
	 * it builds, so this is a real limit rather than a licence check.
	 */
	public const ZIP_LIMIT = 500 * 1024 * 1024;

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
	 * Refuses a review in a folder that has approvals switched off.
	 *
	 * @param array<string, mixed> $row The file.
	 * @return void
	 * @throws ActionError When approvals are off where it is.
	 */
	private function reviewable( array $row ): void {
		if ( $this->plugin->get( FolderTools::class )->switched_off( (int) ( $row['folder_id'] ?? 0 ), 'approvals_off' ) ) {
			throw ActionError::conflict( __( 'Approvals are switched off in this folder.', 'cliezoo' ) );
		}
	}

	/**
	 * Asks for changes to a file: not approved, and what to change.
	 *
	 * @param Actor  $actor Who is asking.
	 * @param string $uid   File uid.
	 * @param string $note  What to change (said in the file's conversation).
	 * @return array<string, mixed>
	 * @throws ActionError When the file is locked.
	 */
	public function request_changes( Actor $actor, string $uid, string $note ): array {
		$service = $this->plugin->service( 'files' );
		$row     = $service->load( $actor, $uid, Policy::UPDATE );
		if ( ! empty( $row['locked'] ) ) {
			throw ActionError::conflict( __( 'This file is locked, so it cannot be reviewed.', 'cliezoo' ) );
		}
		$this->reviewable( $row );
		// A note is a comment: where comments are off, say so before
		// anything changes, rather than half-way through.
		if ( '' !== trim( $note ) && ! apply_filters( 'cliezoo_comments_allowed', true, $service->entity(), $row, $actor ) ) {
			throw ActionError::conflict( __( 'Comments are switched off here, so the request cannot carry a note. Ask without one.', 'cliezoo' ) );
		}
		$service->repository()->update(
			(int) $row['id'],
			array(
				'approved_by' => null,
				'approved_at' => null,
				'changes_by'  => $actor->member_id,
				'changes_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		// What to change is said where the file is discussed, so it is
		// threaded, notified and resolved like anything else said about it.
		if ( '' !== trim( $note ) ) {
			$this->plugin->actions()->run(
				'comments.add',
				$actor,
				array(
					'object_type' => 'files',
					'object'      => (string) $row['uid'],
					'body'        => $note,
				)
			);
		}
		do_action(
			'cliezoo_audit',
			'file_changes_requested',
			array(
				'object_type' => 'files',
				'object_id'   => (int) $row['id'],
			)
		);
		/**
		 * Somebody asked for changes to a file.
		 *
		 * @param array  $row   The file.
		 * @param string $note  What they asked for.
		 * @param Actor  $actor Who asked.
		 */
		do_action( 'cliezoo_file_changes_requested', $row, $note, $actor );
		return $service->present( $actor, (array) $service->repository()->find( (int) $row['id'] ) );
	}

	/**
	 * Approves a file, or takes the approval back.
	 *
	 * @param Actor  $actor    Who decides.
	 * @param string $uid      The file.
	 * @param bool   $approved Approve (true) or take it back (false).
	 * @return array<string, mixed>
	 * @throws ActionError When it cannot be changed.
	 */
	public function approve( Actor $actor, string $uid, bool $approved ): array {
		$service = $this->plugin->service( 'files' );
		$row     = $service->load( $actor, $uid, Policy::UPDATE );
		if ( ! empty( $row['locked'] ) ) {
			throw ActionError::conflict( __( 'This file is locked, so it cannot be approved or unapproved.', 'cliezoo' ) );
		}
		$this->reviewable( $row );
		$service->repository()->update(
			(int) $row['id'],
			array(
				'approved_by' => $approved ? $actor->member_id : null,
				'approved_at' => $approved ? gmdate( 'Y-m-d H:i:s' ) : null,
				// Approving answers a request for changes.
				'changes_by'  => null,
				'changes_at'  => null,
			)
		);
		do_action(
			'cliezoo_audit',
			$approved ? 'file_approved' : 'file_unapproved',
			array(
				'object_type' => 'files',
				'object_id'   => (int) $row['id'],
			)
		);
		/**
		 * A file was approved or the approval was taken back.
		 *
		 * @param array $row      The file.
		 * @param bool  $approved Whether it is approved now.
		 * @param Actor $actor    Who decided.
		 */
		do_action( 'cliezoo_file_approval', $row, $approved, $actor );

		return $service->present( $actor, (array) $service->repository()->find( (int) $row['id'] ) );
	}

	/**
	 * Locks a file so nobody replaces or renames it by accident.
	 *
	 * @param Actor  $actor  Who is deciding.
	 * @param string $uid    File uid.
	 * @param bool   $locked Lock or unlock.
	 * @return array<string, mixed>
	 */
	public function lock( Actor $actor, string $uid, bool $locked ): array {
		$service = $this->plugin->service( 'files' );
		$row     = $service->load( $actor, $uid, Policy::UPDATE );
		$service->repository()->update( (int) $row['id'], array( 'locked' => $locked ? 1 : 0 ) );
		do_action(
			'cliezoo_audit',
			$locked ? 'file_locked' : 'file_unlocked',
			array(
				'object_type' => 'files',
				'object_id'   => (int) $row['id'],
			)
		);
		return $service->present( $actor, (array) $service->repository()->find( (int) $row['id'] ) );
	}

	/**
	 * Copies a file, object and all, into the same or another folder.
	 *
	 * The bytes are copied rather than shared, because the whole point of a
	 * copy is that changing or deleting one does not touch the other.
	 *
	 * @param Actor                $actor   Who is copying.
	 * @param string               $uid     File uid.
	 * @param array<string, mixed> $changes name, folder.
	 * @return array<string, mixed>
	 * @throws ActionError When the copy cannot be stored.
	 */
	public function duplicate( Actor $actor, string $uid, array $changes ): array {
		$service = $this->plugin->service( 'files' );
		$row     = $service->load( $actor, $uid );
		if ( ! $this->plugin->get( Uploads::class )->can_download( $actor, $row ) ) {
			// Copying is taking the bytes away, so it needs what taking
			// them away needs.
			throw ActionError::forbidden();
		}

		$folder = null;
		if ( ! empty( $changes['folder'] ) ) {
			$folder = $this->plugin->get( Folders::class )->editable( $actor, (string) $changes['folder'] );
		} elseif ( ! empty( $row['folder_id'] ) ) {
			$folder = $this->plugin->service( 'folders' )->repository()->find( (int) $row['folder_id'] );
		}

		$name = trim( (string) ( $changes['name'] ?? '' ) );
		if ( '' === $name ) {
			// "(copy)" belongs before the extension: a file called
			// "Homepage.png (copy)" does not open in anything.
			$stem      = (string) pathinfo( (string) $row['name'], PATHINFO_FILENAME );
			$extension = (string) pathinfo( (string) $row['name'], PATHINFO_EXTENSION );
			$name      = sprintf(
				/* translators: %s: the file being copied, without its extension. */
				__( '%s (copy)', 'cliezoo' ),
				$stem
			) . ( '' === $extension ? '' : '.' . $extension );
		}

		$copy = $this->plugin->get( Uploads::class )->copy(
			$actor,
			$row,
			array(
				'name'       => $name,
				'folder_id'  => $folder ? (int) $folder['id'] : null,
				'client_id'  => $folder['client_id'] ?? $row['client_id'] ?? null,
				'project_id' => $folder['project_id'] ?? $row['project_id'] ?? null,
			)
		);
		return $service->present( $actor, $copy );
	}

	/**
	 * Moves files into a folder, in one go.
	 *
	 * @param Actor    $actor  Who is filing.
	 * @param string[] $uids   File uids.
	 * @param string   $folder Folder uid, or '' for the top.
	 * @return array{moved: int, locked: string[]}
	 */
	public function move( Actor $actor, array $uids, string $folder ): array {
		$service = $this->plugin->service( 'files' );
		$into    = '' === $folder ? null : $this->plugin->get( Folders::class )->editable( $actor, $folder );
		$moved   = 0;
		$locked  = array();
		foreach ( array_slice( $uids, 0, 200 ) as $uid ) {
			$row = $service->load( $actor, (string) $uid, Policy::UPDATE );
			if ( ! empty( $row['locked'] ) ) {
				// A locked file says so rather than being skipped quietly.
				$locked[] = (string) $row['name'];
				continue;
			}
			$service->repository()->update(
				(int) $row['id'],
				$this->sharing_from( $into ) + array(
					'folder_id'  => $into ? (int) $into['id'] : null,
					'client_id'  => $into['client_id'] ?? $row['client_id'] ?? null,
					'project_id' => $into['project_id'] ?? $row['project_id'] ?? null,
				)
			);
			++$moved;
		}
		return array(
			'moved'  => $moved,
			'locked' => $locked,
		);
	}

	/**
	 * How a file is shared, when it lives in a folder.
	 *
	 * The area tells people a folder is the thing you share: "shown to the
	 * client only once somebody shares it". That has to be true of what is
	 * inside it, not only of the tile — a delivery folder you share and a
	 * client who then sees nothing in it is the worst of both.
	 *
	 * So a file in a folder carries the folder's sharing. A loose file keeps
	 * its own, which is what the share dialog on a file is for.
	 *
	 * @param array<string, mixed>|null $folder The folder it is going into.
	 * @return array<string, string>
	 */
	public function sharing_from( ?array $folder ): array {
		if ( ! $folder ) {
			return array();
		}
		return array(
			'access'      => (string) ( $folder['access'] ?? 'inherit' ),
			'access_role' => (string) ( $folder['access_role'] ?? 'viewer' ),
		);
	}

	/**
	 * Passes a folder's sharing on to everything in it and below it.
	 *
	 * @param array<string, mixed> $folder The folder as it is now.
	 * @return int How many files it changed.
	 */
	public function reshare( array $folder ): int {
		$below = $this->plugin->get( Folders::class )->descendants( (int) $folder['id'] );
		$ids   = array_merge( array( (int) $folder['id'] ), $below );
		$repo  = $this->plugin->service( 'files' )->repository();
		$share = $this->sharing_from( $folder );
		$done  = 0;
		// The folders inside go the same way: a private folder with an
		// open folder in it is not private.
		$folders = $this->plugin->service( 'folders' )->repository();
		foreach ( $below as $id ) {
			$folders->update( $id, $share );
		}
		foreach ( $repo->where( array( 'folder_id' => $ids ) ) as $row ) {
			if ( (string) $row['access'] === $share['access'] && (string) $row['access_role'] === $share['access_role'] ) {
				continue;
			}
			$repo->update( (int) $row['id'], $share );
			++$done;
		}
		return $done;
	}

	/**
	 * Everything in a folder and below it, for a download or a count.
	 *
	 * @param Actor                $actor  Who is asking.
	 * @param array<string, mixed> $folder The folder.
	 * @return array<int, array<string, mixed>>
	 */
	public function tree_files( Actor $actor, array $folder ): array {
		// The same gate a single download goes through: asking for the
		// folder must not be a way round it.
		return $this->branch_files( (int) $folder['id'], true, $actor );
	}

	/**
	 * The finished uploads in a folder, and below it unless told otherwise.
	 *
	 * With a viewer, only what that person may download; without one, the
	 * whole branch — which is for counting and housekeeping, never for
	 * handing to somebody.
	 *
	 * @param int        $folder_id The folder.
	 * @param bool       $deep      Include everything below it too.
	 * @param Actor|null $viewer    Whose eyes, if anybody's.
	 * @return array<int, array<string, mixed>>
	 */
	public function branch_files( int $folder_id, bool $deep = true, ?Actor $viewer = null ): array {
		$ids     = $deep
			? array_merge( array( $folder_id ), $this->plugin->get( Folders::class )->descendants( $folder_id ) )
			: array( $folder_id );
		$uploads = $this->plugin->get( Uploads::class );
		$out     = array();
		foreach ( $this->plugin->service( 'files' )->repository()->where( array( 'folder_id' => $ids ) ) as $row ) {
			// `where()` already leaves the trash out; saying so here means a
			// caller that stops using it does not silently publish it.
			if ( ! empty( $row['deleted_at'] ) || ! empty( $row['archived_at'] ) ) {
				continue;
			}
			if ( 'ready' !== (string) $row['status'] || 'upload' !== (string) $row['kind'] ) {
				continue;
			}
			if ( $viewer && ! $uploads->can_download( $viewer, $row ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * What a folder would weigh as one download, and whether that is
	 * something this server should try.
	 *
	 * @param Actor                $actor  Who is asking.
	 * @param array<string, mixed> $folder The folder.
	 * @return array{files: int, size: int, within_limit: bool, limit: int}
	 */
	public function zip_estimate( Actor $actor, array $folder ): array {
		$rows = $this->tree_files( $actor, $folder );
		$size = array_sum( array_map( static fn( array $row ): int => (int) $row['size'], $rows ) );
		return array(
			'files'        => count( $rows ),
			'size'         => $size,
			'within_limit' => $size <= self::ZIP_LIMIT,
			'limit'        => self::ZIP_LIMIT,
		);
	}

	/**
	 * A line and a count about files on a client's page.
	 *
	 * @param array<string, mixed> $out    Summary so far.
	 * @param array<string, mixed> $client Client row.
	 * @param Actor                $actor  Who asked.
	 * @return array<string, mixed>
	 */
	public function client_summary( array $out, array $client, Actor $actor ): array {
		$query = new ListQuery();
		$query->where( 'client_id = %d', array( (int) $client['id'] ) );
		$query->limit = 200;
		$rows         = (array) $this->plugin->service( 'files' )->repository()->list(
			$query,
			$this->plugin->get( Policy::class )->scope( $actor, $this->plugin->service( 'files' )->entity() )
		)['rows'];

		$out['counts']['files'] = count( $rows );
		$waiting                = array_filter(
			$rows,
			static fn( array $row ): bool => empty( $row['approved_at'] ) && 'ready' === (string) $row['status']
		);
		if ( $waiting && $actor->is_staff() ) {
			$out['lines'][] = sprintf(
				/* translators: %d: number of files nobody has approved. */
				_n( '%d file has not been approved.', '%d files have not been approved.', count( $waiting ), 'cliezoo' ),
				count( $waiting )
			);
		}
		return $out;
	}

	/**
	 * How the library is grouped: the types people actually look for.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function kinds(): array {
		$kinds = array(
			array(
				'id'     => 'image',
				'label'  => __( 'Images', 'cliezoo' ),
				'prefix' => 'image/',
			),
			array(
				'id'     => 'video',
				'label'  => __( 'Video', 'cliezoo' ),
				'prefix' => 'video/',
			),
			array(
				'id'     => 'audio',
				'label'  => __( 'Audio', 'cliezoo' ),
				'prefix' => 'audio/',
			),
			array(
				'id'    => 'document',
				'label' => __( 'Documents', 'cliezoo' ),
				'mimes' => array(
					'application/pdf',
					'application/msword',
					'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					'application/vnd.oasis.opendocument.text',
					'text/plain',
					'text/markdown',
					'text/csv',
				),
			),
			array(
				'id'    => 'archive',
				'label' => __( 'Archives', 'cliezoo' ),
				'mimes' => array( 'application/zip', 'application/x-7z-compressed', 'application/x-rar-compressed', 'application/gzip' ),
			),
			array(
				'id'    => 'link',
				'label' => __( 'Links', 'cliezoo' ),
				'kind'  => 'link',
			),
		);
		/**
		 * The groups the file library offers.
		 *
		 * @param array $kinds Groups so far, each with an id, a label and
		 *                     either a mime prefix, a list of mimes, or a
		 *                     file kind.
		 */
		return (array) apply_filters( 'cliezoo_file_kinds', $kinds );
	}
}
