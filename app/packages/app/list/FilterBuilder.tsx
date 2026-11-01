/**
 * Advanced filter builder: conditions joined by AND or OR, operators by
 * field type, relative date ranges, record pickers for references.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Plus, Trash2 } from 'lucide-react';
import { api, useQuery } from '@cliezoo/data';
import { Button, IconButton, Select, TextField, toast } from '@cliezoo/ui';
import { humanise } from './cells';
import {
	operatorsFor,
	type Condition,
	type FieldMeta,
	type FilterGroup,
	type Operator,
} from './meta';

const OP_LABELS = (): Record< Operator, string > => ( {
	eq: __( 'is', 'cliezoo' ),
	neq: __( 'is not', 'cliezoo' ),
	in: __( 'is any of', 'cliezoo' ),
	nin: __( 'is none of', 'cliezoo' ),
	contains: __( 'contains', 'cliezoo' ),
	ncontains: __( 'does not contain', 'cliezoo' ),
	starts: __( 'starts with', 'cliezoo' ),
	gt: __( 'is after / more than', 'cliezoo' ),
	gte: __( 'is at least', 'cliezoo' ),
	lt: __( 'is before / less than', 'cliezoo' ),
	lte: __( 'is at most', 'cliezoo' ),
	between: __( 'is between', 'cliezoo' ),
	empty: __( 'is empty', 'cliezoo' ),
	nempty: __( 'is not empty', 'cliezoo' ),
	within: __( 'is within', 'cliezoo' ),
} );

const RANGES = (): Array< [ string, string ] > => [
	[ 'today', __( 'Today', 'cliezoo' ) ],
	[ 'yesterday', __( 'Yesterday', 'cliezoo' ) ],
	[ 'tomorrow', __( 'Tomorrow', 'cliezoo' ) ],
	[ 'this_week', __( 'This week', 'cliezoo' ) ],
	[ 'last_week', __( 'Last week', 'cliezoo' ) ],
	[ 'next_week', __( 'Next week', 'cliezoo' ) ],
	[ 'this_month', __( 'This month', 'cliezoo' ) ],
	[ 'last_month', __( 'Last month', 'cliezoo' ) ],
	[ 'next_month', __( 'Next month', 'cliezoo' ) ],
	[ 'this_quarter', __( 'This quarter', 'cliezoo' ) ],
	[ 'last_quarter', __( 'Last quarter', 'cliezoo' ) ],
	[ 'this_year', __( 'This year', 'cliezoo' ) ],
	[ 'last_year', __( 'Last year', 'cliezoo' ) ],
	[ 'overdue', __( 'Overdue', 'cliezoo' ) ],
	[ 'upcoming', __( 'Upcoming', 'cliezoo' ) ],
];

/**
 * A typed number (money is stored in minor units).
 *
 * @param field Field.
 * @param text  Typed text.
 */
function toNumber( field: FieldMeta, text: string ): number {
	return field.type === 'money'
		? Math.round( Number( text ) * 100 )
		: Number( text );
}

function RefOptions( {
	entity,
	value,
	onChange,
	label,
}: {
	entity: string;
	value: unknown;
	onChange: ( value: string ) => void;
	label: string;
} ) {
	const options = useQuery( {
		queryKey: [ entity, 'list', { picker: true } ],
		staleTime: 60_000,
		queryFn: async () =>
			(
				await api.get< Array< Record< string, unknown > > >(
					`/${ entity }`,
					{ limit: 100 }
				)
			).data,
	} );
	const items = ( options.data ?? [] ).map( ( row ) => ( {
		id: String( row.uid ),
		label: String(
			row.name ?? row.title ?? row.label ?? row.number ?? row.uid
		),
	} ) );
	return (
		<Select
			aria-label={ label }
			items={ items }
			selectedKey={ ( value as string ) ?? null }
			onSelectionChange={ ( key ) => onChange( String( key ) ) }
			placeholder={ __( 'Choose…', 'cliezoo' ) }
		/>
	);
}

