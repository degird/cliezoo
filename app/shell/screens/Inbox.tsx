/**
 * Inbox: notifications by tab, unread first-class, mark read or unread,
 * snooze, archive.
 *
 * A client's inbox has the two tabs a client has a use for — their
 * messages and the updates about their work — rather than a team's eight
 * with six of them always empty.
 */
import { Suspense, useEffect } from 'react';
import { useNavigate, useParams } from '@tanstack/react-router';
import { __ } from '@wordpress/i18n';
import {
	Archive,
	CheckCheck,
	Clock,
	Inbox as InboxIcon,
	Mail,
	MailOpen,
} from 'lucide-react';
import {
	api,
	formatRelative,
	getBoot,
	useList,
	useQuery,
	useQueryClient,
} from '@cliezoo/data';
import {
	Avatar,
	Button,
	ErrorState,
	EmptyState,
	IconButton,
	Menu,
	MenuItem,
	MenuTrigger,
	Skeleton,
	Tab,
	TabList,
	TabPanel,
	Tabs,
	cx,
	toast,
} from '@cliezoo/ui';
import { Slot, useSlotHasFills } from '../../packages/app/slot';
import { sendTimes } from '../../packages/app/composer/schedule';
import { senderZone } from '../../packages/app/composer/zone';
import { Page, PageHeader } from '../Page';

interface Notification {
	uid: string;
	event: string;
	tab: string;
	title: string;
	body: string | null;
	path: string | null;
	actor: { name: string; avatar?: string } | null;
	read: boolean;
	created_at: string;
}

/**
 * When a snoozed notification comes back.
 *
 * @param choice hour | tomorrow | week.
 */
function until( choice: string ): string {
	const when = new Date();
	if ( 'hour' === choice ) {
		when.setHours( when.getHours() + 1 );
		return when.toISOString();
	}
	const times = sendTimes( when, senderZone() );
	return (
		( 'week' === choice ? times[ 2 ] : times[ 0 ] )?.at ??
		when.toISOString()
	);
}

const TABS = ( client: boolean ): Array< [ string, string ] > =>
	client
		? [
				[ 'all', __( 'All', 'cliezoo' ) ],
				[ 'updates', __( 'Updates', 'cliezoo' ) ],
			]
		: [
				[ 'all', __( 'All', 'cliezoo' ) ],
				[ 'mentions', __( 'Mentions', 'cliezoo' ) ],
				[ 'files', __( 'Files', 'cliezoo' ) ],
				[ 'updates', __( 'Updates', 'cliezoo' ) ],
			];

