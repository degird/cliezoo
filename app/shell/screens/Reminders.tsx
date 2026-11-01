/**
 * My reminders: what is coming up for you, with pause, skip and send now,
 * and a "remind me" box for any record.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	BellRing,
	Forward,
	Pause,
	Play,
	SkipForward,
	User,
} from 'lucide-react';
import {
	api,
	formatDate,
	formatRelative,
	formatTime,
	useList,
	useQueryClient,
} from '@cliezoo/data';
import {
	Badge,
	Button,
	ConfirmDialog,
	ErrorState,
	EmptyState,
	IconButton,
	Skeleton,
	toast,
} from '@cliezoo/ui';
import { Page, PageHeader } from '../Page';

interface Reminder {
	uid: string;
	personal: boolean;
	occurrence: number;
	due_at: string;
	status: string;
	message: string | null;
	/** What the delivery did, once it has been tried. */
	result: { members?: number; emails?: number; held?: number } | null;
	record: {
		type: string;
		uid: string;
		label: string;
		number: string | null;
	} | null;
}

/**
 * What a "Send now" actually did.
 *
 * `deliver()` answers true whenever it claimed the occurrence, so a
 * reminder whose recipients all turned out to be unreachable answers the
 * same as one that reached a client. The counts it wrote are the only
 * honest thing to report.
 *
 * @param result The delivery counts, when the server sent any back.
 */
function delivered( result: Reminder[ 'result' ] ): string {
	const reached = ( result?.members ?? 0 ) + ( result?.emails ?? 0 );
	const held = result?.held ?? 0;
	if ( ! reached && ! held ) {
		return __(
			'Nobody was left to send it to, so nothing went out.',
			'cliezoo'
		);
	}
	if ( ! reached ) {
		return sprintf(
			/* translators: %d: how many people. */
			_n(
				'Held for quiet hours: %d person gets it when the working day starts.',
				'Held for quiet hours: %d people get it when the working day starts.',
				held,
				'cliezoo'
			),
			held
		);
	}
	return sprintf(
		/* translators: %d: how many people. */
		_n( 'Sent to %d person.', 'Sent to %d people.', reached, 'cliezoo' ),
		reached
	);
}

function statusBadge( status: string ): {
	label: string;
	tone: 'neutral' | 'warning';
} {
	return 'paused' === status
		? { label: __( 'Paused', 'cliezoo' ), tone: 'warning' }
		: { label: __( 'Scheduled', 'cliezoo' ), tone: 'neutral' };
}

