<?php
/**
 * `wp cliezoo` commands.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core;

use Cliezoo\Platform\Health\Health;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What an administrator needs from a terminal: see where this install
 * stands, run the checks, run due jobs on a schedule the host controls,
 * rebuild the search index and run any waiting migration (Blueprint §16.3).
 *
 * Every command runs as the system actor: no permission is skipped, the
 * work is simply not being done on anybody's behalf.
 */
final class Cli {

	/**
	 * Registers the commands.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'cliezoo info', array( self::class, 'info' ) );
		\WP_CLI::add_command( 'cliezoo health', array( self::class, 'health' ) );
		\WP_CLI::add_command( 'cliezoo migrate', array( self::class, 'migrate' ) );
		\WP_CLI::add_command( 'cliezoo run-jobs', array( self::class, 'run_jobs' ) );
		\WP_CLI::add_command( 'cliezoo reindex', array( self::class, 'reindex' ) );
		\WP_CLI::add_command( 'cliezoo export', array( self::class, 'export' ) );
		\WP_CLI::add_command( 'cliezoo rekey', array( self::class, 'rekey' ) );
		\WP_CLI::add_command( 'cliezoo seed', array( self::class, 'seed' ) );
	}

	/**
	 * Encrypts every stored secret again with the current key.
	 *
	 * Secrets (two-step verification keys, the AI key) are encrypted with
	 * CLIEZOO_ENCRYPTION_KEY, or, without it, with a key derived from the
	 * WordPress salts. When that changes — you added the constant, rotated
	 * it, or the salts were regenerated — the secrets must be opened with
	 * the old key and closed with the new one, or they stop working. Give
	 * the old key material; the current one is read from wp-config.php.
	 *
	 * ## OPTIONS
	 *
	 * [--from=<source>]
	 * : Where the old key came from: `salts` (you have just added
	 * CLIEZOO_ENCRYPTION_KEY and the secrets were encrypted with the salts).
	 *
	 * [--old-key=<value>]
	 * : The previous CLIEZOO_ENCRYPTION_KEY value.
	 *
	 * [--old-auth-key=<value>]
	 * : The previous AUTH_KEY (with --old-secure-auth-key), after the salts changed.
	 *
	 * [--old-secure-auth-key=<value>]
	 * : The previous SECURE_AUTH_KEY.
	 *
	 * [--dry-run]
	 * : Count what would change, and change nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cliezoo rekey --from=salts --dry-run
	 *     wp cliezoo rekey --old-key="base64:…"
	 *     wp cliezoo rekey --old-auth-key="…" --old-secure-auth-key="…"
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public static function rekey( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		try {
			$result = self::run_rekey( $assoc_args );
		} catch ( \InvalidArgumentException $error ) {
			\WP_CLI::error( $error->getMessage() );
			return;
		}
		$dry = isset( $assoc_args['dry-run'] );
		\WP_CLI::line( sprintf( '%s %d secret(s); %d already use the current key; %d could not be opened with the old key.', $dry ? 'Would re-key' : 'Re-keyed', $result['rekeyed'], $result['current'], $result['failed'] ) );
		if ( $result['failed'] > 0 ) {
			\WP_CLI::warning( 'Secrets that could not be opened were left as they were. Check the old key material, or enter those secrets again.' );
		}
		\WP_CLI::success( $dry ? 'Nothing was changed (dry run).' : 'Done.' );
	}

	/**
	 * The re-key itself, apart from the terminal (tests call this).
	 *
	 * @param array<string, string> $options from, old-key, old-auth-key, old-secure-auth-key, dry-run.
	 * @return array{rekeyed: int, failed: int, current: int}
	 * @throws \InvalidArgumentException Without old key material.
	 */
	public static function run_rekey( array $options ): array {
		$vault = cliezoo()->get( \Cliezoo\Infrastructure\Crypto\Vault::class );
		if ( 'salts' === ( $options['from'] ?? '' ) ) {
			$old = \Cliezoo\Infrastructure\Crypto\Vault::salts_key();
		} elseif ( isset( $options['old-key'] ) && '' !== $options['old-key'] ) {
			$old = \Cliezoo\Infrastructure\Crypto\Vault::key_from_constant( (string) $options['old-key'] );
		} elseif ( isset( $options['old-auth-key'], $options['old-secure-auth-key'] ) ) {
			$old = \Cliezoo\Infrastructure\Crypto\Vault::key_from_salts( (string) $options['old-auth-key'], (string) $options['old-secure-auth-key'] );
		} else {
			throw new \InvalidArgumentException( 'Say where the old key came from: --from=salts, --old-key=… or --old-auth-key=… --old-secure-auth-key=….' );
		}
		if ( hash_equals( $old, $vault->key() ) ) {
			throw new \InvalidArgumentException( 'The old key is the same as the current one: there is nothing to re-key.' );
		}
		$result = $vault->rekey( $old, isset( $options['dry-run'] ) );
		if ( ! isset( $options['dry-run'] ) ) {
			do_action( 'cliezoo_audit', 'secrets_rekeyed', array( 'rekeyed' => $result['rekeyed'] ) );
		}
		return $result;
	}

