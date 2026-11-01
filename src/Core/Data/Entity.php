<?php
/**
 * Entity (table) definition.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares one custom table and how it is exposed.
 *
 * One definition drives the dbDelta schema, the REST/Ability JSON schemas,
 * the generic repository queries and the list metadata sent to the app, so
 * validation and permission rules are written once (Blueprint §8.4).
 */
final class Entity {

	/**
	 * Plural name, also the table suffix and REST base ("clients").
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * Table group prefix, e.g. "cliezoo_".
	 *
	 * @var string
	 */
	public string $prefix = 'cliezoo_';

	/**
	 * Fields keyed by column name (system columns excluded).
	 *
	 * @var array<string, Field>
	 */
	public array $fields = array();

	/**
	 * Whether other modules have been asked for their fields yet.
	 *
	 * @var bool
	 */
	private bool $extended = false;

	/**
	 * Secondary indexes: name => column list.
	 *
	 * @var array<string, string[]>
	 */
	public array $indexes = array();

	/**
	 * Unique indexes: name => column list.
	 *
	 * @var array<string, string[]>
	 */
	public array $unique = array();

	/**
	 * FULLTEXT indexes: name => column list.
	 *
	 * @var array<string, string[]>
	 */
	public array $fulltext = array();

	/**
	 * Numbering series key ("CLI", "INV"…); empty for none.
	 *
	 * @var string
	 */
	public string $series = '';

	/**
	 * Assign the number on create (true) or later by an action such as finalize (false).
	 *
	 * @var bool
	 */
	public bool $number_on_create = true;

	/**
	 * Field shown as the record title.
	 *
	 * @var string
	 */
	public string $title_field = 'name';

	/**
	 * Permission area in the role matrix ("clients", "invoices"…).
	 *
	 * @var string
	 */
	public string $permission = '';

	/**
	 * Column holding the owner member id, for "own" scope.
	 *
	 * @var string
	 */
	public string $owner_field = '';

	/**
	 * Column holding the client id, for client-portal scoping.
	 *
	 * @var string
	 */
	public string $client_field = '';

	/**
	 * Column holding the project id (access inheritance from projects).
	 *
	 * @var string
	 */
	public string $project_field = '';

	/**
	 * Column that must be true for clients to see a row (empty = rely on sharing).
	 *
	 * @var string
	 */
	public string $client_visible_field = '';

	/**
	 * Parent entity for object-access inheritance: [entity, column].
	 *
	 * @var array{0:string,1:string}|array{}
	 */
	public array $parent = array();

	/**
	 * Supports archive → trash → purge.
	 *
	 * @var bool
	 */
	public bool $soft_delete = true;

	/**
	 * Has a uid column and is exposed through REST.
	 *
	 * @var bool
	 */
	public bool $public = true;

	/**
	 * Supports per-object sharing (access table memberships).
	 *
	 * @var bool
	 */
	public bool $shareable = false;

	/**
	 * The record whose people also count as this one's, as [entity, column]
	 * — a file is shared with whoever its folder is shared with.
	 *
	 * @var array{0?: string, 1?: string}
	 */
	public array $member_via = array();

	/**
	 * Rows attach to another record through object_type/object_id and inherit
	 * its visibility (notes, to-dos, comments, public links).
	 *
	 * @var bool
	 */
	public bool $object_attached = false;

	/**
	 * Toggle permission needed to create (e.g. "create_boards"); empty = area scope only.
	 *
	 * @var string
	 */
	public string $create_toggle = '';

	/**
	 * Toggle permission needed to delete; empty = same as update.
	 *
	 * @var string
	 */
	public string $delete_toggle = '';

	/**
	 * Supports custom fields.
	 *
	 * @var bool
	 */
	public bool $custom_fields = false;

	/**
	 * Indexed by global search.
	 *
	 * @var bool
	 */
	public bool $searchable = false;

	/**
	 * Records activity (old → new) on changes.
	 *
	 * @var bool
	 */
	public bool $activity = true;

	/**
	 * Default sort ("-created_at").
	 *
	 * @var string
	 */
	public string $default_sort = '-created_at';

	/**
	 * Singular human label.
	 *
	 * @var string
	 */
	public string $label = '';

	/**
	 * Plural human label.
	 *
	 * @var string
	 */
	public string $plural_label = '';

	/**
	 * Owning module id.
	 *
	 * @var string
	 */
	public string $module = '';

