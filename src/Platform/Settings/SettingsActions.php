<?php
/**
 * Settings REST actions.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Settings;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Jobs\SafeMode;
use Cliezoo\Infrastructure\Storage\StorageDriverRegistry;
use Cliezoo\Platform\Money\Currencies;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * `GET/PATCH /settings/{module}` with per-module permissions and extra
 * validation (IANA time zones, ISO currencies, module switches).
 */
final class SettingsActions {

	/**
	 * Modules every staff member reads: the app needs them to render itself.
	 * Any other module needs the rights that let you change it, so credentials
	 * and payment configuration stay with the people who manage them.
	 */
	private const OPEN_READ = array( 'workspace', 'branding', 'notifications', 'reminders', 'storage', 'modules' );

	/**
	 * Toggle that allows editing a settings module (admins always can).
	 */
	private const WRITE_TOGGLE = array(
		'workspace'     => 'settings_general',
		'branding'      => 'settings_general',
		'notifications' => 'settings_general',
		'reminders'     => 'settings_general',
		'invoices'      => 'settings_payments',
		'payments'      => 'settings_payments',
		'quotes'        => 'settings_payments',
		'forms'         => 'settings_forms',
		'ai'            => 'ai_manage',
		'email'         => 'email_templates',
	);

	/**
	 * Registers the actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	public static function register( ActionRegistry $registry, Plugin $plugin ): void {
		\Cliezoo\Platform\Numbering\NumberingActions::register( $registry, $plugin );
		$module = array(
			'type'     => 'string',
			'pattern'  => '^[a-z_]{2,40}$',
			'required' => true,
		);
		$registry->add(
			Op::make( 'templates.all', __( 'Every template', 'cliezoo' ) )
				->describe( __( 'Every template in the workspace, whatever it makes, with where each one is looked after.', 'cliezoo' ) )
				->input(
					array(
						'kind' => array(
							'type'      => 'string',
							'maxLength' => 40,
							'default'   => '',
						),
						'q'    => array(
							'type'      => 'string',
							'maxLength' => 200,
							'default'   => '',
						),
					)
				)
				->allow( static fn( Actor $a ): bool => $a->is_staff() && ( $a->is_admin() || $a->allows( 'templates_manage' ) ) )
				->run(
					static fn( Actor $a, array $in ): array => $plugin->get( TemplateIndex::class )->all(
						$a,
						(string) ( $in['kind'] ?? '' ),
						(string) ( $in['q'] ?? '' )
					)
				)
				->rest( 'GET', '/templates/all' )
				->readonly()
				->with( array( 'mcp' => 'read' ) )
		);

		$registry->add(
			Op::make( 'settings.get', __( 'Read settings', 'cliezoo' ) )
				->input( array( 'module' => $module ) )
				->allow( static fn( Actor $a, array $in ): bool => self::can_read( $a, (string) $in['module'] ) )
				->run(
					static function ( Actor $a, array $in ) use ( $plugin ): array {
						$name = (string) $in['module'];
						if ( ! in_array( $name, $plugin->settings()->modules(), true ) ) {
							throw ActionError::not_found();
						}
						$out = array(
							'values'   => self::mask_secrets( $plugin->settings()->all( $name ), $plugin->settings()->schema( $name ) ),
							'can_edit' => self::can_edit( $a, $name ),
						);
						// Facts about the workspace that are shown, not set.
						if ( 'workspace' === $name ) {
							$out['info'] = array( 'id' => WorkspaceId::get() );
						}
						return $out;
					}
				)
				->rest( 'GET', '/settings/(?P<module>[a-z_]{2,40})' )
				->readonly()
		);
		$registry->add(
			Op::make( 'settings.update', __( 'Save settings', 'cliezoo' ) )
				->input(
					array(
						'module' => $module,
						'values' => array(
							'type'     => 'object',
							'required' => true,
						),
					)
				)
				->allow( static fn( Actor $a, array $in ): bool => self::can_edit( $a, (string) $in['module'] ) )
				->run(
					static function ( Actor $a, array $in ) use ( $plugin ): array {
						$name   = (string) $in['module'];
						$values = (array) $in['values'];
						if ( ! in_array( $name, $plugin->settings()->modules(), true ) ) {
							throw ActionError::not_found();
						}
						// A masked secret sent back unchanged leaves the stored one alone.
						$values = array_filter( $values, static fn( $value ): bool => '__cliezoo_secret__' !== $value );
						self::extra_validation( $name, $values );
						// Masked on the way out as well as the way in: a secret
						// is write-only, and saving is not a way to read it back.
						return array(
							'values'   => self::mask_secrets( $plugin->settings()->update( $name, $values ), $plugin->settings()->schema( $name ) ),
							'can_edit' => true,
						);
					}
				)
				->rest( 'PATCH', '/settings/(?P<module>[a-z_]{2,40})' )
		);
		$registry->add(
			Op::make( 'safe_mode.get', __( 'Safe mode status', 'cliezoo' ) )
				->allow( static fn( Actor $a ): bool => $a->is_admin() )
				->run( static fn(): array => $plugin->get( SafeMode::class )->status() )
				->rest( 'GET', '/safe-mode' )
				->readonly()
		);
		$registry->add(
			Op::make( 'safe_mode.update', __( 'Turn Safe mode on or off', 'cliezoo' ) )
				->describe( __( 'Off means this is the live site: the current address is accepted, paused work resumes and emails held meanwhile are discarded.', 'cliezoo' ) )
				->input(
					array(
						'active' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					)
				)
				->allow( static fn( Actor $a ): bool => $a->is_admin() )
				->run(
					static function ( Actor $a, array $in ) use ( $plugin ): array {
						$safe = $plugin->get( SafeMode::class );
						if ( ! empty( $in['active'] ) ) {
							$safe->enable( SafeMode::MANUAL, array( 'member' => $a->member_id ) );
						} else {
							$safe->disable( $a );
						}
						return $safe->status();
					}
				)
				->rest( 'POST', '/safe-mode' )
				->with( array( 'destructive' => true ) )
		);
		$registry->add(
			Op::make( 'modules.list', __( 'Modules', 'cliezoo' ) )
				->allow( static fn( Actor $a ): bool => $a->is_staff() )
				->run(
					static function () use ( $plugin ): array {
						$out = array();
						foreach ( $plugin->modules()->all() as $id => $mod ) {
							$out[] = array(
								'id'         => $id,
								'switchable' => $mod->switchable(),
								'active'     => $plugin->module_active( $id ),
								'depends_on' => $mod->dependencies(),
							);
						}
						return $out;
					}
				)
				->rest( 'GET', '/modules' )
				->readonly()
		);
		$registry->add(
			Op::make( 'modules.switch', __( 'Turn modules on or off', 'cliezoo' ) )
				->input(
					array(
						'off' => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'string',
								'pattern' => '^[a-z_]{2,40}$',
							),
							'required' => true,
						),
					)
				)
				->allow( static fn( Actor $a ): bool => $a->is_admin() || $a->allows( 'settings_general' ) )
				->run(
					static function ( Actor $a, array $in ) use ( $plugin ): array {
						$off = array();
						foreach ( (array) $in['off'] as $id ) {
							$mod = $plugin->modules()->get( (string) $id );
							if ( $mod && $mod->switchable() ) {
								$off[] = (string) $id;
							}
						}
						$off = array_values( array_unique( $off ) );
						update_option( 'cliezoo_modules_off', $off, true );
						// A module can add a field to another module's table —
						// a task's milestone, say — so switching one on or off
						// changes the shape of the database, not only what is
						// on screen. Without this the column is missing until
						// the next version bump, and the module that reads it
						// fails on a table that has never heard of it.
						$plugin->migrate();
						do_action( 'cliezoo_audit', 'modules_switched', array( 'off' => $off ) );
						return array( 'off' => $off );
					}
				)
				->rest( 'POST', '/modules' )
		);

		$images = static fn(): Images => new Images();
		$registry->add(
			Op::make( 'images.recent', __( 'Pictures for the workspace’s look', 'cliezoo' ) )
				->describe( __( 'The latest pictures in the site’s media library, for a logo or an invoice header: addresses a client can load.', 'cliezoo' ) )
				->allow( static fn( Actor $a ): bool => Images::may( $a ) )
				->run( static fn(): array => $images()->recent() )
				->rest( 'GET', '/images' )
				->readonly()
		);
		$registry->add(
			Op::make( 'images.upload', __( 'Add a picture for the workspace’s look', 'cliezoo' ) )
				->describe( __( 'Adds one PNG, JPEG, GIF or WebP picture of up to 2 MB to the site’s media library and hands back its address.', 'cliezoo' ) )
				->input(
					array(
						'name' => Op::str( 200 ),
						'data' => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 2900000,
						),
					)
				)
				->allow( static fn( Actor $a ): bool => Images::may( $a ) )
				->run( static fn( Actor $a, array $in ): array => $images()->upload( (string) ( $in['name'] ?? '' ), (string) $in['data'] ) )
				->rest( 'POST', '/images' )
		);
	}

	/**
	 * Whether the actor may read a settings module.
	 *
	 * @param Actor  $actor  Actor.
	 * @param string $module Module.
	 * @return bool
	 */
	public static function can_read( Actor $actor, string $module ): bool {
		if ( ! $actor->is_staff() ) {
			return false;
		}
		return in_array( $module, self::OPEN_READ, true ) || self::can_edit( $actor, $module );
	}

