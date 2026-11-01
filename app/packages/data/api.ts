/**
 * REST client for `cliezoo/v1` on top of core's wp.apiFetch (root URL and
 * REST nonce come from core's middlewares). An expired nonce is refreshed
 * and the call retried once here, so a tab left open for days keeps working
 * and a session that has really ended is reported as such.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { getBoot } from './boot';

export interface ListMeta {
	has_more?: boolean;
	next_cursor?: string | null;
	total?: number;
	[ key: string ]: unknown;
}

export interface Envelope< T > {
	data: T;
	meta?: ListMeta;
}

export type Query = Record<
	string,
	string | number | boolean | null | undefined | Array< string | number >
>;

/**
 * A failed call with the server's code, message, HTTP status and field errors.
 */
export class ApiError extends Error {
	readonly code: string;
	readonly status: number;
	readonly fields: Record< string, string >;
	/** Seconds the server asked to wait before trying again (429), or 0. */
	readonly retryAfter: number;

	constructor(
		code: string,
		message: string,
		status = 0,
		fields: Record< string, string > = {},
		retryAfter = 0
	) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
		this.fields = fields;
		this.retryAfter = retryAfter;
	}

	/** Not logged in / session expired. */
	get unauthorized(): boolean {
		return this.status === 401;
	}

	/** Offline or the server could not be reached. */
	get network(): boolean {
		return this.status === 0;
	}
}

type Listener = ( error: ApiError ) => void;
const unauthorizedListeners = new Set< Listener >();
const twoFactorListeners = new Set< Listener >();

/**
 * Subscribe to "your role requires 2FA" refusals (the shell opens account security).
 *
 * @param listener Callback.
 * @return Unsubscribe.
 */
export function onTwoFactorRequired( listener: Listener ): () => void {
	twoFactorListeners.add( listener );
	return () => twoFactorListeners.delete( listener );
}

/**
 * Subscribe to "session expired" failures (the shell shows the sign-in dialog).
 *
 * @param listener Callback.
 * @return Unsubscribe.
 */
export function onUnauthorized( listener: Listener ): () => void {
	unauthorizedListeners.add( listener );
	return () => unauthorizedListeners.delete( listener );
}

/**
 * Serialises a query object (arrays as repeated `key[]`, nested filters as JSON).
 *
 * @param query Query.
 */
export function toSearch( query: Query = {} ): string {
	const params = new URLSearchParams();
	for ( const [ key, value ] of Object.entries( query ) ) {
		if ( value === undefined || value === null || value === '' ) {
			continue;
		}
		if ( Array.isArray( value ) ) {
			value.forEach( ( item ) =>
				params.append( `${ key }[]`, String( item ) )
			);
		} else {
			params.set( key, String( value ) );
		}
	}
	const search = params.toString();
	return search ? `?${ search }` : '';
}

/** What WordPress answers when the REST nonce sent is no longer valid. */
const INVALID_NONCE = 'rest_cookie_invalid_nonce';

let refreshing: Promise< boolean > | null = null;

/**
 * Where a fresh REST nonce comes from: WordPress's own `rest-nonce` Ajax
 * action, which core's api-fetch is told about. It is read with the login
 * cookie alone, and admin-ajax sends no CORS headers, so no other site can
 * read the nonce the way it could read a REST answer.
 */
function nonceEndpoint(): string {
	const known = ( apiFetch as unknown as { nonceEndpoint?: string } )
		.nonceEndpoint;
	if ( known ) {
		return known;
	}
	const admin = getBoot().routes?.adminUrl;
	return admin
		? `${ admin.replace( /\/$/, '' ) }/admin-ajax.php?action=rest-nonce`
		: '';
}

/**
 * Fetches a fresh REST nonce and hands it to api-fetch and to the few calls
 * that go round it. Calls that fail at the same moment share one refresh.
 *
 * @return Whether a fresh nonce was had (false: the session has ended).
 */
export function refreshNonce(): Promise< boolean > {
	if ( refreshing ) {
		return refreshing;
	}
	refreshing = ( async () => {
		const endpoint = nonceEndpoint();
		if ( ! endpoint ) {
			return false;
		}
		try {
			const response = await fetch( endpoint, {
				credentials: 'same-origin',
			} );
			const nonce = response.ok ? ( await response.text() ).trim() : '';
			// A nonce is ten hexadecimal characters; "0" or an error page is
			// WordPress saying nobody is signed in.
			if ( ! /^[0-9a-f]{10}$/i.test( nonce ) ) {
				return false;
			}
			const middleware = (
				apiFetch as unknown as { nonceMiddleware?: { nonce: string } }
			 ).nonceMiddleware;
			if ( middleware ) {
				middleware.nonce = nonce;
			}
			getBoot().rest.nonce = nonce;
			return true;
		} catch {
			return false;
		}
	} )().finally( () => {
		refreshing = null;
	} );
	return refreshing;
}

interface RequestOptions {
	query?: Query;
	data?: unknown;
	headers?: Record< string, string >;
	signal?: AbortSignal;
	/** For POST: a stable key makes retries safe (server replays the first result). */
	idempotencyKey?: string;
	/** Optimistic concurrency: the ETag of the version being edited. */
	ifMatch?: string;
}

function normalise( error: unknown ): ApiError {
	if ( error instanceof ApiError ) {
		return error;
	}
	const raw = ( error ?? {} ) as {
		code?: string;
		message?: string;
		data?: {
			status?: number;
			fields?: Record< string, string >;
			retry_after?: number;
		};
	};
	if ( raw.code === 'fetch_error' || error instanceof TypeError ) {
		return new ApiError(
			'offline',
			raw.message || __( 'You appear to be offline.', 'cliezoo' ),
			0
		);
	}
	return new ApiError(
		raw.code || 'unknown',
		raw.message || __( 'Something went wrong.', 'cliezoo' ),
		raw.data?.status ?? 500,
		raw.data?.fields ?? {},
		Number( raw.data?.retry_after ?? 0 ) || 0
	);
}

