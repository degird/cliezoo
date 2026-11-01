/**
 * Who can open this, and why.
 *
 * The dialog answers the question people actually have — "can the client
 * see this?" — before it offers any control, and it says where each
 * person's access comes from, because inherited access is the part that
 * surprises people.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Check, Copy, Eye, Trash2, UserPlus } from 'lucide-react';
import {
	api,
	formatDate,
	useQuery,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import {
	Alert,
	Badge,
	Button,
	Dialog,
	ErrorState,
	IconButton,
	Link,
	Select,
	Skeleton,
	TextField,
	toast,
} from '@cliezoo/ui';
import { appHref } from '../router';
import { copyText } from '../copy';

interface Principal {
	type: 'user' | 'client' | 'team' | string;
	uid: string;
	label: string;
}

interface Member {
	principal: Principal;
	role: string;
	/** Where it comes from: added here, or inherited from the project. */
	source: 'direct' | 'project' | string;
	/** A person's address, so the list is somewhere to reach them from. */
	email?: string | null;
	/** Whether this is somebody from your side or from the client's. */
	kind?: 'staff' | 'client' | string;
	/** The contact behind a client's person, when there is one. */
	contact?: string | null;
	/** Whether their invitation is still waiting to be accepted. */
	pending?: boolean;
}

/**
 * A public link as the server describes it afterwards. There is no `url`:
 * the token is hashed on the way in, so the address only exists at the
 * moment the link is made or replaced.
 */
interface PublicLinkRow {
	uid: string;
	status: string;
	view_count: number;
	has_passcode: boolean;
	created_at?: string | null;
	expires_at?: string | null;
	last_viewed_at?: string | null;
}

interface Access {
	mode: 'inherit' | 'private' | 'clients' | 'internal' | 'public' | string;
	default_role: string;
	shareable: boolean;
	can_share: boolean;
	members: Member[];
	explanation: string[];
}

const MODES = (): Array< { id: string; label: string; help: string } > => [
	{
		id: 'inherit',
		label: __( 'Follow the project', 'cliezoo' ),
		help: __( 'Whoever can see the project can see this.', 'cliezoo' ),
	},
	{
		id: 'private',
		label: __( 'Only the people listed', 'cliezoo' ),
		help: __( 'Nobody else, however senior.', 'cliezoo' ),
	},
	{
		id: 'internal',
		label: __( 'The team', 'cliezoo' ),
		help: __( 'Everyone whose role covers this area.', 'cliezoo' ),
	},
	{
		id: 'clients',
		label: __( 'The team and the client', 'cliezoo' ),
		help: __( 'Including the client company this belongs to.', 'cliezoo' ),
	},
];

const ROLES = (): Array< { id: string; label: string } > => [
	{ id: 'viewer', label: __( 'Can view', 'cliezoo' ) },
	{ id: 'downloader', label: __( 'Can download', 'cliezoo' ) },
	{ id: 'commenter', label: __( 'Can comment', 'cliezoo' ) },
	{ id: 'uploader', label: __( 'Can upload', 'cliezoo' ) },
	{ id: 'creator', label: __( 'Can add things', 'cliezoo' ) },
	{ id: 'manager', label: __( 'Can manage', 'cliezoo' ) },
	{ id: 'admin', label: __( 'Full access', 'cliezoo' ) },
];

/**
 * Whether moving from one general-access setting to another lets more
 * people in. Only widening is worth interrupting someone over.
 *
 * @param from Current mode.
 * @param to   Chosen mode.
 */
function widens( from: string, to: string ): boolean {
	const reach = [ 'private', 'inherit', 'internal', 'clients', 'public' ];
	const before = reach.indexOf( from );
	const after = reach.indexOf( to );
	return before >= 0 && after > before;
}

function roleLabel( role: string ): string {
	return ROLES().find( ( item ) => item.id === role )?.label ?? role;
}

/**
 * What one person would actually be able to do with this record.
 *
 * @param can          What the server said they can do.
 * @param can.can_view
 * @param can.can_edit
 */
