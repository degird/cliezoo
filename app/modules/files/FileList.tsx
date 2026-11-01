/**
 * The files in one place: what each one is, whether anybody has approved
 * it, and what you can do with it.
 */
import { Suspense, useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { copyText } from '../../packages/app/copy';
import {
	Check,
	Copy,
	FolderInput,
	Pencil,
	Download,
	AppWindow,
	File as FileIcon,
	LayoutGrid,
	Link2,
	List as ListIcon,
	Lock,
	LockOpen,
	Share2,
	Trash2,
} from 'lucide-react';
import {
	api,
	formatBytes,
	formatFullDate,
	formatRelative,
	getBoot,
	useList,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import {
	Badge,
	Button,
	Checkbox,
	Dialog,
	TextField,
	IconButton,
	Menu,
	MenuItem,
	MenuTrigger,
	SearchField,
	Select,
	Skeleton,
	ErrorState,
	toast,
} from '@cliezoo/ui';
import type { ReactNode } from 'react';
import { appHref } from '../../packages/app/router';
import { FolderPicker } from './FolderPicker';
import { ShareDialog } from '../../packages/app';

/** What a file dragged from this list carries: its uids, as JSON. */
export const DRAGGED = 'application/x-cliezoo-files';

/**
 * The files being dragged, if what is being dropped came from this list.
 *
 * @param data The drop's data.
 */
export function draggedFiles( data: DataTransfer ): string[] {
	try {
		const uids: unknown = JSON.parse( data.getData( DRAGGED ) || '[]' );
		return Array.isArray( uids )
			? uids.filter( ( one ): one is string => 'string' === typeof one )
			: [];
	} catch {
		return [];
	}
}

/** The details a row in the list can show; which ones is up to you. */
type Column = 'type' | 'size' | 'added' | 'review' | 'description';

const COLUMNS = (): Array< { id: Column; label: string } > => [
	{ id: 'type', label: __( 'Type', 'cliezoo' ) },
	{ id: 'size', label: __( 'Size', 'cliezoo' ) },
	{ id: 'added', label: __( 'When it was added', 'cliezoo' ) },
	{ id: 'review', label: __( 'Review and approval', 'cliezoo' ) },
	{ id: 'description', label: __( 'Description', 'cliezoo' ) },
];

/**
 * The details kept from last time, or all of them.
 */
function keptColumns(): Column[] {
	const all = COLUMNS().map( ( one ) => one.id );
	try {
		const raw = window.localStorage.getItem( 'cliezoo:files:columns' );
		if ( null === raw ) {
			return all;
		}
		return raw
			.split( ',' )
			.filter( ( one ): one is Column => all.includes( one as Column ) );
	} catch {
		return all;
	}
}

/** The sorts of file the list can be narrowed to. */
type Kind = '' | 'image' | 'video' | 'audio' | 'pdf' | 'link';

const KINDS = () => [
	{ id: 'any', label: __( 'Any type', 'cliezoo' ) },
	{ id: 'image', label: __( 'Pictures', 'cliezoo' ) },
	{ id: 'video', label: __( 'Videos', 'cliezoo' ) },
	{ id: 'audio', label: __( 'Recordings', 'cliezoo' ) },
	{ id: 'pdf', label: __( 'PDFs', 'cliezoo' ) },
	{ id: 'link', label: __( 'Links and embeds', 'cliezoo' ) },
];

/**
 * The filter condition for one sort of file.
 *
 * @param kind The sort chosen.
 */
export function kindFilter( kind: Kind ) {
	switch ( kind ) {
		case 'image':
		case 'video':
		case 'audio':
			return { field: 'mime', op: 'starts', value: `${ kind }/` };
		case 'pdf':
			return { field: 'mime', op: 'eq', value: 'application/pdf' };
		case 'link':
			return { field: 'kind', op: 'in', value: [ 'link', 'embed' ] };
	}
	return null;
}

/** How many files one folder shows before it says there are more. */
const PAGE = 100;

/**
 * How many files have to be on screen before the search box appears. It
 * used to be the page size, which meant the box was hidden until a folder
 * held a hundred files *and* they had all been fetched — so whether you
 * could search depended on how far down you had scrolled. A second page
 * waiting also brings it out, because then what is here is not all there
 * is and looking by eye cannot work.
 */
const SEARCH_FROM = 10;

export interface FileRow {
	uid: string;
	name: string;
	kind: string;
	url?: string | null;
	description?: string | null;
	mime?: string | null;
	size?: number | null;
	locked?: boolean;
	approved_at?: string | null;
	approved_by?: { uid: string; label: string } | null;
	/** When somebody asked for changes instead, and who. */
	changes_at?: string | null;
	changes_by?: { uid: string; label: string } | null;
	/** A signed link to its thumbnail, when it has one. */
	thumb?: string | null;
	/** Whether the browser can show it (pictures, PDF, video, audio, text). */
	viewed?: boolean;
	extension?: string | null;
	created_at?: string;
	/** Where it lives, when it is read by itself. */
	location?: {
		folders: Array< { uid: string; name: string } >;
		attached: { type: string; uid: string; label: string } | null;
	};
	/** Whether its folders switch comments or approvals off. */
	comments_off?: boolean;
	approvals_off?: boolean;
}

/**
 * The menu entries this file will refuse, so the menu can say so before
 * anybody types a new name into a dialog that was never going to save.
 *
 * Locking is the server's rule and it is the same one everywhere: a locked
 * file cannot be renamed, moved, approved or trashed until it is unlocked.
 *
 * @param file The file.
 */
export function refusedActions( file: Pick< FileRow, 'locked' > ): string[] {
	return file.locked ? [ 'rename', 'move', 'approve', 'trash' ] : [];
}

type Sort = 'name' | '-created_at' | 'created_at' | '-size';
type Look = 'list' | 'grid';

const SORTS = (): Array< { id: Sort; label: string } > => [
	{ id: 'name', label: __( 'Name', 'cliezoo' ) },
	{ id: '-created_at', label: __( 'Newest first', 'cliezoo' ) },
	{ id: 'created_at', label: __( 'Oldest first', 'cliezoo' ) },
	{ id: '-size', label: __( 'Largest first', 'cliezoo' ) },
];

/**
 * A choice kept in this browser (how the files are shown), if it can be.
 *
 * @param key      Where it is kept.
 * @param fallback What it is when nothing is kept.
 * @param allowed  What it may be.
 */
function kept< T extends string >( key: string, fallback: T, allowed: T[] ): T {
	try {
		const value = window.localStorage.getItem( key ) as T | null;
		return value && allowed.includes( value ) ? value : fallback;
	} catch {
		return fallback;
	}
}

function keep( key: string, value: string ): void {
	try {
		window.localStorage.setItem( key, value );
	} catch {
		// A private window: the choice lasts as long as the page.
	}
}

/**
 * A file's picture: its thumbnail, or the icon for what it is.
 *
 * @param root0      Props.
 * @param root0.file The file.
 * @param root0.big  A tile rather than a row.
 */
export function FileThumb( {
	file,
	big = false,
}: {
	file: FileRow;
	big?: boolean;
} ) {
	const size = big ? 28 : 18;
	if ( file.thumb ) {
		return (
			<img
				src={ file.thumb }
				alt=""
				loading="lazy"
				className={
					big
						? 'h-full w-full object-cover'
						: 'size-8 shrink-0 rounded object-cover'
				}
			/>
		);
	}
	let Icon = FileIcon;
	if ( 'embed' === file.kind ) {
		Icon = AppWindow;
	} else if ( 'link' === file.kind ) {
		Icon = Link2;
	}
	return (
		<Icon
			size={ size }
			className="shrink-0 text-subtle"
			aria-hidden="true"
		/>
	);
}

/**
 * What kind of file it is, in a word: its extension, or link or embed.
 *
 * @param file The file.
 */
export function typeWord( file: FileRow ): string {
	if ( 'embed' === file.kind ) {
		return __( 'Embed', 'cliezoo' );
	}
	if ( 'link' === file.kind ) {
		return __( 'Link', 'cliezoo' );
	}
	return String( file.extension ?? '' ).toUpperCase();
}

/**
 * How big a file is — or, for a link or an embed, what it is instead.
 *
 * @param file The file.
 */
export function sizeLine( file: Pick< FileRow, 'kind' | 'size' > ): string {
	if ( 'embed' === file.kind ) {
		return __( 'An embed', 'cliezoo' );
	}
	if ( 'link' === file.kind ) {
		return __( 'A link', 'cliezoo' );
	}
	// A size nobody measured is not zero bytes. "0 B" beside a file that
	// plainly has something in it reads as a broken upload.
	if ( null === file.size || undefined === file.size ) {
		return __( 'Size not known', 'cliezoo' );
	}
	return formatBytes( Number( file.size ) );
}

export function FileList( {
	where,
	onOpen,
	onChanged,
	empty,
}: {
	where: { folder?: string; client?: string; project?: string };
	/** Opens a file; it is told the files around it, for next and previous. */
	onOpen: ( uid: string, around: string[] ) => void;
	onChanged: () => void;
	empty?: ReactNode;
} ) {
	const [ look, setLook ] = useState< Look >( () =>
		kept< Look >( 'cliezoo:files:look', 'list', [ 'list', 'grid' ] )
	);
	const [ sort, setSort ] = useState< Sort >( () =>
		kept< Sort >( 'cliezoo:files:sort', 'name', [
			'name',
			'-created_at',
			'created_at',
			'-size',
		] )
	);
	const [ columns, setColumns ] = useState< Column[] >( keptColumns );
	const showing = ( column: Column ) => columns.includes( column );
	// The row whose menu a right-click opened.
	const [ menuFor, setMenuFor ] = useState( '' );
	const queries = useQueryClient();
	const [ picked, setPicked ] = useState< string[] >( [] );
	const [ sharing, setSharing ] = useState< string | null >( null );
	// Renaming and moving one file from its row.
	const [ renaming, setRenaming ] = useState< FileRow | null >( null );
	const [ moving, setMoving ] = useState< FileRow | null >( null );
	const [ newName, setNewName ] = useState( '' );
	const [ into, setInto ] = useState( '' );
	// Walking into another folder clears what was picked: a bar counting
	// rows you can no longer see, over a button labelled "here", is a move
	// nobody meant to make.
	const [ was, setWas ] = useState( '' );
	const here = `${ where.folder ?? '' }|${ where.client ?? '' }|${
		where.project ?? ''
	}`;
	if ( here !== was ) {
		setWas( here );
		if ( picked.length ) {
			setPicked( [] );
		}
	}

	// Search is why this list is not simply "the first hundred": a folder
	// somebody has worked in for a year has more than that, and the file
	// they want is the one they can name.
	const [ typed, setTyped ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	useEffect( () => {
		const timer = setTimeout( () => setSearch( typed ), 300 );
		return () => clearTimeout( timer );
	}, [ typed ] );

	// Narrowing what is here: what sort of file, who added it, and when.
	const [ type, setType ] = useState< Kind >( '' );
	const [ by, setBy ] = useState< '' | 'me' >( '' );
	const [ when, setWhen ] = useState( '' );
	const me = getBoot().user?.uid ?? '';
	const narrowed = !! ( search || type || by || when );

	const files = useList< FileRow >(
		'/files',
		{
			q: search || undefined,
			filter: JSON.stringify( {
				and: [
					// Only what finished: a half-uploaded row is not
					// a file anybody can open.
					{ field: 'status', op: 'eq', value: 'ready' },
					// Inside a folder, what is in it; at the top of a
					// client or project, what is not in any folder —
					// otherwise the same file is listed twice, once
					// here and once inside the folder it lives in.
					where.folder
						? {
								field: 'folder',
								op: 'eq',
								value: where.folder,
							}
						: { field: 'folder', op: 'empty' },
					where.client
						? {
								field: 'client',
								op: 'eq',
								value: where.client,
							}
						: null,
					where.project
						? {
								field: 'project',
								op: 'eq',
								value: where.project,
							}
						: null,
					kindFilter( type ),
					'me' === by && me
						? { field: 'created_by', op: 'eq', value: me }
						: null,
					when
						? { field: 'created_at', op: 'within', value: when }
						: null,
				].filter( Boolean ),
			} ),
			sort,
		},
		{ limit: PAGE }
	);

	const rows = ( files.data?.pages ?? [] ).flatMap( ( page ) => page.data );
	const open = ( uid: string ) =>
		onOpen(
			uid,
			rows.map( ( one ) => one.uid )
		);

	/**
	 * Files what is picked into the folder being looked at, and says what
	 * was left behind. Says whether the move happened, so a refusal keeps
	 * the rows picked and the bar open to try again.
	 */
	const moveHere = async () => {
		try {
			const answer = (
				await api.post< { moved?: number; locked?: string[] } >(
					'/files/move',
					{ files: picked, folder: where.folder }
				)
			).data;
			await queries.invalidateQueries( { queryKey: [ 'files' ] } );
			onChanged();
			const locked = answer.locked ?? [];
			if ( locked.length ) {
				toast.warning(
					sprintf(
						/* translators: 1: how many were filed, 2: the names of the ones that were not. */
						__(
							'%1$d filed. Locked, so left where they are: %2$s',
							'cliezoo'
						),
						answer.moved ?? 0,
						locked.join( ', ' )
					)
				);
				return true;
			}
			toast.success( __( 'Filed.', 'cliezoo' ) );
			return true;
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
			return false;
		}
	};

	// Says whether the work actually happened, so a dialog only closes on
	// the answer it asked for: closing it on a refusal takes the name
	// somebody typed with it.
	const act = async ( work: Promise< unknown >, said: string ) => {
		try {
			await work;
			await queries.invalidateQueries( { queryKey: [ 'files' ] } );
			toast.success( said );
			onChanged();
			return true;
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
			return false;
		}
	};

	// The same actions from a row and from a tile: which way the list
	// happens to be drawn is not a reason to take rename, move, share or
	// approve away from somebody.
	const actions = ( file: FileRow ) => (
		<MenuTrigger
			isOpen={ menuFor === file.uid }
			onOpenChange={ ( on: boolean ) => setMenuFor( on ? file.uid : '' ) }
		>
			<Button
				size="sm"
				variant="ghost"
				aria-label={ sprintf(
					/* translators: %s: the name of the record the menu is for. */
					__( 'More actions for %s', 'cliezoo' ),
					file.name
				) }
			>
				⋯
			</Button>
			<Menu
				onAction={ ( key ) => {
					if ( 'download' === key ) {
						download( file );
					} else if ( 'approve' === key ) {
						act(
							api.post( `/files/${ file.uid }/approve`, {
								approved: ! file.approved_at,
							} ),
							file.approved_at
								? __( 'The approval is taken back.', 'cliezoo' )
								: __( 'Approved.', 'cliezoo' )
						);
					} else if ( 'lock' === key ) {
						act(
							api.post( `/files/${ file.uid }/lock`, {
								locked: ! file.locked,
							} ),
							file.locked
								? __( 'Unlocked.', 'cliezoo' )
								: __( 'Locked.', 'cliezoo' )
						);
					} else if ( 'duplicate' === key ) {
						act(
							api.post( `/files/${ file.uid }/duplicate` ),
							__( 'Copied.', 'cliezoo' )
						);
					} else if ( 'rename' === key ) {
						setNewName( file.name );
						setRenaming( file );
					} else if ( 'move' === key ) {
						setInto( where.folder ?? '' );
						setMoving( file );
					} else if ( 'link' === key ) {
						copyText( appHref( `/files?file=${ file.uid }` ) );
					} else if ( 'share' === key ) {
						setSharing( file.uid );
					} else if ( 'trash' === key ) {
						act(
							api.delete( `/files/${ file.uid }` ),
							__( 'Moved to the trash.', 'cliezoo' )
						);
					}
				} }
				disabledKeys={ refusedActions( file ) }
			>
				<MenuItem id="download" icon={ <Download size={ 16 } /> }>
					{ 'upload' === file.kind
						? __( 'Download', 'cliezoo' )
						: __( 'Open', 'cliezoo' ) }
				</MenuItem>
				<MenuItem
					id="approve"
					icon={ <Check size={ 16 } /> }
					description={
						file.approved_by
							? sprintf(
									/* translators: %s: a person's name. */
									__( 'Approved by %s.', 'cliezoo' ),
									file.approved_by.label
								)
							: undefined
					}
				>
					{ file.approved_at
						? __( 'Take the approval back', 'cliezoo' )
						: __( 'Approve it', 'cliezoo' ) }
				</MenuItem>
				<MenuItem
					id="lock"
					icon={
						file.locked ? (
							<LockOpen size={ 16 } />
						) : (
							<Lock size={ 16 } />
						)
					}
					description={ __(
						'A locked file cannot be renamed, moved, trashed or approved.',
						'cliezoo'
					) }
				>
					{ file.locked
						? __( 'Unlock', 'cliezoo' )
						: __( 'Lock', 'cliezoo' ) }
				</MenuItem>
				<MenuItem id="rename" icon={ <Pencil size={ 16 } /> }>
					{ __( 'Rename…', 'cliezoo' ) }
				</MenuItem>
				<MenuItem id="move" icon={ <FolderInput size={ 16 } /> }>
					{ __( 'Move to…', 'cliezoo' ) }
				</MenuItem>
				<MenuItem id="link" icon={ <Link2 size={ 16 } /> }>
					{ __( 'Copy link', 'cliezoo' ) }
				</MenuItem>
				<MenuItem id="duplicate" icon={ <Copy size={ 16 } /> }>
					{ __( 'Make a copy', 'cliezoo' ) }
				</MenuItem>
				{ 'upload' === file.kind ? (
					// A public link shares stored bytes; a
					// link or an embed is an address to share
					// as it is.
					<MenuItem id="share" icon={ <Share2 size={ 16 } /> }>
						{ __( 'Share', 'cliezoo' ) }
					</MenuItem>
				) : null }
				<MenuItem id="trash" icon={ <Trash2 size={ 16 } /> }>
					{ __( 'Move to trash', 'cliezoo' ) }
				</MenuItem>
			</Menu>
		</MenuTrigger>
	);

	if ( files.isPending ) {
		return <Skeleton className="h-32" />;
	}
	if ( files.isError ) {
		return (
			<ErrorState
				error={ files.error }
				onRetry={ () => files.refetch() }
			/>
		);
	}
	if ( ! rows.length && ! narrowed ) {
		return <>{ empty }</>;
	}

	return (
		<>
			<div className="flex flex-wrap items-end gap-2">
				<Select
					label={ __( 'Sort', 'cliezoo' ) }
					className="w-44"
					items={ SORTS() }
					selectedKey={ sort }
					onSelectionChange={ ( key: unknown ) => {
						const next = String( key ) as Sort;
						setSort( next );
						keep( 'cliezoo:files:sort', next );
					} }
				/>
				<Select
					label={ __( 'Type', 'cliezoo' ) }
					className="w-36"
					items={ KINDS() }
					selectedKey={ type || 'any' }
					onSelectionChange={ ( key: unknown ) =>
						setType(
							( 'any' === key ? '' : String( key ) ) as Kind
						)
					}
				/>
				{ !! me && (
					<Select
						label={ __( 'Added by', 'cliezoo' ) }
						className="w-36"
						items={ [
							{ id: 'anyone', label: __( 'Anyone', 'cliezoo' ) },
							{ id: 'me', label: __( 'Me', 'cliezoo' ) },
						] }
						selectedKey={ by || 'anyone' }
						onSelectionChange={ ( key: unknown ) =>
							setBy( 'me' === key ? 'me' : '' )
						}
					/>
				) }
				<Select
					label={ __( 'Added', 'cliezoo' ) }
					className="w-36"
					items={ [
						{ id: 'any', label: __( 'Any time', 'cliezoo' ) },
						{
							id: 'this_week',
							label: __( 'This week', 'cliezoo' ),
						},
						{
							id: 'this_month',
							label: __( 'This month', 'cliezoo' ),
						},
						{
							id: 'this_year',
							label: __( 'This year', 'cliezoo' ),
						},
					] }
					selectedKey={ when || 'any' }
					onSelectionChange={ ( key: unknown ) =>
						setWhen( 'any' === key ? '' : String( key ) )
					}
				/>
				{ 'list' === look && (
					<MenuTrigger>
						<Button size="sm" variant="ghost">
							{ __( 'Details', 'cliezoo' ) }
						</Button>
						<Menu
							aria-label={ __(
								'Details each row shows',
								'cliezoo'
							) }
							selectionMode="multiple"
							selectedKeys={ new Set( columns ) }
							onSelectionChange={ ( keys ) => {
								const next = COLUMNS()
									.map( ( one ) => one.id )
									.filter(
										( one ) =>
											'all' === keys ||
											( keys as Set< string > ).has( one )
									);
								setColumns( next );
								keep(
									'cliezoo:files:columns',
									next.join( ',' )
								);
							} }
						>
							{ COLUMNS().map( ( one ) => (
								<MenuItem
									key={ one.id }
									id={ one.id }
									icon={
										showing( one.id ) ? (
											<Check size={ 14 } />
										) : (
											<span />
										)
									}
								>
									{ one.label }
								</MenuItem>
							) ) }
						</Menu>
					</MenuTrigger>
				) }
				<div
					className="ms-auto flex gap-1"
					role="group"
					aria-label={ __( 'Show files as', 'cliezoo' ) }
				>
					<IconButton
						size="sm"
						label={ __( 'A list', 'cliezoo' ) }
						aria-pressed={ 'list' === look }
						variant={ 'list' === look ? 'secondary' : 'ghost' }
						icon={ <ListIcon size={ 15 } aria-hidden="true" /> }
						onPress={ () => {
							setLook( 'list' );
							keep( 'cliezoo:files:look', 'list' );
						} }
					/>
					<IconButton
						size="sm"
						label={ __( 'A grid', 'cliezoo' ) }
						aria-pressed={ 'grid' === look }
						variant={ 'grid' === look ? 'secondary' : 'ghost' }
						icon={ <LayoutGrid size={ 15 } aria-hidden="true" /> }
						onPress={ () => {
							setLook( 'grid' );
							keep( 'cliezoo:files:look', 'grid' );
						} }
					/>
				</div>
			</div>

			{ 'grid' === look && !! rows.length && (
				<ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
					{ rows.map( ( file ) => (
						<li
							key={ file.uid }
							className="group relative overflow-hidden rounded-lg border border-line bg-raised"
							onContextMenu={ ( event ) => {
								event.preventDefault();
								setMenuFor( file.uid );
							} }
						>
							<button
								type="button"
								onClick={ () => open( file.uid ) }
								className="block w-full text-start outline-none focus-visible:ring-2 focus-visible:ring-accent"
							>
								<span className="flex aspect-[4/3] items-center justify-center bg-sunken">
									<FileThumb file={ file } big />
								</span>
								<span className="block p-2">
									<span className="block truncate text-sm font-medium text-fg">
										{ file.name }
									</span>
									<span className="block truncate text-xs text-subtle">
										{ [ typeWord( file ), sizeLine( file ) ]
											.filter( Boolean )
											.join( ' • ' ) }
									</span>
								</span>
							</button>
							<span className="absolute start-2 top-2">
								<Checkbox
									isSelected={ picked.includes( file.uid ) }
									aria-label={ sprintf(
										/* translators: %s: the name of the row being picked. */
										__( 'Pick %s', 'cliezoo' ),
										file.name
									) }
									onChange={ ( on: boolean ) =>
										setPicked( ( current ) =>
											on
												? [ ...current, file.uid ]
												: current.filter(
														( one ) =>
															one !== file.uid
													)
										)
									}
								/>
							</span>
							<span className="absolute end-2 top-2 flex items-center gap-1">
								{ !! file.approved_at && (
									<Badge tone="success">
										{ __( 'Approved', 'cliezoo' ) }
									</Badge>
								) }
								{ /* The thumbnail fills the tile, so the
								     trigger carries its own backing the way
								     a folder tile's does. */ }
								<span className="rounded-md bg-raised/80">
									{ actions( file ) }
								</span>
							</span>
						</li>
					) ) }
				</ul>
			) }

			{ ( !! search ||
				rows.length >= SEARCH_FROM ||
				files.hasNextPage ) && (
				<SearchField
					label={ __( 'Search these files', 'cliezoo' ) }
					placeholder={ __( 'Search by name…', 'cliezoo' ) }
					value={ typed }
					onChange={ setTyped }
					className="w-full sm:w-72"
				/>
			) }

			{ ! rows.length && (
				<p className="flex flex-wrap items-center gap-2 text-sm text-muted">
					{ search
						? sprintf(
								/* translators: %s: what was typed. */
								__( 'Nothing here matches “%s”.', 'cliezoo' ),
								search
							)
						: __(
								'Nothing here matches those filters.',
								'cliezoo'
							) }
					{ ( !! type || !! by || !! when ) && (
						<Button
							size="sm"
							variant="ghost"
							onPress={ () => {
								setType( '' );
								setBy( '' );
								setWhen( '' );
							} }
						>
							{ __( 'Clear the filters', 'cliezoo' ) }
						</Button>
					) }
				</p>
			) }

			{ picked.length > 0 && (
				<div className="flex items-center gap-3 rounded-lg border border-line bg-sunken px-4 py-2">
					<span className="text-sm text-fg">
						{ sprintf(
							/* translators: %d: how many are picked. */
							__( '%d picked', 'cliezoo' ),
							picked.length
						) }
					</span>
					<Button
						size="sm"
						onPress={ () =>
							// A locked file stays where it is and the
							// server names it: saying "Filed." over the
							// top of that is how a list loses trust.
							moveHere().then( ( worked ) => {
								if ( worked ) {
									setPicked( [] );
								}
							} )
						}
					>
						{ __( 'Move here', 'cliezoo' ) }
					</Button>
					<Button
						size="sm"
						variant="ghost"
						className="ms-auto"
						onPress={ () => setPicked( [] ) }
					>
						{ __( 'Clear', 'cliezoo' ) }
					</Button>
				</div>
			) }

			{ 'list' === look && !! rows.length && (
				<ul className="divide-y divide-line overflow-hidden rounded-lg border border-line bg-raised">
					{ rows.map( ( file ) => (
						<li
							key={ file.uid }
							className="flex flex-wrap items-center gap-3 px-4 py-3"
							draggable
							onDragStart={ ( event ) => {
								// Dragging a picked file takes every picked
								// file with it, as a file manager does.
								const uids = picked.includes( file.uid )
									? picked
									: [ file.uid ];
								event.dataTransfer.setData(
									DRAGGED,
									JSON.stringify( uids )
								);
								event.dataTransfer.effectAllowed = 'move';
							} }
							onContextMenu={ ( event ) => {
								event.preventDefault();
								setMenuFor( file.uid );
							} }
						>
							<Checkbox
								isSelected={ picked.includes( file.uid ) }
								aria-label={ sprintf(
									/* translators: %s: the name of the row being picked. */
									__( 'Pick %s', 'cliezoo' ),
									file.name
								) }
								onChange={ ( on: boolean ) =>
									setPicked( ( current ) =>
										on
											? [ ...current, file.uid ]
											: current.filter(
													( one ) => one !== file.uid
												)
									)
								}
							/>
							<button
								type="button"
								className="flex min-w-0 flex-1 items-center gap-3 text-start"
								onClick={ () => open( file.uid ) }
							>
								<FileThumb file={ file } />
								<span className="min-w-0 flex-1">
									<span className="flex flex-wrap items-center gap-2">
										<span className="truncate font-medium text-fg">
											{ file.name }
										</span>
										{ showing( 'review' ) &&
											!! file.approved_at && (
												<Badge tone="success">
													{ file.approved_by
														? sprintf(
																/* translators: %s: a person's name. */
																__(
																	'Approved by %s',
																	'cliezoo'
																),
																file.approved_by
																	.label
															)
														: __(
																'Approved',
																'cliezoo'
															) }
												</Badge>
											) }
										{ showing( 'review' ) &&
											! file.approved_at &&
											!! file.changes_at && (
												<Badge tone="warning">
													{ __(
														'Changes asked for',
														'cliezoo'
													) }
												</Badge>
											) }
										{ !! file.locked && (
											<Badge tone="neutral">
												{ __( 'Locked', 'cliezoo' ) }
											</Badge>
										) }
									</span>
									{ showing( 'description' ) &&
										!! file.description && (
											<span className="block truncate text-xs text-muted">
												{ file.description }
											</span>
										) }
									<span className="block text-xs text-subtle">
										{ [
											showing( 'type' )
												? typeWord( file )
												: '',
											showing( 'size' )
												? sizeLine( file )
												: '',
											showing( 'added' ) &&
											file.created_at
												? formatRelative(
														file.created_at
													)
												: '',
											showing( 'review' ) &&
											file.approved_at
												? sprintf(
														/* translators: %s: a date. */
														__(
															'approved %s',
															'cliezoo'
														),
														formatFullDate(
															file.approved_at
														)
													)
												: '',
										]
											.filter( Boolean )
											.join( ' · ' ) }
									</span>
								</span>
							</button>

							{ actions( file ) }
						</li>
					) ) }
				</ul>
			) }

			{ files.hasNextPage && (
				<Button
					variant="secondary"
					isPending={ files.isFetchingNextPage }
					onPress={ () => files.fetchNextPage() }
				>
					{ __( 'Show more', 'cliezoo' ) }
				</Button>
			) }

			{ !! renaming && (
				<Dialog
					isOpen
					size="sm"
					onOpenChange={ ( on ) => ! on && setRenaming( null ) }
					title={ __( 'Rename file', 'cliezoo' ) }
					footer={ ( close ) => (
						<>
							<Button variant="ghost" onPress={ close }>
								{ __( 'Cancel', 'cliezoo' ) }
							</Button>
							<Button
								variant="primary"
								isDisabled={ ! newName.trim() }
								onPress={ () =>
									act(
										api.patch( `/files/${ renaming.uid }`, {
											name: newName.trim(),
										} ),
										__( 'Renamed.', 'cliezoo' )
									).then( ( worked ) => {
										if ( worked ) {
											setRenaming( null );
										}
									} )
								}
							>
								{ __( 'Rename', 'cliezoo' ) }
							</Button>
						</>
					) }
				>
					<TextField
						label={ __( 'Name', 'cliezoo' ) }
						value={ newName }
						onChange={ setNewName }
						maxLength={ 200 }
					/>
				</Dialog>
			) }

			{ !! moving && (
				<Dialog
					isOpen
					size="sm"
					onOpenChange={ ( on ) => ! on && setMoving( null ) }
					title={ sprintf(
						/* translators: %s: the name of the file or folder being moved. */
						__( 'Move “%s”', 'cliezoo' ),
						moving.name
					) }
					footer={ ( close ) => (
						<>
							<Button variant="ghost" onPress={ close }>
								{ __( 'Cancel', 'cliezoo' ) }
							</Button>
							<Button
								variant="primary"
								onPress={ () =>
									act(
										api.post( '/files/move', {
											files: [ moving.uid ],
											folder: into,
										} ),
										__( 'Moved.', 'cliezoo' )
									).then( ( worked ) => {
										if ( worked ) {
											setMoving( null );
										}
									} )
								}
							>
								{ __( 'Move it here', 'cliezoo' ) }
							</Button>
						</>
					) }
				>
					<FolderPicker
						value={ into }
						onChange={ setInto }
						enabled={ !! moving }
					/>
				</Dialog>
			) }

			{ !! sharing && (
				<Suspense fallback={ null }>
					<ShareDialog
						type="files"
						uid={ sharing }
						isOpen={ !! sharing }
						onOpenChange={ () => setSharing( null ) }
					/>
				</Suspense>
			) }
		</>
	);
}

/**
 * Fetches one file, or opens the link a linked file stands for.
 *
 * @param file The file.
 */
export async function download( file: FileRow ): Promise< void > {
	if ( ( 'link' === file.kind || 'embed' === file.kind ) && file.url ) {
		window.open( file.url, '_blank', 'noopener,noreferrer' );
		return;
	}
	try {
		// A link the server signs, which lasts minutes and is only for this
		// file. Putting the REST nonce in a URL the browser navigates to
		// would leave the API's write credential in history and proxy logs.
		const link = await api.get< { url: string } >(
			`/files/${ file.uid }/link`
		);
		window.location.assign( link.data.url );
	} catch ( error ) {
		toast.error( ( error as ApiError ).message );
	}
}
