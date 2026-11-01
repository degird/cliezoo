/**
 * Counters: a row of numbers for the period, each against the period
 * before when the dashboard compares, and each a way into what it counts.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Link } from '@tanstack/react-router';
import { ArrowDownRight, ArrowUpRight, Minus, Settings2 } from 'lucide-react';
import {
	Button,
	Checkbox,
	Dialog,
	ErrorState,
	Skeleton,
	cx,
} from '@cliezoo/ui';
import {
	awaiting,
	change,
	drillLink,
	formatMetric,
	useCard,
	useCatalog,
	useMetrics,
	type Metric,
} from '../api';

export const DEFAULT_COUNTERS = [
	'clients.active',
	'projects.active',
	'tasks.open',
	'tasks.overdue',
	'tasks.done',
	'clients.new',
];

function Delta( { metric }: { metric: Metric } ) {
	const moved = change( metric );
	if ( ! moved ) {
		return null;
	}
	let Icon = Minus;
	if ( moved.direction === 'up' ) {
		Icon = ArrowUpRight;
	} else if ( moved.direction === 'down' ) {
		Icon = ArrowDownRight;
	}
	const words =
		moved.percent === null
			? sprintf(
					/* translators: %s: the number in the period before. */
					__( 'from %s', 'cliezoo' ),
					formatMetric( metric.previous ?? 0, metric.format )
				)
			: sprintf(
					/* translators: %d: change in percent against the period before. */
					__( '%d%% on the period before', 'cliezoo' ),
					moved.percent
				);
	return (
		<span
			className={ cx(
				'inline-flex items-center gap-0.5 text-xs',
				moved.direction === 'level' && 'text-muted',
				moved.direction !== 'level' &&
					( moved.good ? 'text-success' : 'text-danger' )
			) }
		>
			<Icon size={ 12 } aria-hidden="true" />
			{ words }
		</span>
	);
}

export function MetricTile( { metric }: { metric: Metric } ) {
	// A number that was never counted is an em dash and a reason, not a
	// confident nought: "none of them" and "we may not look" are different
	// answers, and only one of them is news.
	const counted = metric.measured && metric.value !== null;
	const body = (
		<>
			<span className="block truncate text-xs text-muted">
				{ metric.label }
			</span>
			<span
				className={ cx(
					'block text-2xl font-semibold tabular-nums',
					counted ? 'text-fg' : 'text-muted'
				) }
			>
				{ counted ? (
					formatMetric( metric.value ?? 0, metric.format )
				) : (
					<span
						aria-label={ __( 'Not measured', 'cliezoo' ) }
						title={ metric.reason || undefined }
					>
						{ '—' }
					</span>
				) }
			</span>
			{ counted ? (
				<Delta metric={ metric } />
			) : (
				<span className="block text-xs text-muted">
					{ metric.reason ||
						__( 'This could not be counted.', 'cliezoo' ) }
				</span>
			) }
		</>
	);
	// Nothing to drill into: the list behind a number nobody could count
	// would refuse the same way.
	if ( ! metric.drill || ! counted ) {
		return <div className="rounded-md p-2">{ body }</div>;
	}
	const link = drillLink( metric.drill );
	return (
		<Link
			to={ link.to }
			search={ link.search as never }
			className="block rounded-md p-2 outline-none hover:bg-sunken focus-visible:ring-2 focus-visible:ring-accent"
		>
			{ body }
		</Link>
	);
}

