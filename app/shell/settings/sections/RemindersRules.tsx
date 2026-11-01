/**
 * Settings → Reminders: the workspace schedules. Pick a record type, say
 * which date to count from, how long before or after, who hears about it and
 * on which channels — then watch the log. A schedule can be kept as a
 * template ("Gentle reminders") and put back later.
 */
import { useEffect, useMemo, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowDown, ArrowUp, Plus, Save, Trash2, X } from 'lucide-react';
import {
	api,
	formatDate,
	formatTime,
	useList,
	useQuery,
	useQueryClient,
	type ApiError,
} from '@cliezoo/data';
import {
	Badge,
	Button,
	Checkbox,
	Dialog,
	EmptyState,
	ErrorState,
	IconButton,
	Select,
	Skeleton,
	Switch,
	TextField,
	toast,
} from '@cliezoo/ui';
import { humanise } from '../../../packages/app/list/cells';
import { Slot } from '../../../packages/app/slot';

interface Target {
	type: string;
	label: string;
	dates: Record< string, string >;
	/** Status values that stop a reminder, e.g. `paid`, `done`. */
	stop: string[];
	roles: Record< string, string >;
}

interface Rule {
	uid: string;
	target_type: string;
	date_field: string;
	scope_type: string;
	schedule_name: string | null;
	offset_minutes: number;
	/** minutes | hours | days | weeks | months: how the offset was chosen. */
	offset_unit?: string | null;
	anchor: string;
	at_time: string | null;
	repeat_minutes: number;
	max_count: number;
	stop_statuses: string[] | null;
	recipients: string[] | null;
	channels: string[] | null;
	subject: string | null;
	message: string | null;
	active: boolean;
	position: number;
	/** What an add-on keeps on the rule, sent back with it when saved. */
	extra?: Record< string, unknown >;
}

interface LogRow {
	uid: string;
	status: string;
	due_at: string;
	sent_at: string | null;
	message: string | null;
	record: { type: string; label: string } | null;
}

const HOUR = 60;
const DAY = 1440;
const WEEK = 10080;
/**
 * A month is stored as 30 days of minutes and moved by the calendar on the
 * server ("1 month before 31 March" is the end of February).
 */
const MONTH = 43200;

const UNITS = (): Array< [ number, string ] > => [
	[ 1, __( 'minutes', 'cliezoo' ) ],
	[ HOUR, __( 'hours', 'cliezoo' ) ],
	[ DAY, __( 'days', 'cliezoo' ) ],
	[ WEEK, __( 'weeks', 'cliezoo' ) ],
	[ MONTH, __( 'months', 'cliezoo' ) ],
];

/** The unit names the server keeps, by size. */
const UNIT_KEYS: Record< number, string > = {
	1: 'minutes',
	[ HOUR ]: 'hours',
	[ DAY ]: 'days',
	[ WEEK ]: 'weeks',
	[ MONTH ]: 'months',
};

/**
 * The unit an offset was chosen in, else the largest whole unit it divides
 * into, so "2880" reads as "2 days".
 *
 * @param minutes Offset in minutes.
 * @param chosen  The saved unit, when there is one.
 */
function splitOffset(
	minutes: number,
	chosen?: string | null
): { value: number; unit: number } {
	const named = Object.entries( UNIT_KEYS ).find(
		( [ , key ] ) => key === chosen
	);
	if ( named && minutes && 0 === minutes % Number( named[ 0 ] ) ) {
		return {
			value: minutes / Number( named[ 0 ] ),
			unit: Number( named[ 0 ] ),
		};
	}
	if ( minutes && 0 === minutes % DAY ) {
		return { value: minutes / DAY, unit: DAY };
	}
	if ( minutes && 0 === minutes % HOUR ) {
		return { value: minutes / HOUR, unit: HOUR };
	}
	return { value: minutes, unit: 1 };
}

