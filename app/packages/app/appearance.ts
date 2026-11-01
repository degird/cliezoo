/**
 * Workspace appearance that goes beyond the accent: the theme tokens for
 * navigation and the sidebar (light and dark), the background presets and
 * the typefaces. Pure functions plus one that writes the result onto the
 * page, so the rules can be tested without a browser.
 *
 * Tokens become custom properties on <html> and a data flag on <body>;
 * tokens.css scopes them to the region they belong to. Both the light and
 * the dark value are written at once and CSS picks between them, so
 * switching theme needs no JavaScript and cannot miss a token.
 */
import type { Branding } from '@cliezoo/data';

/** A theme token: the branding key and the custom property it sets. */
export const THEME_TOKENS = [
	'nav_text',
	'nav_accent',
	'sidebar_bg',
	'sidebar_text',
	'sidebar_accent',
] as const;

export type ThemeToken = ( typeof THEME_TOKENS )[ number ];

/**
 * The named background presets (cliezoo.md §6.37). "plain" is the one the
 * designer calls None. Each is a gradient in tokens.css: nothing is loaded.
 */
export const BACKGROUND_PRESETS = [
	'plain',
	'soft_rose',
	'flare',
	'vivid',
	'aragon',
	'sunset',
	'cheer_up',
	'deja_vu',
	'dark_night',
] as const;

/**
 * Typefaces the picker offers. Only fonts shipped inside the plugin may be
 * listed (WordPress.org plugins may not load fonts from another server),
 * and a font's files load only when it is chosen: `load` is a dynamic
 * import of its stylesheet, so no font sits in the start-up bundle.
 */
export interface FontChoice {
	id: string;
	/** Its own name, which is not translated. Empty for the device default. */
	name: string;
	/** Loads the font's files; absent for fonts that are always there. */
	load?: () => Promise< unknown >;
}

export const FONTS: FontChoice[] = [
	// Inter is part of the app stylesheet already.
	{ id: 'inter', name: 'Inter' },
	{ id: 'system', name: '' },
];

const HEX = /^#[0-9a-f]{6}$/i;

/**
 * Whether a value is a colour the tokens accept.
 *
 * @param value Anything.
 */
export function isHex( value: unknown ): value is string {
	return 'string' === typeof value && HEX.test( value );
}

/**
 * Relative luminance, the WCAG way.
 *
 * @param hex A #rrggbb colour.
 */
export function luminance( hex: string ): number {
	const value = hex.replace( '#', '' );
	const parts = [ 0, 2, 4 ].map( ( i ) => {
		const channel = parseInt( value.slice( i, i + 2 ), 16 ) / 255;
		return channel <= 0.03928
			? channel / 12.92
			: Math.pow( ( channel + 0.055 ) / 1.055, 2.4 );
	} );
	return 0.2126 * parts[ 0 ] + 0.7152 * parts[ 1 ] + 0.0722 * parts[ 2 ];
}

/**
 * WCAG contrast between two colours; 21 when either is not a colour, so a
 * token left empty never blocks saving.
 *
 * @param a A #rrggbb colour.
 * @param b Another.
 */
export function contrastRatio( a: string, b: string ): number {
	if ( ! isHex( a ) || ! isHex( b ) ) {
		return 21;
	}
	const [ light, dark ] = [ luminance( a ), luminance( b ) ].sort(
		( x, y ) => y - x
	);
	return ( light + 0.05 ) / ( dark + 0.05 );
}

/** The page's own colours, which a token is read against when it has no partner. */
export const SURFACE = { light: '#f7f8fa', dark: '#111a2e' } as const;

/**
 * Pairs of colours that must stay readable, for one mode. Text on the
 * sidebar is checked against the sidebar's background (or the page's when
 * the background is not set), and so is its accent.
 *
 * @param branding Branding values (tokens may be missing).
 * @param mode     Light or dark.
 * @return Problems, as [token, ratio] for every pair under 4.5:1.
 */
