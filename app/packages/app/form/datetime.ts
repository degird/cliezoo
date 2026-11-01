/**
 * A moment as a date-and-time box shows it, and back again.
 *
 * The server keeps moments in UTC; a person reads and types them in their own
 * time. A box shown the UTC figure and saved as local moved a meeting by the
 * difference every time it was edited — so both directions go through here.
 */

const pad = ( n: number ) => String( n ).padStart( 2, '0' );

/**
 * What a datetime-local box shows for a stored moment ("" for none).
 *
 * @param value The stored value: ISO with a zone, or "Y-m-d H:i:s" in UTC.
 */
export function toLocalInput( value: unknown ): string {
	if ( 'string' !== typeof value || '' === value ) {
		return '';
	}
	// A bare "2026-09-30 09:00:00" is the server's UTC, said without a zone.
	const bare = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/.test( value );
	const when = new Date( bare ? `${ value.replace( ' ', 'T' ) }Z` : value );
	if ( Number.isNaN( when.getTime() ) ) {
		return '';
	}
	return (
		`${ when.getFullYear() }-${ pad( when.getMonth() + 1 ) }-${ pad(
			when.getDate()
		) }` + `T${ pad( when.getHours() ) }:${ pad( when.getMinutes() ) }`
	);
}

/**
 * What is saved for what was typed in a datetime-local box (null for none).
 *
 * @param typed "2026-09-30T10:00", in the reader's own time.
 */
export function fromLocalInput( typed: string ): string | null {
	if ( '' === typed ) {
		return null;
	}
	const when = new Date( typed );
	return Number.isNaN( when.getTime() ) ? null : when.toISOString();
}
