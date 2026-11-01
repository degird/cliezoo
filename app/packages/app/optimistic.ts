/**
 * Optimistic quick actions (cliezoo.md §6.44 "Optimistic UI").
 *
 * Ticking a task done, starring or watching something and ticking off a
 * to-do are the actions people do most and wait for least: the screen
 * shows the result at once and the request catches up. When the server
 * says no, every cache the action touched goes back to what it was, so a
 * failed tick never looks like it worked.
 */
import type { QueryClient, QueryKey } from '@cliezoo/data';

export interface OptimisticChange {
	/** Every cached query under this key (a prefix match) is changed. */
	key: QueryKey;
	/** The new value from the old one; return the old one to leave it. */
	update: ( old: unknown ) => unknown;
}

/**
 * Applies the changes to the cache, runs the request, and puts the cache
 * back if the request fails (the error is re-thrown for the caller to
 * say what went wrong).
 *
 * @param queries Query client.
 * @param changes What to change in the cache straight away.
 * @param run     The request.
 */
export async function optimistic< T >(
	queries: QueryClient,
	changes: OptimisticChange[],
	run: () => Promise< T >
): Promise< T > {
	const before: Array< [ QueryKey, unknown ] > = [];
	for ( const change of changes ) {
		// A fetch already on its way would land on top of the change and
		// undo it, so it is stopped; the request's own refresh follows.
		await queries.cancelQueries( { queryKey: change.key } );
		for ( const [ key, value ] of queries.getQueriesData( {
			queryKey: change.key,
		} ) ) {
			before.push( [ key, value ] );
			queries.setQueryData( key, change.update( value ) );
		}
	}
	try {
		return await run();
	} catch ( error ) {
		for ( const [ key, value ] of before ) {
			queries.setQueryData( key, value );
		}
		throw error;
	}
}

/**
 * Changes one row, by uid, wherever it sits in a cached value: a plain
 * list, a record, or the pages of a "load more" list. Anything else is
 * left as it is.
 *
 * @param uid   Row uid.
 * @param patch Fields to change.
 */
export function patchRow( uid: string, patch: Record< string, unknown > ) {
	const one = ( row: unknown ): unknown =>
		row &&
		'object' === typeof row &&
		( row as { uid?: unknown } ).uid === uid
			? { ...( row as object ), ...patch }
			: row;
	const walk = ( value: unknown ): unknown => {
		if ( Array.isArray( value ) ) {
			return value.map( one );
		}
		if ( ! value || 'object' !== typeof value ) {
			return value;
		}
		const pages = ( value as { pages?: unknown } ).pages;
		if ( Array.isArray( pages ) ) {
			return {
				...( value as object ),
				pages: pages.map( ( page ) =>
					page && 'object' === typeof page && 'data' in page
						? {
								...page,
								data: walk(
									( page as { data: unknown } ).data
								),
							}
						: page
				),
			};
		}
		return one( value );
	};
	return walk;
}