function whatTheySee( can: { can_view: boolean; can_edit: boolean } ): string {
	if ( can.can_edit ) {
		return __( 'Can open it and make changes.', 'cliezoo' );
	}
	return can.can_view
		? __( 'Can open it, but not change it.', 'cliezoo' )
		: __( 'Cannot open it.', 'cliezoo' );
}

export default function ShareDialog( {
	type,
	uid,
	isOpen,
	onOpenChange,
}: {
	type: string;
	uid: string;
	isOpen: boolean;
	onOpenChange: ( open: boolean ) => void;
} ) {
	const client = useQueryClient();
	const [ busy, setBusy ] = useState( false );
	const [ role, setRole ] = useState( 'viewer' );
	const [ who, setWho ] = useState( '' );
	const [ viewAs, setViewAs ] = useState( '' );
	const [ widening, setWidening ] = useState< string | null >( null );

	const access = useQuery( {
		queryKey: [ 'access', type, uid ],
		enabled: isOpen,
		queryFn: async () =>
			( await api.get< Access >( `/access/${ type }/${ uid }` ) ).data,
	} );
	const people = useQuery( {
		queryKey: [ 'members', 'list', { picker: true } ],
		enabled: isOpen,
		staleTime: 60_000,
		queryFn: async () =>
			(
				await api.get< Array< { uid: string; name: string } > >(
					'/members',
					{ limit: 200, fields: 'uid,name' }
				)
			).data,
	} );
	const canSee = useQuery( {
		queryKey: [ 'access', type, uid, 'check', viewAs ],
		enabled: isOpen && !! viewAs,
		queryFn: async () =>
			(
				await api.get< { can_view: boolean; can_edit: boolean } >(
					`/access/${ type }/${ uid }/check`,
					{ member: viewAs }
				)
			).data,
	} );

	const refresh = () =>
		client.invalidateQueries( { queryKey: [ 'access', type, uid ] } );

	const run = async ( fn: () => Promise< unknown >, message: string ) => {
		setBusy( true );
		try {
			await fn();
			await refresh();
			toast.success( message );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		} finally {
			setBusy( false );
		}
	};

	const data = access.data;

	const setMode = ( mode: string ) =>
		run(
			() => api.post( `/access/${ type }/${ uid }/mode`, { mode } ),
			__( 'Access changed.', 'cliezoo' )
		);

	return (
		<Dialog
			isOpen={ isOpen }
			onOpenChange={ onOpenChange }
			size="lg"
			title={ __( 'Who can open this', 'cliezoo' ) }
		>
			{ access.isPending && <Skeleton className="h-48" /> }
			{ access.isError && (
				<ErrorState
					error={ access.error }
					onRetry={ () => access.refetch() }
				/>
			) }
			{ data && (
				<div className="space-y-5">
					<section
						aria-labelledby="share-why"
						className="rounded-lg bg-sunken p-3"
					>
						<h3
							id="share-why"
							className="text-xs font-semibold uppercase tracking-wider text-subtle"
						>
							{ __( 'Why', 'cliezoo' ) }
						</h3>
						<ul className="mt-1.5 space-y-1 text-sm text-muted">
							{ data.explanation.map( ( line ) => (
								<li key={ line }>{ line }</li>
							) ) }
						</ul>
					</section>

					{ ! data.can_share && (
						<Alert tone="info">
							{ __(
								'You can see who has access, but not change it.',
								'cliezoo'
							) }
						</Alert>
					) }

					{ data.shareable && (
						<Select
							label={ __( 'General access', 'cliezoo' ) }
							description={
								MODES().find( ( m ) => m.id === data.mode )
									?.help
							}
							isDisabled={ ! data.can_share || busy }
							items={ MODES().map( ( mode ) => ( {
								id: mode.id,
								label: mode.label,
							} ) ) }
							selectedKey={ data.mode }
							onSelectionChange={ ( key ) => {
								const mode = String( key );
								if ( widens( data.mode, mode ) ) {
									setWidening( mode );
									return;
								}
								setMode( mode );
							} }
						/>
					) }

					<section aria-labelledby="share-people">
						<h3
							id="share-people"
							className="mb-2 text-sm font-semibold text-fg"
						>
							{ __( 'People with access', 'cliezoo' ) }
						</h3>
						{ ! data.members.length && (
							<p className="text-sm text-muted">
								{ __(
									'Nobody has been added to this one in particular.',
									'cliezoo'
								) }
							</p>
						) }
						<ul className="divide-y divide-line">
							{ data.members.map( ( member ) => (
								<li
									key={ `${ member.principal.type }:${ member.principal.uid }` }
									className="flex flex-wrap items-center gap-2 py-2"
								>
									<span className="min-w-0 flex-1">
										<span className="block truncate text-sm text-fg">
											{ member.principal.label }
										</span>
										{ /* Somewhere to reach them from,
										     rather than a name and a shrug. */ }
										{ !! member.email && (
											<a
												className="block truncate text-xs text-muted hover:text-accent-fg hover:underline"
												href={ `mailto:${ member.email }` }
											>
												{ member.email }
											</a>
										) }
									</span>
									{ 'client' === member.kind && (
										<Badge tone="neutral">
											{ __( 'Client', 'cliezoo' ) }
										</Badge>
									) }
									{ 'project' === member.source && (
										<Badge tone="neutral">
											{ __(
												'From the project',
												'cliezoo'
											) }
										</Badge>
									) }
									<span className="text-xs text-muted">
										{ roleLabel( member.role ) }
									</span>
									{ !! member.contact && (
										<PersonActions
											contact={ member.contact }
											name={ member.principal.label }
											pending={ !! member.pending }
										/>
									) }
									{ data.can_share &&
										'direct' === member.source && (
											<IconButton
												size="sm"
												label={ __(
													'Remove access',
													'cliezoo'
												) }
												icon={
													<Trash2
														size={ 14 }
														aria-hidden="true"
													/>
												}
												isDisabled={ busy }
												onPress={ () =>
													run(
														() =>
															api.delete(
																`/access/${ type }/${ uid }/members`,
																{
																	data: {
																		principal_type:
																			member
																				.principal
																				.type,
																		principal:
																			member
																				.principal
																				.uid,
																	},
																}
															),
														__(
															'Access removed.',
															'cliezoo'
														)
													)
												}
											/>
										) }
								</li>
							) ) }
						</ul>
					</section>

					{ data.can_share && (
						<section
							aria-labelledby="share-add"
							className="space-y-2"
						>
							<h3
								id="share-add"
								className="text-sm font-semibold text-fg"
							>
								{ __( 'Add someone', 'cliezoo' ) }
							</h3>
							<div className="flex flex-wrap items-end gap-2">
								<Select
									label={ __( 'Person', 'cliezoo' ) }
									className="min-w-48 flex-1"
									description={
										( people.data?.length ?? 0 ) >= 200
											? __(
													'The first 200 people, by name.',
													'cliezoo'
												)
											: undefined
									}
									items={ ( people.data ?? [] ).map(
										( person ) => ( {
											id: person.uid,
											label: person.name,
										} )
									) }
									selectedKey={ who }
									onSelectionChange={ ( key ) =>
										setWho( String( key ) )
									}
								/>
								<Select
									label={ __( 'Can', 'cliezoo' ) }
									className="w-44"
									items={ ROLES() }
									selectedKey={ role }
									onSelectionChange={ ( key ) =>
										setRole( String( key ) )
									}
								/>
								<Button
									variant="secondary"
									isPending={ busy }
									isDisabled={ ! who }
									icon={
										<UserPlus
											size={ 14 }
											aria-hidden="true"
										/>
									}
									onPress={ () =>
										run(
											async () => {
												await api.post(
													`/access/${ type }/${ uid }/members`,
													{
														principal_type: 'user',
														principal: who,
														role,
													}
												);
												setWho( '' );
											},
											__( 'Added.', 'cliezoo' )
										)
									}
								>
									{ __( 'Add', 'cliezoo' ) }
								</Button>
							</div>
						</section>
					) }

					<section
						aria-labelledby="share-check"
						className="space-y-2"
					>
						<h3
							id="share-check"
							className="text-sm font-semibold text-fg"
						>
							{ __( 'Check what someone sees', 'cliezoo' ) }
						</h3>
						<div className="flex flex-wrap items-end gap-2">
							<Select
								label={ __( 'Person', 'cliezoo' ) }
								className="min-w-48 flex-1"
								items={ ( people.data ?? [] ).map(
									( person ) => ( {
										id: person.uid,
										label: person.name,
									} )
								) }
								selectedKey={ viewAs }
								onSelectionChange={ ( key ) =>
									setViewAs( String( key ) )
								}
							/>
							{ !! viewAs && canSee.data && (
								<p className="flex items-center gap-1.5 pb-2 text-sm text-muted">
									{ canSee.data.can_view ? (
										<Check
											size={ 14 }
											className="text-success"
											aria-hidden="true"
										/>
									) : (
										<Eye
											size={ 14 }
											className="text-subtle"
											aria-hidden="true"
										/>
									) }
									{ whatTheySee( canSee.data ) }
								</p>
							) }
						</div>
					</section>

					{ data.can_share && (
						<PublicLink type={ type } uid={ uid } />
					) }
				</div>
			) }
			<Dialog
				isOpen={ null !== widening }
				onOpenChange={ ( open ) => ! open && setWidening( null ) }
				role="alertdialog"
				size="sm"
				title={ __( 'Let more people in?', 'cliezoo' ) }
				description={ MODES().find( ( m ) => m.id === widening )?.help }
				footer={ ( close ) => (
					<>
						<Button variant="ghost" onPress={ close }>
							{ __( 'Cancel', 'cliezoo' ) }
						</Button>
						<Button
							variant="primary"
							onPress={ () => {
								if ( widening ) {
									setMode( widening );
								}
								setWidening( null );
							} }
						>
							{ __( 'Change access', 'cliezoo' ) }
						</Button>
					</>
				) }
			>
				<p className="text-sm text-muted">
					{ __(
						'Anyone this covers will be able to open it from now on, including anything already attached to it.',
						'cliezoo'
					) }
				</p>
			</Dialog>
		</Dialog>
	);
}

