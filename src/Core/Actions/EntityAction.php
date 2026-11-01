<?php
/**
 * Generic record action (list, get, create, update, trash…).
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\Actions;

use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\EntityService;
use Cliezoo\Core\Data\FilterCompiler;
use Cliezoo\Core\Data\ListQuery;
use Cliezoo\Platform\Files\OwnerGone;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * One generic operation on an entity, so every table gets the same REST,
 * Ability and MCP surface without per-module boilerplate.
 */
final class EntityAction extends Action {

	public const VERBS = array( 'list', 'get', 'create', 'update', 'trash', 'archive', 'unarchive', 'restore', 'purge', 'empty_trash', 'bulk' );

	/**
	 * Verb.
	 *
	 * @var string
	 */
	private string $verb;

	/**
	 * Service provider (lazy).
	 *
	 * @var callable
	 */
	private $service;

	/**
	 * Entity.
	 *
	 * @var Entity
	 */
	private Entity $entity;

	/**
	 * Extra metadata overrides (e.g. MCP class).
	 *
	 * @var array<string, mixed>
	 */
	private array $extra;

	/**
	 * Constructor.
	 *
	 * @param Entity               $entity  Entity.
	 * @param string               $verb    Verb.
	 * @param callable             $service fn(): EntityService.
	 * @param array<string, mixed> $extra   Metadata overrides.
	 */
	public function __construct( Entity $entity, string $verb, callable $service, array $extra = array() ) {
		$this->entity  = $entity;
		$this->verb    = $verb;
		$this->service = $service;
		$this->extra   = $extra;
	}

