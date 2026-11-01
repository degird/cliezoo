/**
 * Choosing the folder something moves into.
 *
 * A workspace that has been used for a year has more folders than one
 * page, and the one somebody wants is often late in the alphabet — so
 * the list pages through what there is and can be searched by name.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
// From the package, not from react-query itself: the query cache is a
// singleton the app provides, and a second copy of the library in this
// chunk would have no provider at all.
import { api, useQuery } from '@cliezoo/data';
import { SearchField, Select } from '@cliezoo/ui';

/** Folders per request, and how many requests one opening will make. */
const PAGE = 200;
const PAGES = 5;

export interface Place {
	uid: string;
	name: string;
	path?: string;
}

/**
 * Every folder, by name, up to what one opening will fetch.
 *
 * @param enabled Whether the dialog holding it is open.
 * @param q       What is being searched for.
 */
export function useFolderPlaces( enabled: boolean, q: string ) {
	const term = q.trim();
	return useQuery( {
		queryKey: [ 'files', 'folders', 'all', term ],
		enabled,
		queryFn: async () => {
			const found: Place[] = [];
			let cursor = '';
			for ( let page = 0; page < PAGES; page++ ) {
				const answer = await api.get< Place[] >( '/folders', {
					limit: PAGE,
					sort: 'name',
					fields: 'uid,name,path',
					...( term ? { q: term } : {} ),
					...( cursor ? { cursor } : {} ),
				} );
				found.push( ...answer.data );
				cursor = String( answer.meta?.next_cursor ?? '' );
				if ( ! cursor ) {
					break;
				}
			}
			return { rows: found, more: '' !== cursor };
		},
	} );
}

/**
 * The picker itself: a search box and the list of folders.
 *
 * @param root0          Props.
 * @param root0.value    The folder chosen so far ('' for the top).
 * @param root0.onChange Called with the chosen folder's uid.
 * @param root0.except   A folder that cannot be the destination (itself).
 * @param root0.enabled  Whether to fetch (the dialog is open).
 */
export function FolderPicker( {
	value,
	onChange,
	except = '',
	enabled = true,
}: {
	value: string;
	onChange: ( uid: string ) => void;
	except?: string;
	enabled?: boolean;
} ) {
	const [ q, setQ ] = useState( '' );
	const places = useFolderPlaces( enabled, q );
	const rows = ( places.data?.rows ?? [] ).filter(
		( one ) => one.uid !== except
	);

	return (
		<div className="space-y-2">
			<SearchField
				label={ __( 'Find a folder', 'cliezoo' ) }
				value={ q }
				onChange={ setQ }
			/>
			<Select
				label={ __( 'Into', 'cliezoo' ) }
				description={
					places.data?.more
						? sprintf(
								/* translators: %d: how many folders are listed. */
								__(
									'Showing %d folders. Search for the one you want.',
									'cliezoo'
								),
								rows.length
							)
						: undefined
				}
				items={ [
					{
						id: '',
						label: __( 'The top, in no folder', 'cliezoo' ),
					},
					...rows.map( ( one ) => ( {
						id: one.uid,
						label: one.path || one.name,
					} ) ),
				] }
				selectedKey={ value }
				onSelectionChange={ ( key: unknown ) =>
					onChange( String( key ?? '' ) )
				}
			/>
		</div>
	);
}
