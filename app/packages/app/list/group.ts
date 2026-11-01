/**
 * Group by (cliezoo.md §6.2): the loaded rows of a list, grouped by one
 * field's value — status, stage, owner, client — in the order each group
 * first appears, so a list sorted by due date keeps that order inside and
 * across its groups.
 */
import { __ } from '@wordpress/i18n';
import { textOf } from './cells';
import { valueOf, type FieldMeta } from './meta';

/** Field types a list can be grouped by. */
const GROUPABLE = [ 'enum', 'select', 'ref', 'user', 'bool', 'currency' ];

/**
 * Whether a list can be grouped by a field.
 *
 * @param field The field.
 */
export function groupable( field: FieldMeta ): boolean {
	return GROUPABLE.includes( field.type ) || !! field.list;
}

/**
 * Rows in groups. With no field, one unnamed group holds them all.
 *
 * @param rows  The loaded rows.
 * @param field The field grouped by, if any.
 */
export function groupRows< R extends Record< string, unknown > >(
	rows: R[],
	field?: FieldMeta
): Array< { label: string; rows: R[] } > {
	if ( ! field ) {
		return [ { label: '', rows } ];
	}
	const groups = new Map< string, R[] >();
	for ( const row of rows ) {
		const label =
			textOf( field, valueOf( row, field.key ), row ) ||
			__( 'None', 'cliezoo' );
		const list = groups.get( label );
		if ( list ) {
			list.push( row );
		} else {
			groups.set( label, [ row ] );
		}
	}
	return [ ...groups ].map( ( [ label, grouped ] ) => ( {
		label,
		rows: grouped,
	} ) );
}
