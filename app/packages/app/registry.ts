/**
 * The extension registry (Blueprint §11.4): routes, navigation, slot fills,
 * field types, AI actions, widgets and commands. Free modules register here,
 * and so do add-ons through window.cliezoo.app.
 * Registrations must happen before DOMContentLoaded (deferred scripts run
 * in order before it), when the router is built.
 */
import type { ComponentType, ReactNode } from 'react';
import type { Boot } from '@cliezoo/data';

export type NavGroup =
	| 'personal'
	| 'overview'
	| 'sales'
	| 'work'
	| 'documents'
	| 'finance'
	| 'insights'
	| 'settings';
export type Audience = 'staff' | 'client' | 'all';

export interface NavItem {
	id: string;
	label: string;
	/** Path inside the app, e.g. "/projects". */
	to: string;
	icon?: ComponentType< {
		size?: number | string;
		className?: string;
		'aria-hidden'?: boolean | 'true' | 'false';
	} >;
	group: NavGroup;
	order?: number;
	audience?: Audience;
	/** Hidden unless this module is active. */
	module?: string;
	/** Extra visibility rule (UI only; the server enforces access). */
	visible?: ( boot: Boot ) => boolean;
	/** Pinned to the mobile bottom bar (max five). */
	mobile?: boolean;
}

export type LazyComponent = () => Promise< { default: ComponentType< any > } >;

/** Where an address that is only another name for a screen sends you. */
export interface RouteRedirect {
	to: string;
	search?: Record< string, unknown >;
}

export interface RouteDef {
	/** Path inside the app (TanStack syntax): "/projects/$uid". */
	path: string;
	/** What it shows. An address that only redirects shows nothing. */
	component?: LazyComponent;
	/**
	 * Makes this address another name for a screen: the address the spec
	 * gives, kept working, taking you to where the screen lives. It is
	 * asked with the address's params and who is signed in, so one name
	 * can lead the team and clients to their own screens. Null shows the
	 * screen after all.
	 */
	redirectTo?: (
		params: Record< string, string >,
		boot: Boot,
		search: Record< string, unknown >
	) => RouteRedirect | null;
	title?: string;
	audience?: Audience | 'guest';
	module?: string;
	visible?: ( boot: Boot ) => boolean;
	/** "bare" renders without the sidebar/top bar (login, onboarding). */
	layout?: 'shell' | 'bare';
}

export interface SlotFill< P = Record< string, unknown > > {
	id: string;
	component: ComponentType< P >;
	order?: number;
	/** The module it belongs to; not shown while that module is off. */
	module?: string;
	/**
	 * What the fill is called where a slot shows fills by name, such as a
	 * tab in "project.tabs". Slots that just render fills ignore it.
	 */
	label?: string;
}

export interface Command {
	id: string;
	label: string;
	group?: string;
	keywords?: string[];
	shortcut?: string;
	icon?: ReactNode;
	audience?: Audience;
	visible?: ( boot: Boot ) => boolean;
	/**
	 * Offered for whatever was typed in the palette ("Ask AI: …"), rather
	 * than matched against it; `run` is given the words.
	 */
	ask?: boolean;
	run: ( ctx: { navigate: ( to: string ) => void; query?: string } ) => void;
}

export interface FieldTypeDef {
	type: string;
	label: string;
	display: ComponentType< {
		value: unknown;
		field: Record< string, unknown >;
	} >;
	edit?: ComponentType< {
		value: unknown;
		onChange: ( value: unknown ) => void;
		field: Record< string, unknown >;
	} >;
}

export type SettingsGroup =
	'workspace' | 'people' | 'work' | 'money' | 'connect' | 'advanced';

export interface SettingsSection {
	id: string;
	label: string;
	description?: string;
	group: SettingsGroup;
	icon?: NavItem[ 'icon' ];
	order?: number;
	component: LazyComponent;
	/** Hidden unless this module is active. */
	module?: string;
	visible?: ( boot: Boot ) => boolean;
}

export interface RecordTab {
	id: string;
	label: string;
	/** The record type this tab belongs to, e.g. "clients". */
	type: string;
	order?: number;
	module?: string;
	audience?: Audience;
	/** Whether this person is offered it (a permission, say). */
	visible?: ( boot: Boot ) => boolean;
	/**
	 * Loaded when the tab is opened (use React.lazy). It is told which
	 * record type it is on, so one component can serve a tab registered on
	 * several of them rather than being copied per type.
	 */
	component: ComponentType< {
		record: Record< string, unknown >;
		type: string;
	} >;
	/** Shown beside the label when there is something to count. */
	count?: ( record: Record< string, unknown > ) => number | undefined;
}

