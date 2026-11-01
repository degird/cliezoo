/**
 * The overview: one of my dashboards — as many as I like — with the
 * period it looks at, whether it compares with the period before, and
 * its cards, which I can add, take away, resize and put in order.
 *
 * Somebody who has never made one sees the starter dashboard; it is saved
 * as theirs the first time they change anything about it.
 */
import { Suspense, useEffect, useMemo, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useNavigate, useSearch } from '@tanstack/react-router';
import {
	ArrowLeft,
	ArrowRight,
	GripVertical,
	LayoutGrid,
	MoreHorizontal,
	Plus,
	X,
} from 'lucide-react';
import { api, useQuery, useQueryClient, type ApiError } from '@cliezoo/data';
import {
	Alert,
	Button,
	ConfirmDialog,
	Dialog,
	ErrorState,
	IconButton,
	Menu,
	MenuItem,
	MenuTrigger,
	Select,
	Skeleton,
	Switch,
	TextField,
	cx,
	toast,
} from '@cliezoo/ui';
import type { WidgetDef } from '../../packages/app/registry';
import { ErrorBoundary } from '../../shell/ErrorBoundary';
import { useWidgets, widgetComponent } from '../../shell/Widgets';
import {
	CardContext,
	RANGES,
	cardsOf,
	daysFromToday,
	today,
	useCatalog,
	type Card,
	type Dashboard,
	type Period,
	type Range,
	type Size,
} from './api';
import { Slot } from '../../packages/app/slot';

const SPANS: Record< Size, string > = {
	sm: '',
	md: 'md:col-span-2',
	lg: 'md:col-span-2 xl:col-span-3',
};

const SIZES = (): Array< { id: Size; label: string } > => [
	{ id: 'sm', label: __( 'Small', 'cliezoo' ) },
	{ id: 'md', label: __( 'Wide', 'cliezoo' ) },
	{ id: 'lg', label: __( 'Full width', 'cliezoo' ) },
];

function useDashboards() {
	return useQuery( {
		queryKey: [ 'dashboards' ],
		queryFn: async () => {
			const all: Dashboard[] = [];
			let cursor = '';
			do {
				const page = await api.get< Dashboard[] >( '/dashboards', {
					sort: 'position',
					limit: 200,
					...( cursor ? { cursor } : {} ),
				} );
				all.push( ...page.data );
				cursor = page.meta?.has_more
					? ( page.meta.next_cursor ?? '' )
					: '';
			} while ( cursor );
			return all;
		},
	} );
}