	/**
	 * Service.
	 *
	 * @return EntityService
	 */
	private function service(): EntityService {
		return ( $this->service )();
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return $this->entity->name . '.' . $this->verb;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		$plural   = '' !== $this->entity->plural_label ? $this->entity->plural_label : $this->entity->name;
		$singular = '' !== $this->entity->label ? $this->entity->label : $this->entity->name;
		switch ( $this->verb ) {
			case 'list':
				/* translators: %s: plural record type. */
				return sprintf( __( 'List %s', 'cliezoo' ), $plural );
			case 'get':
				/* translators: %s: record type. */
				return sprintf( __( 'Get a %s', 'cliezoo' ), $singular );
			case 'create':
				/* translators: %s: record type. */
				return sprintf( __( 'Create a %s', 'cliezoo' ), $singular );
			case 'update':
				/* translators: %s: record type. */
				return sprintf( __( 'Update a %s', 'cliezoo' ), $singular );
			case 'trash':
				/* translators: %s: record type. */
				return sprintf( __( 'Move a %s to the trash', 'cliezoo' ), $singular );
			case 'archive':
				/* translators: %s: record type. */
				return sprintf( __( 'Archive a %s', 'cliezoo' ), $singular );
			case 'unarchive':
				/* translators: %s: record type. */
				return sprintf( __( 'Unarchive a %s', 'cliezoo' ), $singular );
			case 'restore':
				/* translators: %s: record type. */
				return sprintf( __( 'Restore a %s from the trash', 'cliezoo' ), $singular );
			case 'purge':
				/* translators: %s: record type. */
				return sprintf( __( 'Permanently delete a %s', 'cliezoo' ), $singular );
			case 'empty_trash':
				/* translators: %s: plural record type. */
				return sprintf( __( 'Empty the trash of %s', 'cliezoo' ), $plural );
			default:
				/* translators: %s: plural record type. */
				return sprintf( __( 'Bulk update %s', 'cliezoo' ), $plural );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$uid = array(
			'type'     => 'string',
			'pattern'  => '^[0-9A-HJKMNP-TV-Z]{26}$',
			'required' => true,
		);
		switch ( $this->verb ) {
			case 'list':
				return array(
					'type'       => 'object',
					'properties' => array(
						'filter'     => array( 'type' => array( 'object', 'string' ) ),
						'q'          => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						// Up to three fields, comma-separated: "status,-due_date".
						'sort'       => array(
							'type'      => 'string',
							'maxLength' => 150,
						),
						'cursor'     => array(
							'type'      => 'string',
							'maxLength' => 500,
						),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
							'default' => 50,
						),
						'state'      => array(
							'type' => 'string',
							'enum' => array( 'active', 'archived', 'trash', 'all' ),
						),
						'fields'     => array(
							'type'        => 'string',
							'maxLength'   => 500,
							'description' => 'Comma-separated fields to return. An agent gets a summary of each record by default; "*" returns the whole record.',
						),
						'with_total' => array( 'type' => 'boolean' ),
					),
				);
			case 'create':
				return $this->entity->input_schema( true );
			case 'update':
				$schema                           = $this->entity->input_schema( false );
				$schema['properties']['uid']      = $uid;
				$schema['properties']['if_match'] = array(
					'type'      => 'string',
					'maxLength' => 64,
				);
				return $schema;
			case 'bulk':
				return array(
					'type'       => 'object',
					'properties' => array(
						'uids'      => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'string',
								'pattern' => '^[0-9A-HJKMNP-TV-Z]{26}$',
							),
							'minItems' => 1,
							'maxItems' => 200,
							'required' => true,
						),
						'operation' => array(
							'type'     => 'string',
							'enum'     => array( 'update', 'tag', 'send', 'trash', 'archive', 'unarchive', 'restore', 'purge' ),
							'required' => true,
						),
						'changes'   => array( 'type' => 'object' ),
						// For "send": the one message everybody gets.
						'subject'   => array(
							'type'      => 'string',
							'maxLength' => 190,
						),
						'body'      => array(
							'type'      => 'string',
							'maxLength' => 5000,
						),
						// For "tag": words added to, and taken from, each
						// record's own tags. Setting one whole list on every
						// row would wipe the tags each already had.
						'add'       => self::tag_list(),
						'remove'    => self::tag_list(),
						// For "purge" of clients and projects: their files.
						'files'     => self::files_choice(),
					),
				);
			case 'empty_trash':
				// The trash of this entity is the whole argument, and for
				// clients and projects what becomes of their files.
				return array(
					'type'       => 'object',
					'properties' => array( 'files' => self::files_choice() ),
				);
			case 'purge':
				return array(
					'type'       => 'object',
					'properties' => array(
						'uid'   => $uid,
						'files' => self::files_choice(),
					),
				);
			default:
				return array(
					'type'       => 'object',
					'properties' => array( 'uid' => $uid ),
				);
		}
	}

	/**
	 * What becomes of a client's or project's files when it is deleted for
	 * good (OwnerGone): kept in the library, archived, or trashed.
	 *
	 * @return array<string, mixed>
	 */
	private static function files_choice(): array {
		return array(
			'type' => 'string',
			'enum' => OwnerGone::CHOICES,
		);
	}

	/**
	 * Type-level permission check (row checks happen in the service).
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $input Input.
	 * @return bool
	 */
	public function permission( Actor $actor, array $input ): bool {
		$policy = cliezoo()->policy();
		switch ( $this->verb ) {
			case 'create':
				return $policy->can( $actor, Policy::CREATE, $this->entity );
			case 'purge':
			case 'empty_trash':
				return $actor->is_admin() || $actor->is_system();
			case 'bulk':
				// Bulk takes an operation, and one of them is purge. Answering
				// VIEW here was safe at runtime — the service loads with
				// Policy::PURGE and refuses — but this callback is published as
				// an ability, so wp_get_ability( 'cliezoo/clients-bulk' ) told a
				// read-only member they were permitted to destroy records.
				// Permanent deletion is kept off the Abilities API everywhere
				// else by ability => false, and this handed it back through the
				// front door.
				if ( 'purge' === (string) ( $input['operation'] ?? '' ) ) {
					return $actor->is_admin() || $actor->is_system();
				}
				return $policy->can( $actor, Policy::VIEW, $this->entity );
			default:
				// Row-level checks happen in the service (unknown rows answer "not found").
				return $policy->can( $actor, Policy::VIEW, $this->entity );
		}
	}

	/**
	 * Runs the operation.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $input Validated input.
	 * @return mixed
	 * @throws ActionError When the verb is unknown or the operation fails.
	 */
	public function execute( Actor $actor, array $input ) {
		$service = $this->service();
		switch ( $this->verb ) {
			case 'list':
				$fields = self::fields( $input );
				// An agent reading a list gets each record's summary unless it
				// asks for more: the fields it can find and sort by, and the
				// name — not every long text and setting, which is more than
				// an agent needs and more than it should carry around. "*"
				// asks for the whole record, as the app reads it.
				if ( ! $fields && 'ability' === $actor->channel ) {
					$fields = $this->summary();
				}
				return $service->list( $actor, self::list_query( $input ), array( '*' ) === $fields ? array() : $fields );
			case 'get':
				return $service->get( $actor, (string) $input['uid'], true );
			case 'create':
				return $service->create( $actor, $input );
			case 'update':
				$uid      = (string) $input['uid'];
				$if_match = (string) ( $input['if_match'] ?? '' );
				unset( $input['uid'], $input['if_match'] );
				return $service->update( $actor, $uid, $input, $if_match );
			case 'trash':
				return $service->trash( $actor, (string) $input['uid'] );
			case 'archive':
				return $service->archive( $actor, (string) $input['uid'], true );
			case 'unarchive':
				return $service->archive( $actor, (string) $input['uid'], false );
			case 'restore':
				return $service->restore( $actor, (string) $input['uid'] );
			case 'purge':
				return OwnerGone::with( (string) ( $input['files'] ?? 'keep' ), static fn() => $service->purge( $actor, (string) $input['uid'] ) );
			case 'empty_trash':
				return OwnerGone::with( (string) ( $input['files'] ?? 'keep' ), static fn() => $service->empty_trash( $actor ) );
			case 'bulk':
				return OwnerGone::with( (string) ( $input['files'] ?? 'keep' ), fn() => $this->bulk( $service, $actor, $input ) );
		}
		throw ActionError::not_found();
	}

	/**
	 * Applies one operation to many records; reports per-record results.
	 *
	 * @param EntityService        $service Service.
	 * @param Actor                $actor   Actor.
	 * @param array<string, mixed> $input   Input.
	 * @return array{done: string[], failed: array<string, string>}
	 */
	private function bulk( EntityService $service, Actor $actor, array $input ): array {
		$done    = array();
		$failed  = array();
		$changes = (array) ( $input['changes'] ?? array() );
		if ( 'tag' === $input['operation'] ) {
			return $this->bulk_tag( $service, $actor, $input );
		}
		if ( 'send' === $input['operation'] ) {
			return $this->bulk_send( $service, $actor, $input );
		}
		if ( 'update' === $input['operation'] ) {
			$uids    = (array) $input['uids'];
			$changes = cliezoo()->actions()->validate( new self( $this->entity, 'update', $this->service ), array( 'uid' => (string) reset( $uids ) ) + $changes );
			unset( $changes['uid'], $changes['if_match'] );
		}
		foreach ( array_unique( (array) $input['uids'] ) as $uid ) {
			try {
				switch ( $input['operation'] ) {
					case 'update':
						$service->update( $actor, $uid, $changes );
						break;
					case 'trash':
						$service->trash( $actor, $uid );
						break;
					case 'archive':
						$service->archive( $actor, $uid, true );
						break;
					case 'unarchive':
						$service->archive( $actor, $uid, false );
						break;
					case 'restore':
						$service->restore( $actor, $uid );
						break;
					case 'purge':
						$service->purge( $actor, $uid );
						break;
				}
				$done[] = $uid;
			} catch ( ActionError $error ) {
				$failed[ $uid ] = $error->getMessage();
			}
		}
		return array(
			'done'   => $done,
			'failed' => $failed,
		);
	}

	/**
	 * One message to everybody who was ticked.
	 *
	 * The message goes to the address each record keeps, through the
	 * same queue every other email uses — so the send log, the quiet
	 * hours and the site's own mail settings all apply to it. A row
	 * with nobody to write to is reported rather than skipped: "42
	 * sent" when four had no address is how a list loses trust.
	 *
	 * @param EntityService        $service The service.
	 * @param Actor                $actor   Who is sending.
	 * @param array<string, mixed> $input   uids, subject, body.
	 * @return array{done: string[], failed: array<string, string>}
	 * @throws ActionError When there is no subject or nothing to say.
	 */
	private function bulk_send( EntityService $service, Actor $actor, array $input ): array {
		$done    = array();
		$failed  = array();
		$subject = trim( (string) ( $input['subject'] ?? '' ) );
		$body    = trim( (string) ( $input['body'] ?? '' ) );
		if ( '' === $subject || '' === $body ) {
			throw ActionError::validation( __( 'A message needs something to say, and a subject to say it under.', 'cliezoo' ) );
		}
		$mailer = cliezoo()->get( \Cliezoo\Infrastructure\Mail\Mailer::class );
		foreach ( array_unique( (array) $input['uids'] ) as $uid ) {
			try {
				$row = $service->load( $actor, (string) $uid, Policy::VIEW );
				$to  = trim( (string) ( $row['email'] ?? '' ) );
				if ( '' === $to || ! is_email( $to ) ) {
					$failed[ $uid ] = __( 'No email address.', 'cliezoo' );
					continue;
				}
				// Each one addressed by name: {{name}} and {{first_name}} are
				// this recipient's, so one message reads as written to them.
				$name  = trim( (string) ( $row[ $this->entity->title_field ] ?? '' ) );
				$first = '' !== $name ? (string) strtok( $name, ' ' ) : '';
				$merge = array(
					'{{name}}'       => $name,
					'{{first_name}}' => $first,
				);
				// The notification template, with this one message put in
				// its place: the branding, the layout and the send log are
				// the ones every other email uses.
				$mailer->queue(
					'notification',
					$to,
					array(
						'title' => strtr( $subject, $merge ),
						'body'  => strtr( $body, $merge ),
						'name'  => $name,
					),
					array(
						'override_subject' => strtr( $subject, $merge ),
						'entity'           => $this->entity->name,
						'uid'              => (string) $uid,
					)
				);
				$done[] = (string) $uid;
			} catch ( ActionError $error ) {
				$failed[ $uid ] = $error->getMessage();
			}
		}
		return array(
			'done'   => $done,
			'failed' => $failed,
		);
	}

	/**
	 * Schema of a list of tags given to a bulk "tag".
	 *
	 * @return array<string, mixed>
	 */
	private static function tag_list(): array {
		return array(
			'type'     => 'array',
			'maxItems' => 20,
			'items'    => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 60,
			),
		);
	}

	/**
	 * Adds tags to, and takes tags from, many records at once, each keeping
	 * the tags it had. Every row is loaded with the right to change it and
	 * saved through the ordinary update, so a record the person may only
	 * look at is reported as failed, never quietly changed.
	 *
	 * @param EntityService        $service Service.
	 * @param Actor                $actor   Actor.
	 * @param array<string, mixed> $input   uids, add, remove.
	 * @return array{done: string[], failed: array<string, string>}
	 * @throws ActionError When this kind of record has no tags, or nothing was asked.
	 */
	private function bulk_tag( EntityService $service, Actor $actor, array $input ): array {
		if ( ! $this->entity->has_column( 'tags' ) ) {
			throw ActionError::validation( __( 'These records have no tags.', 'cliezoo' ) );
		}
		$clean  = static fn( $tags ): array => array_values( array_unique( array_filter( array_map( static fn( $tag ): string => trim( sanitize_text_field( (string) $tag ) ), (array) $tags ), static fn( string $tag ): bool => '' !== $tag ) ) );
		$add    = $clean( $input['add'] ?? array() );
		$remove = array_map( 'mb_strtolower', $clean( $input['remove'] ?? array() ) );
		if ( ! $add && ! $remove ) {
			throw ActionError::validation( __( 'Say which tags to add or remove.', 'cliezoo' ) );
		}
		$done   = array();
		$failed = array();
		foreach ( array_unique( (array) $input['uids'] ) as $uid ) {
			try {
				$row  = $service->load( $actor, (string) $uid, Policy::UPDATE );
				$have = is_string( $row['tags'] ?? null ) ? json_decode( (string) $row['tags'], true ) : ( $row['tags'] ?? array() );
				$have = $clean( is_array( $have ) ? $have : array() );
				$next = array_values(
					array_filter(
						$have,
						static fn( string $tag ): bool => ! in_array( mb_strtolower( $tag ), $remove, true )
					)
				);
				// Added as written, unless the record already has the word
				// in some other case.
				$lower = array_map( 'mb_strtolower', $next );
				foreach ( $add as $tag ) {
					if ( ! in_array( mb_strtolower( $tag ), $lower, true ) ) {
						$next[]  = $tag;
						$lower[] = mb_strtolower( $tag );
					}
				}
				if ( $next !== $have ) {
					$service->update_row( $actor, $row, array( 'tags' => $next ) );
				}
				$done[] = (string) $uid;
			} catch ( ActionError $error ) {
				$failed[ (string) $uid ] = $error->getMessage();
			}
		}
		return array(
			'done'   => $done,
			'failed' => $failed,
		);
	}

	/**
	 * Builds a ListQuery from input.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return ListQuery
	 */
	public static function list_query( array $input ): ListQuery {
		$query  = new ListQuery();
		$filter = $input['filter'] ?? array();
		if ( is_string( $filter ) && '' !== $filter ) {
			$filter = json_decode( $filter, true );
		}
		$query->filter     = is_array( $filter ) ? $filter : array();
		$query->search     = (string) ( $input['q'] ?? '' );
		$query->sort       = (string) ( $input['sort'] ?? '' );
		$query->cursor     = (string) ( $input['cursor'] ?? '' );
		$query->limit      = (int) ( $input['limit'] ?? 50 );
		$query->state      = (string) ( $input['state'] ?? 'active' );
		$query->with_total = ! empty( $input['with_total'] );
		return $query;
	}

	/**
	 * The fields a record is summed up by, for an agent's list.
	 *
	 * @return string[]
	 */
	public function summary(): array {
		$out = array();
		foreach ( $this->entity->fields as $column => $field ) {
			if ( $field->hidden || in_array( $field->type, array( 'longtext', 'html', 'json' ), true ) ) {
				continue;
			}
			if ( $field->filterable || $field->sortable || $column === $this->entity->title_field || in_array( $column, array( 'status', 'created_at', 'updated_at' ), true ) ) {
				$out[] = $this->entity->input_key( $column );
			}
		}
		return $out;
	}

	/**
	 * Sparse fieldset from input.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return string[]
	 */
	public static function fields( array $input ): array {
		$fields = (string) ( $input['fields'] ?? '' );
		return '' === $fields ? array() : array_values( array_filter( array_map( 'trim', explode( ',', $fields ) ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function meta(): array {
		$readonly = in_array( $this->verb, array( 'list', 'get' ), true );
		$base     = '/' . $this->entity->name;
		$routes   = array(
			'list'        => array( 'GET', $base ),
			'create'      => array( 'POST', $base ),
			'get'         => array( 'GET', $base . '/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})' ),
			'update'      => array( 'PATCH', $base . '/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})' ),
			'trash'       => array( 'DELETE', $base . '/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})' ),
			'archive'     => array( 'POST', $base . '/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/archive' ),
			'unarchive'   => array( 'POST', $base . '/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/unarchive' ),
			'restore'     => array( 'POST', $base . '/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/restore' ),
			'purge'       => array( 'POST', $base . '/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/purge' ),
			'empty_trash' => array( 'POST', $base . '/empty-trash' ),
			'bulk'        => array( 'POST', $base . '/bulk' ),
		);
		return array_merge(
			array(
				'readonly'    => $readonly,
				'destructive' => in_array( $this->verb, array( 'trash', 'purge', 'empty_trash', 'bulk' ), true ),
				'idempotent'  => ! in_array( $this->verb, array( 'create', 'bulk', 'empty_trash' ), true ),
				'rest'        => $routes[ $this->verb ],
				// Permanent deletion is never exposed to external agents (MCP plan §4.4).
				'ability'     => ! in_array( $this->verb, array( 'purge', 'empty_trash' ), true ),
			),
			$this->extra
		);
	}

	/**
	 * Operators accepted in filters (for docs).
	 *
	 * @return string[]
	 */
	public static function operators(): array {
		return FilterCompiler::OPERATORS;
	}
}
