/**
 * Settings → API: the REST API documented from the live OpenAPI document,
 * drawn here rather than in an outside viewer (no CDN, nothing leaves the
 * site). Every call, its parameters and body, a curl example, the limits
 * as the server enforces them, and the changelog.
 */
import { useMemo, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { copyText } from '../../../packages/app/copy';
import { Copy, Download } from 'lucide-react';
import { api, restUrl, useQuery } from '@cliezoo/data';
import {
	Button,
	ErrorState,
	IconButton,
	SearchField,
	Skeleton,
	cx,
} from '@cliezoo/ui';
import {
	byTag,
	curlFor,
	describeType,
	endpoints,
	findEndpoints,
	type Endpoint,
	type Spec,
} from './apiSpec';

const METHOD_TONES: Record< string, string > = {
	GET: 'bg-sunken text-fg',
	POST: 'bg-accent/10 text-accent-fg',
	PUT: 'bg-warning/10 text-warning',
	PATCH: 'bg-warning/10 text-warning',
	DELETE: 'bg-danger/10 text-danger',
};

/**
 * Copies text, saying whether it worked.
 *
 * @param text What to copy.
 */
async function copy( text: string ) {
	await copyText( text, __( 'Copied.', 'cliezoo' ) );
}

/**
 * One call, opened to show what it takes.
 *
 * @param root0      Props.
 * @param root0.one  The call.
 * @param root0.base The API's base URL.
 */
function Operation( { one, base }: { one: Endpoint; base: string } ) {
	const curl = curlFor( base, one );
	return (
		<details className="group rounded-md border border-line bg-bg">
			<summary className="flex cursor-pointer list-none items-center gap-3 px-3 py-2 text-sm">
				<span
					className={ cx(
						'w-16 shrink-0 rounded px-1.5 py-0.5 text-center font-mono text-xs font-semibold',
						METHOD_TONES[ one.method ] ?? 'bg-sunken text-fg'
					) }
				>
					{ one.method }
				</span>
				<code className="min-w-0 truncate font-mono text-xs text-fg">
					{ one.path }
				</code>
				<span className="ms-auto hidden truncate text-xs text-muted sm:block">
					{ one.summary }
				</span>
			</summary>
			<div className="space-y-3 border-t border-line px-3 py-3 text-sm">
				{ one.description && (
					<p className="text-muted">{ one.description }</p>
				) }
				{ !! one.parameters.length && (
					<table className="w-full text-start text-xs">
						<caption className="mb-1 text-start font-medium text-fg">
							{ __( 'Parameters', 'cliezoo' ) }
						</caption>
						<thead className="text-muted">
							<tr>
								<th className="py-1 pe-3 text-start font-medium">
									{ __( 'Name', 'cliezoo' ) }
								</th>
								<th className="py-1 pe-3 text-start font-medium">
									{ __( 'In', 'cliezoo' ) }
								</th>
								<th className="py-1 pe-3 text-start font-medium">
									{ __( 'Type', 'cliezoo' ) }
								</th>
								<th className="py-1 text-start font-medium">
									{ __( 'About', 'cliezoo' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ one.parameters.map( ( param ) => (
								<tr
									key={ `${ param.in }-${ param.name }` }
									className="border-t border-line align-top"
								>
									<td className="py-1 pe-3 font-mono">
										{ param.name }
										{ param.required && (
											<span className="ms-1 text-danger">
												*
											</span>
										) }
									</td>
									<td className="py-1 pe-3 text-muted">
										{ param.in }
									</td>
									<td className="py-1 pe-3 font-mono text-muted">
										{ describeType( param.schema ) }
									</td>
									<td className="py-1 text-muted">
										{ param.description }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
				{ !! one.body.length && (
					<div>
						<p className="mb-1 text-xs font-medium text-fg">
							{ __( 'JSON body', 'cliezoo' ) }
						</p>
						<ul className="flex flex-wrap gap-x-4 gap-y-1 font-mono text-xs text-muted">
							{ one.body.map( ( field ) => (
								<li key={ field.name }>
									<span className="text-fg">
										{ field.name }
									</span>
									{ field.required && (
										<span className="text-danger">*</span>
									) }
									{ field.type && `: ${ field.type }` }
								</li>
							) ) }
						</ul>
					</div>
				) }
				<div className="relative">
					<pre className="overflow-x-auto rounded bg-sunken p-2 pe-10 font-mono text-xs text-fg">
						{ curl }
					</pre>
					<IconButton
						size="sm"
						className="absolute end-1 top-1"
						label={ __( 'Copy the example', 'cliezoo' ) }
						icon={ <Copy size={ 14 } aria-hidden="true" /> }
						onPress={ () => copy( curl ) }
					/>
				</div>
			</div>
		</details>
	);
}

export default function ApiDocs() {
	const [ query, setQuery ] = useState( '' );
	const spec = useQuery( {
		queryKey: [ 'openapi' ],
		staleTime: 10 * 60_000,
		queryFn: async () => ( await api.get< Spec >( '/openapi' ) ).data,
	} );
	const all = useMemo(
		() => ( spec.data ? endpoints( spec.data ) : [] ),
		[ spec.data ]
	);
	const found = useMemo( () => findEndpoints( all, query ), [ all, query ] );

	if ( spec.isPending ) {
		return <Skeleton className="h-64" />;
	}
	if ( spec.isError ) {
		return (
			<ErrorState error={ spec.error } onRetry={ () => spec.refetch() } />
		);
	}
	const doc = spec.data;
	const base = doc.servers?.[ 0 ]?.url ?? restUrl( '' );
	const changelog = doc[ 'x-cliezoo-changelog' ] ?? [];

	return (
		<div className="space-y-6">
			<section className="space-y-3 rounded-lg border border-line bg-raised p-4 text-sm">
				<div className="flex flex-wrap items-center gap-3">
					<h3 className="font-semibold text-fg">
						{ sprintf(
							/* translators: %s: the API version. */
							__( 'REST API, version %s', 'cliezoo' ),
							doc.info?.version ?? ''
						) }
					</h3>
					<Button
						size="sm"
						className="ms-auto"
						icon={ <Download size={ 14 } aria-hidden="true" /> }
						onPress={ () =>
							window.open(
								restUrl( '/openapi' ),
								'_blank',
								'noopener'
							)
						}
					>
						{ __( 'OpenAPI document', 'cliezoo' ) }
					</Button>
				</div>
				<p className="text-muted">
					{ __( 'Base URL', 'cliezoo' ) }:{ ' ' }
					<code className="rounded bg-sunken px-1.5 py-0.5 text-xs">
						{ base }
					</code>
				</p>
				{ ( doc.info?.description ?? '' )
					.split( /\n\n+/ )
					.filter( Boolean )
					.map( ( paragraph, index ) => (
						<p key={ index } className="text-muted">
							{ paragraph }
						</p>
					) ) }
				<p className="text-muted">
					{ __(
						'Lists take filter, q, sort, cursor and limit; lists and single records take expand (for example ?expand=client,owner) to include related records in full. Money is always an integer in minor units with a currency code. Send If-Match with an ETag to avoid overwriting someone else’s change, and Idempotency-Key to make a POST safe to retry.',
						'cliezoo'
					) }
				</p>
			</section>

			<section className="space-y-3" aria-labelledby="cliezoo-api-calls">
				<div className="flex flex-wrap items-end gap-3">
					<h3
						id="cliezoo-api-calls"
						className="text-sm font-semibold text-fg"
					>
						{ __( 'Calls', 'cliezoo' ) }
					</h3>
					<SearchField
						label={ __( 'Find a call', 'cliezoo' ) }
						className="ms-auto w-full sm:w-72"
						value={ query }
						onChange={ setQuery }
					/>
				</div>
				<p className="text-xs text-muted" aria-live="polite">
					{ sprintf(
						/* translators: %d: number of API calls shown. */
						_n( '%d call', '%d calls', found.length, 'cliezoo' ),
						found.length
					) }
				</p>
				{ byTag( found ).map( ( [ tag, list ] ) => (
					<details
						key={ tag }
						className="rounded-lg border border-line bg-raised"
						open={ '' !== query.trim() }
					>
						<summary className="cursor-pointer px-4 py-2 text-sm font-medium text-fg">
							{ tag }{ ' ' }
							<span className="text-xs text-muted">
								({ list.length })
							</span>
						</summary>
						<div className="space-y-2 px-4 pb-4">
							{ list.map( ( one ) => (
								<Operation
									key={ one.id }
									one={ one }
									base={ base }
								/>
							) ) }
						</div>
					</details>
				) ) }
			</section>

			<section
				className="space-y-3"
				aria-labelledby="cliezoo-api-changelog"
			>
				<h3
					id="cliezoo-api-changelog"
					className="text-sm font-semibold text-fg"
				>
					{ __( 'Changelog', 'cliezoo' ) }
				</h3>
				<ol className="space-y-3">
					{ changelog.map( ( entry ) => (
						<li
							key={ entry.version }
							className="rounded-lg border border-line bg-raised p-4 text-sm"
						>
							<p className="font-medium text-fg">
								{ entry.version }{ ' ' }
								<span className="text-xs font-normal text-muted">
									{ entry.date ||
										__( 'Not released yet', 'cliezoo' ) }
								</span>
							</p>
							<ul className="mt-2 list-disc space-y-1 ps-5 text-muted">
								{ entry.changes.map( ( change, index ) => (
									<li key={ index }>{ change }</li>
								) ) }
							</ul>
						</li>
					) ) }
				</ol>
			</section>
		</div>
	);
}
