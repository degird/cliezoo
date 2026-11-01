<?php
/**
 * Activation and deactivation.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core;

use Cliezoo\Infrastructure\Jobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin lifecycle: tables, defaults, capabilities, scheduled jobs.
 * Deactivation keeps all data; uninstall.php handles deletion (Blueprint §6.5).
 */
final class Installer {

	/**
	 * Activation hook (single site or network-wide).
	 *
	 * @param bool $network_wide Network activation.
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}
		self::install_site();
	}

	/**
	 * Installs Cliezoo on the current site.
	 *
	 * @return void
	 */
	public static function install_site(): void {
		$plugin = Plugin::instance();
		$plugin->boot();
		// Before the migrations, which queue work of their own.
		Jobs::ensure_tables();
		$plugin->migrate();
		update_option( 'cliezoo_version', CLIEZOO_VERSION );
		add_option( 'cliezoo_installed_at', gmdate( 'Y-m-d H:i:s' ), '', false );
		add_option( 'cliezoo_site_url', home_url(), '', false );
		self::add_capabilities();
		/**
		 * Cliezoo was installed/activated on a site (modules seed defaults here).
		 *
		 * @param Plugin $plugin Plugin.
		 */
		do_action( 'cliezoo_installed', $plugin );
		set_transient( 'cliezoo_flush_rewrites', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Gives site administrators the Cliezoo capabilities.
	 *
	 * @return void
	 */
	public static function add_capabilities(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( 'cliezoo_access_app' );
			$role->add_cap( 'cliezoo_manage_settings' );
		}
		// People Cliezoo creates get a role with no wp-admin powers; their app
		// permissions come from the Cliezoo role matrix, never from WordPress.
		$caps = array(
			'read'               => true,
			'cliezoo_access_app' => true,
		);
		if ( ! get_role( 'cliezoo_member' ) ) {
			add_role( 'cliezoo_member', __( 'Cliezoo team member', 'cliezoo' ), $caps );
		}
		if ( ! get_role( 'cliezoo_client' ) ) {
			add_role( 'cliezoo_client', __( 'Cliezoo client', 'cliezoo' ), $caps );
		}
	}

	/**
	 * Deactivation: stop scheduled work, keep data.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'cliezoo' );
		}
		wp_clear_scheduled_hook( 'cliezoo_daily' );
		/**
		 * Cliezoo is being deactivated.
		 */
		do_action( 'cliezoo_deactivated' );
		flush_rewrite_rules( false );
	}
}
