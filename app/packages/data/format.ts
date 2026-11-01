/**
 * Workspace-aware formatting: money in minor units, numbers (incl. lakh/crore
 * grouping), dates and times in the workspace time zone.
 */
import { getBoot } from './boot';

const DECIMALS: Record< string, number > = {
	JPY: 0,
	KRW: 0,
	VND: 0,
	CLP: 0,
	ISK: 0,
	UGX: 0,
	XAF: 0,
	XOF: 0,
	BHD: 3,
	KWD: 3,
	OMR: 3,
	JOD: 3,
	TND: 3,
};

/**
 * Decimal places of a currency (ISO 4217 minor units).
 *
 * @param currency ISO code.
 */
export function currencyDecimals( currency: string ): number {
	return DECIMALS[ currency.toUpperCase() ] ?? 2;
}

/**
 * Money as somebody types it, from the minor units it is stored in.
 *
 * Money is held as whole minor units everywhere — 12500, not 125.00 —
 * because a penny that cannot be divided is a penny that cannot go
 * missing. A box a person types into holds the other thing. Keeping the
 * two straight is not optional: a payment box showing 12500 against a
 * heading reading $125.00 is one where somebody types 50 meaning fifty
 * dollars and is charged fifty cents.
 *
 * Empty in, empty out, so an untouched box stays untouched.
 *
 * @param minor    Amount in minor units, or null for an empty box.
 * @param currency ISO code, which decides how many places there are.
 */
export function moneyToInput(
	minor: number | null | undefined,
	currency: string
): string {
	if ( 'number' !== typeof minor ) {
		return '';
	}
	return String( minor / 10 ** currencyDecimals( currency ) );
}

/**
 * And back again, for what that box is sent as.
 *
 * @param typed    What is in the box.
 * @param currency ISO code.
 */
export function inputToMoney( typed: string, currency: string ): number {
	const value = Number( typed );
	if ( '' === typed.trim() || ! Number.isFinite( value ) ) {
		return 0;
	}
	return Math.round( value * 10 ** currencyDecimals( currency ) );
}

function group(
	digits: string,
	style: 'standard' | 'indian',
	sep: string
): string {
	if ( ! sep ) {
		return digits;
	}
	if ( style === 'indian' && digits.length > 3 ) {
		const last = digits.slice( -3 );
		const rest = digits
			.slice( 0, -3 )
			.replace( /\B(?=(\d{2})+(?!\d))/g, sep );
		return `${ rest }${ sep }${ last }`;
	}
	return digits.replace( /\B(?=(\d{3})+(?!\d))/g, sep );
}

/**
 * Formats a number with the workspace separators.
 *
 * @param value    Number.
 * @param decimals Decimal places.
 */
export function formatNumber( value: number, decimals = 0 ): string {
	const ws = getBoot().workspace;
	const negative = value < 0;
	const fixed = Math.abs( value ).toFixed( decimals );
	const [ int, frac ] = fixed.split( '.' );
	const grouped = group( int, ws.digit_grouping, ws.thousands_separator );
	return `${ negative ? '-' : '' }${ grouped }${ frac ? ws.decimal_separator + frac : '' }`;
}

/**
 * Formats a size in bytes the way people read one.
 *
 * Binary units, because that is what a disk quota is counted in and a
 * storage meter that disagrees with the host's is worse than none.
 *
 * @param bytes Size in bytes.
 */
export function formatBytes( bytes: number ): string {
	const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	let value = Math.max( 0, bytes );
	let unit = 0;
	while ( value >= 1024 && unit < units.length - 1 ) {
		value /= 1024;
		unit += 1;
	}
	// Whole bytes are whole; anything larger reads better with one place.
	return `${ formatNumber( value, 0 === unit ? 0 : 1 ) } ${ units[ unit ] }`;
}

/**
 * Formats money stored as integer minor units.
 *
 * @param minor    Amount in minor units (e.g. cents).
 * @param currency ISO code (defaults to the workspace currency).
 */
