/**
 * The files area itself: where you are, what is in it, and what you can do
 * with what you pick.
 *
 * The same component is the Files page, a client's Files tab and a
 * project's, because they are the same thing looked at from different
 * places — and a second implementation would drift.
 */
import { Suspense, lazy, useCallback, useRef, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	ChevronRight,
	Download,
	FileText,
	FolderPlus,
	FolderUp,
	Kanban,
	LayoutTemplate,
	Link2,
	MessagesSquare,
	Plus,
	Share2,
	Upload,
	X,
} from 'lucide-react';
import { Link } from '@tanstack/react-router';
import {
	api,
	can,
	formatBytes,
	getBoot,
	useQuery,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import {
	Avatar,
	Button,
	Checkbox,
	Dialog,
	EmptyState,
	Menu,
	MenuItem,
	MenuTrigger,
	Select,
	ErrorState,
	Skeleton,
	TextField,
	cx,
	toast,
} from '@cliezoo/ui';
import { FolderTile } from './FolderTile';
import { FolderMenu } from './FolderMenu';
import {
	MOST,
	fromDrop,
	fromPicker,
	makeFolders,
	type Placed,
} from './folderUpload';
import { ShareDialog } from '../../packages/app';
import { recordPath } from '../../packages/app/registry';
import { DRAGGED, FileList } from './FileList';
import {
	uploadsSummary,
	useUpload,
	type Upload as UploadRow,
	type UploadTarget,
} from './useUpload';
import { Slot } from '../../packages/app/slot';

const FileViewer = lazy(
	() => import( /* webpackChunkName: "viewer" */ './FileViewer' )
);

export interface Crumb {
	uid: string;
	name: string;
}

export interface FolderRow {
	uid: string;
	name: string;
	color?: string | null;
	icon?: string | null;
	cover_url?: string | null;
	comments_off?: boolean;
	approvals_off?: boolean;
	is_default?: boolean;
	/** The client or project it is filed under, when that is not obvious. */
	belongs_to?: string;
	files: number;
	folders: number;
}

interface Item {
	type: string;
	kind: string;
	uid: string;
	label: string;
	updated_at?: string | null;
}

interface Browse {
	folder:
		| ( Record< string, unknown > & {
				uid: string;
				name: string;
				is_default?: boolean;
				comments_off?: boolean;
				approvals_off?: boolean;
		  } )
		| null;
	trail: Crumb[];
	folders: FolderRow[];
	items?: Item[];
}

const ITEM_ICONS: Record< string, typeof Kanban > = {
	boards: Kanban,
	docs: FileText,
	conversations: MessagesSquare,
};

export interface FileBrowserProps {
	/** The client or project this area belongs to, if any. */
	scope?: { client?: string; project?: string };
	/** The folder open right now, or '' for the top of the scope. */
	folder: string;
	onOpenFolder: ( uid: string ) => void;
	/** A file to open straight away, from a link somebody followed here. */
	openFile?: string;
	/** Shown above the breadcrumb when the area is a tab rather than a page. */
	intro?: string;
	/**
	 * Opened to add something ("+ New" → link): its dialog opens
	 * at once.
	 */
	ask?: string;
}

/**
 * What an empty folder says, to whoever is reading it.
 *
 * @param kind      Whether the reader is staff or somebody at a client.
 * @param canUpload Whether they may put something here.
 */
export function nothingHere( kind: string | undefined, canUpload: boolean ) {
	if ( 'staff' === kind ) {
		return __(
			'Drop files in, or make a folder to put them in first. Everything here can be shown to the client, one folder at a time.',
			'cliezoo'
		);
	}
	if ( canUpload ) {
		return __(
			'Nothing has been put here yet. You can add files of your own.',
			'cliezoo'
		);
	}
	return __( 'Nothing has been shared here yet.', 'cliezoo' );
}

export function FileBrowser( {
	scope = {},
	folder,
	onOpenFolder,
	openFile,
	intro,
	ask,
}: FileBrowserProps ) {
	const queries = useQueryClient();
	const user = getBoot().user;
	const canFolder = can.toggle( user, 'create_folders' );
	// A client uploads only where their portal lets them; the server
	// refuses the rest, and a button that ends in a refusal is worse than
	// no button.
	const canUpload = can.add( user, 'files' );
	const [ adding, setAdding ] = useState( false );
	const [ linking, setLinking ] = useState( 'link' === ask );
	// The files around the one being looked at, for next and previous.
	const [ around, setAround ] = useState< string[] >( [] );
	const [ viewing, setViewing ] = useState< string | null >(
		openFile ?? null
	);
	// A link to a file opens it, once: closing the viewer must not reopen
	// it on the next render.
	const [ opened, setOpened ] = useState( openFile ?? '' );
	if ( openFile && openFile !== opened ) {
		setOpened( openFile );
		setViewing( openFile );
	}
	const [ dragging, setDragging ] = useState( false );
	const picker = useRef< HTMLInputElement >( null );
	const tree = useRef< HTMLInputElement >( null );
	const [ making, setMaking ] = useState<
		'' | 'template'
	>( '' );
	const [ madeName, setMadeName ] = useState( '' );
	const [ template, setTemplate ] = useState( '' );
	const [ sharing, setSharing ] = useState( false );
	const staff = 'staff' === user?.kind;

	const target: UploadTarget = folder ? { folder } : scope;
	const where = folder ? { folder } : scope;

	const browse = useQuery( {
		queryKey: [ 'files', 'browse', folder, scope.client, scope.project ],
		queryFn: async () =>
			( await api.get< Browse >( '/files/browse', where ) ).data,
	} );

	const refresh = useCallback( () => {
		queries.invalidateQueries( { queryKey: [ 'files' ] } );
	}, [ queries ] );

	const { uploads, add, stop, retry, dismiss, clearFinished } = useUpload(
		target,
		refresh
	);

	/**
	 * Uploads files that may sit in folders of their own: the folders are
	 * made here first, and each file goes where it sat.
	 *
	 * @param placed The files, and where each one sat.
	 */
	const upload = async ( placed: Placed[] ) => {
		if ( ! placed.length ) {
			return;
		}
		if ( placed.length > MOST ) {
			toast.error(
				sprintf(
					/* translators: %d: how many files at most. */
					__(
						'Only the first %d files are uploaded at once.',
						'cliezoo'
					),
					MOST
				)
			);
		}
		try {
			const kept = placed.slice( 0, MOST );
			const made = await makeFolders( kept, folder, scope );
			const groups = new Map< string, File[] >();
			for ( const one of kept ) {
				groups.set( one.dir, [
					...( groups.get( one.dir ) ?? [] ),
					one.file,
				] );
			}
			for ( const [ dir, files ] of groups ) {
				for ( let at = 0; at < files.length; at += 50 ) {
					add(
						files.slice( at, at + 50 ),
						dir ? made.get( dir ) : undefined
					);
				}
			}
			if ( made.size ) {
				refresh();
			}
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	const onDrop = ( event: React.DragEvent ) => {
		event.preventDefault();
		setDragging( false );
		if ( ! canUpload || event.dataTransfer.types.includes( DRAGGED ) ) {
			return;
		}
		fromDrop( event.dataTransfer ).then( upload, () =>
			toast.error(
				__( 'What was dropped could not be read.', 'cliezoo' )
			)
		);
	};

	const [ bringFiles, setBringFiles ] = useState( true );
	const [ bringPeople, setBringPeople ] = useState( false );
	const templates = useQuery( {
		queryKey: [ 'templates', 'folders' ],
		enabled: 'template' === making,
		queryFn: async () =>
			(
				await api.get<
					Array< { uid: string; name: string; category?: string } >
				>( '/templates', {
					filter: JSON.stringify( {
						and: [ { field: 'type', op: 'eq', value: 'folders' } ],
					} ),
					sort: 'name',
					limit: 100,
				} )
			).data,
	} );

	const makeHere = async () => {
		const name = madeName.trim();
		try {
			if ( 'template' === making ) {
				await api.post( '/folders/from-template', {
					template,
					name,
					files: bringFiles,
					sharing: bringPeople,
					...( folder ? { parent: folder } : scope ),
				} );
			}
			setMaking( '' );
			refresh();
			toast.success( __( 'Made.', 'cliezoo' ) );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	const here = browse.data;

	return (
		<div
			className={ cx(
				'space-y-4 rounded-lg',
				dragging && 'outline-2 outline-dashed outline-accent'
			) }
			onDragOver={ ( event ) => {
				if (
					! canUpload ||
					event.dataTransfer.types.includes( DRAGGED )
				) {
					return;
				}
				event.preventDefault();
				setDragging( true );
			} }
			// Only when the pointer actually leaves this panel, or crossing
			// a folder tile makes the outline flicker.
			onDragLeave={ ( event ) => {
				if (
					! event.currentTarget.contains(
						event.relatedTarget as Node
					)
				) {
					setDragging( false );
				}
			} }
			onDrop={ onDrop }
		>
			{ !! intro && <p className="text-sm text-muted">{ intro }</p> }

			<div className="flex flex-wrap items-center gap-3">
				<div className="min-w-0 max-w-full">
					<Breadcrumb
						trail={ here?.trail ?? [] }
						onOpen={ onOpenFolder }
					/>
				</div>
				{ /* On a phone these buttons are wider than the screen, so
				     they wrap onto their own lines rather than pushing the
				     page sideways. */ }
				<div className="ms-auto flex max-w-full flex-wrap justify-end gap-2">
					{ !! folder && (
						<Button
							icon={ <Download size={ 15 } aria-hidden="true" /> }
							onPress={ () => downloadFolder( folder ) }
						>
							{ __( 'Download all', 'cliezoo' ) }
						</Button>
					) }
					{ !! here?.folder && staff && (
						<People folder={ here.folder.uid } />
					) }
					{ !! here?.folder && staff && (
						<Button
							icon={ <Share2 size={ 15 } aria-hidden="true" /> }
							onPress={ () => setSharing( true ) }
						>
							{ __( 'Share', 'cliezoo' ) }
						</Button>
					) }
					{ !! here?.folder && (
						<FolderMenu
							folder={ here.folder }
							onChanged={ refresh }
							onGone={ () =>
								onOpenFolder( here.trail.at( -2 )?.uid ?? '' )
							}
							label={ __( 'More for this folder', 'cliezoo' ) }
						/>
					) }
					{ ( canFolder || canUpload ) && (
						<MenuTrigger>
							<Button
								icon={ <Plus size={ 15 } aria-hidden="true" /> }
							>
								{ __( 'New here', 'cliezoo' ) }
							</Button>
							<Menu
								onAction={ ( key: unknown ) => {
									const id = String( key );
									if ( 'folder' === id ) {
										setAdding( true );
									} else if ( 'link' === id ) {
										setLinking( true );
									} else if ( 'tree' === id ) {
										tree.current?.click();
									} else if ( 'template' === id ) {
										setMadeName( '' );
										setTemplate( '' );
										setMaking( id );
									}
								} }
								disabledKeys={ [
									...( canFolder
										? []
										: [ 'folder', 'template', 'tree' ] ),
									...( canUpload ? [] : [ 'link' ] ),
								] }
							>
								<MenuItem
									id="folder"
									icon={ <FolderPlus size={ 16 } /> }
								>
									{ __( 'Folder', 'cliezoo' ) }
								</MenuItem>
								<MenuItem
									id="template"
									icon={ <LayoutTemplate size={ 16 } /> }
								>
									{ __(
										'Folder from a template…',
										'cliezoo'
									) }
								</MenuItem>
								<MenuItem
									id="tree"
									icon={ <FolderUp size={ 16 } /> }
								>
									{ __( 'Upload a folder…', 'cliezoo' ) }
								</MenuItem>
								<MenuItem
									id="link"
									icon={ <Link2 size={ 16 } /> }
								>
									{ __( 'Link', 'cliezoo' ) }
								</MenuItem>
							</Menu>
						</MenuTrigger>
					) }
					<input
						ref={ tree }
						type="file"
						multiple
						className="sr-only"
						aria-label={ __(
							'Choose a folder to upload',
							'cliezoo'
						) }
						{ ...{ webkitdirectory: '', directory: '' } }
						onChange={ ( event ) => {
							if ( event.target.files?.length ) {
								upload( fromPicker( event.target.files ) );
							}
							event.target.value = '';
						} }
					/>
					{ /* Filled by an add-on (its badge until then), told the
					     folder and the client or project it belongs to. */ }
					<Slot
						name="files.toolbar"
						variant="chip"
						props={ {
							folder,
							client: scope.client ?? '',
							project: scope.project ?? '',
						} }
					/>
					{ canUpload && (
						<Button
							variant="primary"
							icon={ <Upload size={ 15 } aria-hidden="true" /> }
							onPress={ () => picker.current?.click() }
						>
							{ __( 'Upload', 'cliezoo' ) }
						</Button>
					) }
					<input
						ref={ picker }
						type="file"
						multiple
						className="sr-only"
						aria-label={ __( 'Choose files to upload', 'cliezoo' ) }
						onChange={ ( event ) => {
							if ( event.target.files?.length ) {
								upload( fromPicker( event.target.files ) );
							}
							event.target.value = '';
						} }
					/>
				</div>
			</div>

			{ canUpload && (
				<p className="text-xs text-subtle">
					{ __(
						'You can also drop files anywhere on this panel.',
						'cliezoo'
					) }
				</p>
			) }

			{ uploads.length > 0 && (
				<Uploads
					uploads={ uploads }
					onStop={ stop }
					onRetry={ retry }
					onDismiss={ dismiss }
					onClear={ clearFinished }
				/>
			) }

			{ browse.isPending && <Skeleton className="h-32" /> }
			{ browse.isError && (
				<ErrorState
					error={ browse.error }
					onRetry={ () => browse.refetch() }
				/>
			) }

			{ !! here && here.folders.length > 0 && (
				<ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
					{ here.folders.map( ( row ) => (
						<FolderTile
							key={ row.uid }
							folder={ row }
							onOpen={ () => onOpenFolder( row.uid ) }
							onChanged={ refresh }
						/>
					) ) }
				</ul>
			) }

			{ !! here?.items?.length && (
				<ul className="divide-y divide-line overflow-hidden rounded-lg border border-line bg-raised">
					{ here.items.map( ( item ) => {
						const Icon = ITEM_ICONS[ item.type ] ?? FileText;
						const path = recordPath( item.type, item.uid );
						return (
							<li key={ `${ item.type }:${ item.uid }` }>
								<Link
									to={ path.split( '?' )[ 0 ] }
									className="flex items-center gap-3 px-4 py-3 hover:bg-sunken"
								>
									<Icon
										size={ 18 }
										className="shrink-0 text-subtle"
										aria-hidden="true"
									/>
									<span className="min-w-0 flex-1 truncate font-medium text-fg">
										{ item.label }
									</span>
									<span className="text-xs text-subtle">
										{ item.kind }
									</span>
								</Link>
							</li>
						);
					} ) }
				</ul>
			) }

			{ sharing && !! here?.folder && (
				<Suspense fallback={ null }>
					<ShareDialog
						type="folders"
						uid={ here.folder.uid }
						isOpen
						onOpenChange={ ( on ) => ! on && setSharing( false ) }
					/>
				</Suspense>
			) }

			{ !! making && (
				<Dialog
					isOpen
					size="sm"
					onOpenChange={ ( on ) => ! on && setMaking( '' ) }
					title={
						{
							template: __( 'Folder from a template', 'cliezoo' ),
						}[ making ]
					}
					footer={ ( close ) => (
						<>
							<Button variant="ghost" onPress={ close }>
								{ __( 'Cancel', 'cliezoo' ) }
							</Button>
							<Button
								variant="primary"
								isDisabled={
									'template' === making
										? ! template
										: ! madeName.trim()
								}
								onPress={ makeHere }
							>
								{ __( 'Make it', 'cliezoo' ) }
							</Button>
						</>
					) }
				>
					<div className="space-y-3">
						{ 'template' === making && (
							<Select
								label={ __( 'Template', 'cliezoo' ) }
								items={ ( templates.data ?? [] ).map(
									( one ) => ( {
										id: one.uid,
										label: one.category
											? `${ one.name } · ${ one.category }`
											: one.name,
									} )
								) }
								selectedKey={ template || null }
								onSelectionChange={ ( key: unknown ) =>
									setTemplate( String( key ?? '' ) )
								}
								placeholder={
									templates.data && ! templates.data.length
										? __(
												'No folder templates yet: keep a folder as one from its menu.',
												'cliezoo'
											)
										: undefined
								}
							/>
						) }
						{ 'template' === making && (
							<>
								<Checkbox
									isSelected={ bringFiles }
									onChange={ setBringFiles }
								>
									{ __(
										'Bring its files, if it kept them',
										'cliezoo'
									) }
								</Checkbox>
								<Checkbox
									isSelected={ bringPeople }
									onChange={ setBringPeople }
								>
									{ __(
										'Bring its people, if it kept them',
										'cliezoo'
									) }
								</Checkbox>
							</>
						) }
						<TextField
							label={
								'template' === making
									? __(
											'Call the new folder (optional)',
											'cliezoo'
										)
									: __( 'Name', 'cliezoo' )
							}
							value={ madeName }
							onChange={ setMadeName }
							maxLength={ 190 }
						/>
					</div>
				</Dialog>
			) }

			{ !! here && (
				<FileList
					where={ where }
					onOpen={ ( one, list ) => {
						setAround( list );
						setViewing( one );
					} }
					onChanged={ refresh }
					empty={
						0 === here.folders.length ? (
							<EmptyState
								title={ __( 'Nothing here yet', 'cliezoo' ) }
							>
								{ /* The client is the reader here as often
								    as the team is, and telling them what
								    the client can see is telling them
								    about themselves. */ }
								{ nothingHere( user?.kind, canUpload ) }
							</EmptyState>
						) : null
					}
				/>
			) }

			<NewFolderDialog
				isOpen={ adding }
				onOpenChange={ setAdding }
				parent={ folder }
				scope={ scope }
				onMade={ refresh }
			/>


			<AddLinkDialog
				isOpen={ linking }
				onOpenChange={ setLinking }
				target={ target }
				onMade={ refresh }
			/>

			{ !! viewing && (
				<Suspense fallback={ null }>
					<FileViewer
						uid={ viewing }
						around={ around }
						onMove={ setViewing }
						onClose={ () => setViewing( null ) }
						onChanged={ refresh }
					/>
				</Suspense>
			) }
		</div>
	);
}

/**
 * Where you are, and the way back.
 *
 * @param root0
 * @param root0.trail
 * @param root0.onOpen
 */
function Breadcrumb( {
	trail,
	onOpen,
}: {
	trail: Crumb[];
	onOpen: ( uid: string ) => void;
} ) {
	return (
		<nav aria-label={ __( 'Where you are', 'cliezoo' ) }>
			<ol className="flex flex-wrap items-center gap-1 text-sm">
				<li>
					<Button
						variant="ghost"
						size="sm"
						onPress={ () => onOpen( '' ) }
					>
						{ __( 'All files', 'cliezoo' ) }
					</Button>
				</li>
				{ trail.map( ( crumb, index ) => (
					<li key={ crumb.uid } className="flex items-center gap-1">
						<ChevronRight
							size={ 14 }
							className="text-subtle"
							aria-hidden="true"
						/>
						{ index === trail.length - 1 ? (
							<span
								aria-current="page"
								className="font-medium text-fg"
							>
								{ crumb.name }
							</span>
						) : (
							<Button
								variant="ghost"
								size="sm"
								onPress={ () => onOpen( crumb.uid ) }
							>
								{ crumb.name }
							</Button>
						) }
					</li>
				) ) }
			</ol>
		</nav>
	);
}

/**
 * What is going up right now, and what went wrong with anything that did not.
 *
 * @param root0
 * @param root0.uploads
 * @param root0.onStop
 * @param root0.onRetry
 * @param root0.onDismiss
 * @param root0.onClear
 */
export function Uploads( {
	uploads,
	onStop,
	onRetry,
	onDismiss,
	onClear,
}: {
	uploads: UploadRow[];
	onStop: ( id: string ) => void;
	onRetry: ( id: string ) => void;
	onDismiss: ( id: string ) => void;
	onClear: () => void;
} ) {
	const finished = uploads.filter( ( one ) => one.done ).length;
	return (
		<section
			className="rounded-lg border border-line bg-raised p-3"
			aria-label={ __( 'Uploads', 'cliezoo' ) }
		>
			<div className="flex items-center gap-2">
				<h3 className="text-sm font-semibold text-fg">
					{ sprintf(
						/* translators: %d: how many files. */
						_n( '%d file', '%d files', uploads.length, 'cliezoo' ),
						uploads.length
					) }
				</h3>
				{ finished > 0 && (
					<Button
						variant="link"
						size="sm"
						className="ms-auto"
						onPress={ onClear }
					>
						{ __( 'Clear finished', 'cliezoo' ) }
					</Button>
				) }
			</div>
			{ /*
			 * Everything else in here is a bar that fills and a number that
			 * climbs, and neither says a word. This line is always on the
			 * page so that it speaks when it changes, rather than arriving
			 * with its own content and staying quiet.
			 */ }
			<p className="sr-only" role="status">
				{ uploadsSummary( uploads ) }
			</p>
			<ul className="mt-2 space-y-2">
				{ uploads.map( ( one ) => (
					<li key={ one.id }>
						<div className="flex items-center gap-2 text-sm">
							<span className="min-w-0 flex-1 truncate text-fg">
								{ one.name }
							</span>
							<span className="text-xs tabular-nums text-subtle">
								{ formatBytes( one.size ) }
							</span>
							{ one.canRetry && (
								<Button
									variant="link"
									size="sm"
									onPress={ () => onRetry( one.id ) }
								>
									{ one.stopped
										? __( 'Start again', 'cliezoo' )
										: __( 'Try again', 'cliezoo' ) }
								</Button>
							) }
							{ ! one.done && ! one.canRetry && (
								<Button
									variant="link"
									size="sm"
									onPress={ () => onStop( one.id ) }
								>
									{ __( 'Stop', 'cliezoo' ) }
								</Button>
							) }
							{ ( !! one.error || one.stopped ) && (
								<button
									type="button"
									className="text-subtle hover:text-fg"
									aria-label={ sprintf(
										/* translators: %s: a file name. */
										__( 'Dismiss %s', 'cliezoo' ),
										one.name
									) }
									onClick={ () => onDismiss( one.id ) }
								>
									<X size={ 14 } aria-hidden="true" />
								</button>
							) }
						</div>
						{ one.stopped && ! one.error && (
							<p className="text-xs text-muted">
								{ __(
									'Stopped. Starting again picks up where it left off.',
									'cliezoo'
								) }
							</p>
						) }
						{ one.error ? (
							// A file that did not go up is the one thing
							// here worth interrupting for, and it carries
							// the reason with it.
							<p role="alert" className="text-xs text-danger">
								{ sprintf(
									/* translators: 1: a file name, 2: why it failed. */
									__( '%1$s: %2$s', 'cliezoo' ),
									one.name,
									one.error
								) }
							</p>
						) : (
							<div
								className="mt-1 h-1 overflow-hidden rounded-full bg-sunken"
								role="progressbar"
								aria-label={ one.name }
								aria-valuenow={ one.percent ?? undefined }
								aria-valuemin={ 0 }
								aria-valuemax={ 100 }
							>
								<div
									className={ cx(
										'h-full rounded-full transition-[width]',
										one.done ? 'bg-success' : 'bg-accent'
									) }
									style={ {
										width: `${ one.percent ?? 2 }%`,
									} }
								/>
							</div>
						) }
					</li>
				) ) }
			</ul>
		</section>
	);
}

/**
 * Making a folder where you are standing.
 *
 * @param root0
 * @param root0.isOpen
 * @param root0.onOpenChange
 * @param root0.parent
 * @param root0.scope
 * @param root0.onMade
 * @param root0.scope.client
 * @param root0.scope.project
 */
function NewFolderDialog( {
	isOpen,
	onOpenChange,
	parent,
	scope,
	onMade,
}: {
	isOpen: boolean;
	onOpenChange: ( open: boolean ) => void;
	parent: string;
	scope: { client?: string; project?: string };
	onMade: () => void;
} ) {
	const [ name, setName ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const make = async () => {
		if ( busy || '' === name.trim() ) {
			return;
		}
		setBusy( true );
		try {
			await api.post( '/folders', {
				name: name.trim(),
				...( parent ? { parent } : scope ),
			} );
			toast.success( __( 'Folder made.', 'cliezoo' ) );
			setName( '' );
			onOpenChange( false );
			onMade();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Dialog
			isOpen={ isOpen }
			onOpenChange={ onOpenChange }
			size="sm"
			title={ __( 'New folder', 'cliezoo' ) }
			footer={ ( close ) => (
				<>
					<Button variant="ghost" onPress={ close }>
						{ __( 'Cancel', 'cliezoo' ) }
					</Button>
					<Button
						variant="primary"
						isPending={ busy }
						isDisabled={ '' === name.trim() }
						onPress={ make }
					>
						{ __( 'Make it', 'cliezoo' ) }
					</Button>
				</>
			) }
		>
			<TextField
				label={ __( 'Name', 'cliezoo' ) }
				value={ name }
				isRequired
				onChange={ setName }
			/>
		</Dialog>
	);
}

/**
 * Filing something that lives somewhere else.
 *
 * Half the work of a design studio is in Figma, and a copy of a Figma file
 * in a folder is a copy that is wrong by the afternoon. So a link is filed
 * as a file: it sits with the others, it can be approved, shared and
 * commented on, and it uses no storage at all.
 *
 * @param root0              Props.
 * @param root0.isOpen       Whether the dialog is open.
 * @param root0.onOpenChange Opens and closes it.
 * @param root0.target       Where the link is filed.
 * @param root0.onMade       Called once it exists.
 */
function AddLinkDialog( {
	isOpen,
	onOpenChange,
	target,
	onMade,
}: {
	isOpen: boolean;
	onOpenChange: ( open: boolean ) => void;
	target: UploadTarget;
	onMade: () => void;
} ) {
	const [ url, setUrl ] = useState( '' );
	const [ name, setName ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const make = async () => {
		if ( busy || '' === url.trim() ) {
			return;
		}
		setBusy( true );
		try {
			await api.post( '/files/links', {
				...target,
				url: url.trim(),
				...( name.trim() ? { name: name.trim() } : {} ),
			} );
			toast.success( __( 'Added.', 'cliezoo' ) );
			setUrl( '' );
			setName( '' );
			onOpenChange( false );
			onMade();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Dialog
			isOpen={ isOpen }
			onOpenChange={ onOpenChange }
			size="sm"
			title={ __( 'Add a link', 'cliezoo' ) }
			description={ __(
				'A Figma board, a Drive folder, a video — filed here, kept there.',
				'cliezoo'
			) }
			footer={ ( close ) => (
				<>
					<Button variant="ghost" onPress={ close }>
						{ __( 'Cancel', 'cliezoo' ) }
					</Button>
					<Button
						variant="primary"
						isPending={ busy }
						isDisabled={ '' === url.trim() }
						onPress={ make }
					>
						{ __( 'Add it', 'cliezoo' ) }
					</Button>
				</>
			) }
		>
			<div className="space-y-3">
				<TextField
					label={ __( 'Address', 'cliezoo' ) }
					type="url"
					placeholder="https://"
					value={ url }
					isRequired
					onChange={ setUrl }
				/>
				<TextField
					label={ __( 'Call it', 'cliezoo' ) }
					description={ __(
						'Optional. Without one, the address is the name.',
						'cliezoo'
					) }
					value={ name }
					onChange={ setName }
				/>
			</div>
		</Dialog>
	);
}

/**
 * Asks the server what a whole folder would weigh, then fetches it.
 *
 * @param folder Folder uid.
 */
async function downloadFolder( folder: string ): Promise< void > {
	try {
		const estimate = (
			await api.get< {
				files: number;
				size: number;
				within_limit: boolean;
			} >( `/folders/${ folder }/download` )
		).data;

		if ( 0 === estimate.files ) {
			// An empty folder is not a failure: nothing went wrong, there
			// is simply nothing in it.
			toast.info(
				__( 'There is nothing in this folder to download.', 'cliezoo' )
			);
			return;
		}
		if ( ! estimate.within_limit ) {
			toast.error(
				sprintf(
					/* translators: %s: a size, e.g. "812 MB". */
					__(
						'This folder is %s, which is more than one download can carry. Take it a folder at a time.',
						'cliezoo'
					),
					formatBytes( estimate.size )
				)
			);
			return;
		}
		const link = await api.get< { url: string } >(
			`/folders/${ folder }/link`
		);
		window.location.assign( link.data.url );
	} catch ( error ) {
		toast.error( ( error as ApiError ).message );
	}
}

/**
 * The people a folder is shared with, as faces beside its Share button.
 *
 * @param root0        Props.
 * @param root0.folder The folder's uid.
 */
function People( { folder }: { folder: string } ) {
	const access = useQuery( {
		queryKey: [ 'access', 'folders', folder ],
		queryFn: async () =>
			(
				await api.get< {
					members: Array< {
						principal: { type: string; uid: string; label: string };
					} >;
				} >( `/access/folders/${ folder }` )
			).data,
	} );
	const people = access.data?.members ?? [];
	if ( ! people.length ) {
		return null;
	}
	const shown = people.slice( 0, 4 );
	return (
		<span
			className="flex items-center -space-x-1.5"
			title={ people.map( ( one ) => one.principal.label ).join( ', ' ) }
		>
			<span className="sr-only">
				{ sprintf(
					/* translators: %s: names, comma separated. */
					__( 'Shared with %s', 'cliezoo' ),
					people.map( ( one ) => one.principal.label ).join( ', ' )
				) }
			</span>
			{ shown.map( ( one ) => (
				<span
					key={ one.principal.uid }
					className="rounded-full ring-2 ring-raised"
					aria-hidden="true"
				>
					<Avatar name={ one.principal.label } size="sm" />
				</span>
			) ) }
			{ people.length > shown.length && (
				<span className="ps-2 text-xs text-muted" aria-hidden="true">
					+{ people.length - shown.length }
				</span>
			) }
		</span>
	);
}
