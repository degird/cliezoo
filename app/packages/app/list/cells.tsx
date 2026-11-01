/**
 * Read-only cell renderers by field type, plus plain-text values for export and grouping.
 */
import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import {
	formatDate,
	formatMoney,
	formatNumber,
	formatTime,
	safeHref,
} from '@cliezoo/data';
import { Badge } from '@cliezoo/ui';
import type { FieldMeta } from './meta';
import { registeredCell } from '../form/RegisteredField';

type Ref = { uid: string; label: string; number?: string | null };
type Money = { amount: number; currency: string };

const isRef = ( value: unknown ): value is Ref =>
	!! value && typeof value === 'object' && 'uid' in ( value as object );
const isMoney = ( value: unknown ): value is Money =>
	!! value && typeof value === 'object' && 'amount' in ( value as object );

/**
 * Human label for an enum value ("in_progress" → "In progress").
 *
 * @param value Value.
 */
export function humanise( value: string ): string {
	const text = value.replace( /[_-]+/g, ' ' ).trim();
	return text.charAt( 0 ).toUpperCase() + text.slice( 1 );
}

/**
 * The label the workspace gave a list value, when the server sent one.
 *
 * Editable lists ("Mid-Market", "Onboarding") are named by the people using
 * them, and those names are translated with the rest of the workspace. A
 * label made out of the stored value would be English for ever.
 *
 * @param field Field.
 * @param row   The record, when the caller has it.
 */
function listLabel(
	field: FieldMeta,
	row?: Record< string, unknown >
): string | undefined {
	const label = row?.[ `${ field.key }_label` ];
	return 'string' === typeof label && '' !== label ? label : undefined;
}

/**
 * Plain text for a value (CSV, grouping, sorting of loaded rows).
 *
 * @param field Field.
 * @param value Value.
 * @param row   The whole record, when the caller has it.
 */
export function textOf(
	field: FieldMeta,
	value: unknown,
	row?: Record< string, unknown >
): string {
	if ( value === null || value === undefined || value === '' ) {
		return '';
	}
	const label = listLabel( field, row );
	if ( undefined !== label ) {
		return label;
	}
	if ( isRef( value ) ) {
		return value.number
			? `${ value.number } ${ value.label }`
			: value.label;
	}
	if ( isMoney( value ) ) {
		return formatMoney( value.amount, value.currency );
	}
	switch ( field.type ) {
		case 'bool':
		case 'checkbox':
			return value ? __( 'Yes', 'cliezoo' ) : __( 'No', 'cliezoo' );
		case 'date':
			return formatDate( String( value ) );
		case 'datetime':
			return `${ formatDate( String( value ) ) } ${ formatTime( String( value ) ) }`;
		case 'enum':
		case 'select':
			return humanise( String( value ) );
		case 'int':
		case 'bigint':
		case 'decimal':
		case 'number':
			return formatNumber(
				Number( value ),
				field.type === 'decimal' ? 2 : 0
			);
		default:
			if ( Array.isArray( value ) ) {
				return value
					.map( ( item ) =>
						isRef( item ) ? item.label : String( item )
					)
					.join( ', ' );
			}
			return typeof value === 'object'
				? JSON.stringify( value )
				: String( value );
	}
}

const ENUM_TONES: Record<
	string,
	'success' | 'warning' | 'danger' | 'info' | 'accent' | 'neutral'
> = {
	active: 'success',
	done: 'success',
	paid: 'success',
	completed: 'success',
	won: 'success',
	accepted: 'success',
	invited: 'info',
	pending: 'warning',
	draft: 'neutral',
	overdue: 'danger',
	failed: 'danger',
	lost: 'danger',
	deactivated: 'neutral',
	cancelled: 'neutral',
};

/**
 * Cell content.
 *
 * @param field Field.
 * @param value Value.
 * @param row   The whole record, when the caller has it.
 */
