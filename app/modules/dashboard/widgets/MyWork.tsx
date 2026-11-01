/**
 * My Work: what is on me, wherever it lives — tasks, to-dos on records,
 * requests I am handling, reminders coming up, today's meetings and the
 * documents waiting for my approval — in the order a day takes them: late
 * first, then by date, then what has no date. A timer I have running sits
 * above the list, because it is the one thing already under way.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Link } from '@tanstack/react-router';
import {
	BellRing,
	CalendarClock,
	CheckSquare,
	FileCheck2,
	Inbox,
	ListTodo,
	Timer,
	type LucideIcon,
} from 'lucide-react';
import {
	api,
	formatDate,
	getBoot,
	useModule,
	useQuery,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import {
	Checkbox,
	EmptyState,
	ErrorState,
	Skeleton,
	cx,
	toast,
} from '@cliezoo/ui';
import { recordPath } from '../../../packages/app/registry';
import { linkOf, localDay, today } from '../api';

interface Item {
	key: string;
	kind: 'task' | 'todo' | 'request' | 'reminder' | 'meeting' | 'approval';
	uid: string;
	title: string;
	due: string | null;
	href: string;
	search?: Record< string, string >;
	where?: string;
}

interface Ref {
	type?: string;
	uid: string;
	label: string;
}

const ICONS: Record< Item[ 'kind' ], LucideIcon > = {
	task: CheckSquare,
	todo: ListTodo,
	request: Inbox,
	reminder: BellRing,
	meeting: CalendarClock,
	approval: FileCheck2,
};

function kindLabel( kind: Item[ 'kind' ] ): string {
	const labels: Record< Item[ 'kind' ], string > = {
		task: __( 'Task', 'cliezoo' ),
		todo: __( 'To-do', 'cliezoo' ),
		request: __( 'Request', 'cliezoo' ),
		reminder: __( 'Reminder', 'cliezoo' ),
		meeting: __( 'Meeting today', 'cliezoo' ),
		approval: __( 'To approve', 'cliezoo' ),
	};
	return labels[ kind ];
}

/** What "My work" is made of, and what it has to say when a part is missing. */
export interface Work {
	items: Item[];
	/** The kinds that did not arrive, named so the card can say so. */
	missing: string[];
	/** The timer I have running, if one is. */
	running: { uid: string; title: string; started_at: string } | null;
}

/**
 * The names of the parts, in the order they are asked for, for the
 * sentence the card shows when one of them does not arrive.
 */
const PARTS = (): string[] => [
	__( 'tasks', 'cliezoo' ),
	__( 'to-dos', 'cliezoo' ),
	__( 'requests', 'cliezoo' ),
	__( 'reminders', 'cliezoo' ),
	__( 'meetings', 'cliezoo' ),
	__( 'approvals', 'cliezoo' ),
];

/**
 * Everything on me, together.
 *
 * @param limit How many of each kind to ask for.
 */
