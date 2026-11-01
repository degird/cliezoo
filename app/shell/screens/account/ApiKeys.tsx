/**
 * Account → Security → API keys: the keys a person uses the Cliezoo API
 * with. A key is a WordPress application password; its secret is shown
 * once, when it is made. A sandbox key gets real answers and keeps nothing.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { KeyRound } from 'lucide-react';
import {
	api,
	formatRelative,
	useQuery,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import {
	Alert,
	Badge,
	Button,
	Checkbox,
	Dialog,
	ErrorState,
	Skeleton,
	TextField,
	toast,
} from '@cliezoo/ui';
import { copyText } from '../../../packages/app/copy';

interface Key {
	uuid: string;
	name: string;
	sandbox: boolean;
	created: string;
	last_used: string | null;
	last_ip: string;
}

interface Keys {
	keys: Key[];
	available: boolean;
	username: string;
	root: string;
}

interface Made {
	uuid: string;
	name: string;
	sandbox: boolean;
	secret: string;
}

export default function ApiKeys() {
	const client = useQueryClient();
	const list = useQuery( {
		queryKey: [ 'api-keys' ],
		queryFn: async () => ( await api.get< Keys >( '/auth/api-keys' ) ).data,
	} );
	const [ name, setName ] = useState( '' );
	const [ sandbox, setSandbox ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ made, setMade ] = useState< Made | null >( null );
	const [ revoking, setRevoking ] = useState< Key | null >( null );
	const refresh = () =>
		client.invalidateQueries( { queryKey: [ 'api-keys' ] } );

	const create = async () => {
		setBusy( true );
		try {
			setMade(
				(
					await api.post< Made >( '/auth/api-keys', {
						name,
						sandbox,
					} )
				).data
			);
			setName( '' );
			setSandbox( false );
			refresh();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	const revoke = async ( key: Key ) => {
		try {
			await api.post( '/auth/api-keys/revoke', { uuid: key.uuid } );
			toast.success( __( 'Revoked.', 'cliezoo' ) );
			refresh();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	return (
		<section
			id="security-api-keys"
			aria-labelledby="security-api-keys-title"
			className="scroll-mt-20 rounded-lg border border-line bg-raised p-5"
		>
			<h2
				id="security-api-keys-title"
				className="text-base font-semibold"
			>
				{ __( 'API keys', 'cliezoo' ) }
			</h2>
			<p className="mt-1 text-sm text-muted">
				{ __(
					'For a script, Zapier or another app to work with your leads, clients, contacts and files. A key acts as you, with what your role allows — no more — and it can never change passwords, people, roles or settings: those stay in the app. Its secret is shown once.',
					'cliezoo'
				) }
			</p>

			{ list.isPending && <Skeleton className="mt-4 h-10" /> }
			{ list.isError && (
				<ErrorState
					error={ list.error }
					onRetry={ () => list.refetch() }
				/>
			) }
			{ !! list.data && ! list.data.available && (
				<div className="mt-4">
					<Alert tone="warning">
						{ __(
							'This site does not allow application passwords, so no key can be made. A site administrator can allow them; they need HTTPS.',
							'cliezoo'
						) }
					</Alert>
				</div>
			) }
			{ !! list.data?.keys.length && (
				<ul className="mt-4 divide-y divide-line">
					{ list.data.keys.map( ( key ) => (
						<li
							key={ key.uuid }
							className="flex flex-wrap items-center gap-3 py-2"
						>
							<span className="min-w-0 flex-1 text-sm">
								<span className="font-medium text-fg">
									{ key.name.replace( /^Cliezoo: /, '' ) }
								</span>
								{ key.sandbox && (
									<Badge tone="warning" className="ms-2">
										{ __( 'sandbox', 'cliezoo' ) }
									</Badge>
								) }
								<span className="block text-xs text-muted">
									{ key.last_used
										? sprintf(
												/* translators: 1: when, 2: an IP address. */
												__(
													'Last used %1$s from %2$s',
													'cliezoo'
												),
												formatRelative( key.last_used ),
												key.last_ip
											)
										: __( 'Never used', 'cliezoo' ) }
								</span>
							</span>
							<Button
								size="sm"
								variant="ghost"
								onPress={ () => setRevoking( key ) }
							>
								{ __( 'Revoke', 'cliezoo' ) }
							</Button>
						</li>
					) ) }
				</ul>
			) }

			{ !! list.data?.available && (
				<div className="mt-4 flex flex-wrap items-end gap-3">
					<TextField
						className="min-w-48 flex-1"
						label={ __( 'What it is for', 'cliezoo' ) }
						value={ name }
						onChange={ setName }
					/>
					<Checkbox isSelected={ sandbox } onChange={ setSandbox }>
						{ __(
							'Sandbox: real answers, nothing kept',
							'cliezoo'
						) }
					</Checkbox>
					<Button
						icon={ <KeyRound size={ 16 } aria-hidden="true" /> }
						isPending={ busy }
						isDisabled={ '' === name.trim() }
						onPress={ create }
					>
						{ __( 'Make a key', 'cliezoo' ) }
					</Button>
				</div>
			) }

			<Dialog
				isOpen={ !! made }
				isDismissable={ false }
				onOpenChange={ () => setMade( null ) }
				title={ __( 'Your new key', 'cliezoo' ) }
				description={ __(
					'Copy the secret now: it is not shown again. Sign requests with your username and this secret (HTTP Basic authentication).',
					'cliezoo'
				) }
				footer={ () => (
					<>
						<Button
							onPress={ () =>
								copyText(
									made?.secret ?? '',
									__( 'Secret copied.', 'cliezoo' )
								)
							}
						>
							{ __( 'Copy the secret', 'cliezoo' ) }
						</Button>
						<Button
							variant="primary"
							onPress={ () => setMade( null ) }
						>
							{ __( 'Done', 'cliezoo' ) }
						</Button>
					</>
				) }
			>
				<dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
					<dt className="text-muted">
						{ __( 'Username', 'cliezoo' ) }
					</dt>
					<dd className="font-mono">{ list.data?.username }</dd>
					<dt className="text-muted">
						{ __( 'Secret', 'cliezoo' ) }
					</dt>
					<dd className="break-all font-mono">{ made?.secret }</dd>
					<dt className="text-muted">
						{ __( 'Address', 'cliezoo' ) }
					</dt>
					<dd className="break-all font-mono">{ list.data?.root }</dd>
				</dl>
				{ !! made?.sandbox && (
					<p className="mt-3 text-sm text-muted">
						{ __(
							'A sandbox key reads for real. What it changes runs in full and is then undone; no email is sent and nothing leaves the site. Answers carry the header X-Cliezoo-Sandbox.',
							'cliezoo'
						) }
					</p>
				) }
			</Dialog>

			<Dialog
				isOpen={ !! revoking }
				onOpenChange={ () => setRevoking( null ) }
				title={ __( 'Revoke this key?', 'cliezoo' ) }
				description={ __(
					'Anything using it stops working at once. This cannot be undone.',
					'cliezoo'
				) }
				footer={ () => (
					<Button
						variant="danger"
						onPress={ () => {
							if ( revoking ) {
								revoke( revoking );
							}
							setRevoking( null );
						} }
					>
						{ __( 'Revoke', 'cliezoo' ) }
					</Button>
				) }
			>
				{ null }
			</Dialog>
		</section>
	);
}
