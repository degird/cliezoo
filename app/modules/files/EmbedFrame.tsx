/**
 * An embed on screen, and a link's card.
 *
 * An embed shows its page in a frame at the height or proportions it was
 * given — unless its site refuses frames, or it was set to open in a new
 * window, in which case it is a card that opens it. A link is always a
 * card: its picture, title, summary and site, from the page itself.
 */
import { __, sprintf } from '@wordpress/i18n';
import { ExternalLink, Link2, TriangleAlert } from 'lucide-react';
import { safeSrc } from '@cliezoo/data';
import { cx } from '@cliezoo/ui';
import { frameable, type EmbedFile } from './embedBits';

const RATIO: Record< string, string > = {
	'16:9': 'aspect-video',
	'4:3': 'aspect-[4/3]',
	'1:1': 'aspect-square',
};

/**
 * A page from another site as a card that opens it.
 *
 * @param root0         Props.
 * @param root0.file    The link or embed.
 * @param root0.compact A smaller card, for a list.
 */
export function LinkCard( {
	file,
	compact = false,
}: {
	file: EmbedFile;
	compact?: boolean;
} ) {
	const preview = file.preview ?? {};
	const href = String( file.url ?? '' );
	let host = '';
	try {
		host = new URL( href ).host;
	} catch {
		host = '';
	}
	const safe = /^https?:\/\//i.test( href );
	// The picture and the icon are scraped off somebody else's page, so
	// they are checked like any other stored address before the browser
	// is asked to fetch them.
	const image = safeSrc( preview.image );
	const icon = safeSrc( preview.icon );
	return (
		<a
			href={ safe ? href : undefined }
			target="_blank"
			rel="noopener noreferrer"
			className={ cx(
				'flex gap-3 rounded-lg border border-line bg-bg text-start hover:border-accent',
				compact ? 'p-2' : 'p-3'
			) }
		>
			{ !! image && ! compact && (
				<img
					src={ image }
					alt=""
					className="h-20 w-32 shrink-0 rounded-md object-cover"
					loading="lazy"
					referrerPolicy="no-referrer"
					onError={ ( event ) => {
						event.currentTarget.style.display = 'none';
					} }
				/>
			) }
			<span className="min-w-0 flex-1">
				<span className="flex items-center gap-1.5 text-xs text-muted">
					{ icon ? (
						<img
							src={ icon }
							alt=""
							className="size-4 rounded-sm"
							loading="lazy"
							referrerPolicy="no-referrer"
							// A site without an icon: nothing, rather than a
							// broken picture.
							onError={ ( event ) => {
								event.currentTarget.style.display = 'none';
							} }
						/>
					) : (
						<Link2 size={ 13 } aria-hidden="true" />
					) }
					<span className="truncate">{ preview.site || host }</span>
				</span>
				<span className="mt-0.5 block truncate text-sm font-medium text-fg">
					{ preview.title || file.name }
				</span>
				{ ! compact &&
					!! ( preview.description || file.description ) && (
						<span className="mt-0.5 line-clamp-2 block text-xs text-muted">
							{ file.description || preview.description }
						</span>
					) }
			</span>
			<ExternalLink
				size={ 15 }
				className="mt-0.5 shrink-0 text-subtle"
				aria-hidden="true"
			/>
		</a>
	);
}

/**
 * An embed, framed or as a card.
 *
 * @param root0      Props.
 * @param root0.file The embed.
 */
export function EmbedFrame( { file }: { file: EmbedFile } ) {
	const address = file.embed_url || file.url;
	const blocked = !! file.url_check?.blocked;
	if ( file.open_new_window || blocked || ! frameable( address ) ) {
		return (
			<div className="space-y-2">
				<LinkCard file={ file } />
				{ blocked && (
					<p className="flex items-start gap-1.5 text-xs text-muted">
						<TriangleAlert
							size={ 14 }
							className="mt-0.5 shrink-0 text-warning"
							aria-hidden="true"
						/>
						{ __(
							'This site does not allow itself to be shown inside other sites, so it opens in a new window.',
							'cliezoo'
						) }
					</p>
				) }
			</div>
		);
	}
	const ratio = RATIO[ String( file.embed_aspect ?? '' ) ];
	// The sandbox omits allow-popups-to-escape-sandbox: a framed site may
	// open a window, but that window keeps these same limits. The doc
	// reader's frame has said so since it was written.
	return (
		<iframe
			src={ String( address ) }
			title={ sprintf(
				/* translators: %s: the embed's name. */
				__( 'Embedded: %s', 'cliezoo' ),
				file.name
			) }
			className={ cx(
				'w-full rounded-md border border-line bg-bg',
				ratio
			) }
			style={
				ratio
					? undefined
					: { height: Number( file.embed_height ) || 500 }
			}
			sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-presentation"
			referrerPolicy="strict-origin-when-cross-origin"
			allow="fullscreen; clipboard-write; encrypted-media; picture-in-picture"
			loading="lazy"
		/>
	);
}