	/**
	 * Adds (or removes) example records: clients with contacts, a project
	 * each, a board and tasks with due dates — for trying Cliezoo out or
	 * developing on it. Contacts get example.com addresses, which never
	 * receive mail.
	 *
	 * ## OPTIONS
	 *
	 * [--clients=<number>]
	 * : How many example clients (1–20). Default 3.
	 *
	 * [--remove]
	 * : Remove the example records this command added, and nothing else.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cliezoo seed
	 *     wp cliezoo seed --clients=10
	 *     wp cliezoo seed --remove
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public static function seed( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$remove = isset( $assoc_args['remove'] );
		$result = self::run_seed( $remove, (int) ( $assoc_args['clients'] ?? 3 ) );
		foreach ( $result as $type => $count ) {
			\WP_CLI::line( sprintf( '%s: %d', $type, $count ) );
		}
		\WP_CLI::success( $remove ? 'Example records removed.' : 'Example records added.' );
	}

	/**
	 * The seed itself, apart from the terminal (tests call this).
	 *
	 * @param bool $remove  Remove instead of add.
	 * @param int  $clients How many clients.
	 * @return array<string, int> What was added or removed, per type.
	 */
	public static function run_seed( bool $remove = false, int $clients = 3 ): array {
		$plugin = cliezoo();
		// Records need somebody who made them, so the seed acts as the
		// workspace owner when there is one, and as the system otherwise.
		$owner  = $plugin->service( 'members' )->repository()->first(
			array(
				'role'   => 'owner',
				'status' => 'active',
			)
		);
		$actor  = $owner && ! empty( $owner['user_id'] )
			? $plugin->get( \Cliezoo\Platform\Members\Members::class )->actor_for( (int) $owner['user_id'] )->via( 'cli' )
			: \Cliezoo\Platform\Permissions\Actor::system( 'cli' );
		$seeder = new Seeder( $plugin, $actor );
		return $remove ? $seeder->remove() : $seeder->add( $clients );
	}

	/**
	 * Shows version, database state and active modules.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cliezoo info
	 *
	 * @return void
	 */
	public static function info(): void {
		$plugin = cliezoo();
		\WP_CLI::line( 'Cliezoo ' . CLIEZOO_VERSION );
		\WP_CLI::line( 'Installed: ' . (string) get_option( 'cliezoo_installed_at', 'not yet' ) );
		\WP_CLI::line( 'App address: ' . App\AppRouter::app_url() );
		\WP_CLI::line( 'Modules: ' . implode( ', ', array_keys( $plugin->active_modules() ) ) );
		\WP_CLI::line( 'Entities: ' . count( $plugin->entities()->all() ) );
		\WP_CLI::line( 'Actions: ' . count( $plugin->actions()->all() ) );
		\WP_CLI::line( 'Safe mode: ' . ( $plugin->get( \Cliezoo\Infrastructure\Jobs\SafeMode::class )->active() ? 'on' : 'off' ) );
	}

