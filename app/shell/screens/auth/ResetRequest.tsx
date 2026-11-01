/**
 * Request a password reset email (same answer whether or not the account exists).
 */
import { useState, type FormEvent } from 'react';
import { __ } from '@wordpress/i18n';
import { api, type ApiError } from '@cliezoo/data';
import { Alert, Button, Form, Link, TextField } from '@cliezoo/ui';
import { AuthLayout } from './AuthLayout';

export default function ResetRequest() {
	const [ email, setEmail ] = useState( '' );
	const [ sent, setSent ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setBusy( true );
		setError( '' );
		try {
			await api.post( '/auth/password-reset', { email } );
			setSent( true );
		} catch ( failure ) {
			setError( ( failure as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};
	return (
		<AuthLayout
			title={
				sent
					? __( 'Check your email', 'cliezoo' )
					: __( 'Reset your password', 'cliezoo' )
			}
			subtitle={
				sent
					? __(
							'If an account exists for this address, we sent a link to choose a new password. It expires in one hour.',
							'cliezoo'
						)
					: __(
							'Enter your email and we will send you a reset link.',
							'cliezoo'
						)
			}
			footer={
				<Link href="/login">
					{ __( 'Back to sign in', 'cliezoo' ) }
				</Link>
			}
		>
			{ ! sent && (
				<Form onSubmit={ submit } className="space-y-4">
					{ error && <Alert tone="error">{ error }</Alert> }
					<TextField
						label={ __( 'Email', 'cliezoo' ) }
						type="email"
						value={ email }
						onChange={ setEmail }
						isRequired
						autoComplete="email"
					/>
					<Button
						type="submit"
						variant="primary"
						className="w-full"
						isPending={ busy }
					>
						{ __( 'Send reset link', 'cliezoo' ) }
					</Button>
				</Form>
			) }
		</AuthLayout>
	);
}
