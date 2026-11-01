/**
 * For somebody who is a contact at more than one client: which company's
 * portal they are looking at. Switching reloads, because everything on
 * screen — every list, every count — belongs to the company.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { api, useSession, type ApiError } from '@cliezoo/data';
import { Select, toast } from '@cliezoo/ui';

export function CompanySwitcher() {
	const companies = useSession( ( s ) => s.boot.portal?.companies );
	const [ busy, setBusy ] = useState( false );
	if ( ! companies || companies.length < 2 ) {
		return null;
	}
	const active = companies.find( ( one ) => one.active )?.uid ?? null;
	return (
		<div className="px-3 pb-2">
			<Select
				label={ __( 'Company', 'cliezoo' ) }
				items={ companies.map( ( one ) => ( {
					id: one.uid,
					label: one.name,
				} ) ) }
				selectedKey={ active }
				isDisabled={ busy }
				onSelectionChange={ async ( key: unknown ) => {
					const uid = String( key ?? '' );
					if ( ! uid || uid === active ) {
						return;
					}
					setBusy( true );
					try {
						await api.post( '/portal/company', { client: uid } );
						window.location.reload();
					} catch ( error ) {
						toast.error( ( error as ApiError ).message );
						setBusy( false );
					}
				} }
			/>
		</div>
	);
}
