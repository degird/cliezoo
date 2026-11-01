/**
 * One client: who they are, how they are doing, and everything attached.
 */
import { Suspense, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Link as RouterLink,
	useNavigate,
	useParams,
} from '@tanstack/react-router';
import {
	Combine,
	Download,
	Pencil,
	Plus,
	Share2,
	Trash2,
} from 'lucide-react';
import {
	api,
	formatFullDate,
	formatNumber,
	getBoot,
	safeHref,
	useQuery,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import {
	Badge,
	Button,
	Dialog,
	Menu,
	MenuItem,
	MenuTrigger,
	toast,
} from '@cliezoo/ui';
import { RecordScreen } from '../../packages/app/record/RecordScreen';
import { NotesPanel, TodosPanel } from '../../packages/app/record/NotesPanel';
import { RecordForm } from '../../packages/app/form/RecordForm';
import { FieldInput } from '../../packages/app/form/FieldInput';
import { ShareDialog } from '../../packages/app';
import { HealthPanel } from './HealthPanel';
import { KeyFigures } from './KeyFigures';
import { LocationsPanel } from './LocationsPanel';
import { TeamPanel } from './TeamPanel';
import { DeleteClientDialog } from './DeleteClientDialog';
import { FavouriteButton } from '../../packages/app/FavouriteButton';
import { WatchButton } from '../../packages/app/WatchButton';
import { LocalTime } from '../../packages/app/LocalTime';

type Client = Record< string, unknown > & { uid: string; name: string };

/** Everything worth editing about a client, grouped the way people think. */
export const CLIENT_SECTIONS = () => [
	{
		title: __( 'Basics', 'cliezoo' ),
		fields: [
			'name',
			'legal_name',
			'email',
			'phone',
			'website',
			'initials',
			'logo_file',
			'parent',
		],
	},
	{
		title: __( 'How you classify them', 'cliezoo' ),
		fields: [
			'type',
			'sub_type',
			'industry',
			'territory',
			'stage',
			'status',
			'tags',
		],
	},
	{
		title: __( 'The business', 'cliezoo' ),
		fields: [ 'head_count', 'annual_revenue', 'tax_number', 'owner' ],
	},
	{
		title: __( 'Time and language', 'cliezoo' ),
		fields: [ 'timezone', 'language' ],
	},
	{
		title: __( 'Anything else', 'cliezoo' ),
		wide: true,
		fields: [ 'description' ],
	},
];

export default function ClientScreen() {
	const { uid } = useParams( { strict: false } ) as { uid: string };
	const navigate = useNavigate();
	const queries = useQueryClient();
	const [ editing, setEditing ] = useState( false );
	const [ sharing, setSharing ] = useState( false );
	const [ merging, setMerging ] = useState( false );
	// Making something for this client from its page, and deleting it.
	const [ making, setMaking ] = useState< '' | 'contact' >( '' );
	const staff = ! getBoot().user?.client;
	const [ deleting, setDeleting ] = useState( false );

	const client = useQuery( {
		queryKey: [ 'clients', uid ],
		queryFn: async () =>
			( await api.get< Client >( `/clients/${ uid }` ) ).data,
	} );
	const record = client.data;
	const parent = record?.parent as
		{ uid: string; label: string } | null | undefined;
	// The clients filed under this one. A group keeps the brand; the
	// companies under it keep the work.
	const under = useQuery( {
		queryKey: [ 'clients', 'under', uid ],
		queryFn: async () =>
			(
				await api.get< Array< { uid: string } > >( '/clients', {
					filter: JSON.stringify( {
						field: 'parent',
						op: 'eq',
						value: uid,
					} ),
					limit: 100,
					fields: 'uid',
				} )
			).data,
	} );
	const howMany = under.data?.length ?? 0;

	const download = async () => {
		try {
			const data = await api.get< Record< string, unknown > >(
				`/clients/${ uid }/export`
			);
			const blob = new Blob(
				[ JSON.stringify( data.data, null, '\t' ) ],
				{
					type: 'application/json',
				}
			);
			const link = document.createElement( 'a' );
			const url = URL.createObjectURL( blob );
			link.href = url;
			link.download = `${ String( record?.number ?? 'client' ) }.json`;
			document.body.append( link );
			link.click();
			link.remove();
			window.setTimeout( () => URL.revokeObjectURL( url ), 0 );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	return (
		<>
			<RecordScreen
				type="clients"
				record={ record }
				isPending={ client.isPending }
				error={ client.isError ? client.error : undefined }
				onRetry={ () => client.refetch() }
				title={ String( record?.name ?? '' ) }
				avatar={ {
					name: String( record?.name ?? '' ),
					src: ( record?.logo_url as string | null ) ?? undefined,
				} }
				meta={
					record ? (
						<span className="flex flex-wrap items-center gap-x-2 gap-y-1">
							{ !! record.number && (
								<span className="font-mono text-xs">
									{ String( record.number ) }
								</span>
							) }
							{ /* The group it belongs to, and — when it is the
							     group — how many belong to it. */ }
							{ !! parent && (
								<RouterLink
									to={ `/clients/${ parent.uid }` }
									className="text-accent-fg hover:underline"
								>
									{ sprintf(
										/* translators: %s: the company this one is part of. */
										__( 'Part of %s', 'cliezoo' ),
										parent.label
									) }
								</RouterLink>
							) }
							{ howMany > 0 && (
								<span>
									{ sprintf(
										/* translators: %d: how many clients are part of this one. */
										_n(
											'%d client',
											'%d clients',
											howMany,
											'cliezoo'
										),
										howMany
									) }
								</span>
							) }
							{ !! record.email && (
								<span>{ String( record.email ) }</span>
							) }
							{ !! record.website &&
								( safeHref( record.website ) ? (
									<a
										href={ safeHref( record.website ) }
										target="_blank"
										rel="noreferrer noopener"
										className="text-accent-fg hover:underline"
									>
										{ String( record.website ).replace(
											/^https?:\/\//,
											''
										) }
									</a>
								) : (
									// Kept on screen, but not clickable: an
									// address the browser should not follow.
									<span>{ String( record.website ) }</span>
								) ) }
							{ !! record.type_label && (
								<Badge tone="neutral">
									{ String( record.type_label ) }
								</Badge>
							) }
							{ !! record.industry_label && (
								<span>{ String( record.industry_label ) }</span>
							) }
							{ !! record.timezone && (
								<LocalTime zone={ String( record.timezone ) } />
							) }
						</span>
					) : null
				}
				actions={
					<>
						{ !! record && (
							<WatchButton
								type="clients"
								uid={ uid }
								label={ String( record.name ) }
							/>
						) }
						{ !! record && (
							<FavouriteButton
								type="clients"
								uid={ uid }
								label={ String( record.name ) }
							/>
						) }
						<Button
							icon={ <Plus size={ 15 } aria-hidden="true" /> }
							onPress={ () => setMaking( 'contact' ) }
						>
							{ __( 'New contact', 'cliezoo' ) }
						</Button>
						<Button
							icon={ <Pencil size={ 15 } aria-hidden="true" /> }
							onPress={ () => setEditing( true ) }
						>
							{ __( 'Edit', 'cliezoo' ) }
						</Button>
						<Button
							icon={ <Share2 size={ 15 } aria-hidden="true" /> }
							onPress={ () => setSharing( true ) }
						>
							{ __( 'Share', 'cliezoo' ) }
						</Button>
						<MenuTrigger>
							<Button
								aria-label={ __(
									'More actions for this client',
									'cliezoo'
								) }
							>
								⋯
							</Button>
							<Menu
								onAction={ ( key ) => {
									if ( 'merge' === key ) {
										setMerging( true );
									} else if ( 'export' === key ) {
										download();
									} else if ( 'delete' === key ) {
										setDeleting( true );
									}
								} }
							>
								<MenuItem
									id="merge"
									icon={ <Combine size={ 16 } /> }
									description={ __(
										'Move everything into another client.',
										'cliezoo'
									) }
								>
									{ __(
										'Merge into another client',
										'cliezoo'
									) }
								</MenuItem>
								<MenuItem
									id="export"
									icon={ <Download size={ 16 } /> }
								>
									{ __( 'Export everything', 'cliezoo' ) }
								</MenuItem>
								<MenuItem
									id="delete"
									danger
									icon={ <Trash2 size={ 16 } /> }
								>
									{ __( 'Delete…', 'cliezoo' ) }
								</MenuItem>
							</Menu>
						</MenuTrigger>
					</>
				}
				rail={
					record ? (
						<>
							<HealthPanel uid={ uid } />
							{ staff && <KeyFigures uid={ uid } /> }
							<TeamPanel
								uid={ uid }
								team={
									( record.team_people as Array< {
										uid: string;
										label: string;
										title?: string;
									} > ) ?? []
								}
								canEdit={ staff }
							/>
							<NotesPanel type="clients" uid={ uid } />
							<TodosPanel type="clients" uid={ uid } />
							<LocationsPanel client={ record } />
						</>
					) : null
				}
				overview={ record ? <Overview record={ record } /> : null }
			/>

			<Dialog
				isOpen={ editing }
				onOpenChange={ setEditing }
				size="xl"
				title={ __( 'Edit client', 'cliezoo' ) }
			>
				<RecordForm
					entity="clients"
					record={ record }
					sections={ CLIENT_SECTIONS() }
					onCancel={ () => setEditing( false ) }
					onSaved={ async () => {
						setEditing( false );
						await queries.invalidateQueries( {
							queryKey: [ 'clients', uid ],
						} );
						toast.success( __( 'Client saved.', 'cliezoo' ) );
					} }
				/>
			</Dialog>

			{ 'contact' === making && !! record && (
				<Dialog
					isOpen
					size="lg"
					onOpenChange={ ( open ) => ! open && setMaking( '' ) }
					title={ __( 'New contact', 'cliezoo' ) }
				>
					<RecordForm
						entity="contacts"
						fixed={ { client: uid } }
						sections={ [
							{
								fields: [ 'name', 'email', 'title', 'phone' ],
							},
						] }
						custom={ false }
						onCancel={ () => setMaking( '' ) }
						onSaved={ ( saved ) => {
							setMaking( '' );
							navigate( {
								to: `/contacts/${ String( saved.uid ) }`,
							} );
						} }
					/>
				</Dialog>
			) }

			{ deleting && !! record && (
				<DeleteClientDialog
					uid={ uid }
					name={ String( record.name ) }
					onClose={ () => setDeleting( false ) }
					onDeleted={ () => navigate( { to: '/clients' } ) }
				/>
			) }

			{ sharing && (
				<Suspense fallback={ null }>
					<ShareDialog
						type="clients"
						uid={ uid }
						isOpen={ sharing }
						onOpenChange={ setSharing }
					/>
				</Suspense>
			) }

			<MergeDialog
				isOpen={ merging }
				onOpenChange={ setMerging }
				client={ record }
				onMerged={ ( into ) =>
					navigate( { to: `/clients/${ into }` } )
				}
			/>
		</>
	);
}

/**
 * The first tab: the details that do not belong in a panel.
 * @param root0
 * @param root0.record
 */
function Overview( { record }: { record: Client } ) {
	const rows: Array< [ string, string ] > = [
		[
			__( 'Industry', 'cliezoo' ),
			String( record.industry_label ?? record.industry ?? '' ),
		],
		[
			__( 'Lifecycle stage', 'cliezoo' ),
			String( record.stage_label ?? record.stage ?? '' ),
		],
		[
			__( 'Territory', 'cliezoo' ),
			String( record.territory_label ?? record.territory ?? '' ),
		],
		[
			__( 'Account owner', 'cliezoo' ),
			String( ( record.owner as { name?: string } | null )?.name ?? '' ),
		],
		[
			__( 'Head count', 'cliezoo' ),
			record.head_count
				? formatNumber( Number( record.head_count ) )
				: '',
		],
		[
			__( 'VAT or tax number', 'cliezoo' ),
			String( record.tax_number ?? '' ),
		],
		[ __( 'Time zone', 'cliezoo' ), String( record.timezone ?? '' ) ],
		[
			__( 'Added', 'cliezoo' ),
			record.created_at
				? [
						formatFullDate( String( record.created_at ) ),
						( record.created_by as { label?: string } | null )
							?.label,
					]
						.filter( Boolean )
						.join( ' · ' )
				: '',
		],
	].filter( ( pair ): pair is [ string, string ] => '' !== pair[ 1 ] );

	return (
		<div className="space-y-4">
			<section className="rounded-lg border border-line bg-raised p-5">
				<h2 className="text-sm font-semibold text-fg">
					{ __( 'Details', 'cliezoo' ) }
				</h2>
				<dl className="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2">
					{ rows.map( ( [ label, value ] ) => (
						<div key={ label }>
							<dt className="text-xs text-subtle">{ label }</dt>
							<dd className="text-sm text-fg">{ value }</dd>
						</div>
					) ) }
				</dl>
			</section>
			{ !! record.description && (
				<section className="rounded-lg border border-line bg-raised p-5">
					<h2 className="text-sm font-semibold text-fg">
						{ __( 'About', 'cliezoo' ) }
					</h2>
					<p className="mt-2 whitespace-pre-line text-sm text-muted">
						{ String( record.description ) }
					</p>
				</section>
			) }
		</div>
	);
}

/**
 * Choosing the client this one is merged into.
 * @param root0
 * @param root0.isOpen
 * @param root0.onOpenChange
 * @param root0.client
 * @param root0.onMerged
 */
function MergeDialog( {
	isOpen,
	onOpenChange,
	client,
	onMerged,
}: {
	isOpen: boolean;
	onOpenChange: ( open: boolean ) => void;
	client?: Client;
	onMerged: ( into: string ) => void;
} ) {
	const queries = useQueryClient();
	const [ into, setInto ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const merge = async () => {
		if ( '' === into || ! client || busy ) {
			return;
		}
		setBusy( true );
		try {
			await api.post( `/clients/${ client.uid }/merge`, { into } );
			await queries.invalidateQueries( { queryKey: [ 'clients' ] } );
			toast.success( __( 'Merged.', 'cliezoo' ) );
			onOpenChange( false );
			onMerged( into );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Dialog
			isOpen={ isOpen }
			onOpenChange={ onOpenChange }
			role="alertdialog"
			title={ __( 'Merge this client into another', 'cliezoo' ) }
			description={
				client
					? sprintf(
							/* translators: %s: client name. */
							__(
								'Everything attached to %s moves to the client you choose. It is then put in the trash, so this can be undone.',
								'cliezoo'
							),
							client.name
						)
					: undefined
			}
			footer={ ( close ) => (
				<>
					<Button variant="ghost" onPress={ close }>
						{ __( 'Cancel', 'cliezoo' ) }
					</Button>
					<Button
						variant="danger"
						isPending={ busy }
						isDisabled={ '' === into || into === client?.uid }
						onPress={ merge }
					>
						{ __( 'Merge', 'cliezoo' ) }
					</Button>
				</>
			) }
		>
			{ /*
			 * The searchable reference picker, not a plain list of the
			 * first hundred: past a hundred clients the one somebody
			 * means was simply absent, with nothing saying why.
			 */ }
			<FieldInput
				field={ {
					key: 'into',
					label: __( 'Keep this client', 'cliezoo' ),
					type: 'ref',
					ref: 'clients',
				} }
				value={ into }
				onChange={ ( value ) =>
					setInto( value ? String( value ) : '' )
				}
				error={
					into && into === client?.uid
						? __(
								'That is this client. Choose the one it should join.',
								'cliezoo'
							)
						: undefined
				}
			/>
		</Dialog>
	);
}
