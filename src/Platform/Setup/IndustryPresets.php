<?php
/**
 * Sensible defaults per industry, for the setup wizard.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Setup;

use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The twenty industries of cliezoo.md §6.38 and what each one starts with:
 * project categories, and the folders a new client and a new project get.
 *
 * Presets only ever add. A category the workspace already has is left as
 * it is, and folder lists are replaced only while they are still the
 * built-in ones, so running the wizard again never undoes somebody's work.
 * Pipelines (§6.40) arrive with the sales module in a later milestone.
 */
final class IndustryPresets {

	/**
	 * Industry slugs, in the spec's order.
	 */
	public const INDUSTRIES = array(
		'it_services',
		'marketing_design',
		'accounting',
		'legal',
		'consulting',
		'real_estate',
		'construction',
		'home_field_services',
		'app_web_development',
		'healthcare',
		'education',
		'events',
		'creative_services',
		'photography_videography',
		'wellness',
		'recruiting',
		'manufacturing',
		'retail_ecommerce',
		'hospitality',
		'other',
	);

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Industry names, for anything that shows the list.
	 *
	 * @return array<string, string> Slug => name.
	 */
	public static function labels(): array {
		return array(
			'it_services'             => __( 'IT services', 'cliezoo' ),
			'marketing_design'        => __( 'Marketing and design', 'cliezoo' ),
			'accounting'              => __( 'Accounting', 'cliezoo' ),
			'legal'                   => __( 'Legal', 'cliezoo' ),
			'consulting'              => __( 'Consulting', 'cliezoo' ),
			'real_estate'             => __( 'Real estate', 'cliezoo' ),
			'construction'            => __( 'Construction', 'cliezoo' ),
			'home_field_services'     => __( 'Home and field services', 'cliezoo' ),
			'app_web_development'     => __( 'App and web development', 'cliezoo' ),
			'healthcare'              => __( 'Healthcare', 'cliezoo' ),
			'education'               => __( 'Education', 'cliezoo' ),
			'events'                  => __( 'Events', 'cliezoo' ),
			'creative_services'       => __( 'Creative services', 'cliezoo' ),
			'photography_videography' => __( 'Photography and videography', 'cliezoo' ),
			'wellness'                => __( 'Wellness', 'cliezoo' ),
			'recruiting'              => __( 'Recruiting', 'cliezoo' ),
			'manufacturing'           => __( 'Manufacturing', 'cliezoo' ),
			'retail_ecommerce'        => __( 'Retail and e-commerce', 'cliezoo' ),
			'hospitality'             => __( 'Hospitality', 'cliezoo' ),
			'other'                   => __( 'Other', 'cliezoo' ),
		);
	}

