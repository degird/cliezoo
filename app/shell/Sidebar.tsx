/**
 * Grouped, collapsible sidebar with the quick-access panel.
 */
import { Suspense, lazy, useState } from 'react';
import { Link } from '@tanstack/react-router';
import { __ } from '@wordpress/i18n';
import { ChevronsLeft, ChevronsRight, SlidersHorizontal } from 'lucide-react';
import { useSavePreferences, usePreferences, useSession } from '@cliezoo/data';
import { IconButton, cx } from '@cliezoo/ui';
import { useRegistry } from '../packages/app/slot';
import { groupLabel, sidebarGroups } from './nav';
import { Logo } from './Logo';
import { CompanySwitcher } from './CompanySwitcher';

const CustomizeSidebar = lazy(
	() => import( /* webpackChunkName: "customize" */ './CustomizeSidebar' )
);

// Favourites, recents and my projects: loaded after the first paint.
const QuickAccess = lazy(
	() => import( /* webpackChunkName: "quick-access" */ './QuickAccess' )
);

/**
 * The sidebar. `mobile` renders it inside the navigation drawer.
 * @param root0
 * @param root0.mobile
 * @param root0.onNavigate
 */
export function Sidebar( {
	mobile = false,
	onNavigate,
}: {
	mobile?: boolean;
	onNavigate?: () => void;
} ) {
	useRegistry();
	const boot = useSession( ( s ) => s.boot );
	const prefs = usePreferences();
	const save = useSavePreferences();
	const [ customizing, setCustomizing ] = useState( false );
	const collapsed = ! mobile && prefs.sidebar_collapsed;
	const groups = sidebarGroups( boot, prefs );

	return (
		<div
			className={ cx(
				'flex h-full flex-col bg-surface',
				! mobile && 'border-e border-line'
			) }
		>
			<div
				className={ cx(
					'flex h-14 shrink-0 items-center gap-2',
					collapsed ? 'justify-center px-2' : 'px-3'
				) }
			>
				<Link
					to="/"
					onClick={ onNavigate }
					className="flex min-w-0 items-center gap-2 rounded-md focus-visible:outline-2 focus-visible:outline-focus"
				>
					<Logo />
					{ /* The mark is decoration — it is aria-hidden, and a
					     workspace that has not uploaded a logo has nothing
					     else in here. Folded, that left the link to the
					     overview with no name at all, so the name is said
					     quietly rather than dropped. */ }
					<span
						className={
							collapsed
								? 'sr-only'
								: 'truncate text-sm font-semibold text-fg'
						}
					>
						{ boot.workspace.name || boot.branding.logoAlt }
					</span>
				</Link>
			</div>
			{ ! collapsed && <CompanySwitcher /> }
			<nav
				data-tour="nav"
				aria-label={ __( 'Main', 'cliezoo' ) }
				id="cliezoo-nav"
				tabIndex={ -1 }
				className={ cx(
					'min-h-0 flex-1 overflow-y-auto pb-3 outline-none',
					collapsed ? 'px-2' : 'px-2.5'
				) }
			>
				{ groups.map( ( { group, items } ) => {
					if ( ! items.length && group !== 'insights' ) {
						return null;
					}
					const label = groupLabel( group );
					return (
						<div
							key={ group }
							className={ cx( group !== 'personal' && 'mt-4' ) }
						>
							{ label && ! collapsed && items.length > 0 && (
								<h2 className="mb-1 px-2.5 text-[0.7rem] font-semibold uppercase tracking-wider text-subtle">
									{ label }
								</h2>
							) }
							<ul className="space-y-px">
								{ items.map( ( item ) => {
									const Icon = item.icon;
									return (
										<li key={ item.id }>
											<Link
												to={ item.to }
												onClick={ onNavigate }
												activeOptions={ {
													exact: item.to === '/',
												} }
												// Which one you are on is
												// said, not only coloured.
												activeProps={ {
													'aria-current': 'page',
												} }
												title={
													collapsed
														? item.label
														: undefined
												}
												className={ cx(
													'flex items-center gap-2.5 rounded-md py-1.5 text-sm font-medium text-muted hover:bg-sunken hover:text-fg',
													'focus-visible:outline-2 focus-visible:outline-focus',
													'data-[status=active]:bg-accent-subtle data-[status=active]:text-accent-fg',
													collapsed
														? 'justify-center px-0'
														: 'px-2.5'
												) }
											>
												{ Icon && (
													<Icon
														size={ 18 }
														aria-hidden={ true }
														className="shrink-0"
													/>
												) }
												<span
													className={ cx(
														'truncate',
														collapsed && 'sr-only'
													) }
												>
													{ item.label }
												</span>
											</Link>
										</li>
									);
								} ) }
							</ul>
						</div>
					);
				} ) }
				{ ! collapsed && (
					<Suspense fallback={ null }>
						<QuickAccess onNavigate={ onNavigate } />
					</Suspense>
				) }
			</nav>
			<div
				className={ cx(
					'flex shrink-0 items-center gap-1 border-t border-line p-2',
					collapsed ? 'flex-col' : 'justify-between'
				) }
			>
				<IconButton
					label={ __( 'Customize sidebar', 'cliezoo' ) }
					icon={
						<SlidersHorizontal size={ 16 } aria-hidden="true" />
					}
					size="sm"
					onPress={ () => setCustomizing( true ) }
				/>
				{ ! mobile && (
					<IconButton
						label={
							collapsed
								? __( 'Expand sidebar', 'cliezoo' )
								: __( 'Collapse sidebar', 'cliezoo' )
						}
						icon={
							collapsed ? (
								<ChevronsRight
									size={ 16 }
									aria-hidden="true"
									className="rtl:rotate-180"
								/>
							) : (
								<ChevronsLeft
									size={ 16 }
									aria-hidden="true"
									className="rtl:rotate-180"
								/>
							)
						}
						size="sm"
						shortcut="["
						onPress={ () =>
							save.mutate( { sidebar_collapsed: ! collapsed } )
						}
					/>
				) }
			</div>
			{ customizing && (
				<Suspense fallback={ null }>
					<CustomizeSidebar
						isOpen={ customizing }
						onOpenChange={ setCustomizing }
					/>
				</Suspense>
			) }
		</div>
	);
}
