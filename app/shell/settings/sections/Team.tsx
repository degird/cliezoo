/**
 * Settings → Team: members list, invitations, roles, deactivation and
 * ownership transfer.
 */
import { Suspense, lazy, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Slot } from '../../../packages/app/slot';
import { MoreHorizontal, UserPlus } from 'lucide-react';
import {
	api,
	useQuery,
	useQueryClient,
	useUser,
	type ApiError,
} from '@cliezoo/data';
import {
	Alert,
	Avatar,
	Badge,
	Button,
	Dialog,
	IconButton,
	Menu,
	MenuItem,
	MenuTrigger,
	Select,
	Skeleton,
	TextField,
	toast,
} from '@cliezoo/ui';
import type { EntityListProps } from '../../../packages/app/list/EntityList';

const EntityList = lazy(
	() =>
		import(
			/* webpackChunkName: "list" */ '../../../packages/app/list/EntityList'
		)
);

type Row = Parameters< NonNullable< EntityListProps[ 'rowMenu' ] > >[ 0 ];

const ROLE_LABELS = (): Record< string, string > => ( {
	owner: __( 'Owner', 'cliezoo' ),
	admin: __( 'Admin', 'cliezoo' ),
	member: __( 'Member', 'cliezoo' ),
	project_manager: __( 'Project manager', 'cliezoo' ),
	sales: __( 'Sales', 'cliezoo' ),
	finance: __( 'Finance', 'cliezoo' ),
	contractor: __( 'Contractor', 'cliezoo' ),
	readonly: __( 'Read-only', 'cliezoo' ),
} );

const ASSIGNABLE = [
	'admin',
	'member',
	'project_manager',
	'sales',
	'finance',
	'contractor',
	'readonly',
];

/**
 * How many people are on the team, and that there is no ceiling.
 *
 * Other tools put "2 of 5" here, and the number on the right is the one
 * they are selling. Cliezoo Free has no seat cap at all, so the line says
 * how many there are and says the rest plainly rather than leaving a
 * reader to wonder what they are allowed.
 */
function Seats() {
	const counts = useQuery( {
		queryKey: [ 'members', 'rollup', 'status' ],
		staleTime: 15_000,
		queryFn: async () =>
			(
				await api.get< {
					total: number;
					values: Array< { value: string; count: number } >;
				} >( '/rollup', {
					entity: 'members',
					by: 'status',
					filter: JSON.stringify( {
						and: [ { field: 'kind', op: 'eq', value: 'staff' } ],
					} ),
				} )
			).data,
	} );
	if ( ! counts.data ) {
		return null;
	}
	const active =
		counts.data.values.find( ( one ) => 'active' === one.value )?.count ??
		0;
	const invited =
		counts.data.values.find( ( one ) => 'invited' === one.value )?.count ??
		0;
	return (
		<p className="border-b border-line px-4 py-2 text-sm text-muted sm:px-6">
			{ sprintf(
				/* translators: %d: how many people are on the team. */
				_n(
					'%d person on your team.',
					'%d people on your team.',
					active,
					'cliezoo'
				),
				active
			) }
			{ invited > 0 &&
				' ' +
					sprintf(
						/* translators: %d: how many invitations are out. */
						_n(
							'%d invitation is out.',
							'%d invitations are out.',
							invited,
							'cliezoo'
						),
						invited
					) }{ ' ' }
			{ __( 'There is no limit on how many.', 'cliezoo' ) }
		</p>
	);
}

