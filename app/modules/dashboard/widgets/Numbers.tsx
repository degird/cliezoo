/**
 * The small number cards — open requests, unread messages — and month on
 * month, which is this month against the last, number by number.
 */
import { Suspense } from 'react';
import { __ } from '@wordpress/i18n';
import { ErrorState, Skeleton, cx } from '@cliezoo/ui';
import { ActivityFeed } from '../../../packages/app';
import { change, formatMetric, useCard, useMetrics } from '../api';
import { MetricTile } from './Counters';

function Tiles( { keys }: { keys: string[] } ) {
	const card = useCard();
	const numbers = useMetrics( keys, card.period );
	// Held numbers from the period before are not this period's; see
	// `awaiting` for why they are not drawn under the new label.
	if ( numbers.isPending || numbers.isPlaceholderData ) {
		return <Skeleton className="h-16" />;
	}
	if ( numbers.isError ) {
		return (
			<ErrorState
				error={ numbers.error }
				onRetry={ () => numbers.refetch() }
			/>
		);
	}
	return (
		<div className="grid grid-cols-2 gap-2">
			{ keys
				.filter( ( key ) => numbers.data.metrics[ key ] )
				.map( ( key ) => (
					<MetricTile
						key={ key }
						metric={ numbers.data.metrics[ key ] }
					/>
				) ) }
		</div>
	);
}

export function OpenRequests() {
	return <Tiles keys={ [ 'requests.open', 'requests.new' ] } />;
}

export function Unread() {
	return <Tiles keys={ [ 'messages.unread' ] } />;
}

/**
 * Did we make money.
 *
 * The first question anybody opens a dashboard with, and the widget did not
 * exist. Invoiced and collected move with the period; what is owed does not —
 * it is owed now, and asking what was outstanding last March is a question
 * about a moment that has gone.
 */
export function Revenue() {
	return (
		<Tiles
			keys={ [
				'revenue.invoiced',
				'revenue.collected',
				'revenue.outstanding',
				'revenue.overdue',
			] }
		/>
	);
}

/**
 * Who owes us, and for how long.
 *
 * Counted from the day each invoice was due rather than the day it was sent:
 * "sixty days late" is a fact about the promise, not about the paperwork.
 */
export function Ageing() {
	return (
		<Tiles
			keys={ [ 'ar.current', 'ar.d30', 'ar.d60', 'ar.d90', 'ar.older' ] }
		/>
	);
}

/** What is still to play for. */
export function Pipeline() {
	return (
		<Tiles
			keys={ [ 'pipeline.open', 'pipeline.value', 'pipeline.expected' ] }
		/>
	);
}

/** What is waiting on somebody — the answer to "what is holding us up". */
export function Waiting() {
	return (
		<Tiles
			keys={ [
				'documents.awaiting_approval',
				'documents.awaiting_signature',
			] }
		/>
	);
}

/** The hours, which are not money and do not ask about it. */
export function Hours() {
	return (
		<Tiles
			keys={ [ 'hours.tracked', 'hours.billable', 'hours.unbilled' ] }
		/>
	);
}

const MONTHLY = [
	'clients.new',
	'projects.started',
	'tasks.created',
	'tasks.done',
	'requests.new',
	'leads.new',
	'leads.worth',
	'members.joined',
];

function colour( moved: ReturnType< typeof change > ): string {
	if ( ! moved || moved.direction === 'level' ) {
		return 'text-fg';
	}
	return moved.good ? 'text-success' : 'text-danger';
}

export function MonthOnMonth() {
	const numbers = useMetrics( MONTHLY, { range: 'month', compare: true } );
	if ( numbers.isPending ) {
		return <Skeleton className="h-32" />;
	}
	if ( numbers.isError ) {
		return (
			<ErrorState
				error={ numbers.error }
				onRetry={ () => numbers.refetch() }
			/>
		);
	}
	const rows = MONTHLY.filter( ( key ) => numbers.data.metrics[ key ] );
	return (
		<table className="w-full text-sm">
			<caption className="sr-only">
				{ __( 'This month against last month', 'cliezoo' ) }
			</caption>
			<thead>
				<tr className="text-xs text-muted">
					<th scope="col" className="py-1 text-start font-normal">
						<span className="sr-only">
							{ __( 'Number', 'cliezoo' ) }
						</span>
					</th>
					<th scope="col" className="py-1 text-end font-normal">
						{ __( 'Last month', 'cliezoo' ) }
					</th>
					<th scope="col" className="py-1 text-end font-normal">
						{ __( 'This month', 'cliezoo' ) }
					</th>
				</tr>
			</thead>
			<tbody className="divide-y divide-line">
				{ rows.map( ( key ) => {
					const metric = numbers.data.metrics[ key ];
					const moved = change( metric );
					return (
						<tr key={ key }>
							<th
								scope="row"
								className="py-1.5 text-start font-normal text-fg"
							>
								{ metric.label }
							</th>
							<td className="py-1.5 text-end tabular-nums text-muted">
								{ formatMetric(
									metric.previous ?? 0,
									metric.format
								) }
							</td>
							<td
								className={ cx(
									'py-1.5 text-end font-medium tabular-nums',
									colour( moved )
								) }
							>
								{ /* Not counted, so not drawn as nought. */ }
								{ metric.value === null
									? '—'
									: formatMetric(
											metric.value,
											metric.format
										) }
							</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}

export function Activity() {
	return (
		<div className="max-h-96 overflow-auto">
			<Suspense fallback={ <Skeleton className="h-32" /> }>
				<ActivityFeed showObject />
			</Suspense>
		</div>
	);
}
