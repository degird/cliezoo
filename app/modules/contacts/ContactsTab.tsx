/**
 * The people at one client, on the client's own page.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Plus } from 'lucide-react';
import { api, useQuery, useQueryClient } from '@cliezoo/data';
import {
	Avatar,
	Badge,
	Button,
	Dialog,
	EmptyState,
	ErrorState,
	Skeleton,
	toast,
} from '@cliezoo/ui';
import { RouterLink } from '../../packages/app';
import { RecordForm } from '../../packages/app/form/RecordForm';

type Contact = Record< string, unknown > & {
	uid: string;
	name: string;
	email?: string;
};

export function ContactsTab( {
	record,
}: {
	record: Record< string, unknown >;
} ) {
	const client = String( record.uid );
	const queries = useQueryClient();
	const [ adding, setAdding ] = useState( false );

	const contacts = useQuery( {
		queryKey: [ 'contacts', 'of', client ],
		queryFn: async () =>
			(
				await api.get< Contact[] >( '/contacts', {
					filter: JSON.stringify( {
						and: [ { field: 'client', op: 'eq', value: client } ],
					} ),
					sort: 'name',
					limit: 100,
				} )
			).data,
	} );

	const refresh = () =>
		queries.invalidateQueries( { queryKey: [ 'contacts' ] } );

	const rows = contacts.data ?? [];

	return (
		<div className="space-y-4">
			<div className="flex items-center justify-between gap-3">
				<p className="text-sm text-muted">
					{ __(
						'The people you talk to at this client.',
						'cliezoo'
					) }
				</p>
				<div className="flex gap-2">
					<Button
						icon={ <Plus size={ 15 } aria-hidden="true" /> }
						onPress={ () => setAdding( true ) }
					>
						{ __( 'Add someone', 'cliezoo' ) }
					</Button>
				</div>
			</div>

			{ contacts.isPending && <Skeleton className="h-32" /> }
			{ contacts.isError && (
				<ErrorState
					error={ contacts.error }
					onRetry={ () => contacts.refetch() }
				/>
			) }
			{ ! contacts.isPending && ! contacts.isError && ! rows.length && (
				<EmptyState title={ __( 'Nobody here yet', 'cliezoo' ) }>
					{ __(
						'Add the people you actually talk to.',
						'cliezoo'
					) }
				</EmptyState>
			) }

			{ rows.length > 0 && (
				<ul className="divide-y divide-line overflow-hidden rounded-lg border border-line bg-raised">
					{ rows.map( ( contact ) => (
						<li
							key={ contact.uid }
							className="flex flex-wrap items-center gap-3 px-4 py-3"
						>
							<Avatar name={ contact.name } size="sm" />
							<div className="min-w-0 flex-1">
								<p className="flex flex-wrap items-center gap-2">
									<RouterLink
										to={ `/contacts/${ contact.uid }` }
										className="font-medium text-fg hover:underline"
									>
										{ contact.name }
									</RouterLink>
									{ !! contact.is_primary && (
										<Badge tone="accent">
											{ __( 'Primary', 'cliezoo' ) }
										</Badge>
									) }
									{ !! contact.is_billing && (
										<Badge tone="neutral">
											{ __( 'Billing', 'cliezoo' ) }
										</Badge>
									) }
								</p>
								<p className="text-sm text-muted">
									{ [
										String( contact.title ?? '' ),
										String( contact.email ?? '' ),
									]
										.filter( Boolean )
										.join( ' · ' ) }
								</p>
							</div>
						</li>
					) ) }
				</ul>
			) }

			<Dialog
				isOpen={ adding }
				onOpenChange={ setAdding }
				size="lg"
				title={ __( 'Add someone at this client', 'cliezoo' ) }
			>
				<RecordForm
					entity="contacts"
					fixed={ { client } }
					sections={ [
						{
							fields: [
								'name',
								'email',
								'phone',
								'title',
								'role',
							],
						},
						{
							title: __( 'What they are here', 'cliezoo' ),
							fields: [
								'is_primary',
								'is_billing',
								'is_technical',
								'is_decision_maker',
							],
						},
					] }
					submitLabel={ __( 'Add contact', 'cliezoo' ) }
					onCancel={ () => setAdding( false ) }
					onSaved={ async () => {
						setAdding( false );
						await refresh();
						toast.success( __( 'Contact added.', 'cliezoo' ) );
					} }
				/>
			</Dialog>

		</div>
	);
}

export default ContactsTab;
