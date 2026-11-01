<?php
/**
 * Saying something on a record: the one way a comment is written.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Records;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Storage\Downloads;
use Cliezoo\Infrastructure\Storage\Uploads;
use Cliezoo\Platform\Members\Members;
use Cliezoo\Platform\Notifications\Notifier;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * A task comment, a note pinned to a picture and a message in a thread are
 * the same row, written by the same code.
 *
 * Before this, a comment was inserted in one place and nothing else
 * happened: nobody was told, an @mention was a coloured word, and the
 * catalogue's "new comment on a task" and "someone mentions you" were
 * switches in everybody's preferences that no code ever raised. What is
 * here is what writing a comment means — who hears about it, what it
 * carries, and what somebody reading it later is shown alongside it.
 */
final class Comments {

	/**
	 * Files one comment may carry.
	 */
	public const FILES = 10;

	/**
	 * People one comment may mention, and people one comment tells.
	 */
	public const PEOPLE = 50;

	/**
	 * How long the address of a picture or a recording in a message works.
	 */
	private const LINK_TTL = 600;

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
	 * Writes a comment on a record the actor has already been allowed to see.
	 *
	 * @param Actor                $actor  Who is saying it.
	 * @param Entity               $entity What it is said on.
	 * @param array<string, mixed> $row    The record.
	 * @param array<string, mixed> $in     body, internal?, parent?, anchor?, files?.
	 * @param array<string, mixed> $extra  Columns a module adds (a message's send_at).
	 * @return array<string, mixed> The comment row.
	 * @throws ActionError When it may not be said here.
	 */
	public function post( Actor $actor, Entity $entity, array $row, array $in, array $extra = array() ): array {
		$internal = ! empty( $in['internal'] );
		if ( $internal && ! $actor->is_staff() ) {
			throw ActionError::forbidden();
		}
		// A client whose role is to look is not somebody who writes.
		if ( $actor->is_client() && 'client_viewer' === $actor->role ) {
			throw ActionError::forbidden();
		}
		/**
		 * Whether comments are allowed on this record (e.g. folder "Disable comments").
		 *
		 * @param bool   $allowed Default true.
		 * @param Entity $entity  Parent entity.
		 * @param array  $row     Parent row.
		 * @param Actor  $actor   Actor.
		 */
		if ( ! apply_filters( 'cliezoo_comments_allowed', true, $entity, $row, $actor ) ) {
			throw ActionError::conflict( __( 'Comments are turned off here.', 'cliezoo' ) );
		}
		$service = $this->plugin->service( 'comments' );
		$data    = array(
			'object_type' => $entity->name,
			'object_id'   => (int) $row['id'],
			'body'        => (string) $in['body'],
			'internal'    => $internal,
			'anchor'      => $in['anchor'] ?? null,
		);
		$columns = $this->plugin->serializer()->to_columns( $service->entity(), $data ) + array(
			'object_type' => $entity->name,
			'object_id'   => (int) $row['id'],
		);
		if ( ! empty( $in['parent'] ) ) {
			$columns['parent_id'] = $this->reply_to( $entity, $row, (string) $in['parent'], $internal );
		}
		$files = $this->own_files( $actor, $entity, $row, (array) ( $in['files'] ?? array() ) );
		if ( $files ) {
			$columns['files'] = (string) wp_json_encode( $files );
		}
		$columns['body'] = $this->tidy( (string) $columns['body'] );
		if ( '' === trim( wp_strip_all_tags( (string) $columns['body'] ) ) && ! $files ) {
			throw ActionError::validation( __( 'There is nothing to send.', 'cliezoo' ), array( 'body' => __( 'Write something or attach a file.', 'cliezoo' ) ) );
		}
		// Clients may comment on what they can see; the caller checked that.
		$created = $service->repository()->insert( array_merge( $columns, $extra ), $actor->member_id );
		if ( $files && $internal ) {
			$this->keep_with_team( $files );
		}
		if ( empty( $extra['send_at'] ) ) {
			$this->announce( $created, $entity, $row, $actor, $data );
		}
		return $created;
	}

	/**
	 * Tells everything that listens that a comment now exists.
	 *
	 * Split from writing it because a message held for later exists before
	 * anybody is meant to know about it.
	 *
	 * @param array<string, mixed> $comment The comment.
	 * @param Entity               $entity  What it is on.
	 * @param array<string, mixed> $row     The record.
	 * @param Actor                $actor   Who said it.
	 * @param array<string, mixed> $input   What was sent.
	 * @return void
	 */
	public function announce( array $comment, Entity $entity, array $row, Actor $actor, array $input = array() ): void {
		$files = $this->file_ids( $comment );
		if ( $files && empty( $comment['internal'] ) ) {
			$this->share( $files, $entity );
		}
		do_action( 'cliezoo_record_created', $this->plugin->entities()->get( 'comments' ), $comment, $actor, $input );
		do_action( 'cliezoo_comment_added', $comment, $entity, $row, $actor );
	}

	/**
	 * The comment a reply belongs under.
	 *
	 * Threads are one level deep, as in every chat people already use: a
	 * reply to a reply goes under the message that started it. And a reply
	 * is never less private than what it answers — a client's reply to an
	 * internal note could otherwise quote it back to them.
	 *
	 * @param Entity               $entity   What it is on.
	 * @param array<string, mixed> $row      The record.
	 * @param string               $uid      The comment being answered.
	 * @param bool                 $internal Whether the reply is an internal note.
	 * @return int
	 * @throws ActionError When it is not on this record.
	 */
	private function reply_to( Entity $entity, array $row, string $uid, bool $internal ): int {
		$answered = $this->plugin->service( 'comments' )->repository()->find_by_uid( $uid );
		if ( ! $answered || ! empty( $answered['deleted_at'] ) || (int) $answered['object_id'] !== (int) $row['id'] || $answered['object_type'] !== $entity->name ) {
			throw ActionError::validation( __( 'The comment you are replying to was not found.', 'cliezoo' ) );
		}
		if ( ! empty( $answered['internal'] ) && ! $internal ) {
			throw ActionError::validation( __( 'A reply to an internal note is an internal note too.', 'cliezoo' ) );
		}
		return ! empty( $answered['parent_id'] ) ? (int) $answered['parent_id'] : (int) $answered['id'];
	}

	/**
	 * Shares the files a public message carries.
	 *
	 * A file attached in the composer starts out for the team only, so one
	 * attached and then discarded, or sent in an internal note, never
	 * reaches a client. Sending it in a message is what shares it — as
	 * widely as the record says (`cliezoo_comment_file_access`): with the
	 * record's own sharing by default, with the client for a conversation or
	 * a request, which are between the team and the client.
	 *
	 * @param array<int, int> $ids    File ids.
	 * @param Entity          $entity What they were sent on.
	 * @return void
	 */
	private function share( array $ids, Entity $entity ): void {
		global $wpdb;
		/**
		 * How widely a file sent in a public message on this kind of record
		 * is shared: a file access mode.
		 *
		 * @param string $access Default "inherit".
		 * @param Entity $entity What it was sent on.
		 */
		$access = (string) apply_filters( 'cliezoo_comment_file_access', 'inherit', $entity );
		$table  = esc_sql( $this->plugin->entities()->get( 'files' )->table() );
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table from code; the IN list is built from placeholders.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET access = %s WHERE id IN ({$in}) AND access = 'internal'", array_merge( array( $access ), $ids ) ) );
	}

	/**
	 * Keeps files sent in an internal note with the team.
	 *
	 * A file is attached to the record and follows the record's sharing,
	 * so on a task in a project shared with the client it would be in the
	 * client's Files area — while the note it came with is hidden from them.
	 *
	 * @param array<int, int> $ids File ids.
	 * @return void
	 */
	private function keep_with_team( array $ids ): void {
		global $wpdb;
		$table = esc_sql( $this->plugin->entities()->get( 'files' )->table() );
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table from code; the IN list is built from placeholders.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET access = 'internal' WHERE id IN ({$in})", $ids ) );
	}

	/**
	 * The files a comment may carry: ones the author has just put on the
	 * same record.
	 *
	 * A file is attached to the record and the comment points at it, so the
	 * record's Files tab already has every file said in it. Anything else —
	 * a file on another record, or somebody else's upload — is refused
	 * rather than skipped, because pointing at it would show it to
	 * everybody who can read the comment.
	 *
	 * @param Actor                $actor  Who is saying it.
	 * @param Entity               $entity What it is on.
	 * @param array<string, mixed> $row    The record.
	 * @param array<int, mixed>    $uids   File uids.
	 * @return array<int, int> File ids.
	 * @throws ActionError When a file is not one of theirs on this record.
	 */
	private function own_files( Actor $actor, Entity $entity, array $row, array $uids ): array {
		$uids = array_values( array_unique( array_map( 'strval', $uids ) ) );
		if ( ! $uids ) {
			return array();
		}
		if ( count( $uids ) > self::FILES ) {
			/* translators: %d: how many files. */
			throw ActionError::validation( sprintf( __( 'A message can carry up to %d files.', 'cliezoo' ), self::FILES ) );
		}
		$repo = $this->plugin->service( 'files' )->repository();
		$ids  = array();
		foreach ( $uids as $uid ) {
			$file = $repo->find_by_uid( $uid );
			if (
				! $file
				|| ! empty( $file['deleted_at'] )
				|| 'ready' !== (string) $file['status']
				|| (string) ( $file['object_type'] ?? '' ) !== $entity->name
				|| (int) ( $file['object_id'] ?? 0 ) !== (int) $row['id']
				|| (int) $file['created_by'] !== $actor->member_id
			) {
				throw ActionError::validation( __( 'One of the files is not on this record.', 'cliezoo' ) );
			}
			$ids[] = (int) $file['id'];
		}
		return $ids;
	}

	/**
	 * The members a piece of text mentions.
	 *
	 * A mention is `<span data-mention data-id="{member uid}">`, which is
	 * what the composer writes and what the sanitiser keeps. Anything else
	 * that looks like a name is a name.
	 *
	 * @param string $body HTML.
	 * @return array<int, int> Member ids.
	 */
	public function mentioned( string $body ): array {
		if ( false === strpos( $body, 'data-mention' ) ) {
			return array();
		}
		preg_match_all( '/<span\b[^>]*\bdata-mention\b[^>]*>/i', $body, $tags );
		$uids = array();
		foreach ( $tags[0] as $tag ) {
			if ( preg_match( '/\bdata-id\s*=\s*"([0-9A-HJKMNP-TV-Z]{26})"/', $tag, $found ) ) {
				$uids[] = $found[1];
			}
		}
		$uids = array_slice( array_values( array_unique( $uids ) ), 0, self::PEOPLE );
		if ( ! $uids ) {
			return array();
		}
		return array_values( array_map( 'intval', $this->plugin->service( 'members' )->repository()->ids_for( $uids ) ) );
	}

	/**
	 * Tells the people a comment is for.
	 *
	 * Two kinds of people: the ones it names, who hear about it whatever
	 * their settings say about the record, and the ones with a reason to
	 * follow the record, who hear the ordinary way. Nobody hears twice, and
	 * everybody is asked again whether they may see what they would be
	 * told — an internal note never reaches a client by being addressed
	 * to one.
	 *
	 * @param array<string, mixed> $comment The comment.
	 * @param Entity               $entity  What it is on.
	 * @param array<string, mixed> $row     The record.
	 * @param Actor                $actor   Who said it.
	 * @param array<int, int>|null $only    Only these mentions (an edit tells the newly named).
	 * @return void
	 */
	public function tell( array $comment, Entity $entity, array $row, Actor $actor, ?array $only = null ): void {
		/**
		 * Whether comments on this kind of record are for the team only,
		 * whatever each one says — so nobody outside it is told of them.
		 *
		 * @param bool   $team_only Default false.
		 * @param Entity $entity    Entity.
		 */
		$internal = ! empty( $comment['internal'] ) || (bool) apply_filters( 'cliezoo_comments_team_only', false, $entity );
		$named    = $this->mentioned( (string) $comment['body'] );
		if ( null !== $only ) {
			$named = array_values( array_intersect( $named, $only ) );
		}
		$told  = $this->told( $comment );
		$named = array_values( array_diff( $named, $told ) );
		$named = $this->who_may_see( $named, $entity, $row, $internal );
		if ( $named ) {
			$this->plugin->service( 'comments' )->repository()->update(
				(int) $comment['id'],
				array( 'mentioned' => (string) wp_json_encode( array_values( array_unique( array_merge( $told, $named ) ) ) ) )
			);
		}
		$title = (string) ( $row[ $entity->title_field ] ?? '' );
		$who   = $this->plugin->get( Notifier::class )->member_name( $actor->member_id );
		$body  = mb_substr( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $comment['body'] ) ) ), 0, 200 );
		$path  = $this->path( $entity, $row, $comment );

		$events = $this->events( $entity );
		if ( $named && '' !== $events['mention'] ) {
			do_action(
				'cliezoo_notify',
				$events['mention'],
				$named,
				array(
					/* translators: 1: a person, 2: the record's title. */
					'title'  => sprintf( __( '%1$s mentioned you on "%2$s"', 'cliezoo' ), $who, $title ),
					'body'   => $body,
					'entity' => $entity,
					'row'    => $row,
					'path'   => $path,
					'actor'  => $actor->member_id,
				)
			);
		}
		if ( null !== $only || '' === $events['comment'] ) {
			return;
		}
		/**
		 * Who follows what is said on a record, beyond its watchers and the
		 * person who looks after it.
		 *
		 * @param int[]  $members Member ids.
		 * @param Entity $entity  Entity.
		 * @param array  $row     Record.
		 * @param array  $comment The comment.
		 */
		$audience = (array) apply_filters( 'cliezoo_comment_audience', $this->followers( $entity, $row, $comment ), $entity, $row, $comment );
		$audience = array_diff( array_map( 'intval', $audience ), $named, array( $actor->member_id ) );
		$audience = $this->who_may_see( array_values( $audience ), $entity, $row, $internal );
		if ( ! $audience ) {
			return;
		}
		$event = ! empty( $comment['parent_id'] ) && '' !== $events['reply'] ? $events['reply'] : $events['comment'];
		do_action(
			'cliezoo_notify',
			$event,
			$audience,
			array(
				'title'  => $internal
					/* translators: 1: a person, 2: the record's title. */
					? sprintf( __( '%1$s left an internal note on "%2$s"', 'cliezoo' ), $who, $title )
					/* translators: 1: a person, 2: the record's title. */
					: sprintf( __( '%1$s wrote on "%2$s"', 'cliezoo' ), $who, $title ),
				'body'   => $body,
				'entity' => $entity,
				'row'    => $row,
				'path'   => $path,
				'actor'  => $actor->member_id,
			)
		);
	}

	/**
	 * Who has already been told a comment names them.
	 *
	 * @param array<string, mixed> $comment The comment.
	 * @return array<int, int>
	 */
	private function told( array $comment ): array {
		$fresh = $this->plugin->service( 'comments' )->repository()->find( (int) $comment['id'] );
		$raw   = $fresh['mentioned'] ?? null;
		$list  = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		return is_array( $list ) ? array_values( array_map( 'intval', $list ) ) : array();
	}

	/**
	 * Keeps what a comment's markup says and drops how it would look.
	 *
	 * The sanitiser keeps `class` on every tag because documents need it.
	 * In a message it would let somebody — a client, say — dress a message
	 * up in the app's own styles: a full-screen "your session expired" over
	 * the page, with a link of their choosing. A message's only class is the
	 * one a mention carries.
	 *
	 * @param string $body HTML, already sanitised.
	 * @return string
	 */
	public function tidy( string $body ): string {
		if ( false === stripos( $body, 'class' ) ) {
			return $body;
		}
		return (string) preg_replace_callback(
			'/<([a-z][a-z0-9]*)\b([^>]*)>/i',
			static function ( array $tag ): string {
				$attributes = (string) preg_replace( '/\s+class\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $tag[2] );
				if ( 'span' === strtolower( $tag[1] ) && false !== stripos( $attributes, 'data-mention' ) ) {
					$attributes .= ' class="mention"';
				}
				return '<' . $tag[1] . $attributes . '>';
			},
			$body
		);
	}

	/**
	 * The notification events for comments on a kind of record.
	 *
	 * @param Entity $entity Entity.
	 * @return array{comment: string, reply: string, mention: string}
	 */
	private function events( Entity $entity ): array {
		$known = array(
			'tasks'         => array(
				'comment' => 'task.comment',
				'reply'   => '',
				'mention' => 'task.mention',
			),
			'files'         => array(
				'comment' => 'file.comment',
				'reply'   => 'file.comment_reply',
				'mention' => 'file.mention',
			),
			'conversations' => array(
				'comment' => 'message.posted',
				'reply'   => '',
				'mention' => 'message.mention',
			),
		);
		/**
		 * Which catalogue events a comment on this kind of record raises.
		 * An empty string raises nothing.
		 *
		 * @param array  $events comment, reply, mention.
		 * @param Entity $entity Entity.
		 */
		$events = (array) apply_filters(
			'cliezoo_comment_events',
			$known[ $entity->name ] ?? array(
				'comment' => '',
				'reply'   => '',
				'mention' => '',
			),
			$entity
		);
		return array(
			'comment' => (string) ( $events['comment'] ?? '' ),
			'reply'   => (string) ( $events['reply'] ?? '' ),
			'mention' => (string) ( $events['mention'] ?? '' ),
		);
	}

	/**
	 * The people with a standing reason to hear: whoever watches the
	 * record, whoever looks after it, whoever made it, and whoever wrote
	 * the comment being answered.
	 *
	 * @param Entity               $entity  Entity.
	 * @param array<string, mixed> $row     Record.
	 * @param array<string, mixed> $comment The comment.
	 * @return array<int, int>
	 */
	private function followers( Entity $entity, array $row, array $comment ): array {
		$out = $this->plugin->get( Notifier::class )->watchers( $entity->name, (int) $row['id'] );
		if ( '' !== $entity->owner_field && ! empty( $row[ $entity->owner_field ] ) ) {
			$out[] = (int) $row[ $entity->owner_field ];
		}
		if ( ! empty( $row['created_by'] ) ) {
			$out[] = (int) $row['created_by'];
		}
		if ( ! empty( $comment['parent_id'] ) ) {
			$answered = $this->plugin->service( 'comments' )->repository()->find( (int) $comment['parent_id'] );
			if ( $answered ) {
				$out[] = (int) $answered['created_by'];
			}
		}
		return array_values( array_unique( array_map( 'intval', $out ) ) );
	}

	/**
	 * Of the people we were going to tell, the ones who may read it.
	 *
	 * @param array<int, int>      $members  Member ids.
	 * @param Entity               $entity   What it is on.
	 * @param array<string, mixed> $row      The record.
	 * @param bool                 $internal Whether it is an internal note.
	 * @return array<int, int>
	 */
	public function who_may_see( array $members, Entity $entity, array $row, bool $internal ): array {
		$policy  = $this->plugin->policy();
		$people  = $this->plugin->get( Members::class );
		$records = $this->plugin->service( 'members' )->repository();
		$out     = array();
		foreach ( $records->find_many( array_slice( array_values( array_unique( $members ) ), 0, self::PEOPLE ) ) as $member ) {
			if ( empty( $member['user_id'] ) || ( $internal && 'staff' !== $member['kind'] ) ) {
				continue;
			}
			if ( $policy->can( $people->actor_for( (int) $member['user_id'] ), Policy::VIEW, $entity, $row ) ) {
				$out[] = (int) $member['id'];
			}
		}
		return $out;
	}

	/**
	 * Where a notification about a comment takes somebody.
	 *
	 * @param Entity               $entity  Entity.
	 * @param array<string, mixed> $row     Record.
	 * @param array<string, mixed> $comment Comment.
	 * @return string
	 */
	private function path( Entity $entity, array $row, array $comment ): string {
		/**
		 * The app path a comment on this record opens.
		 *
		 * @param string $path    Path ('' for the record's own).
		 * @param Entity $entity  Entity.
		 * @param array  $row     Record.
		 * @param array  $comment The comment.
		 */
		$path = (string) apply_filters( 'cliezoo_comment_path', '', $entity, $row, $comment );
		if ( '' === $path && '' !== $entity->path ) {
			$path = str_replace( '{uid}', (string) $row['uid'], $entity->path );
		}
		return $path;
	}

	/**
	 * What somebody reading a comment is shown with it: who it answers,
	 * how many answered it, and the files it carries that they may open.
	 *
	 * @param array<int, array<string, mixed>> $out   Presented comments.
	 * @param array<int, array<string, mixed>> $rows  Raw rows, same order.
	 * @param Actor                            $actor Who is reading.
	 * @return array<int, array<string, mixed>>
	 */
	public function present( array $out, array $rows, Actor $actor ): array {
		if ( ! $rows ) {
			return $out;
		}
		$repo    = $this->plugin->service( 'comments' )->repository();
		$parents = array();
		$files   = array();
		$ids     = array();
		foreach ( $rows as $row ) {
			$ids[] = (int) $row['id'];
			if ( ! empty( $row['parent_id'] ) ) {
				$parents[] = (int) $row['parent_id'];
			}
			foreach ( $this->file_ids( $row ) as $file ) {
				$files[] = $file;
			}
		}
		$uids    = $parents ? $repo->refs( array_values( array_unique( $parents ) ) ) : array();
		$replies = $this->replies( $ids, $actor );
		$shown   = $this->files( array_values( array_unique( $files ) ), $actor );
		foreach ( $rows as $i => $row ) {
			unset( $out[ $i ]['parent_id'] );
			$out[ $i ]['reply_to'] = ! empty( $row['parent_id'] ) ? ( $uids[ (int) $row['parent_id'] ]['uid'] ?? null ) : null;
			$out[ $i ]['replies']  = $replies[ (int) $row['id'] ] ?? 0;
			$out[ $i ]['files']    = array_values( array_filter( array_map( static fn( int $id ) => $shown[ $id ] ?? null, $this->file_ids( $row ) ) ) );
			$out[ $i ]['mine']     = (int) $row['created_by'] === $actor->member_id;
		}
		return $out;
	}

	/**
	 * Whether a file is something to listen to.
	 *
	 * A browser records a voice note as WebM or Ogg, and the file checker
	 * names those by their container — "video/webm" — because that is what
	 * the bytes say. A recording has sound and no picture, which is what a
	 * video with no width is.
	 *
	 * @param array<string, mixed> $file File row.
	 * @return bool
	 */
	private function is_recording( array $file ): bool {
		$mime = (string) ( $file['mime'] ?? '' );
		if ( str_starts_with( $mime, 'audio/' ) ) {
			return true;
		}
		return in_array( $mime, array( 'video/webm', 'video/ogg', 'video/mp4' ), true ) && empty( $file['width'] );
	}

	/**
	 * The file ids a comment row carries.
	 *
	 * @param array<string, mixed> $row Comment row.
	 * @return array<int, int>
	 */
	public function file_ids( array $row ): array {
		$raw = $row['files'] ?? null;
		if ( is_string( $raw ) && '' !== $raw ) {
			$raw = json_decode( $raw, true );
		}
		return is_array( $raw ) ? array_values( array_map( 'intval', $raw ) ) : array();
	}

	/**
	 * How many replies each comment has, that the reader may see.
	 *
	 * @param array<int, int> $ids   Comment ids.
	 * @param Actor           $actor Who is reading.
	 * @return array<int, int>
	 */
	private function replies( array $ids, Actor $actor ): array {
		global $wpdb;
		$table  = esc_sql( $this->plugin->entities()->get( 'comments' )->table() );
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$hidden = $actor->is_client() ? ' AND internal = 0' : '';
		$held   = $this->plugin->entities()->get( 'comments' )->has_column( 'send_at' ) ? ' AND send_at IS NULL' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and fragments from code.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT parent_id, COUNT(*) AS n FROM {$table} WHERE parent_id IN ({$in}) AND deleted_at IS NULL{$hidden}{$held} GROUP BY parent_id", $ids ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['parent_id'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Chips for files, keeping only the ones the reader may see.
	 *
	 * @param array<int, int> $ids   File ids.
	 * @param Actor           $actor Who is reading.
	 * @return array<int, array<string, mixed>>
	 */
	private function files( array $ids, Actor $actor ): array {
		if ( ! $ids ) {
			return array();
		}
		$entity  = $this->plugin->entities()->get( 'files' );
		$policy  = $this->plugin->policy();
		$uploads = $this->plugin->get( Uploads::class );
		$out     = array();
		foreach ( $this->plugin->service( 'files' )->repository()->find_many( $ids ) as $file ) {
			if ( ! empty( $file['deleted_at'] ) || ! $policy->can( $actor, Policy::VIEW, $entity, $file ) ) {
				continue;
			}
			$mime  = (string) ( $file['mime'] ?? '' );
			$ready = 'ready' === $file['status'] && 'upload' === $file['kind'];
			// A picture is shown in the message and a voice note is played
			// there, so each gets an address that works for a few minutes.
			// A signed address is a bearer token: a picture takes the right
			// to see the file, as a card cover does, and a recording takes
			// the right to download it, because playing it is downloading it.
			$out[ (int) $file['id'] ] = array(
				'uid'      => (string) $file['uid'],
				'name'     => (string) $file['name'],
				'mime'     => $mime,
				'size'     => isset( $file['size'] ) ? (int) $file['size'] : null,
				'kind'     => (string) ( $file['kind'] ?? '' ),
				'duration' => isset( $file['duration'] ) ? (int) $file['duration'] : null,
				'preview'  => $ready && Downloads::is_image( $mime ) ? Downloads::signed_url( (string) $file['uid'], self::LINK_TTL, 'cover' ) : null,
				'play'     => $ready && $this->is_recording( $file ) && $uploads->can_download( $actor, $file ) ? Downloads::signed_url( (string) $file['uid'], self::LINK_TTL ) : null,
			);
		}
		return $out;
	}
}
