<?php
/**
 * What can be done with a lead.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Leads;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\Plugin;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML (decision record 0002).

/**
 * Converting, merging, assigning, and saying somebody was spoken to.
 *
 * The ordinary create, read, update and delete come from the entity
 * itself. These are the four things that are not a field changing.
 */
final class LeadActions {

	/**
	 * Registers them.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	public static function register( ActionRegistry $registry, Plugin $plugin ): void {
		$leads  = static fn(): Leads => $plugin->get( Leads::class );
		$writer = static fn( Actor $actor ): bool =>
			$actor->is_staff() && $actor->can_write() && 'none' !== $actor->scope( 'leads' );
		$reader = static fn( Actor $actor ): bool =>
			$actor->is_staff() && 'none' !== $actor->scope( 'leads' );

		$registry->add(
			Op::make( 'leads.convert', __( 'Make this lead a client', 'cliezoo' ) )
				->describe(
					__(
						'Makes a client and a contact from a lead, carrying everything it knows across, and keeps the lead so it can still be counted as one that was won.',
						'cliezoo'
					)
				)
				->input(
					array(
						'uid'           => Op::uid(),
						'name'          => array(
							'type'    => 'string',
							'default' => '',
						),
						'contact_name'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'contact_email' => array(
							'type'    => 'string',
							'default' => '',
						),
						// Whether to open a sale at the same time, and what
						// to call it. Asked rather than assumed: a lead that
						// converts because they signed a one-off job does not
						// need a deal to track.
						'deal'          => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'deal_name'     => array(
							'type'    => 'string',
							'default' => '',
						),
					)
				)
				->allow( $writer )
				->run(
					static fn( Actor $actor, array $in ): array => $leads()->convert(
						$actor,
						(string) $in['uid'],
						$in
					)
				)
				->rest( 'POST', '/leads/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/convert' )
		);

		$registry->add(
			Op::make( 'leads.merge', __( 'Merge two leads', 'cliezoo' ) )
				->describe(
					__(
						'Folds one lead into another: the one being kept wins every field it has an answer for, the other fills the gaps, and both sets of notes and the whole history go with it.',
						'cliezoo'
					)
				)
				->input(
					array(
						'uid'  => Op::uid(),
						'into' => Op::uid(),
					)
				)
				->allow( $writer )
				->run(
					static fn( Actor $actor, array $in ): array => $leads()->merge(
						$actor,
						(string) $in['into'],
						(string) $in['uid']
					)
				)
				->rest( 'POST', '/leads/merge' )
		);

		$registry->add(
			Op::make( 'leads.duplicates', __( 'Leads that look like this one', 'cliezoo' ) )
				->describe(
					__(
						'Leads with the same email address, and leads at the same domain — the first is the same conversation written down twice, the second is two people at one company nobody wants to pitch to separately.',
						'cliezoo'
					)
				)
				->input(
					array(
						'email' => array(
							'type'    => 'string',
							'default' => '',
						),
						'skip'  => array(
							'type'    => 'string',
							'default' => '',
						),
					)
				)
				->allow( $reader )
				->run(
					static fn( Actor $actor, array $in ): array => $leads()->alike(
						$actor,
						(string) ( $in['email'] ?? '' ),
						(string) ( $in['skip'] ?? '' )
					)
				)
				->rest( 'GET', '/leads/duplicates' )
				->readonly()
		);

		$registry->add(
			Op::make( 'leads.touched', __( 'Say this lead was spoken to', 'cliezoo' ) )
				->describe(
					__(
						'Records a call, an email or a meeting against a lead and brings its "last spoken to" up to date, which is what stops it looking like one nobody has rung back.',
						'cliezoo'
					)
				)
				->input(
					array(
						'uid'  => Op::uid(),
						'kind' => array(
							'type'    => 'string',
							'enum'    => array( 'call', 'email', 'meeting', 'note' ),
							'default' => 'note',
						),
						'said' => array(
							'type'      => 'string',
							'maxLength' => 2000,
							'default'   => '',
						),
					)
				)
				->allow( $writer )
				->run(
					static fn( Actor $actor, array $in ): array => $leads()->touched(
						$actor,
						(string) $in['uid'],
						(string) ( $in['kind'] ?? 'note' ),
						(string) ( $in['said'] ?? '' )
					)
				)
				->rest( 'POST', '/leads/(?P<uid>[0-9A-HJKMNP-TV-Z]{26})/touched' )
		);

		$registry->add(
			Op::make( 'leads.quiet', __( 'Leads that have gone quiet', 'cliezoo' ) )
				->describe(
					__(
						'Leads nobody has spoken to for longer than the workspace allows, longest wait first. Won, lost and not-a-fit are left out, and so is anybody who became a client.',
						'cliezoo'
					)
				)
				->allow( $reader )
				->run(
					static fn( Actor $actor ): array => array( 'leads' => $leads()->quiet( $actor ) )
				)
				->rest( 'GET', '/leads/quiet' )
				->readonly()
				->with( array( 'mcp' => 'read' ) )
		);

		$registry->add(
			Op::make( 'leads.assign', __( 'Give these leads an owner', 'cliezoo' ) )
				->describe(
					__( 'Sets who is following up, for one lead or for every lead that was ticked.', 'cliezoo' )
				)
				->input(
					array(
						'leads' => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
						'owner' => array(
							'type'    => 'string',
							'default' => '',
						),
					)
				)
				->allow( $writer )
				->run(
					static function ( Actor $actor, array $in ) use ( $plugin ): array {
						$uids = array_map( 'strval', (array) ( $in['leads'] ?? array() ) );
						if ( ! $uids ) {
							throw ActionError::validation( __( 'Tick at least one lead.', 'cliezoo' ) );
						}
						$service = $plugin->service( 'leads' );
						$done    = 0;
						foreach ( $uids as $uid ) {
							$service->update( $actor, $uid, array( 'owner' => (string) ( $in['owner'] ?? '' ) ) );
							++$done;
						}
						return array( 'assigned' => $done );
					}
				)
				->rest( 'POST', '/leads/assign' )
		);
	}
}
