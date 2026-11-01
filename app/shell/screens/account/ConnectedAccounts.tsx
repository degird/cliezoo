/**
 * Account → Sign-in methods and connected accounts: what can sign you in
 * today. Free signs people in with this site's own accounts only —
 * password, two-step verification, passkeys and (when the workspace allows
 * it) emailed sign-in links — so the list says that plainly instead of
 * offering buttons for services that are not there.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { KeyRound } from 'lucide-react';
import { api, getBoot, useQuery } from '@cliezoo/data';
import { Badge, Skeleton } from '@cliezoo/ui';

interface Summary {
	two_factor: boolean;
	passkeys: number;
}

export default function ConnectedAccounts() {
	// The same query the Security section reads, so this costs no request.
	const summary = useQuery( {
		queryKey: [ 'security' ],
		queryFn: async () =>
			( await api.get< Summary >( '/auth/security' ) ).data,
	} );
	const rows: Array< { label: string; state: string; on: boolean } > =
		summary.data
			? [
					{
						label: __( 'Password', 'cliezoo' ),
						state: __( 'Set', 'cliezoo' ),
						on: true,
					},
					{
						label: __( 'Two-step verification', 'cliezoo' ),
						state: summary.data.two_factor
							? __( 'On', 'cliezoo' )
							: __( 'Off', 'cliezoo' ),
						on: summary.data.two_factor,
					},
					{
						label: __( 'Passkeys', 'cliezoo' ),
						state: sprintf(
							/* translators: %d: how many passkeys. */
							_n(
								'%d passkey',
								'%d passkeys',
								summary.data.passkeys,
								'cliezoo'
							),
							summary.data.passkeys
						),
						on: summary.data.passkeys > 0,
					},
					{
						label: __( 'Sign-in links by email', 'cliezoo' ),
						state: getBoot().security.magicLinks
							? __( 'Allowed', 'cliezoo' )
							: __( 'Turned off', 'cliezoo' ),
						on: getBoot().security.magicLinks,
					},
				]
			: [];
	return (
		<section
			aria-labelledby="account-connected"
			className="rounded-lg border border-line bg-raised p-5"
		>
			<h2
				id="account-connected"
				className="flex items-center gap-2 text-base font-semibold"
			>
				<KeyRound
					size={ 18 }
					className="text-accent-fg"
					aria-hidden="true"
				/>
				{ __( 'Sign-in methods and connected accounts', 'cliezoo' ) }
			</h2>
			{ summary.isPending && <Skeleton className="mt-4 h-24" /> }
			{ rows.length > 0 && (
				<ul className="mt-4 divide-y divide-line">
					{ rows.map( ( row ) => (
						<li
							key={ row.label }
							className="flex items-center justify-between py-2 text-sm"
						>
							<span>{ row.label }</span>
							<Badge tone={ row.on ? 'success' : 'neutral' }>
								{ row.state }
							</Badge>
						</li>
					) ) }
				</ul>
			) }
			<p className="mt-3 text-sm text-muted">
				{ __(
					'No outside accounts are connected. You sign in with this site’s own account; change these under Security below.',
					'cliezoo'
				) }
			</p>
		</section>
	);
}
