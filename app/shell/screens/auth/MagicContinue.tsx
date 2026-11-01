/**
 * Magic link "Continue" page: the link only signs in after this POST, so
 * email scanners that open links cannot use it up (§31.3).
 */
import { useState } from 'react';
import { useParams } from '@tanstack/react-router';
import { __ } from '@wordpress/i18n';
import { api, type ApiError } from '@cliezoo/data';
import { Alert, Button, Link } from '@cliezoo/ui';
import { AuthLayout } from './AuthLayout';
import {
	TwoFactorStep,
	dropChallenge,
	finish,
	holdChallenge,
	type LoginResult,
} from './TwoFactor';

export default function MagicContinue() {
	const { token } = useParams( { strict: false } ) as { token: string };
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ asking, setAsking ] = useState< LoginResult | null >( null );
	const go = async () => {
		setBusy( true );
		setError( '' );
		try {
			const result = (
				await api.post< LoginResult >( '/auth/magic/consume', {
					token,
				} )
			).data;
			// An account with two-step verification on is not signed in
			// yet: the link hands back a challenge, and the code finishes
			// what it started.
			if ( result.two_factor_required ) {
				holdChallenge( result.challenge ?? '', result.next );
				setAsking( result );
				return;
			}
			finish( result );
		} catch ( failure ) {
			setError( ( failure as ApiError ).message );
			setBusy( false );
		}
	};

	if ( asking ) {
		return (
			<TwoFactorStep
				challenge={ asking.challenge ?? '' }
				next={ asking.next }
				onExpired={ () => {
					dropChallenge();
					setAsking( null );
					setBusy( false );
					setError(
						__(
							'That sign-in link took too long. Ask for a new one.',
							'cliezoo'
						)
					);
				} }
			/>
		);
	}
	return (
		<AuthLayout
			title={ __( 'Continue to sign in', 'cliezoo' ) }
			subtitle={ __(
				'Press the button to finish signing in on this device.',
				'cliezoo'
			) }
			footer={
				<Link href="/login">
					{ __( 'Back to sign in', 'cliezoo' ) }
				</Link>
			}
		>
			{ error && (
				<div className="mb-4">
					<Alert tone="error">{ error }</Alert>
				</div>
			) }
			<Button
				variant="primary"
				className="w-full"
				isPending={ busy }
				onPress={ go }
			>
				{ __( 'Continue', 'cliezoo' ) }
			</Button>
		</AuthLayout>
	);
}
