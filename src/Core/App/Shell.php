<?php
/**
 * The isolated app/public shell.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\App;

use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Storage\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On app and token routes: no page cache, strict security headers with a CSP
 * nonce, noindex, no theme assets, no admin bar, no Heartbeat, and the
 * Cliezoo bundles + boot data (Blueprint §11.2, §17.2, §18.4).
 */
final class Shell {

	/**
	 * CSP nonce for this response.
	 *
	 * @var string
	 */
	private static string $nonce = '';

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
	 * The CSP nonce.
	 *
	 * @return string
	 */
	public static function nonce(): string {
		if ( '' === self::$nonce ) {
			self::$nonce = rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- CSP nonce.
		}
		return self::$nonce;
	}

	/**
	 * Prepares the response for a shell route.
	 *
	 * @param string $context app|public.
	 * @param string $path    Path inside the base.
	 * @return void
	 */
	public function prepare( string $context, string $path ): void {
		// Before the app's own policy: a service worker is governed by the
		// policy sent with its script, and the app's nonce policy would
		// refuse it.
		if ( 'app' === $context && in_array( $path, array( 'manifest.webmanifest', 'sw.js' ), true ) ) {
			$this->no_cache();
			$this->serve_pwa( $path );
		}
		$this->no_cache();
		$this->security_headers( $context );
		if ( 'public' === $context && preg_match( '#^f/([0-9A-HJKMNP-TV-Z]{26})$#', $path, $m ) ) {
			$this->serve_signed_file( $m[1] );
		}
		if ( 'public' === $context && preg_match( '#^sf/([0-9A-HJKMNP-TV-Z]{26})$#', $path, $m ) ) {
			/**
			 * One file of a public share. Signed against the link it came
			 * from, so the module serving it can refuse a link that has been
			 * taken back since the page was opened.
			 *
			 * @param string $uid File uid.
			 */
			do_action( 'cliezoo_serve_shared_file', $m[1] );
			status_header( 404 );
			exit;
		}
		if ( 'public' === $context && preg_match( '#^df/([0-9A-HJKMNP-TV-Z]{26})$#', $path, $m ) ) {
			/**
			 * The file behind a document that was signed somewhere else. Signed
			 * against the document link it is being read from, so a link that
			 * has been taken back stops reaching the file too.
			 *
			 * @param string $uid File uid.
			 */
			do_action( 'cliezoo_serve_document_file', $m[1] );
			status_header( 404 );
			exit;
		}
		if ( 'public' === $context && preg_match( '#^z/([0-9A-HJKMNP-TV-Z]{26})$#', $path, $m ) ) {
			/**
			 * A signed link to a whole folder, which the Files module serves
			 * if it is switched on. Signed rather than nonced, so the URL a
			 * browser navigates to carries no credential of its own.
			 *
			 * @param string $uid Folder uid.
			 */
			do_action( 'cliezoo_serve_signed_folder', $m[1] );
			status_header( 404 );
			exit;
		}
		add_filter( 'show_admin_bar', '__return_false', 99 );
		add_filter( 'wp_robots', array( $this, 'robots' ), 99 );
		add_action( 'wp_enqueue_scripts', array( $this, 'isolate' ), 9999 );
		add_action( 'wp_print_styles', array( $this, 'isolate_styles' ), 9999 );
		add_action( 'wp_enqueue_scripts', static fn() => Assets::enqueue_shell( $context, $path ), 10000 );
		add_filter( 'wp_script_attributes', array( $this, 'script_nonce' ) );
		add_filter( 'wp_inline_script_attributes', array( $this, 'script_nonce' ) );
		add_filter( 'document_title_parts', array( $this, 'title' ) );
		add_action(
			'init',
			static function (): void {
				wp_deregister_script( 'heartbeat' );
			},
			1
		);
		// No main posts query and no 404 handling: nothing below the base is a WordPress query.
		add_filter( 'posts_pre_query', static fn( $posts, \WP_Query $query ) => $query->is_main_query() ? array() : $posts, 10, 2 );
		add_filter( 'pre_handle_404', '__return_true' );
		add_filter( 'body_class', array( $this, 'body_class' ), 99, 2 );
		add_filter( 'wp_speculation_rules_configuration', '__return_null' );
		add_action( 'wp_head', array( $this, 'trim_head' ), -1 );
		/**
		 * A Cliezoo shell route is being served.
		 *
		 * @param string $context app|public.
		 * @param string $path    Path.
		 */
		do_action( 'cliezoo_shell', $context, $path );
	}

