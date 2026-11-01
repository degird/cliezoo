/**
 * The composer: one box for everything people write to each other.
 *
 * A message in a conversation, a comment on a task and a note on a file are
 * written here, so the box behaves the same everywhere: the same marks, the
 * same @mentions, the same way to attach a file, the same "send it tomorrow
 * morning". A module says what it is writing on and what to do with the
 * result; the composer does the rest.
 */
import {
	useEffect,
	useId,
	useRef,
	useState,
	type ClipboardEvent,
	type DragEvent,
	type KeyboardEvent,
} from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	AtSign,
	ChevronDown,
	ChevronUp,
	Clock,
	Film,
	Image as ImageIcon,
	MessageSquareText,
	Mic,
	Paperclip,
	Plus,
	Send,
	Smile,
	X,
} from 'lucide-react';
import {
	api,
	formatBytes,
	formatTime,
	getBoot,
	useQuery,
	type ApiError,
} from '@cliezoo/data';
import {
	AriaDialog,
	Button,
	ConfirmDialog,
	Dialog,
	DialogTrigger,
	IconButton,
	Menu,
	MenuItem,
	MenuTrigger,
	Popover,
	TextField,
	Toolbar,
	cx,
	toast,
} from '@cliezoo/ui';
import { Slot } from '../slot';
import { suggestionsLabel } from '../announce';
import { AiMenu } from '../ai/AiMenu';
import { placeOf } from '../ai/useAi';
import { uploadTo } from '../record/upload';
import {
	EmojiPanel,
	GifPanel,
	SavedRepliesPanel,
	VoiceRecorder,
} from './ComposerTools';
import { applyFormat, mentionAt, toHtml } from './markdown';
import { fromLocalInput, sendTimes } from './schedule';
import { senderZone } from './zone';

export interface ComposerMessage {
	/** The message as HTML. */
	body: string;
	internal: boolean;
	/** Uids of the files it carries. */
	files: string[];
	/** When to send it (ISO), for a message held for later. */
	send_at?: string;
}

interface Pending {
	key: string;
	name: string;
	size: number;
	uid?: string;
	progress: number;
	failed?: boolean;
	/** Kept so a failed upload can be tried again from the chip. */
	file: File;
}

interface Person {
	uid: string;
	name: string;
	kind?: string;
}

export interface ComposerProps {
	/** What it is written on: entity name and uid (files are put there). */
	type: string;
	uid: string;
	/** Sends it. Throwing keeps the draft. */
	onSend: ( message: ComposerMessage ) => Promise< void >;
	/** Offer the Note tab (the team only). */
	canNote?: boolean;
	/** Offer "Send ▾" for later. */
	canSchedule?: boolean;
	/** Offer attachments and voice notes. */
	canAttach?: boolean;
	placeholder?: string;
	/** What is being edited, as typed text. */
	initial?: string;
	/** Text to start a new message with (a link to what it is about). */
	seed?: string;
	/** Start on the Note tab. */
	note?: boolean;
	/** Only a note will do here (an answer to a note is a note). */
	lockNote?: boolean;
	/** The label of the send button. */
	submitLabel?: string;
	/** Shown while editing: stop editing. */
	onCancel?: () => void;
	/** Where to keep an unsent draft, per record. */
	draftKey?: string;
	/** The message being answered, shown above the box. */
	replyTo?: { who: string; text: string } | null;
	onClearReply?: () => void;
	autoFocus?: boolean;
}

/**
 * How far an attachment has got, in a word or a number.
 *
 * @param one The attachment.
 */
function state( one: Pending ): string {
	if ( one.failed ) {
		return __( 'failed', 'cliezoo' );
	}
	if ( one.uid ) {
		return formatBytes( one.size );
	}
	return `${ Math.round( one.progress * 100 ) }%`;
}

/**
 * Reads a saved draft.
 *
 * @param key Where it is kept.
 */
function readDraft( key?: string ): string {
	if ( ! key ) {
		return '';
	}
	try {
		return window.localStorage.getItem( `cliezoo:draft:${ key }` ) ?? '';
	} catch {
		return '';
	}
}

/**
 * Keeps a draft, or forgets it.
 *
 * @param key  Where it is kept.
 * @param text The draft.
 */
function writeDraft( key: string | undefined, text: string ): void {
	if ( ! key ) {
		return;
	}
	try {
		if ( text.trim() ) {
			window.localStorage.setItem( `cliezoo:draft:${ key }`, text );
		} else {
			window.localStorage.removeItem( `cliezoo:draft:${ key }` );
		}
	} catch {
		// A private window or blocked storage: the draft lives as long as the page.
	}
}

