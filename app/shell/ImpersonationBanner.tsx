/**
 * "You are looking at this as somebody else."
 *
 * Deliberately impossible to miss and impossible to lose: it sits above
 * everything, in a colour nothing else in the app uses, and it is the only
 * thing on the screen that can end the sitting.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Eye } from 'lucide-react';
import { api, useSession, type ApiError } from '@cliezoo/data';
import { toast } from '@cliezoo/ui';

export default function ImpersonationBanner() {
	const as = useSession( ( s ) => s.boot.impersonating );
	const [ busy, setBusy ] = useState( false );
	if ( ! as ) {
		return null;
	}

	const stop = async () => {
		if ( busy ) {
			return;
		}
		setBusy( true );
		try {
			await api.post( '/portal/stop-impersonating' );
			// A full reload, not a refetch: everything on the screen was
			// loaded as somebody else and none of it is yours.
			window.location.reload();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
			setBusy( false );
		}
	};

	return (
		<div
			role="status"
			className="flex flex-wrap items-center gap-2 bg-[#FDE047] px-4 py-2 text-sm text-[#422006] dark:bg-[#CA8A04] dark:text-[#1F1300]"
		>
			<Eye size={ 16 } aria-hidden="true" />
			<p className="min-w-0 flex-1">
				{ sprintf(
					/* translators: %s: the person whose view this is. */
					__( 'You are looking at this as %s.', 'cliezoo' ),
					as.name
				) }{ ' ' }
				<span className="opacity-80">
					{ __(
						'Anything you do here is recorded under both names.',
						'cliezoo'
					) }
				</span>
			</p>
			<button
				type="button"
				onClick={ stop }
				disabled={ busy }
				className="rounded-md bg-[#422006] px-3 py-1 font-medium text-[#FDE047] hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#422006] disabled:opacity-60"
			>
				{ __( 'Stop', 'cliezoo' ) }
			</button>
		</div>
	);
}
