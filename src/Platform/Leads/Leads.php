<?php
/**
 * What happens to a lead: scoring it, spotting it twice, merging it, and
 * turning it into a client.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Leads;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML (decision record 0002).

/**
 * The four things a studio does with a lead.
 */
final class Leads {

	/**
	 * How many leads a merge will look at, and a duplicate check will
	 * compare against. Enough for any real workspace's live pipeline, and
	 * a bound rather than a promise.
	 */
	private const MOST = 200;

	/**
	 * The plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Holds the plugin it reads its services from.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * What a lead scores out of a hundred.
	 *
	 * Arithmetic a studio can read, not a model. Every part of it is a
	 * weight in the settings, so a workspace that only ever sells to
	 * people with a budget can say so, and one that lives on referrals can
	 * say that instead. Nothing here is a guess about the person: it is
	 * only what they have told the studio and how recently.
	 *
	 * @param array<string, mixed> $row The lead, as columns.
	 * @return int 0–100.
	 */
	public function score( array $row ): int {
		$weights = (array) $this->plugin->settings()->get( 'leads', 'score_weights', array() );
		$strong  = array_map( 'strval', (array) $this->plugin->settings()->get( 'leads', 'strong_sources', array() ) );

		$weight = static fn( string $key ): int => max( 0, (int) ( $weights[ $key ] ?? 0 ) );

		$score = 0;
		if ( '' !== trim( (string) ( $row['email'] ?? '' ) ) ) {
			$score += $weight( 'has_email' );
		}
		if ( '' !== trim( (string) ( $row['phone'] ?? '' ) ) ) {
			$score += $weight( 'has_phone' );
		}
		if ( '' !== trim( (string) ( $row['business_name'] ?? '' ) ) ) {
			$score += $weight( 'has_business' );
		}
		if ( '' !== trim( (string) ( $row['website'] ?? '' ) ) ) {
			$score += $weight( 'has_website' );
		}
		if ( (int) ( $row['budget'] ?? 0 ) > 0 ) {
			$score += $weight( 'has_budget' );
		}
		// The rating is the one human judgement in it, so it counts per
		// star rather than as a yes or no.
		$score += min( 5, max( 0, (int) ( $row['rating'] ?? 0 ) ) ) * $weight( 'rating' );

		if ( $strong && in_array( (string) ( $row['source'] ?? '' ), $strong, true ) ) {
			$score += $weight( 'source' );
		}

		// Somebody spoken to in the last fortnight is a live conversation;
		// the same lead untouched for two months is a name in a list.
		$quiet = max( 0, (int) $this->plugin->settings()->get( 'leads', 'quiet_after_days', 14 ) );
		$touch = (string) ( $row['last_touch_at'] ?? '' );
		if ( $quiet > 0 && '' !== $touch ) {
			$since = ( time() - (int) strtotime( $touch ) ) / DAY_IN_SECONDS;
			if ( $since <= $quiet ) {
				$score += $weight( 'touched' );
			}
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Leads that look like this one.
	 *
	 * By address first, because an email address is the one thing two
	 * records for the same conversation nearly always share. Then by the
	 * domain of that address, because "someone@acme.com" and
	 * "someone.else@acme.com" are two people at one company a studio is
	 * about to pitch to twice — a warning rather than a refusal, since
	 * they are genuinely two leads.
	 *
	 * @param Actor  $actor Who is asking.
	 * @param string $email The address.
	 * @param string $skip  A lead uid to leave out (itself, when editing).
	 * @return array{same: array<int, array<string, mixed>>, domain: array<int, array<string, mixed>>}
	 */
	public function alike( Actor $actor, string $email, string $skip = '' ): array {
		$email = strtolower( trim( $email ) );
		$out   = array(
			'same'   => array(),
			'domain' => array(),
		);
		if ( '' === $email || ! str_contains( $email, '@' ) ) {
			return $out;
		}
		$domain = substr( $email, strpos( $email, '@' ) + 1 );
		$leads  = $this->plugin->service( 'leads' );

		foreach ( $leads->repository()->where( array(), 'id', self::MOST ) as $row ) {
			$row   = (array) $row;
			$their = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
			if ( '' === $their || ( '' !== $skip && (string) $row['uid'] === $skip ) ) {
				continue;
			}
			if ( $their === $email ) {
				$out['same'][] = $leads->present( $actor, $row );
				continue;
			}
			if ( '' !== $domain && str_ends_with( $their, '@' . $domain ) ) {
				$out['domain'][] = $leads->present( $actor, $row );
			}
		}
		return $out;
	}

	/**
	 * Merges one lead into another.
	 *
	 * The one being kept wins every field it has an answer for; the other
	 * fills the gaps. That way a merge never quietly replaces something
	 * somebody typed with something older, and the thing a studio usually
	 * wants — the newer record's email on the older record's history —
	 * happens by keeping the newer one.
	 *
	 * What is merged is not only the row: notes, to-dos, activity and
	 * anything else attached to the lead go with it, because a merge that
	 * loses the conversation is a delete with extra steps.
	 *
	 * @param Actor  $actor Who is merging.
	 * @param string $keep  The lead that survives.
	 * @param string $other The lead folded into it.
	 * @return array<string, mixed> The surviving lead.
	 * @throws ActionError When either is missing, or they are the same one.
	 */
	public function merge( Actor $actor, string $keep, string $other ): array {
		if ( $keep === $other ) {
			throw ActionError::validation( __( 'That is the same lead twice.', 'cliezoo' ) );
		}
		$leads  = $this->plugin->service( 'leads' );
		$winner = $leads->load( $actor, $keep, Policy::UPDATE );
		$loser  = $leads->load( $actor, $other, Policy::DELETE );

		$fill = array();
		foreach ( array( 'email', 'phone', 'business_name', 'website', 'source', 'owner_id', 'budget', 'currency', 'lost_reason' ) as $column ) {
			$mine = $winner[ $column ] ?? null;
			$them = $loser[ $column ] ?? null;
			if ( ( null === $mine || '' === $mine || 0 === $mine ) && null !== $them && '' !== $them ) {
				$fill[ $column ] = $them;
			}
		}
		// Two sets of notes are two things somebody wrote down, so both
		// are kept rather than one winning.
		$theirs = trim( (string) ( $loser['notes'] ?? '' ) );
		if ( '' !== $theirs ) {
			$mine          = trim( (string) ( $winner['notes'] ?? '' ) );
			$fill['notes'] = '' === $mine ? $theirs : $mine . "\n\n" . $theirs;
		}
		// The higher rating and the more recent conversation, because a
		// merge should not make a lead look colder than it is.
		if ( (int) ( $loser['rating'] ?? 0 ) > (int) ( $winner['rating'] ?? 0 ) ) {
			$fill['rating'] = (string) (int) $loser['rating'];
		}
		if ( (string) ( $loser['last_touch_at'] ?? '' ) > (string) ( $winner['last_touch_at'] ?? '' ) ) {
			$fill['last_touch_at'] = (string) $loser['last_touch_at'];
		}

		// Everything pointing at the loser now points at the winner. The
		// columns are found from the entity definitions rather than a list
		// kept by hand, the way a client merge already does it, so a module
		// added later cannot be forgotten and quietly leave a conversation
		// attached to a trashed lead.
		$this->move_everything( (int) $loser['id'], (int) $winner['id'] );

		if ( $fill ) {
			$leads->update( $actor, $keep, $fill );
		}
		$leads->trash( $actor, $other );

		return $leads->present( $actor, (array) $leads->repository()->find( (int) $winner['id'] ) );
	}

	/**
	 * Turns a lead into a client, with a contact, in one press.
	 *
	 * Everything the lead knows goes across, and the lead is kept rather
	 * than deleted — a studio asking "how many of last quarter's leads
	 * turned into work" is asking about the leads, and a converted lead
	 * that vanished cannot answer.
	 *
	 * A deal is made too where deals exist (M3-02); until they do, this
	 * makes the client and the contact, which is the part a studio cannot
	 * do without.
	 *
	 * @param Actor                $actor Who is converting.
	 * @param string               $uid   The lead.
	 * @param array<string, mixed> $in    name, contact_name, contact_email (all optional overrides).
	 * @return array<string, mixed> The lead, the client and the contact.
	 * @throws ActionError When it has already been converted.
	 */
	public function convert( Actor $actor, string $uid, array $in = array() ): array {
		$leads = $this->plugin->service( 'leads' );
		$lead  = $leads->load( $actor, $uid, Policy::UPDATE );

		if ( ! empty( $lead['client_id'] ) ) {
			throw ActionError::conflict(
				__( 'This lead is already a client. Open the client instead.', 'cliezoo' )
			);
		}

		$called = trim( (string) ( $in['name'] ?? '' ) );
		if ( '' === $called ) {
			// The business if there is one, because that is what an
			// invoice is addressed to; the person's name if there is not.
			$called = trim( (string) ( $lead['business_name'] ?? '' ) );
		}
		if ( '' === $called ) {
			$called = trim( (string) ( $lead['name'] ?? '' ) );
		}

		$client = $this->plugin->service( 'clients' )->create_row(
			$actor,
			array_filter(
				array(
					'name'     => $called,
					'email'    => (string) ( $lead['email'] ?? '' ),
					'phone'    => (string) ( $lead['phone'] ?? '' ),
					'website'  => (string) ( $lead['website'] ?? '' ),
					'currency' => (string) ( $lead['currency'] ?? '' ),
					'notes'    => (string) ( $lead['notes'] ?? '' ),
				),
				static fn( $value ): bool => '' !== $value
			)
		);

		// Everything this conversion makes, so a step that fails takes the
		// ones before it back with it. A lead left marked "won" with a client
		// and no deal cannot be converted again — "already a client" — so the
		// deal the rep asked for would be lost for good.
		$made    = array( array( 'clients', $client ) );
		$contact = null;
		$deal    = null;
		try {
			$person  = trim( (string) ( $in['contact_name'] ?? ( $lead['name'] ?? '' ) ) );
			$address = trim( (string) ( $in['contact_email'] ?? ( $lead['email'] ?? '' ) ) );
			// A contact is a person somebody can write to, so it needs an
			// address. A lead that only ever rang becomes a client with no
			// contact rather than failing halfway and leaving one behind.
			if ( '' !== $address ) {
				$contact = $this->plugin->service( 'contacts' )->create_row(
					$actor,
					array_filter(
						array(
							'name'       => '' !== $person ? $person : $address,
							'email'      => $address,
							'phone'      => (string) ( $lead['phone'] ?? '' ),
							'client'     => (string) $client['uid'],
							'is_primary' => 1,
						),
						static fn( $value ): bool => '' !== $value
					)
				);
				$made[]  = array( 'contacts', $contact );
			}

			// And the sale itself, where the studio keeps deals and asked for
			// one. A converted lead with no deal is a client nobody is selling
			// to: the conversation that was worth tracking as a lead is still
			// worth tracking, and it is worth a number and a date now.
			if ( ! empty( $in['deal'] ) && $this->plugin->entities()->has( 'deals' ) ) {
				$deal = $this->plugin->service( 'deals' )->create_row(
					$actor,
					array_filter(
						array(
							'name'     => '' !== trim( (string) ( $in['deal_name'] ?? '' ) )
								? trim( (string) $in['deal_name'] )
								: (string) $lead['name'],
							'client'   => (string) $client['uid'],
							'contact'  => $contact ? (string) $contact['uid'] : '',
							'lead'     => (string) $lead['uid'],
							// What they said they had to spend, as what the deal
							// is worth for now. It is the only number anybody has
							// and it is the studio's to correct.
							'value'    => (int) ( $lead['budget'] ?? 0 ),
							'currency' => (string) ( $lead['currency'] ?? '' ),
							// Carried across only when it is still a word on the
							// list: a lead kept from before a source was renamed
							// or removed would otherwise stop the deal being made.
							'source'   => $this->listed_source( (string) ( $lead['source'] ?? '' ) ),
							'owner'    => ! empty( $lead['owner_id'] )
								? (string) $this->member_uid( (int) $lead['owner_id'] )
								: '',
						),
						static fn( $value ): bool => '' !== $value
					)
				);
			}
		} catch ( \Throwable $trouble ) {
			// Each on its own: one that will not go must not hide why the
			// conversion failed, nor keep the rest from going.
			foreach ( array_reverse( $made ) as $one ) {
				try {
					$this->plugin->service( $one[0] )->purge_row( Actor::system(), (array) $one[1] );
				} catch ( \Throwable $left ) {
					unset( $left );
				}
			}
			throw $trouble;
		}

		// Written to the row rather than through the serializer, because
		// what a lead became is readonly: it is a fact this method just
		// established, not a field anybody may type. Last, once everything
		// it became exists.
		$leads->repository()->update(
			(int) $lead['id'],
			array_filter(
				array(
					'client_id'    => (int) $client['id'],
					'contact_id'   => $contact ? (int) $contact['id'] : 0,
					'converted_at' => gmdate( 'Y-m-d H:i:s' ),
					'status'       => 'won',
				),
				static fn( $value ): bool => 0 !== $value
			)
		);

		return array(
			'lead'    => $leads->present( $actor, (array) $leads->repository()->find( (int) $lead['id'] ) ),
			'client'  => $this->plugin->service( 'clients' )->present( $actor, (array) $client ),
			'contact' => $contact
				? $this->plugin->service( 'contacts' )->present( $actor, (array) $contact )
				: null,
			'deal'    => $deal
				? $this->plugin->service( 'deals' )->present( $actor, (array) $deal )
				: null,
		);
	}

	/**
	 * A lead source, if it is still one of the list's words; nothing if not.
	 *
	 * @param string $source What the lead says.
	 * @return string
	 */
	private function listed_source( string $source ): string {
		if ( '' === $source ) {
			return '';
		}
		/** This filter is documented in TaxonomiesModule::register(). */
		return apply_filters( 'cliezoo_list_value_valid', true, 'lead_source', $source ) ? $source : '';
	}

	/**
	 * A member's uid, for a create that takes uids.
	 *
	 * @param int $id Row id.
	 * @return string
	 */
	private function member_uid( int $id ): string {
		$found = $this->plugin->service( 'members' )->repository()->find( $id );
		return is_array( $found ) ? (string) $found['uid'] : '';
	}

	/**
	 * Points everything at the surviving lead.
	 *
	 * @param int $from The losing lead's id.
	 * @param int $to   The surviving lead's id.
	 * @return void
	 */
	private function move_everything( int $from, int $to ): void {
		global $wpdb;
		foreach ( $this->plugin->entities()->all() as $name => $entity ) {
			if ( 'leads' === $name ) {
				continue;
			}
			foreach ( $entity->fields as $column => $field ) {
				if ( 'ref' !== $field->type || 'leads' !== $field->ref ) {
					continue;
				}
				$table = esc_sql( $entity->table() );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table and column come from the entity definitions; both values are placeholders.
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$column} = %d WHERE {$column} = %d", $to, $from ) );
			}
		}
		// And the history. Notes, to-dos, comments and the activity trail
		// hang off a record by type and id rather than by a reference
		// column, so the walk above cannot see them — and a merge that
		// loses the conversation is a delete with extra steps.
		foreach ( $this->plugin->entities()->all() as $name => $entity ) {
			if ( 'leads' === $name || ! $entity->has_column( 'object_type' ) || ! $entity->has_column( 'object_id' ) ) {
				continue;
			}
			// A table that may hold one row per record cannot hold two:
			// the search index is one line per thing, so the loser's line
			// is dropped rather than moved onto the winner's, which is
			// already there and already right.
			if ( $this->one_per_record( $entity ) ) {
				$this->plugin->service( $name )->repository()->delete_where(
					array(
						'object_type' => 'leads',
						'object_id'   => $from,
					)
				);
				continue;
			}
			$table = esc_sql( $entity->table() );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- The table comes from the entity definitions; every value is a placeholder.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET object_id = %d WHERE object_type = %s AND object_id = %d", $to, 'leads', $from ) );
		}
	}

	/**
	 * Whether a table holds at most one row per record.
	 *
	 * @param Entity $entity The entity.
	 * @return bool
	 */
	private function one_per_record( Entity $entity ): bool {
		foreach ( $entity->unique as $columns ) {
			$columns = (array) $columns;
			if ( in_array( 'object_type', $columns, true ) && in_array( 'object_id', $columns, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Says a conversation happened, so the lead stops looking quiet.
	 *
	 * @param Actor  $actor Who spoke to them.
	 * @param string $uid   The lead.
	 * @param string $what  call, email, meeting or note.
	 * @param string $said  What to write down, or ''.
	 * @return array<string, mixed> The lead.
	 */
	public function touched( Actor $actor, string $uid, string $what, string $said = '' ): array {
		$leads = $this->plugin->service( 'leads' );
		$lead  = $leads->load( $actor, $uid, Policy::UPDATE );

		/**
		 * Fires when somebody records speaking to a lead.
		 *
		 * @param array  $lead  The lead row.
		 * @param string $what  Kind of contact.
		 * @param string $said  What was written down.
		 * @param Actor  $actor Who.
		 */
		do_action( 'cliezoo_lead_touched', $lead, $what, $said, $actor );

		return $leads->update(
			$actor,
			$uid,
			array( 'last_touch_at' => gmdate( 'Y-m-d H:i:s' ) )
		);
	}

	/**
	 * Leads nobody has spoken to for a while.
	 *
	 * The only thing a lead list is really for. A studio does not lose work
	 * by writing an enquiry down badly; it loses work by writing it down and
	 * then not ringing back, and nothing on a screen sorted by date tells
	 * anybody that.
	 *
	 * Won, lost and not-a-fit are left out: a lead that has been answered is
	 * not waiting for anybody. So is a lead that became a client, whatever
	 * its status says.
	 *
	 * @param Actor $actor Who is asking.
	 * @return array<int, array<string, mixed>> Leads, longest wait first.
	 */
	public function quiet( Actor $actor ): array {
		$days = max( 0, (int) $this->plugin->settings()->get( 'leads', 'quiet_after_days', 14 ) );
		if ( 0 === $days ) {
			return array();
		}

		$entity = $this->plugin->entities()->get( 'leads' );
		// Nothing back from the policy means nothing is being kept from this
		// person, not that nothing is theirs to see — which read the other way
		// round would show an owner an empty list and a studio a list of the
		// leads it had already dealt with.
		$scope = $this->plugin->policy()->scope( $actor, $entity );
		$where = null === $scope ? '1=1' : (string) $scope[0];
		$args  = null === $scope ? array() : (array) $scope[1];

		global $wpdb;
		$table = $entity->table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$sql   = 'SELECT t.uid, t.name, t.last_touch_at, t.created_at'
			. " FROM {$table} t WHERE {$where} AND t.deleted_at IS NULL"
			. ' AND t.client_id IS NULL'
			. " AND ( t.status IS NULL OR t.status NOT IN ('won','lost','unqualified') )"
			. ' AND COALESCE(t.last_touch_at, t.created_at) < %s'
			. ' ORDER BY COALESCE(t.last_touch_at, t.created_at) ASC LIMIT 25';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The table and the scope come from the entity definitions; every value is a placeholder.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $since ) ) ), ARRAY_A );

		$now = time();
		$out = array();
		foreach ( $rows as $row ) {
			$last  = (string) ( $row['last_touch_at'] ?? '' );
			$last  = '' !== $last ? $last : (string) $row['created_at'];
			$out[] = array(
				'uid'   => (string) $row['uid'],
				'name'  => (string) $row['name'],
				// Whole days, because "13.7 days" is not how anybody asks.
				'days'  => (int) floor( max( 0, $now - (int) strtotime( $last ) ) / DAY_IN_SECONDS ),
				'since' => $last,
			);
		}
		return $out;
	}
}
