/**
 * Settings → Branding: logos, the theme (accent, typeface, corners,
 * background, navigation and sidebar colours, in light and dark) and the
 * sign-in page, with a live preview of both. Contrast is checked before
 * anything can be saved, so a workspace cannot brand itself into something
 * unreadable.
 */
import type { CSSProperties, ReactNode } from 'react';
import { Link } from '@tanstack/react-router';
import { __, sprintf } from '@wordpress/i18n';
import { getBoot } from '@cliezoo/data';
import { Alert, Button, Select, TextField, cx } from '@cliezoo/ui';
import { Fieldset, SettingsForm, useSettings } from '../useSettings';
import {
	BACKGROUND_PRESETS,
	FONTS,
	SURFACE,
	contrastRatio,
	isHex,
	unreadableTokens,
} from '../../../packages/app/appearance';
import { ImagePicker } from '../../../packages/app/form/ImagePicker';

const DEFAULT_ACCENT = '#0458FB';

/**
 * What "Restore the defaults" puts back: the Cliezoo house style.
 */
export const BRANDING_DEFAULTS: Record< string, string > = {
	accent: DEFAULT_ACCENT,
	accent_dark: '',
	logo: '',
	logo_dark: '',
	favicon: '',
	tagline: '',
	font: 'inter',
	radius: 'md',
	density: 'comfortable',
	background: 'plain',
	nav_text: '',
	nav_accent: '',
	sidebar_bg: '',
	sidebar_text: '',
	sidebar_accent: '',
	nav_text_dark: '',
	nav_accent_dark: '',
	sidebar_bg_dark: '',
	sidebar_text_dark: '',
	sidebar_accent_dark: '',
	sign_in_image: '',
	sign_in_title: '',
	sign_in_text: '',
	sign_in_side: 'none',
	sign_in_position: 'center',
	sign_in_bg: '',
	sign_in_accent: '',
	sign_in_text_color: '',
};

/**
 * Contrast between a colour and white, which is what sits on the accent.
 * @param hex
 */
export function contrastWithWhite( hex: string ): number {
	return contrastRatio( hex, '#ffffff' );
}

/**
 * Names of the background presets; the ids come from appearance.ts.
 * @param id
 */
function presetName( id: string ): string {
	const names: Record< string, string > = {
		plain: __( 'None', 'cliezoo' ),
		soft_rose: __( 'Soft Rose', 'cliezoo' ),
		flare: __( 'Flare', 'cliezoo' ),
		vivid: __( 'Vivid', 'cliezoo' ),
		aragon: __( 'Aragon', 'cliezoo' ),
		sunset: __( 'Sunset', 'cliezoo' ),
		cheer_up: __( 'Cheer Up', 'cliezoo' ),
		deja_vu: __( 'Deja Vu', 'cliezoo' ),
		dark_night: __( 'Dark Night', 'cliezoo' ),
		soft: __( 'Soft glow', 'cliezoo' ),
		mesh: __( 'Two glows', 'cliezoo' ),
	};
	return names[ id ] ?? id;
}

/**
 * Names of the theme tokens, for the contrast messages.
 * @param token
 */
function tokenName( token: string ): string {
	const dark = token.endsWith( '_dark' );
	const base = token.replace( /_dark$/, '' );
	const names: Record< string, string > = {
		sidebar_text: __( 'Sidebar text', 'cliezoo' ),
		sidebar_accent: __( 'Sidebar accent', 'cliezoo' ),
		nav_text: __( 'Navigation text', 'cliezoo' ),
		nav_accent: __( 'Navigation accent', 'cliezoo' ),
	};
	const name = names[ base ] ?? base;
	/* translators: %s: a colour setting, e.g. "Sidebar text". */
	return dark ? sprintf( __( '%s (dark mode)', 'cliezoo' ), name ) : name;
}

/**
 * A colour that may be left empty, meaning the house style.
 *
 * @param root0
 * @param root0.label
 * @param root0.value
 * @param root0.fallback What an empty value looks like.
 * @param root0.onChange
 * @param root0.error
 */
