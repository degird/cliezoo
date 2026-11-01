/**
 * What the composer's buttons open: emoji, GIFs, saved replies and the
 * voice recorder.
 *
 * Each is a panel inside a popover, so it is reached by keyboard the same
 * way as by mouse, and closes with Escape back to where the person was
 * typing.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Mic, Pencil, Plus, Square, Trash2 } from 'lucide-react';
import { api, useQuery, useQueryClient, type ApiError } from '@cliezoo/data';
import {
	Button,
	ErrorState,
	IconButton,
	SearchField,
	Skeleton,
	TextField,
	cx,
	toast,
} from '@cliezoo/ui';
import { emojiGroups, findEmoji } from './emoji';
import { fromHtml, plain, toHtml } from './markdown';
import { Slot } from '../slot';

/** How many emoji are on a row of the picker. */
const EMOJI_COLUMNS = 8;

/**
 * Where an arrow key goes next in the emoji grid.
 *
 * The grid is one long run of buttons drawn eight to a row, so up and down
 * are eight along and the ends of the run stop rather than wrap: somebody
 * holding the down arrow should arrive at the last emoji, not back at the
 * first.
 *
 * @param at      Where it is now.
 * @param key     The key pressed.
 * @param count   How many emoji are shown.
 * @param columns How many to a row.
 */
export function nextEmoji(
	at: number,
	key: string,
	count: number,
	columns: number = EMOJI_COLUMNS
): number | null {
	const moves: Record< string, number > = {
		ArrowRight: at + 1,
		ArrowLeft: at - 1,
		ArrowDown: at + columns,
		ArrowUp: at - columns,
		Home: 0,
		End: count - 1,
	};
	if ( ! ( key in moves ) || count < 1 ) {
		return null;
	}
	return Math.min( Math.max( 0, moves[ key ] ), count - 1 );
}

/**
 * Picks an emoji.
 *
 * @param root0         Props.
 * @param root0.onPick  Called with the character.
 * @param root0.onClose Closes the panel.
 */
export function EmojiPanel( {
	onPick,
	onClose,
}: {
	onPick: ( char: string ) => void;
	onClose?: () => void;
} ) {
	const groups = useMemo( () => emojiGroups(), [] );
	const [ query, setQuery ] = useState( '' );
	const found = findEmoji( groups, query );
	const shown = query.trim()
		? [ { id: 'found', label: __( 'Found', 'cliezoo' ), emoji: found } ]
		: groups;

	// A few hundred emoji are a few hundred tab stops, which is a keyboard
	// trap with a nice face on it: the grid is one stop, and the arrow keys
	// move about inside it.
	const grid = useRef< HTMLDivElement >( null );
	const [ at, setAt ] = useState( 0 );
	const offsets: number[] = [];
	let count = 0;
	for ( const group of shown ) {
		offsets.push( count );
		count += group.emoji.length;
	}
	// Searching redraws the whole grid, so Tab goes back to its first one.
	useEffect( () => setAt( 0 ), [ query ] );

	const move = ( event: React.KeyboardEvent ) => {
		const to = nextEmoji( at, event.key, count );
		if ( null === to ) {
			return;
		}
		event.preventDefault();
		setAt( to );
		grid.current
			?.querySelectorAll< HTMLButtonElement >( '[data-emoji]' )
			[ to ]?.focus();
	};

	return (
		<div className="w-72 space-y-2 p-2">
			<SearchField
				label={ __( 'Find an emoji', 'cliezoo' ) }
				placeholder={ __( 'Find an emoji', 'cliezoo' ) }
				value={ query }
				onChange={ setQuery }
				// eslint-disable-next-line jsx-a11y/no-autofocus -- A panel somebody just opened to type in.
				autoFocus
			/>
			<div ref={ grid } className="max-h-64 space-y-2 overflow-y-auto">
				{ query.trim() && ! found.length && (
					<p className="p-2 text-sm text-muted">
						{ __( 'No emoji by that name.', 'cliezoo' ) }
					</p>
				) }
				{ shown.map( ( group, g ) => (
					<section key={ group.id } aria-label={ group.label }>
						<h4 className="px-1 pb-1 text-xs font-medium text-subtle">
							{ group.label }
						</h4>
						<div className="grid grid-cols-8 gap-0.5">
							{ group.emoji.map( ( one, i ) => {
								const here = offsets[ g ] + i;
								return (
									<button
										key={ group.id + one.char }
										type="button"
										data-emoji=""
										tabIndex={ here === at ? 0 : -1 }
										className="rounded p-1 text-lg leading-none hover:bg-sunken focus-visible:outline-2 focus-visible:outline-focus"
										onFocus={ () => setAt( here ) }
										onKeyDown={ move }
										onClick={ () => {
											onPick( one.char );
											onClose?.();
										} }
									>
										{ one.char }
									</button>
								);
							} ) }
						</div>
					</section>
				) ) }
			</div>
		</div>
	);
}

