/**
 * What the dashboard reads and keeps: the numbers for a period, what can
 * be shown, the business health score, and each person's dashboards.
 */
import { createContext, useContext } from 'react';
import { __ } from '@wordpress/i18n';
import { api, formatMoney, keepPreviousData, useQuery } from '@cliezoo/data';
import { hours } from './hours';

export type Range =
	| 'today'
	| '7d'
	| '30d'
	| '90d'
	| 'month'
	| 'last_month'
	| 'quarter'
	| 'year'
	| 'custom';

export type Size = 'sm' | 'md' | 'lg';

export interface Card {
	widget: string;
	size: Size;
	settings?: Record< string, string | string[] | boolean >;
}

export interface Dashboard {
	uid: string;
	name: string;
	position: number;
	layout: Card[] | string | null;
	period: Range;
	period_from: string | null;
	period_to: string | null;
	compare: boolean;
}

export interface Drill {
	path: string;
	filter?: unknown;
}

export interface Metric {
	label: string;
	/** Whether the server could count this at all. */
	measured: boolean;
	/** The count, or null when it was not measured — never a stand-in nought. */
	value: number | null;
	/** One line saying why it was not measured; empty when it was. */
	reason: string;
	previous: number | null;
	ranged: boolean;
	format: 'number' | 'money' | 'hours' | 'percent';
	tone: 'good-when-up' | 'bad-when-up';
	drill: Drill | null;
}

export interface MetricInfo {
	key: string;
	label: string;
	group: string;
	ranged: boolean;
}

export interface HealthPart {
	key: string;
	label: string;
	weight: number;
	measured: boolean;
	score: number | null;
	value: string;
	explain: string;
}

export interface Health {
	score: number | null;
	grade: '' | 'good' | 'fair' | 'poor';
	/** The score as a letter, A to F; empty when nothing is measured. */
	letter: '' | 'A' | 'B' | 'C' | 'D' | 'F';
	parts: HealthPart[];
}

export interface Period {
	range: Range;
	from?: string;
	to?: string;
	compare: boolean;
}

export const RANGES = (): Array< { id: Range; label: string } > => [
	{ id: 'today', label: __( 'Today', 'cliezoo' ) },
	{ id: '7d', label: __( 'Last 7 days', 'cliezoo' ) },
	{ id: '30d', label: __( 'Last 30 days', 'cliezoo' ) },
	{ id: '90d', label: __( 'Last 90 days', 'cliezoo' ) },
	{ id: 'month', label: __( 'This month', 'cliezoo' ) },
	{ id: 'last_month', label: __( 'Last month', 'cliezoo' ) },
	{ id: 'quarter', label: __( 'This quarter', 'cliezoo' ) },
	{ id: 'year', label: __( 'This year', 'cliezoo' ) },
	{ id: 'custom', label: __( 'Between dates…', 'cliezoo' ) },
];

/**
 * What a card is told: the period the dashboard looks at, and its own
 * settings. Outside a dashboard (on Home) it looks at the last thirty days.
 */
export interface CardContextValue {
	period: Period;
	settings: Card[ 'settings' ];
	editing: boolean;
	change: ( settings: NonNullable< Card[ 'settings' ] > ) => void;
}

export const CardContext = createContext< CardContextValue >( {
	period: { range: '30d', compare: false },
	settings: undefined,
	editing: false,
	change: () => undefined,
} );

export function useCard(): CardContextValue {
	return useContext( CardContext );
}

/**
 * A dashboard's cards, however the server handed them over.
 *
 * @param layout Stored layout.
 */
export function cardsOf( layout: Dashboard[ 'layout' ] ): Card[] {
	let cards: unknown = layout;
	if ( typeof layout === 'string' ) {
		try {
			cards = JSON.parse( layout );
		} catch {
			cards = [];
		}
	}
	return Array.isArray( cards )
		? cards.filter(
				( card ): card is Card =>
					!! card && typeof card.widget === 'string'
			)
		: [];
}

/**
 * The numbers asked for, for a period.
 *
 * @param keys   Metric keys.
 * @param period The period.
 */
export function useMetrics( keys: string[], period: Period ) {
	return useQuery( {
		queryKey: [ 'dashboard', 'metrics', keys, period ],
		enabled: keys.length > 0,
		placeholderData: keepPreviousData,
		queryFn: async () =>
			(
				await api.get< {
					period: { range: Range; first: string; last: string };
					metrics: Record< string, Metric >;
				} >( '/dashboard/metrics', {
					keys: keys.join( ',' ),
					range: period.range,
					...( period.range === 'custom'
						? { from: period.from, to: period.to }
						: {} ),
					...( period.compare ? { compare: true } : {} ),
				} )
			).data,
	} );
}

