/**
 * Everybody you talk to, as a list.
 */
import { lazy, Suspense, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useCreating } from '../../packages/app/useAskedToCreate';
import { useNavigate } from '@tanstack/react-router';
import { Plus, UserRound } from 'lucide-react';
import {
	Button,
	Dialog,
	EmptyState,
	Skeleton,
	TextField,
	toast,
} from '@cliezoo/ui';
import { api, type ApiError } from '@cliezoo/data';
import { PageHeader } from '../../shell/Page';
import { RecordForm } from '../../packages/app/form/RecordForm';
import type { EntityListProps } from '../../packages/app/list/EntityList';
import { SampleDataOffer } from '../../packages/app/list/SampleDataOffer';

const EntityList = lazy(
	() =>
		import(
			/* webpackChunkName: "list" */ '../../packages/app/list/EntityList'
		)
);

type Row = Parameters< NonNullable< EntityListProps[ 'rowMenu' ] > >[ 0 ];

/** What a new contact is asked for. */
export const NEW_CONTACT_SECTIONS = () => [
	{
		fields: [ 'name', 'email', 'phone', 'title', 'client' ],
	},
	{
		title: __( 'Where they came from', 'cliezoo' ),
		fields: [ 'status', 'source', 'type', 'owner' ],
	},
];

export default function ContactsScreen() {
	const navigate = useNavigate();
	// The palette opens this form by navigating here with ?new=1 — which
	// has to work when the screen is already open, so the ask is watched
	// rather than read once.
	const [ adding, setAdding ] = useCreating( true, false );
	// A contact at a company that is not a client yet: the client is made
	// first, from its name, and the contact is made there.
	const [ company, setCompany ] = useState( '' );
	const [ made, setMade ] = useState< { uid: string; name: string } | null >(
		null
	);
	const makeClient = async () => {
		try {
			const client = (
				await api.post< { uid: string; name: string } >( '/clients', {
					name: company.trim(),
				} )
			).data;
			setMade( client );
			setCompany( '' );
			toast.success(
				sprintf(
					/* translators: %s: a client's name. */
					__(
						'%s is a client now, and stays one whatever you do with this form.',
						'cliezoo'
					),
					client.name
				)
			);
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	return (
		<>
			<PageHeader
				title={ __( 'Contacts', 'cliezoo' ) }
				actions={
					<>
						<Button
							variant="primary"
							icon={ <Plus size={ 16 } aria-hidden="true" /> }
							onPress={ () => setAdding( true ) }
						>
							{ __( 'New contact', 'cliezoo' ) }
						</Button>
					</>
				}
			/>
			<Suspense fallback={ <Skeleton className="m-4 h-64" /> }>
				<EntityList
					entity="contacts"
					label={ __( 'Contacts', 'cliezoo' ) }
					columns={ [
						'name',
						'title',
						'email',
						'client',
						'phone',
						'status',
						'email_problem_at',
					] }
					rowHref={ ( row: Row ) => `/contacts/${ row.uid }` }
					empty={
						<EmptyState
							icon={
								<UserRound size={ 22 } aria-hidden="true" />
							}
							title={ __( 'No contacts yet', 'cliezoo' ) }
							actions={
								<>
									<Button
										variant="primary"
										onPress={ () => setAdding( true ) }
									>
										{ __( 'Add someone', 'cliezoo' ) }
									</Button>
									<SampleDataOffer />
								</>
							}
						>
							{ __(
								'Contacts are the people at your clients — a name and a way to reach them.',
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
				title={ __( 'New contact', 'cliezoo' ) }
			>
				<div className="mb-4 rounded-md border border-line bg-sunken p-3">
					{ made ? (
						<p className="flex flex-wrap items-center gap-2 text-sm">
							{ __( 'At the new client', 'cliezoo' ) }{ ' ' }
							<strong>{ made.name }</strong>
							<Button
								size="sm"
								variant="ghost"
								onPress={ () => setMade( null ) }
							>
								{ __( 'Choose another', 'cliezoo' ) }
							</Button>
						</p>
					) : (
						<div className="flex flex-wrap items-end gap-2">
							<TextField
								label={ __(
									'Their company is not a client yet?',
									'cliezoo'
								) }
								placeholder={ __( 'Company name', 'cliezoo' ) }
								value={ company }
								onChange={ setCompany }
								className="min-w-56 flex-1"
							/>
							<Button
								size="sm"
								isDisabled={ ! company.trim() }
								onPress={ makeClient }
							>
								{ __( 'Create the client', 'cliezoo' ) }
							</Button>
						</div>
					) }
				</div>
				{ /* No key: making the client is something that happens
				     beside this form, not instead of it. Remounting here
				     blanked every field somebody had filled in and then
				     told them their changes had not been saved. */ }
				<RecordForm
					entity="contacts"
					fixed={ made ? { client: made.uid } : undefined }
					sections={
						made
							? NEW_CONTACT_SECTIONS().map( ( section ) => ( {
									...section,
									fields: section.fields.filter(
										( key ) => 'client' !== key
									),
								} ) )
							: NEW_CONTACT_SECTIONS()
					}
					submitLabel={ __( 'Create contact', 'cliezoo' ) }
					onCancel={ () => setAdding( false ) }
					onSaved={ ( record ) => {
						setAdding( false );
						setMade( null );
						toast.success( __( 'Contact created.', 'cliezoo' ) );
						navigate( {
							to: `/contacts/${ String( record.uid ) }`,
						} );
					} }
				/>
			</Dialog>

		</>
	);
}
