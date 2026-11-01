/**
 * What people type, and what a message is stored as.
 *
 * The composer is a text box, not a rich-text editor: it is the same box on
 * a phone, with a screen reader and with a keyboard, and it costs nothing to
 * load. The formatting people use in a chat — bold, a list, a quote, a bit
 * of code, a heading, somebody's name — is the handful of marks every chat
 * app already taught them, and the insert menu writes those marks for anybody
 * who has not learnt them.
 *
 * A message is stored as HTML because everything else that reads a comment
 * — the server's sanitiser, the search index, the activity feed, an email —
 * reads HTML. So what is typed is turned into the small set of tags the
 * server keeps, and turned back again to be edited. Both directions live
 * here, next to each other, so they cannot drift apart.
 */

/** A mention as typed: `@[Ada Bell](01J…)`. */
const MENTION = /@\[([^\]\n]{1,120})\]\(([0-9A-HJKMNP-TV-Z]{26})\)/g;

/**
 * Marks a place set aside while a line is read: a character from the
 * private-use area, which nobody types. Built rather than written, so
 * no formatter can turn it into something else.
 */
const MARK = String.fromCharCode( 0xe000 );

/** Where a set-aside piece goes back in. */
const HELD = new RegExp( `${ MARK }(\\d+)${ MARK }`, 'g' );

/** A web address worth making a link of. */
const URL = /\bhttps?:\/\/[^\s<>"')\]*~]+[^\s<>"')\].,;:!?*~]/g;

/**
 * Escapes text for HTML.
 *
 * @param text Text.
 */
export function escape( text: string ): string {
	return text
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );
}

/**
 * Writes the marks inside a line: mentions, links, bold, italic, struck
 * through and code. Code is set aside first so nothing inside it is read
 * as a mark.
 *
 * @param line Text of one line, not yet escaped.
 */
