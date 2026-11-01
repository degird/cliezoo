<?php
/**
 * Setup health checks.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Health;

use Cliezoo\Core\App\AppRouter;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Storage\LocalDriver;
use Cliezoo\Infrastructure\Crypto\Vault;
use Cliezoo\Infrastructure\Jobs\SafeMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The handful of things that decide whether this install actually works:
 * scheduled jobs running, the REST API reachable, the app address free,
 * a page cache that is not caching the app, an encryption key that will
 * survive, mail going out, and enough room to upload (Blueprint §31.2).
 *
 * Each check answers `ok`, `warn` or `fail` with one sentence a person can
 * act on. The daily job re-runs them and remembers the result, so the
 * screen is instant and problems can raise a notice.
 */
final class Health {

	public const OPTION = 'cliezoo_health';

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
	 * Runs every check.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function run(): array {
		$rest   = $this->rest();
		$checks = array(
			$this->jobs(),
			$rest,
			$this->rich_text( 'ok' === $rest['status'] ),
			$this->address(),
			$this->routes(),
			$this->cache(),
			$this->optimisers(),
			$this->limits(),
			$this->key(),
			$this->mail(),
			$this->uploads(),
			$this->private_files(),
			$this->safe_mode(),
			$this->unique_rules(),
		);
		/**
		 * Filters the health checks (add-ons add their own).
		 *
		 * @param array  $checks Checks.
		 * @param Plugin $plugin Plugin.
		 */
		return (array) apply_filters( 'cliezoo_health_checks', $checks, $this->plugin );
	}

	/**
	 * Runs the checks and remembers the result.
	 *
	 * @return array<string, mixed>
	 */
	public function refresh(): array {
		$checks = $this->run();
		$result = array(
			'checked_at' => gmdate( 'c' ),
			'failing'    => count( array_filter( $checks, static fn( array $c ): bool => 'fail' === $c['status'] ) ),
			'warnings'   => count( array_filter( $checks, static fn( array $c ): bool => 'warn' === $c['status'] ) ),
			'checks'     => array_values( $checks ),
		);
		update_option( self::OPTION, $result, false );
		return $result;
	}

	/**
	 * The last result, running the checks if there is none.
	 *
	 * @return array<string, mixed>
	 */
	public function last(): array {
		$saved = get_option( self::OPTION );
		return is_array( $saved ) && isset( $saved['checks'] ) ? $saved : $this->refresh();
	}

