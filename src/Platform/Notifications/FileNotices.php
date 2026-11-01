<?php
/**
 * What happens to a file, said to the people following it.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Notifications;

use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Jobs\Jobs;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Records\Comments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The catalogue has had "comment resolved", "comment reopened", "file
 * approved", "file unapproved" and "file uploaded to a folder" since the
 * matrix was built. The Files module already announces each of them as a
 * WordPress action; nothing turned those into notifications. This does,
 * and only this: who hears is decided here, and how they hear (in-app,
 * email, digest, quiet hours) is the Notifier's.
 *
 * Uploads are batched. Dropping forty photos into a folder is one thing
 * that happened, so the people watching the folder get one notice a few
 * minutes later ("Ana uploaded 40 files to Photos"), not forty.
 */
final class FileNotices {

	/**
	 * How long uploads to one folder are gathered before anybody is told.
	 */
	public const BATCH_DELAY = 300;

	/**
	 * Files one gathered notice keeps track of (the count keeps going).
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
	 * Hooks the Files module's announcements.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return void
	 */
	public static function listen( Plugin $plugin ): void {
		$notices = static fn(): FileNotices => $plugin->get( self::class );
		add_action(
			'cliezoo_file_approval',
			static function ( array $row, bool $approved, Actor $actor ) use ( $notices ): void {
				$notices()->approval( $row, $approved, $actor );
			},
			10,
			3
		);
		foreach ( array(
			'resolved' => true,
			'reopened' => false,
		) as $verb => $resolved ) {
			add_action(
				'cliezoo_comment_' . $verb,
				static function ( array $comment, Actor $actor ) use ( $notices, $resolved ): void {
					$notices()->comment_state( $comment, $resolved, $actor );
				},
				10,
				2
			);
		}
		add_action(
			'cliezoo_file_uploaded',
			static function ( array $row, Actor $actor ) use ( $notices ): void {
				$notices()->uploaded( $row, $actor );
			},
			10,
			2
		);
		add_action(
			'cliezoo_job_folder_uploads',
			static function ( array $args ) use ( $notices ): void {
				$notices()->flush_folder( (int) ( $args['folder'] ?? 0 ) );
			}
		);
	}

	/**
	 * A file was approved, or the approval was taken back.
	 *
	 * @param array<string, mixed> $row      The file.
	 * @param bool                 $approved Approved now.
	 * @param Actor                $actor    Who did it.
	 * @return int Notifications created.
	 */
	public function approval( array $row, bool $approved, Actor $actor ): int {
		$entity  = $this->plugin->entities()->get( 'files' );
		$members = $this->who_may_see( $this->followers( $entity, $row ), $entity, $row, false, $actor );
		$who     = $this->plugin->get( Notifier::class )->member_name( $actor->member_id );
		return $this->plugin->get( Notifier::class )->notify(
			$approved ? 'file.approved' : 'file.unapproved',
			$members,
			array(
				'title'  => $approved
					/* translators: 1: a person, 2: a file name. */
					? sprintf( __( '%1$s approved "%2$s"', 'cliezoo' ), $who, (string) $row['name'] )
					/* translators: 1: a person, 2: a file name. */
					: sprintf( __( '%1$s took back the approval of "%2$s"', 'cliezoo' ), $who, (string) $row['name'] ),
				'entity' => $entity,
				'row'    => $row,
				'actor'  => $actor->member_id,
			)
		);
	}

