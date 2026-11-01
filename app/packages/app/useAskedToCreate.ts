/**
 * Whether the page was opened to make something ("+ New" and the command
 * palette navigate to a list with ?new=1), so its create form opens at once.
 */
import { useEffect, useRef, useState } from 'react';
import { useNavigate, useSearch } from '@tanstack/react-router';

export function useAskedToCreate(): boolean {
	const search = useSearch( { strict: false } ) as { new?: unknown };
	return '1' === String( search.new ?? '' );
}

/**
 * The create form's open state, kept in step with the address.
 *
 * Reading the ask once, as a first value, misses the common case: the
 * page is already open and "Create new" asks it again. So the ask is
 * watched, and taken out of the address as soon as it is read — which
 * both stops a refresh reopening the form and lets the next ask arrive
 * as a change.
 *
 * @param on  The state that means "the form is open".
 * @param off The state it starts in.
 */
export function useCreating< T >( on: T, off: T ): [ T, ( next: T ) => void ] {
	const asked = useAskedToCreate();
	const navigate = useNavigate();
	const opened = useRef( on );
	opened.current = on;
	const [ state, setState ] = useState< T >( asked ? on : off );

	useEffect( () => {
		if ( ! asked ) {
			return;
		}
		setState( opened.current );
		navigate( {
			search: ( prev: Record< string, unknown > ) => ( {
				...prev,
				new: undefined,
			} ),
			replace: true,
		} as never );
	}, [ asked, navigate ] );

	return [ state, setState ];
}
