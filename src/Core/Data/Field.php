<?php
/**
 * Field definition for an entity column.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes one column: storage type, validation, and how lists/REST use it.
 *
 * Types: text, longtext, html, email, url, phone, color, int, decimal, money,
 * currency, bool, date, datetime, enum, json, ref (another entity), user (member).
 */
final class Field {

	/**
	 * Column name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * Field type.
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * Required on create.
	 *
	 * @var bool
	 */
	public bool $required = false;

	/**
	 * Default value (null = no default).
	 *
	 * @var mixed
	 */
	public $default = null;

	/**
	 * Maximum length for text types.
	 *
	 * @var int
	 */
	public int $max = 190;

	/**
	 * Allowed values for enum.
	 *
	 * @var string[]
	 */
	public array $options = array();

	/**
	 * Editable list (taxonomy) key that supplies the options.
	 *
	 * @var string
	 */
	public string $list = '';

	/**
	 * Referenced entity for ref fields.
	 *
	 * @var string
	 */
	public string $ref = '';

	/**
	 * Can be used in list filters.
	 *
	 * @var bool
	 */
	public bool $filterable = false;

	/**
	 * Can be used in list sorting.
	 *
	 * @var bool
	 */
	public bool $sortable = false;

	/**
	 * Included in the search index text.
	 *
	 * @var bool
	 */
	public bool $searchable = false;

	/**
	 * Set by the server only (not accepted from input).
	 *
	 * @var bool
	 */
	public bool $readonly = false;

	/**
	 * Financial field (rates, costs, margins): hidden from roles without finance access.
	 *
	 * @var bool
	 */
	public bool $finance = false;

	/**
	 * Never exposed through REST (hashes, secrets, internal state).
	 *
	 * @var bool
	 */
	public bool $hidden = false;

	/**
	 * Decimal scale for decimal fields.
	 *
	 * @var int
	 */
	public int $scale = 4;

	/**
	 * Minimum for numeric fields.
	 *
	 * @var int|float|null
	 */
	public $min = null;

	/**
	 * Maximum for numeric fields.
	 *
	 * @var int|float|null
	 */
	public $max_value = null;

	/**
	 * Human label (translated at definition time).
	 *
	 * @var string
	 */
	public string $label = '';

	/**
	 * For money fields: the column holding the currency.
	 *
	 * @var string
	 */
	public string $currency_field = 'currency';

	/**
	 * Uses another currency column.
	 *
	 * @param string $column Currency column.
	 * @return self
	 */
	public function in( string $column ): self {
		$this->currency_field = $column;
		return $this;
	}

	/**
	 * Creates a field of a type.
	 *
	 * @param string $type Field type.
	 */
	private function __construct( string $type ) {
		$this->type = $type;
	}

	/**
	 * Short text (varchar).
	 *
	 * @param int $max Max length.
	 * @return self
	 */
	public static function text( int $max = 190 ): self {
		$field      = new self( 'text' );
		$field->max = $max;
		return $field;
	}

	/**
	 * Long plain text.
	 *
	 * @return self
	 */
	public static function longtext(): self {
		$field      = new self( 'longtext' );
		$field->max = 65535;
		return $field;
	}

	/**
	 * Sanitised rich text (HTML allowlist).
	 *
	 * @return self
	 */
	public static function html(): self {
		$field      = new self( 'html' );
		$field->max = 1000000;
		return $field;
	}

	/**
	 * Email address.
	 *
	 * @return self
	 */
	public static function email(): self {
		return new self( 'email' );
	}

	/**
	 * URL.
	 *
	 * @return self
	 */
	public static function url(): self {
		$field      = new self( 'url' );
		$field->max = 2048;
		return $field;
	}

	/**
	 * Phone number.
	 *
	 * @return self
	 */
	public static function phone(): self {
		$field      = new self( 'phone' );
		$field->max = 40;
		return $field;
	}

	/**
	 * Hex colour.
	 *
	 * @return self
	 */
	public static function color(): self {
		$field      = new self( 'color' );
		$field->max = 9;
		return $field;
	}

	/**
	 * Integer.
	 *
	 * @return self
	 */
	public static function int(): self {
		return new self( 'int' );
	}

	/**
	 * Big integer (file sizes, counters beyond 2^31).
	 *
	 * @return self
	 */
	public static function bigint(): self {
		return new self( 'bigint' );
	}

	/**
	 * Decimal (quantities, hours, percentages — never money).
	 *
	 * @param int $scale Decimal places.
	 * @return self
	 */
	public static function decimal( int $scale = 4 ): self {
		$field        = new self( 'decimal' );
		$field->scale = $scale;
		return $field;
	}

