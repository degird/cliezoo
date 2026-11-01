/**
 * Entity metadata (GET /meta/entities/{entity}) and list configuration types.
 */
import { api, useQuery } from '@cliezoo/data';

export interface FieldMeta {
	key: string;
	label: string;
	type: string;
	options?: string[];
	list?: string;
	ref?: string;
	currency?: string;
	filterable?: boolean;
	sortable?: boolean;
	searchable?: boolean;
	readonly?: boolean;
	required?: boolean;
	finance?: boolean;
	system?: boolean;
	help?: string;
	/** A custom field's reach: global, board or task. */
	scope?: string;
	/** The board or task a scoped custom field is for (uid). */
	scope_of?: string;
}

export interface EntityMeta {
	name: string;
	label: string;
	plural_label: string;
	title_field: string;
	default_sort: string;
	searchable: boolean;
	soft_delete: boolean;
	numbered: boolean;
	path: string;
	icon: string;
	fields: FieldMeta[];
	custom: FieldMeta[];
	can: {
		create: boolean;
		update: boolean;
		delete: boolean;
		archive: boolean;
		bulk: boolean;
		/** Whether this person may delete for good, out of the trash. */
		purge?: boolean;
	};
}

export type Operator =
	| 'eq'
	| 'neq'
	| 'in'
	| 'nin'
	| 'contains'
	| 'ncontains'
	| 'starts'
	| 'gt'
	| 'gte'
	| 'lt'
	| 'lte'
	| 'between'
	| 'empty'
	| 'nempty'
	| 'within';

export interface Condition {
	field: string;
	op: Operator;
	value?: unknown;
}

export interface FilterGroup {
	and?: Condition[];
	or?: Condition[];
}

export type ListState = 'active' | 'archived' | 'trash';

/** A saved or current list configuration. */
export interface ListConfig {
	columns: string[];
	sort: string;
	filter: FilterGroup;
	groupBy: string;
	q: string;
	state: ListState;
	/** Table, cards, board or calendar; a table where not said. */
	layout?: string;
}

/**
 * Loads (and caches) entity metadata.
 *
 * @param entity Entity name.
 */
export function useEntityMeta( entity: string ) {
	return useQuery( {
		queryKey: [ 'meta', entity ],
		staleTime: 5 * 60_000,
		queryFn: async () =>
			( await api.get< EntityMeta >( `/meta/entities/${ entity }` ) )
				.data,
	} );
}

/**
 * Every field (built-in then custom).
 *
 * @param meta Metadata.
 */
export function allFields( meta: EntityMeta ): FieldMeta[] {
	return [
		...meta.fields,
		...meta.custom.map( ( field ) => ( {
			...field,
			filterable: true,
			sortable: false,
		} ) ),
	];
}

/**
 * Whether a custom field applies to this board or task. A field kept for
 * one board, or for one task, is only offered there.
 *
 * @param field       Field.
 * @param where       The board and task being looked at.
 * @param where.board Board uid.
 * @param where.task  Task uid.
 */
export function inScope(
	field: FieldMeta,
	where?: { board?: string; task?: string }
): boolean {
	const scope = field.scope ?? 'global';
	if ( 'board' === scope ) {
		return !! where?.board && field.scope_of === where.board;
	}
	if ( 'task' === scope ) {
		return !! where?.task && field.scope_of === where.task;
	}
	return true;
}

/**
 * Operators that make sense for a field type.
 *
 * @param type Field type.
 */
export function operatorsFor( type: string ): Operator[] {
	switch ( type ) {
		case 'bool':
			return [ 'eq' ];
		case 'enum':
		case 'select':
		case 'currency':
		case 'ref':
		case 'user':
			return [ 'eq', 'neq', 'in', 'nin', 'empty', 'nempty' ];
		case 'int':
		case 'bigint':
		case 'decimal':
		case 'money':
		case 'number':
			return [
				'eq',
				'neq',
				'gt',
				'gte',
				'lt',
				'lte',
				'between',
				'empty',
				'nempty',
			];
		case 'date':
		case 'datetime':
			return [ 'within', 'eq', 'gt', 'lt', 'between', 'empty', 'nempty' ];
		default:
			return [
				'contains',
				'ncontains',
				'eq',
				'neq',
				'starts',
				'empty',
				'nempty',
			];
	}
}

/**
 * The value from a row for a (possibly custom) field key.
 *
 * @param row Row.
 * @param key Field key ("name" or "custom.budget").
 */
export function valueOf(
	row: Record< string, unknown >,
	key: string
): unknown {
	if ( key.startsWith( 'custom.' ) ) {
		const custom = row.custom as Record< string, unknown > | undefined;
		return custom?.[ key.slice( 7 ) ];
	}
	return row[ key ];
}
