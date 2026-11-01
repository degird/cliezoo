/**
 * A text block's formatted words, drawn: elements built from what
 * RichText.php kept, never HTML set from a string, and no editor behind it —
 * the client's page carries a few lines rather than a word processor.
 */
import { Fragment, type CSSProperties, type ReactNode } from 'react';
import { safeHref } from '@cliezoo/data';

export type RichNode = {
	type: string;
	text?: string;
	attrs?: Record< string, unknown >;
	marks?: Array< { type: string; attrs?: Record< string, unknown > } >;
	content?: RichNode[];
};

const MONO = 'ui-monospace, SFMono-Regular, Menlo, monospace';

/**
 * One run of words in its marks.
 *
 * @param node A text node.
 */
function run( node: RichNode ): ReactNode {
	let out: ReactNode = node.text ?? '';
	const style: CSSProperties = {};
	for ( const mark of node.marks ?? [] ) {
		const attrs = mark.attrs ?? {};
		switch ( mark.type ) {
			case 'bold':
				out = <strong>{ out }</strong>;
				break;
			case 'italic':
				out = <em>{ out }</em>;
				break;
			case 'underline':
				out = <u>{ out }</u>;
				break;
			case 'strike':
				out = <s>{ out }</s>;
				break;
			case 'code':
				out = (
					<code className="rounded bg-sunken px-1 text-[0.9em]">
						{ out }
					</code>
				);
				break;
			case 'superscript':
				out = <sup>{ out }</sup>;
				break;
			case 'subscript':
				out = <sub>{ out }</sub>;
				break;
			case 'link': {
				const href = safeHref( String( attrs.href ?? '' ) );
				out = href ? (
					<a
						href={ href }
						rel="noopener noreferrer"
						target="_blank"
						className="text-accent underline"
					>
						{ out }
					</a>
				) : (
					out
				);
				break;
			}
			case 'textStyle':
				if ( attrs.color ) {
					style.color = String( attrs.color );
				}
				if ( attrs.fontSize ) {
					style.fontSize = String( attrs.fontSize );
				}
				if ( attrs.fontFamily ) {
					style.fontFamily = MONO;
				}
				break;
		}
	}
	return Object.keys( style ).length ? (
		<span style={ style }>{ out }</span>
	) : (
		out
	);
}

/**
 * A node and what is in it.
 *
 * @param node The node.
 * @param key  Its place among its siblings.
 */
function draw( node: RichNode, key: number ): ReactNode {
	const inner = ( node.content ?? [] ).map( draw );
	switch ( node.type ) {
		case 'text':
			return <Fragment key={ key }>{ run( node ) }</Fragment>;
		case 'hardBreak':
			return <br key={ key } />;
		case 'paragraph': {
			const align = node.attrs?.textAlign as
				CSSProperties[ 'textAlign' ] | undefined;
			const indent = Number( node.attrs?.indent ?? 0 );
			return (
				<p
					key={ key }
					className="mt-2 min-h-[1em] first:mt-0"
					style={ {
						...( align ? { textAlign: align } : {} ),
						...( indent
							? { marginInlineStart: `${ indent * 1.5 }em` }
							: {} ),
					} }
				>
					{ inner }
				</p>
			);
		}
		case 'bulletList':
			return (
				<ul key={ key } className="mt-2 list-disc ps-5">
					{ inner }
				</ul>
			);
		case 'orderedList':
			return (
				<ol
					key={ key }
					className="mt-2 list-decimal ps-5"
					start={ Number( node.attrs?.start ?? 1 ) }
				>
					{ inner }
				</ol>
			);
		case 'listItem':
			return <li key={ key }>{ inner }</li>;
		default:
			return <Fragment key={ key }>{ inner }</Fragment>;
	}
}

export function RichDoc( {
	doc,
	style,
}: {
	doc: RichNode;
	style?: CSSProperties;
} ) {
	return (
		<div className="mt-3 text-sm text-fg first:mt-0" style={ style }>
			{ ( doc.content ?? [] ).map( draw ) }
		</div>
	);
}
