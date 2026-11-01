<?php
/**
 * Creates and delivers notifications.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Notifications;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\ListQuery;
use Cliezoo\Core\Data\Serializer;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Mail\EmailTemplates;
use Cliezoo\Infrastructure\Mail\Mailer;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event → recipients → preference matrix → in-app row + email (instant,
 * after quiet hours, or in a digest) + extra channels (from add-ons).
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class Notifier {

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
	 * Notifies members about an event.
	 *
	 * @param string               $event   Event id from the catalogue.
	 * @param int[]                $members Member ids.
	 * @param array<string, mixed> $payload title, body?, entity?, row?, object_type?, object_id?, path?, actor? (member id).
	 * @return int Notifications created.
	 */
	public function notify( string $event, array $members, array $payload ): int {
		$definition = $this->plugin->get( EventCatalog::class )->get( $event );
		if ( ! $definition ) {
			return 0;
		}
		$actor_id = (int) ( $payload['actor'] ?? 0 );
		$members  = array_values( array_unique( array_filter( array_map( 'intval', $members ), static fn( int $id ): bool => $id > 0 && $id !== $actor_id ) ) );
		if ( ! $members ) {
			return 0;
		}
		$object_type = (string) ( $payload['object_type'] ?? '' );
		$object_id   = (int) ( $payload['object_id'] ?? 0 );
		$path        = (string) ( $payload['path'] ?? '' );
		if ( isset( $payload['entity'], $payload['row'] ) && $payload['entity'] instanceof Entity ) {
			$entity      = $payload['entity'];
			$object_type = $entity->name;
			$object_id   = (int) $payload['row']['id'];
			if ( '' === $path && '' !== $entity->path && ! empty( $payload['row']['uid'] ) ) {
				$path = str_replace( '{uid}', (string) $payload['row']['uid'], $entity->path );
			}
		}
		$rows     = $this->plugin->service( 'members' )->repository()->find_many( $members );
		$prefs    = $this->prefs_many( $members );
		$channels = $this->plugin->get( ChannelRegistry::class );
		$repo     = $this->plugin->service( 'notifications' )->repository();
		$personal = str_ends_with( $event, '.mention' ) || 'task.assigned' === $event || 'reminder' === $event;
		// A key that says "this has been said once already": the due-date
		// notice for one task on one date, say. Checked per person, so
		// somebody added to the task later still hears it.
		$dedupe = isset( $payload['dedupe'] ) ? mb_substr( (string) $payload['dedupe'], 0, 120 ) : '';
		$count  = 0;
		foreach ( $rows as $member ) {
			if ( 'active' !== $member['status'] ) {
				continue;
			}
			if ( '' !== $dedupe && $repo->first(
				array(
					'member_id' => (int) $member['id'],
					'dedupe'    => $dedupe,
				)
			) ) {
				continue;
			}
			if ( 'client' === $member['kind'] && 'team' === $definition['audience'] ) {
				continue;
			}
			if ( '' !== $object_type && ! $personal && $this->unwatched( (int) $member['id'], $object_type, $object_id ) ) {
				continue;
			}
			$enabled = $this->enabled_channels( $event, $definition, $prefs[ (int) $member['id'] ] ?? array() );
			if ( ! $enabled ) {
				continue;
			}
			$inapp  = in_array( 'inapp', $enabled, true );
			$email  = in_array( 'email', $enabled, true ) && ! empty( $member['email'] );
			$pref   = $prefs[ (int) $member['id'] ] ?? array();
			$digest = (string) ( $pref['digest'] ?? 'instant' );
			$quiet  = $email && 'instant' === $digest ? $this->quiet_until( $member, $pref ) : null;
			$row    = $repo->insert(
				array(
					'member_id'     => (int) $member['id'],
					'event'         => $event,
					'tab'           => (string) ( $payload['tab'] ?? $definition['tab'] ),
					'title'         => mb_substr( wp_strip_all_tags( (string) $payload['title'] ), 0, 255 ),
					'body'          => isset( $payload['body'] ) ? mb_substr( wp_strip_all_tags( (string) $payload['body'] ), 0, 500 ) : null,
					'object_type'   => '' !== $object_type ? $object_type : null,
					'object_id'     => $object_id ? $object_id : null,
					'path'          => '' !== $path ? $path : null,
					'actor_id'      => $actor_id ? $actor_id : null,
					'read_at'       => $inapp ? null : gmdate( 'Y-m-d H:i:s' ),
					'archived'      => $inapp ? 0 : 1,
					'snoozed_until' => $quiet,
					'emailed'       => $email ? ( 'instant' === $digest ? 'pending' : 'digest' ) : 'no',
					'dedupe'        => '' !== $dedupe ? $dedupe : null,
				)
			);
			foreach ( $enabled as $channel ) {
				$deliver = $channels->deliverer( $channel );
				if ( $deliver ) {
					call_user_func( $deliver, $row, $member );
				}
			}
			++$count;
		}
		return $count;
	}

	/**
	 * Channels switched on for an event under a member's preferences.
	 *
	 * @param string               $event      Event id.
	 * @param array<string, mixed> $definition Event definition.
	 * @param array<string, mixed> $pref       Preference row (decoded).
	 * @return string[]
	 */
	public function enabled_channels( string $event, array $definition, array $pref ): array {
		$all    = $this->plugin->get( ChannelRegistry::class )->ids();
		$preset = (string) ( $pref['preset'] ?? 'default' );
		switch ( $preset ) {
			case 'everything':
				$channels = array_values( array_intersect( array( 'inapp', 'email' ), $all ) );
				break;
			case 'nothing':
				$channels = array();
				break;
			case 'messages_mentions':
				$channels = str_starts_with( $event, 'message.' ) || str_ends_with( $event, '.mention' ) ? array( 'inapp', 'email' ) : array( 'inapp' );
				break;
			case 'important':
				$channels = ! empty( $definition['important'] ) ? array( 'inapp', 'email' ) : array( 'inapp' );
				break;
			default:
				$channels = (array) $definition['defaults'];
		}
		$matrix = (array) ( $pref['matrix'] ?? array() );
		if ( 'custom' === $preset || isset( $matrix[ $event ] ) ) {
			$custom = (array) ( $matrix[ $event ] ?? array() );
			foreach ( $all as $channel ) {
				if ( array_key_exists( $channel, $custom ) ) {
					$channels = $custom[ $channel ] ? array_unique( array_merge( $channels, array( $channel ) ) ) : array_diff( $channels, array( $channel ) );
				}
			}
		}
		return array_values( array_intersect( $channels, $all ) );
	}

	/**
	 * Decoded preferences for members.
	 *
	 * @param int[] $members Member ids.
	 * @return array<int, array<string, mixed>>
	 */
	private function prefs_many( array $members ): array {
		$out = array();
		foreach ( $this->plugin->service( 'notification_prefs' )->repository()->where( array( 'member_id' => $members ) ) as $row ) {
			$row['matrix']                  = null === $row['matrix'] ? array() : (array) json_decode( (string) $row['matrix'], true );
			$out[ (int) $row['member_id'] ] = $row;
		}
		return $out;
	}

	/**
	 * When an email to a member may go (UTC), or null when it may go now.
	 *
	 * Two things hold an email back: quiet hours (a window at night, and
	 * weekends if the person asked) and working hours (the days and hours
	 * somebody works — cliezoo.md §6.32, "working-hours awareness"). They
	 * are applied in turn until neither moves the time any more, so "quiet
	 * on weekends" and "I work Tuesday to Friday" together release on
	 * Tuesday morning rather than on Monday.
	 *
	 * @param array<string, mixed> $member Member row.
	 * @param array<string, mixed> $pref   Preferences.
	 * @param int|null             $now    Unix time (tests).
	 * @return string|null
	 */
	public function quiet_until( array $member, array $pref, ?int $now = null ): ?string {
		$local = ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( $this->member_timezone( $member ) );
		$moved = false;
		// More rounds than any real week needs; the bound only guards
		// against settings that could never both be met.
		for ( $round = 0; $round < 8; $round++ ) {
			$next = self::quiet_release( $local, $pref ) ?? self::work_release( $local, $pref );
			if ( null === $next ) {
				break;
			}
			$local = $next;
			$moved = true;
		}
		return $moved ? $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null;
	}

	/**
	 * The end of the quiet hours a local moment falls in, or null.
	 *
	 * @param \DateTimeImmutable   $local Local time.
	 * @param array<string, mixed> $pref  Preferences.
	 * @return \DateTimeImmutable|null
	 */
	private static function quiet_release( \DateTimeImmutable $local, array $pref ): ?\DateTimeImmutable {
		$start = (string) ( $pref['quiet_start'] ?? '' );
		$end   = (string) ( $pref['quiet_end'] ?? '' );
		if ( ! empty( $pref['quiet_weekends'] ) && in_array( (int) $local->format( 'N' ), array( 6, 7 ), true ) ) {
			return $local->modify( 'next monday' )->setTime( '' !== $end ? (int) substr( $end, 0, 2 ) : 8, '' !== $end ? (int) substr( $end, 3, 2 ) : 0 );
		}
		if ( '' === $start || '' === $end ) {
			return null;
		}
		$minutes = (int) $local->format( 'G' ) * 60 + (int) $local->format( 'i' );
		$from    = self::minutes( $start );
		$to      = self::minutes( $end );
		$inside  = $from <= $to ? ( $minutes >= $from && $minutes < $to ) : ( $minutes >= $from || $minutes < $to );
		if ( ! $inside ) {
			return null;
		}
		$release = $local->setTime( intdiv( $to, 60 ), $to % 60 );
		if ( $release <= $local ) {
			$release = $release->modify( '+1 day' );
		}
		return $release;
	}

	/**
	 * The next start of working hours when a local moment is outside them, or null.
	 *
	 * Public and static so reminders can hold an email to somebody outside
	 * the team (who has no preferences of their own) by the workspace's
	 * working hours in that person's time zone.
	 *
	 * @param \DateTimeImmutable   $local Local time.
	 * @param array<string, mixed> $pref  work_start, work_end (HH:MM), work_days (ISO 1–7).
	 * @return \DateTimeImmutable|null
	 */
	public static function work_release( \DateTimeImmutable $local, array $pref ): ?\DateTimeImmutable {
		$start = (string) ( $pref['work_start'] ?? '' );
		$end   = (string) ( $pref['work_end'] ?? '' );
		$days  = self::work_days( $pref );
		if ( '' === $start || '' === $end || ! $days ) {
			return null;
		}
		$from = self::minutes( $start );
		$to   = self::minutes( $end );
		if ( $from >= $to ) {
			// A working day that ends before it starts is a typing slip,
			// and holding every email for it would hold them for ever.
			return null;
		}
		$minutes = (int) $local->format( 'G' ) * 60 + (int) $local->format( 'i' );
		$workday = in_array( (int) $local->format( 'N' ), $days, true );
		if ( $workday && $minutes >= $from && $minutes < $to ) {
			return null;
		}
		// Later today when today's work has not started yet; otherwise the
		// first working day after today.
		$candidate = $local->setTime( intdiv( $from, 60 ), $from % 60 );
		if ( $workday && $minutes < $from ) {
			return $candidate;
		}
		for ( $day = 1; $day <= 7; $day++ ) {
			$next = $candidate->modify( '+' . $day . ' days' );
			if ( in_array( (int) $next->format( 'N' ), $days, true ) ) {
				return $next;
			}
		}
		return null;
	}

	/**
	 * Working days (ISO 1 = Monday … 7 = Sunday) from preferences.
	 *
	 * @param array<string, mixed> $pref Preferences.
	 * @return int[]
	 */
	public static function work_days( array $pref ): array {
		$days = $pref['work_days'] ?? null;
		if ( is_string( $days ) ) {
			$days = json_decode( $days, true );
		}
		if ( ! is_array( $days ) ) {
			return array();
		}
		$days = array_filter( array_map( 'intval', $days ), static fn( int $day ): bool => $day >= 1 && $day <= 7 );
		sort( $days );
		return array_values( array_unique( $days ) );
	}

	/**
	 * "09:30" as minutes after midnight.
	 *
	 * @param string $time HH:MM.
	 * @return int
	 */
	private static function minutes( string $time ): int {
		return (int) substr( $time, 0, 2 ) * 60 + (int) substr( $time, 3, 2 );
	}

	/**
	 * A member's time zone (member setting → workspace → site).
	 *
	 * @param array<string, mixed> $member Member row.
	 * @return \DateTimeZone
	 */
	public function member_timezone( array $member ): \DateTimeZone {
		$zone = (string) ( $member['timezone'] ?? '' );
		if ( '' === $zone ) {
			$zone = (string) $this->plugin->settings()->get( 'workspace', 'timezone', '' );
		}
		try {
			return '' !== $zone ? new \DateTimeZone( $zone ) : wp_timezone();
		} catch ( \Exception $e ) {
			return wp_timezone();
		}
	}

	/**
	 * Sends pending instant emails whose quiet hours are over (runs every minute, 50 at a time).
	 *
	 * @return int Sent.
	 */
	public function release_quiet(): int {
		global $wpdb;
		$table = esc_sql( $this->plugin->entities()->get( 'notifications' )->table() );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE emailed = 'pending' AND (snoozed_until IS NULL OR snoozed_until <= %s) ORDER BY id LIMIT 50", gmdate( 'Y-m-d H:i:s' ) ) );
		$sent  = 0;
		foreach ( (array) $ids as $id ) {
			$sent += $this->deliver_email( (int) $id ) ? 1 : 0;
		}
		return $sent;
	}

	/**
	 * Emails one notification.
	 *
	 * @param int $id Notification id.
	 * @return bool
	 */
	public function deliver_email( int $id ): bool {
		$repo = $this->plugin->service( 'notifications' )->repository();
		$row  = $repo->find( $id );
		if ( ! $row || 'pending' !== $row['emailed'] ) {
			return false;
		}
		// Claim first so a parallel run cannot send twice.
		if ( 1 !== $repo->update_where(
			array( 'emailed' => 'sent' ),
			array(
				'id'      => $id,
				'emailed' => 'pending',
			)
		) ) {
			return false;
		}
		$member = $this->plugin->service( 'members' )->repository()->find( (int) $row['member_id'] );
		if ( ! $member || empty( $member['email'] ) ) {
			$repo->update( $id, array( 'emailed' => 'skipped' ) );
			return false;
		}
		// The wording this event has of its own, or the generic one. A
		// studio that wants "a task is waiting for you" to read differently
		// from "a file was approved" can now say so without rewriting the
		// sentence every other event shares.
		$event    = $this->plugin->get( EventCatalog::class )->get( (string) ( $row['event'] ?? '' ) );
		$template = (string) ( $event['template'] ?? '' );
		if ( '' === $template || ! isset( $this->plugin->get( EmailTemplates::class )->defaults()[ $template ] ) ) {
			$template = 'notification';
		}

		return $this->plugin->get( Mailer::class )->send_template(
			$template,
			(string) $member['email'],
			array(
				'recipient_name' => (string) $member['name'],
				'title'          => (string) $row['title'],
				'body'           => (string) $row['body'],
				'url'            => $this->url( (string) $row['path'] ),
				'is_client'      => 'client' === $member['kind'],
			),
			array(
				'member_id'   => (int) $member['id'],
				'object_type' => $row['object_type'],
				'object_id'   => $row['object_id'],
				'category'    => 'notification',
				// So a reply can find its way back into the thread.
				'message_id'  => $this->plugin->get( \Cliezoo\Infrastructure\Mail\ReplyByEmail::class )->message_id( (string) $row['uid'] ),
			)
		);
	}

	/**
	 * Sends due digests (hourly job).
	 *
	 * @param int|null $now Unix time (tests).
	 * @return int Digests sent.
	 */
	public function send_digests( ?int $now = null ): int {
		global $wpdb;
		$now   = $now ?? time();
		$prefs = $this->plugin->service( 'notification_prefs' )->repository();
		$table = esc_sql( $this->plugin->entities()->get( 'notifications' )->table() );
		$sent  = 0;
		foreach ( $prefs->where( array( 'digest' => array( 'hourly', 'daily', 'weekly' ) ) ) as $pref ) {
			$member = $this->plugin->service( 'members' )->repository()->find( (int) $pref['member_id'] );
			if ( ! $member || 'active' !== $member['status'] || empty( $member['email'] ) || ! $this->digest_due( $pref, $member, $now ) ) {
				continue;
			}
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, title, body, path, event FROM {$table} WHERE member_id = %d AND emailed = 'digest' ORDER BY id LIMIT 100", (int) $member['id'] ), ARRAY_A );
			$prefs->update( (int) $pref['id'], array( 'last_digest_at' => gmdate( 'Y-m-d H:i:s', $now ) ) );
			if ( ! $rows ) {
				continue;
			}
			$items = array();
			foreach ( $rows as $row ) {
				$items[] = array(
					'title' => (string) $row['title'],
					'body'  => (string) $row['body'],
					'url'   => $this->url( (string) $row['path'] ),
				);
			}
			$ids = array_map( 'intval', array_column( $rows, 'id' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET emailed = 'sent' WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			/**
			 * What a digest says, before it is sent (an add-on may add a
			 * "summary" line above the list).
			 *
			 * @param array $values The digest's values.
			 * @param array $member Who it is for.
			 */
			$values = (array) apply_filters(
				'cliezoo_digest_values',
				array(
					'recipient_name' => (string) $member['name'],
					'items'          => $items,
					'count'          => count( $items ),
					'is_client'      => 'client' === $member['kind'],
				),
				$member
			);
			$this->plugin->get( Mailer::class )->send_template(
				'notification_digest',
				(string) $member['email'],
				$values,
				array(
					'member_id' => (int) $member['id'],
					'category'  => 'digest',
				)
			);
			++$sent;
		}
		return $sent;
	}

	/**
	 * Whether a member's digest is due now.
	 *
	 * @param array<string, mixed> $pref   Preferences.
	 * @param array<string, mixed> $member Member.
	 * @param int                  $now    Unix time.
	 * @return bool
	 */
	private function digest_due( array $pref, array $member, int $now ): bool {
		$last = empty( $pref['last_digest_at'] ) ? 0 : (int) strtotime( $pref['last_digest_at'] . ' UTC' );
		if ( 'hourly' === $pref['digest'] ) {
			return $now - $last >= HOUR_IN_SECONDS - 60;
		}
		$local = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $this->member_timezone( $member ) );
		if ( (int) $local->format( 'G' ) !== (int) $pref['digest_hour'] ) {
			return false;
		}
		if ( 'weekly' === $pref['digest'] && 1 !== (int) $local->format( 'N' ) ) {
			return false;
		}
		return $now - $last >= 20 * HOUR_IN_SECONDS;
	}

	/**
	 * Absolute app URL for a path.
	 *
	 * @param string $path Path below the app base.
	 * @return string
	 */
	public function url( string $path ): string {
		/**
		 * The app base URL (e.g. https://site.com/dash/).
		 *
		 * @param string $url URL.
		 */
		$base = (string) apply_filters( 'cliezoo_app_url', home_url( '/dash/' ) );
		return '' === $path ? $base : trailingslashit( $base ) . ltrim( $path, '/' );
	}

	/**
	 * Lists the actor's notifications.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    Input.
	 * @return array<string, mixed>
	 */
	public function list( Actor $actor, array $in ): array {
		$repo          = $this->plugin->service( 'notifications' )->repository();
		$query         = new ListQuery();
		$query->limit  = (int) ( $in['limit'] ?? 30 );
		$query->cursor = (string) ( $in['cursor'] ?? '' );
		$query->sort   = '-created_at';
		$query->where( 't.member_id = %d AND t.archived = %d', array( $actor->member_id, empty( $in['archived'] ) ? 0 : 1 ) );
		$query->where( '(t.snoozed_until IS NULL OR t.snoozed_until <= %s OR t.emailed <> %s)', array( gmdate( 'Y-m-d H:i:s' ), 'no' ) );
		$query->where( '(t.hidden_until IS NULL OR t.hidden_until <= %s)', array( gmdate( 'Y-m-d H:i:s' ) ) );
		$tab = (string) ( $in['tab'] ?? 'all' );
		if ( 'messages' === $tab ) {
			$tab = 'chats';
		}
		if ( 'all' !== $tab ) {
			$query->where( 't.tab = %s', array( $tab ) );
		}
		if ( ! empty( $in['unread'] ) ) {
			$query->where( 't.read_at IS NULL' );
		}
		$result = $repo->list( $query, null );
		$actors = $this->plugin->service( 'members' )->repository()->refs( array_column( $result['rows'], 'actor_id' ) );
		$data   = array();
		foreach ( $result['rows'] as $row ) {
			$data[] = array(
				'uid'        => (string) $row['uid'],
				'event'      => (string) $row['event'],
				'tab'        => (string) $row['tab'],
				'title'      => (string) $row['title'],
				'body'       => $row['body'],
				'path'       => $row['path'],
				'actor'      => $row['actor_id'] ? ( $actors[ (int) $row['actor_id'] ] ?? null ) : null,
				'read'       => null !== $row['read_at'],
				'created_at' => Serializer::iso( $row['created_at'] ),
			);
		}
		return array(
			'data' => $data,
			'meta' => array(
				'next_cursor' => $result['next_cursor'],
				'has_more'    => $result['has_more'],
			),
		);
	}

	/**
	 * Unread counts per tab.
	 *
	 * @param Actor $actor Actor.
	 * @return array<string, int>
	 */
	public function counts( Actor $actor ): array {
		global $wpdb;
		$table = esc_sql( $this->plugin->entities()->get( 'notifications' )->table() );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT tab, COUNT(*) AS c FROM {$table} WHERE member_id = %d AND archived = 0 AND read_at IS NULL AND (snoozed_until IS NULL OR snoozed_until <= %s OR emailed <> 'no') AND (hidden_until IS NULL OR hidden_until <= %s) GROUP BY tab", $actor->member_id, gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
		$out   = array( 'all' => 0 );
		foreach ( $rows as $row ) {
			$out[ (string) $row['tab'] ] = (int) $row['c'];
			$out['all']                 += (int) $row['c'];
		}
		return $out;
	}

	/**
	 * Marks read/unread, archives or snoozes notifications of the actor.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    Input.
	 * @return array<string, int>
	 */
	public function mark( Actor $actor, array $in ): array {
		global $wpdb;
		$table = esc_sql( $this->plugin->entities()->get( 'notifications' )->table() );
		$set   = array();
		$args  = array();
		if ( isset( $in['read'] ) && $in['read'] ) {
			$set[]  = 'read_at = COALESCE(read_at, %s)';
			$args[] = gmdate( 'Y-m-d H:i:s' );
		} elseif ( isset( $in['read'] ) ) {
			$set[] = 'read_at = NULL';
		}
		if ( isset( $in['archive'] ) ) {
			$set[]  = 'archived = %d';
			$args[] = $in['archive'] ? 1 : 0;
		}
		if ( array_key_exists( 'snooze_until', $in ) ) {
			if ( null === $in['snooze_until'] ) {
				$set[] = 'hidden_until = NULL';
			} else {
				$set[]  = 'hidden_until = %s';
				$args[] = Serializer::to_mysql( $in['snooze_until'] );
			}
		}
		if ( ! $set ) {
			return $this->counts( $actor );
		}
		$where  = 'member_id = %d';
		$args[] = $actor->member_id;
		if ( empty( $in['all'] ) ) {
			$uids = array_values( (array) ( $in['uids'] ?? array() ) );
			if ( ! $uids ) {
				return $this->counts( $actor );
			}
			$where .= ' AND uid IN (' . implode( ',', array_fill( 0, count( $uids ), '%s' ) ) . ')';
			$args   = array_merge( $args, $uids );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $set ) . " WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Columns are fixed strings above; every value is a placeholder.
		return $this->counts( $actor );
	}

	/**
	 * Which member's preferences the actor edits (self, or a client contact / member they manage).
	 *
	 * @param Actor  $actor      Actor.
	 * @param string $member_uid Member uid ('' = self).
	 * @return array<string, mixed> Member row.
	 * @throws ActionError When not allowed.
	 */
	public function prefs_target( Actor $actor, string $member_uid ): array {
		$repo = $this->plugin->service( 'members' )->repository();
		if ( '' === $member_uid ) {
			return (array) $repo->find( $actor->member_id );
		}
		$member = $repo->find_by_uid( $member_uid );
		if ( ! $member ) {
			throw ActionError::not_found();
		}
		if ( (int) $member['id'] === $actor->member_id ) {
			return $member;
		}
		$allowed = $actor->is_admin()
			|| ( 'client' === $member['kind'] && $actor->allows( 'clients_edit' ) )
			|| ( 'client' === $member['kind'] && $actor->is_client_admin() && array_intersect( $actor->client_ids, $this->plugin->get( \Cliezoo\Platform\Members\Members::class )->client_ids( (int) $member['id'] ) ) );
		if ( ! $allowed ) {
			throw ActionError::forbidden();
		}
		return $member;
	}

	/**
	 * Saves preferences.
	 *
	 * @param array<string, mixed> $member Member row.
	 * @param array<string, mixed> $in     Values.
	 * @return void
	 */
	public function save_prefs( array $member, array $in ): void {
		$repo   = $this->plugin->service( 'notification_prefs' )->repository();
		$row    = $repo->first( array( 'member_id' => (int) $member['id'] ) );
		$events = $this->plugin->get( EventCatalog::class )->all();
		$data   = array();
		foreach ( array( 'preset', 'digest', 'digest_hour', 'quiet_start', 'quiet_end', 'quiet_weekends', 'work_start', 'work_end' ) as $key ) {
			if ( array_key_exists( $key, $in ) ) {
				$data[ $key ] = is_bool( $in[ $key ] ) ? (int) $in[ $key ] : $in[ $key ];
			}
		}
		if ( array_key_exists( 'work_days', $in ) ) {
			$days              = self::work_days( array( 'work_days' => (array) $in['work_days'] ) );
			$data['work_days'] = $days ? wp_json_encode( $days ) : null;
		}
		if ( isset( $in['matrix'] ) ) {
			$clean    = array();
			$channels = $this->plugin->get( ChannelRegistry::class )->ids();
			foreach ( (array) $in['matrix'] as $event => $values ) {
				if ( ! isset( $events[ $event ] ) ) {
					continue;
				}
				foreach ( (array) $values as $channel => $on ) {
					if ( in_array( $channel, $channels, true ) ) {
						$clean[ $event ][ $channel ] = (bool) $on;
					}
				}
			}
			$data['matrix'] = wp_json_encode( $clean );
		}
		if ( $row ) {
			$repo->update( (int) $row['id'], $data );
		} else {
			$repo->insert( $data + array( 'member_id' => (int) $member['id'] ) );
		}
	}

	/**
	 * Preferences in UI form: resolved matrix per event/channel.
	 *
	 * @param array<string, mixed> $member Member row.
	 * @return array<string, mixed>
	 */
	public function prefs_for_ui( array $member ): array {
		$pref   = $this->prefs_many( array( (int) $member['id'] ) )[ (int) $member['id'] ] ?? array();
		$matrix = array();
		foreach ( $this->plugin->get( EventCatalog::class )->all() as $event => $definition ) {
			if ( 'client' === $member['kind'] && 'team' === $definition['audience'] ) {
				continue;
			}
			$enabled = $this->enabled_channels( $event, $definition, $pref );
			foreach ( $this->plugin->get( ChannelRegistry::class )->ids() as $channel ) {
				$matrix[ $event ][ $channel ] = in_array( $channel, $enabled, true );
			}
		}
		return array(
			'member'         => array(
				'uid'   => (string) $member['uid'],
				'label' => (string) $member['name'],
			),
			'preset'         => (string) ( $pref['preset'] ?? 'default' ),
			'matrix'         => $matrix,
			'digest'         => (string) ( $pref['digest'] ?? 'instant' ),
			'digest_hour'    => (int) ( $pref['digest_hour'] ?? 8 ),
			'quiet_start'    => $pref['quiet_start'] ?? null,
			'quiet_end'      => $pref['quiet_end'] ?? null,
			'quiet_weekends' => ! empty( $pref['quiet_weekends'] ),
			'work_start'     => $pref['work_start'] ?? null,
			'work_end'       => $pref['work_end'] ?? null,
			'work_days'      => self::work_days( $pref ),
		);
	}

	/**
	 * Whether a member explicitly unwatched an object.
	 *
	 * @param int    $member Member id.
	 * @param string $type   Entity.
	 * @param int    $id     Row id.
	 * @return bool
	 */
	private function unwatched( int $member, string $type, int $id ): bool {
		$row = $this->plugin->service( 'watches' )->repository()->first(
			array(
				'member_id'   => $member,
				'object_type' => $type,
				'object_id'   => $id,
			)
		);
		return $row && ! (int) $row['watching'];
	}

	/**
	 * Whether a member watches an object.
	 *
	 * @param int    $member Member id.
	 * @param string $type   Entity.
	 * @param int    $id     Row id.
	 * @return bool
	 */
	public function is_watching( int $member, string $type, int $id ): bool {
		$row = $this->plugin->service( 'watches' )->repository()->first(
			array(
				'member_id'   => $member,
				'object_type' => $type,
				'object_id'   => $id,
			)
		);
		return $row && (int) $row['watching'];
	}

	/**
	 * Sets watch state.
	 *
	 * @param int    $member   Member id.
	 * @param string $type     Entity.
	 * @param int    $id       Row id.
	 * @param bool   $watching Watching.
	 * @return void
	 */
	public function watch( int $member, string $type, int $id, bool $watching ): void {
		global $wpdb;
		$wpdb->replace(
			$this->plugin->entities()->get( 'watches' )->table(),
			array(
				'member_id'   => $member,
				'object_type' => $type,
				'object_id'   => $id,
				'watching'    => $watching ? 1 : 0,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Watchers of an object (explicit watchers only).
	 *
	 * @param string $type Entity.
	 * @param int    $id   Row id.
	 * @return int[]
	 */
	public function watchers( string $type, int $id ): array {
		return array_map(
			'intval',
			array_column(
				$this->plugin->service( 'watches' )->repository()->where(
					array(
						'object_type' => $type,
						'object_id'   => $id,
						'watching'    => 1,
					)
				),
				'member_id'
			)
		);
	}

	/**
	 * The people who look after the workspace: active owners and admins.
	 *
	 * @return int[] Member ids.
	 */
	public function admins(): array {
		$out = array();
		foreach ( $this->plugin->service( 'members' )->repository()->where(
			array(
				'kind'   => 'staff',
				'status' => 'active',
				'role'   => array( 'owner', 'admin' ),
			)
		) as $row ) {
			$out[] = (int) $row['id'];
		}
		return $out;
	}

	/**
	 * Tells the owners and admins that scheduled work ran late.
	 *
	 * The site's cron did not run for a while (a quiet site, a server that
	 * was down): what was due has now been caught up, and the people who
	 * run the workspace should know that reminders and emails went out
	 * late, and that a real cron job would stop it (Blueprint §31, "cron
	 * didn't run for 3 days"). Said once a day at most.
	 *
	 * @param int    $seconds How late the work ran.
	 * @param string $kind    tick|hourly|daily.
	 * @return int Notifications created.
	 */
	public function jobs_late( int $seconds, string $kind = 'tick' ): int {
		$hours = max( 1, (int) floor( $seconds / HOUR_IN_SECONDS ) );
		return $this->notify(
			'system.jobs_late',
			$this->admins(),
			array(
				'title'  => $hours >= 48
					/* translators: %d: number of days. */
					? sprintf( _n( 'Scheduled work had not run for %d day and has now caught up', 'Scheduled work had not run for %d days and has now caught up', intdiv( $hours, 24 ), 'cliezoo' ), intdiv( $hours, 24 ) )
					/* translators: %d: number of hours. */
					: sprintf( _n( 'Scheduled work had not run for %d hour and has now caught up', 'Scheduled work had not run for %d hours and has now caught up', $hours, 'cliezoo' ), $hours ),
				'body'   => __( 'Reminders and emails that were due in that time went out late. WordPress runs scheduled work only when somebody visits the site; a real cron job on the server keeps it on time.', 'cliezoo' ),
				'path'   => 'settings/health',
				'dedupe' => 'system.jobs_late:' . $kind . ':' . gmdate( 'Y-m-d' ),
			)
		);
	}

	/**
	 * A member's display name.
	 *
	 * @param int $member_id Member id.
	 * @return string
	 */
	public function member_name( int $member_id ): string {
		$row = $this->plugin->service( 'members' )->repository()->find( $member_id );
		return $row ? (string) $row['name'] : __( 'Someone', 'cliezoo' );
	}
}