	/**
	 * Money in integer minor units. Pair it with a currency field.
	 *
	 * @return self
	 */
	public static function money(): self {
		return new self( 'money' );
	}

	/**
	 * ISO 4217 currency code.
	 *
	 * @return self
	 */
	public static function currency(): self {
		$field      = new self( 'currency' );
		$field->max = 3;
		return $field;
	}

	/**
	 * Boolean.
	 *
	 * @return self
	 */
	public static function bool(): self {
		$field          = new self( 'bool' );
		$field->default = false;
		return $field;
	}

	/**
	 * Date only (no time zone).
	 *
	 * @return self
	 */
	public static function date(): self {
		return new self( 'date' );
	}

	/**
	 * A moment in time, stored in UTC.
	 *
	 * @return self
	 */
	public static function datetime(): self {
		return new self( 'datetime' );
	}

	/**
	 * One of a fixed set of values.
	 *
	 * @param string[] $options Allowed values.
	 * @return self
	 */
	public static function enum( array $options ): self {
		$field          = new self( 'enum' );
		$field->options = $options;
		$field->max     = 40;
		return $field;
	}

	/**
	 * A value from an editable list (taxonomy).
	 *
	 * @param string $list_key List key, e.g. "lead_status".
	 * @return self
	 */
	public static function listed( string $list_key ): self {
		$field       = new self( 'enum' );
		$field->list = $list_key;
		$field->max  = 60;
		return $field;
	}

	/**
	 * JSON document (content/layout/config only, never filtered on).
	 *
	 * @return self
	 */
	public static function json(): self {
		return new self( 'json' );
	}

	/**
	 * Reference to another entity (stored as internal id, exposed as uid).
	 *
	 * @param string $entity Referenced entity name.
	 * @return self
	 */
	public static function ref( string $entity ): self {
		$field      = new self( 'ref' );
		$field->ref = $entity;
		return $field;
	}

	/**
	 * Reference to a Cliezoo member (a WordPress user).
	 *
	 * @return self
	 */
	public static function user(): self {
		$field      = new self( 'user' );
		$field->ref = 'members';
		return $field;
	}

	/**
	 * Marks the field required.
	 *
	 * @return self
	 */
	public function required(): self {
		$this->required = true;
		return $this;
	}

	/**
	 * Sets the default.
	 *
	 * @param mixed $value Default value.
	 * @return self
	 */
	public function default( $value ): self {
		$this->default = $value;
		return $this;
	}

	/**
	 * Filterable + sortable (the common list combination).
	 *
	 * @return self
	 */
	public function indexed(): self {
		$this->filterable = true;
		$this->sortable   = true;
		return $this;
	}

	/**
	 * Filterable in lists.
	 *
	 * @return self
	 */
	public function filterable(): self {
		$this->filterable = true;
		return $this;
	}

	/**
	 * Sortable in lists.
	 *
	 * @return self
	 */
	public function sortable(): self {
		$this->sortable = true;
		return $this;
	}

	/**
	 * Included in search.
	 *
	 * @return self
	 */
	public function searchable(): self {
		$this->searchable = true;
		return $this;
	}

	/**
	 * Server-managed.
	 *
	 * @return self
	 */
	public function readonly(): self {
		$this->readonly = true;
		return $this;
	}

	/**
	 * Financial field (field-level privacy).
	 *
	 * @return self
	 */
	public function finance(): self {
		$this->finance = true;
		return $this;
	}

	/**
	 * Never exposed through REST.
	 *
	 * @return self
	 */
	public function hidden(): self {
		$this->hidden   = true;
		$this->readonly = true;
		return $this;
	}

	/**
	 * Numeric range.
	 *
	 * @param int|float|null $min Minimum.
	 * @param int|float|null $max Maximum.
	 * @return self
	 */
	public function range( $min, $max = null ): self {
		$this->min       = $min;
		$this->max_value = $max;
		return $this;
	}

	/**
	 * Human label.
	 *
	 * @param string $label Translated label.
	 * @return self
	 */
	public function label( string $label ): self {
		$this->label = $label;
		return $this;
	}

	/**
	 * SQL column definition for dbDelta (lower-case types, no backticks).
	 *
	 * @return string
	 */
	public function sql(): string {
		switch ( $this->type ) {
			case 'text':
			case 'email':
			case 'url':
			case 'phone':
			case 'color':
			case 'enum':
				$sql = $this->max > 255 ? 'text' : 'varchar(' . $this->max . ')';
				break;
			case 'currency':
				$sql = 'char(3)';
				break;
			case 'longtext':
			case 'html':
			case 'json':
				$sql = 'longtext';
				break;
			case 'int':
				$sql = 'int(11)';
				break;
			case 'decimal':
				$sql = 'decimal(18,' . $this->scale . ')';
				break;
			case 'money':
			case 'bigint':
				$sql = 'bigint(20)';
				break;
			case 'bool':
				$sql = 'tinyint(1)';
				break;
			case 'date':
				$sql = 'date';
				break;
			case 'datetime':
				$sql = 'datetime';
				break;
			case 'ref':
			case 'user':
				$sql = 'bigint(20) unsigned';
				break;
			default:
				$sql = 'varchar(190)';
		}
		$sql    .= $this->not_null() ? ' NOT NULL' : ' NULL';
		$default = $this->sql_default();
		if ( null !== $default ) {
			$sql .= ' DEFAULT ' . $default;
		}
		return $sql;
	}

