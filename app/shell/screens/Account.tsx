/**
 * Account: your picture, profile, appearance, sign-in methods, security
 * (password, 2FA, passkeys, sessions) and notifications. Other sections
 * are added through the "account.sections" slot.
 */
import { useSearch } from '@tanstack/react-router';
import { __ } from '@wordpress/i18n';
import {
	useSavePreferences,
	usePreferences,
	useUser,
	type ThemePreference,
} from '@cliezoo/data';
import { Avatar, Select, Switch } from '@cliezoo/ui';
import { Slot } from '../../packages/app/slot';
import { Page, PageHeader } from '../Page';
import Security from './account/Security';
import Notifications from './account/Notifications';
import Profile from './account/Profile';
import ProfilePicture from './account/ProfilePicture';
import ConnectedAccounts from './account/ConnectedAccounts';

export default function Account() {
	const user = useUser();
	const search = useSearch( { strict: false } ) as { setup?: string };
	const prefs = usePreferences();
	const save = useSavePreferences();
	if ( ! user ) {
		return null;
	}
	return (
		<>
			<PageHeader
				title={ __( 'Account', 'cliezoo' ) }
				meta={
					<span className="flex items-center gap-2">
						<Avatar
							name={ user.name }
							src={ user.avatar }
							size="xs"
						/>
						{ user.name } · { user.email } · { user.roleLabel }
					</span>
				}
			/>
			<Page width="form" className="space-y-6">
				<section
					aria-labelledby="account-picture"
					className="rounded-lg border border-line bg-raised p-5"
				>
					<h2
						id="account-picture"
						className="text-base font-semibold"
					>
						{ __( 'Your picture', 'cliezoo' ) }
					</h2>
					<p className="mt-1 text-sm text-muted">
						{ __(
							'Shown to your team and clients next to what you do. Kept as a private file.',
							'cliezoo'
						) }
					</p>
					<div className="mt-4">
						<ProfilePicture user={ user } />
					</div>
				</section>
				<Profile uid={ user.uid } />
				<section
					aria-labelledby="account-appearance"
					className="rounded-lg border border-line bg-raised p-5"
				>
					<h2
						id="account-appearance"
						className="text-base font-semibold"
					>
						{ __( 'Appearance', 'cliezoo' ) }
					</h2>
					<div className="mt-4 grid gap-4 sm:grid-cols-2">
						<Select
							label={ __( 'Theme', 'cliezoo' ) }
							selectedKey={ prefs.theme }
							onSelectionChange={ ( key ) =>
								save.mutate( { theme: key as ThemePreference } )
							}
							items={ [
								{
									id: 'system',
									label: __( 'System', 'cliezoo' ),
								},
								{
									id: 'light',
									label: __( 'Light', 'cliezoo' ),
								},
								{ id: 'dark', label: __( 'Dark', 'cliezoo' ) },
							] }
						/>
						<Select
							label={ __( 'Density', 'cliezoo' ) }
							selectedKey={ prefs.density }
							onSelectionChange={ ( key ) => {
								document.body.dataset.density = String( key );
								save.mutate( {
									density: key as 'comfortable' | 'compact',
								} );
							} }
							items={ [
								{
									id: 'comfortable',
									label: __( 'Comfortable', 'cliezoo' ),
								},
								{
									id: 'compact',
									label: __( 'Compact', 'cliezoo' ),
								},
							] }
						/>
					</div>
					<div className="mt-4">
						<Switch
							isSelected={ prefs.reduce_motion }
							onChange={ ( on ) =>
								save.mutate( { reduce_motion: on } )
							}
						>
							{ __( 'Reduce motion', 'cliezoo' ) }
						</Switch>
					</div>
				</section>
				<ConnectedAccounts />
				<Security autoSetup={ search.setup === '2fa' } />
				{ /* A client signs through their own link; countersigning is the studio's. */ }
				<Notifications />
				<Slot name="account.sections" props={ { user } } />
			</Page>
		</>
	);
}
