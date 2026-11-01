/**
 * The two-step verification step, and what happens once a sign-in is
 * accepted. Every way in — a password, a passkey, an emailed sign-in
 * link — ends here when the account asks for a code, so the step is
 * written once.
 */
import { useState, type FormEvent } from 'react';
import { __ } from '@wordpress/i18n';
import { api, type ApiError } from '@cliezoo/data';
import { Alert, Button, Form, TextField } from '@cliezoo/ui';
import { AuthLayout, afterLoginUrl, appLink } from './AuthLayout';

export interface LoginResult {
	logged_in?: boolean;
	two_factor_required?: boolean;
	two_factor_enroll?: boolean;
	challenge?: string;
	passkeys?: boolean;
	redirect?: string;
	/** Where the emailed link was going, when it carried somewhere. */
	next?: string;
}

/**
 * Leaves for the app. A full load gives it a fresh REST nonce and boot
 * data for the new session.
 *
 * @param result What the server answered.
 */
export function finish( result: LoginResult ): void {
	window.location.assign(
		result.two_factor_enroll
			? appLink( '/account?setup=2fa' )
			: afterLoginUrl( result.redirect || result.next )
	);
}

/**
 * The challenge the sign-in handed back, while the code is being asked
 * for. It lives in memory only: a challenge in the address would be a
 * key to a session kept in a history, a log and a referrer, and one in
 * storage would outlive the moment it is for. A refresh loses it, which
 * is what starting again means.
 */
let waiting: { challenge: string; next?: string } | null = null;

/**
 * Holds the challenge for the step that is about to open.
 *
 * @param challenge What the sign-in handed back.
 * @param next      Where to go once the code is accepted.
 */
export function holdChallenge( challenge: string, next?: string ): void {
	waiting = { challenge, next };
}

/**
 * Takes the held challenge, if there is one.
 */
export function heldChallenge(): { challenge: string; next?: string } | null {
	return waiting;
}

/**
 * Forgets it, once the step is done with.
 */
export function dropChallenge(): void {
	waiting = null;
}

/**
 * Asks for the code that finishes a sign-in.
 *
 * @param root0           Props.
 * @param root0.challenge The challenge the sign-in handed back.
 * @param root0.next      Where to go afterwards, when the caller knows.
 * @param root0.onExpired Called when the challenge is too old to use.
 */
export function TwoFactorStep( {
	challenge,
	next,
	onExpired,
}: {
	challenge: string;
	next?: string;
	onExpired?: () => void;
} ) {
	const [ code, setCode ] = useState( '' );
	const [ recovery, setRecovery ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setBusy( true );
		setError( '' );
		try {
			const result = (
				await api.post< LoginResult >(
					'/auth/2fa',
					recovery
						? { challenge, recovery_code: code }
						: { challenge, code }
				)
			).data;
			finish( { next, ...result } );
		} catch ( failure ) {
			const problem = failure as ApiError;
			setError(
				problem.message ||
					__( 'Something went wrong. Please try again.', 'cliezoo' )
			);
			if ( 'cliezoo_challenge_expired' === problem.code ) {
				onExpired?.();
			}
			setBusy( false );
		}
	};

	return (
		<AuthLayout
			title={ __( 'Two-step verification', 'cliezoo' ) }
			subtitle={
				recovery
					? __( 'Enter one of your recovery codes.', 'cliezoo' )
					: __(
							'Enter the 6-digit code from your authenticator app.',
							'cliezoo'
						)
			}
		>
			<Form onSubmit={ submit } className="space-y-4">
				{ error && <Alert tone="error">{ error }</Alert> }
				<TextField
					label={
						recovery
							? __( 'Recovery code', 'cliezoo' )
							: __( 'Code', 'cliezoo' )
					}
					value={ code }
					onChange={ setCode }
					// eslint-disable-next-line jsx-a11y/no-autofocus -- Focus follows the step the user just chose.
					autoFocus
					isRequired
					autoComplete="one-time-code"
					inputMode={ recovery ? 'text' : 'numeric' }
				/>
				<Button
					type="submit"
					variant="primary"
					className="w-full"
					isPending={ busy }
				>
					{ __( 'Verify', 'cliezoo' ) }
				</Button>
				<Button
					variant="link"
					onPress={ () => {
						setRecovery( ! recovery );
						setCode( '' );
					} }
				>
					{ recovery
						? __( 'Use an authenticator code', 'cliezoo' )
						: __( 'Use a recovery code', 'cliezoo' ) }
				</Button>
			</Form>
		</AuthLayout>
	);
}
