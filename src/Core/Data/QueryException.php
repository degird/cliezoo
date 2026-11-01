<?php
/**
 * Invalid list query.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raised for bad filters, sorts or cursors. Messages are safe to show.
 */
final class QueryException extends \InvalidArgumentException {
}
