/**
 * The client's health score, and why it is that.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { RefreshCw } from 'lucide-react';
import { api, useQuery, type ApiError } from '@cliezoo/data';
import { ErrorState, IconButton, Skeleton, cx, toast } from '@cliezoo/ui';
import { RailPanel } from '../../packages/app/record/RecordScreen';

interface Part {
	id: string;
	label: string;
	score: number;
	weight: number;
	detail: string;
}

interface Health {
	score: number | null;
	parts: Part[];
}

/**
 * Green above 70, amber above 40, red below: the same bands everywhere.
 * @param score
 */
function tone( score: number ): string {
	if ( score >= 70 ) {
		return 'text-success';
	}
	return score >= 40 ? 'text-warning' : 'text-danger';
}

/**
 * The bar's colour, in the same bands as the number above it.
 *
 * @param score This part's score.
 */
function bar( score: number ): string {
	if ( score >= 70 ) {
		return 'bg-success';
	}
	return score >= 40 ? 'bg-warning' : 'bg-danger';
}

function band( score: number ): string {
	if ( score >= 70 ) {
		return __( 'Healthy', 'cliezoo' );
	}
	return score >= 40
		? __( 'Worth a look', 'cliezoo' )
		: __( 'Needs attention', 'cliezoo' );
}

export function HealthPanel( { uid }: { uid: string } ) {
	const [ working, setWorking ] = useState( false );
	const health = useQuery( {
		queryKey: [ 'clients', uid, 'health' ],
		queryFn: async () =>
			( await api.get< Health >( `/clients/${ uid }/health` ) ).data,
	} );

	return (
		<RailPanel
			title={ __( 'Health', 'cliezoo' ) }
			action={
				<IconButton
					size="sm"
					// Greyed on its own said nothing; the name says why,
					// and the toast below says when it is over. A score
					// that changes silently looks like a redraw.
					label={
						working
							? __( 'Working it out…', 'cliezoo' )
							: __( 'Work it out again', 'cliezoo' )
					}
					icon={
						<RefreshCw
							size={ 14 }
							aria-hidden="true"
							className={
								working
									? 'animate-spin motion-reduce:animate-none'
									: undefined
							}
						/>
					}
					isDisabled={ working }
					onPress={ async () => {
						if ( working ) {
							return;
						}
						setWorking( true );
						try {
							await api.post(
								`/clients/${ uid }/health/refresh`
							);
							await health.refetch();
							toast.success(
								__(
									'The score has been worked out again.',
									'cliezoo'
								)
							);
						} catch ( error ) {
							toast.error( ( error as ApiError ).message );
						} finally {
							setWorking( false );
						}
					} }
				/>
			}
		>
			{ health.isPending && <Skeleton className="h-16" /> }
			{ health.isError && (
				<ErrorState
					error={ health.error }
					onRetry={ () => health.refetch() }
				/>
			) }
			{ health.data && null === health.data.score && (
				<p className="text-muted">
					{ __(
						'There is nothing to score yet. Invoices, work and conversations each add their part as you use them.',
						'cliezoo'
					) }
				</p>
			) }
			{ health.data && null !== health.data.score && (
				<>
					<p className="flex items-baseline gap-2">
						<span
							className={ cx(
								'text-2xl font-semibold tabular-nums',
								tone( health.data.score )
							) }
						>
							{ health.data.score }
						</span>
						<span className="text-xs text-muted">
							{ band( health.data.score ) }
						</span>
					</p>
					<ul className="mt-3 space-y-2">
						{ health.data.parts.map( ( part ) => (
							<li key={ part.id }>
								<div className="flex items-center gap-2 text-xs">
									<span className="text-fg">
										{ part.label }
									</span>
									<span className="ms-auto tabular-nums text-muted">
										{ sprintf(
											/* translators: 1: this part's score, 2: how much of the total it is worth. */
											__(
												'%1$d · weight %2$d',
												'cliezoo'
											),
											part.score,
											part.weight
										) }
									</span>
								</div>
								<div
									className="mt-1 h-1.5 overflow-hidden rounded-full bg-sunken"
									role="presentation"
								>
									<div
										className={ cx(
											'h-full rounded-full',
											bar( part.score )
										) }
										style={ { width: `${ part.score }%` } }
									/>
								</div>
								{ '' !== part.detail && (
									<p className="mt-0.5 text-xs text-subtle">
										{ part.detail }
									</p>
								) }
							</li>
						) ) }
					</ul>
				</>
			) }
		</RailPanel>
	);
}
