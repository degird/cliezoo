/**
 * Centered card used by the sign-in screens (branded, no vendor name).
 */
import type { CSSProperties, ReactNode } from 'react';
import { getBoot, type Branding } from '@cliezoo/data';
import { Toaster, cx } from '@cliezoo/ui';
import { Logo } from '../../Logo';
import { PoweredBy } from '../../PoweredBy';

export function AuthLayout( {
	title,
	subtitle,
	children,
	footer,
}: {
	title: string;
	subtitle?: ReactNode;
	children: ReactNode;
	footer?: ReactNode;
} ) {
	const { workspace, branding } = getBoot();
	const side = branding.sign_in_side ?? 'none';
	// The designer's text colour is for the words on the page around the
	// form; the form's own card keeps the theme's, which it was checked
	// against.
	const ink =
		branding.sign_in_text_color &&
		/^#[0-9a-f]{6}$/i.test( branding.sign_in_text_color )
			? { color: branding.sign_in_text_color }
			: undefined;
	const panel = 'none' !== side && (
		<aside
			className={ cx(
				'relative hidden flex-1 overflow-hidden bg-accent-subtle lg:block',
				'end' === side ? 'order-last' : ''
			) }
		>
			{ branding.sign_in_image && (
				<img
					src={ branding.sign_in_image }
					alt=""
					className="size-full object-cover"
				/>
			) }
			{ ( branding.sign_in_title || branding.sign_in_text ) && (
				<div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-8 text-white">
					{ branding.sign_in_title && (
						<p className="text-2xl font-semibold">
							{ branding.sign_in_title }
						</p>
					) }
					{ branding.sign_in_text && (
						<p className="mt-2 max-w-md text-sm opacity-90">
							{ branding.sign_in_text }
						</p>
					) }
				</div>
			) }
		</aside>
	);
	return (
		<div className="flex min-h-dvh" style={ signInStyle( branding ) }>
			{ panel }
			<main
				id="cliezoo-main"
				tabIndex={ -1 }
				className={ cx(
					'cz-backdrop flex min-h-dvh flex-1 flex-col items-center justify-center bg-surface px-4 py-10 outline-none',
					formPlacement( branding.sign_in_position )
				) }
				style={
					branding.sign_in_bg
						? { backgroundColor: branding.sign_in_bg }
						: undefined
				}
			>
				<div className="mb-6 flex items-center gap-2.5">
					<Logo size={ 36 } />
					<span
						className="text-lg font-semibold text-fg"
						style={ ink }
					>
						{ workspace.name || branding.logoAlt }
					</span>
				</div>
				{ 'none' === side && branding.sign_in_title && (
					<p
						className="mb-4 max-w-sm text-center text-sm text-muted"
						style={ ink }
					>
						{ branding.sign_in_title }
					</p>
				) }
				<div className="w-full max-w-sm rounded-xl border border-line bg-raised p-6 shadow-md sm:p-8">
					<h1 className="text-xl font-semibold tracking-tight text-fg">
						{ title }
					</h1>
					{ subtitle && (
						<p className="mt-1.5 text-sm text-muted">
							{ subtitle }
						</p>
					) }
					<div className="mt-6">{ children }</div>
				</div>
				{ footer && (
					<div className="mt-6 text-sm text-muted" style={ ink }>
						{ footer }
					</div>
				) }
				<PoweredBy className="mt-6" />
				<Toaster />
			</main>
		</div>
	);
}

/**
 * Where the sign-in form sits on a wide screen. A phone always centres it.
 *
 * @param position Designer setting.
 */
export function formPlacement( position: string | undefined ): string {
	if ( 'start' === position ) {
		return 'lg:items-start lg:ps-16';
	}
	if ( 'end' === position ) {
		return 'lg:items-end lg:pe-16';
	}
	return '';
}

/**
 * The sign-in page's own accent, as the tokens every
 * control reads, so the designer's colours reach buttons, links and the
 * focus ring without touching them one by one. The designer checks the
 * accent against white before it can be saved.
 *
 * @param branding Branding.
 */
export function signInStyle(
	branding: Pick< Branding, 'sign_in_accent' >
): CSSProperties | undefined {
	const style: Record< string, string > = {};
	const hex = /^#[0-9a-f]{6}$/i;
	if ( branding.sign_in_accent && hex.test( branding.sign_in_accent ) ) {
		style[ '--cz-accent' ] = branding.sign_in_accent;
		style[ '--cz-accent-hover' ] =
			`color-mix(in oklab, ${ branding.sign_in_accent } 85%, black)`;
		style[ '--cz-accent-text' ] = branding.sign_in_accent;
		style[ '--cz-focus-ring' ] = branding.sign_in_accent;
	}
	return Object.keys( style ).length ? ( style as CSSProperties ) : undefined;
}

/**
 * A place in this app, or undefined for anywhere else.
 *
 * @param wanted Where something asked to go.
 */
function inApp( wanted: string ): string | undefined {
	if ( '' === wanted ) {
		return undefined;
	}
	const { appUrl } = getBoot().routes;
	// An in-app path ("/projects/…") from the router's sign-in redirect.
	if (
		/^\/(?!\/)[^\\]*$/.test( wanted ) &&
		! /^\/(login|auth\/)/.test( wanted )
	) {
		return appLink( wanted );
	}
	try {
		const url = new URL( wanted, window.location.origin );
		const app = new URL( appUrl, window.location.origin );
		if (
			url.origin === app.origin &&
			url.pathname.startsWith( app.pathname ) &&
			! /\/(login|auth\/)/.test( url.pathname )
		) {
			return url.href;
		}
	} catch {
		// Ignore malformed redirects.
	}
	return undefined;
}

/**
 * Where to go after signing in: a same-site app URL from ?redirect=, else
 * the server's answer, else the app itself.
 *
 * The server's answer gets the same reading as the query string. Part of
 * it — the `next` of a magic link — began life as a `redirect` field the
 * person asking for the link filled in, so "the server said so" is not by
 * itself a reason to send a browser there.
 *
 * @param fallback Server-provided redirect.
 */
export function afterLoginUrl( fallback?: string ): string {
	const { appUrl, pretty } = getBoot().routes;
	const search = pretty
		? window.location.search
		: window.location.hash.replace( /^[^?]*/, '' );
	const wanted = new URLSearchParams( search ).get( 'redirect' ) ?? '';
	return inApp( wanted ) ?? inApp( String( fallback ?? '' ) ) ?? appUrl;
}

/**
 * Absolute URL of an in-app path, for full page loads.
 *
 * @param path In-app path, e.g. "/account".
 */
export function appLink( path: string ): string {
	const { appUrl, pretty } = getBoot().routes;
	return pretty
		? appUrl.replace( /\/$/, '' ) + path
		: `${ appUrl }#${ path }`;
}
