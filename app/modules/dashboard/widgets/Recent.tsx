/**
 * What I opened lately: a strip of clients and projects to jump back
 * into, and a table of every kind of record.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link } from '@tanstack/react-router';
import { api, formatDate, useQuery } from '@cliezoo/data';
import {
	Briefcase,
	Building2,
	CheckSquare,
	File,
	FileText,
	Flag,
	FolderKanban,
	Inbox,
	Kanban,
	MessagesSquare,
	UserRound,
	type LucideIcon,
} from 'lucide-react';
import { ErrorState, Skeleton, cx } from '@cliezoo/ui';
import { recordPath } from '../../../packages/app/registry';
import { linkOf } from '../api';

export interface Recent {
	type: string;
	kind: string;
	icon: string;
	uid: string;
	label: string;
	number: string | null;
	color: string | null;
	visibility: string;
	created_at: string | null;
	opened_at: string;
	count: number | null;
}

export function useRecents( types: string[], limit: number ) {
	return useQuery( {
		queryKey: [ 'dashboard', 'recents', types, limit ],
		queryFn: async () =>
			(
				await api.get< Recent[] >( '/me/recents', {
					...( types.length ? { types: types.join( ',' ) } : {} ),
					limit,
				} )
			).data,
	} );
}

const ICONS: Record< string, LucideIcon > = {
	clients: Building2,
	contacts: UserRound,
	projects: FolderKanban,
	boards: Kanban,
	tasks: CheckSquare,
	milestones: Flag,
	docs: FileText,
	files: File,
	folders: Briefcase,
	conversations: MessagesSquare,
	requests: Inbox,
};

function TypeIcon( {
	type,
	size,
	color,
}: {
	type: string;
	size: number;
	/** The record's own colour, where it has chosen one. */
	color?: string | null;
} ) {
	const Icon = ICONS[ type ] ?? File;
	return (
		<Icon
			size={ size }
			aria-hidden="true"
			className={ cx( 'shrink-0', ! color && 'text-muted' ) }
			style={ color ? { color } : undefined }
		/>
	);
}

function visibilityLabel( value: string ): string {
	const labels: Record< string, string > = {
		inherit: __( 'Follows the project', 'cliezoo' ),
		private: __( 'Only the people listed', 'cliezoo' ),
		internal: __( 'The team', 'cliezoo' ),
		clients: __( 'The team and the client', 'cliezoo' ),
		public: __( 'Anyone with the link', 'cliezoo' ),
	};
	return labels[ value ] ?? value;
}

function countLabel( recent: Recent ): string {
	if ( recent.count === null ) {
		return '';
	}
	return recent.type === 'clients'
		? sprintf(
				/* translators: %d: active projects. */
				_n(
					'%d active project',
					'%d active projects',
					recent.count,
					'cliezoo'
				),
				recent.count
			)
		: sprintf(
				/* translators: %d: open tasks. */
				_n( '%d open task', '%d open tasks', recent.count, 'cliezoo' ),
				recent.count
			);
}

/**
 * What I opened last, to jump back into.
 *
 * Home asks for clients and projects; the Files screen asks for
 * folders, which is the strip a person who lives in Files wants.
 *
 * @param root0       Props.
 * @param root0.types Record types to show; clients and projects by default.
 * @param root0.label What the strip is called.
 */
export function RecentStrip( {
	types,
	label,
}: {
	types?: string[];
	label?: string;
} = {} ) {
	const recents = useRecents( types ?? [ 'clients', 'projects' ], 8 );
	// Nothing opened lately is no strip at all; a strip that could not be
	// worked out is a different thing, and says so.
	if ( recents.isError ) {
		return (
			<ErrorState
				error={ recents.error }
				title={ __(
					'What you opened lately could not be loaded.',
					'cliezoo'
				) }
				onRetry={ () => recents.refetch() }
			/>
		);
	}
	if ( ! recents.data?.length ) {
		return null;
	}
	return (
		<nav aria-label={ label ?? __( 'Recently opened', 'cliezoo' ) }>
			<ul className="flex gap-2 overflow-x-auto pb-1">
				{ recents.data.map( ( recent ) => (
					<li key={ recent.uid } className="shrink-0">
						<Link
							{ ...linkOf(
								recordPath( recent.type, recent.uid )
							) }
							className="flex w-52 items-center gap-2 rounded-lg border border-line bg-raised p-3 outline-none hover:border-accent focus-visible:ring-2 focus-visible:ring-accent"
						>
							<TypeIcon
								type={ recent.type }
								size={ 18 }
								color={ recent.color }
							/>
							<span className="flex min-w-0 flex-col">
								<span className="truncate text-sm font-medium text-fg">
									{ recent.label || recent.kind }
								</span>
								<span className="truncate text-xs text-muted">
									{ countLabel( recent ) || recent.kind }
								</span>
							</span>
						</Link>
					</li>
				) ) }
			</ul>
		</nav>
	);
}

export default function RecentItems() {
	const recents = useRecents( [], 10 );
	if ( recents.isPending ) {
		return <Skeleton className="h-32" />;
	}
	if ( recents.isError ) {
		return (
			<ErrorState
				error={ recents.error }
				onRetry={ () => recents.refetch() }
			/>
		);
	}
	if ( ! recents.data.length ) {
		return (
			<p className="py-4 text-center text-sm text-muted">
				{ __( 'What you open shows up here.', 'cliezoo' ) }
			</p>
		);
	}
	return (
		<div className="overflow-x-auto">
			<table className="w-full text-sm">
				<thead>
					<tr className="text-xs text-muted">
						<th scope="col" className="py-1 text-start font-normal">
							{ __( 'Name', 'cliezoo' ) }
						</th>
						<th scope="col" className="py-1 text-start font-normal">
							{ __( 'Kind', 'cliezoo' ) }
						</th>
						<th
							scope="col"
							className="hidden py-1 text-start font-normal sm:table-cell"
						>
							{ __( 'Visibility', 'cliezoo' ) }
						</th>
						<th
							scope="col"
							className="hidden py-1 text-end font-normal sm:table-cell"
						>
							{ __( 'Created', 'cliezoo' ) }
						</th>
					</tr>
				</thead>
				<tbody className="divide-y divide-line">
					{ recents.data.map( ( recent ) => {
						const path = recordPath( recent.type, recent.uid );
						// Something with no name of its own — a timer nobody titled —
						// is called what it is, rather than drawn as an empty link.
						const label = recent.label || recent.kind;
						return (
							<tr key={ `${ recent.type }:${ recent.uid }` }>
								<td className="max-w-0 py-1.5 pe-2">
									<span className="flex items-center gap-2">
										<TypeIcon
											type={ recent.type }
											size={ 14 }
										/>
										{ path ? (
											<Link
												{ ...linkOf( path ) }
												className="truncate text-fg hover:underline"
											>
												{ label }
											</Link>
										) : (
											<span className="truncate text-fg">
												{ label }
											</span>
										) }
									</span>
								</td>
								<td className="py-1.5 pe-2 text-muted">
									{ recent.kind }
								</td>
								<td className="hidden py-1.5 pe-2 text-muted sm:table-cell">
									{ recent.visibility
										? visibilityLabel( recent.visibility )
										: '—' }
								</td>
								<td className="hidden py-1.5 text-end tabular-nums text-muted sm:table-cell">
									{ recent.created_at
										? formatDate( recent.created_at )
										: '—' }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}
