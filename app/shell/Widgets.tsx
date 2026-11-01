/**
 * The home dashboard: cards that modules register, each lazily loaded and
 * kept in its own card so one slow or broken widget cannot take the page
 * down with it.
 */
import { Suspense, lazy, useMemo, type ComponentType } from 'react';
import { __ } from '@wordpress/i18n';
import { useSession } from '@cliezoo/data';
import { Alert, Skeleton, cx } from '@cliezoo/ui';
import { applies, registry, type WidgetDef } from '../packages/app/registry';
import { useRegistry } from '../packages/app/slot';
import { ErrorBoundary } from './ErrorBoundary';

const SPANS: Record< NonNullable< WidgetDef[ 'size' ] >, string > = {
	sm: '',
	md: 'md:col-span-2',
	lg: 'md:col-span-2 xl:col-span-3',
};

const components = new Map< string, ComponentType >();

export function widgetComponent( widget: WidgetDef ): ComponentType {
	let component = components.get( widget.id );
	if ( ! component ) {
		component = lazy( widget.component );
		components.set( widget.id, component );
	}
	return component;
}

/**
 * The cards this person may have in one place.
 *
 * @param place Home, or the dashboard.
 */
export function useWidgets(
	place: 'home' | 'dashboard' = 'home'
): WidgetDef[] {
	useRegistry();
	const boot = useSession( ( s ) => s.boot );
	return useMemo( () => {
		const hidden = boot.prefs?.nav_hidden ?? [];
		// The server decides which cards this person may have (module on or
		// off, role, permission) and in which order; the browser supplies
		// what each one looks like. An add-on's card is
		// declared on the server too, through `cliezoo_register_widgets`;
		// one registered only in the browser is never shown.
		const declared = boot.widgets ?? [];
		const rank = new Map( declared.map( ( w, index ) => [ w.id, index ] ) );
		return registry.widgets
			.filter(
				( widget ) =>
					applies( boot, widget ) &&
					! hidden.includes( `widget:${ widget.id }` ) &&
					( ! declared.length || rank.has( widget.id ) )
			)
			.sort(
				( a, b ) =>
					( rank.get( a.id ) ?? 99 ) - ( rank.get( b.id ) ?? 99 )
			)
			.map( ( widget ) => {
				const server = declared.find( ( w ) => w.id === widget.id );
				return server
					? {
							...widget,
							title: server.title || widget.title,
							size: server.size ?? widget.size,
							place: server.place ?? widget.place,
						}
					: widget;
			} )
			.filter( ( widget ) => ( widget.place ?? 'home' ) === place );
	}, [ boot, place ] );
}

export function Widgets( { widgets }: { widgets: WidgetDef[] } ) {
	return (
		<div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
			{ widgets.map( ( widget ) => {
				const Component = widgetComponent( widget );
				return (
					<section
						key={ widget.id }
						aria-labelledby={ `widget-${ widget.id }` }
						className={ cx(
							'rounded-lg border border-line bg-raised p-4',
							SPANS[ widget.size ?? 'sm' ]
						) }
					>
						<h2
							id={ `widget-${ widget.id }` }
							className="mb-3 text-sm font-semibold text-fg"
						>
							{ widget.title }
						</h2>
						<ErrorBoundary
							fallback={
								<Alert tone="warning">
									{ __(
										'This card could not be shown.',
										'cliezoo'
									) }
								</Alert>
							}
						>
							<Suspense
								fallback={ <Skeleton className="h-24" /> }
							>
								<Component />
							</Suspense>
						</ErrorBoundary>
					</section>
				);
			} ) }
		</div>
	);
}
