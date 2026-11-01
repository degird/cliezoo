/**
 * Settings → Work: the folders every new client and every new project
 * starts with. An empty list is allowed; it means no folders until
 * somebody makes one. Existing clients and projects keep what they have.
 */
import { __, sprintf } from '@wordpress/i18n';
import { ArrowDown, ArrowUp, Plus, X } from 'lucide-react';
import { Button, IconButton, TextField } from '@cliezoo/ui';
import { Fieldset, SettingsForm, useSettings } from '../useSettings';

/** The most folders a list may have (the server says the same). */
const MAX = 20;

/**
 * What is wrong with a folder list, if anything, before the server says so.
 *
 * @param names Folder names.
 */
export function folderListProblem( names: string[] ): string | undefined {
	const trimmed = names.map( ( name ) => name.trim() );
	if ( trimmed.length > MAX ) {
		return __( 'Twenty folders at most.', 'cliezoo' );
	}
	if (
		trimmed.some(
			( name ) => '' === name || name.length > 120 || /[\\/]/.test( name )
		)
	) {
		return __(
			'Each folder needs a name of up to 120 characters, without / or \\.',
			'cliezoo'
		);
	}
	const lower = trimmed.map( ( name ) => name.toLowerCase() );
	if ( new Set( lower ).size !== lower.length ) {
		return __( 'Two folders have the same name.', 'cliezoo' );
	}
	return undefined;
}

function FolderList( {
	label,
	names,
	onChange,
	error,
}: {
	label: string;
	names: string[];
	onChange: ( names: string[] ) => void;
	error?: string;
} ) {
	const move = ( from: number, to: number ) => {
		const next = [ ...names ];
		const [ item ] = next.splice( from, 1 );
		next.splice( to, 0, item );
		onChange( next );
	};
	const problem = error ?? folderListProblem( names );
	return (
		<div className="sm:col-span-2">
			<p className="mb-2 text-sm font-medium">{ label }</p>
			{ 0 === names.length && (
				<p className="mb-2 text-sm text-muted">
					{ __( 'No folders: new ones start empty.', 'cliezoo' ) }
				</p>
			) }
			<ol className="space-y-2">
				{ names.map( ( name, index ) => (
					<li key={ index } className="flex items-center gap-1">
						<TextField
							className="flex-1"
							aria-label={ sprintf(
								/* translators: %d: position in the list, from 1. */
								__( 'Folder %d', 'cliezoo' ),
								index + 1
							) }
							value={ name }
							onChange={ ( value ) =>
								onChange(
									names.map( ( one, i ) =>
										i === index ? value : one
									)
								)
							}
						/>
						<IconButton
							label={ __( 'Move up', 'cliezoo' ) }
							icon={ <ArrowUp size={ 14 } aria-hidden="true" /> }
							size="sm"
							isDisabled={ 0 === index }
							onPress={ () => move( index, index - 1 ) }
						/>
						<IconButton
							label={ __( 'Move down', 'cliezoo' ) }
							icon={
								<ArrowDown size={ 14 } aria-hidden="true" />
							}
							size="sm"
							isDisabled={ index === names.length - 1 }
							onPress={ () => move( index, index + 1 ) }
						/>
						<IconButton
							label={ __( 'Remove', 'cliezoo' ) }
							icon={ <X size={ 14 } aria-hidden="true" /> }
							size="sm"
							onPress={ () =>
								onChange(
									names.filter( ( _, i ) => i !== index )
								)
							}
						/>
					</li>
				) ) }
			</ol>
			<Button
				className="mt-2"
				variant="ghost"
				size="sm"
				icon={ <Plus size={ 14 } aria-hidden="true" /> }
				isDisabled={ names.length >= MAX }
				onPress={ () => onChange( [ ...names, '' ] ) }
			>
				{ __( 'Add a folder', 'cliezoo' ) }
			</Button>
			{ problem && (
				<p className="mt-1 text-sm text-danger" role="alert">
					{ problem }
				</p>
			) }
		</div>
	);
}

export default function Work() {
	const s = useSettings( 'files' );
	const list = ( key: string ) =>
		Array.isArray( s.values[ key ] ) ? ( s.values[ key ] as string[] ) : [];
	const ok =
		! folderListProblem( list( 'client_folders' ) ) &&
		! folderListProblem( list( 'project_folders' ) );
	return (
		<SettingsForm state={ s } canSave={ ok }>
			<Fieldset
				title={ __( 'Default folders', 'cliezoo' ) }
				description={ __(
					'Made for every new client and project. Clients and projects you already have keep their folders.',
					'cliezoo'
				) }
			>
				<FolderList
					label={ __( 'A new client starts with', 'cliezoo' ) }
					names={ list( 'client_folders' ) }
					onChange={ ( names ) => s.set( 'client_folders', names ) }
					error={ s.errors.client_folders }
				/>
				<FolderList
					label={ __( 'A new project starts with', 'cliezoo' ) }
					names={ list( 'project_folders' ) }
					onChange={ ( names ) => s.set( 'project_folders', names ) }
					error={ s.errors.project_folders }
				/>
			</Fieldset>
		</SettingsForm>
	);
}
