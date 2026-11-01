/**
 * Lazily loaded controls with the same API as the real ones.
 *
 * Two chunks are loaded this way, both preloaded as soon as the browser is
 * idle: the form controls, and the overlays (dialogs, menus, popovers).
 * Neither is on screen when the app first paints, and together they are
 * most of what React Aria weighs, so keeping them out of the first load is
 * what holds the start-up budget (Blueprint §18.1, ADR 0003).
 *
 * Once a chunk has landed its parts render the real component
 * synchronously, so they work inside React Aria collections; before that
 * each one shows whatever standing in for it costs least — a field shows a
 * placeholder, a container shows its own children, an overlay shows
 * nothing, because nothing is what an unopened overlay looks like.
 */
import {
	forwardRef,
	useEffect,
	useState,
	type ComponentType,
	type ReactNode,
} from 'react';

type Forms = typeof import( './extras' );
type Overlays = typeof import( './overlays' );

const chunks = {
	forms: {
		pending: null as Promise< Forms > | null,
		loaded: null as Forms | null,
	},
	overlays: {
		pending: null as Promise< Overlays > | null,
		loaded: null as Overlays | null,
	},
};

/**
 * Loads the form controls once.
 */
export function loadExtras(): Promise< Forms > {
	chunks.forms.pending ??= import(
		/* webpackChunkName: "forms" */ './extras'
	).then( ( mod ) => {
		chunks.forms.loaded = mod;
		return mod;
	} );
	return chunks.forms.pending;
}

/**
 * Loads the overlays once.
 */
export function loadOverlays(): Promise< Overlays > {
	chunks.overlays.pending ??= import(
		/* webpackChunkName: "overlays" */ './overlays'
	).then( ( mod ) => {
		chunks.overlays.loaded = mod;
		return mod;
	} );
	return chunks.overlays.pending;
}

/**
 * Preloads both chunks when the browser is idle.
 */
export function preloadExtras(): void {
	const run = () => {
		void loadExtras();
		void loadOverlays();
	};
	if ( typeof window !== 'undefined' && 'requestIdleCallback' in window ) {
		window.requestIdleCallback( run, { timeout: 3000 } );
	} else {
		setTimeout( run, 1500 );
	}
}

const fieldFallback = (
	<span
		aria-hidden="true"
		className="block h-9 w-full animate-pulse rounded-md bg-sunken motion-reduce:animate-none"
	/>
);

/**
 * What a wrapper shows while its chunk is on its way: its own children.
 * @param props
 */
export const passthrough = ( props: Record< string, unknown > ) => (
	<>{ props.children as ReactNode }</>
);

type Fallback =
	ReactNode | ( ( props: Record< string, unknown > ) => ReactNode );

/**
 * A stand-in for one export of a chunk.
 *
 * @param load     Loads the chunk.
 * @param slot     Which chunk's cache to read.
 * @param name     The export's name.
 * @param fallback What to show until it arrives.
 */
function lazyPart< T extends object, K extends keyof T >(
	load: () => Promise< T >,
	slot: 'forms' | 'overlays',
	name: K,
	fallback: Fallback
): T[ K ] {
	const Wrapped = forwardRef< unknown, Record< string, unknown > >(
		function LazyPart( props, ref ) {
			const [ mod, setMod ] = useState< T | null >(
				chunks[ slot ].loaded as T | null
			);
			useEffect( () => {
				if ( mod ) {
					return undefined;
				}
				let live = true;
				load().then( ( m ) => live && setMod( m ) );
				return () => {
					live = false;
				};
			}, [ mod ] );
			if ( ! mod ) {
				return (
					<>
						{ 'function' === typeof fallback
							? fallback( props )
							: fallback }
					</>
				);
			}
			const Real = mod[ name ] as unknown as ComponentType<
				Record< string, unknown > & { ref?: unknown }
			>;
			return <Real ref={ ref } { ...props } />;
		}
	);
	Wrapped.displayName = String( name );
	return Wrapped as unknown as T[ K ];
}

/**
 * A form control.
 *
 * @param name     Export name.
 * @param fallback What to show until the chunk arrives.
 */
export function part< K extends keyof Forms >(
	name: K,
	fallback: Fallback = fieldFallback
): Forms[ K ] {
	return lazyPart< Forms, K >( loadExtras, 'forms', name, fallback );
}

/**
 * An overlay, or one of the primitives overlays are built from.
 *
 * @param name     Export name.
 * @param fallback What to show until the chunk arrives (nothing, usually).
 */
export function overlayPart< K extends keyof Overlays >(
	name: K,
	fallback: Fallback = null
): Overlays[ K ] {
	return lazyPart< Overlays, K >( loadOverlays, 'overlays', name, fallback );
}

export const TextField = part( 'TextField' );
export const SearchField = part( 'SearchField' );
export const Select = part( 'Select' );
export const Option = part( 'Option', null );
export const Checkbox = part(
	'Checkbox',
	<span aria-hidden="true" className="block h-5" />
);
export const Switch = part(
	'Switch',
	<span aria-hidden="true" className="block h-5" />
);
// A sign-in form is the first thing anyone sees and may be the only thing
// on the screen, so it is a real form with its fields in it from the
// first paint, whether or not the chunk has landed.
export const Form = part( 'Form', ( props ) => (
	<form
		className={ props.className as string }
		onSubmit={
			props.onSubmit as ( event: { preventDefault: () => void } ) => void
		}
	>
		{ props.children as ReactNode }
	</form>
) );
export const Input = part( 'Input' );
export const Label = part( 'Label', null );
export const ListBox = part( 'ListBox', null );
export const ListBoxItem = part( 'ListBoxItem', null );
export const DragList = part( 'DragList', null );
export const AriaSearchField = part( 'AriaSearchField' );
export const Autocomplete = part( 'Autocomplete', passthrough );
export const Disclosure = part( 'Disclosure', passthrough );
export const DisclosurePanel = part( 'DisclosurePanel', null );
export const Toolbar = part( 'Toolbar', passthrough );
export const Group = part( 'Group', passthrough );
export const Tooltip = part( 'Tooltip', null );
export const TooltipTrigger = part( 'TooltipTrigger', null );
// Tabs can wrap a whole screen, so what is inside them has to survive the
// wait for the chunk; only the tab strip itself is worth holding back.
export const Tabs = part( 'Tabs', passthrough );
export const TabList = part( 'TabList', null );
export const Tab = part( 'Tab', null );
export const TabPanel = part( 'TabPanel', passthrough );

// The raw React Aria primitives the app builds its own overlays from.
export const Header = overlayPart( 'Header', passthrough );
export const Modal = overlayPart( 'Modal' );
export const ModalOverlay = overlayPart( 'ModalOverlay' );
export const AriaDialog = overlayPart( 'AriaDialog', passthrough );
export const AriaMenu = overlayPart( 'AriaMenu' );
export const AriaMenuItem = overlayPart( 'AriaMenuItem' );
export const AriaMenuSection = overlayPart( 'AriaMenuSection' );
export const AriaButton = overlayPart( 'AriaButton', passthrough );
