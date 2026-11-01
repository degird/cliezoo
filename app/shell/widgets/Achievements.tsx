/**
 * Home card: the badges this workspace has earned.
 *
 * Each one is read from the workspace itself — a client on the books, a
 * task ticked off, somebody let into their portal — so a badge cannot
 * claim something that did not happen. What is still to come is shown
 * too, quietly: it is the shortest description of what Cliezoo is for.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Award, Circle } from 'lucide-react';
import { api, formatDate, useQuery } from '@cliezoo/data';
import { Skeleton } from '@cliezoo/ui';

interface Badge {
	id: string;
	label: string;
	about: string;
	done: boolean;
	earned_at: string;
}

interface SetupState {
	achievements?: Badge[];
}

export default function Achievements() {
	const setup = useQuery( {
		queryKey: [ 'setup' ],
		queryFn: async () => ( await api.get< SetupState >( '/setup' ) ).data,
	} );

	if ( setup.isPending ) {
		return <Skeleton className="h-24" />;
	}
	// A card that could not be read stays out of the way; Settings →
	// Health is where something broken is reported.
	if ( setup.isError ) {
		return null;
	}
	const badges = setup.data?.achievements ?? [];
	if ( ! badges.length ) {
		return null;
	}
	const earned = badges.filter( ( one ) => one.done );

	return (
		<div className="space-y-3">
			<p className="text-sm text-muted">
				{ sprintf(
					/* translators: 1: badges earned, 2: badges in total. */
					__( '%1$d of %2$d earned', 'cliezoo' ),
					earned.length,
					badges.length
				) }
			</p>
			<ul className="space-y-2">
				{ badges.map( ( badge ) => (
					<li key={ badge.id } className="flex items-start gap-2.5">
						{ badge.done ? (
							<Award
								size={ 16 }
								className="mt-0.5 shrink-0 text-success"
								aria-hidden="true"
							/>
						) : (
							<Circle
								size={ 16 }
								className="mt-0.5 shrink-0 text-subtle"
								aria-hidden="true"
							/>
						) }
						<span className="min-w-0">
							<span
								className={
									badge.done
										? 'block text-sm font-medium text-fg'
										: 'block text-sm font-medium text-muted'
								}
							>
								{ badge.label }
							</span>
							<span className="block text-xs text-muted">
								{ badge.done && badge.earned_at
									? sprintf(
											/* translators: %s: a date. */
											__( 'Earned %s', 'cliezoo' ),
											formatDate( badge.earned_at )
										)
									: badge.about }
							</span>
						</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}
