/**
 * The Dashboard module's registrations: the dashboard, its cards, and
 * what it adds to Home — what I opened lately and what is on me.
 */
import { lazy } from 'react';
import { __ } from '@wordpress/i18n';
import { LayoutDashboard } from 'lucide-react';
import {
	registerCommand,
	registerNavItems,
	registerRoutes,
	registerSlotFill,
	registerWidget,
} from '../../packages/app/registry';

const chunk = () =>
	import( /* webpackChunkName: "dashboard-widgets" */ './widgets/index' );

export function registerDashboard(): void {
	registerRoutes( [
		{
			path: '/dashboard',
			title: __( 'Dashboard', 'cliezoo' ),
			module: 'dashboard',
			component: () =>
				import(
					/* webpackChunkName: "dashboard" */ './DashboardScreen'
				),
		},
	] );

	// The addresses the spec gives, kept working: the business dashboard is
	// /overview and its calendar is /calendar.
	registerRoutes( [
		{
			path: '/overview',
			title: __( 'Dashboard', 'cliezoo' ),
			module: 'dashboard',
			redirectTo: () => ( { to: '/dashboard' } ),
		},
	] );

	registerNavItems( [
		{
			id: 'dashboard',
			label: __( 'Dashboard', 'cliezoo' ),
			to: '/dashboard',
			icon: LayoutDashboard,
			group: 'personal',
			order: 2,
			module: 'dashboard',
		},
	] );

	registerCommand( {
		id: 'dashboard.open',
		label: __( 'Open the dashboard', 'cliezoo' ),
		keywords: [ 'numbers', 'overview', 'health', 'metrics' ],
		visible: ( boot ) => boot.modules.includes( 'dashboard' ),
		run: ( { navigate } ) => navigate( '/dashboard' ),
	} );

	// The server says which of these each person may have, and where. Its
	// title is the one that renders, so these have to be the same words:
	// two names for one card is two cards as far as anybody reading is
	// concerned.
	const cards: Array< {
		id: string;
		title: string;
		size: 'sm' | 'md' | 'lg';
		part: keyof Awaited< ReturnType< typeof chunk > >;
	} > = [
		{
			id: 'counters',
			title: __( 'Key numbers', 'cliezoo' ),
			size: 'lg',
			part: 'Counters',
		},
		{
			id: 'revenue',
			title: __( 'Money', 'cliezoo' ),
			size: 'lg',
			part: 'Revenue',
		},
		{
			id: 'ar-ageing',
			title: __( 'How old the debt is', 'cliezoo' ),
			size: 'md',
			part: 'Ageing',
		},
		{
			id: 'pipeline',
			title: __( 'In the pipeline', 'cliezoo' ),
			size: 'md',
			part: 'Pipeline',
		},
		{
			id: 'waiting',
			title: __( 'Waiting on somebody', 'cliezoo' ),
			size: 'sm',
			part: 'Waiting',
		},
		{
			id: 'hours',
			title: __( 'Hours', 'cliezoo' ),
			size: 'md',
			part: 'Hours',
		},
		{
			id: 'my-work',
			title: __( 'My work', 'cliezoo' ),
			size: 'md',
			part: 'MyWork',
		},
		{
			id: 'tasks',
			title: __( 'Tasks', 'cliezoo' ),
			size: 'md',
			part: 'Tasks',
		},
		{
			id: 'overdue-tasks',
			title: __( 'Overdue tasks', 'cliezoo' ),
			size: 'sm',
			part: 'OverdueTasks',
		},
		{
			id: 'month-on-month',
			title: __( 'This month and last', 'cliezoo' ),
			size: 'md',
			part: 'MonthOnMonth',
		},
		{
			id: 'health',
			title: __( 'Business health', 'cliezoo' ),
			size: 'sm',
			part: 'Health',
		},
		{
			id: 'open-requests',
			title: __( 'Open requests', 'cliezoo' ),
			size: 'sm',
			part: 'OpenRequests',
		},
		{
			id: 'unread',
			title: __( 'Unread conversations', 'cliezoo' ),
			size: 'sm',
			part: 'Unread',
		},
		{
			id: 'recent-items',
			title: __( 'Recently opened', 'cliezoo' ),
			size: 'md',
			part: 'RecentItems',
		},
		{
			id: 'activity',
			title: __( 'Recent activity', 'cliezoo' ),
			size: 'md',
			part: 'Activity',
		},
	];
	cards.forEach( ( card ) =>
		registerWidget( {
			id: card.id,
			title: card.title,
			size: card.size,
			place: 'dashboard',
			component: () =>
				chunk().then( ( parts ) => ( {
					default: parts[ card.part ],
				} ) ),
		} )
	);

	registerSlotFill( 'home.strip', {
		id: 'dashboard.recent-strip',
		module: 'dashboard',
		component: lazy( () =>
			chunk().then( ( parts ) => ( { default: parts.RecentStrip } ) )
		),
	} );
	registerSlotFill( 'home.work', {
		id: 'dashboard.my-work',
		module: 'dashboard',
		component: lazy( () =>
			chunk().then( ( parts ) => ( { default: parts.HomeWork } ) )
		),
	} );
	registerSlotFill( 'home.recent', {
		id: 'dashboard.recent-items',
		module: 'dashboard',
		component: lazy( () =>
			chunk().then( ( parts ) => ( { default: parts.HomeRecent } ) )
		),
	} );
}
