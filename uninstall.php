<?php
/**
 * Runs when Cliezoo is deleted from the Plugins screen.
 *
 * What is only temporary (transients, scheduled actions, caches) is always
 * removed. Everything else — tables, settings, user meta, roles and
 * capabilities, uploaded private files — is removed only when the
 * workspace asked for it in Settings → Site and data ("Delete all Cliezoo
 * data when the plugin is deleted", off by default).
 *
 * This is a deliberate, documented exception to wp_extra_compliance.md
 * §10.3 (Blueprint §6.5, owner decision D5): invoices, contracts and
 * e-signature evidence are legal records, and deleting a plugin by
 * accident must never destroy a business's records. The readme says so
 * too. The work itself lives in Cliezoo\Platform\Privacy\Uninstaller,
 * where it is tested.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Platform/Privacy/Uninstaller.php';

if ( is_multisite() ) {
	$cliezoo_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $cliezoo_sites as $cliezoo_site ) {
		switch_to_blog( (int) $cliezoo_site );
		\Cliezoo\Platform\Privacy\Uninstaller::run_site();
		restore_current_blog();
	}
} else {
	\Cliezoo\Platform\Privacy\Uninstaller::run_site();
}