function Choose( {
	chosen,
	onClose,
}: {
	chosen: string[];
	onClose: ( keys: string[] | null ) => void;
} ) {
	const catalog = useCatalog();
	const [ keys, setKeys ] = useState( chosen );
	const list = catalog.data?.metrics ?? [];
	const groups = new Map< string, typeof list >();
	list.forEach( ( one ) => {
		groups.set( one.group, [ ...( groups.get( one.group ) ?? [] ), one ] );
	} );
	return (
		<Dialog
			isOpen
			onOpenChange={ ( open ) => ! open && onClose( null ) }
			title={ __( 'Choose the numbers', 'cliezoo' ) }
			footer={ () => (
				<>
					<Button variant="ghost" onPress={ () => onClose( null ) }>
						{ __( 'Cancel', 'cliezoo' ) }
					</Button>
					<Button
						variant="primary"
						isDisabled={ ! keys.length || ! list.length }
						onPress={ () => onClose( keys ) }
					>
						{ __( 'Show these', 'cliezoo' ) }
					</Button>
				</>
			) }
		>
			{ catalog.isPending && <Skeleton className="h-40" /> }
			{ catalog.isError && (
				<ErrorState
					error={ catalog.error }
					onRetry={ () => catalog.refetch() }
				/>
			) }
			{ /* An empty dialog with a live "Show these" invited somebody
			     to press it and wipe the card. */ }
			{ catalog.isSuccess && ! list.length && (
				<p className="text-sm text-muted">
					{ __(
						'There are no numbers to choose from yet.',
						'cliezoo'
					) }
				</p>
			) }
			<div className="space-y-4">
				{ [ ...groups ].map( ( [ group, metrics ] ) => (
					<fieldset key={ group }>
						<legend className="mb-1 text-xs font-semibold uppercase text-muted">
							{ group }
						</legend>
						{ metrics.map( ( one ) => (
							<Checkbox
								key={ one.key }
								isSelected={ keys.includes( one.key ) }
								onChange={ ( on: boolean ) =>
									setKeys(
										on
											? [ ...keys, one.key ]
											: keys.filter(
													( k ) => k !== one.key
												)
									)
								}
							>
								{ one.label }
							</Checkbox>
						) ) }
					</fieldset>
				) ) }
			</div>
		</Dialog>
	);
}

export default function Counters() {
	const card = useCard();
	const [ choosing, setChoosing ] = useState( false );
	const given = card.settings?.metrics;
	const keys =
		Array.isArray( given ) && given.length ? given : DEFAULT_COUNTERS;
	const numbers = useMetrics( keys, card.period );
	const metrics = numbers.data?.metrics;
	const shown = metrics ? keys.filter( ( key ) => metrics[ key ] ) : [];

	return (
		<div>
			{ card.editing && (
				<div className="mb-2 flex justify-end">
					<Button
						size="sm"
						variant="ghost"
						icon={ <Settings2 size={ 14 } aria-hidden="true" /> }
						onPress={ () => setChoosing( true ) }
					>
						{ __( 'Choose the numbers', 'cliezoo' ) }
					</Button>
				</div>
			) }
			{ awaiting( numbers ) && <Skeleton className="h-20" /> }
			{ numbers.isError && (
				<ErrorState
					error={ numbers.error }
					onRetry={ () => numbers.refetch() }
				/>
			) }
			{ ! awaiting( numbers ) && numbers.data && !! shown.length && (
				<div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
					{ shown.map( ( key ) => (
						<MetricTile
							key={ key }
							metric={ numbers.data.metrics[ key ] }
						/>
					) ) }
				</div>
			) }
			{ /* The server leaves out a key it no longer knows, so a card
			     somebody set up a year ago can come back with nothing in
			     it. An empty grid inside a titled frame reads as "none",
			     which is a different answer from "not measured any
			     more". */ }
			{ ! awaiting( numbers ) && numbers.data && ! shown.length && (
				<p className="text-sm text-muted">
					{ __(
						'None of the numbers this card was set to show are measured any more. Choose some others.',
						'cliezoo'
					) }
				</p>
			) }
			{ ! awaiting( numbers ) &&
				numbers.data &&
				!! shown.length &&
				shown.length < keys.length && (
					<p className="mt-1 text-xs text-subtle">
						{ __(
							'Some of the numbers this card was set to show are not measured any more.',
							'cliezoo'
						) }
					</p>
				) }
			{ choosing && (
				<Choose
					chosen={ keys }
					onClose={ ( picked ) => {
						setChoosing( false );
						if ( picked ) {
							card.change( {
								...card.settings,
								metrics: picked,
							} );
						}
					} }
				/>
			) }
		</div>
	);
}
