/**
 * What the embed screens share: the shape of an embed, the check an
 * address goes through, and the sizes a frame can take.
 */
import { __ } from '@wordpress/i18n';
import { api, getBoot, useQuery } from '@cliezoo/data';

export interface Preview {
	title?: string;
	description?: string;
	site?: string;
	image?: string;
	icon?: string;
}

export interface UrlCheck {
	reachable: boolean;
	blocked: boolean;
	reason: string;
	checked_at?: string;
}

/** A file that is a link or an embed. */
export interface EmbedFile {
	uid: string;
	name: string;
	kind: string;
	url?: string | null;
	description?: string | null;
	embed_url?: string | null;
	embed_provider?: string | null;
	embed_height?: number | null;
	embed_aspect?: string | null;
	open_new_window?: boolean;
	url_check?: UrlCheck | null;
	preview?: Preview | null;
	show_as_tab?: boolean;
	portal_widget?: boolean;
	client?: { uid: string; label: string } | null;
	project?: { uid: string; label: string } | null;
}

export interface Checked {
	url: string;
	embed_url: string;
	provider: string;
	label: string;
	height: number | null;
	aspect: string;
	reachable: boolean;
	blocked: boolean;
	reason: string;
	preview: Preview;
}

/**
 * Whether the workspace has embeds switched on.
 */
export function embedsOn(): boolean {
	return getBoot().modules?.includes( 'embeds' ) ?? false;
}

/**
 * The check of an address, once it looks like one.
 *
 * @param url The address as typed.
 */
export function useCheck( url: string ) {
	const ready = checkable( url );
	return useQuery( {
		queryKey: [ 'embeds', 'check', url.trim() ],
		enabled: ready,
		staleTime: 5 * 60_000,
		retry: false,
		queryFn: async () =>
			(
				await api.post< Checked >( '/embeds/check', {
					url: url.trim(),
				} )
			).data,
	} );
}

/**
 * Whether an address, as typed, is one to check: a web address over https.
 *
 * @param url The address.
 */
export function checkable( url: string ): boolean {
	return /^https:\/\/[^\s/]+\.[^\s]+$/i.test( url.trim() );
}

/**
 * The heights a frame can be given.
 */
export function heights(): Array< { id: string; label: string } > {
	return [
		{ id: '0', label: __( 'As the site suggests', 'cliezoo' ) },
		{ id: '300', label: __( 'Short (300 px)', 'cliezoo' ) },
		{ id: '500', label: __( 'Medium (500 px)', 'cliezoo' ) },
		{ id: '700', label: __( 'Tall (700 px)', 'cliezoo' ) },
		{ id: '900', label: __( 'Taller (900 px)', 'cliezoo' ) },
		{ id: '1200', label: __( 'Page (1200 px)', 'cliezoo' ) },
	];
}

/**
 * The proportions a frame can keep instead.
 */
export function aspects(): Array< { id: string; label: string } > {
	return [
		{ id: 'none', label: __( 'Fixed height', 'cliezoo' ) },
		{ id: '16:9', label: __( 'Widescreen (16:9)', 'cliezoo' ) },
		{ id: '4:3', label: __( 'Standard (4:3)', 'cliezoo' ) },
		{ id: '1:1', label: __( 'Square (1:1)', 'cliezoo' ) },
	];
}

/**
 * Whether an address may be shown in a frame here: the web, over https,
 * and never this site itself.
 *
 * @param url The address.
 */
export function frameable( url: string | null | undefined ): boolean {
	try {
		const parsed = new URL( String( url ?? '' ) );
		return (
			'https:' === parsed.protocol && parsed.host !== window.location.host
		);
	} catch {
		return false;
	}
}
