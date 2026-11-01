/**
 * Bulk edit for the universal list (cliezoo.md §6.2): set one field on
 * every ticked row — assign, change status, any other field — or add and
 * remove tags, each row keeping the tags it had.
 *
 * It all goes through `POST /{object}/bulk`, which checks every row on its
 * own: a row this person may not change is reported back, never quietly
 * changed, and the toast says how many were.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, type ApiError } from '@cliezoo/data';
import { Button, Dialog, Select, TextField, toast } from '@cliezoo/ui';
import { FieldInput } from '../form/FieldInput';
import type { FieldMeta } from './meta';

/** Field types a value can be given to many rows at once. */
const BULK_TYPES = [
	'enum',
	'select',
	'user',
	'ref',
	'bool',
	'checkbox',
	'date',
	'datetime',
	'text',
	'email',
	'phone',
	'url',
	'int',
	'bigint',
	'decimal',
	'number',
];

/**
 * Whether many rows can be given one value of a field.
 *
 * @param field Field.
 */
export function bulkEditable( field: FieldMeta ): boolean {
	return (
		! field.readonly &&
		! field.system &&
		'computed' !== field.type &&
		BULK_TYPES.includes( field.type )
	);
}

/**
 * The field "Assign" and "Change status" start on, if the list has one.
 *
 * @param fields Fields.
 * @param what   Which shortcut.
 */
export function shortcutField(
	fields: FieldMeta[],
	what: 'assign' | 'status'
): FieldMeta | undefined {
	const editable = fields.filter( bulkEditable );
	if ( 'assign' === what ) {
		return (
			editable.find( ( f ) =>
				[ 'owner', 'assignee', 'owner_id', 'assignee_id' ].includes(
					f.key
				)
			) ?? editable.find( ( f ) => 'user' === f.type )
		);
	}
	return editable.find( ( f ) => [ 'status', 'stage' ].includes( f.key ) );
}

/**
 * The body a bulk update sends: custom fields travel inside `custom`, as
 * they do everywhere else.
 *
 * @param field The field.
 * @param value The value.
 */
export function bulkChanges(
	field: FieldMeta,
	value: unknown
): Record< string, unknown > {
	return field.key.startsWith( 'custom.' )
		? { custom: { [ field.key.slice( 7 ) ]: value } }
		: { [ field.key ]: value };
}

/**
 * Splits typed tags ("web, print") into words.
 *
 * @param text What was typed.
 */
export function splitTags( text: string ): string[] {
	return [
		...new Set(
			text
				.split( ',' )
				.map( ( tag ) => tag.trim() )
				.filter( Boolean )
		),
	];
}

interface Result {
	done: string[];
	failed: Record< string, string >;
}

/**
 * Says what a bulk change did, including what it could not do.
 *
 * @param result What the server did.
 */
export function reportBulk( result: Result ): void {
	const failed = Object.keys( result.failed ?? {} ).length;
	const done = sprintf(
		/* translators: %d: number of records. */
		_n(
			'%d record updated.',
			'%d records updated.',
			result.done.length,
			'cliezoo'
		),
		result.done.length
	);
	if ( ! failed ) {
		toast.success( done );
		return;
	}
	toast.warning(
		`${ done } ${ sprintf(
			/* translators: %d: number of records. */
			_n(
				'%d could not be changed by you, and was left as it was.',
				'%d could not be changed by you, and were left as they were.',
				failed,
				'cliezoo'
			),
			failed
		) }`
	);
}

export function BulkEdit( {
	mode,
	fields,
	start,
	collection,
	uids,
	onClose,
	onDone,
}: {
	/** Set one field, or add/remove tags. */
	mode: 'field' | 'tags';
	fields: FieldMeta[];
	/** The field to begin with ("Assign", "Change status"). */
	start?: string;
	/** REST collection, e.g. "/projects". */
	collection: string;
	uids: string[];
	onClose: () => void;
	onDone: () => void;
} ) {
	const editable = fields.filter( bulkEditable );
	const [ key, setKey ] = useState( start ?? editable[ 0 ]?.key ?? '' );
	const [ value, setValue ] = useState< unknown >( null );
	const [ add, setAdd ] = useState( '' );
	const [ remove, setRemove ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const field = editable.find( ( f ) => f.key === key );
	const count = uids.length;

	const apply = async ( close: () => void ) => {
		setBusy( true );
		try {
			const body =
				'tags' === mode
					? {
							uids,
							operation: 'tag',
							add: splitTags( add ),
							remove: splitTags( remove ),
						}
					: {
							uids,
							operation: 'update',
							changes: field ? bulkChanges( field, value ) : {},
						};
			const result = (
				await api.post< Result >( `${ collection }/bulk`, body )
			).data;
			reportBulk( result );
			close();
			onDone();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	const ready =
		'tags' === mode
			? splitTags( add ).length + splitTags( remove ).length > 0
			: !! field;

	return (
		<Dialog
			isOpen
			onOpenChange={ ( open ) => ! open && onClose() }
			title={
				'tags' === mode
					? __( 'Tag records', 'cliezoo' )
					: __( 'Edit records', 'cliezoo' )
			}
			description={ sprintf(
				/* translators: %d: number of selected records. */
				_n(
					'Changes %d selected record.',
					'Changes %d selected records.',
					count,
					'cliezoo'
				),
				count
			) }
			footer={ ( close ) => (
				<>
					<Button variant="ghost" onPress={ close }>
						{ __( 'Cancel', 'cliezoo' ) }
					</Button>
					<Button
						variant="primary"
						isPending={ busy }
						isDisabled={ ! ready }
						onPress={ () => apply( close ) }
					>
						{ __( 'Apply', 'cliezoo' ) }
					</Button>
				</>
			) }
		>
			{ 'tags' === mode ? (
				<div className="space-y-3">
					<TextField
						label={ __( 'Add tags', 'cliezoo' ) }
						description={ __(
							'Separate tags with commas. Each record keeps the tags it has.',
							'cliezoo'
						) }
						value={ add }
						onChange={ setAdd }
					/>
					<TextField
						label={ __( 'Remove tags', 'cliezoo' ) }
						value={ remove }
						onChange={ setRemove }
					/>
				</div>
			) : (
				<div className="space-y-3">
					<Select
						label={ __( 'Field', 'cliezoo' ) }
						items={ editable.map( ( f ) => ( {
							id: f.key,
							label: f.label,
						} ) ) }
						selectedKey={ key || null }
						onSelectionChange={ ( next ) => {
							setKey( String( next ?? '' ) );
							setValue( null );
						} }
					/>
					{ field && (
						<FieldInput
							key={ field.key }
							field={ { ...field, required: false } }
							value={ value }
							onChange={ setValue }
						/>
					) }
					<p className="text-sm text-muted">
						{ __(
							'Left empty, the field is cleared on every selected record.',
							'cliezoo'
						) }
					</p>
				</div>
			) }
		</Dialog>
	);
}