function ruleSummary( rule: Rule, target: Target | undefined ): string {
	const date = target?.dates?.[ rule.date_field ] ?? rule.date_field;
	const { value, unit } = splitOffset(
		rule.offset_minutes,
		rule.offset_unit
	);
	const unitLabel =
		UNITS().find( ( [ size ] ) => size === unit )?.[ 1 ] ??
		__( 'minutes', 'cliezoo' );
	if ( 'on' === rule.anchor || ! rule.offset_minutes ) {
		return sprintf(
			/* translators: %s: name of a date field, e.g. "Due date". */
			__( 'On %s', 'cliezoo' ),
			date
		);
	}
	return 'after' === rule.anchor
		? sprintf(
				/* translators: 1: number, 2: unit (days), 3: date field name. */
				__( '%1$s %2$s after %3$s', 'cliezoo' ),
				String( value ),
				unitLabel,
				date
			)
		: sprintf(
				/* translators: 1: number, 2: unit (days), 3: date field name. */
				__( '%1$s %2$s before %3$s', 'cliezoo' ),
				String( value ),
				unitLabel,
				date
			);
}

const empty = ( target: Target | undefined ): Partial< Rule > => ( {
	target_type: target?.type ?? '',
	date_field: Object.keys( target?.dates ?? {} )[ 0 ] ?? '',
	scope_type: 'workspace',
	anchor: 'before',
	offset_minutes: DAY,
	repeat_minutes: 0,
	max_count: 0,
	recipients: [],
	channels: [ 'email' ],
	active: true,
} );

