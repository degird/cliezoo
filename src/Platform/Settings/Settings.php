<?php
/**
 * Typed settings per module.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Settings;

use Cliezoo\Core\Actions\ActionError;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * One non-autoloaded option per module (`cliezoo_settings_{module}`) with a
 * JSON schema (defaults, validation), cached in the object cache.
 */
final class Settings {

	/**
	 * Schemas per module: key => JSON schema (with "default").
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $schemas = array();

	/**
	 * Loaded values per module.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $loaded = array();

	/**
	 * Whether schemas were collected.
	 *
	 * @var bool
	 */
	private bool $schemas_ready = false;

	/**
	 * Registers (or extends) a module's settings schema.
	 *
	 * @param string                              $module Module id.
	 * @param array<string, array<string, mixed>> $schema Key => JSON schema incl. default.
	 * @return void
	 */
	public function register( string $module, array $schema ): void {
		$this->schemas[ $module ] = array_merge( $this->schemas[ $module ] ?? array(), $schema );
		unset( $this->loaded[ $module ] );
	}

	/**
	 * Registered schema for a module.
	 *
	 * @param string $module Module.
	 * @return array<string, array<string, mixed>>
	 */
	public function schema( string $module ): array {
		$this->ensure_schemas();
		return $this->schemas[ $module ] ?? array();
	}

	/**
	 * Modules with settings.
	 *
	 * @return string[]
	 */
	public function modules(): array {
		$this->ensure_schemas();
		return array_keys( $this->schemas );
	}

	/**
	 * Lets modules register schemas lazily.
	 *
	 * @return void
	 */
	private function ensure_schemas(): void {
		if ( $this->schemas_ready ) {
			return;
		}
		$this->schemas_ready = true;
		/**
		 * Register settings schemas: $settings->register( 'invoices', [...] ).
		 *
		 * @param Settings $settings Settings service.
		 */
		do_action( 'cliezoo_register_settings', $this );
	}

	/**
	 * All values for a module, defaults filled in.
	 *
	 * @param string $module Module.
	 * @return array<string, mixed>
	 */
	public function all( string $module ): array {
		if ( isset( $this->loaded[ $module ] ) ) {
			return $this->loaded[ $module ];
		}
		$this->ensure_schemas();
		$cached = wp_cache_get( 'settings_' . $module, 'cliezoo' );
		if ( ! is_array( $cached ) ) {
			$cached = get_option( 'cliezoo_settings_' . $module, array() );
			$cached = is_array( $cached ) ? $cached : array();
			wp_cache_set( 'settings_' . $module, $cached, 'cliezoo' );
		}
		$values = array();
		foreach ( $this->schemas[ $module ] ?? array() as $key => $schema ) {
			$values[ $key ] = array_key_exists( $key, $cached ) ? $cached[ $key ] : ( $schema['default'] ?? null );
		}
		$this->loaded[ $module ] = $values;
		return $values;
	}

	/**
	 * One value.
	 *
	 * @param string $module Module.
	 * @param string $key    Key.
	 * @param mixed  $fallback Value when the key is unknown.
	 * @return mixed
	 */
	public function get( string $module, string $key, $fallback = null ) {
		$all = $this->all( $module );
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Forgets the values read in this request, so the next read comes from
	 * the option again (tests that roll the database back, long jobs).
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->loaded = array();
	}

	/**
	 * Validates and saves values (unknown keys are ignored).
	 *
	 * @param string               $module Module.
	 * @param array<string, mixed> $values Values.
	 * @return array<string, mixed> All values after saving.
	 * @throws ActionError On invalid values.
	 */
	public function update( string $module, array $values ): array {
		$this->ensure_schemas();
		$schema  = $this->schemas[ $module ] ?? array();
		$current = get_option( 'cliezoo_settings_' . $module, array() );
		$current = is_array( $current ) ? $current : array();
		$errors  = array();
		/**
		 * Filters values on their way in, before validation. Modules that
		 * store a secret encrypt it here, so the plain value never reaches
		 * the option table.
		 *
		 * @param array  $values Values being saved.
		 * @param string $module Module id.
		 */
		$values = (array) apply_filters( 'cliezoo_settings_before_save', $values, $module );
		foreach ( $values as $key => $value ) {
			if ( ! isset( $schema[ $key ] ) ) {
				continue;
			}
			$field_schema = $schema[ $key ];
			unset( $field_schema['default'] );
			$valid = rest_validate_value_from_schema( $value, $field_schema, $key );
			if ( is_wp_error( $valid ) ) {
				$errors[ $key ] = $valid->get_error_message();
				continue;
			}
			$current[ $key ] = rest_sanitize_value_from_schema( $value, $field_schema, $key );
		}
		if ( $errors ) {
			throw ActionError::validation( __( 'Some settings are not valid.', 'cliezoo' ), $errors );
		}
		update_option( 'cliezoo_settings_' . $module, $current, false );
		wp_cache_delete( 'settings_' . $module, 'cliezoo' );
		unset( $this->loaded[ $module ] );
		/**
		 * Settings of a module were saved.
		 *
		 * @param string $module Module.
		 * @param array  $values New values.
		 */
		do_action( 'cliezoo_settings_updated', $module, $this->all( $module ) );
		return $this->all( $module );
	}
}
