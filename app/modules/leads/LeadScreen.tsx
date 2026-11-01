/**
 * One lead: who they are, what they asked for, and what happened next.
 *
 * Three things a lead screen has to make easy, because a studio that has to
 * think about them stops doing them: saying you spoke to somebody, saying
 * what came of it, and turning the ones that said yes into a client without
 * typing their details again.
 *
 * The score is shown and never asked for. It is worked out from the fields
 * on the way in to every write, so a number somebody could type would be a
 * number that disagreed with what it was made of.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useNavigate, useParams } from '@tanstack/react-router';
import { CheckCircle2, Pencil, Phone, UserCheck } from 'lucide-react';
import {
	api,
	formatDate,
	formatMoney,
	getBoot,
	useQuery,
	useRecord,
	type ApiError,
} from '@cliezoo/data';
import {
	Badge,
	Button,
	Dialog,
	Menu,
	MenuItem,
	MenuTrigger,
	Select,
	TextField,
	toast,
} from '@cliezoo/ui';
import { RouterLink } from '../../packages/app';
import { RecordScreen } from '../../packages/app/record/RecordScreen';
import { Slot } from '../../packages/app/slot';
import { NotesPanel, TodosPanel } from '../../packages/app/record/NotesPanel';
import { RecordForm } from '../../packages/app/form/RecordForm';
import { ConvertLead } from './ConvertLead';
import { MergeLead } from './MergeLead';
import { RemindersCard } from '../../packages/app/record/RemindersPanel';

type Lead = Record< string, unknown > & { uid: string; name: string };

type Near = { uid: string; name: string; business_name?: string | null };

/** Leads with the same address, and leads at the same company. */
type Alike = { same: Near[]; domain: Near[] };

/** How a lead came in, in words: the stored value is a code, not a sentence. */
const CAME_IN_BY = (): Record< string, string > => ( {
	manual: __( 'Added by hand', 'cliezoo' ),
	form: __( 'A form on the website', 'cliezoo' ),
	booking: __( 'Booking a call', 'cliezoo' ),
	email: __( 'Email', 'cliezoo' ),
	import: __( 'Import', 'cliezoo' ),
	api: __( 'Another app', 'cliezoo' ),
	portal: __( 'The client portal', 'cliezoo' ),
} );

/** What the details form asks for, in the order somebody fills it in. */
const DETAILS = () => [
	{
		title: __( 'Who they are', 'cliezoo' ),
		fields: [ 'name', 'email', 'phone', 'business_name', 'website' ],
	},
	{
		title: __( 'What they are after', 'cliezoo' ),
		fields: [ 'source', 'status', 'rating', 'budget', 'currency' ],
	},
	{
		title: __( 'Ours to chase', 'cliezoo' ),
		fields: [ 'owner', 'lost_reason' ],
	},
	{
		title: __( 'Notes', 'cliezoo' ),
		fields: [ 'notes' ],
		wide: true,
	},
];

const KINDS = () => [
	{ id: 'call', label: __( 'A call', 'cliezoo' ) },
	{ id: 'email', label: __( 'An email', 'cliezoo' ) },
	{ id: 'meeting', label: __( 'A meeting', 'cliezoo' ) },
	{ id: 'note', label: __( 'Something else', 'cliezoo' ) },
];

/**
 * How warm a lead looks, in a word.
 *
 * @param score The score the server worked out.
 */
function warmth( score: number ): {
	tone: 'success' | 'warning' | 'neutral';
	says: string;
} {
	if ( score >= 70 ) {
		return { tone: 'success', says: __( 'Warm', 'cliezoo' ) };
	}
	if ( score >= 40 ) {
		return { tone: 'warning', says: __( 'Worth a call', 'cliezoo' ) };
	}
	return { tone: 'neutral', says: __( 'Cold', 'cliezoo' ) };
}