function CardFrame( {
	card,
	index,
	count,
	widget,
	period,
	editing,
	onChange,
	onMove,
	onRemove,
	onDragStart,
	onDrop,
}: {
	card: Card;
	index: number;
	count: number;
	widget: WidgetDef | undefined;
	period: Period;
	editing: boolean;
	onChange: ( card: Card ) => void;
	onMove: ( to: number ) => void;
	onRemove: () => void;
	onDragStart: () => void;
	onDrop: () => void;
} ) {
	const title = widget?.title ?? __( 'Not available', 'cliezoo' );
	const id = `card-${ index }`;
	const context = useMemo(
		() => ( {
			period,
			settings: card.settings,
			editing,
			change: ( settings: NonNullable< Card[ 'settings' ] > ) =>
				onChange( { ...card, settings } ),
		} ),
		[ period, card, editing, onChange ]
	);
	if ( ! widget && ! editing ) {
		return null;
	}
	const Component = widget ? widgetComponent( widget ) : null;
	return (
		<section
			aria-labelledby={ id }
			className={ cx(
				'rounded-lg border border-line bg-raised p-4',
				SPANS[ card.size ] ?? '',
				editing && 'border-dashed'
			) }
			draggable={ editing }
			onDragStart={ ( event ) => {
				event.dataTransfer.effectAllowed = 'move';
				onDragStart();
			} }
			onDragOver={ ( event ) => editing && event.preventDefault() }
			onDrop={ ( event ) => {
				event.preventDefault();
				onDrop();
			} }
		>
			<div className="mb-3 flex items-center gap-1">
				{ editing && (
					<GripVertical
						size={ 14 }
						className="cursor-grab text-subtle"
						aria-hidden="true"
					/>
				) }
				<h2 id={ id } className="text-sm font-semibold text-fg">
					{ title }
				</h2>
				{ editing && (
					<div className="ms-auto flex items-center gap-0.5">
						<IconButton
							size="sm"
							label={ __( 'Move earlier', 'cliezoo' ) }
							icon={
								<ArrowLeft size={ 14 } aria-hidden="true" />
							}
							isDisabled={ index === 0 }
							onPress={ () => onMove( index - 1 ) }
						/>
						<IconButton
							size="sm"
							label={ __( 'Move later', 'cliezoo' ) }
							icon={
								<ArrowRight size={ 14 } aria-hidden="true" />
							}
							isDisabled={ index === count - 1 }
							onPress={ () => onMove( index + 1 ) }
						/>
						<MenuTrigger>
							<IconButton
								size="sm"
								label={ sprintf(
									/* translators: %s: a card's title. */
									__( 'Size of %s', 'cliezoo' ),
									title
								) }
								icon={
									<LayoutGrid
										size={ 14 }
										aria-hidden="true"
									/>
								}
							/>
							<Menu
								selectionMode="single"
								selectedKeys={ [ card.size ] }
								onAction={ ( key: unknown ) =>
									onChange( {
										...card,
										size: String( key ) as Size,
									} )
								}
							>
								{ SIZES().map( ( size ) => (
									<MenuItem key={ size.id } id={ size.id }>
										{ size.label }
									</MenuItem>
								) ) }
							</Menu>
						</MenuTrigger>
						<IconButton
							size="sm"
							label={ sprintf(
								/* translators: %s: the name of what is being removed. */
								__( 'Remove %s', 'cliezoo' ),
								title
							) }
							icon={ <X size={ 14 } aria-hidden="true" /> }
							onPress={ onRemove }
						/>
					</div>
				) }
			</div>
			{ Component ? (
				<ErrorBoundary
					fallback={
						<Alert tone="warning">
							{ __( 'This card could not be shown.', 'cliezoo' ) }
						</Alert>
					}
				>
					<CardContext.Provider value={ context }>
						<Suspense fallback={ <Skeleton className="h-24" /> }>
							{ /* The card's settings and period, handed to an add-on's
							     card too (it cannot reach this context). */ }
							<Component { ...( { card: context } as object ) } />
						</Suspense>
					</CardContext.Provider>
				</ErrorBoundary>
			) : (
				<p className="text-sm text-muted">
					{ __(
						'This card belongs to a part of Cliezoo that is switched off, or that you cannot use.',
						'cliezoo'
					) }
				</p>
			) }
		</section>
	);
}

function Picker( {
	widgets,
	onPick,
	onClose,
}: {
	widgets: WidgetDef[];
	onPick: ( widget: WidgetDef ) => void;
	onClose: () => void;
} ) {
	return (
		<Dialog
			isOpen
			onOpenChange={ ( open ) => ! open && onClose() }
			title={ __( 'Add a card', 'cliezoo' ) }
		>
			<ul className="space-y-1">
				{ widgets.map( ( widget ) => (
					<li key={ widget.id }>
						<Button
							variant="ghost"
							className="w-full justify-start"
							icon={ <Plus size={ 14 } aria-hidden="true" /> }
							onPress={ () => onPick( widget ) }
						>
							{ widget.title }
						</Button>
					</li>
				) ) }
			</ul>
		</Dialog>
	);
}

