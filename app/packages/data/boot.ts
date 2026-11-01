/**
 * Boot data printed by the shell (window.cliezooBoot) and the session store.
 */
import { create } from 'zustand';

export type Scope = 'none' | 'own' | 'team' | 'all';
export type ThemePreference = 'light' | 'dark' | 'system';

export interface Permissions {
	scopes: Record< string, Scope >;
	toggles: Record< string, boolean >;
	write: boolean;
}

export interface SessionUser {
	uid: string;
	name: string;
	email: string;
	kind: 'staff' | 'client';
	role: string;
	roleLabel: string;
	avatar: string;
	color: string;
	timezone: string;
	locale: string;
	dateFormat?: string;
	timeFormat?: string;
	/** 0 (Sunday) to 6, or null for the workspace's. */
	weekStart?: number | null;
	owner: boolean;
	admin: boolean;
	client: boolean;
	permissions: Permissions;
}

export interface Preferences {
	theme: ThemePreference;
	density: 'comfortable' | 'compact';
	sidebar_collapsed: boolean;
	quick_access_open: boolean;
	nav_hidden: string[];
	nav_order: string[];
	reduce_motion: boolean;
	/** Whether the person has been shown around once. */
	tour_done: boolean;
	/** Last search terms, newest first. */
	recent_searches: string[];
	/** Last records opened, newest first, as `type:uid`. */
	recent_records: string[];
}

export interface Workspace {
	name: string;
	country: string;
	language: string;
	timezone: string;
	currency: string;
	currency_symbol_position:
		'before' | 'after' | 'before_space' | 'after_space';
	decimal_separator: '.' | ',';
	thousands_separator: string;
	digit_grouping: 'standard' | 'indian';
	date_format: string;
	time_format: '12' | '24';
	week_start: number;
	fiscal_year_start: number;
	working_days?: number[];
	/** The languages installed on the site ("en_US", "de_DE"). */
	languages?: string[];
}

export interface Branding {
	accent: string;
	/** Accent for dark mode; falls back to `accent`. */
	accent_dark?: string;
	logo: string;
	/** Logo for dark mode; falls back to `logo`. */
	logo_dark?: string;
	favicon?: string;
	logoAlt: string;
	tagline?: string;
	font: 'inter' | 'system' | string;
	radius: 'none' | 'sm' | 'md' | 'lg' | string;
	density: 'comfortable' | 'compact';
	sidebar?: string;
	background?: string;
	/** Sign-in page: side panel image, heading and words. */
	sign_in_image?: string;
	sign_in_title?: string;
	sign_in_text?: string;
	sign_in_side?: 'none' | 'start' | 'end';
	/** Sign-in designer: where the form sits and its own colours. */
	sign_in_position?: 'center' | 'start' | 'end';
	sign_in_bg?: string;
	sign_in_accent?: string;
	sign_in_text_color?: string;
	/** Theme tokens (empty = house style), each with a dark-mode version. */
	nav_text?: string;
	nav_accent?: string;
	sidebar_bg?: string;
	sidebar_text?: string;
	sidebar_accent?: string;
	nav_text_dark?: string;
	nav_accent_dark?: string;
	sidebar_bg_dark?: string;
	sidebar_text_dark?: string;
	sidebar_accent_dark?: string;
	/** The one global "Powered by" credit switch, off unless opted in. */
	powered_by?: boolean;
}

export interface Boot {
	version: string;
	context: 'app' | 'public';
	rest: { root: string; nonce: string };
	routes: {
		app: string;
		public: string;
		appUrl: string;
		publicUrl: string;
		pretty: boolean;
		path: string;
		adminUrl: string;
	};
	locale: string;
	security: { passwordMin: number; magicLinks: boolean };
	rtl: boolean;
	workspace: Workspace;
	branding: Branding;
	user: SessionUser | null;
	theme: ThemePreference;
	prefs: Preferences | null;
	modules: string[];
	/** Cards this person may have, in order (the app supplies the looks). */
	widgets?: Array< {
		id: string;
		title: string;
		size?: 'sm' | 'md' | 'lg';
		/** Where the card lives: Home, or the dashboard's picker. */
		place?: 'home' | 'dashboard';
	} >;
	/** Setup progress, for administrators only. */
	setup?: { completed: boolean; dismissed: boolean; remaining: number };
	safeMode?: SafeModeStatus | null;
	/** Set while somebody is looking at the app as one of a client's people. */
	impersonating?: {
		name: string;
		contact: string;
		until: string;
	} | null;
	/** Set for a client: what their own portal is configured to show. */
	portal?: {
		sections: Record< string, 'view' | 'edit' >;
		notice: { title: string; body: string } | null;
		welcome: string;
		layout: 'cards' | 'list';
		contacts: boolean;
		/** The companies somebody belongs to, when there is a choice. */
		companies?: Array< { uid: string; name: string; active: boolean } >;
	} | null;
}