export function formatMoney( minor: number, currency?: string ): string {
	const ws = getBoot().workspace;
	const code = ( currency || ws.currency || 'USD' ).toUpperCase();
	const decimals = currencyDecimals( code );
	const amount = formatNumber( Math.abs( minor ) / 10 ** decimals, decimals );
	let symbol = code;
	try {
		symbol =
			new Intl.NumberFormat( getBoot().locale.replace( '_', '-' ), {
				style: 'currency',
				currency: code,
				currencyDisplay: 'narrowSymbol',
			} )
				.formatToParts( 0 )
				.find( ( part ) => part.type === 'currency' )?.value ?? code;
	} catch {
		// Unknown to this browser: fall back to the ISO code.
	}
	const sign = minor < 0 ? '-' : '';
	switch ( ws.currency_symbol_position ) {
		case 'after':
			return `${ sign }${ amount }${ symbol }`;
		case 'after_space':
			return `${ sign }${ amount } ${ symbol }`;
		case 'before_space':
			return `${ sign }${ symbol } ${ amount }`;
		default:
			return `${ sign }${ symbol }${ amount }`;
	}
}

const PATTERNS: Record< string, Intl.DateTimeFormatOptions > = {
	'MM/DD/YYYY': { month: '2-digit', day: '2-digit', year: 'numeric' },
	'DD/MM/YYYY': { day: '2-digit', month: '2-digit', year: 'numeric' },
	'YYYY-MM-DD': { year: 'numeric', month: '2-digit', day: '2-digit' },
	'MMM D, YYYY': { month: 'short', day: 'numeric', year: 'numeric' },
	'D MMM YYYY': { day: 'numeric', month: 'short', year: 'numeric' },
};

function zone( timezone?: string ): string {
	const tz =
		timezone ||
		getBoot().user?.timezone ||
		getBoot().workspace.timezone ||
		'UTC';
	const offset = /^UTC([+-])(\d{1,2})(?::(\d{2}))?$/.exec( tz );
	if ( offset ) {
		// Etc/GMT zones have inverted signs and whole hours only.
		return offset[ 3 ] && offset[ 3 ] !== '00'
			? 'UTC'
			: `Etc/GMT${ offset[ 1 ] === '+' ? '-' : '+' }${ Number( offset[ 2 ] ) }`;
	}
	return tz;
}

function parts(
	date: Date,
	options: Intl.DateTimeFormatOptions,
	timezone?: string
): Record< string, string > {
	const out: Record< string, string > = {};
	new Intl.DateTimeFormat( 'en-US', {
		...options,
		timeZone: zone( timezone ),
	} )
		.formatToParts( date )
		.forEach( ( p ) => {
			out[ p.type ] = p.value;
		} );
	return out;
}

/**
 * A moment picked in a `datetime-local` box, as an instant.
 *
 * That box gives back "2026-09-26T14:30" with no zone on it at all, and
 * WordPress pins PHP's default zone to UTC — so a studio in Dhaka asking
 * for half past two got half past eight in the evening. Sent with the
 * offset the person picked it in, there is nothing left to guess.
 *
 * @param typed What the box holds, or ''.
 */
export function pickedMoment( typed: string ): string {
	if ( '' === typed.trim() ) {
		return '';
	}
	const when = new Date( typed );
	return Number.isNaN( when.getTime() ) ? '' : when.toISOString();
}

/**
 * Today, as a "YYYY-MM-DD" date, in the workspace's time zone.
 *
 * Not `toISOString().slice( 0, 10 )`, which is today in UTC: for half the
 * world that is yesterday or tomorrow for part of every day, and an
 * invoice marked overdue six hours early is a client chased early.
 */
export function todayHere(): string {
	// "en-CA" because its short date format is exactly YYYY-MM-DD.
	return new Intl.DateTimeFormat( 'en-CA', {
		timeZone: zone( undefined ),
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	} ).format( new Date() );
}

/**
 * Formats a date the long way round — weekday, month and year — in the
 * workspace's language and time zone. For headings that name today.
 *
 * @param value Date string or Date.
 */
