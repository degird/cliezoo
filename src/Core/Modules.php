<?php
/**
 * The list of modules.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instantiates every module in dependency-friendly order.
 */
final class Modules {

	/**
	 * Modules.
	 *
	 * @return Module[]
	 */
	public static function free(): array {
		return array(
			new \Cliezoo\Platform\CoreModule(),
			new \Cliezoo\Platform\Permissions\PermissionsModule(),
			new \Cliezoo\Platform\Activity\ActivityModule(),
			new \Cliezoo\Platform\Records\RecordsModule(),
			new \Cliezoo\Platform\Taxonomies\TaxonomiesModule(),
			new \Cliezoo\Platform\CustomFields\CustomFieldsModule(),
			new \Cliezoo\Platform\Search\SearchModule(),
			new \Cliezoo\Platform\Clients\ClientsModule(),
			new \Cliezoo\Platform\Contacts\ContactsModule(),
			new \Cliezoo\Platform\Files\FilesModule(),
			new \Cliezoo\Platform\Dashboard\DashboardModule(),
			new \Cliezoo\Platform\Leads\LeadsModule(),
			new \Cliezoo\Infrastructure\Mail\MailModule(),
			new \Cliezoo\Platform\Notifications\NotificationsModule(),
			new \Cliezoo\Platform\Reminders\RemindersModule(),
			new \Cliezoo\Infrastructure\Storage\StorageModule(),
			new \Cliezoo\Platform\Auth\AuthModule(),
			new \Cliezoo\Platform\Privacy\PrivacyModule(),
			new \Cliezoo\Platform\Health\HealthModule(),
			new \Cliezoo\Platform\Setup\SetupModule(),
		);
	}
}
