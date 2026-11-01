/**
 * Sign in: password (+ 2FA step), passkey, or an emailed sign-in link.
 */
import { useState, type FormEvent } from 'react';
import { __ } from '@wordpress/i18n';
import { KeyRound, Mail } from 'lucide-react';
import { api, type ApiError } from '@cliezoo/data';
import { Alert, Button, Checkbox, Form, Link, TextField } from '@cliezoo/ui';
import { AuthLayout } from './AuthLayout';
import {
	TwoFactorStep,
	dropChallenge,
	finish,
	holdChallenge,
	type LoginResult,
} from './TwoFactor';

async function passkeyLogin( login: string ): Promise< LoginResult > {
	const { startAuthentication } = await import(
		/* webpackChunkName: "webauthn" */ '@simplewebauthn/browser'
	);
	const { options, challenge } = (
		await api.post< {
			options: { publicKey: Record< string, unknown > };
			challenge: string;
		} >( '/auth/passkey/login-options', { login } )
	).data;
	const credential = await startAuthentication( {
		optionsJSON: options.publicKey as never,
	} );
	return (
		await api.post< LoginResult >( '/auth/passkey/login', {
			challenge,
			id: credential.id,
			clientDataJSON: credential.response.clientDataJSON,
			authenticatorData: credential.response.authenticatorData,
			signature: credential.response.signature,
			userHandle: credential.response.userHandle ?? '',
			remember: true,
		} )
	).data;
}

export default function Login() {
	const [ step, setStep ] = useState<
		'password' | '2fa' | 'magic' | 'magic-sent'
	>( 'password' );
	const [ login, setLogin ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ remember, setRemember ] = useState( true );
	const [ challenge, setChallenge ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const run = async ( fn: () => Promise< void > ) => {
		setBusy( true );
		setError( '' );
		try {
			await fn();
		} catch ( failure ) {
			const apiError = failure as ApiError & { name?: string };
			if (
				apiError.name === 'NotAllowedError' ||
				apiError.name === 'AbortError'
			) {
				setError(
					__( 'The passkey request was cancelled.', 'cliezoo' )
				);
			} else {
				setError(
					apiError.message ||
						__(
							'Something went wrong. Please try again.',
							'cliezoo'
						)
				);
			}
		} finally {
			setBusy( false );
		}
	};

	const submitPassword = ( event: FormEvent ) => {
		event.preventDefault();
		run( async () => {
			const result = (
				await api.post< LoginResult >( '/auth/login', {
					login,
					password,
					remember,
				} )
			).data;
			if ( result.two_factor_required ) {
				// The step has an address of its own; the challenge is
				// handed to it in memory rather than in the address.
				holdChallenge( result.challenge ?? '' );
				setChallenge( result.challenge ?? '' );
				setStep( '2fa' );
				return;
			}
			finish( result );
		} );
	};

	const submitMagic = ( event: FormEvent ) => {
		event.preventDefault();
		run( async () => {
			await api.post( '/auth/magic', {
				email: login,
				redirect:
					new URLSearchParams( window.location.search ).get(
						'redirect'
					) ?? '',
			} );
			setStep( 'magic-sent' );
		} );
	};

	if ( step === '2fa' ) {
		return (
			<TwoFactorStep
				challenge={ challenge }
				onExpired={ () => {
					dropChallenge();
					setStep( 'password' );
				} }
			/>
		);
	}

	if ( step === 'magic' || step === 'magic-sent' ) {
		return (
			<AuthLayout
				title={
					step === 'magic-sent'
						? __( 'Check your email', 'cliezoo' )
						: __( 'Email me a sign-in link', 'cliezoo' )
				}
				subtitle={
					step === 'magic-sent'
						? __(
								'If an account exists for this address, a sign-in link is on its way. It works once and expires in 15 minutes.',
								'cliezoo'
							)
						: undefined
				}
				footer={
					<Button
						variant="link"
						onPress={ () => setStep( 'password' ) }
					>
						{ __( 'Back to sign in', 'cliezoo' ) }
					</Button>
				}
			>
				{ step === 'magic' && (
					<Form onSubmit={ submitMagic } className="space-y-4">
						{ error && <Alert tone="error">{ error }</Alert> }
						<TextField
							label={ __( 'Email', 'cliezoo' ) }
							type="email"
							value={ login }
							onChange={ setLogin }
							// eslint-disable-next-line jsx-a11y/no-autofocus -- Focus follows the step the user just chose.
							autoFocus
							isRequired
							autoComplete="email"
						/>
						<Button
							type="submit"
							variant="primary"
							className="w-full"
							isPending={ busy }
							icon={ <Mail size={ 16 } aria-hidden="true" /> }
						>
							{ __( 'Send link', 'cliezoo' ) }
						</Button>
					</Form>
				) }
			</AuthLayout>
		);
	}

	return (
		<AuthLayout title={ __( 'Sign in', 'cliezoo' ) }>
			<Form onSubmit={ submitPassword } className="space-y-4">
				{ error && <Alert tone="error">{ error }</Alert> }
				<TextField
					label={ __( 'Email or username', 'cliezoo' ) }
					value={ login }
					onChange={ setLogin }
					isRequired
					autoComplete="username webauthn"
				/>
				<TextField
					label={ __( 'Password', 'cliezoo' ) }
					type="password"
					value={ password }
					onChange={ setPassword }
					isRequired
					autoComplete="current-password"
				/>
				<div className="flex items-center justify-between">
					<Checkbox isSelected={ remember } onChange={ setRemember }>
						{ __( 'Keep me signed in', 'cliezoo' ) }
					</Checkbox>
					<Link href="/auth/reset" className="text-sm">
						{ __( 'Forgot password?', 'cliezoo' ) }
					</Link>
				</div>
				<Button
					type="submit"
					variant="primary"
					className="w-full"
					isPending={ busy }
				>
					{ __( 'Sign in', 'cliezoo' ) }
				</Button>
			</Form>
			<div className="my-5 flex items-center gap-3 text-xs text-subtle">
				<span className="h-px flex-1 bg-line" />
				{ __( 'or', 'cliezoo' ) }
				<span className="h-px flex-1 bg-line" />
			</div>
			<div className="space-y-2">
				{ typeof window !== 'undefined' &&
					'PublicKeyCredential' in window && (
						<Button
							className="w-full"
							icon={ <KeyRound size={ 16 } aria-hidden="true" /> }
							isDisabled={ busy }
							onPress={ () =>
								run( async () =>
									finish( await passkeyLogin( login ) )
								)
							}
						>
							{ __( 'Sign in with a passkey', 'cliezoo' ) }
						</Button>
					) }
				<Button
					className="w-full"
					icon={ <Mail size={ 16 } aria-hidden="true" /> }
					isDisabled={ busy }
					onPress={ () => setStep( 'magic' ) }
				>
					{ __( 'Email me a sign-in link', 'cliezoo' ) }
				</Button>
			</div>
		</AuthLayout>
	);
}