const LASTS = (): Array< { id: string; label: string } > => [
	{ id: '7', label: __( 'A week', 'cliezoo' ) },
	{ id: '30', label: __( 'A month', 'cliezoo' ) },
	{ id: '0', label: __( 'Until I take it back', 'cliezoo' ) },
];

/**
 * When a link was opened, in words rather than a count on its own.
 *
 * @param row The link.
 */
function opened( row: PublicLinkRow ): string {
	if ( ! row.view_count ) {
		return __( 'Not opened yet', 'cliezoo' );
	}
	const times = sprintf(
		/* translators: %d: number of times a link was opened. */
		_n( 'Opened %d time', 'Opened %d times', row.view_count, 'cliezoo' ),
		row.view_count
	);
	if ( ! row.last_viewed_at ) {
		return times;
	}
	return sprintf(
		/* translators: 1: how many times it was opened, 2: date. */
		__( '%1$s, last on %2$s', 'cliezoo' ),
		times,
		formatDate( row.last_viewed_at )
	);
}

/**
 * Whether the dialog may say that nobody outside can open this record.
 *
 * It is the one sentence here that is a promise rather than a report, so
 * it may only be made from an answer that actually arrived: a list that
 * failed to load is not a list of none.
 *
 * @param state           Where the links stand.
 * @param state.live      How many links are live.
 * @param state.isPending Whether the list is still on its way.
 * @param state.isError   Whether the list failed to load.
 * @param state.made      Whether a link was just made in this dialog.
 */
