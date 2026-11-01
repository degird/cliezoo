<?php
/**
 * WordPress personal data export and erasure (Tools → Export/Erase Personal Data).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Privacy;

use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Cliezoo's personal data exporter and eraser with WordPress
 * core's privacy tools, plus a suggested privacy-policy text.
 *
 * - Export: the person's member profile, app preferences, sign-in history
 *   and the emails Cliezoo sent to their address.
 * - Erase: preferences, known devices, sign-in tokens and the email log are
 *   removed; the member profile is anonymised once the person is no longer
 *   active. Security and audit records, invoices, signed documents and
 *   payments are kept where the law or the business needs them, and the
 *   eraser says so (items_retained + messages).
 * - Client contacts, who often have no WordPress account, have their own
 *   exporter and eraser (ContactData): the contact record, what they wrote
 *   and what they uploaded.
 */
final class PrivacyModule extends BaseModule {

	private const PAGE = 100;

	/**
	 * Plugin.
	 *
	 * @var Plugin|null
	 */
	private ?Plugin $plugin = null;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'privacy';
	}

	/**
	 * {@inheritDoc}
	 */
	public function switchable(): bool {
		return false;
	}

	/**
	 * Registers hooks.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function exporters( array $exporters ): array {
		$exporters['cliezoo']          = array(
			'exporter_friendly_name' => __( 'Cliezoo workspace', 'cliezoo' ),
			'callback'               => array( $this, 'export' ),
		);
		$exporters['cliezoo-contacts'] = array(
			'exporter_friendly_name' => __( 'Cliezoo client contacts', 'cliezoo' ),
			'callback'               => array( $this->contact_data(), 'export' ),
		);
		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function erasers( array $erasers ): array {
		$erasers['cliezoo']          = array(
			'eraser_friendly_name' => __( 'Cliezoo workspace', 'cliezoo' ),
			'callback'             => array( $this, 'erase' ),
		);
		$erasers['cliezoo-contacts'] = array(
			'eraser_friendly_name' => __( 'Cliezoo client contacts', 'cliezoo' ),
			'callback'             => array( $this->contact_data(), 'erase' ),
		);
		return $erasers;
	}

	/**
	 * The contact exporter and eraser.
	 *
	 * @return ContactData
	 */
	public function contact_data(): ContactData {
		return new ContactData( $this->plugin() );
	}

	/**
	 * The plugin.
	 *
	 * @return Plugin
	 */
	private function plugin(): Plugin {
		return $this->plugin ?? cliezoo();
	}

	/**
	 * The member row for an email address.
	 *
	 * @param string $email Email.
	 * @return array<string, mixed>|null
	 */
	private function member( string $email ): ?array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return null;
		}
		return $this->plugin()->service( 'members' )->repository()->first( array( 'user_id' => (int) $user->ID ) );
	}

	/**
	 * Exports one page of personal data.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based).
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$email  = sanitize_email( (string) $email );
		$page   = max( 1, (int) $page );
		$items  = array();
		$member = $this->member( $email );
		if ( 1 === $page && $member ) {
			$fields = array(
				'name'          => __( 'Name', 'cliezoo' ),
				'email'         => __( 'Email', 'cliezoo' ),
				'handle'        => __( 'Handle', 'cliezoo' ),
				'title'         => __( 'Job title', 'cliezoo' ),
				'phone'         => __( 'Phone', 'cliezoo' ),
				'kind'          => __( 'Account type', 'cliezoo' ),
				'role'          => __( 'Role', 'cliezoo' ),
				'status'        => __( 'Status', 'cliezoo' ),
				'timezone'      => __( 'Time zone', 'cliezoo' ),
				'locale'        => __( 'Language', 'cliezoo' ),
				'created_at'    => __( 'Added', 'cliezoo' ),
				'last_login_at' => __( 'Last sign-in', 'cliezoo' ),
			);
			$data   = array();
			foreach ( $fields as $key => $label ) {
				if ( isset( $member[ $key ] ) && '' !== (string) $member[ $key ] ) {
					$data[] = array(
						'name'  => $label,
						'value' => (string) $member[ $key ],
					);
				}
			}
			$items[] = array(
				'group_id'    => 'cliezoo-profile',
				'group_label' => __( 'Cliezoo profile', 'cliezoo' ),
				'item_id'     => 'member-' . $member['uid'],
				'data'        => $data,
			);
			$prefs   = get_user_meta( (int) $member['user_id'], 'cliezoo_prefs', true );
			if ( is_array( $prefs ) && $prefs ) {
				$items[] = array(
					'group_id'    => 'cliezoo-preferences',
					'group_label' => __( 'Cliezoo app preferences', 'cliezoo' ),
					'item_id'     => 'prefs-' . $member['uid'],
					'data'        => array_map(
						static fn( $key, $value ): array => array(
							'name'  => (string) $key,
							'value' => is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ),
						),
						array_keys( $prefs ),
						$prefs
					),
				);
			}
			foreach ( $this->sign_ins( (int) $member['user_id'] ) as $row ) {
				$items[] = array(
					'group_id'    => 'cliezoo-sign-ins',
					'group_label' => __( 'Cliezoo sign-in history', 'cliezoo' ),
					'item_id'     => 'sign-in-' . $row['id'],
					'data'        => array(
						array(
							'name'  => __( 'Event', 'cliezoo' ),
							'value' => (string) $row['event'],
						),
						array(
							'name'  => __( 'Date', 'cliezoo' ),
							'value' => (string) $row['created_at'],
						),
						array(
							'name'  => __( 'IP address', 'cliezoo' ),
							'value' => (string) $row['ip'],
						),
						array(
							'name'  => __( 'Browser', 'cliezoo' ),
							'value' => (string) $row['user_agent'],
						),
					),
				);
			}
		}
		$emails = $this->emails( $email, $page );
		foreach ( $emails as $row ) {
			$items[] = array(
				'group_id'    => 'cliezoo-emails',
				'group_label' => __( 'Emails sent by Cliezoo', 'cliezoo' ),
				'item_id'     => 'email-' . $row['uid'],
				'data'        => array(
					array(
						'name'  => __( 'Subject', 'cliezoo' ),
						'value' => (string) $row['subject'],
					),
					array(
						'name'  => __( 'Status', 'cliezoo' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'Date', 'cliezoo' ),
						'value' => (string) ( $row['sent_at'] ?? $row['created_at'] ),
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => count( $emails ) < self::PAGE,
		);
	}

	/**
	 * Erases one page of personal data.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		global $wpdb;
		$email    = sanitize_email( (string) $email );
		$removed  = false;
		$retained = false;
		$messages = array();
		$member   = $this->member( $email );

		$log     = esc_sql( $this->plugin()->entities()->get( 'email_log' )->table() );
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE to_email = %s LIMIT %d", $email, self::PAGE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from code.
		$removed = $deleted > 0;
		$done    = $deleted < self::PAGE;

		if ( $done && $member ) {
			$user_id = (int) $member['user_id'];
			foreach ( array( 'cliezoo_prefs', '_cliezoo_known_devices', '_cliezoo_active_client' ) as $key ) {
				$removed = delete_user_meta( $user_id, $key ) || $removed;
			}
			$tokens  = esc_sql( $this->plugin()->entities()->get( 'auth_tokens' )->table() );
			$removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$tokens} WHERE user_id = %d", $user_id ) ) > 0 || $removed; // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from code.

			if ( 'active' === $member['status'] ) {
				$retained   = true;
				$messages[] = __( 'The Cliezoo profile was kept because this person is still an active member. Deactivate or remove them in Cliezoo first, then run the eraser again.', 'cliezoo' );
			} else {
				$this->plugin()->service( 'members' )->repository()->update(
					(int) $member['id'],
					array(
						'name'   => __( 'Former member', 'cliezoo' ),
						'email'  => null,
						'handle' => null,
						'title'  => null,
						'phone'  => null,
					)
				);
				$removed = true;
			}
			$retained   = true;
			$messages[] = __( 'Security and audit records (sign-ins, permission changes) and legal records such as invoices, payments and signed documents were kept, as the law and the business may need them.', 'cliezoo' );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Sign-in events of a user.
	 *
	 * @param int $user_id User.
	 * @return array<int, array<string, mixed>>
	 */
	private function sign_ins( int $user_id ): array {
		global $wpdb;
		$table = esc_sql( $this->plugin()->entities()->get( 'audit_log' )->table() );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, event, ip, user_agent, created_at FROM {$table} WHERE user_id = %d AND event IN ('login','login_failed','logout') ORDER BY id DESC LIMIT 500", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from code.
	}

	/**
	 * One page of emails sent to an address.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array<int, array<string, mixed>>
	 */
	private function emails( string $email, int $page ): array {
		global $wpdb;
		$table = esc_sql( $this->plugin()->entities()->get( 'email_log' )->table() );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT uid, subject, status, sent_at, created_at FROM {$table} WHERE to_email = %s ORDER BY id ASC LIMIT %d OFFSET %d", $email, self::PAGE, ( $page - 1 ) * self::PAGE ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from code.
	}

	/**
	 * Suggested text for the site's privacy policy.
	 *
	 * @return void
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . __( 'This site uses Cliezoo to work with clients. When you are a client or a team member, Cliezoo stores your name, email address, the details you or the team enter (projects, messages, files, invoices, documents) and security records such as sign-in times, IP addresses and browser types.', 'cliezoo' ) . '</p>'
			. '<p>' . __( 'Emails sent to you by the app are logged so the team can see whether they were delivered. Security and legal records (sign-in history, signed documents, invoices and payments) are kept as long as the law requires, even after a request to erase your data.', 'cliezoo' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Cliezoo', 'cliezoo' ), wp_kses_post( wpautop( $text, false ) ) );
	}
}
