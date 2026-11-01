<?php
/**
 * Somebody who might become a client, and what is known about them.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Leads;

use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\Field;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A lead is not a client yet, and the difference matters.
 *
 * Both competitors make new work a client from the first email, so a
 * studio's client list fills with people who never bought anything: every
 * count, every report and every "who have we not spoken to" list is
 * wrong, and the way to fix it is to delete a record that also holds the
 * only history of the conversation.
 *
 * So a lead is its own record. It holds an email and a phone rather than
 * a billing address, a budget rather than a rate, and a status that says
 * how the conversation is going rather than how the work is going. It
 * becomes a client — with its contact, its history and its notes carried
 * across — in one press, and what it was is kept, because "we won this in
 * March and it came from a referral" is a fact about the business and not
 * a fact about the lead record.
 *
 * Nothing here scores a lead with a model. The score is arithmetic a
 * studio can read and change: nothing is scored
 * by a service elsewhere.
 */
final class LeadsModule extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'leads';
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependencies(): array {
		return array( 'clients' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function switchable(): bool {
		return true;
	}

	/**
	 * How a lead arrived.
	 *
	 * Not the same question as its source: the source is the marketing
	 * answer ("a referral"), this is the mechanical one ("through the
	 * form"), and a studio wanting to know whether its form works needs
	 * the second rather than the first.
	 *
	 * @return array<int, string>
	 */
	public static function origins(): array {
		return array( 'manual', 'form', 'booking', 'email', 'import', 'api', 'portal' );
	}

	/**
	 * How warm it is.
	 *
	 * Kept as a number rather than Hot/Warm/Cold so it sorts, and shown as
	 * stars, which is how everybody who has used a CRM expects to set it.
	 *
	 * @return array<int, string>
	 */
	public static function ratings(): array {
		return array( '0', '1', '2', '3', '4', '5' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function entities(): array {
		return array(
			( new Entity(
				'leads',
				array(
					// Who it is. A name is all that is ever required,
					// because somebody phoning to ask about a job has not
					// given their email yet and refusing to write them
					// down is how they are forgotten.
					'name'          => Field::text( 190 )->required()->searchable()->sortable()->label( __( 'Name', 'cliezoo' ) ),
					'email'         => Field::email()->searchable()->filterable()->label( __( 'Email', 'cliezoo' ) ),
					'phone'         => Field::phone()->searchable()->label( __( 'Phone', 'cliezoo' ) ),
					'business_name' => Field::text( 190 )->searchable()->label( __( 'Business', 'cliezoo' ) ),
					'website'       => Field::url()->searchable()->label( __( 'Website', 'cliezoo' ) ),

					// What it might be worth. A budget is what they said,
					// not what anybody has agreed, so it is never money
					// that reaches a report as revenue.
					'currency'      => Field::currency()->filterable(),
					'budget'        => Field::money()->in( 'currency' )->sortable()->label( __( 'Budget', 'cliezoo' ) ),

					// Both lists a workspace edits. The source is shared
					// with deals so the question "where does our work come
					// from" can be answered across the join.
					'source'        => Field::listed( 'lead_source' )->filterable()->label( __( 'Source', 'cliezoo' ) ),
					'status'        => Field::listed( 'lead_status' )->filterable()->sortable()->label( __( 'Status', 'cliezoo' ) ),
					'rating'        => Field::enum( self::ratings() )->default( '0' )->filterable()->sortable()->label( __( 'Rating', 'cliezoo' ) ),

					// Worked out from the fields on the way in to every
					// write, so it cannot be typed and cannot disagree
					// with what it is made of.
					'score'         => Field::int()->readonly()->filterable()->sortable()->label( __( 'Score', 'cliezoo' ) ),

					'owner_id'      => Field::ref( 'members' )->filterable()->label( __( 'Owner', 'cliezoo' ) ),
					'origin'        => Field::enum( self::origins() )->default( 'manual' )->filterable()->label( __( 'Came in by', 'cliezoo' ) ),
					'tags'          => Field::json()->label( __( 'Tags', 'cliezoo' ) ),
					'notes'         => Field::longtext()->searchable()->label( __( 'Notes', 'cliezoo' ) ),

					// What became of it. Kept on the lead rather than only
					// on the client, because "how many of the leads from
					// that campaign turned into work" is a question about
					// the leads.
					'client_id'     => Field::ref( 'clients' )->readonly()->filterable()->label( __( 'Became', 'cliezoo' ) ),
					'contact_id'    => Field::ref( 'contacts' )->readonly()->label( __( 'Their contact', 'cliezoo' ) ),
					'converted_at'  => Field::datetime()->readonly()->sortable()->label( __( 'Converted', 'cliezoo' ) ),
					'lost_reason'   => Field::text( 190 )->label( __( 'Why it was lost', 'cliezoo' ) ),

					// So a studio can see who has gone quiet, which is the
					// only thing a lead list is really for.
					'last_touch_at' => Field::datetime()->filterable()->sortable()->label( __( 'Last spoken to', 'cliezoo' ) ),
				)
			) )->with(
				array(
					'permission'    => 'leads',
					'owner_field'   => 'owner_id',
					'series'        => 'LEAD',
					'searchable'    => true,
					'custom_fields' => true,
					'title_field'   => 'name',
					'default_sort'  => '-created_at',
					'path'          => 'leads/{uid}',
					'icon'          => 'user-plus',
					'indexes'       => array(
						'owner'  => array( 'owner_id', 'status' ),
						'status' => array( 'status', 'created_at' ),
						'source' => array( 'source', 'created_at' ),
						'became' => array( 'client_id' ),
						'quiet'  => array( 'last_touch_at' ),
					),
					'fulltext'      => array(
						'search' => array( 'name', 'email', 'business_name', 'notes' ),
					),
					'label'         => __( 'Lead', 'cliezoo' ),
					'plural_label'  => __( 'Leads', 'cliezoo' ),
				)
			),
		);
	}

	/**
	 * What a workspace can set about its leads.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return array(
			// Which status a lead starts in. A list somebody edits cannot
			// have its first value assumed, so it is said here.
			'default_status'    => array(
				'type'    => 'string',
				'default' => 'new',
			),
			// Whether a second lead with an address somebody already has
			// is refused, or written down with a warning. Refusing by
			// default, because two rows for one conversation is how a
			// client gets chased twice by two people.
			'refuse_duplicates' => array(
				'type'    => 'boolean',
				'default' => true,
			),
			// After how many days without a word a lead is counted as gone
			// quiet. Nought turns it off.
			'quiet_after_days'  => array(
				'type'    => 'integer',
				'default' => 14,
			),
			// What the score is made of. Said out loud rather than hidden
			// in code, because a studio that sells one thing to one kind
			// of buyer knows better than any default what a good lead
			// looks like.
			'score_weights'     => array(
				'type'                 => 'object',
				'additionalProperties' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100,
				),
				'default'              => array(
					'has_email'    => 10,
					'has_phone'    => 10,
					'has_business' => 10,
					'has_website'  => 5,
					'has_budget'   => 25,
					'rating'       => 8,
					'source'       => 10,
					'touched'      => 10,
				),
			),
			// The sources worth the extra points above. Empty means none
			// of them are, which is the honest default: nobody else can
			// know which of a studio's sources is the good one.
			'strong_sources'    => array(
				'type'     => 'array',
				'maxItems' => 50,
				'items'    => array(
					'type'      => 'string',
					'maxLength' => 60,
				),
				'default'  => array(),
			),
		);
	}

	/**
	 * Registers the service, its lists, settings and actions.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( Leads::class, static fn(): Leads => new Leads( $plugin ) );
		$plugin->container()->set( LeadIntake::class, static fn(): LeadIntake => new LeadIntake( $plugin ) );
		add_action(
			'cliezoo_register_routes',
			static function ( \Cliezoo\Core\Rest\RouteRegistry $routes ) use ( $plugin ): void {
				$plugin->get( LeadIntake::class )->routes( $routes );
			}
		);

		add_filter( 'cliezoo_lists', array( Lists::class, 'declare_lists' ) );

		// A lead is chased from the day it was last spoken to — "a week after
		// the last word, if nobody has said another" — until it is decided.
		add_action(
			'cliezoo_register_reminder_targets',
			static function ( $targets ): void {
				$targets->add(
					'leads',
					array(
						'label'      => __( 'Leads', 'cliezoo' ),
						'dates'      => array(
							'last_touch_at' => __( 'Last spoken to', 'cliezoo' ),
							'created_at'    => __( 'Came in', 'cliezoo' ),
						),
						'stop'       => array( 'won', 'lost', 'unqualified' ),
						'status'     => 'status',
						'roles'      => array(
							'owner' => __( 'Whoever owns the lead', 'cliezoo' ),
						),
						'recipients' => static fn( array $row ): array => array(
							'members' => ! empty( $row['owner_id'] ) ? array( (int) $row['owner_id'] ) : array(),
						),
					)
				);
			}
		);

		// The month-on-month cards: how many enquiries came in, and what the
		// people behind them said they had to spend. A budget is what a lead
		// says, not what anybody has agreed, and the label says so.
		add_filter(
			'cliezoo_dashboard_metrics',
			static function ( array $metrics ) use ( $plugin ): array {
				if ( ! $plugin->entities()->has( 'leads' ) ) {
					return $metrics;
				}
				$numbers = static fn(): \Cliezoo\Platform\Dashboard\Metrics => $plugin->get( \Cliezoo\Platform\Dashboard\Metrics::class );
				$drill   = array(
					'path'   => '/leads',
					'filter' => array(
						'and' => array(
							array(
								'field' => 'created_at',
								'op'    => 'gte',
								'value' => '@from',
							),
							array(
								'field' => 'created_at',
								'op'    => 'lt',
								'value' => '@to',
							),
						),
					),
				);

				$metrics['leads.new']   = array(
					'label'  => __( 'New leads', 'cliezoo' ),
					'group'  => __( 'Sales', 'cliezoo' ),
					'ranged' => true,
					'count'  => static fn( Actor $actor, string $from, string $to ): ?int => $numbers()->count( $actor, 'leads', 't.created_at >= %s AND t.created_at < %s', array( $from, $to ) ),
					'drill'  => $drill,
				);
				$metrics['leads.worth'] = array(
					'label'  => sprintf(
						/* translators: %s: a currency code, such as USD. */
						__( 'Leads worth (%s)', 'cliezoo' ),
						strtoupper( (string) $plugin->settings()->get( 'workspace', 'currency', 'USD' ) )
					),
					'group'  => __( 'Sales', 'cliezoo' ),
					'format' => 'money',
					'ranged' => true,
					'count'  => static fn( Actor $actor, string $from, string $to ): ?int => $numbers()->sum( $actor, 'leads', 'budget', 't.created_at >= %s AND t.created_at < %s', array( $from, $to ) ),
					'drill'  => $drill,
				);
				return $metrics;
			}
		);

		add_action(
			'cliezoo_register_settings',
			static function ( \Cliezoo\Platform\Settings\Settings $settings ): void {
				$settings->register( 'leads', self::settings() );
			}
		);

		// What an agent may do with a lead: read them, and write one down. Converting one makes a client, a contact and a deal at once, which is a decision rather than a task (MCP plan §4).
		add_filter(
			'cliezoo_entity_action_meta',
			static function ( array $extra, Entity $entity, string $verb ): array {
				if ( ! in_array( $entity->name, array( 'leads' ), true ) ) {
					return $extra;
				}
				if ( in_array( $verb, array( 'list', 'get' ), true ) ) {
					$extra['mcp'] = 'read';
				} elseif ( in_array( $verb, array( 'create', 'update' ), true ) ) {
					$extra['mcp'] = 'safe';
				}
				return $extra;
			},
			10,
			3
		);

		add_action(
			'cliezoo_register_actions',
			static function ( ActionRegistry $registry ) use ( $plugin ): void {
				LeadActions::register( $registry, $plugin );

				$intake = static fn(): LeadIntake => $plugin->get( LeadIntake::class );
				$owner  = static fn( Actor $actor ): bool => $actor->is_staff() && $actor->is_admin();
				$registry->add(
					\Cliezoo\Core\Actions\Op::make( 'leads.intake', __( 'Whether leads can be sent in', 'cliezoo' ) )
						->allow( $owner )
						->run(
							static fn(): array => array(
								'on'  => $intake()->is_on(),
								'url' => rest_url( 'cliezoo/v1/public/leads' ),
							)
						)
						->rest( 'GET', '/leads/intake' )
						->readonly()
						->with( array( 'ability' => false ) )
				);
				$registry->add(
					\Cliezoo\Core\Actions\Op::make( 'leads.intake_key', __( 'Make the key leads are sent in with', 'cliezoo' ) )
						->describe( __( 'Makes a new key for posting leads in from a webhook or Zapier, shown this once and kept only as a hash; the old one stops working.', 'cliezoo' ) )
						->allow( $owner )
						->run( static fn(): array => $intake()->new_key() )
						->rest( 'POST', '/leads/intake' )
						// A key is a credential: never handed to an agent.
						->with( array( 'ability' => false ) )
				);
				$registry->add(
					\Cliezoo\Core\Actions\Op::make( 'leads.intake_off', __( 'Stop leads being sent in', 'cliezoo' ) )
						->allow( $owner )
						->run( static fn(): array => $intake()->turn_off() )
						->rest( 'POST', '/leads/intake/off' )
						->with( array( 'ability' => false ) )
				);
			}
		);

		// A lead written down with no status starts in the one the
		// workspace chose, and with no owner belongs to whoever wrote it
		// down — because an unowned lead is one nobody rings back.
		add_filter(
			'cliezoo_leads_before_create',
			static function ( array $data, $actor ) use ( $plugin ): array {
				if ( empty( $data['status'] ) ) {
					$data['status'] = (string) $plugin->settings()->get( 'leads', 'default_status', 'new' );
				}
				if ( empty( $data['owner_id'] ) && $actor instanceof Actor && $actor->member_id ) {
					$data['owner_id'] = $actor->member_id;
				}
				if ( empty( $data['currency'] ) ) {
					$data['currency'] = (string) $plugin->settings()->get( 'workspace', 'currency', 'USD' );
				}
				return $data;
			},
			10,
			2
		);

		// The score is the server's arithmetic, redone on the way in to
		// every write. Registered at boot rather than when the routes are
		// first asked for: a rule that arrives late is a rule that was not
		// there for the write that happened first.
		add_filter(
			'cliezoo_record_before_save',
			static function ( array $data, $entity, array $input, $existing ) use ( $plugin ): array {
				if ( ! $entity instanceof Entity || 'leads' !== $entity->name ) {
					return $data;
				}
				$row           = is_array( $existing ) ? array_merge( $existing, $data ) : $data;
				$data['score'] = $plugin->get( Leads::class )->score( $row );
				return $data;
			},
			10,
			4
		);
	}
}
