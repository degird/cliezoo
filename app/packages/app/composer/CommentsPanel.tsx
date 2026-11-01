/**
 * The conversation on a record: its comments, and the composer to add one.
 *
 * Any record's discussion — a task's, a request's — is the platform's
 * comments, so it is searched, notified and exported by machinery that
 * already exists, and written with the same composer as a message: a
 * mention, a file, a voice note or a reaction works the same everywhere.
 */
import { useState, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import {
	api,
	can,
	getBoot,
	sectionOf,
	useInfiniteQuery,
	useQueryClient,
} from '@cliezoo/data';
import { Button, ErrorState, Skeleton, cx } from '@cliezoo/ui';
import { Composer } from './Composer';
import { MessageItem, type Message } from './MessageItem';
import { plain } from './markdown';

/**
 * Comments in the order a conversation is read: each message, then its
 * replies under it.
 *
 * @param rows Comments, oldest first.
 */
export function threaded( rows: Message[] ): Message[] {
	const replies = new Map< string, Message[] >();
	const top: Message[] = [];
	const known = new Set( rows.map( ( one ) => one.uid ) );
	for ( const one of rows ) {
		if ( one.reply_to && known.has( one.reply_to ) ) {
			replies.set( one.reply_to, [
				...( replies.get( one.reply_to ) ?? [] ),
				one,
			] );
		} else {
			top.push( one );
		}
	}
	return top.flatMap( ( one ) => [
		one,
		...( replies.get( one.uid ) ?? [] ),
	] );
}

export function CommentsPanel( {
	type,
	uid,
	heading = __( 'Comments', 'cliezoo' ),
	placeholder = __( 'Write a comment…', 'cliezoo' ),
	empty = __( 'Nothing said about this yet.', 'cliezoo' ),
	canWrite = true,
	closedNotice,
	onSent,
	seed,
	below,
}: {
	/** Entity name of the record: 'tasks', 'requests'. */
	type: string;
	uid: string;
	heading?: string;
	placeholder?: string;
	empty?: string;
	/** Whether the reader may add to it. */
	canWrite?: boolean;
	/** Said instead of the composer when they may not. */
	closedNotice?: string;
	/** Called after something is said — saying it can change the record. */
	onSent?: () => void;
	/** Starts a new comment with this (a link to the part it is about). */
	seed?: string;
	/** Shown under a comment — a suggested edit and what to do with it. */
	below?: ( one: Message, refresh: () => void ) => ReactNode;
} ) {
	const queries = useQueryClient();
	const user = getBoot().user;
	const staff = 'staff' === user?.kind;
	// A client's portal says which sections they may add to; the server
	// holds the same line, so a composer they cannot use is a refusal
	// waiting to happen.
	const mayWrite = canWrite && can.add( user, sectionOf( type ) );
	const [ replying, setReplying ] = useState< Message | null >( null );
	const key = [ 'comments', type, uid ];
	const id = `comments-${ type }-${ uid }`;

	// Newest first, a page at a time, then read oldest first: the latest
	// message is always there, however long the conversation.
	const comments = useInfiniteQuery( {
		queryKey: key,
		initialPageParam: null as string | null,
		// The same twenty seconds the conversations screen uses. This is
		// the same thread on a request or a task, and a thread that only
		// changes when somebody reloads it is not a conversation.
		refetchInterval: 20_000,
		queryFn: ( { pageParam, signal } ) =>
			api.get< Message[] >(
				'/comments',
				{
					object_type: type,
					object: uid,
					limit: 50,
					newest: true,
					cursor: pageParam ?? undefined,
				},
				signal
			),
		getNextPageParam: ( last ) =>
			last.meta?.has_more
				? ( ( last.meta.next_cursor as string ) ?? null )
				: null,
	} );
	const refresh = () => queries.invalidateQueries( { queryKey: key } );
	const rows = threaded(
		( comments.data?.pages ?? [] )
			.flatMap( ( page ) => page.data )
			.slice()
			.reverse()
	);
	const byUid = new Map( rows.map( ( one ) => [ one.uid, one ] ) );

	return (
		<section aria-labelledby={ id } className="space-y-3">
			<h3 id={ id } className="text-sm font-semibold text-fg">
				{ heading }
			</h3>

			{ comments.isPending && <Skeleton className="h-16" /> }
			{ comments.isError && (
				<ErrorState
					error={ comments.error }
					onRetry={ () => comments.refetch() }
				/>
			) }
			{ comments.isSuccess && ! rows.length && (
				<p className="text-sm text-muted">{ empty }</p>
			) }

			{ comments.hasNextPage && (
				<Button
					size="sm"
					variant="ghost"
					isPending={ comments.isFetchingNextPage }
					onPress={ () => comments.fetchNextPage() }
				>
					{ __( 'Show earlier', 'cliezoo' ) }
				</Button>
			) }

			<ul className="space-y-1">
				{ rows.map( ( one ) => (
					<li
						key={ one.uid }
						className={ cx( !! one.reply_to && 'ms-8' ) }
					>
						<MessageItem
							message={ one }
							canModerate={ staff }
							onChanged={ refresh }
							onReply={
								mayWrite
									? () =>
											setReplying(
												( one.reply_to &&
													byUid.get(
														one.reply_to
													) ) ||
													one
											)
									: undefined
							}
						/>
						{ below?.( one, refresh ) }
					</li>
				) ) }
			</ul>

			{ mayWrite ? (
				<Composer
					// Keyed on what the box is started with, never on who is
					// being answered: picking a message to reply to is aiming
					// the comment, and remounting for it would throw away the
					// attachments already uploaded to go with it.
					key={ seed ?? 'new' }
					type={ type }
					uid={ uid }
					canNote={ staff }
					draftKey={ `${ type }:${ uid }` }
					seed={ replying ? undefined : seed }
					// "Comment" on a block is asking to write one.
					// eslint-disable-next-line jsx-a11y/no-autofocus
					autoFocus={ !! seed && ! replying }
					lockNote={ !! replying?.internal }
					placeholder={ placeholder }
					replyTo={
						replying
							? {
									who:
										replying.created_by?.label ??
										__( 'Somebody', 'cliezoo' ),
									text: plain( replying.body, 80 ),
								}
							: null
					}
					onClearReply={ () => setReplying( null ) }
					onSend={ async ( message ) => {
						await api.post( '/comments', {
							object_type: type,
							object: uid,
							body: message.body,
							internal: message.internal || !! replying?.internal,
							files: message.files,
							...( replying ? { parent: replying.uid } : {} ),
						} );
						setReplying( null );
						await refresh();
						onSent?.();
					} }
				/>
			) : (
				!! closedNotice && (
					<p className="rounded-lg bg-sunken p-3 text-sm text-muted">
						{ closedNotice }
					</p>
				)
			) }
		</section>
	);
}
