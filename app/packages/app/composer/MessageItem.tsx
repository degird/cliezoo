/**
 * One message, or one comment: who said it, what they said, what it
 * carries, and what can be done with it.
 *
 * Shared by conversations and task comments, because they are the same
 * rows — a reaction, an edit or a reply works the same on both.
 */
import { useState } from 'react';
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import {
	CornerDownRight,
	Download,
	MoreHorizontal,
	Paperclip,
	Pencil,
	Pin,
	PinOff,
	Reply,
	SmilePlus,
	Trash2,
} from 'lucide-react';
import { api, formatBytes, formatRelative, type ApiError } from '@cliezoo/data';
import {
	AriaDialog,
	Avatar,
	Badge,
	ConfirmDialog,
	DialogTrigger,
	IconButton,
	Menu,
	MenuItem,
	MenuTrigger,
	Popover,
	cx,
	toast,
} from '@cliezoo/ui';
import { copyText } from '../copy';
import { Composer } from './Composer';
import { EmojiPanel } from './ComposerTools';
import { QUICK } from './emoji';
import { fromHtml } from './markdown';
import { MessageBody } from './MessageBody';

export interface MessageFile {
	uid: string;
	name: string;
	mime?: string;
	size?: number | null;
	kind?: string;
	preview?: string | null;
	play?: string | null;
}

export interface Reaction {
	emoji: string;
	count: number;
	mine: boolean;
	people: string[];
}

export interface Message {
	uid: string;
	body: string;
	internal?: boolean;
	created_at: string;
	edited_at?: string | null;
	created_by?: { uid: string; label: string } | null;
	reply_to?: string | null;
	replies?: number;
	files?: MessageFile[];
	mine?: boolean;
	reactions?: Reaction[];
	pinned?: boolean;
	send_at?: string | null;
	/** What it is about: a suggested edit names the words and the block. */
	anchor?: Record< string, unknown > | null;
	resolved?: boolean;
}

/**
 * Opens a file that is not shown in the message.
 *
 * @param file The file.
 */
async function open( file: MessageFile ): Promise< void > {
	try {
		const link = await api.get< { url: string } >(
			`/files/${ file.uid }/link`
		);
		window.location.assign( link.data.url );
	} catch ( error ) {
		toast.error( ( error as ApiError ).message );
	}
}

/**
 * The files a message carries: pictures shown, recordings playable, the
 * rest as something to download.
 *
 * @param root0       Props.
 * @param root0.files The files.
 */
export function MessageFiles( { files }: { files: MessageFile[] } ) {
	if ( ! files.length ) {
		return null;
	}
	return (
		<ul className="mt-2 flex flex-wrap gap-2">
			{ files.map( ( file ) => (
				<li key={ file.uid }>
					<FileShown file={ file } />
				</li>
			) ) }
		</ul>
	);
}

/**
 * One file in a message: a picture shown, a recording to play, or
 * something to download.
 *
 * @param root0      Props.
 * @param root0.file The file.
 */
function FileShown( { file }: { file: MessageFile } ) {
	if ( file.preview ) {
		return (
			<button
				type="button"
				className="block overflow-hidden rounded border border-line focus-visible:outline-2 focus-visible:outline-focus"
				onClick={ () => open( file ) }
			>
				<img
					src={ file.preview }
					alt={ file.name }
					className="max-h-48 max-w-64 object-cover"
					loading="lazy"
				/>
			</button>
		);
	}
	if ( file.play ) {
		return (
			<figure className="rounded border border-line p-1.5">
				{ /* A voice note is somebody talking; there is no caption track to give it. */ }
				<audio
					controls
					preload="none"
					src={ file.play }
					className="h-9 w-64 max-w-full"
				/>
				<figcaption className="sr-only">{ file.name }</figcaption>
			</figure>
		);
	}
	return (
		<button
			type="button"
			className="flex items-center gap-1.5 rounded border border-line px-2 py-1 text-xs text-muted hover:text-fg focus-visible:outline-2 focus-visible:outline-focus"
			onClick={ () => open( file ) }
		>
			<Paperclip size={ 12 } aria-hidden="true" />
			<span className="max-w-48 truncate">{ file.name }</span>
			{ !! file.size && (
				<span className="text-subtle">
					{ formatBytes( file.size ) }
				</span>
			) }
			<Download size={ 12 } aria-hidden="true" />
		</button>
	);
}

