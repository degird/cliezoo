<?php
/**
 * Script and style registration for the app bundles.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the built bundles from `build/{entry}.asset.php` manifests.
 *
 * Handles: `cliezoo-data` (API client, query cache, stores → `window.cliezoo.data`),
 * `cliezoo-ui` (design system → `window.cliezoo.ui`), `cliezoo-app` (shell, routes,
 * extension registry → `window.cliezoo.app`) and `cliezoo-public` (the small
 * bundle for token pages). Add-ons declare these handles as dependencies
 * and never ship their own React.
 */
final class Assets {

	/**
	 * Entries in dependency order.
	 */
	public const ENTRIES = array( 'data', 'ui', 'app', 'public' );

	/**
	 * Registered already.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Whether a built entry exists.
	 *
	 * @param string $entry Entry.
	 * @return bool
	 */
	public static function built( string $entry ): bool {
		return is_readable( CLIEZOO_PATH . 'build/' . $entry . '.js' ) && is_readable( CLIEZOO_PATH . 'build/' . $entry . '.asset.php' );
	}

	/**
	 * Registers every built entry (scripts deferred, in the footer).
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		// Registered before the bundles that want it, so it can go in
		// their dependencies rather than be pushed onto the global
		// registry afterwards.
		$aria = self::aria_locale( determine_locale() );
		if ( '' !== $aria ) {
			wp_register_script(
				'cliezoo-aria-locale',
				CLIEZOO_URL . 'build/aria-locales/' . $aria . '.js',
				array(),
				CLIEZOO_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
		foreach ( self::ENTRIES as $entry ) {
			if ( ! self::built( $entry ) ) {
				continue;
			}
			$asset  = (array) require CLIEZOO_PATH . 'build/' . $entry . '.asset.php';
			$handle = 'cliezoo-' . $entry;
			$deps   = array_values( (array) ( $asset['dependencies'] ?? array() ) );
			// React Aria reads its own locale data; the app gets it
			// through the ui bundle, the public pages through their own.
			if ( '' !== $aria && in_array( $entry, array( 'ui', 'public' ), true ) ) {
				$deps[] = 'cliezoo-aria-locale';
			}
			wp_register_script(
				$handle,
				CLIEZOO_URL . 'build/' . $entry . '.js',
				$deps,
				(string) ( $asset['version'] ?? CLIEZOO_VERSION ),
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			wp_set_script_translations( $handle, 'cliezoo', CLIEZOO_PATH . 'languages' );
			self::chunk_translations( $entry, $handle );
			if ( is_readable( CLIEZOO_PATH . 'build/' . $entry . '.css' ) ) {
				wp_register_style( $handle, CLIEZOO_URL . 'build/' . $entry . '.css', 'app' === $entry ? array( 'cliezoo-ui' ) : array(), (string) ( $asset['version'] ?? CLIEZOO_VERSION ) );
				wp_style_add_data( $handle, 'rtl', 'replace' );
			}
		}
	}

	/**
	 * Translations for the chunks an entry loads later.
	 *
	 * WordPress gives a script the translations of its own file, and a
	 * chunk loaded on demand is a different file with its own JSON. So an
	 * entry carries its chunks' translations too, added to the same text
	 * domain before it runs: a screen opened later reads in the language the
	 * rest of the page is in.
	 *
	 * @param string $entry  Entry name ('app', 'public').
	 * @param string $handle Its script handle.
	 * @return void
	 */
	private static function chunk_translations( string $entry, string $handle ): void {
		$locale = determine_locale();
		if ( 'en_US' === $locale ) {
			return;
		}
		$chunks = glob( CLIEZOO_PATH . 'build/chunks/' . $entry . '.*.js' );
		$merged = array();
		foreach ( is_array( $chunks ) ? $chunks : array() as $chunk ) {
			$md5 = md5( 'build/chunks/' . basename( $chunk ) );
			foreach ( array( WP_LANG_DIR . '/plugins', CLIEZOO_PATH . 'languages' ) as $dir ) {
				$file = $dir . '/cliezoo-' . $locale . '-' . $md5 . '.json';
				if ( ! is_readable( $file ) ) {
					continue;
				}
				$json     = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
				$messages = is_array( $json ) ? (array) ( $json['locale_data']['messages'] ?? $json['locale_data']['cliezoo'] ?? array() ) : array();
				unset( $messages[''] );
				$merged += $messages;
				break;
			}
		}
		if ( $merged ) {
			wp_add_inline_script( $handle, 'wp.i18n.setLocaleData( ' . wp_json_encode( $merged ) . ', "cliezoo" );', 'before' );
		}
	}

