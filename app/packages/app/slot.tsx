/**
 * Named extension slots (generic; any add-on may fill them).
 */
import { Suspense, useSyncExternalStore, type ReactNode } from 'react';
import { useSession } from '@cliezoo/data';
import { registry } from './registry';

/**
 * Re-renders when registrations change.
 */
export function useRegistry(): number {
	return useSyncExternalStore(
		registry.subscribe,
		registry.getVersion,
		registry.getVersion
	);
}

/**
 * Renders every fill of a slot. When nothing fills it, the fallback is shown.
 * @param root0
 * @param root0.name
 * @param root0.props
 * @param root0.fallback
 */
export function Slot< P extends Record< string, unknown > >( {
	name,
	props,
	fallback,
}: {
	name: string;
	props?: P;
	fallback?: ReactNode;
	badge?: boolean;
	/** How the slot is laid out: a row in a menu, a chip in a toolbar. */
	variant?: 'row' | 'chip';
} ) {
	useRegistry();
	const modules = useSession( ( s ) => s.boot.modules );
	const fills = ( registry.slots.get( name ) ?? [] ).filter(
		( fill ) => ! fill.module || modules.includes( fill.module )
	);
	if ( ! fills.length ) {
		return <>{ fallback }</>;
	}
	// Each fill waits on its own. A fill fetched when the slot is first
	// drawn suspends while its chunk arrives, and without a boundary of
	// its own that suspension is caught by whatever is above — which on a
	// slot in the top bar is the whole page, blank until a chunk nobody is
	// waiting for lands.
	return (
		<>
			{ fills.map( ( { id, component: Fill } ) => (
				<Suspense key={ id } fallback={ null }>
					<Fill { ...( props ?? ( {} as P ) ) } />
				</Suspense>
			) ) }
		</>
	);
}

/**
 * Whether a slot has fills (for hiding empty containers).
 *
 * @param name Slot name.
 */
export function useSlotHasFills( name: string ): boolean {
	useRegistry();
	const modules = useSession( ( s ) => s.boot.modules );
	return ( registry.slots.get( name ) ?? [] ).some(
		( fill ) => ! fill.module || modules.includes( fill.module )
	);
}
