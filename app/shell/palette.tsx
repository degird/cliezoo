/**
 * Command palette (Ctrl/⌘+K) and "+ New" menu: navigation, registered
 * commands and live search across every record type the user can see.
 */
import { Suspense, lazy, useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { create } from 'zustand';
import {
	AriaDialog as Dialog,
	Modal,
	ModalOverlay,
	Spinner,
	cx,
} from '@cliezoo/ui';

const loadBody = () =>
	import( /* webpackChunkName: "palette" */ './PaletteBody' );
const PaletteBody = lazy( loadBody );
const ShortcutsSheet = lazy(
	() => import( /* webpackChunkName: "palette" */ './Shortcuts' )
);

/**
 * Loads the palette content ahead of time (idle time after start).
 */
export function preloadPalette(): void {
	void loadBody();
}

export type PaletteMode = 'search' | 'new';
type Mode = PaletteMode;

interface PaletteState {
	open: boolean;
	mode: Mode;
	set: ( open: boolean, mode?: Mode ) => void;
}

export const usePalette = create< PaletteState >()( ( set ) => ( {
	open: false,
	mode: 'search',
	set: ( open, mode ) =>
		set( ( state ) => ( { open, mode: mode ?? state.mode } ) ),
} ) );

/**
 * Opens the palette.
 *
 * @param mode "search" (everything) or "new" (create commands only).
 */
export function openPalette( mode: Mode = 'search' ): void {
	usePalette.getState().set( true, mode );
}

/**
 * The palette overlay plus its global shortcuts: Ctrl/⌘+K and "/".
 */
export function CommandPalette() {
	const { open, mode, set } = usePalette();
	const [ shortcuts, setShortcuts ] = useState( false );

	useEffect( () => {
		const onKey = ( event: KeyboardEvent ) => {
			const target = event.target as HTMLElement | null;
			const typing =
				!! target &&
				( target.isContentEditable ||
					/^(INPUT|TEXTAREA|SELECT)$/.test( target.tagName ) );
			if (
				( event.metaKey || event.ctrlKey ) &&
				event.key.toLowerCase() === 'k'
			) {
				event.preventDefault();
				set( ! usePalette.getState().open, 'search' );
				return;
			}
			// Plain letters belong to whatever is on top: a dialog, a menu or
			// a field the person is typing in.
			const busy =
				typing ||
				!! target?.closest( '[role="dialog"], [role="menu"]' ) ||
				!! document.querySelector( '[role="dialog"]' );
			if ( busy || event.metaKey || event.ctrlKey || event.altKey ) {
				return;
			}
			if ( event.key === '/' ) {
				event.preventDefault();
				set( true, 'search' );
			} else if ( event.key === '?' ) {
				event.preventDefault();
				setShortcuts( true );
			} else if ( event.key.toLowerCase() === 'c' ) {
				event.preventDefault();
				set( true, 'new' );
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ set ] );

	return (
		<>
			{ shortcuts && (
				<Suspense fallback={ null }>
					<ShortcutsSheet close={ () => setShortcuts( false ) } />
				</Suspense>
			) }
			<ModalOverlay
				isOpen={ open }
				onOpenChange={ ( next ) => set( next ) }
				isDismissable
				className="fixed inset-0 z-50 flex items-start justify-center bg-overlay p-3 pt-[10vh]"
			>
				<Modal
					className={ cx(
						'w-full max-w-xl overflow-hidden rounded-xl border border-line bg-raised text-fg shadow-lg'
					) }
				>
					<Dialog
						aria-label={ __( 'Command palette', 'cliezoo' ) }
						className="outline-none"
					>
						{ open && (
							<Suspense
								fallback={
									<div className="flex h-40 items-center justify-center text-muted">
										<Spinner
											label={ __( 'Loading', 'cliezoo' ) }
										/>
									</div>
								}
							>
								<PaletteBody
									mode={ mode }
									close={ () => set( false ) }
								/>
							</Suspense>
						) }
					</Dialog>
				</Modal>
			</ModalOverlay>
		</>
	);
}