function InviteDialog( {
	isOpen,
	onOpenChange,
	onDone,
}: {
	isOpen: boolean;
	onOpenChange: ( open: boolean ) => void;
	onDone: () => void;
} ) {
	const [ email, setEmail ] = useState( '' );
	const [ name, setName ] = useState( '' );
	const [ role, setRole ] = useState( 'member' );
	const [ message, setMessage ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< ApiError | null >( null );
	const labels = ROLE_LABELS();
	const send = async () => {
		setBusy( true );
		setError( null );
		try {
			await api.post( '/members/invite', { email, name, role, message } );
			toast.success(
				sprintf(
					/* translators: %s: email address. */
					__( 'Invitation sent to %s.', 'cliezoo' ),
					email
				)
			);
			setEmail( '' );
			setName( '' );
			setMessage( '' );
			onOpenChange( false );
			onDone();
		} catch ( failure ) {
			setError( failure as ApiError );
		} finally {
			setBusy( false );
		}
	};
	return (
		<Dialog
			isOpen={ isOpen }
			onOpenChange={ onOpenChange }
			title={ __( 'Invite a team member', 'cliezoo' ) }
			description={ __(
				'They get an email with a link to join. There is no limit on team members.',
				'cliezoo'
			) }
			footer={ () => (
				<Button
					variant="primary"
					isPending={ busy }
					isDisabled={ ! email }
					onPress={ send }
				>
					{ __( 'Send invitation', 'cliezoo' ) }
				</Button>
			) }
		>
			<div className="space-y-4">
				{ error && ! Object.keys( error.fields ).length && (
					<Alert tone="error">{ error.message }</Alert>
				) }
				<TextField
					label={ __( 'Email', 'cliezoo' ) }
					type="email"
					value={ email }
					onChange={ setEmail }
					isRequired
					errorMessage={ error?.fields.email }
				/>
				<TextField
					label={ __( 'Name', 'cliezoo' ) }
					value={ name }
					onChange={ setName }
				/>
				<Select
					label={ __( 'Role', 'cliezoo' ) }
					items={ ASSIGNABLE.map( ( id ) => ( {
						id,
						label: labels[ id ],
					} ) ) }
					selectedKey={ role }
					onSelectionChange={ ( key ) => setRole( String( key ) ) }
					errorMessage={ error?.fields.role }
				/>
				<TextField
					label={ __( 'Personal note (optional)', 'cliezoo' ) }
					multiline
					rows={ 3 }
					value={ message }
					onChange={ setMessage }
				/>
			</div>
		</Dialog>
	);
}

export default function Team() {
	const me = useUser();
	const client = useQueryClient();
	const [ inviting, setInviting ] = useState( false );
	const [ roleFor, setRoleFor ] = useState< Row | null >( null );
	const [ newRole, setNewRole ] = useState( 'member' );
	const [ transferTo, setTransferTo ] = useState< Row | null >( null );
	const [ password, setPassword ] = useState( '' );
	const labels = ROLE_LABELS();
	const refresh = () =>
		client.invalidateQueries( { queryKey: [ 'members' ] } );

	const run = async ( fn: () => Promise< unknown >, message: string ) => {
		try {
			await fn();
			toast.success( message );
			refresh();
		} catch ( failure ) {
			toast.error( ( failure as Error ).message );
		}
	};

	const menu = ( row: Row ) => {
		const self = row.uid === me?.uid;
		const invited = row.status === 'invited';
		return (
			<MenuTrigger>
				<IconButton
					size="sm"
					label={ __( 'Member actions', 'cliezoo' ) }
					icon={ <MoreHorizontal size={ 16 } aria-hidden="true" /> }
				/>
				<Menu
					onAction={ ( key ) => {
						switch ( key ) {
							case 'resend':
								run(
									() =>
										api.post(
											`/members/${ row.uid }/invite/resend`
										),
									__( 'Invitation sent again.', 'cliezoo' )
								);
								break;
							case 'revoke':
								run(
									() =>
										api.post(
											`/members/${ row.uid }/invite/revoke`
										),
									__( 'Invitation withdrawn.', 'cliezoo' )
								);
								break;
							case 'role':
								setNewRole( String( row.role ) );
								setRoleFor( row );
								break;
							case 'deactivate':
								run(
									() =>
										api.patch( `/members/${ row.uid }`, {
											status: 'deactivated',
										} ),
									__(
										'Member deactivated and signed out everywhere.',
										'cliezoo'
									)
								);
								break;
							case 'activate':
								run(
									() =>
										api.patch( `/members/${ row.uid }`, {
											status: 'active',
										} ),
									__( 'Member reactivated.', 'cliezoo' )
								);
								break;
							case 'transfer':
								setTransferTo( row );
								break;
						}
					} }
				>
					{ invited && (
						<MenuItem id="resend">
							{ __( 'Send invitation again', 'cliezoo' ) }
						</MenuItem>
					) }
					{ invited && (
						<MenuItem id="revoke" danger>
							{ __( 'Withdraw invitation', 'cliezoo' ) }
						</MenuItem>
					) }
					{ row.role !== 'owner' && ! self && (
						<MenuItem id="role">
							{ __( 'Change role…', 'cliezoo' ) }
						</MenuItem>
					) }
					{ ! invited &&
						! self &&
						row.role !== 'owner' &&
						row.status === 'active' && (
							<MenuItem id="deactivate" danger>
								{ __( 'Deactivate', 'cliezoo' ) }
							</MenuItem>
						) }
					{ row.status === 'deactivated' && (
						<MenuItem id="activate">
							{ __( 'Reactivate', 'cliezoo' ) }
						</MenuItem>
					) }
					{ me?.role === 'owner' &&
						row.role === 'admin' &&
						row.status === 'active' && (
							<MenuItem id="transfer">
								{ __( 'Make owner…', 'cliezoo' ) }
							</MenuItem>
						) }
				</Menu>
			</MenuTrigger>
		);
	};

	return (
		<div className="-mx-4 overflow-hidden rounded-none border-y border-line bg-bg sm:mx-0 sm:rounded-lg sm:border">
			<Seats />
			<Suspense fallback={ <Skeleton className="h-64" /> }>
				<EntityList
					entity="members"
					label={ __( 'Team members', 'cliezoo' ) }
					baseFilter={ {
						and: [ { field: 'kind', op: 'eq', value: 'staff' } ],
					} }
					columns={ [
						'name',
						'email',
						'role',
						'status',
						'last_login_at',
						'created_at',
					] }
					render={ {
						name: ( row ) => (
							<span className="flex items-center gap-2">
								<Avatar
									name={ String( row.name ) }
									color={
										( row.color as string ) || undefined
									}
									size="sm"
								/>
								<span className="truncate">
									{ String( row.name ) }
								</span>
								{ row.uid === me?.uid && (
									<Badge>{ __( 'You', 'cliezoo' ) }</Badge>
								) }
							</span>
						),
						role: ( row ) => (
							<Badge
								tone={
									row.role === 'owner' ? 'accent' : 'neutral'
								}
							>
								{ labels[ String( row.role ) ] ??
									String( row.role ) }
							</Badge>
						),
					} }
					rowMenu={ menu }
					actions={
						<Button
							size="sm"
							variant="primary"
							icon={ <UserPlus size={ 14 } aria-hidden="true" /> }
							onPress={ () => setInviting( true ) }
						>
							{ __( 'Invite', 'cliezoo' ) }
						</Button>
					}
				/>
			</Suspense>
			<InviteDialog
				isOpen={ inviting }
				onOpenChange={ setInviting }
				onDone={ refresh }
			/>
			<Dialog
				isOpen={ !! roleFor }
				onOpenChange={ () => setRoleFor( null ) }
				title={ sprintf(
					/* translators: %s: a person's name. */
					__( 'Role for %s', 'cliezoo' ),
					String( roleFor?.name ?? '' )
				) }
				footer={ () => (
					<Button
						variant="primary"
						onPress={ () => {
							const row = roleFor;
							setRoleFor( null );
							if ( row ) {
								run(
									() =>
										api.patch( `/members/${ row.uid }`, {
											role: newRole,
										} ),
									__( 'Role changed.', 'cliezoo' )
								);
							}
						} }
					>
						{ __( 'Save', 'cliezoo' ) }
					</Button>
				) }
			>
				<Select
					label={ __( 'Role', 'cliezoo' ) }
					items={ ASSIGNABLE.map( ( id ) => ( {
						id,
						label: labels[ id ],
					} ) ) }
					selectedKey={ newRole }
					onSelectionChange={ ( key ) => setNewRole( String( key ) ) }
				/>
			</Dialog>
			<Dialog
				isOpen={ !! transferTo }
				onOpenChange={ () => setTransferTo( null ) }
				role="alertdialog"
				title={ __( 'Transfer ownership?', 'cliezoo' ) }
				description={ sprintf(
					/* translators: %s: member name. */
					__(
						'%s becomes the workspace owner and you become an admin. WordPress site administrators always keep full access.',
						'cliezoo'
					),
					String( transferTo?.name ?? '' )
				) }
				footer={ () => (
					<Button
						variant="danger"
						isDisabled={ ! password }
						onPress={ () => {
							const row = transferTo;
							setTransferTo( null );
							if ( row ) {
								run(
									() =>
										api
											.post(
												'/members/transfer-ownership',
												{ uid: row.uid, password }
											)
											.then( () =>
												window.location.reload()
											),
									__( 'Ownership transferred.', 'cliezoo' )
								);
							}
							setPassword( '' );
						} }
					>
						{ __( 'Transfer ownership', 'cliezoo' ) }
					</Button>
				) }
			>
				<TextField
					label={ __( 'Your password', 'cliezoo' ) }
					type="password"
					value={ password }
					onChange={ setPassword }
					isRequired
					autoComplete="current-password"
				/>
			</Dialog>

			<Slot name="settings.team" />
		</div>
	);
}
