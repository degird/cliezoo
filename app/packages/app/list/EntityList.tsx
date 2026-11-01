/**
 * The universal list (cliezoo.md §6.2): search, advanced filters, sort,
 * column picker, group by, saved personal views, bulk actions with undo,
 * inline edit, CSV export and infinite loading. Every module screen that
 * lists records uses it.
 */
import {
	Suspense,
	lazy,
	useEffect,
	useMemo,
	useRef,
	useState,
	type ReactNode,
} from 'react';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { useWindowVirtualizer } from '@tanstack/react-virtual';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Archive,
	ArrowDown,
	ArrowUp,
	Columns3,
	Download,
	Filter,
	Group,
	PencilLine,
	RotateCcw,
	Mail,
	MoreHorizontal,
	Tag,
	Trash2,
	UserRound,
	X,
} from 'lucide-react';
import {
	api,
	formatNumber,
	useList,
	useQueryClient,
} from '@cliezoo/data';
import {
	Badge,
	Button,
	Checkbox,
	Dialog,
	DialogTrigger,
	EmptyState,
	ErrorState,
	IconButton,
	Menu,
	MenuItem,
	MenuTrigger,
	Popover,
	SearchField,
	Skeleton,
	TextField,
	AriaDialog,
	cx,
	toast,
} from '@cliezoo/ui';
import { copyText } from '../copy';
import { countConditions } from './FilterBuilder';
import { downloadCsv, renderCell, textOf } from './cells';
import { shortcutField } from './BulkEdit';
// Opened rarely, so they arrive when they are opened rather than in the
// chunk every screen shares.
// Opened when somebody presses Import, and fetched then.
const FilterBuilder = lazy( () =>
	import( /* webpackChunkName: "list-tools" */ './FilterBuilder' ).then(
		( m ) => ( { default: m.FilterBuilder } )
	)
);
const ColumnPicker = lazy( () =>
	import( /* webpackChunkName: "list-tools" */ './ColumnPicker' ).then(
		( m ) => ( { default: m.ColumnPicker } )
	)
);
const BulkEdit = lazy( () =>
	import( /* webpackChunkName: "list-tools" */ './BulkEdit' ).then(
		( m ) => ( {
			default: m.BulkEdit,
		} )
	)
);
const SampleDataOffer = lazy( () =>
	import( /* webpackChunkName: "list-tools" */ './SampleDataOffer' ).then(
		( m ) => ( { default: m.SampleDataOffer } )
	)
);
import { SortMenu } from './SortMenu';
import { StatusRollup, rollupField } from './StatusRollup';
import {
	ViewsMenu,
	startingView,
	useSavedViews,
	viewLayout,
} from './ViewsMenu';
import { nextSort, sortLevelOf } from './sort';
import { groupRows, groupable } from './group';
import {
	LayoutSwitcher,
	OtherLayout,
	layoutsFor,
	type Layout,
} from './ListLayouts';
import {
	allFields,
	useEntityMeta,
	valueOf,
	type Condition,
	type FieldMeta,
	type FilterGroup,
	type ListConfig,
	type ListState,
} from './meta';
import { InlineEditor, canInlineEdit } from './InlineEditor';
import { conflictMessage, isConflict } from '../form/draft';
import { Slot } from '../slot';

/** Past this many rows, a list draws only those near the screen. */
const VIRTUAL_FROM = 200;
/** A row's height, for placing the ones not drawn. */
const ROW_HEIGHT = 45;

type Row = Record< string, unknown > & { uid: string };

export interface EntityListProps {
	/** Entity name, e.g. "members". */
	entity: string;
	/** REST collection path (defaults to "/{entity}"). */
	path?: string;
	/** Default visible columns. */
	columns?: string[];
	/** Custom cell renderers by field key. */
	render?: Record< string, ( row: Row ) => ReactNode >;
	/**
	 * Columns worked out rather than stored ("budget used"): shown and
	 * picked like any other, drawn by render, never sorted, filtered
	 * or edited in place.
	 */
	extraColumns?: Array< { key: string; label: string } >;
	/** Where a row opens. */
	rowHref?: ( row: Row ) => string;
	/** Always-on filter (e.g. kind = staff). */
	baseFilter?: FilterGroup;
	/** Extra toolbar content (e.g. an "Invite" button). */
	actions?: ReactNode;
	/** Shown when there are no records at all. */
	empty?: ReactNode;
	/** Accessible name of the table. */
	label?: string;
	/** Row actions menu. */
	rowMenu?: ( row: Row ) => ReactNode;
	/**
	 * A list's own acts on a row, put at the top of the default row menu —
	 * "Add a meeting" on a deal — without giving up open, link, archive and
	 * trash, as a whole `rowMenu` of its own would.
	 */
	rowExtras?: (
		row: Row
	) => Array< { id: string; label: string; run: () => void } >;
}

const STORAGE = 'cliezoo-list:';

/**
 * How many rows one CSV takes. The browser holds the whole file in memory
 * while it builds it, so this is a real limit rather than a licence check;
 * when it bites, the person is told.
 */
const EXPORT_LIMIT = 5000;

/** The list as a file's contents: what exportRows() gives. */
export type ListExport = {
	name: string;
	header: string[];
	/** Each column's field type, for a file that keeps numbers numbers. */
	types: string[];
	rows: string[][];
	/**
	 * The same cells as stored, for a file that keeps a number a number
	 * rather than the text "1,234".
	 */
	raw: unknown[][];
	/** How many records there are in all (more than rows when cut off). */
	total: number;
};

/**
 * What an exported file is called, before the `.csv`: the list's own word
 * for its records, lower case, with the spaces closed up into hyphens.
 * Both exports go through here, because they were drifting apart — the
 * ticked-rows one dropped the whole word and wrote "client-.csv".
 *
 * @param plural What the list calls its records, e.g. "Clients".
 */
export function exportName( plural: string ): string {
	return plural.toLowerCase().replace( /\s+/g, '-' );
}

/** What a row's own action says when it has happened. */
const ROW_DONE = (): Record< string, string > => ( {
	archive: __( 'Archived.', 'cliezoo' ),
	unarchive: __( 'Out of the archive.', 'cliezoo' ),
	trash: __( 'Moved to the trash.', 'cliezoo' ),
	restore: __( 'Restored.', 'cliezoo' ),
	purge: __( 'Deleted for good.', 'cliezoo' ),
} );

const UNDO: Partial< Record< string, 'unarchive' | 'restore' > > = {
	archive: 'unarchive',
	trash: 'restore',
};

function stateLabel( state: ListState ): string {
	if ( state === 'archived' ) {
		return __( 'Archived', 'cliezoo' );
	}
	return state === 'trash'
		? __( 'Trash', 'cliezoo' )
		: __( 'Active', 'cliezoo' );
}

