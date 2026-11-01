<?php
/**
 * Minimum-requirements check (WordPress, PHP, extensions).
 *
 * Kept free of modern syntax so it parses on old PHP and can show a notice.
 *
 * @package Cliezoo
 */

namespace Cliezoo\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks that the site can run Cliezoo.
 */
final class Requirements {

	/**
	 * PHP extensions Cliezoo cannot work without.
	 *
	 * Sodium is not among them: WordPress ships sodium_compat, a pure-PHP
	 * copy of the functions below, for hosts without the extension.
	 *
	 * @var string[]
	 */
	const EXTENSIONS = array( 'json', 'mbstring', 'fileinfo' );

	/**
	 * Sodium functions the secret store uses, from the extension or from
	 * WordPress's sodium_compat.
	 *
	 * @var string[]
	 */
	const SODIUM = array(
		'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt',
		'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt',
		'sodium_crypto_generichash',
	);

	/**
	 * Whether every requirement is met.
	 *
	 * @return bool
	 */
	public static function met() {
		return array() === self::problems();
	}

	/**
	 * Human-readable list of unmet requirements.
	 *
	 * @return string[]
	 */
	public static function problems() {
		global $wp_version;
		$problems = array();

		if ( version_compare( PHP_VERSION, CLIEZOO_MIN_PHP, '<' ) ) {
			/* translators: 1: required PHP version, 2: current PHP version. */
			$problems[] = sprintf( __( 'PHP %1$s or newer is required (this site runs %2$s).', 'cliezoo' ), CLIEZOO_MIN_PHP, PHP_VERSION );
		}
		if ( version_compare( (string) $wp_version, CLIEZOO_MIN_WP, '<' ) ) {
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			$problems[] = sprintf( __( 'WordPress %1$s or newer is required (this site runs %2$s).', 'cliezoo' ), CLIEZOO_MIN_WP, $wp_version );
		}
		foreach ( self::EXTENSIONS as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				/* translators: %s: PHP extension name. */
				$problems[] = sprintf( __( 'The PHP extension "%s" is required.', 'cliezoo' ), $extension );
			}
		}
		foreach ( self::SODIUM as $function ) {
			if ( ! function_exists( $function ) ) {
				$problems[] = __( 'Encryption is not available: neither the PHP "sodium" extension nor WordPress\'s built-in copy of it could be loaded.', 'cliezoo' );
				break;
			}
		}
		if ( ! file_exists( CLIEZOO_PATH . 'vendor/autoload.php' ) ) {
			$problems[] = __( 'The plugin package is incomplete (missing vendor files). Please reinstall Cliezoo.', 'cliezoo' );
		}
		return $problems;
	}

	/**
	 * Admin notice listing the unmet requirements.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Cliezoo cannot start:', 'cliezoo' ) . '</strong></p><ul>';
		foreach ( self::problems() as $problem ) {
			echo '<li>' . esc_html( $problem ) . '</li>';
		}
		echo '</ul></div>';
	}
}