	/**
	 * One check.
	 *
	 * @param string $id     Id.
	 * @param string $label  Label.
	 * @param string $status ok|warn|fail.
	 * @param string $detail One sentence.
	 * @param string $fix    What to do about it.
	 * @return array<string, mixed>
	 */
	private static function check( string $id, string $label, string $status, string $detail, string $fix = '' ): array {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
			'fix'    => $fix,
		);
	}

	/**
	 * Are the rules the database keeps — no two invoices with one number —
	 * actually in force?
	 *
	 * A rule like that is an index, and the database refuses to add it while
	 * two records already break it: nothing says so, the upgrade carries on,
	 * and the rule is simply not there. This names which, and what to do.
	 *
	 * @return array<string, mixed>
	 */
	private function unique_rules(): array {
		global $wpdb;
		$label   = __( 'Numbers that must be unique', 'cliezoo' );
		$missing = array();
		foreach ( $this->plugin->entities()->all() as $entity ) {
			foreach ( $entity->unique as $index => $columns ) {
				$table = esc_sql( $entity->table() );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from the entity registry.
				$found = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", (string) $index ) );
				if ( null !== $found ) {
					continue;
				}
				$names = array_map( 'strval', (array) $columns );
				$list  = implode( ', ', $names );
				$set   = implode( ' AND ', array_map( static fn( string $one ): string => "{$one} IS NOT NULL AND {$one} <> ''", $names ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from the entity registry.
				$twice     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT 1 FROM {$table} WHERE {$set} GROUP BY {$list} HAVING COUNT(*) > 1 ) AS twice" );
				$missing[] = sprintf(
					/* translators: 1: a kind of record, 2: a column, 3: how many values are used twice. */
					__( '%1$s by %2$s (%3$d used more than once)', 'cliezoo' ),
					'' !== $entity->label ? $entity->label : $entity->name,
					$list,
					$twice
				);
			}
		}
		if ( ! $missing ) {
			return self::check( 'unique', $label, 'ok', __( 'Every number that must be unique is kept unique by the database.', 'cliezoo' ) );
		}
		return self::check(
			'unique',
			$label,
			'warn',
			sprintf(
				/* translators: %s: a list of kinds of record and columns. */
				__( 'Not enforced for: %s. Records made before the rule existed share a value, so the database would not add it.', 'cliezoo' ),
				implode( '; ', $missing )
			),
			__( 'Give each of the records that share a number a number of its own, then run this check again: the rule is added on the next update.', 'cliezoo' )
		);
	}

	/**
	 * Are scheduled jobs actually running?
	 *
	 * @return array<string, mixed>
	 */
	private function jobs(): array {
		$label = __( 'Scheduled jobs', 'cliezoo' );
		$last  = (int) get_option( 'cliezoo_heartbeat_daily', 0 );
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ! $last ) {
			return self::check(
				'jobs',
				$label,
				'fail',
				__( 'WordPress cron is switched off and nothing has run yet.', 'cliezoo' ),
				__( 'Add a real cron job on the server that calls wp-cron.php, or turn DISABLE_WP_CRON off.', 'cliezoo' )
			);
		}
		if ( ! $last ) {
			return self::check(
				'jobs',
				$label,
				'warn',
				__( 'Nothing has run yet. This is normal on a new install.', 'cliezoo' ),
				__( 'Check again tomorrow; reminders and emails need this to work.', 'cliezoo' )
			);
		}
		if ( time() - $last > 2 * DAY_IN_SECONDS ) {
			return self::check(
				'jobs',
				$label,
				'fail',
				__( 'Jobs have not run for more than two days.', 'cliezoo' ),
				__( 'Reminders, emails and recurring work are stopped. Check that the site receives traffic or that a server cron calls wp-cron.php.', 'cliezoo' )
			);
		}
		return self::check( 'jobs', $label, 'ok', __( 'Jobs ran within the last day.', 'cliezoo' ) );
	}

	/**
	 * Is our own REST namespace reachable from outside?
	 *
	 * @return array<string, mixed>
	 */
	private function rest(): array {
		$label    = __( 'REST API', 'cliezoo' );
		$response = wp_remote_get(
			rest_url( 'cliezoo/v1/ping' ),
			array(
				'timeout'   => 5,
				// A loopback call to this very site, as core's own loopback test
				// makes: a self-signed certificate must not fail the check.
				'sslverify' => false,
			)
		);
		if ( is_wp_error( $response ) ) {
			// From a terminal the site's own address often does not resolve;
			// that says nothing about whether browsers can reach it.
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return self::check(
					'rest',
					$label,
					'warn',
					__( 'Could not be checked from the command line.', 'cliezoo' ),
					__( 'Open Settings → Setup health in the app to check it properly.', 'cliezoo' )
				);
			}
			return self::check(
				'rest',
				$label,
				'fail',
				sprintf(
					/* translators: %s: error message. */
					__( 'The site cannot reach its own REST API: %s', 'cliezoo' ),
					$response->get_error_message()
				),
				$this->rest_fix( __( 'A security plugin, a firewall or a server rule is usually the cause. The app cannot load until this works.', 'cliezoo' ) )
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return self::check(
				'rest',
				$label,
				'fail',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The REST API answered with %d.', 'cliezoo' ),
					$code
				),
				$this->rest_fix( __( 'Something in front of WordPress is blocking /wp-json/. The app cannot load until this works.', 'cliezoo' ) )
			);
		}
		return self::check( 'rest', $label, 'ok', __( 'The REST API answers normally.', 'cliezoo' ) );
	}

	/**
	 * Security plugins known to switch off or limit the REST API, by the
	 * folder they are installed in, with where their switch is.
	 *
	 * @return array<string, array{name: string, fix: string}>
	 */
	public static function security_plugins(): array {
		return array(
			'wordfence'                           => array(
				'name' => 'Wordfence',
				'fix'  => __( 'Wordfence → Firewall → All Firewall Options: under "Brute Force Protection" turn off "Prevent discovery of usernames through REST API", and check Live Traffic for blocked /wp-json/cliezoo/ requests; allowlist them with "Add Param to Firewall Allowlist".', 'cliezoo' ),
			),
			'better-wp-security'                  => array(
				'name' => 'Solid Security (iThemes)',
				'fix'  => __( 'Security → Settings → Advanced → WordPress Tweaks: set "REST API" to "Default Access".', 'cliezoo' ),
			),
			'ithemes-security-pro'                => array(
				'name' => 'Solid Security Pro',
				'fix'  => __( 'Security → Settings → Advanced → WordPress Tweaks: set "REST API" to "Default Access".', 'cliezoo' ),
			),
			'all-in-one-wp-security-and-firewall' => array(
				'name' => 'All In One WP Security',
				'fix'  => __( 'WP Security → Miscellaneous → WP REST API: untick "Disallow unauthorized REST requests", or add cliezoo to the allowed namespaces.', 'cliezoo' ),
			),
			'wp-cerber'                           => array(
				'name' => 'WP Cerber',
				'fix'  => __( 'WP Cerber → Hardening: in "Access to WordPress REST API" add "cliezoo" to the allowed namespaces.', 'cliezoo' ),
			),
			'wp-simple-firewall'                  => array(
				'name' => 'Shield Security',
				'fix'  => __( 'Shield → Config → Lockdown: turn off "Anonymous REST API", or add "cliezoo" to its exclusions.', 'cliezoo' ),
			),
			'sucuri-scanner'                      => array(
				'name' => 'Sucuri',
				'fix'  => __( 'In the Sucuri firewall dashboard, allowlist the path /wp-json/cliezoo/ (Settings → Allowlist URL Paths).', 'cliezoo' ),
			),
			'disable-json-api'                    => array(
				'name' => 'Disable REST API',
				'fix'  => __( 'Settings → Disable REST API: allow the "cliezoo" routes for everyone, or deactivate the plugin.', 'cliezoo' ),
			),
			'disable-wp-rest-api'                 => array(
				'name' => 'Disable WP REST API',
				'fix'  => __( 'This plugin blocks the whole REST API for visitors and cannot make exceptions; deactivate it so the app and client portal can load.', 'cliezoo' ),
			),
			'perfmatters'                         => array(
				'name' => 'Perfmatters',
				'fix'  => __( 'Perfmatters → General → Disable REST API: set it to "Default (Enabled)".', 'cliezoo' ),
			),
			'clearfy'                             => array(
				'name' => 'Clearfy',
				'fix'  => __( 'Clearfy → Code cleaning: turn off "Disable REST API".', 'cliezoo' ),
			),
			'admin-site-enhancements'             => array(
				'name' => 'Admin and Site Enhancements',
				'fix'  => __( 'Tools → Enhancements → Security: turn off "Disable REST API".', 'cliezoo' ),
			),
			'wp-hide-security-enhancer'           => array(
				'name' => 'WP Hide & Security Enhancer',
				'fix'  => __( 'WP Hide → Rewrite → JSON REST: turn off "Disable JSON REST V2 service" and "Block any JSON REST calls".', 'cliezoo' ),
			),
		);
	}

	/**
	 * Folders of the active plugins (network-active ones too).
	 *
	 * @return string[]
	 */
	public static function active_plugin_slugs(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$slugs = array();
		foreach ( $active as $file ) {
			$slugs[] = strtok( (string) $file, '/' );
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );
	}

	/**
	 * The fix for a blocked REST API, naming the security plugin to look at
	 * when one that is known to do this is active.
	 *
	 * @param string $general What to say when none is.
	 * @return string
	 */
	private function rest_fix( string $general ): string {
		$known = array_intersect_key( self::security_plugins(), array_flip( self::active_plugin_slugs() ) );
		if ( ! $known ) {
			return $general;
		}
		$said = array();
		foreach ( $known as $plugin ) {
			/* translators: 1: plugin name, 2: where its setting is. */
			$said[] = sprintf( __( '%1$s is active: %2$s', 'cliezoo' ), $plugin['name'], $plugin['fix'] );
		}
		return implode( ' ', $said ) . ' ' . __( 'The app cannot load until this works.', 'cliezoo' );
	}

	/**
	 * Does a firewall refuse requests that carry HTML? Rich text (a doc, a
	 * message, a proposal) is sent as HTML, and mod_security or a host's
	 * WAF often answers such a request with 403 while plain ones pass. A
	 * harmless formatted sentence is posted to the ping route, which reads
	 * nothing from it.
	 *
	 * @param bool $reachable Whether the REST API answered at all.
	 * @return array<string, mixed>
	 */
	private function rich_text( bool $reachable ): array {
		$label = __( 'Formatted text', 'cliezoo' );
		if ( ! $reachable ) {
			return self::check( 'rich_text', $label, 'warn', __( 'Not checked, because the REST API is not reachable.', 'cliezoo' ) );
		}
		$response = wp_remote_post(
			rest_url( 'cliezoo/v1/ping' ),
			array(
				'timeout'   => 5,
				// A loopback call to this very site, as core's own loopback test
				// makes: a self-signed certificate must not fail the check.
				'sslverify' => false,
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => (string) wp_json_encode(
					array(
						'probe' => '<p>Hello <strong>there</strong>, see <a href="https://example.com/">this link</a> and <em>this list</em>:</p><ul><li>one</li><li>two</li></ul><blockquote>A quote.</blockquote>',
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::check( 'rich_text', $label, 'warn', __( 'Could not be checked from here.', 'cliezoo' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 403, 406, 412, 501 ), true ) ) {
			return self::check(
				'rich_text',
				$label,
				'fail',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'A firewall refuses requests that contain formatted text (it answered %d), so docs, messages and proposals will not save.', 'cliezoo' ),
					$code
				),
				__( 'Ask your host to allow HTML in POST requests to /wp-json/cliezoo/ (for mod_security, disable the XSS rules 941100–941999 for that path), or allowlist the path in your firewall plugin.', 'cliezoo' )
			);
		}
		if ( 200 !== $code ) {
			return self::check(
				'rich_text',
				$label,
				'warn',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'A test with formatted text answered %d.', 'cliezoo' ),
					$code
				)
			);
		}
		return self::check( 'rich_text', $label, 'ok', __( 'Formatted text gets through to the site.', 'cliezoo' ) );
	}

	/**
	 * Are the PHP limits enough for exports, imports and PDFs?
	 *
	 * @return array<string, mixed>
	 */
	private function limits(): array {
		$label   = __( 'PHP limits', 'cliezoo' );
		$raw     = (string) ini_get( 'memory_limit' );
		$memory  = '-1' === $raw ? PHP_INT_MAX : (int) wp_convert_hr_to_bytes( $raw );
		$time    = (int) ini_get( 'max_execution_time' );
		$problem = array();
		$status  = 'ok';
		if ( $memory < 64 * MB_IN_BYTES ) {
			$status    = 'fail';
			$problem[] = sprintf(
				/* translators: %s: size, e.g. "32 MB". */
				__( 'PHP may use only %s of memory.', 'cliezoo' ),
				size_format( $memory )
			);
		} elseif ( $memory < 128 * MB_IN_BYTES ) {
			$status    = 'warn';
			$problem[] = sprintf(
				/* translators: %s: size, e.g. "96 MB". */
				__( 'PHP may use %s of memory; 128 MB or more is better for exports and PDFs.', 'cliezoo' ),
				size_format( $memory )
			);
		}
		if ( $time > 0 && $time < 30 ) {
			$status    = 'fail' === $status ? 'fail' : 'warn';
			$problem[] = sprintf(
				/* translators: %d: seconds. */
				__( 'A request may run for only %d seconds.', 'cliezoo' ),
				$time
			);
		}
		if ( $problem ) {
			return self::check(
				'limits',
				$label,
				$status,
				implode( ' ', $problem ),
				__( 'Ask your host to raise memory_limit to 128M or more and max_execution_time to 30 seconds or more. Big exports and imports run in the background in small steps, but each step needs room.', 'cliezoo' )
			);
		}
		return self::check(
			'limits',
			$label,
			'ok',
			sprintf(
				/* translators: 1: memory size, 2: seconds or "no limit". */
				__( 'Memory %1$s, time per request %2$s.', 'cliezoo' ),
				PHP_INT_MAX === $memory ? __( 'unlimited', 'cliezoo' ) : size_format( $memory ),
				0 === $time ? __( 'unlimited', 'cliezoo' ) : sprintf(
					/* translators: %d: seconds. */
					__( '%d s', 'cliezoo' ),
					$time
				)
			)
		);
	}

	/**
	 * Optimisation plugins that combine, minify or delay scripts, by
	 * folder, and whether Cliezoo tells them to leave its files alone
	 * ({@see OptimiserExclusions}).
	 *
	 * @return array<string, array{name: string, excluded: bool, fix: string}>
	 */
	public static function optimiser_plugins(): array {
		return array(
			'wp-rocket'               => array(
				'name'     => 'WP Rocket',
				'excluded' => true,
				'fix'      => '',
			),
			'litespeed-cache'         => array(
				'name'     => 'LiteSpeed Cache',
				'excluded' => true,
				'fix'      => '',
			),
			'autoptimize'             => array(
				'name'     => 'Autoptimize',
				'excluded' => true,
				'fix'      => '',
			),
			'sg-cachepress'           => array(
				'name'     => 'SiteGround Speed Optimizer',
				'excluded' => true,
				'fix'      => '',
			),
			'hummingbird-performance' => array(
				'name'     => 'Hummingbird',
				'excluded' => true,
				'fix'      => '',
			),
			'w3-total-cache'          => array(
				'name'     => 'W3 Total Cache',
				'excluded' => true,
				'fix'      => '',
			),
			'wp-optimize'             => array(
				'name'     => 'WP-Optimize',
				'excluded' => false,
				'fix'      => __( 'WP-Optimize → Minify → JavaScript and CSS: add "/wp-content/plugins/cliezoo/" to the files to exclude.', 'cliezoo' ),
			),
			'perfmatters'             => array(
				'name'     => 'Perfmatters',
				'excluded' => false,
				'fix'      => __( 'Perfmatters → Assets → JavaScript: add "cliezoo" to "Delay JavaScript" exclusions.', 'cliezoo' ),
			),
			'flying-press'            => array(
				'name'     => 'FlyingPress',
				'excluded' => false,
				'fix'      => __( 'FlyingPress → JavaScript: add "cliezoo" to the exclusions for delay and defer.', 'cliezoo' ),
			),
			'nitropack'               => array(
				'name'     => 'NitroPack',
				'excluded' => false,
				'fix'      => __( 'NitroPack dashboard → Cache → Excludes: exclude the app and portal addresses, and "cliezoo" scripts from optimisation.', 'cliezoo' ),
			),
			'jetpack-boost'           => array(
				'name'     => 'Jetpack Boost',
				'excluded' => false,
				'fix'      => __( 'Jetpack Boost → Concatenate JS and CSS: add the handles starting with "cliezoo" to the exclusions.', 'cliezoo' ),
			),
		);
	}

	/**
	 * Is an optimisation plugin combining or delaying the app's scripts?
	 * Known ones are told to leave Cliezoo's files alone; others are named
	 * with where to exclude them.
	 *
	 * @return array<string, mixed>
	 */
	private function optimisers(): array {
		$label  = __( 'Script optimisers', 'cliezoo' );
		$active = array_intersect_key( self::optimiser_plugins(), array_flip( self::active_plugin_slugs() ) );
		if ( ! $active ) {
			return self::check( 'optimisers', $label, 'ok', __( 'No plugin that combines or delays scripts is active.', 'cliezoo' ) );
		}
		$handled = array_filter( $active, static fn( array $one ): bool => $one['excluded'] );
		$manual  = array_filter( $active, static fn( array $one ): bool => ! $one['excluded'] );
		if ( $manual ) {
			return self::check(
				'optimisers',
				$label,
				'warn',
				sprintf(
					/* translators: %s: plugin names. */
					__( 'Active and not told to leave Cliezoo alone: %s. Combined or delayed scripts can stop the app from loading.', 'cliezoo' ),
					implode( ', ', array_column( $manual, 'name' ) )
				),
				implode( ' ', array_column( $manual, 'fix' ) )
			);
		}
		return self::check(
			'optimisers',
			$label,
			'ok',
			sprintf(
				/* translators: %s: plugin names. */
				__( 'Cliezoo\'s scripts are excluded from %s automatically.', 'cliezoo' ),
				implode( ', ', array_column( $handled, 'name' ) )
			)
		);
	}

	/**
	 * Is the app address free and usable?
	 *
	 * @return array<string, mixed>
	 */
	private function address(): array {
		$label = __( 'App address', 'cliezoo' );
		$bases = AppRouter::bases();
		if ( ! AppRouter::pretty() ) {
			return self::check(
				'address',
				$label,
				'warn',
				__( 'Plain permalinks are on, so the app uses a query address.', 'cliezoo' ),
				__( 'Settings → Permalinks → any option other than Plain gives you tidy addresses.', 'cliezoo' )
			);
		}
		$problems = array_merge( AppRouter::conflicts( $bases['app'], 'app' ), AppRouter::conflicts( $bases['public'], 'public' ) );
		if ( $problems ) {
			// Said with an address that is free today, rather than leaving
			// somebody to guess one and meet the same clash again.
			$free = '';
			foreach ( array( 'app', 'studio', 'work', 'hq', 'office', 'clients' ) as $maybe ) {
				if ( $maybe !== $bases['app'] && $maybe !== $bases['public'] && ! AppRouter::conflicts( $maybe, 'app' ) ) {
					$free = $maybe;
					break;
				}
			}
			return self::check(
				'address',
				$label,
				'fail',
				implode( ' ', $problems ),
				'' !== $free
					? sprintf(
						/* translators: %s: an address that is free, like /studio. */
						__( 'Change the addresses in Settings → Site and data — /%s is free.', 'cliezoo' ),
						$free
					)
					: __( 'Change the addresses in Settings → Site and data.', 'cliezoo' )
			);
		}
		return self::check(
			'address',
			$label,
			'ok',
			sprintf(
				/* translators: %s: app URL. */
				__( 'The app is at %s.', 'cliezoo' ),
				AppRouter::app_url()
			)
		);
	}

	/**
	 * Does the app's own address actually answer?
	 *
	 * The app lives on a rewritten path, and a server that does not pass
	 * unknown paths to WordPress — nginx without `try_files`, most often —
	 * returns its own 404 and the app never loads. WordPress itself cannot
	 * tell; asking the address is the only way to know.
	 *
	 * @return array<string, mixed>
	 */
	private function routes(): array {
		$label  = __( 'App route', 'cliezoo' );
		$server = strtolower( (string) ( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '' ) );
		$hint   = str_contains( $server, 'nginx' )
			? __( 'On nginx the site block needs "try_files $uri $uri/ /index.php?$args", which is what lets WordPress see the app address at all.', 'cliezoo' )
			: __( 'The server has to pass unknown paths to WordPress. On Apache that is the WordPress .htaccess rules; on other servers it is the equivalent rewrite.', 'cliezoo' );

		$response = wp_remote_get(
			AppRouter::app_url(),
			array(
				'timeout'     => 5,
				// A loopback call to this very site, as core's own loopback test
				// makes: a self-signed certificate must not fail the check.
				'sslverify'   => false,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::check(
				'routes',
				$label,
				'warn',
				__( 'Could not be checked from here.', 'cliezoo' ),
				__( 'Open the app address in a browser: if the server shows its own "not found" page, the rewrite is missing.', 'cliezoo' )
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code || 403 === $code ) {
			return self::check( 'routes', $label, 'fail', __( 'The server does not hand the app address to WordPress.', 'cliezoo' ), $hint );
		}
		if ( $code >= 500 ) {
			return self::check(
				'routes',
				$label,
				'fail',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The app address answered with %d.', 'cliezoo' ),
					$code
				),
				__( 'Something on the site is failing before the app renders. The PHP error log usually says what.', 'cliezoo' )
			);
		}
		return self::check( 'routes', $label, 'ok', __( 'The app address answers.', 'cliezoo' ) );
	}

	/**
	 * Is a page cache caching the app?
	 *
	 * @return array<string, mixed>
	 */
	private function cache(): array {
		$label = __( 'Page cache', 'cliezoo' );
		// Asked twice: a cache often stores the first answer and serves it
		// to the second, and only the second shows it. Each real render
		// carries a fresh script nonce, so two identical ones are one page
		// served twice.
		$args   = array(
			'timeout'   => 5,
			// A loopback call to this very site, as core's own loopback test
			// makes: a self-signed certificate must not fail the check.
			'sslverify' => false,
		);
		$first  = wp_remote_get( AppRouter::app_url(), $args );
		$second = is_wp_error( $first ) ? $first : wp_remote_get( AppRouter::app_url(), $args );
		if ( is_wp_error( $first ) || is_wp_error( $second ) ) {
			return self::check( 'cache', $label, 'warn', __( 'The app address could not be checked from here.', 'cliezoo' ) );
		}
		$fix    = __( 'Exclude the app and public addresses from your page cache and CDN.', 'cliezoo' );
		$failed = self::check(
			'cache',
			$label,
			'fail',
			__( 'Something is caching the app, so people can see each other\'s pages.', 'cliezoo' ),
			$fix
		);
		foreach ( array( $first, $second ) as $response ) {
			$headers = wp_remote_retrieve_headers( $response );
			$control = strtolower( (string) ( $headers['cache-control'] ?? '' ) );
			$hit     = strtolower( (string) ( $headers['x-cache'] ?? $headers['cf-cache-status'] ?? $headers['x-litespeed-cache'] ?? $headers['x-proxy-cache'] ?? $headers['x-sucuri-cache'] ?? '' ) );
			$age     = (int) ( $headers['age'] ?? 0 );
			if ( str_contains( $hit, 'hit' ) || $age > 0 || ( '' !== $control && ! str_contains( $control, 'no-store' ) && ! str_contains( $control, 'no-cache' ) && ! str_contains( $control, 'private' ) ) ) {
				return $failed;
			}
		}
		$once  = self::render_nonce( $first );
		$twice = self::render_nonce( $second );
		if ( '' !== $once && $once === $twice ) {
			return self::check(
				'cache',
				$label,
				'fail',
				__( 'The app address answered twice with the very same page, so something is storing it and people can see each other\'s pages.', 'cliezoo' ),
				$fix
			);
		}
		return self::check( 'cache', $label, 'ok', __( 'The app is not being cached.', 'cliezoo' ) );
	}

	/**
	 * The script nonce a rendered app page carries (from its CSP header or,
	 * when a proxy dropped that, from the page), or '' when there is none.
	 *
	 * @param array<string, mixed>|\WP_Error $response Response.
	 * @return string
	 */
	private static function render_nonce( $response ): string {
		$csp = (string) ( wp_remote_retrieve_headers( $response )['content-security-policy'] ?? '' );
		if ( preg_match( "/'nonce-([A-Za-z0-9+\/=_-]+)'/", $csp, $match ) ) {
			return $match[1];
		}
		if ( preg_match( '/\snonce="([A-Za-z0-9+\/=_-]+)"/', (string) wp_remote_retrieve_body( $response ), $match ) ) {
			return $match[1];
		}
		return '';
	}

	/**
	 * Will the encryption key survive?
	 *
	 * @return array<string, mixed>
	 */
	private function key(): array {
		$label = __( 'Encryption key', 'cliezoo' );
		if ( 'constant' === Vault::key_source() ) {
			return self::check( 'key', $label, 'ok', __( 'The key comes from CLIEZOO_ENCRYPTION_KEY.', 'cliezoo' ) );
		}
		return self::check(
			'key',
			$label,
			'warn',
			__( 'The key is derived from the WordPress salts.', 'cliezoo' ),
			__( 'Add CLIEZOO_ENCRYPTION_KEY to wp-config.php. Otherwise, changing the salts makes stored secrets unreadable.', 'cliezoo' )
		);
	}

	/**
	 * Can this site send email at all?
	 *
	 * @return array<string, mixed>
	 */
	private function mail(): array {
		global $wpdb;
		$label  = __( 'Email', 'cliezoo' );
		$table  = $this->plugin->entities()->get( 'email_log' )->table();
		$failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND created_at > %s", gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table from code.
		if ( $failed > 0 ) {
			return self::check(
				'mail',
				$label,
				'fail',
				sprintf(
					/* translators: %d: number of failures. */
					_n( '%d email could not be sent recently.', '%d emails could not be sent recently.', $failed, 'cliezoo' ),
					$failed
				),
				__( 'Set up an SMTP plugin or a sending service so invitations and reminders arrive.', 'cliezoo' )
			);
		}
		if ( ! has_action( 'phpmailer_init' ) && ! defined( 'WPMS_ON' ) ) {
			return self::check(
				'mail',
				$label,
				'warn',
				__( 'Mail goes out through the server\'s own mail program.', 'cliezoo' ),
				__( 'Most of those messages end up in spam. An SMTP plugin or a sending service is more reliable.', 'cliezoo' )
			);
		}
		return self::check( 'mail', $label, 'ok', __( 'Email is going out through a configured sender.', 'cliezoo' ) );
	}

	/**
	 * Is there room to upload anything useful?
	 *
	 * @return array<string, mixed>
	 */
	private function uploads(): array {
		$label = __( 'Uploads', 'cliezoo' );
		$limit = wp_max_upload_size();
		if ( $limit < 2 * MB_IN_BYTES ) {
			return self::check(
				'uploads',
				$label,
				'fail',
				sprintf(
					/* translators: %s: size, e.g. "1 MB". */
					__( 'The server accepts uploads of at most %s.', 'cliezoo' ),
					size_format( $limit )
				),
				__( 'Ask your host to raise upload_max_filesize and post_max_size. Cliezoo uploads in chunks, but each chunk needs room.', 'cliezoo' )
			);
		}
		return self::check(
			'uploads',
			$label,
			'ok',
			sprintf(
				/* translators: %s: size, e.g. "64 MB". */
				__( 'Files up to %s per chunk.', 'cliezoo' ),
				size_format( $limit )
			)
		);
	}

	/**
	 * Are private files private? They are kept in a folder with deny rules
	 * for Apache and IIS, which nginx and some other servers ignore; if
	 * that folder is inside the site, the site is asked for a test file in
	 * it, the way anybody on the internet could ask.
	 *
	 * @return array<string, mixed>
	 */
	private function private_files(): array {
		$label = __( 'Private files', 'cliezoo' );
		$dir   = LocalDriver::base();
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return self::check(
				'private_files',
				$label,
				'fail',
				__( 'The folder for private files cannot be written to, so nothing can be uploaded.', 'cliezoo' ),
				__( 'Make the folder writable by the web server, or set CLIEZOO_PRIVATE_DIR in wp-config.php to one that is.', 'cliezoo' )
			);
		}
		// Only a folder under the uploads folder has an address to try; one
		// set elsewhere (CLIEZOO_PRIVATE_DIR) is the host's to keep out of
		// reach, and usually is outside the site entirely.
		$uploads = wp_upload_dir( null, false );
		$base    = wp_normalize_path( untrailingslashit( (string) $uploads['basedir'] ) );
		$here    = wp_normalize_path( $dir );
		if ( ! str_starts_with( $here, $base . '/' ) ) {
			return self::check( 'private_files', $label, 'ok', __( 'Private files are kept outside the uploads folder.', 'cliezoo' ) );
		}
		$name  = 'cliezoo-probe-' . wp_generate_password( 12, false ) . '.txt';
		$token = wp_generate_password( 24, false );
		$path  = $dir . '/' . $name;
		if ( false === file_put_contents( $path, $token ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A probe file, removed straight after.
			return self::check( 'private_files', $label, 'fail', __( 'A file could not be written to the private folder.', 'cliezoo' ), __( 'Make the folder writable by the web server.', 'cliezoo' ) );
		}
		$folder   = untrailingslashit( (string) $uploads['baseurl'] ) . substr( $here, strlen( $base ) );
		$url      = $folder . '/' . $name;
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				// A loopback call to this very site, as core's own loopback test
				// makes: a self-signed certificate must not fail the check.
				'sslverify'   => false,
				'redirection' => 0,
			)
		);
		wp_delete_file( $path );
		if ( is_wp_error( $response ) ) {
			return self::check(
				'private_files',
				$label,
				'warn',
				__( 'Could not ask the site for a test file, so it is not known whether private files can be downloaded directly.', 'cliezoo' ),
				__( 'Open Settings → Setup health in the app to check again.', 'cliezoo' )
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code && str_contains( (string) wp_remote_retrieve_body( $response ), $token ) ) {
			return self::check(
				'private_files',
				$label,
				'fail',
				__( 'Private files can be downloaded by anybody who knows their address: the server ignores the folder’s deny rules (nginx does).', 'cliezoo' ),
				sprintf(
					/* translators: %s: the folder's address on the site, e.g. /wp-content/uploads/cliezoo-private. */
					__( 'Deny access to %s in the server’s configuration, or set CLIEZOO_PRIVATE_DIR in wp-config.php to a folder outside the site.', 'cliezoo' ),
					(string) wp_parse_url( $folder, PHP_URL_PATH )
				)
			);
		}
		return self::check( 'private_files', $label, 'ok', __( 'Private files cannot be downloaded directly.', 'cliezoo' ) );
	}

	/**
	 * Is Safe mode on, and why?
	 *
	 * @return array<string, mixed>
	 */
	private function safe_mode(): array {
		$label  = __( 'Safe mode', 'cliezoo' );
		$status = $this->plugin->get( SafeMode::class )->status();
		if ( empty( $status['active'] ) ) {
			return self::check( 'safe_mode', $label, 'ok', __( 'Off: emails, webhooks and payments run normally.', 'cliezoo' ) );
		}
		$detail = SafeMode::MOVED === $status['reason']
			? __( 'On, because the site address changed. This looks like a copy or a staging site, so nothing is being sent out.', 'cliezoo' )
			: __( 'On, so nothing is being sent out.', 'cliezoo' );
		return self::check(
			'safe_mode',
			$label,
			'warn',
			$detail,
			__( 'Turn it off in Settings → Site and data once you are sure this install should contact the outside world.', 'cliezoo' )
		);
	}
}