export interface WidgetDef {
	id: string;
	title: string;
	component: LazyComponent;
	size?: 'sm' | 'md' | 'lg';
	audience?: Audience;
	/** Where it lives: Home (the default), or the dashboard's picker. */
	place?: 'home' | 'dashboard';
}

export interface AiActionDef {
	id: string;
	label: string;
	context: string;
	run: ( input: Record< string, unknown > ) => Promise< unknown > | void;
}

/**
 * A section of the client portal whose access is set per client (`view`
 * or `edit`). Mirrors the PHP PortalSectionRegistry: the server decides
 * which sections exist and enforces them; this names and explains them.
 */
export interface PortalSectionDef {
	id: string;
	label: string;
	/** One line on what a client finds there. */
	help?: string;
	order?: number;
}

type Listener = () => void;

class Registry {
	recordLinks = new Map< string, ( uid: string ) => string >();
	routes: RouteDef[] = [];
	nav: NavItem[] = [];
	slots = new Map< string, SlotFill< any >[] >();
	commands: Command[] = [];
	fieldTypes = new Map< string, FieldTypeDef >();
	widgets: WidgetDef[] = [];
	recordTabs: RecordTab[] = [];
	settings: SettingsSection[] = [];
	aiActions: AiActionDef[] = [];
	portalSections: PortalSectionDef[] = [];
	viewSources: ViewSource[] = [];
	private listeners = new Set< Listener >();
	private version = 0;

	subscribe = ( listener: Listener ) => {
		this.listeners.add( listener );
		return () => {
			this.listeners.delete( listener );
		};
	};

	getVersion = () => this.version;

	changed() {
		this.version++;
		this.listeners.forEach( ( listener ) => listener() );
	}
}

/**
 * Where a list's views come from besides the person's own: an add-on's
 * (views shared with the team, a role's default). Each answers for one
 * kind of record; what it gives is read, never changed, here.
 */
export type ViewSource = ( entity: string ) => Promise<
	Array< {
		uid: string;
		name: string;
		config: Record< string, unknown > | null;
		/** Who shared it, said in words. */
		from?: string;
		/** The list opens on it when the person has no default of their own. */
		role_default?: boolean;
	} >
>;

export const registry = new Registry();

/**
 * Adds a source of views for lists.
 *
 * @param source Gives views for a kind of record.
 */
export function registerViewSource( source: ViewSource ): void {
	registry.viewSources.push( source );
	registry.changed();
}

const upsert = < T extends { id?: string; path?: string } >(
	list: T[],
	items: T[],
	key: 'id' | 'path'
) => {
	for ( const item of items ) {
		const index = list.findIndex(
			( existing ) => existing[ key ] === item[ key ]
		);
		if ( index >= 0 ) {
			list[ index ] = item;
		} else {
			list.push( item );
		}
	}
};

/**
 * Registers routes (a later registration with the same path replaces the earlier one).
 *
 * @param routes Routes.
 */
export function registerRoutes( routes: RouteDef[] ): void {
	upsert( registry.routes, routes, 'path' );
	registry.changed();
}

/**
 * Registers sidebar items.
 *
 * @param items Items.
 */
export function registerNavItems( items: NavItem[] ): void {
	upsert( registry.nav, items, 'id' );
	registry.changed();
}

/**
 * Registers tabs on a record's page.
 *
 * A module owns its own tab: Files adds "Files" to clients and projects,
 * Invoices adds "Finance". The record page asks the registry what to show,
 * so no page has to know which modules exist.
 *
 * @param tabs Tabs.
 */
export function registerRecordTabs( tabs: RecordTab[] ): void {
	upsert( registry.recordTabs, tabs, 'id' );
	registry.recordTabs.sort(
		( a, b ) => ( a.order ?? 10 ) - ( b.order ?? 10 )
	);
	registry.changed();
}

/**
 * Streams an AI run's answer as it is written (an add-on's; Cliezoo itself
 * answers in one piece). Resolves to what `/ai/run` answers, or to null
 * when streaming cannot be had here, and then the run is asked for in one
 * piece instead.
 */
export type AiStreamer = (
	payload: Record< string, unknown >,
	options: { signal?: AbortSignal; onDelta: ( text: string ) => void }
) => Promise< unknown | null >;

