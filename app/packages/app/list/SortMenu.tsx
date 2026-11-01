/**
 * The list's sort menu: the levels a list is sorted by, turning one round,
 * and adding the next one.
 *
 * Shift-clicking a column header adds a level too, but a held key is not
 * something everyone can do with a click; this menu is the same thing for
 * the keyboard and for anybody who never knew about Shift.
 */
import { __, sprintf } from '@wordpress/i18n';
import { ArrowUpDown } from 'lucide-react';
import { Button, Menu, MenuItem, MenuSection, MenuTrigger } from '@cliezoo/ui';
import type { FieldMeta } from './meta';
import { SORT_LEVELS, formatSort, parseSort } from './sort';

export function SortMenu( {
	fields,
	sort,
	onChange,
}: {
	fields: FieldMeta[];
	sort: string;
	onChange: ( sort: string ) => void;
} ) {
	const levels = parseSort( sort );
	const byKey = new Map( fields.map( ( f ) => [ f.key, f ] ) );
	const label = ( key: string ) => byKey.get( key )?.label ?? key;
	const others = fields.filter(
		( f ) => f.sortable && ! levels.some( ( level ) => level.key === f.key )
	);
	const full = levels.length >= SORT_LEVELS;

	return (
		<MenuTrigger>
			<Button
				size="sm"
				icon={ <ArrowUpDown size={ 14 } aria-hidden="true" /> }
			>
				{ levels.length > 1
					? sprintf(
							/* translators: %d: how many fields the list is sorted by. */
							__( 'Sorted by %d', 'cliezoo' ),
							levels.length
						)
					: __( 'Sort', 'cliezoo' ) }
			</Button>
			<Menu
				placement="bottom start"
				disabledKeys={
					full ? others.map( ( f ) => `add:${ f.key }` ) : []
				}
				onAction={ ( key ) => {
					const id = String( key );
					if ( id.startsWith( 'flip:' ) ) {
						const which = id.slice( 5 );
						onChange(
							formatSort(
								levels.map( ( level ) =>
									level.key === which
										? { ...level, desc: ! level.desc }
										: level
								)
							)
						);
					} else if ( id.startsWith( 'drop:' ) ) {
						const which = id.slice( 5 );
						onChange(
							formatSort(
								levels.filter(
									( level ) => level.key !== which
								)
							)
						);
					} else if ( id.startsWith( 'add:' ) ) {
						onChange(
							formatSort( [
								...levels,
								{ key: id.slice( 4 ), desc: false },
							] )
						);
					}
				} }
			>
				{ levels.length > 0 && (
					<MenuSection
						id="levels"
						title={ __( 'Sorted by', 'cliezoo' ) }
					>
						{ levels.map( ( level, index ) => (
							<MenuItem
								key={ level.key }
								id={ `flip:${ level.key }` }
								description={
									level.desc
										? __(
												'Descending. Choose to sort ascending.',
												'cliezoo'
											)
										: __(
												'Ascending. Choose to sort descending.',
												'cliezoo'
											)
								}
							>
								{ sprintf(
									/* translators: 1: level number, 2: field name. */
									__( '%1$d. %2$s', 'cliezoo' ),
									index + 1,
									label( level.key )
								) }
							</MenuItem>
						) ) }
					</MenuSection>
				) }
				{ levels.length > 1 && (
					<MenuSection id="drop" title={ __( 'Remove', 'cliezoo' ) }>
						{ levels.slice( 1 ).map( ( level ) => (
							<MenuItem
								key={ level.key }
								id={ `drop:${ level.key }` }
							>
								{ sprintf(
									/* translators: %s: field name. */
									__( 'Stop sorting by %s', 'cliezoo' ),
									label( level.key )
								) }
							</MenuItem>
						) ) }
					</MenuSection>
				) }
				{ others.length > 0 && (
					<MenuSection
						id="add"
						title={
							levels.length
								? __( 'Then by', 'cliezoo' )
								: __( 'Sort by', 'cliezoo' )
						}
					>
						{ others.map( ( f ) => (
							<MenuItem
								key={ f.key }
								id={ `add:${ f.key }` }
								description={
									full
										? sprintf(
												/* translators: %d: the most sort levels. */
												__(
													'A list is sorted by %d fields at most.',
													'cliezoo'
												),
												SORT_LEVELS
											)
										: undefined
								}
							>
								{ f.label }
							</MenuItem>
						) ) }
					</MenuSection>
				) }
			</Menu>
		</MenuTrigger>
	);
}
