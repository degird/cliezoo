<?php
/**
 * The one page Cliezoo keeps in wp-admin.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Admin;

use Cliezoo\Core\App\AppRouter;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Health\Health;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cliezoo is a front-end application: the work happens at /dash, and
 * wp-admin is for installing, for the things WordPress itself owns, and
 * for finding the plugin again after activating it (Blueprint §4.1).
 *
 * So one page, in WordPress's own styles: a way in, how the site is
 * doing, and where the rest lives.
 */
final class AdminPage {

	/**
	 * The menu slug.
	 */
	public const SLUG = 'cliezoo';

	/**
	 * The Cliezoo mark for the menu, as an SVG that WordPress paints in the
	 * admin colour scheme (the C is an outline, not a stroke, so it can).
	 */
	public const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjkuMjUgMTAgODAgODAiPjxwYXRoIGZpbGw9ImJsYWNrIiBkPSJNNjguNCAyNS42QTM0LjUgMzQuNSAwIDEgMCA2OC40IDc0LjRBNi41IDYuNSAwIDAgMCA1OS4yIDY1LjJBMjEuNSAyMS41IDAgMSAxIDU5LjIgMzQuOEE2LjUgNi41IDAgMCAwIDY4LjQgMjUuNloiLz48cGF0aCBmaWxsPSJibGFjayIgZD0iTTc2IDM3Qzc4LjYgNDUuMDYgODAuOTQgNDcuNCA4OSA1MEM4MC45NCA1Mi42IDc4LjYgNTQuOTQgNzYgNjNDNzMuNCA1NC45NCA3MS4wNiA1Mi42IDYzIDUwQzcxLjA2IDQ3LjQgNzMuNCA0NS4wNiA3NiAzN1oiLz48L3N2Zz4=';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Holds the plugin the page speaks for.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers the menu.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	/**
	 * Adds the menu, for whoever may run the workspace.
	 *
	 * @return void
	 */
	public function menu(): void {
		add_menu_page(
			__( 'Cliezoo', 'cliezoo' ),
			__( 'Cliezoo', 'cliezoo' ),
			'cliezoo_manage_settings',
			self::SLUG,
			array( $this, 'render' ),
			self::MENU_ICON,
			30
		);
	}

	/**
	 * The page itself. WordPress's own classes, no stylesheet of our own:
	 * a plugin has no business restyling somebody's admin.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'cliezoo_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'cliezoo' ) );
		}
		$app    = AppRouter::app_url();
		$health = $this->plugin->get( Health::class )->last();
		$checks = (array) ( $health['checks'] ?? array() );
		$bad    = array_values(
			array_filter(
				$checks,
				static fn( array $check ): bool => in_array( (string) ( $check['status'] ?? '' ), array( 'warn', 'fail' ), true )
			)
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Cliezoo', 'cliezoo' ) . '</h1>';
		echo '<p>' . esc_html__( 'Your workspace lives on the front end of this site. Everything — clients, projects, files, conversations and settings — is there.', 'cliezoo' ) . '</p>';

		echo '<p>';
		printf(
			'<a class="button button-primary" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a>',
			esc_url( $app ),
			esc_html__( 'Open Cliezoo', 'cliezoo' ),
			esc_url( AppRouter::app_url( 'settings' ) ),
			esc_html__( 'Settings', 'cliezoo' )
		);
		echo '</p>';

		echo '<h2>' . esc_html__( 'How this site is doing', 'cliezoo' ) . '</h2>';
		if ( ! $checks ) {
			echo '<p>' . esc_html__( 'Nothing has been checked yet. Setup Health runs on its own, or you can open it now.', 'cliezoo' ) . '</p>';
		} elseif ( ! $bad ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: how many checks passed. */
					_n( '%d check passed, with nothing to do.', 'All %d checks passed, with nothing to do.', count( $checks ), 'cliezoo' ),
					count( $checks )
				)
			) . '</p>';
		} else {
			echo '<ul class="ul-disc">';
			foreach ( $bad as $check ) {
				echo '<li><strong>' . esc_html( (string) ( $check['label'] ?? '' ) ) . '</strong> — ' . esc_html( (string) ( $check['detail'] ?? '' ) );
				if ( ! empty( $check['fix'] ) ) {
					echo ' ' . esc_html( (string) $check['fix'] );
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		printf(
			'<p><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( AppRouter::app_url( 'settings/health' ) ),
			esc_html__( 'Open Setup Health', 'cliezoo' )
		);

		echo '<h2>' . esc_html__( 'Where things are', 'cliezoo' ) . '</h2>';
		echo '<ul class="ul-disc">';
		printf(
			'<li>%1$s <code>%2$s</code></li>',
			esc_html__( 'The workspace:', 'cliezoo' ),
			esc_html( $app )
		);
		printf(
			'<li>%1$s <code>%2$s</code></li>',
			esc_html__( 'Your clients sign in at:', 'cliezoo' ),
			esc_html( AppRouter::app_url() )
		);
		echo '<li>' . esc_html__( 'People, roles and permissions are in Cliezoo, not in WordPress users: a client of yours never sees wp-admin.', 'cliezoo' ) . '</li>';
		echo '</ul>';
		echo '</div>';
	}
}