function ValueInput( {
	field,
	condition,
	onChange,
}: {
	field: FieldMeta;
	condition: Condition;
	onChange: ( value: unknown ) => void;
} ) {
	if ( condition.op === 'empty' || condition.op === 'nempty' ) {
		return null;
	}
	const label = __( 'Value', 'cliezoo' );
	if ( condition.op === 'within' ) {
		return (
			<Select
				aria-label={ label }
				items={ RANGES().map( ( [ id, text ] ) => ( {
					id,
					label: text,
				} ) ) }
				selectedKey={ ( condition.value as string ) ?? null }
				onSelectionChange={ ( key ) => onChange( String( key ) ) }
				placeholder={ __( 'Choose a period…', 'cliezoo' ) }
			/>
		);
	}
	if ( field.type === 'bool' || field.type === 'checkbox' ) {
		return (
			<Select
				aria-label={ label }
				items={ [
					{ id: '1', label: __( 'Yes', 'cliezoo' ) },
					{ id: '0', label: __( 'No', 'cliezoo' ) },
				] }
				selectedKey={
					condition.value === undefined
						? null
						: String( condition.value ? 1 : 0 )
				}
				onSelectionChange={ ( key ) => onChange( key === '1' ) }
			/>
		);
	}
	if (
		( field.type === 'enum' || field.type === 'select' ) &&
		field.options?.length &&
		( condition.op === 'eq' || condition.op === 'neq' )
	) {
		return (
			<Select
				aria-label={ label }
				items={ field.options.map( ( option ) => ( {
					id: option,
					label: humanise( option ),
				} ) ) }
				selectedKey={ ( condition.value as string ) ?? null }
				onSelectionChange={ ( key ) => onChange( String( key ) ) }
				placeholder={ __( 'Choose…', 'cliezoo' ) }
			/>
		);
	}
	if (
		( field.type === 'ref' || field.type === 'user' ) &&
		field.ref &&
		( condition.op === 'eq' || condition.op === 'neq' )
	) {
		return (
			<RefOptions
				entity={ field.ref }
				value={ condition.value }
				onChange={ onChange }
				label={ label }
			/>
		);
	}
	const isDate = field.type === 'date' || field.type === 'datetime';
	const isNumber = [ 'int', 'bigint', 'decimal', 'money', 'number' ].includes(
		field.type
	);
	if ( condition.op === 'between' ) {
		const pair = Array.isArray( condition.value )
			? ( condition.value as string[] )
			: [ '', '' ];
		return (
			<div className="flex gap-2">
				<TextField
					aria-label={ __( 'From', 'cliezoo' ) }
					type={ isDate ? 'date' : 'text' }
					value={ pair[ 0 ] ?? '' }
					onChange={ ( v ) => onChange( [ v, pair[ 1 ] ?? '' ] ) }
				/>
				<TextField
					aria-label={ __( 'To', 'cliezoo' ) }
					type={ isDate ? 'date' : 'text' }
					value={ pair[ 1 ] ?? '' }
					onChange={ ( v ) => onChange( [ pair[ 0 ] ?? '', v ] ) }
				/>
			</div>
		);
	}
	if ( condition.op === 'in' || condition.op === 'nin' ) {
		return (
			<TextField
				aria-label={ label }
				description={ __( 'Separate values with commas.', 'cliezoo' ) }
				value={
					Array.isArray( condition.value )
						? ( condition.value as string[] ).join( ', ' )
						: ''
				}
				onChange={ ( v ) =>
					onChange(
						v
							.split( ',' )
							.map( ( item ) => item.trim() )
							.filter( Boolean )
					)
				}
			/>
		);
	}
	return (
		<TextField
			aria-label={ label }
			type={ isDate ? 'date' : 'text' }
			inputMode={ isNumber ? 'decimal' : undefined }
			value={
				condition.value === undefined || condition.value === null
					? ''
					: String( condition.value )
			}
			onChange={ ( v ) =>
				onChange(
					isNumber && v !== '' && ! Number.isNaN( Number( v ) )
						? toNumber( field, v )
						: v
				)
			}
		/>
	);
}

/**
 * Number of active conditions.
 *
 * @param filter Filter.
 */
export function countConditions( filter: FilterGroup ): number {
	return ( filter.and ?? filter.or ?? [] ).length;
}

/**
 * The conditions that can be sent, and how many were left behind.
 *
 * A condition with nothing typed in it cannot be asked of the server, but
 * dropping one in silence is how four conditions become three without
 * anybody being told.
 *
 * @param conditions What has been built.
 */
export function applicable( conditions: Condition[] ): {
	complete: Condition[];
	dropped: number;
} {
	const complete = conditions.filter(
		( c ) =>
			c.op === 'empty' ||
			c.op === 'nempty' ||
			( c.value !== undefined && c.value !== '' )
	);
	return { complete, dropped: conditions.length - complete.length };
}

