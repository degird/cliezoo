/**
 * Root route: document title, route-change announcements, toasts and the
 * "session expired" prompt.
 */
import { useEffect, useRef, useState } from 'react';
import {
	Outlet,
	useMatches,
	useNavigate,
	useRouterState,
} from '@tanstack/react-router';
import { __, sprintf } from '@wordpress/i18n';
import {
	getBoot,
	onTwoFactorRequired,
	onUnauthorized,
	watchChanges,
} from '@cliezoo/data';
import { Toaster, toast } from '@cliezoo/ui';
import { SessionDialog } from '../packages/app/session/SessionDialog';

function useDocumentTitle(): string {
	const matches = useMatches();
	const title =
		[ ...matches ]
			.reverse()
			.find( ( m ) => ( m.staticData as { title?: string } )?.title )
			?.staticData?.title ?? '';
	const workspace = getBoot().workspace.name || getBoot().branding.logoAlt;
	useEffect( () => {
		document.title = title ? `${ title } · ${ workspace }` : workspace;
	}, [ title, workspace ] );
	return title;
}

export function Root() {
	const title = useDocumentTitle();
	const pathname = useRouterState( { select: ( s ) => s.location.pathname } );
	const first = useRef( true );
	const [ announcement, setAnnouncement ] = useState( '' );
	const [ expired, setExpired ] = useState( false );

	// Move focus to the main landmark and announce the new page after client-side navigation.
	useEffect( () => {
		if ( first.current ) {
			first.current = false;
			return;
		}
		const main = document.getElementById( 'cliezoo-main' );
		main?.focus( { preventScroll: true } );
		setAnnouncement(
			title
				? sprintf(
						/* translators: %s: page title. */
						__( '%s page', 'cliezoo' ),
						title
					)
				: ''
		);
	}, [ pathname ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect(
		() => onUnauthorized( () => setExpired( !! getBoot().user ) ),
		[]
	);

	// One poll for the whole app: it refreshes the record types that changed.
	useEffect( () => ( getBoot().user ? watchChanges() : undefined ), [] );

	// First run: an administrator who has not been through setup starts there.
	useEffect( () => {
		const setup = getBoot().setup;
		if ( setup && ! setup.completed && '/' === pathname ) {
			navigate( { to: '/setup', replace: true } );
		}
	}, [ pathname ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Roles that require 2FA: send the person to account security until it is set up.
	const navigate = useNavigate();
	useEffect(
		() =>
			onTwoFactorRequired( ( error ) => {
				toast.warning( error.message );
				navigate( {
					to: '/account',
					search: { setup: '2fa' } as never,
				} );
			} ),
		[ navigate ]
	);

	return (
		<>
			<Outlet />
			<p className="sr-only" aria-live="polite" aria-atomic="true">
				{ announcement }
			</p>
			<Toaster />
			<SessionDialog isOpen={ expired } onOpenChange={ setExpired } />
		</>
	);
}