	/**
	 * A comment on a file was resolved or reopened.
	 *
	 * The person who wrote it hears first of all — it was their question —
	 * and then everybody following the file.
	 *
	 * @param array<string, mixed> $comment  The comment, as it is now.
	 * @param bool                 $resolved Resolved (true) or reopened.
	 * @param Actor                $actor    Who did it.
	 * @return int Notifications created.
	 */
	public function comment_state( array $comment, bool $resolved, Actor $actor ): int {
		if ( 'files' !== (string) ( $comment['object_type'] ?? '' ) ) {
			return 0;
		}
		$entity = $this->plugin->entities()->get( 'files' );
		$row    = $this->plugin->service( 'files' )->repository()->find( (int) $comment['object_id'] );
		if ( ! $row || ! empty( $row['deleted_at'] ) ) {
			return 0;
		}
		$people = $this->followers( $entity, $row );
		if ( ! empty( $comment['created_by'] ) ) {
			$people[] = (int) $comment['created_by'];
		}
		$members = $this->who_may_see( $people, $entity, $row, ! empty( $comment['internal'] ), $actor );
		$who     = $this->plugin->get( Notifier::class )->member_name( $actor->member_id );
		/** This filter is documented in src/Platform/Records/Comments.php */
		$path = (string) apply_filters( 'cliezoo_comment_path', '', $entity, $row, $comment );
		return $this->plugin->get( Notifier::class )->notify(
			$resolved ? 'file.comment_resolved' : 'file.comment_reopened',
			$members,
			array(
				'title'  => $resolved
					/* translators: 1: a person, 2: a file name. */
					? sprintf( __( '%1$s resolved a comment on "%2$s"', 'cliezoo' ), $who, (string) $row['name'] )
					/* translators: 1: a person, 2: a file name. */
					: sprintf( __( '%1$s reopened a comment on "%2$s"', 'cliezoo' ), $who, (string) $row['name'] ),
				'body'   => mb_substr( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $comment['body'] ) ) ), 0, 200 ),
				'entity' => $entity,
				'row'    => $row,
				'path'   => $path,
				'actor'  => $actor->member_id,
			)
		);
	}

	/**
	 * A file finished uploading: remember it against its folder and make
	 * sure one gathered notice is on its way.
	 *
	 * @param array<string, mixed> $row   The file.
	 * @param Actor                $actor Who uploaded it.
	 * @return void
	 */
	public function uploaded( array $row, Actor $actor ): void {
		$folder = (int) ( $row['folder_id'] ?? 0 );
		if ( ! $folder || ! $this->plugin->entities()->has( 'folders' ) ) {
			return;
		}
		$key     = self::key( $folder );
		$pending = get_transient( $key );
		$pending = is_array( $pending ) ? $pending : array(
			'files'  => array(),
			'count'  => 0,
			'actors' => array(),
		);
		if ( count( $pending['files'] ) < self::MOST ) {
			$pending['files'][] = (int) $row['id'];
		}
		++$pending['count'];
		$pending['actors'][] = $actor->member_id;
		$pending['actors']   = array_values( array_unique( array_map( 'intval', $pending['actors'] ) ) );
		set_transient( $key, $pending, DAY_IN_SECONDS );
		$queued = $this->plugin->get( Jobs::class )->queue( 'folder_uploads', array( 'folder' => $folder ), self::BATCH_DELAY, true );
		// No job runner (a site without Action Scheduler): say it now
		// rather than never.
		if ( 0 === $queued && ! function_exists( 'as_schedule_single_action' ) ) {
			$this->flush_folder( $folder );
		}
	}

	/**
	 * Tells the people following a folder what was uploaded to it since the
	 * last notice.
	 *
	 * @param int $folder_id Folder id.
	 * @return int Notifications created.
	 */
	public function flush_folder( int $folder_id ): int {
		$key     = self::key( $folder_id );
		$pending = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $pending ) || empty( $pending['count'] ) || ! $this->plugin->entities()->has( 'folders' ) ) {
			return 0;
		}
		$entity = $this->plugin->entities()->get( 'folders' );
		$folder = $this->plugin->service( 'folders' )->repository()->find( $folder_id );
		if ( ! $folder || ! empty( $folder['deleted_at'] ) ) {
			return 0;
		}
		$actors = array_map( 'intval', (array) $pending['actors'] );
		// Somebody who uploaded everything themselves does not need telling;
		// when two people uploaded, each hears about the other's files too.
		$people  = array_diff( $this->followers( $entity, $folder ), 1 === count( $actors ) ? $actors : array() );
		$members = $this->who_may_see( array_values( $people ), $entity, $folder, false, null );
		if ( ! $members ) {
			return 0;
		}
		$notifier = $this->plugin->get( Notifier::class );
		$who      = 1 === count( $actors ) ? $notifier->member_name( (int) $actors[0] ) : __( 'Several people', 'cliezoo' );
		$count    = (int) $pending['count'];
		$first    = 1 === $count ? $this->plugin->service( 'files' )->repository()->find( (int) ( $pending['files'][0] ?? 0 ) ) : null;
		return $notifier->notify(
			'folder.file_uploaded',
			$members,
			array(
				'title'  => $first
					/* translators: 1: a person, 2: a file name, 3: a folder name. */
					? sprintf( __( '%1$s uploaded "%2$s" to %3$s', 'cliezoo' ), $who, (string) $first['name'], (string) $folder['name'] )
					/* translators: 1: a person (or "Several people"), 2: number of files, 3: a folder name. */
					: sprintf( _n( '%1$s uploaded %2$d file to %3$s', '%1$s uploaded %2$d files to %3$s', $count, 'cliezoo' ), $who, $count, (string) $folder['name'] ),
				'entity' => $entity,
				'row'    => $folder,
				// Nobody is left out of hearing about a folder because they
				// uploaded to it: the actor filter is done above.
				'actor'  => 0,
			)
		);
	}

	/**
	 * Transient key for one folder's gathered uploads.
	 *
	 * @param int $folder_id Folder id.
	 * @return string
	 */
	private static function key( int $folder_id ): string {
		return 'cliezoo_folder_uploads_' . $folder_id;
	}

	/**
	 * The people with a standing reason to hear about a record: its
	 * watchers, whoever looks after it and whoever made it.
	 *
	 * @param Entity               $entity Entity.
	 * @param array<string, mixed> $row    Record.
	 * @return int[]
	 */
	private function followers( Entity $entity, array $row ): array {
		$out = $this->plugin->get( Notifier::class )->watchers( $entity->name, (int) $row['id'] );
		if ( '' !== $entity->owner_field && ! empty( $row[ $entity->owner_field ] ) ) {
			$out[] = (int) $row[ $entity->owner_field ];
		}
		if ( ! empty( $row['created_by'] ) ) {
			$out[] = (int) $row['created_by'];
		}
		return array_values( array_unique( array_map( 'intval', $out ) ) );
	}

	/**
	 * Of the people we were going to tell, the ones who may see the record,
	 * never the person who did it.
	 *
	 * @param int[]                $members  Member ids.
	 * @param Entity               $entity   Entity.
	 * @param array<string, mixed> $row      Record.
	 * @param bool                 $internal Team only.
	 * @param Actor|null           $actor    Who did it.
	 * @return int[]
	 */
	private function who_may_see( array $members, Entity $entity, array $row, bool $internal, ?Actor $actor ): array {
		if ( $actor ) {
			$members = array_diff( $members, array( $actor->member_id ) );
		}
		if ( ! $members ) {
			return array();
		}
		return $this->plugin->get( Comments::class )->who_may_see( array_values( $members ), $entity, $row, $internal );
	}
}
