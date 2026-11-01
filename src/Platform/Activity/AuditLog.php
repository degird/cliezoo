<?php
/**
 * Workspace audit log (security events).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Activity;

use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\ListQuery;
use Cliezoo\Core\Data\Repository;
use Cliezoo\Core\Data\Serializer;
use Cliezoo\Core\Logger;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\Request;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security events: logins (success/failure), logouts, permission and role
 * changes, settings changes, exports, impersonation, API and MCP calls.
 */
final class AuditLog {

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
	 * Hooks WordPress and Cliezoo security events.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return void
	 */
	public static function listen( Plugin $plugin ): void {
		$log = static fn(): AuditLog => $plugin->get( self::class );
		add_action(
			'wp_login',
			static function ( string $login, \WP_User $user ) use ( $log ): void {
				$log()->log( 'login', array( 'login' => $login ), 'success', (int) $user->ID );
			},
			10,
			2
		);
		add_action(
			'wp_login_failed',
			static function ( string $login ) use ( $log ): void {
				// Written against the account that was tried, when there is
				// one, so it shows on that person's sign-in history; an
				// attempt on no account stays nobody's.
				$user = get_user_by( 'email', $login );
				$user = $user ? $user : get_user_by( 'login', $login );
				$log()->log( 'login_failed', array( 'login' => mb_substr( $login, 0, 60 ) ), 'failure', $user ? (int) $user->ID : 0 );
			}
		);
		add_action(
			'wp_logout',
			static function ( int $user_id ) use ( $log ): void {
				$log()->log( 'logout', array(), 'success', $user_id );
			}
		);
		add_action(
			'cliezoo_settings_updated',
			static function ( string $module ) use ( $log ): void {
				$log()->log( 'settings_changed', array( 'module' => $module ) );
			}
		);
		add_action(
			'cliezoo_audit',
			static function ( string $event, array $meta = array(), string $status = 'success', ?int $user_id = null ) use ( $log ): void {
				$log()->log( $event, $meta, $status, $user_id );
			},
			10,
			4
		);

		// Every create, update and delete (cliezoo.md §6.42). The activity
		// timeline says what changed on a record, for the people working
		// on it; this says who did what where, for the people answerable
		// for the workspace — so it names the fields that changed and
		// never their values, which may be personal or secret.
		$records = array(
			'created'  => 'record_created',
			'trashed'  => 'record_trashed',
			'restored' => 'record_restored',
			'purged'   => 'record_deleted',
		);
		foreach ( $records as $hook => $event ) {
			add_action(
				'cliezoo_record_' . $hook,
				static function ( Entity $entity, array $row ) use ( $log, $event ): void {
					if ( self::audited( $entity ) ) {
						$log()->log( $event, self::record_meta( $entity, $row ) );
					}
				},
				50,
				2
			);
		}
		add_action(
			'cliezoo_record_updated',
			static function ( Entity $entity, array $row, array $before, array $changes ) use ( $log ): void {
				if ( ! self::audited( $entity ) ) {
					return;
				}
				$log()->log( 'record_updated', self::record_meta( $entity, $row ) + array( 'fields' => array_slice( array_keys( $changes ), 0, 40 ) ) );
				// A change of role is a permission change, and is listed as one.
				if ( 'members' === $entity->name && isset( $changes['role'] ) ) {
					$log()->log(
						'role_changed',
						self::record_meta( $entity, $row ) + array(
							'from' => (string) $changes['role'][0],
							'to'   => (string) $changes['role'][1],
						)
					);
				}
			},
			50,
			4
		);

		// API calls: requests from outside the app (Application Passwords).
		// The app's own requests are not API calls and are not listed; for
		// the API, every change is listed and reads are listed once per
		// person and operation every ten minutes, so a script polling a
		// list does not bury the log.
		add_action(
			'cliezoo_after_action',
			static function ( string $name, Actor $actor ) use ( $plugin, $log ): void {
				if ( ! \Cliezoo\Platform\Auth\ApiAccess::is_api_request() ) {
					return;
				}
				$action = $plugin->actions()->get( $name );
				$read   = $action && ! empty( $action->resolved_meta()['readonly'] );
				if ( $read ) {
					$key = 'cliezoo_api_seen_' . md5( $actor->user_id . '|' . $name );
					if ( get_transient( $key ) ) {
						return;
					}
					set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
				}
				$log()->log(
					'api_call',
					array(
						'action' => $name,
						'read'   => $read,
					)
				);
			},
			10,
			2
		);
		add_filter(
			'cliezoo_action_gate',
			static function ( $refusal, $action ) use ( $log ) {
				if ( $refusal instanceof \Cliezoo\Core\Actions\ActionError && 'cliezoo_api_not_allowed' === $refusal->error_code ) {
					$log()->log( 'api_call', array( 'action' => $action->name() ), 'failure' );
				}
				return $refusal;
			},
			99,
			2
		);
	}

	/**
	 * Whether changes to a kind of record are written to the audit log.
	 *
	 * Records that are themselves a log, a queue or somebody's own
	 * bookkeeping (notifications, reminders sent, the email log, watches,
	 * favourites) are not: auditing them would record the audit.
	 *
	 * @param Entity $entity Entity.
	 * @return bool
	 */
	public static function audited( Entity $entity ): bool {
		$skip = array( 'activity', 'audit_log', 'notifications', 'notification_prefs', 'watches', 'reminders', 'email_log', 'auth_tokens', 'two_factor', 'favourites', 'saved_views', 'search_index', 'sequences', 'recents', 'ai_runs' );
		$on   = $entity->public && ! in_array( $entity->name, $skip, true );
		/**
		 * Whether changes to this kind of record are written to the audit log.
		 *
		 * @param bool   $on     Default: public records outside the skip list.
		 * @param Entity $entity Entity.
		 */
		return (bool) apply_filters( 'cliezoo_audited_entity', $on, $entity );
	}

