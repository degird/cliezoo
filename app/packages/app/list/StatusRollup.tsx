/**
 * A list's status roll-up (cliezoo.md §6.2): one line above the rows that
 * says how many are in each status — "4 active · 2 on hold · 1 planned" —
 * for exactly what the list is showing. Each count is also a button that
 * narrows the list to it.
 */
import { __, sprintf } from '@wordpress/i18n';
import { formatNumber, useQuery, api } from '@cliezoo/data';
import { cx } from '@cliezoo/ui';
import { humanise } from './cells';
import type { FieldMeta } from './meta';

export interface RollupValue {
	value: string;
	label: string | null;
	color: string | null;
	count: number;
}

interface Rollup {
	by: string;
	total: number;
	values: RollupValue[];
}

/**
 * The field a list is rolled up by: its status, or its stage.
 *
 * @param fields The list's fields.
 */
export function rollupField( fields: FieldMeta[] ): FieldMeta | undefined {
	return fields.find(
		( f ) =>
			[ 'status', 'stage' ].includes( f.key ) &&
			f.filterable &&
			( 'enum' === f.type || !! f.list )
	);
}

/**
 * The words for one count: "4 active".
 *
 * @param one A count.
 */
export function rollupText( one: RollupValue ): string {
	return sprintf(
		/* translators: 1: how many, 2: a status ("active"). */
		__( '%1$s %2$s', 'cliezoo' ),
		formatNumber( one.count ),
		( one.label ?? humanise( one.value ) ).toLowerCase()
	);
}

export function StatusRollup( {
	entity,
	field,
	query,
	onPick,
}: {
	entity: string;
	field: FieldMeta;
	/** The list's own filter, search and state. */
	query: { filter?: string; q?: string; state?: string };
	/** Narrows the list to one value. */
	onPick: ( value: string ) => void;
} ) {
	const rollup = useQuery( {
		// Under the entity's key, so whatever refreshes the list refreshes
		// this too.
		queryKey: [ entity, 'rollup', field.key, query ],
		staleTime: 15_000,
		queryFn: async () =>
			(
				await api.get< Rollup >( '/rollup', {
					entity,
					by: field.key,
					...query,
				} )
			).data,
	} );
	const values = rollup.data?.values ?? [];
	if ( values.length < 2 ) {
		// One status is no summary; the record count already says it.
		return null;
	}
	return (
		<div
			className="flex flex-wrap items-center gap-x-1 gap-y-1 border-b border-line px-4 py-1.5 text-sm text-muted sm:px-6"
			role="group"
			aria-label={ sprintf(
				/* translators: %s: field name ("Status"). */
				__( 'Records by %s', 'cliezoo' ),
				field.label.toLowerCase()
			) }
		>
			{ values.map( ( one, index ) => (
				<span key={ one.value } className="inline-flex items-center">
					{ index > 0 && (
						<span aria-hidden="true" className="mx-1 text-subtle">
							·
						</span>
					) }
					<button
						type="button"
						onClick={ () => onPick( one.value ) }
						className={ cx(
							'inline-flex items-center gap-1.5 rounded px-1 hover:bg-sunken hover:text-fg',
							'focus-visible:outline-2 focus-visible:outline-focus'
						) }
					>
						{ one.color && (
							<span
								aria-hidden="true"
								className="size-2 rounded-full"
								style={ { backgroundColor: one.color } }
							/>
						) }
						{ rollupText( one ) }
					</button>
				</span>
			) ) }
		</div>
	);
}
