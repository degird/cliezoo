/**
 * The dashboard's cards, in one chunk that loads when the first of them
 * is shown, and the parts of Home they fill.
 */
import { __ } from '@wordpress/i18n';
import { Link } from '@tanstack/react-router';
import MyWork from './MyWork';
import RecentItems from './Recent';

export { default as Counters } from './Counters';
export { default as Health } from './Health';
export { default as Tasks, OverdueTasks } from './Tasks';
export {
	Activity,
	Ageing,
	Hours,
	MonthOnMonth,
	OpenRequests,
	Pipeline,
	Revenue,
	Unread,
	Waiting,
} from './Numbers';
export { RecentStrip } from './Recent';
export { MyWork, RecentItems };

/**
 * My Work on Home.
 */
export function HomeWork() {
	return (
		<section
			aria-labelledby="home-my-work"
			className="rounded-lg border border-line bg-raised p-4"
		>
			<div className="mb-3 flex items-center gap-2">
				<h2 id="home-my-work" className="text-sm font-semibold text-fg">
					{ __( 'My work', 'cliezoo' ) }
				</h2>
				<Link
					to="/dashboard"
					search={ { tab: 'todo' } as never }
					className="ms-auto text-xs text-accent-fg hover:underline"
				>
					{ __( 'See everything', 'cliezoo' ) }
				</Link>
			</div>
			<MyWork />
		</section>
	);
}

/**
 * Recently opened, on Home.
 */
export function HomeRecent() {
	return (
		<section
			aria-labelledby="home-recent"
			className="rounded-lg border border-line bg-raised p-4"
		>
			<h2 id="home-recent" className="mb-3 text-sm font-semibold text-fg">
				{ __( 'Recently opened', 'cliezoo' ) }
			</h2>
			<RecentItems />
		</section>
	);
}
