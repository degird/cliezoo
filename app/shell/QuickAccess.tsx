/**
 * The sidebar's quick-access panel (cliezoo.md §6.1), in place of a folder
 * tree: what the person starred, the clients and projects they opened
 * lately, and the projects they are on, each with its colour and icon.
 *
 * Its own chunk: it is never part of the first paint, and three small
 * lists should not cost the start-up bundle anything.
 */
import type { ReactNode } from 'react';
import { Link } from '@tanstack/react-router';
import { __ } from '@wordpress/i18n';
import { Clock, FolderKanban, Star } from 'lucide-react';
import { api, useQuery, useSession } from '@cliezoo/data';
import { recordPath, registry } from '../packages/app/registry';
import { RecordIcon } from '../packages/app/RecordIcon';

/** How many of each are shown; the rest are a click away on their pages. */
const SHOWN = 6;

export interface QuickItem {
	type: string;
	uid: string;
	label: string;
	color?: string | null;
	icon?: string | null;
	/** For a starred saved view: the list it opens. */
	entity?: string | null;
}

interface Favourite {
	type: string;
	uid: string;
	label: string;
	meta: { color?: string; icon?: string; entity?: string };
}

/**
 * Where one opens: its record page, or for a starred view, its list with
 * the view applied. Something no module can open is left out rather than
 * shown as a link to nowhere.
 *
 * @param item The item.
 */
export function quickPath( item: QuickItem ): string {
	if ( 'saved_views' === item.type ) {
		const list = item.entity ? `/${ item.entity }` : '';
		return list && registry.routes.some( ( route ) => route.path === list )
			? `${ list }?view=${ item.uid }`
			: '';
	}
	return recordPath( item.type, item.uid );
}

function Section( {
	id,
	title,
	icon,
	items,
	onNavigate,
	iconFor,
}: {
	id: string;
	title: string;
	icon: ReactNode;
	items: QuickItem[];
	onNavigate?: () => void;
	/** Draw each item's own icon (projects), rather than a dot. */
	iconFor?: boolean;
} ) {
	const links = items
		.map( ( item ) => ( { ...item, to: quickPath( item ) } ) )
		.filter( ( item ) => item.to )
		.slice( 0, SHOWN );
	if ( ! links.length ) {
		return null;
	}
	return (
		<section aria-labelledby={ id } className="mt-4">
			<h2
				id={ id }
				className="mb-1 flex items-center gap-1.5 px-2.5 text-[0.7rem] font-semibold uppercase tracking-wider text-subtle"
			>
				{ icon }
				{ title }
			</h2>
			<ul className="space-y-px">
				{ links.map( ( item ) => (
					<li key={ `${ item.type }:${ item.uid }` }>
						<Link
							to={ item.to }
							onClick={ onNavigate }
							activeProps={ { 'aria-current': 'page' } }
							className="flex items-center gap-2 truncate rounded-md px-2.5 py-1.5 text-sm text-muted hover:bg-sunken hover:text-fg focus-visible:outline-2 focus-visible:outline-focus data-[status=active]:bg-accent-subtle data-[status=active]:text-accent-fg"
						>
							{ iconFor ? (
								<RecordIcon
									name={ item.icon }
									fallback="flag"
									size={ 14 }
									color={ item.color }
									className="shrink-0 text-subtle"
								/>
							) : (
								<span
									aria-hidden="true"
									className="size-2 shrink-0 rounded-full bg-line-strong"
									style={
										item.color
											? { backgroundColor: item.color }
											: undefined
									}
								/>
							) }
							<span className="truncate">{ item.label }</span>
						</Link>
					</li>
				) ) }
			</ul>
		</section>
	);
}

export default function QuickAccess( {
	onNavigate,
}: {
	onNavigate?: () => void;
} ) {
	const boot = useSession( ( s ) => s.boot );
	const staff = !! boot.user && ! boot.user.client;
	const projectsOn = boot.modules.includes( 'projects' );

	const favourites = useQuery( {
		queryKey: [ 'favourites', 'list', {} ],
		queryFn: async () =>
			( await api.get< Favourite[] >( '/favourites' ) ).data,
		staleTime: 60_000,
	} );
	const recents = useQuery( {
		queryKey: [ 'me', 'recents', 'quick' ],
		queryFn: async () =>
			(
				await api.get< QuickItem[] >( '/me/recents', {
					types: 'clients,projects',
					limit: SHOWN,
				} )
			).data,
		staleTime: 60_000,
	} );
	const mine = useQuery( {
		queryKey: [ 'projects', 'mine' ],
		enabled: staff && projectsOn,
		queryFn: async () =>
			(
				await api.get< QuickItem[] >( '/projects/mine', {
					limit: SHOWN,
				} )
			).data,
		staleTime: 60_000,
	} );

	return (
		<>
			<Section
				id="cliezoo-quick-favourites"
				title={ __( 'Favourites', 'cliezoo' ) }
				icon={ <Star size={ 12 } aria-hidden="true" /> }
				onNavigate={ onNavigate }
				items={ ( favourites.data ?? [] ).map( ( fav ) => ( {
					type: fav.type,
					uid: fav.uid,
					label: fav.label,
					color: fav.meta?.color,
					icon: fav.meta?.icon,
					entity: fav.meta?.entity,
				} ) ) }
			/>
			<Section
				id="cliezoo-quick-recent"
				title={ __( 'Recent', 'cliezoo' ) }
				icon={ <Clock size={ 12 } aria-hidden="true" /> }
				onNavigate={ onNavigate }
				items={ recents.data ?? [] }
			/>
			{ staff && projectsOn && (
				<Section
					id="cliezoo-quick-projects"
					title={ __( 'My projects', 'cliezoo' ) }
					icon={ <FolderKanban size={ 12 } aria-hidden="true" /> }
					onNavigate={ onNavigate }
					items={ mine.data ?? [] }
					iconFor
				/>
			) }
		</>
	);
}
