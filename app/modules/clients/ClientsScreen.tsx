/**
 * Every client, as a list.
 */
import { lazy, Suspense } from 'react';
import { __ } from '@wordpress/i18n';
import { useCreating } from '../../packages/app/useAskedToCreate';
import { useNavigate } from '@tanstack/react-router';
import { Building2, Plus } from 'lucide-react';
import { Button, Dialog, EmptyState, Skeleton, toast } from '@cliezoo/ui';
import { PageHeader } from '../../shell/Page';
import { RecordForm } from '../../packages/app/form/RecordForm';
import { DuplicateWarning } from './DuplicateWarning';
import type { EntityListProps } from '../../packages/app/list/EntityList';
import { SampleDataOffer } from '../../packages/app/list/SampleDataOffer';

const EntityList = lazy(
	() =>
		import(
			/* webpackChunkName: "list" */ '../../packages/app/list/EntityList'
		)
);

type Row = Parameters< NonNullable< EntityListProps[ 'rowMenu' ] > >[ 0 ];

/** What a new client is asked for: the least that makes a useful record. */
export const NEW_CLIENT_SECTIONS = () => [
	{
		fields: [ 'name', 'legal_name', 'email', 'phone', 'website', 'type' ],
	},
	{
		title: __( 'How you work with them', 'cliezoo' ),
		fields: [ 'industry', 'stage', 'owner', 'currency' ],
	},
];

export default function ClientsScreen() {
	const navigate = useNavigate();
	// The command palette opens this form by navigating here with ?new=1,
	// which is the only way it can reach a dialog that lives on a screen —
	// including when the screen is already open, which is why the ask is
	// watched rather than read once.
	const [ adding, setAdding ] = useCreating( true, false );

	return (
		<>
			<PageHeader
				title={ __( 'Clients', 'cliezoo' ) }
				actions={
					<>
						<Button
							variant="primary"
							icon={ <Plus size={ 16 } aria-hidden="true" /> }
							onPress={ () => setAdding( true ) }
						>
							{ __( 'New client', 'cliezoo' ) }
						</Button>
					</>
				}
			/>
			<Suspense fallback={ <Skeleton className="m-4 h-64" /> }>
				<EntityList
					entity="clients"
					label={ __( 'Clients', 'cliezoo' ) }
					columns={ [
						'name',
						'type',
						'industry',
						'stage',
						'owner',
						'health_score',
					] }
					rowHref={ ( row: Row ) => `/clients/${ row.uid }` }
					empty={
						<EmptyState
							icon={
								<Building2 size={ 22 } aria-hidden="true" />
							}
							title={ __( 'No clients yet', 'cliezoo' ) }
							actions={
								<>
									<Button
										variant="primary"
										onPress={ () => setAdding( true ) }
									>
										{ __(
											'Add your first client',
											'cliezoo'
										) }
									</Button>
									<SampleDataOffer />
								</>
							}
						>
							{ __(
								'A client is the company or person you do the work for. Their contacts and files hang off one.',
								'cliezoo'
							) }
						</EmptyState>
					}
				/>
			</Suspense>

			<Dialog
				isOpen={ adding }
				onOpenChange={ setAdding }
				size="lg"
				title={ __( 'New client', 'cliezoo' ) }
			>
				<RecordForm
					entity="clients"
					sections={ NEW_CLIENT_SECTIONS() }
					watch={ ( draft ) => <DuplicateWarning draft={ draft } /> }
					submitLabel={ __( 'Create client', 'cliezoo' ) }
					onCancel={ () => setAdding( false ) }
					onSaved={ ( record ) => {
						setAdding( false );
						toast.success( __( 'Client created.', 'cliezoo' ) );
						navigate( {
							to: `/clients/${ String( record.uid ) }`,
						} );
					} }
				/>
			</Dialog>
		</>
	);
}
