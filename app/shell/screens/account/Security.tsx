/**
 * Account security: password, two-step verification (authenticator app +
 * recovery codes), passkeys, signed-in devices and sign-in history.
 */
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { copyText } from '../../../packages/app/copy';
import {
	KeyRound,
	LogOut,
	Monitor,
	ShieldCheck,
	Smartphone,
} from 'lucide-react';
import qrcode from 'qrcode-generator';
import ApiKeys from './ApiKeys';
import {
	api,
	formatDate,
	formatRelative,
	getBoot,
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
	Form,
	IconButton,
	Skeleton,
	TextField,
	toast,
} from '@cliezoo/ui';

interface Summary {
	two_factor: boolean;
	recovery_codes_left: number;
	passkeys: number;
	must_enroll: boolean;
	password_min_length: number;
}

interface Passkey {
	id: string;
	name: string;
	created: string;
	last_used: string | null;
}

interface Session {
	id: string;
	current: boolean;
	device: string;
	ip: string;
	login: string;
	expiration: string;
}

interface SignIn {
	event: string;
	status: string;
	ip: string;
	device: string;
	created_at: string;
}

function eventLabel( event: string ): string {
	if ( event === 'login_failed' ) {
		return __( 'Failed sign-in', 'cliezoo' );
	}
	return event === 'logout'
		? __( 'Signed out', 'cliezoo' )
		: __( 'Signed in', 'cliezoo' );
}

function Section( {
	id,
	title,
	description,
	children,
	action,
}: {
	id: string;
	title: string;
	description?: string;
	children: ReactNode;
	action?: ReactNode;
} ) {
	return (
		<section
			id={ id }
			aria-labelledby={ `${ id }-title` }
			className="scroll-mt-20 rounded-lg border border-line bg-raised p-5"
		>
			<div className="flex flex-wrap items-start gap-3">
				<div className="min-w-0 flex-1">
					<h2
						id={ `${ id }-title` }
						className="text-base font-semibold"
					>
						{ title }
					</h2>
					{ description && (
						<p className="mt-1 text-sm text-muted">
							{ description }
						</p>
					) }
				</div>
				{ action }
			</div>
			<div className="mt-4">{ children }</div>
		</section>
	);
}

function RecoveryCodes( {
	codes,
	onDone,
}: {
	codes: string[];
	onDone: () => void;
} ) {
	const download = () => {
		const blob = new Blob( [ codes.join( '\n' ) + '\n' ], {
			type: 'text/plain',
		} );
		const url = URL.createObjectURL( blob );
		const link = document.createElement( 'a' );
		link.href = url;
		link.download = 'recovery-codes.txt';
		link.click();
		URL.revokeObjectURL( url );
	};
	return (
		<Dialog
			isOpen
			isDismissable={ false }
			onOpenChange={ () => undefined }
			title={ __( 'Save your recovery codes', 'cliezoo' ) }
			description={ __(
				'Each code works once if you lose your phone. Keep them somewhere safe; they will not be shown again.',
				'cliezoo'
			) }
			footer={ () => (
				<>
					<Button
						onPress={ () =>
							copyText(
								codes.join( '\n' ),
								__( 'Copied.', 'cliezoo' )
							)
						}
					>
						{ __( 'Copy', 'cliezoo' ) }
					</Button>
					<Button onPress={ download }>
						{ __( 'Download', 'cliezoo' ) }
					</Button>
					<Button variant="primary" onPress={ onDone }>
						{ __( 'I saved them', 'cliezoo' ) }
					</Button>
				</>
			) }
		>
			<ul className="grid grid-cols-2 gap-2 font-mono text-sm">
				{ codes.map( ( code ) => (
					<li
						key={ code }
						className="rounded bg-sunken px-2 py-1 text-center"
					>
						{ code }
					</li>
				) ) }
			</ul>
		</Dialog>
	);
}

