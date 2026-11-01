/**
 * Accept an invitation: confirm your name, choose a password when you are
 * new to the site, then you are signed in.
 */
import { useEffect, useState, type FormEvent } from 'react';
import { useParams } from '@tanstack/react-router';
import { __, sprintf } from '@wordpress/i18n';
import { api, type ApiError } from '@cliezoo/data';
import { Alert, Button, Form, Link, Skeleton, TextField } from '@cliezoo/ui';
import { passwordHint } from './passwordHint';
import { AuthLayout, appLink } from './AuthLayout';

interface InviteInfo {
	workspace: string;
	email: string;
	name: string;
	needs_password: boolean;
	kind: 'staff' | 'client';
}

export default function AcceptInvite() {
	const { token } = useParams( { strict: false } ) as { token: string };
	const [ info, setInfo ] = useState< InviteInfo | null >( null );
	const [ failed, setFailed ] = useState( '' );
	const [ name, setName ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ fields, setFields ] = useState< Record< string, string > >( {} );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		api.post< InviteInfo >( '/auth/invite/info', { token } )
			.then( ( result ) => {
				setInfo( result.data );
				setName( result.data.name );
			} )
			.catch( ( failure: ApiError ) => setFailed( failure.message ) );
	}, [ token ] );

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setBusy( true );
		setError( '' );
		setFields( {} );
		try {
			await api.post( '/auth/invite/accept', { token, name, password } );
			window.location.assign( appLink( '/' ) );
		} catch ( failure ) {
			const apiError = failure as ApiError;
			setError( apiError.message );
			setFields( apiError.fields );
			setBusy( false );
		}
	};

	if ( failed ) {
		return (
			<AuthLayout
				title={ __( 'Invitation not available', 'cliezoo' ) }
				footer={
					<Link href="/login">
						{ __( 'Go to sign in', 'cliezoo' ) }
					</Link>
				}
			>
				<Alert tone="error">{ failed }</Alert>
			</AuthLayout>
		);
	}

	if ( ! info ) {
		return (
			<AuthLayout title={ __( 'Accept invitation', 'cliezoo' ) }>
				<div className="space-y-3" aria-busy="true">
					<Skeleton className="h-9" />
					<Skeleton className="h-9" />
				</div>
			</AuthLayout>
		);
	}

	return (
		<AuthLayout
			title={ sprintf(
				/* translators: %s: workspace name. */
				__( 'Join %s', 'cliezoo' ),
				info.workspace
			) }
			subtitle={ sprintf(
				/* translators: %s: email address. */
				__( 'You were invited as %s.', 'cliezoo' ),
				info.email
			) }
		>
			<Form onSubmit={ submit } className="space-y-4">
				{ error && <Alert tone="error">{ error }</Alert> }
				<TextField
					label={ __( 'Your name', 'cliezoo' ) }
					value={ name }
					onChange={ setName }
					isRequired
					autoComplete="name"
				/>
				{ info.needs_password && (
					<TextField
						label={ __( 'Choose a password', 'cliezoo' ) }
						type="password"
						value={ password }
						onChange={ setPassword }
						isRequired
						autoComplete="new-password"
						errorMessage={ fields.password }
						description={ passwordHint() }
					/>
				) }
				<Button
					type="submit"
					variant="primary"
					className="w-full"
					isPending={ busy }
				>
					{ __( 'Accept and continue', 'cliezoo' ) }
				</Button>
			</Form>
		</AuthLayout>
	);
}
