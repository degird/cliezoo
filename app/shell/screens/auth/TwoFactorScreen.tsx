/**
 * /dash/auth/2fa — the code step on its own address (Blueprint §11.3).
 *
 * The challenge that gets here is held in memory by the sign-in that
 * asked for it. Arriving with nothing held — a refresh, or a bookmark —
 * means starting again, which is what the sign-in screen is for.
 */
import { __ } from '@wordpress/i18n';
import { Button } from '@cliezoo/ui';
import { AuthLayout, appLink } from './AuthLayout';
import { TwoFactorStep, dropChallenge, heldChallenge } from './TwoFactor';

export default function TwoFactorScreen() {
	const held = heldChallenge();

	if ( ! held ) {
		return (
			<AuthLayout
				title={ __( 'Sign in again', 'cliezoo' ) }
				subtitle={ __(
					'This step belongs to a sign-in that is no longer going on.',
					'cliezoo'
				) }
			>
				<Button
					variant="primary"
					className="w-full"
					onPress={ () =>
						window.location.assign( appLink( '/login' ) )
					}
				>
					{ __( 'Back to sign in', 'cliezoo' ) }
				</Button>
			</AuthLayout>
		);
	}

	return (
		<TwoFactorStep
			challenge={ held.challenge }
			next={ held.next }
			onExpired={ () => {
				dropChallenge();
				window.location.assign( appLink( '/login' ) );
			} }
		/>
	);
}