export function inline( line: string ): string {
	const held: string[] = [];
	const hold = ( html: string ) => {
		held.push( html );
		return `${ MARK }${ held.length - 1 }${ MARK }`;
	};
	let out = line.replace( /`([^`\n]+)`/g, ( _match, code: string ) =>
		hold( `<code>${ escape( code ) }</code>` )
	);
	out = out.replace( MENTION, ( _match, name: string, uid: string ) =>
		hold(
			`<span class="mention" data-mention="" data-id="${ uid }">@${ escape(
				name
			) }</span>`
		)
	);
	out = out.replace( URL, ( url ) =>
		hold(
			`<a href="${ escape(
				url
			) }" target="_blank" rel="noopener noreferrer">${ escape(
				url
			) }</a>`
		)
	);
	out = escape( out )
		.replace( /\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>' )
		.replace( /(^|[^*\w])\*(?=\S)([^*\n]*?\S)\*(?!\w)/g, '$1<em>$2</em>' )
		.replace( /(^|[^_\w])_(?=\S)([^_\n]*?\S)_(?!\w)/g, '$1<em>$2</em>' )
		.replace( /~~([^~\n]+)~~/g, '<s>$1</s>' );

	return out.replace( HELD, ( _match, index: string ) =>
		String( held[ Number( index ) ] )
	);
}

type Block =
	| { kind: 'p' | 'quote'; lines: string[] }
	| { kind: 'ul' | 'ol'; items: string[]; start?: number }
	| { kind: 'code'; lines: string[] }
	| { kind: 'h'; level: number; text: string }
	| { kind: 'hr' };

/**
 * Splits what was typed into blocks.
 *
 * @param text What was typed.
 */
function blocks( text: string ): Block[] {
	const out: Block[] = [];
	const lines = text.replace( /\r\n?/g, '\n' ).split( '\n' );
	let current: Block | null = null;
	const close = () => {
		if ( current ) {
			out.push( current );
			current = null;
		}
	};
	for ( let i = 0; i < lines.length; i++ ) {
		const line = lines[ i ] as string;
		if ( /^```/.test( line ) ) {
			close();
			const code: string[] = [];
			i++;
			while (
				i < lines.length &&
				! /^```/.test( lines[ i ] as string )
			) {
				code.push( lines[ i ] as string );
				i++;
			}
			out.push( { kind: 'code', lines: code } );
			continue;
		}
		if ( '' === line.trim() ) {
			close();
			continue;
		}
		if ( /^(-{3,}|\*{3,}|_{3,})\s*$/.test( line ) ) {
			close();
			out.push( { kind: 'hr' } );
			continue;
		}
		const heading = /^(#{1,3})\s+(.+)$/.exec( line );
		if ( heading ) {
			close();
			out.push( {
				kind: 'h',
				level: ( heading[ 1 ] as string ).length,
				text: heading[ 2 ] as string,
			} );
			continue;
		}
		const bullet = /^\s*[-*•]\s+(.*)$/.exec( line );
		const numbered = /^\s*(?:\d+)[.)]\s+(.*)$/.exec( line );
		const first = /^\s*(\d+)[.)]\s/.exec( line );
		const quote = /^>\s?(.*)$/.exec( line );
		let kind: 'ul' | 'ol' | 'quote' | 'p' = 'p';
		if ( bullet ) {
			kind = 'ul';
		} else if ( numbered ) {
			kind = 'ol';
		} else if ( quote ) {
			kind = 'quote';
		}
		const content = ( bullet ?? numbered ?? quote )?.[ 1 ] ?? line;
		const open = current as Block | null;
		if ( ! open || open.kind !== kind ) {
			close();
			current =
				'ul' === kind || 'ol' === kind
					? {
							kind,
							items: [ content ],
							start: first ? Number( first[ 1 ] ) : 1,
						}
					: { kind, lines: [ content ] };
			continue;
		}
		if ( 'ul' === open.kind || 'ol' === open.kind ) {
			open.items.push( content );
		} else if ( 'p' === open.kind || 'quote' === open.kind ) {
			open.lines.push( content );
		}
	}
	close();
	return out;
}

/**
 * Turns what was typed into the HTML a message is kept as.
 *
 * @param text What was typed.
 */
export function toHtml( text: string ): string {
	return blocks( text )
		.map( ( block ) => {
			switch ( block.kind ) {
				case 'hr':
					return '<hr>';
				case 'h':
					// A heading in a message is a label, not a page title.
					return `<h${ block.level + 2 }>${ inline(
						block.text
					) }</h${ block.level + 2 }>`;
				case 'code':
					return `<pre><code>${ escape(
						block.lines.join( '\n' )
					) }</code></pre>`;
				case 'ul':
				case 'ol':
					return `<${ block.kind }${
						'ol' === block.kind && ( block.start ?? 1 ) !== 1
							? ` start="${ block.start }"`
							: ''
					}>${ block.items
						.map( ( item ) => `<li>${ inline( item ) }</li>` )
						.join( '' ) }</${ block.kind }>`;
				case 'quote':
					return `<blockquote><p>${ block.lines
						.map( inline )
						.join( '<br>' ) }</p></blockquote>`;
				default:
					return `<p>${ block.lines
						.map( inline )
						.join( '<br>' ) }</p>`;
			}
		} )
		.join( '' );
}

/**
 * Whether a stored body is HTML at all. Comments written before the
 * composer are plain text, and are shown as the text they are.
 *
 * @param body Stored body.
 */
export function isHtml( body: string ): boolean {
	return /<\/?(p|br|ul|ol|li|strong|em|b|i|s|code|pre|blockquote|h[1-6]|hr|a|span|div)\b/i.test(
		body
	);
}

/**
 * The marks for what is inside an element.
 *
 * @param node Element or text.
 */
function marks( node: Node ): string {
	let out = '';
	node.childNodes.forEach( ( child ) => {
		if ( 3 === child.nodeType ) {
			out += child.textContent ?? '';
			return;
		}
		if ( 1 !== child.nodeType ) {
			return;
		}
		const element = child as HTMLElement;
		const tag = element.tagName.toLowerCase();
		const inner = marks( element );
		if ( 'br' === tag ) {
			out += '\n';
		} else if ( 'strong' === tag || 'b' === tag ) {
			out += `**${ inner }**`;
		} else if ( 'em' === tag || 'i' === tag ) {
			out += `*${ inner }*`;
		} else if ( 's' === tag || 'del' === tag || 'strike' === tag ) {
			out += `~~${ inner }~~`;
		} else if ( 'code' === tag ) {
			out += `\`${ element.textContent ?? '' }\``;
		} else if ( 'span' === tag && element.hasAttribute( 'data-mention' ) ) {
			const uid = element.getAttribute( 'data-id' ) ?? '';
			const name = ( element.textContent ?? '' ).replace( /^@/, '' );
			out += uid ? `@[${ name }](${ uid })` : inner;
		} else {
			out += inner;
		}
	} );
	return out;
}

/**
 * Turns a stored message back into what can be typed, to edit it.
 *
 * @param html Stored body.
 */