/**
 * Whether the numbers on screen are still the last period's.
 *
 * `keepPreviousData` holds the old answer while the new one is fetched,
 * which stops the card collapsing to a skeleton every time somebody
 * changes the period. The label above it changes at once, though, so
 * drawing the held numbers puts last quarter's figures under this
 * quarter's heading and nothing says so. Until the new answer lands they
 * are not this period's numbers, so they are not shown as though they
 * were.
 *
 * @param query                   What `useMetrics` returned.
 * @param query.isPending         Whether nothing has arrived at all.
 * @param query.isPlaceholderData Whether what is there is the last answer.
 */
export function awaiting( query: {
	isPending: boolean;
	isPlaceholderData: boolean;
} ): boolean {
	return query.isPending || query.isPlaceholderData;
}

export function useCatalog() {
	return useQuery( {
		queryKey: [ 'dashboard', 'catalog' ],
		staleTime: 5 * 60_000,
		queryFn: async () =>
			(
				await api.get< { metrics: MetricInfo[]; starter: Card[] } >(
					'/dashboard/catalog'
				)
			).data,
	} );
}

export function useHealth() {
	return useQuery( {
		queryKey: [ 'dashboard', 'health' ],
		queryFn: async () =>
			( await api.get< Health >( '/dashboard/health' ) ).data,
	} );
}

/**
 * Today in the site's calendar, as the browser sees it (Y-m-d).
 */
export function today(): string {
	const now = new Date();
	const pad = ( n: number ) => String( n ).padStart( 2, '0' );
	return `${ now.getFullYear() }-${ pad( now.getMonth() + 1 ) }-${ pad(
		now.getDate()
	) }`;
}

/**
 * The local day (Y-m-d) of a moment, however it was written down.
 *
 * @param at An ISO date-time, or a day.
 */
export function localDay( at: string | Date ): string {
	const when =
		typeof at === 'string' && /^\d{4}-\d{2}-\d{2}$/.test( at )
			? new Date( `${ at }T00:00:00` )
			: new Date( at );
	if ( Number.isNaN( when.getTime() ) ) {
		return typeof at === 'string' ? at.slice( 0, 10 ) : '';
	}
	const pad = ( n: number ) => String( n ).padStart( 2, '0' );
	return `${ when.getFullYear() }-${ pad( when.getMonth() + 1 ) }-${ pad(
		when.getDate()
	) }`;
}

/**
 * A day some days from today, local (Y-m-d).
 *
 * @param days How many days on (or back, when negative).
 */
export function daysFromToday( days: number ): string {
	const day = new Date();
	day.setDate( day.getDate() + days );
	return localDay( day );
}

/**
 * Where a number leads: the list of what it counts, filtered to it.
 * "@today" in a filter is today's date.
 *
 * @param drill Where the number leads.
 */
export function drillLink( drill: Drill ): {
	to: string;
	search?: { filter: unknown };
} {
	if ( ! drill.filter ) {
		return { to: drill.path };
	}
	return {
		to: drill.path,
		search: {
			filter: JSON.parse(
				JSON.stringify( drill.filter ).replaceAll(
					'"@today"',
					JSON.stringify( today() )
				)
			),
		},
	};
}

/**
 * A number as it is shown.
 *
 * @param value  The number.
 * @param format How it is counted.
 */
export function formatMetric( value: number, format: Metric[ 'format' ] ) {
	if ( format === 'percent' ) {
		return `${ Math.round( value ) }%`;
	}
	// Money is kept in the smallest unit and time in minutes, so neither is
	// the number anybody reads: 120000 is twelve hundred dollars and 90 is an
	// hour and a half. Both go through the formatter the rest of the product
	// uses, so a total on the dashboard reads the same as the same total on
	// the invoice it came from.
	if ( format === 'money' ) {
		return formatMoney( value );
	}
	if ( format === 'hours' ) {
		return hours( value );
	}
	return new Intl.NumberFormat( undefined, {
		maximumFractionDigits: 0,
	} ).format( value );
}

/**
 * How a number moved against the period before: up, down or level, and
 * whether that is good.
 *
 * @param metric The number.
 */
export function change( metric: Metric ): {
	direction: 'up' | 'down' | 'level';
	percent: number | null;
	good: boolean;
} | null {
	// Nothing moved if we never measured where it is now.
	if ( metric.previous === null || metric.value === null ) {
		return null;
	}
	const diff = metric.value - metric.previous;
	const direction = diff > 0 ? 'up' : 'down';
	const good =
		diff === 0 ||
		( direction === 'up' ) === ( metric.tone !== 'bad-when-up' );
	return {
		direction: diff === 0 ? 'level' : direction,
		percent:
			metric.previous === 0
				? null
				: Math.round( ( diff / metric.previous ) * 100 ),
		good,
	};
}

/**
 * An app path, possibly with a query ("/tasks?task=…"), as a link.
 *
 * @param path The path.
 */
export function linkOf( path: string ): {
	to: string;
	search?: Record< string, string >;
} {
	const [ to, query ] = path.split( '?' );
	if ( ! query ) {
		return { to };
	}
	return { to, search: Object.fromEntries( new URLSearchParams( query ) ) };
}
