/**
 * Looking at one file, and saying something about a particular part of it.
 *
 * A pin is a comment on the file carrying where it was put — a spot on a
 * picture, a page of a PDF, a moment in a video — so it is searched,
 * notified, resolved and exported by the machinery that already does that
 * for every other record. The viewer does not own a commenting system; it
 * owns a coordinate.
 *
 * Pictures, PDFs, video, audio and text are shown here; anything else is
 * offered as a download. What the browser is handed is a short-lived link
 * the server signs, never the API's credential.
 */
import { useEffect, useRef, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { copyText } from '../../packages/app/copy';
import {
	Check,
	ChevronLeft,
	ChevronRight,
	Copy,
	Download,
	ExternalLink,
	Maximize2,
	MessageSquarePlus,
	MoreHorizontal,
	Pencil,
	RotateCcw,
	Trash2,
	Undo2,
} from 'lucide-react';
import {
	api,
	formatFullDate,
	formatRelative,
	getBoot,
	useQuery,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import {
	Badge,
	Button,
	Dialog,
	ErrorState,
	IconButton,
	Menu,
	MenuItem,
	MenuTrigger,
	Skeleton,
	TextField,
	cx,
	toast,
} from '@cliezoo/ui';
import { Composer } from '../../packages/app/composer/Composer';
import { MessageBody } from '../../packages/app/composer/MessageBody';
import { appHref } from '../../packages/app/router';
import { MediaControls } from './MediaControls';
import { WatchButton } from '../../packages/app/WatchButton';
import { recordPath } from '../../packages/app/registry';
import { Link } from '@tanstack/react-router';
import { download, sizeLine, type FileRow } from './FileList';
import { EmbedFrame, LinkCard } from './EmbedFrame';
import type { EmbedFile } from './embedBits';
import { Slot } from '../../packages/app/slot';

/** How many pins one file shows. Beyond this, the thread is the place. */
const PIN_LIMIT = 200;

/** How far an arrow key moves a pin, and how far with shift held. */
const STEP = 0.02;
const BIG_STEP = 0.1;

/** The largest text file shown as text; bigger ones are downloaded. */
const TEXT_LIMIT = 512 * 1024;

export interface Anchor {
	/** A spot on a picture, as fractions of it. */
	x?: number;
	y?: number;
	/** A page of a PDF. */
	page?: number;
	/** A moment in a video or a recording, in seconds. */
	t?: number;
}

export type Look = 'image' | 'pdf' | 'video' | 'audio' | 'text' | 'none';

/**
 * How a file is shown here, from what it is.
 *
 * @param record The file.
 */
export function lookOf(
	record: Pick< FileRow, 'kind' | 'mime' | 'size' >
): Look {
	if ( 'upload' !== record.kind ) {
		return 'none';
	}
	const mime = String( record.mime ?? '' );
	if (
		[
			'image/png',
			'image/jpeg',
			'image/gif',
			'image/webp',
			'image/avif',
		].includes( mime )
	) {
		return 'image';
	}
	if ( 'application/pdf' === mime ) {
		return 'pdf';
	}
	if ( [ 'video/mp4', 'video/webm', 'video/quicktime' ].includes( mime ) ) {
		return 'video';
	}
	if ( mime.startsWith( 'audio/' ) ) {
		return 'audio';
	}
	const texty =
		mime.startsWith( 'text/' ) ||
		[
			'application/json',
			'application/xml',
			'application/javascript',
			'application/x-yaml',
			'image/svg+xml',
		].includes( mime );
	return texty && Number( record.size ?? 0 ) <= TEXT_LIMIT ? 'text' : 'none';
}

/**
 * Where a click landed on the picture, as a fraction of it.
 *
 * Measured against the picture and not the box around it: the image is
 * centred and usually narrower than its container, so a fraction of the
 * container lands somewhere else at another window size — and a click in
 * the margin is not on the picture at all, which is why this can say so.
 *
 * @param box        The picture's box on screen.
 * @param box.left   Its left edge.
 * @param box.top    Its top edge.
 * @param box.width  Its width.
 * @param box.height Its height.
 * @param clientX    Pointer x.
 * @param clientY    Pointer y.
 */
export function spotIn(
	box: { left: number; top: number; width: number; height: number },
	clientX: number,
	clientY: number
): Anchor | null {
	if ( box.width <= 0 || box.height <= 0 ) {
		return null;
	}
	const x = ( clientX - box.left ) / box.width;
	const y = ( clientY - box.top ) / box.height;
	return x < 0 || x > 1 || y < 0 || y > 1 ? null : { x, y };
}

/**
 * A pin moved by one arrow key, kept on the picture.
 *
 * @param from Where it is now.
 * @param key  The key pressed.
 * @param big  Whether shift was held.
 */
export function nudge( from: Anchor, key: string, big = false ): Anchor {
	const step = big ? BIG_STEP : STEP;
	const moves: Record< string, [ number, number ] > = {
		ArrowLeft: [ -step, 0 ],
		ArrowRight: [ step, 0 ],
		ArrowUp: [ 0, -step ],
		ArrowDown: [ 0, step ],
	};
	if ( ! moves[ key ] ) {
		return from;
	}
	return {
		x: Math.min( 1, Math.max( 0, ( from.x ?? 0.5 ) + moves[ key ][ 0 ] ) ),
		y: Math.min( 1, Math.max( 0, ( from.y ?? 0.5 ) + moves[ key ][ 1 ] ) ),
	};
}

/**
 * A moment as it is read: 1:05, or 1:02:05.
 *
 * @param seconds Seconds.
 */
export function timecode( seconds: number ): string {
	const all = Math.max( 0, Math.floor( seconds ) );
	const h = Math.floor( all / 3600 );
	const m = Math.floor( ( all % 3600 ) / 60 );
	const s = String( all % 60 ).padStart( 2, '0' );
	return h
		? `${ h }:${ String( m ).padStart( 2, '0' ) }:${ s }`
		: `${ m }:${ s }`;
}

/**
 * What a pin points at, in words.
 *
 * @param anchor Where it is.
 */
export function anchorLabel( anchor: Anchor ): string {
	if ( undefined !== anchor.page ) {
		return sprintf(
			/* translators: %d: a page number. */
			__( 'Page %d', 'cliezoo' ),
			anchor.page
		);
	}
	if ( undefined !== anchor.t ) {
		return sprintf(
			/* translators: %s: a moment in a recording, like 1:05. */
			__( 'At %s', 'cliezoo' ),
			timecode( anchor.t )
		);
	}
	return '';
}

interface Pin {
	uid: string;
	body: string;
	anchor: Anchor | null;
	resolved: boolean;
	created_at: string;
	created_by?: { uid: string; label: string } | null;
	/** The comment this one answers, when it is a reply. */
	reply_to?: string | null;
}

type Show = 'all' | 'open' | 'resolved';

/**
 * Whether the approval buttons are worth offering for this file.
 *
 * There is nothing to approve while the file itself has not arrived — the
 * fetch is still out, or it failed — and the server refuses the other two:
 * a folder with approvals switched off, and a locked file. A primary
 * button under "This could not be loaded." is a promise the footer cannot
 * keep.
 *
 * @param record The file, when there is one.
 */
export function canReview(
	record?: Pick< FileRow, 'approvals_off' | 'locked' > | null
): boolean {
	return !! record && ! record.approvals_off && ! record.locked;
}

export default function FileViewer( {
	uid,
	around = [],
	onMove,
	onClose,
	onChanged,
}: {
	uid: string;
	/** The files beside this one, in order, for next and previous. */
	around?: string[];
	onMove?: ( uid: string ) => void;
	onClose: () => void;
	onChanged: () => void;
} ) {
	const queries = useQueryClient();
	const picture = useRef< HTMLImageElement >( null );
	const player = useRef< HTMLMediaElement >( null );
	const stage = useRef< HTMLDivElement >( null );
	const [ placing, setPlacing ] = useState( false );
	const [ pending, setPending ] = useState< Anchor | null >( null );
	const [ answering, setAnswering ] = useState< Pin | null >( null );
	const [ broken, setBroken ] = useState( false );
	const [ page, setPage ] = useState( 1 );
	const [ show, setShow ] = useState< Show >( 'all' );
	const [ renaming, setRenaming ] = useState< string | null >( null );
	const [ asking, setAsking ] = useState< string | null >( null );
	const team = 'staff' === getBoot().user?.kind;

	const file = useQuery( {
		queryKey: [ 'files', 'one', uid ],
		queryFn: async () =>
			( await api.get< FileRow >( `/files/${ uid }` ) ).data,
	} );
	const record = file.data;
	const look: Look = record ? lookOf( record ) : 'none';

	const pins = useQuery( {
		queryKey: [ 'files', 'pins', uid ],
		queryFn: async () =>
			(
				await api.get< Pin[] >( '/comments', {
					object_type: 'files',
					object: uid,
					limit: PIN_LIMIT,
				} )
			).data,
	} );

	// A link for looking, not for saving: the server shows it in the page
	// for the types it shows at all. Text is read and set out here.
	const link = useQuery( {
		queryKey: [ 'files', 'view', uid ],
		enabled: 'none' !== look,
		staleTime: 4 * 60 * 1000,
		queryFn: async () =>
			(
				await api.get< { url: string } >( `/files/${ uid }/link`, {
					for: 'view',
				} )
			).data.url,
	} );
	const text = useQuery( {
		queryKey: [ 'files', 'text', uid ],
		enabled: 'text' === look && !! link.data,
		staleTime: 4 * 60 * 1000,
		queryFn: async () => {
			const response = await fetch( String( link.data ), {
				credentials: 'same-origin',
			} );
			if ( ! response.ok ) {
				throw new Error(
					__( 'This file could not be opened.', 'cliezoo' )
				);
			}
			return response.text();
		},
	} );
	const src = link.data ?? '';

	// Next and previous, by the arrow keys too — unless a pin is being
	// placed or somebody is typing.
	const index = around.indexOf( uid );
	const previous = index > 0 ? around[ index - 1 ] : null;
	const next =
		index >= 0 && index < around.length - 1 ? around[ index + 1 ] : null;
	useEffect( () => {
		const onKey = ( event: KeyboardEvent ) => {
			const typing = ( event.target as HTMLElement | null )?.closest(
				'input, textarea, [contenteditable="true"], [role="application"]'
			);
			if (
				placing ||
				typing ||
				event.altKey ||
				event.ctrlKey ||
				event.metaKey
			) {
				return;
			}
			if ( 'ArrowLeft' === event.key && previous && onMove ) {
				event.preventDefault();
				onMove( previous );
			} else if ( 'ArrowRight' === event.key && next && onMove ) {
				event.preventDefault();
				onMove( next );
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ placing, previous, next, onMove ] );

	// "Click it, or move with the arrow keys" is a promise the arrow keys
	// only keep once the picture has focus, and pressing the button leaves
	// focus on the button. So the stage takes it, the moment it becomes
	// something that can hold it.
	useEffect( () => {
		if ( placing ) {
			stage.current?.focus();
		}
	}, [ placing ] );

	// A new file starts clean.
	useEffect( () => {
		setPlacing( false );
		setPending( null );
		setBroken( false );
		setPage( 1 );
	}, [ uid ] );

	const at = ( clientX: number, clientY: number ): Anchor | null =>
		picture.current
			? spotIn(
					picture.current.getBoundingClientRect(),
					clientX,
					clientY
				)
			: null;

	const place = ( event: React.MouseEvent ) => {
		if ( ! placing || 'image' !== look ) {
			return;
		}
		const spot = at( event.clientX, event.clientY );
		if ( ! spot ) {
			toast.error(
				__( 'That is beside the picture, not on it.', 'cliezoo' )
			);
			return;
		}
		setPending( spot );
		setPlacing( false );
	};

	// Placing one without a mouse: it starts in the middle and the arrow
	// keys move it, because a pin you can only place by pointing is a
	// review round some people cannot take part in.
	const placeByKey = ( event: React.KeyboardEvent ) => {
		if ( ! placing ) {
			return;
		}
		if ( event.key.startsWith( 'Arrow' ) ) {
			event.preventDefault();
			// From whatever it is now, not from what it was when this
			// render happened: a held-down arrow key fires faster than
			// React re-renders, and every press has to count.
			setPending( ( current ) =>
				nudge(
					current ?? { x: 0.5, y: 0.5 },
					event.key,
					event.shiftKey
				)
			);
			return;
		}
		if ( 'Enter' === event.key || ' ' === event.key ) {
			event.preventDefault();
			setPending( ( current ) => current ?? { x: 0.5, y: 0.5 } );
			setPlacing( false );
		}
	};

	/** Starts a comment about a part of the file: a spot, a page, a moment. */
	const point = () => {
		if ( 'image' === look ) {
			setPlacing( true );
			setPending( { x: 0.5, y: 0.5 } );
		} else if ( 'pdf' === look ) {
			setPending( { page: Math.max( 1, page ) } );
		} else if ( 'video' === look || 'audio' === look ) {
			const now = player.current?.currentTime ?? 0;
			player.current?.pause();
			setPending( { t: Math.round( now * 10 ) / 10 } );
		}
	};

	/**
	 * Goes to what a pin points at.
	 * @param anchor
	 */
	const go = ( anchor: Anchor | null ) => {
		if ( ! anchor ) {
			return;
		}
		if ( undefined !== anchor.page ) {
			setPage( anchor.page );
		}
		if ( undefined !== anchor.t && player.current ) {
			player.current.currentTime = anchor.t;
			player.current.pause();
		}
	};

	const say = async ( message: { body: string; internal: boolean } ) => {
		// A reply goes under what it answers; a new comment may point.
		let where = {};
		if ( answering ) {
			where = { parent: answering.uid };
		} else if ( pending ) {
			where = { anchor: pending };
		}
		await api.post( '/comments', {
			object_type: 'files',
			object: uid,
			body: message.body,
			internal: message.internal,
			...where,
		} );
		await queries.invalidateQueries( {
			queryKey: [ 'files', 'pins', uid ],
		} );
		setPending( null );
		setAnswering( null );
	};

	const refresh = async () => {
		await queries.invalidateQueries( { queryKey: [ 'files' ] } );
		onChanged();
	};

	const settle = async ( pin: Pin ) => {
		try {
			await api.post(
				`/comments/${ pin.uid }/${ pin.resolved ? 'reopen' : 'resolve' }`
			);
			await queries.invalidateQueries( {
				queryKey: [ 'files', 'pins', uid ],
			} );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	const approve = async () => {
		try {
			await api.post( `/files/${ uid }/approve`, {
				approved: ! record?.approved_at,
			} );
			await refresh();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	const askForChanges = async ( note: string ) => {
		try {
			await api.post( `/files/${ uid }/changes`, { note } );
			await queries.invalidateQueries( {
				queryKey: [ 'files', 'pins', uid ],
			} );
			await refresh();
			setAsking( null );
			toast.success( __( 'Changes asked for.', 'cliezoo' ) );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	const rename = async ( name: string ) => {
		try {
			await api.patch( `/files/${ uid }`, { name } );
			await refresh();
			setRenaming( null );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	const copyLink = async () => {
		await copyText( appHref( `/files?file=${ uid }` ) );
	};

	const trash = async () => {
		try {
			await api.delete( `/files/${ uid }` );
			await refresh();
			toast.success( __( 'Moved to the trash.', 'cliezoo' ) );
			onClose();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	const full = () => {
		const box = stage.current;
		if ( ! box ) {
			return;
		}
		if ( document.fullscreenElement ) {
			document.exitFullscreen().catch( () => undefined );
		} else {
			box.requestFullscreen?.().catch( () =>
				toast.error(
					__(
						'This browser will not show it full screen.',
						'cliezoo'
					)
				)
			);
		}
	};

	// Replies sit under the comment they answer; the pins, counts and the
	// filter are about the comments themselves.
	const all = ( pins.data ?? [] ).filter( ( pin ) => ! pin.reply_to );
	const repliesTo = ( pin: Pin ) =>
		( pins.data ?? [] ).filter( ( one ) => one.reply_to === pin.uid );
	const listed = all.filter( ( pin ) => {
		if ( 'open' === show ) {
			return ! pin.resolved;
		}
		return 'resolved' === show ? pin.resolved : true;
	} );
	const spots = all.filter(
		( pin ) => undefined !== pin.anchor?.x && undefined !== pin.anchor?.y
	);
	const pointable =
		[ 'image', 'pdf', 'video', 'audio' ].includes( look ) && ! broken;
	const openCount = all.filter( ( pin ) => ! pin.resolved ).length;

	return (
		<Dialog
			isOpen
			onOpenChange={ ( open ) => ! open && onClose() }
			size="xl"
			title={ record?.name ?? __( 'File', 'cliezoo' ) }
			description={
				record
					? // Locked is why rename, trash and the approval
						// buttons are not on offer, so it is said here
						// rather than left for somebody to work out.
						[
							sizeLine( record ),
							reviewLine( record ),
							record.locked ? __( 'Locked', 'cliezoo' ) : '',
						]
							.filter( Boolean )
							.join( ' · ' )
					: undefined
			}
			footer={ ( close ) => (
				<>
					<Button variant="ghost" onPress={ close }>
						{ __( 'Close', 'cliezoo' ) }
					</Button>
					{ !! record && (
						<Button
							icon={ <Download size={ 15 } aria-hidden="true" /> }
							onPress={ () => download( record ) }
						>
							{ __( 'Download', 'cliezoo' ) }
						</Button>
					) }
					{ !! record && (
						<WatchButton
							type="files"
							uid={ uid }
							label={ record.name }
						/>
					) }
					{ canReview( record ) && ! record?.approved_at && (
						<Button
							icon={ <Undo2 size={ 15 } aria-hidden="true" /> }
							onPress={ () => setAsking( '' ) }
						>
							{ __( 'Ask for changes', 'cliezoo' ) }
						</Button>
					) }
					{ canReview( record ) && (
						<Button
							variant={
								record?.approved_at ? 'ghost' : 'primary'
							}
							icon={ <Check size={ 15 } aria-hidden="true" /> }
							onPress={ approve }
						>
							{ record?.approved_at
								? __( 'Take the approval back', 'cliezoo' )
								: __( 'Approve it', 'cliezoo' ) }
						</Button>
					) }
				</>
			) }
		>
			{ !! record?.location && <Where location={ record.location } /> }
			<div className="mb-3 flex flex-wrap items-center gap-1">
				{ around.length > 1 && index >= 0 && (
					<>
						<IconButton
							size="sm"
							label={ __( 'The file before', 'cliezoo' ) }
							icon={
								<ChevronLeft size={ 16 } aria-hidden="true" />
							}
							isDisabled={ ! previous || ! onMove }
							onPress={ () => previous && onMove?.( previous ) }
						/>
						<span className="text-xs tabular-nums text-muted">
							{ sprintf(
								/* translators: 1: how many so far, 2: how many there are. */
								__( '%1$d of %2$d', 'cliezoo' ),
								index + 1,
								around.length
							) }
						</span>
						<IconButton
							size="sm"
							label={ __( 'The file after', 'cliezoo' ) }
							icon={
								<ChevronRight size={ 16 } aria-hidden="true" />
							}
							isDisabled={ ! next || ! onMove }
							onPress={ () => next && onMove?.( next ) }
						/>
					</>
				) }
				<span className="ms-auto flex items-center gap-1">
					{ /* Filled by an add-on (its badge until then), and told which file. */ }
					<Slot
						name="file.viewer"
						variant="chip"
						props={ { file: uid } }
					/>
					{ 'none' !== look && (
						<IconButton
							size="sm"
							label={ __( 'Full screen', 'cliezoo' ) }
							icon={
								<Maximize2 size={ 15 } aria-hidden="true" />
							}
							onPress={ full }
						/>
					) }
					{ !! record && (
						<MenuTrigger>
							<IconButton
								size="sm"
								label={ __( 'More for this file', 'cliezoo' ) }
								icon={
									<MoreHorizontal
										size={ 16 }
										aria-hidden="true"
									/>
								}
							/>
							<Menu
								onAction={ ( key: unknown ) => {
									const id = String( key );
									if ( 'rename' === id ) {
										setRenaming( record.name );
									} else if ( 'copy' === id ) {
										copyLink();
									} else if ( 'open' === id && src ) {
										window.open(
											src,
											'_blank',
											'noopener,noreferrer'
										);
									} else if ( 'download' === id ) {
										download( record );
									} else if ( 'trash' === id ) {
										trash();
									}
								} }
								disabledKeys={ [
									...( record.locked
										? [ 'rename', 'trash' ]
										: [] ),
									...( src ? [] : [ 'open' ] ),
								] }
							>
								<MenuItem
									id="rename"
									icon={ <Pencil size={ 16 } /> }
								>
									{ __( 'Rename…', 'cliezoo' ) }
								</MenuItem>
								<MenuItem
									id="copy"
									icon={ <Copy size={ 16 } /> }
								>
									{ __( 'Copy link', 'cliezoo' ) }
								</MenuItem>
								<MenuItem
									id="open"
									icon={ <ExternalLink size={ 16 } /> }
								>
									{ __( 'Open in a new tab', 'cliezoo' ) }
								</MenuItem>
								<MenuItem
									id="download"
									icon={ <Download size={ 16 } /> }
								>
									{ __( 'Download', 'cliezoo' ) }
								</MenuItem>
								<MenuItem
									id="trash"
									icon={ <Trash2 size={ 16 } /> }
									danger
								>
									{ __( 'Move to trash', 'cliezoo' ) }
								</MenuItem>
							</Menu>
						</MenuTrigger>
					) }
				</span>
			</div>
			<div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
				<div>
					{ file.isPending && <Skeleton className="h-64" /> }
					{ file.isError && (
						<ErrorState
							error={ file.error }
							onRetry={ () => file.refetch() }
						/>
					) }
					{ !! record &&
						( 'link' === record.kind ||
							'embed' === record.kind ) && (
							<div className="space-y-3">
								{ !! record.description && (
									<p className="text-sm text-muted">
										{ record.description }
									</p>
								) }
								{ 'embed' === record.kind ? (
									<EmbedFrame file={ record as EmbedFile } />
								) : (
									<LinkCard file={ record as EmbedFile } />
								) }
							</div>
						) }
					{ !! record && 'upload' === record.kind && (
						<>
							<div
								ref={ stage }
								className={ cx(
									'relative overflow-hidden rounded-lg border border-line bg-sunken',
									placing && 'cursor-crosshair'
								) }
								role={ placing ? 'application' : undefined }
								aria-label={
									placing
										? __(
												'Choose a spot: click it, or use the arrow keys and press Enter.',
												'cliezoo'
											)
										: undefined
								}
								tabIndex={ placing ? 0 : undefined }
								onClick={ place }
								onKeyDown={ placeByKey }
							>
								{ 'none' !== look &&
									! src &&
									! link.isError && (
										<Skeleton className="h-64" />
									) }
								{ link.isError && (
									<div className="p-6">
										<ErrorState
											error={ link.error }
											title={ __(
												'This file could not be opened.',
												'cliezoo'
											) }
											onRetry={ () => link.refetch() }
										/>
									</div>
								) }
								{ /*
								 * The pins are placed inside a wrapper the
								 * size of the picture, not of the stage.
								 * A spot is measured as a fraction of the
								 * picture, and the picture is centred and
								 * usually narrower than the box around it,
								 * so drawn against the stage every pin sat
								 * to the left of what it was about.
								 */ }
								{ 'image' === look && !! src && ! broken && (
									<span className="relative mx-auto block w-fit">
										<img
											ref={ picture }
											src={ src }
											alt={ record.name }
											className="block max-h-[60vh] w-auto"
											onError={ () => setBroken( true ) }
										/>
										{ undefined !== pending?.x && (
											<span
												className="absolute size-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-accent bg-raised"
												style={ {
													left: `${ ( pending.x ?? 0 ) * 100 }%`,
													top: `${ ( pending.y ?? 0 ) * 100 }%`,
												} }
												aria-hidden="true"
											/>
										) }
										{ spots.map( ( pin, number ) => (
											<span
												key={ pin.uid }
												role="img"
												aria-label={ sprintf(
													/* translators: 1: pin number, 2: whether it is sorted or still open. */
													__(
														'Pin %1$d, %2$s',
														'cliezoo'
													),
													number + 1,
													pin.resolved
														? __(
																'sorted',
																'cliezoo'
															)
														: __(
																'still open',
																'cliezoo'
															)
												) }
												className={ cx(
													'absolute -translate-x-1/2 -translate-y-1/2 rounded-full px-2 py-0.5 text-xs font-semibold shadow-sm',
													pin.resolved
														? 'bg-success text-white'
														: 'bg-accent text-white'
												) }
												style={ {
													left: `${ ( pin.anchor?.x ?? 0 ) * 100 }%`,
													top: `${ ( pin.anchor?.y ?? 0 ) * 100 }%`,
												} }
											>
												{ pin.resolved
													? '✓'
													: number + 1 }
											</span>
										) ) }
									</span>
								) }
								{ 'pdf' === look && !! src && (
									<iframe
										key={ page }
										title={ record.name }
										src={ `${ src }#page=${ page }` }
										className="block h-[70vh] w-full bg-raised"
									/>
								) }
								{ 'video' === look && !! src && (
									<>
										<video
											ref={
												player as React.RefObject< HTMLVideoElement >
											}
											src={ src }
											controls
											preload="metadata"
											className="mx-auto block max-h-[60vh] w-full bg-black"
											onError={ () => setBroken( true ) }
										/>
										<MediaControls
											player={ player }
											label={ record.name }
										/>
									</>
								) }
								{ 'audio' === look && !! src && (
									<div className="p-6">
										{  }
										<audio
											ref={
												player as React.RefObject< HTMLAudioElement >
											}
											src={ src }
											controls
											preload="metadata"
											className="w-full"
											onError={ () => setBroken( true ) }
										/>
										<MediaControls
											player={ player }
											label={ record.name }
										/>
									</div>
								) }
								{ 'text' === look &&
									text.isPending &&
									!! src && (
										<Skeleton className="m-4 h-40" />
									) }
								{ 'text' === look && text.isError && (
									<div className="p-6">
										<ErrorState
											error={ text.error }
											title={ __(
												'This file could not be opened. Download it to look at it.',
												'cliezoo'
											) }
											onRetry={ () => text.refetch() }
										/>
									</div>
								) }
								{ 'text' === look &&
									undefined !== text.data && (
										<pre className="max-h-[60vh] overflow-auto whitespace-pre-wrap break-words p-4 font-mono text-xs text-fg">
											{ text.data }
										</pre>
									) }
								{ broken && (
									<p className="p-10 text-center text-sm text-muted">
										{ __(
											'This file could not be opened. It may have been removed from storage.',
											'cliezoo'
										) }
									</p>
								) }
								{ 'none' === look && (
									<p className="p-10 text-center text-sm text-muted">
										{ __(
											'This kind of file is not shown here. Download it to look at it.',
											'cliezoo'
										) }
									</p>
								) }
							</div>

							<div className="mt-2 flex flex-wrap items-center gap-2">
								{ 'pdf' === look && (
									<TextField
										label={ __( 'Page', 'cliezoo' ) }
										type="number"
										className="w-24"
										value={ String( page ) }
										onChange={ ( value: string ) =>
											setPage(
												Math.max(
													1,
													Math.floor(
														Number( value ) || 1
													)
												)
											)
										}
									/>
								) }
								<Button
									size="sm"
									icon={
										<MessageSquarePlus
											size={ 15 }
											aria-hidden="true"
										/>
									}
									isDisabled={ ! pointable || ! src }
									onPress={ point }
								>
									{ pointLabel( look, placing ) }
								</Button>
								{ placing && (
									<span className="text-xs text-muted">
										{ __(
											'Click it, or move with the arrow keys and press Enter.',
											'cliezoo'
										) }
									</span>
								) }
								{ ! placing && !! pending && (
									<span className="flex items-center gap-2 text-xs text-muted">
										{ undefined === pending.x
											? sprintf(
													/* translators: %s: where the comment is about, like "Page 3" or "At 1:05". */
													__(
														'About %s. Now say what you mean, on the right.',
														'cliezoo'
													),
													anchorLabel( pending )
												)
											: __(
													'Now say what you mean, on the right.',
													'cliezoo'
												) }
										<Button
											size="sm"
											variant="link"
											onPress={ () => setPending( null ) }
										>
											{ __(
												'Not about a part',
												'cliezoo'
											) }
										</Button>
									</span>
								) }
								{ ! pointable &&
									'none' !== look &&
									! broken && (
										<span className="text-xs text-subtle">
											{ __(
												'You can still leave a comment.',
												'cliezoo'
											) }
										</span>
									) }
							</div>
						</>
					) }
				</div>

				<aside className="space-y-3">
					<div className="flex items-center gap-2">
						<h3 className="text-sm font-semibold text-fg">
							{ __( 'What people said', 'cliezoo' ) }
						</h3>
						{ all.length > 0 && (
							<div
								className="ms-auto flex gap-1"
								role="group"
								aria-label={ __( 'Which comments', 'cliezoo' ) }
							>
								{ (
									[
										[ 'all', __( 'All', 'cliezoo' ) ],
										[
											'open',
											sprintf(
												/* translators: %d: how many comments are still open. */
												_n(
													'%d open',
													'%d open',
													openCount,
													'cliezoo'
												),
												openCount
											),
										],
										[
											'resolved',
											__( 'Sorted', 'cliezoo' ),
										],
									] as Array< [ Show, string ] >
								 ).map( ( [ id, label ] ) => (
									<Button
										key={ id }
										size="sm"
										variant={
											show === id ? 'secondary' : 'ghost'
										}
										aria-pressed={ show === id }
										onPress={ () => setShow( id ) }
									>
										{ label }
									</Button>
								) ) }
							</div>
						) }
					</div>

					{ pins.isPending && <Skeleton className="h-24" /> }
					{ pins.isError && (
						<ErrorState
							error={ pins.error }
							onRetry={ () => pins.refetch() }
						/>
					) }
					{ pins.data && 0 === pins.data.length && (
						<p className="text-sm text-muted">
							{ __(
								'Nothing yet. Point at the part you mean, so nobody has to guess which logo you meant.',
								'cliezoo'
							) }
						</p>
					) }

					<ul className="space-y-3">
						{ listed.map( ( pin ) => {
							const number = spots.indexOf( pin );
							const where = pin.anchor
								? anchorLabel( pin.anchor )
								: '';
							return (
								<li
									key={ pin.uid }
									className="rounded-lg border border-line p-3"
								>
									<p className="flex flex-wrap items-center gap-2 text-xs text-subtle">
										{ number >= 0 && (
											<Badge tone="accent">
												{ sprintf(
													/* translators: %d: which pin on the picture this is. */
													__( 'Pin %d', 'cliezoo' ),
													number + 1
												) }
											</Badge>
										) }
										{ !! where && (
											<button
												type="button"
												className="rounded bg-accent-subtle px-1.5 text-accent-fg hover:underline"
												onClick={ () =>
													go( pin.anchor )
												}
											>
												{ where }
											</button>
										) }
										<span>
											{ pin.created_by?.label ??
												__( 'Someone', 'cliezoo' ) }
										</span>
										<span>
											{ formatRelative( pin.created_at ) }
										</span>
										{ pin.resolved && (
											<Badge tone="success">
												{ __( 'Sorted', 'cliezoo' ) }
											</Badge>
										) }
									</p>
									<p className="mt-1 whitespace-pre-line text-sm text-fg">
										<MessageBody body={ pin.body } />
									</p>
									{ repliesTo( pin ).length > 0 && (
										<ul
											className="mt-2 space-y-2 border-s-2 border-line ps-3"
											aria-label={ __(
												'Replies',
												'cliezoo'
											) }
										>
											{ repliesTo( pin ).map(
												( reply ) => (
													<li key={ reply.uid }>
														<p className="text-xs text-subtle">
															{ reply.created_by
																?.label ??
																__(
																	'Someone',
																	'cliezoo'
																) }{ ' ' }
															·{ ' ' }
															{ formatRelative(
																reply.created_at
															) }
														</p>
														<div className="text-sm text-fg">
															<MessageBody
																body={
																	reply.body
																}
															/>
														</div>
													</li>
												)
											) }
										</ul>
									) }
									{ ! record?.comments_off && (
										<Button
											size="sm"
											variant="link"
											onPress={ () => {
												setPending( null );
												setAnswering( pin );
											} }
										>
											{ __( 'Reply', 'cliezoo' ) }
										</Button>
									) }
									<Button
										size="sm"
										variant="link"
										icon={
											pin.resolved ? (
												<RotateCcw
													size={ 13 }
													aria-hidden="true"
												/>
											) : (
												<Check
													size={ 13 }
													aria-hidden="true"
												/>
											)
										}
										onPress={ () => settle( pin ) }
									>
										{ pin.resolved
											? __( 'Open it again', 'cliezoo' )
											: __(
													'Mark it sorted',
													'cliezoo'
												) }
									</Button>
								</li>
							);
						} ) }
					</ul>

					{ record?.comments_off && (
						<p className="rounded-md bg-sunken p-3 text-sm text-muted">
							{ __(
								'Comments are switched off in this folder.',
								'cliezoo'
							) }
						</p>
					) }
					{ ! record?.comments_off && !! answering && (
						<p className="flex items-center gap-2 text-xs text-muted">
							{ sprintf(
								/* translators: %s: who wrote the comment being answered. */
								__( 'Replying to %s', 'cliezoo' ),
								answering.created_by?.label ??
									__( 'a comment', 'cliezoo' )
							) }
							<Button
								size="sm"
								variant="link"
								onPress={ () => setAnswering( null ) }
							>
								{ __( 'Cancel', 'cliezoo' ) }
							</Button>
						</p>
					) }
					{ ! record?.comments_off && (
						<Composer
							key={ `${ uid }-${ answering?.uid ?? '' }` }
							type="files"
							uid={ uid }
							canAttach={ false }
							canNote={ team }
							draftKey={ `files:${ uid }` }
							placeholder={ composerHint( pending ) }
							submitLabel={ __( 'Post it', 'cliezoo' ) }
							onSend={ say }
						/>
					) }
				</aside>
			</div>

			{ null !== renaming && (
				<Dialog
					isOpen
					size="sm"
					onOpenChange={ ( open ) => ! open && setRenaming( null ) }
					title={ __( 'Rename file', 'cliezoo' ) }
					footer={ ( close ) => (
						<>
							<Button variant="ghost" onPress={ close }>
								{ __( 'Cancel', 'cliezoo' ) }
							</Button>
							<Button
								variant="primary"
								isDisabled={ ! renaming.trim() }
								onPress={ () => rename( renaming.trim() ) }
							>
								{ __( 'Rename', 'cliezoo' ) }
							</Button>
						</>
					) }
				>
					<TextField
						label={ __( 'Name', 'cliezoo' ) }
						value={ renaming }
						onChange={ setRenaming }
						maxLength={ 200 }
					/>
				</Dialog>
			) }

			{ null !== asking && (
				<Dialog
					isOpen
					onOpenChange={ ( open ) => ! open && setAsking( null ) }
					title={ __( 'Ask for changes', 'cliezoo' ) }
					description={
						record?.comments_off
							? __(
									'The file is marked as needing changes. Comments are off in this folder, so there is no note to add.',
									'cliezoo'
								)
							: __(
									'The file is marked as needing changes, and what you write goes into its conversation.',
									'cliezoo'
								)
					}
					footer={ ( close ) => (
						<>
							<Button variant="ghost" onPress={ close }>
								{ __( 'Cancel', 'cliezoo' ) }
							</Button>
							<Button
								variant="primary"
								onPress={ () => askForChanges( asking ) }
							>
								{ __( 'Ask for changes', 'cliezoo' ) }
							</Button>
						</>
					) }
				>
					{ ! record?.comments_off && (
						<TextField
							label={ __( 'What should change?', 'cliezoo' ) }
							multiline
							rows={ 4 }
							value={ asking }
							onChange={ setAsking }
							maxLength={ 5000 }
						/>
					) }
				</Dialog>
			) }

		</Dialog>
	);
}

/**
 * Where a file lives: the folders it is in, or what it came with.
 *
 * @param root0          Props.
 * @param root0.location Where it is.
 */
function Where( {
	location,
}: {
	location: NonNullable< FileRow[ 'location' ] >;
} ) {
	if ( location.attached ) {
		const path = recordPath(
			location.attached.type,
			location.attached.uid
		);
		return (
			<p className="mb-2 text-xs text-muted">
				{ __( 'Came with', 'cliezoo' ) }{ ' ' }
				{ path ? (
					<Link
						to={ path.split( '?' )[ 0 ] }
						className="text-accent-fg hover:underline"
					>
						{ location.attached.label }
					</Link>
				) : (
					location.attached.label
				) }
			</p>
		);
	}
	if ( ! location.folders.length ) {
		return null;
	}
	return (
		<nav
			aria-label={ __( 'Where it is', 'cliezoo' ) }
			className="mb-2 text-xs text-muted"
		>
			{ __( 'In', 'cliezoo' ) }{ ' ' }
			{ location.folders.map( ( folder, index ) => (
				<span key={ folder.uid }>
					{ index > 0 && ' / ' }
					<Link
						to="/files"
						search={ { folder: folder.uid } as never }
						className="text-accent-fg hover:underline"
					>
						{ folder.name }
					</Link>
				</span>
			) ) }
		</nav>
	);
}

function pointLabel( look: Look, placing: boolean ): string {
	if ( placing ) {
		return __( 'Choose the spot', 'cliezoo' );
	}
	if ( 'pdf' === look ) {
		return __( 'Comment on this page', 'cliezoo' );
	}
	if ( 'video' === look || 'audio' === look ) {
		return __( 'Comment on this moment', 'cliezoo' );
	}
	return __( 'Point at something', 'cliezoo' );
}

function composerHint( pending: Anchor | null ): string {
	if ( pending && undefined !== pending.x ) {
		return sprintf(
			/* translators: 1: horizontal position as a percentage, 2: vertical. */
			__( 'About the spot at %1$d%% across, %2$d%% down', 'cliezoo' ),
			Math.round( ( pending.x ?? 0 ) * 100 ),
			Math.round( ( pending.y ?? 0 ) * 100 )
		);
	}
	if ( pending ) {
		return sprintf(
			/* translators: %s: where the comment is about, like "Page 3" or "At 1:05". */
			__( 'About %s', 'cliezoo' ),
			anchorLabel( pending )
		);
	}
	return __( 'Say something about this file', 'cliezoo' );
}

/**
 * Where the review stands: approved by whom and when, changes asked for,
 * or not reviewed yet — the whole difference between an approval and a
 * tick.
 *
 * @param record The file.
 */
function reviewLine( record: FileRow ): string {
	if ( record.approved_at ) {
		return record.approved_by
			? sprintf(
					/* translators: 1: a person's name, 2: a date. */
					__( 'Approved by %1$s on %2$s', 'cliezoo' ),
					record.approved_by.label,
					formatFullDate( record.approved_at )
				)
			: sprintf(
					/* translators: %s: a date. */
					__( 'Approved %s', 'cliezoo' ),
					formatFullDate( record.approved_at )
				);
	}
	if ( record.changes_at ) {
		return record.changes_by
			? sprintf(
					/* translators: 1: a person's name, 2: a date. */
					__( 'Changes asked for by %1$s on %2$s', 'cliezoo' ),
					record.changes_by.label,
					formatFullDate( record.changes_at )
				)
			: __( 'Changes asked for', 'cliezoo' );
	}
	return __( 'Not approved yet', 'cliezoo' );
}
