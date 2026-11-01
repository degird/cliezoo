/**
 * Leads: everybody who has asked about work and is not a client yet.
 *
 * The list is not a filing cabinet. The only thing it is really for is
 * showing who has gone quiet, so the day count since anybody last spoke to
 * a lead is a column rather than something to work out, and the leads
 * nobody has touched are said out loud above the table.
 *
 * A lead needs nothing but a name. Somebody phoning to ask about a job has
 * not given their email yet, and a form that refuses them is how they end
 * up on a sticky note.
 */
import { Suspense, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { KanbanSquare, Plus, Table2, UserPlus } from 'lucide-react';
import { api, useQuery } from '@cliezoo/data';
import { Alert, Button, Dialog, EmptyState, Skeleton } from '@cliezoo/ui';
import { RouterLink } from '../../packages/app';
import EntityList from '../../packages/app/list/EntityList';
import { RecordForm } from '../../packages/app/form/RecordForm';
import { PageHeader } from '../../shell/Page';
import { Slot } from '../../packages/app/slot';
import { useCreating } from '../../packages/app/useAskedToCreate';
import { LeadBoard } from './LeadBoard';

type Quiet = {
	leads: Array< { uid: string; name: string; days: number } >;
};

/** What starting one asks for, and nothing more. */
const NEW_LEAD = () => [
	{
		fields: [ 'name', 'email', 'phone', 'business_name' ],
	},
	{
		title: __( 'What they are after', 'cliezoo' ),
		fields: [ 'source', 'status', 'budget', 'currency', 'rating' ],
	},
	{
		title: __( 'Anything else', 'cliezoo' ),
		fields: [ 'website', 'owner', 'notes' ],
		wide: true,
	},
];

export default function LeadsScreen() {
	const [ adding, setAdding ] = useCreating( true, false );
	// A table by default: leads arrive faster than they are sorted, and a
	// list is where one is found. The board is for working them through.
	const [ how, setHow ] = useState< 'table' | 'board' >( 'table' );

	// Who has gone quiet. The number of days is the server's, because
	// "fourteen days" is a workspace setting and a browser clock is not.
	const quiet = useQuery< Quiet >( {
		queryKey: [ 'leads', 'quiet' ],
		queryFn: async () => ( await api.get< Quiet >( '/leads/quiet' ) ).data,
	} );
	const waiting = quiet.data?.leads ?? [];

	return (
		<>
			<PageHeader
				title={ __( 'Leads', 'cliezoo' ) }
				actions={
					<>
						<Slot name="leads.toolbar" variant="chip" />
						<Button
							icon={
								'board' === how ? (
									<Table2 size={ 15 } aria-hidden="true" />
								) : (
									<KanbanSquare
										size={ 15 }
										aria-hidden="true"
									/>
								)
							}
							onPress={ () =>
								setHow( 'board' === how ? 'table' : 'board' )
							}
						>
							{ 'board' === how
								? __( 'As a table', 'cliezoo' )
								: __( 'As a board', 'cliezoo' ) }
						</Button>
						<Button
							variant="primary"
							icon={ <Plus size={ 16 } aria-hidden="true" /> }
							onPress={ () => setAdding( true ) }
						>
							{ __( 'New lead', 'cliezoo' ) }
						</Button>
					</>
				}
			/>

			{ !! waiting.length && (
				<div className="px-4 pt-4">
					<Alert
						tone="warning"
						role="status"
						title={ sprintf(
							/* translators: %d: how many leads have gone quiet. */
							_n(
								'%d lead has gone quiet',
								'%d leads have gone quiet',
								waiting.length,
								'cliezoo'
							),
							waiting.length
						) }
					>
						<ul className="flex flex-wrap gap-x-3 gap-y-1">
							{ waiting.slice( 0, 8 ).map( ( one ) => (
								<li key={ one.uid }>
									<RouterLink
										to={ `/leads/${ one.uid }` }
										className="text-accent-fg hover:underline"
									>
										{ one.name }
									</RouterLink>{ ' ' }
									<span className="text-xs text-muted">
										{ sprintf(
											/* translators: %d: a number of days. */
											_n(
												'%d day',
												'%d days',
												one.days,
												'cliezoo'
											),
											one.days
										) }
									</span>
								</li>
							) ) }
						</ul>
					</Alert>
				</div>
			) }

			{ 'board' === how && <LeadBoard /> }
			{ 'table' === how && (
				<Suspense fallback={ <Skeleton className="m-4 h-64" /> }>
					<EntityList
						entity="leads"
						label={ __( 'Leads', 'cliezoo' ) }
						columns={ [
							'number',
							'name',
							'business_name',
							'source',
							'status',
							'rating',
							'score',
							'owner',
							'last_touch_at',
						] }
						rowHref={ ( row: { uid: string } ) =>
							`/leads/${ row.uid }`
						}
						empty={
							<EmptyState
								icon={
									<UserPlus size={ 22 } aria-hidden="true" />
								}
								title={ __( 'No leads yet', 'cliezoo' ) }
								actions={
									<Button
										variant="primary"
										onPress={ () => setAdding( true ) }
									>
										{ __(
											'Write the first one down',
											'cliezoo'
										) }
									</Button>
								}
							>
								{ __(
									'A lead is anybody who has asked about work. A name is all it needs — the rest can be filled in after the call.',
									'cliezoo'
								) }
							</EmptyState>
						}
					/>
				</Suspense>
			) }

			<Dialog
				isOpen={ adding }
				onOpenChange={ ( open: boolean ) =>
					! open && setAdding( false )
				}
				size="lg"
				title={ __( 'New lead', 'cliezoo' ) }
			>
				<Slot name="lead.create-options" variant="row" />
				<RecordForm
					entity="leads"
					sections={ NEW_LEAD() }
					rich={ [ 'notes' ] }
					submitLabel={ __( 'Write it down', 'cliezoo' ) }
					onCancel={ () => setAdding( false ) }
					onSaved={ () => setAdding( false ) }
				/>
			</Dialog>
		</>
	);
}
