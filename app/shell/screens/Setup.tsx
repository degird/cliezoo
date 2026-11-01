/**
 * Setup wizard: the few answers that make the workspace yours, then out of
 * the way. Every step can be skipped and repeated later in Settings.
 */
import { useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowLeft, ArrowRight, Check } from 'lucide-react';
import {
	api,
	getBoot,
	useQuery,
	useQueryClient,
	useSession,
} from '@cliezoo/data';
import {
	Alert,
	ConfirmDialog,
	Button,
	ErrorState,
	Select,
	Spinner,
	TextField,
	toast,
} from '@cliezoo/ui';
import { COUNTRIES, countryName } from '../settings/countries';
import {
	COMPANY_SIZES,
	INDUSTRIES,
	industryName,
} from '../settings/industries';

interface SetupState {
	completed: boolean;
	app_url: string;
	key_source: 'constant' | 'salts' | string;
	sample_data: boolean;
}

export default function Setup() {
	const navigate = useNavigate();
	const client = useQueryClient();
	const [ step, setStep ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	const [ workspace, setWorkspace ] = useState( {
		name: getBoot().workspace.name ?? '',
		industry: '',
		company_size: '',
		country: '',
	} );
	const [ emailSent, setEmailSent ] = useState< boolean | null >( null );
	// Where the test went. "Sent." with no address leaves somebody
	// watching the wrong inbox and concluding the site cannot send mail.
	const [ sentTo, setSentTo ] = useState( '' );
	const [ leaving, setLeaving ] = useState( false );

	// Plain permalinks put the app on a query address; setup offers the
	// switch rather than leaving it to be found later.
	const [ pretty, setPretty ] = useState( getBoot().routes?.pretty ?? true );
	const tidy = async () => {
		try {
			const { data } = await api.post< { pretty: boolean } >(
				'/setup/pretty-permalinks'
			);
			setPretty( data.pretty );
			await client.invalidateQueries( { queryKey: [ 'setup' ] } );
			toast.success(
				__( 'Done: the app has a tidy address.', 'cliezoo' )
			);
		} catch ( error ) {
			toast.error( ( error as { message?: string } ).message ?? '' );
		}
	};
	const setup = useQuery( {
		queryKey: [ 'setup' ],
		queryFn: async () => ( await api.get< SetupState >( '/setup' ) ).data,
	} );

	const save = async ( values: Record< string, unknown > ) => {
		setBusy( true );
		try {
			const { industry, ...rest } = values;
			await api.patch( '/settings/workspace', { values: rest } );
			// The industry goes through setup, which also adds the project
			// categories and folders that suit it (never replacing any).
			if ( industry ) {
				await api.post( '/setup/industry', { industry } );
			}
			await client.invalidateQueries( { queryKey: [ 'settings' ] } );
			setStep( ( current ) => current + 1 );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		} finally {
			setBusy( false );
		}
	};

	const testEmail = async () => {
		setBusy( true );
		try {
			const { data } = await api.post< { sent: boolean; to: string } >(
				'/setup/test-email'
			);
			setEmailSent( data.sent );
			setSentTo( data.to ?? '' );
		} catch ( error ) {
			setEmailSent( false );
			setSentTo( '' );
			toast.error( ( error as Error ).message );
		} finally {
			setBusy( false );
		}
	};

	// Setup does not come back, so what is on this step and not yet
	// saved is about to go for good. Somebody who has answered nothing
	// is not asked.
	const typed =
		0 === step &&
		( workspace.name !== ( getBoot().workspace.name ?? '' ) ||
			!! workspace.industry ||
			!! workspace.company_size ||
			!! workspace.country );

	const finish = async ( withSample: boolean ) => {
		setBusy( true );
		try {
			if ( withSample ) {
				await api.post( '/setup/sample-data' );
			}
			await api.post( '/setup/finish' );
			await client.invalidateQueries();
			// The boot payload said setup was unfinished; say otherwise now,
			// or Home would send us straight back here.
			const boot = useSession.getState().boot;
			useSession.getState().setBoot( {
				...boot,
				setup: boot.setup
					? { ...boot.setup, completed: true }
					: undefined,
			} );
			toast.success( __( 'Your workspace is ready.', 'cliezoo' ) );
			navigate( { to: '/' } );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		} finally {
			setBusy( false );
		}
	};

	if ( setup.isPending ) {
		return (
			<div className="flex min-h-dvh items-center justify-center">
				<Spinner label={ __( 'Loading', 'cliezoo' ) } />
			</div>
		);
	}

	if ( setup.isError ) {
		return (
			<div className="mx-auto max-w-md p-6">
				<ErrorState
					error={ setup.error }
					onRetry={ () => setup.refetch() }
				/>
			</div>
		);
	}

	const steps = [
		{
			title: __( 'What should we call this workspace?', 'cliezoo' ),
			body: (
				<div className="space-y-4">
					<TextField
						label={ __( 'Workspace name', 'cliezoo' ) }
						value={ workspace.name }
						// eslint-disable-next-line jsx-a11y/no-autofocus -- The wizard opens on this field.
						autoFocus
						onChange={ ( x ) =>
							setWorkspace( { ...workspace, name: x } )
						}
					/>
					<Select
						label={ __( 'What do you do?', 'cliezoo' ) }
						description={ __(
							'Optional. We add folders that suit it; you can change them all later.',
							'cliezoo'
						) }
						items={ INDUSTRIES.map( ( id ) => ( {
							id,
							label: industryName( id ),
						} ) ) }
						selectedKey={ workspace.industry || null }
						onSelectionChange={ ( k ) =>
							setWorkspace( {
								...workspace,
								industry: String( k ),
							} )
						}
					/>
					<Select
						label={ __( 'How many of you are there?', 'cliezoo' ) }
						items={ COMPANY_SIZES.map( ( size ) => ( {
							id: size,
							label: size,
						} ) ) }
						selectedKey={ workspace.company_size }
						onSelectionChange={ ( k ) =>
							setWorkspace( {
								...workspace,
								company_size: String( k ),
							} )
						}
					/>
					<Select
						label={ __( 'Where are you based?', 'cliezoo' ) }
						items={ COUNTRIES.map( ( code ) => ( {
							id: code,
							label: countryName( code ),
						} ) ) }
						selectedKey={ workspace.country }
						onSelectionChange={ ( k ) =>
							setWorkspace( {
								...workspace,
								country: String( k ),
							} )
						}
					/>
				</div>
			),
			next: () => save( workspace ),
		},
		{
			title: __( 'Can this site send email?', 'cliezoo' ),
			body: (
				<div className="space-y-4">
					<p className="text-sm text-muted">
						{ __(
							'Invitations, reminders and notifications all go out as email. Send yourself a test to be sure it arrives.',
							'cliezoo'
						) }
					</p>
					<Button
						variant="secondary"
						isPending={ busy }
						onPress={ testEmail }
					>
						{ __( 'Send a test email', 'cliezoo' ) }
					</Button>
					{ true === emailSent && (
						<Alert tone="success" role="status">
							{ sentTo
								? sprintf(
										/* translators: %s: the address the test was sent to. */
										__(
											'Sent to %s. If it does not arrive in a few minutes, check your spam folder — that usually means the site needs an SMTP plugin.',
											'cliezoo'
										),
										sentTo
									)
								: __(
										'Sent. If it does not arrive in a few minutes, check your spam folder — that usually means the site needs an SMTP plugin.',
										'cliezoo'
									) }
						</Alert>
					) }
					{ false === emailSent && (
						<Alert tone="error">
							{ __(
								'It could not be sent. An SMTP plugin or a sending service will fix this; you can carry on and come back to it.',
								'cliezoo'
							) }
						</Alert>
					) }
					{ 'salts' === setup.data?.key_source && (
						<Alert tone="warning">
							{ __(
								'Tip: add CLIEZOO_ENCRYPTION_KEY to wp-config.php. Without it the key comes from your WordPress salts, and changing those would make stored secrets unreadable.',
								'cliezoo'
							) }
						</Alert>
					) }
				</div>
			),
			next: () => setStep( ( current ) => current + 1 ),
		},
		{
			title: __( 'Your workspace lives here', 'cliezoo' ),
			body: (
				<div className="space-y-4">
					{ ! pretty && (
						<Alert tone="warning">
							<p>
								{ __(
									'WordPress is using plain permalinks, so the app lives at a query address rather than a tidy one. Switching changes the site’s permalinks to post names.',
									'cliezoo'
								) }
							</p>
							<Button className="mt-2" onPress={ tidy }>
								{ __( 'Use tidy addresses', 'cliezoo' ) }
							</Button>
						</Alert>
					) }
					<p className="text-sm text-muted">
						{ sprintf(
							/* translators: %s: the app address. */
							__(
								'Everything happens at %s. Bookmark it — your team signs in there, not in wp-admin.',
								'cliezoo'
							),
							setup.data?.app_url ?? ''
						) }
					</p>
					<p className="text-sm text-muted">
						{ __(
							'Would you like a few example records to look at? You can remove them in one click later.',
							'cliezoo'
						) }
					</p>
					<div className="flex flex-wrap gap-2">
						<Button
							variant="primary"
							isPending={ busy }
							onPress={ () => finish( true ) }
						>
							{ __( 'Add examples and finish', 'cliezoo' ) }
						</Button>
						<Button
							variant="secondary"
							isPending={ busy }
							onPress={ () => finish( false ) }
						>
							{ __( 'Start empty', 'cliezoo' ) }
						</Button>
					</div>
				</div>
			),
			next: null,
		},
	];

	const current = steps[ Math.min( step, steps.length - 1 ) ];

	return (
		<main
			id="cliezoo-main"
			tabIndex={ -1 }
			className="mx-auto flex min-h-dvh max-w-xl flex-col justify-center px-4 py-10 outline-none"
		>
			<ol
				className="mb-6 flex items-center gap-2"
				aria-label={ __( 'Steps', 'cliezoo' ) }
			>
				{ steps.map( ( item, index ) => (
					<li
						key={ item.title }
						aria-current={ index === step ? 'step' : undefined }
						className={ `h-1.5 flex-1 rounded-full ${
							index <= step ? 'bg-accent' : 'bg-sunken'
						}` }
					>
						<span className="sr-only">{ item.title }</span>
					</li>
				) ) }
			</ol>
			<h1 className="text-2xl font-semibold tracking-tight text-fg">
				{ current.title }
			</h1>
			<div className="mt-6">{ current.body }</div>
			<div className="mt-4">
			</div>
			<div className="mt-8 flex items-center gap-2">
				{ step > 0 && (
					<Button
						variant="ghost"
						icon={
							<ArrowLeft
								size={ 14 }
								aria-hidden="true"
								className="rtl:rotate-180"
							/>
						}
						onPress={ () => setStep( step - 1 ) }
					>
						{ __( 'Back', 'cliezoo' ) }
					</Button>
				) }
				{ current.next && (
					<Button
						className="ms-auto"
						variant="primary"
						isPending={ busy }
						icon={
							<ArrowRight
								size={ 14 }
								aria-hidden="true"
								className="rtl:rotate-180"
							/>
						}
						onPress={ current.next }
					>
						{ __( 'Continue', 'cliezoo' ) }
					</Button>
				) }
				<Button
					className={ current.next ? '' : 'ms-auto' }
					variant="ghost"
					icon={ <Check size={ 14 } aria-hidden="true" /> }
					onPress={ () =>
						typed ? setLeaving( true ) : finish( false )
					}
				>
					{ __( 'Finish without these', 'cliezoo' ) }
				</Button>
				<ConfirmDialog
					isOpen={ leaving }
					onOpenChange={ setLeaving }
					title={ __( 'Finish without saving these?', 'cliezoo' ) }
					description={ __(
						'What is on this step has not been saved, and setup does not come back. You can set all of it in Settings whenever you like.',
						'cliezoo'
					) }
					confirmLabel={ __( 'Finish anyway', 'cliezoo' ) }
					onConfirm={ () => finish( false ) }
				/>
			</div>
		</main>
	);
}