let aiStreamer: AiStreamer | null = null;

/**
 * Sets the streamer AI drafts use; null for none.
 *
 * @param streamer The streamer.
 */
export function registerAiStreamer( streamer: AiStreamer | null ): void {
	aiStreamer = streamer;
}

/**
 * The streamer AI drafts use, if an add-on gave one.
 */
export function getAiStreamer(): AiStreamer | null {
	return aiStreamer;
}

/**
 * Fills a named slot, e.g. "project.tabs", "invoice.sidebar", "settings.sections".
 *
 * @param slot Slot name.
 * @param fill Fill.
 */
export function registerSlotFill< P = Record< string, unknown > >(
	slot: string,
	fill: SlotFill< P >
): void {
	const fills = registry.slots.get( slot ) ?? [];
	upsert( fills, [ fill ], 'id' );
	registry.slots.set(
		slot,
		fills.sort( ( a, b ) => ( a.order ?? 10 ) - ( b.order ?? 10 ) )
	);
	registry.changed();
}

/**
 * How to open a record of a type (used by favourites, search, notifications).
 *
 * @param type Entity type, e.g. "projects".
 * @param link Builds the in-app path from a uid.
 */
export function registerRecordLink(
	type: string,
	link: ( uid: string ) => string
): void {
	registry.recordLinks.set( type, link );
	registry.changed();
}

/**
 * In-app path of a record ("" when no module registered the type).
 *
 * @param type Entity type.
 * @param uid  Record uid.
 */
export function recordPath( type: string, uid: string ): string {
	return registry.recordLinks.get( type )?.( uid ) ?? '';
}

export function registerCommand( command: Command | Command[] ): void {
	upsert(
		registry.commands,
		Array.isArray( command ) ? command : [ command ],
		'id'
	);
	registry.changed();
}

export function registerFieldType( def: FieldTypeDef ): void {
	registry.fieldTypes.set( def.type, def );
	registry.changed();
}

/**
 * Adds a Settings section (Settings → {label}); a later registration with the same id replaces it.
 *
 * @param section Section.
 */
export function registerSettingsSection(
	section: SettingsSection | SettingsSection[]
): void {
	upsert(
		registry.settings,
		Array.isArray( section ) ? section : [ section ],
		'id'
	);
	registry.changed();
}

export function registerWidget( widget: WidgetDef ): void {
	upsert( registry.widgets, [ widget ], 'id' );
	registry.changed();
}

export function registerAiAction( action: AiActionDef ): void {
	upsert( registry.aiActions, [ action ], 'id' );
	registry.changed();
}

/**
 * The AI actions registered for one place ("composer", "doc", "projects"),
 * in the order they were registered. An action registered for "*" is
 * offered everywhere AI helpers are.
 *
 * @param context Where the helpers are being offered.
 */
export function aiActionsFor( context: string ): AiActionDef[] {
	return registry.aiActions.filter(
		( action ) => action.context === context || '*' === action.context
	);
}

/**
 * Whether an item applies to the current user.
 *
 * @param boot          Boot data.
 * @param item          Item with audience/module/visible.
 * @param item.audience
 * @param item.module
 * @param item.visible
 */
export function applies(
	boot: Boot,
	item: {
		audience?: Audience | 'guest';
		module?: string;
		visible?: ( boot: Boot ) => boolean;
	}
): boolean {
	const user = boot.user;
	const audience = item.audience ?? 'staff';
	if ( audience === 'guest' ) {
		return true;
	}
	if ( ! user ) {
		return false;
	}
	if ( audience === 'staff' && user.client ) {
		return false;
	}
	if ( audience === 'client' && ! user.client ) {
		return false;
	}
	if ( item.module && ! boot.modules.includes( item.module ) ) {
		return false;
	}
	return item.visible ? item.visible( boot ) : true;
}

/**
 * Registers portal sections (a later registration with the same id replaces it).
 *
 * @param section Section or sections.
 */
export function registerPortalSection(
	section: PortalSectionDef | PortalSectionDef[]
): void {
	upsert(
		registry.portalSections,
		Array.isArray( section ) ? section : [ section ],
		'id'
	);
	registry.changed();
}

/**
 * A registered portal section, or null.
 *
 * @param id Section id.
 */
export function portalSection( id: string ): PortalSectionDef | null {
	return registry.portalSections.find( ( one ) => one.id === id ) ?? null;
}