export default function Inbox() {
	const isClient = 'client' === getBoot().user?.kind;
	const params = useParams( { strict: false } ) as { tab?: string };
	const tab = TABS( isClient ).some( ( [ id ] ) => id === params.tab )
		? String( params.tab )
		: 'all';
	const navigate = useNavigate();
	const setTab = ( next: string ) =>
		navigate( {
			to: ( 'all' === next ? '/inbox' : `/inbox/${ next }` ) as never,
			replace: true,
			resetScroll: false,
		} );
	const stray = !! params.tab && 'all' === tab;
	useEffect( () => {
		if ( stray ) {
			navigate( { to: '/inbox' as never, replace: true } );
		}
	}, [ stray, navigate ] );
	const chats = useSlotHasFills( 'inbox.chats' );
	const client = useQueryClient();
	const list = useList< Notification >(
		'/notifications',
		{ tab },
		{ limit: 30 }
	);
	const counts = useQuery( {
		queryKey: [ 'notifications', 'counts' ],
		queryFn: async () =>
			(
				await api.get< Record< string, number > >(
					'/notifications/counts'
				)
			).data,
	} );
	const items = list.data?.pages.flatMap( ( page ) => page.data ) ?? [];
	// Greyed only when the count arrived and said nothing is unread. A
	// count that failed, or has not come back yet, is not an empty inbox:
	// gating on the data alone left a full inbox with the one button that
	// clears it greyed out and no reason given.
	const nothingUnread = counts.isSuccess && ! counts.data.all;

	const mark = async (
		body: Record< string, unknown >,
		message?: string,
		action?: { label: string; onAction: () => void }
	) => {
		try {
			await api.post( '/notifications/mark', body );
			await client.invalidateQueries( { queryKey: [ 'notifications' ] } );
			if ( message ) {
				toast.success( message, { action } );
			}
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		}
	};

	return (
		// The tabs and the list they filter are one Tabs, so the list is the
		// tab's panel rather than a strip of buttons with nothing attached.
		<Tabs
			selectedKey={ tab }
			onSelectionChange={ ( key ) => setTab( String( key ) ) }
		>
			<PageHeader
				title={ __( 'Inbox', 'cliezoo' ) }
				actions={
					<>
						<Slot name="inbox.toolbar" variant="chip" />
						<Button
							icon={
								<CheckCheck size={ 16 } aria-hidden="true" />
							}
							onPress={ () =>
								mark(
									{ all: true, read: true },
									__( 'All caught up.', 'cliezoo' )
								)
							}
							isDisabled={ nothingUnread }
						>
							{ __( 'Mark all as read', 'cliezoo' ) }
						</Button>
					</>
				}
			>
				<TabList
					aria-label={ __( 'Inbox tabs', 'cliezoo' ) }
					className="-mb-px flex gap-1 overflow-x-auto"
				>
					{ TABS( isClient ).map( ( [ id, label ] ) => (
						<Tab
							key={ id }
							id={ id }
							className="flex cursor-default items-center gap-1.5 whitespace-nowrap border-b-2 border-transparent px-2.5 pb-2.5 pt-1 text-sm font-medium text-muted outline-none hover:text-fg data-[focus-visible]:outline-2 data-[focus-visible]:outline-focus data-[selected]:border-accent data-[selected]:text-fg"
						>
							{ label }
							{ !! counts.data?.[ id ] && (
								<span className="rounded-full bg-accent-subtle px-1.5 text-xs text-accent-fg">
									{ counts.data[ id ] }
								</span>
							) }
						</Tab>
					) ) }
				</TabList>
			</PageHeader>
			{ /* A panel for each tab, named by it: one panel wearing
			     whichever id was selected left every other tab pointing
			     at an element that did not exist. React Aria draws only
			     the panel that is open. */ }
			{ TABS( isClient ).map( ( [ id ] ) => (
				<TabPanel key={ id } id={ id }>
					<Page width="narrow">
						{ 'chats' === tab && chats ? (
							<Suspense
								fallback={ <Skeleton className="h-24" /> }
							>
								<Slot name="inbox.chats" badge={ false } />
							</Suspense>
						) : (
							<>
								{ list.isPending && (
									<div className="space-y-3">
										{ [ 0, 1, 2, 3 ].map( ( i ) => (
											<Skeleton
												key={ i }
												className="h-14"
											/>
										) ) }
									</div>
								) }
								{ list.isError && (
									<ErrorState
										error={ list.error }
										onRetry={ () => list.refetch() }
									/>
								) }
								{ ! list.isPending &&
									! list.isError &&
									! items.length && (
										<EmptyState
											icon={
												<InboxIcon
													size={ 22 }
													aria-hidden="true"
												/>
											}
											title={ __(
												'Nothing here',
												'cliezoo'
											) }
										>
											{ __(
												'Mentions, messages and updates about your work will appear here.',
												'cliezoo'
											) }
										</EmptyState>
									) }
								{ items.length > 0 && (
									<ul className="divide-y divide-line overflow-hidden rounded-lg border border-line bg-raised">
										{ items.map( ( item ) => (
											<li
												key={ item.uid }
												className={ cx(
													'group flex items-start gap-3 px-4 py-3',
													! item.read &&
														'bg-accent-subtle/40'
												) }
											>
												<Avatar
													name={
														item.actor?.name ?? '•'
													}
													src={ item.actor?.avatar }
													size="sm"
												/>
												<button
													type="button"
													className="min-w-0 flex-1 rounded text-start focus-visible:outline-2 focus-visible:outline-focus"
													onClick={ () => {
														if ( ! item.read ) {
															mark( {
																uids: [
																	item.uid,
																],
																read: true,
															} );
														}
														if ( item.path ) {
															navigate( {
																to: item.path,
															} );
														}
													} }
												>
													<p
														className={ cx(
															'text-sm',
															item.read
																? 'text-muted'
																: 'font-medium text-fg'
														) }
													>
														{ ! item.read && (
															<span className="sr-only">
																{ __(
																	'Unread:',
																	'cliezoo'
																) }{ ' ' }
															</span>
														) }
														{ item.title }
													</p>
													{ item.body && (
														<p className="mt-0.5 line-clamp-2 text-sm text-muted">
															{ item.body }
														</p>
													) }
													<p className="mt-1 text-xs text-subtle">
														{ formatRelative(
															item.created_at
														) }
													</p>
												</button>
												<IconButton
													size="sm"
													label={
														item.read
															? __(
																	'Mark unread',
																	'cliezoo'
																)
															: __(
																	'Mark read',
																	'cliezoo'
																)
													}
													icon={
														item.read ? (
															<Mail
																size={ 15 }
																aria-hidden="true"
															/>
														) : (
															<MailOpen
																size={ 15 }
																aria-hidden="true"
															/>
														)
													}
													onPress={ () =>
														mark( {
															uids: [ item.uid ],
															read: ! item.read,
														} )
													}
												/>
												<MenuTrigger>
													<IconButton
														size="sm"
														label={ __(
															'Snooze',
															'cliezoo'
														) }
														icon={
															<Clock
																size={ 15 }
																aria-hidden="true"
															/>
														}
													/>
													<Menu
														onAction={ ( key ) =>
															mark(
																{
																	uids: [
																		item.uid,
																	],
																	snooze_until:
																		until(
																			String(
																				key
																			)
																		),
																},
																__(
																	'Snoozed.',
																	'cliezoo'
																)
															)
														}
													>
														<MenuItem id="hour">
															{ __(
																'For an hour',
																'cliezoo'
															) }
														</MenuItem>
														<MenuItem id="tomorrow">
															{ __(
																'Until tomorrow morning',
																'cliezoo'
															) }
														</MenuItem>
														<MenuItem id="week">
															{ __(
																'Until next week',
																'cliezoo'
															) }
														</MenuItem>
													</Menu>
												</MenuTrigger>
												<IconButton
													size="sm"
													label={ __(
														'Archive',
														'cliezoo'
													) }
													icon={
														<Archive
															size={ 15 }
															aria-hidden="true"
														/>
													}
													/* Nothing in the app shows
													   what has been archived,
													   so the toast is the only
													   way back to it. */
													onPress={ () =>
														mark(
															{
																uids: [
																	item.uid,
																],
																archive: true,
															},
															__(
																'Archived.',
																'cliezoo'
															),
															{
																label: __(
																	'Undo',
																	'cliezoo'
																),
																onAction: () =>
																	mark(
																		{
																			uids: [
																				item.uid,
																			],
																			archive: false,
																		},
																		__(
																			'Back in your inbox.',
																			'cliezoo'
																		)
																	),
															}
														)
													}
												/>
											</li>
										) ) }
									</ul>
								) }
								{ list.hasNextPage && (
									<div className="mt-4 flex justify-center">
										<Button
											onPress={ () =>
												list.fetchNextPage()
											}
											isPending={
												list.isFetchingNextPage
											}
										>
											{ __( 'Load more', 'cliezoo' ) }
										</Button>
									</div>
								) }
							</>
						) }
					</Page>
				</TabPanel>
			) ) }
		</Tabs>
	);
}
