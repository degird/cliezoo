/**
 * Settings → Site and data: app addresses, Safe mode, trash, uninstall and
 * debug logging.
 */
import { useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import {
	api,
	getBoot,
	restUrl,
	useQuery,
	useSession,
	type ApiError,
	type SafeModeStatus,
} from '@cliezoo/data';
import {
	Alert,
	Button,
	Dialog,
	ErrorState,
	Switch,
	TextField,
	toast,
} from '@cliezoo/ui';
import { Fieldset, SettingsForm, useSettings } from '../useSettings';

interface Urls {
	app: string;
	public: string;
	appUrl: string;
	publicUrl: string;
	pretty: boolean;
	problems: { app: string[]; public: string[] };
}

function AppUrls() {
	const urls = useQuery( {
		queryKey: [ 'app-urls' ],
		queryFn: async () => ( await api.get< Urls >( '/app-urls' ) ).data,
	} );
	const [ kind, setKind ] = useState< 'app' | 'public' | null >( null );
	const [ base, setBase ] = useState( '' );
	const [ problems, setProblems ] = useState< string[] >( [] );
	const [ busy, setBusy ] = useState( false );
	const open = ( which: 'app' | 'public' ) => {
		setKind( which );
		setBase( urls.data?.[ which ] ?? '' );
		setProblems( [] );
	};
	// One request when the typing stops, not one per keystroke — and the
	// answer to an older question is thrown away rather than shown.
	const checking = useRef< number >( 0 );
	const check = ( value: string ) => {
		setBase( value );
		window.clearTimeout( checking.current );
		if ( ! kind || value.length < 2 ) {
			setProblems( [] );
			return;
		}
		checking.current = window.setTimeout( () => ask( value ), 400 );
	};
	const ask = async ( value: string ) => {
		if ( ! kind ) {
			return;
		}
		try {
			setProblems(
				(
					await api.get< { problems: string[] } >(
						'/app-urls/check',
						{ kind, base: value }
					)
				).data.problems
			);
		} catch ( error ) {
			setProblems(
				Object.values( ( error as ApiError ).fields ).length
					? Object.values( ( error as ApiError ).fields )
					: [ ( error as ApiError ).message ]
			);
		}
	};
	const save = async () => {
		setBusy( true );
		try {
			await api.post( '/app-urls', { kind, base } );
			toast.success(
				__(
					'Address changed. Taking you to the new address…',
					'cliezoo'
				)
			);
			const fresh = ( await api.get< Urls >( '/app-urls' ) ).data;
			window.setTimeout(
				() =>
					window.location.assign(
						fresh.appUrl + 'settings/advanced'
					),
				800
			);
		} catch ( error ) {
			setProblems( [ ( error as ApiError ).message ] );
			setBusy( false );
		}
	};
	if ( urls.isError ) {
		return (
			<section className="rounded-lg border border-line bg-raised p-5">
				<ErrorState
					error={ urls.error }
					onRetry={ () => urls.refetch() }
				/>
			</section>
		);
	}
	if ( ! urls.data ) {
		return null;
	}
	return (
		<section className="rounded-lg border border-line bg-raised p-5">
			<h2 className="text-base font-semibold">
				{ __( 'App addresses', 'cliezoo' ) }
			</h2>
			<p className="mt-1 text-sm text-muted">
				{ __(
					'Where your team and clients open the app, and where token pages (signing, payments, forms) live.',
					'cliezoo'
				) }
			</p>
			{ ! urls.data.pretty && (
				<div className="mt-3">
					<Alert tone="warning">
						{ __(
							'WordPress uses plain permalinks, so the app runs at an address with ?cliezoo=. Choose “Post name” in Settings → Permalinks for clean addresses.',
							'cliezoo'
						) }
					</Alert>
				</div>
			) }
			<dl className="mt-4 grid gap-3 text-sm sm:grid-cols-[10rem_1fr_auto]">
				{ ( [ 'app', 'public' ] as const ).map( ( which ) => (
					<div key={ which } className="contents">
						<dt className="font-medium">
							{ which === 'app'
								? __( 'App', 'cliezoo' )
								: __( 'Public pages', 'cliezoo' ) }
						</dt>
						<dd className="min-w-0 break-all text-muted">
							{ which === 'app'
								? urls.data.appUrl
								: urls.data.publicUrl }
							{ !! urls.data.problems[ which ].length && (
								<span className="mt-1 block text-warning">
									{ urls.data.problems[ which ].join( ' ' ) }
								</span>
							) }
						</dd>
						<dd>
							<Button size="sm" onPress={ () => open( which ) }>
								{ __( 'Change', 'cliezoo' ) }
							</Button>
						</dd>
					</div>
				) ) }
			</dl>
			<Dialog
				isOpen={ !! kind }
				onOpenChange={ () => setKind( null ) }
				title={
					kind === 'app'
						? __( 'Change the app address', 'cliezoo' )
						: __( 'Change the public pages address', 'cliezoo' )
				}
				description={ __(
					'Links already sent in emails keep working only while the old address is not used by anything else. We check for clashes with pages and other plugins.',
					'cliezoo'
				) }
				footer={ () => (
					<Button
						variant="primary"
						isPending={ busy }
						isDisabled={ !! problems.length || ! base }
						onPress={ save }
					>
						{ __( 'Change address', 'cliezoo' ) }
					</Button>
				) }
			>
				<TextField
					label={ __( 'Address', 'cliezoo' ) }
					description={ sprintf(
						/* translators: %s: example address. */
						__(
							'Lower-case letters, numbers and dashes, e.g. %s',
							'cliezoo'
						),
						kind === 'app' ? 'workspace' : 'go'
					) }
					value={ base }
					onChange={ check }
					errorMessage={
						problems.length ? problems.join( ' ' ) : undefined
					}
				/>
			</Dialog>
		</section>
	);
}

interface Domain {
	host: string;
	url: string;
	verified: boolean;
	checked_at: string;
	problem: string;
}

/**
 * The portal on a domain of the studio's own.
 *
 * The domain belongs to the studio: they point it here at their DNS and
 * their host issues its certificate. What Cliezoo does is check — asking
 * the domain, over HTTPS, whether it really reaches this site — and it
 * moves nothing until that check passes, so a mistyped domain cannot take
 * the portal off the air.
 */
export function PortalDomain() {
	const domain = useQuery( {
		queryKey: [ 'portal-domain' ],
		queryFn: async () =>
			( await api.get< Domain >( '/portal-domain' ) ).data,
	} );
	const [ host, setHost ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ problem, setProblem ] = useState( '' );
	if ( ! domain.data ) {
		return null;
	}
	const now = domain.data;
	const typed = host ?? now.host;
	const save = async () => {
		setBusy( true );
		setProblem( '' );
		try {
			await api.post( '/portal-domain', { host: typed } );
			setHost( null );
			await domain.refetch();
			toast.success(
				'' === typed.trim()
					? __( 'The portal is back on this site.', 'cliezoo' )
					: __(
							'Saved. Point the domain here, then check it.',
							'cliezoo'
						)
			);
		} catch ( error ) {
			setProblem( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};
	const check = async () => {
		setBusy( true );
		try {
			const after = ( await api.post< Domain >( '/portal-domain/check' ) )
				.data;
			await domain.refetch();
			if ( after.verified ) {
				toast.success(
					__(
						'The domain reaches this site. The portal is on it now.',
						'cliezoo'
					)
				);
			} else {
				toast.error( after.problem );
			}
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};
	return (
		<section className="rounded-lg border border-line bg-raised p-5">
			<h2 className="text-base font-semibold">
				{ __( 'Portal domain', 'cliezoo' ) }
			</h2>
			<p className="mt-1 text-sm text-muted">
				{ __(
					'Clients can open the portal at a domain of your own — clients.youragency.com — instead of a path on this site. Point the domain here at your DNS and have your host issue its certificate; we check both before anything moves.',
					'cliezoo'
				) }
			</p>
			<div className="mt-4 max-w-md">
				<TextField
					label={ __( 'Domain', 'cliezoo' ) }
					description={ __(
						'Leave it empty to keep the portal on this site.',
						'cliezoo'
					) }
					value={ typed }
					onChange={ setHost }
					errorMessage={ problem || undefined }
				/>
			</div>
			{ !! now.host && (
				<div className="mt-3">
					<Alert tone={ now.verified ? 'success' : 'warning' }>
						{ now.verified
							? sprintf(
									/* translators: %s: the portal address. */
									__(
										'Checked. Clients open the portal at %s.',
										'cliezoo'
									),
									now.url
								)
							: now.problem ||
								__(
									'Not checked yet. Point the domain at this site, then press Check the domain.',
									'cliezoo'
								) }
					</Alert>
				</div>
			) }
			<div className="mt-4 flex flex-wrap gap-2">
				<Button
					variant="primary"
					isPending={ busy }
					isDisabled={ typed === now.host }
					onPress={ save }
				>
					{ __( 'Save domain', 'cliezoo' ) }
				</Button>
				<Button
					isPending={ busy }
					isDisabled={ ! now.host || typed !== now.host }
					onPress={ check }
				>
					{ __( 'Check the domain', 'cliezoo' ) }
				</Button>
			</div>
		</section>
	);
}

function SafeMode() {
	const status = useQuery( {
		queryKey: [ 'safe-mode' ],
		queryFn: async () =>
			( await api.get< SafeModeStatus >( '/safe-mode' ) ).data,
	} );
	const [ confirm, setConfirm ] = useState( false );
	if ( ! status.data ) {
		return null;
	}
	const toggle = async () => {
		try {
			const next = (
				await api.post< SafeModeStatus >( '/safe-mode', {
					active: ! status.data.active,
				} )
			).data;
			useSession.getState().setBoot( { ...getBoot(), safeMode: next } );
			status.refetch();
			setConfirm( false );
			toast.success(
				next.active
					? __( 'Safe mode is on.', 'cliezoo' )
					: __( 'Safe mode is off.', 'cliezoo' )
			);
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		}
	};
	return (
		<section className="rounded-lg border border-line bg-raised p-5">
			<div className="flex flex-wrap items-start gap-3">
				<div className="min-w-0 flex-1">
					<h2 className="text-base font-semibold">
						{ __( 'Safe mode', 'cliezoo' ) }
					</h2>
					<p className="mt-1 text-sm text-muted">
						{ __(
							'Pauses emails, webhooks, automations, AI runs and payment calls. It turns on by itself when the site address changes, so a staging copy never contacts real clients.',
							'cliezoo'
						) }
					</p>
				</div>
				<Switch
					isSelected={ status.data.active }
					onChange={ () => setConfirm( true ) }
				>
					<span className="sr-only">
						{ __( 'Safe mode', 'cliezoo' ) }
					</span>
				</Switch>
			</div>
			<Dialog
				isOpen={ confirm }
				onOpenChange={ setConfirm }
				role="alertdialog"
				title={
					status.data.active
						? __( 'Turn off Safe mode?', 'cliezoo' )
						: __( 'Turn on Safe mode?', 'cliezoo' )
				}
				description={
					status.data.active
						? __(
								'Only on the live site. Paused work starts again; emails held meanwhile are discarded.',
								'cliezoo'
							)
						: __(
								'Emails, webhooks, automations, AI runs and payment calls pause until you turn it off.',
								'cliezoo'
							)
				}
				footer={ () => (
					<Button
						variant={ status.data.active ? 'primary' : 'danger' }
						onPress={ toggle }
					>
						{ status.data.active
							? __( 'Turn off', 'cliezoo' )
							: __( 'Turn on', 'cliezoo' ) }
					</Button>
				) }
			/>
		</section>
	);
}

/**
 * The API description, generated from the live endpoints: enough for anyone
 * building against Cliezoo, and it cannot drift from what the server does.
 */
function ApiDocs() {
	const url = restUrl( '/openapi' );
	return (
		<section className="rounded-lg border border-line bg-raised p-4">
			<h3 className="text-sm font-semibold text-fg">
				{ __( 'API', 'cliezoo' ) }
			</h3>
			<p className="mt-1 text-sm text-muted">
				{ __(
					'Everything the app does is available over the REST API, described in OpenAPI form. Sign in with a WordPress application password.',
					'cliezoo'
				) }
			</p>
			<div className="mt-3 flex flex-wrap items-center gap-3">
				<Button
					variant="secondary"
					size="sm"
					onPress={ () => window.open( url, '_blank', 'noopener' ) }
				>
					{ __( 'View API docs', 'cliezoo' ) }
				</Button>
				<code className="min-w-0 flex-1 truncate rounded bg-sunken px-2 py-1 text-xs text-muted">
					{ url }
				</code>
			</div>
		</section>
	);
}

export default function Advanced() {
	const s = useSettings( 'workspace' );
	const v = s.values;
	// Switching this on is a decision about every record in the workspace,
	// so it is asked for rather than toggled. Switching it off is not.
	const [ confirmErase, setConfirmErase ] = useState( false );
	return (
		<div className="space-y-6">
			<AppUrls />
			<PortalDomain />
			<SafeMode />
			<SettingsForm state={ s }>
				<Fieldset title={ __( 'Data', 'cliezoo' ) }>
					<TextField
						label={ __(
							'Empty the trash after (days)',
							'cliezoo'
						) }
						type="number"
						inputMode="numeric"
						value={ String( v.trash_days ?? '' ) }
						onChange={ ( x ) =>
							s.set( 'trash_days', x === '' ? '' : Number( x ) )
						}
						errorMessage={ s.errors.trash_days }
					/>
					<div className="space-y-3 self-center">
						<Switch
							isSelected={ !! v.debug_log }
							onChange={ ( on ) => s.set( 'debug_log', on ) }
						>
							{ __(
								'Keep a debug log (for support)',
								'cliezoo'
							) }
						</Switch>
						<Switch
							isSelected={ !! v.delete_data_on_uninstall }
							onChange={ ( on ) => {
								if ( on ) {
									setConfirmErase( true );
									return;
								}
								s.set( 'delete_data_on_uninstall', false );
							} }
						>
							{ __(
								'Delete all Cliezoo data when the plugin is deleted',
								'cliezoo'
							) }
						</Switch>
					</div>
				</Fieldset>
			</SettingsForm>
			<Dialog
				isOpen={ confirmErase }
				onOpenChange={ setConfirmErase }
				role="alertdialog"
				size="sm"
				title={ __( 'Delete everything on uninstall?', 'cliezoo' ) }
				description={ __(
					'If someone deletes the plugin, every client, project, invoice, file and message Cliezoo holds is removed with it. There is no undo and no export afterwards.',
					'cliezoo'
				) }
				footer={ ( close ) => (
					<>
						<Button variant="ghost" onPress={ close }>
							{ __( 'Cancel', 'cliezoo' ) }
						</Button>
						<Button
							variant="danger"
							onPress={ () => {
								s.set( 'delete_data_on_uninstall', true );
								close();
							} }
						>
							{ __( 'Yes, delete it all', 'cliezoo' ) }
						</Button>
					</>
				) }
			>
				<p className="text-sm text-muted">
					{ __(
						'You still have to save this page for the choice to take effect.',
						'cliezoo'
					) }
				</p>
			</Dialog>
			<ApiDocs />
		</div>
	);
}
