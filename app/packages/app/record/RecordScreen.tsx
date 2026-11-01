/**
 * The page one record gets: who it is, what you can do with it, its tabs
 * and the panels beside it.
 *
 * Every module's detail page is this same scaffold, so a client, a project
 * and an invoice behave identically — and so a module can add a tab or a
 * panel to a page it does not own (Blueprint §11.4).
 */
import { Suspense, useEffect, useId, type ReactNode } from 'react';
import { useNavigate, useParams } from '@tanstack/react-router';
import { __ } from '@wordpress/i18n';
import {
	Avatar,
	Button,
	ErrorState,
	Skeleton,
	Tab,
	TabList,
	TabPanel,
	Tabs,
	cx,
} from '@cliezoo/ui';
import { getBoot } from '@cliezoo/data';
import { PageHeader } from '../../../shell/Page';
import { applies, registry, type RecordTab } from '../registry';
import { Slot, useRegistry } from '../slot';

export interface RecordScreenProps {
	/** Entity name, e.g. "clients". */
	type: string;
	record: Record< string, unknown > | undefined;
	/** While the record is on its way. */
	isPending?: boolean;
	error?: unknown;
	onRetry?: () => void;
	title: string;
	/** Avatar text and colour, when the record has a face. */
	avatar?: { name: string; src?: string; color?: string };
	/** Short facts under the title: code, email, local time. */
	meta?: ReactNode;
	/** Buttons in the header. */
	actions?: ReactNode;
	/** Panels down the right-hand side. */
	rail?: ReactNode;
	/** The first tab, which the owning module always provides. */
	overview: ReactNode;
	overviewLabel?: string;
	/**
	 * A slot whose fills become tabs after the registered ones, such as
	 * "project.tabs": an add-on fills it with registerSlotFill() and a
	 * `label`, and the tab appears (Blueprint §11.4).
	 */
	tabSlot?: string;
}

/**
 * The fills of a tab slot, as record tabs: in the slot's order, without
 * those of a module that is off, each named by its label.
 *
 * @param slot    Slot name.
 * @param type    Record type the page is for.
 * @param modules Active modules.
 */
export function slotTabs(
	slot: string | undefined,
	type: string,
	modules: string[]
): RecordTab[] {
	if ( ! slot ) {
		return [];
	}
	return ( registry.slots.get( slot ) ?? [] )
		.filter( ( fill ) => ! fill.module || modules.includes( fill.module ) )
		.map( ( fill, index ) => ( {
			// Named like a registered tab, so its address is
			// /projects/{uid}/{fill id} rather than something with the
			// slot's name in it.
			id: `${ type }.${ fill.id }`,
			label: fill.label ?? fill.id,
			type,
			order: fill.order ?? index,
			component: fill.component as RecordTab[ 'component' ],
		} ) );
}

/**
 * Renders a record page.
 *
 * @param root0
 * @param root0.type
 * @param root0.record
 * @param root0.isPending
 * @param root0.error
 * @param root0.onRetry
 * @param root0.title
 * @param root0.avatar
 * @param root0.meta
 * @param root0.actions
 * @param root0.rail
 * @param root0.overview
 * @param root0.overviewLabel
 * @param root0.tabSlot
 */
