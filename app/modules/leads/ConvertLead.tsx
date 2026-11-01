/**
 * The lead said yes.
 *
 * Everything it knows goes across — the address, the phone number, the
 * website, the currency, the notes — so nobody retypes a client they already
 * wrote down once. What the dialog asks for is only what a client record
 * needs and a lead does not have: what to call the company, and who the
 * person is, because "Ada Lovelace" is a contact and "Bluedot Studio" is a
 * client and a lead is often written down as one when it means the other.
 *
 * The lead is kept afterwards rather than consumed. "How many of the leads
 * from that campaign turned into work" is a question about the leads, and a
 * lead that vanishes when it succeeds cannot answer it.
 */
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { api, getBoot, type ApiError } from '@cliezoo/data';
import { Alert, Button, Dialog, Switch, TextField, toast } from '@cliezoo/ui';

/**
 * The dialog.
 *
 * @param root0             Props.
 * @param root0.lead        The lead.
 * @param root0.isOpen      Whether it is showing.
 * @param root0.onClose     Called when it should close.
 * @param root0.onConverted Called with the new client's uid.
 */
export function ConvertLead( {
	lead,
	isOpen,
	onClose,
	onConverted,
}: {
	lead: Record< string, unknown >;
	isOpen: boolean;
	onClose: () => void;
	onConverted: ( client: string ) => void;
} ) {
	const [ name, setName ] = useState( '' );
	const [ contact, setContact ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ sale, setSale ] = useState( true );
	const [ busy, setBusy ] = useState( false );

	// Only where the studio keeps deals at all. Offering to open one in a
	// workspace with the module switched off would be offering a button
	// whose next call refuses.
	const sells = ( getBoot().modules ?? [] ).includes( 'deals' );

	// Filled in from the lead each time it opens, rather than once at mount:
	// a dialog that works its state out once is a dialog that offers the
	// last lead's details for this one.
	useEffect( () => {
		if ( ! isOpen ) {
			return;
		}
		setName(
			String( lead.business_name ?? '' ) || String( lead.name ?? '' )
		);
		setContact( String( lead.name ?? '' ) );
		setEmail( String( lead.email ?? '' ) );
		setSale( true );
	}, [ isOpen, lead ] );

	const convert = async () => {
		setBusy( true );
		try {
			const { data } = await api.post< {
				client: { uid: string; name: string };
			} >( `/leads/${ String( lead.uid ) }/convert`, {
				name,
				contact_name: contact,
				contact_email: email,
				deal: sells && sale,
			} );
			toast.success( __( 'They are a client now.', 'cliezoo' ) );
			onConverted( data.client?.uid ?? '' );
			onClose();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Dialog
			isOpen={ isOpen }
			onOpenChange={ ( open: boolean ) => ! open && onClose() }
			title={ __( 'Make them a client', 'cliezoo' ) }
			description={ __(
				'The lead stays as it is, so it still counts as one that was won.',
				'cliezoo'
			) }
			footer={ ( close ) => (
				<>
					<Button variant="ghost" onPress={ close }>
						{ __( 'Cancel', 'cliezoo' ) }
					</Button>
					<Button
						variant="primary"
						isDisabled={ busy || '' === name.trim() }
						onPress={ convert }
					>
						{ __( 'Make the client', 'cliezoo' ) }
					</Button>
				</>
			) }
		>
			<div className="flex flex-col gap-3">
				<TextField
					label={ __( 'The client is called', 'cliezoo' ) }
					value={ name }
					onChange={ setName }
				/>
				<TextField
					label={ __( 'Their contact', 'cliezoo' ) }
					value={ contact }
					onChange={ setContact }
				/>
				<TextField
					label={ __( 'Their email', 'cliezoo' ) }
					type="email"
					value={ email }
					onChange={ setEmail }
					description={ __(
						'A contact needs an address to be one. Without it the client is made on its own.',
						'cliezoo'
					) }
				/>
				{ sells && (
					<div>
						<Switch isSelected={ sale } onChange={ setSale }>
							{ __(
								'Open a deal for the work as well',
								'cliezoo'
							) }
						</Switch>
						<p className="mt-1 text-xs text-muted">
							{ __(
								'With what they said their budget was, for you to correct. Turn it off for a client who signed there and then — a deal nobody will ever move is a card that sits on the board forever.',
								'cliezoo'
							) }
						</p>
					</div>
				) }
				<Alert tone="info">
					{ __(
						'The phone number, website, currency and notes go across as well.',
						'cliezoo'
					) }
				</Alert>
			</div>
		</Dialog>
	);
}
