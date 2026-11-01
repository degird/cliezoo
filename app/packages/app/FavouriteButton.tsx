/**
 * Adding a record to the sidebar, and taking it off again.
 *
 * The sidebar and the command palette have listed favourites since the
 * shell was built, and until now nothing in the app could make one: the
 * route existed, the list read it, and the only way to get a row in was to
 * write it yourself. So this is deliberately about records in general
 * rather than about boards — every screen that shows one thing can put it
 * in the header and mean the same by it.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Star } from 'lucide-react';
import { api, useQuery, useQueryClient, type ApiError } from '@cliezoo/data';
import { Button, cx, toast } from '@cliezoo/ui';
import { optimistic } from './optimistic';

interface Favourite {
	type: string;
	uid: string;
}

export function FavouriteButton( {
	type,
	uid,
	label,
}: {
	/** Entity name, as the server knows it: 'boards', 'projects'. */
	type: string;
	uid: string;
	/** The record's name, for what the button says afterwards. */
	label: string;
} ) {
	const queries = useQueryClient();

	// The same query the sidebar runs, so the star and the sidebar are one
	// answer rather than two that can disagree.
	const favourites = useQuery( {
		queryKey: [ 'favourites', 'list', {} ],
		staleTime: 30_000,
		queryFn: async () =>
			( await api.get< Favourite[] >( '/favourites' ) ).data,
	} );

	const on = ( favourites.data ?? [] ).some(
		( one ) => one.type === type && one.uid === uid
	);

	return (
		<Button
			size="sm"
			variant="ghost"
			// One name, and the pressed state says whether it is on: a label
			// that changes as well is told twice.
			aria-pressed={ on }
			aria-label={ __( 'Keep it on the sidebar', 'cliezoo' ) }
			onPress={ async () => {
				try {
					// The star and the sidebar change at once; the row is the
					// shape the sidebar draws (the server's answer replaces
					// it straight after), and a refusal takes it back off.
					const next = await optimistic(
						queries,
						[
							{
								key: [ 'favourites', 'list' ],
								update: ( old ) => {
									if ( ! Array.isArray( old ) ) {
										return old;
									}
									return on
										? old.filter(
												( one: Favourite ) =>
													! (
														one.type === type &&
														one.uid === uid
													)
											)
										: [
												...old,
												{ type, uid, label, meta: {} },
											];
								},
							},
						],
						async () =>
							(
								await api.post< { favourite: boolean } >(
									'/favourites',
									{ object_type: type, object: uid }
								)
							).data
					);
					queries.invalidateQueries( {
						queryKey: [ 'favourites' ],
					} );
					toast.success(
						next.favourite
							? sprintf(
									/* translators: %s: the record's name. */
									__( '%s is on the sidebar.', 'cliezoo' ),
									label
								)
							: sprintf(
									/* translators: %s: the record's name. */
									__( '%s is off the sidebar.', 'cliezoo' ),
									label
								)
					);
				} catch ( error ) {
					toast.error( ( error as ApiError ).message );
				}
			} }
		>
			<Star
				size={ 16 }
				aria-hidden="true"
				className={ cx( on && 'fill-warning text-warning' ) }
			/>
		</Button>
	);
}
