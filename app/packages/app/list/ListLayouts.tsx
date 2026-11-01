/**
 * The same records, looked at another way: cards, a board, a calendar.
 *
 * A table is not always how somebody thinks of a list. Deals are a board,
 * people are cards, anything with a date is a calendar. These draw the rows
 * the list already has — the same filter, sort and search — so switching
 * is looking, never a second query with its own idea of what is in it.
 */
import { useMemo, useState, type DragEvent, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Link } from '@tanstack/react-router';
import {
	CalendarDays,
	ChevronLeft,
	ChevronRight,
	Columns3,
	LayoutGrid,
	Table2,
} from 'lucide-react';
import { api, formatDate, type ApiError } from '@cliezoo/data';
import {
	IconButton,
	Menu,
	MenuItem,
	MenuTrigger,
	Button,
	toast,
} from '@cliezoo/ui';
import { renderCell, textOf } from './cells';
import { valueOf, type FieldMeta } from './meta';

export type Layout = 'table' | 'cards' | 'board' | 'calendar';

type Row = Record< string, unknown > & { uid: string; etag?: unknown };

/** The layouts, in the order they are offered. */
export const LAYOUTS = (): Array< {
	id: Layout;
	label: string;
	icon: ReactNode;
} > => [
	{
		id: 'table',
		label: __( 'Table', 'cliezoo' ),
		icon: <Table2 size={ 14 } aria-hidden="true" />,
	},
	{
		id: 'cards',
		label: __( 'Cards', 'cliezoo' ),
		icon: <LayoutGrid size={ 14 } aria-hidden="true" />,
	},
	{
		id: 'board',
		label: __( 'Board', 'cliezoo' ),
		icon: <Columns3 size={ 14 } aria-hidden="true" />,
	},
	{
		id: 'calendar',
		label: __( 'Calendar', 'cliezoo' ),
		icon: <CalendarDays size={ 14 } aria-hidden="true" />,
	},
];

/**
 * The field a board is laid out by: the one grouped by, else the first
 * with a closed list of values.
 *
 * @param fields  Every field.
 * @param groupBy The field grouped by, if any.
 */
export function boardField(
	fields: FieldMeta[],
	groupBy: string
): FieldMeta | undefined {
	const closed = fields.filter(
		( f ) =>
			! f.system &&
			( !! f.list ||
				( 'enum' === f.type && ( f.options?.length ?? 0 ) > 1 ) )
	);
	return (
		fields.find( ( f ) => f.key === groupBy ) ??
		closed.find( ( f ) => [ 'status', 'stage' ].includes( f.key ) ) ??
		closed[ 0 ]
	);
}

/**
 * The date a calendar places a record on: a due date or a start before
 * any other.
 *
 * @param fields Every field.
 */
export function calendarField( fields: FieldMeta[] ): FieldMeta | undefined {
	const dated = fields.filter(
		( f ) => ( 'date' === f.type || 'datetime' === f.type ) && ! f.system
	);
	return (
		dated.find( ( f ) =>
			[ 'due_date', 'starts_at', 'start_date', 'date' ].includes( f.key )
		) ?? dated[ 0 ]
	);
}

/**
 * Which layouts this list can be drawn in.
 *
 * @param fields  Every field.
 * @param groupBy The field grouped by.
 */
export function layoutsFor( fields: FieldMeta[], groupBy: string ): Layout[] {
	return LAYOUTS()
		.map( ( one ) => one.id )
		.filter(
			( id ) =>
				( 'board' !== id || !! boardField( fields, groupBy ) ) &&
				( 'calendar' !== id || !! calendarField( fields ) )
		);
}

/**
 * The switcher: one button per layout, the chosen one pressed.
 *
 * @param root0          Props.
 * @param root0.value    The layout shown.
 * @param root0.offered  The layouts this list can be drawn in.
 * @param root0.onChange Told the one chosen.
 */
export function LayoutSwitcher( {
	value,
	offered,
	onChange,
}: {
	value: Layout;
	offered: Layout[];
	onChange: ( layout: Layout ) => void;
} ) {
	if ( offered.length < 2 ) {
		return null;
	}
	return (
		<div
			role="group"
			aria-label={ __( 'How to look at it', 'cliezoo' ) }
			className="inline-flex rounded-md border border-line"
		>
			{ LAYOUTS()
				.filter( ( one ) => offered.includes( one.id ) )
				.map( ( one ) => (
					<IconButton
						key={ one.id }
						size="sm"
						variant={ value === one.id ? 'secondary' : 'ghost' }
						label={ one.label }
						aria-pressed={ value === one.id }
						icon={ one.icon }
						onPress={ () => onChange( one.id ) }
					/>
				) ) }
		</div>
	);
}

