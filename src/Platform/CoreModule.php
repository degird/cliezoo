<?php
/**
 * Platform core module: people, access, numbering, lists and shared records.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform;

use Cliezoo\Core\App\AppRouter;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Field;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Jobs\Jobs;
use Cliezoo\Infrastructure\Jobs\SafeMode;
use Cliezoo\Platform\Members\Members;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Numbering\Numbering;
use Cliezoo\Platform\Settings\Settings;
use Cliezoo\Platform\Settings\TemplateIndex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Always-on platform tables and services.
 */
final class CoreModule extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'core';
	}

	/**
	 * {@inheritDoc}
	 */
	public function switchable(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function entities(): array {
		$object_ref = array(
			'object_type' => Field::text( 40 )->required()->filterable(),
			'object_id'   => Field::int()->required()->filterable(),
		);
		return array(
			( new Entity(
				'members',
				array(
					'user_id'          => Field::int()->hidden(),
					'kind'             => Field::enum( array( 'staff', 'client' ) )->default( 'staff' )->readonly()->filterable(),
					'role'             => Field::text( 40 )->default( 'member' )->filterable(),
					'status'           => Field::enum( array( 'active', 'invited', 'deactivated' ) )->default( 'active' )->indexed(),
					'name'             => Field::text()->required()->searchable()->sortable(),
					'email'            => Field::email()->searchable()->readonly(),
					'handle'           => Field::text( 60 )->searchable(),
					'title'            => Field::text(),
					'phone'            => Field::phone(),
					'avatar_file_id'   => Field::ref( 'files' ),
					'color'            => Field::color(),
					'timezone'         => Field::text( 60 ),
					'locale'           => Field::text( 20 ),
					'date_format'      => Field::text( 20 ),
					'time_format'      => Field::enum( array( '12', '24' ) ),
					'week_start'       => Field::int()->range( 0, 6 ),
					'bill_rate'        => Field::money()->finance()->in( 'rate_currency' ),
					'rate_currency'    => Field::currency(),
					'capacity_minutes' => Field::int()->range( 0, 10080 ),
					'settings'         => Field::json(),
					'last_login_at'    => Field::datetime()->readonly()->sortable(),
					'invited_at'       => Field::datetime()->readonly(),
					'accepted_at'      => Field::datetime()->readonly(),
					'deactivated_at'   => Field::datetime()->readonly(),
				)
			) )->with(
				array(
					'unique'       => array( 'user_id' => array( 'user_id' ) ),
					'indexes'      => array( 'kind_status' => array( 'kind', 'status' ) ),
					'permission'   => 'members',
					'title_field'  => 'name',
					'default_sort' => 'name',
					'label'        => __( 'Member', 'cliezoo' ),
					'plural_label' => __( 'Members', 'cliezoo' ),
					'searchable'   => true,
				)
			),
			( new Entity(
				'access',
				array_merge(
					$object_ref,
					array(
						'principal_type' => Field::enum( array( 'user', 'client', 'team' ) )->required(),
						'principal_id'   => Field::int()->required(),
						'role'           => Field::enum( array( 'admin', 'manager', 'creator', 'commenter', 'uploader', 'downloader', 'viewer' ) )->default( 'viewer' ),
						'invited_by'     => Field::int(),
					)
				)
			) )->with(
				array(
					'public'      => false,
					'soft_delete' => false,
					'activity'    => false,
					'unique'      => array( 'object_principal' => array( 'object_type', 'object_id', 'principal_type', 'principal_id' ) ),
					'indexes'     => array( 'principal' => array( 'principal_type', 'principal_id', 'object_type' ) ),
				)
			),
			( new Entity(
				'sequences',
				array(
					'series'       => Field::text( 20 )->required(),
					'prefix'       => Field::text( 40 )->default( '' ),
					'pattern'      => Field::text( 80 )->default( '{PREFIX}{#####}' ),
					'next_value'   => Field::int()->default( 1 ),
					'yearly_reset' => Field::bool(),
					'period'       => Field::text( 10 )->default( '' ),
				)
			) )->with(
				array(
					'public'      => false,
					'soft_delete' => false,
					'activity'    => false,
					'unique'      => array( 'series' => array( 'series' ) ),
				)
			),
			( new Entity(
				'taxonomy_terms',
				array(
					'list'       => Field::text( 60 )->required()->filterable(),
					'value'      => Field::text( 60 )->required(),
					'label'      => Field::text()->required()->searchable(),
					'color'      => Field::color(),
					'position'   => Field::int()->default( 0 )->sortable(),
					'is_default' => Field::bool(),
					'meta'       => Field::json(),
				)
			) )->with(
				array(
					'soft_delete'  => false,
					'unique'       => array( 'list_value' => array( 'list', 'value' ) ),
					'indexes'      => array( 'list_position' => array( 'list', 'position' ) ),
					'permission'   => 'settings',
					'title_field'  => 'label',
					'default_sort' => 'position',
					'label'        => __( 'List value', 'cliezoo' ),
					'plural_label' => __( 'Editable lists', 'cliezoo' ),
				)
			),
			( new Entity(
				'favourites',
				array_merge(
					$object_ref,
					array(
						'member_id' => Field::int()->required(),
						'position'  => Field::int()->default( 0 ),
					)
				)
			) )->with(
				array(
					'label'        => __( 'Favourite', 'cliezoo' ),
					'plural_label' => __( 'Favourites', 'cliezoo' ),
					'soft_delete'  => false,
					'activity'     => false,
					'unique'       => array( 'member_object' => array( 'member_id', 'object_type', 'object_id' ) ),
					'permission'   => 'self',
				)
			),
			( new Entity(
				'saved_views',
				array(
					'member_id'  => Field::int()->readonly(),
					'entity'     => Field::text( 60 )->required()->filterable(),
					'name'       => Field::text()->required(),
					'config'     => Field::json(),
					'is_default' => Field::bool(),
					'shared'     => Field::bool()->readonly(),
					'role'       => Field::text( 40 )->readonly(),
					'position'   => Field::int()->default( 0 ),
				)
			) )->with(
				array(
					'label'        => __( 'Saved view', 'cliezoo' ),
					'plural_label' => __( 'Saved views', 'cliezoo' ),
					'soft_delete'  => false,
					'activity'     => false,
					'indexes'      => array( 'member_entity' => array( 'member_id', 'entity' ) ),
					'permission'   => 'self',
					'default_sort' => 'position',
				)
			),
			( new Entity(
				'notes',
				array_merge(
					$object_ref,
					array(
						'body'         => Field::html()->required()->searchable(),
						'pinned'       => Field::bool(),
						'ai_generated' => Field::bool()->readonly(),
					)
				)
			) )->with(
				array(
					'label'           => __( 'Note', 'cliezoo' ),
					'plural_label'    => __( 'Notes', 'cliezoo' ),
					'indexes'         => array( 'object' => array( 'object_type', 'object_id' ) ),
					'permission'      => 'notes',
					'object_attached' => true,
					'title_field'     => 'body',
				)
			),
			( new Entity(
				'todos',
				array_merge(
					$object_ref,
					array(
						'title'       => Field::text()->required()->searchable(),
						'due_date'    => Field::date()->indexed(),
						'assignee_id' => Field::user()->filterable(),
						'done'        => Field::bool()->filterable(),
						'done_at'     => Field::datetime()->readonly(),
						'activity_id' => Field::int(),
					)
				)
			) )->with(
				array(
					'label'           => __( 'To-do', 'cliezoo' ),
					'plural_label'    => __( 'To-dos', 'cliezoo' ),
					'indexes'         => array(
						'object'        => array( 'object_type', 'object_id' ),
						'assignee_done' => array( 'assignee_id', 'done', 'due_date' ),
					),
					'permission'      => 'notes',
					'object_attached' => true,
					'owner_field'     => 'assignee_id',
					'title_field'     => 'title',
					'default_sort'    => 'created_at',
				)
			),
			( new Entity(
				'comments',
				array_merge(
					$object_ref,
					array(
						'parent_id'   => Field::int(),
						'body'        => Field::html()->required()->searchable(),
						'internal'    => Field::bool()->filterable(),
						'anchor'      => Field::json(),
						'resolved'    => Field::bool()->filterable(),
						'resolved_by' => Field::int()->readonly(),
						'resolved_at' => Field::datetime()->readonly(),
						'edited_at'   => Field::datetime()->readonly(),
						// The files it carries. They are attached to the
						// record, so its Files tab has every one of them.
						'files'       => Field::json()->readonly(),
						// Who has been told they were mentioned, so saying
						// their name again in an edit does not tell them again.
						'mentioned'   => Field::json()->hidden(),
					)
				)
			) )->with(
				array(
					'label'           => __( 'Comment', 'cliezoo' ),
					'plural_label'    => __( 'Comments', 'cliezoo' ),
					'indexes'         => array( 'object' => array( 'object_type', 'object_id', 'created_at' ) ),
					'permission'      => 'comments',
					'object_attached' => true,
					'title_field'     => 'body',
					'default_sort'    => 'created_at',
				)
			),
			( new Entity(
				'templates',
				array(
					'type'        => Field::text( 40 )->required()->filterable(),
					'name'        => Field::text()->required()->searchable()->sortable(),
					'description' => Field::longtext(),
					'category'    => Field::text( 80 )->filterable(),
					'tags'        => Field::json(),
					'content'     => Field::json(),
					'is_builtin'  => Field::bool()->readonly()->filterable(),
					'builtin_key' => Field::text( 80 )->readonly(),
					'locale'      => Field::text( 20 ),
				)
			) )->with(
				array(
					'indexes'      => array( 'type_category' => array( 'type', 'category' ) ),
					'permission'   => 'templates',
					'title_field'  => 'name',
					'default_sort' => 'name',
					'label'        => __( 'Template', 'cliezoo' ),
					'plural_label' => __( 'Templates', 'cliezoo' ),
				)
			),
			( new Entity(
				'public_links',
				array_merge(
					$object_ref,
					array(
						'token_hash'     => Field::text( 64 )->hidden(),
						// The token again, encrypted rather than hashed, so
						// a link can be put back on a screen. A hash cannot
						// be turned back into an address, and a link
						// nobody can see again is a link that has to be
						// replaced every time it is wanted — which breaks
						// the one already in a client's inbox.
						'token_cipher'   => Field::longtext()->hidden(),
						'purpose'        => Field::text( 30 )->required()->filterable(),
						'passcode_hash'  => Field::text( 255 )->hidden(),
						'expires_at'     => Field::datetime(),
						'allow_download' => Field::bool()->default( true ),
						'revoked_at'     => Field::datetime()->readonly(),
						'view_count'     => Field::int()->default( 0 )->readonly(),
						'last_viewed_at' => Field::datetime()->readonly(),
						'require_email'  => Field::bool(),
						'meta'           => Field::json(),
					)
				)
			) )->with(
				array(
					'label'           => __( 'Share link', 'cliezoo' ),
					'plural_label'    => __( 'Share links', 'cliezoo' ),
					'soft_delete'     => false,
					'unique'          => array( 'token_hash' => array( 'token_hash' ) ),
					'indexes'         => array( 'object' => array( 'object_type', 'object_id' ) ),
					'permission'      => 'links',
					'object_attached' => true,
				)
			),
		);
	}

	/**
	 * Registers hooks, services and extension points.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$container = $plugin->container();
		$container->set( Members::class, static fn(): Members => new Members( $plugin ) );

		// Somebody's language is theirs: the app and the portal speak it.
		// It is kept where WordPress keeps a user's language, so the same
		// choice holds in wp-admin, and read back on Cliezoo's own screens,
		// where WordPress would otherwise use the site's.
		foreach ( array( 'create', 'update' ) as $when ) {
			add_filter( 'cliezoo_members_before_' . $when, array( Members::class, 'check_locale' ) );
		}
		add_action(
			'cliezoo_members_updated',
			static function ( array $row, array $before ): void {
				if ( empty( $row['user_id'] ) || (string) ( $row['locale'] ?? '' ) === (string) ( $before['locale'] ?? '' ) ) {
					return;
				}
				Members::keep_locale( (int) $row['user_id'], (string) ( $row['locale'] ?? '' ) );
			},
			10,
			2
		);
		add_filter(
			'determine_locale',
			static function ( string $locale ): string {
				if ( is_admin() || ! is_user_logged_in() || ! self::is_cliezoo_request() ) {
					return $locale;
				}
				$mine = get_user_locale();
				return '' !== $mine ? $mine : $locale;
			},
			20
		);
		$container->set( Numbering::class, static fn(): Numbering => new Numbering() );
		$container->set( Settings::class, static fn(): Settings => new Settings() );
		\Cliezoo\Core\App\SyncClock::hooks( $plugin );
		$container->set( TemplateIndex::class, static fn(): TemplateIndex => new TemplateIndex( $plugin ) );
		$container->set( Jobs::class, static fn(): Jobs => new Jobs() );
		$container->set( SafeMode::class, static fn(): SafeMode => new SafeMode() );
		$container->get( Members::class )->hooks();
		\Cliezoo\Platform\Members\TeamActions::hooks( $plugin );
		$container->get( Jobs::class )->hooks();
		$container->get( SafeMode::class )->hooks();
		add_action( 'cliezoo_migrated', array( \Cliezoo\Core\Installer::class, 'add_capabilities' ) );
		// After an install, an upgrade or a restore, no counter may be behind
		// the numbers already in use (Blueprint §31.4).
		add_action( 'cliezoo_migrated', static fn() => $container->get( Numbering::class )->repair_all( $plugin->entities() ), 20 );
		add_action(
			'cliezoo_every_day',
			static function () use ( $plugin ): void {
				( new TrashPurger( $plugin ) )->run();
			}
		);
		// Deleting a record for good takes its children with it. Addresses,
		// billing profiles and their like exist only through their parent, so
		// leaving them behind would leave rows nothing can reach (cliezoo.md
		// §6.5: nothing is left orphaned).
		add_action(
			'cliezoo_record_purging',
			static function ( Entity $entity, array $row, Actor $actor ) use ( $plugin ): void {
				foreach ( $plugin->entities()->all() as $child ) {
					if ( ( $child->parent[0] ?? '' ) !== $entity->name ) {
						continue;
					}
					$service = $plugin->service( $child->name );
					// Through the service, not the table: a child has its own
					// access grants, notes, activity and custom-field values,
					// and they are removed by the same hook this is listening
					// to. A bare DELETE would leave all of them behind — and
					// an access grant pointing at a recycled id is worse than
					// a wasted row.
					foreach ( $service->repository()->where( array( $child->parent[1] => (int) $row['id'] ), 'id', 0, true ) as $child_row ) {
						$service->purge_row( $actor, $child_row );
					}
				}
			},
			10,
			3
		);
		add_action( 'cliezoo_register_settings', array( $this, 'settings' ) );
		add_action(
			'cliezoo_register_actions',
			static function ( \Cliezoo\Core\Actions\ActionRegistry $registry ) use ( $plugin ): void {
				\Cliezoo\Platform\Settings\SettingsActions::register( $registry, $plugin );
				\Cliezoo\Core\App\AppActions::register( $registry, $plugin );
				\Cliezoo\Core\Rest\MetaActions::register( $registry, $plugin );
				\Cliezoo\Core\Rest\BatchActions::register( $registry, $plugin );
				\Cliezoo\Core\Rest\ListRollup::register( $registry, $plugin );
				\Cliezoo\Core\Rest\OpenApi::register( $registry, $plugin );
			}
		);
		add_filter( 'cliezoo_entity_verbs', array( $this, 'verbs' ), 10, 2 );
	}

	/**
	 * Workspace settings schema (General, Localization, Contact details, data safety).
	 *
	 * @param Settings $settings Settings.
	 * @return void
	 */
	public function settings( Settings $settings ): void {
		$string = static fn( string $fallback = '', int $max = 190 ): array => array(
			'type'      => 'string',
			'maxLength' => $max,
			'default'   => $fallback,
		);
		$bool   = static fn( bool $fallback ): array => array(
			'type'    => 'boolean',
			'default' => $fallback,
		);
		$settings->register(
			'workspace',
			array(
				'name'                     => $string( get_bloginfo( 'name' ) ),
				// One of the spec's twenty industries, so the setup wizard
				// can preload defaults for it (IndustryPresets). Empty means
				// "not said".
				'industry'                 => array(
					'type'    => 'string',
					'enum'    => array_merge( array( '' ), \Cliezoo\Platform\Setup\IndustryPresets::INDUSTRIES ),
					'default' => '',
				),
				'company_size'             => array(
					'type'    => 'string',
					'enum'    => array( '', '1-10', '11-50', '51-200', '201-500', '501-1000', '1001-5000', '5000+' ),
					'default' => '',
				),
				'country'                  => array(
					'type'    => 'string',
					'pattern' => '^([A-Z]{2})?$',
					'default' => '',
				),
				'language'                 => $string( '', 20 ),
				'timezone'                 => $string( wp_timezone_string(), 60 ),
				'currency'                 => array(
					'type'    => 'string',
					'pattern' => '^[A-Z]{3}$',
					'default' => 'USD',
				),
				'currency_symbol_position' => array(
					'type'    => 'string',
					'enum'    => array( 'before', 'after', 'before_space', 'after_space' ),
					'default' => 'before',
				),
				'decimal_separator'        => array(
					'type'    => 'string',
					'enum'    => array( '.', ',' ),
					'default' => '.',
				),
				'thousands_separator'      => array(
					'type'    => 'string',
					'enum'    => array( ',', '.', ' ', "'", '' ),
					'default' => ',',
				),
				'digit_grouping'           => array(
					'type'    => 'string',
					'enum'    => array( 'standard', 'indian' ),
					'default' => 'standard',
				),
				'date_format'              => array(
					'type'    => 'string',
					'enum'    => array( 'MM/DD/YYYY', 'DD/MM/YYYY', 'YYYY-MM-DD', 'MMM D, YYYY', 'D MMM YYYY' ),
					'default' => 'MMM D, YYYY',
				),
				'time_format'              => array(
					'type'    => 'string',
					'enum'    => array( '12', '24' ),
					'default' => '12',
				),
				'week_start'               => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 6,
					'default' => (int) get_option( 'start_of_week', 1 ),
				),
				'fiscal_year_start'        => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 12,
					'default' => 1,
				),
				'holidays'                 => array(
					'type'    => 'array',
					'items'   => array(
						'type'       => 'object',
						'properties' => array(
							'date'      => array(
								'type'   => 'string',
								'format' => 'date',
							),
							'name'      => array(
								'type'      => 'string',
								'maxLength' => 120,
							),
							'recurring' => array( 'type' => 'boolean' ),
						),
					),
					'default' => array(),
				),
				'working_days'             => array(
					'type'    => 'array',
					'items'   => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 6,
					),
					'default' => array( 1, 2, 3, 4, 5 ),
				),
				'address'                  => array(
					'type'       => 'object',
					'properties' => array(
						'street'   => array(
							'type'      => 'string',
							'maxLength' => 190,
						),
						'city'     => array(
							'type'      => 'string',
							'maxLength' => 120,
						),
						'region'   => array(
							'type'      => 'string',
							'maxLength' => 120,
						),
						'postcode' => array(
							'type'      => 'string',
							'maxLength' => 30,
						),
						'country'  => array(
							'type'      => 'string',
							'maxLength' => 2,
						),
					),
					'default'    => array(),
				),
				'website'                  => array(
					'type'    => 'string',
					'format'  => 'uri',
					'default' => home_url(),
				),
				'phone'                    => $string( '', 40 ),
				'phone_country'            => $string( '', 2 ),
				'support_email'            => array(
					'type'    => 'string',
					'default' => (string) get_option( 'admin_email' ),
				),
				'trash_days'               => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 3650,
					'default' => 30,
				),
				'powered_by'               => $bool( false ),
				'delete_data_on_uninstall' => $bool( false ),
				'debug_log'                => $bool( false ),
			)
		);
		$colour = static fn( string $fallback ): array => array(
			'type'    => 'string',
			'pattern' => '^(#[0-9a-fA-F]{6})?$',
			'default' => $fallback,
		);
		$settings->register(
			'branding',
			array(
				'logo'                => $string( '', 500 ),
				'logo_dark'           => $string( '', 500 ),
				'favicon'             => $string( '', 500 ),
				'logoAlt'             => $string(),
				'tagline'             => $string( '', 120 ),
				'accent'              => $colour( '#0458FB' ),
				'accent_dark'         => $colour( '' ),
				// Only typefaces shipped inside the plugin: WordPress.org
				// plugins may not load fonts from another server.
				'font'                => array(
					'type'    => 'string',
					'enum'    => array( 'inter', 'system' ),
					'default' => 'inter',
				),
				// The theme tokens (cliezoo.md §6.37): navigation (the top
				// bar and the phone's bottom bar) and the sidebar, each with
				// a dark-mode version. Empty means the house style.
				'nav_text'            => $colour( '' ),
				'nav_accent'          => $colour( '' ),
				'sidebar_bg'          => $colour( '' ),
				'sidebar_text'        => $colour( '' ),
				'sidebar_accent'      => $colour( '' ),
				'nav_text_dark'       => $colour( '' ),
				'nav_accent_dark'     => $colour( '' ),
				'sidebar_bg_dark'     => $colour( '' ),
				'sidebar_text_dark'   => $colour( '' ),
				'sidebar_accent_dark' => $colour( '' ),
				'radius'              => array(
					'type'    => 'string',
					'enum'    => array( 'none', 'sm', 'md', 'lg' ),
					'default' => 'md',
				),
				'density'             => array(
					'type'    => 'string',
					'enum'    => array( 'comfortable', 'compact' ),
					'default' => 'comfortable',
				),
				// "plain" is the preset called None. The named presets are
				// CSS gradients in tokens.css, so nothing is fetched.
				'background'          => array(
					'type'    => 'string',
					'enum'    => array( 'plain', 'soft', 'mesh', 'image', 'soft_rose', 'flare', 'vivid', 'aragon', 'sunset', 'cheer_up', 'deja_vu', 'dark_night' ),
					'default' => 'plain',
				),
				'sign_in_image'       => $string( '', 500 ),
				'sign_in_title'       => $string( '', 120 ),
				'sign_in_text'        => $string( '', 300 ),
				'sign_in_side'        => array(
					'type'    => 'string',
					'enum'    => array( 'none', 'start', 'end' ),
					'default' => 'none',
				),
				// The sign-in page designer: where the form sits and its own
				// colours. The "Powered by" credit is the workspace's one
				// global switch (workspace.powered_by), not a second one here.
				'sign_in_position'    => array(
					'type'    => 'string',
					'enum'    => array( 'center', 'start', 'end' ),
					'default' => 'center',
				),
				'sign_in_bg'          => $colour( '' ),
				'sign_in_accent'      => $colour( '' ),
				'sign_in_text_color'  => $colour( '' ),
			)
		);
	}

	/**
	 * Members are created through invitations, not the generic create route.
	 *
	 * @param string[]                  $verbs  Verbs.
	 * @param \Cliezoo\Core\Data\Entity $entity Entity.
	 * @return string[]
	 */
	public function verbs( array $verbs, Entity $entity ): array {
		if ( 'members' === $entity->name ) {
			return array( 'list', 'get', 'update' );
		}
		return $verbs;
	}

	/**
	 * Whether this request is one of Cliezoo's own: its app or portal
	 * screens, or its REST routes. The rest of the site keeps the site's
	 * language whoever is signed in.
	 *
	 * @return bool
	 */
	private static function is_cliezoo_request(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return false;
		}
		$uri  = rawurldecode( $uri );
		$base = trim( AppRouter::bases()['app'], '/' );
		$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return str_contains( $uri, '/cliezoo/v1' )
			|| str_starts_with( $uri, trailingslashit( $path ) . $base . '/' )
			|| str_contains( $uri, 'cliezoo=/' . $base );
	}
}
