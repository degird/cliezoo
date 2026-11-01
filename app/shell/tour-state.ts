/**
 * Whether the tour is showing.
 *
 * Kept apart from the tour itself so the top bar can start it without
 * pulling the tour into the start-up bundle.
 */
import { create } from 'zustand';

/** Where the help centre lives. */
export const HELP_URL = 'https://cliezoo.com/docs/';

interface TourState {
	open: boolean;
	set: ( open: boolean ) => void;
}

export const useTour = create< TourState >()( ( set ) => ( {
	open: false,
	set: ( open ) => set( { open } ),
} ) );

/**
 * Starts the tour (from the account menu, or on a first visit).
 */
export function startTour(): void {
	useTour.getState().set( true );
}