export default function LeadScreen() {
	const { uid } = useParams( { strict: false } ) as { uid: string };
	const navigate = useNavigate();
	const lead = useRecord< Lead >( '/leads', uid );
	const [ editing, setEditing ] = useState( false );
	const [ converting, setConverting ] = useState( false );
	const [ merging, setMerging ] = useState( false );
	const [ saying, setSaying ] = useState( false );
	const [ kind, setKind ] = useState( 'call' );
	const [ said, setSaid ] = useState( '' );

	const record = lead.data;
	const score = Number( record?.score ?? 0 );
	const how = warmth( score );
	const became = record?.client as { uid: string; label: string } | null;
	const budget = record?.budget as
		{ amount: number; currency: string } | undefined;

	// Somebody else who looks like this one, and the server keeps the two
	// cases apart on purpose: the same address is probably the same person
	// written down twice, while another address at the same company is a
	// colleague and perfectly normal. Offering to merge the second would be
	// offering to lose one of them.
	const alike = useQuery< Alike >( {
		queryKey: [ 'leads', 'duplicates', String( record?.email ?? '' ) ],
		enabled: !! record?.email,
		queryFn: async () =>
			(
				await api.get< Alike >( '/leads/duplicates', {
					email: String( record?.email ?? '' ),
					skip: uid,
				} )
			).data,
	} );
	const twins = alike.data?.same ?? [];
	const colleagues = alike.data?.domain ?? [];

	const spokeTo = async () => {
		try {
			await api.post( `/leads/${ uid }/touched`, { kind, said } );
			toast.success( __( 'Written down.', 'cliezoo' ) );
			setSaying( false );
			setSaid( '' );
			lead.refetch();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	return (
		<>
			<RecordScreen
				type="leads"
				record={ record }
				isPending={ lead.isPending }
				error={ lead.error }
				onRetry={ () => lead.refetch() }
				title={ String( record?.name ?? '' ) }
				avatar={ { name: String( record?.name ?? '' ) } }
				meta={
					record ? (
						<span className="flex flex-wrap items-center gap-2">
							{ !! record.number && (
								<span className="text-xs tabular-nums text-muted">
									{ String( record.number ) }
								</span>
							) }
							{ !! record.status_label && (
								<Badge tone="neutral">
									{ String( record.status_label ) }
								</Badge>
							) }
							<Badge tone={ how.tone }>
								{ sprintf(
									/* translators: 1: a word for how warm the lead is. 2: a score out of 100. */
									__( '%1$s · %2$d', 'cliezoo' ),
									how.says,
									score
								) }
							</Badge>
							{ !! budget && budget.amount > 0 && (
								<span className="text-xs text-muted">
									{ sprintf(
										/* translators: %s: an amount of money. */
										__( 'Budget %s', 'cliezoo' ),
										formatMoney(
											budget.amount,
											budget.currency
										)
									) }
								</span>
							) }
							{ !! record.last_touch_at && (
								<span className="text-xs text-muted">
									{ sprintf(
										/* translators: %s: a date. */
										__( 'Last spoken to %s', 'cliezoo' ),
										formatDate(
											String( record.last_touch_at )
										)
									) }
								</span>
							) }
						</span>
					) : null
				}
				actions={
					<>
						<Slot name="lead.sidebar" variant="chip" />
						{ !! record && ! became && (
							<Button
								icon={
									<Phone size={ 15 } aria-hidden="true" />
								}
								onPress={ () => setSaying( true ) }
							>
								{ __( 'We spoke', 'cliezoo' ) }
							</Button>
						) }
						{ !! record && ! became && (
							<Button
								variant="primary"
								icon={
									<UserCheck size={ 15 } aria-hidden="true" />
								}
								onPress={ () => setConverting( true ) }
							>
								{ __( 'Make them a client', 'cliezoo' ) }
							</Button>
						) }
						{ !! record && (
							<MenuTrigger>
								<Button
									icon={
										<Pencil
											size={ 15 }
											aria-hidden="true"
										/>
									}
								>
									{ __( 'Edit', 'cliezoo' ) }
								</Button>
								<Menu
									onAction={ ( key: unknown ) => {
										if ( 'edit' === String( key ) ) {
											setEditing( true );
										}
										if ( 'merge' === String( key ) ) {
											setMerging( true );
										}
									} }
								>
									<MenuItem id="edit">
										{ __( 'Its details', 'cliezoo' ) }
									</MenuItem>
									<MenuItem id="merge">
										{ __(
											'Fold another lead into this one',
											'cliezoo'
										) }
									</MenuItem>
								</Menu>
							</MenuTrigger>
						) }
					</>
				}
				rail={
					<>
						<NotesPanel type="leads" uid={ uid } />
						<TodosPanel type="leads" uid={ uid } />
						<RemindersCard type="leads" uid={ uid } />
					</>
				}
				overview={
					<div className="flex flex-col gap-4">
						{ !! became && (
							<p className="rounded-lg border border-line bg-surface p-3 text-sm">
								{ __( 'This one said yes.', 'cliezoo' ) }{ ' ' }
								<RouterLink
									to={ `/clients/${ became.uid }` }
									className="text-accent-fg hover:underline"
								>
									{ became.label }
								</RouterLink>
							</p>
						) }

						{ ! became && !! twins.length && (
							<div className="rounded-lg border border-warning bg-surface p-3 text-sm">
								<p className="font-medium">
									{ __(
										'Somebody else here has this address',
										'cliezoo'
									) }
								</p>
								<ul className="mt-1 flex flex-wrap gap-3">
									{ twins.map( ( one ) => (
										<li key={ one.uid }>
											<RouterLink
												to={ `/leads/${ one.uid }` }
												className="text-accent-fg hover:underline"
											>
												{ one.name }
											</RouterLink>
										</li>
									) ) }
								</ul>
								<p className="mt-1 text-xs text-muted">
									{ __(
										'The same person written down twice is one lead. Folding one into the other keeps both sets of notes.',
										'cliezoo'
									) }
								</p>
							</div>
						) }

						{ ! became && !! colleagues.length && (
							<p className="text-sm text-muted">
								{ __(
									'Also here from the same company:',
									'cliezoo'
								) }{ ' ' }
								{ colleagues.map( ( one, at ) => (
									<span key={ one.uid }>
										{ at > 0 ? ', ' : '' }
										<RouterLink
											to={ `/leads/${ one.uid }` }
											className="text-accent-fg hover:underline"
										>
											{ one.name }
										</RouterLink>
									</span>
								) ) }
							</p>
						) }

						<section
							aria-label={ __(
								'What they asked for',
								'cliezoo'
							) }
							className="rounded-lg border border-line bg-surface p-4"
						>
							<h3 className="mb-2 text-sm font-semibold text-fg">
								{ __( 'What they asked for', 'cliezoo' ) }
							</h3>
							<dl className="grid gap-3 sm:grid-cols-2">
								{ [
									[
										__( 'Business', 'cliezoo' ),
										String( record?.business_name ?? '' ),
									],
									[
										__( 'Email', 'cliezoo' ),
										String( record?.email ?? '' ),
									],
									[
										__( 'Phone', 'cliezoo' ),
										String( record?.phone ?? '' ),
									],
									[
										__( 'Where from', 'cliezoo' ),
										String( record?.source_label ?? '' ),
									],
									[
										__( 'Came in by', 'cliezoo' ),
										CAME_IN_BY()[
											String( record?.origin ?? '' )
										] ?? String( record?.origin ?? '' ),
									],
									[
										__( 'Why it was lost', 'cliezoo' ),
										String( record?.lost_reason ?? '' ),
									],
								]
									.filter( ( [ , value ] ) => '' !== value )
									.map( ( [ label, value ] ) => (
										<div key={ label }>
											<dt className="text-xs text-muted">
												{ label }
											</dt>
											<dd className="text-sm text-fg">
												{ value }
											</dd>
										</div>
									) ) }
							</dl>
							{ !! record?.notes && (
								<p className="mt-3 whitespace-pre-line text-sm text-fg">
									{ String( record.notes ) }
								</p>
							) }
						</section>
					</div>
				}
				overviewLabel={ __( 'The lead', 'cliezoo' ) }
				tabSlot="lead.tabs"
			/>

			<Dialog
				isOpen={ saying }
				onOpenChange={ ( open: boolean ) =>
					! open && setSaying( false )
				}
				title={ __( 'We spoke to them', 'cliezoo' ) }
				description={ __(
					'So they stop showing up as somebody nobody has rung back.',
					'cliezoo'
				) }
				footer={ ( close ) => (
					<>
						<Button variant="ghost" onPress={ close }>
							{ __( 'Cancel', 'cliezoo' ) }
						</Button>
						<Button
							variant="primary"
							icon={
								<CheckCircle2 size={ 15 } aria-hidden="true" />
							}
							onPress={ spokeTo }
						>
							{ __( 'Write it down', 'cliezoo' ) }
						</Button>
					</>
				) }
			>
				<div className="flex flex-col gap-3">
					<Select
						label={ __( 'What it was', 'cliezoo' ) }
						selectedKey={ kind }
						onSelectionChange={ ( key ) =>
							setKind( String( key ) )
						}
						items={ KINDS() }
					/>
					<TextField
						label={ __( 'What came of it', 'cliezoo' ) }
						value={ said }
						onChange={ setSaid }
						description={ __(
							'Kept with the lead, so the next person to ring knows where it was left.',
							'cliezoo'
						) }
					/>
				</div>
			</Dialog>

			<Dialog
				isOpen={ editing }
				onOpenChange={ ( open: boolean ) =>
					! open && setEditing( false )
				}
				size="lg"
				title={ __( 'The lead’s details', 'cliezoo' ) }
			>
				{ !! record && (
					<RecordForm
						entity="leads"
						record={ record }
						sections={ DETAILS() }
						rich={ [ 'notes' ] }
						submitLabel={ __( 'Save', 'cliezoo' ) }
						onCancel={ () => setEditing( false ) }
						onSaved={ () => {
							toast.success( __( 'Saved.', 'cliezoo' ) );
							setEditing( false );
							lead.refetch();
						} }
					/>
				) }
			</Dialog>

			{ !! record && (
				<ConvertLead
					lead={ record }
					isOpen={ converting }
					onClose={ () => setConverting( false ) }
					onConverted={ ( client: string ) => {
						lead.refetch();
						if ( client && getBoot().user ) {
							navigate( {
								to: '/clients/$uid',
								params: { uid: client },
							} );
						}
					} }
				/>
			) }

			{ !! record && (
				<MergeLead
					keep={ record }
					isOpen={ merging }
					onClose={ () => setMerging( false ) }
					onMerged={ () => lead.refetch() }
				/>
			) }
		</>
	);
}