	/**
	 * The object an audit entry is about: its type, id, uid and name.
	 *
	 * @param Entity               $entity Entity.
	 * @param array<string, mixed> $row    Record.
	 * @return array<string, mixed>
	 */
	private static function record_meta( Entity $entity, array $row ): array {
		$meta = array(
			'object_type' => $entity->name,
			'object_id'   => (int) ( $row['id'] ?? 0 ),
		);
		if ( ! empty( $row['uid'] ) ) {
			$meta['uid'] = (string) $row['uid'];
		}
		if ( ! empty( $row['number'] ) ) {
			$meta['number'] = (string) $row['number'];
		}
		return $meta;
	}

	/**
	 * Writes one event for the current user.
	 *
	 * @param string               $event   Event key.
	 * @param array<string, mixed> $meta    Details (secrets are redacted).
	 * @param string               $status  success|failure.
	 * @param int|null             $user_id WordPress user (defaults to current).
	 * @return void
	 */
	public function log( string $event, array $meta = array(), string $status = 'success', ?int $user_id = null ): void {
		$user_id = $user_id ?? get_current_user_id();
		$actor   = $user_id ? $this->plugin->actor() : Actor::guest();
		$member  = $user_id && $actor->user_id !== $user_id ? $this->plugin->get( \Cliezoo\Platform\Members\Members::class )->member_for( $user_id ) : null;
		/**
		 * Details attached to an audit entry.
		 *
		 * Impersonation adds the real person here, so an entry written while
		 * somebody was looking as a client has both names on it.
		 *
		 * @param array  $meta  Details.
		 * @param string $event Event key.
		 */
		$meta = (array) apply_filters( 'cliezoo_audit_meta', $meta, $event );
		$this->plugin->service( 'audit_log' )->repository()->insert(
			array(
				'event'       => mb_substr( $event, 0, 40 ),
				'member_id'   => $member ? (int) $member['id'] : ( $actor->member_id ? $actor->member_id : null ),
				'user_id'     => $user_id ? $user_id : null,
				'channel'     => $this->channel_for( $actor ),
				'object_type' => isset( $meta['object_type'] ) ? (string) $meta['object_type'] : null,
				'object_id'   => isset( $meta['object_id'] ) ? (int) $meta['object_id'] : null,
				'status'      => 'failure' === $status ? 'failure' : 'success',
				'ip'          => Request::ip(),
				'user_agent'  => Request::user_agent(),
				'meta'        => $meta ? wp_json_encode( Logger::redact( $meta ) ) : null,
			)
		);
	}

	/**
	 * How this entry arrived.
	 *
	 * The operation running says how it was called, which is the honest
	 * answer: an ability called by an outside agent is an ability whether it
	 * came over WP-CLI or over HTTP, and filing the HTTP one as "api" is what
	 * made "what has the agent actually done" unanswerable. Outside an
	 * operation — signing in, for instance — an application password still
	 * means the API.
	 *
	 * @param Actor $actor The actor the entry is written for.
	 * @return string
	 */
	private function channel_for( Actor $actor ): string {
		$channel = $this->plugin->actions()->channel();
		if ( '' === $channel ) {
			$channel = $actor->channel;
		}
		if ( \Cliezoo\Platform\Auth\ApiAccess::is_api_request() && in_array( $channel, array( 'app', 'rest' ), true ) ) {
			return 'api';
		}
		return $channel;
	}

	/**
	 * Lists events (needs "audit log" permission).
	 *
	 * @param array<string, mixed> $filter event, member_id, channel, from, to.
	 * @param string               $cursor Cursor.
	 * @param int                  $limit  Page size.
	 * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
	 */
	public function list( array $filter, string $cursor = '', int $limit = 50 ): array {
		$repo          = new Repository( $this->plugin->entities()->get( 'audit_log' ), $this->plugin->entities() );
		$query         = new ListQuery();
		$query->cursor = $cursor;
		$query->limit  = $limit;
		$query->sort   = '-created_at';
		if ( ! empty( $filter['event'] ) ) {
			$query->where( 't.event = %s', array( (string) $filter['event'] ) );
		}
		if ( ! empty( $filter['channel'] ) ) {
			$query->where( 't.channel = %s', array( (string) $filter['channel'] ) );
		}
		if ( ! empty( $filter['member_id'] ) ) {
			$query->where( 't.member_id = %d', array( (int) $filter['member_id'] ) );
		}
		if ( ! empty( $filter['from'] ) ) {
			$query->where( 't.created_at >= %s', array( gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $filter['from'] ) ) ) );
		}
		if ( ! empty( $filter['to'] ) ) {
			$query->where( 't.created_at <= %s', array( gmdate( 'Y-m-d 23:59:59', (int) strtotime( (string) $filter['to'] ) ) ) );
		}
		$result  = $repo->list( $query, null );
		$members = ( new Repository( $this->plugin->entities()->get( 'members' ), $this->plugin->entities() ) )->refs( array_column( $result['rows'], 'member_id' ) );
		$data    = array();
		foreach ( $result['rows'] as $row ) {
			$data[] = array(
				'uid'        => (string) $row['uid'],
				'event'      => (string) $row['event'],
				'status'     => (string) $row['status'],
				'channel'    => (string) $row['channel'],
				'member'     => $row['member_id'] ? ( $members[ (int) $row['member_id'] ] ?? null ) : null,
				'ip'         => (string) $row['ip'],
				'device'     => Request::device( (string) $row['user_agent'] ),
				'meta'       => null === $row['meta'] ? null : json_decode( (string) $row['meta'], true ),
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
}
