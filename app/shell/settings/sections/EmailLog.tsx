/**
 * Settings → Email log: what left the building.
 *
 * The question this answers is almost always "did they get it", asked
 * after a client says they did not. So it shows the address, the moment
 * and what went wrong where something did — and it does not show the
 * body, because an email log that anybody with settings access can read
 * every word of is a filing cabinet with the lock taken off.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { api, formatDate, useQuery, type ApiError } from '@cliezoo/data';
import {
	Badge,
	Button,
	ErrorState,
	Select,
	Skeleton,
	toast,
} from '@cliezoo/ui';

type Row = {
	uid: string;
	to: string;
	subject: string;
	template: string | null;
	category: string;
	status: string;
	error: string | null;
	sent_at: string | null;
	created_at: string | null;
};

const TONE: Record< string, 'neutral' | 'success' | 'danger' | 'accent' > = {
	queued: 'neutral',
	scheduled: 'accent',
	sent: 'success',
	failed: 'danger',
	held: 'neutral',
	suppressed: 'neutral',
	discarded: 'neutral',
};

// What each of those is called. Without this the badge printed the key —
// "suppressed", "discarded" — three lines under a filter that already had
// a translated word for the same thing.
const SAYS = (): Record< string, string > => ( {
	queued: __( 'Queued', 'cliezoo' ),
	scheduled: __( 'Waiting to go', 'cliezoo' ),
	sent: __( 'Sent', 'cliezoo' ),
	failed: __( 'Failed', 'cliezoo' ),
	held: __( 'Held', 'cliezoo' ),
	suppressed: __( 'Not sent to this address', 'cliezoo' ),
	discarded: __( 'Discarded', 'cliezoo' ),
} );

const WHICH = () => [
	{ id: '', label: __( 'Everything', 'cliezoo' ) },
	{ id: 'sent', label: __( 'Sent', 'cliezoo' ) },
	{ id: 'failed', label: __( 'Failed', 'cliezoo' ) },
	{ id: 'scheduled', label: __( 'Waiting to go', 'cliezoo' ) },
	{ id: 'held', label: __( 'Held', 'cliezoo' ) },
];

export default function EmailLog() {
	const [ status, setStatus ] = useState( '' );

	const log = useQuery< Row[] >( {
		queryKey: [ 'email-log', status ],
		queryFn: async () =>
			( await api.get< Row[] >( '/email-log', status ? { status } : {} ) )
				.data,
	} );
	const rows = log.data ?? [];

	// One waiting to go can still be stopped — by whoever wrote it, or an
	// admin, as the server holds it.
	const callBack = async ( uid: string ) => {
		try {
			await api.post( `/emails/${ uid }/unsend` );
			toast.success( __( 'Called back. It will not go.', 'cliezoo' ) );
			log.refetch();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	return (
		<div className="space-y-3">
			<div className="flex flex-wrap items-center gap-2">
				<Select
					aria-label={ __( 'Which ones', 'cliezoo' ) }
					selectedKey={ status }
					onSelectionChange={ ( key ) => setStatus( String( key ) ) }
					items={ WHICH() }
				/>
			</div>

			{ log.isPending && <Skeleton className="h-64" /> }

			{ /* A log that could not be read is not a log with nothing in it.
			   This screen exists to answer "did they get it", and answering
			   "nothing has been sent" to a failed request is how somebody
			   sends the same invoice twice. */ }
			{ log.isError && (
				<ErrorState
					error={ log.error }
					onRetry={ () => log.refetch() }
				/>
			) }

			{ ! log.isPending && ! log.isError && ! rows.length && (
				<p className="text-sm text-muted">
					{ __( 'Nothing has been sent yet.', 'cliezoo' ) }
				</p>
			) }

			{ !! rows.length && (
				<div
					role="region"
					aria-label={ __( 'Emails this workspace sent', 'cliezoo' ) }
					tabIndex={ 0 }
					className="overflow-x-auto rounded-lg border border-line focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
				>
					<table className="w-full text-sm">
						<caption className="sr-only">
							{ __( 'Emails this workspace sent', 'cliezoo' ) }
						</caption>
						<thead>
							<tr className="border-b border-line text-start text-muted">
								<th
									scope="col"
									className="px-3 py-2 text-start"
								>
									{ __( 'To', 'cliezoo' ) }
								</th>
								<th
									scope="col"
									className="px-3 py-2 text-start"
								>
									{ __( 'About', 'cliezoo' ) }
								</th>
								<th
									scope="col"
									className="px-3 py-2 text-start"
								>
									{ __( 'When', 'cliezoo' ) }
								</th>
								<th
									scope="col"
									className="px-3 py-2 text-start"
								>
									{ __( 'What happened', 'cliezoo' ) }
								</th>
							</tr>
						</thead>
						<tbody className="divide-y divide-line">
							{ rows.map( ( row ) => (
								<tr key={ row.uid }>
									<td className="max-w-48 truncate px-3 py-2">
										{ row.to }
									</td>
									<td className="max-w-64 truncate px-3 py-2">
										{ row.subject }
									</td>
									<td className="whitespace-nowrap px-3 py-2 text-muted">
										{ formatDate(
											row.sent_at ?? row.created_at ?? ''
										) }
									</td>
									<td className="px-3 py-2">
										<Badge
											tone={
												TONE[ row.status ] ?? 'neutral'
											}
										>
											{ SAYS()[ row.status ] ??
												row.status }
										</Badge>
										{ 'scheduled' === row.status && (
											<Button
												size="sm"
												variant="ghost"
												className="ms-2"
												onPress={ () =>
													callBack( row.uid )
												}
											>
												{ __(
													'Call it back',
													'cliezoo'
												) }
											</Button>
										) }
										{ !! row.error && (
											<span className="ms-2 text-xs text-danger">
												{ row.error }
											</span>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}