export function formatFullDate( value: string | Date ): string {
	const date = typeof value === 'string' ? new Date( value ) : value;
	if ( Number.isNaN( date.getTime() ) ) {
		return '';
	}
	return new Intl.DateTimeFormat( getBoot().locale.replace( '_', '-' ), {
		dateStyle: 'full',
		timeZone: zone( undefined ),
	} ).format( date );
}

/**
 * Formats a date (a "YYYY-MM-DD" date or an ISO/UTC datetime) in the workspace format.
 *
 * @param value    Date string or Date.
 * @param timezone Override time zone (datetimes only).
 */
export function formatDate(
	value: string | Date | null | undefined,
	timezone?: string
): string {
	if ( ! value ) {
		return '';
	}
	const pattern =
		getBoot().user?.dateFormat || getBoot().workspace.date_format;
	const isDateOnly =
		typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test( value );
	const date =
		typeof value === 'string'
			? new Date(
					isDateOnly
						? `${ value }T12:00:00Z`
						: value.replace( ' ', 'T' ) +
								( /[zZ]|[+-]\d\d:?\d\d$/.test( value )
									? ''
									: 'Z' )
				)
			: value;
	if ( Number.isNaN( date.getTime() ) ) {
		return '';
	}
	const options = PATTERNS[ pattern ] ?? PATTERNS[ 'MMM D, YYYY' ];
	const tz = isDateOnly ? 'UTC' : timezone;
	if (
		pattern === 'YYYY-MM-DD' ||
		pattern === 'MM/DD/YYYY' ||
		pattern === 'DD/MM/YYYY'
	) {
		const p = parts( date, options, tz );
		if ( pattern === 'YYYY-MM-DD' ) {
			return `${ p.year }-${ p.month }-${ p.day }`;
		}
		return pattern === 'MM/DD/YYYY'
			? `${ p.month }/${ p.day }/${ p.year }`
			: `${ p.day }/${ p.month }/${ p.year }`;
	}
	return new Intl.DateTimeFormat( getBoot().locale.replace( '_', '-' ), {
		...options,
		timeZone: zone( tz ),
	} ).format( date );
}

/**
 * Formats a time of day in the workspace 12/24-hour style.
 *
 * @param value    ISO/UTC datetime.
 * @param timezone Override time zone.
 */
export function formatTime( value: string | Date, timezone?: string ): string {
	const date =
		typeof value === 'string'
			? new Date(
					value.replace( ' ', 'T' ) +
						( /[zZ]|[+-]\d\d:?\d\d$/.test( value ) ? '' : 'Z' )
				)
			: value;
	return new Intl.DateTimeFormat( getBoot().locale.replace( '_', '-' ), {
		hour: 'numeric',
		minute: '2-digit',
		hour12:
			( getBoot().user?.timeFormat ||
				getBoot().workspace.time_format ) !== '24',
		timeZone: zone( timezone ),
	} ).format( date );
}

/**
 * "3 min ago" style relative time.
 *
 * @param value ISO/UTC datetime.
 * @param now   Reference time (tests).
 */
export function formatRelative(
	value: string | Date,
	now: Date = new Date()
): string {
	const date =
		typeof value === 'string'
			? new Date(
					value.replace( ' ', 'T' ) +
						( /[zZ]|[+-]\d\d:?\d\d$/.test( value ) ? '' : 'Z' )
				)
			: value;
	const seconds = Math.round( ( date.getTime() - now.getTime() ) / 1000 );
	const rtf = new Intl.RelativeTimeFormat(
		getBoot().locale.replace( '_', '-' ),
		{ numeric: 'auto' }
	);
	const units: Array< [ Intl.RelativeTimeFormatUnit, number ] > = [
		[ 'year', 31_536_000 ],
		[ 'month', 2_592_000 ],
		[ 'week', 604_800 ],
		[ 'day', 86_400 ],
		[ 'hour', 3_600 ],
		[ 'minute', 60 ],
	];
	for ( const [ unit, size ] of units ) {
		if ( Math.abs( seconds ) >= size ) {
			return rtf.format( Math.round( seconds / size ), unit );
		}
	}
	return rtf.format( seconds, 'second' );
}
