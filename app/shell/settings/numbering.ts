/**
 * Record number patterns, as the numbering settings build and read them.
 * The server (Numbering::format) is the one that hands numbers out; this
 * only has to agree with it for the preview.
 */

/** Date parts a number can carry, in the order people usually want them. */
export const DATE_PARTS = [ '', '{YYYY}', '{YY}', '{YYYY}{MM}', '{YY}{MM}' ];

export interface PatternParts {
	/** How many digits the counter is padded to. */
	digits: number;
	/** One of DATE_PARTS. */
	date: string;
}

/**
 * The pattern for a set of choices: prefix, date, a dash, then the counter.
 *
 * @param parts Choices.
 */
export function buildPattern( parts: PatternParts ): string {
	const digits = Math.min( 12, Math.max( 1, Math.round( parts.digits ) ) );
	const date = DATE_PARTS.includes( parts.date ) ? parts.date : '';
	return `{PREFIX}${ date }${ date ? '-' : '' }{${ '#'.repeat( digits ) }}`;
}

/**
 * The choices a pattern was built from, or null when somebody wrote it by
 * hand in a shape the simple controls cannot show.
 *
 * @param pattern Pattern.
 */
export function parsePattern( pattern: string ): PatternParts | null {
	const found =
		/^\{PREFIX\}((?:\{YYYY\}|\{YY\})(?:\{MM\})?)?(-?)\{(#{1,12})\}$/.exec(
			pattern
		);
	if ( ! found ) {
		return null;
	}
	const date = found[ 1 ] ?? '';
	// A date with no dash after it, or a dash with no date, is not what
	// buildPattern makes.
	if ( ( '' === date ) !== ( '' === found[ 2 ] ) ) {
		return null;
	}
	return { digits: found[ 3 ].length, date };
}

/**
 * Whether the server will accept a pattern: one {#…} counter, and only
 * the tokens it fills in.
 *
 * @param pattern Pattern.
 */
export function validPattern( pattern: string ): boolean {
	const counters = pattern.match( /\{#{1,12}\}/g ) ?? [];
	if ( 1 !== counters.length ) {
		return false;
	}
	return ! /[{}]/.test(
		pattern.replace( /\{(#{1,12}|PREFIX|YYYY|YY|MM|DD)\}/g, '' )
	);
}

/**
 * What the next number will look like.
 *
 * @param prefix  Prefix.
 * @param pattern Pattern.
 * @param value   Counter value.
 * @param now     The date to show.
 */
export function previewNumber(
	prefix: string,
	pattern: string,
	value: number,
	now: Date = new Date()
): string {
	const two = ( n: number ) => String( n ).padStart( 2, '0' );
	return pattern
		.replace( /\{PREFIX\}/g, prefix )
		.replace( /\{YYYY\}/g, String( now.getFullYear() ) )
		.replace( /\{YY\}/g, String( now.getFullYear() ).slice( -2 ) )
		.replace( /\{MM\}/g, two( now.getMonth() + 1 ) )
		.replace( /\{DD\}/g, two( now.getDate() ) )
		.replace( /\{(#+)\}/g, ( ignored, hashes: string ) =>
			String( Math.max( 1, value ) ).padStart( hashes.length, '0' )
		);
}
