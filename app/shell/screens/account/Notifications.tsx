/**
 * Account → Notifications: preset, event × channel matrix, digest, quiet
 * hours and working hours.
 */
import { useEffect, useMemo, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Bell } from 'lucide-react';
import { api, useQuery, useQueryClient } from '@cliezoo/data';
import {
	Button,
	Checkbox,
	ErrorState,
	Select,
	Skeleton,
	Switch,
	TextField,
	toast,
} from '@cliezoo/ui';

interface Prefs {
	preset: string;
	matrix: Record< string, Record< string, boolean > >;
	digest: 'instant' | 'hourly' | 'daily' | 'weekly';
	digest_hour: number;
	quiet_start: string | null;
	quiet_end: string | null;
	quiet_weekends: boolean;
	work_start: string | null;
	work_end: string | null;
	/** ISO days, 1 = Monday … 7 = Sunday. Empty: no working hours. */
	work_days: number[];
}

/** Monday first, as ISO counts them. */
const WEEKDAYS = (): Array< [ number, string ] > => [
	[ 1, __( 'Mon', 'cliezoo' ) ],
	[ 2, __( 'Tue', 'cliezoo' ) ],
	[ 3, __( 'Wed', 'cliezoo' ) ],
	[ 4, __( 'Thu', 'cliezoo' ) ],
	[ 5, __( 'Fri', 'cliezoo' ) ],
	[ 6, __( 'Sat', 'cliezoo' ) ],
	[ 7, __( 'Sun', 'cliezoo' ) ],
];

interface Catalog {
	events: Array< {
		id: string;
		group: string;
		label: string;
		tab: string;
		important?: boolean;
	} >;
	channels: Record< string, string >;
}

const PRESETS = (): Array< { id: string; label: string } > => [
	{ id: 'default', label: __( 'Recommended', 'cliezoo' ) },
	{ id: 'everything', label: __( 'Everything', 'cliezoo' ) },
	{
		id: 'messages_mentions',
		label: __( 'Messages and mentions only', 'cliezoo' ),
	},
	{ id: 'important', label: __( 'Important only', 'cliezoo' ) },
	{ id: 'nothing', label: __( 'Nothing (in-app only)', 'cliezoo' ) },
	{ id: 'custom', label: __( 'Custom', 'cliezoo' ) },
];

