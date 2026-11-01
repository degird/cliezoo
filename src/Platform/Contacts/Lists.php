<?php
/**
 * The editable lists contacts use.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Contacts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a person came from, what kind of person they are, and where the
 * conversation stands. All three are lists the workspace edits, because
 * every business tracks these differently (cliezoo.md §6.6).
 */
final class Lists {

	/**
	 * Adds the contact lists to the workspace's editable lists.
	 *
	 * @param array<string, array<string, mixed>> $lists Lists so far.
	 * @return array<string, array<string, mixed>>
	 */
	public static function declare_lists( array $lists ): array {
		$lists['contact_status'] = array(
			'label' => __( 'Contact statuses', 'cliezoo' ),
			'seeds' => array(
				array( 'new', __( 'New', 'cliezoo' ), '#38BDF8' ),
				array( 'contacted', __( 'Contacted', 'cliezoo' ), '#A78BFA' ),
				array( 'qualified', __( 'Qualified', 'cliezoo' ), '#22C55E' ),
				array( 'negotiating', __( 'Negotiating', 'cliezoo' ), '#F59E0B' ),
				array( 'proposal_sent', __( 'Proposal sent', 'cliezoo' ), '#F97316' ),
				array( 'active', __( 'Active', 'cliezoo' ), '#16A34A' ),
				array( 'nurturing', __( 'Nurturing', 'cliezoo' ), '#0EA5E9' ),
				array( 'unresponsive', __( 'Unresponsive', 'cliezoo' ), '#94A3B8' ),
				array( 'not_interested', __( 'Not interested', 'cliezoo' ), '#64748B' ),
				array( 'lost', __( 'Lost', 'cliezoo' ), '#EF4444' ),
				array( 'do_not_contact', __( 'Do not contact', 'cliezoo' ), '#B91C1C' ),
			),
		);
		$lists['contact_source'] = array(
			'label' => __( 'Contact sources', 'cliezoo' ),
			'seeds' => array(
				array( 'referral', __( 'Referral', 'cliezoo' ) ),
				array( 'social_media', __( 'Social media', 'cliezoo' ) ),
				array( 'email_marketing', __( 'Email marketing', 'cliezoo' ) ),
				array( 'paid_search', __( 'Paid search', 'cliezoo' ) ),
				array( 'organic_search', __( 'Organic search', 'cliezoo' ) ),
				array( 'reached_out', __( 'They got in touch', 'cliezoo' ) ),
				array( 'other_campaign', __( 'Another campaign', 'cliezoo' ) ),
			),
		);
		$lists['contact_type']   = array(
			'label' => __( 'Contact types', 'cliezoo' ),
			'seeds' => array(
				array( 'customer', __( 'Customer', 'cliezoo' ) ),
				array( 'partner', __( 'Partner', 'cliezoo' ) ),
				array( 'vendor', __( 'Vendor', 'cliezoo' ) ),
			),
		);
		return $lists;
	}
}