export function unreadableTokens(
	branding: Partial< Record< string, unknown > >,
	mode: 'light' | 'dark'
): Array< [ string, number ] > {
	const suffix = 'dark' === mode ? '_dark' : '';
	const read = ( key: string ): string => {
		const value = branding[ key + suffix ];
		return isHex( value ) ? value : '';
	};
	const sidebar = read( 'sidebar_bg' ) || SURFACE[ mode ];
	const page = SURFACE[ mode ];
	const pairs: Array< [ string, string, string ] > = [
		[ 'sidebar_text', read( 'sidebar_text' ), sidebar ],
		[ 'sidebar_accent', read( 'sidebar_accent' ), sidebar ],
		[ 'nav_text', read( 'nav_text' ), page ],
		[ 'nav_accent', read( 'nav_accent' ), page ],
	];
	const out: Array< [ string, number ] > = [];
	for ( const [ token, colour, against ] of pairs ) {
		if ( ! colour ) {
			continue;
		}
		const ratio = contrastRatio( colour, against );
		if ( ratio < 4.5 ) {
			out.push( [ token + suffix, ratio ] );
		}
	}
	return out;
}

/**
 * What the tokens write onto the page: custom properties for <html> and
 * flags for <body>, for every token that is set.
 *
 * @param branding Branding values.
 */
export function themeTokens( branding: Partial< Record< string, unknown > > ): {
	vars: Record< string, string >;
	flags: string[];
} {
	const vars: Record< string, string > = {};
	const flags: string[] = [];
	for ( const token of THEME_TOKENS ) {
		for ( const suffix of [ '', '_dark' ] ) {
			const value = branding[ token + suffix ];
			if ( ! isHex( value ) ) {
				continue;
			}
			const name = ( token + suffix ).replace( /_/g, '-' );
			vars[ `--cz-brand-${ name }` ] = value;
			flags.push( name );
		}
	}
	return { vars, flags };
}

/**
 * The font to show: a known id, else Inter.
 *
 * @param id Saved font id.
 */
export function fontFor( id: string | undefined ): FontChoice {
	return FONTS.find( ( font ) => font.id === id ) ?? FONTS[ 0 ];
}

/**
 * Writes the theme tokens, background and font onto the page.
 *
 * @param branding Branding from the boot data.
 */
export function applyBranding( branding: Branding ): void {
	const root = document.documentElement;
	const body = document.body;
	const { vars, flags } = themeTokens(
		branding as unknown as Record< string, unknown >
	);
	for ( const [ name, value ] of Object.entries( vars ) ) {
		root.style.setProperty( name, value );
	}
	for ( const flag of flags ) {
		body.setAttribute( `data-${ flag }`, '' );
	}
	const font = fontFor( branding.font );
	body.dataset.font = font.id;
	font.load?.().catch( () => {
		// The fallback stack in tokens.css is already on screen.
	} );
	if ( branding.background ) {
		body.dataset.background = branding.background;
	}
}

/**
 * Puts an accent on the page: buttons, links and the focus ring.
 *
 * @param accent A #rrggbb colour; anything else leaves the house accent.
 */
export function setAccent( accent: unknown ): void {
	if ( ! isHex( accent ) ) {
		return;
	}
	const root = document.documentElement;
	root.style.setProperty( '--cz-accent', accent );
	root.style.setProperty(
		'--cz-accent-hover',
		`color-mix(in oklab, ${ accent } 85%, black)`
	);
	root.style.setProperty( '--cz-accent-text', accent );
	root.style.setProperty( '--cz-focus-ring', accent );
}

/**
 * The workspace accent for the theme on screen; dark mode may have its own.
 *
 * @param branding Branding from the boot data.
 */
export function accentFor( branding: Branding ): string {
	const dark =
		document.documentElement.getAttribute( 'data-theme' ) === 'dark';
	return ( dark && branding.accent_dark ) || branding.accent || '';
}