export function renderCell(
	field: FieldMeta,
	value: unknown,
	row?: Record< string, unknown >
): ReactNode {
	if (
		value === null ||
		value === undefined ||
		value === '' ||
		( Array.isArray( value ) && ! value.length )
	) {
		return (
			<span
				className="text-subtle"
				aria-label={ __( 'Empty', 'cliezoo' ) }
			>
				—
			</span>
		);
	}
	// A type an add-on registered (registerFieldType) is drawn its way.
	const registered = registeredCell( field, value );
	if ( undefined !== registered ) {
		return registered;
	}
	const label = listLabel( field, row );
	if ( undefined !== label ) {
		const color = row?.[ `${ field.key }_color` ];
		return 'string' === typeof color && '' !== color ? (
			<span
				className="inline-flex items-center gap-1.5 text-sm"
				style={ { color } }
			>
				<span
					aria-hidden="true"
					className="size-2 rounded-full"
					style={ { backgroundColor: color } }
				/>
				<span className="text-fg">{ label }</span>
			</span>
		) : (
			<Badge tone="neutral">{ label }</Badge>
		);
	}
	switch ( field.type ) {
		case 'enum':
		case 'select':
			return (
				<Badge tone={ ENUM_TONES[ String( value ) ] ?? 'neutral' }>
					{ humanise( String( value ) ) }
				</Badge>
			);
		case 'bool':
		case 'checkbox':
			return value ? (
				__( 'Yes', 'cliezoo' )
			) : (
				<span className="text-muted">{ __( 'No', 'cliezoo' ) }</span>
			);
		case 'email':
			return (
				<a
					className="text-accent-fg hover:underline"
					href={ `mailto:${ String( value ) }` }
					onClick={ ( e ) => e.stopPropagation() }
				>
					{ String( value ) }
				</a>
			);
		case 'url': {
			// The column shows what the record holds either way; only an
			// address the browser may follow becomes a link.
			const address = safeHref( value );
			const shown = String( value ).replace( /^https?:\/\//, '' );
			return address ? (
				<a
					className="text-accent-fg hover:underline"
					href={ address }
					target="_blank"
					rel="noopener noreferrer"
					onClick={ ( e ) => e.stopPropagation() }
				>
					{ shown }
				</a>
			) : (
				<span>{ shown }</span>
			);
		}
		case 'color':
			return (
				<span className="inline-flex items-center gap-1.5">
					<span
						aria-hidden="true"
						className="size-3 rounded-full border border-line"
						style={ { backgroundColor: String( value ) } }
					/>
					{ String( value ) }
				</span>
			);
		case 'money':
		case 'int':
		case 'bigint':
		case 'decimal':
		case 'number':
			return (
				<span className="tabular-nums">{ textOf( field, value ) }</span>
			);
		default:
			return <span className="truncate">{ textOf( field, value ) }</span>;
	}
}

/**
 * CSV cell with spreadsheet-formula neutralising (§31.8: cells starting with
 * = + - @ tab or CR are prefixed so they are never run as formulas).
 *
 * @param text Text.
 */
export function csvCell( text: string ): string {
	const safe = /^[=+\-@\t\r]/.test( text ) ? `'${ text }` : text;
	return /[",\n\r]/.test( safe ) ? `"${ safe.replace( /"/g, '""' ) }"` : safe;
}

/**
 * Builds and downloads a CSV file.
 *
 * @param name   File name without extension.
 * @param header Header labels.
 * @param rows   Rows of text cells.
 */
export function downloadCsv(
	name: string,
	header: string[],
	rows: string[][]
): void {
	const body = [ header, ...rows ]
		.map( ( row ) => row.map( csvCell ).join( ',' ) )
		.join( '\r\n' );
	const blob = new Blob( [ '﻿' + body ], { type: 'text/csv;charset=utf-8' } );
	const url = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = `${ name }.csv`;
	link.click();
	URL.revokeObjectURL( url );
}
