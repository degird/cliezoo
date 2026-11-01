/**
 * Choose a new password from a reset link.
 */
import { useState, type FormEvent } from 'react';
import { useParams } from '@tanstack/react-router';
import { __ } from '@wordpress/i18n';
import { api, type ApiError } from '@cliezoo/data';
import { Alert, Button, Form, Link, TextField } from '@cliezoo/ui';
import { passwordHint } from './passwordHint';
import { AuthLayout } from './AuthLayout';

export default function ResetConfirm() {
	const { token } = useParams( { strict: false } ) as { token: string };
	const [ password, setPassword ] = useState( '' );
	const [ confirm, setConfirm ] = useState( '' );
	const [ done, setDone ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const mismatch = confirm.length > 0 && confirm !== password;
	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		if ( mismatch ) {
			return;
		}
		setBusy( true );
		setError( '' );
		try {
			await api.post( '/auth/password-reset/confirm', {
				token,
				password,
			} );
			setDone( true );
		} catch ( failure ) {
			setError( ( failure as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};
	if ( done ) {
		return (
			<AuthLayout
				title={ __( 'Password changed', 'cliezoo' ) }
				subtitle={ __(
					'You can now sign in with your new password.',
					'cliezoo'
				) }
			>
				<Link href="/login" variant="primary" className="w-full">
					{ __( 'Sign in', 'cliezoo' ) }
				</Link>
			</AuthLayout>
		);
	}
	return (
		<AuthLayout
			title={ __( 'Choose a new password', 'cliezoo' ) }
			footer={
				<Link href="/login">
					{ __( 'Back to sign in', 'cliezoo' ) }
				</Link>
			}
		>
			<Form onSubmit={ submit } className="space-y-4">
				{ error && <Alert tone="error">{ error }</Alert> }
				<TextField
					label={ __( 'New password', 'cliezoo' ) }
					type="password"
					value={ password }
					onChange={ setPassword }
					isRequired
					autoComplete="new-password"
					description={ passwordHint() }
				/>
				<TextField
					label={ __( 'Confirm new password', 'cliezoo' ) }
					type="password"
					value={ confirm }
					onChange={ setConfirm }
					isRequired
					autoComplete="new-password"
					errorMessage={
						mismatch
							? __( 'The passwords do not match.', 'cliezoo' )
							: undefined
					}
				/>
				<Button
					type="submit"
					variant="primary"
					className="w-full"
					isPending={ busy }
				>
					{ __( 'Save password', 'cliezoo' ) }
				</Button>
			</Form>
		</AuthLayout>
	);
}
