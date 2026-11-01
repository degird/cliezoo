/**
 * "Send tomorrow at 9:00" — in the sender's timezone.
 *
 * The person writing is the one who knows what "tomorrow morning" means,
 * so the times offered are wall-clock times where they are, not where the
 * browser or the server happens to be. Somebody in Lisbon on a laptop set
 * to New York gets 9:00 in Lisbon, because that is what their profile says
 * and what they meant.
 */

export interface SendTime {
	id: 'tomorrow' | 'two-days' | 'next-week';
	/** The moment, as ISO 8601 in UTC. */
	at: string;
}

/**
 * The parts of a moment as a clock in a timezone reads them.
 *
 * @param when     The moment.
 * @param timezone IANA zone.
 */
function partsIn(
	when: Date,
	timezone: string
): { y: number; m: number; d: number; h: number; min: number; dow: number } {
	const format = new Intl.DateTimeFormat( 'en-US', {
		timeZone: timezone,
		year: 'numeric',
		month: 'numeric',
		day: 'numeric',
		hour: 'numeric',
		minute: 'numeric',
		weekday: 'short',
		hourCycle: 'h23',
	} );
	const got: Record< string, string > = {};
	for ( const part of format.formatToParts( when ) ) {
		got[ part.type ] = part.value;
	}
	const days = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
	return {
		y: Number( got.year ),
		m: Number( got.month ),
		d: Number( got.day ),
		h: Number( got.hour ) % 24,
		min: Number( got.minute ),
		dow: Math.max( 0, days.indexOf( String( got.weekday ) ) ),
	};
}

/**
 * The moment a clock in a timezone shows a given date and time.
 *
 * Guessed as if the zone were UTC, then corrected by however far off the
 * zone's clock is — twice, so a guess that lands across a daylight-saving
 * change settles on the right side of it.
 *
 * @param y        Year.
 * @param m        Month, 1–12.
 * @param d        Day of the month (may overflow; the calendar carries it).
 * @param h        Hour.
 * @param min      Minute.
 * @param timezone IANA zone.
 */
export function wallClock(
	y: number,
	m: number,
	d: number,
	h: number,
	min: number,
	timezone: string
): Date {
	const wanted = Date.UTC( y, m - 1, d, h, min );
	let guess = wanted;
	for ( let i = 0; i < 2; i++ ) {
		const seen = partsIn( new Date( guess ), timezone );
		const shown = Date.UTC( seen.y, seen.m - 1, seen.d, seen.h, seen.min );
		guess += wanted - shown;
	}
	return new Date( guess );
}

/**
 * Whether a timezone name is one this browser knows.
 *
 * @param timezone IANA zone.
 */
function known( timezone: string ): boolean {
	try {
		new Intl.DateTimeFormat( 'en-US', { timeZone: timezone } );
		return true;
	} catch {
		return false;
	}
}

/**
 * The quick choices in "Send ▾": tomorrow, in two days and next Monday,
 * each at 9:00 where the sender is.
 *
 * @param now      The moment to count from.
 * @param timezone The sender's zone.
 */
export function sendTimes( now: Date, timezone: string ): SendTime[] {
	const zone = timezone && known( timezone ) ? timezone : 'UTC';
	const today = partsIn( now, zone );
	const at = ( days: number ) =>
		wallClock( today.y, today.m, today.d + days, 9, 0, zone ).toISOString();
	// Monday is day 1; from a Monday, "next week" is the Monday after.
	const toMonday = ( 8 - today.dow ) % 7 || 7;
	return [
		{ id: 'tomorrow', at: at( 1 ) },
		{ id: 'two-days', at: at( 2 ) },
		{ id: 'next-week', at: at( toMonday ) },
	];
}

/**
 * A `datetime-local` value ("2026-09-22T14:30") read in the sender's zone.
 *
 * @param value    What the field holds.
 * @param timezone The sender's zone.
 * @return ISO 8601 in UTC, or null when it is not a time.
 */
export function fromLocalInput(
	value: string,
	timezone: string
): string | null {
	const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec( value );
	if ( ! match ) {
		return null;
	}
	const zone = timezone && known( timezone ) ? timezone : 'UTC';
	return wallClock(
		Number( match[ 1 ] ),
		Number( match[ 2 ] ),
		Number( match[ 3 ] ),
		Number( match[ 4 ] ),
		Number( match[ 5 ] ),
		zone
	).toISOString();
}