function sortState(
	active: boolean,
	desc: boolean
): 'ascending' | 'descending' | undefined {
	if ( ! active ) {
		return undefined;
	}
	return desc ? 'descending' : 'ascending';
}

function loadLocal( entity: string ): Partial< ListConfig > {
	try {
		return JSON.parse(
			window.localStorage.getItem( STORAGE + entity ) ?? '{}'
		);
	} catch {
		return {};
	}
}

function saveLocal( entity: string, config: ListConfig ): void {
	try {
		window.localStorage.setItem(
			STORAGE + entity,
			JSON.stringify( {
				columns: config.columns,
				sort: config.sort,
				groupBy: config.groupBy,
				layout: config.layout,
			} )
		);
	} catch {
		// Private mode: the list still works, it just forgets the layout.
	}
}

/**
 * A filter handed over in the address (?filter=…), as a dashboard number
 * links to what it counts. Only plain conditions are taken — a field, an
 * operator and a value — and the server checks every one of them again.
 *
 * @param value What the address holds.
 */
export function linkedFilter( value: unknown ): FilterGroup {
	let given = value;
	if ( typeof given === 'string' ) {
		try {
			given = JSON.parse( given );
		} catch {
			return {};
		}
	}
	const isCondition = ( one: unknown ): one is Condition =>
		!! one &&
		typeof one === 'object' &&
		typeof ( one as Condition ).field === 'string' &&
		typeof ( one as Condition ).op === 'string' &&
		[ 'string', 'number', 'boolean', 'undefined' ].includes(
			typeof ( one as Condition ).value
		);
	if ( isCondition( given ) ) {
		return { and: [ given ] };
	}
	if ( ! given || typeof given !== 'object' ) {
		return {};
	}
	const group = given as FilterGroup;
	const pick = ( list: unknown ) =>
		Array.isArray( list ) ? list.filter( isCondition ).slice( 0, 10 ) : [];
	if ( pick( group.and ).length ) {
		return { and: pick( group.and ) };
	}
	return pick( group.or ).length ? { or: pick( group.or ) } : {};
}

function mergeFilters(
	base: FilterGroup | undefined,
	user: FilterGroup
): FilterGroup {
	const parts = [ base, user ].filter(
		( f ): f is FilterGroup => !! f && countConditions( f ) > 0
	);
	if ( ! parts.length ) {
		return {};
	}
	return parts.length === 1 ? parts[ 0 ] : { and: parts as never };
}

/**
 * The words a list was asked to search for in its own address.
 *
 * The filter and the saved view beside it already travel this way; the
 * words somebody typed were the one thing a copied link forgot.
 *
 * @param linked   What the address carries.
 * @param linked.q
 */
export function searchInAddress( linked: { q?: unknown } ): string {
	return 'string' === typeof linked.q ? linked.q : '';
}