	/**
	 * Replaces stored secrets with a marker: they are write-only over REST.
	 *
	 * @param array<string, mixed>                $values Values.
	 * @param array<string, array<string, mixed>> $schema Module schema.
	 * @return array<string, mixed>
	 */
	public static function mask_secrets( array $values, array $schema ): array {
		foreach ( $schema as $key => $field ) {
			if ( ! empty( $field['secret'] ) && ! empty( $values[ $key ] ) ) {
				$values[ $key ] = '__cliezoo_secret__';
			}
		}
		return $values;
	}

	/**
	 * Whether the actor may edit a settings module.
	 *
	 * @param Actor  $actor  Actor.
	 * @param string $module Module.
	 * @return bool
	 */
	public static function can_edit( Actor $actor, string $module ): bool {
		if ( $actor->is_admin() ) {
			return true;
		}
		$toggle = self::WRITE_TOGGLE[ $module ] ?? '';
		return '' !== $toggle && $actor->allows( $toggle );
	}

	/**
	 * Validation the JSON schema cannot express.
	 *
	 * @param string               $module Module.
	 * @param array<string, mixed> $values Values.
	 * @return void
	 * @throws ActionError On invalid values.
	 */
	private static function extra_validation( string $module, array $values ): void {
		$errors = array();
		if ( 'workspace' === $module ) {
			if ( isset( $values['timezone'] ) && ! in_array( (string) $values['timezone'], \DateTimeZone::listIdentifiers(), true ) && ! preg_match( '/^UTC[+-]\d{1,2}(:\d{2})?$/', (string) $values['timezone'] ) ) {
				$errors['timezone'] = __( 'Please choose a time zone from the list.', 'cliezoo' );
			}
			if ( isset( $values['currency'] ) && ! Currencies::exists( (string) $values['currency'] ) ) {
				$errors['currency'] = __( 'Unknown currency.', 'cliezoo' );
			}
			if ( isset( $values['support_email'] ) && '' !== $values['support_email'] && ! is_email( (string) $values['support_email'] ) ) {
				$errors['support_email'] = __( 'Please enter a valid email address.', 'cliezoo' );
			}
		}
		if ( 'storage' === $module && isset( $values['default_driver'] ) && ! cliezoo()->get( StorageDriverRegistry::class )->has( (string) $values['default_driver'] ) ) {
			// A storage nothing provides would stop every upload.
			$errors['default_driver'] = __( 'Choose storage that is connected.', 'cliezoo' );
		}
		if ( 'files' === $module ) {
			// Folder names become folders on every new client or project,
			// so a blank, a path or a duplicate would be copied everywhere.
			foreach ( array( 'client_folders', 'project_folders' ) as $key ) {
				if ( ! isset( $values[ $key ] ) ) {
					continue;
				}
				$names = array_map( static fn( $name ): string => trim( (string) $name ), (array) $values[ $key ] );
				$lower = array_map( 'mb_strtolower', $names );
				if ( count( $names ) > 20 ) {
					$errors[ $key ] = __( 'Twenty folders at most.', 'cliezoo' );
				} elseif ( in_array( '', $names, true ) || array_filter( $names, static fn( string $name ): bool => mb_strlen( $name ) > 120 || (bool) preg_match( '#[/\\\\]#', $name ) ) ) {
					$errors[ $key ] = __( 'Each folder needs a name of up to 120 characters, without / or \\.', 'cliezoo' );
				} elseif ( count( array_unique( $lower ) ) !== count( $lower ) ) {
					$errors[ $key ] = __( 'Two folders have the same name.', 'cliezoo' );
				}
			}
		}
		if ( 'email' === $module ) {
			// Mail from an address that is not one would quietly go out from
			// the site's default instead; saying so here is kinder.
			foreach ( array( 'from_address', 'reply_to' ) as $key ) {
				if ( isset( $values[ $key ] ) && '' !== $values[ $key ] && ! is_email( (string) $values[ $key ] ) ) {
					$errors[ $key ] = __( 'Please enter a valid email address.', 'cliezoo' );
				}
			}
		}
		if ( 'ai' === $module && isset( $values['revoye_base'] ) && '' !== (string) $values['revoye_base'] ) {
			// The key is sent to whatever this address says. An address that
			// is not an absolute https one — or that carries credentials of
			// its own — would be a way to post the key somewhere else.
			$base  = (string) $values['revoye_base'];
			$parts = wp_parse_url( $base );
			if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || '' === ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				$errors['revoye_base'] = __( 'Enter a full address beginning https://, or leave this empty for the default.', 'cliezoo' );
			}
		}
		/**
		 * Extra settings validation. Return an array of field => message.
		 *
		 * @param array  $errors Errors.
		 * @param string $module Module.
		 * @param array  $values Values.
		 */
		$errors = (array) apply_filters( 'cliezoo_settings_validate', $errors, $module, $values );
		if ( $errors ) {
			throw ActionError::validation( __( 'Some settings are not valid.', 'cliezoo' ), $errors );
		}
	}
}
