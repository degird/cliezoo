/**
 * A client's addresses: several, each with a type, and one of them the
 * default.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { MapPin, Pencil, Plus, Trash2 } from 'lucide-react';
import { api, useQuery, useQueryClient, type ApiError } from '@cliezoo/data';
import {
	Badge,
	ConfirmDialog,
	Dialog,
	ErrorState,
	IconButton,
	Skeleton,
	toast,
} from '@cliezoo/ui';
import { RailEmpty, RailPanel } from '../../packages/app/record/RecordScreen';
import { RecordForm } from '../../packages/app/form/RecordForm';

type Location = Record< string, unknown > & {
	uid: string;
	type: string;
	is_default?: boolean;
};

/**
 * An address on one line, the way an envelope would read.
 * @param row
 */
export function oneLine( row: Record< string, unknown > ): string {
	return [ 'street', 'street2', 'city', 'region', 'postal_code', 'country' ]
		.map( ( key ) => String( row[ key ] ?? '' ).trim() )
		.filter( ( part ) => '' !== part )
		.join( ', ' );
}

const TYPES = (): Record< string, string > => ( {
	billing: __( 'Billing', 'cliezoo' ),
	shipping: __( 'Shipping', 'cliezoo' ),
	office: __( 'Office', 'cliezoo' ),
	other: __( 'Other', 'cliezoo' ),
} );

const SECTIONS = () => [
	{
		fields: [ 'type', 'label' ],
	},
	{
		fields: [
			'street',
			'street2',
			'city',
			'region',
			'postal_code',
			'country',
		],
	},
	{
		fields: [ 'is_default' ],
	},
];

export function LocationsPanel( {
	client,
}: {
	client: Record< string, unknown > & { uid: string };
} ) {
	const queries = useQueryClient();
	const [ editing, setEditing ] = useState< Location | null | 'new' >( null );

	const locations = useQuery( {
		queryKey: [ 'client_locations', client.uid ],
		queryFn: async () =>
			(
				await api.get< Location[] >( '/client_locations', {
					filter: JSON.stringify( {
						and: [
							{ field: 'client', op: 'eq', value: client.uid },
						],
					} ),
					limit: 50,
				} )
			).data,
	} );

	const refresh = () =>
		queries.invalidateQueries( {
			queryKey: [ 'client_locations', client.uid ],
		} );

	const [ removing, setRemoving ] = useState< Location | null >( null );

	const remove = async ( row: Location ) => {
		try {
			await api.delete( `/client_locations/${ row.uid }` );
			await refresh();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	const rows = locations.data ?? [];
	const labels = TYPES();

	return (
		<>
			<RailPanel
				title={ __( 'Addresses', 'cliezoo' ) }
				count={ locations.isSuccess ? rows.length : undefined }
				action={
					<IconButton
						size="sm"
						label={ __( 'Add an address', 'cliezoo' ) }
						icon={ <Plus size={ 15 } aria-hidden="true" /> }
						onPress={ () => setEditing( 'new' ) }
					/>
				}
			>
				{ locations.isPending && <Skeleton className="h-12" /> }
				{ locations.isError && (
					<ErrorState
						error={ locations.error }
						onRetry={ () => locations.refetch() }
					/>
				) }
				{ ! locations.isPending &&
					! locations.isError &&
					! rows.length && (
						<RailEmpty
							onAdd={ () => setEditing( 'new' ) }
							addLabel={ __( 'Add one', 'cliezoo' ) }
						>
							{ __(
								'No address yet.',
								'cliezoo'
							) }
						</RailEmpty>
					) }
				<ul className="space-y-3">
					{ rows.map( ( row ) => (
						<li key={ row.uid } className="group flex gap-2">
							<MapPin
								size={ 14 }
								aria-hidden="true"
								className="mt-0.5 shrink-0 text-muted"
							/>
							<div className="min-w-0 flex-1">
								<p className="flex flex-wrap items-center gap-1.5">
									<span className="text-xs font-medium text-fg">
										{ String( row.label ?? '' ) ||
											labels[ row.type ] ||
											row.type }
									</span>
									{ row.is_default && (
										<Badge tone="accent">
											{ __( 'Default', 'cliezoo' ) }
										</Badge>
									) }
								</p>
								<p className="text-xs text-muted">
									{ oneLine( row ) }
								</p>
							</div>
							<span className="flex shrink-0 gap-0.5 opacity-0 focus-within:opacity-100 group-hover:opacity-100 [@media(hover:none)]:opacity-100">
								<IconButton
									size="sm"
									label={ __(
										'Edit this address',
										'cliezoo'
									) }
									icon={
										<Pencil
											size={ 13 }
											aria-hidden="true"
										/>
									}
									onPress={ () => setEditing( row ) }
								/>
								<IconButton
									size="sm"
									label={ __(
										'Delete this address',
										'cliezoo'
									) }
									icon={
										<Trash2
											size={ 13 }
											aria-hidden="true"
										/>
									}
									onPress={ () => setRemoving( row ) }
								/>
							</span>
						</li>
					) ) }
				</ul>
			</RailPanel>

			{ /* An address is deleted for good — there is no trash for one —
			     so it is asked about rather than undone. */ }
			<ConfirmDialog
				isOpen={ null !== removing }
				onOpenChange={ ( open ) => ! open && setRemoving( null ) }
				title={ __( 'Delete this address?', 'cliezoo' ) }
				description={ removing ? oneLine( removing ) : undefined }
				onConfirm={ () => {
					if ( removing ) {
						remove( removing );
					}
					setRemoving( null );
				} }
			/>

			<Dialog
				isOpen={ null !== editing }
				onOpenChange={ ( open ) => ! open && setEditing( null ) }
				title={
					'new' === editing
						? __( 'Add an address', 'cliezoo' )
						: __( 'Edit address', 'cliezoo' )
				}
			>
				<RecordForm
					entity="client_locations"
					record={ 'new' === editing ? null : editing }
					fixed={ { client: client.uid } }
					sections={ SECTIONS() }
					onCancel={ () => setEditing( null ) }
					onSaved={ async () => {
						setEditing( null );
						await refresh();
					} }
				/>
			</Dialog>
		</>
	);
}