function TwoStep( {
	summary,
	reload,
	autoStart,
}: {
	summary: Summary;
	reload: () => void;
	autoStart: boolean;
} ) {
	const [ setup, setSetup ] = useState< {
		secret: string;
		uri: string;
	} | null >( null );
	const [ code, setCode ] = useState( '' );
	const [ codes, setCodes ] = useState< string[] | null >( null );
	const [ password, setPassword ] = useState( '' );
	const [ confirm, setConfirm ] = useState< 'disable' | 'codes' | null >(
		null
	);
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const start = async () => {
		setError( '' );
		try {
			setSetup(
				(
					await api.post< { secret: string; uri: string } >(
						'/auth/2fa/setup'
					)
				).data
			);
		} catch ( failure ) {
			setError( ( failure as ApiError ).message );
		}
	};

	useEffect( () => {
		if ( autoStart && ! summary.two_factor ) {
			start();
		}
	}, [ autoStart ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const enable = async ( event: FormEvent ) => {
		event.preventDefault();
		setBusy( true );
		setError( '' );
		try {
			const result = (
				await api.post< { recovery_codes: string[] } >(
					'/auth/2fa/enable',
					{ code }
				)
			).data;
			setCodes( result.recovery_codes );
			setSetup( null );
			setCode( '' );
			reload();
		} catch ( failure ) {
			setError( ( failure as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	const confirmed = async () => {
		setBusy( true );
		setError( '' );
		try {
			if ( confirm === 'disable' ) {
				await api.post( '/auth/2fa/disable', { password } );
				toast.success(
					__( 'Two-step verification is off.', 'cliezoo' )
				);
			} else {
				setCodes(
					(
						await api.post< { recovery_codes: string[] } >(
							'/auth/2fa/recovery-codes',
							{ password }
						)
					).data.recovery_codes
				);
			}
			setConfirm( null );
			setPassword( '' );
			reload();
		} catch ( failure ) {
			setError( ( failure as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	let qr = '';
	if ( setup ) {
		const generator = qrcode( 0, 'M' );
		generator.addData( setup.uri );
		generator.make();
		qr = generator.createDataURL( 5, 8 );
	}

	return (
		<Section
			id="security-2fa"
			title={ __( 'Two-step verification', 'cliezoo' ) }
			description={ __(
				'After your password, enter a code from an authenticator app. A passkey also counts as a second step.',
				'cliezoo'
			) }
			action={
				summary.two_factor ? (
					<Badge tone="success">{ __( 'On', 'cliezoo' ) }</Badge>
				) : (
					<Badge>{ __( 'Off', 'cliezoo' ) }</Badge>
				)
			}
		>
			{ summary.must_enroll && ! setup && (
				<div className="mb-4">
					<Alert
						tone="warning"
						title={ __( 'Required for your role', 'cliezoo' ) }
					>
						{ __(
							'Set up an authenticator app or add a passkey to keep using the workspace.',
							'cliezoo'
						) }
					</Alert>
				</div>
			) }
			{ error && ! confirm && (
				<div className="mb-4">
					<Alert tone="error">{ error }</Alert>
				</div>
			) }
			{ ! summary.two_factor && ! setup && (
				<Button
					variant="primary"
					icon={ <Smartphone size={ 16 } aria-hidden="true" /> }
					onPress={ start }
				>
					{ __( 'Set up authenticator app', 'cliezoo' ) }
				</Button>
			) }
			{ setup && (
				<Form
					onSubmit={ enable }
					className="grid gap-5 sm:grid-cols-[auto_1fr]"
				>
					<img
						src={ qr }
						alt={ __(
							'QR code for your authenticator app',
							'cliezoo'
						) }
						width={ 180 }
						height={ 180 }
						className="rounded-md border border-line bg-white"
					/>
					<div className="space-y-3">
						<ol className="list-decimal space-y-1 ps-5 text-sm text-muted">
							<li>
								{ __(
									'Open your authenticator app and scan the code.',
									'cliezoo'
								) }
							</li>
							<li>
								{ __(
									'Or enter this key by hand:',
									'cliezoo'
								) }{ ' ' }
								<code className="break-all rounded bg-sunken px-1.5 py-0.5 font-mono text-xs text-fg">
									{ setup.secret }
								</code>
							</li>
							<li>
								{ __(
									'Type the 6-digit code the app shows.',
									'cliezoo'
								) }
							</li>
						</ol>
						<TextField
							label={ __( 'Code', 'cliezoo' ) }
							value={ code }
							onChange={ setCode }
							isRequired
							inputMode="numeric"
							autoComplete="one-time-code"
						/>
						<div className="flex gap-2">
							<Button
								type="submit"
								variant="primary"
								isPending={ busy }
							>
								{ __( 'Turn on', 'cliezoo' ) }
							</Button>
							<Button
								variant="ghost"
								onPress={ () => setSetup( null ) }
							>
								{ __( 'Cancel', 'cliezoo' ) }
							</Button>
						</div>
					</div>
				</Form>
			) }
			{ summary.two_factor && (
				<div className="flex flex-wrap items-center gap-3">
					<p className="flex-1 text-sm text-muted">
						{ sprintf(
							/* translators: %d: number of unused recovery codes. */
							_n(
								'%d recovery code left.',
								'%d recovery codes left.',
								summary.recovery_codes_left,
								'cliezoo'
							),
							summary.recovery_codes_left
						) }
					</p>
					<Button onPress={ () => setConfirm( 'codes' ) }>
						{ __( 'New recovery codes', 'cliezoo' ) }
					</Button>
					<Button
						variant="danger"
						onPress={ () => setConfirm( 'disable' ) }
					>
						{ __( 'Turn off', 'cliezoo' ) }
					</Button>
				</div>
			) }
			<Dialog
				isOpen={ !! confirm }
				onOpenChange={ () => setConfirm( null ) }
				title={
					confirm === 'disable'
						? __( 'Turn off two-step verification?', 'cliezoo' )
						: __( 'Create new recovery codes?', 'cliezoo' )
				}
				description={
					confirm === 'disable'
						? __(
								'Your account will be protected by your password only.',
								'cliezoo'
							)
						: __(
								'Your old recovery codes stop working.',
								'cliezoo'
							)
				}
				footer={ () => (
					<Button
						variant={ confirm === 'disable' ? 'danger' : 'primary' }
						isPending={ busy }
						onPress={ confirmed }
					>
						{ __( 'Confirm', 'cliezoo' ) }
					</Button>
				) }
			>
				{ error && (
					<div className="mb-3">
						<Alert tone="error">{ error }</Alert>
					</div>
				) }
				<TextField
					label={ __( 'Your password', 'cliezoo' ) }
					type="password"
					value={ password }
					onChange={ setPassword }
					isRequired
					autoComplete="current-password"
				/>
			</Dialog>
			{ codes && (
				<RecoveryCodes
					codes={ codes }
					onDone={ () => setCodes( null ) }
				/>
			) }
		</Section>
	);
}

function Passkeys( { reload }: { reload: () => void } ) {
	const client = useQueryClient();
	const list = useQuery( {
		queryKey: [ 'passkeys' ],
		queryFn: async () =>
			( await api.get< Passkey[] >( '/auth/passkeys' ) ).data,
	} );
	const [ busy, setBusy ] = useState( false );
	const supported =
		typeof window !== 'undefined' && 'PublicKeyCredential' in window;
	const refresh = () => {
		client.invalidateQueries( { queryKey: [ 'passkeys' ] } );
		reload();
	};
	const add = async () => {
		setBusy( true );
		try {
			const { startRegistration } = await import(
				/* webpackChunkName: "webauthn" */ '@simplewebauthn/browser'
			);
			const { options, challenge } = (
				await api.post< {
					options: { publicKey: Record< string, unknown > };
					challenge: string;
				} >( '/auth/passkeys/options' )
			).data;
			const credential = await startRegistration( {
				optionsJSON: options.publicKey as never,
			} );
			await api.post( '/auth/passkeys', {
				challenge,
				clientDataJSON: credential.response.clientDataJSON,
				attestationObject: credential.response.attestationObject,
			} );
			toast.success( __( 'Passkey added.', 'cliezoo' ) );
			refresh();
		} catch ( failure ) {
			const name = ( failure as { name?: string } ).name;
			if ( name !== 'NotAllowedError' && name !== 'AbortError' ) {
				toast.error( ( failure as Error ).message );
			}
		} finally {
			setBusy( false );
		}
	};
	// Removing a way in is asked about first, because the person doing it
	// may be removing the way they got here.
	const [ removing, setRemoving ] = useState< Passkey | null >( null );
	const remove = async ( id: string ) => {
		try {
			await api.post( '/auth/passkeys/delete', { id } );
			refresh();
		} catch ( failure ) {
			toast.error( ( failure as Error ).message );
		}
	};
	return (
		<Section
			id="security-passkeys"
			title={ __( 'Passkeys', 'cliezoo' ) }
			description={ __(
				'Sign in with your fingerprint, face or device PIN instead of a password.',
				'cliezoo'
			) }
			action={
				supported && (
					<Button
						icon={ <KeyRound size={ 16 } aria-hidden="true" /> }
						isPending={ busy }
						onPress={ add }
					>
						{ __( 'Add a passkey', 'cliezoo' ) }
					</Button>
				)
			}
		>
			{ ! supported && (
				<p className="text-sm text-muted">
					{ __(
						'This browser does not support passkeys.',
						'cliezoo'
					) }
				</p>
			) }
			{ list.isPending && <Skeleton className="h-10" /> }
			{ list.isError && (
				<ErrorState
					error={ list.error }
					onRetry={ () => list.refetch() }
				/>
			) }
			{ list.data && ! list.data.length && (
				<p className="text-sm text-muted">
					{ __( 'No passkeys yet.', 'cliezoo' ) }
				</p>
			) }
			{ !! list.data?.length && (
				<ul className="divide-y divide-line rounded-md border border-line">
					{ list.data.map( ( key ) => (
						<li
							key={ key.id }
							className="flex items-center gap-3 px-3 py-2"
						>
							<KeyRound
								size={ 16 }
								className="text-muted"
								aria-hidden="true"
							/>
							<div className="min-w-0 flex-1">
								<p className="truncate text-sm font-medium">
									{ key.name }
								</p>
								<p className="text-xs text-muted">
									{ key.last_used
										? sprintf(
												/* translators: %s: relative time. */
												__( 'Last used %s', 'cliezoo' ),
												formatRelative( key.last_used )
											)
										: sprintf(
												/* translators: %s: date. */
												__( 'Added %s', 'cliezoo' ),
												formatDate( key.created )
											) }
								</p>
							</div>
							<Button
								size="sm"
								variant="ghost"
								onPress={ () => setRemoving( key ) }
							>
								{ __( 'Remove', 'cliezoo' ) }
							</Button>
						</li>
					) ) }
				</ul>
			) }
			<Dialog
				isOpen={ null !== removing }
				onOpenChange={ ( open ) => ! open && setRemoving( null ) }
				role="alertdialog"
				size="sm"
				title={ __( 'Remove this passkey?', 'cliezoo' ) }
				description={
					removing
						? sprintf(
								/* translators: %s: the passkey's name. */
								__(
									'%s will no longer sign you in. You can add it again from the device it belongs to.',
									'cliezoo'
								),
								removing.name
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
							onPress={ () => {
								if ( removing ) {
									remove( removing.id );
								}
								setRemoving( null );
							} }
						>
							{ __( 'Remove', 'cliezoo' ) }
						</Button>
					</>
				) }
			>
				{ 1 === list.data?.length && (
					<p className="text-sm text-muted">
						{ __(
							'This is your only passkey. You will need your password — and your second step, if you use one — to sign in next time.',
							'cliezoo'
						) }
					</p>
				) }
			</Dialog>
		</Section>
	);
}

function Password() {
	const [ current, setCurrent ] = useState( '' );
	const [ next, setNext ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ fields, setFields ] = useState< Record< string, string > >( {} );
	const [ error, setError ] = useState( '' );
	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setBusy( true );
		setError( '' );
		setFields( {} );
		try {
			await api.post( '/auth/password', { current, password: next } );
			toast.success(
				__(
					'Password changed. Other devices were signed out.',
					'cliezoo'
				)
			);
			setCurrent( '' );
			setNext( '' );
		} catch ( failure ) {
			setError( ( failure as ApiError ).message );
			setFields( ( failure as ApiError ).fields );
		} finally {
			setBusy( false );
		}
	};
	return (
		<Section id="security-password" title={ __( 'Password', 'cliezoo' ) }>
			<Form onSubmit={ submit } className="grid gap-4 sm:grid-cols-2">
				{ error && (
					<div className="sm:col-span-2">
						<Alert tone="error">{ error }</Alert>
					</div>
				) }
				<TextField
					label={ __( 'Current password', 'cliezoo' ) }
					type="password"
					value={ current }
					onChange={ setCurrent }
					isRequired
					autoComplete="current-password"
					errorMessage={ fields.current }
				/>
				<TextField
					label={ __( 'New password', 'cliezoo' ) }
					type="password"
					value={ next }
					onChange={ setNext }
					isRequired
					autoComplete="new-password"
					errorMessage={ fields.password }
					description={ sprintf(
						/* translators: %d: minimum number of characters. */
						_n(
							'At least %d character.',
							'At least %d characters.',
							getBoot().security.passwordMin,
							'cliezoo'
						),
						getBoot().security.passwordMin
					) }
				/>
				<div className="sm:col-span-2">
					<Button type="submit" variant="primary" isPending={ busy }>
						{ __( 'Change password', 'cliezoo' ) }
					</Button>
				</div>
			</Form>
		</Section>
	);
}

function Sessions() {
	const client = useQueryClient();
	const sessions = useQuery( {
		queryKey: [ 'sessions' ],
		queryFn: async () =>
			( await api.get< Session[] >( '/auth/sessions' ) ).data,
	} );
	const history = useQuery( {
		queryKey: [ 'login-history' ],
		queryFn: async () =>
			(
				await api.get< SignIn[] >( '/auth/login-history', {
					limit: 10,
				} )
			).data,
	} );
	const revoke = async ( path: string, body?: Record< string, string > ) => {
		try {
			await api.post( path, body );
			client.invalidateQueries( { queryKey: [ 'sessions' ] } );
			toast.success( __( 'Signed out.', 'cliezoo' ) );
		} catch ( failure ) {
			toast.error( ( failure as Error ).message );
		}
	};
	const others = ( sessions.data ?? [] ).filter(
		( s ) => ! s.current
	).length;
	return (
		<Section
			id="security-sessions"
			title={ __( 'Where you are signed in', 'cliezoo' ) }
			action={
				others > 0 && (
					<Button
						icon={ <LogOut size={ 16 } aria-hidden="true" /> }
						onPress={ () =>
							revoke( '/auth/sessions/revoke-others' )
						}
					>
						{ __( 'Sign out everywhere else', 'cliezoo' ) }
					</Button>
				)
			}
		>
			{ sessions.isPending && <Skeleton className="h-10" /> }
			{ sessions.isError && (
				<ErrorState
					error={ sessions.error }
					onRetry={ () => sessions.refetch() }
				/>
			) }
			<ul className="divide-y divide-line rounded-md border border-line">
				{ ( sessions.data ?? [] ).map( ( session ) => (
					<li
						key={ session.id }
						className="flex items-center gap-3 px-3 py-2"
					>
						<Monitor
							size={ 16 }
							className="text-muted"
							aria-hidden="true"
						/>
						<div className="min-w-0 flex-1">
							<p className="truncate text-sm font-medium">
								{ session.device }{ ' ' }
								{ session.current && (
									<Badge tone="accent">
										{ __( 'This device', 'cliezoo' ) }
									</Badge>
								) }
							</p>
							<p className="text-xs text-muted">
								{ session.ip } ·{ ' ' }
								{ formatRelative( session.login ) }
							</p>
						</div>
						{ ! session.current && (
							<IconButton
								size="sm"
								label={ __(
									'Sign out this device',
									'cliezoo'
								) }
								icon={
									<LogOut size={ 15 } aria-hidden="true" />
								}
								onPress={ () =>
									revoke( '/auth/sessions/revoke', {
										id: session.id,
									} )
								}
							/>
						) }
					</li>
				) ) }
			</ul>
			{ !! history.data?.length && (
				<details className="mt-4">
					<summary className="cursor-pointer text-sm font-medium text-accent-fg">
						{ __( 'Recent sign-ins', 'cliezoo' ) }
					</summary>
					<ul className="mt-2 space-y-1 text-sm text-muted">
						{ history.data.map( ( row, index ) => (
							<li key={ index }>
								{ eventLabel( row.event ) } · { row.device } ·{ ' ' }
								{ row.ip } ·{ ' ' }
								{ formatRelative( row.created_at ) }
							</li>
						) ) }
					</ul>
				</details>
			) }
		</Section>
	);
}

export default function Security( { autoSetup }: { autoSetup: boolean } ) {
	const summary = useQuery( {
		queryKey: [ 'security' ],
		queryFn: async () =>
			( await api.get< Summary >( '/auth/security' ) ).data,
	} );
	const reload = () => summary.refetch();
	useEffect( () => {
		if ( autoSetup ) {
			document
				.getElementById( 'security-2fa' )
				?.scrollIntoView( { block: 'start' } );
		}
	}, [ autoSetup, summary.isSuccess ] );
	// A Skeleton is aria-hidden: a failure here pulses for ever and tells
	// nobody anything.
	if ( summary.isError ) {
		return (
			<ErrorState
				error={ summary.error }
				title={ __(
					'Your security settings could not be loaded.',
					'cliezoo'
				) }
				onRetry={ reload }
			/>
		);
	}
	if ( ! summary.data ) {
		return <Skeleton className="h-40" />;
	}
	return (
		<div className="space-y-6">
			<h2 className="flex items-center gap-2 pt-2 text-lg font-semibold">
				<ShieldCheck
					size={ 20 }
					className="text-accent-fg"
					aria-hidden="true"
				/>
				{ __( 'Security', 'cliezoo' ) }
			</h2>
			<TwoStep
				summary={ summary.data }
				reload={ reload }
				autoStart={ autoSetup }
			/>
			<Passkeys reload={ reload } />
			<Password />
			{ ( !! getBoot().user?.owner ||
				!! getBoot().user?.permissions.toggles.settings_api ) && (
				<ApiKeys />
			) }
			<Sessions />
		</div>
	);
}
