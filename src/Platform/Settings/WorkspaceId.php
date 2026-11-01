<?php
/**
 * The workspace's own ID.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Settings;

use Cliezoo\Core\Data\Ulid;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A fixed identifier for this workspace, shown under Settings → General
 * (cliezoo.md §6.38) so support and integrations can tell one workspace
 * from another. It is made the first time anybody asks and never changes;
 * the site address can, which is why the address is not the ID.
 */
final class WorkspaceId {

	private const OPTION = 'cliezoo_workspace_id';

	/**
	 * The ID, made on first use.
	 *
	 * @return string
	 */
	public static function get(): string {
		$id = get_option( self::OPTION );
		if ( is_string( $id ) && Ulid::valid( $id ) ) {
			return $id;
		}
		$fresh = Ulid::generate();
		if ( false === $id ) {
			// add_option only writes when nothing is there, so two requests
			// racing to make the first ID end up agreeing on one of them.
			add_option( self::OPTION, $fresh, '', true );
			return (string) get_option( self::OPTION, $fresh );
		}
		update_option( self::OPTION, $fresh, true );
		return $fresh;
	}
}
