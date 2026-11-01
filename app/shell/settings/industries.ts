/**
 * The twenty industries of cliezoo.md §6.38 and the company size bands,
 * shared by Settings → General and the setup wizard. The ids match
 * IndustryPresets::INDUSTRIES on the server, which is what validates them.
 */
import { __ } from '@wordpress/i18n';

export const INDUSTRIES = [
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
] as const;

/**
 * An industry's name.
 *
 * @param id Industry id.
 */
export function industryName( id: string ): string {
	const names: Record< string, string > = {
		it_services: __( 'IT services', 'cliezoo' ),
		marketing_design: __( 'Marketing and design', 'cliezoo' ),
		accounting: __( 'Accounting', 'cliezoo' ),
		legal: __( 'Legal', 'cliezoo' ),
		consulting: __( 'Consulting', 'cliezoo' ),
		real_estate: __( 'Real estate', 'cliezoo' ),
		construction: __( 'Construction', 'cliezoo' ),
		home_field_services: __( 'Home and field services', 'cliezoo' ),
		app_web_development: __( 'App and web development', 'cliezoo' ),
		healthcare: __( 'Healthcare', 'cliezoo' ),
		education: __( 'Education', 'cliezoo' ),
		events: __( 'Events', 'cliezoo' ),
		creative_services: __( 'Creative services', 'cliezoo' ),
		photography_videography: __( 'Photography and videography', 'cliezoo' ),
		wellness: __( 'Wellness', 'cliezoo' ),
		recruiting: __( 'Recruiting', 'cliezoo' ),
		manufacturing: __( 'Manufacturing', 'cliezoo' ),
		retail_ecommerce: __( 'Retail and e-commerce', 'cliezoo' ),
		hospitality: __( 'Hospitality', 'cliezoo' ),
		other: __( 'Other', 'cliezoo' ),
	};
	return names[ id ] ?? id;
}

/** Company sizes, as the server stores them. */
export const COMPANY_SIZES = [
	'1-10',
	'11-50',
	'51-200',
	'201-500',
	'501-1000',
	'1001-5000',
	'5000+',
] as const;