	/**
	 * What an industry starts with.
	 *
	 * @param string $industry Industry slug.
	 * @return array{categories: array<int, array{0: string, 1: string}>, client_folders: string[], project_folders: string[]}
	 */
	public static function preset( string $industry ): array {
		$preset = array(
			'categories'      => array(),
			'client_folders'  => array(),
			'project_folders' => array(),
		);
		switch ( $industry ) {
			case 'it_services':
				$preset['categories']      = array(
					array( 'managed_services', __( 'Managed services', 'cliezoo' ) ),
					array( 'support', __( 'Support', 'cliezoo' ) ),
					array( 'network', __( 'Network and infrastructure', 'cliezoo' ) ),
					array( 'maintenance', __( 'Maintenance', 'cliezoo' ) ),
				);
				$preset['client_folders']  = array( __( 'Contracts', 'cliezoo' ), __( 'Documentation', 'cliezoo' ), __( 'Credentials handover', 'cliezoo' ) );
				$preset['project_folders'] = array( __( 'Scope', 'cliezoo' ), __( 'Configuration', 'cliezoo' ), __( 'Handover', 'cliezoo' ) );
				break;
			case 'marketing_design':
			case 'creative_services':
				$preset['categories']      = array(
					array( 'branding', __( 'Branding', 'cliezoo' ) ),
					array( 'campaign', __( 'Campaign', 'cliezoo' ) ),
					array( 'social_media', __( 'Social media', 'cliezoo' ) ),
					array( 'content', __( 'Content writing', 'cliezoo' ) ),
					array( 'seo', __( 'SEO', 'cliezoo' ) ),
				);
				$preset['client_folders']  = array( __( 'Brand assets', 'cliezoo' ), __( 'Documents', 'cliezoo' ), __( 'Designs', 'cliezoo' ) );
				$preset['project_folders'] = array( __( 'Brief', 'cliezoo' ), __( 'Drafts', 'cliezoo' ), __( 'Review rounds', 'cliezoo' ), __( 'Final delivery', 'cliezoo' ) );
				break;
			case 'accounting':
				$preset['categories']      = array(
					array( 'bookkeeping', __( 'Bookkeeping', 'cliezoo' ) ),
					array( 'tax_return', __( 'Tax return', 'cliezoo' ) ),
					array( 'payroll', __( 'Payroll', 'cliezoo' ) ),
					array( 'year_end', __( 'Year-end accounts', 'cliezoo' ) ),
				);
				$preset['client_folders']  = array( __( 'Engagement letters', 'cliezoo' ), __( 'Statements', 'cliezoo' ), __( 'Tax', 'cliezoo' ) );
				$preset['project_folders'] = array( __( 'Records received', 'cliezoo' ), __( 'Working papers', 'cliezoo' ), __( 'Filed', 'cliezoo' ) );
				break;
			case 'legal':
				$preset['categories']      = array(
					array( 'matter', __( 'Matter', 'cliezoo' ) ),
					array( 'contract_review', __( 'Contract review', 'cliezoo' ) ),
					array( 'litigation', __( 'Litigation', 'cliezoo' ) ),
					array( 'advisory', __( 'Advisory', 'cliezoo' ) ),
				);
				$preset['client_folders']  = array( __( 'Engagement', 'cliezoo' ), __( 'Identity documents', 'cliezoo' ), __( 'Correspondence', 'cliezoo' ) );
				$preset['project_folders'] = array( __( 'Evidence', 'cliezoo' ), __( 'Drafts', 'cliezoo' ), __( 'Filed', 'cliezoo' ) );
				break;
			case 'consulting':
				$preset['categories']      = array(
					array( 'consulting', __( 'Consulting', 'cliezoo' ) ),
					array( 'workshop', __( 'Workshop', 'cliezoo' ) ),
					array( 'audit', __( 'Audit', 'cliezoo' ) ),
					array( 'retainer', __( 'Retainer', 'cliezoo' ) ),
				);
				$preset['project_folders'] = array( __( 'Discovery', 'cliezoo' ), __( 'Findings', 'cliezoo' ), __( 'Recommendations', 'cliezoo' ) );
				break;
			case 'real_estate':
				$preset['categories']      = array(
					array( 'listing', __( 'Listing', 'cliezoo' ) ),
					array( 'sale', __( 'Sale', 'cliezoo' ) ),
					array( 'letting', __( 'Letting', 'cliezoo' ) ),
					array( 'valuation', __( 'Valuation', 'cliezoo' ) ),
				);
				$preset['client_folders']  = array( __( 'Identity documents', 'cliezoo' ), __( 'Agreements', 'cliezoo' ) );
				$preset['project_folders'] = array( __( 'Photos', 'cliezoo' ), __( 'Floor plans', 'cliezoo' ), __( 'Paperwork', 'cliezoo' ) );
				break;
			case 'construction':
			case 'home_field_services':
				$preset['categories']      = array(
					array( 'job', __( 'Job', 'cliezoo' ) ),
					array( 'renovation', __( 'Renovation', 'cliezoo' ) ),
					array( 'repair', __( 'Repair', 'cliezoo' ) ),
					array( 'inspection', __( 'Inspection', 'cliezoo' ) ),
					array( 'maintenance', __( 'Maintenance', 'cliezoo' ) ),
				);
				$preset['client_folders']  = array( __( 'Documents', 'cliezoo' ), __( 'Site photos', 'cliezoo' ) );
				$preset['project_folders'] = array( __( 'Plans', 'cliezoo' ), __( 'Permits', 'cliezoo' ), __( 'Before and after', 'cliezoo' ), __( 'Sign-off', 'cliezoo' ) );
				break;
			case 'app_web_development':
				$preset['categories']      = array(
					array( 'web_design', __( 'Web design', 'cliezoo' ) ),
					array( 'app_development', __( 'App development', 'cliezoo' ) ),
					array( 'ecommerce', __( 'E-commerce', 'cliezoo' ) ),
					array( 'maintenance', __( 'Maintenance', 'cliezoo' ) ),
				);
				$preset['project_folders'] = array( __( 'Specification', 'cliezoo' ), __( 'Designs', 'cliezoo' ), __( 'Test builds', 'cliezoo' ), __( 'Release', 'cliezoo' ) );
				break;
			case 'healthcare':
			case 'wellness':
				$preset['categories']      = array(
					array( 'programme', __( 'Programme', 'cliezoo' ) ),
					array( 'course_of_treatment', __( 'Course of treatment', 'cliezoo' ) ),
					array( 'consultation', __( 'Consultation', 'cliezoo' ) ),
				);
				$preset['client_folders']  = array( __( 'Consent forms', 'cliezoo' ), __( 'Documents', 'cliezoo' ) );
				$preset['project_folders'] = array( __( 'Plan', 'cliezoo' ), __( 'Progress notes', 'cliezoo' ) );
				break;
			case 'education':
				$preset['categories']      = array(
					array( 'course', __( 'Course', 'cliezoo' ) ),
					array( 'tutoring', __( 'Tutoring', 'cliezoo' ) ),
					array( 'workshop', __( 'Workshop', 'cliezoo' ) ),
				);
				$preset['project_folders'] = array( __( 'Materials', 'cliezoo' ), __( 'Assignments', 'cliezoo' ), __( 'Feedback', 'cliezoo' ) );
				break;
			case 'events':
			case 'hospitality':
				$preset['categories']      = array(
					array( 'event', __( 'Event', 'cliezoo' ) ),
					array( 'wedding', __( 'Wedding', 'cliezoo' ) ),
					array( 'corporate_event', __( 'Corporate event', 'cliezoo' ) ),
					array( 'booking', __( 'Booking', 'cliezoo' ) ),
				);
				$preset['project_folders'] = array( __( 'Run sheet', 'cliezoo' ), __( 'Suppliers', 'cliezoo' ), __( 'Photos', 'cliezoo' ) );
				break;
			case 'photography_videography':
				$preset['categories']      = array(
					array( 'photo_shoot', __( 'Photo shoot', 'cliezoo' ) ),
					array( 'video', __( 'Video', 'cliezoo' ) ),
					array( 'wedding', __( 'Wedding', 'cliezoo' ) ),
					array( 'editing', __( 'Editing', 'cliezoo' ) ),
				);
				$preset['project_folders'] = array( __( 'Shot list', 'cliezoo' ), __( 'Raw', 'cliezoo' ), __( 'Proofs', 'cliezoo' ), __( 'Final delivery', 'cliezoo' ) );
				break;
			case 'recruiting':
				$preset['categories']      = array(
					array( 'search', __( 'Search', 'cliezoo' ) ),
					array( 'permanent_placement', __( 'Permanent placement', 'cliezoo' ) ),
					array( 'contract_placement', __( 'Contract placement', 'cliezoo' ) ),
				);
				$preset['project_folders'] = array( __( 'Job description', 'cliezoo' ), __( 'Shortlist', 'cliezoo' ), __( 'Offers', 'cliezoo' ) );
				break;
			case 'manufacturing':
				$preset['categories']      = array(
					array( 'order', __( 'Order', 'cliezoo' ) ),
					array( 'prototype', __( 'Prototype', 'cliezoo' ) ),
					array( 'production_run', __( 'Production run', 'cliezoo' ) ),
				);
				$preset['project_folders'] = array( __( 'Specifications', 'cliezoo' ), __( 'Drawings', 'cliezoo' ), __( 'Quality checks', 'cliezoo' ) );
				break;
			case 'retail_ecommerce':
				$preset['categories']      = array(
					array( 'ecommerce', __( 'E-commerce', 'cliezoo' ) ),
					array( 'store_setup', __( 'Store set-up', 'cliezoo' ) ),
					array( 'product_launch', __( 'Product launch', 'cliezoo' ) ),
				);
				$preset['project_folders'] = array( __( 'Product photos', 'cliezoo' ), __( 'Listings', 'cliezoo' ), __( 'Launch', 'cliezoo' ) );
				break;
		}
		return $preset;
	}

