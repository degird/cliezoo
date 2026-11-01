/**
 * Keeping every screen up to date without asking forty times a minute.
 */
import { useEffect, useRef } from 'react';
import { api, useQueryClient } from '@cliezoo/data';

interface Clock {
	now: number;
	/** How long to wait before asking again, in seconds. */
	every: number;
	/** When each kind of record last changed, as a unix time. */
	changed: Record< string, number >;
}

/** Used until the server says otherwise. */
const EVERY = 20_000;

/**
 * Asks the server what has changed, and refetches only that.
 *
 * A list that only moves when somebody reloads it is not a list two people
 * can work from, and the obvious answer — every list polling for itself —
 * is forty requests a minute from one open tab, nearly all of them
 * answering "nothing". So one request asks when each *kind* of record last
 * changed, and whatever moved has its queries marked stale; React Query
 * then refetches the ones actually on screen and leaves the rest alone.
 *
 * A tab nobody is looking at asks nothing: the timer stops when the page
 * is hidden and the first thing it does on coming back is ask.
 *
 * Mounted once, by the shell.
 */
export function useSync(): void {
	const queries = useQueryClient();
	// What the last answer said, so the first one only sets the baseline:
	// everything on screen was just fetched, and refetching all of it the
	// moment the app opens would be a second load for nothing.
	const seen = useRef< Record< string, number > | null >( null );

	useEffect( () => {
		let timer = 0;
		let stopped = false;

		const ask = async () => {
			if ( stopped || document.hidden ) {
				return;
			}
			let every = EVERY;
			try {
				const clock = ( await api.get< Clock >( '/sync/poll' ) ).data;
				every = Math.max( 5, clock.every ) * 1000;
				const before = seen.current;
				seen.current = clock.changed;
				if ( before ) {
					for ( const [ kind, at ] of Object.entries(
						clock.changed
					) ) {
						if ( at > ( before[ kind ] ?? 0 ) ) {
							queries.invalidateQueries( { queryKey: [ kind ] } );
						}
					}
				}
			} catch {
				// A poll that failed is not something to tell anybody
				// about: the next one is twenty seconds away, and the
				// screen is still showing what it last had.
			}
			if ( ! stopped ) {
				timer = window.setTimeout( ask, every );
			}
		};

		const wake = () => {
			if ( ! document.hidden ) {
				window.clearTimeout( timer );
				ask();
			}
		};

		// Asked at once, to know where things stood when the app opened.
		// Waiting for the first interval would let everything changed in
		// those twenty seconds set the baseline and go unnoticed.
		ask();
		document.addEventListener( 'visibilitychange', wake );
		return () => {
			stopped = true;
			window.clearTimeout( timer );
			document.removeEventListener( 'visibilitychange', wake );
		};
	}, [ queries ] );
}
