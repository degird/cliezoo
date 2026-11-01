/**
 * "Your session has ended", without losing the page.
 *
 * Reloading to sign in again throws away whatever is open. Instead the
 * sign-in page opens in a new tab; once signed in there, "I have signed in"
 * fetches a fresh REST nonce for this tab, and everything open here —
 * a half-written form, a message, a filter — carries on. Record forms also
 * keep their drafts in the browser, so even a reload loses nothing.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { getBoot, refreshNonce, useQueryClient } from '@cliezoo/data';
import { Button, Dialog } from '@cliezoo/ui';

export interface SessionDialogProps {
	isOpen: boolean;
	onOpenChange: ( open: boolean ) => void;
}

/**
 * The address to sign in at, back to the app afterwards.
 */
export function signInUrl(): string {
	const base = getBoot().routes.appUrl.replace( /\/$/, '' );
	return `${ base }/login`;
}

/**
 * The dialog.
 *
 * @param root0              Props.
 * @param root0.isOpen       Whether it is shown.
 * @param root0.onOpenChange Called to show or hide it.
 */
export function SessionDialog( { isOpen, onOpenChange }: SessionDialogProps ) {
	const client = useQueryClient();
	const [ checking, setChecking ] = useState( false );
	const [ still, setStill ] = useState( false );

	const back = async () => {
		setChecking( true );
		const ok = await refreshNonce();
		setChecking( false );
		setStill( ! ok );
		if ( ok ) {
			onOpenChange( false );
			// Whatever failed while signed out is asked for again.
			client.invalidateQueries();
		}
	};

	return (
		<Dialog
			isOpen={ isOpen }
			onOpenChange={ onOpenChange }
			role="alertdialog"
			title={ __( 'Your session has ended', 'cliezoo' ) }
			description={
				still
					? __(
							'You are still signed out here. Sign in in the other tab, then try again.',
							'cliezoo'
						)
					: __(
							'Sign in again in a new tab, then come back: everything on this page stays as it is.',
							'cliezoo'
						)
			}
			footer={ () => (
				<>
					<Button
						onPress={ () =>
							window.open( signInUrl(), '_blank', 'noopener' )
						}
					>
						{ __( 'Sign in in a new tab', 'cliezoo' ) }
					</Button>
					<Button
						variant="primary"
						isPending={ checking }
						onPress={ back }
					>
						{ __( 'I have signed in', 'cliezoo' ) }
					</Button>
				</>
			) }
		/>
	);
}