export default function EntityList( {
	entity,
	path,
	columns,
	render = {},
	rowHref,
	baseFilter,
	actions,
	empty,
	label,
	rowMenu,
	rowExtras,
	extraColumns = [],
}: EntityListProps ) {
	const extras: FieldMeta[] = extraColumns.map( ( column ) => ( {
		key: column.key,
		label: column.label,
		type: 'computed',
		readonly: true,
		filterable: false,
		sortable: false,
	} ) );
	const collection = path ?? `/${ entity }`;
	const navigate = useNavigate();
	const meta = useEntityMeta( entity );
	const client = useQueryClient();
	const linked = useSearch( { strict: false } ) as {
		filter?: unknown;
		view?: unknown;
		q?: unknown;
	};
	const [ config, setConfig ] = useState< ListConfig | null >( null );
	// The saved view the list is showing, while it still is one.
	const [ activeView, setActiveView ] = useState< string | undefined >();
	// "Reset layout" asked for the plain list, not the default view.
	const [ plain, setPlain ] = useState( false );
	const [ bulkEdit, setBulkEdit ] = useState< {
		mode: 'field' | 'tags';
		start?: string;
	} | null >( null );
	// One message to everybody who was ticked.
	const [ sending, setSending ] = useState( false );
	const [ subject, setSubject ] = useState( '' );
	const [ letter, setLetter ] = useState( '' );
	const [ search, setSearch ] = useState( searchInAddress( linked ) );
	const [ selected, setSelected ] = useState< Set< string > >( new Set() );
	const [ editing, setEditing ] = useState< {
		uid: string;
		key: string;
	} | null >( null );
	// Deleting for good is asked about, always: 'picked' is the rows that
	// are ticked, 'all' is the whole trash.
	// A half-built filter, kept out here because the builder's popover
	// unmounts as soon as anything outside it is clicked.
	const [ filterDraft, setFilterDraft ] = useState< FilterGroup | null >(
		null
	);
	const [ purging, setPurging ] = useState< 'picked' | 'all' | null >( null );
	// What becomes of a client's or project's files when it goes for good
	// (an add-on asks, in the confirmation); kept in the library otherwise.
	const [ purgeFiles, setPurgeFiles ] = useState< string >( 'keep' );
	const [ emptying, setEmptying ] = useState( false );
	const sentinel = useRef< HTMLDivElement >( null );
	const views = useSavedViews( entity, !! meta.data );
	const viewsSettled = views.isFetched || views.isError;

	// Initial layout: the view named in the address or the person's default
	// view, else what was left locally, else the given or first useful
	// columns. It waits for the views, so a list with a default does not
	// flash its plain layout first.
	useEffect( () => {
		if ( ! meta.data || config || ! viewsSettled ) {
			return;
		}
		const fields = [ ...allFields( meta.data ), ...extras ];
		const start = plain
			? undefined
			: startingView( views.data, linked.view );
		const layout = viewLayout( start?.config );
		const local: Partial< ListConfig > = start
			? layout
			: loadLocal( entity );
		setActiveView( start?.uid );
		const fallback =
			columns ??
			[
				meta.data.title_field,
				...fields
					.filter(
						( f ) =>
							! f.system &&
							f.key !== meta.data.title_field &&
							f.type !== 'json' &&
							f.type !== 'html' &&
							f.type !== 'longtext'
					)
					.map( ( f ) => f.key ),
			].slice( 0, 6 );
		setConfig( {
			columns: ( local.columns ?? fallback ).filter( ( key ) =>
				fields.some( ( f ) => f.key === key )
			),
			sort: local.sort ?? meta.data.default_sort,
			groupBy: local.groupBy ?? '',
			layout: local.layout ?? 'table',
			// A filter handed over in the address is what was asked for;
			// otherwise the view's own.
			filter:
				undefined !== linked.filter
					? linkedFilter( linked.filter )
					: ( layout.filter ?? {} ),
			q: searchInAddress( linked ),
			state: 'active',
		} );
	}, [ meta.data, viewsSettled, ! config ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// A starred view followed from the sidebar while this list is open.
	useEffect( () => {
		if ( ! config || 'string' !== typeof linked.view ) {
			return;
		}
		const view = views.data?.find( ( v ) => v.uid === linked.view );
		if ( view && view.uid !== activeView ) {
			applyView( view );
		}
	}, [ linked.view ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Debounced search.
	useEffect( () => {
		const timer = window.setTimeout(
			() => setConfig( ( c ) => ( c ? { ...c, q: search } : c ) ),
			300
		);
		return () => window.clearTimeout( timer );
	}, [ search ] );

	useEffect( () => {
		if ( config ) {
			saveLocal( entity, config );
		}
	}, [ entity, config ] );

	const filter = mergeFilters( baseFilter, config?.filter ?? {} );
	const query = config
		? {
				q: config.q || undefined,
				sort: config.sort || undefined,
				state: config.state,
				filter: Object.keys( filter ).length
					? JSON.stringify( filter )
					: undefined,
				with_total: true,
			}
		: {};
	const list = useList< Row >( collection, query, {
		enabled: !! config,
		limit: 50,
	} );
	const rows = useMemo(
		() => list.data?.pages.flatMap( ( page ) => page.data ) ?? [],
		[ list.data ]
	);
	const total = list.data?.pages[ 0 ]?.meta?.total;

	/**
	 * Shows a saved view: its layout and filters, keeping what is being
	 * searched and which records (active, archived, trash) are shown.
	 *
	 * @param view        The view.
	 * @param view.uid
	 * @param view.config
	 */
	function applyView( view: {
		uid: string;
		config: Partial< ListConfig > | null;
	} ) {
		const layout = viewLayout( view.config );
		setActiveView( view.uid );
		setConfig( ( current ) =>
			current
				? {
						...current,
						...layout,
						filter: layout.filter ?? {},
					}
				: current
		);
	}

	// Infinite loading.
	useEffect( () => {
		const node = sentinel.current;
		if ( ! node || ! list.hasNextPage ) {
			return;
		}
		const observer = new IntersectionObserver( ( entries ) => {
			if ( entries[ 0 ]?.isIntersecting && ! list.isFetchingNextPage ) {
				list.fetchNextPage();
			}
		} );
		observer.observe( node );
		return () => observer.disconnect();
	}, [ list.hasNextPage, list.isFetchingNextPage, rows.length ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect(
		() => setSelected( new Set() ),
		[ config?.filter, config?.q, config?.state ]
	);

	// A long list draws only the rows near the screen: ten thousand rows
	// are ten thousand table rows otherwise. Grouped lists stay whole, as
	// their headings need every group's rows.
	const tableBody = useRef< HTMLTableSectionElement >( null );
	const windowed = ! config?.groupBy && rows.length > VIRTUAL_FROM;
	const virtual = useWindowVirtualizer( {
		count: windowed ? rows.length : 0,
		estimateSize: () => ROW_HEIGHT,
		overscan: 15,
		scrollMargin: tableBody.current
			? tableBody.current.getBoundingClientRect().top + window.scrollY
			: 0,
	} );
	if ( meta.isError ) {
		return (
			<div className="p-4 sm:px-6">
				<ErrorState
					error={ meta.error }
					title={ __( 'This list is not available', 'cliezoo' ) }
					onRetry={ () => meta.refetch() }
				/>
			</div>
		);
	}
	if ( ! meta.data || ! config ) {
		return (
			<div className="space-y-2 p-4" aria-busy="true">
				<Skeleton className="h-9 w-72 max-w-full" />
				<Skeleton className="h-64" />
			</div>
		);
	}

	const m = meta.data;
	const fields = [ ...allFields( m ), ...extras ];
	const byKey = new Map( fields.map( ( f ) => [ f.key, f ] ) );
	const visible = config.columns
		.map( ( key ) => byKey.get( key ) )
		.filter( ( f ): f is FieldMeta => !! f );
	const groupField = config.groupBy ? byKey.get( config.groupBy ) : undefined;
	const set = ( change: Partial< ListConfig > ) => {
		// Changing the layout makes it no longer the saved view it was.
		if (
			[ 'columns', 'sort', 'filter', 'groupBy' ].some(
				( key ) => key in change
			)
		) {
			setActiveView( undefined );
		}
		setConfig( { ...config, ...change } );
	};
	const refresh = () => client.invalidateQueries( { queryKey: [ entity ] } );
	// How the rows are drawn: a table unless another was chosen, and only
	// a layout this list's fields can draw.
	const offered = layoutsFor( fields, config.groupBy );
	const layout: Layout = offered.includes( config.layout as Layout )
		? ( config.layout as Layout )
		: 'table';
	const statusField = rollupField( fields );
	const selectedUids = [ ...selected ];
	const assignField = shortcutField( fields, 'assign' );
	const statusShortcut = shortcutField( fields, 'status' );
	const tagsField = fields.find(
		( f ) => 'tags' === f.key && 'json' === f.type && ! f.readonly
	);
	// A message can only be sent where the record keeps an address.
	const emailField = fields.find( ( f ) => 'email' === f.key );

	/**
	 * Empties the trash, a bounded page at a time, until the server says
	 * there is nothing left it may delete. A trash with ten thousand things
	 * in it is one request the server should not be asked to survive.
	 */
	const emptyTrash = async () => {
		setEmptying( true );
		let purged = 0;
		let left = 0;
		try {
			for ( let round = 0; round < 20; round += 1 ) {
				const result = (
					await api.post< { purged: number; left: number } >(
						`${ collection }/empty-trash`,
						{ files: purgeFiles }
					)
				).data;
				purged += result.purged;
				left = result.left;
				if ( ! result.purged || ! result.left ) {
					break;
				}
			}
			setSelected( new Set() );
			refresh();
			toast.success(
				left
					? sprintf(
							/* translators: 1: how many were deleted, 2: how many are left. */
							__(
								'%1$d deleted. %2$d could not be, and are still in the trash.',
								'cliezoo'
							),
							purged,
							left
						)
					: sprintf(
							/* translators: %d: how many were deleted. */
							_n(
								'%d record deleted for good.',
								'%d records deleted for good.',
								purged,
								'cliezoo'
							),
							purged
						)
			);
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		} finally {
			setEmptying( false );
		}
	};

	/**
	 * What one row can have done to it, without leaving the list.
	 *
	 * The same endpoint the bulk bar uses, with one uid — so a row and a
	 * ticked selection can never drift apart, and neither can what each of
	 * them is allowed to do.
	 *
	 * @param row       The record.
	 * @param operation What to do to it.
	 */
	const rowAction = async (
		row: Row,
		operation: 'archive' | 'unarchive' | 'trash' | 'restore' | 'purge'
	) => {
		try {
			await api.post( `${ collection }/bulk`, {
				uids: [ row.uid ],
				operation,
			} );
			refresh();
			toast.success( ROW_DONE()[ operation ] );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		}
	};

	/**
	 * The menu a row gets when its list has not brought one of its own:
	 * open it, copy a link to it, and whichever of archive, trash, restore
	 * and delete this reader may do from where the list is standing.
	 *
	 * A list that passes `rowMenu` keeps it; this is the default, so a new
	 * list is not a list whose rows can only be acted on four clicks away.
	 *
	 * @param row The record.
	 */
	const ownMenu = ( row: Row ) => {
		const active = config.state === 'active';
		const trashed = config.state === 'trash';
		const href = rowHref?.( row );
		const called = String( valueOf( row, m.title_field ) ?? row.uid );
		const acts = active && rowExtras ? rowExtras( row ) : [];
		return (
			<MenuTrigger>
				<IconButton
					size="sm"
					variant="ghost"
					label={ sprintf(
						/* translators: %s: what the row is called. */
						__( 'More for %s', 'cliezoo' ),
						called
					) }
					icon={ <MoreHorizontal size={ 15 } aria-hidden="true" /> }
				/>
				<Menu
					onAction={ ( key ) => {
						const what = String( key );
						const extra = acts.find(
							( one ) => `extra:${ one.id }` === what
						);
						if ( extra ) {
							extra.run();
							return;
						}
						if ( 'open' === what && href ) {
							navigate( { to: href } );
							return;
						}
						if ( 'link' === what && href ) {
							copyText(
								new URL( href, window.location.href ).toString()
							);
							return;
						}
						rowAction(
							row,
							what as
								| 'archive'
								| 'unarchive'
								| 'trash'
								| 'restore'
								| 'purge'
						);
					} }
				>
					{ acts.map( ( one ) => (
						<MenuItem key={ one.id } id={ `extra:${ one.id }` }>
							{ one.label }
						</MenuItem>
					) ) }
					{ href ? (
						<MenuItem id="open">
							{ __( 'Open', 'cliezoo' ) }
						</MenuItem>
					) : null }
					{ href ? (
						<MenuItem id="link">
							{ __( 'Copy link', 'cliezoo' ) }
						</MenuItem>
					) : null }
					{ active && m.can.archive ? (
						<MenuItem id="archive">
							{ __( 'Archive', 'cliezoo' ) }
						</MenuItem>
					) : null }
					{ config.state === 'archived' && m.can.archive ? (
						<MenuItem id="unarchive">
							{ __( 'Out of the archive', 'cliezoo' ) }
						</MenuItem>
					) : null }
					{ ! trashed && m.can.delete ? (
						<MenuItem id="trash" danger>
							{ __( 'Move to the trash', 'cliezoo' ) }
						</MenuItem>
					) : null }
					{ trashed && m.can.delete ? (
						<MenuItem id="restore">
							{ __( 'Restore', 'cliezoo' ) }
						</MenuItem>
					) : null }
					{ trashed && m.can.purge ? (
						<MenuItem id="purge" danger>
							{ __( 'Delete for good', 'cliezoo' ) }
						</MenuItem>
					) : null }
				</Menu>
			</MenuTrigger>
		);
	};

	const bulk = async (
		operation:
			'archive' | 'trash' | 'restore' | 'unarchive' | 'purge' | 'send',
		/** What a "send" says; nothing for the rest. */
		message?: { subject: string; body: string }
	) => {
		const uids = [ ...selected ];
		try {
			// The server says which of them it managed and which it would
			// not: a record somebody may not archive stays where it is, and
			// saying "12 updated" when nine were is how a list loses trust.
			const answer = (
				await api.post< {
					done?: string[];
					failed?: Record< string, string >;
				} >( `${ collection }/bulk`, {
					uids,
					operation,
					...message,
					// A client's or project's files, when it goes for good.
					...( 'purge' === operation ? { files: purgeFiles } : {} ),
				} )
			).data;
			const done = answer.done ?? uids;
			const refused = Object.values( answer.failed ?? {} );
			setSelected( new Set() );
			refresh();
			const undo = done.length ? ( UNDO[ operation ] ?? null ) : null;
			if ( refused.length ) {
				toast.warning(
					sprintf(
						/* translators: 1: how many were done, 2: how many were not, 3: why the first one was refused. */
						__( '%1$d done, %2$d left alone: %3$s', 'cliezoo' ),
						done.length,
						refused.length,
						refused[ 0 ]
					)
				);
			}
			// What a bulk says when it is over depends on what it did.
			let said: string = sprintf(
				/* translators: %d: number of records. */
				_n(
					'%d record updated.',
					'%d records updated.',
					done.length,
					'cliezoo'
				),
				done.length
			);
			if ( 'send' === operation ) {
				said = sprintf(
					/* translators: %d: how many were written to. */
					_n(
						'Your message is on its way to %d record.',
						'Your message is on its way to %d records.',
						done.length,
						'cliezoo'
					),
					done.length
				);
			} else if ( 'purge' === operation ) {
				said = sprintf(
					/* translators: %d: how many were deleted. */
					_n(
						'%d record deleted for good.',
						'%d records deleted for good.',
						done.length,
						'cliezoo'
					),
					done.length
				);
			}
			toast.success(
				said,
				undo
					? {
							action: {
								label: __( 'Undo', 'cliezoo' ),
								onAction: () =>
									api
										.post( `${ collection }/bulk`, {
											uids: done,
											operation: undo,
										} )
										.then( refresh )
										// An undo that failed has to say so;
										// silence would look like success.
										.catch( ( error: unknown ) =>
											toast.error(
												( error as Error ).message
											)
										),
							},
						}
					: {}
			);
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		}
	};

	/**
	 * The list as rows of text, as the columns show them. With rows ticked
	 * it is those rows and nothing else: somebody who ticked four records
	 * and pressed Export meant those four, not the four thousand behind
	 * them. Read by the CSV export here and by an add-on's (Excel, PDF)
	 * through the list.toolbar slot.
	 *
	 * @param onlyPicked Whether to take the ticked rows alone.
	 * @return The file name, the column headings and the rows.
	 */
	const exportRows = async ( onlyPicked = false ): Promise< ListExport > => {
		const header = visible.map( ( f ) => f.label );
		const types = visible.map( ( f ) => f.type );
		const name = exportName( m.plural_label );
		if ( onlyPicked ) {
			const picked = rows.filter( ( row ) => selected.has( row.uid ) );
			return {
				name,
				header,
				types,
				rows: picked.map( ( row ) =>
					visible.map( ( f ) =>
						textOf( f, valueOf( row, f.key ), row )
					)
				),
				raw: picked.map( ( row ) =>
					visible.map( ( f ) => valueOf( row, f.key ) )
				),
				total: picked.length,
			};
		}
		let pages = list.data?.pages ?? [];
		while (
			pages[ pages.length - 1 ]?.meta?.has_more &&
			pages.length * 50 < EXPORT_LIMIT
		) {
			const result = await list.fetchNextPage();
			pages = result.data?.pages ?? pages;
		}
		const all = pages.flatMap( ( page ) => page.data );
		return {
			name,
			header,
			types,
			rows: all.map( ( row ) =>
				visible.map( ( f ) => textOf( f, valueOf( row, f.key ), row ) )
			),
			raw: all.map( ( row ) =>
				visible.map( ( f ) => valueOf( row, f.key ) )
			),
			total: typeof total === 'number' ? total : all.length,
		};
	};

	/**
	 * The list as a CSV file.
	 *
	 * @param onlyPicked Whether to write the ticked rows alone.
	 */
	const exportCsv = async ( onlyPicked = false ) => {
		const file = await exportRows( onlyPicked );
		downloadCsv( file.name, file.header, file.rows );
		// A file that quietly stopped at five thousand rows is worse than
		// one that says it did.
		if ( file.total > file.rows.length ) {
			toast.warning(
				sprintf(
					/* translators: 1: number of exported records, 2: total number of records. */
					__(
						'The file has the first %1$s of %2$s. Filter the list to export the rest.',
						'cliezoo'
					),
					formatNumber( file.rows.length ),
					formatNumber( file.total )
				)
			);
		}
	};

	/**
	 * A click on a column header: sorts by it alone, or with Shift held
	 * adds it as the next sort level (the sort menu does the same without
	 * a held key).
	 *
	 * @param field The column.
	 * @param add   Whether Shift was held.
	 */
	const toggleSort = ( field: FieldMeta, add: boolean ) => {
		if ( ! field.sortable ) {
			return;
		}
		set( { sort: nextSort( config.sort, field.key, add ) } );
	};

	const groups = groupRows( rows, groupField );

	const slice = virtual.getVirtualItems();
	const before =
		windowed && slice.length
			? slice[ 0 ].start - virtual.options.scrollMargin
			: 0;
	const after =
		windowed && slice.length
			? virtual.getTotalSize() -
				( slice[ slice.length - 1 ].end - virtual.options.scrollMargin )
			: 0;
	const shownRows = ( group: { label: string; rows: Row[] } ): Row[] =>
		windowed
			? slice.map( ( item ) => group.rows[ item.index ] )
			: group.rows;

	const filtered =
		countConditions( config.filter ) > 0 ||
		!! config.q ||
		config.state !== 'active';
	const allChecked =
		rows.length > 0 && rows.every( ( row ) => selected.has( row.uid ) );

	return (
		<div className="flex flex-col">
			{ /* Toolbar */ }
			<div className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2.5 sm:px-6">
				{ m.searchable || fields.some( ( f ) => f.searchable ) ? (
					<SearchField
						label={ sprintf(
							/* translators: %s: plural record name. */
							__( 'Search %s', 'cliezoo' ),
							m.plural_label.toLowerCase()
						) }
						placeholder={ __( 'Search…', 'cliezoo' ) }
						value={ search }
						onChange={ setSearch }
						className="w-full sm:w-64"
					/>
				) : null }
				<DialogTrigger>
					<Button
						size="sm"
						icon={ <Filter size={ 14 } aria-hidden="true" /> }
					>
						{ __( 'Filter', 'cliezoo' ) }
						{ countConditions( config.filter ) > 0 && (
							<Badge tone="accent">
								{ countConditions( config.filter ) }
							</Badge>
						) }
					</Button>
					<Popover placement="bottom start">
						<AriaDialog
							aria-label={ __( 'Filter', 'cliezoo' ) }
							className="outline-none"
						>
							{ ( { close } ) => (
								<Suspense
									fallback={
										<Skeleton className="h-64 w-80 max-w-full" />
									}
								>
									<FilterBuilder
										fields={ fields }
										value={ filterDraft ?? config.filter }
										onDraft={ setFilterDraft }
										onApply={ ( f ) => {
											setFilterDraft( null );
											set( { filter: f } );
										} }
										onClose={ close }
									/>
								</Suspense>
							) }
						</AriaDialog>
					</Popover>
				</DialogTrigger>
				<MenuTrigger>
					<Button
						size="sm"
						icon={ <Group size={ 14 } aria-hidden="true" /> }
					>
						{ groupField
							? groupField.label
							: __( 'Group', 'cliezoo' ) }
					</Button>
					<Menu
						onAction={ ( key ) =>
							set( {
								groupBy: key === '__none' ? '' : String( key ),
							} )
						}
					>
						<MenuItem id="__none">
							{ __( 'No grouping', 'cliezoo' ) }
						</MenuItem>
						{ fields.filter( groupable ).map( ( f ) => (
							<MenuItem key={ f.key } id={ f.key }>
								{ f.label }
							</MenuItem>
						) ) }
					</Menu>
				</MenuTrigger>
				<SortMenu
					fields={ fields }
					sort={ config.sort }
					onChange={ ( sort ) => set( { sort } ) }
				/>
				<LayoutSwitcher
					value={ layout }
					offered={ offered }
					onChange={ ( next ) => set( { layout: next } ) }
				/>
				<DialogTrigger>
					<Button
						size="sm"
						icon={ <Columns3 size={ 14 } aria-hidden="true" /> }
					>
						{ __( 'Columns', 'cliezoo' ) }
					</Button>
					<Popover placement="bottom start">
						<AriaDialog
							aria-label={ __( 'Columns', 'cliezoo' ) }
							className="max-h-96 w-72 overflow-auto p-3 outline-none"
						>
							<Suspense
								fallback={ <Skeleton className="h-48" /> }
							>
								<ColumnPicker
									fields={ fields }
									columns={ config.columns }
									titleField={ m.title_field }
									onChange={ ( next ) =>
										set( { columns: next } )
									}
								/>
							</Suspense>
						</AriaDialog>
					</Popover>
				</DialogTrigger>
				<ViewsMenu
					entity={ entity }
					views={ views.data ?? [] }
					active={ activeView }
					current={ {
						columns: config.columns,
						sort: config.sort,
						filter: config.filter,
						groupBy: config.groupBy,
						layout: config.layout,
					} }
					onApply={ applyView }
					onReset={ () => {
						try {
							window.localStorage.removeItem( STORAGE + entity );
						} catch {
							// Nothing was kept, so there is nothing to forget.
						}
						setPlain( true );
						setActiveView( undefined );
						setConfig( null );
					} }
				/>
				{ m.soft_delete && (
					<MenuTrigger>
						<Button
							size="sm"
							variant={
								config.state === 'active'
									? 'secondary'
									: 'primary'
							}
						>
							{ stateLabel( config.state ) }
						</Button>
						<Menu
							onAction={ ( key ) =>
								set( { state: key as ListState } )
							}
						>
							<MenuItem id="active">
								{ __( 'Active', 'cliezoo' ) }
							</MenuItem>
							<MenuItem id="archived">
								{ __( 'Archived', 'cliezoo' ) }
							</MenuItem>
							<MenuItem id="trash">
								{ __( 'Trash', 'cliezoo' ) }
							</MenuItem>
						</Menu>
					</MenuTrigger>
				) }
				{ config.state === 'trash' && m.can.purge && (
					<Button
						size="sm"
						variant="ghost"
						icon={ <Trash2 size={ 14 } aria-hidden="true" /> }
						onPress={ () => setPurging( 'all' ) }
						isDisabled={ ! rows.length }
					>
						{ __( 'Empty the trash', 'cliezoo' ) }
					</Button>
				) }
				<Button
					size="sm"
					variant="ghost"
					icon={ <Download size={ 14 } aria-hidden="true" /> }
					onPress={ () => exportCsv() }
					isDisabled={ ! rows.length }
				>
					{ __( 'Export', 'cliezoo' ) }
				</Button>
				{ /* An add-on's tools for this list, given the kind of
				     record and a way to filter it as the filter builder
				     does. */ }
				<Slot
					name="list.toolbar"
					badge={ false }
					props={ {
						entity,
						applyFilter: ( f: FilterGroup ) => set( { filter: f } ),
						exportRows,
						picked: selected.size,
					} }
				/>
				{ /* Wraps like the toolbar around it, so it never runs past a
				     phone screen. */ }
				<div className="ms-auto flex flex-wrap items-center justify-end gap-2">
					{ typeof total === 'number' && (
						<span className="text-sm text-muted" aria-live="polite">
							{ sprintf(
								/* translators: %s: number of records. */
								_n(
									'%s record',
									'%s records',
									total,
									'cliezoo'
								),
								formatNumber( total )
							) }
						</span>
					) }
					{ actions }
				</div>
			</div>

			{ /* Bulk bar */ }
			{ selected.size > 0 && (
				<div
					className="sticky top-14 z-20 flex flex-wrap items-center gap-2 border-b border-line bg-accent-subtle px-4 py-2 sm:px-6"
					role="region"
					aria-label={ __( 'Bulk actions', 'cliezoo' ) }
				>
					<span className="text-sm font-medium">
						{ sprintf(
							/* translators: %d: number of selected records. */
							_n(
								'%d selected',
								'%d selected',
								selected.size,
								'cliezoo'
							),
							selected.size
						) }
					</span>
					{ /* The rows that were ticked, as a file: somebody who
					     ticked four and pressed Export meant those four. */ }
					<Button
						size="sm"
						icon={ <Download size={ 14 } aria-hidden="true" /> }
						onPress={ () => exportCsv( true ) }
					>
						{ __( 'Export these', 'cliezoo' ) }
					</Button>
					{ config.state === 'active' && m.can.update && (
						<>
							<Button
								size="sm"
								icon={
									<PencilLine
										size={ 14 }
										aria-hidden="true"
									/>
								}
								onPress={ () =>
									setBulkEdit( { mode: 'field' } )
								}
							>
								{ __( 'Edit…', 'cliezoo' ) }
							</Button>
							{ assignField && (
								<Button
									size="sm"
									icon={
										<UserRound
											size={ 14 }
											aria-hidden="true"
										/>
									}
									onPress={ () =>
										setBulkEdit( {
											mode: 'field',
											start: assignField.key,
										} )
									}
								>
									{ __( 'Assign…', 'cliezoo' ) }
								</Button>
							) }
							{ statusShortcut && (
								<Button
									size="sm"
									onPress={ () =>
										setBulkEdit( {
											mode: 'field',
											start: statusShortcut.key,
										} )
									}
								>
									{ __( 'Change status…', 'cliezoo' ) }
								</Button>
							) }
							{ tagsField && (
								<Button
									size="sm"
									icon={
										<Tag size={ 14 } aria-hidden="true" />
									}
									onPress={ () =>
										setBulkEdit( { mode: 'tags' } )
									}
								>
									{ __( 'Tag…', 'cliezoo' ) }
								</Button>
							) }
						</>
					) }
					{ /* Only where there is somewhere to write to. */ }
					{ emailField && m.can.update && (
						<Button
							size="sm"
							icon={ <Mail size={ 14 } aria-hidden="true" /> }
							onPress={ () => setSending( true ) }
						>
							{ __( 'Send a message…', 'cliezoo' ) }
						</Button>
					) }
					{ config.state === 'active' && m.can.archive && (
						<Button
							size="sm"
							icon={ <Archive size={ 14 } aria-hidden="true" /> }
							onPress={ () => bulk( 'archive' ) }
						>
							{ __( 'Archive', 'cliezoo' ) }
						</Button>
					) }
					{ config.state !== 'trash' && m.can.delete && (
						<Button
							size="sm"
							variant="danger"
							icon={ <Trash2 size={ 14 } aria-hidden="true" /> }
							onPress={ () => bulk( 'trash' ) }
						>
							{ __( 'Move to trash', 'cliezoo' ) }
						</Button>
					) }
					{ config.state !== 'active' && (
						<Button
							size="sm"
							icon={
								<RotateCcw size={ 14 } aria-hidden="true" />
							}
							onPress={ () =>
								bulk(
									config.state === 'trash'
										? 'restore'
										: 'unarchive'
								)
							}
						>
							{ __( 'Restore', 'cliezoo' ) }
						</Button>
					) }
					{ config.state === 'trash' && m.can.purge && (
						<Button
							size="sm"
							variant="danger"
							icon={ <Trash2 size={ 14 } aria-hidden="true" /> }
							onPress={ () => setPurging( 'picked' ) }
						>
							{ __( 'Delete for good', 'cliezoo' ) }
						</Button>
					) }
					<Button
						size="sm"
						variant="ghost"
						icon={ <X size={ 14 } aria-hidden="true" /> }
						onPress={ () => setSelected( new Set() ) }
					>
						{ __( 'Clear', 'cliezoo' ) }
					</Button>
				</div>
			) }

			{ /* Status roll-up: what the rows below hold, by status. */ }
			{ statusField && ! list.isError && rows.length > 0 && (
				<StatusRollup
					entity={ entity }
					field={ statusField }
					query={ {
						filter: Object.keys( filter ).length
							? JSON.stringify( filter )
							: undefined,
						q: config.q || undefined,
						state: config.state,
					} }
					onPick={ ( value ) =>
						set( {
							filter: mergeFilters( config.filter, {
								and: [
									{
										field: statusField.key,
										op: 'eq',
										value,
									},
								],
							} ),
						} )
					}
				/>
			) }

			{ /* Table */ }
			{ list.isPending && (
				<div className="space-y-2 p-4 sm:px-6" aria-busy="true">
					{ [ 0, 1, 2, 3, 4 ].map( ( i ) => (
						<Skeleton key={ i } className="h-10" />
					) ) }
				</div>
			) }
			{ list.isError && (
				<div className="p-4 sm:px-6">
					<ErrorState
						error={ list.error }
						onRetry={ () => list.refetch() }
					/>
				</div>
			) }
			{ ! list.isPending &&
				! list.isError &&
				! rows.length &&
				filtered && (
					<EmptyState
						title={ __( 'No matching records', 'cliezoo' ) }
						actions={
							<Button
								onPress={ () => {
									setSearch( '' );
									set( {
										filter: {},
										q: '',
										state: 'active',
									} );
								} }
							>
								{ __( 'Clear filters', 'cliezoo' ) }
							</Button>
						}
					>
						{ __(
							'Try other words or remove some filters.',
							'cliezoo'
						) }
					</EmptyState>
				) }
			{ ! list.isPending &&
				! list.isError &&
				! rows.length &&
				! filtered &&
				( empty ?? (
					<EmptyState
						title={ sprintf(
							/* translators: %s: plural record name. */ __(
								'No %s yet',
								'cliezoo'
							),
							m.plural_label.toLowerCase()
						) }
						actions={
							<Suspense fallback={ null }>
								<SampleDataOffer />
							</Suspense>
						}
					/>
				) ) }
			{ ! list.isPending && rows.length > 0 && 'table' !== layout && (
				<OtherLayout
					layout={ layout as Exclude< Layout, 'table' > }
					rows={ rows }
					fields={ fields }
					columns={ config.columns }
					titleField={ m.title_field }
					groupBy={ config.groupBy }
					collection={ collection }
					canUpdate={ !! m.can.update && 'active' === config.state }
					label={ label ?? m.plural_label }
					rowHref={ rowHref }
					render={ render }
					onChanged={ refresh }
				/>
			) }
			{ ! list.isPending && rows.length > 0 && 'table' === layout && (
				// A table this wide is meant to be scrolled sideways inside
				// its own box. Without containment its width still counts
				// towards the page's, and a phone browser answers that by
				// zooming the whole page out — 412px of screen showing
				// 1158px of layout, every control a third of its size.
				<div className="overflow-x-auto [contain:layout]">
					<table
						className="w-full border-collapse text-sm"
						aria-label={ label ?? m.plural_label }
						aria-rowcount={ total ?? rows.length }
					>
						<thead className="sticky top-0 z-10 bg-surface text-start text-xs font-semibold text-muted">
							<tr>
								{ m.can.bulk && (
									<th
										scope="col"
										className="w-10 border-b border-line px-4 py-2 sm:ps-6"
									>
										<Checkbox
											aria-label={ __(
												'Select all',
												'cliezoo'
											) }
											isSelected={ allChecked }
											isIndeterminate={
												! allChecked &&
												selected.size > 0
											}
											onChange={ ( on ) =>
												setSelected(
													on
														? new Set(
																rows.map(
																	( r ) =>
																		r.uid
																)
															)
														: new Set()
												)
											}
										/>
									</th>
								) }
								{ visible.map( ( f ) => {
									const level = sortLevelOf(
										config.sort,
										f.key
									);
									// Only the first level is the column
									// the table is sorted by, as far as
									// aria-sort goes; the others say their
									// place in words.
									return (
										<th
											key={ f.key }
											scope="col"
											aria-sort={ sortState(
												level?.level === 1,
												!! level?.desc
											) }
											className="whitespace-nowrap border-b border-line px-3 py-2 text-start font-semibold"
										>
											{ f.sortable ? (
												<button
													type="button"
													className="inline-flex items-center gap-1 rounded hover:text-fg focus-visible:outline-2 focus-visible:outline-focus"
													title={ __(
														'Click to sort. Shift-click to sort by this as well.',
														'cliezoo'
													) }
													onClick={ ( event ) =>
														toggleSort(
															f,
															event.shiftKey
														)
													}
												>
													{ f.label }
													{ level &&
														( level.desc ? (
															<ArrowDown
																size={ 12 }
																aria-hidden="true"
															/>
														) : (
															<ArrowUp
																size={ 12 }
																aria-hidden="true"
															/>
														) ) }
													{ level && level.of > 1 && (
														<>
															<span
																aria-hidden="true"
																className="text-[0.65rem] text-subtle"
															>
																{ level.level }
															</span>
															<span className="sr-only">
																{ sprintf(
																	/* translators: 1: sort level, 2: how many levels. */
																	__(
																		'(sort %1$d of %2$d)',
																		'cliezoo'
																	),
																	level.level,
																	level.of
																) }
															</span>
														</>
													) }
												</button>
											) : (
												f.label
											) }
										</th>
									);
								} ) }
								<th
									scope="col"
									className="w-10 border-b border-line"
								>
									<span className="sr-only">
										{ __( 'Actions', 'cliezoo' ) }
									</span>
								</th>
							</tr>
						</thead>
						{ groups.map( ( group ) => (
							<tbody
								key={ group.label || 'all' }
								ref={ windowed ? tableBody : undefined }
							>
								{ before > 0 && (
									<tr aria-hidden="true">
										<td style={ { height: before } } />
									</tr>
								) }
								{ group.label && (
									<tr>
										<th
											scope="rowgroup"
											colSpan={
												visible.length +
												( m.can.bulk ? 1 : 0 ) +
												1
											}
											className="border-b border-line bg-sunken px-4 py-1.5 text-start text-xs font-semibold text-muted sm:px-6"
										>
											{ group.label }{ ' ' }
											<span className="font-normal">
												({ group.rows.length })
											</span>
										</th>
									</tr>
								) }
								{ shownRows( group ).map( ( row ) => (
									<tr
										key={ row.uid }
										aria-rowindex={
											windowed
												? rows.indexOf( row ) + 2
												: undefined
										}
										className={ cx(
											'group border-b border-line hover:bg-sunken/60',
											selected.has( row.uid ) &&
												'bg-accent-subtle/60'
										) }
									>
										{ m.can.bulk && (
											<td className="px-4 py-2 sm:ps-6">
												<Checkbox
													aria-label={ __(
														'Select row',
														'cliezoo'
													) }
													isSelected={ selected.has(
														row.uid
													) }
													onChange={ ( on ) => {
														const next = new Set(
															selected
														);
														if ( on ) {
															next.add( row.uid );
														} else {
															next.delete(
																row.uid
															);
														}
														setSelected( next );
													} }
												/>
											</td>
										) }
										{ visible.map( ( f ) => {
											const value = valueOf( row, f.key );
											const isTitle =
												f.key === m.title_field;
											const editable =
												m.can.update &&
												config.state === 'active' &&
												canInlineEdit( f ) &&
												! render[ f.key ];
											const isEditing =
												editing?.uid === row.uid &&
												editing.key === f.key;
											let content: ReactNode = render[
												f.key
											]
												? render[ f.key ]( row )
												: renderCell( f, value, row );
											if ( isTitle && rowHref ) {
												content = (
													<Link
														to={ rowHref( row ) }
														className="font-medium text-fg hover:text-accent-fg hover:underline focus-visible:outline-2 focus-visible:outline-focus"
													>
														{ content }
													</Link>
												);
											}
											return (
												<td
													key={ f.key }
													className={ cx(
														'max-w-xs px-3 py-2 align-middle',
														isTitle &&
															'font-medium',
														editable &&
															! isEditing &&
															'cursor-text'
													) }
													onDoubleClick={
														editable
															? () =>
																	setEditing(
																		{
																			uid: row.uid,
																			key: f.key,
																		}
																	)
															: undefined
													}
												>
													{ isEditing ? (
														<InlineEditor
															field={ f }
															value={ value }
															onCancel={ () =>
																setEditing(
																	null
																)
															}
															onSave={ async (
																next
															) => {
																try {
																	const payload =
																		f.key.startsWith(
																			'custom.'
																		)
																			? {
																					custom: {
																						[ f.key.slice(
																							7
																						) ]:
																							next,
																					},
																				}
																			: {
																					[ f.key ]:
																						next,
																				};
																	await api.patch(
																		`${ collection }/${ row.uid }`,
																		payload,
																		row.etag
																			? {
																					ifMatch: `"${ String(
																						row.etag
																					) }"`,
																				}
																			: {}
																	);
																	setEditing(
																		null
																	);
																	refresh();
																} catch ( error ) {
																	// Somebody else saved first: say
																	// so, and show what they saved
																	// rather than a message about a
																	// version number.
																	if (
																		isConflict(
																			error
																		)
																	) {
																		setEditing(
																			null
																		);
																		refresh();
																		toast.warning(
																			conflictMessage()
																		);
																		return;
																	}
																	toast.error(
																		(
																			error as Error
																		 )
																			.message
																	);
																}
															} }
														/>
													) : (
														<div className="flex min-w-0 items-center gap-1">
															<div className="min-w-0 flex-1 truncate">
																{ content }
															</div>
															{ editable && (
																<button
																	type="button"
																	className="sr-only rounded px-1 text-xs text-muted hover:text-fg focus-visible:not-sr-only focus-visible:outline-2 focus-visible:outline-focus group-hover:not-sr-only"
																	onClick={ () =>
																		setEditing(
																			{
																				uid: row.uid,
																				key: f.key,
																			}
																		)
																	}
																>
																	{ __(
																		'Edit',
																		'cliezoo'
																	) }
																	<span className="sr-only">
																		{ ' ' }
																		{
																			f.label
																		}
																	</span>
																</button>
															) }
														</div>
													) }
												</td>
											);
										} ) }
										<td className="px-2 py-1 text-end">
											{ rowMenu
												? rowMenu( row )
												: ownMenu( row ) }
										</td>
									</tr>
								) ) }
								{ after > 0 && (
									<tr aria-hidden="true">
										<td style={ { height: after } } />
									</tr>
								) }
							</tbody>
						) ) }
					</table>
					<div ref={ sentinel } className="h-10">
						{ list.isFetchingNextPage && (
							<Skeleton className="mx-4 my-2 h-8" />
						) }
					</div>
				</div>
			) }

			<Dialog
				isOpen={ null !== purging }
				onOpenChange={ ( open ) => ! open && setPurging( null ) }
				role="alertdialog"
				size="sm"
				title={ __( 'Delete for good?', 'cliezoo' ) }
				footer={ ( close ) => (
					<>
						<Button variant="ghost" onPress={ close }>
							{ __( 'Cancel', 'cliezoo' ) }
						</Button>
						<Button
							variant="danger"
							isPending={ emptying }
							onPress={ () => {
								const which = purging;
								setPurging( null );
								if ( 'all' === which ) {
									emptyTrash();
								} else {
									bulk( 'purge' );
								}
							} }
						>
							{ __( 'Delete for good', 'cliezoo' ) }
						</Button>
					</>
				) }
			>
				<p className="text-sm text-muted">
					{ 'all' === purging
						? __(
								'Everything in the trash is deleted, along with whatever hangs off it. This cannot be undone, and there is no second copy.',
								'cliezoo'
							)
						: sprintf(
								/* translators: %d: how many records are picked. */
								_n(
									'%d record is deleted, along with whatever hangs off it. This cannot be undone.',
									'%d records are deleted, along with whatever hangs off them. This cannot be undone.',
									selected.size,
									'cliezoo'
								),
								selected.size
							) }
				</p>
				<Slot
					name="list.purge"
					badge={ false }
					props={ {
						entity,
						choice: purgeFiles,
						choose: setPurgeFiles,
					} }
				/>
			</Dialog>

			{ bulkEdit && (
				<Suspense fallback={ null }>
					<BulkEdit
						mode={ bulkEdit.mode }
						start={ bulkEdit.start }
						fields={ fields }
						collection={ collection }
						uids={ selectedUids }
						onClose={ () => setBulkEdit( null ) }
						onDone={ () => {
							setBulkEdit( null );
							setSelected( new Set() );
							refresh();
						} }
					/>
				</Suspense>
			) }
			<Dialog
				isOpen={ sending }
				onOpenChange={ setSending }
				size="md"
				title={ __( 'Send a message', 'cliezoo' ) }
				description={ sprintf(
					/* translators: %d: how many were ticked. */
					_n(
						'It goes to the %d record you ticked, at the address it keeps.',
						'It goes to the %d records you ticked, each at the address it keeps.',
						selectedUids.length,
						'cliezoo'
					),
					selectedUids.length
				) }
				footer={ ( close ) => (
					<>
						<Button variant="ghost" onPress={ close }>
							{ __( 'Cancel', 'cliezoo' ) }
						</Button>
						<Button
							variant="primary"
							isDisabled={
								'' === subject.trim() || '' === letter.trim()
							}
							onPress={ async () => {
								await bulk( 'send', { subject, body: letter } );
								close();
								setSubject( '' );
								setLetter( '' );
							} }
						>
							{ __( 'Send it', 'cliezoo' ) }
						</Button>
					</>
				) }
			>
				<div className="space-y-3">
					<TextField
						label={ __( 'Subject', 'cliezoo' ) }
						value={ subject }
						onChange={ setSubject }
					/>
					<TextField
						label={ __( 'Message', 'cliezoo' ) }
						description={ __(
							'{{name}} and {{first_name}} become each person’s own.',
							'cliezoo'
						) }
						value={ letter }
						onChange={ setLetter }
						multiline
						rows={ 6 }
					/>
					{ /* An add-on's help with the message: given what it is
					     and a way to put words in. */ }
					<Slot
						name="list.message"
						badge={ false }
						props={ {
							entity,
							subject,
							write: ( words: {
								subject?: string;
								body?: string;
							} ) => {
								if ( words.subject ) {
									setSubject( words.subject );
								}
								if ( words.body ) {
									setLetter( words.body );
								}
							},
						} }
					/>
				</div>
			</Dialog>

		</div>
	);
}