export function saysNobodyCanOpen( state: {
	live: number;
	isPending: boolean;
	isError: boolean;
	made: boolean;
} ): boolean {
	return (
		0 === state.live && ! state.isPending && ! state.isError && ! state.made
	);
}

/**
 * A link for somebody with no account — a client's printer, a lawyer, a
 * colleague at another firm.
 *
 * It is the platform's own public link: it can expire, it can carry a
 * passcode, it counts how often it was opened, and it can be taken back.
 * None of which is worth anything if there is no way to make one, which
 * there was not.
 *
 * The address itself is shown once. The server hashes the token on the way
 * in and cannot say it again, so the dialog says so plainly rather than
 * leaving a blank space where the link should be.
 *
 * @param root0      Props.
 * @param root0.type Entity name.
 * @param root0.uid  Record uid.
 */
function PublicLink( { type, uid }: { type: string; uid: string } ) {
	const client = useQueryClient();
	const [ busy, setBusy ] = useState( false );
	const [ made, setMade ] = useState< string | null >( null );
	const [ lasts, setLasts ] = useState( '30' );
	const [ passcode, setPasscode ] = useState( '' );

	const links = useQuery( {
		queryKey: [ 'links', type, uid ],
		queryFn: async () =>
			(
				await api.get< PublicLinkRow[] >( '/links', {
					object_type: type,
					uid,
				} )
			).data,
	} );

	const act = async (
		work: () => Promise< { url?: string } | void >,
		said: string
	) => {
		setBusy( true );
		try {
			const result = await work();
			if ( result?.url ) {
				setMade( result.url );
			}
			await client.invalidateQueries( {
				queryKey: [ 'links', type, uid ],
			} );
			toast.success( said );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		} finally {
			setBusy( false );
		}
	};

	const live = ( links.data ?? [] ).filter(
		( row ) => 'active' === row.status
	);

	const until = (): string | null => {
		const days = Number( lasts );
		if ( ! days ) {
			return null;
		}
		return new Date( Date.now() + days * 86_400_000 ).toISOString();
	};

	return (
		<section aria-labelledby="share-link" className="space-y-2">
			<h3
				id="share-link"
				className="text-xs font-semibold uppercase tracking-wider text-subtle"
			>
				{ __( 'A link for somebody with no account', 'cliezoo' ) }
			</h3>

			{ links.isError && (
				<ErrorState
					error={ links.error }
					onRetry={ () => links.refetch() }
				/>
			) }

			{ made && (
				<div className="space-y-1.5 rounded-lg border border-accent bg-sunken p-2">
					<div className="flex flex-wrap items-center gap-2">
						<code className="min-w-0 flex-1 truncate text-xs">
							{ made }
						</code>
						<Button
							size="sm"
							icon={ <Copy size={ 14 } aria-hidden="true" /> }
							onPress={ () =>
								// This address is shown once and kept
								// nowhere it can be read back, so a copy
								// that silently failed loses it for good.
								copyText( made, __( 'Copied.', 'cliezoo' ) )
							}
						>
							{ __( 'Copy', 'cliezoo' ) }
						</Button>
					</div>
					<p className="text-xs text-muted">
						{ __(
							'Copy it now. It is not kept anywhere you can read it back — if you lose it, replace the link.',
							'cliezoo'
						) }
					</p>
				</div>
			) }

			{ saysNobodyCanOpen( {
				live: live.length,
				isPending: links.isPending,
				isError: links.isError,
				made: !! made,
			} ) && (
				<p className="text-sm text-muted">
					{ __(
						'Nobody outside can open this. A link lets them, and you can take it back.',
						'cliezoo'
					) }
				</p>
			) }

			{ live.map( ( row ) => (
				<div
					key={ row.uid }
					className="flex flex-wrap items-center gap-2 rounded-lg border border-line p-2 text-sm"
				>
					<div className="min-w-0 flex-1">
						<p className="text-fg">
							{ row.created_at
								? sprintf(
										/* translators: %s: date the link was made. */
										__( 'Made %s', 'cliezoo' ),
										formatDate( row.created_at )
									)
								: __( 'A link is live', 'cliezoo' ) }
							{ row.has_passcode && (
								<Badge tone="neutral" className="ms-2">
									{ __( 'Passcode', 'cliezoo' ) }
								</Badge>
							) }
						</p>
						<p className="text-xs text-muted">
							{ opened( row ) }
							{ row.expires_at &&
								' · ' +
									sprintf(
										/* translators: %s: date. */
										__( 'stops working %s', 'cliezoo' ),
										formatDate( row.expires_at )
									) }
						</p>
					</div>
					<Button
						size="sm"
						variant="secondary"
						isDisabled={ busy }
						onPress={ () =>
							act(
								async () =>
									(
										await api.post< { url: string } >(
											`/links/${ row.uid }/regenerate`
										)
									).data,
								__(
									'A new link. The old one has stopped working.',
									'cliezoo'
								)
							)
						}
					>
						{ __( 'Replace it', 'cliezoo' ) }
					</Button>
					<Button
						size="sm"
						variant="ghost"
						isDisabled={ busy }
						onPress={ () =>
							act(
								async () => {
									await api.post(
										`/links/${ row.uid }/revoke`
									);
									setMade( null );
								},
								__( 'Taken back.', 'cliezoo' )
							)
						}
					>
						{ __( 'Take it back', 'cliezoo' ) }
					</Button>
				</div>
			) ) }

			{ 0 === live.length && (
				<div className="flex flex-wrap items-end gap-2">
					<Select
						label={ __( 'Lasts', 'cliezoo' ) }
						className="w-52"
						items={ LASTS() }
						selectedKey={ lasts }
						onSelectionChange={ ( key ) =>
							setLasts( String( key ) )
						}
					/>
					<TextField
						label={ __( 'Passcode', 'cliezoo' ) }
						className="w-44"
						description={ __( 'Optional.', 'cliezoo' ) }
						value={ passcode }
						onChange={ setPasscode }
					/>
					<Button
						size="sm"
						className="mb-0.5"
						isPending={ busy }
						onPress={ () =>
							act(
								async () => {
									const result = (
										await api.post< { url: string } >(
											'/links',
											{
												object_type: type,
												uid,
												expires_at: until(),
												passcode: passcode || undefined,
											}
										)
									).data;
									setPasscode( '' );
									return result;
								},
								__( 'Link made.', 'cliezoo' )
							)
						}
					>
						{ __( 'Make a link', 'cliezoo' ) }
					</Button>
				</div>
			) }
		</section>
	);
}