export interface SafeModeStatus {
	active: boolean;
	reason: '' | 'manual' | 'site_moved';
	from: string;
	to: string;
	since: string;
	site_url: string;
}

declare global {
	interface Window {
		cliezooBoot?: Boot;
	}
}

const FALLBACK: Boot = {
	version: '0',
	context: 'app',
	rest: { root: '/wp-json/cliezoo/v1/', nonce: '' },
	routes: {
		app: 'dash',
		public: 'cz',
		appUrl: '/dash/',
		publicUrl: '/cz/',
		pretty: true,
		path: '',
		adminUrl: '',
	},
	locale: 'en_US',
	security: { passwordMin: 10, magicLinks: true },
	rtl: false,
	workspace: {
		name: '',
		country: '',
		language: '',
		timezone: 'UTC',
		currency: 'USD',
		currency_symbol_position: 'before',
		decimal_separator: '.',
		thousands_separator: ',',
		digit_grouping: 'standard',
		date_format: 'MMM D, YYYY',
		time_format: '12',
		week_start: 1,
		fiscal_year_start: 1,
	},
	branding: {
		accent: '#0458FB',
		logo: '',
		logoAlt: '',
		font: 'inter',
		radius: 'md',
		density: 'comfortable',
	},
	user: null,
	theme: 'system',
	prefs: null,
	modules: [],
};

/**
 * The boot payload (a safe fallback when the page was not served by the shell).
 */
export function readBoot(): Boot {
	return ( typeof window !== 'undefined' && window.cliezooBoot ) || FALLBACK;
}

interface SessionState {
	boot: Boot;
	setBoot: ( boot: Boot ) => void;
	setPrefs: ( prefs: Preferences ) => void;
}

/**
 * The current session. Permissions only shape the UI; the server re-checks every call.
 */
export const useSession = create< SessionState >()( ( set ) => ( {
	boot: readBoot(),
	setBoot: ( boot ) => set( { boot } ),
	setPrefs: ( prefs ) =>
		set( ( state ) => ( {
			boot: { ...state.boot, prefs, theme: prefs.theme },
		} ) ),
} ) );

/**
 * Read the boot data outside React.
 */
export const getBoot = (): Boot => useSession.getState().boot;

/**
 * The current user (null for guests).
 */
export const useUser = (): SessionUser | null =>
	useSession( ( s ) => s.boot.user );

/**
 * Whether a module is active.
 *
 * @param id Module id.
 */
export const useModule = ( id: string ): boolean =>
	useSession( ( s ) => s.boot.modules.includes( id ) );

/**
 * Which portal section a kind of record belongs to. The server holds the
 * same line — a section set to "view" is nothing a client may add to —
 * so a screen that offers the control anyway is offering a refusal.
 */
const SECTION: Record< string, string > = {
	boards: 'tasks',
	tasks: 'tasks',
	task_items: 'tasks',
	milestones: 'milestones',
	projects: 'projects',
	files: 'files',
	folders: 'files',
	conversations: 'conversations',
	requests: 'requests',
	docs: 'docs',
	documents: 'documents',
	invoices: 'invoices',
};

/**
 * The portal section a kind of record belongs to, or the kind itself.
 *
 * @param entity Entity name, as the server knows it.
 */
export function sectionOf( entity: string ): string {
	return SECTION[ entity ] ?? entity;
}

/**
 * UI permission helpers (never a security boundary).
 */
export const can = {
	scope( user: SessionUser | null, area: string ): Scope {
		return (
			( user?.permissions.scopes[ area ] as Scope | undefined ) ?? 'none'
		);
	},
	see( user: SessionUser | null, area: string ): boolean {
		return this.scope( user, area ) !== 'none';
	},
	toggle( user: SessionUser | null, name: string ): boolean {
		return !! user && ( user.owner || !! user.permissions.toggles[ name ] );
	},
	write( user: SessionUser | null ): boolean {
		return !! user?.permissions.write;
	},
	/**
	 * Whether this person may add something to a part of the workspace:
	 * staff who may write, or a client whose portal says "Can take part"
	 * for that module. It is the same line the server holds, so a client
	 * is never offered a button their next call would refuse.
	 *
	 * @param user   The reader.
	 * @param module Module id, as the portal names its sections.
	 */
	add( user: SessionUser | null, module: string ): boolean {
		if ( 'client' !== user?.kind ) {
			return this.write( user );
		}
		if ( 'client_viewer' === user.role ) {
			return false;
		}
		return 'edit' === getBoot().portal?.sections?.[ module ];
	},
};
