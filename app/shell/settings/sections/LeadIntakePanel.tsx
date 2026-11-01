/**
 * Settings → Sales: an address other tools post leads to — a webhook,
 * a Zapier step, a site's own form — with a key.
 *
 * The key is shown once, when it is made, and kept only as a hash; making
 * another stops the last. Only somebody who runs the workspace sees this.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { KeyRound } from 'lucide-react';
import { api, useQuery, type ApiError } from '@cliezoo/data';
import { Alert, Button, TextField, toast } from '@cliezoo/ui';
import { copyText } from '../../../packages/app/copy';

type Intake = { on: boolean; url: string };

export function LeadIntakePanel() {
	const [ key, setKey ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const intake = useQuery< Intake >( {
		queryKey: [ 'leads', 'intake' ],
		retry: false,
		queryFn: async () =>
			( await api.get< Intake >( '/leads/intake' ) ).data,
	} );

	// Not somebody who runs the place: nothing to show.
	if ( ! intake.data ) {
		return null;
	}

	const make = async () => {
		setBusy( true );
		try {
			const { data } = await api.post< { key: string } >(
				'/leads/intake'
			);
			setKey( data.key );
			intake.refetch();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	const off = async () => {
		setBusy( true );
		try {
			await api.post( '/leads/intake/off' );
			setKey( '' );
			intake.refetch();
			toast.success( __( 'Leads are no longer taken in.', 'cliezoo' ) );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<section
			aria-label={ __( 'Leads from elsewhere', 'cliezoo' ) }
			className="mt-6 rounded-lg border border-line bg-surface p-4"
		>
			<h2 className="text-sm font-semibold text-fg">
				{ __( 'Leads from elsewhere', 'cliezoo' ) }
			</h2>
			<p className="mt-1 text-sm text-muted">
				{ __(
					'A webhook, a Zapier step or a form on another site can post a lead here: name, email, phone, company, website, notes and source, with the key in an X-Cliezoo-Key header. It arrives as a lead that came in through the API, checked like any other.',
					'cliezoo'
				) }
			</p>
			<div className="mt-3 flex flex-col gap-3">
				<TextField
					label={ __( 'Where to post', 'cliezoo' ) }
					value={ intake.data.url }
					isReadOnly
				/>
				{ !! key && (
					<Alert tone="warning">
						{ __(
							'This is the only time the key is shown. Copy it into the tool that sends the leads.',
							'cliezoo'
						) }
					</Alert>
				) }
				{ !! key && (
					<div className="flex items-end gap-2">
						<div className="min-w-0 flex-1">
							<TextField
								label={ __( 'The key', 'cliezoo' ) }
								value={ key }
								isReadOnly
							/>
						</div>
						<Button
							onPress={ () =>
								copyText( key, __( 'Key copied.', 'cliezoo' ) )
							}
						>
							{ __( 'Copy', 'cliezoo' ) }
						</Button>
					</div>
				) }
				<div className="flex flex-wrap gap-2">
					<Button
						icon={ <KeyRound size={ 15 } aria-hidden="true" /> }
						isDisabled={ busy }
						onPress={ make }
					>
						{ intake.data.on
							? __(
									'Make a new key (the old one stops)',
									'cliezoo'
								)
							: __( 'Start taking leads in', 'cliezoo' ) }
					</Button>
					{ intake.data.on && (
						<Button
							variant="ghost"
							isDisabled={ busy }
							onPress={ off }
						>
							{ __( 'Stop taking leads in', 'cliezoo' ) }
						</Button>
					) }
				</div>
			</div>
		</section>
	);
}
