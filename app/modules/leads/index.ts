/**
 * The Leads module's registrations.
 */
import { __ } from '@wordpress/i18n';
import { can } from '@cliezoo/data';
import { UserPlus } from 'lucide-react';
import {
	registerCommand,
	registerNavItems,
	registerRecordLink,
	registerRoutes,
} from '../../packages/app/registry';

export function registerLeads(): void {
	registerRoutes( [
		{
			path: '/leads',
			title: __( 'Leads', 'cliezoo' ),
			module: 'leads',
			component: () =>
				import( /* webpackChunkName: "leads" */ './LeadsScreen' ),
		},
		{
			path: '/leads/$uid',
			title: __( 'Lead', 'cliezoo' ),
			module: 'leads',
			component: () =>
				import( /* webpackChunkName: "leads" */ './LeadScreen' ),
		},
	] );

	registerNavItems( [
		{
			id: 'leads',
			label: __( 'Leads', 'cliezoo' ),
			to: '/leads',
			icon: UserPlus,
			group: 'sales',
			order: 5,
			module: 'leads',
		},
	] );

	registerRecordLink( 'leads', ( uid ) => `/leads/${ uid }` );

	registerCommand( {
		id: 'leads.open',
		label: __( 'Leads', 'cliezoo' ),
		group: __( 'Go to', 'cliezoo' ),
		keywords: [ 'lead', 'enquiry', 'enquiries', 'prospect', 'sales' ],
		run: ( { navigate } ) => navigate( '/leads' ),
	} );
	registerCommand( {
		id: 'leads.new',
		label: __( 'New lead', 'cliezoo' ),
		group: 'create',
		keywords: [ 'lead', 'enquiry', 'prospect' ],
		visible: ( boot ) => can.see( boot.user, 'leads' ),
		run: ( { navigate } ) => navigate( '/leads?new=1' ),
	} );
}
