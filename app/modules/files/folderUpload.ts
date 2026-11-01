/**
 * Uploading a whole folder: its files, where each one sat inside it, and
 * the folders to make so they land in the same places here.
 */
import { api } from '@cliezoo/data';

export interface Placed {
	file: File;
	/** The folders it sits in, inside what was chosen ("Brand/Logos"). */
	dir: string;
}

/** Past this many files a folder upload stops and says so. */
export const MOST = 500;

/**
 * What a "choose a folder" picker gave, with each file's place.
 *
 * @param files The picker's files (each with webkitRelativePath).
 */
export function fromPicker( files: FileList | File[] ): Placed[] {
	return Array.from( files ).map( ( file ) => {
		const path =
			( file as File & { webkitRelativePath?: string } )
				.webkitRelativePath ?? '';
		const parts = path.split( '/' ).filter( Boolean );
		return { file, dir: parts.slice( 0, -1 ).join( '/' ) };
	} );
}

interface Entry {
	isFile: boolean;
	isDirectory: boolean;
	name: string;
	file?: ( done: ( file: File ) => void, fail: () => void ) => void;
	createReader?: () => {
		readEntries: (
			done: ( entries: Entry[] ) => void,
			fail: () => void
		) => void;
	};
}

/**
 * What was dropped, walking into any folders among it.
 *
 * @param data The drop's data.
 */
export async function fromDrop( data: DataTransfer ): Promise< Placed[] > {
	const entries = Array.from( data.items ?? [] )
		.map( ( item ) => item.webkitGetAsEntry?.() as unknown as Entry | null )
		.filter( ( entry ): entry is Entry => !! entry );
	if ( ! entries.some( ( entry ) => entry.isDirectory ) ) {
		return Array.from( data.files ).map( ( file ) => ( {
			file,
			dir: '',
		} ) );
	}
	const out: Placed[] = [];
	const walk = async ( entry: Entry, dir: string ): Promise< void > => {
		// One past the most, so the caller can tell there were more.
		if ( out.length > MOST ) {
			return;
		}
		if ( entry.isFile && entry.file ) {
			const file = await new Promise< File >( ( done, fail ) =>
				entry.file?.( done, fail )
			);
			out.push( { file, dir } );
			return;
		}
		if ( entry.isDirectory && entry.createReader ) {
			const reader = entry.createReader();
			const here = dir ? `${ dir }/${ entry.name }` : entry.name;
			// A reader hands entries over in batches until it has none left.
			for (;;) {
				const batch = await new Promise< Entry[] >( ( done, fail ) =>
					reader.readEntries( done, fail )
				);
				if ( ! batch.length ) {
					break;
				}
				for ( const child of batch ) {
					await walk( child, here );
				}
			}
		}
	};
	for ( const entry of entries ) {
		await walk( entry, '' );
	}
	return out;
}

/**
 * Makes the folders the files sit in, under where they are going, and says
 * which folder each place became.
 *
 * @param placed        Files and their places.
 * @param into          The folder they go into (or none, with client/project).
 * @param scope         The client or project, at the top.
 * @param scope.client  Client uid.
 * @param scope.project Project uid.
 */
export async function makeFolders(
	placed: Placed[],
	into: string | undefined,
	scope: { client?: string; project?: string }
): Promise< Map< string, string > > {
	const made = new Map< string, string >();
	const dirs = Array.from(
		new Set( placed.map( ( one ) => one.dir ).filter( Boolean ) )
	).sort(
		( a, b ) =>
			a.split( '/' ).length - b.split( '/' ).length ||
			a.localeCompare( b )
	);
	for ( const dir of dirs ) {
		const parts = dir.split( '/' );
		for ( let depth = 1; depth <= parts.length; depth++ ) {
			const path = parts.slice( 0, depth ).join( '/' );
			if ( made.has( path ) ) {
				continue;
			}
			const parent =
				depth > 1
					? made.get( parts.slice( 0, depth - 1 ).join( '/' ) )
					: into;
			const folder = (
				await api.post< { uid: string } >( '/folders', {
					name: parts[ depth - 1 ],
					...( parent ? { parent } : scope ),
				} )
			).data;
			made.set( path, folder.uid );
		}
	}
	return made;
}