	/**
	 * Whether the column is NOT NULL.
	 *
	 * @return bool
	 */
	public function not_null(): bool {
		return in_array( $this->type, array( 'bool', 'money' ), true ) || ( null !== $this->default && ! in_array( $this->type, array( 'longtext', 'html', 'json' ), true ) );
	}

	/**
	 * SQL DEFAULT literal, or null when none.
	 *
	 * @return string|null
	 */
	private function sql_default(): ?string {
		if ( in_array( $this->type, array( 'longtext', 'html', 'json' ), true ) ) {
			return null;
		}
		if ( 'bool' === $this->type ) {
			return $this->default ? '1' : '0';
		}
		if ( 'money' === $this->type ) {
			return (string) (int) ( $this->default ?? 0 );
		}
		if ( null === $this->default ) {
			return null;
		}
		if ( is_int( $this->default ) || is_float( $this->default ) ) {
			return (string) $this->default;
		}
		return "'" . esc_sql( (string) $this->default ) . "'";
	}

	/**
	 * JSON schema for REST validation.
	 *
	 * @return array<string, mixed>
	 */
	public function schema(): array {
		$schema = array();
		switch ( $this->type ) {
			case 'int':
			case 'bigint':
				$schema['type'] = 'integer';
				break;
			case 'money':
				// Money is read as `{ amount, currency }`, so it is written
				// that way too; an amount on its own is still accepted,
				// because that is what the column holds.
				$schema['type']                 = array( 'integer', 'object', 'null' );
				$schema['properties']           = array(
					'amount'   => array( 'type' => 'integer' ),
					'currency' => array(
						'type'    => 'string',
						'pattern' => '^[A-Z]{3}$',
					),
				);
				$schema['additionalProperties'] = false;
				break;
			case 'decimal':
				$schema['type'] = 'number';
				break;
			case 'bool':
				$schema['type'] = 'boolean';
				break;
			case 'json':
				$schema['type'] = array( 'object', 'array', 'null' );
				break;
			case 'date':
				$schema['type']   = 'string';
				$schema['format'] = 'date';
				break;
			case 'datetime':
				$schema['type']   = 'string';
				$schema['format'] = 'date-time';
				break;
			case 'email':
				$schema['type']   = 'string';
				$schema['format'] = 'email';
				break;
			case 'url':
				$schema['type']   = 'string';
				$schema['format'] = 'uri';
				break;
			case 'ref':
			case 'user':
				$schema['type']    = array( 'string', 'null' );
				$schema['pattern'] = '^[0-9A-HJKMNP-TV-Z]{26}$';
				break;
			default:
				$schema['type'] = 'string';
		}
		if ( in_array( $this->type, array( 'text', 'longtext', 'html', 'phone', 'color', 'enum', 'currency' ), true ) ) {
			$schema['maxLength'] = $this->max;
		}
		if ( 'enum' === $this->type && $this->options ) {
			$schema['enum'] = $this->options;
			// A form that offers "choose one" sends nothing back when nobody
			// chose — an empty string, or null — and refusing that would
			// make every optional enum look required. Either means the
			// field's own default, or nothing at all when it has none.
			if ( ! $this->required ) {
				$schema['enum'][] = '';
				$schema['enum'][] = null;
			}
		}
		if ( 'currency' === $this->type ) {
			$schema['pattern'] = '^[A-Z]{3}$';
		}
		if ( 'color' === $this->type ) {
			$schema['pattern'] = '^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$';
		}
		if ( null !== $this->min ) {
			$schema['minimum'] = $this->min;
		}
		if ( null !== $this->max_value ) {
			$schema['maximum'] = $this->max_value;
		}
		if ( ! $this->required && ! in_array( $this->type, array( 'ref', 'user', 'json' ), true ) && ! is_array( $schema['type'] ) ) {
			$schema['type'] = array( $schema['type'], 'null' );
		}
		if ( '' !== $this->label ) {
			$schema['description'] = $this->label;
		}
		if ( $this->readonly ) {
			$schema['readonly'] = true;
		}
		return $schema;
	}
}
