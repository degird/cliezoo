/**
 * Tasks: every open task, mine, or what is coming up in the next two
 * weeks; and the overdue card, which is the late ones with how many.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Link } from '@tanstack/react-router';
import { api, formatDate, useQuery } from '@cliezoo/data';
import {
	ErrorState,
	Skeleton,
	Tab,
	TabList,
	TabPanel,
	Tabs,
	cx,
} from '@cliezoo/ui';
import {
	awaiting,
	daysFromToday,
	today,
	useCard,
	useMetrics,
	type Metric,
} from '../api';
import { MetricTile } from './Counters';

interface Task {
	uid: string;
	title: string;
	due_date?: string | null;
	board?: { uid: string; label: string } | null;
}

type Which = 'all' | 'mine' | 'upcoming' | 'late';

const OPEN = [
	{ field: 'parent', op: 'empty' },
	{ field: 'completed_at', op: 'empty' },
];

function useTasks( which: Which, limit: number ) {
	return useQuery( {
		queryKey: [ 'dashboard', 'tasks', which, limit ],
		queryFn: async () => {
			if ( which === 'mine' ) {
				return ( await api.get< Task[] >( '/tasks/mine', { limit } ) )
					.data;
			}
			const and: unknown[] = [ ...OPEN ];
			if ( which === 'upcoming' ) {
				and.push( {
					field: 'due_date',
					op: 'between',
					value: [ today(), daysFromToday( 14 ) ],
				} );
			}
			if ( which === 'late' ) {
				and.push( { field: 'due_date', op: 'lt', value: today() } );
			}
			return (
				await api.get< Task[] >( '/tasks', {
					filter: JSON.stringify( { and } ),
					sort: which === 'all' ? '-created_at' : 'due_date',
					limit,
				} )
			).data;
		},
	} );
}

function TaskList( { which, limit = 8 }: { which: Which; limit?: number } ) {
	const tasks = useTasks( which, limit );
	if ( tasks.isPending ) {
		return <Skeleton className="h-24" />;
	}
	if ( tasks.isError ) {
		return (
			<ErrorState
				error={ tasks.error }
				onRetry={ () => tasks.refetch() }
			/>
		);
	}
	if ( ! tasks.data.length ) {
		return (
			<p className="py-4 text-center text-sm text-muted">
				{ which === 'late'
					? __( 'Nothing is late.', 'cliezoo' )
					: __( 'No tasks here.', 'cliezoo' ) }
			</p>
		);
	}
	const now = today();
	return (
		<ul className="divide-y divide-line">
			{ tasks.data.map( ( task ) => {
				const late = !! task.due_date && task.due_date < now;
				return (
					<li
						key={ task.uid }
						className="flex items-center gap-2 py-1.5 text-sm"
					>
						<span className="flex min-w-0 flex-1 flex-col">
							<Link
								to="/tasks"
								search={ { task: task.uid } as never }
								className="truncate text-fg hover:underline"
							>
								{ task.title }
							</Link>
							{ !! task.board && (
								<span className="truncate text-xs text-subtle">
									{ task.board.label }
								</span>
							) }
						</span>
						{ !! task.due_date && (
							<span
								className={ cx(
									'shrink-0 text-xs tabular-nums',
									late
										? 'font-medium text-danger'
										: 'text-muted'
								) }
							>
								{ formatDate( task.due_date ) }
							</span>
						) }
					</li>
				);
			} ) }
		</ul>
	);
}

export default function Tasks() {
	const [ which, setWhich ] = useState< Which >( 'all' );
	return (
		<Tabs
			selectedKey={ which }
			onSelectionChange={ ( key: unknown ) =>
				setWhich( String( key ) as Which )
			}
		>
			<TabList
				aria-label={ __( 'Which tasks', 'cliezoo' ) }
				className="mb-2 flex gap-1 border-b border-line"
			>
				{ (
					[
						[ 'all', __( 'All', 'cliezoo' ) ],
						[ 'mine', __( 'Yours', 'cliezoo' ) ],
						[ 'upcoming', __( 'Upcoming', 'cliezoo' ) ],
					] as const
				 ).map( ( [ id, label ] ) => (
					<Tab
						key={ id }
						id={ id }
						className="cursor-pointer px-2 py-1 text-xs text-muted outline-none data-[selected]:border-b-2 data-[selected]:border-accent data-[selected]:text-fg data-[focus-visible]:ring-2 data-[focus-visible]:ring-accent"
					>
						{ label }
					</Tab>
				) ) }
			</TabList>
			{ ( [ 'all', 'mine', 'upcoming' ] as const ).map( ( id ) => (
				<TabPanel key={ id } id={ id }>
					<TaskList which={ id } />
				</TabPanel>
			) ) }
		</Tabs>
	);
}

/** How many late tasks the card lists before it says "and N more". */
const LATE_SHOWN = 5;

/**
 * How many late tasks the card has left to mention: never fewer than
 * none, and nothing at all when the count was never made.
 *
 * @param metric The overdue count.
 * @param shown  How many are listed.
 */
export function overdueMore( metric: Metric | undefined, shown: number ) {
	if ( ! metric || ! metric.measured || metric.value === null ) {
		return 0;
	}
	return Math.max( 0, metric.value - shown );
}

export function OverdueTasks() {
	const card = useCard();
	const numbers = useMetrics( [ 'tasks.overdue' ], {
		...card.period,
		compare: false,
	} );
	// Held numbers belong to the period before this one; see `awaiting`.
	const metric = awaiting( numbers )
		? undefined
		: numbers.data?.metrics[ 'tasks.overdue' ];
	const more = overdueMore( metric, LATE_SHOWN );
	return (
		<div>
			{ awaiting( numbers ) && <Skeleton className="h-14" /> }
			{ numbers.isError && (
				<ErrorState
					error={ numbers.error }
					onRetry={ () => numbers.refetch() }
				/>
			) }
			{ !! metric && <MetricTile metric={ metric } /> }
			<TaskList which="late" limit={ LATE_SHOWN } />
			{ more > 0 && (
				<p className="mt-1 text-xs text-muted">
					{ sprintf(
						/* translators: %d: how many more there are. */
						__( 'And %d more.', 'cliezoo' ),
						more
					) }
				</p>
			) }
			{ /* The tile and the list both count what is late now. The
			     dashboard's period does not narrow either of them, and a
			     card that says nothing about that reads as two numbers
			     disagreeing. */ }
			{ !! metric && ! metric.ranged && metric.measured && (
				<p className="mt-1 text-xs text-subtle">
					{ __(
						'Counted as things stand now, not over the dashboard’s period.',
						'cliezoo'
					) }
				</p>
			) }
		</div>
	);
}
