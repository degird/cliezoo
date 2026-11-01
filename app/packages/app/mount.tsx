/**
 * Mounts the app into #cliezoo-root once every deferred bundle (the plugin's
 * own and any add-on's) has registered.
 */
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { RouterProvider as TanStackRouterProvider } from '@tanstack/react-router';
import {
	QueryClientProvider,
	applyTheme,
	getBoot,
	queryClient,
	watchSystemTheme,
} from '@cliezoo/data';
import { I18nProvider, RouterProvider, preloadExtras } from '@cliezoo/ui';
import { buildRouter, type AppRouter } from './router';
import { accentFor, applyBranding, setAccent } from './appearance';

let router: AppRouter | null = null;

/**
 * The live router (null before mount).
 */
export function getRouter(): AppRouter | null {
	return router;
}

/**
 * Applies workspace branding and personal display preferences to <html>/<body>.
 */
export function applyAppearance(): void {
	const boot = getBoot();
	const root = document.documentElement;
	const body = document.body;
	applyTheme( boot.prefs?.theme ?? boot.theme );
	body.dataset.density =
		boot.prefs?.density ?? boot.branding.density ?? 'comfortable';
	root.dir = boot.rtl ? 'rtl' : 'ltr';
	body.dataset.radius = boot.branding.radius ?? 'md';
	// Font, background preset and the navigation and sidebar tokens.
	applyBranding( boot.branding );
	applyAccent();
}

/**
 * Sets the accent tokens for the current theme. Dark mode may have its own
 * accent, so this runs again whenever the theme changes.
 */
export function applyAccent(): void {
	const boot = getBoot();
	const accent = accentFor( boot.branding );
	if ( accent.toLowerCase() === '#0458fb' && ! boot.branding.accent_dark ) {
		return;
	}
	// White-label accent; the branding designer checks contrast before saving (§12.4).
	setAccent( accent );
}

function locale(): string {
	return getBoot().locale.replace( '_', '-' );
}

/**
 * Renders the app.
 *
 * @param element Container.
 */
export function mount( element: HTMLElement ): void {
	applyAppearance();
	watchSystemTheme();
	router = buildRouter();
	const current = router;
	createRoot( element ).render(
		<StrictMode>
			<QueryClientProvider client={ queryClient }>
				<I18nProvider locale={ locale() }>
					<RouterProvider
						navigate={ ( to, options ) =>
							current.navigate( {
								to: to as string,
								...( options ?? {} ),
							} )
						}
						useHref={ ( to ) =>
							current.buildLocation( { to: to as string } ).href
						}
					>
						<TanStackRouterProvider router={ current } />
					</RouterProvider>
				</I18nProvider>
			</QueryClientProvider>
		</StrictMode>
	);
	element.removeAttribute( 'aria-busy' );
	preloadExtras();
}

/**
 * Mounts after DOMContentLoaded (all deferred scripts have run by then).
 */
export function start(): void {
	const run = () => {
		const element = document.getElementById( 'cliezoo-root' );
		if ( element && ! router ) {
			mount( element );
		}
	};
	// A deferred script runs while the document is already "interactive",
	// before the deferred scripts after it (an add-on's, registering its
	// routes) — so "not loading" is not "everything has run". Only a
	// finished page is; otherwise the router waits for DOMContentLoaded
	// (or the load event, for a script that came in after that).
	if ( document.readyState === 'complete' ) {
		run();
	} else {
		document.addEventListener( 'DOMContentLoaded', run, { once: true } );
		window.addEventListener( 'load', run, { once: true } );
	}
}
