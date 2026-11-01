/**
 * The dashboard: my numbers and cards, a calendar of what falls due,
 * everything on me, and what happened across the workspace.
 */
import { Suspense, lazy } from 'react';
import { __ } from '@wordpress/i18n';
import { useNavigate, useSearch } from '@tanstack/react-router';
import { Skeleton, Tab, TabList, TabPanel, Tabs } from '@cliezoo/ui';
import { ActivityFeed } from '../../packages/app';
import { Page, PageHeader } from '../../shell/Page';
import MyWork from './widgets/MyWork';

const Overview = lazy(
	() => import( /* webpackChunkName: "dashboard" */ './Overview' )
);
const Calendar = lazy(
	() => import( /* webpackChunkName: "dashboard" */ './Calendar' )
);

type TabId = 'overview' | 'calendar' | 'todo' | 'activity';

const TABS = (): Array< { id: TabId; label: string } > => [
	{ id: 'overview', label: __( 'Overview', 'cliezoo' ) },
	{ id: 'calendar', label: __( 'Calendar', 'cliezoo' ) },
	{ id: 'todo', label: __( 'To-do', 'cliezoo' ) },
	{ id: 'activity', label: __( 'Activity', 'cliezoo' ) },
];

export default function DashboardScreen() {
	const navigate = useNavigate();
	const search = useSearch( { strict: false } ) as {
		tab?: string;
		board?: string;
	};
	const tab = TABS().some( ( one ) => one.id === search.tab )
		? ( search.tab as TabId )
		: 'overview';

	return (
		<>
			<PageHeader title={ __( 'Dashboard', 'cliezoo' ) } />
			<Page>
				<Tabs
					selectedKey={ tab }
					onSelectionChange={ ( key: unknown ) =>
						navigate( {
							to: '/dashboard',
							search: {
								...( key === 'overview'
									? {}
									: { tab: String( key ) } ),
								...( search.board
									? { board: search.board }
									: {} ),
							} as never,
						} )
					}
				>
					<TabList
						aria-label={ __( 'Dashboard', 'cliezoo' ) }
						className="mb-4 flex gap-1 overflow-x-auto border-b border-line"
					>
						{ TABS().map( ( one ) => (
							<Tab
								key={ one.id }
								id={ one.id }
								className="cursor-pointer whitespace-nowrap px-3 py-2 text-sm text-muted outline-none data-[selected]:border-b-2 data-[selected]:border-accent data-[selected]:text-fg data-[focus-visible]:ring-2 data-[focus-visible]:ring-accent"
							>
								{ one.label }
							</Tab>
						) ) }
					</TabList>
					<Suspense fallback={ <Skeleton className="h-64" /> }>
						<TabPanel id="overview">
							<Overview />
						</TabPanel>
						<TabPanel id="calendar">
							<Calendar />
						</TabPanel>
						<TabPanel id="todo">
							<div className="max-w-3xl rounded-lg border border-line bg-raised p-4">
								<MyWork limit={ 200 } />
							</div>
						</TabPanel>
						<TabPanel id="activity">
							<div className="max-w-3xl">
								<ActivityFeed showObject />
							</div>
						</TabPanel>
					</Suspense>
				</Tabs>
			</Page>
		</>
	);
}
