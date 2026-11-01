<?php
/**
 * The editable lists clients use.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Clients;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client type, lifecycle stage, industry and territory are lists a
 * workspace edits, not enums in code: every business sorts its clients
 * differently, and the seeds are only a starting point (cliezoo.md §6.5).
 *
 * The industry list is the one from the sources with its duplicates
 * removed — "Technology & Software" and "SaaS & Software" both stay,
 * because they are not the same thing, but nothing appears twice.
 */
final class Lists {

	/**
	 * Adds the client lists to the workspace's editable lists.
	 *
	 * @param array<string, array<string, mixed>> $lists Lists so far.
	 * @return array<string, array<string, mixed>>
	 */
	public static function declare_lists( array $lists ): array {
		$lists['client_type']  = array(
			'label' => __( 'Client types', 'cliezoo' ),
			'seeds' => self::seeds(
				array(
					'smb'        => __( 'SMB', 'cliezoo' ),
					'mid_market' => __( 'Mid-Market', 'cliezoo' ),
					'enterprise' => __( 'Enterprise', 'cliezoo' ),
					'corporate'  => __( 'Corporate', 'cliezoo' ),
					'agency'     => __( 'Agency', 'cliezoo' ),
					'government' => __( 'Government', 'cliezoo' ),
					'non_profit' => __( 'Non-Profit', 'cliezoo' ),
					'partner'    => __( 'Partner or reseller', 'cliezoo' ),
					'prospect'   => __( 'Prospect', 'cliezoo' ),
					'individual' => __( 'Individual', 'cliezoo' ),
				)
			),
		);
		$lists['client_stage'] = array(
			'label' => __( 'Client lifecycle stages', 'cliezoo' ),
			'seeds' => self::seeds(
				array(
					'lead'       => __( 'Lead', 'cliezoo' ),
					'prospect'   => __( 'Prospect', 'cliezoo' ),
					'onboarding' => __( 'Onboarding', 'cliezoo' ),
					'active'     => __( 'Active', 'cliezoo' ),
					'paused'     => __( 'Paused', 'cliezoo' ),
					'churned'    => __( 'Churned', 'cliezoo' ),
					'former'     => __( 'Former', 'cliezoo' ),
				),
				array(
					'lead'       => '#94A3B8',
					'prospect'   => '#38BDF8',
					'onboarding' => '#A78BFA',
					'active'     => '#22C55E',
					'paused'     => '#F59E0B',
					'churned'    => '#EF4444',
					'former'     => '#64748B',
				)
			),
		);
		$lists['territory']    = array(
			'label' => __( 'Territories', 'cliezoo' ),
			'seeds' => self::seeds( array() ),
		);
		$lists['industry']     = array(
			'label' => __( 'Industries', 'cliezoo' ),
			'seeds' => self::seeds( self::industries() ),
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

	/**
	 * The seed industries.
	 *
	 * @return array<string, string>
	 */
	private static function industries(): array {
		return array(
			'accounting_tax'        => __( 'Accounting & Tax', 'cliezoo' ),
			'agriculture'           => __( 'Agriculture', 'cliezoo' ),
			'architecture_interior' => __( 'Architecture & Interior Design', 'cliezoo' ),
			'arts_entertainment'    => __( 'Arts & Entertainment', 'cliezoo' ),
			'automotive'            => __( 'Automotive', 'cliezoo' ),
			'beauty_wellness'       => __( 'Beauty & Wellness', 'cliezoo' ),
			'construction'          => __( 'Construction & Engineering', 'cliezoo' ),
			'consulting'            => __( 'Consulting & Professional Services', 'cliezoo' ),
			'ecommerce_retail'      => __( 'E-commerce & Retail', 'cliezoo' ),
			'education'             => __( 'Education & Training', 'cliezoo' ),
			'energy_utilities'      => __( 'Energy & Utilities', 'cliezoo' ),
			'events_hospitality'    => __( 'Events & Hospitality', 'cliezoo' ),
			'financial_services'    => __( 'Financial Services', 'cliezoo' ),
			'fitness_sports'        => __( 'Fitness & Sports', 'cliezoo' ),
			'food_beverage'         => __( 'Food & Beverage', 'cliezoo' ),
			'government'            => __( 'Government & Public Sector', 'cliezoo' ),
			'healthcare'            => __( 'Healthcare & Medical', 'cliezoo' ),
			'home_field_services'   => __( 'Home & Field Services', 'cliezoo' ),
			'hospitality_travel'    => __( 'Hospitality & Travel', 'cliezoo' ),
			'hr_recruiting'         => __( 'Human Resources & Recruiting', 'cliezoo' ),
			'insurance'             => __( 'Insurance', 'cliezoo' ),
			'it_services'           => __( 'IT Services', 'cliezoo' ),
			'legal'                 => __( 'Legal Services', 'cliezoo' ),
			'logistics'             => __( 'Logistics & Transportation', 'cliezoo' ),
			'manufacturing'         => __( 'Manufacturing', 'cliezoo' ),
			'marketing_advertising' => __( 'Marketing & Advertising', 'cliezoo' ),
			'media_publishing'      => __( 'Media & Publishing', 'cliezoo' ),
			'nonprofit_social'      => __( 'Nonprofit & Social Services', 'cliezoo' ),
			'photography_video'     => __( 'Photography & Videography', 'cliezoo' ),
			'real_estate'           => __( 'Real Estate', 'cliezoo' ),
			'religious'             => __( 'Religious Organizations', 'cliezoo' ),
			'saas_software'         => __( 'SaaS & Software', 'cliezoo' ),
			'security'              => __( 'Security', 'cliezoo' ),
			'technology'            => __( 'Technology & Software', 'cliezoo' ),
			'telecommunications'    => __( 'Telecommunications', 'cliezoo' ),
			'veterinary'            => __( 'Veterinary Services', 'cliezoo' ),
			'wholesale'             => __( 'Wholesale & Distribution', 'cliezoo' ),
			'other'                 => __( 'Other', 'cliezoo' ),
		);
	}
}
