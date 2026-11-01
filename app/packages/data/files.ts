/**
 * Files the site makes and sends as JSON — PDFs of documents, invoices,
 * receipts, certificates and docs — saved to the person's own device.
 */
import { api } from './api';

/** A file the site sent: its name, its type and its bytes in base64. */
export type SentFile = { name: string; type: string; data: string };

/**
 * Saves a file the site sent base64-encoded.
 *
 * @param file The file.
 */
export function saveSent( file: SentFile ): void {
	const bytes = Uint8Array.from( atob( file.data ), ( c ) =>
		c.charCodeAt( 0 )
	);
	const url = URL.createObjectURL(
		new Blob( [ bytes ], { type: file.type } )
	);
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = file.name;
	document.body.appendChild( link );
	link.click();
	link.remove();
	window.setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}

/**
 * Asks the site for a file at a route and saves it.
 *
 * @param path A route that answers with a SentFile, e.g. /invoices/{uid}/pdf.
 */
export async function downloadSent( path: string ): Promise< void > {
	const answer = await api.get< SentFile >( path );
	saveSent( answer.data );
}
