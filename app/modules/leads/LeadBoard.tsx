/**
 * Leads as a board, one column to each status.
 *
 * The same leads as the table, laid out by where each one has got to, and
 * moved by dragging — or from the keyboard, the way the deals board is. The
 * statuses are the workspace's own list, in its order.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useNavigate } from '@tanstack/react-router';
import { api, useQuery, useQueryClient, type ApiError } from '@cliezoo/data';
import { DragList, ErrorState, Skeleton, toast } from '@cliezoo/ui';

type Lead = {
	uid: string;
	name: string;
	business_name?: string | null;
	status?: string | null;
	score?: number | null;
};

/** The most leads a column draws; the table has the rest. */
const PER_COLUMN = 50;

export function LeadBoard() {
	const navigate = useNavigate();
	const queries = useQueryClient();
	const statuses = useQuery( {
		queryKey: [ 'lists' ],
		staleTime: 5 * 60_000,
		queryFn: async () =>
			(
				await api.get<
					Record<
						string,
						{ values: Array< { value: string; label: string } > }
					>
				>( '/lists' )
			).data,
		select: ( lists ) => lists.lead_status?.values ?? [],
	} );
	const columns = statuses.data ?? [];
	const leads = useQuery( {
		queryKey: [ 'leads', 'board', columns.map( ( one ) => one.value ) ],
		enabled: columns.length > 0,
		queryFn: async () => {
			const each = await Promise.all(
				columns.map(
					async ( one ) =>
						[
							one.value,
							(
								await api.get< Lead[] >( '/leads', {
									sort: '-last_touch_at',
									limit: PER_COLUMN,
									filter: JSON.stringify( {
										and: [
											{
												field: 'status',
												op: 'eq',
												value: one.value,
											},
										],
									} ),
								} )
							).data,
						] as const
				)
			);
			return Object.fromEntries( each ) as Record< string, Lead[] >;
		},
	} );

	const move = async ( uid: string, status: string ) => {
		try {
			await api.patch( `/leads/${ uid }`, { status } );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
		await queries.invalidateQueries( { queryKey: [ 'leads' ] } );
	};

	if ( statuses.isError || leads.isError ) {
		return (
			<div className="p-4">
				<ErrorState
					error={ ( statuses.error ?? leads.error ) as Error }
					onRetry={ () => {
						statuses.refetch();
						leads.refetch();
					} }
				/>
			</div>
		);
	}
	if ( ! statuses.data || ! leads.data ) {
		return <Skeleton className="m-4 h-64" />;
	}

	return (
		<div
			className="flex gap-3 overflow-x-auto p-4"
			role="group"
			aria-label={ __( 'Leads by status', 'cliezoo' ) }
		>
			{ columns.map( ( column ) => {
				const here = leads.data[ column.value ] ?? [];
				return (
					<section
						key={ column.value }
						aria-label={ column.label }
						className="flex w-72 shrink-0 flex-col rounded-lg border border-line bg-surface"
					>
						<header className="flex items-baseline justify-between gap-2 border-b border-line p-3">
							<h3 className="text-sm font-semibold text-fg">
								{ column.label }
							</h3>
							<span className="text-xs tabular-nums text-muted">
								{ sprintf(
									/* translators: %d: how many leads. */
									_n(
										'%d lead',
										'%d leads',
										here.length,
										'cliezoo'
									),
									here.length
								) }
							</span>
						</header>
						<DragList
							listId={ column.value }
							label={ column.label }
							className="flex min-h-24 flex-col gap-2 p-2"
							items={ here.map( ( lead ) => ( {
								id: lead.uid,
								label: lead.name,
								children: (
									<article className="rounded border border-line bg-raised p-2">
										<p className="text-sm font-medium text-fg">
											{ lead.name }
										</p>
										{ !! lead.business_name && (
											<p className="text-xs text-muted">
												{ lead.business_name }
											</p>
										) }
										{ null !== lead.score &&
											undefined !== lead.score && (
												<p className="mt-1 text-xs tabular-nums text-muted">
													{ sprintf(
														/* translators: %d: a lead's score. */
														__(
															'Score %d',
															'cliezoo'
														),
														lead.score
													) }
												</p>
											) }
									</article>
								),
							} ) ) }
							empty={
								<p className="p-2 text-xs text-muted">
									{ __( 'Nothing here yet.', 'cliezoo' ) }
								</p>
							}
							onDrop={ ( ids ) => {
								const lead = ids[ 0 ];
								if ( lead ) {
									move( lead, column.value );
								}
							} }
							onOpen={ ( id ) =>
								navigate( {
									to: '/leads/$uid',
									params: { uid: id },
								} )
							}
						/>
					</section>
				);
			} ) }
		</div>
	);
}
