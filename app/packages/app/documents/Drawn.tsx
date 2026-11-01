/**
 * How a document's blocks are drawn — the one copy of it.
 *
 * The studio's preview and the page the client opens import the same
 * component, because a preview drawn by different code is a preview that
 * lies. Everything a document can hold has a case here, and that is the whole
 * reason the engine keeps a closed list of block types.
 */
import { useId, type CSSProperties } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatDate, formatMoney } from '@cliezoo/data';
import { RichDoc, type RichNode } from './RichDoc';

export type Block = Record< string, unknown > & { type: string };

/** A block's look, as Documents::clean_style keeps it. */
export type Look = {
	align?: 'start' | 'center' | 'end' | 'justify';
	color?: string;
	color_opacity?: number;
	background?: string;
	background_opacity?: number;
	size?: number;
	font?: 'mono';
	weight?: 'bold';
	width?: number;
	padding?: number;
	border?: boolean;
};

/** What a band names, worked out when the document is read. */
export type Facts = {
	total?: number | null;
	currency?: string;
	delivery?: string;
	turnaround?: number;
};

/**
 * A colour at an opacity.
 *
 * @param hex     A #rrggbb colour.
 * @param opacity 0 to 100.
 */
function tint( hex: string, opacity = 100 ): string {
	if ( opacity >= 100 ) {
		return hex;
	}
	const [ r, g, b ] = [ 1, 3, 5 ].map( ( at ) =>
		parseInt( hex.slice( at, at + 2 ), 16 )
	);
	return `rgba(${ r }, ${ g }, ${ b }, ${ opacity / 100 })`;
}

/**
 * The text side of a block's look: colour, size, weight, family, alignment.
 *
 * @param look The block's look.
 */
