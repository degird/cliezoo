/**
 * Personal preferences (theme, density, sidebar) and live theme switching.
 */
import { useMutation } from '@tanstack/react-query';
import { api, type ApiError } from './api';
import {
	getBoot,
	useSession,
	type Preferences,
	type ThemePreference,
} from './boot';

const STORAGE_KEY = 'cliezoo-theme';

const media = (): MediaQueryList | null =>
	typeof window !== 'undefined' && window.matchMedia
		? window.matchMedia( '(prefers-color-scheme: dark)' )
		: null;

/**
 * Resolves a preference to the mode shown now.
 *
 * @param pref Preference.
 */
export function resolveTheme( pref: ThemePreference ): 'light' | 'dark' {
	if ( pref === 'system' ) {
		return media()?.matches ? 'dark' : 'light';
	}
	return pref;
}

/**
 * Applies a theme to <html> and remembers it on this device.
 *
 * @param pref Preference.
 */
export function applyTheme( pref: ThemePreference ): void {
	const root = document.documentElement;
	root.setAttribute( 'data-theme', resolveTheme( pref ) );
	root.setAttribute( 'data-theme-pref', pref );
	try {
		window.localStorage.setItem( STORAGE_KEY, pref );
	} catch {
		// Private mode: the server copy still applies on the next load.
	}
}

/**
 * Follows the operating system while the preference is "system".
 *
 * @return Unsubscribe.
 */
export function watchSystemTheme(): () => void {
	const query = media();
	if ( ! query ) {
		return () => undefined;
	}
	const onChange = () => {
		if (
			document.documentElement.getAttribute( 'data-theme-pref' ) ===
			'system'
		) {
			document.documentElement.setAttribute(
				'data-theme',
				query.matches ? 'dark' : 'light'
			);
		}
	};
	query.addEventListener( 'change', onChange );
	return () => query.removeEventListener( 'change', onChange );
}

/**
 * Current preferences (defaults for guests).
 */
export function usePreferences(): Preferences {
	return (
		useSession( ( s ) => s.boot.prefs ) ?? {
			theme: getBoot().theme,
			density: 'comfortable',
			sidebar_collapsed: false,
			quick_access_open: true,
			nav_hidden: [],
			nav_order: [],
			reduce_motion: false,
			tour_done: false,
			recent_searches: [],
			recent_records: [],
		}
	);
}

/**
 * Saves preferences optimistically (the UI changes at once; a failure rolls back).
 */
export function useSavePreferences() {
	return useMutation<
		Preferences,
		ApiError,
		Partial< Preferences >,
		{ previous: Preferences | null }
	>( {
		mutationFn: async ( changes ) =>
			( await api.patch< Preferences >( '/me/preferences', changes ) )
				.data,
		onMutate: ( changes ) => {
			const previous = useSession.getState().boot.prefs;
			if ( previous ) {
				useSession.getState().setPrefs( { ...previous, ...changes } );
			}
			if ( changes.theme ) {
				applyTheme( changes.theme );
			}
			return { previous };
		},
		onError: ( _error, _changes, context ) => {
			if ( context?.previous ) {
				useSession.getState().setPrefs( context.previous );
				applyTheme( context.previous.theme );
			}
		},
		onSuccess: ( prefs ) => useSession.getState().setPrefs( prefs ),
	} );
}

/**
 * Changes the theme from outside React (commands, shortcuts) and saves it.
 *
 * @param pref Preference.
 */
export async function setThemePreference(
	pref: ThemePreference
): Promise< void > {
	applyTheme( pref );
	const current = useSession.getState().boot.prefs;
	if ( ! current ) {
		return;
	}
	useSession.getState().setPrefs( { ...current, theme: pref } );
	try {
		useSession.getState().setPrefs(
			(
				await api.patch< Preferences >( '/me/preferences', {
					theme: pref,
				} )
			).data
		);
	} catch {
		// Kept on this device; the server copy updates next time.
	}
}

/**
 * Remembers something the person just did, newest first and without
 * duplicates, so the palette can offer it next time. Saving is best-effort:
 * a failure never interrupts what they were doing.
 *
 * @param key   Which list.
 * @param value A search term, or a record as `type:uid`.
 */
export async function remember(
	key: 'recent_searches' | 'recent_records',
	value: string
): Promise< void > {
	const trimmed = value.trim().slice( 0, 80 );
	const current = useSession.getState().boot.prefs;
	if ( ! trimmed || ! current ) {
		return;
	}
	const next = [
		trimmed,
		...current[ key ].filter( ( item ) => item !== trimmed ),
	].slice( 0, 10 );
	if ( next.join( '|' ) === current[ key ].join( '|' ) ) {
		return;
	}
	useSession.getState().setPrefs( { ...current, [ key ]: next } );
	try {
		useSession.getState().setPrefs(
			(
				await api.patch< Preferences >( '/me/preferences', {
					[ key ]: next,
				} )
			).data
		);
	} catch {
		// Kept on this device; the server copy updates next time.
	}
}