interface GifFile {
	uid: string;
	name: string;
}

/**
 * A GIF from the computer, or one already in the workspace.
 *
 * What is offered needs nobody else's service: the GIFs people keep, and
 * the ones the team has already sent.
 *
 * @param root0         Props.
 * @param root0.onFile  Called with a chosen file from the computer.
 * @param root0.onReuse Called with a GIF already uploaded.
 * @param root0.onClose Closes the panel.
 */
export function GifPanel( {
	onFile,
	onReuse,
	onClose,
}: {
	onFile: ( file: File ) => void;
	onReuse: ( gif: GifFile ) => void;
	onClose?: () => void;
} ) {
	const picker = useRef< HTMLInputElement >( null );
	const gifs = useQuery( {
		queryKey: [ 'files', 'gifs' ],
		staleTime: 60_000,
		queryFn: async () =>
			(
				await api.get< GifFile[] >( '/files', {
					filter: JSON.stringify( {
						field: 'mime',
						op: 'eq',
						value: 'image/gif',
					} ),
					limit: 24,
					fields: 'uid,name',
				} )
			).data,
	} );

	return (
		<div className="w-72 space-y-2 p-3">
			<Button
				size="sm"
				variant="secondary"
				className="w-full"
				onPress={ () => picker.current?.click() }
			>
				{ __( 'Choose a GIF from your computer', 'cliezoo' ) }
			</Button>
			<input
				ref={ picker }
				type="file"
				accept="image/gif"
				className="hidden"
				onChange={ ( event ) => {
					const chosen = event.target.files?.[ 0 ];
					event.target.value = '';
					if ( chosen ) {
						onFile( chosen );
						onClose?.();
					}
				} }
			/>
			<h4 className="text-xs font-medium text-subtle">
				{ __( 'Sent before', 'cliezoo' ) }
			</h4>
			{ gifs.isPending && <Skeleton className="h-16" /> }
			{ gifs.isError && (
				<ErrorState
					error={ gifs.error }
					onRetry={ () => gifs.refetch() }
				/>
			) }
			{ gifs.isSuccess && ! gifs.data.length && (
				<p className="text-sm text-muted">
					{ __(
						'GIFs the team sends show up here to use again.',
						'cliezoo'
					) }
				</p>
			) }
			<ul className="max-h-48 space-y-1 overflow-y-auto">
				{ ( gifs.data ?? [] )
					.filter(
						( gif, i, all ) =>
							all.findIndex(
								( one ) => one.name === gif.name
							) === i
					)
					.map( ( gif ) => (
						<li key={ gif.uid }>
							<button
								type="button"
								className="w-full truncate rounded px-2 py-1 text-start text-sm hover:bg-sunken focus-visible:outline-2 focus-visible:outline-focus"
								onClick={ () => {
									onReuse( gif );
									onClose?.();
								} }
							>
								{ gif.name }
							</button>
						</li>
					) ) }
			</ul>
		</div>
	);
}

export interface SavedReply {
	uid: string;
	name: string;
	body: string;
	editable: boolean;
}