	/**
	 * Runs the setup health checks and prints the result.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cliezoo health
	 *     wp cliezoo health --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 * @return void
	 */
	public static function health( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$report = cliezoo()->get( Health::class )->refresh();
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $report ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $report['checks'], array( 'status', 'label', 'detail' ) );
		}
		if ( $report['failing'] > 0 ) {
			\WP_CLI::error( sprintf( '%d check(s) failing.', (int) $report['failing'] ) );
		}
		\WP_CLI::success( 'Everything that matters is working.' );
	}

	/**
	 * Runs any waiting database migration.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cliezoo migrate
	 *
	 * @return void
	 */
	public static function migrate(): void {
		cliezoo()->migrate();
		update_option( 'cliezoo_version', CLIEZOO_VERSION );
		\WP_CLI::success( 'Database is up to date.' );
	}

	/**
	 * Runs the work that is due now (reminders, emails, digests), for hosts
	 * that drive WordPress cron from a real cron job.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cliezoo run-jobs
	 *
	 * @return void
	 */
	public static function run_jobs(): void {
		do_action( 'cliezoo_tick' );
		do_action( 'cliezoo_every_hour' );
		\WP_CLI::success( 'Due work has been run.' );
	}

	/**
	 * Rebuilds the search index from scratch.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cliezoo reindex
	 *
	 * @return void
	 */
	public static function reindex(): void {
		$plugin  = cliezoo();
		$indexed = 0;
		foreach ( $plugin->entities()->all() as $name => $entity ) {
			if ( ! $entity->searchable || ! $entity->public ) {
				continue;
			}
			$search = $plugin->get( \Cliezoo\Platform\Search\Search::class );
			$after  = 0;
			$count  = 0;
			// In batches to the end, rather than the first few thousand: an
			// index that quietly stopped would be worse than none.
			while ( true ) {
				$rows = $plugin->service( $name )->repository()->batch( $after, 500 );
				if ( ! $rows ) {
					break;
				}
				foreach ( $rows as $row ) {
					$search->index( $entity, $row );
					$after = (int) $row['id'];
					++$count;
					++$indexed;
				}
			}
			\WP_CLI::line( sprintf( '%s: %d', $name, $count ) );
		}
		\WP_CLI::success( sprintf( 'Reindexed %d record(s).', $indexed ) );
	}

	/**
	 * Writes every row of a record type to CSV.
	 *
	 * The app's own export is built in the browser and stops at 5,000 rows;
	 * this one streams straight from the database, so it has no ceiling.
	 *
	 * ## OPTIONS
	 *
	 * <entity>
	 * : The record type, e.g. clients.
	 *
	 * [--file=<path>]
	 * : Where to write it. Defaults to standard output.
	 *
	 * [--trashed]
	 * : Include records in the trash.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cliezoo export clients --file=clients.csv
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public static function export( array $args = array(), array $assoc_args = array() ): void {
		$plugin = cliezoo();
		$name   = (string) ( $args[0] ?? '' );
		if ( ! $plugin->entities()->has( $name ) ) {
			\WP_CLI::error( sprintf( 'Unknown record type "%s".', $name ) );
			return;
		}
		$entity = $plugin->entities()->get( $name );
		if ( ! $entity->public ) {
			\WP_CLI::error( sprintf( '"%s" is internal and cannot be exported.', $name ) );
			return;
		}
		$path    = (string) ( $assoc_args['file'] ?? '' );
		$trashed = isset( $assoc_args['trashed'] );
		$handle  = '' !== $path ? fopen( $path, 'w' ) : fopen( 'php://stdout', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to disk or the terminal; WP_Filesystem holds the whole file in memory.
		$columns = array_merge( array( 'uid' ), array_keys( $entity->fields ), array( 'created_at', 'updated_at' ) );
		$columns = array_values( array_unique( array_filter( $columns, array( $entity, 'has_column' ) ) ) );
		fputcsv( $handle, $columns, ',', '"', '\\' );

		$after = 0;
		$rows  = 0;
		while ( true ) {
			$batch = $plugin->service( $name )->repository()->batch( $after, 500, $trashed );
			if ( ! $batch ) {
				break;
			}
			foreach ( $batch as $row ) {
				$line = array();
				foreach ( $columns as $column ) {
					$value  = $row[ $column ] ?? '';
					$line[] = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
				}
				fputcsv( $handle, $line, ',', '"', '\\' );
				$after = (int) $row['id'];
				++$rows;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with the fopen above.
		if ( '' !== $path ) {
			\WP_CLI::success( sprintf( 'Wrote %d row(s) to %s.', $rows, $path ) );
		}
	}
}
