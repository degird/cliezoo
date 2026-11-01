/**
 * Settings → Audit log: security and permission events with who, when,
 * where from (IP) and which device. Filter by event, person and dates.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Download } from 'lucide-react';
import { api, formatDate, formatTime, useList, useQuery } from '@cliezoo/data';
import {
	Badge,
	Button,
	ErrorState,
	EmptyState,
	Select,
	Skeleton,
	TextField,
} from '@cliezoo/ui';
import { downloadCsv, humanise } from '../../../packages/app/list/cells';

interface AuditRow {
	uid: string;
	event: string;
	status: string;
	channel: string;
	member: { uid: string; label: string } | null;
	ip: string;
	device: string;
	meta: Record< string, unknown > | null;
	created_at: string;
}

const details = ( meta: AuditRow[ 'meta' ] ): string =>
	meta
		? Object.entries( meta )
				.filter(
					( [ key ] ) => ! [ 'object_id', 'member' ].includes( key )
				)
				.map(
					( [ key, value ] ) =>
						`${ humanise( key ) }: ${ typeof value === 'object' ? JSON.stringify( value ) : String( value ) }`
				)
				.join( ' · ' )
		: '';

export default function AuditLog() {
	const [ event, setEvent ] = useState( '' );
	const [ member, setMember ] = useState( '' );
	const [ from, setFrom ] = useState( '' );
	const [ to, setTo ] = useState( '' );
	const members = useQuery( {
		queryKey: [ 'members', 'list', { picker: true } ],
		queryFn: async () =>
			(
				await api.get< Array< { uid: string; name: string } > >(
					'/members',
					{ limit: 200, fields: 'uid,name' }
				)
			).data,
	} );
	const list = useList< AuditRow >(
		'/audit-log',
		{
			event: event || undefined,
			member: member || undefined,
			from: from || undefined,
			to: to || undefined,
		},
		{ limit: 50 }
	);
	const rows = list.data?.pages.flatMap( ( page ) => page.data ) ?? [];

	const exportCsv = () =>
		downloadCsv(
			'audit-log',
			[
				__( 'Time', 'cliezoo' ),
				__( 'Person', 'cliezoo' ),
				__( 'Event', 'cliezoo' ),
				__( 'Result', 'cliezoo' ),
				__( 'IP address', 'cliezoo' ),
				__( 'Device', 'cliezoo' ),
				__( 'Details', 'cliezoo' ),
			],
			rows.map( ( row ) => [
				row.created_at,
				row.member?.label ?? '',
				row.event,
				row.status,
				row.ip,
				row.device,
				details( row.meta ),
			] )
		);

	return (
		<div className="space-y-4">
			<div className="grid gap-3 sm:grid-cols-4">
				<TextField
					label={ __( 'Event', 'cliezoo' ) }
					placeholder={ __( 'e.g. login_failed', 'cliezoo' ) }
					value={ event }
					onChange={ setEvent }
				/>
				<Select
					label={ __( 'Person', 'cliezoo' ) }
					items={ [
						{ id: 'all', label: __( 'Everyone', 'cliezoo' ) },
						...( members.data ?? [] ).map( ( m ) => ( {
							id: m.uid,
							label: m.name,
						} ) ),
					] }
					selectedKey={ member || 'all' }
					onSelectionChange={ ( key ) =>
						setMember( key === 'all' ? '' : String( key ) )
					}
				/>
				<TextField
					label={ __( 'From', 'cliezoo' ) }
					type="date"
					value={ from }
					onChange={ setFrom }
				/>
				<TextField
					label={ __( 'To', 'cliezoo' ) }
					type="date"
					value={ to }
					onChange={ setTo }
				/>
			</div>
			<div className="flex justify-end">
				<Button
					size="sm"
					variant="ghost"
					icon={ <Download size={ 14 } aria-hidden="true" /> }
					onPress={ exportCsv }
					isDisabled={ ! rows.length }
				>
					{ __( 'Export', 'cliezoo' ) }
				</Button>
			</div>
			{ list.isPending && <Skeleton className="h-64" /> }
			{ list.isError && (
				<ErrorState
					error={ list.error }
					onRetry={ () => list.refetch() }
				/>
			) }
			{ ! list.isPending && ! list.isError && ! rows.length && (
				<EmptyState title={ __( 'No events match', 'cliezoo' ) } />
			) }
			{ rows.length > 0 && (
				<div className="overflow-x-auto rounded-lg border border-line">
					<table
						className="w-full text-sm"
						aria-label={ __( 'Audit log', 'cliezoo' ) }
					>
						<thead className="bg-surface text-xs font-semibold text-muted">
							<tr>
								{ [
									__( 'Time', 'cliezoo' ),
									__( 'Person', 'cliezoo' ),
									__( 'Event', 'cliezoo' ),
									__( 'Where', 'cliezoo' ),
									__( 'Details', 'cliezoo' ),
								].map( ( label ) => (
									<th
										key={ label }
										scope="col"
										className="border-b border-line px-3 py-2 text-start"
									>
										{ label }
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr
									key={ row.uid }
									className="border-b border-line align-top"
								>
									<td className="whitespace-nowrap px-3 py-2 text-muted">
										<time dateTime={ row.created_at }>
											{ formatDate( row.created_at ) }{ ' ' }
											{ formatTime( row.created_at ) }
										</time>
									</td>
									<td className="px-3 py-2">
										{ row.member?.label ?? (
											<span className="text-muted">
												{ __(
													'System or visitor',
													'cliezoo'
												) }
											</span>
										) }
									</td>
									<td className="px-3 py-2">
										<span className="me-2">
											{ humanise( row.event ) }
										</span>
										{ row.status &&
											row.status !== 'success' && (
												<Badge
													tone={
														row.status === 'failure'
															? 'danger'
															: 'warning'
													}
												>
													{ humanise( row.status ) }
												</Badge>
											) }
									</td>
									<td className="px-3 py-2 text-muted">
										{ row.ip }
										<span className="block text-xs">
											{ row.device }
										</span>
									</td>
									<td className="max-w-md px-3 py-2 text-xs text-muted">
										{ details( row.meta ) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
			{ list.hasNextPage && (
				<Button
					onPress={ () => list.fetchNextPage() }
					isPending={ list.isFetchingNextPage }
				>
					{ __( 'Load more', 'cliezoo' ) }
				</Button>
			) }
		</div>
	);
}