export function useMyWork( limit = 50 ) {
	const boards = useModule( 'boards' );
	const requests = useModule( 'requests' );
	const calendar = useModule( 'calendar' );
	const documents = useModule( 'documents' );
	const time = useModule( 'time' );
	const user = getBoot().user;
	const me = user?.uid ?? '';
	// Approving is for an owner or an admin, as the server holds it.
	const approves = !! user && ( user.owner || user.admin );
	return useQuery( {
		queryKey: [
			'dashboard',
			'my-work',
			me,
			limit,
			boards,
			requests,
			calendar,
			documents,
			time,
		],
		queryFn: async (): Promise< Work > => {
			// Four separate asks. One of them being refused is not a
			// reason to hide the other three: what arrived is shown, and
			// the card says which part is missing.
			const settled = await Promise.allSettled( [
				boards
					? api.get<
							Array< {
								uid: string;
								title: string;
								due_date?: string | null;
								board?: Ref | null;
							} >
						>( '/tasks/mine', { limit } )
					: Promise.resolve( { data: [] } ),
				api.get<
					Array< {
						uid: string;
						title: string;
						due_date?: string | null;
						record?: Ref | null;
					} >
				>( '/todos/mine', { done: false, limit } ),
				requests && me
					? api.get<
							Array< {
								uid: string;
								title: string;
								number?: string;
								due_date?: string | null;
							} >
						>( '/requests', {
							filter: JSON.stringify( {
								and: [
									{ field: 'assignee', op: 'eq', value: me },
									{
										field: 'status',
										op: 'nin',
										value: [ 'done', 'rejected' ],
									},
								],
							} ),
							limit,
						} )
					: Promise.resolve( { data: [] } ),
				api.get<
					Array< {
						uid: string;
						due_at: string;
						message: string | null;
						record: Ref | null;
					} >
				>( '/reminders/mine', { limit } ),
				calendar && me
					? api.get<
							Array< {
								uid: string;
								title: string;
								starts_at: string;
							} >
						>( '/meetings', {
							filter: JSON.stringify( {
								and: [
									{ field: 'owner', op: 'eq', value: me },
									{
										field: 'status',
										op: 'eq',
										value: 'booked',
									},
									{
										field: 'starts_at',
										op: 'within',
										value: 'today',
									},
								],
							} ),
							sort: 'starts_at',
							limit,
						} )
					: Promise.resolve( { data: [] } ),
				documents && approves
					? api.get<
							Array< {
								uid: string;
								name: string;
								number?: string;
							} >
						>( '/documents', {
							filter: JSON.stringify( {
								and: [
									{
										field: 'status',
										op: 'eq',
										value: 'awaiting_approval',
									},
								],
							} ),
							limit,
						} )
					: Promise.resolve( { data: [] } ),
			] );
			// The timer is not a list: whatever else happened, it is asked
			// for on its own and a refusal only means none is shown.
			const running = time
				? await api
						.get< {
							entry: {
								uid: string;
								title?: string | null;
								started_at: string;
							} | null;
						} >( '/time/running' )
						.then( ( one ) => one.data.entry )
						.catch( () => null )
				: null;
			const names = PARTS();
			const missing: string[] = [];
			const rows = < T, >(
				at: number,
				result: PromiseSettledResult< { data: T[] } >
			): T[] => {
				if ( result.status === 'fulfilled' ) {
					return result.value.data;
				}
				missing.push( names[ at ] );
				return [];
			};
			const tasks = rows( 0, settled[ 0 ] );
			const todos = rows( 1, settled[ 1 ] );
			const asked = rows( 2, settled[ 2 ] );
			const reminders = rows( 3, settled[ 3 ] );
			const meetings = rows( 4, settled[ 4 ] );
			const approvals = rows( 5, settled[ 5 ] );
			const week = new Date();
			week.setDate( week.getDate() + 7 );
			const items: Item[] = [
				...tasks.map( ( task ) => ( {
					key: `task:${ task.uid }`,
					kind: 'task' as const,
					uid: task.uid,
					title: task.title,
					due: task.due_date ?? null,
					href: '/my-tasks',
					search: { task: task.uid },
					where: task.board?.label,
				} ) ),
				...todos.map( ( todo ) => ( {
					key: `todo:${ todo.uid }`,
					kind: 'todo' as const,
					uid: todo.uid,
					title: todo.title,
					due: todo.due_date ?? null,
					href: todo.record?.type
						? recordPath( todo.record.type, todo.record.uid )
						: '',
					where: todo.record?.label,
				} ) ),
				...asked.map( ( one ) => ( {
					key: `request:${ one.uid }`,
					kind: 'request' as const,
					uid: one.uid,
					title: one.title,
					due: one.due_date ?? null,
					href: `/requests/${ one.uid }`,
					where: one.number,
				} ) ),
				...reminders
					.filter( ( one ) => new Date( one.due_at ) <= week )
					.map( ( one ) => ( {
						key: `reminder:${ one.uid }`,
						kind: 'reminder' as const,
						uid: one.uid,
						title:
							one.message ||
							one.record?.label ||
							__( 'Reminder', 'cliezoo' ),
						due: localDay( one.due_at ),
						href: one.record?.type
							? recordPath( one.record.type, one.record.uid )
							: '/reminders',
						where: one.record?.label,
					} ) ),
				...meetings.map( ( one ) => ( {
					key: `meeting:${ one.uid }`,
					kind: 'meeting' as const,
					uid: one.uid,
					title: one.title,
					due: localDay( one.starts_at ),
					href: `/meetings/${ one.uid }`,
					where: new Date( one.starts_at ).toLocaleTimeString( [], {
						hour: '2-digit',
						minute: '2-digit',
					} ),
				} ) ),
				...approvals.map( ( one ) => ( {
					key: `approval:${ one.uid }`,
					kind: 'approval' as const,
					uid: one.uid,
					title: one.name,
					due: null,
					href: `/documents/${ one.uid }`,
					where: one.number,
				} ) ),
			];
			items.sort( ( a, b ) => {
				if ( ! a.due || ! b.due ) {
					return a.due ? -1 : Number( !! b.due );
				}
				return a.due.localeCompare( b.due );
			} );
			return {
				items,
				missing,
				running: running
					? {
							uid: running.uid,
							title: running.title || __( 'Untitled', 'cliezoo' ),
							started_at: running.started_at,
						}
					: null,
			};
		},
	} );
}

