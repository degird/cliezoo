/**
 * The Files module's registrations: the area itself, and the Files tab it
 * puts on a client's and a project's page.
 */
import { lazy } from 'react';
import { __ } from '@wordpress/i18n';
import { FolderOpen } from 'lucide-react';
import { can } from '@cliezoo/data';
import {
	registerCommand,
	registerNavItems,
	registerRecordLink,
	registerRecordTabs,
	registerRoutes,
	registerSlotFill,
} from '../../packages/app/registry';

export function registerFiles(): void {
	registerRoutes( [
		{
			path: '/files',
			// Offered to clients in the navigation, so theirs to open.
			audience: 'all',
			title: __( 'Files', 'cliezoo' ),
			module: 'files',
			component: () =>
				import( /* webpackChunkName: "files" */ './FilesScreen' ),
		},
	] );

	registerNavItems( [
		{
			id: 'files',
			label: __( 'Files', 'cliezoo' ),
			to: '/files',
			icon: FolderOpen,
			group: 'documents',
			order: 10,
			module: 'files',
			audience: 'all',
			mobile: true,
		},
	] );

	registerRecordLink( 'folders', ( uid ) => `/files?folder=${ uid }` );
	registerRecordLink( 'files', ( uid ) => `/files?file=${ uid }` );

	registerRecordTabs( [
		{
			id: 'clients.files',
			type: 'clients',
			label: __( 'Files', 'cliezoo' ),
			order: 40,
			module: 'files',
			component: lazy(
				() => import( /* webpackChunkName: "files" */ './FilesTab' )
			),
		},
		{
			id: 'projects.files',
			type: 'projects',
			label: __( 'Files', 'cliezoo' ),
			order: 40,
			module: 'files',
			// A client sees what is theirs here; the server says what that is.
			audience: 'all',
			component: lazy(
				() => import( /* webpackChunkName: "files" */ './FilesTab' )
			),
		},
	] );

	// The papers that belong with a record, on that record's own screen.
	// Fetched when the slot is first drawn rather than imported by the screen
	// that hosts it: an invoice importing the file library would put chunked
	// uploads in the bundle every screen waits for.
	registerSlotFill( 'record.attachments', {
		id: 'files.attachments',
		module: 'files',
		component: lazy( () =>
			import(
				/* webpackChunkName: "files" */ './RecordAttachments'
			).then( ( m ) => ( { default: m.RecordAttachments } ) )
		),
	} );

	registerCommand( {
		id: 'files.open',
		label: __( 'Files', 'cliezoo' ),
		group: __( 'Go to', 'cliezoo' ),
		keywords: [ 'file', 'folder', 'upload', 'document', 'asset' ],
		run: ( { navigate } ) => navigate( '/files' ),
	} );

	// "+ New" → Link (cliezoo.md §6.15, creating items): filed in the Files
	// area, where its dialog opens straight away.
	registerCommand( {
		id: 'files.new-link',
		label: __( 'New link', 'cliezoo' ),
		group: 'create',
		keywords: [ 'link', 'url', 'figma', 'drive', 'bookmark' ],
		visible: ( boot ) =>
			boot.modules.includes( 'files' ) &&
			! boot.user?.client &&
			can.write( boot.user ),
		run: ( { navigate } ) => navigate( '/files?new=link' ),
	} );
}