export default function Reminders() {
	const client = useQueryClient();
	const list = useList< Reminder >( '/reminders/mine', {}, { limit: 50 } );
	const items = list.data?.pages.flatMap( ( page ) => page.data ) ?? [];

	// The one being asked about, before it is sent or skipped for good.
	const [ asking, setAsking ] = useState< {
		uid: string;
		verb: 'send' | 'skip';
		what: string;
	} | null >( null );

	const control = async ( uid: string, verb: string, message: string ) => {
		try {
			const answer = await api.post< Reminder >(
				`/reminders/${ uid }/${ verb }`
			);
			await client.invalidateQueries( { queryKey: [ 'reminders' ] } );
			toast.success(
				'send' === verb ? delivered( answer.data.result ) : message
			);
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		}
	};

	return (
		<>
			<PageHeader
				title={ __( 'Reminders', 'cliezoo' ) }
				meta={ __( 'What is coming up for you', 'cliezoo' ) }
			/>
			<Page width="narrow">
				{ list.isPending && <Skeleton className="h-40" /> }
				{ list.isError && (
					<ErrorState
						error={ list.error }
						onRetry={ () => list.refetch() }
					/>
				) }
				{ ! list.isPending && ! list.isError && ! items.length && (
					<EmptyState
						icon={ <BellRing size={ 20 } aria-hidden="true" /> }
						title={ __( 'Nothing to remind you about', 'cliezoo' ) }
					>
						{ __(
							'Reminders you set yourself, and the ones your workspace schedules for your records, appear here.',
							'cliezoo'
						) }
					</EmptyState>
				) }
				{ !! items.length && (
					<ul className="divide-y divide-line overflow-hidden rounded-lg border border-line bg-raised">
						{ items.map( ( item ) => {
							const badge = statusBadge( item.status );
							const paused = 'paused' === item.status;
							return (
								<li
									key={ item.uid }
									className="flex flex-wrap items-center gap-3 px-4 py-3"
								>
									<span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-accent-subtle text-accent-fg">
										{ item.personal ? (
											<User
												size={ 15 }
												aria-hidden="true"
											/>
										) : (
											<BellRing
												size={ 15 }
												aria-hidden="true"
											/>
										) }
									</span>
									<span className="min-w-0 flex-1">
										<span className="block truncate text-sm font-medium text-fg">
											{ item.record?.label ??
												__(
													'A record you can no longer see',
													'cliezoo'
												) }
										</span>
										<span className="block truncate text-xs text-muted">
											{ item.message ||
												__(
													'Scheduled reminder',
													'cliezoo'
												) }
										</span>
									</span>
									<span className="text-end text-xs text-muted">
										<span className="block">
											{ formatRelative( item.due_at ) }
										</span>
										<span className="block">
											{ formatDate( item.due_at ) }
											{ ' · ' }
											{ formatTime( item.due_at ) }
										</span>
									</span>
									<Badge tone={ badge.tone }>
										{ badge.label }
									</Badge>
									<IconButton
										size="sm"
										label={
											paused
												? __( 'Resume', 'cliezoo' )
												: __( 'Pause', 'cliezoo' )
										}
										icon={
											paused ? (
												<Play
													size={ 14 }
													aria-hidden="true"
												/>
											) : (
												<Pause
													size={ 14 }
													aria-hidden="true"
												/>
											)
										}
										onPress={ () =>
											control(
												item.uid,
												paused ? 'resume' : 'pause',
												paused
													? __(
															'Reminder resumed.',
															'cliezoo'
														)
													: __(
															'Reminder paused.',
															'cliezoo'
														)
											)
										}
									/>
									<IconButton
										size="sm"
										label={ __( 'Send now', 'cliezoo' ) }
										icon={
											<Forward
												size={ 14 }
												aria-hidden="true"
											/>
										}
										onPress={ () =>
											setAsking( {
												uid: item.uid,
												verb: 'send',
												what:
													item.record?.label ??
													__(
														'this reminder',
														'cliezoo'
													),
											} )
										}
									/>
									<IconButton
										size="sm"
										label={ __( 'Skip', 'cliezoo' ) }
										icon={
											<SkipForward
												size={ 14 }
												aria-hidden="true"
											/>
										}
										onPress={ () =>
											setAsking( {
												uid: item.uid,
												verb: 'skip',
												what:
													item.record?.label ??
													__(
														'this reminder',
														'cliezoo'
													),
											} )
										}
									/>
								</li>
							);
						} ) }
					</ul>
				) }
				{ list.hasNextPage && (
					<div className="mt-4 text-center">
						<Button
							variant="secondary"
							isPending={ list.isFetchingNextPage }
							onPress={ () => list.fetchNextPage() }
						>
							{ __( 'Show more', 'cliezoo' ) }
						</Button>
					</div>
				) }
			</Page>

			{ /* Sending reaches whoever the rule names, which can be the
			     client, and a skipped occurrence leaves this list for
			     good: /reminders/mine answers with the pending and the
			     paused only. Both are worth a question first. */ }
			<ConfirmDialog
				isOpen={ !! asking }
				onOpenChange={ ( open ) => ! open && setAsking( null ) }
				title={
					'send' === asking?.verb
						? sprintf(
								/* translators: %s: what the reminder is about. */
								__( 'Send the reminder about %s?', 'cliezoo' ),
								asking.what
							)
						: sprintf(
								/* translators: %s: what the reminder is about. */
								__( 'Skip the reminder about %s?', 'cliezoo' ),
								asking?.what ?? ''
							)
				}
				description={
					'send' === asking?.verb
						? __(
								'It goes out now to everyone the reminder is set for, which can include the client. A sent reminder cannot be called back.',
								'cliezoo'
							)
						: __(
								'It will not be sent, and it leaves this list for good. The reminders after it, if the rule has any, still go.',
								'cliezoo'
							)
				}
				confirmLabel={
					'send' === asking?.verb
						? __( 'Send it now', 'cliezoo' )
						: __( 'Skip it', 'cliezoo' )
				}
				onConfirm={ () => {
					if ( ! asking ) {
						return;
					}
					control(
						asking.uid,
						asking.verb,
						__( 'Reminder skipped.', 'cliezoo' )
					);
					setAsking( null );
				} }
			/>
		</>
	);
}
