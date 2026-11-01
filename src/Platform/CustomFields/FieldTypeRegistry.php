<?php
/**
 * Custom field types.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\CustomFields;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Data\Html;
use Cliezoo\Platform\Money\Currencies;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * The FieldTypeRegistry (Blueprint §19.1). A type is an array of callbacks:
 *
 * - label: string
 * - column: 'text' | 'number' | 'date' — where the value is stored/indexed
 * - normalize: fn( mixed $value, array $definition ): mixed — validate + clean (throws ActionError)
 * - present: fn( array $stored, array $definition ): mixed — stored columns → API value (optional)
 *
 * Free registers: text, longtext, number (plain, thousands, percentage),
 * currency, list and multilist (with per-choice colours), date, datetime,
 * checkbox, url, email, phone, rating, person, file. Add-ons may add relation,
 * formula, rollup and auto-number through `cliezoo_register_field_types`.
 */
final class FieldTypeRegistry {

	/**
	 * Types keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $types = array();

	/**
	 * Registers the Free types.
	 */
	public function __construct() {
		$this->register_free();
		/**
		 * Register more custom field types.
		 *
		 * @param FieldTypeRegistry $registry Registry.
		 */
		do_action( 'cliezoo_register_field_types', $this );
	}

	/**
	 * Adds a type.
	 *
	 * @param string               $id   Type id.
	 * @param array<string, mixed> $type Definition.
	 * @return void
	 */
	public function add( string $id, array $type ): void {
		$this->types[ $id ] = $type + array(
			'column'  => 'text',
			'present' => null,
			// Cleans a field's options when it is saved: fn( array ): array.
			'options' => null,
		);
	}

	/**
	 * A field's options, cleaned by its type (unknown types keep them).
	 *
	 * @param string              $id      Type id.
	 * @param array<mixed, mixed> $options Options as sent.
	 * @return array<string, mixed>
	 */
	public function clean_options( string $id, array $options ): array {
		$type = $this->get( $id );
		if ( ! $type || ! is_callable( $type['options'] ) ) {
			return $options;
		}
		return (array) call_user_func( $type['options'], $options );
	}

