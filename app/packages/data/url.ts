/**
 * Addresses that came out of the database, checked before the browser is
 * handed one.
 *
 * The server already refuses anything but http and https where a link is
 * saved (Serializer::sanitize, FieldTypeRegistry, Uploads, DocContent).
 * This is the second lock: a row written before a rule existed, imported,
 * or reached through some path nobody thought of should still not be able
 * to put `javascript:` behind a click. Anything not recognised returns
 * undefined, which leaves the element with no address rather than a
 * dangerous one.
 */

/** What a link may be: the web, an address, a phone number. */
const LINK_SCHEMES = [ 'http:', 'https:', 'mailto:', 'tel:' ];

/** What a picture or a frame may be: the web. */
const MEDIA_SCHEMES = [ 'http:', 'https:' ];

/** A path on this site ("/files/…"), or a place on this page ("#top"). */
const SAME_SITE = /^(\/(?!\/)|#)[^\s<>"'\\]*$/;

/**
 * An address a link may go to, or undefined.
 *
 * @param href What the record says.
 */
export function safeHref( href: unknown ): string | undefined {
	return checked( href, LINK_SCHEMES );
}

/**
 * An address a picture or a frame may be loaded from, or undefined.
 *
 * `mailto:` and `tel:` make no sense in a `src`, so this is narrower.
 *
 * @param src What the record says.
 */
export function safeSrc( src: unknown ): string | undefined {
	return checked( src, MEDIA_SCHEMES );
}

/**
 * The address, if its scheme is one of these.
 *
 * @param value   What the record says.
 * @param schemes The schemes allowed.
 */
function checked( value: unknown, schemes: string[] ): string | undefined {
	const address = String( value ?? '' );
	if ( '' === address ) {
		return undefined;
	}
	if ( SAME_SITE.test( address ) ) {
		return address;
	}
	try {
		// A bare "example.com" has no scheme and is not a path, so it is
		// not an address anybody meant: URL throws and it is refused.
		return schemes.includes( new URL( address ).protocol )
			? address
			: undefined;
	} catch {
		return undefined;
	}
}
