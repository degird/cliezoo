/**
 * Home: greeting and the personal overview. Modules fill its parts: the
 * strip of what I opened lately ("home.strip"), what is on me
 * ("home.work"), cards ("home.cards" and registered widgets), and the
 * table of recently opened records ("home.recent").
 *
 * A client's home is a different page — their notice, their welcome, what
 * is waiting for them — and the portal fills the "home.client" slot with
 * it. The shell does not know what is on that page, only whose it is.
 */
import { Suspense } from 'react';
import { __, _x, sprintf } from '@wordpress/i18n';
import { Link } from '@tanstack/react-router';
import { Sparkles } from 'lucide-react';
import { formatFullDate, getBoot, useUser, type Boot } from '@cliezoo/data';
import { Alert, EmptyState, Skeleton, buttonClass } from '@cliezoo/ui';
import { Slot, useSlotHasFills } from '../../packages/app/slot';
import type { NavItem } from '../../packages/app/registry';
import { ALWAYS_SHOWN, availableItems } from '../nav';
import { Page, PageHeader } from '../Page';
import { ErrorBoundary } from '../ErrorBoundary';
import { Widgets, useWidgets } from '../Widgets';

/**
 * The greeting for the time of day.
 *
 * @param now The moment (now, unless a test says otherwise).
 */
export function greeting( now: Date = new Date() ): string {
	const hour = now.getHours();
	if ( hour < 12 ) {
		return __( 'Good morning', 'cliezoo' );
	}
	return hour < 18
		? __( 'Good afternoon', 'cliezoo' )
		: __( 'Good evening', 'cliezoo' );
}

/**
 * Home's title: the greeting and the person's first name ("Good morning,
 * Ada"), or the greeting alone when there is no name to say.
 *
 * @param name The person's full name.
 * @param now  The moment.
 */
export function greetingTitle( name: string | undefined, now?: Date ): string {
	const first = ( name ?? '' ).trim().split( /\s+/ )[ 0 ] ?? '';
	return first
		? sprintf(
				/* translators: 1: greeting, 2: first name. */
				_x( '%1$s, %2$s', 'a greeting and a first name', 'cliezoo' ),
				greeting( now ),
				first
			)
		: greeting( now );
}

/**
 * Where a person with an empty Home can go instead.
 *
 * Their own screens, from the sidebar they actually have, rather than a
 * sentence about work appearing here on its own: Home and the Inbox are
 * left out because they are where they already are.
 *
 * @param boot    Boot data.
 * @param howMany How many to offer.
 */
export function waysIn( boot: Boot, howMany = 3 ): NavItem[] {
	return availableItems( boot )
		.filter( ( item ) => ! ALWAYS_SHOWN.includes( item.id ) )
		.slice( 0, howMany );
}

/**
 * A part of Home a module fills: it loads on its own, and if it fails it
 * leaves the rest of the page standing.
 *
 * @param root0        Props.
 * @param root0.name   The slot.
 * @param root0.height How tall it is while loading.
 */
function Part( { name, height }: { name: string; height: string } ) {
	return (
		<ErrorBoundary
			fallback={
				<Alert tone="warning">
					{ __( 'This part of Home could not be shown.', 'cliezoo' ) }
				</Alert>
			}
		>
			<Suspense fallback={ <Skeleton className={ height } /> }>
				<Slot name={ name } badge={ false } />
			</Suspense>
		</ErrorBoundary>
	);
}

export default function Home() {
	const user = useUser();
	const client = useSlotHasFills( 'home.client' ) && !! user?.client;
	const widgets = useWidgets( 'home' );
	const work = useSlotHasFills( 'home.work' );
	const recent = useSlotHasFills( 'home.recent' );
	const cards = useSlotHasFills( 'home.cards' );
	const hasCards = work || recent || cards || widgets.length > 0;
	if ( client ) {
		return <Slot name="home.client" badge={ false } />;
	}

	return (
		<>
			<PageHeader
				title={ greetingTitle( user?.name ) }
				meta={ formatFullDate( new Date() ) }
			/>
			<Page>
				{ hasCards ? (
					<div className="space-y-4">
						<Part name="home.strip" height="h-16" />
						<div className="grid gap-4 xl:grid-cols-3">
							{ work && (
								<div className="xl:col-span-2">
									<Part name="home.work" height="h-48" />
								</div>
							) }
							<div className="grid content-start gap-4 md:grid-cols-2 xl:grid-cols-1">
								<Slot name="home.cards" />
							</div>
						</div>
						<Widgets widgets={ widgets } />
						<Part name="home.recent" height="h-32" />
						<div className="flex flex-wrap gap-2">
							<Slot name="home.widgets" variant="chip" />
						</div>
					</div>
				) : (
					<EmptyState
						icon={ <Sparkles size={ 22 } aria-hidden="true" /> }
						title={ __( 'Your workspace is ready', 'cliezoo' ) }
						actions={
							<>
								{ /*
								 * Links that look like buttons, rather than
								 * links inside them: a link in a button is
								 * two tab stops for one thing, and announces
								 * as neither.
								 */ }
								{ !! user?.admin && (
									<Link
										to="/setup"
										className={ buttonClass(
											'primary',
											'sm'
										) }
									>
										{ __( 'Finish setting up', 'cliezoo' ) }
									</Link>
								) }
								{ waysIn( getBoot() ).map( ( item ) => (
									<Link
										key={ item.id }
										to={ item.to }
										className={ buttonClass(
											'ghost',
											'sm'
										) }
									>
										{ item.label }
									</Link>
								) ) }
							</>
						}
					>
						{ __(
							'Your tasks, meetings and updates will show up here as you start working.',
							'cliezoo'
						) }
					</EmptyState>
				) }
			</Page>
		</>
	);
}
