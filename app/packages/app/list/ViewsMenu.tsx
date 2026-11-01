/**
 * Saved views of a list (cliezoo.md §6.2): a named filter, columns, sort
 * and grouping, personal to whoever saved it. One can be a person's
 * default — the list opens on it — any can be starred onto the sidebar,
 * and any can be deleted.
 *
 * Views are personal; nothing here shares anything.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Bookmark, Check, Star, Trash2 } from 'lucide-react';
import { api, useQuery, useQueryClient } from '@cliezoo/data';
import {
	Button,
	Checkbox,
	Dialog,
	IconButton,
	Menu,
	MenuItem,
	MenuSection,
	MenuTrigger,
	TextField,
	toast,
} from '@cliezoo/ui';
import type { ListConfig } from './meta';
import { registry } from '../registry';

export interface SavedView {
	uid: string;
	name: string;
	config: Partial< ListConfig > | null;
	is_default?: boolean;
	/**
	 * A view from somewhere else (an add-on's: shared by the team), read
	 * but not changed here; said as who it came from.
	 */
	from?: string;
	/** Opens the list when the person has no default of their own. */
	role_default?: boolean;
}

/** What a view keeps of a list's layout. */
export type ViewConfig = Pick<
	ListConfig,
	'columns' | 'sort' | 'filter' | 'groupBy' | 'layout'
>;

/**
 * The person's views of one list.
 *
 * @param entity  Entity name.
 * @param enabled Whether to ask yet.
 */
export function useSavedViews( entity: string, enabled = true ) {
	return useQuery( {
		queryKey: [ 'saved_views', 'list', { entity } ],
		enabled,
		queryFn: async () => {
			const [ own, ...others ] = await Promise.all( [
				api
					.get< SavedView[] >( '/saved_views', {
						filter: JSON.stringify( {
							field: 'entity',
							op: 'eq',
							value: entity,
						} ),
						limit: 100,
					} )
					.then( ( answer ) => answer.data ),
				// An add-on's views (shared by the team). One that cannot
				// answer leaves the person's own views as they are.
				...registry.viewSources.map( ( source ) =>
					source( entity ).catch( () => [] )
				),
			] );
			const foreign = others.flat().map( ( view ) => ( {
				...view,
				from: view.from || __( 'The team', 'cliezoo' ),
				is_default: false,
			} ) ) as SavedView[];
			return [ ...own, ...foreign ];
		},
	} );
}

/**
 * What of a saved view's layout a list can use. A view is stored as the
 * browser sent it, and an older or hand-made one may carry a sort or
 * columns in another shape; those parts are left out rather than broken.
 *
 * @param config The stored layout.
 */
export function viewLayout(
	config: Partial< ListConfig > | null | undefined
): Partial< ViewConfig > {
	const out: Partial< ViewConfig > = {};
	if ( ! config || 'object' !== typeof config ) {
		return out;
	}
	if (
		Array.isArray( config.columns ) &&
		config.columns.every( ( key ) => 'string' === typeof key )
	) {
		out.columns = config.columns;
	}
	if ( 'string' === typeof config.sort ) {
		out.sort = config.sort;
	}
	if ( 'string' === typeof config.groupBy ) {
		out.groupBy = config.groupBy;
	}
	if ( config.filter && 'object' === typeof config.filter ) {
		out.filter = config.filter;
	}
	if ( 'string' === typeof config.layout ) {
		out.layout = config.layout;
	}
	return out;
}

/**
 * Which view a list opens on: the one named in the address (a starred
 * view followed from the sidebar), else the person's default, else none.
 *
 * @param views  The person's views.
 * @param linked The view uid in the address, if any.
 */
export function startingView(
	views: SavedView[] | undefined,
	linked?: unknown
): SavedView | undefined {
	const all = views ?? [];
	if ( 'string' === typeof linked && linked ) {
		const named = all.find( ( view ) => view.uid === linked );
		if ( named ) {
			return named;
		}
	}
	// Their own default first; else the one their role opens on.
	return (
		all.find( ( view ) => ! view.from && !! view.is_default ) ??
		all.find( ( view ) => !! view.from && !! view.role_default )
	);
}