export default function Notifications() {
	const client = useQueryClient();
	const prefs = useQuery( {
		queryKey: [ 'notifications', 'prefs' ],
		queryFn: async () =>
			( await api.get< Prefs >( '/notifications/prefs' ) ).data,
	} );
	const catalog = useQuery( {
		queryKey: [ 'notifications', 'events' ],
		staleTime: 10 * 60_000,
		queryFn: async () =>
			( await api.get< Catalog >( '/notifications/events' ) ).data,
	} );
	const [ draft, setDraft ] = useState< Prefs | null >( null );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => {
		if ( prefs.data ) {
			setDraft( prefs.data );
		}
	}, [ prefs.data ] );
	const groups = useMemo( () => {
		const out: Record< string, Catalog[ 'events' ] > = {};
		for ( const event of catalog.data?.events ?? [] ) {
			( out[ event.group ] ??= [] ).push( event );
		}
		return out;
	}, [ catalog.data ] );

	// A Skeleton is aria-hidden: a failure here pulses for ever and tells
	// nobody anything.
	if ( prefs.isError || catalog.isError ) {
		return (
			<ErrorState
				error={ prefs.error ?? catalog.error }
				title={ __(
					'Your notification settings could not be loaded.',
					'cliezoo'
				) }
				onRetry={ () => {
					if ( prefs.isError ) {
						prefs.refetch();
					}
					if ( catalog.isError ) {
						catalog.refetch();
					}
				} }
			/>
		);
	}
	if ( ! draft || ! catalog.data ) {
		return <Skeleton className="h-64" />;
	}
	const channels = Object.entries( catalog.data.channels );
	const set = ( change: Partial< Prefs > ) =>
		setDraft( { ...draft, ...change } );
	const toggle = ( event: string, channel: string, on: boolean ) =>
		set( {
			preset: 'custom',
			matrix: {
				...draft.matrix,
				[ event ]: { ...draft.matrix[ event ], [ channel ]: on },
			},
		} );
	const every = catalog.data.events;
	// How many events a channel is on for, so its "all" box can say all,
	// none or some.
	const onFor = ( channel: string ) =>
		every.filter( ( event ) => !! draft.matrix[ event.id ]?.[ channel ] )
			.length;
	// "Turn all on/off" for one channel: every event, one change, the
	// other channels left exactly as they were.
	const toggleChannel = ( channel: string, on: boolean ) => {
		const matrix = { ...draft.matrix };
		for ( const event of every ) {
			matrix[ event.id ] = { ...matrix[ event.id ], [ channel ]: on };
		}
		set( { preset: 'custom', matrix } );
	};
	const toggleDay = ( day: number, on: boolean ) => {
		const days = new Set( draft.work_days );
		if ( on ) {
			days.add( day );
		} else {
			days.delete( day );
		}
		set( { work_days: Array.from( days ).sort() } );
	};

	const save = async ( body: Partial< Prefs > ) => {
		setBusy( true );
		try {
			const result = (
				await api.put< Prefs >( '/notifications/prefs', body )
			).data;
			client.setQueryData( [ 'notifications', 'prefs' ], result );
			setDraft( result );
			toast.success( __( 'Notification settings saved.', 'cliezoo' ) );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<section
			aria-labelledby="account-notifications"
			className="rounded-lg border border-line bg-raised p-5"
		>
			<h2
				id="account-notifications"
				className="flex items-center gap-2 text-base font-semibold"
			>
				<Bell
					size={ 18 }
					className="text-accent-fg"
					aria-hidden="true"
				/>
				{ __( 'Notifications', 'cliezoo' ) }
			</h2>
			<p className="mt-1 text-sm text-muted">
				{ __(
					'Choose what reaches you and how. The inbox always keeps everything.',
					'cliezoo'
				) }
			</p>
			<div className="mt-4 grid gap-4 sm:grid-cols-2">
				<Select
					label={ __( 'Preset', 'cliezoo' ) }
					items={ PRESETS() }
					selectedKey={ draft.preset }
					onSelectionChange={ ( key ) => {
						if ( key !== 'custom' ) {
							save( { preset: String( key ) } );
						}
					} }
				/>
				<Select
					label={ __( 'Email summary', 'cliezoo' ) }
					items={ [
						{
							id: 'instant',
							label: __(
								'Send each email right away',
								'cliezoo'
							),
						},
						{
							id: 'hourly',
							label: __( 'Hourly summary', 'cliezoo' ),
						},
						{
							id: 'daily',
							label: __( 'Daily summary', 'cliezoo' ),
						},
						{
							id: 'weekly',
							label: __( 'Weekly summary', 'cliezoo' ),
						},
					] }
					selectedKey={ draft.digest }
					onSelectionChange={ ( key ) =>
						set( { digest: key as Prefs[ 'digest' ] } )
					}
				/>
				{ ( draft.digest === 'daily' || draft.digest === 'weekly' ) && (
					<Select
						label={ __( 'Send the summary at', 'cliezoo' ) }
						items={ Array.from( { length: 24 }, ( _, h ) => ( {
							id: String( h ),
							label: `${ String( h ).padStart( 2, '0' ) }:00`,
						} ) ) }
						selectedKey={ String( draft.digest_hour ) }
						onSelectionChange={ ( key ) =>
							set( { digest_hour: Number( key ) } )
						}
					/>
				) }
			</div>
			<fieldset className="mt-5">
				<legend className="text-sm font-medium">
					{ __( 'Quiet hours', 'cliezoo' ) }
				</legend>
				<p className="text-xs text-muted">
					{ __(
						'No emails or push messages during these hours (your time zone). Urgent items wait until the morning.',
						'cliezoo'
					) }
				</p>
				<div className="mt-2 grid items-end gap-3 sm:grid-cols-3">
					<TextField
						label={ __( 'From', 'cliezoo' ) }
						type="time"
						value={ draft.quiet_start ?? '' }
						onChange={ ( v ) => set( { quiet_start: v || null } ) }
					/>
					<TextField
						label={ __( 'Until', 'cliezoo' ) }
						type="time"
						value={ draft.quiet_end ?? '' }
						onChange={ ( v ) => set( { quiet_end: v || null } ) }
					/>
					<Switch
						className="pb-2"
						isSelected={ draft.quiet_weekends }
						onChange={ ( on ) => set( { quiet_weekends: on } ) }
					>
						{ __( 'Quiet on weekends', 'cliezoo' ) }
					</Switch>
				</div>
			</fieldset>
			<fieldset className="mt-5">
				<legend className="text-sm font-medium">
					{ __( 'Working hours', 'cliezoo' ) }
				</legend>
				<p className="text-xs text-muted">
					{ __(
						'Emails that arrive outside your working days and hours wait until you start. Leave the times empty to get them whenever they happen.',
						'cliezoo'
					) }
				</p>
				<div className="mt-2 grid items-end gap-3 sm:grid-cols-2">
					<TextField
						label={ __( 'Start', 'cliezoo' ) }
						type="time"
						value={ draft.work_start ?? '' }
						onChange={ ( v ) => set( { work_start: v || null } ) }
					/>
					<TextField
						label={ __( 'Finish', 'cliezoo' ) }
						type="time"
						value={ draft.work_end ?? '' }
						onChange={ ( v ) => set( { work_end: v || null } ) }
					/>
				</div>
				<div
					role="group"
					aria-label={ __( 'Working days', 'cliezoo' ) }
					className="mt-3 flex flex-wrap gap-x-4 gap-y-1"
				>
					{ WEEKDAYS().map( ( [ day, label ] ) => (
						<Checkbox
							key={ day }
							isSelected={ draft.work_days.includes( day ) }
							onChange={ ( on ) => toggleDay( day, on ) }
						>
							{ label }
						</Checkbox>
					) ) }
				</div>
			</fieldset>
			<div className="mt-5 overflow-x-auto rounded-md border border-line">
				<table className="w-full text-sm">
					<caption className="sr-only">
						{ __(
							'Which notifications you get on each channel',
							'cliezoo'
						) }
					</caption>
					<thead className="bg-surface text-xs font-semibold text-muted">
						<tr>
							<th scope="col" className="px-3 py-2 text-start">
								{ __( 'Event', 'cliezoo' ) }
							</th>
							{ channels.map( ( [ id, label ] ) => {
								const on = onFor( id );
								return (
									<th
										key={ id }
										scope="col"
										className="w-24 px-3 py-2 text-center"
									>
										<span className="block">{ label }</span>
										{ /* In-app is always on: the inbox keeps everything. */ }
										{ id !== 'inapp' && (
											<span className="mt-1 flex justify-center">
												<Checkbox
													aria-label={ sprintf(
														/* translators: %s: a channel, e.g. "Email". */
														__(
															'Turn all %s notifications on or off',
															'cliezoo'
														),
														label
													) }
													isSelected={
														on === every.length
													}
													isIndeterminate={
														on > 0 &&
														on < every.length
													}
													onChange={ ( checked ) =>
														toggleChannel(
															id,
															checked
														)
													}
												/>
											</span>
										) }
									</th>
								);
							} ) }
						</tr>
					</thead>
					{ Object.entries( groups ).map( ( [ group, events ] ) => (
						<tbody key={ group }>
							<tr>
								<th
									scope="rowgroup"
									colSpan={ channels.length + 1 }
									className="bg-sunken px-3 py-1.5 text-start text-xs font-semibold text-muted"
								>
									{ group }
								</th>
							</tr>
							{ events.map( ( event ) => (
								<tr
									key={ event.id }
									className="border-t border-line"
								>
									<th
										scope="row"
										className="px-3 py-2 text-start font-normal"
									>
										{ event.label }
									</th>
									{ channels.map( ( [ channel, label ] ) => (
										<td
											key={ channel }
											className="px-3 py-2"
										>
											<div className="flex justify-center">
												<Checkbox
													aria-label={ `${ event.label }: ${ label }` }
													isSelected={
														!! draft.matrix[
															event.id
														]?.[ channel ]
													}
													isDisabled={
														channel === 'inapp'
													}
													onChange={ ( on ) =>
														toggle(
															event.id,
															channel,
															on
														)
													}
												/>
											</div>
										</td>
									) ) }
								</tr>
							) ) }
						</tbody>
					) ) }
				</table>
			</div>
			<div className="mt-4 flex justify-end">
				<Button
					variant="primary"
					isPending={ busy }
					onPress={ () =>
						save( {
							preset: draft.preset,
							matrix:
								draft.preset === 'custom'
									? draft.matrix
									: undefined,
							digest: draft.digest,
							digest_hour: draft.digest_hour,
							quiet_start: draft.quiet_start,
							quiet_end: draft.quiet_end,
							quiet_weekends: draft.quiet_weekends,
							work_start: draft.work_start,
							work_end: draft.work_end,
							work_days: draft.work_days,
						} )
					}
				>
					{ __( 'Save notification settings', 'cliezoo' ) }
				</Button>
			</div>
		</section>
	);
}
