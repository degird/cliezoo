<?php
/**
 * Catalogue of notification / automation / webhook events.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The EventCatalog (Blueprint §19.1): every documented domain event with its
 * group, label, inbox tab, audience and default channels. Notifications,
 * Add-ons read from it too.
 */
final class EventCatalog {

	/**
	 * Events keyed by id.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $events = null;

	/**
	 * All events.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( null === $this->events ) {
			$events = $this->defaults();
			/**
			 * Register events: id => [group, label, tab, audience (team|client|both), defaults (channels), important].
			 *
			 * @param array $events Events.
			 */
			$this->events = (array) apply_filters( 'cliezoo_events', $events );
		}
		return $this->events;
	}

	/**
	 * One event.
	 *
	 * @param string $id Event id.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Built-in events.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function defaults(): array {
		// The last argument is the email template this event sends with.
		// Left off, it goes out as the generic notification — a title, a
		// body and a link — which is right for the dozens of events where
		// there is nothing else to say. Where there is, the event names its
		// own template and a studio can edit that wording on its own,
		// rather than editing one sentence that every event shares.
		$e             = static fn( string $group, string $label, string $tab, string $audience = 'both', array $defaults = array( 'inapp', 'email' ), bool $important = false, string $template = '' ): array => array(
			'group'     => $group,
			'label'     => $label,
			'tab'       => $tab,
			'audience'  => $audience,
			'defaults'  => $defaults,
			'important' => $important,
			'template'  => $template,
		);
		$comments = __( 'Comments', 'cliezoo' );
		$files    = __( 'Files', 'cliezoo' );
		$sales    = __( 'Sales', 'cliezoo' );
		$calendar = __( 'Calendar', 'cliezoo' );
		$system   = __( 'System', 'cliezoo' );
		return array(
			'message.mention'         => $e( $comments, __( 'Someone mentions you', 'cliezoo' ), 'mentions', 'both', array( 'inapp', 'email' ), true, 'mention' ),
			'file.comment'            => $e( $files, __( 'New comment on a file', 'cliezoo' ), 'files', 'both', array( 'inapp', 'email' ), false, 'file_comment' ),
			'file.comment_reply'      => $e( $files, __( 'Reply to a comment', 'cliezoo' ), 'files' ),
			'file.comment_resolved'   => $e( $files, __( 'Comment resolved', 'cliezoo' ), 'files', 'both', array( 'inapp' ) ),
			'file.comment_reopened'   => $e( $files, __( 'Comment reopened', 'cliezoo' ), 'files', 'both', array( 'inapp' ) ),
			'file.approved'           => $e( $files, __( 'File approved', 'cliezoo' ), 'approvals', 'both', array( 'inapp', 'email' ), true, 'file_approved' ),
			'file.unapproved'         => $e( $files, __( 'File unapproved', 'cliezoo' ), 'approvals', 'both', array( 'inapp' ) ),
			'file.mention'            => $e( $files, __( 'Mentioned on a file', 'cliezoo' ), 'mentions', 'both', array( 'inapp', 'email' ), true, 'mention' ),
			'folder.file_uploaded'    => $e( $files, __( 'File uploaded to a folder', 'cliezoo' ), 'files', 'both', array( 'inapp' ) ),
			'lead.created'            => $e( $sales, __( 'Lead created or assigned', 'cliezoo' ), 'updates', 'team' ),
			'reminder'                => $e( $calendar, __( 'Reminders', 'cliezoo' ), 'updates', 'both', array( 'inapp', 'email' ), true ),
			'export.ready'            => $e( $system, __( 'Export ready', 'cliezoo' ), 'updates', 'team', array( 'inapp', 'email' ) ),
			'access.granted'          => $e( $system, __( 'You were given access', 'cliezoo' ), 'updates', 'both', array( 'inapp' ) ),
			'security.new_login'      => $e( $system, __( 'New login to your account', 'cliezoo' ), 'updates', 'both', array( 'inapp', 'email' ), true ),
			'security.account_locked' => $e( $system, __( 'An account was locked after failed sign-ins', 'cliezoo' ), 'updates', 'team', array( 'inapp', 'email' ), true ),
			'system.jobs_late'        => $e( $system, __( 'Background work ran late', 'cliezoo' ), 'updates', 'team', array( 'inapp', 'email' ) ),
		);
	}
}
