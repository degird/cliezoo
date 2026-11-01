/**
 * The team/client shell: skip links, sidebar (drawer on small screens),
 * top bar, main landmark, mobile bottom navigation and the command palette.
 */
import { Suspense, lazy, useEffect, useState } from 'react';
import { Link, Outlet, useRouterState } from '@tanstack/react-router';
import { __ } from '@wordpress/i18n';
import { MoreHorizontal } from 'lucide-react';
import {
	usePreferences,
	useSavePreferences,
	useSession,
	useUser,
} from '@cliezoo/data';
import { Sheet, cx } from '@cliezoo/ui';
import { useRegistry } from '../packages/app/slot';
import { useSync } from '../packages/app/useSync';
import { availableItems, isHidden, ordered } from './nav';
import { Sidebar } from './Sidebar';
import { TopBar } from './TopBar';
import { CommandPalette, preloadPalette } from './palette';
import { useTour } from './tour-state';

const Tour = lazy( () => import( /* webpackChunkName: "tour" */ './Tour' ) );

/**
 * Shows the tour on a first visit, and whenever somebody asks for it
 * again. It is its own chunk: most visits never load it.
 */
function TourHost() {
	const { open, set } = useTour();
	const prefs = usePreferences();
	const user = useUser();

	useEffect( () => {
		if ( user && prefs && ! prefs.tour_done ) {
			// After the first paint, so the things it points at exist.
			const timer = window.setTimeout( () => set( true ), 800 );
			return () => window.clearTimeout( timer );
		}
	}, [ user, prefs?.tour_done ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( ! open ) {
		return null;
	}
	return (
		<Suspense fallback={ null }>
			<Tour onDone={ () => set( false ) } />
		</Suspense>
	);
}

const SafeModeBanner = lazy(
	() => import( /* webpackChunkName: "safe-mode" */ './SafeModeBanner' )
);

const ImpersonationBanner = lazy(
	() =>
		import(
			/* webpackChunkName: "impersonation" */ './ImpersonationBanner'
		)
);

function SkipLinks() {
	const link =
		'sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-[70] focus:rounded-md focus:bg-accent focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:text-accent-contrast';
	return (
		<>
			<a
				href="#cliezoo-main"
				className={ link }
				onClick={ ( e ) => (
					e.preventDefault(),
					document.getElementById( 'cliezoo-main' )?.focus()
				 ) }
			>
				{ __( 'Skip to content', 'cliezoo' ) }
			</a>
			<a
				href="#cliezoo-nav"
				className={ link }
				onClick={ ( e ) => (
					e.preventDefault(),
					document.getElementById( 'cliezoo-nav' )?.focus()
				 ) }
			>
				{ __( 'Skip to navigation', 'cliezoo' ) }
			</a>
		</>
	);
}

function MobileNav( { onMore }: { onMore: () => void } ) {
	useRegistry();
	const boot = useSession( ( s ) => s.boot );
	const prefs = usePreferences();
	const items = ordered( availableItems( boot ), prefs.nav_order )
		.filter( ( item ) => item.mobile && ! isHidden( prefs, item.id ) )
		.slice( 0, 4 );
	return (
		<nav
			aria-label={ __( 'Quick navigation', 'cliezoo' ) }
			className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-bg pb-[env(safe-area-inset-bottom)] lg:hidden"
		>
			<ul className="grid grid-cols-5">
				{ items.map( ( item ) => {
					const Icon = item.icon;
					return (
						<li key={ item.id }>
							<Link
								to={ item.to }
								activeOptions={ { exact: item.to === '/' } }
								className="flex h-14 flex-col items-center justify-center gap-0.5 text-[0.7rem] text-muted focus-visible:outline-2 focus-visible:outline-focus data-[status=active]:text-accent-fg"
							>
								{ Icon && (
									<Icon size={ 20 } aria-hidden={ true } />
								) }
								<span className="max-w-full truncate px-1">
									{ item.label }
								</span>
							</Link>
						</li>
					);
				} ) }
				<li className={ cx( items.length < 4 && 'col-start-5' ) }>
					<button
						type="button"
						onClick={ onMore }
						className="flex h-14 w-full flex-col items-center justify-center gap-0.5 text-[0.7rem] text-muted focus-visible:outline-2 focus-visible:outline-focus"
					>
						<MoreHorizontal size={ 20 } aria-hidden="true" />
						<span>{ __( 'More', 'cliezoo' ) }</span>
					</button>
				</li>
			</ul>
		</nav>
	);
}

export function Layout() {
	const prefs = usePreferences();
	const save = useSavePreferences();
	const [ drawer, setDrawer ] = useState( false );
	const pathname = useRouterState( { select: ( s ) => s.location.pathname } );
	const collapsed = prefs.sidebar_collapsed;
	const safeMode = useSession( ( s ) => !! s.boot.safeMode?.active );
	const impersonating = useSession( ( s ) => !! s.boot.impersonating );

	useEffect( () => setDrawer( false ), [ pathname ] );

	// One request asks what has changed; the lists whose kind moved are
	// marked stale and refetch themselves. Mounted here, once, rather than
	// by every screen.
	useSync();

	useEffect( () => {
		const timer = window.setTimeout( preloadPalette, 2500 );
		return () => window.clearTimeout( timer );
	}, [] );

	// "[" toggles the sidebar (outside text fields).
	useEffect( () => {
		const onKey = ( event: KeyboardEvent ) => {
			const target = event.target as HTMLElement | null;
			if (
				event.key === '[' &&
				! event.metaKey &&
				! event.ctrlKey &&
				! event.altKey &&
				! (
					target &&
					( target.isContentEditable ||
						/^(INPUT|TEXTAREA|SELECT)$/.test( target.tagName ) )
				)
			) {
				save.mutate( { sidebar_collapsed: ! collapsed } );
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ collapsed ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return (
		<div className="flex min-h-dvh bg-bg text-fg">
			<SkipLinks />
			<aside
				className={ cx(
					'sticky top-0 hidden h-dvh shrink-0 transition-[width] duration-150 lg:block',
					collapsed
						? 'w-[var(--cz-sidebar-collapsed)]'
						: 'w-[var(--cz-sidebar-width)]'
				) }
				aria-label={ __( 'Sidebar', 'cliezoo' ) }
			>
				<Sidebar />
			</aside>
			<Sheet
				isOpen={ drawer }
				onOpenChange={ setDrawer }
				side="start"
				width="md"
				title={ __( 'Navigation', 'cliezoo' ) }
			>
				<Sidebar mobile onNavigate={ () => setDrawer( false ) } />
			</Sheet>
			<div className="flex min-w-0 flex-1 flex-col">
				{ impersonating && (
					<Suspense fallback={ null }>
						<ImpersonationBanner />
					</Suspense>
				) }
				<TopBar onMenu={ () => setDrawer( true ) } />
				{ safeMode && (
					<Suspense fallback={ null }>
						<SafeModeBanner />
					</Suspense>
				) }
				<main
					id="cliezoo-main"
					tabIndex={ -1 }
					className="min-w-0 flex-1 pb-20 outline-none lg:pb-0"
				>
					<Outlet />
				</main>
			</div>
			<MobileNav onMore={ () => setDrawer( true ) } />
			<CommandPalette />
			<TourHost />
		</div>
	);
}