/**
 * What each "send it later" option is called.
 *
 * Nine o'clock where? The dialog beside these explains the timezone
 * carefully and the menu above it said nothing at all.
 *
 * @param timezone The sender's timezone, or nothing for UTC.
 */
export function sendLaterLabels( timezone: string ): Record< string, string > {
	const zone = timezone || 'UTC';
	return {
		tomorrow: sprintf(
			/* translators: %s: a timezone name. */
			__( 'Tomorrow at 9:00 (%s)', 'cliezoo' ),
			zone
		),
		'two-days': sprintf(
			/* translators: %s: a timezone name. */
			__( 'In 2 days at 9:00 (%s)', 'cliezoo' ),
			zone
		),
		'next-week': sprintf(
			/* translators: %s: a timezone name. */
			__( 'Next Monday at 9:00 (%s)', 'cliezoo' ),
			zone
		),
	};
}

export function Composer( {
	type,
	uid,
	onSend,
	canNote = false,
	canSchedule = false,
	canAttach = true,
	placeholder,
	initial,
	seed,
	note = false,
	lockNote = false,
	submitLabel,
	onCancel,
	draftKey,
	replyTo,
	onClearReply,
	autoFocus,
}: ComposerProps ) {
	const box = useRef< HTMLTextAreaElement >( null );
	const picker = useRef< HTMLInputElement >( null );
	const images = useRef< HTMLInputElement >( null );
	const [ text, setText ] = useState(
		() =>
			initial ??
			// What it is about goes first; a draft already begun is kept.
			( seed
				? [ seed, readDraft( draftKey ) ]
						.filter( Boolean )
						.join( ' ' ) + ' '
				: readDraft( draftKey ) )
	);
	const [ chosenNote, setInternal ] = useState( note && canNote );
	const internal = chosenNote || ( lockNote && canNote );
	const ids = useId();
	const listId = `${ ids }-mentions`;
	const [ panel, setPanel ] = useState< '' | 'emoji' | 'gif' >( '' );
	// Files taken off while still on their way up: when they arrive, they go.
	const dropped = useRef( new Set< string >() );
	// What is in the box right now, for code that reads it after an await.
	const latest = useRef( { text: '', files: [] as Pending[] } );
	const [ files, setFiles ] = useState< Pending[] >( [] );
	const [ busy, setBusy ] = useState( false );
	const [ collapsed, setCollapsed ] = useState( false );
	const [ dragging, setDragging ] = useState( false );
	const [ custom, setCustom ] = useState( false );
	const [ when, setWhen ] = useState( '' );
	const [ mention, setMention ] = useState< {
		query: string;
		start: number;
	} | null >( null );
	const [ pick, setPick ] = useState( 0 );
	const timezone = senderZone();
	const editing = undefined !== initial;

	useEffect( () => {
		if ( ! editing ) {
			writeDraft( draftKey, text );
		}
	}, [ draftKey, editing, text ] );

	const people = useQuery( {
		queryKey: [ 'members', 'mention', mention?.query ?? '' ],
		enabled: null !== mention,
		staleTime: 30_000,
		queryFn: async () =>
			(
				await api.get< Person[] >( '/members', {
					q: mention?.query ?? '',
					limit: 8,
					fields: 'uid,name,kind',
				} )
			).data,
	} );
	const found = people.data ?? [];

	/**
	 * Replaces the selection with text and puts the caret after it.
	 *
	 * @param insert What to put in.
	 * @param from   Where to start replacing (the selection by default).
	 * @param to     Where to stop.
	 */
	const put = ( insert: string, from?: number, to?: number ) => {
		const el = box.current;
		const start = from ?? el?.selectionStart ?? text.length;
		const end = to ?? el?.selectionEnd ?? text.length;
		const next = text.slice( 0, start ) + insert + text.slice( end );
		setText( next );
		window.requestAnimationFrame( () => {
			el?.focus();
			el?.setSelectionRange(
				start + insert.length,
				start + insert.length
			);
		} );
	};

	const format = ( kind: Parameters< typeof applyFormat >[ 3 ] ) => {
		const el = box.current;
		const done = applyFormat(
			text,
			el?.selectionStart ?? text.length,
			el?.selectionEnd ?? text.length,
			kind
		);
		setText( done.text );
		window.requestAnimationFrame( () => {
			el?.focus();
			el?.setSelectionRange( done.start, done.end );
		} );
	};

	const choose = ( person: Person ) => {
		if ( ! mention ) {
			return;
		}
		const caret = box.current?.selectionStart ?? text.length;
		put( `@[${ person.name }](${ person.uid }) `, mention.start, caret );
		setMention( null );
	};

	/**
	 * Puts one attachment up, keeping its chip in step with it.
	 *
	 * @param key  Which chip it is.
	 * @param file The file itself.
	 */
	const upload = ( key: string, file: File ) => {
		setFiles( ( list ) =>
			list.map( ( one ) =>
				one.key === key ? { ...one, failed: false, progress: 0 } : one
			)
		);
		uploadTo(
			type,
			uid,
			file,
			( progress ) =>
				setFiles( ( list ) =>
					list.map( ( one ) =>
						one.key === key ? { ...one, progress } : one
					)
				),
			'message'
		)
			.then( ( made ) => {
				if ( dropped.current.has( key ) ) {
					api.delete( `/files/${ made.uid }` ).catch(
						() => undefined
					);
					return;
				}
				setFiles( ( list ) =>
					list.map( ( one ) =>
						one.key === key
							? { ...one, uid: made.uid, progress: 1 }
							: one
					)
				);
			} )
			.catch( ( error: unknown ) => {
				toast.error( ( error as ApiError ).message );
				setFiles( ( list ) =>
					list.map( ( one ) =>
						one.key === key ? { ...one, failed: true } : one
					)
				);
			} );
	};

	const attach = ( chosen: File[] ) => {
		if ( ! canAttach ) {
			return;
		}
		for ( const file of chosen ) {
			const key = `${ file.name }-${ file.size }-${ Math.random() }`;
			setFiles( ( list ) => [
				...list,
				{ key, name: file.name, size: file.size, progress: 0, file },
			] );
			upload( key, file );
		}
	};

	/**
	 * Sends a GIF the team already has.
	 *
	 * A message carries only files put on the same record by the person
	 * writing it, so a GIF from somewhere else is copied here rather than
	 * pointed at — pointing at it would show it to whoever reads this.
	 *
	 * @param gif      The GIF.
	 * @param gif.uid  Its uid.
	 * @param gif.name Its name.
	 */
	const reuse = async ( gif: { uid: string; name: string } ) => {
		try {
			const link = await api.get< { url: string } >(
				`/files/${ gif.uid }/link`
			);
			const blob = await ( await fetch( link.data.url ) ).blob();
			attach( [ new File( [ blob ], gif.name, { type: 'image/gif' } ) ] );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	const drop = ( key: string ) => {
		const gone = files.find( ( one ) => one.key === key );
		dropped.current.add( key );
		setFiles( ( list ) => list.filter( ( one ) => one.key !== key ) );
		// Taken off before it was sent, so nobody ever saw it: it goes.
		if ( gone?.uid ) {
			api.delete( `/files/${ gone.uid }` ).catch( () => undefined );
		}
	};

	latest.current = { text, files };
	const uploading = files.some( ( one ) => ! one.uid && ! one.failed );
	// A file that did not arrive is not carried by the message, so sending
	// now would send a message about an attachment nobody else can see:
	// the chip's "Try again" or "Remove" says which it is to be.
	const broken = files.some( ( one ) => one.failed );
	const ready = files
		.filter( ( one ) => one.uid )
		.map( ( one ) => one.uid as string );
	const empty = '' === text.trim() && ! ready.length;

	/**
	 * Sends it, and says whether it went.
	 *
	 * The answer matters to "Send it later": a dialog that shuts on the
	 * press is the only thing telling anybody it worked, and shutting it
	 * before the server has answered means a refused time is a toast over
	 * a dialog that has already gone, with the time itself lost.
	 *
	 * @param sendAt When to send it, for a message held for later.
	 */
	const send = async ( sendAt?: string ): Promise< boolean > => {
		if ( busy || empty || uploading || broken ) {
			return false;
		}
		const sentText = text;
		const sentFiles = new Set( ready );
		setBusy( true );
		try {
			await onSend( {
				body: toHtml( text ),
				internal,
				files: ready,
				...( sendAt ? { send_at: sendAt } : {} ),
			} );
			// Sending takes a moment — the screen reloads its lists behind
			// it — and what somebody types or attaches in those seconds is
			// the start of the next message, not part of this one.
			const kept =
				latest.current.text === sentText ? '' : latest.current.text;
			setText( kept );
			setFiles( ( now ) =>
				now.filter(
					( one ) =>
						// One that failed while this was going belongs to the
						// message just sent, and never arrived: leaving its
						// chip behind makes it look queued for the next one.
						! one.failed &&
						( ! one.uid || ! sentFiles.has( one.uid ) )
				)
			);
			writeDraft( draftKey, kept );
			if ( sendAt ) {
				toast.success(
					sprintf(
						/* translators: %s: a time. */
						__( 'It will go at %s.', 'cliezoo' ),
						formatTime( sendAt, timezone )
					)
				);
			}
			return true;
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
			return false;
		} finally {
			setBusy( false );
		}
	};

	// Discard is the only button here that takes something away, and it
	// takes the uploaded files with it: `api.delete` on each one, which no
	// amount of typing the message again brings back.
	const [ dropping, setDropping ] = useState( false );

	const discard = () => {
		for ( const one of files ) {
			dropped.current.add( one.key );
			if ( one.uid ) {
				api.delete( `/files/${ one.uid }` ).catch( () => undefined );
			}
		}
		setText( '' );
		setFiles( [] );
		writeDraft( draftKey, '' );
		onCancel?.();
	};

	const onKey = ( event: KeyboardEvent< HTMLTextAreaElement > ) => {
		if ( mention && found.length ) {
			if ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) {
				event.preventDefault();
				const step = 'ArrowDown' === event.key ? 1 : -1;
				setPick( ( n ) => ( n + step + found.length ) % found.length );
				return;
			}
			if ( 'Enter' === event.key || 'Tab' === event.key ) {
				event.preventDefault();
				choose( found[ Math.min( pick, found.length - 1 ) ] as Person );
				return;
			}
		}
		if ( 'Escape' === event.key && mention ) {
			event.preventDefault();
			setMention( null );
			return;
		}
		if ( 'Enter' === event.key && ( event.metaKey || event.ctrlKey ) ) {
			event.preventDefault();
			send();
			return;
		}
		if ( ( event.metaKey || event.ctrlKey ) && ! event.shiftKey ) {
			const key = event.key.toLowerCase();
			if ( 'b' === key || 'i' === key ) {
				event.preventDefault();
				format( 'b' === key ? 'bold' : 'italic' );
			}
		}
	};

	const onPaste = ( event: ClipboardEvent< HTMLTextAreaElement > ) => {
		const pasted = Array.from( event.clipboardData.files );
		if ( pasted.length && canAttach ) {
			event.preventDefault();
			attach( pasted );
		}
	};

	const onDrop = ( event: DragEvent< HTMLDivElement > ) => {
		event.preventDefault();
		setDragging( false );
		attach( Array.from( event.dataTransfer.files ) );
	};

	if ( collapsed ) {
		return (
			<div className="flex items-center gap-2 rounded-lg border border-line bg-raised px-3 py-2">
				<button
					type="button"
					className="min-w-0 flex-1 truncate text-start text-sm text-muted"
					onClick={ () => setCollapsed( false ) }
				>
					{ text.trim() ||
						placeholder ||
						__( 'Write a message', 'cliezoo' ) }
				</button>
				<IconButton
					size="sm"
					label={ __( 'Open the composer', 'cliezoo' ) }
					icon={ <ChevronUp size={ 15 } aria-hidden="true" /> }
					onPress={ () => setCollapsed( false ) }
				/>
			</div>
		);
	}

	const times = canSchedule ? sendTimes( new Date(), timezone ) : [];
	const timeLabels = sendLaterLabels( timezone );

	return (
		<div
			className={ cx(
				'relative rounded-lg border bg-raised shadow-sm',
				internal
					? 'border-warning/60 bg-warning/5'
					: 'border-line-strong',
				dragging && 'ring-2 ring-accent'
			) }
			onDragOver={ ( event ) => {
				if (
					canAttach &&
					event.dataTransfer.types.includes( 'Files' )
				) {
					event.preventDefault();
					setDragging( true );
				}
			} }
			onDragLeave={ () => setDragging( false ) }
			onDrop={ onDrop }
		>
			{ canNote && lockNote && (
				<p className="border-b border-line px-3 py-1.5 text-xs text-warning-fg">
					{ __(
						'An answer to an internal note is an internal note: only the team sees it.',
						'cliezoo'
					) }
				</p>
			) }
			{ canNote && ! lockNote && (
				<div
					role="group"
					aria-label={ __( 'Who can read it', 'cliezoo' ) }
					className="flex gap-1 border-b border-line px-2 pt-1.5"
				>
					{ [
						[ false, __( 'Message', 'cliezoo' ) ],
						[ true, __( 'Internal note', 'cliezoo' ) ],
					].map( ( [ value, label ] ) => (
						<button
							key={ String( value ) }
							type="button"
							aria-pressed={ internal === value }
							className={ cx(
								'-mb-px border-b-2 px-2 pb-1.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-focus',
								internal === value
									? 'border-accent text-fg'
									: 'border-transparent text-muted hover:text-fg'
							) }
							onClick={ () => setInternal( value as boolean ) }
						>
							{ label as string }
						</button>
					) ) }
					{ internal && (
						<span className="ms-auto self-center pb-1.5 text-xs text-warning-fg">
							{ __( 'Only the team sees notes.', 'cliezoo' ) }
						</span>
					) }
				</div>
			) }

			{ replyTo && (
				<div className="flex items-center gap-2 border-b border-line px-3 py-1.5 text-xs text-muted">
					<span className="min-w-0 flex-1 truncate">
						{ sprintf(
							/* translators: 1: a person, 2: what they said. */
							__( 'Replying to %1$s: %2$s', 'cliezoo' ),
							replyTo.who,
							replyTo.text
						) }
					</span>
					<IconButton
						size="sm"
						label={ __( 'Stop replying', 'cliezoo' ) }
						icon={ <X size={ 13 } aria-hidden="true" /> }
						onPress={ onClearReply }
					/>
				</div>
			) }

			<div className="relative">
				<textarea
					ref={ box }
					value={ text }
					rows={ editing ? 4 : 3 }
					// eslint-disable-next-line jsx-a11y/no-autofocus -- Opening an editor or a reply means writing in it.
					autoFocus={ autoFocus }
					aria-label={
						internal
							? __( 'Internal note', 'cliezoo' )
							: ( placeholder ?? __( 'Message', 'cliezoo' ) )
					}
					placeholder={
						internal
							? __( 'A note for the team…', 'cliezoo' )
							: ( placeholder ??
								__( 'Write a message…', 'cliezoo' ) )
					}
					aria-autocomplete="list"
					aria-haspopup="listbox"
					aria-controls={
						mention && found.length ? listId : undefined
					}
					aria-activedescendant={
						mention && found.length
							? `${ listId }-${ Math.min(
									pick,
									found.length - 1
								) }`
							: undefined
					}
					className="block max-h-80 min-h-[4.5rem] w-full resize-y bg-transparent px-3 py-2 text-sm text-fg outline-none placeholder:text-subtle"
					onChange={ ( event ) => {
						setText( event.target.value );
						const at = mentionAt(
							event.target.value,
							event.target.selectionStart
						);
						setMention( at );
						setPick( 0 );
					} }
					onKeyDown={ onKey }
					onPaste={ onPaste }
				/>
				{ mention && found.length > 0 && (
					<ul
						id={ listId }
						role="listbox"
						aria-label={ __( 'People to mention', 'cliezoo' ) }
						className="absolute bottom-full start-2 z-20 mb-1 w-64 overflow-hidden rounded-lg border border-line bg-raised py-1 shadow-lg"
					>
						{ found.map( ( person, i ) => (
							// An option in a combobox is never focused: the
							// keyboard picks it from the box, which owns the
							// arrow keys and Enter.
							// eslint-disable-next-line jsx-a11y/click-events-have-key-events
							<li
								key={ person.uid }
								id={ `${ listId }-${ i }` }
								role="option"
								aria-selected={ i === pick }
								className={ cx(
									'cursor-default px-3 py-1.5 text-sm',
									i === pick
										? 'bg-accent-subtle text-fg'
										: 'text-muted'
								) }
								// Mousedown keeps the caret in the box for a
								// pointer; a tap and a screen reader's own
								// click emulation only ever send click, so
								// both are listened for.
								onMouseDown={ ( event ) => {
									event.preventDefault();
									choose( person );
								} }
								onClick={ () => choose( person ) }
							>
								{ person.name }
								{ 'client' === person.kind && (
									<span className="ms-2 text-xs text-subtle">
										{ __( 'client', 'cliezoo' ) }
									</span>
								) }
							</li>
						) ) }
					</ul>
				) }
				<p className="sr-only" role="status">
					{ suggestionsLabel(
						!! mention,
						found.map( ( person ) => person.name ),
						pick
					) }
				</p>
				{ mention && people.isError && (
					<p
						role="alert"
						className="absolute bottom-full start-2 z-20 mb-1 rounded-lg border border-line bg-raised px-3 py-1.5 text-sm text-danger shadow-lg"
					>
						{ __(
							'Could not look people up. Try again.',
							'cliezoo'
						) }
					</p>
				) }
				{ dragging && (
					<div className="pointer-events-none absolute inset-0 grid place-items-center rounded bg-accent-subtle/80 text-sm font-medium text-accent-fg">
						{ __( 'Drop to attach', 'cliezoo' ) }
					</div>
				) }
			</div>

			{ files.length > 0 && (
				<ul className="flex flex-wrap gap-1.5 px-3 pb-2">
					{ files.map( ( one ) => (
						<li
							key={ one.key }
							className={ cx(
								'flex items-center gap-1.5 rounded border px-2 py-1 text-xs',
								one.failed
									? 'border-danger/60 text-danger'
									: 'border-line text-muted'
							) }
						>
							<Paperclip size={ 12 } aria-hidden="true" />
							<span className="max-w-40 truncate">
								{ one.name }
							</span>
							<span className="text-subtle">
								{ state( one ) }
							</span>
							{ one.failed && (
								<button
									type="button"
									aria-label={ sprintf(
										/* translators: %s: a file name. */
										__( 'Try sending %s again', 'cliezoo' ),
										one.name
									) }
									className="rounded font-medium text-danger underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-focus"
									onClick={ () =>
										upload( one.key, one.file )
									}
								>
									{ __( 'Try again', 'cliezoo' ) }
								</button>
							) }
							<button
								type="button"
								aria-label={ sprintf(
									/* translators: %s: the name of what is being removed. */
									__( 'Remove %s', 'cliezoo' ),
									one.name
								) }
								className="rounded text-subtle hover:text-fg focus-visible:outline-2 focus-visible:outline-focus"
								onClick={ () => drop( one.key ) }
							>
								<X size={ 12 } aria-hidden="true" />
							</button>
						</li>
					) ) }
				</ul>
			) }

			<input
				ref={ picker }
				type="file"
				multiple
				className="hidden"
				onChange={ ( event ) => {
					attach( Array.from( event.target.files ?? [] ) );
					event.target.value = '';
				} }
			/>
			<input
				ref={ images }
				type="file"
				accept="image/*"
				multiple
				className="hidden"
				onChange={ ( event ) => {
					attach( Array.from( event.target.files ?? [] ) );
					event.target.value = '';
				} }
			/>

			{ /* Arrow keys walk it, as a toolbar's are meant to. */ }
			<Toolbar
				aria-label={ __( 'Formatting and attachments', 'cliezoo' ) }
				className="flex flex-wrap items-center gap-0.5 border-t border-line px-1.5 py-1"
			>
				{ canAttach && (
					<IconButton
						size="sm"
						label={ __( 'Attach files', 'cliezoo' ) }
						icon={ <Paperclip size={ 15 } aria-hidden="true" /> }
						onPress={ () => picker.current?.click() }
					/>
				) }
				<DialogTrigger>
					<IconButton
						size="sm"
						label={ __( 'Emoji', 'cliezoo' ) }
						icon={ <Smile size={ 15 } aria-hidden="true" /> }
					/>
					<Popover placement="top start">
						<AriaDialog
							aria-label={ __( 'Emoji', 'cliezoo' ) }
							className="outline-none"
						>
							{ ( { close }: { close: () => void } ) => (
								<EmojiPanel
									onPick={ ( char ) => put( char ) }
									onClose={ close }
								/>
							) }
						</AriaDialog>
					</Popover>
				</DialogTrigger>
				{ canAttach && (
					<DialogTrigger>
						<IconButton
							size="sm"
							label={ __( 'GIF', 'cliezoo' ) }
							icon={ <Film size={ 15 } aria-hidden="true" /> }
						/>
						<Popover placement="top start">
							<AriaDialog
								aria-label={ __( 'GIF', 'cliezoo' ) }
								className="outline-none"
							>
								{ ( { close }: { close: () => void } ) => (
									<GifPanel
										onFile={ ( file ) =>
											attach( [ file ] )
										}
										onReuse={ ( gif ) => reuse( gif ) }
										onClose={ close }
									/>
								) }
							</AriaDialog>
						</Popover>
					</DialogTrigger>
				) }
				{ canAttach && (
					<DialogTrigger>
						<IconButton
							size="sm"
							label={ __( 'Record a voice note', 'cliezoo' ) }
							icon={ <Mic size={ 15 } aria-hidden="true" /> }
						/>
						<Popover placement="top start">
							<AriaDialog
								aria-label={ __( 'Voice note', 'cliezoo' ) }
								className="outline-none"
							>
								{ ( { close }: { close: () => void } ) => (
									<VoiceRecorder
										onDone={ ( file ) =>
											attach( [ file ] )
										}
										onClose={ close }
									/>
								) }
							</AriaDialog>
						</Popover>
					</DialogTrigger>
				) }
				<IconButton
					size="sm"
					label={ __( 'Mention somebody', 'cliezoo' ) }
					icon={ <AtSign size={ 15 } aria-hidden="true" /> }
					onPress={ () => {
						const caret =
							box.current?.selectionStart ?? text.length;
						const lead =
							caret > 0 && ! /\s/.test( text[ caret - 1 ] ?? '' )
								? ' '
								: '';
						put( `${ lead }@` );
						setMention( { query: '', start: caret + lead.length } );
					} }
				/>
				{ getBoot().user?.kind === 'staff' && (
					<DialogTrigger>
						<IconButton
							size="sm"
							label={ __( 'Saved replies', 'cliezoo' ) }
							icon={
								<MessageSquareText
									size={ 15 }
									aria-hidden="true"
								/>
							}
						/>
						<Popover placement="top start">
							<AriaDialog
								aria-label={ __( 'Saved replies', 'cliezoo' ) }
								className="outline-none"
							>
								{ ( { close }: { close: () => void } ) => (
									<SavedRepliesPanel
										draft={ text }
										onUse={ ( reply ) => put( reply ) }
										onClose={ close }
										on={ { type, uid } }
									/>
								) }
							</AriaDialog>
						</Popover>
					</DialogTrigger>
				) }
				<MenuTrigger>
					<IconButton
						size="sm"
						label={ __( 'Insert', 'cliezoo' ) }
						icon={ <Plus size={ 15 } aria-hidden="true" /> }
					/>
					<Menu
						placement="top start"
						onAction={ ( key ) => {
							const action = String( key );
							if ( 'image' === action ) {
								images.current?.click();
							} else if ( 'file' === action ) {
								picker.current?.click();
							} else if (
								'gif' === action ||
								'emoji' === action
							) {
								setPanel( action );
							} else {
								format(
									action as Parameters<
										typeof applyFormat
									>[ 3 ]
								);
							}
						} }
					>
						{ canAttach && (
							<MenuItem
								id="image"
								icon={
									<ImageIcon size={ 15 } aria-hidden="true" />
								}
							>
								{ __( 'Image', 'cliezoo' ) }
							</MenuItem>
						) }
						{ canAttach && (
							<MenuItem
								id="file"
								icon={
									<Paperclip size={ 15 } aria-hidden="true" />
								}
							>
								{ __( 'File', 'cliezoo' ) }
							</MenuItem>
						) }
						{ canAttach && (
							<MenuItem
								id="gif"
								icon={ <Film size={ 15 } aria-hidden="true" /> }
							>
								{ __( 'GIF', 'cliezoo' ) }
							</MenuItem>
						) }
						<MenuItem
							id="emoji"
							icon={ <Smile size={ 15 } aria-hidden="true" /> }
						>
							{ __( 'Emoji', 'cliezoo' ) }
						</MenuItem>
						<MenuItem id="bold">
							{ __( 'Bold', 'cliezoo' ) }
						</MenuItem>
						<MenuItem id="italic">
							{ __( 'Italic', 'cliezoo' ) }
						</MenuItem>
						<MenuItem id="quote">
							{ __( 'Quote', 'cliezoo' ) }
						</MenuItem>
						<MenuItem id="code">
							{ __( 'Code', 'cliezoo' ) }
						</MenuItem>
						<MenuItem id="codeblock">
							{ __( 'Code block', 'cliezoo' ) }
						</MenuItem>
						<MenuItem id="bullets">
							{ __( 'Bulleted list', 'cliezoo' ) }
						</MenuItem>
						<MenuItem id="numbers">
							{ __( 'Numbered list', 'cliezoo' ) }
						</MenuItem>
						<MenuItem id="heading">
							{ __( 'Heading', 'cliezoo' ) }
						</MenuItem>
						<MenuItem id="rule">
							{ __( 'Horizontal line', 'cliezoo' ) }
						</MenuItem>
					</Menu>
				</MenuTrigger>
				<AiMenu
					place={ placeOf( type ) }
					record={ editing ? undefined : { type, uid } }
					extras={ editing ? [] : [ 'summarise', 'draft_reply' ] }
					source={ () => {
						const el = box.current;
						const from = el?.selectionStart ?? 0;
						const to = el?.selectionEnd ?? 0;
						return from !== to
							? { text: text.slice( from, to ), selected: true }
							: { text, selected: false };
					} }
					apply={ ( draft, how ) => {
						// Offered as Undo: what the box held before the draft.
						const before = text;
						const undo = () => setText( before );
						const el = box.current;
						const from = el?.selectionStart ?? 0;
						const to = el?.selectionEnd ?? 0;
						const picked = from !== to;
						if ( 'replace' === how ) {
							if ( picked ) {
								put( draft, from, to );
							} else {
								put( draft, 0, text.length );
							}
							return undo;
						}
						// Inserted after what it was made from, or at the end.
						const at = picked ? to : text.length;
						const gap = text.slice( 0, at ).trim() ? '\n\n' : '';
						put( gap + draft, at, at );
						return undo;
					} }
				/>
				<Slot name="composer.actions" variant="chip" />

				<div className="ms-auto flex items-center gap-1">
					{ /* A keyboard shortcut, shown where there is a
					    keyboard. A phone has the Send button beside it. */ }
					<span className="hidden text-xs text-subtle sm:inline">
						{ __( 'Ctrl+Enter to send', 'cliezoo' ) }
					</span>
					{ ( ! empty || editing ) && (
						<Button
							size="sm"
							variant="ghost"
							onPress={ () =>
								empty ? discard() : setDropping( true )
							}
						>
							{ editing
								? __( 'Cancel', 'cliezoo' )
								: __( 'Discard', 'cliezoo' ) }
						</Button>
					) }
					{ ! editing && (
						<IconButton
							size="sm"
							label={ __( 'Collapse the composer', 'cliezoo' ) }
							icon={
								<ChevronDown size={ 15 } aria-hidden="true" />
							}
							onPress={ () => setCollapsed( true ) }
						/>
					) }
					<div className="flex">
						<Button
							size="sm"
							variant="primary"
							className={ cx( canSchedule && '!rounded-e-none' ) }
							isPending={ busy }
							isDisabled={ empty || uploading || broken }
							icon={ <Send size={ 14 } aria-hidden="true" /> }
							onPress={ () => send() }
						>
							{ submitLabel ??
								( internal
									? __( 'Add note', 'cliezoo' )
									: __( 'Send', 'cliezoo' ) ) }
						</Button>
						{ canSchedule && (
							<MenuTrigger>
								<IconButton
									size="sm"
									variant="primary"
									className="!rounded-s-none border-s border-accent-fg/20"
									label={ __( 'Send later', 'cliezoo' ) }
									isDisabled={
										empty || uploading || broken || busy
									}
									icon={
										<ChevronDown
											size={ 14 }
											aria-hidden="true"
										/>
									}
								/>
								<Menu
									placement="top end"
									onAction={ ( key ) => {
										if ( 'custom' === key ) {
											setCustom( true );
											return;
										}
										const chosen = times.find(
											( one ) => one.id === key
										);
										if ( chosen ) {
											send( chosen.at );
										}
									} }
								>
									{ times.map( ( one ) => (
										<MenuItem
											key={ one.id }
											id={ one.id }
											icon={
												<Clock
													size={ 15 }
													aria-hidden="true"
												/>
											}
										>
											{ timeLabels[ one.id ] }
										</MenuItem>
									) ) }
									<MenuItem id="custom">
										{ __( 'Pick a time…', 'cliezoo' ) }
									</MenuItem>
								</Menu>
							</MenuTrigger>
						) }
					</div>
				</div>
			</Toolbar>

			<Dialog
				isOpen={ '' !== panel }
				onOpenChange={ ( open ) => ! open && setPanel( '' ) }
				size="sm"
				title={
					'gif' === panel
						? __( 'GIF', 'cliezoo' )
						: __( 'Emoji', 'cliezoo' )
				}
			>
				{ 'gif' === panel ? (
					<GifPanel
						onFile={ ( file ) => attach( [ file ] ) }
						onReuse={ ( gif ) => reuse( gif ) }
						onClose={ () => setPanel( '' ) }
					/>
				) : (
					<EmojiPanel
						onPick={ ( char ) => put( char ) }
						onClose={ () => setPanel( '' ) }
					/>
				) }
			</Dialog>

			<Dialog
				isOpen={ custom }
				onOpenChange={ setCustom }
				size="sm"
				title={ __( 'Send it later', 'cliezoo' ) }
				description={ sprintf(
					/* translators: %s: a timezone name. */
					__( 'The time is in your timezone (%s).', 'cliezoo' ),
					timezone || 'UTC'
				) }
				footer={ ( close: () => void ) => (
					<>
						<Button variant="ghost" onPress={ close }>
							{ __( 'Cancel', 'cliezoo' ) }
						</Button>
						<Button
							variant="primary"
							isPending={ busy }
							isDisabled={ ! fromLocalInput( when, timezone ) }
							onPress={ async () => {
								const at = fromLocalInput( when, timezone );
								// Shut once it has gone, not once it has
								// been asked for: the dialog closing is the
								// only thing that says it worked.
								if ( at && ( await send( at ) ) ) {
									close();
								}
							} }
						>
							{ __( 'Schedule it', 'cliezoo' ) }
						</Button>
					</>
				) }
			>
				<TextField
					label={ __( 'When', 'cliezoo' ) }
					type="datetime-local"
					value={ when }
					onChange={ setWhen }
				/>
			</Dialog>

			<ConfirmDialog
				isOpen={ dropping }
				onOpenChange={ setDropping }
				title={
					editing
						? __( 'Stop editing this message?', 'cliezoo' )
						: __( 'Discard this message?', 'cliezoo' )
				}
				description={
					ready.length
						? sprintf(
								/* translators: %d: how many attached files. */
								_n(
									'What you have written goes, and the %d file attached to it is deleted with it.',
									'What you have written goes, and the %d files attached to it are deleted with it.',
									ready.length,
									'cliezoo'
								),
								ready.length
							)
						: __(
								'What you have written goes, and there is no way back to it.',
								'cliezoo'
							)
				}
				confirmLabel={
					editing
						? __( 'Stop editing', 'cliezoo' )
						: __( 'Discard it', 'cliezoo' )
				}
				onConfirm={ discard }
			/>
		</div>
	);
}
