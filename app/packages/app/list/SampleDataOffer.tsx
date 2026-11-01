/**
 * The sample-data offer on an empty list (cliezoo.md §6.2, §2.1 "guided
 * start"): an empty workspace is hard to judge, so an admin who has not
 * added the example records yet is offered them where the emptiness is.
 * They are the same records the setup wizard adds (`POST /setup/sample-data`).
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Sparkles } from 'lucide-react';
import { api, useQueryClient, useSession, type ApiError } from '@cliezoo/data';
import { Button, toast } from '@cliezoo/ui';

interface SampleBoot {
	user?: { admin?: boolean } | null;
	setup?: unknown;
}

/**
 * Whether to offer example records: an admin, a workspace that has not had
 * them yet.
 *
 * @param boot What the page knows: the person, and (for admins) setup.
 */
export function offersSampleData( boot: SampleBoot ): boolean {
	const setup = boot.setup as { sample_data?: boolean } | undefined;
	return !! boot.user?.admin && !! setup && ! setup.sample_data;
}

export function SampleDataOffer() {
	const boot = useSession( ( s ) => s.boot );
	const queries = useQueryClient();
	const [ busy, setBusy ] = useState( false );
	const [ added, setAdded ] = useState( false );
	if ( added || ! offersSampleData( boot ) ) {
		return null;
	}

	const add = async () => {
		setBusy( true );
		try {
			const result = (
				await api.post< { total: number } >( '/setup/sample-data' )
			).data;
			setAdded( true );
			// Every list and count may have something in it now.
			await queries.invalidateQueries();
			const session = useSession.getState();
			session.setBoot( {
				...session.boot,
				setup: session.boot.setup
					? { ...session.boot.setup, sample_data: true }
					: session.boot.setup,
			} as typeof session.boot );
			if ( ! result.total ) {
				// Nothing on this site makes example records: say so rather
				// than claim something was added.
				toast.warning(
					__( 'No example records could be added.', 'cliezoo' )
				);
				return;
			}
			toast.success(
				sprintf(
					/* translators: %d: how many example records were added. */
					_n(
						'%d example record added.',
						'%d example records added.',
						result.total,
						'cliezoo'
					),
					result.total
				)
			);
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Button
			icon={ <Sparkles size={ 15 } aria-hidden="true" /> }
			isPending={ busy }
			onPress={ add }
		>
			{ __( 'Add example records', 'cliezoo' ) }
		</Button>
	);
}