export function FilterBuilder( {
	fields,
	value,
	onApply,
	onDraft,
	onClose,
}: {
	fields: FieldMeta[];
	value: FilterGroup;
	onApply: ( filter: FilterGroup ) => void;
	/**
	 * Holds what is being built. The builder lives in a popover, which
	 * unmounts the moment anything outside it is clicked, so the half-built
	 * filter has to be kept somewhere that outlives the popover.
	 */
	onDraft: ( draft: FilterGroup ) => void;
	onClose: () => void;
} ) {
	const filterable = fields.filter( ( field ) => field.filterable );
	const mode: 'and' | 'or' = value.or ? 'or' : 'and';
	const conditions: Condition[] = value.and ?? value.or ?? [];
	const labels = OP_LABELS();
	const byKey = new Map(
		filterable.map( ( field ) => [ field.key, field ] )
	);

	const setAll = ( how: 'and' | 'or', next: Condition[] ) =>
		onDraft( { [ how ]: next } );
	const setConditions = ( next: Condition[] ) => setAll( mode, next );

	const update = ( index: number, change: Partial< Condition > ) =>
		setConditions(
			conditions.map( ( condition, i ) =>
				i === index ? { ...condition, ...change } : condition
			)
		);

	const add = () => {
		const field = filterable[ 0 ];
		if ( field ) {
			setConditions( [
				...conditions,
				{ field: field.key, op: operatorsFor( field.type )[ 0 ] },
			] );
		}
	};

	const apply = () => {
		const { complete, dropped } = applicable( conditions );
		if ( dropped ) {
			toast.warning(
				sprintf(
					/* translators: %d: how many conditions had nothing typed in them. */
					_n(
						'%d condition had nothing to match on and was left out.',
						'%d conditions had nothing to match on and were left out.',
						dropped,
						'cliezoo'
					),
					dropped
				)
			);
		}
		onApply( complete.length ? { [ mode ]: complete } : {} );
		onClose();
	};

	return (
		<div className="w-[min(40rem,calc(100vw-2rem))] p-4">
			<div className="mb-3 flex items-center gap-2 text-sm">
				<span className="text-muted">
					{ __( 'Show records that match', 'cliezoo' ) }
				</span>
				<Select
					aria-label={ __( 'Match', 'cliezoo' ) }
					className="w-28"
					items={ [
						{ id: 'and', label: __( 'all', 'cliezoo' ) },
						{ id: 'or', label: __( 'any', 'cliezoo' ) },
					] }
					selectedKey={ mode }
					onSelectionChange={ ( key ) =>
						setAll( key as 'and' | 'or', conditions )
					}
				/>
				<span className="text-muted">
					{ __( 'of these conditions:', 'cliezoo' ) }
				</span>
			</div>
			{ ! conditions.length && (
				<p className="py-3 text-sm text-muted">
					{ __( 'No conditions yet.', 'cliezoo' ) }
				</p>
			) }
			<ul className="space-y-2">
				{ conditions.map( ( condition, index ) => {
					const field =
						byKey.get( condition.field ) ?? filterable[ 0 ];
					return (
						<li
							key={ index }
							className="grid items-start gap-2 sm:grid-cols-[10rem_10rem_1fr_auto]"
						>
							<Select
								aria-label={ __( 'Field', 'cliezoo' ) }
								items={ filterable.map( ( f ) => ( {
									id: f.key,
									label: f.label,
								} ) ) }
								selectedKey={ condition.field }
								onSelectionChange={ ( key ) => {
									const next = byKey.get( String( key ) );
									update( index, {
										field: String( key ),
										op: operatorsFor(
											next?.type ?? 'text'
										)[ 0 ],
										value: undefined,
									} );
								} }
							/>
							<Select
								aria-label={ __( 'Condition', 'cliezoo' ) }
								items={ operatorsFor(
									field?.type ?? 'text'
								).map( ( op ) => ( {
									id: op,
									label: labels[ op ],
								} ) ) }
								selectedKey={ condition.op }
								onSelectionChange={ ( key ) =>
									update( index, {
										op: key as Operator,
										value: undefined,
									} )
								}
							/>
							<div>
								{ field && (
									<ValueInput
										field={ field }
										condition={ condition }
										onChange={ ( v ) =>
											update( index, { value: v } )
										}
									/>
								) }
							</div>
							<IconButton
								label={ __( 'Remove condition', 'cliezoo' ) }
								icon={
									<Trash2 size={ 15 } aria-hidden="true" />
								}
								tooltip={ false }
								onPress={ () =>
									setConditions(
										conditions.filter(
											( _, i ) => i !== index
										)
									)
								}
							/>
						</li>
					);
				} ) }
			</ul>
			<div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-3">
				<Button
					size="sm"
					icon={ <Plus size={ 14 } aria-hidden="true" /> }
					onPress={ add }
					isDisabled={ ! filterable.length }
				>
					{ __( 'Add condition', 'cliezoo' ) }
				</Button>
				<div className="ms-auto flex gap-2">
					<Button
						size="sm"
						variant="ghost"
						onPress={ () => {
							onApply( {} );
							onClose();
						} }
					>
						{ __( 'Clear', 'cliezoo' ) }
					</Button>
					<Button size="sm" variant="primary" onPress={ apply }>
						{ __( 'Apply', 'cliezoo' ) }
					</Button>
				</div>
			</div>
		</div>
	);
}