export default function RemindersRules() {
	const client = useQueryClient();
	const [ type, setType ] = useState( '' );
	const [ editing, setEditing ] = useState< Partial< Rule > | null >( null );
	const [ unit, setUnit ] = useState( DAY );
	const [ removing, setRemoving ] = useState< Rule | null >( null );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ busy, setBusy ] = useState( false );
	const [ naming, setNaming ] = useState( false );
	const [ templateName, setTemplateName ] = useState( '' );

	const meta = useQuery( {
		queryKey: [ 'reminders', 'targets' ],
		staleTime: 10 * 60_000,
		queryFn: async () =>
			(
				await api.get< {
					targets: Target[];
					channels: Record< string, string >;
					/** Team roles, for "everyone with a role". */
					roles?: Record< string, string >;
				} >( '/reminders/targets' )
			).data,
	} );
	const targets = meta.data?.targets ?? [];
	const current = type || targets[ 0 ]?.type || '';
	const target = targets.find( ( item ) => item.type === current );
	const rules = useQuery( {
		queryKey: [ 'reminder_rules', 'list', { type: current } ],
		enabled: !! current,
		queryFn: async () =>
			(
				await api.get< Rule[] >( '/reminder_rules', {
					filter: JSON.stringify( {
						and: [
							{ field: 'target_type', op: 'eq', value: current },
							{
								field: 'scope_type',
								op: 'eq',
								value: 'workspace',
							},
						],
					} ),
					sort: 'position',
					limit: 100,
				} )
			).data,
	} );
	const templates = useQuery( {
		queryKey: [ 'reminder_rules', 'templates', { type: current } ],
		enabled: !! current,
		queryFn: async () =>
			(
				await api.get< Rule[] >( '/reminder_rules', {
					filter: JSON.stringify( {
						and: [
							{ field: 'target_type', op: 'eq', value: current },
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
	// One template is every rule saved under its name.
	const schedules = useMemo( () => {
		const out: Record< string, Rule[] > = {};
		for ( const rule of templates.data ?? [] ) {
			( out[ rule.schedule_name ?? '' ] ??= [] ).push( rule );
		}
		return Object.entries( out ).filter( ( [ name ] ) => '' !== name );
	}, [ templates.data ] );
	const members = useQuery( {
		queryKey: [ 'members', 'list', { picker: true } ],
		enabled: !! editing,
		staleTime: 60_000,
		queryFn: async () =>
			(
				await api.get< Array< { uid: string; name: string } > >(
					'/members',
					{ limit: 200, fields: 'uid,name' }
				)
			).data,
	} );
	const log = useList< LogRow >( '/reminders/log', {}, { limit: 20 } );
	const logRows = log.data?.pages.flatMap( ( page ) => page.data ) ?? [];

	useEffect( () => {
		if ( editing ) {
			setUnit(
				splitOffset( editing.offset_minutes ?? 0, editing.offset_unit )
					.unit
			);
		}
	}, [ editing?.uid ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const refresh = () =>
		client.invalidateQueries( { queryKey: [ 'reminder_rules' ] } );

	const saveTemplate = async () => {
		setBusy( true );
		try {
			const saved = (
				await api.post< { saved: number } >( '/reminders/templates', {
					target_type: current,
					name: templateName.trim(),
				} )
			).data;
			setNaming( false );
			setTemplateName( '' );
			refresh();
			toast.success(
				sprintf(
					/* translators: %d: number of reminders. */
					__( 'Saved %d reminders as a template.', 'cliezoo' ),
					saved.saved
				)
			);
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	const applyTemplate = async ( name: string ) => {
		setBusy( true );
		try {
			const applied = (
				await api.post< { created: number } >(
					'/reminders/apply-template',
					{ schedule: name, scope_type: 'workspace' }
				)
			).data;
			refresh();
			toast.success(
				sprintf(
					/* translators: 1: number of reminders, 2: template name. */
					__( 'Added %1$d reminders from "%2$s".', 'cliezoo' ),
					applied.created,
					name
				)
			);
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	const removeTemplate = async ( name: string, list: Rule[] ) => {
		setBusy( true );
		try {
			await Promise.all(
				list.map( ( rule ) =>
					api.delete( `/reminder_rules/${ rule.uid }` )
				)
			);
			refresh();
			toast.success(
				sprintf(
					/* translators: %s: template name. */
					__( 'Template "%s" removed.', 'cliezoo' ),
					name
				)
			);
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	const save = async () => {
		setBusy( true );
		setErrors( {} );
		try {
			const body = {
				target_type: current,
				scope_type: 'workspace',
				date_field: editing?.date_field,
				anchor: editing?.anchor,
				offset_minutes: editing?.offset_minutes,
				offset_unit: UNIT_KEYS[ unit ] ?? null,
				at_time: editing?.at_time || null,
				repeat_minutes: editing?.repeat_minutes ?? 0,
				max_count: editing?.max_count ?? 0,
				stop_statuses: editing?.stop_statuses ?? [],
				recipients: editing?.recipients ?? [],
				channels: editing?.channels ?? [],
				subject: editing?.subject ?? '',
				message: editing?.message ?? '',
				schedule_name: editing?.schedule_name ?? '',
				active: editing?.active ?? true,
				// What an add-on added to the rule (its own fields).
				...( editing?.extra ?? {} ),
			};
			if ( editing?.uid ) {
				await api.patch( `/reminder_rules/${ editing.uid }`, body );
			} else {
				await api.post( '/reminder_rules', {
					...body,
					position: rules.data?.length ?? 0,
				} );
			}
			setEditing( null );
			refresh();
			toast.success( __( 'Reminder saved.', 'cliezoo' ) );
		} catch ( error ) {
			setErrors( ( error as ApiError ).fields );
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	const move = async ( rule: Rule, delta: number ) => {
		const list = [ ...( rules.data ?? [] ) ];
		const index = list.findIndex( ( item ) => item.uid === rule.uid );
		const other = list[ index + delta ];
		if ( ! other ) {
			return;
		}
		await Promise.all( [
			api.patch( `/reminder_rules/${ rule.uid }`, {
				position: other.position,
			} ),
			api.patch( `/reminder_rules/${ other.uid }`, {
				position: rule.position,
			} ),
		] );
		refresh();
	};

	const remove = async ( rule: Rule ) => {
		setBusy( true );
		try {
			await api.delete( `/reminder_rules/${ rule.uid }` );
			setRemoving( null );
			refresh();
			toast.success( __( 'Reminder removed.', 'cliezoo' ) );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		} finally {
			setBusy( false );
		}
	};

	const toggle = (
		field: 'recipients' | 'channels' | 'stop_statuses',
		value: string,
		on: boolean
	) => {
		const set = new Set( editing?.[ field ] ?? [] );
		if ( on ) {
			set.add( value );
		} else {
			set.delete( value );
		}
		setEditing( { ...editing, [ field ]: Array.from( set ) } );
	};

	if ( meta.isPending ) {
		return <Skeleton className="h-48" />;
	}
	if ( meta.isError ) {
		return (
			<ErrorState error={ meta.error } onRetry={ () => meta.refetch() } />
		);
	}
	if ( ! targets.length ) {
		return (
			<EmptyState
				title={ __( 'Nothing to remind about yet', 'cliezoo' ) }
			>
				{ __(
					'Record types with dates — invoices, tasks, contracts — appear here once their modules are active.',
					'cliezoo'
				) }
			</EmptyState>
		);
	}

	const offset = splitOffset(
		editing?.offset_minutes ?? 0,
		UNIT_KEYS[ unit ]
	);
	// Recipients that name somebody rather than a part they play:
	// "member:{uid}" and "role:{key}".
	const named = ( editing?.recipients ?? [] ).filter(
		( one ) => one.startsWith( 'member:' ) || one.startsWith( 'role:' )
	);
	const nameOf = ( recipient: string ): string => {
		if ( recipient.startsWith( 'role:' ) ) {
			const key = recipient.slice( 5 );
			return sprintf(
				/* translators: %s: a role, e.g. "Finance". */
				__( 'Everyone who is %s', 'cliezoo' ),
				meta.data?.roles?.[ key ] ?? humanise( key )
			);
		}
		const uid = recipient.slice( 7 );
		return (
			( members.data ?? [] ).find( ( one ) => one.uid === uid )?.name ??
			__( 'Somebody', 'cliezoo' )
		);
	};

	return (
		<div className="space-y-6">
			<div className="flex flex-wrap items-end gap-3">
				<Select
					label={ __( 'Record type', 'cliezoo' ) }
					className="w-60"
					items={ targets.map( ( item ) => ( {
						id: item.type,
						label: item.label,
					} ) ) }
					selectedKey={ current }
					onSelectionChange={ ( key ) => setType( String( key ) ) }
				/>
				<Button
					className="ms-auto"
					variant="secondary"
					icon={ <Save size={ 14 } aria-hidden="true" /> }
					isDisabled={ ! rules.data?.length }
					onPress={ () => setNaming( true ) }
				>
					{ __( 'Save as template', 'cliezoo' ) }
				</Button>
				<Button
					variant="primary"
					icon={ <Plus size={ 14 } aria-hidden="true" /> }
					onPress={ () => setEditing( empty( target ) ) }
				>
					{ __( 'Add reminder', 'cliezoo' ) }
				</Button>
			</div>

			{ rules.isPending && <Skeleton className="h-32" /> }
			{ rules.isError && (
				<ErrorState
					error={ rules.error }
					onRetry={ () => rules.refetch() }
				/>
			) }
			{ ! rules.isPending && ! rules.isError && ! rules.data?.length && (
				<EmptyState title={ __( 'No reminders yet', 'cliezoo' ) }>
					{ __(
						'Add one and everyone you choose hears about these records before or after the date that matters.',
						'cliezoo'
					) }
				</EmptyState>
			) }
			{ !! rules.data?.length && (
				<ul className="divide-y divide-line overflow-hidden rounded-lg border border-line bg-raised">
					{ rules.data.map( ( rule, index ) => (
						<li
							key={ rule.uid }
							className="flex flex-wrap items-center gap-3 px-4 py-3"
						>
							<span className="min-w-0 flex-1">
								<span className="block truncate text-sm font-medium text-fg">
									{ rule.schedule_name ||
										ruleSummary( rule, target ) }
								</span>
								<span className="block truncate text-xs text-muted">
									{ rule.schedule_name
										? ruleSummary( rule, target )
										: rule.subject ||
											__(
												'Uses the default message',
												'cliezoo'
											) }
								</span>
							</span>
							{ ! rule.active && (
								<Badge tone="neutral">
									{ __( 'Off', 'cliezoo' ) }
								</Badge>
							) }
							<Button
								variant="ghost"
								size="sm"
								onPress={ () => setEditing( rule ) }
							>
								{ __( 'Edit', 'cliezoo' ) }
							</Button>
							<IconButton
								size="sm"
								label={ __( 'Move up', 'cliezoo' ) }
								tooltip={ false }
								icon={
									<ArrowUp size={ 14 } aria-hidden="true" />
								}
								isDisabled={ 0 === index }
								onPress={ () => move( rule, -1 ) }
							/>
							<IconButton
								size="sm"
								label={ __( 'Move down', 'cliezoo' ) }
								tooltip={ false }
								icon={
									<ArrowDown size={ 14 } aria-hidden="true" />
								}
								isDisabled={
									index === ( rules.data?.length ?? 1 ) - 1
								}
								onPress={ () => move( rule, 1 ) }
							/>
							<IconButton
								size="sm"
								label={ __( 'Remove reminder', 'cliezoo' ) }
								icon={
									<Trash2 size={ 14 } aria-hidden="true" />
								}
								onPress={ () => setRemoving( rule ) }
							/>
						</li>
					) ) }
				</ul>
			) }

			<section aria-labelledby="reminder-templates">
				<h3
					id="reminder-templates"
					className="mb-1 text-sm font-semibold text-fg"
				>
					{ __( 'Templates', 'cliezoo' ) }
				</h3>
				<p className="mb-2 text-xs text-muted">
					{ __(
						'A saved schedule, such as "Gentle reminders", to add back here whenever you want it again.',
						'cliezoo'
					) }
				</p>
				{ ! schedules.length && (
					<p className="text-sm text-muted">
						{ __(
							'No templates yet. Set up the reminders above, then save them as a template.',
							'cliezoo'
						) }
					</p>
				) }
				{ !! schedules.length && (
					<ul className="divide-y divide-line overflow-hidden rounded-lg border border-line bg-raised text-sm">
						{ schedules.map( ( [ name, list ] ) => (
							<li
								key={ name }
								className="flex flex-wrap items-center gap-3 px-4 py-2"
							>
								<span className="min-w-0 flex-1">
									<span className="block truncate font-medium text-fg">
										{ name }
									</span>
									<span className="block truncate text-xs text-muted">
										{ list
											.map( ( rule ) =>
												ruleSummary( rule, target )
											)
											.join( ' · ' ) }
									</span>
								</span>
								<Button
									size="sm"
									variant="ghost"
									isDisabled={ busy }
									onPress={ () => applyTemplate( name ) }
								>
									{ __( 'Add to this schedule', 'cliezoo' ) }
								</Button>
								<IconButton
									size="sm"
									label={ sprintf(
										/* translators: %s: template name. */
										__( 'Remove template %s', 'cliezoo' ),
										name
									) }
									icon={
										<Trash2
											size={ 14 }
											aria-hidden="true"
										/>
									}
									onPress={ () =>
										removeTemplate( name, list )
									}
								/>
							</li>
						) ) }
					</ul>
				) }
			</section>

			<Dialog
				isOpen={ naming }
				onOpenChange={ () => setNaming( false ) }
				title={ __( 'Save as template', 'cliezoo' ) }
				description={ __(
					'Keeps the reminders above under a name. A template with the same name is replaced.',
					'cliezoo'
				) }
				footer={ () => (
					<Button
						variant="primary"
						isPending={ busy }
						isDisabled={ ! templateName.trim() }
						onPress={ saveTemplate }
					>
						{ __( 'Save template', 'cliezoo' ) }
					</Button>
				) }
			>
				<TextField
					label={ __( 'Template name', 'cliezoo' ) }
					placeholder={ __( 'Gentle reminders', 'cliezoo' ) }
					value={ templateName }
					onChange={ setTemplateName }
				/>
			</Dialog>

			<section aria-labelledby="reminder-log">
				<h3
					id="reminder-log"
					className="mb-2 text-sm font-semibold text-fg"
				>
					{ __( 'Recently sent', 'cliezoo' ) }
				</h3>
				{ ! logRows.length && (
					<p className="text-sm text-muted">
						{ __( 'Nothing has been sent yet.', 'cliezoo' ) }
					</p>
				) }
				{ !! logRows.length && (
					<ul className="divide-y divide-line overflow-hidden rounded-lg border border-line bg-raised text-sm">
						{ logRows.map( ( row ) => (
							<li
								key={ row.uid }
								className="flex flex-wrap items-center gap-3 px-4 py-2"
							>
								<span className="min-w-0 flex-1 truncate">
									{ row.record?.label ??
										__( 'Removed record', 'cliezoo' ) }
								</span>
								<span className="text-xs text-muted">
									{ formatDate( row.sent_at ?? row.due_at ) }
									{ ' · ' }
									{ formatTime( row.sent_at ?? row.due_at ) }
								</span>
								<Badge
									tone={
										'failed' === row.status
											? 'danger'
											: 'neutral'
									}
								>
									{ row.status }
								</Badge>
							</li>
						) ) }
					</ul>
				) }
			</section>

			<Dialog
				isOpen={ !! removing }
				onOpenChange={ () => setRemoving( null ) }
				role="alertdialog"
				title={ __( 'Remove this reminder?', 'cliezoo' ) }
				description={ __(
					'Anything already scheduled by it stops. Reminders that were sent stay in the log.',
					'cliezoo'
				) }
				footer={ () => (
					<Button
						variant="danger"
						isPending={ busy }
						onPress={ () => {
							if ( removing ) {
								remove( removing );
							}
						} }
					>
						{ __( 'Remove reminder', 'cliezoo' ) }
					</Button>
				) }
			/>

			<Dialog
				isOpen={ !! editing }
				onOpenChange={ () => setEditing( null ) }
				size="lg"
				title={
					editing?.uid
						? __( 'Edit reminder', 'cliezoo' )
						: __( 'Add reminder', 'cliezoo' )
				}
				footer={ () => (
					<Button
						variant="primary"
						isPending={ busy }
						isDisabled={ ! editing?.date_field }
						onPress={ save }
					>
						{ __( 'Save reminder', 'cliezoo' ) }
					</Button>
				) }
			>
				<div className="space-y-4">
					<TextField
						label={ __( 'Name', 'cliezoo' ) }
						description={ __(
							'Optional, so you recognise it in the list.',
							'cliezoo'
						) }
						value={ editing?.schedule_name ?? '' }
						onChange={ ( value ) =>
							setEditing( {
								...editing,
								schedule_name: value,
							} )
						}
					/>
					<div className="grid gap-4 sm:grid-cols-3">
						<TextField
							label={ __( 'How long', 'cliezoo' ) }
							type="number"
							value={ String( offset.value ) }
							errorMessage={ errors.offset_minutes }
							onChange={ ( value ) =>
								setEditing( {
									...editing,
									offset_minutes:
										Math.max( 0, Number( value ) || 0 ) *
										unit,
								} )
							}
						/>
						<Select
							label={ __( 'Unit', 'cliezoo' ) }
							items={ UNITS().map( ( [ size, label ] ) => ( {
								id: String( size ),
								label,
							} ) ) }
							selectedKey={ String( unit ) }
							onSelectionChange={ ( key ) => {
								const next = Number( key );
								setUnit( next );
								setEditing( {
									...editing,
									offset_minutes: offset.value * next,
									offset_unit: UNIT_KEYS[ next ] ?? null,
								} );
							} }
						/>
						<Select
							label={ __( 'When', 'cliezoo' ) }
							items={ [
								{
									id: 'before',
									label: __( 'Before', 'cliezoo' ),
								},
								{ id: 'on', label: __( 'On', 'cliezoo' ) },
								{
									id: 'after',
									label: __( 'After', 'cliezoo' ),
								},
							] }
							selectedKey={ editing?.anchor ?? 'before' }
							onSelectionChange={ ( key ) =>
								setEditing( {
									...editing,
									anchor: String( key ),
								} )
							}
						/>
					</div>
					<div className="grid gap-4 sm:grid-cols-2">
						<Select
							label={ __( 'Date', 'cliezoo' ) }
							items={ Object.entries( target?.dates ?? {} ).map(
								( [ id, label ] ) => ( { id, label } )
							) }
							selectedKey={ editing?.date_field ?? '' }
							onSelectionChange={ ( key ) =>
								setEditing( {
									...editing,
									date_field: String( key ),
								} )
							}
						/>
						<TextField
							label={ __( 'Send at', 'cliezoo' ) }
							description={ __(
								'Optional time of day, e.g. 09:00.',
								'cliezoo'
							) }
							value={ editing?.at_time ?? '' }
							errorMessage={ errors.at_time }
							onChange={ ( value ) =>
								setEditing( { ...editing, at_time: value } )
							}
						/>
					</div>
					<div className="grid gap-4 sm:grid-cols-2">
						<TextField
							label={ __( 'Repeat every (minutes)', 'cliezoo' ) }
							description={ __(
								'0 sends it once. Repeats are at least 15 minutes apart.',
								'cliezoo'
							) }
							type="number"
							value={ String( editing?.repeat_minutes ?? 0 ) }
							onChange={ ( value ) =>
								setEditing( {
									...editing,
									repeat_minutes: Number( value ) || 0,
								} )
							}
						/>
						<TextField
							label={ __( 'Stop after', 'cliezoo' ) }
							description={ __(
								'0 keeps repeating until something stops it.',
								'cliezoo'
							) }
							type="number"
							value={ String( editing?.max_count ?? 0 ) }
							onChange={ ( value ) =>
								setEditing( {
									...editing,
									max_count: Number( value ) || 0,
								} )
							}
						/>
					</div>
					{ !! target?.stop?.length && (
						<fieldset>
							<legend className="mb-1 text-sm font-medium text-fg">
								{ __( 'Stop when the record is', 'cliezoo' ) }
							</legend>
							<div className="flex flex-wrap gap-x-5 gap-y-1">
								{ target.stop.map( ( status ) => (
									<Checkbox
										key={ status }
										isSelected={ (
											editing?.stop_statuses ?? []
										).includes( status ) }
										onChange={ ( on ) =>
											toggle(
												'stop_statuses',
												status,
												on
											)
										}
									>
										{ humanise( status ) }
									</Checkbox>
								) ) }
							</div>
						</fieldset>
					) }
					<fieldset>
						<legend className="mb-1 text-sm font-medium text-fg">
							{ __( 'Tell', 'cliezoo' ) }
						</legend>
						<div className="flex flex-wrap gap-x-5 gap-y-1">
							{ Object.entries( target?.roles ?? {} ).map(
								( [ id, label ] ) => (
									<Checkbox
										key={ id }
										isSelected={ (
											editing?.recipients ?? []
										).includes( id ) }
										onChange={ ( on ) =>
											toggle( 'recipients', id, on )
										}
									>
										{ label }
									</Checkbox>
								)
							) }
						</div>
						{ !! named.length && (
							<ul
								className="mt-2 flex flex-wrap gap-2"
								aria-label={ __(
									'People and roles who are told',
									'cliezoo'
								) }
							>
								{ named.map( ( recipient ) => (
									<li
										key={ recipient }
										className="flex items-center gap-1 rounded-full border border-line bg-surface py-0.5 ps-2.5 pe-1 text-xs text-fg"
									>
										{ nameOf( recipient ) }
										<IconButton
											size="sm"
											tooltip={ false }
											label={ sprintf(
												/* translators: %s: a person or a role. */
												__(
													'Stop telling %s',
													'cliezoo'
												),
												nameOf( recipient )
											) }
											icon={
												<X
													size={ 12 }
													aria-hidden="true"
												/>
											}
											onPress={ () =>
												toggle(
													'recipients',
													recipient,
													false
												)
											}
										/>
									</li>
								) ) }
							</ul>
						) }
						<div className="mt-2 grid gap-3 sm:grid-cols-2">
							<Select
								label={ __( 'Also tell a person', 'cliezoo' ) }
								placeholder={ __(
									'Choose somebody',
									'cliezoo'
								) }
								items={ ( members.data ?? [] ).map(
									( one ) => ( {
										id: `member:${ one.uid }`,
										label: one.name,
									} )
								) }
								selectedKey={ null }
								onSelectionChange={ ( key ) => {
									if ( key ) {
										toggle(
											'recipients',
											String( key ),
											true
										);
									}
								} }
							/>
							<Select
								label={ __(
									'Also tell everyone with a role',
									'cliezoo'
								) }
								placeholder={ __( 'Choose a role', 'cliezoo' ) }
								items={ Object.entries(
									meta.data?.roles ?? {}
								).map( ( [ id, label ] ) => ( {
									id: `role:${ id }`,
									label,
								} ) ) }
								selectedKey={ null }
								onSelectionChange={ ( key ) => {
									if ( key ) {
										toggle(
											'recipients',
											String( key ),
											true
										);
									}
								} }
							/>
						</div>
					</fieldset>
					<fieldset>
						<legend className="mb-1 text-sm font-medium text-fg">
							{ __( 'Through', 'cliezoo' ) }
						</legend>
						<div className="flex flex-wrap gap-x-5 gap-y-1">
							{ Object.entries( meta.data?.channels ?? {} ).map(
								( [ id, label ] ) => (
									<Checkbox
										key={ id }
										isSelected={ (
											editing?.channels ?? []
										).includes( id ) }
										onChange={ ( on ) =>
											toggle( 'channels', id, on )
										}
									>
										{ label }
									</Checkbox>
								)
							) }
						</div>
					</fieldset>
					<TextField
						label={ __( 'Subject', 'cliezoo' ) }
						value={ editing?.subject ?? '' }
						errorMessage={ errors.subject }
						onChange={ ( value ) =>
							setEditing( { ...editing, subject: value } )
						}
					/>
					<TextField
						label={ __( 'Message', 'cliezoo' ) }
						description={ __(
							'Leave empty to use the reminder template.',
							'cliezoo'
						) }
						multiline
						rows={ 3 }
						value={ editing?.message ?? '' }
						onChange={ ( value ) =>
							setEditing( { ...editing, message: value } )
						}
					/>
					<Slot
						name="reminders.rule.message"
						variant="row"
						props={ {
							rule: editing,
							onExtra: ( extra: Record< string, unknown > ) =>
								setEditing( ( now ) => ( {
									...now,
									extra: {
										...( now?.extra ?? {} ),
										...extra,
									},
								} ) ),
						} }
					/>
					<Switch
						isSelected={ editing?.active ?? true }
						onChange={ ( on ) =>
							setEditing( { ...editing, active: on } )
						}
					>
						{ __( 'Active', 'cliezoo' ) }
					</Switch>
				</div>
			</Dialog>
		</div>
	);
}
