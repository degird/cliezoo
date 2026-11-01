/**
 * One contact: who they are, where they work, and how they get in.
 */
import { Suspense, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useParams } from '@tanstack/react-router';
import { Pencil } from 'lucide-react';
import { api, useQuery, useQueryClient } from '@cliezoo/data';
import { Badge, Button, Dialog, toast } from '@cliezoo/ui';
import { RecordScreen } from '../../packages/app/record/RecordScreen';
import { WatchButton } from '../../packages/app/WatchButton';
import { NotesPanel, TodosPanel } from '../../packages/app/record/NotesPanel';
import { RecordForm } from '../../packages/app/form/RecordForm';
import { RouterLink } from '../../packages/app';
import { MarketingPanel } from './MarketingPanel';
import { SignInsPanel } from './SignInsPanel';

type Contact = Record< string, unknown > & { uid: string; name: string };

/** Everything worth editing about a person. */
export const CONTACT_SECTIONS = () => [
	{
		title: __( 'Who they are', 'cliezoo' ),
		fields: [
			'name',
			'preferred_name',
			'email',
			'emails',
			'phone',
			'title',
			'handle',
			'avatar_file',
			'tags',
		],
	},
	{
		title: __( 'Where they work', 'cliezoo' ),
		fields: [ 'client', 'role', 'owner' ],
	},
	{
		title: __( 'What kind of contact', 'cliezoo' ),
		fields: [ 'status', 'source', 'type' ],
	},
	{
		title: __( 'What they are to this client', 'cliezoo' ),
		description: __(
			'Used when Cliezoo has to pick somebody: invoices go to the billing contact, questions to the technical one.',
			'cliezoo'
		),
		fields: [
			'is_primary',
			'is_decision_maker',
			'is_billing',
			'is_technical',
		],
	},
	{
		title: __( 'Their settings', 'cliezoo' ),
		fields: [
			'locale',
			'timezone',
			'date_format',
			'time_format',
			'week_start',
			'notification_preset',
		],
	},
];

export default function ContactScreen() {
	const { uid } = useParams( { strict: false } ) as { uid: string };
	const queries = useQueryClient();
	const [ editing, setEditing ] = useState( false );

	const contact = useQuery( {
		queryKey: [ 'contacts', uid ],
		queryFn: async () =>
			( await api.get< Contact >( `/contacts/${ uid }` ) ).data,
	} );
	const record = contact.data;

	return (
		<>
			<RecordScreen
				type="contacts"
				record={ record }
				isPending={ contact.isPending }
				error={ contact.isError ? contact.error : undefined }
				onRetry={ () => contact.refetch() }
				title={ String( record?.name ?? '' ) }
				avatar={ { name: String( record?.name ?? '' ) } }
				meta={
					record ? (
						<span className="flex flex-wrap items-center gap-x-2 gap-y-1">
							{ !! record.title && (
								<span>{ String( record.title ) }</span>
							) }
							{ !! record.email && (
								<a
									href={ `mailto:${ String( record.email ) }` }
									className="text-accent-fg hover:underline"
								>
									{ String( record.email ) }
								</a>
							) }
							{ !! record.phone && (
								<span>{ String( record.phone ) }</span>
							) }
							{ !! record.client && (
								<RouterLink
									to={ `/clients/${ String(
										( record.client as { uid: string } ).uid
									) }` }
									className="text-accent-fg hover:underline"
								>
									{ String(
										( record.client as { label: string } )
											.label
									) }
								</RouterLink>
							) }
							{ !! record.is_primary && (
								<Badge tone="accent">
									{ __( 'Primary contact', 'cliezoo' ) }
								</Badge>
							) }
							{ /* Email to this address did not arrive. Said
							     here because a studio waiting for a reply
							     needs to know there will not be one, and
							     what the mail server said about why. */ }
							{ !! record.email_problem_at && (
								<Badge tone="warning">
									{ record.email_problem
										? sprintf(
												/* translators: %s: what the mail server said. */
												__(
													'Email problem: %s',
													'cliezoo'
												),
												String( record.email_problem )
											)
										: __( 'Email problem', 'cliezoo' ) }
								</Badge>
							) }
						</span>
					) : null
				}
				actions={
					<>
						{ !! record && (
							<WatchButton
								type="contacts"
								uid={ uid }
								label={ String( record.name ?? '' ) }
							/>
						) }
						<Button
							icon={ <Pencil size={ 15 } aria-hidden="true" /> }
							onPress={ () => setEditing( true ) }
						>
							{ __( 'Edit', 'cliezoo' ) }
						</Button>
					</>
				}
				rail={
					record ? (
						<>
							<MarketingPanel
								email={ String( record.email ?? '' ) }
							/>
							{ !! ( record.member as { uid?: string } | null )
								?.uid && (
								<SignInsPanel
									member={ String(
										( record.member as { uid: string } ).uid
									) }
								/>
							) }
							<NotesPanel type="contacts" uid={ uid } />
							<TodosPanel type="contacts" uid={ uid } />
						</>
					) : null
				}
				overview={ record ? <Overview record={ record } /> : null }
			/>

			<Dialog
				isOpen={ editing }
				onOpenChange={ setEditing }
				size="xl"
				title={ __( 'Edit contact', 'cliezoo' ) }
			>
				<Suspense fallback={ null }>
					<RecordForm
						entity="contacts"
						record={ record }
						sections={ CONTACT_SECTIONS() }
						onCancel={ () => setEditing( false ) }
						onSaved={ async () => {
							setEditing( false );
							await queries.invalidateQueries( {
								queryKey: [ 'contacts', uid ],
							} );
							toast.success( __( 'Contact saved.', 'cliezoo' ) );
						} }
					/>
				</Suspense>
			</Dialog>
		</>
	);
}

