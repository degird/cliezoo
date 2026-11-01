/**
 * "Remind me about this."
 *
 * The reminders engine has taken a record and a time since it was written,
 * and the only screen that ever asked it anything was the list of what is
 * already coming up. So a person could see their reminders and had nowhere
 * to make one. Like the notes and attachments panels, this is about
 * records in general rather than about any one module.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { BellRing, X } from 'lucide-react';
import {
	api,
	getBoot,
	formatDate,
	formatTime,
	useQuery,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import {
	Button,
	ErrorState,
	Select,
	Skeleton,
	TextField,
	toast,
} from '@cliezoo/ui';

/** One rule of a saved schedule; a template is every rule under a name. */
interface TemplateRule {
	uid: string;
	schedule_name?: string | null;
}

/**
 * Saved schedules ("Gentle reminders") that fit this kind of record, by
 * name, with how many reminders each would add.
 *
 * @param rules Template rules of the record type.
 */
export function scheduleNames(
	rules: TemplateRule[]
): Array< { id: string; label: string; count: number } > {
	const counts = new Map< string, number >();
	for ( const rule of rules ) {
		const name = String( rule.schedule_name ?? '' ).trim();
		if ( name ) {
			counts.set( name, ( counts.get( name ) ?? 0 ) + 1 );
		}
	}
	return [ ...counts ].map( ( [ name, count ] ) => ( {
		id: name,
		label: name,
		count,
	} ) );
}

interface Occurrence {
	uid: string;
	personal: boolean;
	due_at: string | null;
	status: string;
	message?: string | null;
}

/** Sixteen minutes past nine tomorrow is nobody's idea of a reminder. */
function tomorrowMorning(): string {
	const when = new Date();
	when.setDate( when.getDate() + 1 );
	when.setHours( 9, 0, 0, 0 );
	const pad = ( n: number ) => String( n ).padStart( 2, '0' );
	return `${ when.getFullYear() }-${ pad( when.getMonth() + 1 ) }-${ pad(
		when.getDate()
	) }T${ pad( when.getHours() ) }:${ pad( when.getMinutes() ) }`;
}

