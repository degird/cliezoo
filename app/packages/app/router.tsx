/**
 * Router: TanStack Router built from the registry at mount time.
 * Pretty permalinks use the History API under the app base; plain
 * permalinks use hash routing on `?cliezoo=/dash/`.
 */
import {
	Outlet,
	createBrowserHistory,
	createHashHistory,
	createRootRoute,
	createRoute,
	createRouter,
	lazyRouteComponent,
	notFound,
	redirect,
	type AnyRoute,
} from '@tanstack/react-router';
import { getBoot } from '@cliezoo/data';
import { applies, registry } from './registry';
import { Root } from '../../shell/Root';
import { Layout } from '../../shell/Layout';
import { NotFound } from '../../shell/screens/NotFound';
import { RouteError } from '../../shell/screens/RouteError';

export interface RouteStaticData {
	title?: string;
}

declare module '@tanstack/react-router' {
	interface StaticDataRouteOption {
		title?: string;
	}
}

/**
 * The router's base path ("/dash" or "/sub/site/dash"; "" for hash routing).
 */
export function basePath(): string {
	const { routes } = getBoot();
	if ( ! routes.pretty ) {
		return '';
	}
	try {
		return new URL(
			routes.appUrl,
			window.location.origin
		).pathname.replace( /\/$/, '' );
	} catch {
		return '/' + routes.app;
	}
}

/**
 * The full address of a page in the app, to copy or share ("/files?file=…").
 *
 * @param path App path, with any query.
 */
export function appHref( path: string ): string {
	const { routes } = getBoot();
	const base = new URL( routes.appUrl, window.location.origin ).href;
	return routes.pretty
		? base.replace( /\/$/, '' ) + path
		: `${ base }#${ path }`;
}

function history() {
	const { routes } = getBoot();
	if ( routes.pretty ) {
		return createBrowserHistory();
	}
	// Plain permalinks: a server-side deep link (?cliezoo=/dash/auth/magic/…) becomes the hash route.
	if ( ! window.location.hash && routes.path ) {
		window.history.replaceState(
			null,
			'',
			`${ window.location.pathname }${ window.location.search }#/${ routes.path }`
		);
	}
	return createHashHistory();
}

const GUEST_PATHS = /^\/(login|auth\/)/;

// Token links stay reachable while signed in (they may be for another account).
const TOKEN_PATHS = [ '/auth/magic/$token', '/auth/invite/$token' ];

/**
 * Builds the router from every registered route.
 */
export function buildRouter() {
	const rootRoute = createRootRoute( {
		component: Root,
		notFoundComponent: NotFound,
		errorComponent: RouteError,
	} );

	const shellRoute = createRoute( {
		getParentRoute: () => rootRoute,
		id: 'shell',
		component: Layout,
		// The boundary belongs inside the shell: a crash in one screen
		// should not take the sidebar, and with it every route out of the
		// screen that crashed.
		errorComponent: RouteError,
		beforeLoad: ( { location } ) => {
			if ( ! getBoot().user ) {
				throw redirect( {
					to: '/login',
					search: { redirect: location.href } as never,
				} );
			}
		},
		notFoundComponent: NotFound,
	} );

	const bareRoute = createRoute( {
		getParentRoute: () => rootRoute,
		id: 'bare',
		component: Outlet,
	} );

	const children: AnyRoute[] = [];
	const shellChildren: AnyRoute[] = [];
	for ( const def of registry.routes ) {
		const parent = def.layout === 'bare' ? bareRoute : shellRoute;
		const route = createRoute( {
			getParentRoute: () => parent,
			path: def.path,
			component: def.component
				? lazyRouteComponent( def.component )
				: () => null,
			staticData: { title: def.title },
			beforeLoad: ( { params, search } ) => {
				const boot = getBoot();
				if (
					boot.user &&
					def.audience === 'guest' &&
					GUEST_PATHS.test( def.path ) &&
					! TOKEN_PATHS.includes( def.path )
				) {
					throw redirect( { to: '/' } );
				}
				if ( ! applies( boot, def ) ) {
					throw notFound();
				}
				if ( def.redirectTo ) {
					const where = def.redirectTo(
						params as Record< string, string >,
						boot,
						( search ?? {} ) as Record< string, unknown >
					);
					if ( ! where ) {
						return;
					}
					throw redirect( {
						to: where.to,
						search: ( where.search ?? {} ) as never,
						replace: true,
					} as never );
				}
			},
		} );
		( def.layout === 'bare' ? children : shellChildren ).push( route );
	}

	const tree = rootRoute.addChildren( [
		shellRoute.addChildren( shellChildren ),
		bareRoute.addChildren( children ),
	] );

	return createRouter( {
		routeTree: tree,
		history: history(),
		basepath: basePath() || '/',
		defaultPreload: 'intent',
		defaultPreloadStaleTime: 0,
		scrollRestoration: true,
		defaultNotFoundComponent: NotFound,
		defaultErrorComponent: RouteError,
	} );
}

export type AppRouter = ReturnType< typeof buildRouter >;