	/**
	 * Choices of a list, cleaned: a value, a label and an optional colour
	 * each; at most 200; no two with one value.
	 *
	 * @param array<mixed, mixed> $options Options.
	 * @return array{choices: array<int, array{value: string, label: string, color: string}>}
	 */
	public static function clean_choices( array $options ): array {
		$out  = array();
		$seen = array();
		foreach ( array_slice( (array) ( $options['choices'] ?? array() ), 0, 200 ) as $choice ) {
			if ( ! is_array( $choice ) ) {
				continue;
			}
			$label = mb_substr( sanitize_text_field( (string) ( $choice['label'] ?? '' ) ), 0, 120 );
			// A value is kept as it was sent — records already hold it — and
			// only made from the label when there is none.
			$value = mb_substr( sanitize_text_field( (string) ( $choice['value'] ?? '' ) ), 0, 60 );
			if ( '' === $value ) {
				$value = trim( substr( (string) preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $label ) ), 0, 60 ), '_' );
			}
			// A choice sent as a value alone is labelled with it.
			if ( '' === $label ) {
				$label = mb_substr( sanitize_text_field( (string) ( $choice['value'] ?? '' ) ), 0, 120 );
			}
			if ( '' === $label || '' === $value || isset( $seen[ $value ] ) ) {
				continue;
			}
			$seen[ $value ] = true;
			$color          = (string) ( $choice['color'] ?? '' );
			$out[]          = array(
				'value' => $value,
				'label' => $label,
				'color' => 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ? strtolower( $color ) : '',
			);
		}
		return array( 'choices' => $out );
	}

	/**
	 * The workspace currency, for a currency field that names none.
	 *
	 * @return string
	 */
	private static function workspace_currency(): string {
		$code = function_exists( 'cliezoo' ) ? strtoupper( (string) ( cliezoo()->settings()->all( 'workspace' )['currency'] ?? '' ) ) : '';
		return Currencies::exists( $code ) ? $code : 'USD';
	}

	/**
	 * A type.
	 *
	 * @param string $id Type id.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->types[ $id ] ?? null;
	}

	/**
	 * Type ids with labels.
	 *
	 * @return array<string, string>
	 */
	public function labels(): array {
		return array_map( static fn( array $type ): string => (string) $type['label'], $this->types );
	}

	/**
	 * Built-in Free types.
	 *
	 * @return void
	 */
	private function register_free(): void {
		$invalid = static function ( string $message ): ActionError {
			return ActionError::validation( $message );
		};

		$this->add(
			'text',
			array(
				'label'     => __( 'Text', 'cliezoo' ),
				'normalize' => static fn( $v ) => mb_substr( sanitize_text_field( (string) $v ), 0, 500 ),
			)
		);
		$this->add(
			'longtext',
			array(
				'label'     => __( 'Long text', 'cliezoo' ),
				'normalize' => static fn( $v ) => Html::clean( (string) $v ),
			)
		);
		$this->add(
			'number',
			array(
				'label'     => __( 'Number', 'cliezoo' ),
				'column'    => 'number',
				'normalize' => static function ( $v ) use ( $invalid ) {
					if ( ! is_numeric( $v ) ) {
						throw $invalid( __( 'Please enter a number.', 'cliezoo' ) );
					}
					return (float) $v;
				},
				'present'   => static fn( array $s ) => null === $s['number'] ? null : (float) $s['number'],
				// How the number is shown: as typed, with thousands
				// separators, or as a percentage. The value is the same
				// number whichever it is; only the display changes.
				'options'   => static function ( array $o ): array {
					$format = (string) ( $o['format'] ?? 'plain' );
					return array(
						'format'   => in_array( $format, array( 'plain', 'thousands', 'percentage' ), true ) ? $format : 'plain',
						'decimals' => isset( $o['decimals'] ) && is_numeric( $o['decimals'] ) ? max( 0, min( 6, (int) $o['decimals'] ) ) : null,
					);
				},
			)
		);
		$this->add(
			'currency',
			array(
				'label'     => __( 'Currency', 'cliezoo' ),
				'column'    => 'number',
				// An amount of money is kept as the API keeps all money: an
				// integer in minor units (cents), in the currency the field
				// is set to. A value in another currency is refused rather
				// than silently counted as this one.
				'normalize' => static function ( $v, array $def ) use ( $invalid ) {
					$currency = (string) ( $def['options']['currency'] ?? '' );
					if ( is_array( $v ) ) {
						if ( isset( $v['currency'] ) && '' !== $currency && strtoupper( (string) $v['currency'] ) !== $currency ) {
							/* translators: %s: currency code, e.g. EUR. */
							throw $invalid( sprintf( __( 'This field is counted in %s.', 'cliezoo' ), $currency ) );
						}
						$v = $v['amount'] ?? null;
					}
					if ( ! is_numeric( $v ) || floor( (float) $v ) !== (float) $v ) {
						throw $invalid( __( 'Please enter an amount in minor units (cents).', 'cliezoo' ) );
					}
					if ( abs( (float) $v ) > 1.0E+13 ) {
						throw $invalid( __( 'The amount is too large.', 'cliezoo' ) );
					}
					return (int) $v;
				},
				'present'   => static fn( array $s, array $def ) => null === $s['number'] ? null : array(
					'amount'   => (int) round( (float) $s['number'] ),
					'currency' => (string) ( $def['options']['currency'] ?? '' ),
				),
				'options'   => static function ( array $o ): array {
					$code = strtoupper( (string) ( $o['currency'] ?? '' ) );
					return array( 'currency' => Currencies::exists( $code ) ? $code : self::workspace_currency() );
				},
			)
		);
		$this->add(
			'list',
			array(
				'label'     => __( 'List', 'cliezoo' ),
				'options'   => array( self::class, 'clean_choices' ),
				'normalize' => static function ( $v, array $def ) use ( $invalid ) {
					$values = array_column( (array) ( $def['options']['choices'] ?? array() ), 'value' );
					if ( ! in_array( (string) $v, array_map( 'strval', $values ), true ) ) {
						throw $invalid( __( 'Please choose a value from the list.', 'cliezoo' ) );
					}
					return (string) $v;
				},
			)
		);
		$this->add(
			'multilist',
			array(
				'label'     => __( 'Multi-list', 'cliezoo' ),
				'options'   => array( self::class, 'clean_choices' ),
				'normalize' => static function ( $v, array $def ) use ( $invalid ) {
					$values = array_map( 'strval', array_column( (array) ( $def['options']['choices'] ?? array() ), 'value' ) );
					$picked = array_values( array_unique( array_map( 'strval', (array) $v ) ) );
					if ( array_diff( $picked, $values ) ) {
						throw $invalid( __( 'Please choose values from the list.', 'cliezoo' ) );
					}
					return $picked;
				},
				'present'   => static fn( array $s ) => null === $s['text'] ? array() : (array) json_decode( (string) $s['text'], true ),
			)
		);
		$this->add(
			'date',
			array(
				'label'     => __( 'Date', 'cliezoo' ),
				'column'    => 'date',
				'normalize' => static function ( $v ) use ( $invalid ) {
					if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ) {
						throw $invalid( __( 'Please enter a valid date.', 'cliezoo' ) );
					}
					return (string) $v . ' 00:00:00';
				},
				'present'   => static fn( array $s ) => null === $s['date'] ? null : substr( (string) $s['date'], 0, 10 ),
			)
		);
		$this->add(
			'datetime',
			array(
				'label'     => __( 'Date and time', 'cliezoo' ),
				'column'    => 'date',
				'normalize' => static function ( $v ) use ( $invalid ) {
					$time = strtotime( (string) $v );
					if ( false === $time ) {
						throw $invalid( __( 'Please enter a valid date and time.', 'cliezoo' ) );
					}
					return gmdate( 'Y-m-d H:i:s', $time );
				},
				'present'   => static fn( array $s ) => null === $s['date'] ? null : str_replace( ' ', 'T', (string) $s['date'] ) . 'Z',
			)
		);
		$this->add(
			'checkbox',
			array(
				'label'     => __( 'Checkbox', 'cliezoo' ),
				'column'    => 'number',
				'normalize' => static fn( $v ) => $v ? 1 : 0,
				'present'   => static fn( array $s ) => (bool) $s['number'],
			)
		);
		$this->add(
			'url',
			array(
				'label'     => __( 'URL', 'cliezoo' ),
				'normalize' => static function ( $v ) use ( $invalid ) {
					$url = esc_url_raw( (string) $v, array( 'http', 'https' ) );
					if ( '' === $url ) {
						throw $invalid( __( 'Please enter a valid web address.', 'cliezoo' ) );
					}
					return $url;
				},
			)
		);
		$this->add(
			'email',
			array(
				'label'     => __( 'Email', 'cliezoo' ),
				'normalize' => static function ( $v ) use ( $invalid ) {
					$email = sanitize_email( (string) $v );
					if ( ! is_email( $email ) ) {
						throw $invalid( __( 'Please enter a valid email address.', 'cliezoo' ) );
					}
					return $email;
				},
			)
		);
		$this->add(
			'phone',
			array(
				'label'     => __( 'Phone', 'cliezoo' ),
				// Stripping what is not part of a number used to be all it
				// did, so "call me" was saved as a blank that passed even a
				// required field.
				'normalize' => static function ( $v ) use ( $invalid ) {
					$phone = trim( mb_substr( (string) preg_replace( '/[^0-9+()\-. x]/', '', (string) $v ), 0, 40 ) );
					if ( 1 !== preg_match( '/\d/', $phone ) ) {
						throw $invalid( __( 'Please enter a valid phone number.', 'cliezoo' ) );
					}
					return $phone;
				},
			)
		);
		$this->add(
			'rating',
			array(
				'label'     => __( 'Rating', 'cliezoo' ),
				'column'    => 'number',
				'normalize' => static function ( $v, array $def ) use ( $invalid ) {
					$max = (int) ( $def['options']['max'] ?? 5 );
					$v   = (int) $v;
					if ( $v < 0 || $v > $max ) {
						throw $invalid( __( 'The rating is out of range.', 'cliezoo' ) );
					}
					return $v;
				},
				'present'   => static fn( array $s ) => null === $s['number'] ? null : (int) $s['number'],
				'options'   => static fn( array $o ): array => array( 'max' => isset( $o['max'] ) ? max( 1, min( 10, (int) $o['max'] ) ) : 5 ),
			)
		);
		$this->add(
			'person',
			array(
				'label'     => __( 'Person', 'cliezoo' ),
				'normalize' => static function ( $v ) use ( $invalid ) {
					$uid = is_array( $v ) ? (string) ( $v['uid'] ?? '' ) : (string) $v;
					$row = cliezoo()->service( 'members' )->repository()->find_by_uid( $uid );
					if ( ! $row ) {
						throw $invalid( __( 'Please choose a team member.', 'cliezoo' ) );
					}
					return $uid;
				},
				// Resolved in batch by the service (uid → {uid, label}).
				'refs'      => 'members',
			)
		);
		$this->add(
			'file',
			array(
				'label'     => __( 'File', 'cliezoo' ),
				'normalize' => static function ( $v ) use ( $invalid ) {
					$uid = is_array( $v ) ? (string) ( $v['uid'] ?? '' ) : (string) $v;
					if ( 1 !== preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $uid ) ) {
						throw $invalid( __( 'Please attach a file.', 'cliezoo' ) );
					}
					return $uid;
				},
			)
		);
	}
}
