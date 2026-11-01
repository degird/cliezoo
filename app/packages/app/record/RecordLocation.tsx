/**
 * Where a record lives, and what it holds, in a line (cliezoo.md §6.2):
 *
 * - the **location breadcrumb** — the client / project path — shown with
 *   the name of a board, milestone, conversation or anything else that
 *   belongs to one, each part a link;
 * - **live subtitles** — "65 messages", "26/38 tasks", "4 items" — from
 *   counts the page already has, never a request of their own.
 */
import { Fragment, type ReactNode } from 'react';
import { Link } from '@tanstack/react-router';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatNumber, useRecord } from '@cliezoo/data';
import { recordPath } from '../registry';

interface Ref {
	uid: string;
	label: string;
}

export interface Crumb {
	type: 'clients' | 'projects';
	uid: string;
	label: string;
}

const isRef = ( value: unknown ): value is Ref =>
	!! value &&
	'object' === typeof value &&
	'string' === typeof ( value as Ref ).uid &&
	'string' === typeof ( value as Ref ).label;

/**
 * The client / project path of a record, from the references it already
 * carries (`client`, `project`). A project's own client is its path.
 *
 * @param record The record as the server sent it.
 * @param type   Its type, so a client or project is not its own parent.
 */
export function locationOf(
	record: Record< string, unknown > | null | undefined,
	type?: string
): Crumb[] {
	if ( ! record ) {
		return [];
	}
	const out: Crumb[] = [];
	if ( 'clients' !== type && isRef( record.client ) ) {
		out.push( { type: 'clients', ...pick( record.client ) } );
	}
	if (
		'projects' !== type &&
		'clients' !== type &&
		isRef( record.project )
	) {
		out.push( { type: 'projects', ...pick( record.project ) } );
	}
	return out;
}

function pick( ref: Ref ): Ref {
	return { uid: ref.uid, label: ref.label };
}

/**
 * The breadcrumb. Nothing at all when the record belongs to nobody.
 *
 * @param root0        Props.
 * @param root0.record The record.
 * @param root0.type   Its type.
 */
export function RecordLocation( {
	record,
	type,
}: {
	record: Record< string, unknown > | null | undefined;
	type?: string;
} ) {
	const own = locationOf( record, type );
	// A record in a project that does not name the project's client — a
	// board made inside the project, say — still lives at that client. The
	// path said only the project, which is the "wrong breadcrumb" of §8.2.
	// Read from the project itself (cached, and only if this person may open
	// it) rather than written onto the record: who a record belongs to
	// decides who can see it, and a breadcrumb is not the place to change that.
	const project = own.find( ( crumb ) => 'projects' === crumb.type );
	const needsClient =
		!! project && ! own.some( ( crumb ) => 'clients' === crumb.type );
	const parent = useRecord< Record< string, unknown > >(
		'/projects',
		needsClient ? project?.uid : undefined
	);
	const crumbs: Crumb[] =
		needsClient && isRef( parent.data?.client )
			? [ { type: 'clients', ...pick( parent.data.client ) }, ...own ]
			: own;
	if ( ! crumbs.length ) {
		return null;
	}
	return (
		<ol className="flex min-w-0 flex-wrap items-center gap-1">
			{ crumbs.map( ( crumb, index ) => {
				const to = recordPath( crumb.type, crumb.uid );
				return (
					<Fragment key={ `${ crumb.type }:${ crumb.uid }` }>
						{ index > 0 && (
							<li aria-hidden="true" className="text-subtle">
								/
							</li>
						) }
						<li className="min-w-0 truncate">
							<span className="sr-only">
								{ 'clients' === crumb.type
									? __( 'Client:', 'cliezoo' )
									: __( 'Project:', 'cliezoo' ) }{ ' ' }
							</span>
							{ to ? (
								<Link
									to={ to }
									className="hover:text-fg hover:underline focus-visible:outline-2 focus-visible:outline-focus"
								>
									{ crumb.label }
								</Link>
							) : (
								crumb.label
							) }
						</li>
					</Fragment>
				);
			} ) }
		</ol>
	);
}

/**
 * The breadcrumb for a page header, or nothing — so the header does not
 * draw an empty breadcrumb landmark for a record that belongs to nobody.
 *
 * @param record The record.
 * @param type   Its type.
 */
export function recordLocation(
	record: unknown,
	type?: string
): ReactNode | undefined {
	const row = record as Record< string, unknown > | null | undefined;
	return locationOf( row, type ).length ? (
		<RecordLocation record={ row } type={ type } />
	) : undefined;
}

/**
 * "65 messages".
 *
 * @param count How many.
 */
export function messagesSubtitle( count: number ): string {
	return sprintf(
		/* translators: %s: number of messages. */
		_n( '%s message', '%s messages', count, 'cliezoo' ),
		formatNumber( count )
	);
}

/**
 * "26/38 tasks": done of all.
 *
 * @param done  How many are done.
 * @param total How many there are.
 */
export function tasksSubtitle( done: number, total: number ): string {
	return sprintf(
		/* translators: 1: tasks done, 2: all tasks. */
		_n( '%1$s/%2$s task', '%1$s/%2$s tasks', total, 'cliezoo' ),
		formatNumber( done ),
		formatNumber( total )
	);
}

/**
 * "4 items".
 *
 * @param count How many.
 */
export function itemsSubtitle( count: number ): string {
	return sprintf(
		/* translators: %s: number of items. */
		_n( '%s item', '%s items', count, 'cliezoo' ),
		formatNumber( count )
	);
}

/**
 * A header's meta line from its parts — a subtitle, a description — with
 * the empty ones left out, and nothing at all when every one is empty, so
 * the header draws no blank line.
 *
 * @param parts The parts, in order.
 */
export function joinMeta( ...parts: ReactNode[] ): ReactNode {
	const kept = parts.filter(
		( part ) =>
			null !== part && undefined !== part && '' !== part && false !== part
	);
	if ( ! kept.length ) {
		return null;
	}
	return (
		<span className="flex flex-wrap items-center gap-x-1.5">
			{ kept.map( ( part, index ) => (
				<Fragment key={ index }>
					{ index > 0 && <span aria-hidden="true">·</span> }
					<span>{ part }</span>
				</Fragment>
			) ) }
		</span>
	);
}

/**
 * A board's "26/38 tasks", from the counts its lists already carry.
 *
 * Finished is the completion written on the card, which is what the rest
 * of the product means by it — milestones, the dashboard's numbers, a
 * client's open work, a shared board. Counting a whole column instead,
 * because the column is marked as the done one, disagreed with all of
 * them: a card finished from its overlay and left in "Doing" was done on
 * its milestone and not done on its board, and a board with no done
 * column could never reach the end at all.
 *
 * @param lists The board's lists.
 */
export function boardTasks(
	lists: Array< { tasks?: number; done?: number } > | undefined
): { done: number; total: number } | null {
	if ( ! lists?.length ) {
		return null;
	}
	let done = 0;
	let total = 0;
	for ( const list of lists ) {
		total += Number( list.tasks ?? 0 );
		done += Number( list.done ?? 0 );
	}
	return { done, total };
}
