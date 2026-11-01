/**
 * The Contacts module's registrations, including the tab it adds to a page
 * it does not own: a client's Contacts.
 */
import { lazy } from 'react';
import { __ } from '@wordpress/i18n';
import { UserRound } from 'lucide-react';
import {
	registerCommand,
	registerNavItems,
	registerRecordLink,
	registerRecordTabs,
	registerRoutes,
} from '../../packages/app/registry';

export function registerContacts(): void {
	registerRoutes( [
		{
			path: '/contacts',
			title: __( 'Contacts', 'cliezoo' ),
			module: 'contacts',
			component: () =>
				import( /* webpackChunkName: "contacts" */ './ContactsScreen' ),
		},
		{
			path: '/contacts/$uid',
			title: __( 'Contact', 'cliezoo' ),
			module: 'contacts',
			component: () =>
				import( /* webpackChunkName: "contacts" */ './ContactScreen' ),
		},
	] );

	registerNavItems( [
		{
			id: 'contacts',
			label: __( 'Contacts', 'cliezoo' ),
			to: '/contacts',
			icon: UserRound,
			group: 'sales',
			order: 20,
			module: 'contacts',
		},
	] );

	registerRecordLink( 'contacts', ( uid ) => `/contacts/${ uid }` );

	registerRecordTabs( [
		{
			id: 'clients.contacts',
			type: 'clients',
			label: __( 'Contacts', 'cliezoo' ),
			order: 10,
			module: 'contacts',
			component: lazy(
				() =>
					import( /* webpackChunkName: "contacts" */ './ContactsTab' )
			),
		},
	] );

	registerCommand( {
		id: 'contacts.new',
		label: __( 'New contact', 'cliezoo' ),
		group: 'create',
		keywords: [ 'contact', 'person', 'people', 'portal user' ],
		run: ( { navigate } ) => navigate( '/contacts?new=1' ),
	} );
}