export function fromHtml( html: string ): string {
	if ( ! isHtml( html ) ) {
		return html;
	}
	const doc = new DOMParser().parseFromString(
		`<body>${ html }</body>`,
		'text/html'
	);
	const out: string[] = [];
	doc.body.childNodes.forEach( ( child ) => {
		if ( 3 === child.nodeType ) {
			const text = ( child.textContent ?? '' ).trim();
			if ( text ) {
				out.push( text );
			}
			return;
		}
		if ( 1 !== child.nodeType ) {
			return;
		}
		const element = child as HTMLElement;
		const tag = element.tagName.toLowerCase();
		if ( 'hr' === tag ) {
			out.push( '---' );
		} else if ( /^h[1-6]$/.test( tag ) ) {
			const level = Math.min( 3, Math.max( 1, Number( tag[ 1 ] ) - 2 ) );
			out.push( `${ '#'.repeat( level ) } ${ marks( element ) }` );
		} else if ( 'pre' === tag ) {
			out.push( '```\n' + ( element.textContent ?? '' ) + '\n```' );
		} else if ( 'ul' === tag || 'ol' === tag ) {
			const items: string[] = [];
			const start = Number( element.getAttribute( 'start' ) ?? 1 ) || 1;
			element.querySelectorAll( ':scope > li' ).forEach( ( li, i ) => {
				items.push(
					`${ 'ol' === tag ? `${ start + i }.` : '-' } ${ marks( li ) }`
				);
			} );
			out.push( items.join( '\n' ) );
		} else if ( 'blockquote' === tag ) {
			out.push(
				marks( element )
					.trim()
					.split( '\n' )
					.map( ( line ) => `> ${ line }` )
					.join( '\n' )
			);
		} else {
			out.push( marks( element ) );
		}
	} );
	return out.join( '\n\n' );
}

/**
 * A message as one line of text, for a preview or a reply quote.
 *
 * @param body  Stored body.
 * @param limit Most characters.
 */
export function plain( body: string, limit = 140 ): string {
	const text = isHtml( body )
		? ( new DOMParser().parseFromString(
				`<body>${ body.replace( /</g, ' <' ) }</body>`,
				'text/html'
			).body.textContent ?? '' )
		: body;
	const flat = text.replace( /\s+/g, ' ' ).trim();
	return flat.length > limit ? `${ flat.slice( 0, limit - 1 ) }…` : flat;
}

/**
 * The @-word being typed at the caret, if there is one: what to look up
 * and where it starts.
 *
 * @param text  The whole draft.
 * @param caret Where the caret is.
 */
export function mentionAt(
	text: string,
	caret: number
): { query: string; start: number } | null {
	const before = text.slice( 0, caret );
	const match = /(^|\s)@([^\s@[\]()]{0,40})$/.exec( before );
	if ( ! match ) {
		return null;
	}
	return {
		query: match[ 2 ] as string,
		start: caret - ( match[ 2 ] as string ).length - 1,
	};
}

/**
 * Wraps the selection in marks, or puts a block mark at the start of each
 * selected line. Returns the new text and where the selection goes.
 *
 * @param text   The draft.
 * @param start  Selection start.
 * @param end    Selection end.
 * @param format What to insert.
 */
export function applyFormat(
	text: string,
	start: number,
	end: number,
	format:
		| 'bold'
		| 'italic'
		| 'strike'
		| 'code'
		| 'codeblock'
		| 'quote'
		| 'bullets'
		| 'numbers'
		| 'heading'
		| 'rule'
): { text: string; start: number; end: number } {
	const chosen = text.slice( start, end );
	const wrap = ( left: string, right = left, placeholder = '' ) => {
		const inner = chosen || placeholder;
		const next =
			text.slice( 0, start ) + left + inner + right + text.slice( end );
		return {
			text: next,
			start: start + left.length,
			end: start + left.length + inner.length,
		};
	};
	const lineStart = text.lastIndexOf( '\n', start - 1 ) + 1;
	const prefix = ( mark: ( i: number ) => string ) => {
		const block = text.slice( lineStart, end );
		const lines = block.split( '\n' );
		const marked = lines
			.map( ( line, i ) => mark( i ) + line )
			.join( '\n' );
		const next = text.slice( 0, lineStart ) + marked + text.slice( end );
		return {
			text: next,
			start: lineStart + mark( 0 ).length,
			end: lineStart + marked.length,
		};
	};
	switch ( format ) {
		case 'bold':
			return wrap( '**' );
		case 'italic':
			return wrap( '*' );
		case 'strike':
			return wrap( '~~' );
		case 'code':
			return wrap( '`' );
		case 'codeblock':
			return wrap(
				( start > 0 && '\n' !== text[ start - 1 ] ? '\n' : '' ) +
					'```\n',
				'\n```\n'
			);
		case 'quote':
			return prefix( () => '> ' );
		case 'bullets':
			return prefix( () => '- ' );
		case 'numbers':
			return prefix( ( i ) => `${ i + 1 }. ` );
		case 'heading':
			return prefix( () => '## ' );
		case 'rule': {
			const lead = start > 0 && '\n' !== text[ start - 1 ] ? '\n' : '';
			const insert = `${ lead }---\n`;
			const next = text.slice( 0, start ) + insert + text.slice( end );
			return {
				text: next,
				start: start + insert.length,
				end: start + insert.length,
			};
		}
	}
	return { text, start, end };
}
