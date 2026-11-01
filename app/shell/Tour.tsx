/**
 * A short walk around the app on the first visit.
 *
 * Four stops, each pointing at something real on the screen. It can be
 * left at any point and never comes back by itself; "Show me around" in
 * the account menu replays it. A step whose target is not on this screen
 * is skipped rather than pointing at nothing.
 */
import { useEffect, useLayoutEffect, useMemo, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useSavePreferences } from '@cliezoo/data';
import { AriaDialog, Button, Modal, ModalOverlay } from '@cliezoo/ui';

interface Stop {
	/** The `data-tour` value of the thing being pointed at. */
	target: string;
	title: string;
	body: string;
}

const STOPS = (): Stop[] => [
	{
		target: 'nav',
		title: __( 'Everything lives here', 'cliezoo' ),
		body: __(
			'Your work is in the sidebar. You can hide what you do not use, and drag the rest into the order you want.',
			'cliezoo'
		),
	},
	{
		target: 'search',
		title: __( 'One place to find anything', 'cliezoo' ),
		body: __(
			'Search every record you can see, or type a command. Ctrl or ⌘ and K from anywhere; / on its own.',
			'cliezoo'
		),
	},
	{
		target: 'new',
		title: __( 'Make something', 'cliezoo' ),
		body: __(
			'Add a record from anywhere, without leaving the screen you are on. The C key does the same.',
			'cliezoo'
		),
	},
	{
		target: 'account',
		title: __( 'You are set up', 'cliezoo' ),
		body: __(
			'Your account, appearance and this tour again are all in here. Press ? for the keyboard shortcuts.',
			'cliezoo'
		),
	},
];

/** Where a stop's target sits on screen. */
interface Spot {
	top: number;
	left: number;
	width: number;
	height: number;
}

function find( target: string ): Spot | null {
	const node = document.querySelector( `[data-tour="${ target }"]` );
	if ( ! node ) {
		return null;
	}
	const box = node.getBoundingClientRect();
	if ( 0 === box.width && 0 === box.height ) {
		return null;
	}
	return {
		top: box.top,
		left: box.left,
		width: box.width,
		height: box.height,
	};
}

/** Roughly how big the card is, for keeping it on screen. */
const CARD = { width: 320, height: 190 };

/**
 * Puts the card next to what it points at, and always fully on screen.
 *
 * A tall target — the sidebar — gets the card beside it rather than
 * underneath, where there would be no room for it.
 *
 * @param spot Where the target is.
 */
function position( spot: Spot ): { top: number; left: number } {
	const gap = 12;
	const maxTop = Math.max( gap, window.innerHeight - CARD.height - gap );
	const maxLeft = Math.max( gap, window.innerWidth - CARD.width - gap );
	const clamp = ( top: number, left: number ) => ( {
		top: Math.min( Math.max( gap, top ), maxTop ),
		left: Math.min( Math.max( gap, left ), maxLeft ),
	} );

	const tall = spot.height > window.innerHeight * 0.5;
	if ( tall ) {
		// Beside it, level with the middle of the target.
		const left =
			spot.left + spot.width + gap + CARD.width < window.innerWidth
				? spot.left + spot.width + gap
				: spot.left - CARD.width - gap;
		return clamp( spot.top + spot.height / 2 - CARD.height / 2, left );
	}
	const below = spot.top + spot.height + gap;
	const fits = below + CARD.height + gap <= window.innerHeight;
	return clamp( fits ? below : spot.top - CARD.height - gap, spot.left );
}

export default function Tour( { onDone }: { onDone: () => void } ) {
	const save = useSavePreferences();
	// Worked out once: the list must not change identity between renders,
	// or measuring a stop would schedule the next measurement for ever.
	const stops = useMemo(
		() => STOPS().filter( ( stop ) => null !== find( stop.target ) ),
		[]
	);
	const [ index, setIndex ] = useState( 0 );
	const [ spot, setSpot ] = useState< Spot | null >( null );
	const stop = stops[ index ];
	const target = stop?.target ?? '';

	useLayoutEffect( () => {
		setSpot( target ? find( target ) : null );
	}, [ target ] );

	useEffect( () => {
		const onMove = () => setSpot( target ? find( target ) : null );
		window.addEventListener( 'resize', onMove );
		window.addEventListener( 'scroll', onMove, true );
		return () => {
			window.removeEventListener( 'resize', onMove );
			window.removeEventListener( 'scroll', onMove, true );
		};
	}, [ target ] );

	const finish = () => {
		save.mutate( { tour_done: true } );
		onDone();
	};

	if ( ! stop || ! spot ) {
		return null;
	}

	const place = position( spot );

	// The card is a real modal rather than a div wearing role="dialog": the
	// same overlay every other dialog in the app uses, so focus moves into
	// it, stays in it while it is up, and goes back to whatever had it when
	// the walk ends. Escape and a click beside it come with it. Somebody
	// arriving by keyboard on their first minute here is exactly the person
	// a hand-rolled one strands.
	return (
		<ModalOverlay
			isOpen
			isDismissable
			onOpenChange={ ( open: boolean ) => {
				if ( ! open ) {
					finish();
				}
			} }
			className="fixed inset-0 z-[60] bg-overlay"
		>
			<div
				className="pointer-events-none absolute rounded-lg ring-2 ring-accent ring-offset-2 ring-offset-bg"
				style={ {
					top: spot.top,
					left: spot.left,
					width: spot.width,
					height: spot.height,
				} }
				aria-hidden="true"
			/>
			<Modal
				className="absolute w-80 max-w-[calc(100vw-1.5rem)] rounded-xl border border-line bg-raised p-4 shadow-lg"
				style={ place }
			>
				<AriaDialog
					aria-label={ __( 'A quick tour', 'cliezoo' ) }
					className="outline-none"
				>
					<p className="text-sm font-semibold text-fg">
						{ stop.title }
					</p>
					<p className="mt-1 text-sm text-muted">{ stop.body }</p>
					<div className="mt-4 flex items-center gap-2">
						<span className="text-xs text-subtle">
							{ index + 1 }/{ stops.length }
						</span>
						<Button
							className="ms-auto"
							variant="ghost"
							size="sm"
							onPress={ finish }
						>
							{ __( 'Skip', 'cliezoo' ) }
						</Button>
						<Button
							variant="primary"
							size="sm"
							// eslint-disable-next-line jsx-a11y/no-autofocus -- Each step moves focus on to its own control.
							autoFocus
							onPress={ () =>
								index + 1 < stops.length
									? setIndex( index + 1 )
									: finish()
							}
						>
							{ index + 1 < stops.length
								? __( 'Next', 'cliezoo' )
								: __( 'Done', 'cliezoo' ) }
						</Button>
					</div>
				</AriaDialog>
			</Modal>
		</ModalOverlay>
	);
}
