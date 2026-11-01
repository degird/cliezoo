/**
 * The portal sections, named and explained for the portal settings
 * screen. Add-ons register theirs with `registerPortalSection`; a
 * section the server sends that nobody registered is still shown, by its
 * id, so a new server-side section is never hidden from the settings.
 */
import { __ } from '@wordpress/i18n';
import { humanise } from './list/cells';
import {
	portalSection,
	registerPortalSection,
	type PortalSectionDef,
} from './registry';

let done = false;

/** Registers the Free sections once. */
export function registerCorePortalSections(): void {
	if ( done ) {
		return;
	}
	done = true;
	registerPortalSection( [
		{
			id: 'projects',
			label: __( 'Projects', 'cliezoo' ),
			help: __( 'Their projects and the progress on them.', 'cliezoo' ),
			order: 10,
		},
		{
			id: 'tasks',
			label: __( 'Tasks', 'cliezoo' ),
			help: __(
				'Tasks they are on, and anything assigned to them.',
				'cliezoo'
			),
			order: 20,
		},
		{
			id: 'milestones',
			label: __( 'Milestones', 'cliezoo' ),
			order: 30,
		},
		{
			id: 'files',
			label: __( 'Files', 'cliezoo' ),
			help: __( 'Shared files: view, or upload and approve.', 'cliezoo' ),
			order: 40,
		},
		{
			id: 'conversations',
			label: __( 'Conversations', 'cliezoo' ),
			help: __( 'Messages with your team.', 'cliezoo' ),
			order: 50,
		},
		{
			id: 'documents',
			label: __( 'Documents', 'cliezoo' ),
			help: __( 'Quotes, proposals and contracts.', 'cliezoo' ),
			order: 60,
		},
		{
			id: 'invoices',
			label: __( 'Invoices', 'cliezoo' ),
			help: __( 'Invoices, payments and receipts.', 'cliezoo' ),
			order: 70,
		},
		{
			id: 'requests',
			label: __( 'Requests', 'cliezoo' ),
			help: __(
				'Requests they send you, and their progress.',
				'cliezoo'
			),
			order: 80,
		},
		{
			// Called what the client's own screen calls it. "Docs" sat five
			// rows from "Documents" in the same list and meant something
			// else entirely.
			id: 'docs',
			label: __( 'Knowledge base', 'cliezoo' ),
			help: __(
				'Knowledge base articles you publish to them.',
				'cliezoo'
			),
			order: 90,
		},
	] );
}

/**
 * What to call a section and how to explain it: its registration, or its
 * id made readable when nothing registered it.
 *
 * @param id Section id.
 */
export function portalSectionInfo( id: string ): PortalSectionDef {
	registerCorePortalSections();
	return portalSection( id ) ?? { id, label: humanise( id ), help: '' };
}
