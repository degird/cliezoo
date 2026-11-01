/**
 * Sort levels and column order for the universal list (cliezoo.md §6.2).
 *
 * A sort travels as the server reads it — "status,-due_date" is by status,
 * then by due date newest first — and these helpers are the one place that
 * reads and writes that string, so the header, the sort menu and a saved
 * view cannot disagree about it.
 */

/** The most fields a list is sorted by; the server says the same. */
export const SORT_LEVELS = 3;

export interface SortLevel {
	key: string;
	desc: boolean;
}

/**
 * The levels of a sort string.
 *
 * @param sort "status,-due_date".
 */
export function parseSort( sort: string ): SortLevel[] {
	const levels: SortLevel[] = [];
	for ( const part of ( sort ?? '' ).split( ',' ) ) {
		const text = part.trim();
		const key = text.replace( /^-/, '' );
		if ( key && ! levels.some( ( level ) => level.key === key ) ) {
			levels.push( { key, desc: text.startsWith( '-' ) } );
		}
	}
	return levels.slice( 0, SORT_LEVELS );
}

/**
 * The sort string for some levels.
 *
 * @param levels Levels, first first.
 */
export function formatSort( levels: SortLevel[] ): string {
	return levels
		.slice( 0, SORT_LEVELS )
		.map( ( level ) => ( level.desc ? '-' : '' ) + level.key )
		.join( ',' );
}

/**
 * What clicking a column header does to the sort.
 *
 * A plain click sorts by that column alone, and a second click turns it
 * round. With Shift held, the column is added as the next level instead;
 * shift-clicking a level again turns it round, and a third time takes it
 * off (unless it is the only one).
 *
 * @param sort Current sort string.
 * @param key  The column.
 * @param add  Whether Shift was held (add a level).
 */
export function nextSort( sort: string, key: string, add: boolean ): string {
	const levels = parseSort( sort );
	const at = levels.findIndex( ( level ) => level.key === key );
	if ( ! add ) {
		if ( levels.length === 1 && at === 0 ) {
			return formatSort( [ { key, desc: ! levels[ 0 ].desc } ] );
		}
		return formatSort( [ { key, desc: false } ] );
	}
	if ( at < 0 ) {
		const next = [ ...levels, { key, desc: false } ];
		// Full: the newest level takes the last place.
		if ( next.length > SORT_LEVELS ) {
			next.splice( SORT_LEVELS - 1, 1 );
		}
		return formatSort( next );
	}
	if ( ! levels[ at ].desc ) {
		return formatSort(
			levels.map( ( level, index ) =>
				index === at ? { ...level, desc: true } : level
			)
		);
	}
	if ( levels.length === 1 ) {
		return formatSort( [ { key, desc: false } ] );
	}
	return formatSort( levels.filter( ( _, index ) => index !== at ) );
}

/**
 * Where a column is in the sort, for its header: its level (1-based) and
 * direction, or nothing.
 *
 * @param sort Sort string.
 * @param key  The column.
 */
export function sortLevelOf(
	sort: string,
	key: string
): { level: number; desc: boolean; of: number } | null {
	const levels = parseSort( sort );
	const at = levels.findIndex( ( level ) => level.key === key );
	return at < 0
		? null
		: { level: at + 1, desc: levels[ at ].desc, of: levels.length };
}

/**
 * Moves a column to a place among the shown ones.
 *
 * The column picker's drag and its "move left / move right" buttons both
 * end here. The record's name stays first: it is what a row is.
 *
 * @param columns Column keys, in order.
 * @param key     The column that moves.
 * @param index   Where it lands (0-based, in the new order).
 * @param pinned  A column that stays first (the title field).
 */
export function moveColumn(
	columns: string[],
	key: string,
	index: number,
	pinned?: string
): string[] {
	const from = columns.indexOf( key );
	if ( from < 0 || key === pinned ) {
		return columns;
	}
	const floor = pinned && columns[ 0 ] === pinned ? 1 : 0;
	const target = Math.max( floor, Math.min( columns.length - 1, index ) );
	if ( target === from ) {
		return columns;
	}
	const next = [ ...columns ];
	next.splice( from, 1 );
	next.splice( target, 0, key );
	return next;
}