	/**
	 * App path of a record below the app base, with {uid} (e.g. "clients/{uid}").
	 *
	 * @var string
	 */
	public string $path = '';

	/**
	 * Lucide icon name for chips, search and the Library.
	 *
	 * @var string
	 */
	public string $icon = '';

	/**
	 * Creates a definition.
	 *
	 * @param string               $name   Plural name.
	 * @param array<string, Field> $fields Fields.
	 */
	public function __construct( string $name, array $fields ) {
		$this->name = $name;
		foreach ( $fields as $column => $field ) {
			$field->name             = $column;
			$this->fields[ $column ] = $field;
		}
	}

	/**
	 * Sets several options fluently.
	 *
	 * @param array<string, mixed> $options Property => value.
	 * @return self
	 * @throws \InvalidArgumentException On unknown options.
	 */
	public function with( array $options ): self {
		foreach ( $options as $key => $value ) {
			if ( ! property_exists( $this, $key ) ) {
				throw new \InvalidArgumentException( esc_html( "Unknown entity option {$key}" ) );
			}
			$this->{$key} = $value;
		}
		return $this;
	}

	/**
	 * Adds the fields implied by options (sharing). Called by the registry.
	 *
	 * @return self
	 */
	public function finalize(): self {
		if ( ! $this->extended ) {
			$this->extended = true;
			/**
			 * Fields another module adds to this entity.
			 *
			 * A milestone is the boards module's business only because a
			 * task can be part of one, and boards does not depend on
			 * milestones. So the field is declared by whoever owns the
			 * idea, and switching that module off takes the field with it
			 * rather than leaving a reference pointing at nothing.
			 *
			 * Here rather than in the registry because the migrator builds
			 * entities straight from the module to make the tables: a field
			 * added in only one of those places is a column the code reads
			 * and the database has never heard of.
			 *
			 * @param array<string, Field> $fields The fields so far.
			 * @param Entity               $entity The entity.
			 */
			// Handed copies, not the fields themselves: a filter is meant
			// to add, and a filter given the live objects can quietly make
			// a read-only field writable or change a column's type with
			// the "only adds" guard never firing.
			$seen = array();
			foreach ( $this->fields as $column => $field ) {
				$seen[ $column ] = clone $field;
			}
			$added = (array) apply_filters( 'cliezoo_entity_fields', $seen, $this );
			foreach ( $added as $column => $field ) {
				if ( $field instanceof Field && ! isset( $this->fields[ $column ] ) ) {
					$field->name             = (string) $column;
					$this->fields[ $column ] = $field;
				}
			}
		}
		if ( $this->shareable && ! isset( $this->fields['access'] ) ) {
			$this->fields['access']            = Field::enum( array( 'inherit', 'private', 'clients', 'internal', 'public' ) )->default( 'inherit' )->filterable();
			$this->fields['access']->name      = 'access';
			$this->fields['access_role']       = Field::enum( array( 'admin', 'manager', 'creator', 'commenter', 'uploader', 'downloader', 'viewer' ) )->default( 'viewer' );
			$this->fields['access_role']->name = 'access_role';
		}
		return $this;
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . $this->prefix . $this->name;
	}

	/**
	 * Every column name including system columns.
	 *
	 * @return string[]
	 */
	public function columns(): array {
		$columns = array( 'id' );
		if ( $this->public ) {
			$columns[] = 'uid';
		}
		if ( '' !== $this->series ) {
			$columns[] = 'number';
		}
		$columns = array_merge( $columns, array_keys( $this->fields ), array( 'created_by', 'created_at', 'updated_at' ) );
		if ( $this->soft_delete ) {
			$columns[] = 'archived_at';
			$columns[] = 'deleted_at';
		}
		return $columns;
	}

	/**
	 * Whether a column exists.
	 *
	 * @param string $column Column name.
	 * @return bool
	 */
	public function has_column( string $column ): bool {
		return in_array( $column, $this->columns(), true );
	}

	/**
	 * A field by name.
	 *
	 * @param string $name Column.
	 * @return Field|null
	 */
	public function field( string $name ): ?Field {
		return $this->fields[ $name ] ?? null;
	}