/**
 * The details that do not belong in a panel.
 * @param root0
 * @param root0.record
 */
function Overview( { record }: { record: Contact } ) {
	const flags = [
		[ 'is_primary', __( 'Primary contact', 'cliezoo' ) ],
		[ 'is_decision_maker', __( 'Decision maker', 'cliezoo' ) ],
		[ 'is_billing', __( 'Billing contact', 'cliezoo' ) ],
		[ 'is_technical', __( 'Technical contact', 'cliezoo' ) ],
	].filter( ( [ key ] ) => !! record[ key as string ] );

	const rows: Array< [ string, string ] > = (
		[
			[
				__( 'Role at the client', 'cliezoo' ),
				String( record.role ?? '' ),
			],
			[
				__( 'Status', 'cliezoo' ),
				String( record.status_label ?? record.status ?? '' ),
			],
			[
				__( 'Source', 'cliezoo' ),
				String( record.source_label ?? record.source ?? '' ),
			],
			[
				__( 'Type', 'cliezoo' ),
				String( record.type_label ?? record.type ?? '' ),
			],
			[
				__( 'Owner', 'cliezoo' ),
				String(
					( record.owner as { label?: string } | null )?.label ?? ''
				),
			],
			[
				__( 'Mention handle', 'cliezoo' ),
				String( record.handle ?? '' ),
			],
			[ __( 'Language', 'cliezoo' ), String( record.locale ?? '' ) ],
			[ __( 'Time zone', 'cliezoo' ), String( record.timezone ?? '' ) ],
		] as Array< [ string, string ] >
	 ).filter( ( pair ) => '' !== pair[ 1 ] );

	return (
		<section className="rounded-lg border border-line bg-raised p-5">
			<h2 className="text-sm font-semibold text-fg">
				{ __( 'Details', 'cliezoo' ) }
			</h2>
			{ flags.length > 0 && (
				<p className="mt-2 flex flex-wrap gap-1.5">
					{ flags.map( ( [ key, label ] ) => (
						<Badge key={ key as string } tone="neutral">
							{ label as string }
						</Badge>
					) ) }
				</p>
			) }
			<dl className="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2">
				{ rows.map( ( [ label, value ] ) => (
					<div key={ label }>
						<dt className="text-xs text-subtle">{ label }</dt>
						<dd className="text-sm text-fg">{ value }</dd>
					</div>
				) ) }
			</dl>
		</section>
	);
}
