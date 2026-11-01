/**
 * Moving AI text in and out of a field kept as HTML (a task's
 * description), so the model reads words, not tags, and what it writes
 * goes back as HTML the rest of the app shows.
 */
import { __ } from '@wordpress/i18n';

/**
 * The words of some HTML, paragraphs and list items on their own lines.
 *
 * @param html HTML.
 */
export function htmlToText( html: string ): string {
	return html
		.replace( /<\s*li[^>]*>/gi, '\n- ' )
		.replace( /<\s*(br|\/p|\/div|\/h[1-6]|\/ul|\/ol)[^>]*>/gi, '\n' )
		.replace( /<[^>]+>/g, '' )
		.replace( /&nbsp;/g, ' ' )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /&quot;/g, '"' )
		.replace( /&#0?39;/g, "'" )
		.replace( /&amp;/g, '&' )
		.replace( /\n{3,}/g, '\n\n' )
		.trim();
}

function escape( text: string ): string {
	return text
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );
}

/**
 * Plain text as HTML: blank lines make paragraphs, lines starting "- " or
 * "* " make a list. Everything is escaped; nothing the model wrote becomes
 * markup of its own.
 *
 * @param text Plain text.
 */
export function textToHtml( text: string ): string {
	return text
		.replace( /\r\n?/g, '\n' )
		.split( /\n{2,}/ )
		.map( ( block ) => block.trim() )
		.filter( Boolean )
		.map( ( block ) => {
			const lines = block.split( '\n' );
			if ( lines.every( ( line ) => /^\s*[-*]\s+/.test( line ) ) ) {
				return `<ul>${ lines
					.map(
						( line ) =>
							`<li>${ escape( line.replace( /^\s*[-*]\s+/, '' ) ) }</li>`
					)
					.join( '' ) }</ul>`;
			}
			return `<p>${ lines.map( escape ).join( '<br>' ) }</p>`;
		} )
		.join( '' );
}

/**
 * A draft with the line that says who wrote it, for workspaces that label
 * AI-written content. It is plain words, so it survives any field, any
 * export and any sanitiser, and the person can delete it like any text.
 *
 * @param text The draft.
 */
export function labelled( text: string ): string {
	/* translators: added on its own line after text that an AI draft put into a field. */
	const line = __( 'Written by AI', 'cliezoo' );
	return `${ text.replace( /\s+$/, '' ) }\n\n— ${ line }`;
}
