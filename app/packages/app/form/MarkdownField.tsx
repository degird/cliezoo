/**
 * A long text written with formatting: bold, italic, lists, quotes, code,
 * links and emoji, in the same markdown the composer writes, with a preview
 * that shows it as it will read.
 *
 * What is typed is markdown; what is kept is the HTML the composer keeps
 * (`toHtml`), so it is sanitised like every message and reads the same
 * wherever it is shown. Older plain or HTML text opens as markdown.
 */
import { useId, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import {
	Bold,
	Code,
	Eye,
	Italic,
	Link2,
	List,
	ListOrdered,
	Pencil,
	Quote,
	Smile,
	Strikethrough,
} from 'lucide-react';
import { IconButton, Popover, Toolbar, cx } from '@cliezoo/ui';
import { applyFormat, fromHtml, toHtml } from '../composer/markdown';
import { MessageBody } from '../composer/MessageBody';
import { EmojiPanel } from '../composer/ComposerTools';

type Format = Parameters< typeof applyFormat >[ 3 ];

/**
 * Puts a link around what is selected, or the address itself when nothing is.
 *
 * @param text  The text.
 * @param start Selection start.
 * @param end   Selection end.
 * @param url   The address.
 */
export function linkAt(
	text: string,
	start: number,
	end: number,
	url: string
): { text: string; start: number; end: number } {
	const chosen = text.slice( start, end ) || url;
	const made = `[${ chosen }](${ url })`;
	return {
		text: text.slice( 0, start ) + made + text.slice( end ),
		start: start + 1,
		end: start + 1 + chosen.length,
	};
}

export function MarkdownField( {
	label,
	value,
	onChange,
	description,
	errorMessage,
	isDisabled,
}: {
	label?: string;
	value: string;
	onChange: ( next: string ) => void;
	description?: string;
	errorMessage?: string;
	isDisabled?: boolean;
} ) {
	const box = useRef< HTMLTextAreaElement >( null );
	const smile = useRef< HTMLButtonElement >( null );
	const [ looking, setLooking ] = useState( false );
	const [ emoji, setEmoji ] = useState( false );
	const id = useId();
	const help = useId();
	// The markdown being written, and the stored value it was last turned
	// into: a value that changes from outside (an AI draft, a reset) is
	// read again, one this field sent is not.
	const [ text, setText ] = useState( () => fromHtml( value ?? '' ) );
	const sent = useRef( value ?? '' );
	// The value last seen, kept in state rather than in the ref: a render
	// React throws away keeps a ref's change and loses the state's, and
	// that left a filled-in value (a saved email, an AI draft) showing as
	// an empty box.
	const [ seen, setSeen ] = useState( value ?? '' );
	if ( ( value ?? '' ) !== seen ) {
		setSeen( value ?? '' );
		if ( ( value ?? '' ) !== sent.current ) {
			setText( fromHtml( value ?? '' ) );
		}
	}
	const write = ( next: string ) => {
		setText( next );
		const html = '' === next.trim() ? '' : toHtml( next );
		sent.current = html;
		onChange( html );
	};

	const put = ( next: { text: string; start: number; end: number } ) => {
		write( next.text );
		window.requestAnimationFrame( () => {
			box.current?.focus();
			box.current?.setSelectionRange( next.start, next.end );
		} );
	};
	const format = ( what: Format ) => {
		const el = box.current;
		put(
			applyFormat(
				text,
				el?.selectionStart ?? text.length,
				el?.selectionEnd ?? text.length,
				what
			)
		);
	};
	const link = () => {
		// eslint-disable-next-line no-alert -- A one-line question needs no dialog.
		const url = window.prompt( __( 'Link to', 'cliezoo' ), 'https://' );
		if ( ! url || ! /^https?:\/\//i.test( url ) ) {
			return;
		}
		const el = box.current;
		put(
			linkAt(
				text,
				el?.selectionStart ?? text.length,
				el?.selectionEnd ?? text.length,
				url
			)
		);
	};
	const insert = ( char: string ) => {
		const el = box.current;
		const start = el?.selectionStart ?? text.length;
		const end = el?.selectionEnd ?? text.length;
		put( {
			text: text.slice( 0, start ) + char + text.slice( end ),
			start: start + char.length,
			end: start + char.length,
		} );
	};

	const tools: Array< [ Format, string, JSX.Element ] > = [
		[ 'bold', __( 'Bold', 'cliezoo' ), <Bold key="b" size={ 15 } /> ],
		[ 'italic', __( 'Italic', 'cliezoo' ), <Italic key="i" size={ 15 } /> ],
		[
			'strike',
			__( 'Strike through', 'cliezoo' ),
			<Strikethrough key="s" size={ 15 } />,
		],
		[ 'bullets', __( 'A list', 'cliezoo' ), <List key="l" size={ 15 } /> ],
		[
			'numbers',
			__( 'A numbered list', 'cliezoo' ),
			<ListOrdered key="n" size={ 15 } />,
		],
		[ 'quote', __( 'A quote', 'cliezoo' ), <Quote key="q" size={ 15 } /> ],
		[ 'code', __( 'Code', 'cliezoo' ), <Code key="c" size={ 15 } /> ],
	];

	return (
		<div className="space-y-1.5">
			{ !! label && (
				<label
					id={ `${ id }-label` }
					htmlFor={ id }
					className="block text-sm font-medium text-fg"
				>
					{ label }
				</label>
			) }
			<div
				className={ cx(
					'rounded-md border bg-raised',
					errorMessage ? 'border-danger' : 'border-line'
				) }
			>
				{ /* Arrow keys walk it, as a toolbar's are meant to. */ }
				<Toolbar
					className="flex flex-wrap items-center gap-0.5 border-b border-line p-1"
					aria-label={ __( 'Formatting', 'cliezoo' ) }
				>
					{ tools.map( ( [ what, name, icon ] ) => (
						<IconButton
							key={ what }
							size="sm"
							variant="ghost"
							label={ name }
							icon={ icon }
							isDisabled={ isDisabled || looking }
							onPress={ () => format( what ) }
						/>
					) ) }
					<IconButton
						size="sm"
						variant="ghost"
						label={ __( 'A link', 'cliezoo' ) }
						icon={ <Link2 size={ 15 } /> }
						isDisabled={ isDisabled || looking }
						onPress={ link }
					/>
					<IconButton
						ref={ smile }
						size="sm"
						variant="ghost"
						label={ __( 'An emoji', 'cliezoo' ) }
						icon={ <Smile size={ 15 } /> }
						isDisabled={ isDisabled || looking }
						onPress={ () => setEmoji( true ) }
					/>
					<IconButton
						size="sm"
						variant={ looking ? 'secondary' : 'ghost' }
						className="ms-auto"
						aria-pressed={ looking }
						label={
							looking
								? __( 'Write', 'cliezoo' )
								: __( 'See how it reads', 'cliezoo' )
						}
						icon={
							looking ? (
								<Pencil size={ 15 } />
							) : (
								<Eye size={ 15 } />
							)
						}
						onPress={ () => setLooking( ! looking ) }
					/>
				</Toolbar>
				{ looking ? (
					<div
						className="min-h-24 p-3"
						role="region"
						aria-labelledby={ label ? `${ id }-label` : undefined }
					>
						<MessageBody body={ value || '' } />
					</div>
				) : (
					<textarea
						ref={ box }
						id={ id }
						rows={ 6 }
						value={ text }
						disabled={ isDisabled }
						aria-describedby={
							errorMessage || description ? help : undefined
						}
						onChange={ ( event ) => write( event.target.value ) }
						onKeyDown={ ( event ) => {
							if (
								( event.metaKey || event.ctrlKey ) &&
								! event.shiftKey
							) {
								const key = event.key.toLowerCase();
								if ( 'b' === key || 'i' === key ) {
									event.preventDefault();
									format( 'b' === key ? 'bold' : 'italic' );
								}
							}
						} }
						aria-invalid={ !! errorMessage }
						className="block w-full resize-y bg-transparent p-3 text-sm text-fg outline-none"
					/>
				) }
			</div>
			<Popover
				triggerRef={ smile }
				isOpen={ emoji }
				onOpenChange={ setEmoji }
				placement="bottom start"
			>
				<EmojiPanel
					onPick={ ( char ) => {
						insert( char );
						setEmoji( false );
					} }
					onClose={ () => setEmoji( false ) }
				/>
			</Popover>
			{ !! errorMessage && (
				<p id={ help } className="text-xs text-danger">
					{ errorMessage }
				</p>
			) }
			{ ! errorMessage && !! description && (
				<p id={ help } className="text-xs text-muted">
					{ description }
				</p>
			) }
		</div>
	);
}
