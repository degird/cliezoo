/**
 * One list, filtered to the record whose page it is on.
 *
 * A record's tab of other records — a client's deals, a project's hours — is
 * the ordinary list with one field filled in. Nothing is copied onto the
 * record and nothing is counted twice, so the tab and the list it comes from
 * cannot drift apart.
 */
import { Suspense, lazy } from 'react';
import { Skeleton } from '@cliezoo/ui';
import type { EntityListProps } from './EntityList';

const EntityList = lazy(
	() => import( /* webpackChunkName: "list" */ './EntityList' )
);

export type OfThisRow = Parameters<
	NonNullable< EntityListProps[ 'rowHref' ] >
>[ 0 ];

/**
 * @param root0         Props.
 * @param root0.field   The field that points at the record ("client").
 * @param root0.value   The record's uid.
 * @param root0.entity  Which records.
 * @param root0.label   What they are called.
 * @param root0.columns The columns to show.
 * @param root0.href    Where a row goes.
 * @param root0.empty   What to say when there are none.
 * @param root0.actions Anything offered above the list.
 */
export function OfThisRecord( {
	field,
	value,
	entity,
	label,
	columns,
	href,
	empty,
	actions,
}: {
	field: string;
	value: string;
	entity: string;
	label: string;
	columns: string[];
	href: ( row: OfThisRow ) => string;
	empty: React.ReactNode;
	actions?: React.ReactNode;
} ) {
	return (
		<Suspense fallback={ <Skeleton className="h-64" /> }>
			<EntityList
				entity={ entity }
				label={ label }
				columns={ columns }
				baseFilter={ {
					and: [ { field, op: 'eq', value } ],
				} }
				rowHref={ href }
				empty={ empty }
				actions={ actions }
			/>
		</Suspense>
	);
}

export default OfThisRecord;
