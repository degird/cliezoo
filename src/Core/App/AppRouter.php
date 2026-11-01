<?php
/**
 * App and public routes (/dash, /cz).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\App;

use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the app base (default `/dash`, team and clients) and the public
 * base (default `/cz`, token pages), serves the isolated shell templates and
 * handles plain permalinks with `?cliezoo=/dash/...` (Blueprint §11.2–§11.3,
 * §31.4). The bases live in one small autoloaded option.
 */
final class AppRouter {

	public const OPTION = 'cliezoo_routes';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Which shell this request renders: '', 'app' or 'public'.
	 *
	 * @var string
	 */
	private string $context = '';

	/**
	 * Path inside the base (without leading slash).
	 *
	 * @var string
	 */
	private string $path = '';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Current bases.
	 *
	 * @return array{app: string, public: string}
	 */
	public static function bases(): array {
		$routes = get_option( self::OPTION, array() );
		$routes = is_array( $routes ) ? $routes : array();
		return array(
			'app'    => (string) ( $routes['app'] ?? 'dash' ),
			'public' => (string) ( $routes['public'] ?? 'cz' ),
		);
	}

	/**
	 * Whether pretty permalinks are on.
	 *
	 * @return bool
	 */
	public static function pretty(): bool {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	/**
	 * Absolute app URL (with trailing slash), honouring plain permalinks.
	 *
	 * @param string $path Path inside the app.
	 * @return string
	 */
	public static function app_url( string $path = '', string $for = '' ): string {
		// On the studio's own domain the app is the whole site, so it
		// has no base at all. A link made for a client goes there even
		// when it is made from a request that arrived elsewhere: that
		// is what putting the portal on a domain of its own is for.
		if ( PortalDomain::on_it() || ( 'client' === $for && '' !== PortalDomain::host() ) ) {
			return PortalDomain::url( $path );
		}
		$base = self::bases()['app'];
		if ( ! self::pretty() ) {
			return add_query_arg( 'cliezoo', '/' . $base . '/' . ltrim( $path, '/' ), home_url( '/' ) );
		}
		return home_url( '/' . $base . '/' . ltrim( $path, '/' ) );
	}

	/**
	 * Absolute public URL.
	 *
	 * @param string $path Path inside the public base.
	 * @return string
	 */
	public static function public_url( string $path = '' ): string {
		$base = self::bases()['public'];
		if ( ! self::pretty() ) {
			return add_query_arg( 'cliezoo', '/' . $base . '/' . ltrim( $path, '/' ), home_url( '/' ) );
		}
		return home_url( '/' . $base . '/' . ltrim( $path, '/' ) );
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( PortalDomain::class, 'answer_check' ), 1 );
		add_action( 'init', array( $this, 'rewrites' ), 5 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'detect' ), 0 );
		add_filter( 'template_include', array( $this, 'template' ), 9999 );
		add_filter( 'cliezoo_app_url', static fn( $url, string $for = '' ): string => self::app_url( '', $for ), 10, 2 );
		add_filter( 'cliezoo_public_url', static fn(): string => self::public_url() );
		add_filter( 'redirect_canonical', array( $this, 'no_canonical_redirect' ) );
		add_action( 'init', array( $this, 'maybe_flush' ), 99 );
	}

	/**
	 * Rewrite rules for both bases.
	 *
	 * @return void
	 */
	public function rewrites(): void {
		$bases = self::bases();
		add_rewrite_rule( '^' . preg_quote( $bases['app'], '/' ) . '(?:/(.*))?/?$', 'index.php?cliezoo_app=1&cliezoo_path=$matches[1]', 'top' );
		add_rewrite_rule( '^' . preg_quote( $bases['public'], '/' ) . '/(.+?)/?$', 'index.php?cliezoo_public=1&cliezoo_path=$matches[1]', 'top' );
	}

	/**
	 * Flushes rules once after activation or a base change, or when another
	 * plugin's flush dropped ours (a cheap check of the autoloaded rules; never
	 * a flush per request).
	 *
	 * @return void
	 */
	public function maybe_flush(): void {
		$flush = (bool) get_transient( 'cliezoo_flush_rewrites' );
		if ( ! $flush && self::pretty() ) {
			$rules = get_option( 'rewrite_rules' );
			$flush = is_array( $rules ) && ! isset( $rules[ '^' . preg_quote( self::bases()['app'], '/' ) . '(?:/(.*))?/?$' ] );
		}
		if ( $flush ) {
			delete_transient( 'cliezoo_flush_rewrites' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Query vars.
	 *
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public function query_vars( array $vars ): array {
		return array_merge( $vars, array( 'cliezoo_app', 'cliezoo_public', 'cliezoo_path', 'cliezoo' ) );
	}

	/**
	 * Detects app/public requests (pretty permalinks or the ?cliezoo= fallback).
	 *
	 * @param \WP $wp WordPress request.
	 * @return void
	 */
	public function detect( \WP $wp ): void {
		$bases = self::bases();
		// The portal domain has no base: the app starts at its root.
		// REST is left alone — the API answers on every host the site
		// does, and the app running on this one needs it.
		if ( PortalDomain::on_it() && empty( $wp->query_vars['rest_route'] ) ) {
			$asked  = trim( (string) $wp->request, '/' );
			// Token pages keep their own base there as well: a signing
			// link is not part of the app and must not be handed to it.
			$public = $asked === $bases['public'] || str_starts_with( $asked . '/', $bases['public'] . '/' );
			$this->context = $public ? 'public' : 'app';
			$this->path    = $public
				? ltrim( substr( $asked, strlen( $bases['public'] ) ), '/' )
				// A link to /dash on the portal domain is the same app,
				// so the base it does not need there is dropped.
				: ltrim( preg_replace( '#^' . preg_quote( $bases['app'], '#' ) . '(?:/|$)#', '', $asked ) ?? $asked, '/' );
		} elseif ( ! empty( $wp->query_vars['cliezoo_app'] ) ) {
			$this->context = 'app';
			$this->path    = (string) ( $wp->query_vars['cliezoo_path'] ?? '' );
		} elseif ( ! empty( $wp->query_vars['cliezoo_public'] ) ) {
			$this->context = 'public';
			$this->path    = (string) ( $wp->query_vars['cliezoo_path'] ?? '' );
		} elseif ( isset( $_GET['cliezoo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter.
			$route = '/' . trim( sanitize_text_field( wp_unslash( $_GET['cliezoo'] ) ), '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter.
			if ( str_starts_with( $route . '/', '/' . $bases['app'] . '/' ) ) {
				$this->context = 'app';
				$this->path    = ltrim( substr( $route, strlen( $bases['app'] ) + 1 ), '/' );
			} elseif ( str_starts_with( $route . '/', '/' . $bases['public'] . '/' ) ) {
				$this->context = 'public';
				$this->path    = ltrim( substr( $route, strlen( $bases['public'] ) + 1 ), '/' );
			}
		}
		if ( '' === $this->context ) {
			return;
		}
		$this->path = trim( (string) preg_replace( '#[^A-Za-z0-9/_\-.@]#', '', $this->path ), '/' );
		// Nothing below is a WordPress query: avoid a 404 from the main query.
		$wp->query_vars = array_merge(
			$wp->query_vars,
			array(
				'cliezoo_app'    => 'app' === $this->context ? 1 : 0,
				'cliezoo_public' => 'public' === $this->context ? 1 : 0,
			)
		);
		( new Shell( $this->plugin ) )->prepare( $this->context, $this->path );
	}

	/**
	 * Current context ('', 'app', 'public').
	 *
	 * @return string
	 */
	public function context(): string {
		return $this->context;
	}

	/**
	 * Uses the isolated shell template on app/public routes.
	 *
	 * @param string $template Theme template.
	 * @return string
	 */
	public function template( string $template ): string {
		if ( '' === $this->context ) {
			return $template;
		}
		global $wp_query;
		$wp_query->is_404 = false;
		status_header( 200 );
		return CLIEZOO_PATH . 'templates/' . ( 'app' === $this->context ? 'app-shell.php' : 'public-shell.php' );
	}

	/**
	 * Stops WordPress from "correcting" app URLs.
	 *
	 * @param string|false $redirect Redirect.
	 * @return string|false
	 */
	public function no_canonical_redirect( $redirect ) {
		return '' !== $this->context ? false : $redirect;
	}

	/**
	 * Why a base cannot be used (empty when free).
	 *
	 * @param string $base Candidate base ("dash").
	 * @param string $kind app|public.
	 * @return string[] Problems.
	 */
	public static function conflicts( string $base, string $kind = 'app' ): array {
		$problems = array();
		$base     = trim( strtolower( $base ), '/' );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9\-]{1,39}$/', $base ) ) {
			$problems[] = __( 'Use 2–40 lower-case letters, numbers or dashes.', 'cliezoo' );
			return $problems;
		}
		$reserved = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'comments', 'search', 'page', 'author', 'tag', 'category', 'embed', 'trackback', 'attachment', 'login', 'admin', 'dashboard', 'sitemap', 'robots' );
		if ( in_array( $base, $reserved, true ) ) {
			$problems[] = __( 'This address is reserved by WordPress.', 'cliezoo' );
		}
		$other = self::bases()[ 'app' === $kind ? 'public' : 'app' ];
		if ( $base === $other ) {
			$problems[] = __( 'The app and public addresses must be different.', 'cliezoo' );
		}
		$page = get_page_by_path( $base, OBJECT, array( 'page', 'post' ) );
		if ( $page ) {
			/* translators: %s: page title. */
			$problems[] = sprintf( __( 'A page or post already uses this address ("%s").', 'cliezoo' ), get_the_title( $page ) );
		}
		if ( term_exists( $base ) ) {
			$problems[] = __( 'A category or tag already uses this address.', 'cliezoo' );
		}
		global $wp_rewrite;
		$bases = self::bases();
		foreach ( (array) $wp_rewrite->wp_rewrite_rules() as $pattern => $query ) {
			if ( str_contains( (string) $query, 'cliezoo_' ) || str_starts_with( (string) $pattern, '^' . preg_quote( $bases[ $kind ], '/' ) ) ) {
				continue;
			}
			if ( @preg_match( '#' . $pattern . '#', $base ) || @preg_match( '#' . $pattern . '#', $base . '/x' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Third-party patterns may be invalid PCRE.
				if ( ! str_contains( (string) $query, 'pagename=' ) && ! str_contains( (string) $query, 'name=' ) && ! str_contains( (string) $query, 'attachment=' ) ) {
					/* translators: %s: rewrite query. */
					$problems[] = sprintf( __( 'Another plugin handles this address (%s).', 'cliezoo' ), mb_substr( (string) $query, 0, 60 ) );
					break;
				}
			}
		}
		return $problems;
	}

	/**
	 * Changes a base after a conflict check and flushes rewrites once.
	 *
	 * @param string $kind app|public.
	 * @param string $base New base.
	 * @return string[] Problems (empty on success).
	 */
	public static function change_base( string $kind, string $base ): array {
		$base     = trim( strtolower( $base ), '/' );
		$problems = self::conflicts( $base, $kind );
		if ( $problems ) {
			return $problems;
		}
		$routes          = self::bases();
		$routes[ $kind ] = $base;
		update_option( self::OPTION, $routes, true );
		set_transient( 'cliezoo_flush_rewrites', 1, HOUR_IN_SECONDS );
		return array();
	}
}
