/**
 * Core routes, navigation and commands. Feature modules register theirs in
 * app/modules/{module}/index.ts (and add-ons through window.cliezoo.app).
 */
import { __ } from '@wordpress/i18n';
import {
	BellRing,
	Home,
	Inbox,
	Settings,
	UserRound,
} from 'lucide-react';
import {
	registerCommand,
	registerNavItems,
	registerRoutes,
	registerWidget,
} from '../packages/app/registry';
import { setThemePreference } from '@cliezoo/data';

export function registerCore(): void {
	registerRoutes( [
		{
			path: '/',
			title: __( 'Home', 'cliezoo' ),
			audience: 'all',
			component: () =>
				import( /* webpackChunkName: "home" */ './screens/Home' ),
		},
		{
			// The tab is part of the address: /inbox/mentions.
			path: '/inbox/{-$tab}',
			title: __( 'Inbox', 'cliezoo' ),
			audience: 'all',
			component: () =>
				import( /* webpackChunkName: "inbox" */ './screens/Inbox' ),
		},
		{
			path: '/my-work',
			title: __( 'My work', 'cliezoo' ),
			audience: 'all',
			redirectTo: () => ( { to: '/' } ),
		},
		{
			path: '/reminders',
			title: __( 'Reminders', 'cliezoo' ),
			audience: 'all',
			component: () =>
				import(
					/* webpackChunkName: "reminders" */ './screens/Reminders'
				),
		},
		{
			path: '/account',
			title: __( 'Account', 'cliezoo' ),
			audience: 'all',
			component: () =>
				import( /* webpackChunkName: "account" */ './screens/Account' ),
		},
		{
			path: '/setup',
			title: __( 'Set up Cliezoo', 'cliezoo' ),
			layout: 'bare',
			visible: ( boot ) => !! boot.user?.admin,
			component: () =>
				import( /* webpackChunkName: "setup" */ './screens/Setup' ),
		},
		{
			path: '/login',
			title: __( 'Sign in', 'cliezoo' ),
			audience: 'guest',
			layout: 'bare',
			component: () =>
				import( /* webpackChunkName: "auth" */ './screens/auth/Login' ),
		},
		{
			path: '/auth/magic/$token',
			title: __( 'Sign in', 'cliezoo' ),
			audience: 'guest',
			layout: 'bare',
			component: () =>
				import(
					/* webpackChunkName: "auth" */ './screens/auth/MagicContinue'
				),
		},
		{
			path: '/auth/reset',
			title: __( 'Reset password', 'cliezoo' ),
			audience: 'guest',
			layout: 'bare',
			component: () =>
				import(
					/* webpackChunkName: "auth" */ './screens/auth/ResetRequest'
				),
		},
		{
			path: '/auth/reset/$token',
			title: __( 'Choose a new password', 'cliezoo' ),
			audience: 'guest',
			layout: 'bare',
			component: () =>
				import(
					/* webpackChunkName: "auth" */ './screens/auth/ResetConfirm'
				),
		},
		{
			path: '/auth/2fa',
			title: __( 'Two-step verification', 'cliezoo' ),
			audience: 'guest',
			layout: 'bare',
			component: () =>
				import(
					/* webpackChunkName: "auth" */ './screens/auth/TwoFactorScreen'
				),
		},
		{
			path: '/auth/invite/$token',
			title: __( 'Accept invitation', 'cliezoo' ),
			audience: 'guest',
			layout: 'bare',
			component: () =>
				import(
					/* webpackChunkName: "auth" */ './screens/auth/AcceptInvite'
				),
		},
	] );

	registerRoutes( [
		{
			path: '/settings',
			title: __( 'Settings', 'cliezoo' ),
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './settings/SettingsPage'
				).then( ( m ) => ( { default: m.SettingsIndex } ) ),
		},
		{
			path: '/settings/$section',
			title: __( 'Settings', 'cliezoo' ),
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './settings/SettingsPage'
				),
		},
	] );

	registerNavItems( [
		{
			id: 'home',
			label: __( 'Home', 'cliezoo' ),
			to: '/',
			icon: Home,
			group: 'personal',
			order: 1,
			audience: 'all',
			mobile: true,
		},
		{
			id: 'inbox',
			label: __( 'Inbox', 'cliezoo' ),
			to: '/inbox',
			icon: Inbox,
			group: 'personal',
			order: 2,
			audience: 'all',
			mobile: true,
		},
		{
			id: 'reminders',
			label: __( 'Reminders', 'cliezoo' ),
			to: '/reminders',
			icon: BellRing,
			group: 'personal',
			order: 3,
			audience: 'all',
		},
		{
			id: 'account',
			label: __( 'Account', 'cliezoo' ),
			to: '/account',
			icon: UserRound,
			group: 'settings',
			order: 90,
			audience: 'client',
		},
		{
			id: 'settings',
			label: __( 'Settings', 'cliezoo' ),
			to: '/settings',
			icon: Settings,
			group: 'settings',
			order: 99,
			visible: ( boot ) =>
				!! boot.user &&
				( boot.user.admin ||
					Object.entries( boot.user.permissions.toggles ).some(
						( [ name, on ] ) => on && name.startsWith( 'settings_' )
					) ),
		},
	] );

	registerWidget( {
		id: 'setup-checklist',
		title: __( 'Finish setting up', 'cliezoo' ),
		size: 'md',
		component: () =>
			import(
				/* webpackChunkName: "setup" */ './widgets/SetupChecklist'
			),
	} );

	registerWidget( {
		id: 'achievements',
		title: __( 'What you have got done', 'cliezoo' ),
		size: 'md',
		component: () =>
			import( /* webpackChunkName: "setup" */ './widgets/Achievements' ),
	} );

	registerCommand( [
		{
			id: 'theme.light',
			label: __( 'Switch to light theme', 'cliezoo' ),
			keywords: [ 'appearance', 'mode' ],
			audience: 'all',
			run: () => setThemePreference( 'light' ),
		},
		{
			id: 'theme.dark',
			label: __( 'Switch to dark theme', 'cliezoo' ),
			keywords: [ 'appearance', 'mode', 'night' ],
			audience: 'all',
			run: () => setThemePreference( 'dark' ),
		},
		{
			id: 'theme.system',
			label: __( 'Use the system theme', 'cliezoo' ),
			keywords: [ 'appearance', 'mode', 'auto' ],
			audience: 'all',
			run: () => setThemePreference( 'system' ),
		},
	] );
}
