/**
 * A client's key figures: what they have paid, what they owe, what is kept
 * of it, and what is open to sell to them.
 *
 * Each figure is the module that owns it answering through the client's
 * summary, so a workspace without deals has no "open deals" line rather
 * than a nought, and somebody whose role does not include the money is not
 * shown any of it.
 */
import { __, sprintf } from '@wordpress/i18n';
import { api, formatMoney, formatNumber, useQuery } from '@cliezoo/data';
import { ErrorState, Skeleton } from '@cliezoo/ui';
import { RailPanel } from '../../packages/app/record/RecordScreen';

interface Figure {
	label: string;
	money?: { amount: number; currency: string };
	count?: number;
	percent?: number;
	explain?: string;
}

interface Summary {
	metrics?: Record< string, Figure >;
}

const ORDER = [ 'lifetime', 'outstanding', 'margin', 'open_deals' ];

function value( figure: Figure ): string {
	if ( undefined !== figure.percent ) {
		return sprintf(
			/* translators: %d: a percentage. */
			__( '%d%%', 'cliezoo' ),
			figure.percent
		);
	}
	const money = figure.money
		? formatMoney( figure.money.amount, figure.money.currency )
		: '';
	if ( undefined !== figure.count ) {
		return money
			? sprintf(
					/* translators: 1: how many, 2: what they are worth. */
					__( '%1$s · %2$s', 'cliezoo' ),
					formatNumber( figure.count ),
					money
				)
			: formatNumber( figure.count );
	}
	return money;
}

export function KeyFigures( { uid }: { uid: string } ) {
	const summary = useQuery( {
		queryKey: [ 'clients', uid, 'summary' ],
		queryFn: async () =>
			( await api.get< Summary >( `/clients/${ uid }/summary` ) ).data,
	} );

	const figures = ORDER.map(
		( key ) => summary.data?.metrics?.[ key ]
	).filter( ( figure ): figure is Figure => !! figure );

	// Nothing any module could say, or nothing this person may see: no
	// panel, rather than an empty box.
	if ( summary.isSuccess && ! figures.length ) {
		return null;
	}

	return (
		<RailPanel title={ __( 'Key figures', 'cliezoo' ) }>
			{ summary.isPending && <Skeleton className="h-16" /> }
			{ summary.isError && (
				<ErrorState
					error={ summary.error }
					onRetry={ () => summary.refetch() }
				/>
			) }
			{ figures.length > 0 && (
				<dl className="space-y-1.5">
					{ figures.map( ( figure ) => (
						<div
							key={ figure.label }
							className="flex items-baseline justify-between gap-3"
						>
							<dt className="text-muted" title={ figure.explain }>
								{ figure.label }
							</dt>
							<dd className="font-medium tabular-nums text-fg">
								{ value( figure ) }
							</dd>
						</div>
					) ) }
				</dl>
			) }
		</RailPanel>
	);
}

export default KeyFigures;