export function textLook( look: Look | undefined ): CSSProperties {
	if ( ! look ) {
		return {};
	}
	return {
		...( look.color
			? { color: tint( look.color, look.color_opacity ) }
			: {} ),
		...( look.size ? { fontSize: `${ look.size }px` } : {} ),
		...( 'bold' === look.weight ? { fontWeight: 700 } : {} ),
		...( 'mono' === look.font
			? { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace' }
			: {} ),
		...( look.align ? { textAlign: look.align } : {} ),
	};
}

/**
 * The container side of a block's look: width, place across the page,
 * background, padding and border.
 *
 * @param look The block's look.
 */
export function boxLook( look: Look | undefined ): CSSProperties | null {
	if (
		! look ||
		! (
			look.background ||
			look.border ||
			undefined !== look.padding ||
			look.width
		)
	) {
		return null;
	}
	return {
		...( look.background
			? { background: tint( look.background, look.background_opacity ) }
			: {} ),
		...( undefined !== look.padding
			? { padding: `${ look.padding }px` }
			: { padding: '8px' } ),
		...( look.border
			? { border: '1px solid var(--cz-border-strong)', borderRadius: 4 }
			: {} ),
		...( look.width ? { width: `${ look.width }%` } : {} ),
		...( look.width && 'center' === look.align
			? { marginInline: 'auto' }
			: {} ),
		...( look.width && 'end' === look.align
			? { marginInlineStart: 'auto' }
			: {} ),
	};
}

/**
 * What a band shows, as label and value pairs.
 *
 * @param block The band.
 * @param facts What the document comes to, where the block was not given it.
 */
export function bandItems(
	block: Block,
	facts?: Facts
): Array< { label: string; value: string } > {
	const known = ( block.facts as Facts | undefined ) ?? facts ?? {};
	const show = ( block.show ?? [
		'timeframe',
		'delivery',
		'total',
	] ) as string[];
	const out: Array< { label: string; value: string } > = [];
	for ( const part of show ) {
		if ( 'timeframe' === part && String( block.timeframe ?? '' ) ) {
			out.push( {
				label: __( 'Timeframe', 'cliezoo' ),
				value: String( block.timeframe ),
			} );
		}
		if ( 'delivery' === part ) {
			if ( known.delivery ) {
				out.push( {
					label: __( 'Delivery', 'cliezoo' ),
					value: formatDate( known.delivery ),
				} );
			} else if ( ( known.turnaround ?? 0 ) > 0 ) {
				out.push( {
					label: __( 'Delivery', 'cliezoo' ),
					value: sprintf(
						/* translators: %d: a number of working days. */
						_n(
							'%d working day after acceptance',
							'%d working days after acceptance',
							Number( known.turnaround ),
							'cliezoo'
						),
						Number( known.turnaround )
					),
				} );
			}
		}
		if (
			'total' === part &&
			null !== known.total &&
			undefined !== known.total
		) {
			out.push( {
				label: __( 'Total', 'cliezoo' ),
				value: formatMoney( known.total, known.currency ?? '' ),
			} );
		}
	}
	return out;
}

/**
 * A link to somewhere else, said plainly.
 *
 * @param root0       Props.
 * @param root0.url   Where it goes.
 * @param root0.title What it is called.
 * @param root0.about A word about what it is.
 */
function Away( {
	url,
	title,
	about,
}: {
	url: string;
	title: string;
	about: string;
} ) {
	return (
		<p className="mt-4 rounded border border-line p-3 text-sm">
			<a
				href={ url }
				rel="noreferrer noopener"
				target="_blank"
				className="font-medium text-accent underline"
			>
				{ '' === title ? url : title }
			</a>
			<span className="ms-2 text-xs text-muted">{ about }</span>
		</p>
	);
}

/**
 * One block, drawn.
 *
 * Every type this engine stores has a case here. That is the whole reason the
 * list of types is closed: a block nothing can draw would be a gap in the
 * middle of a client's quote.
 *
 * @param root0       Props.
 * @param root0.block The block.
 * @param root0.money What currency its prices are in.
 */
type Row = Record< string, unknown >;

/**
 * An amount less a discount, as the site works it out (Documents::less).
 *
 * @param amount In minor units.
 * @param off    A percentage, or an amount in minor units.
 * @param how    percent or fixed.
 */
function less( amount: number, off: unknown, how: unknown ): number {
	const value = Number( off ?? 0 );
	if ( ! value || value <= 0 ) {
		return amount;
	}
	if ( 'fixed' === how ) {
		return Math.max( 0, amount - Math.round( value ) );
	}
	return Math.max(
		0,
		amount - Math.round( ( amount * Math.min( 100, value ) ) / 100 )
	);
}

/**
 * The tax on an amount (Documents::plus_tax).
 *
 * @param amount In minor units.
 * @param rate   A percentage.
 */
function taxOn( amount: number, rate: unknown ): number {
	const value = Number( rate ?? 0 );
	return value > 0 ? Math.round( ( amount * value ) / 100 ) : 0;
}

/**
 * What one priced line comes to: quantity times rate, less its own
 * discount, plus its own tax — the same as the stored value (PHP
 * Documents::line_total), so the page and the bill never disagree.
 *
 * @param row The line.
 */
export function lineTotal( row: Row ): number {
	const sum = less(
		Math.round( Number( row.quantity ?? 1 ) * Number( row.rate ?? 0 ) ),
		row.discount,
		row.discount_type ?? 'percent'
	);
	return sum + taxOn( sum, row.tax );
}

/**
 * A priced block in its parts (PHP Documents::block_parts).
 *
 * @param block   The block.
 * @param counted The rows that count, by position.
 */
export function blockParts(
	block: Block,
	counted: number[]
): { subtotal: number; discount: number; tax: number; total: number } {
	const rows = ( block.rows ?? [] ) as Row[];
	const subtotal = counted.reduce(
		( sum, at ) => sum + lineTotal( rows[ at ] ),
		0
	);
	const lessened = less(
		subtotal,
		block.discount,
		block.discount_type ?? 'percent'
	);
	const tax = taxOn( lessened, block.tax );
	return {
		subtotal,
		discount: subtotal - lessened,
		tax,
		total: Math.max( 0, lessened + tax ),
	};
}

/**
 * The rows of a priced block that count, as the client has it now: the
 * optional ones they put in, and of alternatives the one they chose.
 *
 * @param block The priced block.
 * @param picks The rows chosen, by position; none means as it was sent.
 */
export function pickedRows( block: Block, picks?: number[] ): number[] {
	const rows = ( block.rows ?? [] ) as Row[];
	if ( picks ) {
		return rows
			.map( ( _row, at ) => at )
			.filter(
				( at ) =>
					picks.includes( at ) ||
					( ! rows[ at ].optional && ! block.choose_one )
			);
	}
	if ( block.choose_one ) {
		const chosen = rows.findIndex( ( row ) => !! row.chosen );
		return rows.length ? [ Math.max( 0, chosen ) ] : [];
	}
	return rows
		.map( ( row, at ) => ( row.optional && ! row.chosen ? -1 : at ) )
		.filter( ( at ) => at >= 0 );
}

/**
 * A priced block: a table, and — while the client is deciding — the extras
 * they can put in and the alternatives they choose between.
 *
 * @param root0        Props.
 * @param root0.block  The block.
 * @param root0.money  Its currency.
 * @param root0.picks  The rows chosen, by position.
 * @param root0.onPick Told when the client changes them; none, and it is read-only.
 */
function Pricing( {
	block,
	money,
	picks,
	onPick,
}: {
	block: Block;
	money: string;
	picks?: number[];
	onPick?: ( rows: number[] ) => void;
} ) {
	const group = useId();
	const rows = ( block.rows ?? [] ) as Row[];
	const one = !! block.choose_one;
	const counted = pickedRows( block, picks );
	const parts = blockParts( block, counted );
	const choosing =
		!! onPick && ( one || rows.some( ( row ) => row.optional ) );
	const toggle = ( at: number ) => {
		if ( ! onPick ) {
			return;
		}
		if ( one ) {
			onPick( [ at ] );
			return;
		}
		const now = counted.filter( ( n ) => rows[ n ].optional );
		onPick(
			now.includes( at )
				? now.filter( ( n ) => n !== at )
				: [ ...now, at ]
		);
	};
	return (
		<section className="mt-6">
			{ one && (
				<p className="mb-2 text-sm text-muted">
					{ choosing
						? __( 'Choose one of these.', 'cliezoo' )
						: __( 'One of these.', 'cliezoo' ) }
				</p>
			) }
			<table className="w-full text-sm">
				<caption className="sr-only">
					{ __( 'What is included', 'cliezoo' ) }
				</caption>
				<thead>
					<tr className="border-b border-line text-start text-xs text-muted">
						<th scope="col" className="py-1 text-start">
							{ __( 'What', 'cliezoo' ) }
						</th>
						<th scope="col" className="py-1 text-end">
							{ __( 'Quantity', 'cliezoo' ) }
						</th>
						<th scope="col" className="py-1 text-end">
							{ __( 'Amount', 'cliezoo' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( row, at ) => {
						const title = String( row.title ?? '' );
						const pickable = choosing && ( one || !! row.optional );
						const inIt = counted.includes( at );
						return (
							<tr
								key={ at }
								className="border-b border-line last:border-b-0"
							>
								<td className="py-1.5">
									{ pickable ? (
										<span className="flex items-center gap-2">
											<input
												id={ `${ group }-${ at }` }
												type={
													one ? 'radio' : 'checkbox'
												}
												name={ one ? group : undefined }
												checked={ inIt }
												onChange={ () => toggle( at ) }
											/>
											<label
												htmlFor={ `${ group }-${ at }` }
											>
												{ title }
											</label>
										</span>
									) : (
										title
									) }
									{ !! row.optional && ! pickable && (
										<span className="ms-2 text-xs text-muted">
											{ inIt
												? __( 'included', 'cliezoo' )
												: __( 'optional', 'cliezoo' ) }
										</span>
									) }
								</td>
								<td className="py-1.5 text-end tabular-nums">
									{ String( row.quantity ?? 1 ) }
								</td>
								<td className="py-1.5 text-end tabular-nums">
									{ formatMoney( lineTotal( row ), money ) }
								</td>
							</tr>
						);
					} ) }
				</tbody>
				<tfoot>
					{ ( parts.discount > 0 || parts.tax > 0 ) &&
						[
							[ __( 'Subtotal', 'cliezoo' ), parts.subtotal ],
							...( parts.discount > 0
								? [
										[
											__( 'Discount', 'cliezoo' ),
											-parts.discount,
										],
									]
								: [] ),
							...( parts.tax > 0
								? [ [ __( 'Tax', 'cliezoo' ), parts.tax ] ]
								: [] ),
						].map( ( [ label, amount ] ) => (
							<tr key={ String( label ) }>
								<th
									scope="row"
									colSpan={ 2 }
									className="py-1 text-start font-normal text-muted"
								>
									{ label }
								</th>
								<td className="py-1 text-end tabular-nums">
									{ formatMoney( Number( amount ), money ) }
								</td>
							</tr>
						) ) }
					<tr>
						<th
							scope="row"
							colSpan={ 2 }
							className="py-2 text-start"
						>
							{ __( 'Total', 'cliezoo' ) }
						</th>
						<td
							className="py-2 text-end font-semibold tabular-nums"
							aria-live="polite"
						>
							{ formatMoney( parts.total, money ) }
						</td>
					</tr>
				</tfoot>
			</table>
		</section>
	);
}

/**
 * A signature block's heading: its own words, or its number, with whom it
 * is for ("Signature 2 — for the client").
 *
 * @param block The block.
 */
export function signatureHeading( block: Block ): string {
	const number = Number( block.number ?? 0 );
	const title = String( block.title ?? '' ).trim();
	const base =
		'' !== title
			? title
			: sprintf(
					/* translators: %d: the signature block's number. */
					__( 'Signature %d', 'cliezoo' ),
					number > 0 ? number : 1
				);
	if ( 'client' === block.role ) {
		return `${ base } — ${ __( 'for the client', 'cliezoo' ) }`;
	}
	if ( 'us' === block.role ) {
		return `${ base } — ${ __( 'for the studio', 'cliezoo' ) }`;
	}
	return base;
}

type DrawnProps = {
	block: Block;
	money: string;
	/** For a priced block: the rows the client has chosen. */
	picks?: number[];
	/** For a priced block the client is deciding on. */
	onPick?: ( rows: number[] ) => void;
	/** What a band names, where the block was not given it when read. */
	facts?: Facts;
};

export function Drawn( props: DrawnProps ) {
	const box = boxLook( props.block.style as Look | undefined );
	if ( ! box ) {
		return <DrawnBlock { ...props } />;
	}
	return (
		<div className="mt-3" style={ box }>
			<DrawnBlock { ...props } />
		</div>
	);
}

function DrawnBlock( { block, money, picks, onPick, facts }: DrawnProps ) {
	const text = String( block.text ?? '' );
	const look = textLook( block.style as Look | undefined );

	if ( 'heading' === block.type ) {
		return (
			<h2
				className="mt-6 text-xl font-semibold text-fg first:mt-0"
				style={ look }
			>
				{ text }
			</h2>
		);
	}
	if ( 'text' === block.type && block.doc ) {
		return <RichDoc doc={ block.doc as RichNode } style={ look } />;
	}
	if ( 'text' === block.type ) {
		return (
			<p
				className="mt-3 whitespace-pre-line text-sm text-fg first:mt-0"
				style={ look }
			>
				{ text }
			</p>
		);
	}
	if ( 'band' === block.type ) {
		const items = bandItems( block, facts );
		if ( ! items.length ) {
			return null;
		}
		const fill = ( block.style as Look | undefined )?.background;
		return (
			<dl
				className="mt-4 grid gap-px overflow-hidden rounded-lg sm:grid-flow-col sm:auto-cols-fr"
				style={ fill ? undefined : { background: 'var(--cz-border)' } }
			>
				{ items.map( ( item ) => (
					<div
						key={ item.label }
						className={ fill ? 'p-3' : 'bg-sunken p-3' }
					>
						<dt className="text-xs text-muted">{ item.label }</dt>
						<dd className="mt-0.5 text-base font-semibold text-fg">
							{ item.value }
						</dd>
					</div>
				) ) }
			</dl>
		);
	}
	if ( 'divider' === block.type ) {
		return <hr className="my-6 border-line" />;
	}
	if ( 'spacer' === block.type ) {
		return <div className="h-6" aria-hidden="true" />;
	}
	if ( 'cover' === block.type ) {
		return (
			<header className="mb-6 rounded-lg border border-line bg-raised p-6">
				<h2 className="text-2xl font-semibold text-fg">
					{ String( block.title ?? text ) }
				</h2>
				{ !! block.caption && (
					<p className="mt-1 text-sm text-muted">
						{ String( block.caption ) }
					</p>
				) }
			</header>
		);
	}
	if ( 'image' === block.type && block.url ) {
		return (
			<figure className="mt-4">
				{  }
				<img
					src={ String( block.url ) }
					alt={ String( block.caption ?? '' ) }
					className="w-full rounded"
				/>
				{ !! block.caption && (
					<figcaption className="mt-1 text-xs text-muted">
						{ String( block.caption ) }
					</figcaption>
				) }
			</figure>
		);
	}
	if ( 'terms' === block.type ) {
		return (
			<section className="mt-6 rounded border border-line p-4">
				<h3 className="text-sm font-semibold text-fg">
					{ String( block.title ?? __( 'Terms', 'cliezoo' ) ) }
				</h3>
				<p className="mt-2 whitespace-pre-line text-xs text-muted">
					{ text }
				</p>
			</section>
		);
	}
	if ( 'pricing' === block.type ) {
		return (
			<Pricing
				block={ block }
				money={ money }
				picks={ picks }
				onPick={ onPick }
			/>
		);
	}

	if ( 'page_break' === block.type ) {
		// Nothing to see on a screen; it matters when the page is printed.
		return <div className="break-after-page" aria-hidden="true" />;
	}
	// A video and an embed are both a link, and deliberately not a frame: a
	// framed player would have the client's browser talking to somebody else
	// the moment they opened a quote, which the client never agreed to and the
	// studio cannot agree to on their behalf.
	if ( 'video' === block.type ) {
		return (
			<Away
				url={ String( block.url ?? '' ) }
				title={ String( block.caption ?? '' ) }
				about={ __( 'a video, on another site', 'cliezoo' ) }
			/>
		);
	}
	if ( 'embed' === block.type ) {
		return (
			<Away
				url={ String( block.url ?? '' ) }
				title={ String( block.title ?? '' ) }
				about={ __( 'opens on another site', 'cliezoo' ) }
			/>
		);
	}
	if ( 'attachment' === block.type ) {
		return (
			<Away
				url={ String( block.url ?? '' ) }
				title={ String( block.title ?? '' ) }
				about={ __( 'attached to this document', 'cliezoo' ) }
			/>
		);
	}
	if ( 'columns' === block.type ) {
		const columns = ( block.columns ?? [] ) as Array<
			Record< string, unknown >
		>;
		return (
			<div className="mt-4 grid gap-4 sm:grid-cols-2">
				{ columns.map( ( column, at ) => (
					<div key={ at }>
						{ !! column.title && (
							<h3 className="text-sm font-semibold text-fg">
								{ String( column.title ) }
							</h3>
						) }
						<p className="mt-1 whitespace-pre-line text-sm text-fg">
							{ String( column.text ?? '' ) }
						</p>
					</div>
				) ) }
			</div>
		);
	}
	if ( 'table' === block.type ) {
		const head = ( block.head ?? [] ) as string[];
		const rows = ( block.rows ?? [] ) as string[][];
		return (
			<table className="mt-4 w-full text-sm">
				{ !! block.title && (
					<caption className="pb-2 text-start text-sm font-semibold text-fg">
						{ String( block.title ) }
					</caption>
				) }
				{ !! head.length && (
					<thead>
						<tr className="border-b border-line text-xs text-muted">
							{ head.map( ( cell, at ) => (
								<th
									key={ at }
									scope="col"
									className="py-1 text-start"
								>
									{ String( cell ) }
								</th>
							) ) }
						</tr>
					</thead>
				) }
				<tbody>
					{ rows.map( ( row, at ) => (
						<tr
							key={ at }
							className="border-b border-line last:border-b-0"
						>
							{ ( row ?? [] ).map( ( cell, cellAt ) => (
								<td key={ cellAt } className="py-1.5">
									{ String( cell ) }
								</td>
							) ) }
						</tr>
					) ) }
				</tbody>
			</table>
		);
	}
	if ( 'schedule' === block.type ) {
		const rows = ( block.rows ?? [] ) as Array< Record< string, unknown > >;
		return (
			<section className="mt-6">
				<h3 className="text-sm font-semibold text-fg">
					{ String(
						block.title ?? __( 'When it is paid', 'cliezoo' )
					) }
				</h3>
				<ol className="mt-2 flex flex-col gap-2">
					{ rows.map( ( row, at ) => (
						<li
							key={ at }
							className="flex flex-wrap justify-between gap-2 border-b border-line pb-2 text-sm last:border-b-0"
						>
							<span className="text-fg">
								{ String( row.what ?? '' ) }
								{ !! row.when && (
									<span className="ms-2 text-xs text-muted">
										{ String( row.when ) }
									</span>
								) }
							</span>
							<span className="tabular-nums text-fg">
								{ 'number' === typeof row.amount
									? formatMoney( Number( row.amount ), money )
									: `${ String( row.percent ?? '' ) }%` }
							</span>
						</li>
					) ) }
				</ol>
			</section>
		);
	}
	if ( 'timeline' === block.type ) {
		const rows = ( block.rows ?? [] ) as Array< Record< string, unknown > >;
		return (
			<ol className="mt-4 flex flex-col gap-3 border-s border-line ps-4">
				{ rows.map( ( row, at ) => (
					<li key={ at }>
						<p className="text-xs font-medium uppercase text-muted">
							{ String( row.when ?? '' ) }
						</p>
						<p className="text-sm text-fg">
							{ String( row.what ?? '' ) }
						</p>
					</li>
				) ) }
			</ol>
		);
	}
	if ( 'team' === block.type ) {
		const people = ( block.people ?? [] ) as Array<
			Record< string, unknown >
		>;
		return (
			<ul className="mt-4 grid gap-4 sm:grid-cols-3">
				{ people.map( ( one, at ) => (
					<li key={ at }>
						{ !! one.photo && (
							<img
								src={ String( one.photo ) }
								alt=""
								className="mb-2 h-16 w-16 rounded-full object-cover"
							/>
						) }
						<p className="text-sm font-medium text-fg">
							{ String( one.name ?? '' ) }
						</p>
						<p className="text-xs text-muted">
							{ String( one.role ?? '' ) }
						</p>
					</li>
				) ) }
			</ul>
		);
	}
	if ( 'testimonials' === block.type ) {
		const quotes = ( block.quotes ?? [] ) as Array<
			Record< string, unknown >
		>;
		return (
			<div className="mt-6 flex flex-col gap-4">
				{ quotes.map( ( one, at ) => (
					<blockquote
						key={ at }
						className="border-s-2 border-accent ps-4"
					>
						<p className="text-sm italic text-fg">
							{ String( one.said ?? '' ) }
						</p>
						<footer className="mt-1 text-xs text-muted">
							{ String( one.who ?? '' ) }
						</footer>
					</blockquote>
				) ) }
			</div>
		);
	}
	if ( 'signature' === block.type ) {
		// Where a signature goes on the printed page. Nobody signs here: a
		// signature made on a page anybody can open proves nothing, so the
		// signing happens on each signer's own link.
		return (
			<section className="mt-8">
				<h3 className="text-sm font-semibold text-fg">
					{ signatureHeading( block ) }
				</h3>
				<p className="mt-6 border-t border-line-strong pt-1 text-xs text-muted">
					{ String( block.who ?? __( 'Name and date', 'cliezoo' ) ) }
				</p>
			</section>
		);
	}
	if ( 'accept' === block.type ) {
		// A cue, not a second button. Two places to accept the same document
		// is how somebody accepts twice and nobody knows which one counted.
		return (
			<p className="mt-6 rounded border border-line bg-raised p-3 text-sm text-fg">
				{ String(
					block.text ??
						__(
							'When you are happy with this, say so at the foot of the page.',
							'cliezoo'
						)
				) }
			</p>
		);
	}

	// A block this page has no drawing for. Said plainly rather than left as
	// a gap: a client who can see something is missing can ask about it.
	return (
		<p className="mt-3 rounded border border-line p-3 text-xs text-muted">
			{ __(
				'Part of this document cannot be shown here. Ask the sender for it.',
				'cliezoo'
			) }
		</p>
	);
}