/**
 * What can be done about one of a client's people from here: open their
 * page, send their invitation again if it is still waiting, and look at
 * the workspace as them.
 *
 * @param root0         Props.
 * @param root0.contact The contact's uid.
 * @param root0.name    Their name, for what the buttons say.
 * @param root0.pending Whether their invitation is still waiting.
 */
function PersonActions( {
	contact,
	name,
	pending,
}: {
	contact: string;
	name: string;
	pending: boolean;
} ) {
	const [ busy, setBusy ] = useState( false );

	return (
		<>
			<Link href={ appHref( `/contacts/${ contact }` ) }>
				{ __( 'Their page', 'cliezoo' ) }
			</Link>
			{ pending && (
				<Button
					size="sm"
					variant="ghost"
					isPending={ busy }
					onPress={ async () => {
						setBusy( true );
						try {
							await api.post(
								`/contacts/${ contact }/invite/resend`
							);
							toast.success(
								sprintf(
									/* translators: %s: a person's name. */
									__(
										'The invitation is on its way to %s again.',
										'cliezoo'
									),
									name
								)
							);
						} catch ( error ) {
							toast.error( ( error as ApiError ).message );
						} finally {
							setBusy( false );
						}
					} }
				>
					{ __( 'Invite again', 'cliezoo' ) }
				</Button>
			) }
		</>
	);
}
