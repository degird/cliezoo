<?php
/**
 * The editable lists leads use.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a lead came from, and how far along it is.
 *
 * Both are lists a workspace edits rather than enums in code. A studio
 * that gets everything from one agency and a studio that runs ads have
 * nothing in common about where work comes from, and a fixed list would
 * be wrong for both. The seeds are a starting point.
 *
 * Lead source is deliberately one list shared with deals (M3-02), because
 * "where did this come from" has to be answerable across the join — a
 * source that means one thing on a lead and another on the deal it became
 * cannot be reported on (ledger B-0354).
 */
final class Lists {

	/**
	 * Adds the lead lists to the workspace's editable lists.
	 *
	 * @param array<string, array<string, mixed>> $lists Lists so far.
	 * @return array<string, array<string, mixed>>
	 */
	public static function declare_lists( array $lists ): array {
		$lists['lead_source'] = array(
			'label' => __( 'Lead sources', 'cliezoo' ),
			'seeds' => self::seeds(
				array(
					'referral'      => __( 'Referral', 'cliezoo' ),
					'website'       => __( 'Website', 'cliezoo' ),
					'search'        => __( 'Search', 'cliezoo' ),
					'social'        => __( 'Social', 'cliezoo' ),
					'advertising'   => __( 'Advertising', 'cliezoo' ),
					'email'         => __( 'Email', 'cliezoo' ),
					'event'         => __( 'Event', 'cliezoo' ),
					'cold_outreach' => __( 'Cold outreach', 'cliezoo' ),
					'partner'       => __( 'Partner', 'cliezoo' ),
					'existing'      => __( 'An existing client', 'cliezoo' ),
					'other'         => __( 'Somewhere else', 'cliezoo' ),
				)
			),
		);

		$lists['lead_status'] = array(
			'label' => __( 'Lead statuses', 'cliezoo' ),
			'seeds' => self::seeds(
				array(
					'new'         => __( 'New', 'cliezoo' ),
					'contacted'   => __( 'Contacted', 'cliezoo' ),
					'qualified'   => __( 'Qualified', 'cliezoo' ),
					'proposal'    => __( 'Proposal sent', 'cliezoo' ),
					'won'         => __( 'Won', 'cliezoo' ),
					'lost'        => __( 'Lost', 'cliezoo' ),
					'unqualified' => __( 'Not a fit', 'cliezoo' ),
				),
				array(
					'new'         => '#38BDF8',
					'contacted'   => '#A78BFA',
					'qualified'   => '#22C55E',
					'proposal'    => '#F59E0B',
					'won'         => '#16A34A',
					'lost'        => '#EF4444',
					'unqualified' => '#64748B',
				)
			),
		);

		return $lists;
	}

	/**
	 * Turns value => label (and optional colours) into seed rows.
	 *
	 * @param array<string, string> $values Value => label.
	 * @param array<string, string> $colors Value => hex colour.
	 * @return array<int, array<int, string>>
	 */
	private static function seeds( array $values, array $colors = array() ): array {
		$out = array();
		foreach ( $values as $value => $label ) {
			$row = array( (string) $value, $label );
			if ( isset( $colors[ $value ] ) ) {
				$row[] = $colors[ $value ];
			}
			$out[] = $row;
		}
		return $out;
	}
}
