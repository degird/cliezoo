/**
 * Core Settings sections, registered when Settings first opens (keeps the
 * start-up bundle small). Add-ons register theirs at start-up.
 */
import { __ } from '@wordpress/i18n';
import {
	BellRing,
	Blocks,
	Boxes,
	FileCode2,
	FolderTree,
	Hash,
	Mail,
	MailOpen,
	MailCheck,
	ListOrdered,
	Building2,
	Globe,
	KeyRound,
	Palette,
	ScrollText,
	Server,
	ShieldCheck,
	Stethoscope,
	Stamp,
	Target,
	Users,
} from 'lucide-react';
import type { Boot } from '@cliezoo/data';
import { registerSettingsSection } from '../../packages/app/registry';

let done = false;

export function registerCoreSettings(): void {
	if ( done ) {
		return;
	}
	done = true;
	const admin = ( boot: Boot ) => !! boot.user?.admin;
	const toggle = ( name: string ) => ( boot: Boot ) =>
		!! boot.user &&
		( boot.user.admin || !! boot.user.permissions.toggles[ name ] );
	registerSettingsSection( [
		{
			id: 'general',
			label: __( 'General', 'cliezoo' ),
			description: __( 'Workspace name and contact details', 'cliezoo' ),
			group: 'workspace',
			icon: Building2,
			order: 1,
			visible: toggle( 'settings_general' ),
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/General'
				),
		},
		{
			id: 'localization',
			label: __( 'Localization', 'cliezoo' ),
			description: __(
				'Time zone, currency, number and date formats',
				'cliezoo'
			),
			group: 'workspace',
			icon: Globe,
			order: 2,
			visible: toggle( 'settings_general' ),
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/Localization'
				),
		},
		{
			id: 'branding',
			label: __( 'Branding', 'cliezoo' ),
			description: __( 'Logo, colours and the sign-in page', 'cliezoo' ),
			group: 'workspace',
			icon: Palette,
			order: 3,
			visible: toggle( 'settings_general' ),
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/Branding'
				),
		},
		{
			id: 'modules',
			label: __( 'Modules', 'cliezoo' ),
			description: __( 'Turn parts of the app on or off', 'cliezoo' ),
			group: 'workspace',
			icon: Blocks,
			order: 4,
			visible: toggle( 'settings_general' ),
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/Modules'
				),
		},
		{
			id: 'email',
			label: __( 'Email', 'cliezoo' ),
			description: __( 'Sender name and from-address', 'cliezoo' ),
			group: 'workspace',
			icon: Mail,
			order: 5,
			visible: toggle( 'email_templates' ),
			component: () =>
				import( /* webpackChunkName: "settings" */ './sections/Email' ),
		},
		{
			id: 'numbering',
			label: __( 'Numbering', 'cliezoo' ),
			description: __(
				'How client, lead and other record numbers look',
				'cliezoo'
			),
			group: 'workspace',
			icon: Hash,
			order: 6,
			visible: toggle( 'settings_general' ),
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/Numbering'
				),
		},
		{
			id: 'work',
			label: __( 'Work', 'cliezoo' ),
			description: __(
				'The folders new clients start with',
				'cliezoo'
			),
			group: 'work',
			icon: FolderTree,
			order: 0,
			visible: ( boot ) =>
				admin( boot ) && boot.modules.includes( 'files' ),
			component: () =>
				import( /* webpackChunkName: "settings" */ './sections/Work' ),
		},
		{
			id: 'sales',
			label: __( 'Sales', 'cliezoo' ),
			description: __(
				'How a lead is judged, and when one has gone quiet',
				'cliezoo'
			),
			group: 'work',
			icon: Target,
			order: 1,
			// Both halves are about selling, so the screen is offered when
			// either part of it is switched on and says so about the other.
			visible: ( boot ) =>
				boot.modules.includes( 'leads' ) ||
				boot.modules.includes( 'deals' ),
			component: () =>
				import( /* webpackChunkName: "settings" */ './sections/Sales' ),
		},
		{
			id: 'email-templates',
			label: __( 'Email templates', 'cliezoo' ),
			description: __(
				'Every email the workspace sends, beside what it will look like',
				'cliezoo'
			),
			group: 'work',
			icon: MailOpen,
			order: 5,
			visible: toggle( 'email_templates' ),
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/EmailTemplates'
				),
		},
		{
			id: 'email-log',
			label: __( 'Email log', 'cliezoo' ),
			description: __(
				'What left the building, and what went wrong where it did not',
				'cliezoo'
			),
			group: 'work',
			icon: MailCheck,
			order: 6,
			visible: ( boot ) => admin( boot ),
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/EmailLog'
				),
		},
		{
			id: 'templates',
			label: __( 'Templates', 'cliezoo' ),
			description: __(
				'Every template in the workspace, and where each one lives',
				'cliezoo'
			),
			group: 'work',
			icon: Stamp,
			order: 1,
			visible: toggle( 'templates_manage' ),
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/Templates'
				),
		},
		{
			id: 'data-model',
			label: __( 'Data model', 'cliezoo' ),
			description: __( 'Custom fields on your records', 'cliezoo' ),
			group: 'work',
			icon: Boxes,
			order: 1,
			visible: toggle( 'settings_custom_fields' ),
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/CustomFields'
				),
		},
		{
			id: 'lists',
			label: __( 'Editable lists', 'cliezoo' ),
			description: __(
				'Statuses, stages, priorities and other choices',
				'cliezoo'
			),
			group: 'work',
			icon: ListOrdered,
			order: 2,
			visible: toggle( 'settings_custom_fields' ),
			component: () =>
				import( /* webpackChunkName: "settings" */ './sections/Lists' ),
		},
		{
			id: 'reminders',
			label: __( 'Reminders', 'cliezoo' ),
			description: __(
				'Schedules that chase dates on your records',
				'cliezoo'
			),
			group: 'work',
			icon: BellRing,
			order: 3,
			visible: toggle( 'settings_general' ),
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/RemindersRules'
				),
		},
		{
			id: 'team',
			label: __( 'Team', 'cliezoo' ),
			description: __( 'Members, invitations and roles', 'cliezoo' ),
			group: 'people',
			icon: Users,
			order: 1,
			visible: toggle( 'settings_members' ),
			component: () =>
				import( /* webpackChunkName: "settings" */ './sections/Team' ),
		},
		{
			id: 'security',
			label: __( 'Security', 'cliezoo' ),
			description: __(
				'Passwords, two-step verification and sign-in',
				'cliezoo'
			),
			group: 'people',
			icon: ShieldCheck,
			order: 2,
			visible: admin,
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/Security'
				),
		},
		{
			id: 'access',
			label: __( 'Access', 'cliezoo' ),
			description: __(
				'What each person can open, and taking it back',
				'cliezoo'
			),
			group: 'people',
			icon: KeyRound,
			order: 4,
			visible: toggle( 'settings_members' ),
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/AccessInventory'
				),
		},
		{
			id: 'audit-log',
			label: __( 'Audit log', 'cliezoo' ),
			description: __(
				'Sign-ins, permission changes and other security events',
				'cliezoo'
			),
			group: 'people',
			icon: ScrollText,
			order: 3,
			visible: toggle( 'audit_view' ),
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/AuditLog'
				),
		},
		{
			id: 'health',
			label: __( 'Setup health', 'cliezoo' ),
			description: __(
				'Whether jobs, email and the app address are working',
				'cliezoo'
			),
			group: 'advanced',
			icon: Stethoscope,
			order: 2,
			visible: admin,
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/HealthSection'
				),
		},
		{
			id: 'advanced',
			label: __( 'Site and data', 'cliezoo' ),
			description: __( 'App addresses, Safe mode and data', 'cliezoo' ),
			group: 'advanced',
			icon: Server,
			order: 1,
			visible: admin,
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/Advanced'
				),
		},
		{
			id: 'api',
			label: __( 'API', 'cliezoo' ),
			description: __(
				'REST API documentation, limits and changelog',
				'cliezoo'
			),
			group: 'connect',
			icon: FileCode2,
			order: 3,
			// The public API is Free; its docs are for whoever builds on it.
			visible: admin,
			component: () =>
				import(
					/* webpackChunkName: "settings" */ './sections/ApiDocs'
				),
		},
	] );
}