	/**
	 * CREATE TABLE statement for dbDelta.
	 *
	 * Rules for dbDelta: one field per line, two spaces after PRIMARY KEY,
	 * KEY (not INDEX), lower-case types, no backticks.
	 *
	 * @param string $charset_collate Charset clause.
	 * @return string
	 */
	public function create_sql( string $charset_collate ): string {
		$lines   = array();
		$lines[] = 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT';
		if ( $this->public ) {
			$lines[] = 'uid char(26) NOT NULL';
		}
		if ( '' !== $this->series ) {
			$lines[] = 'number varchar(40) NULL';
		}
		foreach ( $this->fields as $column => $field ) {
			$lines[] = $column . ' ' . $field->sql();
		}
		$lines[] = 'created_by bigint(20) unsigned NULL';
		$lines[] = 'created_at datetime NOT NULL';
		$lines[] = 'updated_at datetime NOT NULL';
		if ( $this->soft_delete ) {
			$lines[] = 'archived_at datetime NULL';
			$lines[] = 'deleted_at datetime NULL';
		}
		$lines[] = 'PRIMARY KEY  (id)';
		if ( $this->public ) {
			$lines[] = 'UNIQUE KEY uid (uid)';
		}
		if ( '' !== $this->series ) {
			$lines[] = 'UNIQUE KEY number (number)';
		}
		foreach ( $this->unique as $index => $columns ) {
			$lines[] = 'UNIQUE KEY ' . $index . ' (' . implode( ',', $columns ) . ')';
		}
		foreach ( $this->key_indexes() as $index => $columns ) {
			$lines[] = 'KEY ' . $index . ' (' . implode( ',', $columns ) . ')';
		}
		foreach ( $this->fulltext as $index => $columns ) {
			$lines[] = 'FULLTEXT KEY ' . $index . ' (' . implode( ',', $columns ) . ')';
		}
		return 'CREATE TABLE ' . $this->table() . " (\n" . implode( ",\n", $lines ) . "\n) " . $charset_collate . ';';
	}

	/**
	 * Secondary indexes including automatic ones for references and soft delete.
	 *
	 * @return array<string, string[]>
	 */
	public function key_indexes(): array {
		$indexes = $this->indexes;
		$covered = array();
		foreach ( $indexes as $columns ) {
			$covered[] = $columns[0];
		}
		foreach ( array_merge( $this->unique ) as $columns ) {
			$covered[] = $columns[0];
		}
		foreach ( $this->fields as $column => $field ) {
			if ( in_array( $field->type, array( 'ref', 'user' ), true ) && ! in_array( $column, $covered, true ) ) {
				$indexes[ $column ] = array( $column );
			}
		}
		if ( $this->soft_delete && ! isset( $indexes['deleted_at'] ) ) {
			$indexes['deleted_at'] = array( 'deleted_at' );
		}
		if ( ! isset( $indexes['created_at'] ) ) {
			$indexes['created_at'] = array( 'created_at' );
		}
		return $indexes;
	}

	/**
	 * JSON schema of the writable input.
	 *
	 * @param bool $for_create Whether required fields are enforced.
	 * @return array<string, mixed>
	 */
	public function input_schema( bool $for_create ): array {
		$properties = array();
		$required   = array();
		foreach ( $this->fields as $column => $field ) {
			if ( $field->readonly ) {
				continue;
			}
			$key                = $this->input_key( $column );
			$properties[ $key ] = $field->schema();
			if ( $for_create && $field->required ) {
				$required[] = $key;
			}
		}
		if ( $this->custom_fields ) {
			$properties['custom'] = array(
				'type'                 => 'object',
				'additionalProperties' => true,
			);
		}
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => $this->name,
			'type'       => 'object',
			'properties' => $properties,
		);
		if ( $required ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * REST key for a column: references drop the "_id" suffix ("client_id" → "client").
	 *
	 * @param string $column Column name.
	 * @return string
	 */
	public function input_key( string $column ): string {
		$field = $this->fields[ $column ] ?? null;
		if ( $field && in_array( $field->type, array( 'ref', 'user' ), true ) && str_ends_with( $column, '_id' ) ) {
			return substr( $column, 0, -3 );
		}
		return $column;
	}

	/**
	 * Column for a REST key (inverse of input_key()).
	 *
	 * @param string $key REST key.
	 * @return string|null
	 */
	public function column_for_key( string $key ): ?string {
		if ( isset( $this->fields[ $key ] ) ) {
			return $key;
		}
		if ( isset( $this->fields[ $key . '_id' ] ) && in_array( $this->fields[ $key . '_id' ]->type, array( 'ref', 'user' ), true ) ) {
			return $key . '_id';
		}
		return null;
	}
}
