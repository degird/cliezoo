/**
 * Settings → Setup health: the handful of things that decide whether this
 * install actually works, each with what to do when it does not.
 */
import { __ } from '@wordpress/i18n';
import {
	CircleAlert,
	CircleCheck,
	RefreshCw,
	TriangleAlert,
} from 'lucide-react';
import { api, formatDate, formatTime, useQuery } from '@cliezoo/data';
import { Button, ErrorState, Skeleton, cx, toast } from '@cliezoo/ui';

type Status = 'ok' | 'warn' | 'fail';

interface Check {
	id: string;
	label: string;
	status: Status;
	detail: string;
	fix: string;
}

interface HealthReport {
	checked_at: string;
	failing: number;
	warnings: number;
	checks: Check[];
}

const ICONS: Record< Status, typeof CircleCheck > = {
	ok: CircleCheck,
	warn: TriangleAlert,
	fail: CircleAlert,
};

const TONES: Record< Status, string > = {
	ok: 'text-success',
	warn: 'text-warning',
	fail: 'text-danger',
};

/** The status in words, for anyone who cannot use the colour. */
const STATUS_LABEL = (): Record< Status, string > => ( {
	ok: __( 'Fine', 'cliezoo' ),
	warn: __( 'Worth a look', 'cliezoo' ),
	fail: __( 'Needs attention', 'cliezoo' ),
} );

function summary( report: HealthReport ): string {
	if ( report.failing > 0 ) {
		return __( 'Some things need your attention.', 'cliezoo' );
	}
	if ( report.warnings > 0 ) {
		return __(
			'Everything works, with a few things worth improving.',
			'cliezoo'
		);
	}
	return __( 'Everything looks right.', 'cliezoo' );
}

export default function HealthSection() {
	const report = useQuery( {
		queryKey: [ 'health' ],
		queryFn: async () =>
			( await api.get< HealthReport >( '/health' ) ).data,
	} );

	const recheck = async () => {
		try {
			await api.get< HealthReport >( '/health', { refresh: true } );
			await report.refetch();
			toast.success( __( 'Checked again.', 'cliezoo' ) );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		}
	};

	if ( report.isPending ) {
		return <Skeleton className="h-64" />;
	}
	if ( report.isError ) {
		return (
			<ErrorState
				error={ report.error }
				onRetry={ () => report.refetch() }
			/>
		);
	}
	const data = report.data;
	if ( ! data ) {
		return null;
	}

	return (
		<div className="space-y-4">
			<div className="flex flex-wrap items-center gap-3">
				<p className="text-sm text-muted">
					{ summary( data ) }
					{ data.checked_at && (
						<>
							{ ' · ' }
							{ __( 'Last checked', 'cliezoo' ) }{ ' ' }
							{ formatDate( data.checked_at ) }{ ' ' }
							{ formatTime( data.checked_at ) }
						</>
					) }
				</p>
				<Button
					className="ms-auto"
					variant="secondary"
					size="sm"
					isPending={ report.isFetching }
					icon={ <RefreshCw size={ 14 } aria-hidden="true" /> }
					onPress={ recheck }
				>
					{ __( 'Check again', 'cliezoo' ) }
				</Button>
			</div>
			<ul className="divide-y divide-line overflow-hidden rounded-lg border border-line bg-raised">
				{ data.checks.map( ( check ) => {
					const Icon = ICONS[ check.status ];
					return (
						<li key={ check.id } className="flex gap-3 px-4 py-3">
							<Icon
								size={ 18 }
								className={ cx(
									'mt-0.5 shrink-0',
									TONES[ check.status ]
								) }
								aria-hidden="true"
							/>
							<div className="min-w-0 flex-1">
								<p className="text-sm font-medium text-fg">
									{ check.label }
									<span
										className={ cx(
											'ms-2 align-middle text-xs font-normal',
											TONES[ check.status ]
										) }
									>
										{ STATUS_LABEL()[ check.status ] }
									</span>
								</p>
								<p className="text-sm text-muted">
									{ check.detail }
								</p>
								{ check.fix && 'ok' !== check.status && (
									<p className="mt-1 text-sm text-subtle">
										{ check.fix }
									</p>
								) }
							</div>
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}
