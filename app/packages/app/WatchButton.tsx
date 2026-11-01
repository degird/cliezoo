/**
 * Watching a record, and stopping.
 *
 * The server has had one watch route for every kind of record since the
 * notification matrix was built, and the only screen that offered it was
 * a task. So, like the favourite star beside it, this is about records in
 * general: every screen that shows one thing can put the bell in its
 * header and mean the same by it.
 *
 * Three states, because the server keeps three: watching (told about
 * everything), muted (stopped on purpose — only mentions get through),
 * and neither (told the ordinary way, as the owner or somebody on it).
 * The bell shows the first two; pressing it moves between watching and
 * not.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Bell, BellOff } from 'lucide-react';
import { api, useQuery, useQueryClient, type ApiError } from '@cliezoo/data';
import { Button, cx, toast } from '@cliezoo/ui';
import { optimistic } from './optimistic';

interface WatchState {
	watching: boolean;
	muted: boolean;
}

export function WatchButton( {
	type,
	uid,
	label,
}: {
	/** Entity name, as the server knows it: 'boards', 'docs'. */
	type: string;
	uid: string;
	/** The record's name, for what the button says afterwards. */
	label: string;
} ) {
	const queries = useQueryClient();
	const key = [ 'watch', type, uid ];
	const state = useQuery( {
		queryKey: key,
		staleTime: 60_000,
		queryFn: async () =>
			(
				await api.get< WatchState >( '/watch', {
					object_type: type,
					object: uid,
				} )
			).data,
	} );
	const watching = !! state.data?.watching;
	const muted = !! state.data?.muted;

	return (
		<Button
			size="sm"
			variant="ghost"
			// One name, and the pressed state says whether it is on: a label
			// that changes as well is told twice.
			aria-pressed={ watching }
			aria-label={ __( 'Watch for updates', 'cliezoo' ) }
			isDisabled={ state.isPending }
			onPress={ async () => {
				try {
					// The bell changes at once; a refusal puts it back.
					const next = await optimistic(
						queries,
						[
							{
								key,
								update: () => ( {
									watching: ! watching,
									muted: watching,
								} ),
							},
						],
						async () =>
							(
								await api.post< { watching: boolean } >(
									'/watch',
									{
										object_type: type,
										object: uid,
										watching: ! watching,
									}
								)
							).data
					);
					queries.setQueryData< WatchState >( key, {
						watching: next.watching,
						muted: ! next.watching,
					} );
					toast.success(
						next.watching
							? sprintf(
									/* translators: %s: the record's name. */
									__(
										'You will hear about everything on %s.',
										'cliezoo'
									),
									label
								)
							: sprintf(
									/* translators: %s: the record's name. */
									__(
										'You stopped watching %s. Mentions still reach you.',
										'cliezoo'
									),
									label
								)
					);
				} catch ( error ) {
					toast.error( ( error as ApiError ).message );
				}
			} }
		>
			{ muted ? (
				<BellOff size={ 16 } aria-hidden="true" />
			) : (
				<Bell
					size={ 16 }
					aria-hidden="true"
					className={ cx( watching && 'fill-accent text-accent-fg' ) }
				/>
			) }
		</Button>
	);
}
