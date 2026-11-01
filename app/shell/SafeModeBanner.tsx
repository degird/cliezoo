/**
 * Admin banner while Safe mode pauses emails, webhooks, automations and
 * payment calls (for example after the site was copied or moved).
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ShieldAlert } from 'lucide-react';
import { api, getBoot, useSession, type SafeModeStatus } from '@cliezoo/data';
import { Button, Dialog, toast } from '@cliezoo/ui';

export default function SafeModeBanner() {
	const status = useSession( ( s ) => s.boot.safeMode );
	const [ confirm, setConfirm ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	if ( ! status?.active ) {
		return null;
	}
	const moved = status.reason === 'site_moved';
	const goLive = async () => {
		setBusy( true );
		try {
			const next = (
				await api.post< SafeModeStatus >( '/safe-mode', {
					active: false,
				} )
			).data;
			useSession.getState().setBoot( { ...getBoot(), safeMode: next } );
			toast.success(
				__(
					'Safe mode is off. Emails and automations run again.',
					'cliezoo'
				)
			);
			setConfirm( false );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		} finally {
			setBusy( false );
		}
	};
	return (
		<div
			role="status"
			className="flex flex-wrap items-center gap-3 border-b border-warning/30 bg-warning-subtle px-4 py-2.5 text-sm text-fg sm:px-6"
		>
			<ShieldAlert
				size={ 18 }
				className="shrink-0 text-warning"
				aria-hidden="true"
			/>
			<p className="min-w-0 flex-1">
				<strong className="font-semibold">
					{ __( 'Safe mode is on.', 'cliezoo' ) }
				</strong>{ ' ' }
				{ moved
					? sprintf(
							/* translators: 1: old site address, 2: new site address. */
							__(
								'The site address changed from %1$s to %2$s, so emails, webhooks, automations and payment calls are paused.',
								'cliezoo'
							),
							status.from,
							status.to
						)
					: __(
							'Emails, webhooks, automations and payment calls are paused.',
							'cliezoo'
						) }
			</p>
			<Button size="sm" onPress={ () => setConfirm( true ) }>
				{ moved
					? __( 'This is the live site', 'cliezoo' )
					: __( 'Turn off Safe mode', 'cliezoo' ) }
			</Button>
			<Dialog
				isOpen={ confirm }
				onOpenChange={ setConfirm }
				role="alertdialog"
				title={ __( 'Turn off Safe mode?', 'cliezoo' ) }
				description={ __(
					'Only do this on the live site. Emails, reminders, webhooks, automations and payment calls start again. Emails held while Safe mode was on are discarded, because they may be out of date.',
					'cliezoo'
				) }
				footer={ ( close ) => (
					<>
						<Button variant="ghost" onPress={ close }>
							{ __( 'Keep Safe mode', 'cliezoo' ) }
						</Button>
						<Button
							variant="primary"
							isPending={ busy }
							onPress={ goLive }
						>
							{ __( 'Turn off Safe mode', 'cliezoo' ) }
						</Button>
					</>
				) }
			/>
		</div>
	);
}
