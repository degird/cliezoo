/**
 * How a contact signs in: two-step verification, passkeys, where they are
 * signed in and the last sign-ins — for whoever manages people, on the
 * contact's own page.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ShieldCheck, ShieldAlert, ShieldOff } from 'lucide-react';
import {
	api,
	formatRelative,
	getBoot,
	useQuery,
	type ApiError,
} from '@cliezoo/data';
import {
	Badge,
	Button,
	Dialog,
	ErrorState,
	Skeleton,
	toast,
} from '@cliezoo/ui';

interface Security {
	two_factor: boolean;
	passkeys: number;
	sessions: number;
	last_login_at: string | null;
}

interface SignIn {
	event: string;
	status: string;
	ip: string;
	device: string;
	created_at: string;
}

const EVENTS = (): Record< string, string > => ( {
	login: __( 'Signed in', 'cliezoo' ),
	login_failed: __( 'Failed', 'cliezoo' ),
	logout: __( 'Signed out', 'cliezoo' ),
} );

export function SignInsPanel( { member }: { member: string } ) {
	const [ resetting, setResetting ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const user = getBoot().user;
	const allowed =
		!! user &&
		( user.admin || !! user.permissions?.toggles?.settings_members );
	const security = useQuery( {
		queryKey: [ 'members', member, 'security' ],
		enabled: allowed,
		queryFn: async () =>
			( await api.get< Security >( `/members/${ member }/security` ) )
				.data,
	} );
	const history = useQuery( {
		queryKey: [ 'members', member, 'sign-ins' ],
		enabled: allowed,
		queryFn: async () =>
			(
				await api.get< SignIn[] >( '/auth/login-history', {
					member,
					limit: 5,
				} )
			).data,
	} );
	if ( ! allowed ) {
		return null;
	}

	return (
		<section
			aria-labelledby="contact-sign-ins"
			className="rounded-lg border border-line bg-raised p-4"
		>
			<h2
				id="contact-sign-ins"
				className="mb-3 text-sm font-semibold text-fg"
			>
				{ __( 'Signing in', 'cliezoo' ) }
			</h2>
			{ security.isPending && <Skeleton className="h-16" /> }
			{ /* Blank under "Signing in" reads as "nothing to say". Whether
			     two-step is on is exactly what this panel is for, so a
			     failed ask says so and offers another go. */ }
			{ security.isError && (
				<ErrorState
					error={ security.error }
					title={ __(
						'How they sign in could not be loaded.',
						'cliezoo'
					) }
					onRetry={ () => security.refetch() }
				/>
			) }
			{ !! security.data && (
				<div className="space-y-1.5 text-sm">
					<p className="flex items-center gap-2">
						{ security.data.two_factor ? (
							<ShieldCheck
								size={ 16 }
								className="text-success"
								aria-hidden="true"
							/>
						) : (
							<ShieldAlert
								size={ 16 }
								className="text-warning"
								aria-hidden="true"
							/>
						) }
						{ security.data.two_factor
							? __( 'Two-step verification is on', 'cliezoo' )
							: __( 'Two-step verification is off', 'cliezoo' ) }
					</p>
					<p className="text-muted">
						{ [
							sprintf(
								/* translators: %d: how many passkeys. */
								_n(
									'%d passkey',
									'%d passkeys',
									security.data.passkeys,
									'cliezoo'
								),
								security.data.passkeys
							),
							sprintf(
								/* translators: %d: how many places. */
								_n(
									'signed in on %d device',
									'signed in on %d devices',
									security.data.sessions,
									'cliezoo'
								),
								security.data.sessions
							),
						].join( ' · ' ) }
					</p>
					{ security.data.two_factor && !! user?.admin && (
						<Button
							className="mt-2"
							size="sm"
							variant="danger"
							icon={
								<ShieldOff size={ 14 } aria-hidden="true" />
							}
							onPress={ () => setResetting( true ) }
						>
							{ __( 'Turn their two-step off', 'cliezoo' ) }
						</Button>
					) }
				</div>
			) }
			{ resetting && (
				<Dialog
					isOpen
					onOpenChange={ ( open: boolean ) =>
						! open && setResetting( false )
					}
					title={ __( 'Turn their two-step off', 'cliezoo' ) }
					footer={ ( close ) => (
						<>
							<Button onPress={ close }>
								{ __( 'Leave it on', 'cliezoo' ) }
							</Button>
							<Button
								variant="danger"
								isDisabled={ busy }
								onPress={ async () => {
									setBusy( true );
									try {
										await api.post( '/auth/2fa/reset', {
											member,
										} );
										toast.success(
											__(
												'Turned off. They can set it up again when they sign in.',
												'cliezoo'
											)
										);
										setResetting( false );
										security.refetch();
									} catch ( error ) {
										toast.error(
											( error as ApiError ).message
										);
									} finally {
										setBusy( false );
									}
								} }
							>
								{ __( 'Turn it off', 'cliezoo' ) }
							</Button>
						</>
					) }
				>
					<p className="text-sm text-fg">
						{ __(
							'This is what to do for somebody who has lost the phone their codes were on: it turns their two-step verification off so they can sign in with their password and set it up again.',
							'cliezoo'
						) }
					</p>
					<p className="mt-2 text-sm text-muted">
						{ __(
							'It is written in the audit log with your name on it, because for as long as it is off their password is the only thing standing in the way.',
							'cliezoo'
						) }
					</p>
				</Dialog>
			) }
			{ history.isError && (
				<ErrorState
					error={ history.error }
					title={ __(
						'Their recent sign-ins could not be loaded.',
						'cliezoo'
					) }
					onRetry={ () => history.refetch() }
				/>
			) }
			{ !! history.data?.length && (
				<ul className="mt-3 space-y-1.5 border-t border-line pt-3">
					{ history.data.map( ( one, index ) => (
						<li
							key={ `${ one.created_at }:${ index }` }
							className="flex flex-wrap items-center gap-2 text-xs"
						>
							<Badge
								tone={
									'login_failed' === one.event
										? 'warning'
										: 'neutral'
								}
							>
								{ EVENTS()[ one.event ] ?? EVENTS().login }
							</Badge>
							<span className="text-muted">
								{ [ one.device, one.ip ]
									.filter( Boolean )
									.join( ' · ' ) }
							</span>
							<span className="ms-auto text-subtle">
								{ formatRelative( one.created_at ) }
							</span>
						</li>
					) ) }
				</ul>
			) }
		</section>
	);
}