/**
 * Saved replies: search, use, and keep new ones.
 *
 * @param root0         Props.
 * @param root0.draft   What is in the composer now, offered as a new reply.
 * @param root0.onUse   Called with the reply's text.
 * @param root0.onClose Closes the panel.
 * @param root0.on      What the message is written on, told to an add-on.
 * @param root0.on.type Its kind of record.
 * @param root0.on.uid  Which one.
 */
export function SavedRepliesPanel( {
	draft,
	onUse,
	onClose,
	on,
}: {
	draft: string;
	onUse: ( text: string ) => void;
	onClose?: () => void;
	on?: { type: string; uid: string };
} ) {
	const queries = useQueryClient();
	const [ query, setQuery ] = useState( '' );
	const [ editing, setEditing ] = useState< SavedReply | 'new' | null >(
		null
	);
	const [ name, setName ] = useState( '' );
	const [ body, setBody ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const replies = useQuery( {
		queryKey: [ 'saved-replies', query ],
		queryFn: async () =>
			( await api.get< SavedReply[] >( '/saved-replies', { q: query } ) )
				.data,
	} );
	const refresh = () =>
		queries.invalidateQueries( { queryKey: [ 'saved-replies' ] } );

	const open = ( reply: SavedReply | 'new' ) => {
		setEditing( reply );
		setName( 'new' === reply ? '' : reply.name );
		setBody( 'new' === reply ? draft : fromHtml( reply.body ) );
	};

	if ( editing ) {
		return (
			<form
				className="w-80 space-y-2 p-3"
				onSubmit={ async ( event ) => {
					event.preventDefault();
					setBusy( true );
					try {
						await api.post( '/saved-replies', {
							...( 'new' === editing
								? {}
								: { uid: editing.uid } ),
							name,
							body: toHtml( body ),
						} );
						await refresh();
						setEditing( null );
						toast.success( __( 'Reply saved.', 'cliezoo' ) );
					} catch ( error ) {
						toast.error( ( error as ApiError ).message );
					} finally {
						setBusy( false );
					}
				} }
			>
				<TextField
					label={ __( 'Name', 'cliezoo' ) }
					value={ name }
					onChange={ setName }
					isRequired
					// eslint-disable-next-line jsx-a11y/no-autofocus -- A panel somebody just opened to type in.
					autoFocus
				/>
				<TextField
					label={ __( 'Reply', 'cliezoo' ) }
					value={ body }
					onChange={ setBody }
					multiline
					rows={ 5 }
					isRequired
				/>
				<div className="flex justify-end gap-2">
					<Button
						size="sm"
						variant="ghost"
						onPress={ () => setEditing( null ) }
					>
						{ __( 'Back', 'cliezoo' ) }
					</Button>
					<Button
						size="sm"
						variant="primary"
						type="submit"
						isPending={ busy }
						isDisabled={ ! name.trim() || ! body.trim() }
					>
						{ __( 'Save', 'cliezoo' ) }
					</Button>
				</div>
			</form>
		);
	}

	return (
		<div className="w-80 space-y-2 p-3">
			<div className="flex items-center gap-2">
				<SearchField
					label={ __( 'Find a saved reply', 'cliezoo' ) }
					placeholder={ __( 'Find a saved reply', 'cliezoo' ) }
					className="flex-1"
					value={ query }
					onChange={ setQuery }
					// eslint-disable-next-line jsx-a11y/no-autofocus -- A panel somebody just opened to type in.
					autoFocus
				/>
				<IconButton
					size="sm"
					label={ __( 'Save a new reply', 'cliezoo' ) }
					icon={ <Plus size={ 15 } aria-hidden="true" /> }
					onPress={ () => open( 'new' ) }
				/>
			</div>
			{ /* An add-on's help choosing one; the person still reads it. */ }
			<Slot
				name="composer.saved_replies"
				badge={ false }
				props={ {
					type: on?.type ?? '',
					uid: on?.uid ?? '',
					draft,
					use: ( reply: SavedReply ) => {
						onUse( fromHtml( reply.body ) );
						onClose?.();
					},
				} }
			/>
			{ replies.isPending && <Skeleton className="h-16" /> }
			{ replies.isError && (
				<ErrorState
					error={ replies.error }
					onRetry={ () => replies.refetch() }
				/>
			) }
			{ replies.isSuccess && ! replies.data.length && (
				<p className="text-sm text-muted">
					{ query
						? __( 'No saved reply by that name.', 'cliezoo' )
						: __(
								'Keep the answers you give often, and use them in any message.',
								'cliezoo'
							) }
				</p>
			) }
			<ul className="max-h-64 space-y-1 overflow-y-auto">
				{ ( replies.data ?? [] ).map( ( reply ) => (
					<li
						key={ reply.uid }
						className="group flex items-start gap-1"
					>
						<button
							type="button"
							className="min-w-0 flex-1 rounded px-2 py-1.5 text-start hover:bg-sunken focus-visible:outline-2 focus-visible:outline-focus"
							onClick={ () => {
								onUse( fromHtml( reply.body ) );
								onClose?.();
							} }
						>
							<span className="block truncate text-sm font-medium text-fg">
								{ reply.name }
							</span>
							<span className="block truncate text-xs text-muted">
								{ plain( reply.body, 80 ) }
							</span>
						</button>
						{ reply.editable && (
							<>
								<IconButton
									size="sm"
									variant="ghost"
									label={ sprintf(
										/* translators: %s: a saved reply's name. */
										__( 'Change %s', 'cliezoo' ),
										reply.name
									) }
									icon={
										<Pencil
											size={ 13 }
											aria-hidden="true"
										/>
									}
									onPress={ () => open( reply ) }
								/>
								<IconButton
									size="sm"
									variant="ghost"
									label={ sprintf(
										/* translators: %s: the name of what is being removed. */
										__( 'Remove %s', 'cliezoo' ),
										reply.name
									) }
									icon={
										<Trash2
											size={ 13 }
											aria-hidden="true"
										/>
									}
									onPress={ async () => {
										try {
											await api.delete(
												`/saved-replies/${ reply.uid }`
											);
											await refresh();
											// A saved reply is the team's, not
											// only this person's, and it went
											// with no word at all. There is no
											// route back to a deleted one, so
											// Undo writes it again from what
											// the list still holds.
											toast.success(
												sprintf(
													/* translators: %s: a saved reply's name. */
													__(
														'%s removed.',
														'cliezoo'
													),
													reply.name
												),
												{
													action: {
														label: __(
															'Undo',
															'cliezoo'
														),
														onAction: () =>
															api
																.post(
																	'/saved-replies',
																	{
																		name: reply.name,
																		body: reply.body,
																	}
																)
																.then(
																	() =>
																		refresh(),
																	(
																		error: unknown
																	) =>
																		toast.error(
																			(
																				error as ApiError
																			 )
																				.message
																		)
																),
													},
												}
											);
										} catch ( error ) {
											toast.error(
												( error as ApiError ).message
											);
										}
									} }
								/>
							</>
						) }
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * The type of recording this browser can make, if any.
 */
function recordingType(): string | null {
	if ( 'undefined' === typeof window.MediaRecorder ) {
		return null;
	}
	for ( const type of [
		'audio/webm;codecs=opus',
		'audio/webm',
		'audio/ogg;codecs=opus',
		'audio/mp4',
	] ) {
		if ( window.MediaRecorder.isTypeSupported( type ) ) {
			return type;
		}
	}
	return '';
}

/**
 * Records a voice note.
 *
 * The recording stays on the device until it is sent: it is a file like any
 * other, attached to the message, so it is played, downloaded, moved and
 * deleted the way every attachment is.
 *
 * @param root0         Props.
 * @param root0.onDone  Called with the recording.
 * @param root0.onClose Closes the panel.
 */
export function VoiceRecorder( {
	onDone,
	onClose,
}: {
	onDone: ( file: File ) => void;
	onClose?: () => void;
} ) {
	const [ state, setState ] = useState< 'idle' | 'recording' | 'denied' >(
		'idle'
	);
	const [ seconds, setSeconds ] = useState( 0 );
	const recorder = useRef< MediaRecorder | null >( null );
	const chunks = useRef< Blob[] >( [] );
	const timer = useRef( 0 );
	const cancelled = useRef( false );
	const type = recordingType();

	useEffect(
		() => () => {
			cancelled.current = true;
			window.clearInterval( timer.current );
			recorder.current?.stream
				.getTracks()
				.forEach( ( track ) => track.stop() );
		},
		[]
	);

	if ( null === type ) {
		return (
			<p className="w-64 p-3 text-sm text-muted">
				{ __( 'This browser cannot record audio.', 'cliezoo' ) }
			</p>
		);
	}

	const start = async () => {
		try {
			const stream = await navigator.mediaDevices.getUserMedia( {
				audio: true,
			} );
			const made = new MediaRecorder(
				stream,
				type ? { mimeType: type } : undefined
			);
			chunks.current = [];
			made.ondataavailable = ( event ) => {
				if ( event.data.size ) {
					chunks.current.push( event.data );
				}
			};
			made.onstop = () => {
				stream.getTracks().forEach( ( track ) => track.stop() );
				if ( cancelled.current ) {
					return;
				}
				const mime = ( made.mimeType || 'audio/webm' ).split(
					';'
				)[ 0 ] as string;
				let extension = 'webm';
				if ( mime.includes( 'mp4' ) ) {
					extension = 'm4a';
				} else if ( mime.includes( 'ogg' ) ) {
					extension = 'ogg';
				}
				const stamp = new Date()
					.toISOString()
					.slice( 0, 16 )
					.replace( /[-:T]/g, '' );
				onDone(
					new File(
						chunks.current,
						`voice-note-${ stamp }.${ extension }`,
						{ type: mime }
					)
				);
				onClose?.();
			};
			made.start();
			recorder.current = made;
			setSeconds( 0 );
			setState( 'recording' );
			timer.current = window.setInterval(
				() => setSeconds( ( n ) => n + 1 ),
				1000
			);
		} catch {
			setState( 'denied' );
		}
	};

	const stop = () => {
		window.clearInterval( timer.current );
		recorder.current?.stop();
		recorder.current = null;
	};

	const clock = `${ Math.floor( seconds / 60 ) }:${ String(
		seconds % 60
	).padStart( 2, '0' ) }`;

	return (
		<div className="w-64 space-y-3 p-3 text-center">
			{ 'denied' === state && (
				<p className="text-sm text-danger">
					{ __(
						'The microphone is blocked. Allow it for this site in the browser, then try again.',
						'cliezoo'
					) }
				</p>
			) }
			{ 'recording' === state ? (
				<>
					<p className="text-2xl font-semibold tabular-nums text-fg">
						<span className="me-2 inline-block size-2.5 animate-pulse rounded-full bg-danger align-middle" />
						{ clock }
					</p>
					<Button
						variant="primary"
						icon={ <Square size={ 14 } aria-hidden="true" /> }
						onPress={ stop }
					>
						{ __( 'Stop and attach', 'cliezoo' ) }
					</Button>
				</>
			) : (
				<Button
					variant="primary"
					icon={ <Mic size={ 15 } aria-hidden="true" /> }
					onPress={ start }
				>
					{ __( 'Start recording', 'cliezoo' ) }
				</Button>
			) }
			<p className="text-xs text-subtle">
				{ sprintf(
					/* translators: %d: seconds recorded. */
					_n( '%d second', '%d seconds', seconds, 'cliezoo' ),
					seconds
				) }
			</p>
		</div>
	);
}

/**
 * A small, quiet class set for the toolbar's icon buttons.
 *
 * @param active Whether the tool is on.
 */
export function toolClass( active = false ): string {
	return cx(
		'rounded p-1.5 text-muted hover:bg-sunken hover:text-fg',
		active && 'bg-accent-subtle text-accent-fg'
	);
}
