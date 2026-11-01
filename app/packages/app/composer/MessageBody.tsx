/**
 * What a message says, shown.
 *
 * The server already keeps only the tags a message may have. This cleans
 * it again on the way in to the page, because a body is text other people
 * wrote and the page is where it would do harm; the two lists are the same
 * list. A comment written before the composer existed is plain text and is
 * shown as the text it is.
 */
import { useMemo } from 'react';
import DOMPurify from 'dompurify';
import { cx } from '@cliezoo/ui';
import { isHtml } from './markdown';

// A link in a message opens elsewhere and cannot reach back into the app.
DOMPurify.addHook( 'afterSanitizeAttributes', ( node ) => {
	if ( 'A' === node.tagName ) {
		node.setAttribute( 'target', '_blank' );
		node.setAttribute( 'rel', 'noopener noreferrer' );
	}
} );

const TAGS = [
	'p',
	'br',
	'strong',
	'b',
	'em',
	'i',
	's',
	'del',
	'code',
	'pre',
	'blockquote',
	'ul',
	'ol',
	'li',
	'h1',
	'h2',
	'h3',
	'h4',
	'h5',
	'h6',
	'hr',
	'a',
	'span',
	'mark',
];

/** The look of the few tags a message has. */
const PROSE = cx(
	'break-words text-sm text-fg',
	'[&_p+p]:mt-2 [&_p]:leading-relaxed',
	'[&_a]:text-accent-fg [&_a]:underline',
	'[&_ul]:my-1 [&_ul]:list-disc [&_ul]:ps-5 [&_ol]:my-1 [&_ol]:list-decimal [&_ol]:ps-5',
	'[&_blockquote]:my-1 [&_blockquote]:border-s-2 [&_blockquote]:border-line-strong [&_blockquote]:ps-3 [&_blockquote]:text-muted',
	'[&_code]:rounded [&_code]:bg-sunken [&_code]:px-1 [&_code]:text-[0.85em]',
	'[&_pre]:my-1 [&_pre]:overflow-x-auto [&_pre]:rounded [&_pre]:bg-sunken [&_pre]:p-2 [&_pre_code]:bg-transparent [&_pre_code]:p-0',
	'[&_h3]:font-semibold [&_h4]:font-semibold [&_h5]:font-semibold',
	'[&_hr]:my-2 [&_hr]:border-line',
	'[&_[data-mention]]:rounded [&_[data-mention]]:bg-accent-subtle [&_[data-mention]]:px-1 [&_[data-mention]]:font-medium [&_[data-mention]]:text-accent-fg'
);

export function MessageBody( {
	body,
	className,
}: {
	body: string;
	className?: string;
} ) {
	const html = useMemo(
		() =>
			isHtml( body )
				? DOMPurify.sanitize( body, {
						ALLOWED_TAGS: TAGS,
						ALLOWED_ATTR: [
							'href',
							'target',
							'rel',
							'start',
							'data-mention',
							'data-id',
						],
					} )
				: null,
		[ body ]
	);

	if ( null === html ) {
		return (
			<p className={ cx( 'whitespace-pre-wrap', PROSE, className ) }>
				{ body }
			</p>
		);
	}
	return (
		<div
			className={ cx( PROSE, className ) }
			// Cleaned above with the same list of tags the server keeps.

			dangerouslySetInnerHTML={ { __html: html } }
		/>
	);
}
