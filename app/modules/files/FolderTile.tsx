/**
 * One folder, as something you can open: its cover, its colour, what is in
 * it, and everything you can do to it.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Folder } from 'lucide-react';
import { api, useQueryClient, type ApiError } from '@cliezoo/data';
import { cx, toast } from '@cliezoo/ui';
import { DRAGGED, draggedFiles } from './FileList';
import type { FolderRow } from './FileBrowser';
import { FolderMenu } from './FolderMenu';

export function FolderTile( {
	folder,
	onOpen,
	onChanged,
}: {
	folder: FolderRow;
	onOpen: () => void;
	onChanged: () => void;
} ) {
	const inside = [
		folder.files
			? sprintf(
					/* translators: %d: how many files. */
					_n( '%d file', '%d files', folder.files, 'cliezoo' ),
					folder.files
				)
			: '',
		folder.folders
			? sprintf(
					/* translators: %d: number of folders. */
					_n( '%d folder', '%d folders', folder.folders, 'cliezoo' ),
					folder.folders
				)
			: '',
	]
		.filter( Boolean )
		.join( ' · ' );
	const queries = useQueryClient();
	const [ menu, setMenu ] = useState( false );
	const [ over, setOver ] = useState( false );

	const dropInto = async ( files: string[] ) => {
		try {
			await api.post( '/files/move', { files, folder: folder.uid } );
			await queries.invalidateQueries( { queryKey: [ 'files' ] } );
			toast.success(
				sprintf(
					/* translators: %s: a folder name. */
					__( 'Moved into %s.', 'cliezoo' ),
					folder.name
				)
			);
			onChanged();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	return (
		<li
			className={ cx(
				'group relative overflow-hidden rounded-lg border bg-raised',
				over ? 'border-accent ring-2 ring-accent' : 'border-line'
			) }
			onContextMenu={ ( event ) => {
				event.preventDefault();
				setMenu( true );
			} }
			onDragOver={ ( event ) => {
				if ( event.dataTransfer.types.includes( DRAGGED ) ) {
					event.preventDefault();
					event.stopPropagation();
					event.dataTransfer.dropEffect = 'move';
					setOver( true );
				}
			} }
			onDragLeave={ () => setOver( false ) }
			onDrop={ ( event ) => {
				const files = draggedFiles( event.dataTransfer );
				if ( ! files.length ) {
					return;
				}
				event.preventDefault();
				event.stopPropagation();
				setOver( false );
				dropInto( files );
			} }
		>
			<button
				type="button"
				className="block w-full text-start outline-none focus-visible:ring-2 focus-visible:ring-accent"
				onClick={ onOpen }
			>
				{ ( !! folder.cover_url || !! folder.color ) && (
					<span
						className="block h-12 w-full bg-cover bg-center"
						style={
							folder.cover_url
								? {
										backgroundImage: `url("${ cssUrl( folder.cover_url ) }")`,
									}
								: { backgroundColor: folder.color ?? undefined }
						}
						aria-hidden="true"
					/>
				) }
				<span className="flex items-center gap-3 p-4">
					<Folder
						size={ 22 }
						className="shrink-0 text-accent-fg"
						style={
							folder.color ? { color: folder.color } : undefined
						}
						aria-hidden="true"
					/>
					<span className="min-w-0 flex-1">
						<span className="block truncate font-medium text-fg">
							{ folder.name }
						</span>
						<span className="block text-xs text-subtle">
							{ [
								folder.belongs_to,
								inside || __( 'Empty', 'cliezoo' ),
								folder.comments_off
									? __( 'comments off', 'cliezoo' )
									: '',
								folder.approvals_off
									? __( 'approvals off', 'cliezoo' )
									: '',
							]
								.filter( Boolean )
								.join( ' · ' ) }
						</span>
					</span>
				</span>
			</button>
			<div className="absolute end-2 top-2 rounded-md bg-raised/80">
				<FolderMenu
					folder={ folder }
					onChanged={ onChanged }
					isOpen={ menu }
					onOpenChange={ setMenu }
				/>
			</div>
		</li>
	);
}

/**
 * A URL made safe to sit inside url("…") in a style.
 *
 * @param url The URL.
 */
function cssUrl( url: string ): string {
	return url.replace( /["\\\n\r]/g, ( one ) => encodeURIComponent( one ) );
}
