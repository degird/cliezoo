<?php
/**
 * Personal data about a client contact, for WordPress's privacy tools.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Privacy;

use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The workspace exporter and eraser work from a WordPress account. Most of
 * the people a business keeps about are not account holders: they are
 * client contacts, who may never have signed in. GDPR's "everything about
 * one person" is them too (cliezoo.md §6.41), so these find people by the
 * email address on their contact record, and take in what they said in
 * the portal (messages and comments) and the files they uploaded.
 *
 * Erasing anonymises rather than deletes the contact, because invoices,
 * projects and signed documents point at it; blanks what they wrote; and
 * sends what they uploaded to the trash, from which it is deleted for
 * good when the trash is emptied. Somebody who still has a live portal
 * account is left alone until that access is ended, the same rule the
 * member eraser follows.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class ContactData {

	/**
	 * Messages, comments or files per page.
	 */
	public const PAGE = 100;

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
	 * Contact records with this email address.
	 *
	 * @param string $email Email.
	 * @return array<int, array<string, mixed>>
	 */
	private function contacts( string $email ): array {
		if ( '' === $email || ! $this->plugin->entities()->has( 'contacts' ) ) {
			return array();
		}
		return $this->plugin->service( 'contacts' )->repository()->where( array( 'email' => $email ), 'id', 50, true );
	}

	/**
	 * Portal members behind those contacts.
	 *
	 * @param array<int, array<string, mixed>> $contacts Contacts.
	 * @return int[]
	 */
	private function members( array $contacts ): array {
		return array_values( array_unique( array_filter( array_map( static fn( array $row ): int => (int) ( $row['member_id'] ?? 0 ), $contacts ) ) ) );
	}

	/**
	 * Exports one page: the contact records (page 1), then what they wrote.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (1-based).
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$email    = sanitize_email( (string) $email );
		$page     = max( 1, (int) $page );
		$contacts = $this->contacts( $email );
		$items    = array();
		if ( 1 === $page ) {
			foreach ( $contacts as $contact ) {
				$items[] = $this->contact_item( $contact );
			}
			foreach ( $this->files( $this->members( $contacts ), 0, 500 ) as $file ) {
				$items[] = array(
					'group_id'    => 'cliezoo-contact-files',
					'group_label' => __( 'Files you uploaded', 'cliezoo' ),
					'item_id'     => 'file-' . $file['uid'],
					'data'        => array(
						array(
							'name'  => __( 'Name', 'cliezoo' ),
							'value' => (string) $file['name'],
						),
						array(
							'name'  => __( 'Size (bytes)', 'cliezoo' ),
							'value' => (string) (int) $file['size'],
						),
						array(
							'name'  => __( 'Uploaded', 'cliezoo' ),
							'value' => (string) $file['created_at'],
						),
					),
				);
			}
		}
		$messages = $this->messages( $this->members( $contacts ), ( $page - 1 ) * self::PAGE );
		foreach ( $messages as $row ) {
			$items[] = array(
				'group_id'    => 'cliezoo-contact-messages',
				'group_label' => __( 'Messages and comments you wrote', 'cliezoo' ),
				'item_id'     => 'comment-' . $row['uid'],
				'data'        => array(
					array(
						'name'  => __( 'Written', 'cliezoo' ),
						'value' => (string) $row['created_at'],
					),
					array(
						'name'  => __( 'Message', 'cliezoo' ),
						'value' => wp_strip_all_tags( (string) $row['body'] ),
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => count( $messages ) < self::PAGE,
		);
	}

	/**
	 * One contact record as an export item.
	 *
	 * @param array<string, mixed> $contact Contact.
	 * @return array<string, mixed>
	 */
	private function contact_item( array $contact ): array {
		$fields = array(
			'name'           => __( 'Full name', 'cliezoo' ),
			'preferred_name' => __( 'Preferred name', 'cliezoo' ),
			'email'          => __( 'Email', 'cliezoo' ),
			'phone'          => __( 'Phone', 'cliezoo' ),
			'title'          => __( 'Job title', 'cliezoo' ),
			'role'           => __( 'Role at the client', 'cliezoo' ),
			'timezone'       => __( 'Time zone', 'cliezoo' ),
			'locale'         => __( 'Language', 'cliezoo' ),
			'portal_status'  => __( 'Portal access', 'cliezoo' ),
			'created_at'     => __( 'Added', 'cliezoo' ),
			'last_login_at'  => __( 'Last sign-in', 'cliezoo' ),
		);
		$data   = array();
		foreach ( $fields as $key => $label ) {
			if ( isset( $contact[ $key ] ) && '' !== (string) $contact[ $key ] ) {
				$data[] = array(
					'name'  => $label,
					'value' => (string) $contact[ $key ],
				);
			}
		}
		$others = is_string( $contact['emails'] ?? null ) ? json_decode( (string) $contact['emails'], true ) : null;
		if ( is_array( $others ) && $others ) {
			$data[] = array(
				'name'  => __( 'Other email addresses', 'cliezoo' ),
				'value' => implode( ', ', array_map( static fn( $one ): string => is_array( $one ) ? (string) ( $one['email'] ?? '' ) : (string) $one, $others ) ),
			);
		}
		if ( ! empty( $contact['client_id'] ) && $this->plugin->entities()->has( 'clients' ) ) {
			$client = $this->plugin->service( 'clients' )->repository()->find( (int) $contact['client_id'] );
			if ( $client ) {
				$data[] = array(
					'name'  => __( 'Company', 'cliezoo' ),
					'value' => (string) $client['name'],
				);
			}
		}
		return array(
			'group_id'    => 'cliezoo-contact',
			'group_label' => __( 'Contact record', 'cliezoo' ),
			'item_id'     => 'contact-' . $contact['uid'],
			'data'        => $data,
		);
	}

	/**
	 * Erases one page.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (1-based; each call works on what is left).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$email    = sanitize_email( (string) $email );
		$contacts = $this->contacts( $email );
		$removed  = false;
		$retained = false;
		$messages = array();
		if ( ! $contacts ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		$live = array_filter( $contacts, static fn( array $row ): bool => 'active' === (string) ( $row['portal_status'] ?? '' ) );
		if ( $live ) {
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array( __( 'This person still has access to the client portal. End their portal access in Cliezoo first, then run the eraser again.', 'cliezoo' ) ),
				'done'           => true,
			);
		}
		$members = $this->members( $contacts );

		// What they wrote: a page at a time, blanked in place so a thread
		// still reads in order and nobody's reply points at nothing.
		$written = $this->messages( $members, 0, true );
		if ( $written ) {
			global $wpdb;
			$table = esc_sql( $this->plugin->entities()->get( 'comments' )->table() );
			$ids   = array_map( 'intval', array_column( $written, 'id' ) );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET body = %s, files = NULL, mentioned = NULL WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
					array_merge( array( '<p>' . esc_html__( 'Removed at the writer\'s request.', 'cliezoo' ) . '</p>' ), $ids )
				)
			);
			$removed = true;
		}
		$done = count( $written ) < self::PAGE;

		if ( $done ) {
			$system = Actor::system( 'privacy' );
			$files  = $this->plugin->service( 'files' );
			foreach ( $this->files( $members, 0, self::PAGE ) as $file ) {
				try {
					$files->trash_row( $system, $file );
					$removed = true;
				} catch ( \Throwable $error ) {
					unset( $error );
					$retained = true;
				}
			}
			$repo = $this->plugin->service( 'contacts' )->repository();
			foreach ( $contacts as $contact ) {
				$repo->update(
					(int) $contact['id'],
					array(
						'name'           => __( 'Former contact', 'cliezoo' ),
						'preferred_name' => null,
						'handle'         => null,
						'title'          => null,
						'phone'          => null,
						'emails'         => null,
						'role'           => null,
						// The column must hold an address; this one can never
						// be delivered to (.invalid is reserved, RFC 2606).
						'email'          => 'erased-' . strtolower( (string) $contact['uid'] ) . '@example.invalid',
					)
				);
				$removed = true;
			}
			// The search index holds a copy of the name and address; it is
			// written again from the anonymised row.
			if ( $this->plugin->container()->has( \Cliezoo\Platform\Search\Search::class ) ) {
				$entity = $this->plugin->entities()->get( 'contacts' );
				foreach ( $contacts as $contact ) {
					$this->plugin->get( \Cliezoo\Platform\Search\Search::class )->index( $entity, (array) $repo->find( (int) $contact['id'] ) );
				}
			}
			$retained   = true;
			$messages[] = __( 'The contact record was anonymised rather than deleted, because invoices, projects and signed documents refer to it. Files this person uploaded were moved to the trash and are deleted for good when the trash is emptied.', 'cliezoo' );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Messages and comments written by these members.
	 *
	 * @param int[] $members   Member ids.
	 * @param int   $offset    Offset.
	 * @param bool  $remaining Only ones not blanked yet.
	 * @return array<int, array<string, mixed>>
	 */
	private function messages( array $members, int $offset, bool $remaining = false ): array {
		global $wpdb;
		if ( ! $members || ! $this->plugin->entities()->has( 'comments' ) ) {
			return array();
		}
		$table = esc_sql( $this->plugin->entities()->get( 'comments' )->table() );
		$in    = implode( ',', array_fill( 0, count( $members ), '%d' ) );
		$left  = $remaining ? ' AND body NOT LIKE %s' : '';
		$args  = $members;
		if ( $remaining ) {
			$args[] = '%' . $wpdb->esc_like( __( 'Removed at the writer\'s request.', 'cliezoo' ) ) . '%';
		}
		$args[] = self::PAGE;
		$args[] = $offset;
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholders built above.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, uid, body, created_at FROM {$table} WHERE created_by IN ({$in}) AND deleted_at IS NULL{$left} ORDER BY id ASC LIMIT %d OFFSET %d", $args ), ARRAY_A );
	}

	/**
	 * Files uploaded by these members.
	 *
	 * @param int[] $members Member ids.
	 * @param int   $offset  Offset.
	 * @param int   $limit   Limit.
	 * @return array<int, array<string, mixed>>
	 */
	private function files( array $members, int $offset, int $limit ): array {
		global $wpdb;
		if ( ! $members || ! $this->plugin->entities()->has( 'files' ) ) {
			return array();
		}
		$table = esc_sql( $this->plugin->entities()->get( 'files' )->table() );
		$in    = implode( ',', array_fill( 0, count( $members ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared -- Placeholders built above.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_by IN ({$in}) AND deleted_at IS NULL ORDER BY id ASC LIMIT %d OFFSET %d", array_merge( $members, array( $limit, $offset ) ) ), ARRAY_A );
	}
}
