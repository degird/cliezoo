/**
 * The library: everything the workspace keeps, whatever it is — boards,
 * conversations, documents, embeds, files, links, milestones and tasks —
 * newest first, with the one archive and the one trash beside it.
 *
 * It lists only what each kind's own screen would show this person; the
 * server asks each kind for it (GET /library).
 */
import { useEffect, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link } from '@tanstack/react-router';
import { Archive, RotateCcw, Trash2 } from 'lucide-react';
import {
	api,
	formatFullDate,
	formatRelative,
	getBoot,
	useList,
	useQuery,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import {
	Badge,
	Button,
	Checkbox,
	ConfirmDialog,
	EmptyState,
	ErrorState,
	SearchField,
	Select,
	Skeleton,
	cx,
	reportOutcome,
	toast,
} from '@cliezoo/ui';
import { recordPath } from '../../packages/app/registry';

/** One thing in the library, whatever kind it is. */
export interface LibraryRow {
	type: string;
	entity: string;
	uid: string;
	label: string;
	kind: string;
	visibility: string | null;
	client: { uid: string; label: string } | null;
	project: { uid: string; label: string } | null;
	author: { uid: string; label: string } | null;
	created_at: string | null;
	archived_at: string | null;
	deleted_at: string | null;
}

type View = 'everything' | 'archive' | 'trash';

const VISIBILITY = (): Record< string, string > => ( {
	inherit: __( 'Follows the project', 'cliezoo' ),
	private: __( 'Only the people listed', 'cliezoo' ),
	internal: __( 'The team', 'cliezoo' ),
	clients: __( 'The team and the client', 'cliezoo' ),
	public: __( 'Anyone with the link', 'cliezoo' ),
} );

/**
 * Where a row opens.
 *
 * @param row The row.
 */
export function openPath( row: LibraryRow ): string {
	if ( 'files' === row.entity ) {
		return `/files?file=${ row.uid }`;
	}
	return recordPath( row.entity, row.uid );
}

export function Library( { client }: { client?: string } = {} ) {
	const user = getBoot().user;
	const admin = !! user?.admin;
	const me = user?.uid ?? '';
	const queries = useQueryClient();
	const [ view, setView ] = useState< View >( 'everything' );
	const [ kind, setKind ] = useState( '' );
	const [ typed, setTyped ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ mine, setMine ] = useState( false );
	const [ created, setCreated ] = useState( '' );
	const [ visibility, setVisibility ] = useState( '' );
	const narrowed =
		!! search || !! kind || mine || !! created || !! visibility;
	const clearAll = () => {
		setTyped( '' );
		setSearch( '' );
		setKind( '' );
		setMine( false );
		setCreated( '' );
		setVisibility( '' );
	};
	const [ picked, setPicked ] = useState< string[] >( [] );
	const [ confirm, setConfirm ] = useState< '' | 'purge' | 'empty' >( '' );

	useEffect( () => {
		const timer = setTimeout( () => setSearch( typed ), 300 );
		return () => clearTimeout( timer );
	}, [ typed ] );

	// What was picked belongs to the rows that were on screen: another view,
	// kind or filter shows other rows, so nothing stays picked.
	useEffect( () => {
		setPicked( [] );
	}, [ view, kind, search, mine, created, visibility ] );

	const kinds = useQuery( {
		queryKey: [ 'library', 'kinds' ],
		staleTime: 5 * 60 * 1000,
		queryFn: async () =>
			(
				await api.get< Array< { id: string; label: string } > >(
					'/library/kinds'
				)
			).data,
	} );

	const list = useList< LibraryRow >(
		'/library',
		{
			view,
			kind: kind || undefined,
			q: search || undefined,
			author: mine && me ? me : undefined,
			created: created || undefined,
			visibility: visibility || undefined,
			client,
		},
		{ limit: 50 }
	);
	const rows = ( list.data?.pages ?? [] ).flatMap( ( page ) => page.data );
	const key = ( row: LibraryRow ) => `${ row.type }:${ row.uid }`;
	const items = () =>
		rows
			.filter( ( row ) => picked.includes( key( row ) ) )
			.map( ( row ) => ( { type: row.type, uid: row.uid } ) );

	const changed = async () => {
		setPicked( [] );
		await queries.invalidateQueries( { queryKey: [ 'library' ] } );
	};

	const run = async (
		path: string,
		body: Record< string, unknown >,
		said: ( done: number ) => string
	) => {
		try {
			const result = (
				await api.post< {
					done?: number;
					purged?: number;
					left?: number;
					failed?: Array< { message: string } >;
				} >( path, body )
			).data;
			const done = result.done ?? result.purged ?? 0;
			// One report, in one tone. A green "0 things brought back."
			// beside a red one is the screen arguing with itself.
			reportOutcome( {
				done,
				reasons: ( result.failed ?? [] ).map( ( one ) => one.message ),
				said: said( done ),
				partly: ( through, failed ) =>
					`${ said( through ) } ${ sprintf(
						/* translators: %d: how many things. */
						_n(
							'%d could not be.',
							'%d could not be.',
							failed,
							'cliezoo'
						),
						failed
					) }`,
			} );
			if ( result.left ) {
				toast.info(
					sprintf(
						/* translators: %d: how many things are still in the trash. */
						_n(
							'%d more is still in the trash: empty it again to finish.',
							'%d more are still in the trash: empty it again to finish.',
							result.left,
							'cliezoo'
						),
						result.left
					)
				);
			}
			await changed();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	const bringBack = () =>
		run( '/library/restore', { items: items() }, ( done ) =>
			sprintf(
				/* translators: %d: how many things. */
				_n(
					'%d thing brought back.',
					'%d things brought back.',
					done,
					'cliezoo'
				),
				done
			)
		);

	const views: Array< { id: View; label: string; icon?: JSX.Element } > = [
		{ id: 'everything', label: __( 'Everything', 'cliezoo' ) },
		{
			id: 'archive',
			label: __( 'Archive', 'cliezoo' ),
			icon: <Archive size={ 14 } aria-hidden="true" />,
		},
		{
			id: 'trash',
			label: __( 'Trash', 'cliezoo' ),
			icon: <Trash2 size={ 14 } aria-hidden="true" />,
		},
	];

	return (
		<div className="space-y-3">
			<div className="flex flex-wrap items-center gap-2">
				<div
					className="flex gap-1"
					role="group"
					aria-label={ __( 'What to show', 'cliezoo' ) }
				>
					{ views.map( ( one ) => (
						<Button
							key={ one.id }
							size="sm"
							icon={ one.icon }
							variant={ one.id === view ? 'primary' : 'ghost' }
							aria-pressed={ one.id === view }
							onPress={ () => {
								setView( one.id );
								setPicked( [] );
							} }
						>
							{ one.label }
						</Button>
					) ) }
				</div>
				{ 'trash' === view && admin && (
					<Button
						size="sm"
						variant="ghost"
						className="ms-auto text-danger"
						onPress={ () => setConfirm( 'empty' ) }
					>
						{ __( 'Empty the trash', 'cliezoo' ) }
					</Button>
				) }
			</div>

			<div
				className="flex flex-wrap gap-1"
				role="group"
				aria-label={ __( 'Kinds of thing', 'cliezoo' ) }
			>
				<Button
					size="sm"
					variant={ '' === kind ? 'secondary' : 'ghost' }
					aria-pressed={ '' === kind }
					onPress={ () => setKind( '' ) }
				>
					{ __( 'All kinds', 'cliezoo' ) }
				</Button>
				{ ( kinds.data ?? [] ).map( ( one ) => (
					<Button
						key={ one.id }
						size="sm"
						variant={ one.id === kind ? 'secondary' : 'ghost' }
						aria-pressed={ one.id === kind }
						onPress={ () => setKind( one.id ) }
					>
						{ one.label }
					</Button>
				) ) }
			</div>

			<div className="flex flex-wrap items-end gap-2">
				<SearchField
					label={ __( 'Search the library', 'cliezoo' ) }
					placeholder={ __( 'Search by name…', 'cliezoo' ) }
					value={ typed }
					onChange={ setTyped }
					className="w-full sm:w-72"
				/>
				<Select
					label={ __( 'Added by', 'cliezoo' ) }
					className="w-36"
					items={ [
						{ id: 'anyone', label: __( 'Anyone', 'cliezoo' ) },
						{ id: 'me', label: __( 'Me', 'cliezoo' ) },
					] }
					selectedKey={ mine ? 'me' : 'anyone' }
					onSelectionChange={ ( chosen: unknown ) =>
						setMine( 'me' === chosen )
					}
				/>
				<Select
					label={ __( 'Added', 'cliezoo' ) }
					className="w-36"
					items={ [
						{ id: 'any', label: __( 'Any time', 'cliezoo' ) },
						{
							id: 'this_week',
							label: __( 'This week', 'cliezoo' ),
						},
						{
							id: 'this_month',
							label: __( 'This month', 'cliezoo' ),
						},
						{
							id: 'this_year',
							label: __( 'This year', 'cliezoo' ),
						},
						{
							id: 'last_year',
							label: __( 'Last year', 'cliezoo' ),
						},
					] }
					selectedKey={ created || 'any' }
					onSelectionChange={ ( chosen: unknown ) =>
						setCreated( 'any' === chosen ? '' : String( chosen ) )
					}
				/>
				<Select
					label={ __( 'Who may see it', 'cliezoo' ) }
					className="w-44"
					items={ [
						{ id: 'any', label: __( 'Anyone who may', 'cliezoo' ) },
						...Object.entries( VISIBILITY() ).map(
							( [ id, label ] ) => ( { id, label } )
						),
					] }
					selectedKey={ visibility || 'any' }
					onSelectionChange={ ( chosen: unknown ) =>
						setVisibility(
							'any' === chosen ? '' : String( chosen )
						)
					}
				/>
			</div>

			{ picked.length > 0 && 'everything' !== view && (
				<div className="flex flex-wrap items-center gap-3 rounded-lg border border-line bg-sunken px-4 py-2">
					<span className="text-sm text-fg">
						{ sprintf(
							/* translators: %d: how many are picked. */
							__( '%d picked', 'cliezoo' ),
							picked.length
						) }
					</span>
					<Button
						size="sm"
						icon={ <RotateCcw size={ 14 } aria-hidden="true" /> }
						onPress={ bringBack }
					>
						{ __( 'Bring back', 'cliezoo' ) }
					</Button>
					{ 'trash' === view && admin && (
						<Button
							size="sm"
							variant="ghost"
							className="text-danger"
							onPress={ () => setConfirm( 'purge' ) }
						>
							{ __( 'Delete for good', 'cliezoo' ) }
						</Button>
					) }
					<Button
						size="sm"
						variant="ghost"
						onPress={ () => setPicked( [] ) }
					>
						{ __( 'Clear', 'cliezoo' ) }
					</Button>
				</div>
			) }

			{ list.isPending && <Skeleton className="h-64" /> }
			{ list.isError && (
				<ErrorState
					error={ list.error }
					onRetry={ () => list.refetch() }
				/>
			) }
			{ ! list.isPending && ! list.isError && ! rows.length && (
				<EmptyState
					title={
						narrowed
							? __( 'Nothing matches', 'cliezoo' )
							: {
									everything: __(
										'Nothing here yet',
										'cliezoo'
									),
									archive: __(
										'Nothing is archived',
										'cliezoo'
									),
									trash: __(
										'The trash is empty',
										'cliezoo'
									),
								}[ view ]
					}
					actions={
						narrowed ? (
							<Button size="sm" onPress={ clearAll }>
								{ __(
									'Clear what is narrowing this',
									'cliezoo'
								) }
							</Button>
						) : undefined
					}
				>
					{ /* Not "Nothing here yet" over "Nothing matches what
					    you picked.": one of those is true and the other
					    is not, and together they read as a fault. */ }
					{ narrowed
						? __(
								'Nothing matches what you picked. Clearing it brings the rest back.',
								'cliezoo'
							)
						: null }
				</EmptyState>
			) }

			{ !! rows.length && (
				<div className="overflow-x-auto rounded-lg border border-line bg-raised">
					<table className="w-full text-sm">
						<thead className="border-b border-line text-start text-xs text-muted">
							<tr>
								{ 'everything' !== view && (
									<th className="w-10 px-3 py-2">
										<span className="sr-only">
											{ __( 'Pick', 'cliezoo' ) }
										</span>
									</th>
								) }
								<th className="px-3 py-2 text-start font-medium">
									{ __( 'Name', 'cliezoo' ) }
								</th>
								<th className="px-3 py-2 text-start font-medium">
									{ __( 'Kind', 'cliezoo' ) }
								</th>
								<th className="px-3 py-2 text-start font-medium">
									{ __( 'Who may see it', 'cliezoo' ) }
								</th>
								<th className="px-3 py-2 text-start font-medium">
									{ __( 'Client / project', 'cliezoo' ) }
								</th>
								<th className="px-3 py-2 text-start font-medium">
									{ __( 'Added by', 'cliezoo' ) }
								</th>
								<th className="px-3 py-2 text-start font-medium">
									{ __( 'Added', 'cliezoo' ) }
								</th>
							</tr>
						</thead>
						<tbody className="divide-y divide-line">
							{ rows.map( ( row ) => {
								// Something in the trash has nowhere to open;
								// something archived opens as it always did.
								const path =
									'trash' === view ? '' : openPath( row );
								const when = row.created_at;
								return (
									<tr key={ key( row ) }>
										{ 'everything' !== view && (
											<td className="px-3 py-2">
												<Checkbox
													isSelected={ picked.includes(
														key( row )
													) }
													aria-label={ sprintf(
														/* translators: %s: the name of the row being picked. */
														__(
															'Pick %s',
															'cliezoo'
														),
														row.label
													) }
													onChange={ (
														on: boolean
													) =>
														setPicked( ( now ) =>
															on
																? [
																		...now,
																		key(
																			row
																		),
																	]
																: now.filter(
																		(
																			one
																		) =>
																			one !==
																			key(
																				row
																			)
																	)
														)
													}
												/>
											</td>
										) }
										<td className="max-w-xs truncate px-3 py-2 font-medium text-fg">
											{ path ? (
												<Link
													to={
														path.split( '?' )[ 0 ]
													}
													search={
														Object.fromEntries(
															new URLSearchParams(
																path.split(
																	'?'
																)[ 1 ] ?? ''
															)
														) as never
													}
													className="hover:underline"
												>
													{ row.label }
												</Link>
											) : (
												row.label
											) }
										</td>
										<td className="px-3 py-2">
											<Badge tone="neutral">
												{ row.kind }
											</Badge>
										</td>
										<td className="px-3 py-2 text-muted">
											{ row.visibility
												? ( VISIBILITY()[
														row.visibility
													] ?? row.visibility )
												: '—' }
										</td>
										<td className="px-3 py-2 text-muted">
											{ [
												row.client?.label,
												row.project?.label,
											]
												.filter( Boolean )
												.join( ' · ' ) || '—' }
										</td>
										<td className="px-3 py-2 text-muted">
											{ row.author?.label ?? '—' }
										</td>
										<td
											className={ cx(
												'whitespace-nowrap px-3 py-2 text-muted'
											) }
											title={
												when
													? formatFullDate( when )
													: undefined
											}
										>
											{ when
												? formatRelative( when )
												: '—' }
											{ 'trash' === view &&
												!! row.deleted_at && (
													<span className="block text-xs text-subtle">
														{ sprintf(
															/* translators: %s: when, e.g. "2 days ago". */
															__(
																'trashed %s',
																'cliezoo'
															),
															formatRelative(
																row.deleted_at
															)
														) }
													</span>
												) }
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }

			{ list.hasNextPage && (
				<Button
					onPress={ () => list.fetchNextPage() }
					isDisabled={ list.isFetchingNextPage }
				>
					{ __( 'Show more', 'cliezoo' ) }
				</Button>
			) }

			<ConfirmDialog
				isOpen={ '' !== confirm }
				onOpenChange={ ( open ) => ! open && setConfirm( '' ) }
				title={
					'empty' === confirm
						? __( 'Empty the trash?', 'cliezoo' )
						: __( 'Delete these for good?', 'cliezoo' )
				}
				description={ __(
					'They cannot be brought back afterwards.',
					'cliezoo'
				) }
				confirmLabel={
					'empty' === confirm
						? __( 'Empty the trash', 'cliezoo' )
						: __( 'Delete for good', 'cliezoo' )
				}
				onConfirm={ () => {
					const which = confirm;
					setConfirm( '' );
					if ( 'empty' === which ) {
						run( '/library/empty-trash', {}, ( done ) =>
							sprintf(
								/* translators: %d: how many things. */
								_n(
									'%d thing deleted.',
									'%d things deleted.',
									done,
									'cliezoo'
								),
								done
							)
						);
					} else {
						run( '/library/purge', { items: items() }, ( done ) =>
							sprintf(
								/* translators: %d: how many things. */
								_n(
									'%d thing deleted.',
									'%d things deleted.',
									done,
									'cliezoo'
								),
								done
							)
						);
					}
				} }
			/>
		</div>
	);
}

export default Library;
