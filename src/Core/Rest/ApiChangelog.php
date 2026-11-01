<?php
/**
 * The API changelog.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What changed in `cliezoo/v1`, newest first. Kept in code next to the API
 * so a change and its note ship together; `GET /openapi` carries it and the
 * API docs in Settings show it. Only additions go into v1: a change that
 * would break a caller needs a v2.
 */
final class ApiChangelog {

	/**
	 * The entries.
	 *
	 * @return array<int, array{version: string, date: string, changes: string[]}>
	 */
	public static function entries(): array {
		$entries = array(
			array(
				'version' => '0.1.0',
				// Not released yet: the date is set when it is.
				'date'    => '',
				'changes' => array(
					__( 'First version: every module, cursor pagination, filters and sorting, ETag and If-Match, Idempotency-Key, batch calls and this OpenAPI document.', 'cliezoo' ),
					__( 'The expand parameter on record lists and single records includes related records in full.', 'cliezoo' ),
					__( 'Calls made with an application password carry X-RateLimit-Limit, X-RateLimit-Remaining and X-RateLimit-Reset.', 'cliezoo' ),
					__( 'Search finds message text, doc content and custom-field values, with the operators type:, status:, client:, message:, from:, before: and after:.', 'cliezoo' ),
					__( 'The currency custom field type (an amount in minor units, in the currency of the field) and number formats.', 'cliezoo' ),
				),
			),
		);
		/**
		 * The API changelog (add-ons add their own entries).
		 *
		 * @param array $entries Entries, newest first.
		 */
		return (array) apply_filters( 'cliezoo_api_changelog', $entries );
	}
}