	/**
	 * Adds what an industry starts with to the workspace.
	 *
	 * @param string $industry Industry slug.
	 * @return array{categories: string[], client_folders: bool, project_folders: bool} What changed.
	 */
	public function apply( string $industry ): array {
		$preset  = self::preset( $industry );
		$changed = array(
			'categories'      => array(),
			'client_folders'  => false,
			'project_folders' => false,
		);
		if ( $preset['categories'] && $this->plugin->entities()->has( 'taxonomy_terms' ) ) {
			$lists    = $this->plugin->get( \Cliezoo\Platform\Taxonomies\Taxonomies::class );
			$existing = array_column( $lists->values( 'project_category' ), 'value' );
			$repo     = $this->plugin->service( 'taxonomy_terms' )->repository();
			$position = count( $existing );
			foreach ( $preset['categories'] as $category ) {
				if ( in_array( $category[0], $existing, true ) ) {
					continue;
				}
				$repo->insert(
					array(
						'list'       => 'project_category',
						'value'      => $category[0],
						'label'      => $category[1],
						'position'   => $position++,
						'is_default' => 0,
					)
				);
				$existing[]              = $category[0];
				$changed['categories'][] = $category[0];
			}
			$lists->flush();
		}
		$settings = $this->plugin->settings();
		if ( in_array( 'files', $settings->modules(), true ) ) {
			$schema = $settings->schema( 'files' );
			$update = array();
			foreach ( array( 'client_folders', 'project_folders' ) as $key ) {
				// Only a list nobody has changed is replaced.
				if ( $preset[ $key ] && $settings->get( 'files', $key ) === ( $schema[ $key ]['default'] ?? null ) ) {
					$update[ $key ]  = $preset[ $key ];
					$changed[ $key ] = true;
				}
			}
			if ( $update ) {
				$settings->update( 'files', $update );
			}
		}
		return $changed;
	}
}