/**
 * Performs a request and returns the parsed body ({ data, meta }).
 *
 * @param method HTTP method.
 * @param path   Path below /cliezoo/v1 (e.g. "/members").
 * @param opts   Options.
 */
export async function request< T = unknown >(
	method: string,
	path: string,
	opts: RequestOptions = {}
): Promise< Envelope< T > > {
	const headers: Record< string, string > = { ...opts.headers };
	if ( opts.idempotencyKey ) {
		headers[ 'Idempotency-Key' ] = opts.idempotencyKey;
	}
	if ( opts.ifMatch ) {
		headers[ 'If-Match' ] = opts.ifMatch;
	}
	const call = () =>
		apiFetch< Envelope< T > >( {
			path: `/cliezoo/v1${ path }${ toSearch( opts.query ) }`,
			method,
			data: opts.data as Record< string, unknown > | undefined,
			headers,
			signal: opts.signal,
		} );
	try {
		return await call();
	} catch ( error ) {
		let failure = normalise( error );
		// A tab left open for days holds a REST nonce that has expired. A
		// fresh one is fetched and the call made once more (with the same
		// idempotency key, so a POST is not done twice). When no fresh
		// nonce can be had, the session itself has ended.
		if ( INVALID_NONCE === failure.code ) {
			if ( await refreshNonce() ) {
				try {
					return await call();
				} catch ( again ) {
					failure = normalise( again );
				}
			}
			if ( INVALID_NONCE === failure.code ) {
				failure = new ApiError(
					'cliezoo_session_ended',
					__(
						'Your session has ended. Please sign in again.',
						'cliezoo'
					),
					401
				);
			}
		}
		if ( failure.unauthorized ) {
			unauthorizedListeners.forEach( ( listener ) =>
				listener( failure )
			);
		}
		if ( failure.code === 'cliezoo_2fa_required' ) {
			twoFactorListeners.forEach( ( listener ) => listener( failure ) );
		}
		throw failure;
	}
}

/**
 * Convenience verbs.
 */
export const api = {
	get: < T = unknown >( path: string, query?: Query, signal?: AbortSignal ) =>
		request< T >( 'GET', path, { query, signal } ),
	post: < T = unknown >(
		path: string,
		data?: unknown,
		opts: Omit< RequestOptions, 'data' > = {}
	) => request< T >( 'POST', path, { ...opts, data } ),
	put: < T = unknown >(
		path: string,
		data?: unknown,
		opts: Omit< RequestOptions, 'data' > = {}
	) => request< T >( 'PUT', path, { ...opts, data } ),
	patch: < T = unknown >(
		path: string,
		data?: unknown,
		opts: Omit< RequestOptions, 'data' > = {}
	) => request< T >( 'PATCH', path, { ...opts, data } ),
	delete: < T = unknown >( path: string, opts: RequestOptions = {} ) =>
		request< T >( 'DELETE', path, opts ),
};

/**
 * The full URL of one REST path, for the few places that cannot go through
 * apiFetch: a raw upload chunk, and a download the browser navigates to.
 *
 * `rest.root` is already the namespaced root, so nothing here adds the
 * namespace to it a second time.
 *
 * @param path  Path below /cliezoo/v1, starting with a slash.
 * @param query Anything to append, e.g. a nonce for a navigation.
 */
export function restUrl( path: string, query: Query = {} ): string {
	const boot = getBoot();
	return boot.rest.root.replace( /\/$/, '' ) + path + toSearch( query );
}

/**
 * Sends raw bytes to one path, for a chunk of an upload.
 *
 * apiFetch JSON-encodes what it is given, and a chunk is not JSON, so this
 * is the one call that goes round it — with the same root and nonce, and
 * the same errors, so nothing else has to know that.
 *
 * @param path   Path below /cliezoo/v1.
 * @param body   The bytes.
 * @param signal Abort signal.
 */
export async function sendBytes(
	path: string,
	body: Blob,
	signal?: AbortSignal
): Promise< void > {
	const url = restUrl( path );
	const send = async () => {
		try {
			return await fetch( url, {
				method: 'PUT',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': getBoot().rest.nonce,
					'Content-Type': 'application/octet-stream',
				},
				body,
				signal,
			} );
		} catch ( error ) {
			throw normalise( error );
		}
	};
	let response = await send();
	let said: unknown = null;
	if ( ! response.ok ) {
		said = await response.json().catch( () => null );
		// This call goes round api-fetch, so it refreshes an expired
		// nonce itself, once.
		if (
			INVALID_NONCE === ( said as { code?: string } | null )?.code &&
			( await refreshNonce() )
		) {
			response = await send();
			said = response.ok
				? null
				: await response.json().catch( () => null );
		}
	}
	if ( ! response.ok ) {
		// A chunk that fails must say why: "something went wrong" in the
		// middle of a long upload tells nobody what to do next.
		if ( said && ( said as { code?: string } ).code ) {
			throw normalise( said );
		}
		throw new ApiError(
			'upload_failed',
			sprintf(
				/* translators: %d: an HTTP status code, e.g. 413. */
				__( 'The server refused part of this upload (%d).', 'cliezoo' ),
				response.status
			),
			response.status
		);
	}
}

/**
 * A random idempotency key (crypto-strength where available).
 */
export function idempotencyKey(): string {
	if ( typeof crypto !== 'undefined' && 'randomUUID' in crypto ) {
		return crypto.randomUUID();
	}
	return `${ Date.now().toString( 36 ) }-${ Math.random().toString( 36 ).slice( 2 ) }`;
}