type Props = {
	layout: Exclude< Layout, 'table' >;
	rows: Row[];
	fields: FieldMeta[];
	/** The fields the table shows, which a card shows too. */
	columns: string[];
	titleField: string;
	groupBy: string;
	collection: string;
	canUpdate: boolean;
	label: string;
	rowHref?: ( row: Row ) => string;
	render?: Record< string, ( row: Row ) => ReactNode >;
	onChanged: () => void;
};

/**
 * What a record is called, as a link where it has a page.
 *
 * @param root0            Props.
 * @param root0.row        The record.
 * @param root0.titleField Its title field.
 * @param root0.rowHref    Where it opens.
 * @param root0.block      Whether it takes a whole line, as in a calendar day.
 */
function Title( {
	row,
	titleField,
	rowHref,
	block = false,
}: {
	row: Row;
	titleField: string;
	rowHref?: ( row: Row ) => string;
	block?: boolean;
} ) {
	const called = String( valueOf( row, titleField ) ?? '' ) || row.uid;
	return rowHref ? (
		<Link
			to={ rowHref( row ) }
			className={
				( block ? 'block min-h-6 truncate leading-6 ' : '' ) +
				'font-medium text-fg hover:text-accent-fg hover:underline focus-visible:outline-2 focus-visible:outline-focus'
			}
		>
			{ called }
		</Link>
	) : (
		<span className="font-medium text-fg">{ called }</span>
	);
}

/**
 * The other layouts.
 *
 * @param props The list's rows and how to read them.
 */
export function OtherLayout( props: Props ) {
	if ( 'cards' === props.layout ) {
		return <Cards { ...props } />;
	}
	if ( 'board' === props.layout ) {
		return <Board { ...props } />;
	}
	return <Calendar { ...props } />;
}

/**
 * The fields a card shows under its title.
 *
 * @param props The list.
 */
function shownOnCard( props: Props ): FieldMeta[] {
	return props.columns
		.filter( ( key ) => key !== props.titleField )
		.map( ( key ) => props.fields.find( ( f ) => f.key === key ) )
		.filter( ( f ): f is FieldMeta => !! f )
		.slice( 0, 4 );
}

/**
 * One record as a card.
 *
 * @param root0       Props.
 * @param root0.row   The record.
 * @param root0.props The list.
 * @param root0.extra Anything else on it.
 */
function Card( {
	row,
	props,
	extra,
}: {
	row: Row;
	props: Props;
	extra?: ReactNode;
} ) {
	return (
		<article className="flex flex-col gap-1 rounded-lg border border-line bg-surface p-3 text-sm">
			<div className="flex items-start gap-2">
				<div className="min-w-0 flex-1">
					<Title
						row={ row }
						titleField={ props.titleField }
						rowHref={ props.rowHref }
					/>
				</div>
				{ extra }
			</div>
			<dl className="flex flex-col gap-0.5">
				{ shownOnCard( props ).map( ( f ) => {
					const value = valueOf( row, f.key );
					if (
						null === value ||
						undefined === value ||
						'' === value
					) {
						return null;
					}
					return (
						<div key={ f.key } className="flex gap-2">
							<dt className="shrink-0 text-xs text-muted">
								{ f.label }
							</dt>
							<dd className="min-w-0 truncate text-xs text-fg">
								{ props.render?.[ f.key ]
									? props.render[ f.key ]( row )
									: renderCell( f, value, row ) }
							</dd>
						</div>
					);
				} ) }
			</dl>
		</article>
	);
}

/**
 * Records as cards, in the list's order.
 *
 * @param props The list.
 */
function Cards( props: Props ) {
	return (
		<ul
			aria-label={ props.label }
			className="grid grid-cols-1 gap-3 p-3 sm:grid-cols-2 lg:grid-cols-3"
		>
			{ props.rows.map( ( row ) => (
				<li key={ row.uid }>
					<Card row={ row } props={ props } />
				</li>
			) ) }
		</ul>
	);
}

