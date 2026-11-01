/**
 * The controls a recording needs beyond the ones the browser draws.
 *
 * Play, pause, volume, the scrub bar and full screen are the browser's
 * own, which people already know and screen readers already speak. What
 * they do not give is a moment written the way a reviewer needs it —
 * comments on a recording are made at a time, and what is written down
 * has to match what the other side reads — or a way to slow a recording
 * down. So this adds those two, beside the player.
 */
import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Select } from '@cliezoo/ui';

export type TimeFormat = 'short' | 'long' | 'seconds';

/** Where the chosen format is kept, per person and per browser. */
const KEY = 'cliezoo-media-timecode';

/** What the speed control offers. */
export const SPEEDS = [ 0.5, 0.75, 1, 1.25, 1.5, 2 ];

/**
 * A moment written in one of the ways people ask for.
 *
 * @param seconds How far in.
 * @param format  short: 1:05 · long: 00:01:05 · seconds: 65.0s
 */
export function timeIn( seconds: number, format: TimeFormat ): string {
	const all = Math.max( 0, seconds );
	if ( 'seconds' === format ) {
		return sprintf(
			/* translators: %s: a number of seconds, to one decimal place. */
			__( '%ss', 'cliezoo' ),
			all.toFixed( 1 )
		);
	}
	const whole = Math.floor( all );
	const h = Math.floor( whole / 3600 );
	const m = Math.floor( ( whole % 3600 ) / 60 );
	const s = String( whole % 60 ).padStart( 2, '0' );
	if ( 'long' === format ) {
		return `${ String( h ).padStart( 2, '0' ) }:${ String( m ).padStart(
			2,
			'0'
		) }:${ s }`;
	}
	return h
		? `${ h }:${ String( m ).padStart( 2, '0' ) }:${ s }`
		: `${ m }:${ s }`;
}

/**
 * Reads the format somebody chose last time.
 */
export function savedFormat(): TimeFormat {
	try {
		const kept = window.localStorage.getItem( KEY );
		if ( 'long' === kept || 'seconds' === kept || 'short' === kept ) {
			return kept;
		}
	} catch {
		// A browser that refuses storage still plays recordings.
	}
	return 'short';
}

/**
 * Where a recording is, how fast it is playing, and how the time is
 * written.
 *
 * @param root0        Props.
 * @param root0.player The audio or video element.
 * @param root0.label  What is playing, for the controls' group label.
 */
export function MediaControls( {
	player,
	label,
}: {
	player: React.RefObject< HTMLMediaElement | null >;
	label: string;
} ) {
	const [ format, setFormat ] = useState< TimeFormat >( savedFormat );
	const [ speed, setSpeed ] = useState( 1 );
	const [ at, setAt ] = useState( 0 );
	const [ length, setLength ] = useState( 0 );

	useEffect( () => {
		const media = player.current;
		if ( ! media ) {
			return undefined;
		}
		const moved = () => setAt( media.currentTime );
		const sized = () =>
			setLength( Number.isFinite( media.duration ) ? media.duration : 0 );
		const sped = () => setSpeed( media.playbackRate );
		media.addEventListener( 'timeupdate', moved );
		media.addEventListener( 'loadedmetadata', sized );
		media.addEventListener( 'durationchange', sized );
		media.addEventListener( 'ratechange', sped );
		sized();
		moved();
		return () => {
			media.removeEventListener( 'timeupdate', moved );
			media.removeEventListener( 'loadedmetadata', sized );
			media.removeEventListener( 'durationchange', sized );
			media.removeEventListener( 'ratechange', sped );
		};
	}, [ player ] );

	const keep = ( chosen: TimeFormat ) => {
		setFormat( chosen );
		try {
			window.localStorage.setItem( KEY, chosen );
		} catch {
			// Not being able to remember it is not a reason to refuse it.
		}
	};

	return (
		<div
			role="group"
			aria-label={ sprintf(
				/* translators: %s: a file name. */
				__( 'Controls for %s', 'cliezoo' ),
				label
			) }
			className="flex flex-wrap items-end justify-between gap-3 px-4 pb-3"
		>
			{ /*
			 * Not a live region: this changes four times a second while
			 * something is playing, and a live region would read the
			 * running clock straight over the recording. The player's own
			 * scrub bar is a slider and already says where it is; this is
			 * the same moment written the way a reviewer needs to quote it,
			 * there to be read when somebody goes looking for it.
			 */ }
			<p className="font-mono text-xs tabular-nums text-muted">
				{ length
					? sprintf(
							/* translators: 1: how far in, 2: how long it is. */
							__( '%1$s of %2$s', 'cliezoo' ),
							timeIn( at, format ),
							timeIn( length, format )
						)
					: timeIn( at, format ) }
			</p>
			<div className="flex flex-wrap items-end gap-2">
				<Select
					label={ __( 'Time shown as', 'cliezoo' ) }
					className="w-40"
					items={ [
						{ id: 'short', label: __( '1:05', 'cliezoo' ) },
						{ id: 'long', label: __( '00:01:05', 'cliezoo' ) },
						{ id: 'seconds', label: __( '65.0s', 'cliezoo' ) },
					] }
					selectedKey={ format }
					onSelectionChange={ ( key: unknown ) =>
						keep( String( key ) as TimeFormat )
					}
				/>
				<Select
					label={ __( 'Speed', 'cliezoo' ) }
					className="w-28"
					items={ SPEEDS.map( ( rate ) => ( {
						id: String( rate ),
						label: sprintf(
							/* translators: %s: a playback speed, e.g. 1.5. */
							__( '%s×', 'cliezoo' ),
							String( rate )
						),
					} ) ) }
					selectedKey={ String( speed ) }
					onSelectionChange={ ( key: unknown ) => {
						const rate = Number( key );
						setSpeed( rate );
						if ( player.current ) {
							player.current.playbackRate = rate;
						}
					} }
				/>
			</div>
		</div>
	);
}
