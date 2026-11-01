/**
 * Palette content: navigation, registered commands and live search
 * (a separate chunk, loaded on first open or when the browser is idle).
 */
import { useDeferredValue, useMemo, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { __, sprintf } from '@wordpress/i18n';
import {
	ArrowRight,
	CornerDownLeft,
	FileText,
	Search as SearchIcon,
} from 'lucide-react';
import {
	Autocomplete,
	Header,
	Input,
	AriaMenu as Menu,
	AriaMenuItem as MenuItem,
	AriaMenuSection as MenuSection,
	AriaSearchField as SearchField,
	Kbd,
	Spinner,
} from '@cliezoo/ui';
import { api, remember, useQuery, useSession } from '@cliezoo/data';
import { applies, recordPath, registry } from '../packages/app/registry';
import { useRegistry } from '../packages/app/slot';
import { availableItems } from './nav';
import type { PaletteMode } from './palette';

interface SearchHit {
	type: string;
	type_label: string;
	uid: string;
	label: string;
	number: string | null;
	archived: boolean;
	path: string | null;
}

interface Chip {
	type: string;
	uid: string;
	label: string;
	number: string | null;
}

interface Row {
	id: string;
	label: string;
	hint?: string;
	section: string;
	run: () => void;
}

const matches = ( text: string, query: string ) =>
	text.toLowerCase().includes( query.toLowerCase().trim() );

export default function PaletteBody( {
	mode,
	close,
}: {
	mode: PaletteMode;
	close: () => void;
} ) {
	useRegistry();
	const boot = useSession( ( s ) => s.boot );
	const navigate = useNavigate();
	const [ input, setInput ] = useState( '' );
	const query = useDeferredValue( input.trim() );
	const go = ( to: string ) => {
		close();
		navigate( { to } );
	};

	const recentTerms = boot.prefs?.recent_searches ?? [];
	const recentRefs = boot.prefs?.recent_records ?? [];
	const idle = mode === 'search' && ! query;

	const recents = useQuery( {
		queryKey: [ 'records', 'refs', recentRefs ],
		enabled: idle && recentRefs.length > 0,
		staleTime: 60_000,
		queryFn: async () =>
			( await api.get< Chip[] >( '/records/refs', { refs: recentRefs } ) )
				.data,
	} );
	const favourites = useQuery( {
		queryKey: [ 'favourites', 'list', {} ],
		enabled: idle,
		staleTime: 60_000,
		queryFn: async () => ( await api.get< Chip[] >( '/favourites' ) ).data,
	} );

	const search = useQuery( {
		queryKey: [ 'search', 'palette', query ],
		enabled: mode === 'search' && query.length >= 2,
		queryFn: async ( { signal } ) =>
			(
				await api.get< SearchHit[] >(
					'/search',
					{ q: query, limit: 8 },
					signal
				)
			).data,
		staleTime: 10_000,
	} );

	const openRecord = ( chip: { type: string; uid: string }, to: string ) => {
		void remember( 'recent_records', `${ chip.type }:${ chip.uid }` );
		go( to );
	};

	const rows = useMemo( () => {
		const out: Row[] = [];
		if ( idle ) {
			for ( const term of recentTerms ) {
				out.push( {
					id: `term:${ term }`,
					label: term,
					section: __( 'Recent searches', 'cliezoo' ),
					run: () => setInput( term ),
				} );
			}
			for ( const [ section, chips ] of [
				[ __( 'Recently opened', 'cliezoo' ), recents.data ?? [] ],
				[ __( 'Favourites', 'cliezoo' ), favourites.data ?? [] ],
			] as Array< [ string, Chip[] ] > ) {
				for ( const chip of chips ) {
					const to = recordPath( chip.type, chip.uid );
					if ( to ) {
						out.push( {
							id: `${ section }:${ chip.type }:${ chip.uid }`,
							label: chip.number
								? `${ chip.number } · ${ chip.label }`
								: chip.label,
							section,
							run: () => openRecord( chip, to ),
						} );
					}
				}
			}
		}
		const commands = registry.commands.filter(
			( c ) =>
				applies( boot, c ) &&
				( mode === 'search' || c.group === 'create' )
		);
		for ( const command of commands ) {
			if ( command.ask ) {
				// Offered with the words themselves, once there are some.
				if ( mode === 'search' && query.length >= 3 ) {
					out.push( {
						id: `ask:${ command.id }`,
						label: sprintf(
							/* translators: 1: a command such as "Ask AI", 2: what was typed. */
							__( '%1$s: “%2$s”', 'cliezoo' ),
							command.label,
							query
						),
						section: __( 'Commands', 'cliezoo' ),
						run: () => {
							close();
							command.run( {
								navigate: ( to ) => navigate( { to } ),
								query,
							} );
						},
					} );
				}
				continue;
			}
			if (
				! query ||
				matches( command.label, query ) ||
				command.keywords?.some( ( k ) => matches( k, query ) )
			) {
				out.push( {
					id: `cmd:${ command.id }`,
					label: command.label,
					hint: command.shortcut,
					section:
						command.group === 'create'
							? __( 'Create', 'cliezoo' )
							: __( 'Commands', 'cliezoo' ),
					run: () => {
						close();
						command.run( {
							navigate: ( to ) => navigate( { to } ),
						} );
					},
				} );
			}
		}
		if ( mode === 'search' ) {
			for ( const item of availableItems( boot ) ) {
				if ( ! query || matches( item.label, query ) ) {
					out.push( {
						id: `nav:${ item.id }`,
						label: item.label,
						section: __( 'Go to', 'cliezoo' ),
						run: () => go( item.to ),
					} );
				}
			}
			for ( const hit of search.data ?? [] ) {
				const to = hit.path || recordPath( hit.type, hit.uid );
				if ( to ) {
					out.push( {
						id: `rec:${ hit.type }:${ hit.uid }`,
						label: hit.number
							? `${ hit.number } · ${ hit.label }`
							: hit.label,
						hint: hit.type_label,
						section: __( 'Records', 'cliezoo' ),
						run: () => {
							void remember( 'recent_searches', query );
							openRecord( hit, to );
						},
					} );
				}
			}
		}
		return out;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		boot,
		mode,
		query,
		idle,
		search.data,
		recents.data,
		favourites.data,
		recentTerms,
	] );

	const sections = rows.reduce< Record< string, Row[] > >( ( acc, row ) => {
		( acc[ row.section ] ??= [] ).push( row );
		return acc;
	}, {} );

	return (
		<Autocomplete inputValue={ input } onInputChange={ setInput }>
			<SearchField
				aria-label={
					mode === 'new'
						? __( 'Create new', 'cliezoo' )
						: __( 'Search or run a command', 'cliezoo' )
				}
				// eslint-disable-next-line jsx-a11y/no-autofocus -- The palette was opened on purpose; typing starts at once.
				autoFocus
				className="relative flex items-center border-b border-line"
			>
				<SearchIcon
					size={ 18 }
					className="pointer-events-none absolute start-4 text-subtle"
					aria-hidden="true"
				/>
				<Input
					placeholder={
						mode === 'new'
							? __( 'What do you want to create?', 'cliezoo' )
							: __(
									'Search clients, projects, invoices… or type a command',
									'cliezoo'
								)
					}
					className="h-14 w-full bg-transparent ps-11 pe-12 text-base text-fg outline-none placeholder:text-subtle [&::-webkit-search-cancel-button]:hidden"
				/>
				{ search.isFetching && (
					<span className="absolute end-4 text-muted">
						<Spinner
							size="sm"
							label={ __( 'Searching', 'cliezoo' ) }
						/>
					</span>
				) }
			</SearchField>
			<Menu
				className="max-h-[min(60vh,28rem)] overflow-auto p-2 outline-none"
				renderEmptyState={ () => (
					<p className="px-3 py-8 text-center text-sm text-muted">
						{ query.length >= 2 && ! search.isFetching
							? __(
									'No results. Try other words or an operator like type:invoice.',
									'cliezoo'
								)
							: __(
									'Start typing to search, or press ? for the keyboard shortcuts.',
									'cliezoo'
								) }
					</p>
				) }
				onAction={ ( key ) =>
					rows.find( ( row ) => row.id === key )?.run()
				}
			>
				{ Object.entries( sections ).map( ( [ section, items ] ) => (
					<MenuSection
						key={ section }
						id={ section }
						className="[&:not(:first-child)]:mt-2"
					>
						<Header className="px-3 pb-1 pt-1 text-[0.7rem] font-semibold uppercase tracking-wider text-subtle">
							{ section }
						</Header>
						{ items.map( ( row ) => (
							<MenuItem
								key={ row.id }
								id={ row.id }
								textValue={ row.label }
								className="group flex cursor-default items-center gap-3 rounded-md px-3 py-2 text-sm text-fg outline-none data-[focused]:bg-accent-subtle data-[focused]:text-accent-fg"
							>
								{ row.id.startsWith( 'rec:' ) ? (
									<FileText
										size={ 16 }
										className="shrink-0 text-muted"
										aria-hidden="true"
									/>
								) : (
									<ArrowRight
										size={ 16 }
										className="shrink-0 text-muted rtl:rotate-180"
										aria-hidden="true"
									/>
								) }
								<span className="min-w-0 flex-1 truncate">
									{ row.label }
								</span>
								{ row.hint && (
									<span className="shrink-0 text-xs text-subtle">
										{ row.hint }
									</span>
								) }
								<CornerDownLeft
									size={ 14 }
									className="invisible shrink-0 text-muted group-data-[focused]:visible"
									aria-hidden="true"
								/>
							</MenuItem>
						) ) }
					</MenuSection>
				) ) }
			</Menu>
			<footer className="flex items-center gap-3 border-t border-line px-4 py-2 text-xs text-subtle">
				<span className="flex items-center gap-1">
					<Kbd>↑</Kbd>
					<Kbd>↓</Kbd> { __( 'to move', 'cliezoo' ) }
				</span>
				<span className="flex items-center gap-1">
					<Kbd>↵</Kbd> { __( 'to open', 'cliezoo' ) }
				</span>
				<span className="flex items-center gap-1">
					<Kbd>Esc</Kbd> { __( 'to close', 'cliezoo' ) }
				</span>
				<span className="ms-auto">
				</span>
			</footer>
		</Autocomplete>
	);
}