export default function Overview() {
	const client = useQueryClient();
	const navigate = useNavigate();
	const search = useSearch( { strict: false } ) as { board?: string };
	const dashboards = useDashboards();
	const catalog = useCatalog();
	const widgets = useWidgets( 'dashboard' );
	const [ editing, setEditing ] = useState( false );
	const [ draft, setDraft ] = useState< Card[] | null >( null );
	const [ picking, setPicking ] = useState( false );
	const [ naming, setNaming ] = useState< 'new' | 'rename' | null >( null );
	const [ name, setName ] = useState( '' );
	const [ deleting, setDeleting ] = useState( false );
	const [ dragging, setDragging ] = useState< number | null >( null );
	const [ saving, setSaving ] = useState( false );

	const list = dashboards.data ?? [];
	const current =
		list.find( ( one ) => one.uid === search.board ) ?? list[ 0 ] ?? null;
	const saved = current
		? cardsOf( current.layout )
		: ( catalog.data?.starter ?? [] );
	const cards = draft ?? saved;
	const period: Period = {
		range: ( current?.period ?? '30d' ) as Range,
		from: current?.period_from ?? undefined,
		to: current?.period_to ?? undefined,
		compare: !! current?.compare,
	};

	useEffect( () => {
		setDraft( null );
		setEditing( false );
	}, [ current?.uid ] );

	const refresh = () =>
		client.invalidateQueries( { queryKey: [ 'dashboards' ] } );

	/**
	 * Keeps a change. The starter dashboard becomes the person's own the
	 * first time they change it.
	 *
	 * @param changes What changed.
	 */
	const keep = async (
		changes: Partial< Dashboard > & { layout?: Card[] }
	) => {
		setSaving( true );
		try {
			if ( current ) {
				await api.patch( `/dashboards/${ current.uid }`, changes );
			} else {
				const made = await api.post< Dashboard >( '/dashboards', {
					name: __( 'My dashboard', 'cliezoo' ),
					layout: saved,
					...changes,
				} );
				navigate( {
					to: '/dashboard',
					search: { board: made.data.uid } as never,
				} );
			}
			await refresh();
			return true;
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
			return false;
		} finally {
			setSaving( false );
		}
	};

	/**
	 * Keeps a between-dates period, both ends together and the right way
	 * round.
	 *
	 * @param from First day.
	 * @param to   Last day.
	 */
	const between = ( from?: string, to?: string ) => {
		if ( ! from || ! to ) {
			return;
		}
		const [ first, last ] = from <= to ? [ from, to ] : [ to, from ];
		if ( first !== period.from || last !== period.to ) {
			keep( { period_from: first, period_to: last } );
		}
	};

	const create = async () => {
		try {
			const made = await api.post< Dashboard >( '/dashboards', {
				name: name.trim() || __( 'New dashboard', 'cliezoo' ),
				layout: catalog.data?.starter ?? [],
				position: list.length,
			} );
			await refresh();
			navigate( {
				to: '/dashboard',
				search: { board: made.data.uid } as never,
			} );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	const remove = async () => {
		if ( ! current ) {
			return;
		}
		try {
			await api.delete( `/dashboards/${ current.uid }` );
			await refresh();
			navigate( { to: '/dashboard', search: {} as never } );
			toast.success( __( 'Dashboard deleted.', 'cliezoo' ) );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	const move = ( from: number, to: number ) => {
		if ( to < 0 || to >= cards.length || from === to ) {
			return;
		}
		const next = [ ...cards ];
		const [ card ] = next.splice( from, 1 );
		next.splice( to, 0, card );
		setDraft( next );
	};

	if ( dashboards.isPending || ( ! current && catalog.isPending ) ) {
		return <Skeleton className="h-64" />;
	}
	if ( dashboards.isError ) {
		return (
			<ErrorState
				error={ dashboards.error }
				onRetry={ () => dashboards.refetch() }
			/>
		);
	}

	const byId = new Map( widgets.map( ( widget ) => [ widget.id, widget ] ) );

	return (
		<div className="space-y-4">
			{ /*
			 * A group, not a toolbar. A toolbar promises one tab stop and
			 * the arrow keys to get round it, and this is a row of labelled
			 * selects and buttons that each want a tab stop of their own —
			 * claiming the role only tells people to press keys that do
			 * nothing.
			 */ }
			<div
				role="group"
				aria-label={ __( 'Dashboard', 'cliezoo' ) }
				className="flex flex-wrap items-end gap-2"
			>
				{ list.length > 1 && (
					<Select
						label={ __( 'Dashboard', 'cliezoo' ) }
						className="w-48"
						items={ list.map( ( one ) => ( {
							id: one.uid,
							label: one.name,
						} ) ) }
						selectedKey={ current?.uid ?? null }
						onSelectionChange={ ( key: unknown ) =>
							navigate( {
								to: '/dashboard',
								search: { board: String( key ) } as never,
							} )
						}
					/>
				) }
				<Select
					label={ __( 'Period', 'cliezoo' ) }
					className="w-44"
					items={ RANGES() }
					selectedKey={ period.range }
					isDisabled={ editing || saving }
					onSelectionChange={ ( key: unknown ) =>
						keep(
							String( key ) === 'custom'
								? {
										// Between dates starts as the last
										// thirty days, shown, not implied.
										period: 'custom',
										period_from:
											period.from ?? daysFromToday( -29 ),
										period_to: period.to ?? today(),
									}
								: { period: String( key ) as Range }
						)
					}
				/>
				{ period.range === 'custom' && (
					<>
						<TextField
							key={ `${ current?.uid }-from-${ period.from }` }
							label={ __( 'From', 'cliezoo' ) }
							type="date"
							className="w-40"
							defaultValue={ period.from ?? '' }
							onBlur={ ( event: { target: HTMLInputElement } ) =>
								between( event.target.value, period.to )
							}
						/>
						<TextField
							key={ `${ current?.uid }-to-${ period.to }` }
							label={ __( 'To', 'cliezoo' ) }
							type="date"
							className="w-40"
							defaultValue={ period.to ?? '' }
							onBlur={ ( event: { target: HTMLInputElement } ) =>
								between( period.from, event.target.value )
							}
						/>
					</>
				) }
				<Switch
					isSelected={ period.compare }
					isDisabled={ editing || saving }
					onChange={ ( on: boolean ) => keep( { compare: on } ) }
				>
					{ __( 'Compare with the period before', 'cliezoo' ) }
				</Switch>
				{ /* Wraps, for the same reason the list toolbar does. */ }
				<div className="ms-auto flex flex-wrap items-center justify-end gap-2">
					{ /* Filled by an add-on (its badge until then), told
					     which dashboard. */ }
					<Slot
						name="dashboard.toolbar"
						variant="chip"
						props={ { dashboard: current?.uid ?? '' } }
					/>
					{ editing ? (
						<>
							<Button
								variant="ghost"
								onPress={ () => {
									setDraft( null );
									setEditing( false );
								} }
							>
								{ __( 'Cancel', 'cliezoo' ) }
							</Button>
							<Button
								variant="primary"
								isDisabled={ saving }
								onPress={ async () => {
									if (
										! draft ||
										( await keep( { layout: draft } ) )
									) {
										setDraft( null );
										setEditing( false );
									}
								} }
							>
								{ __( 'Save layout', 'cliezoo' ) }
							</Button>
						</>
					) : (
						<Button
							icon={
								<LayoutGrid size={ 14 } aria-hidden="true" />
							}
							onPress={ () => setEditing( true ) }
						>
							{ __( 'Customise', 'cliezoo' ) }
						</Button>
					) }
					<MenuTrigger>
						<IconButton
							label={ __( 'More for dashboards', 'cliezoo' ) }
							icon={
								<MoreHorizontal
									size={ 16 }
									aria-hidden="true"
								/>
							}
						/>
						<Menu
							onAction={ ( key: unknown ) => {
								const action = String( key );
								if ( action === 'delete' ) {
									setDeleting( true );
									return;
								}
								setName(
									action === 'rename'
										? ( current?.name ?? '' )
										: ''
								);
								setNaming(
									action === 'rename' ? 'rename' : 'new'
								);
							} }
						>
							<MenuItem id="new">
								{ __( 'New dashboard…', 'cliezoo' ) }
							</MenuItem>
							{ !! current && (
								<MenuItem id="rename">
									{ __( 'Rename…', 'cliezoo' ) }
								</MenuItem>
							) }
							{ !! current && (
								<MenuItem id="delete" danger>
									{ __( 'Delete…', 'cliezoo' ) }
								</MenuItem>
							) }
						</Menu>
					</MenuTrigger>
				</div>
			</div>

			<div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
				{ cards.map( ( card, index ) => (
					<CardFrame
						key={ `${ card.widget }-${ index }` }
						card={ card }
						index={ index }
						count={ cards.length }
						widget={ byId.get( card.widget ) }
						period={ period }
						editing={ editing }
						onChange={ ( next ) => {
							const all = [ ...cards ];
							all[ index ] = next;
							if ( editing ) {
								setDraft( all );
							} else {
								keep( { layout: all } );
							}
						} }
						onMove={ ( to ) => move( index, to ) }
						onRemove={ () =>
							setDraft( cards.filter( ( _, i ) => i !== index ) )
						}
						onDragStart={ () => setDragging( index ) }
						onDrop={ () => {
							if ( dragging !== null ) {
								move( dragging, index );
							}
							setDragging( null );
						} }
					/>
				) ) }
				{ editing && (
					<button
						type="button"
						onClick={ () => setPicking( true ) }
						className="flex min-h-32 items-center justify-center gap-2 rounded-lg border border-dashed border-line text-sm text-muted outline-none hover:border-accent hover:text-fg focus-visible:ring-2 focus-visible:ring-accent"
					>
						<Plus size={ 16 } aria-hidden="true" />
						{ __( 'Add a card', 'cliezoo' ) }
					</button>
				) }
			</div>

			{ /* With no dashboard of their own, the cards are the starter
			     set from the catalog. If that ask failed, there are no
			     cards because we do not know them — not because the
			     dashboard is empty. */ }
			{ ! cards.length && ! editing && catalog.isError && (
				<ErrorState
					error={ catalog.error }
					title={ __(
						'The cards for this dashboard could not be loaded.',
						'cliezoo'
					) }
					onRetry={ () => catalog.refetch() }
				/>
			) }
			{ ! cards.length && ! editing && ! catalog.isError && (
				<p className="text-center text-sm text-muted">
					{ __(
						'This dashboard is empty. Customise it to add cards.',
						'cliezoo'
					) }
				</p>
			) }

			{ picking && (
				<Picker
					widgets={ widgets }
					onClose={ () => setPicking( false ) }
					onPick={ ( widget ) => {
						setDraft( [
							...cards,
							{ widget: widget.id, size: widget.size ?? 'sm' },
						] );
						setPicking( false );
					} }
				/>
			) }

			{ naming && (
				<Dialog
					isOpen
					onOpenChange={ ( open ) => ! open && setNaming( null ) }
					title={
						naming === 'new'
							? __( 'New dashboard', 'cliezoo' )
							: __( 'Rename dashboard', 'cliezoo' )
					}
					footer={ ( close ) => (
						<>
							<Button variant="ghost" onPress={ close }>
								{ __( 'Cancel', 'cliezoo' ) }
							</Button>
							<Button
								variant="primary"
								isDisabled={ ! name.trim() }
								onPress={ async () => {
									if ( naming === 'new' ) {
										await create();
									} else {
										await keep( { name: name.trim() } );
									}
									close();
								} }
							>
								{ naming === 'new'
									? __( 'Create', 'cliezoo' )
									: __( 'Rename', 'cliezoo' ) }
							</Button>
						</>
					) }
				>
					<TextField
						label={ __( 'Name', 'cliezoo' ) }
						value={ name }
						onChange={ setName }
						maxLength={ 120 }
					/>
				</Dialog>
			) }

			<ConfirmDialog
				isOpen={ deleting }
				onOpenChange={ setDeleting }
				title={ __( 'Delete this dashboard?', 'cliezoo' ) }
				description={ sprintf(
					/* translators: %s: dashboard name. */
					__(
						'“%s” and its layout go. The numbers on it stay where they are.',
						'cliezoo'
					),
					current?.name ?? ''
				) }
				confirmLabel={ __( 'Delete', 'cliezoo' ) }
				onConfirm={ remove }
			/>
		</div>
	);
}
