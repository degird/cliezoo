/**
 * What time it is where somebody is: "4:05 PM there", kept up to date.
 * Nothing when the zone is unknown or the same as the reader's own.
 */
import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';

/**
 * The time now in a zone, or '' when the zone is not one the browser knows.
 *
 * @param zone IANA zone ("Europe/Paris").
 * @param now  The moment.
 */
export function timeIn( zone: string, now: Date = new Date() ): string {
	if ( ! zone ) {
		return '';
	}
	try {
		return new Intl.DateTimeFormat( undefined, {
			hour: 'numeric',
			minute: '2-digit',
			timeZone: zone,
		} ).format( now );
	} catch {
		return '';
	}
}

export function LocalTime( { zone }: { zone: string } ) {
	const [ now, setNow ] = useState( () => new Date() );
	useEffect( () => {
		const timer = window.setInterval( () => setNow( new Date() ), 60_000 );
		return () => window.clearInterval( timer );
	}, [] );
	const theirs = timeIn( zone, now );
	const ours = Intl.DateTimeFormat().resolvedOptions().timeZone;
	if ( ! theirs || zone === ours ) {
		return null;
	}
	return (
		<span title={ zone }>
			{ sprintf(
				/* translators: %s: a time of day, e.g. "4:05 PM". */
				__( '%s their time', 'cliezoo' ),
				theirs
			) }
		</span>
	);
}
