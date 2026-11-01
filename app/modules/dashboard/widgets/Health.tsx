/**
 * Business health: one score, and every part of it explained — including
 * the parts that are not measured yet and what would measure them.
 */
import { __, sprintf } from '@wordpress/i18n';
import { ErrorState, Skeleton, cx } from '@cliezoo/ui';
import { useHealth, type Health as HealthScore } from '../api';

function gradeLabel( grade: HealthScore[ 'grade' ] ): string {
	if ( grade === 'good' ) {
		return __( 'Healthy', 'cliezoo' );
	}
	return grade === 'fair'
		? __( 'Worth a look', 'cliezoo' )
		: __( 'Needs attention', 'cliezoo' );
}

function tone( score: number ): string {
	if ( score >= 75 ) {
		return 'bg-success';
	}
	return score >= 50 ? 'bg-warning' : 'bg-danger';
}

export default function Health() {
	const health = useHealth();
	if ( health.isPending ) {
		return <Skeleton className="h-32" />;
	}
	if ( health.isError ) {
		return (
			<ErrorState
				error={ health.error }
				onRetry={ () => health.refetch() }
			/>
		);
	}
	const { score, grade, letter, parts } = health.data;
	// Parts that are not measured leave the score, so the others count
	// for more than their weight says.
	const measured = parts
		.filter( ( part ) => part.measured )
		.reduce( ( sum, part ) => sum + part.weight, 0 );
	return (
		<div>
			{ score === null ? (
				<p className="text-sm text-muted">
					{ __(
						'There is nothing to score yet. Each part counts once there is something to measure.',
						'cliezoo'
					) }
				</p>
			) : (
				<p className="flex items-baseline gap-2">
					<span
						className={ cx(
							'text-3xl font-semibold tabular-nums',
							grade === 'good' && 'text-success',
							grade === 'fair' && 'text-warning',
							grade === 'poor' && 'text-danger'
						) }
					>
						{ letter || score }
					</span>
					<span className="text-xs text-muted">
						{ sprintf(
							/* translators: 1: the score out of 100, 2: what it means (Healthy, Worth a look…). */
							__( '%1$d out of 100 · %2$s', 'cliezoo' ),
							score,
							gradeLabel( grade )
						) }
					</span>
				</p>
			) }
			<ul className="mt-3 space-y-2">
				{ parts.map( ( part ) => (
					<li key={ part.key }>
						<div className="flex items-center gap-2 text-xs">
							<span
								className={ cx(
									part.measured ? 'text-fg' : 'text-muted'
								) }
							>
								{ part.label }
							</span>
							<span className="ms-auto tabular-nums text-muted">
								{ part.measured
									? sprintf(
											/* translators: 1: the part's score, 2: how much of the total it is worth, in percent. */
											__(
												'%1$d · counts %2$d%%',
												'cliezoo'
											),
											part.score ?? 0,
											measured
												? Math.round(
														( part.weight /
															measured ) *
															100
													)
												: 0
										)
									: __( 'Not measured yet', 'cliezoo' ) }
							</span>
						</div>
						{ part.measured && (
							<div
								className="mt-1 h-1.5 overflow-hidden rounded-full bg-sunken"
								role="presentation"
							>
								<div
									className={ cx(
										'h-full rounded-full',
										tone( part.score ?? 0 )
									) }
									style={ { width: `${ part.score ?? 0 }%` } }
								/>
							</div>
						) }
						{ /* An unmeasured part owes a sentence saying what
						     would measure it; an empty paragraph says
						     nothing at all, so it is not drawn. */ }
						{ !! part.explain && (
							<p className="mt-0.5 text-xs text-subtle">
								{ part.value ? `${ part.value } · ` : '' }
								{ part.explain }
							</p>
						) }
					</li>
				) ) }
			</ul>
			<div className="mt-3 flex items-center gap-2 border-t border-line pt-2 text-xs text-muted">
				<span>{ __( 'Fixed formula', 'cliezoo' ) }</span>
			</div>
		</div>
	);
}
