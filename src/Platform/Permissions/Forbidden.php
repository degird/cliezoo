<?php
/**
 * Permission denied.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raised when the Policy denies an operation. Maps to HTTP 403 (or 404 for
 * records the actor must not know exist).
 */
final class Forbidden extends \RuntimeException {
}