/**
 * Records in columns by one field's value, moved by dragging or by the
 * card's own menu — the menu being what a keyboard has.
 *
 * @param props The list.
 */
function Board( props: Props ) {
	const field = boardField( props.fields, props.groupBy ) as FieldMeta;
	const [ over, setOver ] = useState( '' );
	// A closed list moves; a reference or an open list is grouped only.
	const movable =
		props.canUpdate &&
		! field.readonly &&
		( 'enum' === field.type || !! field.list );

	const columns = useMemo( () => {
		const keys = [
			...( field.options ?? [] ),
			...props.rows.map( ( row ) =>
				String( valueOf( row, field.key ) ?? '' )
			),
		].filter( ( key, at, all ) => all.indexOf( key ) === at );
		return keys.map( ( key ) => ( {
			key,
			label:
				'' === key
					? __( 'None', 'cliezoo' )
					: String(
							props.rows
								.map( ( row ) =>
									String(
										valueOf( row, field.key ) ?? ''
									) === key
										? textOf( field, key, row )
										: ''
								)
								.find( Boolean ) ||
								textOf( field, key ) ||
								key
						),
			rows: props.rows.filter(
				( row ) => String( valueOf( row, field.key ) ?? '' ) === key
			),
		} ) );
	}, [ field, props.rows ] );

	const move = async ( row: Row, to: string ) => {
		if ( String( valueOf( row, field.key ) ?? '' ) === to ) {
			return;
		}
		try {
			await api.patch(
				`${ props.collection }/${ row.uid }`,
				{ [ field.key ]: to },
				row.etag ? { ifMatch: `"${ String( row.etag ) }"` } : {}
			);
			props.onChanged();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	return (
		<div
			role="region"
			aria-label={ sprintf(
				/* translators: 1: the records, 2: the field they are laid out by. */
				__( '%1$s by %2$s', 'cliezoo' ),
				props.label,
				field.label
			) }
			tabIndex={ 0 }
			className="flex gap-3 overflow-x-auto p-3 focus-visible:outline-2 focus-visible:outline-focus"
		>
			{ columns.map( ( column ) => (
				<section
					key={ column.key || '__none' }
					aria-label={ column.label }
					onDragOver={ ( event: DragEvent ) => {
						if ( movable ) {
							event.preventDefault();
							setOver( column.key );
						}
					} }
					onDragLeave={ () => setOver( '' ) }
					onDrop={ ( event: DragEvent ) => {
						event.preventDefault();
						setOver( '' );
						const uid = event.dataTransfer.getData( 'text/plain' );
						const row = props.rows.find(
							( one ) => one.uid === uid
						);
						if ( row ) {
							move( row, column.key );
						}
					} }
					className={
						'flex w-72 shrink-0 flex-col gap-2 rounded-lg border p-2 ' +
						( over === column.key
							? 'border-accent bg-accent/5'
							: 'border-line bg-sunken/50' )
					}
				>
					<h3 className="flex items-center justify-between px-1 text-xs font-semibold text-muted">
						<span>{ column.label }</span>
						<span>{ column.rows.length }</span>
					</h3>
					<ul className="flex flex-col gap-2">
						{ column.rows.map( ( row ) => (
							<li
								key={ row.uid }
								draggable={ movable }
								onDragStart={ ( event: DragEvent ) =>
									event.dataTransfer.setData(
										'text/plain',
										row.uid
									)
								}
							>
								<Card
									row={ row }
									props={ props }
									extra={
										movable ? (
											<MenuTrigger>
												<Button
													size="sm"
													variant="ghost"
													aria-label={ sprintf(
														/* translators: %s: what the record is called. */
														__(
															'Move %s',
															'cliezoo'
														),
														String(
															valueOf(
																row,
																props.titleField
															) ?? ''
														)
													) }
												>
													{ __( 'Move', 'cliezoo' ) }
												</Button>
												<Menu
													onAction={ ( key ) =>
														move(
															row,
															String( key )
														)
													}
												>
													{ columns
														.filter(
															( other ) =>
																other.key !==
																	column.key &&
																'' !== other.key
														)
														.map( ( other ) => (
															<MenuItem
																key={
																	other.key
																}
																id={ other.key }
															>
																{ other.label }
															</MenuItem>
														) ) }
												</Menu>
											</MenuTrigger>
										) : undefined
									}
								/>
							</li>
						) ) }
					</ul>
				</section>
			) ) }
		</div>
	);
}

/**
 * A day as the calendar keys it.
 *
 * @param at The day.
 */
function dayKey( at: Date ): string {
	return [
		at.getFullYear(),
		String( at.getMonth() + 1 ).padStart( 2, '0' ),
		String( at.getDate() ).padStart( 2, '0' ),
	].join( '-' );
}

/**
 * Records on a month's days by their date. The month moves; the records
 * are the ones the list has loaded, so a filter narrows the calendar too.
 *
 * @param props The list.
 */
function Calendar( props: Props ) {
	const field = calendarField( props.fields ) as FieldMeta;
	const [ month, setMonth ] = useState( () => {
		const now = new Date();
		return new Date( now.getFullYear(), now.getMonth(), 1 );
	} );

	const byDay = useMemo( () => {
		const out = new Map< string, Row[] >();
		for ( const row of props.rows ) {
			const raw = String( valueOf( row, field.key ) ?? '' );
			if ( ! raw ) {
				continue;
			}
			// A date-time is placed on the day it falls in here.
			const day =
				'datetime' === field.type
					? dayKey( new Date( raw ) )
					: raw.slice( 0, 10 );
			out.set( day, [ ...( out.get( day ) ?? [] ), row ] );
		}
		return out;
	}, [ field, props.rows ] );

	const first = new Date( month );
	// Weeks start on Monday, as a working week does.
	const lead = ( first.getDay() + 6 ) % 7;
	const days = new Date(
		month.getFullYear(),
		month.getMonth() + 1,
		0
	).getDate();
	const cells: Array< Date | null > = [
		...Array.from( { length: lead }, () => null ),
		...Array.from(
			{ length: days },
			( _, at ) =>
				new Date( month.getFullYear(), month.getMonth(), at + 1 )
		),
	];
	const undated = props.rows.filter(
		( row ) => ! valueOf( row, field.key )
	).length;
	const title = month.toLocaleDateString( undefined, {
		month: 'long',
		year: 'numeric',
	} );

	return (
		<section
			aria-label={ sprintf(
				/* translators: 1: the records, 2: the date they are placed by. */
				__( '%1$s by %2$s', 'cliezoo' ),
				props.label,
				field.label
			) }
			className="flex flex-col gap-2 p-3"
		>
			<div className="flex items-center gap-2">
				<IconButton
					size="sm"
					variant="ghost"
					label={ __( 'The month before', 'cliezoo' ) }
					icon={ <ChevronLeft size={ 15 } aria-hidden="true" /> }
					onPress={ () =>
						setMonth(
							new Date(
								month.getFullYear(),
								month.getMonth() - 1,
								1
							)
						)
					}
				/>
				<h3 className="min-w-40 text-center text-sm font-semibold text-fg">
					{ title }
				</h3>
				<IconButton
					size="sm"
					variant="ghost"
					label={ __( 'The month after', 'cliezoo' ) }
					icon={ <ChevronRight size={ 15 } aria-hidden="true" /> }
					onPress={ () =>
						setMonth(
							new Date(
								month.getFullYear(),
								month.getMonth() + 1,
								1
							)
						)
					}
				/>
				{ undated > 0 && (
					<span className="ms-auto text-xs text-muted">
						{ sprintf(
							/* translators: 1: how many, 2: the date field. */
							__( '%1$d with no %2$s', 'cliezoo' ),
							undated,
							field.label.toLowerCase()
						) }
					</span>
				) }
			</div>
			<ol className="grid grid-cols-7 gap-px overflow-hidden rounded-lg border border-line bg-line text-xs">
				{ cells.map( ( day, at ) => {
					if ( ! day ) {
						return (
							<li
								key={ `gap-${ at }` }
								aria-hidden="true"
								className="min-h-20 bg-sunken/50"
							/>
						);
					}
					const on = byDay.get( dayKey( day ) ) ?? [];
					return (
						<li
							key={ dayKey( day ) }
							aria-label={ formatDate( dayKey( day ) ) }
							className="flex min-h-20 min-w-0 flex-col gap-1 bg-surface p-1"
						>
							<span className="text-muted">
								{ day.getDate() }
							</span>
							{ on.map( ( row ) => (
								<Title
									key={ row.uid }
									row={ row }
									titleField={ props.titleField }
									rowHref={ props.rowHref }
									block
								/>
							) ) }
						</li>
					);
				} ) }
			</ol>
		</section>
	);
}
