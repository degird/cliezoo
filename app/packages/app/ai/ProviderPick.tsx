/**
 * Which AI answers this person, on an AI draft: shown when the workspace
 * has more than one provider, and remembered for the person (`/ai/mine`).
 * Choosing another asks again with it. An add-on may draw its own in the
 * "ai.draft.provider" slot instead.
 */
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { api, type ApiError } from '@cliezoo/data';
import { Select, toast } from '@cliezoo/ui';

interface Mine {
	provider: string;
	providers: { id: string; label: string }[];
}

export default function ProviderPick( {
	busy,
	retry,
}: {
	busy: boolean;
	retry: () => void;
} ) {
	const [ data, setData ] = useState< Mine | null >( null );
	useEffect( () => {
		let live = true;
		Promise.resolve( api.get< Mine >( '/ai/mine' ) )
			.then( ( answer ) => live && answer && setData( answer.data ) )
			// Not offered here: no switcher.
			.catch( () => undefined );
		return () => {
			live = false;
		};
	}, [] );
	// One provider is no choice.
	if ( ! data || data.providers.length < 2 ) {
		return null;
	}
	const choose = async ( provider: string ) => {
		try {
			const answer = await api.post< Mine >( '/ai/mine', { provider } );
			setData( answer.data );
			retry();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};
	return (
		<Select
			aria-label={ __( 'Answer with', 'cliezoo' ) }
			className="w-48"
			isDisabled={ busy }
			items={ [
				{ id: '', label: __( 'The usual AI', 'cliezoo' ) },
				...data.providers,
			] }
			selectedKey={ data.provider }
			onSelectionChange={ ( chosen ) => choose( String( chosen ?? '' ) ) }
		/>
	);
}
