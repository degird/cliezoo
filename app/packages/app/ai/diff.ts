/**
 * What changed between two texts, word by word, for the draft preview.
 */

export interface DiffPart {
	kind: 'same' | 'added' | 'removed';
	text: string;
}

/** Beyond this many words on a side, the preview shows both texts whole. */
const MOST = 1500;

/**
 * Splits text into words and the spaces between them, so joining the
 * parts gives the text back exactly.
 *
 * @param text The text.
 */
function words( text: string ): string[] {
	return text.match( /\s+|[^\s]+/g ) ?? [];
}

/**
 * The changes from one text to another, or null when they are too long to
 * compare word by word.
 *
 * @param before The text as it was.
 * @param after  The draft.
 */
export function wordDiff( before: string, after: string ): DiffPart[] | null {
	const a = words( before );
	const b = words( after );
	if ( a.length > MOST || b.length > MOST ) {
		return null;
	}
	// Longest common subsequence, filled from the end so the walk below
	// can go forwards.
	const rows = a.length + 1;
	const cols = b.length + 1;
	const table = new Uint16Array( rows * cols );
	for ( let i = a.length - 1; i >= 0; i-- ) {
		for ( let j = b.length - 1; j >= 0; j-- ) {
			table[ i * cols + j ] =
				a[ i ] === b[ j ]
					? table[ ( i + 1 ) * cols + j + 1 ] + 1
					: Math.max(
							table[ ( i + 1 ) * cols + j ],
							table[ i * cols + j + 1 ]
						);
		}
	}
	const out: DiffPart[] = [];
	const push = ( kind: DiffPart[ 'kind' ], text: string ) => {
		const last = out[ out.length - 1 ];
		if ( last && last.kind === kind ) {
			last.text += text;
		} else {
			out.push( { kind, text } );
		}
	};
	let i = 0;
	let j = 0;
	while ( i < a.length && j < b.length ) {
		if ( a[ i ] === b[ j ] ) {
			push( 'same', a[ i ] );
			i++;
			j++;
		} else if (
			table[ ( i + 1 ) * cols + j ] >= table[ i * cols + j + 1 ]
		) {
			push( 'removed', a[ i ] );
			i++;
		} else {
			push( 'added', b[ j ] );
			j++;
		}
	}
	while ( i < a.length ) {
		push( 'removed', a[ i++ ] );
	}
	while ( j < b.length ) {
		push( 'added', b[ j++ ] );
	}
	return out;
}
