/**
 * The calendar: a month of what falls due — tasks and milestones — laid
 * out by day, so a heavy week shows before it arrives.
 */
import { useMemo, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Link } from '@tanstack/react-router';
import { ChevronLeft, ChevronRight, Flag } from 'lucide-react';
import { api, getBoot, useModule, useQuery } from '@cliezoo/data';
import { Button, ErrorState, IconButton, Skeleton, cx } from '@cliezoo/ui';
import { today } from './api';

interface Due {
	kind: 'task' | 'milestone';
	uid: string;
	title: string;
	date: string;
}

/** The most of each kind asked for in one month. */
const LIMIT = 200;

const pad = ( n: number ) => String( n ).padStart( 2, '0' );
const ymd = ( d: Date ) =>
	`${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;

function useDue( first: string, last: string ) {
	const boards = useModule( 'boards' );
	const milestones = useModule( 'milestones' );
	return useQuery( {
		queryKey: [ 'dashboard', 'calendar', first, last, boards, milestones ],
		queryFn: async (): Promise< { items: Due[]; more: boolean } > => {
			const between = {
				field: 'due_date',
				op: 'between',
				value: [ first, last ],
			};
			const [ tasks, marks ] = await Promise.all( [
				boards
					? api.get<
							Array< {
								uid: string;
								title: string;
								due_date: string;
							} >
						>( '/tasks', {
							filter: JSON.stringify( {
								and: [
									between,
									{ field: 'parent', op: 'empty' },
									{ field: 'completed_at', op: 'empty' },
								],
							} ),
							sort: 'due_date',
							limit: 200,
						} )
					: Promise.resolve( { data: [] } ),
				milestones
					? api.get<
							Array< {
								uid: string;
								name: string;
								due_date: string;
							} >
						>( '/milestones', {
							filter: JSON.stringify( {
								and: [
									between,
									{ field: 'closed_at', op: 'empty' },
								],
							} ),
							sort: 'due_date',
							limit: 200,
						} )
					: Promise.resolve( { data: [] } ),
			] );
			const more =
				tasks.data.length >= LIMIT || marks.data.length >= LIMIT;
			const items = [
				...tasks.data.map( ( task ) => ( {
					kind: 'task' as const,
					uid: task.uid,
					title: task.title,
					date: task.due_date,
				} ) ),
				...marks.data.map( ( mark ) => ( {
					kind: 'milestone' as const,
					uid: mark.uid,
					title: mark.name,
					date: mark.due_date,
				} ) ),
			];
			return { items, more };
		},
	} );
}

export default function Calendar() {
	const [ month, setMonth ] = useState( () => {
		const now = new Date();
		return new Date( now.getFullYear(), now.getMonth(), 1 );
	} );
	const days = useMemo( () => {
		const boot = getBoot();
		// The person's own first day of the week, or the workspace's.
		const start = boot.user?.weekStart ?? boot.workspace.week_start ?? 1;
		const first = new Date( month );
		first.setDate( 1 - ( ( first.getDay() - start + 7 ) % 7 ) );
		return Array.from( { length: 42 }, ( _, i ) => {
			const day = new Date( first );
			day.setDate( first.getDate() + i );
			return day;
		} );
	}, [ month ] );
	// Days somebody asked to see the whole of. A day shows four things
	// and says how many more there are; before this the rest were
	// simply out of reach, which is a promise the calendar broke.
	const [ opened, setOpened ] = useState< string[] >( [] );
	const first = ymd( days[ 0 ] );
	const last = ymd( days[ days.length - 1 ] );
	const due = useDue( first, last );
	const now = today();
	const byDay = new Map< string, Due[] >();
	( due.data?.items ?? [] ).forEach( ( one ) => {
		byDay.set( one.date, [ ...( byDay.get( one.date ) ?? [] ), one ] );
	} );
	const heading = month.toLocaleDateString( undefined, {
		month: 'long',
		year: 'numeric',
	} );
	const shift = ( by: number ) =>
		setMonth( new Date( month.getFullYear(), month.getMonth() + by, 1 ) );

	return (
		<div>
			<div className="mb-3 flex items-center gap-2">
				<h2
					className="text-base font-semibold text-fg"
					aria-live="polite"
				>
					{ heading }
				</h2>
				<div className="ms-auto flex items-center gap-1">
					<IconButton
						size="sm"
						label={ __( 'Month before', 'cliezoo' ) }
						icon={ <ChevronLeft size={ 16 } aria-hidden="true" /> }
						onPress={ () => shift( -1 ) }
					/>
					<Button
						size="sm"
						variant="ghost"
						onPress={ () => {
							const d = new Date();
							setMonth(
								new Date( d.getFullYear(), d.getMonth(), 1 )
							);
						} }
					>
						{ __( 'This month', 'cliezoo' ) }
					</Button>
					<IconButton
						size="sm"
						label={ __( 'Month after', 'cliezoo' ) }
						icon={ <ChevronRight size={ 16 } aria-hidden="true" /> }
						onPress={ () => shift( 1 ) }
					/>
				</div>
			</div>
			{ due.isError && (
				<ErrorState
					error={ due.error }
					onRetry={ () => due.refetch() }
				/>
			) }
			{ !! due.data?.more && (
				<p className="mb-2 text-xs text-warning">
					{ __(
						'This month holds more than can be shown at once; some days show only part of what falls due.',
						'cliezoo'
					) }
				</p>
			) }
			{ /* Nothing came back, so nothing is drawn. Forty-two empty
			     cells under an error say the month is clear, which is the
			     one thing they cannot know. */ }
			{ ! due.isError && (
				<div
					className="hidden grid-cols-7 gap-px pb-1 text-center text-xs text-muted sm:grid"
					aria-hidden="true"
				>
					{ days.slice( 0, 7 ).map( ( day ) => (
						<span key={ ymd( day ) }>
							{ day.toLocaleDateString( undefined, {
								weekday: 'short',
							} ) }
						</span>
					) ) }
				</div>
			) }
			{ due.isPending && <Skeleton className="h-96" /> }
			{ ! due.isPending && ! due.isError && (
				<ol className="grid grid-cols-1 gap-px overflow-hidden rounded-lg border border-line bg-line sm:grid-cols-7">
					{ days.map( ( day ) => {
						const key = ymd( day );
						const items = byDay.get( key ) ?? [];
						const outside = day.getMonth() !== month.getMonth();
						return (
							<li
								key={ key }
								aria-label={ day.toLocaleDateString(
									undefined,
									{
										weekday: 'long',
										day: 'numeric',
										month: 'long',
									}
								) }
								className={ cx(
									'min-h-24 bg-raised p-1.5',
									outside && 'hidden bg-sunken sm:block',
									! items.length &&
										! outside &&
										'max-sm:hidden'
								) }
							>
								<span
									className={ cx(
										'inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1 text-xs tabular-nums',
										key === now
											? 'bg-accent font-semibold text-accent-fg'
											: 'text-muted'
									) }
								>
									<span className="sm:hidden">
										{ day.toLocaleDateString( undefined, {
											weekday: 'short',
											day: 'numeric',
										} ) }
									</span>
									<span className="max-sm:hidden">
										{ day.getDate() }
									</span>
								</span>
								<ul className="mt-1 space-y-0.5">
									{ items
										.slice(
											0,
											opened.includes( key )
												? items.length
												: 4
										)
										.map( ( one ) => (
											<li
												key={ `${ one.kind }:${ one.uid }` }
											>
												<Link
													to={
														one.kind === 'task'
															? '/tasks'
															: `/milestones/${ one.uid }`
													}
													search={
														( one.kind === 'task'
															? { task: one.uid }
															: {} ) as never
													}
													className={ cx(
														// At least 24px tall, and
														// spaced: a row of things
														// due is still something
														// people press with a
														// thumb (WCAG 2.2, 2.5.8).
														'flex min-h-6 items-center gap-1 truncate rounded px-1 py-1 text-xs hover:bg-sunken',
														key < now
															? 'text-danger'
															: 'text-fg'
													) }
												>
													{ one.kind ===
														'milestone' && (
														<Flag
															size={ 11 }
															aria-label={ __(
																'Milestone',
																'cliezoo'
															) }
														/>
													) }
													<span className="truncate">
														{ one.title }
													</span>
												</Link>
											</li>
										) ) }
									{ items.length > 4 &&
										! opened.includes( key ) && (
											<li>
												<Button
													variant="ghost"
													size="sm"
													// The visible words are
													// in the spoken name,
													// which is the rule
													// (WCAG 2.2, 2.5.3).
													aria-label={ sprintf(
														/* translators: %d: how many more there are. */
														__(
															'+%d more, show them',
															'cliezoo'
														),
														items.length - 4
													) }
													className="w-full justify-start px-1 text-xs"
													onPress={ () =>
														setOpened( ( was ) => [
															...was,
															key,
														] )
													}
												>
													{ sprintf(
														/* translators: %d: how many more there are. */
														__(
															'+%d more',
															'cliezoo'
														),
														items.length - 4
													) }
												</Button>
											</li>
										) }
								</ul>
							</li>
						);
					} ) }
				</ol>
			) }
		</div>
	);
}