function Row( {
	item,
	ticked = false,
	onDone,
}: {
	item: Item;
	/** Whether this row is being marked done; the box follows it. */
	ticked?: boolean;
	onDone?: () => void;
} ) {
	const Icon = ICONS[ item.kind ];
	const late = !! item.due && item.due < today();
	const title = item.href ? (
		<Link
			{ ...linkOf( item.href ) }
			{ ...( item.search ? { search: item.search } : {} ) }
			className="truncate text-fg hover:underline"
		>
			{ item.title }
		</Link>
	) : (
		<span className="truncate text-fg">{ item.title }</span>
	);
	return (
		<li className="flex items-center gap-2 py-1.5 text-sm">
			{ onDone ? (
				// Controlled, so that a tick the server refused comes
				// back off: an uncontrolled box keeps its own mind and
				// shows a task ticked that is not done.
				<Checkbox
					aria-label={ sprintf(
						/* translators: %s: what is done. */
						__( 'Mark “%s” done', 'cliezoo' ),
						item.title
					) }
					isSelected={ ticked }
					onChange={ ( on: boolean ) => on && onDone() }
				/>
			) : (
				<Icon
					size={ 16 }
					className="shrink-0 text-muted"
					aria-label={ kindLabel( item.kind ) }
				/>
			) }
			<span className="flex min-w-0 flex-1 flex-col">
				{ title }
				{ !! item.where && (
					<span className="truncate text-xs text-subtle">
						{ kindLabel( item.kind ) } · { item.where }
					</span>
				) }
			</span>
			{ !! item.due && (
				<span
					className={ cx(
						'shrink-0 text-xs tabular-nums',
						late ? 'font-medium text-danger' : 'text-muted'
					) }
				>
					{ late
						? sprintf(
								/* translators: %s: a date. */
								__( 'Late · %s', 'cliezoo' ),
								formatDate( item.due )
							)
						: formatDate( item.due ) }
				</span>
			) }
		</li>
	);
}

export default function MyWork( { limit = 8 }: { limit?: number } ) {
	const client = useQueryClient();
	const work = useMyWork( Math.min( 200, Math.max( 50, limit ) ) );
	const [ gone, setGone ] = useState< string[] >( [] );
	// Which row is on its way to being done, so its box stays ticked
	// while the server is asked and unticks itself if it refuses.
	const [ ticking, setTicking ] = useState< string[] >( [] );

	const done = async ( item: Item ) => {
		setTicking( ( list ) => [ ...list, item.key ] );
		setGone( ( list ) => [ ...list, item.key ] );
		try {
			if ( item.kind === 'task' ) {
				await api.post( `/tasks/${ item.uid }/complete`, {
					done: true,
				} );
			} else {
				await api.patch( `/todos/${ item.uid }`, { done: true } );
			}
			toast.success( __( 'Done.', 'cliezoo' ) );
			await client.invalidateQueries( { queryKey: [ 'dashboard' ] } );
		} catch ( error ) {
			// The row comes back, and so does its box: the task is not done.
			setGone( ( list ) => list.filter( ( key ) => key !== item.key ) );
			setTicking( ( list ) =>
				list.filter( ( key ) => key !== item.key )
			);
			toast.error( ( error as ApiError ).message );
		}
	};

	if ( work.isPending ) {
		return <Skeleton className="h-32" />;
	}
	if ( work.isError ) {
		return (
			<ErrorState error={ work.error } onRetry={ () => work.refetch() } />
		);
	}
	const items = work.data.items.filter(
		( item ) => ! gone.includes( item.key )
	);
	// A part that did not arrive is said out loud, whether or not anything
	// else did: an empty card must not mean "nothing is waiting on you"
	// when the truth is that we could not find out.
	const missing = work.data.missing.length ? (
		<p role="status" className="mt-2 text-xs text-warning">
			{ sprintf(
				/* translators: %s: the kinds of work that did not load, e.g. "requests". */
				__(
					'Your %s could not be loaded, so they are not here.',
					'cliezoo'
				),
				work.data.missing.join(
					/* translators: separates the kinds in a list, e.g. "tasks, to-dos". */
					__( ',', 'cliezoo' ) + ' '
				)
			) }
		</p>
	) : null;
	const timer = work.data.running ? (
		<p className="mb-1 flex items-center gap-2 rounded-md bg-sunken px-2 py-1.5 text-sm">
			<Timer
				size={ 16 }
				className="shrink-0 text-accent"
				aria-hidden="true"
			/>
			<span className="min-w-0 flex-1 truncate">
				{ sprintf(
					/* translators: %s: what the timer is for. */
					__( 'Timer running: %s', 'cliezoo' ),
					work.data.running.title
				) }
			</span>
			<Link
				{ ...linkOf( '/time' ) }
				className="shrink-0 text-xs text-accent hover:underline"
			>
				{ __( 'Open', 'cliezoo' ) }
			</Link>
		</p>
	) : null;
	if ( ! items.length ) {
		return (
			<>
				{ timer }
				{ ! work.data.missing.length && ! timer && (
					<EmptyState
						title={ __( 'Nothing is waiting on you', 'cliezoo' ) }
					>
						{ __(
							'To-dos and reminders given to you show up here.',
							'cliezoo'
						) }
					</EmptyState>
				) }
				{ missing }
			</>
		);
	}
	return (
		<>
			{ timer }
			<ul className="divide-y divide-line">
				{ items.slice( 0, limit ).map( ( item ) => (
					<Row
						key={ item.key }
						item={ item }
						ticked={ ticking.includes( item.key ) }
						onDone={
							item.kind === 'task' || item.kind === 'todo'
								? () => done( item )
								: undefined
						}
					/>
				) ) }
			</ul>
			{ items.length > limit && (
				<p className="mt-2 text-xs text-muted">
					{ sprintf(
						/* translators: %d: how many more there are. */
						__( 'And %d more.', 'cliezoo' ),
						items.length - limit
					) }
				</p>
			) }
			{ missing }
		</>
	);
}
