/**
 * The Clients module's registrations: its screens, where it sits in the
 * sidebar, how a client is linked to, and the tabs it puts on its own page.
 *
 * Other modules add their tabs to the same page — Contacts adds "Contacts"
 * — so this file never has to know about them.
 */
import { lazy } from 'react';
import { __ } from '@wordpress/i18n';
import { Building2 } from 'lucide-react';
import {
	registerCommand,
	registerNavItems,
	registerRecordLink,
	registerRecordTabs,
	registerRoutes,
} from '../../packages/app/registry';

export function registerClients(): void {
	registerRoutes( [
		{
			path: '/clients',
			title: __( 'Clients', 'cliezoo' ),
			module: 'clients',
			component: () =>
				import( /* webpackChunkName: "clients" */ './ClientsScreen' ),
		},
		{
			// The tab, and a folder in Files, are part of the address
			// (/clients/{uid}/files/{folder}); one route, so moving between
			// them keeps the page as it is.
			path: '/clients/$uid/{-$tab}/{-$folder}',
			title: __( 'Client', 'cliezoo' ),
			module: 'clients',
			component: () =>
				import( /* webpackChunkName: "clients" */ './ClientScreen' ),
		},
	] );

	registerNavItems( [
		{
			id: 'clients',
			label: __( 'Clients', 'cliezoo' ),
			to: '/clients',
			icon: Building2,
			group: 'sales',
			order: 10,
			module: 'clients',
			mobile: true,
		},
	] );

	registerRecordLink( 'clients', ( uid ) => `/clients/${ uid }` );

	registerRecordTabs( [
		{
			id: 'clients.activity',
			type: 'clients',
			label: __( 'Activity', 'cliezoo' ),
			order: 20,
			component: lazy(
				() =>
					import( /* webpackChunkName: "clients" */ './ActivityTab' )
			),
		},
	] );

	registerCommand( {
		id: 'clients.new',
		label: __( 'New client', 'cliezoo' ),
		group: 'create',
		keywords: [ 'client', 'account', 'company', 'customer' ],
		run: ( { navigate } ) => navigate( '/clients?new=1' ),
	} );
}