export function MessageItem( {
	message,
	onChanged,
	onReply,
	onPin,
	canModerate = false,
	selecting,
	selected,
	onSelect,
	highlight,
}: {
	message: Message;
	/** Called after anything changes it. */
	onChanged: () => void;
	/** Answer it (opens its thread). */
	onReply?: () => void;
	/** Pin or unpin it, when the reader may. */
	onPin?: ( pinned: boolean ) => void;
	/** The team may take anybody's message down. */
	canModerate?: boolean;
	/** Choosing messages to move. */
	selecting?: boolean;
	selected?: boolean;
	onSelect?: ( chosen: boolean ) => void;
	/** The message a link pointed at. */
	highlight?: boolean;
} ) {
	const [ editing, setEditing ] = useState( false );
	const [ removing, setRemoving ] = useState( false );
	const who = message.created_by?.label ?? __( 'Somebody', 'cliezoo' );

	const react = async ( emoji: string ) => {
		try {
			await api.post( '/reactions', { comment: message.uid, emoji } );
			onChanged();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	return (
		<article
			id={ `message-${ message.uid }` }
			aria-label={ sprintf(
				/* translators: 1: a person, 2: when. */
				_x( '%1$s, %2$s', 'who said something and when', 'cliezoo' ),
				who,
				formatRelative( message.created_at )
			) }
			className={ cx(
				'group relative flex gap-3 rounded-lg px-3 py-2',
				message.internal && 'bg-warning/10',
				highlight && 'ring-2 ring-accent',
				selected && 'bg-accent-subtle'
			) }
		>
			{ selecting && (
				<input
					type="checkbox"
					className="mt-2"
					checked={ !! selected }
					aria-label={ __( 'Choose this message', 'cliezoo' ) }
					onChange={ ( event ) => onSelect?.( event.target.checked ) }
				/>
			) }
			<Avatar name={ who } size="sm" />
			<div className="min-w-0 flex-1">
				<p className="flex flex-wrap items-center gap-x-2 text-xs text-subtle">
					<span className="font-medium text-fg">{ who }</span>
					<time dateTime={ message.created_at }>
						{ formatRelative( message.created_at ) }
					</time>
					{ !! message.edited_at && (
						<span>{ __( '(edited)', 'cliezoo' ) }</span>
					) }
					{ !! message.internal && (
						<Badge tone="warning">
							{ __( 'Internal note', 'cliezoo' ) }
						</Badge>
					) }
					{ !! message.pinned && (
						<span className="inline-flex items-center gap-0.5 text-accent-fg">
							<Pin size={ 11 } aria-hidden="true" />
							{ __( 'Pinned', 'cliezoo' ) }
						</span>
					) }
				</p>

				{ editing ? (
					<div className="mt-1">
						<Composer
							type="comments"
							uid={ message.uid }
							canAttach={ false }
							initial={ fromHtml( message.body ) }
							submitLabel={ __( 'Save', 'cliezoo' ) }
							// eslint-disable-next-line jsx-a11y/no-autofocus -- A panel somebody just opened to type in.
							autoFocus
							onCancel={ () => setEditing( false ) }
							onSend={ async ( next ) => {
								await api.patch( `/comments/${ message.uid }`, {
									body: next.body,
								} );
								setEditing( false );
								onChanged();
							} }
						/>
					</div>
				) : (
					'' !== message.body && (
						<MessageBody body={ message.body } className="mt-0.5" />
					)
				) }

				<MessageFiles files={ message.files ?? [] } />

				{ ( !! message.reactions?.length || !! message.replies ) && (
					<div className="mt-1.5 flex flex-wrap items-center gap-1">
						{ ( message.reactions ?? [] ).map( ( one ) => (
							<button
								key={ one.emoji }
								type="button"
								aria-pressed={ one.mine }
								title={ one.people.join( ', ' ) }
								aria-label={ sprintf(
									/* translators: 1: an emoji, 2: how many people. */
									_n(
										'%1$s, %2$d person',
										'%1$s, %2$d people',
										one.count,
										'cliezoo'
									),
									one.emoji,
									one.count
								) }
								className={ cx(
									'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs focus-visible:outline-2 focus-visible:outline-focus',
									one.mine
										? 'border-accent bg-accent-subtle text-accent-fg'
										: 'border-line text-muted hover:border-line-strong'
								) }
								onClick={ () => react( one.emoji ) }
							>
								<span aria-hidden="true">{ one.emoji }</span>
								<span aria-hidden="true">{ one.count }</span>
							</button>
						) ) }
						{ !! message.replies && onReply && (
							<button
								type="button"
								className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium text-accent-fg hover:underline focus-visible:outline-2 focus-visible:outline-focus"
								onClick={ onReply }
							>
								<CornerDownRight
									size={ 12 }
									aria-hidden="true"
								/>
								{ sprintf(
									/* translators: %d: how many replies. */
									_n(
										'%d reply',
										'%d replies',
										message.replies,
										'cliezoo'
									),
									message.replies
								) }
							</button>
						) }
					</div>
				) }
			</div>

			{ ! editing && ! selecting && (
				<div className="absolute -top-3 end-2 flex items-center gap-0.5 rounded-md border border-line bg-raised p-0.5 opacity-0 shadow-sm transition-opacity focus-within:opacity-100 group-hover:opacity-100 [@media(hover:none)]:opacity-100">
					<DialogTrigger>
						<IconButton
							size="sm"
							label={ __( 'React', 'cliezoo' ) }
							icon={
								<SmilePlus size={ 14 } aria-hidden="true" />
							}
						/>
						<Popover placement="bottom end">
							<AriaDialog
								aria-label={ __( 'React', 'cliezoo' ) }
								className="outline-none"
							>
								{ ( { close }: { close: () => void } ) => (
									<div>
										<div className="flex gap-0.5 border-b border-line p-1.5">
											{ QUICK.map( ( emoji ) => (
												<button
													key={ emoji }
													type="button"
													className="rounded p-1 text-lg leading-none hover:bg-sunken focus-visible:outline-2 focus-visible:outline-focus"
													onClick={ () => {
														react( emoji );
														close();
													} }
												>
													{ emoji }
												</button>
											) ) }
										</div>
										<EmojiPanel
											onPick={ ( emoji ) =>
												react( emoji )
											}
											onClose={ close }
										/>
									</div>
								) }
							</AriaDialog>
						</Popover>
					</DialogTrigger>
					{ onReply && (
						<IconButton
							size="sm"
							label={ __( 'Reply', 'cliezoo' ) }
							icon={ <Reply size={ 14 } aria-hidden="true" /> }
							onPress={ onReply }
						/>
					) }
					{ ( message.mine || canModerate || onPin ) && (
						<MenuTrigger>
							<IconButton
								size="sm"
								label={ __( 'More', 'cliezoo' ) }
								icon={
									<MoreHorizontal
										size={ 14 }
										aria-hidden="true"
									/>
								}
							/>
							<Menu
								onAction={ ( key ) => {
									if ( 'edit' === key ) {
										setEditing( true );
									} else if ( 'remove' === key ) {
										setRemoving( true );
									} else if ( 'pin' === key ) {
										onPin?.( ! message.pinned );
									} else if ( 'link' === key ) {
										const url = new URL(
											window.location.href
										);
										url.searchParams.set(
											'message',
											message.uid
										);
										copyText( url.toString() );
									}
								} }
							>
								{ !! message.mine && (
									<MenuItem
										id="edit"
										icon={
											<Pencil
												size={ 15 }
												aria-hidden="true"
											/>
										}
									>
										{ __( 'Edit', 'cliezoo' ) }
									</MenuItem>
								) }
								{ !! onPin && (
									<MenuItem
										id="pin"
										icon={
											message.pinned ? (
												<PinOff
													size={ 15 }
													aria-hidden="true"
												/>
											) : (
												<Pin
													size={ 15 }
													aria-hidden="true"
												/>
											)
										}
									>
										{ message.pinned
											? __( 'Unpin', 'cliezoo' )
											: __(
													'Pin to the top',
													'cliezoo'
												) }
									</MenuItem>
								) }
								<MenuItem id="link">
									{ __( 'Copy link', 'cliezoo' ) }
								</MenuItem>
								{ ( !! message.mine || canModerate ) && (
									<MenuItem
										id="remove"
										icon={
											<Trash2
												size={ 15 }
												aria-hidden="true"
											/>
										}
									>
										{ __( 'Delete', 'cliezoo' ) }
									</MenuItem>
								) }
							</Menu>
						</MenuTrigger>
					) }
				</div>
			) }

			<ConfirmDialog
				isOpen={ removing }
				onOpenChange={ setRemoving }
				title={ __( 'Delete this message?', 'cliezoo' ) }
				description={ __(
					'It goes to the trash, where it can be put back.',
					'cliezoo'
				) }
				confirmLabel={ __( 'Delete it', 'cliezoo' ) }
				onConfirm={ async () => {
					try {
						await api.delete( `/comments/${ message.uid }` );
						onChanged();
					} catch ( error ) {
						toast.error( ( error as ApiError ).message );
					}
				} }
			/>
		</article>
	);
}
