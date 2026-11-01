/**
 * Settings → Security: password rules, required 2FA per role, API access
 * per role, sign-in length, magic links and new sign-in alerts.
 */
import { __ } from '@wordpress/i18n';
import { Checkbox, Switch, TextField } from '@cliezoo/ui';
import { Fieldset, SettingsForm, useSettings } from '../useSettings';

const ROLES = (): Array< [ string, string ] > => [
	[ 'owner', __( 'Owner', 'cliezoo' ) ],
	[ 'admin', __( 'Admin', 'cliezoo' ) ],
	[ 'member', __( 'Member', 'cliezoo' ) ],
	[ 'project_manager', __( 'Project manager', 'cliezoo' ) ],
	[ 'sales', __( 'Sales', 'cliezoo' ) ],
	[ 'finance', __( 'Finance', 'cliezoo' ) ],
	[ 'contractor', __( 'Contractor', 'cliezoo' ) ],
	[ 'readonly', __( 'Read-only', 'cliezoo' ) ],
	[ 'client_admin', __( 'Client admin', 'cliezoo' ) ],
	[ 'client_member', __( 'Client member', 'cliezoo' ) ],
	[ 'client_viewer', __( 'Client viewer', 'cliezoo' ) ],
];

export default function Security() {
	const s = useSettings( 'security' );
	const v = s.values;
	const required = ( v.require_2fa_roles as string[] ) ?? [];
	// Roles given the API on its own (the "API and webhooks" permission).
	const api = ( v.api_roles as string[] ) ?? [];
	const number = ( key: string, value: string ) =>
		s.set( key, value === '' ? '' : Number( value ) );
	return (
		<SettingsForm state={ s }>
			<Fieldset title={ __( 'Passwords', 'cliezoo' ) }>
				<TextField
					label={ __( 'Minimum length', 'cliezoo' ) }
					type="number"
					inputMode="numeric"
					value={ String( v.password_min_length ?? '' ) }
					onChange={ ( x ) => number( 'password_min_length', x ) }
					errorMessage={ s.errors.password_min_length }
					description={ __(
						'Between 8 and 128 characters.',
						'cliezoo'
					) }
				/>
				<Switch
					className="self-center"
					isSelected={ !! v.password_require_mix }
					onChange={ ( on ) => s.set( 'password_require_mix', on ) }
				>
					{ __( 'Require letters and numbers', 'cliezoo' ) }
				</Switch>
			</Fieldset>
			<section className="rounded-lg border border-line bg-raised p-5">
				<h2 className="text-base font-semibold">
					{ __( 'Two-step verification', 'cliezoo' ) }
				</h2>
				<p className="mt-1 text-sm text-muted">
					{ __(
						'People with these roles must set up an authenticator app or a passkey before they can use the workspace.',
						'cliezoo'
					) }
				</p>
				<div className="mt-4 grid gap-2 sm:grid-cols-3">
					{ ROLES().map( ( [ role, label ] ) => (
						<Checkbox
							key={ role }
							isSelected={ required.includes( role ) }
							onChange={ ( on ) =>
								s.set(
									'require_2fa_roles',
									on
										? [ ...required, role ]
										: required.filter( ( r ) => r !== role )
								)
							}
						>
							{ label }
						</Checkbox>
					) ) }
				</div>
			</section>
			<section className="rounded-lg border border-line bg-raised p-5">
				<h2 className="text-base font-semibold">
					{ __( 'API access', 'cliezoo' ) }
				</h2>
				<p className="mt-1 text-sm text-muted">
					{ __(
						'Owners and admins can always use the Cliezoo API with a WordPress application password. Tick a role to let it use the API too — it gives that role nothing else, and what it can see and change stays what the role allows.',
						'cliezoo'
					) }
				</p>
				<div className="mt-4 grid gap-2 sm:grid-cols-3">
					{ ROLES()
						.filter(
							( [ role ] ) =>
								! role.startsWith( 'client_' ) &&
								! [ 'owner', 'admin' ].includes( role )
						)
						.map( ( [ role, label ] ) => (
							<Checkbox
								key={ role }
								isSelected={ api.includes( role ) }
								onChange={ ( on ) =>
									s.set(
										'api_roles',
										on
											? [ ...api, role ]
											: api.filter( ( r ) => r !== role )
									)
								}
							>
								{ label }
							</Checkbox>
						) ) }
				</div>
			</section>
			<Fieldset title={ __( 'Signing in', 'cliezoo' ) }>
				<TextField
					label={ __( 'Stay signed in for (days)', 'cliezoo' ) }
					type="number"
					inputMode="numeric"
					value={ String( v.session_days ?? '' ) }
					onChange={ ( x ) => number( 'session_days', x ) }
					errorMessage={ s.errors.session_days }
					description={ __(
						'The longest a sign-in lasts, with or without “Keep me signed in”.',
						'cliezoo'
					) }
				/>
				<div className="space-y-3 self-center">
					<Switch
						isSelected={ !! v.magic_links }
						onChange={ ( on ) => s.set( 'magic_links', on ) }
					>
						{ __( 'Allow sign-in links by email', 'cliezoo' ) }
					</Switch>
					<Switch
						isSelected={ !! v.new_login_alerts }
						onChange={ ( on ) => s.set( 'new_login_alerts', on ) }
					>
						{ __(
							'Email people when they sign in on a new device',
							'cliezoo'
						) }
					</Switch>
				</div>
			</Fieldset>
		</SettingsForm>
	);
}
