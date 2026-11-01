/**
 * Navigation model: registered items filtered for the user and ordered by
 * their personal sidebar layout.
 */
import { __ } from '@wordpress/i18n';
import type { Boot, Preferences } from '@cliezoo/data';
import {
	applies,
	registry,
	type NavGroup,
	type NavItem,
} from '../packages/app/registry';

export const GROUP_ORDER: NavGroup[] = [
	'personal',
	'overview',
	'sales',
	'work',
	'documents',
	'finance',
	'insights',
	'settings',
];

export function groupLabel( group: NavGroup ): string {
	const labels: Record< NavGroup, string > = {
		personal: '',
		overview: __( 'Overview', 'cliezoo' ),
		sales: __( 'Sales', 'cliezoo' ),
		work: __( 'Work', 'cliezoo' ),
		documents: __( 'Documents', 'cliezoo' ),
		finance: __( 'Finance', 'cliezoo' ),
		insights: __( 'Insights', 'cliezoo' ),
		settings: __( 'Settings', 'cliezoo' ),
	};
	return labels[ group ];
}

/**
 * Items a person may move but never hide (cliezoo.md §6.1): Home is the way
 * back, and Inbox is where they hear about their work. The server strips
 * them from saved hidden lists too; this covers layouts saved before.
 */
export const ALWAYS_SHOWN = [ 'home', 'inbox' ];

/**
 * Whether a person has hidden an item from their sidebar.
 *
 * @param prefs Preferences.
 * @param id    Item id.
 */
export function isHidden( prefs: Preferences, id: string ): boolean {
	return ! ALWAYS_SHOWN.includes( id ) && prefs.nav_hidden.includes( id );
}

/**
 * Moves one item among the others of its group.
 *
 * The drag in "Customize sidebar" and its keyboard buttons both end here,
 * so they cannot disagree about what moving an item means.
 *
 * @param order  Every item id, in the current order.
 * @param groups Item id → its group.
 * @param id     The item that moves.
 * @param index  Where it lands, counted within its own group.
 */
export function moveWithinGroup(
	order: string[],
	groups: Map< string, NavGroup | undefined >,
	id: string,
	index: number
): string[] {
	const group = groups.get( id );
	const inGroup = order.filter( ( other ) => groups.get( other ) === group );
	const from = inGroup.indexOf( id );
	if ( from < 0 ) {
		return order;
	}
	const target = Math.max( 0, Math.min( inGroup.length - 1, index ) );
	if ( target === from ) {
		return order;
	}
	const moved = [ ...inGroup ];
	moved.splice( from, 1 );
	moved.splice( target, 0, id );
	// The group's slots in the whole list stay where they were; only who
	// sits in them changes.
	let next = 0;
	return order.map( ( other ) =>
		groups.get( other ) === group ? moved[ next++ ] : other
	);
}

/**
 * Items this user may see (before personal hiding).
 *
 * @param boot Boot data.
 */
export function availableItems( boot: Boot ): NavItem[] {
	return registry.nav
		.filter( ( item ) => applies( boot, item ) )
		.sort( ( a, b ) => ( a.order ?? 50 ) - ( b.order ?? 50 ) );
}

/**
 * Applies the personal order (ids listed first keep that order within their group).
 *
 * @param items Items.
 * @param order Saved order.
 */
export function ordered( items: NavItem[], order: string[] ): NavItem[] {
	if ( ! order.length ) {
		return items;
	}
	const rank = new Map( order.map( ( id, index ) => [ id, index ] ) );
	return [ ...items ].sort(
		( a, b ) =>
			( rank.get( a.id ) ?? 1000 + ( a.order ?? 50 ) ) -
			( rank.get( b.id ) ?? 1000 + ( b.order ?? 50 ) )
	);
}

/**
 * Grouped, visible items for the sidebar.
 *
 * @param boot  Boot data.
 * @param prefs Preferences.
 */
export function sidebarGroups(
	boot: Boot,
	prefs: Preferences
): Array< { group: NavGroup; items: NavItem[] } > {
	const visible = ordered( availableItems( boot ), prefs.nav_order ).filter(
		( item ) => ! isHidden( prefs, item.id )
	);
	return GROUP_ORDER.map( ( group ) => ( {
		group,
		items: visible.filter( ( item ) => item.group === group ),
	} ) );
}
