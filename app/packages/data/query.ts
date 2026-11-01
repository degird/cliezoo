/**
 * Query cache (one per page, shared by every bundle) and entity hooks.
 */
import {
	QueryClient,
	keepPreviousData,
	useInfiniteQuery,
	useMutation,
	useQuery,
	useQueryClient,
	type InfiniteData,
} from '@tanstack/react-query';
import {
	ApiError,
	api,
	idempotencyKey,
	type Envelope,
	type ListMeta,
	type Query,
} from './api';

export const queryClient = new QueryClient( {
	defaultOptions: {
		queries: {
			staleTime: 30_000,
			gcTime: 5 * 60_000,
			refetchOnWindowFocus: true,
			retry: ( count, error ) => {
				// Never retry client errors (validation, permission, not found).
				if (
					error instanceof ApiError &&
					error.status >= 400 &&
					error.status < 500
				) {
					return false;
				}
				return count < 2;
			},
		},
		mutations: { retry: false },
	},
} );

/**
 * Query keys: [ entity, 'list', query ] and [ entity, 'one', uid ].
 */
export const keys = {
	all: ( entity: string ) => [ entity ] as const,
	list: ( entity: string, query: Query = {} ) =>
		[ entity, 'list', query ] as const,
	one: ( entity: string, uid: string ) => [ entity, 'one', uid ] as const,
};

export interface Page< T > {
	items: T[];
	meta: ListMeta;
}

/**
 * A keyset-paginated list with "load more".
 *
 * @param path         List path (e.g. "/members").
 * @param query        Filters, sort, search.
 * @param opts         Options.
 * @param opts.enabled
 * @param opts.limit
 */
export function useList< T = Record< string, unknown > >(
	path: string,
	query: Query = {},
	opts: { enabled?: boolean; limit?: number } = {}
) {
	const entity = path.replace( /^\//, '' );
	return useInfiniteQuery<
		Envelope< T[] >,
		ApiError,
		InfiniteData< Envelope< T[] > >,
		readonly unknown[],
		string | null
	>( {
		queryKey: keys.list( entity, query ),
		enabled: opts.enabled ?? true,
		initialPageParam: null,
		placeholderData: keepPreviousData,
		queryFn: ( { pageParam, signal } ) =>
			api.get< T[] >(
				path,
				{
					...query,
					limit: opts.limit ?? 50,
					cursor: pageParam ?? undefined,
				},
				signal
			),
		getNextPageParam: ( last ) =>
			last.meta?.has_more
				? ( ( last.meta.next_cursor as string ) ?? null )
				: null,
	} );
}

/**
 * One record by uid.
 *
 * @param path Collection path (e.g. "/members").
 * @param uid  Record uid (query disabled when empty).
 */
export function useRecord< T = Record< string, unknown > >(
	path: string,
	uid: string | undefined
) {
	const entity = path.replace( /^\//, '' );
	return useQuery< T, ApiError >( {
		queryKey: keys.one( entity, uid ?? '' ),
		enabled: !! uid,
		queryFn: async ( { signal } ) =>
			( await api.get< T >( `${ path }/${ uid }`, undefined, signal ) )
				.data,
	} );
}

/**
 * A mutation that refreshes the entity's lists and record afterwards.
 *
 * @param entity Entity path segment (e.g. "members").
 * @param fn     Mutation function.
 */
export function useEntityMutation< TVars, TResult = unknown >(
	entity: string,
	fn: ( vars: TVars, key: string ) => Promise< TResult >
) {
	const client = useQueryClient();
	return useMutation< TResult, ApiError, TVars >( {
		mutationFn: ( vars ) => fn( vars, idempotencyKey() ),
		onSettled: () =>
			client.invalidateQueries( { queryKey: keys.all( entity ) } ),
	} );
}

/**
 * Asks the server what changed and refreshes only those record types.
 *
 * One request on a timer instead of every open list polling on its own. It
 * stops while the tab is in the background, and asks again straight away
 * when the person comes back.
 *
 * @param seconds How often to ask while the tab is visible.
 * @return A function that stops the polling.
 */
export function watchChanges( seconds = 25 ): () => void {
	let since = new Date().toISOString();
	let timer = 0;
	let running = false;

	const ask = async () => {
		if ( running || document.hidden ) {
			return;
		}
		running = true;
		try {
			const { data } = await api.get< {
				now: string;
				types: Record< string, number >;
				total: number;
			} >( '/changes', { since } );
			since = data.now;
			for ( const entity of Object.keys( data.types ) ) {
				queryClient.invalidateQueries( {
					queryKey: keys.all( entity ),
				} );
			}
			if ( data.total > 0 ) {
				queryClient.invalidateQueries( {
					queryKey: [ 'notifications' ],
				} );
			}
		} catch {
			// A failed poll is not worth telling anyone about; the next one
			// covers the same window.
		} finally {
			running = false;
		}
	};

	const onVisible = () => {
		if ( ! document.hidden ) {
			void ask();
		}
	};
	timer = window.setInterval( ask, Math.max( 5, seconds ) * 1000 );
	document.addEventListener( 'visibilitychange', onVisible );
	return () => {
		window.clearInterval( timer );
		document.removeEventListener( 'visibilitychange', onVisible );
	};
}

export {
	QueryClient,
	useQuery,
	useMutation,
	useQueryClient,
	useInfiniteQuery,
	keepPreviousData,
};
export {
	QueryClientProvider,
	useIsFetching,
	useIsMutating,
	useSuspenseQuery,
	useQueries,
} from '@tanstack/react-query';
export type {
	InfiniteData,
	UseQueryResult,
	UseMutationResult,
	QueryKey,
} from '@tanstack/react-query';
