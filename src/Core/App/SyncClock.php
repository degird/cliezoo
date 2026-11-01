<?php
/**
 * When each kind of record last changed, so a screen can keep up.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\App;

use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A list that only changes when somebody reloads it is not a list two
 * people can work from. The obvious fix — every list polling for itself —
 * is forty requests a minute from one open tab, most of them answering
 * "nothing", and it gets worse with every screen added.
 *
 * So one clock instead. Every write stamps the kind of record it touched;
 * one small request reads the whole clock, and the app refetches only the
 * lists whose kind moved. A tab nobody is looking at asks nothing at all,
 * and a quiet workspace costs one autoloaded option read per poll.
 *
 * The clock says what changed, never what it was: it holds counters, no
 * record data, and answers the same to everybody. What a reader may then
 * see is decided by the list they refetch, as it always was.
 */
final class SyncClock {

	/**
	 * Where the clock is kept. Autoloaded: it is read on every poll.
	 */
	public const OPTION = 'cliezoo_sync_clock';

	/**
	 * How often the app is told to ask again, in seconds.
	 */
	public const EVERY = 20;

	/**
	 * Kinds one clock holds. Past this it is a workspace with more
	 * entities than the app has screens, and the oldest stamps go.
	 */
	private const KINDS = 200;

	/**
	 * Read once per request, written at most once.
	 *
	 * @var array<string, int>|null
	 */
	private static ?array $clock = null;

	/**
	 * The kinds stamped during this request, not yet written.
	 *
	 * @var array<string, bool>
	 */
	private static array $pending = array();

	/**
	 * Listens for every write.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return void
	 */
	public static function hooks( Plugin $plugin ): void {
		unset( $plugin );
		foreach ( array( 'created', 'updated', 'trashed', 'restored', 'archived', 'unarchived', 'purged' ) as $what ) {
			add_action(
				'cliezoo_record_' . $what,
				static function ( $entity ): void {
					if ( $entity instanceof Entity ) {
						self::touch( $entity->name );
					}
				},
				5
			);
		}
		// Written once, at the end, however many records one request wrote:
		// a bulk of two hundred is one option update, not two hundred.
		add_action( 'shutdown', array( self::class, 'flush' ), 1 );
	}

	/**
	 * Stamps a kind of record as having just changed.
	 *
	 * @param string $kind Entity name.
	 * @return void
	 */
	public static function touch( string $kind ): void {
		if ( '' === $kind ) {
			return;
		}
		self::$pending[ $kind ] = true;
	}

	/**
	 * Writes whatever this request stamped.
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( ! self::$pending ) {
			return;
		}
		$clock = self::all();
		$now   = time();
		foreach ( array_keys( self::$pending ) as $kind ) {
			$clock[ $kind ] = $now;
		}
		self::$pending = array();
		if ( count( $clock ) > self::KINDS ) {
			arsort( $clock );
			$clock = array_slice( $clock, 0, self::KINDS, true );
		}
		self::$clock = $clock;
		update_option( self::OPTION, $clock, true );
	}

	/**
	 * The whole clock.
	 *
	 * @return array<string, int>
	 */
	public static function all(): array {
		if ( null === self::$clock ) {
			$kept        = get_option( self::OPTION, array() );
			self::$clock = is_array( $kept ) ? array_map( 'intval', $kept ) : array();
		}
		return self::$clock;
	}

	/**
	 * What to tell a screen that is keeping up.
	 *
	 * @return array{now: int, every: int, changed: array<string, int>}
	 */
	public static function state(): array {
		return array(
			'now'     => time(),
			/**
			 * How long a screen waits before asking again, in seconds.
			 *
			 * @param int $every Seconds.
			 */
			'every'   => max( 5, (int) apply_filters( 'cliezoo_sync_every', self::EVERY ) ),
			'changed' => self::all(),
		);
	}

	/**
	 * Forgets what was read, for a test that writes behind its own back.
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::$clock   = null;
		self::$pending = array();
	}
}
