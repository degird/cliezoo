/**
 * Webpack loader: keeps only the en-US strings in React Aria's
 * `intlStrings.mjs` modules. The other locales ship as separate small files
 * (build/aria-locales/{locale}.js, made by tools/scripts/aria-locales.js)
 * and are loaded only for sites in that language.
 *
 * @param {string} source Module source.
 * @return {string} Source without the non-English imports.
 */
module.exports = function ariaEnglishOnly( source ) {
	const dropped = new Set();
	const kept = [];
	for ( const line of source.split( '\n' ) ) {
		const match =
			/^import (\S+) from "[^"]+\/([a-z]{2}-[A-Z]{2})\.mjs";$/.exec(
				line
			);
		if ( match && match[ 2 ] !== 'en-US' ) {
			dropped.add( match[ 1 ] );
			continue;
		}
		const entry = /^\s*"[a-z]{2}-[A-Z]{2}": (\S+?),?$/.exec( line );
		if ( entry && dropped.has( entry[ 1 ] ) ) {
			continue;
		}
		kept.push( line );
	}
	return kept.join( '\n' );
};
