<?php
/**
 * What deleting the plugin removes.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The data-safe uninstall of Blueprint §6.5.
 *
 * Deleting the plugin always removes what is only temporary: transients,
 * scheduled actions and caches. Everything else — tables, settings, user
 * meta, roles and capabilities, the private files folder — is removed only
 * when the workspace switched on "Delete all Cliezoo data when the plugin
 * is deleted" (off by default). This is the deliberate, documented
 * exception to wp_extra_compliance.md §10.3 (owner decision D5): invoices,
 * contracts and e-signature evidence are legal records, and a plugin
 * deleted by mistake must not take them with it.
 *
 * Kept apart from uninstall.php so it can be tested: uninstall.php only
 * loads this file and runs it for each site. The plugin is not booted
 * during uninstall, so nothing here may use the plugin's services.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
 */
final class Uninstaller {

	/**
	 * Recurring and one-off hooks Cliezoo schedules.
	 */
	private const HOOKS = array( 'cliezoo_tick', 'cliezoo_hourly', 'cliezoo_daily', 'cliezoo_run_job' );

	/**
	 * Runs the uninstall for the current site.
	 *
	 * @return array{full: bool}
	 */
	public static function run_site(): array {
		$settings = get_option( 'cliezoo_settings_workspace' );
		$full     = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );
		self::temporary();
		if ( $full ) {
			self::everything();
		}
		return array( 'full' => $full );
	}

	/**
	 * Removes what is only temporary: transients, scheduled actions, caches.
	 *
	 * @return void
	 */
	public static function temporary(): void {
		global $wpdb;
		foreach ( array( '_transient_cliezoo_', '_transient_timeout_cliezoo_' ) as $prefix ) {
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
			foreach ( (array) $names as $name ) {
				delete_option( (string) $name );
			}
		}
		if ( is_multisite() ) {
			foreach ( array( '_site_transient_cliezoo_', '_site_transient_timeout_cliezoo_' ) as $prefix ) {
				$names = $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
				foreach ( (array) $names as $name ) {
					delete_site_option( (string) $name );
				}
			}
		}
		foreach ( self::HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		self::scheduled_actions();
		// Locks and heartbeats are state of a running plugin, not data.
		foreach ( array( 'cliezoo_period_lock_hour', 'cliezoo_period_lock_day' ) as $option ) {
			delete_option( $option );
		}
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'cliezoo' );
		}
	}

	/**
	 * Removes Cliezoo's Action Scheduler actions and their logs.
	 *
	 * Action Scheduler's own functions are not loaded when the plugin that
	 * bundles it is being deleted, so the tables are cleaned directly —
	 * only rows with Cliezoo's hooks, since another plugin may share them.
	 *
	 * @return int Actions removed.
	 */
	public static function scheduled_actions(): int {
		global $wpdb;
		$actions = esc_sql( $wpdb->prefix . 'actionscheduler_actions' );
		if ( $actions !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions ) ) ) ) {
			return 0;
		}
		$like = $wpdb->esc_like( 'cliezoo_' ) . '%';
		$logs = esc_sql( $wpdb->prefix . 'actionscheduler_logs' );
		if ( $logs === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $logs ) ) ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE l FROM {$logs} l INNER JOIN {$actions} a ON a.action_id = l.action_id WHERE a.hook LIKE %s", $like ) );
		}
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$actions} WHERE hook LIKE %s", $like ) );
	}

	/**
	 * Removes every Cliezoo table, option, user meta, role, capability and
	 * the private files folder.
	 *
	 * @return void
	 */
	public static function everything(): void {
		global $wpdb;
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'cliezoo_' ) . '%' ) );
		foreach ( (array) $tables as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( (string) $table ) . '`' );
		}
		$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'cliezoo_' ) . '%' ) );
		foreach ( (array) $options as $option ) {
			delete_option( (string) $option );
		}
		self::user_meta();
		self::capabilities();
		self::private_files();
	}

	/**
	 * Removes Cliezoo's user meta.
	 *
	 * Both spellings: public preferences (cliezoo_prefs) and the private
	 * bookkeeping WordPress hides from custom-field screens
	 * (_cliezoo_known_devices, _cliezoo_active_client…).
	 *
	 * @return int Rows removed.
	 */
	public static function user_meta(): int {
		global $wpdb;
		$removed = 0;
		foreach ( array( 'cliezoo_', '_cliezoo_' ) as $prefix ) {
			$removed += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		}
		wp_cache_flush();
		return $removed;
	}

	/**
	 * Removes Cliezoo's roles and capabilities, on roles and on people.
	 *
	 * @return void
	 */
	public static function capabilities(): void {
		global $wpdb;
		foreach ( array( 'cliezoo_member', 'cliezoo_client' ) as $role ) {
			remove_role( $role );
		}
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( array_keys( $role->capabilities ) as $capability ) {
				if ( str_starts_with( (string) $capability, 'cliezoo_' ) ) {
					$role->remove_cap( (string) $capability );
				}
			}
		}
		// Capabilities given to one person rather than to a role.
		$users = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s", $wpdb->get_blog_prefix() . 'capabilities', '%' . $wpdb->esc_like( 'cliezoo_' ) . '%' ) );
		foreach ( (array) $users as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( ! $user ) {
				continue;
			}
			foreach ( array_keys( $user->caps ) as $capability ) {
				if ( str_starts_with( (string) $capability, 'cliezoo_' ) ) {
					$user->remove_cap( (string) $capability );
				}
			}
		}
	}

	/**
	 * Deletes the private files folder.
	 *
	 * The default folder (uploads/cliezoo-private) always goes. A folder
	 * set with CLIEZOO_PRIVATE_DIR goes only when its name says it is
	 * Cliezoo's: it was chosen by hand and could be anywhere, and deleting
	 * the wrong folder recursively is not a mistake anybody gets back.
	 *
	 * @param string[]|null $folders Folders to delete (tests); null for Cliezoo's own.
	 * @return bool Whether a folder was deleted.
	 */
	public static function private_files( ?array $folders = null ): bool {
		if ( null === $folders ) {
			$uploads = wp_get_upload_dir();
			$folders = array( trailingslashit( (string) $uploads['basedir'] ) . 'cliezoo-private' );
			if ( defined( 'CLIEZOO_PRIVATE_DIR' ) && '' !== (string) CLIEZOO_PRIVATE_DIR ) {
				$folders[] = untrailingslashit( (string) CLIEZOO_PRIVATE_DIR );
			}
		}
		$folders = array_filter( $folders, array( self::class, 'is_ours' ) );
		$deleted = false;
		foreach ( array_unique( $folders ) as $folder ) {
			if ( ! is_dir( $folder ) ) {
				continue;
			}
			global $wp_filesystem;
			require_once ABSPATH . 'wp-admin/includes/file.php';
			if ( function_exists( 'WP_Filesystem' ) && WP_Filesystem() && $wp_filesystem ) {
				$deleted = $wp_filesystem->delete( $folder, true ) || $deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Whether a folder's name says it is Cliezoo's.
	 *
	 * @param string $folder Path.
	 * @return bool
	 */
	public static function is_ours( string $folder ): bool {
		return str_contains( strtolower( basename( untrailingslashit( $folder ) ) ), 'cliezoo' );
	}
}
