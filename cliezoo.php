<?php
/**
 * Plugin Name:       Cliezoo
 * Plugin URI:        https://cliezoo.com/
 * Description:       A simple client manager inside WordPress: leads, clients, contacts, files, an inbox and a home dashboard for your team.
 * Version:           0.1.2
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Degird
 * Author URI:        https://degird.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cliezoo
 * Domain Path:       /languages
 *
 * @package Cliezoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CLIEZOO_VERSION', '0.1.2' );
define( 'CLIEZOO_FILE', __FILE__ );
define( 'CLIEZOO_PATH', plugin_dir_path( __FILE__ ) );
define( 'CLIEZOO_URL', plugin_dir_url( __FILE__ ) );
define( 'CLIEZOO_EXTENSION_API', 1 );
define( 'CLIEZOO_MIN_WP', '7.0' );
define( 'CLIEZOO_MIN_PHP', '8.1' );

require_once CLIEZOO_PATH . 'src/Core/Requirements.php';

if ( ! \Cliezoo\Core\Requirements::met() ) {
	add_action( 'admin_notices', array( \Cliezoo\Core\Requirements::class, 'render_notice' ) );
	return;
}

require_once CLIEZOO_PATH . 'vendor/autoload.php';
// Libraries renamed into this plugin's own namespace, so that a
// second plugin carrying the same library cannot decide which
// copy either of them runs (docs/decisions/0005-vendor-prefixing.md).
if ( is_readable( CLIEZOO_PATH . 'vendor-prefixed/autoload.php' ) ) {
	require_once CLIEZOO_PATH . 'vendor-prefixed/autoload.php';
}
// Action Scheduler is deliberately not among them: every plugin
// that ships it registers its copy, and the newest one wins for
// the whole site. A renamed copy would run a second scheduler
// over the same tables.
require_once CLIEZOO_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

register_activation_hook( __FILE__, array( \Cliezoo\Core\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Cliezoo\Core\Installer::class, 'deactivate' ) );

/**
 * The Cliezoo plugin instance.
 *
 * @return \Cliezoo\Core\Plugin
 */
function cliezoo(): \Cliezoo\Core\Plugin {
	return \Cliezoo\Core\Plugin::instance();
}

add_action(
	'plugins_loaded',
	static function (): void {
		cliezoo()->boot();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\Cliezoo\Core\Cli::register();
		}
	},
	0
);

// A new site on a network where Cliezoo is network-activated gets its tables.
add_action(
	'wp_initialize_site',
	static function ( $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( CLIEZOO_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		\Cliezoo\Core\Installer::install_site();
		restore_current_blog();
	},
	20
);