export function RecordScreen( {
	type,
	record,
	isPending,
	error,
	onRetry,
	title,
	avatar,
	meta,
	actions,
	rail,
	overview,
	overviewLabel,
	tabSlot,
}: RecordScreenProps ) {
	useRegistry();
	const boot = getBoot();
	const registered = registry.recordTabs.filter(
		( item ) => item.type === type && applies( boot, item )
	);
	// A registered tab wins over a fill of the same id, so an add-on
	// cannot quietly replace a tab the page already has.
	const tabs = [
		...registered,
		...slotTabs( tabSlot, type, boot.modules ?? [] ).filter(
			( fill ) => ! registered.some( ( item ) => item.id === fill.id )
		),
	];
	// The tab is part of the address (/projects/{uid}/tasks), so it can be
	// linked to and survives a reload; a folder inside Files is too
	// (/projects/{uid}/files/{folder}), which means the Files tab.
	const params = useParams( { strict: false } ) as {
		tab?: string;
		folder?: string;
	};
	const navigate = useNavigate();
	// A tab's place in the address is its id without the record type in
	// front: projects.files is /projects/{uid}/files.
	const segment = ( id: string ) =>
		id.startsWith( `${ type }.` ) ? id.slice( type.length + 1 ) : id;
	const wanted = params.tab;
	const tab =
		tabs.find( ( item ) => segment( item.id ) === wanted )?.id ??
		'overview';
	const setTab = ( next: string ) => {
		const uid = String( record?.uid ?? '' );
		if ( ! uid || next === tab ) {
			return;
		}
		navigate( {
			to: ( 'overview' === next
				? `/${ type }/${ uid }`
				: `/${ type }/${ uid }/${ segment( next ) }` ) as never,
			search: true as never,
			replace: true,
			resetScroll: false,
		} );
	};
	// An address naming a tab this record does not have is put right, so
	// the tabs and the address agree.
	const stray = !! wanted && 'overview' === tab && !! record?.uid;
	useEffect( () => {
		if ( stray ) {
			navigate( {
				to: `/${ type }/${ String( record?.uid ) }` as never,
				replace: true,
			} );
		}
	}, [ stray, type, record?.uid, navigate ] );

	if ( error ) {
		return (
			<div className="mx-auto max-w-2xl p-6">
				<ErrorState error={ error } onRetry={ onRetry } />
			</div>
		);
	}
	if ( isPending || ! record ) {
		return (
			<div className="space-y-3 p-6" aria-busy="true">
				<Skeleton className="h-8 w-64" />
				<Skeleton className="h-4 w-96" />
				<Skeleton className="h-64" />
			</div>
		);
	}

	return (
		<Tabs
			selectedKey={ tab }
			onSelectionChange={ ( key ) => setTab( String( key ) ) }
		>
			<PageHeader
				title={
					avatar ? (
						<span className="flex items-center gap-2.5">
							<Avatar
								name={ avatar.name }
								src={ avatar.src }
								color={ avatar.color }
								size="lg"
							/>
							<span className="min-w-0 truncate">{ title }</span>
						</span>
					) : (
						title
					)
				}
				meta={ meta }
				actions={ actions }
			>
				<TabList
					aria-label={ __( 'Sections', 'cliezoo' ) }
					className="-mb-px flex gap-1 overflow-x-auto"
				>
					{ [
						{
							id: 'overview',
							label: overviewLabel ?? __( 'Overview', 'cliezoo' ),
							count: undefined,
						},
						...tabs.map( ( item ) => ( {
							id: item.id,
							label: item.label,
							count: item.count?.( record ),
						} ) ),
					].map( ( item ) => (
						<Tab
							key={ item.id }
							id={ item.id }
							className={ cx(
								'flex cursor-default items-center gap-1.5 whitespace-nowrap border-b-2 border-transparent px-2.5 pb-2.5 pt-1 text-sm font-medium text-muted outline-none',
								'hover:text-fg data-[focus-visible]:outline-2 data-[focus-visible]:outline-focus data-[selected]:border-accent data-[selected]:text-fg'
							) }
						>
							{ item.label }
							{ undefined !== item.count && item.count > 0 && (
								<span className="rounded-full bg-sunken px-1.5 text-xs text-muted">
									{ item.count }
								</span>
							) }
						</Tab>
					) ) }
				</TabList>
			</PageHeader>

			<div className="mx-auto grid w-full max-w-7xl gap-6 px-4 py-5 sm:px-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
				<div className="min-w-0">
					<TabPanel id="overview">{ overview }</TabPanel>
					{ tabs.map( ( item ) => {
						const Body = item.component;
						return (
							<TabPanel key={ item.id } id={ item.id }>
								<Suspense
									fallback={ <Skeleton className="h-64" /> }
								>
									<Body record={ record } type={ type } />
								</Suspense>
							</TabPanel>
						);
					} ) }
				</div>
				<aside className="space-y-4">
					{ rail }
					<Slot
						name={ `record.rail.${ type }` }
						props={ { record } }
					/>
				</aside>
			</div>
		</Tabs>
	);
}

/**
 * A titled panel for the right-hand rail, with a count and an optional
 * "add" button — the shape every rail panel uses.
 *
 * @param root0
 * @param root0.title
 * @param root0.count
 * @param root0.action
 * @param root0.children
 */
export function RailPanel( {
	title,
	count,
	action,
	children,
}: {
	title: string;
	count?: number;
	action?: ReactNode;
	children: ReactNode;
} ) {
	// Named, so the rail is a list of landmarks somebody can jump
	// between rather than one undifferentiated run of text.
	const heading = useId();

	return (
		<section
			aria-labelledby={ heading }
			className="rounded-lg border border-line bg-raised"
		>
			<header className="flex items-center gap-2 border-b border-line px-4 py-2.5">
				<h2 id={ heading } className="text-sm font-semibold text-fg">
					{ title }
				</h2>
				{ undefined !== count && (
					<span className="rounded-full bg-sunken px-1.5 text-xs text-muted">
						{ count }
					</span>
				) }
				<div className="ms-auto">{ action }</div>
			</header>
			<div className="px-4 py-3 text-sm">{ children }</div>
		</section>
	);
}

/**
 * The empty line a rail panel shows when there is nothing in it yet.
 *
 * @param root0
 * @param root0.children
 * @param root0.onAdd
 * @param root0.addLabel
 */
export function RailEmpty( {
	children,
	onAdd,
	addLabel,
}: {
	children: ReactNode;
	onAdd?: () => void;
	addLabel?: string;
} ) {
	return (
		<p className="text-sm text-muted">
			{ children }
			{ onAdd && (
				<>
					{ ' ' }
					<Button variant="link" onPress={ onAdd }>
						{ addLabel ?? __( 'Add one', 'cliezoo' ) }
					</Button>
				</>
			) }
		</p>
	);
}