export function RemindersPanel( { type, uid }: { type: string; uid: string } ) {
	const queries = useQueryClient();
	const [ at, setAt ] = useState( tomorrowMorning );
	const [ message, setMessage ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const mine = useQuery( {
		queryKey: [ 'reminders', type, uid ],
		queryFn: async () =>
			(
				await api.get< { reminders: Occurrence[] } >( '/reminders', {
					object_type: type,
					object: uid,
				} )
			).data.reminders,
	} );

	const waiting = ( mine.data ?? [] ).filter(
		( one ) => 'pending' === one.status
	);

	// Schedules saved in Settings → Reminders for this kind of record. A
	// template made for invoices means nothing on a task, so only this
	// type's are offered. Somebody who may not read the rules simply does
	// not get the picker: the panel's own reminders still work.
	const templates = useQuery( {
		queryKey: [ 'reminder_rules', 'templates', { type } ],
		staleTime: 60_000,
		retry: false,
		queryFn: async () =>
			(
				await api.get< TemplateRule[] >( '/reminder_rules', {
					filter: JSON.stringify( {
						and: [
							{ field: 'target_type', op: 'eq', value: type },
							{
								field: 'scope_type',
								op: 'eq',
								value: 'template',
							},
						],
					} ),
					sort: 'position',
					limit: 100,
				} )
			).data,
	} );
	const schedules = scheduleNames( templates.data ?? [] );
	const [ schedule, setSchedule ] = useState( '' );
	const [ applying, setApplying ] = useState( false );

	const apply = async () => {
		if ( ! schedule || applying ) {
			return;
		}
		setApplying( true );
		try {
			const applied = (
				await api.post< { created: number } >(
					'/reminders/apply-template',
					{
						schedule,
						scope_type: 'record',
						object_type: type,
						object: uid,
					}
				)
			).data;
			queries.invalidateQueries( { queryKey: [ 'reminders' ] } );
			toast.success(
				sprintf(
					/* translators: 1: number of reminders, 2: schedule name. */
					_n(
						'Added %1$d reminder from "%2$s".',
						'Added %1$d reminders from "%2$s".',
						applied.created,
						'cliezoo'
					),
					applied.created,
					schedule
				)
			);
			setSchedule( '' );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setApplying( false );
		}
	};

	const set = async () => {
		// A cleared date field is an empty string, and new Date( '' )
		// .toISOString() throws — which reached the person as the
		// browser's own "Invalid time value".
		const when = new Date( at );
		if ( '' === at.trim() || Number.isNaN( when.getTime() ) ) {
			toast.error( __( 'Choose a date and a time first.', 'cliezoo' ) );
			return;
		}
		setBusy( true );
		try {
			await api.post( '/reminders/personal', {
				object_type: type,
				object: uid,
				// The picker gives local wall-clock time; the server wants a
				// moment, and the difference is an hour somebody misses.
				at: when.toISOString(),
				...( message.trim() ? { message: message.trim() } : {} ),
			} );
			setMessage( '' );
			queries.invalidateQueries( { queryKey: [ 'reminders' ] } );
			toast.success( __( 'You will be reminded.', 'cliezoo' ) );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<section aria-labelledby="record-reminders" className="space-y-2">
			<h3 id="record-reminders" className="text-sm font-semibold text-fg">
				{ __( 'Remind me', 'cliezoo' ) }
			</h3>

			{ mine.isPending && <Skeleton className="h-10" /> }

			{ /* No reminders and reminders we could not fetch look the
			     same from here, so the second one says which it is. */ }
			{ mine.isError && (
				<ErrorState
					error={ mine.error }
					title={ __(
						'Your reminders on this record could not be loaded.',
						'cliezoo'
					) }
					onRetry={ () => mine.refetch() }
				/>
			) }

			{ waiting.length > 0 && (
				<ul className="space-y-1">
					{ waiting.map( ( one ) => (
						<li
							key={ one.uid }
							className="flex items-center gap-2 rounded border border-line px-2 py-1.5 text-sm"
						>
							<BellRing
								size={ 14 }
								aria-hidden="true"
								className="shrink-0 text-subtle"
							/>
							<span className="min-w-0 flex-1 truncate text-fg">
								{ one.due_at
									? sprintf(
											/* translators: 1: a date, 2: a time. */
											__( '%1$s at %2$s', 'cliezoo' ),
											formatDate( one.due_at ),
											formatTime( one.due_at )
										)
									: __( 'Some time', 'cliezoo' ) }
								{ !! one.message && ` — ${ one.message }` }
							</span>
							{ one.personal && (
								<Button
									size="sm"
									variant="ghost"
									aria-label={ __(
										'Call it off',
										'cliezoo'
									) }
									onPress={ async () => {
										try {
											await api.post(
												`/reminders/${ one.uid }/skip`
											);
											queries.invalidateQueries( {
												queryKey: [ 'reminders' ],
											} );
											toast.success(
												__( 'Called off.', 'cliezoo' )
											);
										} catch ( error ) {
											toast.error(
												( error as ApiError ).message
											);
										}
									} }
								>
									<X size={ 13 } aria-hidden="true" />
								</Button>
							) }
						</li>
					) ) }
				</ul>
			) }

			<div className="flex flex-wrap items-end gap-2">
				<TextField
					label={ __( 'When', 'cliezoo' ) }
					type="datetime-local"
					className="min-w-52"
					value={ at }
					onChange={ setAt }
				/>
				<TextField
					label={ __( 'About what', 'cliezoo' ) }
					placeholder={ __( 'Optional', 'cliezoo' ) }
					className="min-w-48 flex-1"
					value={ message }
					onChange={ setMessage }
				/>
				<Button size="sm" isPending={ busy } onPress={ set }>
					{ __( 'Remind me', 'cliezoo' ) }
				</Button>
			</div>

			{ schedules.length > 0 && (
				<div className="flex flex-wrap items-end gap-2 border-t border-line pt-2">
					<Select
						label={ __( 'Use a saved schedule', 'cliezoo' ) }
						description={ __(
							'Adds its reminders to this record only, for everyone they are meant for.',
							'cliezoo'
						) }
						placeholder={ __( 'Choose a schedule', 'cliezoo' ) }
						className="min-w-52 flex-1"
						items={ schedules.map( ( one ) => ( {
							id: one.id,
							label: one.label,
							description: sprintf(
								/* translators: %d: number of reminders in the schedule. */
								_n(
									'%d reminder',
									'%d reminders',
									one.count,
									'cliezoo'
								),
								one.count
							),
						} ) ) }
						selectedKey={ schedule || null }
						onSelectionChange={ ( key ) =>
							setSchedule( String( key ?? '' ) )
						}
					/>
					<Button
						size="sm"
						isPending={ applying }
						isDisabled={ ! schedule }
						onPress={ apply }
					>
						{ __( 'Apply', 'cliezoo' ) }
					</Button>
				</div>
			) }
		</section>
	);
}

/**
 * The panel in a card of its own, for a page's side column: offered to the
 * team only, because reminders are the studio's.
 *
 * @param root0      Props.
 * @param root0.type The record type.
 * @param root0.uid  The record.
 */
export function RemindersCard( { type, uid }: { type: string; uid: string } ) {
	if ( getBoot().user?.client ) {
		return null;
	}
	return (
		<div className="rounded-lg border border-line bg-raised p-4">
			<RemindersPanel type={ type } uid={ uid } />
		</div>
	);
}
