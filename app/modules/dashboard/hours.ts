/**
 * Minutes as a studio says them: 2h 30m, or 45m.
 *
 * In a file of its own because the top bar, the task panel and the reports
 * screen all want it, and importing it from the Time screen would pull the
 * screen — and everything the screen draws with — into the bundle that
 * loads on every page.
 *
 * "h" and "m" are English abbreviations and go through the catalogue like
 * any other word: German writes Std and Min, Russian ч and мин, and Arabic
 * writes them right to left. The digits go through the workspace's own
 * separators for the same reason.
 */
import { __, sprintf } from '@wordpress/i18n';
import { formatNumber } from '@cliezoo/data';

/**
 * Minutes said out loud.
 *
 * @param minutes How long.
 */
export function hours( minutes: number ): string {
	const whole = Math.floor( Math.abs( minutes ) / 60 );
	const rest = Math.abs( minutes ) % 60;
	const sign = minutes < 0 ? '−' : '';
	if ( 0 === whole ) {
		return (
			sign +
			sprintf(
				/* translators: %s: a number of minutes. Keep it short — this sits in a table cell and a top-bar button. */
				__( '%sm', 'cliezoo' ),
				formatNumber( rest )
			)
		);
	}
	if ( 0 === rest ) {
		return (
			sign +
			sprintf(
				/* translators: %s: a number of hours. Keep it short — this sits in a table cell and a top-bar button. */
				__( '%sh', 'cliezoo' ),
				formatNumber( whole )
			)
		);
	}
	return (
		sign +
		sprintf(
			/* translators: 1: a number of hours. 2: a number of minutes. Keep it short — this sits in a table cell and a top-bar button. */
			__( '%1$sh %2$sm', 'cliezoo' ),
			formatNumber( whole ),
			formatNumber( rest )
		)
	);
}