export function ViewsMenu( {
	entity,
	views: every,
	active,
	current,
	onApply,
	onReset,
}: {
	entity: string;
	views: SavedView[];
	/** The view the list is showing, if it is one. */
	active?: string;
	/** The list's layout now, for saving. */
	current: ViewConfig;
	onApply: ( view: SavedView ) => void;
	onReset: () => void;
} ) {
	const queries = useQueryClient();
	// The person's own views are theirs to change; the team's are read.
	const views = every.filter( ( view ) => ! view.from );
	const shared = every.filter( ( view ) => !! view.from );
	const [ saving, setSaving ] = useState( false );
	const [ managing, setManaging ] = useState( false );
	const [ name, setName ] = useState( '' );
	const [ asDefault, setAsDefault ] = useState( false );
	const refresh = () =>
		queries.invalidateQueries( { queryKey: [ 'saved_views' ] } );

	const save = async () => {
		try {
			await api.post( '/saved_views', {
				entity,
				name: name.trim(),
				is_default: asDefault,
				config: current,
			} );
			setSaving( false );
			setName( '' );
			setAsDefault( false );
			refresh();
			toast.success( __( 'View saved.', 'cliezoo' ) );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		}
	};

	return (
		<>
			<MenuTrigger>
				<Button
					size="sm"
					icon={ <Bookmark size={ 14 } aria-hidden="true" /> }
				>
					{ views.find( ( view ) => view.uid === active )?.name ??
						__( 'Views', 'cliezoo' ) }
				</Button>
				<Menu
					onAction={ ( key ) => {
						if ( key === '__save' ) {
							setSaving( true );
						} else if ( key === '__manage' ) {
							setManaging( true );
						} else if ( key === '__reset' ) {
							onReset();
						} else {
							const view = every.find( ( v ) => v.uid === key );
							if ( view ) {
								onApply( view );
							}
						}
					} }
				>
					{ views.length > 0 && (
						<MenuSection
							id="mine"
							title={ __( 'My views', 'cliezoo' ) }
						>
							{ views.map( ( view ) => (
								<MenuItem
									key={ view.uid }
									id={ view.uid }
									textValue={ view.name }
									icon={
										view.uid === active ? (
											<Check size={ 16 } />
										) : undefined
									}
									description={
										view.is_default
											? __( 'Your default', 'cliezoo' )
											: undefined
									}
								>
									{ view.name }
								</MenuItem>
							) ) }
						</MenuSection>
					) }
					{ shared.length > 0 && (
						<MenuSection
							id="shared"
							title={ __( 'Shared with the team', 'cliezoo' ) }
						>
							{ shared.map( ( view ) => (
								<MenuItem
									key={ view.uid }
									id={ view.uid }
									textValue={ view.name }
									icon={
										view.uid === active ? (
											<Check size={ 16 } />
										) : undefined
									}
									description={
										view.role_default
											? __(
													'The default for your role',
													'cliezoo'
												)
											: view.from
									}
								>
									{ view.name }
								</MenuItem>
							) ) }
						</MenuSection>
					) }
					<MenuSection id="actions">
						<MenuItem id="__save">
							{ __( 'Save current view…', 'cliezoo' ) }
						</MenuItem>
						{ views.length > 0 && (
							<MenuItem id="__manage">
								{ __( 'Manage views…', 'cliezoo' ) }
							</MenuItem>
						) }
						<MenuItem id="__reset">
							{ __( 'Reset layout', 'cliezoo' ) }
						</MenuItem>
					</MenuSection>
				</Menu>
			</MenuTrigger>

			<Dialog
				isOpen={ saving }
				onOpenChange={ setSaving }
				title={ __( 'Save view', 'cliezoo' ) }
				description={ __(
					'Saves the columns, sort, filters and grouping for you.',
					'cliezoo'
				) }
				footer={ () => (
					<Button
						variant="primary"
						onPress={ save }
						isDisabled={ ! name.trim() }
					>
						{ __( 'Save', 'cliezoo' ) }
					</Button>
				) }
			>
				<div className="space-y-3">
					<TextField
						label={ __( 'Name', 'cliezoo' ) }
						value={ name }
						onChange={ setName }
						isRequired
					/>
					<Checkbox
						isSelected={ asDefault }
						onChange={ setAsDefault }
					>
						{ __( 'Open this list on it', 'cliezoo' ) }
					</Checkbox>
				</div>
			</Dialog>

			{ managing && (
				<ManageViews
					views={ views }
					onClose={ () => setManaging( false ) }
					onChanged={ refresh }
				/>
			) }
		</>
	);
}

interface Favourite {
	type: string;
	uid: string;
}

/**
 * Starring, making default and deleting views.
 *
 * @param root0           Props.
 * @param root0.views     The person's views of this list.
 * @param root0.onClose   Closes it.
 * @param root0.onChanged Something changed; the views are fetched again.
 */