	/**
	 * Makes the app installable: the web manifest and a service worker that
	 * caches the build files and nothing else.
	 *
	 * @return void
	 */
	private static function installable(): void {
		add_action(
			'wp_head',
			static function (): void {
				printf(
					'<link rel="manifest" href="%s">' . "\n",
					esc_url( AppRouter::app_url( 'manifest.webmanifest' ) )
				);
			},
			5
		);
		wp_add_inline_script(
			'cliezoo-theme',
			"if ('serviceWorker' in navigator) { window.addEventListener('load', function () { navigator.serviceWorker.register("
				. wp_json_encode( AppRouter::app_url( 'sw.js' ) )
				. ', { scope: ' . wp_json_encode( AppRouter::app_url() ) . " }).catch(function () {}); }); }"
		);
	}

	/**
	 * Enqueues the shell bundle, the pre-paint theme script and the boot data.
	 *
	 * @param string $context app|public.
	 * @param string $path    Path inside the base.
	 * @return void
	 */
	public static function enqueue_shell( string $context, string $path ): void {
		self::register();
		$handle = 'app' === $context ? 'cliezoo-app' : 'cliezoo-public';
		$boot   = ( new Boot( cliezoo() ) )->data( $context, $path );

		// Before first paint: resolve light/dark from the saved choice or the system (no flash).
		wp_register_script( 'cliezoo-theme', false, array(), CLIEZOO_VERSION, false );
		wp_enqueue_script( 'cliezoo-theme' );
		wp_add_inline_script( 'cliezoo-theme', self::theme_script( (string) $boot['theme'] ) );

		if ( 'app' === $context ) {
			self::installable();
		}

		if ( 'app' === $context && wp_style_is( 'cliezoo-ui', 'registered' ) ) {
			wp_enqueue_style( 'cliezoo-ui' );
		}
		if ( wp_style_is( $handle, 'registered' ) ) {
			wp_enqueue_style( $handle );
		}
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}
		wp_add_inline_script( $handle, 'window.cliezooBoot=' . wp_json_encode( $boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . ';', 'before' );
		wp_enqueue_script( $handle );
	}

	/**
	 * The React Aria strings file for a WordPress locale ('' for English or
	 * languages React Aria does not ship; those fall back to English).
	 *
	 * @param string $locale WordPress locale, e.g. "de_DE".
	 * @return string File tag, e.g. "de-DE".
	 */
	public static function aria_locale( string $locale ): string {
		$tag = str_replace( '_', '-', $locale );
		if ( str_starts_with( $tag, 'en' ) ) {
			return '';
		}
		$dir = CLIEZOO_PATH . 'build/aria-locales/';
		if ( preg_match( '/^[a-z]{2}-[A-Z]{2}$/', $tag ) && is_readable( $dir . $tag . '.js' ) ) {
			return $tag;
		}
		$language = strtolower( substr( $tag, 0, 2 ) );
		$files    = glob( $dir . $language . '-*.js' );
		return $files ? basename( (string) $files[0], '.js' ) : '';
	}

	/**
	 * The pre-paint theme resolver.
	 *
	 * @param string $saved Saved preference (light|dark|system).
	 * @return string
	 */
	public static function theme_script( string $saved ): string {
		$saved = in_array( $saved, array( 'light', 'dark', 'system' ), true ) ? $saved : 'system';
		return '(function(d){var p=' . wp_json_encode( $saved ) . ';try{var l=localStorage.getItem("cliezoo-theme");if(l==="light"||l==="dark"||l==="system"){p=l;}}catch(e){}'
			. 'var m=p==="system"?(window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"):p;'
			. 'd.documentElement.setAttribute("data-theme",m);d.documentElement.setAttribute("data-theme-pref",p);})(document);';
	}
}