function ColourField( {
	label,
	value,
	fallback,
	onChange,
	error,
}: {
	label: string;
	value: string;
	fallback: string;
	onChange: ( value: string ) => void;
	error?: string;
} ) {
	return (
		<div className="flex items-end gap-2">
			<TextField
				className="flex-1"
				label={ label }
				type="color"
				value={ value || fallback }
				description={
					value ? undefined : __( 'The default', 'cliezoo' )
				}
				onChange={ onChange }
				errorMessage={ error }
			/>
			{ value && (
				<Button
					variant="ghost"
					size="sm"
					onPress={ () => onChange( '' ) }
				>
					{ __( 'Use the default', 'cliezoo' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * The background presets as swatches to pick from.
 *
 * @param root0
 * @param root0.value
 * @param root0.onChange
 */
function BackgroundPicker( {
	value,
	onChange,
}: {
	value: string;
	onChange: ( id: string ) => void;
} ) {
	// Older workspaces may still have one of the two first backgrounds.
	const ids: string[] = [ ...BACKGROUND_PRESETS ];
	if ( ! ids.includes( value ) && value ) {
		ids.push( value );
	}
	return (
		<div className="sm:col-span-2">
			<p id="branding-background" className="mb-1.5 text-sm font-medium">
				{ __( 'Background', 'cliezoo' ) }
			</p>
			<div
				role="radiogroup"
				aria-labelledby="branding-background"
				className="grid grid-cols-3 gap-2 sm:grid-cols-5"
			>
				{ ids.map( ( id ) => (
					<button
						key={ id }
						type="button"
						role="radio"
						aria-checked={ id === value }
						onClick={ () => onChange( id ) }
						className={ cx(
							'rounded-md border p-1.5 text-start text-xs focus-visible:outline-2 focus-visible:outline-focus',
							id === value
								? 'border-accent ring-2 ring-accent'
								: 'border-line hover:border-line-strong'
						) }
					>
						<span
							aria-hidden="true"
							data-background={ id }
							className="cz-swatch block h-10 rounded bg-bg"
						/>
						<span className="mt-1 block truncate text-fg">
							{ presetName( id ) }
						</span>
					</button>
				) ) }
			</div>
		</div>
	);
}

/**
 * A small picture of the app with the navigation and sidebar tokens.
 *
 * @param root0
 * @param root0.v
 * @param root0.dark
 */
function AppPreview( {
	v,
	dark,
}: {
	v: Record< string, string >;
	dark: boolean;
} ) {
	const suffix = dark ? '_dark' : '';
	const pick = ( key: string, fallback: string ) =>
		isHex( v[ key + suffix ] ) ? v[ key + suffix ] : fallback;
	const page = dark ? '#0b1220' : '#ffffff';
	const surface = dark ? SURFACE.dark : SURFACE.light;
	const text = dark ? '#e6eaf2' : '#0f172a';
	const accent =
		( dark && isHex( v.accent_dark ) && v.accent_dark ) ||
		( isHex( v.accent ) ? v.accent : DEFAULT_ACCENT );
	const sidebarBg = pick( 'sidebar_bg', surface );
	const sidebarText = pick( 'sidebar_text', dark ? '#94a3b8' : '#475569' );
	const sidebarAccent = pick( 'sidebar_accent', accent );
	return (
		<div
			className="flex h-36 overflow-hidden rounded-lg border border-line text-[0.7rem]"
			style={ { backgroundColor: page, color: text } }
		>
			<div
				className="flex w-28 shrink-0 flex-col gap-1 p-2"
				style={ { backgroundColor: sidebarBg, color: sidebarText } }
			>
				<span className="mb-1 font-semibold">
					{ getBoot().workspace.name }
				</span>
				<span
					className="rounded px-1.5 py-0.5 font-medium"
					style={ {
						color: sidebarAccent,
						backgroundColor: `color-mix(in oklab, ${ sidebarAccent } 14%, ${ sidebarBg })`,
					} }
				>
					{ __( 'Home', 'cliezoo' ) }
				</span>
				<span className="px-1.5">{ __( 'Clients', 'cliezoo' ) }</span>
				<span className="px-1.5">{ __( 'Projects', 'cliezoo' ) }</span>
			</div>
			<div
				data-background={ v.background || 'plain' }
				className="cz-swatch flex flex-1 flex-col"
			>
				<div
					className="flex items-center justify-between border-b px-2 py-1.5"
					style={ {
						color: pick( 'nav_text', text ),
						borderColor: dark ? '#1e293b' : '#e2e8f0',
					} }
				>
					<span>{ __( 'Search', 'cliezoo' ) }</span>
					<span style={ { color: pick( 'nav_accent', accent ) } }>
						{ __( 'Inbox', 'cliezoo' ) }
					</span>
				</div>
				<div className="p-2">
					<span
						className="inline-block rounded px-2 py-1 text-white"
						style={ { backgroundColor: accent } }
					>
						{ __( 'New project', 'cliezoo' ) }
					</span>
				</div>
			</div>
		</div>
	);
}

/**
 * A small picture of the sign-in page.
 *
 * @param root0
 * @param root0.v
 */
function SignInPreview( { v }: { v: Record< string, string > } ) {
	const accent =
		( isHex( v.sign_in_accent ) && v.sign_in_accent ) ||
		( isHex( v.accent ) ? v.accent : DEFAULT_ACCENT );
	const side = v.sign_in_side || 'none';
	const position = v.sign_in_position || 'center';
	const ink: CSSProperties | undefined = isHex( v.sign_in_text_color )
		? { color: v.sign_in_text_color }
		: undefined;
	const align = {
		center: 'items-center',
		start: 'items-start ps-4',
		end: 'items-end pe-4',
	}[ position ];
	const panel: ReactNode = 'none' !== side && (
		<div
			className={ cx(
				'w-1/3 shrink-0 bg-accent-subtle bg-cover bg-center',
				'end' === side && 'order-last'
			) }
			style={
				v.sign_in_image
					? {
							backgroundImage: `url("${ encodeURI( v.sign_in_image ) }")`,
						}
					: undefined
			}
		/>
	);
	return (
		<div
			className="flex h-56 overflow-hidden rounded-lg border border-line"
			aria-hidden="true"
		>
			{ panel }
			<div
				data-background={ v.background || 'plain' }
				className={ cx(
					'cz-swatch flex flex-1 flex-col justify-center bg-surface py-3',
					align
				) }
				style={
					isHex( v.sign_in_bg )
						? { backgroundColor: v.sign_in_bg }
						: undefined
				}
			>
				<span
					className="mb-2 text-xs font-semibold text-fg"
					style={ ink }
				>
					{ v.logoAlt || getBoot().workspace.name }
				</span>
				<div className="w-40 rounded-md border border-line bg-raised p-3 shadow-sm">
					<p className="text-xs font-semibold text-fg">
						{ __( 'Sign in', 'cliezoo' ) }
					</p>
					<span className="mt-2 block h-4 rounded border border-line" />
					<span
						className="mt-2 block rounded py-1 text-center text-[0.65rem] text-white"
						style={ { backgroundColor: accent } }
					>
						{ __( 'Continue', 'cliezoo' ) }
					</span>
				</div>
				{ getBoot().branding.powered_by && (
					<span
						className="mt-2 text-[0.6rem] text-subtle"
						style={ ink }
					>
						{ __( 'Powered by Cliezoo', 'cliezoo' ) }
					</span>
				) }
			</div>
		</div>
	);
}

export default function Branding() {
	const s = useSettings( 'branding' );
	const v = s.values as Record< string, string >;
	const text = ( key: string ) => ( v[ key ] as string ) ?? '';
	const accent = text( 'accent' ) || DEFAULT_ACCENT;
	const contrast = contrastWithWhite( accent );
	const signInAccent = text( 'sign_in_accent' );
	const problems = [
		...unreadableTokens( v, 'light' ),
		...unreadableTokens( v, 'dark' ),
	];
	const signInText = text( 'sign_in_text_color' );
	const signInTextRatio = contrastRatio(
		signInText,
		text( 'sign_in_bg' ) || SURFACE.light
	);
	const readable =
		contrast >= 4.5 &&
		contrastWithWhite( signInAccent ) >= 4.5 &&
		signInTextRatio >= 4.5 &&
		0 === problems.length;
	const workspace = getBoot().workspace.name;

	const restore = () => {
		for ( const [ key, value ] of Object.entries( BRANDING_DEFAULTS ) ) {
			s.set( key, value );
		}
	};
	const colour = ( key: string, label: string, fallback: string ) => (
		<ColourField
			label={ label }
			value={ text( key ) }
			fallback={ fallback }
			onChange={ ( x ) => s.set( key, x ) }
			error={ s.errors[ key ] }
		/>
	);

	return (
		<SettingsForm state={ s } canSave={ readable }>
			<Fieldset
				title={ __( 'Logo', 'cliezoo' ) }
				description={ __(
					'Shown in the sidebar, on the sign-in page and on what you send out. Choose a picture, add one, or paste its address.',
					'cliezoo'
				) }
			>
				<ImagePicker
					label={ __( 'Logo', 'cliezoo' ) }
					value={ text( 'logo' ) }
					onChange={ ( x ) => s.set( 'logo', x ) }
					errorMessage={ s.errors.logo }
				/>
				<ImagePicker
					label={ __( 'Logo for dark mode', 'cliezoo' ) }
					description={ __(
						'Optional. The main logo is used when this is empty.',
						'cliezoo'
					) }
					value={ text( 'logo_dark' ) }
					onChange={ ( x ) => s.set( 'logo_dark', x ) }
					errorMessage={ s.errors.logo_dark }
				/>
				<TextField
					label={ __( 'Logo text', 'cliezoo' ) }
					description={ __(
						'Used when there is no logo, and read aloud instead of the image.',
						'cliezoo'
					) }
					value={ text( 'logoAlt' ) }
					onChange={ ( x ) => s.set( 'logoAlt', x ) }
					errorMessage={ s.errors.logoAlt }
				/>
				<TextField
					label={ __( 'Favicon', 'cliezoo' ) }
					value={ text( 'favicon' ) }
					onChange={ ( x ) => s.set( 'favicon', x ) }
					errorMessage={ s.errors.favicon }
				/>
				<TextField
					label={ __( 'Tagline', 'cliezoo' ) }
					value={ text( 'tagline' ) }
					onChange={ ( x ) => s.set( 'tagline', x ) }
					errorMessage={ s.errors.tagline }
				/>
			</Fieldset>

			<Fieldset
				title={ __( 'Workspace theme', 'cliezoo' ) }
				description={ __(
					'Your accent colour is used for buttons, links, switches and focus rings.',
					'cliezoo'
				) }
			>
				<TextField
					label={ __( 'Accent', 'cliezoo' ) }
					type="color"
					value={ accent }
					onChange={ ( x ) => s.set( 'accent', x ) }
					errorMessage={ s.errors.accent }
				/>
				{ colour(
					'accent_dark',
					__( 'Accent for dark mode', 'cliezoo' ),
					accent
				) }
				<Select
					label={ __( 'Corners', 'cliezoo' ) }
					items={ [
						{ id: 'none', label: __( 'Square', 'cliezoo' ) },
						{ id: 'sm', label: __( 'Slightly round', 'cliezoo' ) },
						{ id: 'md', label: __( 'Round', 'cliezoo' ) },
						{ id: 'lg', label: __( 'Very round', 'cliezoo' ) },
					] }
					selectedKey={ text( 'radius' ) || 'md' }
					onSelectionChange={ ( k ) =>
						s.set( 'radius', String( k ) )
					}
				/>
				<Select
					label={ __( 'Typeface', 'cliezoo' ) }
					description={ __(
						'Typefaces come with the plugin, so nothing is loaded from another server.',
						'cliezoo'
					) }
					items={ FONTS.map( ( font ) => ( {
						id: font.id,
						label:
							font.name || __( 'The device default', 'cliezoo' ),
					} ) ) }
					selectedKey={ text( 'font' ) || 'inter' }
					onSelectionChange={ ( k ) => s.set( 'font', String( k ) ) }
				/>
				<BackgroundPicker
					value={ text( 'background' ) || 'plain' }
					onChange={ ( id ) => s.set( 'background', id ) }
				/>
			</Fieldset>

			<Fieldset
				title={ __( 'Navigation and sidebar', 'cliezoo' ) }
				description={ __(
					'Navigation is the bar along the top (and along the bottom on a phone). Leave a colour on the default to keep the standard look. Dark mode has its own set.',
					'cliezoo'
				) }
			>
				{ colour(
					'nav_text',
					__( 'Navigation text', 'cliezoo' ),
					'#0f172a'
				) }
				{ colour(
					'nav_accent',
					__( 'Navigation accent', 'cliezoo' ),
					accent
				) }
				{ colour(
					'sidebar_bg',
					__( 'Sidebar background', 'cliezoo' ),
					SURFACE.light
				) }
				{ colour(
					'sidebar_text',
					__( 'Sidebar text', 'cliezoo' ),
					'#475569'
				) }
				{ colour(
					'sidebar_accent',
					__( 'Sidebar accent', 'cliezoo' ),
					accent
				) }
			</Fieldset>

			<Fieldset
				title={ __( 'Dark theme colours', 'cliezoo' ) }
				description={ __(
					'The same colours for people who use dark mode. Empty ones keep the standard dark look.',
					'cliezoo'
				) }
			>
				{ colour(
					'nav_text_dark',
					__( 'Navigation text', 'cliezoo' ),
					'#e6eaf2'
				) }
				{ colour(
					'nav_accent_dark',
					__( 'Navigation accent', 'cliezoo' ),
					'#4a87fc'
				) }
				{ colour(
					'sidebar_bg_dark',
					__( 'Sidebar background', 'cliezoo' ),
					SURFACE.dark
				) }
				{ colour(
					'sidebar_text_dark',
					__( 'Sidebar text', 'cliezoo' ),
					'#94a3b8'
				) }
				{ colour(
					'sidebar_accent_dark',
					__( 'Sidebar accent', 'cliezoo' ),
					'#4a87fc'
				) }
			</Fieldset>

			{ contrast < 4.5 && (
				<Alert tone="error">
					{ __(
						'White text on this accent is hard to read. Choose a darker colour before saving.',
						'cliezoo'
					) }
				</Alert>
			) }
			{ problems.map( ( [ token, ratio ] ) => (
				<Alert tone="error" key={ token }>
					{ sprintf(
						/* translators: 1: a colour setting, e.g. "Sidebar text"; 2: contrast ratio, e.g. "2.1". */
						__(
							'%1$s is hard to read against its background (%2$s:1; at least 4.5:1 is needed).',
							'cliezoo'
						),
						tokenName( token ),
						ratio.toFixed( 1 )
					) }
				</Alert>
			) ) }

			<section
				aria-labelledby="branding-preview"
				className="rounded-lg border border-line bg-raised p-4"
			>
				<h3
					id="branding-preview"
					className="mb-3 text-sm font-semibold text-fg"
				>
					{ __( 'Preview', 'cliezoo' ) }
				</h3>
				<div className="grid gap-3 sm:grid-cols-2">
					<div>
						<p className="mb-1 text-xs text-muted">
							{ __( 'Light', 'cliezoo' ) }
						</p>
						<AppPreview v={ v } dark={ false } />
					</div>
					<div>
						<p className="mb-1 text-xs text-muted">
							{ __( 'Dark', 'cliezoo' ) }
						</p>
						<AppPreview v={ v } dark />
					</div>
				</div>
				<p className="mt-3 text-xs text-muted">
					{ text( 'logoAlt' ) || workspace } ·{ ' ' }
					{ sprintf(
						/* translators: %s: contrast ratio, e.g. "5.2". */
						__( 'Contrast with white: %s', 'cliezoo' ),
						contrast.toFixed( 1 )
					) }
				</p>
			</section>

			<Fieldset
				title={ __( 'Sign-in page', 'cliezoo' ) }
				description={ __(
					'What people see before they are signed in.',
					'cliezoo'
				) }
			>
				<TextField
					label={ __( 'Heading', 'cliezoo' ) }
					value={ text( 'sign_in_title' ) }
					onChange={ ( x ) => s.set( 'sign_in_title', x ) }
					errorMessage={ s.errors.sign_in_title }
				/>
				<TextField
					label={ __( 'Words under it', 'cliezoo' ) }
					multiline
					rows={ 2 }
					value={ text( 'sign_in_text' ) }
					onChange={ ( x ) => s.set( 'sign_in_text', x ) }
					errorMessage={ s.errors.sign_in_text }
				/>
				<Select
					label={ __( 'Where the form sits', 'cliezoo' ) }
					description={ __(
						'On a wide screen. Phones always show it in the middle.',
						'cliezoo'
					) }
					items={ [
						{
							id: 'center',
							label: __( 'In the middle', 'cliezoo' ),
						},
						{ id: 'start', label: __( 'On the left', 'cliezoo' ) },
						{ id: 'end', label: __( 'On the right', 'cliezoo' ) },
					] }
					selectedKey={ text( 'sign_in_position' ) || 'center' }
					onSelectionChange={ ( k ) =>
						s.set( 'sign_in_position', String( k ) )
					}
				/>
				<Select
					label={ __( 'Side panel', 'cliezoo' ) }
					items={ [
						{ id: 'none', label: __( 'None', 'cliezoo' ) },
						{ id: 'start', label: __( 'On the left', 'cliezoo' ) },
						{ id: 'end', label: __( 'On the right', 'cliezoo' ) },
					] }
					selectedKey={ text( 'sign_in_side' ) || 'none' }
					onSelectionChange={ ( k ) =>
						s.set( 'sign_in_side', String( k ) )
					}
				/>
				<TextField
					label={ __( 'Side panel image', 'cliezoo' ) }
					value={ text( 'sign_in_image' ) }
					onChange={ ( x ) => s.set( 'sign_in_image', x ) }
					errorMessage={ s.errors.sign_in_image }
				/>
				{ colour(
					'sign_in_bg',
					__( 'Background colour', 'cliezoo' ),
					SURFACE.light
				) }
				{ colour(
					'sign_in_accent',
					__( 'Button and link colour', 'cliezoo' ),
					accent
				) }
				{ colour(
					'sign_in_text_color',
					__( 'Text colour', 'cliezoo' ),
					'#0f172a'
				) }
				<p className="text-sm text-muted sm:col-span-2">
					{ getBoot().branding.powered_by
						? __(
								'The "Powered by Cliezoo" credit is on. It is one switch for every page your clients see, in Settings → General.',
								'cliezoo'
							)
						: __(
								'The "Powered by Cliezoo" credit is off. It is one switch for every page your clients see, in Settings → General.',
								'cliezoo'
							) }{ ' ' }
					<Link
						to="/settings/$section"
						params={ { section: 'general' } }
						className="text-accent-fg underline"
					>
						{ __( 'Open General', 'cliezoo' ) }
					</Link>
				</p>
			</Fieldset>

			{ contrastWithWhite( signInAccent ) < 4.5 && (
				<Alert tone="error">
					{ __(
						'White text on the sign-in button colour is hard to read. Choose a darker colour before saving.',
						'cliezoo'
					) }
				</Alert>
			) }
			{ signInTextRatio < 4.5 && (
				<Alert tone="error">
					{ __(
						'The sign-in text colour is hard to read on its background. Choose another before saving.',
						'cliezoo'
					) }
				</Alert>
			) }

			<section
				aria-labelledby="branding-sign-in-preview"
				className="rounded-lg border border-line bg-raised p-4"
			>
				<h3
					id="branding-sign-in-preview"
					className="mb-3 text-sm font-semibold text-fg"
				>
					{ __( 'Sign-in page preview', 'cliezoo' ) }
				</h3>
				<SignInPreview v={ v } />
			</section>

			<div>
				<Button variant="secondary" size="sm" onPress={ restore }>
					{ __( 'Restore the defaults', 'cliezoo' ) }
				</Button>
			</div>
		</SettingsForm>
	);
}
