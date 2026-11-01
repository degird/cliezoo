/**
 * Reading the OpenAPI document for the API docs screen: operations grouped
 * by area, found by words, with their parameters and body described in
 * plain terms. Pure functions, so the screen stays a thin view of them.
 */

export interface SpecParameter {
	name: string;
	in: 'query' | 'path' | 'header';
	required?: boolean;
	description?: string;
	schema?: Schema;
}

export interface Schema {
	type?: string | string[];
	enum?: unknown[];
	pattern?: string;
	maxLength?: number;
	minimum?: number;
	maximum?: number;
	default?: unknown;
	format?: string;
	description?: string;
	properties?: Record< string, Schema >;
	items?: Schema;
	required?: string[];
	$ref?: string;
}

export interface SpecOperation {
	operationId?: string;
	summary?: string;
	description?: string;
	tags?: string[];
	parameters?: SpecParameter[];
	requestBody?: {
		content?: Record< string, { schema?: Schema } >;
	};
}

export interface ChangelogEntry {
	version: string;
	date: string;
	changes: string[];
}

export interface Spec {
	info?: { title?: string; version?: string; description?: string };
	servers?: Array< { url: string } >;
	paths?: Record< string, Record< string, SpecOperation > >;
	'x-cliezoo-rate-limit'?: { limit: number; window: number };
	'x-cliezoo-changelog'?: ChangelogEntry[];
}

/** One call, flattened for listing. */
export interface Endpoint {
	id: string;
	method: string;
	path: string;
	tag: string;
	summary: string;
	description: string;
	parameters: SpecParameter[];
	body: Array< { name: string; type: string; required: boolean } >;
}

const ORDER = [ 'get', 'post', 'put', 'patch', 'delete' ];

/**
 * A schema's type in a few words ("string", "integer 1–200", "one of a, b").
 *
 * @param schema The schema.
 */
export function describeType( schema: Schema | undefined ): string {
	if ( ! schema ) {
		return '';
	}
	if ( schema.enum?.length ) {
		return schema.enum.map( String ).join( ' | ' );
	}
	const type = Array.isArray( schema.type )
		? schema.type.join( ' | ' )
		: ( schema.type ?? ( schema.properties ? 'object' : '' ) );
	if ( 'array' === type && schema.items ) {
		return `${ describeType( schema.items ) }[]`;
	}
	const range =
		undefined !== schema.minimum || undefined !== schema.maximum
			? ` ${ schema.minimum ?? '' }–${ schema.maximum ?? '' }`
			: '';
	return `${ type }${ range }`.trim();
}

/**
 * Every call in the document, grouped order: by area, then path, then verb.
 *
 * @param spec The document.
 */
export function endpoints( spec: Spec ): Endpoint[] {
	const out: Endpoint[] = [];
	for ( const [ path, methods ] of Object.entries( spec.paths ?? {} ) ) {
		for ( const [ method, op ] of Object.entries( methods ?? {} ) ) {
			if ( ! ORDER.includes( method ) ) {
				continue;
			}
			const schema =
				op.requestBody?.content?.[ 'application/json' ]?.schema;
			const required = schema?.required ?? [];
			out.push( {
				id: `${ method } ${ path }`,
				method: method.toUpperCase(),
				path,
				tag: op.tags?.[ 0 ] ?? path.split( '/' )[ 1 ] ?? '',
				summary: op.summary ?? '',
				description: op.description ?? '',
				parameters: op.parameters ?? [],
				body: Object.entries( schema?.properties ?? {} ).map(
					( [ name, property ] ) => ( {
						name,
						type: describeType( property ),
						required: required.includes( name ),
					} )
				),
			} );
		}
	}
	return out.sort(
		( a, b ) =>
			a.tag.localeCompare( b.tag ) ||
			a.path.localeCompare( b.path ) ||
			ORDER.indexOf( a.method.toLowerCase() ) -
				ORDER.indexOf( b.method.toLowerCase() )
	);
}

/**
 * The calls whose path, summary, area or verb hold every word typed.
 *
 * @param all   Every call.
 * @param query What was typed.
 */
export function findEndpoints( all: Endpoint[], query: string ): Endpoint[] {
	const words = query.toLowerCase().split( /\s+/ ).filter( Boolean );
	if ( ! words.length ) {
		return all;
	}
	return all.filter( ( one ) => {
		const text =
			`${ one.method } ${ one.path } ${ one.tag } ${ one.summary }`.toLowerCase();
		return words.every( ( word ) => text.includes( word ) );
	} );
}

/**
 * Calls by area, keeping their order.
 *
 * @param list The calls.
 */
export function byTag( list: Endpoint[] ): Array< [ string, Endpoint[] ] > {
	const groups = new Map< string, Endpoint[] >();
	for ( const one of list ) {
		groups.set( one.tag, [ ...( groups.get( one.tag ) ?? [] ), one ] );
	}
	return [ ...groups.entries() ];
}

/**
 * A curl call for one endpoint, with placeholders for what must be filled.
 *
 * @param base The API's base URL.
 * @param one  The call.
 */
export function curlFor( base: string, one: Endpoint ): string {
	const url = base.replace( /\/$/, '' ) + one.path;
	const lines = [
		`curl -X ${ one.method } '${ url }'`,
		`  -u 'USER:APPLICATION_PASSWORD'`,
	];
	if ( one.body.length ) {
		const sample: Record< string, string > = {};
		one.body
			.filter( ( field ) => field.required )
			.forEach( ( field ) => {
				sample[ field.name ] = `<${ field.type || 'value' }>`;
			} );
		lines.push( `  -H 'Content-Type: application/json'` );
		lines.push( `  -d '${ JSON.stringify( sample ) }'` );
	}
	return lines.join( ' \\\n' );
}