export function ManageViews( {
	views,
	onClose,
	onChanged,
}: {
	views: SavedView[];
	onClose: () => void;
	onChanged: () => void;
} ) {
	const queries = useQueryClient();
	const [ confirming, setConfirming ] = useState< string | null >( null );
	const favourites = useQuery( {
		queryKey: [ 'favourites', 'list', {} ],
		staleTime: 30_000,
		queryFn: async () =>
			( await api.get< Favourite[] >( '/favourites' ) ).data,
	} );
	const starred = ( uid: string ) =>
		( favourites.data ?? [] ).some(
			( one ) => 'saved_views' === one.type && one.uid === uid
		);

	const run = async ( work: () => Promise< unknown >, done: string ) => {
		try {
			await work();
			onChanged();
			toast.success( done );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		}
	};

	return (
		<Dialog
			isOpen
			onOpenChange={ ( open ) => ! open && onClose() }
			title={ __( 'Manage views', 'cliezoo' ) }
			description={ __(
				'Star a view to keep it on the sidebar, choose the one this list opens on, or delete the ones you no longer need.',
				'cliezoo'
			) }
			footer={ ( close ) => (
				<Button onPress={ close }>{ __( 'Done', 'cliezoo' ) }</Button>
			) }
		>
			<ul className="divide-y divide-line rounded-md border border-line">
				{ views.map( ( view ) => {
					const star = starred( view.uid );
					return (
						<li
							key={ view.uid }
							className="flex flex-wrap items-center gap-2 px-3 py-2"
						>
							<span className="min-w-0 flex-1 truncate text-sm font-medium">
								{ view.name }
							</span>
							{ confirming === view.uid ? (
								<>
									<span className="text-sm text-muted">
										{ __( 'Delete this view?', 'cliezoo' ) }
									</span>
									<Button
										size="sm"
										variant="ghost"
										onPress={ () => setConfirming( null ) }
									>
										{ __( 'Keep it', 'cliezoo' ) }
									</Button>
									<Button
										size="sm"
										variant="danger"
										onPress={ () => {
											setConfirming( null );
											run(
												() =>
													api.delete(
														`/saved_views/${ view.uid }`
													),
												__( 'View deleted.', 'cliezoo' )
											).then( () =>
												queries.invalidateQueries( {
													queryKey: [ 'favourites' ],
												} )
											);
										} }
									>
										{ __( 'Delete', 'cliezoo' ) }
									</Button>
								</>
							) : (
								<>
									<IconButton
										size="sm"
										tooltip={ false }
										aria-pressed={ star }
										label={ sprintf(
											/* translators: %s: view name. */
											__(
												'Keep %s on the sidebar',
												'cliezoo'
											),
											view.name
										) }
										icon={
											<Star
												size={ 15 }
												aria-hidden="true"
												className={
													star
														? 'fill-current text-warning'
														: undefined
												}
											/>
										}
										onPress={ () =>
											run(
												async () => {
													await api.post(
														'/favourites',
														{
															object_type:
																'saved_views',
															object: view.uid,
														}
													);
													await queries.invalidateQueries(
														{
															queryKey: [
																'favourites',
															],
														}
													);
												},
												star
													? __(
															'Taken off the sidebar.',
															'cliezoo'
														)
													: __(
															'On the sidebar.',
															'cliezoo'
														)
											)
										}
									/>
									<Button
										size="sm"
										variant={
											view.is_default
												? 'primary'
												: 'secondary'
										}
										aria-pressed={ !! view.is_default }
										onPress={ () =>
											run(
												() =>
													api.patch(
														`/saved_views/${ view.uid }`,
														{
															is_default:
																! view.is_default,
														}
													),
												view.is_default
													? __(
															'The list opens as usual again.',
															'cliezoo'
														)
													: __(
															'The list opens on this view.',
															'cliezoo'
														)
											)
										}
									>
										{ __( 'Default', 'cliezoo' ) }
										<span className="sr-only">
											{ ' ' }
											{ view.name }
										</span>
									</Button>
									<IconButton
										size="sm"
										tooltip={ false }
										variant="ghost"
										label={ sprintf(
											/* translators: %s: view name. */
											__( 'Delete %s', 'cliezoo' ),
											view.name
										) }
										icon={
											<Trash2
												size={ 15 }
												aria-hidden="true"
											/>
										}
										onPress={ () =>
											setConfirming( view.uid )
										}
									/>
								</>
							) }
						</li>
					);
				} ) }
			</ul>
		</Dialog>
	);
}