	/**
	 * Page-cache bypass for WordPress and popular cache plugins/CDNs (§18.4).
	 *
	 * @return void
	 */
	private function no_cache(): void {
		foreach ( array( 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB', 'DONOTMINIFY', 'DONOTCDN', 'DONOTROCKETOPTIMIZE' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- These are the names cache plugins read (DONOTCACHEPAGE and its siblings); they cannot carry a prefix.
				define( $constant, true );
			}
		}
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'CDN-Cache-Control: no-store' );
		header( 'Cloudflare-CDN-Cache-Control: no-store' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'Surrogate-Control: no-store' );
		do_action( 'litespeed_control_set_nocache', 'cliezoo app route' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's documented hook.
	}

	/**
	 * CSP and security headers.
	 *
	 * @param string $context app|public.
	 * @return void
	 */
	private function security_headers( string $context ): void {
		if ( headers_sent() ) {
			return;
		}
		/**
		 * Extra CSP sources per directive (payment gateways, storage buckets, embed providers).
		 *
		 * @param array  $sources Directive => list of sources.
		 * @param string $context app|public.
		 */
		$extra      = (array) apply_filters(
			'cliezoo_csp_sources',
			array(
				'connect-src' => array(),
				'frame-src'   => array(),
				'img-src'     => array(),
				'form-action' => array(),
				'script-src'  => array(),
			),
			$context
		);
		$directives = array(
			'default-src'     => array( "'self'" ),
			'script-src'      => array_merge( array( "'self'", "'nonce-" . self::nonce() . "'", "'strict-dynamic'" ), (array) ( $extra['script-src'] ?? array() ) ),
			'style-src'       => array( "'self'", "'unsafe-inline'" ),
			'img-src'         => array_merge( array( "'self'", 'data:', 'blob:', 'https:' ), (array) ( $extra['img-src'] ?? array() ) ),
			'font-src'        => array( "'self'", 'data:' ),
			'connect-src'     => array_merge( array( "'self'" ), (array) ( $extra['connect-src'] ?? array() ) ),
			'media-src'       => array( "'self'", 'blob:' ),
			'worker-src'      => array( "'self'", 'blob:' ),
			'frame-src'       => array_merge( array( "'self'" ), (array) ( $extra['frame-src'] ?? array() ) ),
			'frame-ancestors' => array( "'self'" ),
			'form-action'     => array_merge( array( "'self'" ), (array) ( $extra['form-action'] ?? array() ) ),
			'base-uri'        => array( "'self'" ),
			'object-src'      => array( "'none'" ),
		);
		$csp        = array();
		foreach ( $directives as $name => $sources ) {
			$csp[] = $name . ' ' . implode( ' ', array_unique( array_map( 'strval', $sources ) ) );
		}
		header( 'Content-Security-Policy: ' . implode( '; ', $csp ) );
		// On a token page the address *is* the credential, and a same-origin
		// Referer would write it into the access log of every request the
		// page makes.
		header( 'Referrer-Policy: ' . ( 'public' === $context ? 'no-referrer' : 'strict-origin-when-cross-origin' ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Permissions-Policy: camera=(), geolocation=(), payment=(self), microphone=(self)' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	/**
	 * Serves the two files that make the app installable.
	 *
	 * The service worker deliberately caches nothing but the versioned build
	 * files: records, files and every API answer stay online-only, so a
	 * shared computer cannot serve one person's data to the next.
	 *
	 * @param string $path manifest.webmanifest|sw.js.
	 * @return void
	 */
	private function serve_pwa( string $path ): void {
		nocache_headers();
		if ( 'manifest.webmanifest' === $path ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			echo wp_json_encode( $this->web_manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			exit;
		}
		header( 'Content-Type: text/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . esc_url_raw( AppRouter::app_url() ) );
		header( "Content-Security-Policy: default-src 'none'; script-src 'self'; connect-src 'self'" );
		header( 'X-Content-Type-Options: nosniff' );
		echo self::service_worker(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated JavaScript.
		exit;
	}

	/**
	 * The web manifest that makes the app installable.
	 *
	 * @return array<string, mixed>
	 */
	public function web_manifest(): array {
		$settings = $this->plugin->settings();
		$name     = (string) $settings->get( 'workspace', 'name', get_bloginfo( 'name' ) );
		$branding = (array) ( in_array( 'branding', $settings->modules(), true ) ? $settings->all( 'branding' ) : array() );
		$accent   = sanitize_hex_color( (string) ( $branding['accent'] ?? '' ) );
		$icon     = (string) ( $branding['favicon'] ?? ( $branding['logo'] ?? '' ) );
		// A manifest without icons is not installable, so the plugin ships
		// two of its own. A workspace that has uploaded its mark leads with
		// that, and the defaults stay behind it for the sizes browsers
		// insist on.
		$icons = array();
		if ( '' !== $icon ) {
			$icons[] = array(
				'src'     => esc_url_raw( $icon ),
				'sizes'   => 'any',
				'purpose' => 'any',
			);
		}
		foreach ( array( 192, 512 ) as $size ) {
			$icons[] = array(
				'src'     => esc_url_raw( plugins_url( 'assets/icon-' . $size . '.png', CLIEZOO_FILE ) ),
				'sizes'   => $size . 'x' . $size,
				'type'    => 'image/png',
				'purpose' => 'any',
			);
		}
		return array(
			'name'             => $name,
			'short_name'       => mb_substr( $name, 0, 12 ),
			'description'      => (string) ( $branding['tagline'] ?? '' ),
			'start_url'        => AppRouter::app_url(),
			'scope'            => AppRouter::app_url(),
			'display'          => 'standalone',
			'orientation'      => 'any',
			'background_color' => '#ffffff',
			'theme_color'      => $accent ? $accent : '#0458FB',
			'icons'            => $icons,
			'lang'             => str_replace( '_', '-', determine_locale() ),
			'dir'              => is_rtl() ? 'rtl' : 'ltr',
		);
	}

	/**
	 * The service worker source: versioned build files only.
	 *
	 * @return string
	 */
	public static function service_worker(): string {
		$cache = wp_json_encode( 'cliezoo-' . CLIEZOO_VERSION );
		$build = wp_json_encode( CLIEZOO_URL . 'build/' );
		return "// Cliezoo service worker: static build files only, never data.\n"
			. "const CACHE = {$cache};\n"
			. "const BUILD = {$build};\n"
			. "self.addEventListener('install', function () { self.skipWaiting(); });\n"
			. "self.addEventListener('activate', function (event) { event.waitUntil(caches.keys().then(function (keys) { return Promise.all(keys.filter(function (key) { return key !== CACHE; }).map(function (key) { return caches.delete(key); })); }).then(function () { return self.clients.claim(); })); });\n"
			. "self.addEventListener('fetch', function (event) {\n"
			. "\tvar request = event.request;\n"
			. "\tif (request.method !== 'GET' || request.url.indexOf(BUILD) !== 0) { return; }\n"
			. "\tevent.respondWith(caches.open(CACHE).then(function (cache) { return cache.match(request).then(function (hit) { return hit || fetch(request).then(function (response) { if (response && response.status === 200) { cache.put(request, response.clone()); } return response; }); }); }));\n"
			. "});\n";
	}

	/**
	 * Serves /cz/f/{uid}?e=…&s=… (signed, expiring file links).
	 *
	 * @param string $uid File uid.
	 * @return void
	 */
	private function serve_signed_file( string $uid ): void {
		$expires = isset( $_GET['e'] ) ? (int) $_GET['e'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed URL.
		$sig     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed URL.
		$purpose = isset( $_GET['p'] ) ? sanitize_key( wp_unslash( $_GET['p'] ) ) : 'file'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed URL.
		if ( ! Downloads::verify_signed( $uid, $expires, $sig, $purpose ) ) {
			status_header( 403 );
			exit;
		}
		$row = $this->plugin->service( 'files' )->repository()->find_by_uid( $uid );
		if ( ! $row || 'ready' !== $row['status'] || ! empty( $row['deleted_at'] ) || 'upload' !== $row['kind'] ) {
			status_header( 404 );
			exit;
		}
		// An avatar or a card cover serves images and nothing else, whatever
		// the file row says. Both are put in an <img> by the app, and a link
		// that can be made to hand back a PDF instead is a link worth
		// finding.
		$picture = in_array( $purpose, array( 'avatar', 'cover' ), true );
		if ( $picture && ! Downloads::is_image( (string) $row['mime'] ) ) {
			status_header( 404 );
			exit;
		}
		// A thumbnail link serves that thumbnail and nothing else.
		if ( preg_match( '/^thumb_(small|medium|large)$/', $purpose, $size ) ) {
			$thumbs = (array) json_decode( (string) $row['thumbs'], true );
			$thumb  = $thumbs[ $size[1] ] ?? null;
			if ( ! is_array( $thumb ) || ! Downloads::is_image( (string) ( $thumb['mime'] ?? '' ) ) ) {
				status_header( 404 );
				exit;
			}
			Downloads::output(
				array(
					'driver' => (string) $row['storage_driver'],
					'key'    => (string) $thumb['key'],
					'mime'   => (string) $thumb['mime'],
					'name'   => (string) $row['name'],
					'inline' => true,
					'cache'  => 'private, max-age=3600',
				)
			);
			exit;
		}
		Downloads::output(
			array(
				'driver' => (string) $row['storage_driver'],
				'key'    => (string) $row['object_key'],
				'mime'   => (string) $row['mime'],
				'name'   => (string) $row['name'],
				// A link made for looking shows the file in the page, for the
				// types the server shows at all; anything else downloads.
				'inline' => $picture || 'view' === $purpose,
				'cache'  => 'private, no-store',
			)
		);
		exit;
	}

	/**
	 * Robots: noindex, nofollow on app and token routes.
	 *
	 * @param array<string, bool|string> $robots Robots directives.
	 * @return array<string, bool|string>
	 */
	public function robots( array $robots ): array {
		unset( $robots['index'], $robots['follow'], $robots['max-image-preview'] );
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		return $robots;
	}

	/**
	 * Dequeues every non-Cliezoo script and style (theme isolation) except allowlisted handles.
	 *
	 * @return void
	 */
	public function isolate(): void {
		global $wp_scripts;
		foreach ( (array) ( $wp_scripts->queue ?? array() ) as $handle ) {
			if ( ! $this->allowed( (string) $handle ) ) {
				wp_dequeue_script( $handle );
			}
		}
		$this->isolate_styles();
	}

	/**
	 * Dequeues foreign styles.
	 *
	 * @return void
	 */
	public function isolate_styles(): void {
		global $wp_styles;
		foreach ( (array) ( $wp_styles->queue ?? array() ) as $handle ) {
			if ( ! $this->allowed( (string) $handle ) ) {
				wp_dequeue_style( $handle );
			}
		}
	}

	/**
	 * Whether a handle may stay on app pages.
	 *
	 * @param string $handle Handle.
	 * @return bool
	 */
	private function allowed( string $handle ): bool {
		if ( str_starts_with( $handle, 'cliezoo' ) ) {
			return true;
		}
		/**
		 * Script/style handles allowed on app pages (e.g. a cookie-consent script).
		 *
		 * @param string[] $handles Handles.
		 */
		return in_array( $handle, (array) apply_filters( 'cliezoo_app_allowed_handles', array() ), true );
	}

	/**
	 * Removes theme and core front-end output the app does not use (theme
	 * fonts, emoji, feeds, a second viewport tag). Plugin `wp_head` output stays.
	 *
	 * @return void
	 */
	public function trim_head(): void {
		remove_action( 'wp_head', '_block_template_viewport_meta_tag', 0 );
		remove_action( 'wp_head', 'wp_print_font_faces', 50 );
		remove_action( 'wp_head', 'wp_print_font_faces_from_style_variations', 50 );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'wp_footer', 'print_emoji_detection_script' );
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		remove_action( 'wp_head', 'rel_canonical' );
		remove_action( 'wp_footer', 'the_block_template_skip_link' );
	}

	/**
	 * Body classes: only the shell's own (theme classes would leak theme CSS hooks).
	 *
	 * @param string[] $classes Classes.
	 * @param string[] $extra   Classes passed to body_class().
	 * @return string[]
	 */
	public function body_class( array $classes, array $extra ): array {
		return array_merge( $extra, is_rtl() ? array( 'rtl' ) : array() );
	}

	/**
	 * Adds the CSP nonce to script tags.
	 *
	 * @param array<string, string|bool> $attributes Attributes.
	 * @return array<string, string|bool>
	 */
	public function script_nonce( array $attributes ): array {
		$attributes['nonce'] = self::nonce();
		return $attributes;
	}

	/**
	 * Browser title: the workspace name (no vendor name).
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public function title( array $parts ): array {
		return array( 'title' => (string) $this->plugin->settings()->get( 'workspace', 'name', get_bloginfo( 'name' ) ) );
	}
}
