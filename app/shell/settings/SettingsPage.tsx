/**
 * Settings: grouped section list (desktop side navigation) and the section view.
 */
import { Suspense, lazy, useMemo, type ComponentType } from 'react';
import { Link, useParams } from '@tanstack/react-router';
import { __ } from '@wordpress/i18n';
import { ChevronRight } from 'lucide-react';
import { useSession } from '@cliezoo/data';
import { EmptyState, Skeleton, cx } from '@cliezoo/ui';
import {
	applies,
	registry,
	type SettingsGroup,
	type SettingsSection,
} from '../../packages/app/registry';
import { Slot, useRegistry } from '../../packages/app/slot';
import { Page, PageHeader } from '../Page';
import { registerCoreSettings } from './coreSections';

registerCoreSettings();

const GROUPS = (): Array< [ SettingsGroup, string ] > => [
	[ 'workspace', __( 'Workspace', 'cliezoo' ) ],
	[ 'people', __( 'People and access', 'cliezoo' ) ],
	[ 'work', __( 'Work', 'cliezoo' ) ],
	[ 'money', __( 'Money', 'cliezoo' ) ],
	[ 'connect', __( 'Connections', 'cliezoo' ) ],
	[ 'advanced', __( 'Site and data', 'cliezoo' ) ],
];

function useSections(): SettingsSection[] {
	useRegistry();
	const boot = useSession( ( s ) => s.boot );
	return registry.settings
		.filter( ( section ) => applies( boot, section ) )
		.sort( ( a, b ) => ( a.order ?? 50 ) - ( b.order ?? 50 ) );
}

const components = new Map< string, ComponentType >();

function sectionComponent( section: SettingsSection ): ComponentType {
	let component = components.get( section.id );
	if ( ! component ) {
		component = lazy( section.component );
		components.set( section.id, component );
	}
	return component;
}

function SectionNav( {
	sections,
	active,
}: {
	sections: SettingsSection[];
	active?: string;
} ) {
	return (
		<nav
			aria-label={ __( 'Settings sections', 'cliezoo' ) }
			className="space-y-5"
		>
			{ GROUPS().map( ( [ group, label ] ) => {
				const items = sections.filter( ( s ) => s.group === group );
				if ( ! items.length ) {
					return null;
				}
				return (
					<div key={ group }>
						<h2 className="mb-1 px-2.5 text-[0.7rem] font-semibold uppercase tracking-wider text-subtle">
							{ label }
						</h2>
						<ul className="space-y-px">
							{ items.map( ( section ) => {
								const Icon = section.icon;
								return (
									<li key={ section.id }>
										<Link
											to="/settings/$section"
											params={ { section: section.id } }
											aria-current={
												active === section.id
													? 'page'
													: undefined
											}
											className={ cx(
												'flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm font-medium text-muted hover:bg-sunken hover:text-fg focus-visible:outline-2 focus-visible:outline-focus',
												active === section.id &&
													'bg-accent-subtle text-accent-fg'
											) }
										>
											{ Icon && (
												<Icon
													size={ 16 }
													aria-hidden={ true }
												/>
											) }
											{ section.label }
										</Link>
									</li>
								);
							} ) }
						</ul>
					</div>
				);
			} ) }
		</nav>
	);
}

export function SettingsIndex() {
	const sections = useSections();
	return (
		<>
			<PageHeader title={ __( 'Settings', 'cliezoo' ) } />
			<Page width="narrow">
				{ ! sections.length && (
					<EmptyState
						title={ __( 'No settings for your role', 'cliezoo' ) }
					/>
				) }
				<div className="space-y-8">
					{ GROUPS().map( ( [ group, label ] ) => {
						const items = sections.filter(
							( s ) => s.group === group
						);
						if ( ! items.length ) {
							return null;
						}
						return (
							<section
								key={ group }
								aria-labelledby={ `settings-${ group }` }
							>
								<h2
									id={ `settings-${ group }` }
									className="mb-2 text-sm font-semibold text-muted"
								>
									{ label }
								</h2>
								<ul className="divide-y divide-line overflow-hidden rounded-lg border border-line bg-raised">
									{ items.map( ( section ) => {
										const Icon = section.icon;
										return (
											<li key={ section.id }>
												<Link
													to="/settings/$section"
													params={ {
														section: section.id,
													} }
													className="flex items-center gap-3 px-4 py-3 hover:bg-sunken focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus"
												>
													{ Icon && (
														<span className="flex size-8 items-center justify-center rounded-md bg-accent-subtle text-accent-fg">
															<Icon
																size={ 16 }
																aria-hidden={
																	true
																}
															/>
														</span>
													) }
													<span className="min-w-0 flex-1">
														<span className="block text-sm font-medium text-fg">
															{ section.label }
														</span>
														{ section.description && (
															<span className="block truncate text-xs text-muted">
																{
																	section.description
																}
															</span>
														) }
													</span>
													<ChevronRight
														size={ 16 }
														className="text-subtle rtl:rotate-180"
														aria-hidden="true"
													/>
												</Link>
											</li>
										);
									} ) }
								</ul>
							</section>
						);
					} ) }
				</div>
			</Page>
		</>
	);
}

export default function SettingsSectionPage() {
	const { section: id } = useParams( { strict: false } ) as {
		section: string;
	};
	const sections = useSections();
	const section = useMemo(
		() => sections.find( ( s ) => s.id === id ),
		[ sections, id ]
	);
	if ( ! section ) {
		return (
			<EmptyState
				title={ __( 'Settings section not found', 'cliezoo' ) }
			/>
		);
	}
	const Component = sectionComponent( section );
	return (
		<>
			<PageHeader
				title={ section.label }
				meta={ section.description }
				breadcrumb={
					<Link
						to="/settings"
						className="hover:text-fg hover:underline"
					>
						{ __( 'Settings', 'cliezoo' ) }
					</Link>
				}
			/>
			<div className="mx-auto flex max-w-6xl gap-8 px-4 py-5 sm:px-6">
				<aside className="hidden w-52 shrink-0 lg:block lg:sticky lg:top-20 lg:max-h-[calc(100dvh-6rem)] lg:overflow-y-auto lg:self-start">
					<SectionNav sections={ sections } active={ section.id } />
				</aside>
				<div className="min-w-0 flex-1 space-y-5">
					<Suspense fallback={ <Skeleton className="h-64" /> }>
						<Component />
					</Suspense>
					{ /* An add-on's part of this section. */ }
					<Slot name={ `settings.${ section.id }` } />
				</div>
			</div>
		</>
	);
}
